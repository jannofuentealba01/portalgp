<?php
declare(strict_types=1);

final class CobranzaContratoService
{
    public function __construct(private PDO $conn)
    {
    }

    public function obtener(int $idContrato): array
    {
        if ($idContrato <= 0) {
            throw new RuntimeException('El contrato solicitado no es válido.');
        }
        $contrato = $this->contrato($idContrato);
        if ($contrato === null) {
            throw new RuntimeException('El contrato solicitado no existe.');
        }
        $documentos = $this->documentos($idContrato);
        $ids = array_column($documentos, 'id_documento_cobro');
        $detalles = $this->detalles($ids);
        foreach ($documentos as &$documento) {
            $documento['detalles'] = $detalles[(int) $documento['id_documento_cobro']] ?? [];
        }
        unset($documento);

        $hoy = new DateTimeImmutable('today');
        $deudaTotal = 0.0;
        $deudaVencida = 0.0;
        $docsPendientes = 0;
        $docsVencidos = 0;
        $moraMaxima = 0;
        foreach ($documentos as &$documento) {
            $saldo = round((float) $documento['saldo_pendiente'], 2);
            $deudaTotal += $saldo;
            if ($saldo > 0.005) {
                $docsPendientes++;
            }
            $fechaVencimiento = trim((string) ($documento['fecha_vencimiento'] ?? ''));
            $diasMora = 0;
            if ($saldo > 0.005 && $fechaVencimiento !== '') {
                $vence = new DateTimeImmutable(substr($fechaVencimiento, 0, 10));
                if ($vence < $hoy) {
                    $diasMora = (int) $vence->diff($hoy)->format('%a');
                    $deudaVencida += $saldo;
                    $docsVencidos++;
                    $moraMaxima = max($moraMaxima, $diasMora);
                }
            }
            $documento['dias_mora'] = $diasMora;
            $documento['monto_pagado_calculado'] = max(0, round((float) $documento['monto_total'] - $saldo, 2));
        }
        unset($documento);

        $ultimoPago = $this->ultimoPago($idContrato);
        $saldoFavor = $this->saldoFavor((int) $contrato['id_tienda']);
        $garantias = $this->garantias($idContrato);
        $cargosMora = $this->cargosMora($idContrato);
        $locales = $this->locales($idContrato);
        $garantiaTotales = ['pactado' => 0.0, 'recibido' => 0.0, 'reservado' => 0.0, 'aplicado' => 0.0, 'devuelto' => 0.0, 'disponible' => 0.0];
        foreach ($garantias as $garantia) {
            foreach ($garantiaTotales as $campo => $valor) {
                $garantiaTotales[$campo] += (float) ($garantia['monto_' . $campo] ?? 0);
            }
        }

        return [
            'contrato' => $contrato,
            'locales' => $locales,
            'documentos' => $documentos,
            'resumen' => [
                'deuda_total' => round($deudaTotal, 2),
                'deuda_vencida' => round($deudaVencida, 2),
                'documentos_pendientes' => $docsPendientes,
                'documentos_vencidos' => $docsVencidos,
                'mora_maxima' => $moraMaxima,
                'ultimo_pago' => $ultimoPago,
                'saldo_favor' => $saldoFavor,
                'garantia_disponible' => round($garantiaTotales['disponible'], 2),
            ],
            'garantias' => $garantias,
            'garantia_totales' => array_map(static fn (float $v): float => round($v, 2), $garantiaTotales),
            'cargos_mora' => $cargosMora,
            'eventos_financieros' => $this->eventosFinancieros($idContrato),
        ];
    }

    private function contrato(int $id): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT c.*,a.nombre_locatario,a.nombre_representante,a.rut,t.nombre_comercial,
                    CASE c.estado_contrato WHEN 1 THEN N'Borrador' WHEN 2 THEN N'Vigente'
                         WHEN 3 THEN N'En proceso de cierre' WHEN 4 THEN N'Terminado'
                         WHEN 5 THEN N'Anulado' ELSE N'Sin estado' END estado_contrato_nombre
             FROM dbo.msp_contratos_arriendo c
             INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
             INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
             WHERE c.id_contrato_arriendo=:id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function locales(int $id): array
    {
        $stmt = $this->conn->prepare(
            'SELECT l.id_local,l.cdo_local,l.desc_local,cl.fecha_inicio,cl.fecha_termino,cl.estado_relacion
             FROM dbo.msp_contrato_locales cl INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local
             WHERE cl.id_contrato_arriendo=:id ORDER BY cl.orden_visual,l.cdo_local'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function documentos(int $id): array
    {
        $stmt = $this->conn->prepare(
            "SELECT dc.*,ISNULL((SELECT SUM(p.monto_pagado) FROM dbo.msp_pagos p
                       WHERE p.id_documento_cobro=dc.id_documento_cobro AND p.estado_pago=1),0) pagos_registrados
             FROM dbo.msp_documentos_cobro dc
             OUTER APPLY(SELECT TOP (1) c.id_contrato_arriendo FROM dbo.msp_contratos_arriendo c
                         WHERE c.id_tienda=dc.id_tienda AND c.fecha_inicio<=EOMONTH(dc.periodo_facturacion)
                           AND (c.fecha_termino_efectiva IS NULL OR c.fecha_termino_efectiva>=dc.periodo_facturacion)
                           AND c.estado_contrato IN(1,2,3,4)
                         ORDER BY c.fecha_inicio DESC,c.id_contrato_arriendo DESC) contrato_vigente
             WHERE COALESCE(dc.id_contrato_arriendo,contrato_vigente.id_contrato_arriendo)=:id AND dc.estado_documento<>4
             ORDER BY dc.periodo_facturacion,ISNULL(dc.fecha_vencimiento,dc.periodo_facturacion),dc.id_documento_cobro"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function detalles(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $holders = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $holders[] = ':id' . $i;
            $params[':id' . $i] = (int) $id;
        }
        $stmt = $this->conn->prepare(
            'SELECT d.*,ti.codigo_item,ti.nombre_item FROM dbo.msp_documentos_cobro_detalle d
             INNER JOIN dbo.msp_tipo_item_documento ti ON ti.id_tipo_item_documento=d.id_tipo_item_documento
             WHERE d.id_documento_cobro IN (' . implode(',', $holders) . ')
             ORDER BY d.id_documento_cobro,d.orden_item,d.id_detalle_documento'
        );
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['id_documento_cobro']][] = $row;
        }
        return $map;
    }

    private function ultimoPago(int $id): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT TOP (1) p.id_pago,p.fecha_pago,p.monto_pagado,p.medio_pago,p.referencia_pago
             FROM dbo.msp_pagos p INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=p.id_documento_cobro
             OUTER APPLY(SELECT TOP (1) c.id_contrato_arriendo FROM dbo.msp_contratos_arriendo c
                         WHERE c.id_tienda=dc.id_tienda AND c.fecha_inicio<=EOMONTH(dc.periodo_facturacion)
                           AND (c.fecha_termino_efectiva IS NULL OR c.fecha_termino_efectiva>=dc.periodo_facturacion)
                           AND c.estado_contrato IN(1,2,3,4) ORDER BY c.fecha_inicio DESC,c.id_contrato_arriendo DESC) cv
             WHERE COALESCE(dc.id_contrato_arriendo,cv.id_contrato_arriendo)=:id AND p.estado_pago=1 ORDER BY p.fecha_pago DESC,p.id_pago DESC'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function saldoFavor(int $idTienda): float
    {
        if (!msp2TableExists($this->conn, 'msp_saldos_favor_tienda')) {
            return 0.0;
        }
        $stmt = $this->conn->prepare('SELECT ISNULL(saldo_disponible,0) FROM dbo.msp_saldos_favor_tienda WHERE id_tienda=:id');
        $stmt->execute([':id' => $idTienda]);
        return round((float) ($stmt->fetchColumn() ?: 0), 2);
    }

    private function garantias(int $id): array
    {
        if (!msp2TableExists($this->conn, 'msp_vw_garantias_control_integral')) {
            return [];
        }
        $stmt = $this->conn->prepare('SELECT * FROM dbo.msp_vw_garantias_control_integral WHERE id_contrato_arriendo=:id ORDER BY cdo_local,id_garantia');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function cargosMora(int $id): array
    {
        if (!msp2TableExists($this->conn, 'msp_cargos_auto_generados')) {
            return [];
        }
        $stmt = $this->conn->prepare(
            'SELECT cg.*,r.nombre_regla,origen.numero_documento documento_origen,emitido.numero_documento documento_emitido
             FROM dbo.msp_cargos_auto_generados cg
             INNER JOIN dbo.msp_reglas_cobro_auto r ON r.id_regla_cobro_auto=cg.id_regla_cobro_auto
             INNER JOIN dbo.msp_documentos_cobro origen ON origen.id_documento_cobro=cg.id_documento_origen_deuda
             LEFT JOIN dbo.msp_documentos_cobro emitido ON emitido.id_documento_cobro=cg.id_documento_cobro
             WHERE origen.id_contrato_arriendo=:id ORDER BY cg.fecha_calculo DESC,cg.id_cargo_auto_generado DESC'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function eventosFinancieros(int $id): array
    {
        $stmt = $this->conn->prepare(
            "SELECT TOP (100) p.fecha_registro fecha_evento,N'PAGO' tipo,
                    CONCAT(N'Pago #',p.id_pago,N' · ',ISNULL(p.medio_pago,N'Sin medio')) titulo,
                    p.monto_pagado monto,dc.numero_documento referencia
             FROM dbo.msp_pagos p INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=p.id_documento_cobro
             OUTER APPLY(SELECT TOP (1) c.id_contrato_arriendo FROM dbo.msp_contratos_arriendo c
                         WHERE c.id_tienda=dc.id_tienda AND c.fecha_inicio<=EOMONTH(dc.periodo_facturacion)
                           AND (c.fecha_termino_efectiva IS NULL OR c.fecha_termino_efectiva>=dc.periodo_facturacion)
                           AND c.estado_contrato IN(1,2,3,4) ORDER BY c.fecha_inicio DESC,c.id_contrato_arriendo DESC) cv
             WHERE COALESCE(dc.id_contrato_arriendo,cv.id_contrato_arriendo)=:id AND p.estado_pago=1 ORDER BY p.fecha_registro DESC,p.id_pago DESC"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
