<?php
declare(strict_types=1);

require_once __DIR__ . '/CobranzaContratoService.php';

final class Ficha360Service
{
    public function __construct(private PDO $conn)
    {
    }

    public function obtener(int $idArrendatario): array
    {
        if ($idArrendatario <= 0) {
            throw new RuntimeException('El arrendatario solicitado no es válido.');
        }

        $arrendatario = $this->arrendatario($idArrendatario);
        if ($arrendatario === null) {
            throw new RuntimeException('El arrendatario solicitado no existe.');
        }

        $arrendatario['correos'] = $this->contactos('msp_arrendatarios_correos', 'correo', 'id_arrendatario_correo', $idArrendatario);
        $arrendatario['telefonos'] = $this->contactos('msp_arrendatarios_telefonos', 'telefono', 'id_arrendatario_telefono', $idArrendatario);

        $contratosBase = $this->contratos($idArrendatario);
        $cobranza = new CobranzaContratoService($this->conn);
        $contratos = [];
        $actividad = [
            'pagos' => [],
            'gestiones' => [],
            'compromisos' => [],
            'correcciones' => [],
            'historial' => [],
        ];
        $totales = [
            'contratos' => count($contratosBase),
            'vigentes' => 0,
            'locales' => 0,
            'deuda_total' => 0.0,
            'deuda_vencida' => 0.0,
            'saldo_favor' => 0.0,
            'garantia_pactada' => 0.0,
            'garantia_recibida' => 0.0,
            'garantia_disponible' => 0.0,
            'documentos_pendientes' => 0,
        ];

        foreach ($contratosBase as $base) {
            $idContrato = (int) $base['id_contrato_arriendo'];
            try {
                $detalle = $cobranza->obtener($idContrato);
                $detalle['operacion'] = $this->operacionContrato($idContrato);
                $detalle['cobranza'] = $this->cobranzaContrato($idContrato);
                $detalle['correcciones'] = $this->correccionesContrato($idContrato);
                $detalle['historial'] = $this->historialContrato($idContrato, $base);
                $detalle['error'] = null;
            } catch (Throwable $e) {
                $detalle = [
                    'contrato' => $base,
                    'locales' => [],
                    'documentos' => [],
                    'resumen' => [],
                    'garantias' => [],
                    'garantia_totales' => [],
                    'operacion' => [],
                    'error' => $e->getMessage(),
                ];
            }

            $estado = (int) ($detalle['contrato']['estado_contrato'] ?? $base['estado_contrato'] ?? 0);
            if ($estado === 2) {
                $totales['vigentes']++;
            }
            $totales['locales'] += count($detalle['locales'] ?? []);
            $totales['deuda_total'] += (float) ($detalle['resumen']['deuda_total'] ?? 0);
            $totales['deuda_vencida'] += (float) ($detalle['resumen']['deuda_vencida'] ?? 0);
            $totales['saldo_favor'] += (float) ($detalle['resumen']['saldo_favor'] ?? 0);
            $totales['documentos_pendientes'] += (int) ($detalle['resumen']['documentos_pendientes'] ?? 0);
            $totales['garantia_pactada'] += (float) ($detalle['garantia_totales']['pactado'] ?? 0);
            $totales['garantia_recibida'] += (float) ($detalle['garantia_totales']['recibido'] ?? 0);
            $totales['garantia_disponible'] += (float) ($detalle['garantia_totales']['disponible'] ?? 0);
            $contratos[] = $detalle;
            foreach (($detalle['eventos_financieros'] ?? []) as $pago) {
                $pago['id_contrato_arriendo'] = $idContrato;
                $actividad['pagos'][] = $pago;
            }
            foreach (($detalle['cobranza']['gestiones'] ?? []) as $gestion) {
                $gestion['id_contrato_arriendo'] = $idContrato;
                $actividad['gestiones'][] = $gestion;
            }
            foreach (($detalle['cobranza']['compromisos'] ?? []) as $compromiso) {
                $compromiso['id_contrato_arriendo'] = $idContrato;
                $actividad['compromisos'][] = $compromiso;
            }
            foreach (($detalle['correcciones'] ?? []) as $correccion) {
                $correccion['id_contrato_arriendo'] = $idContrato;
                $actividad['correcciones'][] = $correccion;
            }
            foreach (($detalle['historial'] ?? []) as $evento) {
                $evento['id_contrato_arriendo'] = $idContrato;
                $actividad['historial'][] = $evento;
            }
        }

        foreach (['pagos', 'gestiones', 'compromisos', 'correcciones', 'historial'] as $tipo) {
            usort($actividad[$tipo], static fn (array $a, array $b): int => strcmp((string) ($b['fecha_evento'] ?? $b['fecha_gestion'] ?? $b['fecha_creacion'] ?? $b['fecha_solicitud'] ?? ''), (string) ($a['fecha_evento'] ?? $a['fecha_gestion'] ?? $a['fecha_creacion'] ?? $a['fecha_solicitud'] ?? '')));
            $actividad[$tipo] = array_slice($actividad[$tipo], 0, 20);
        }

        foreach (['deuda_total', 'deuda_vencida', 'saldo_favor', 'garantia_pactada', 'garantia_recibida', 'garantia_disponible'] as $campo) {
            $totales[$campo] = round((float) $totales[$campo], 2);
        }

        return [
            'arrendatario' => $arrendatario,
            'contratos' => $contratos,
            'totales' => $totales,
            'actividad' => $actividad,
        ];
    }

    private function arrendatario(int $id): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT a.*,ea.desc_estado,c.desc_comuna
             FROM dbo.msp_arrendatarios a
             LEFT JOIN dbo.msp_estado_arrendatarios ea ON ea.id_estado_arrendatario=a.id_estado_arrendatario
             LEFT JOIN dbo.msp_comunas c ON c.id_comuna=a.id_comuna
             WHERE a.id_arrendatario=:id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function contactos(string $tabla, string $campo, string $idCampo, int $id): array
    {
        if (!msp2TableExists($this->conn, $tabla)) {
            return [];
        }
        $sql = "SELECT {$campo} valor,es_principal FROM dbo.{$tabla} WHERE id_arrendatario=:id ORDER BY es_principal DESC,{$idCampo}";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function contratos(int $id): array
    {
        $stmt = $this->conn->prepare(
            "SELECT ca.*,t.nombre_comercial,
                    CASE ca.estado_contrato WHEN 1 THEN N'Borrador' WHEN 2 THEN N'Vigente'
                         WHEN 3 THEN N'En proceso de cierre' WHEN 4 THEN N'Terminado'
                         WHEN 5 THEN N'Anulado' ELSE N'Sin estado' END estado_contrato_nombre
             FROM dbo.msp_contratos_arriendo ca
             INNER JOIN dbo.msp_tiendas t ON t.id_tienda=ca.id_tienda
             WHERE ca.id_arrendatario=:id
             ORDER BY CASE WHEN ca.estado_contrato=2 THEN 0 WHEN ca.estado_contrato=3 THEN 1 ELSE 2 END,
                      ca.fecha_inicio DESC,ca.id_contrato_arriendo DESC"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function operacionContrato(int $idContrato): array
    {
        if (!msp2TableExists($this->conn, 'msp_medidores') || !msp2TableExists($this->conn, 'msp_lecturas_medidores')) {
            return [];
        }
        $stmt = $this->conn->prepare(
            "SELECT m.id_medidor,m.codigo_medidor,m.alias_medidor,m.estado_medidor,l.cdo_local,
                    ts.codigo_servicio,ts.nombre_servicio,ts.unidad_medida,
                    ult.periodo_facturacion,ult.fecha_lectura,ult.lectura_anterior,ult.lectura_actual,ult.consumo_informado
             FROM dbo.msp_contrato_locales cl
             INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local
             INNER JOIN dbo.msp_medidores m ON m.id_local=l.id_local
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=m.id_tipo_servicio
             OUTER APPLY (
                SELECT TOP (1) lm.periodo_facturacion,lm.fecha_lectura,lm.lectura_anterior,lm.lectura_actual,
                       COALESCE(lm.consumo_informado,lm.lectura_actual-lm.lectura_anterior) consumo_informado
                FROM dbo.msp_lecturas_medidores lm
                WHERE lm.id_medidor=m.id_medidor
                ORDER BY lm.periodo_facturacion DESC,lm.id_lectura DESC
             ) ult
             WHERE cl.id_contrato_arriendo=:id
             ORDER BY l.cdo_local,ts.id_tipo_servicio,m.codigo_medidor"
        );
        $stmt->execute([':id' => $idContrato]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function cobranzaContrato(int $idContrato): array
    {
        $resultado = ['gestiones' => [], 'compromisos' => [], 'avisos' => [], 'caso' => null];
        if (!msp2TableExists($this->conn, 'msp_cobranza_gestiones') || !msp2TableExists($this->conn, 'msp_cobranza_compromisos')) {
            return $resultado;
        }
        $stmt = $this->conn->prepare(
            'SELECT TOP (20) g.id_gestion_cobranza,g.fecha_gestion,g.persona_contactada,g.observacion,
                    g.proxima_fecha_seguimiento,t.nombre tipo_nombre,r.nombre resultado_nombre
             FROM dbo.msp_cobranza_gestiones g
             LEFT JOIN dbo.msp_cobranza_tipos_gestion t ON t.id_tipo_gestion=g.id_tipo_gestion
             LEFT JOIN dbo.msp_cobranza_resultados_gestion r ON r.id_resultado_gestion=g.id_resultado_gestion
             WHERE g.id_contrato_arriendo=:id
             ORDER BY g.fecha_gestion DESC,g.id_gestion_cobranza DESC'
        );
        $stmt->execute([':id' => $idContrato]);
        $resultado['gestiones'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmt = $this->conn->prepare(
            'SELECT TOP (20) id_compromiso_pago,fecha_creacion,fecha_comprometida,monto_comprometido,
                    monto_pagado_evaluado,estado,observacion
             FROM dbo.msp_cobranza_compromisos
             WHERE id_contrato_arriendo=:id
             ORDER BY fecha_creacion DESC,id_compromiso_pago DESC'
        );
        $stmt->execute([':id' => $idContrato]);
        $resultado['compromisos'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (msp2TableExists($this->conn, 'msp_cobranza_avisos')) {
            $stmt = $this->conn->prepare('SELECT TOP (20) id_aviso_cobranza,fecha_generacion,fecha_envio,estado,medio_envio,asunto_snapshot FROM dbo.msp_cobranza_avisos WHERE id_contrato_arriendo=:id ORDER BY fecha_generacion DESC,id_aviso_cobranza DESC');
            $stmt->execute([':id' => $idContrato]);
            $resultado['avisos'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return $resultado;
    }

    private function correccionesContrato(int $idContrato): array
    {
        if (!msp2TableExists($this->conn, 'msp_correcciones')) {
            return [];
        }
        $stmt = $this->conn->prepare(
            'SELECT TOP (20) id_correccion,codigo_operacion,tipo_correccion,entidad_afectada,
                    estado_correccion,nivel_correcion,motivo,fecha_solicitud,fecha_actualizacion,error_ejecucion
             FROM dbo.msp_correcciones
             WHERE id_contrato_arriendo=:id
             ORDER BY fecha_solicitud DESC,id_correccion DESC'
        );
        $stmt->execute([':id' => $idContrato]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function historialContrato(int $idContrato, array $contrato): array
    {
        $eventos = [];
        if (msp2TableExists($this->conn, 'msp_bitacora_cierre_contrato')) {
            $stmt = $this->conn->prepare("SELECT TOP (20) fecha_registro fecha_evento, N'CONTRATO' tipo_evento, motivo_cierre detalle, estado_contrato_anterior, estado_contrato_nuevo FROM dbo.msp_bitacora_cierre_contrato WHERE id_contrato_arriendo=:id ORDER BY fecha_registro DESC");
            $stmt->execute([':id' => $idContrato]);
            $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if (msp2TableExists($this->conn, 'msp_historial_contrato')) {
            $stmt = $this->conn->prepare('SELECT TOP (20) fecha_registro fecha_evento, tipo_evento, detalle_evento detalle, motivo_evento FROM dbo.msp_historial_contrato WHERE id_contrato_arriendo=:id ORDER BY fecha_registro DESC');
            $stmt->execute([':id' => $idContrato]);
            $eventos = array_merge($eventos, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
        if (($contrato['fecha_termino_efectiva'] ?? '') !== '') {
            $eventos[] = ['fecha_evento' => $contrato['fecha_termino_efectiva'], 'tipo_evento' => 'TERMINO', 'detalle' => 'Término operativo registrado.'];
        }
        return $eventos;
    }
}
