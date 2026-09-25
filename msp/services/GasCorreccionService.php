<?php
declare(strict_types=1);

/** Corrección controlada de lecturas de gas ya documentadas. */
final class GasCorreccionService
{
    public static function ejecutar(PDO $conn, array $corr, int $usuario): array
    {
        $idCorreccion = (int) ($corr['id_correccion'] ?? 0);
        $idLectura = (int) ($corr['id_registro_origen'] ?? 0);
        $idContrato = (int) ($corr['id_contrato_arriendo'] ?? 0);
        $nuevo = self::numeroNuevo($corr['valor_nuevo'] ?? null);
        if ($idCorreccion <= 0 || $idLectura <= 0 || $idContrato <= 0 || $nuevo === null || $nuevo < 0) {
            throw new RuntimeException('La corrección controlada no contiene una lectura válida.');
        }
        foreach (['msp_lecturas_medidores','msp_medidores','msp_contrato_locales','msp_contratos_arriendo',
                  'msp_procesos_cobro_servicio','msp_tipos_servicio','msp_proceso_cobro_gas',
                  'msp_cobros_servicios','msp_documentos_cobro','msp_documentos_cobro_detalle'] as $tabla) {
            if (!msp2TableExists($conn, $tabla)) {
                throw new RuntimeException('Falta la estructura requerida para corregir la lectura de gas.');
            }
        }

        $transaccionPropia = !$conn->inTransaction();
        if ($transaccionPropia) { $conn->beginTransaction(); }
        try {
            $claim = $conn->prepare(
                "UPDATE dbo.msp_correcciones
                 SET estado_correccion=N'EJECUTANDO',estrategia_ejecucion=N'LECTURA_GAS_CONTROLADA',
                     fecha_actualizacion=SYSDATETIME()
                 WHERE id_correccion=:id AND estado_correccion=N'APROBADA'"
            );
            $claim->execute([':id' => $idCorreccion]);
            if ($claim->rowCount() !== 1) {
                throw new RuntimeException('La corrección ya fue ejecutada o está siendo procesada.');
            }

            $lectura = self::cargarLectura($conn, $idLectura, $idContrato);
            if ($lectura === null) {
                throw new RuntimeException('La lectura no pertenece al contrato indicado.');
            }
            if ((string) ($lectura['codigo_servicio'] ?? '') !== 'GAS') {
                throw new RuntimeException('La corrección controlada implementada corresponde solamente a gas.');
            }
            if ((int) ($lectura['id_cobro_servicio'] ?? 0) <= 0) {
                throw new RuntimeException('La lectura no tiene un cobro de gas calculado. Vuelve a generar la etapa de gas.');
            }
            $esperada = self::campoAnterior($corr['valor_anterior'] ?? null, 'lectura_actual');
            if ($esperada !== null && abs((float) $lectura['lectura_actual'] - $esperada) > 0.0001) {
                throw new RuntimeException('La lectura cambió después del análisis. Vuelve a registrar la corrección.');
            }
            $anterior = (float) ($lectura['lectura_anterior'] ?? 0);
            if ($nuevo < $anterior) {
                throw new RuntimeException('La lectura nueva no puede ser menor que la lectura anterior.');
            }
            if (abs($nuevo - (float) $lectura['lectura_actual']) <= 0.0001) {
                throw new RuntimeException('La lectura nueva es igual a la registrada; no existe una corrección que aplicar.');
            }
            self::validarParametrosGas($lectura, 'del período');

            $siguiente = self::cargarSiguiente($conn, (int) $lectura['id_medidor'], (string) $lectura['periodo_facturacion']);
            if ($siguiente !== null && $nuevo > (float) $siguiente['lectura_actual']) {
                throw new RuntimeException('La lectura nueva no puede superar la lectura del período siguiente (' . (string) $siguiente['lectura_actual'] . ').');
            }

            $consumoNuevo = round($nuevo - $anterior, 4);
            $montoNuevo = self::calcularMonto($consumoNuevo, $lectura);
            $afectados = [[
                'tipo' => 'actual',
                'id_lectura' => $idLectura,
                'id_cobro' => (int) $lectura['id_cobro_servicio'],
                'id_documento' => (int) ($lectura['id_documento_cobro'] ?? 0),
                'lectura_anterior_original' => $anterior,
                'lectura_anterior_nueva' => $anterior,
                'lectura_actual_original' => (float) $lectura['lectura_actual'],
                'lectura_actual_nueva' => $nuevo,
                'consumo_anterior' => (float) ($lectura['consumo_cobrado'] ?? $lectura['consumo_informado'] ?? 0),
                'consumo_nuevo' => $consumoNuevo,
                'monto_anterior' => (float) ($lectura['monto_cobro'] ?? 0),
                'monto_nuevo' => $montoNuevo,
                'factor' => (float) ($lectura['factor'] ?? 0),
                'valor_litro' => (float) ($lectura['valor_litro'] ?? 0),
            ]];
            $propagaSiguiente = $siguiente !== null
                && $siguiente['lectura_anterior'] !== null
                && abs((float) $siguiente['lectura_anterior'] - (float) $lectura['lectura_actual']) <= 0.0001;
            if ($propagaSiguiente) {
                $contratoSiguiente = (int) ($siguiente['id_contrato_arriendo_siguiente'] ?? 0);
                if ($contratoSiguiente > 0 && $contratoSiguiente !== $idContrato) {
                    throw new RuntimeException(
                        'La lectura siguiente pertenece a otro contrato. Debe revisarse manualmente la distribución del consumo antes de corregir.'
                    );
                }
                if ($contratoSiguiente <= 0
                    && ((int) ($siguiente['id_documento_cobro'] ?? 0) > 0
                        || (int) ($siguiente['id_cobro_servicio'] ?? 0) > 0)) {
                    throw new RuntimeException(
                        'No fue posible identificar el contrato de la lectura siguiente. No se modificó ningún dato.'
                    );
                }
                self::validarParametrosGas($siguiente, 'del período siguiente');
                $consumoSiguiente = round((float) $siguiente['lectura_actual'] - $nuevo, 4);
                $afectados[] = [
                    'tipo' => 'siguiente',
                    'id_lectura' => (int) $siguiente['id_lectura'],
                    'id_cobro' => (int) ($siguiente['id_cobro_servicio'] ?? 0),
                    'id_documento' => (int) ($siguiente['id_documento_cobro'] ?? 0),
                    'lectura_anterior_original' => (float) $siguiente['lectura_anterior'],
                    'lectura_anterior_nueva' => $nuevo,
                    'lectura_actual_original' => (float) $siguiente['lectura_actual'],
                    'lectura_actual_nueva' => (float) $siguiente['lectura_actual'],
                    'consumo_anterior' => (float) ($siguiente['consumo_cobrado'] ?? $siguiente['consumo_informado'] ?? 0),
                    'consumo_nuevo' => $consumoSiguiente,
                    'monto_anterior' => (float) ($siguiente['monto_cobro'] ?? 0),
                    'monto_nuevo' => self::calcularMonto($consumoSiguiente, $siguiente),
                    'factor' => (float) ($siguiente['factor'] ?? 0),
                    'valor_litro' => (float) ($siguiente['valor_litro'] ?? 0),
                ];
            }

            $deltasDocumento = [];
            foreach ($afectados as $item) {
                $idDocumento = (int) $item['id_documento'];
                if ($idDocumento <= 0) { continue; }
                $dependencias = self::dependenciasProtegidas($conn, $idDocumento);
                if ($dependencias !== []) {
                    throw new RuntimeException('El documento ' . $idDocumento . ' tiene ' . implode(', ', $dependencias)
                        . '. Debe resolverse mediante un ajuste financiero; no se modificó ningún valor.');
                }
                $deltasDocumento[$idDocumento] = round((float) ($deltasDocumento[$idDocumento] ?? 0)
                    + ((float) $item['monto_nuevo'] - (float) $item['monto_anterior']), 2);
            }
            self::aplicarCambios($conn, $corr, $usuario, $afectados, $deltasDocumento);
            $resultado = [
                'estrategia' => 'LECTURA_GAS_CONTROLADA',
                'servicio' => 'GAS',
                'id_lectura' => $idLectura,
                'lectura_original' => (float) $lectura['lectura_actual'],
                'lectura_nueva' => $nuevo,
                'consumo_recalculado' => $consumoNuevo,
                'factor' => (float) ($lectura['factor'] ?? 0),
                'valor_litro' => (float) ($lectura['valor_litro'] ?? 0),
                'monto_anterior' => (float) ($lectura['monto_cobro'] ?? 0),
                'monto_nuevo' => $montoNuevo,
                'documentos_actualizados' => array_map('intval', array_keys($deltasDocumento)),
                'lectura_siguiente_actualizada' => $propagaSiguiente ? (int) ($siguiente['id_lectura'] ?? 0) : null,
            ];
            CorreccionesService::cambiarEstado($conn, $idCorreccion, 'EJECUTADA', $usuario,
                'Lectura de gas corregida con regeneración documental y contable controlada.', $resultado);
            if ($transaccionPropia) { $conn->commit(); }
            return $resultado;
        } catch (Throwable $e) {
            if ($transaccionPropia && $conn->inTransaction()) { $conn->rollBack(); }
            throw $e;
        }
    }

    private static function cargarLectura(PDO $conn, int $idLectura, int $idContrato): ?array
    {
        $stmt = $conn->prepare(
            "SELECT TOP(1) lm.id_lectura,lm.id_medidor,lm.periodo_facturacion,lm.lectura_anterior,
                lm.lectura_actual,lm.consumo_informado,m.id_local,m.codigo_medidor,l.cdo_local,
                cl.id_contrato_arriendo,ca.id_tienda,UPPER(ts.codigo_servicio) codigo_servicio,
                pg.factor,pg.valor_litro,cs.id_cobro_servicio,cs.consumo_cobrado,cs.monto_total monto_cobro,
                dd.id_documento_cobro,dc.numero_documento,dc.monto_total monto_documento,
                dc.saldo_pendiente,dc.estado_documento
             FROM dbo.msp_lecturas_medidores lm WITH(UPDLOCK,HOLDLOCK)
             INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
             INNER JOIN dbo.msp_locales l ON l.id_local=m.id_local
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_local=m.id_local AND cl.id_contrato_arriendo=:contrato
             INNER JOIN dbo.msp_contratos_arriendo ca ON ca.id_contrato_arriendo=cl.id_contrato_arriendo
             INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro=lm.id_proceso_cobro
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=p.id_tipo_servicio
             INNER JOIN dbo.msp_proceso_cobro_gas pg ON pg.id_proceso_cobro=p.id_proceso_cobro
             LEFT JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
             LEFT JOIN dbo.msp_documentos_cobro_detalle dd ON dd.id_cobro_servicio=cs.id_cobro_servicio
             LEFT JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=dd.id_documento_cobro
             WHERE lm.id_lectura=:lectura
               AND UPPER(ts.codigo_servicio)=N'GAS'
               AND (dc.id_documento_cobro IS NULL OR dc.id_contrato_arriendo=cl.id_contrato_arriendo)
               AND (
                    dc.id_documento_cobro IS NOT NULL
                    OR (
                        cl.fecha_inicio<=EOMONTH(lm.periodo_facturacion)
                        AND (cl.fecha_termino IS NULL OR cl.fecha_termino>=lm.periodo_facturacion)
                        AND ca.fecha_inicio<=EOMONTH(lm.periodo_facturacion)
                        AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva>=lm.periodo_facturacion)
                    )
               )
             ORDER BY dd.id_detalle_documento DESC"
        );
        $stmt->execute([':contrato'=>$idContrato, ':lectura'=>$idLectura]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private static function cargarSiguiente(PDO $conn, int $idMedidor, string $periodo): ?array
    {
        $stmt = $conn->prepare(
            "SELECT TOP(1) lm.id_lectura,lm.periodo_facturacion,lm.lectura_anterior,lm.lectura_actual,
                lm.consumo_informado,pg.factor,pg.valor_litro,cs.id_cobro_servicio,cs.consumo_cobrado,
                cs.monto_total monto_cobro,dd.id_documento_cobro,dc.numero_documento,
                COALESCE(dc.id_contrato_arriendo,contrato_periodo.id_contrato_arriendo) id_contrato_arriendo_siguiente
             FROM dbo.msp_lecturas_medidores lm WITH(UPDLOCK,HOLDLOCK)
             INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
             INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro=lm.id_proceso_cobro
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=p.id_tipo_servicio
             INNER JOIN dbo.msp_proceso_cobro_gas pg ON pg.id_proceso_cobro=p.id_proceso_cobro
             LEFT JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
             LEFT JOIN dbo.msp_documentos_cobro_detalle dd ON dd.id_cobro_servicio=cs.id_cobro_servicio
             LEFT JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=dd.id_documento_cobro
             OUTER APPLY (
                SELECT TOP(1) cl.id_contrato_arriendo
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca ON ca.id_contrato_arriendo=cl.id_contrato_arriendo
                WHERE cl.id_local=m.id_local
                  AND cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio<=EOMONTH(lm.periodo_facturacion)
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino>=lm.periodo_facturacion)
                  AND ca.fecha_inicio<=EOMONTH(lm.periodo_facturacion)
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva>=lm.periodo_facturacion)
                  AND ca.estado_contrato IN (1,2,3,4)
                ORDER BY ca.fecha_inicio DESC,ca.id_contrato_arriendo DESC
             ) contrato_periodo
             WHERE lm.id_medidor=:medidor AND lm.periodo_facturacion>:periodo
               AND UPPER(ts.codigo_servicio)=N'GAS'
             ORDER BY lm.periodo_facturacion,lm.id_lectura,dd.id_detalle_documento DESC"
        );
        $stmt->execute([':medidor'=>$idMedidor, ':periodo'=>$periodo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private static function validarParametrosGas(array $lectura, string $contexto): void
    {
        $factor = (float) ($lectura['factor'] ?? -1);
        $valorLitro = (float) ($lectura['valor_litro'] ?? -1);
        if (!is_finite($factor) || $factor < 0 || !is_finite($valorLitro) || $valorLitro < 0) {
            throw new RuntimeException('Los parámetros de gas ' . $contexto . ' no son válidos.');
        }
    }

    private static function calcularMonto(float $consumo, array $lectura): float
    {
        return round(
            $consumo * (float) ($lectura['factor'] ?? 0) * (float) ($lectura['valor_litro'] ?? 0),
            2
        );
    }

    private static function dependenciasProtegidas(PDO $conn, int $idDocumento): array
    {
        $encontradas = [];
        $checks = [
            'msp_pagos' => ['id_documento_cobro','pagos registrados'],
            'msp_saldo_favor_periodo_aplicaciones' => ['id_documento_cobro','saldo a favor aplicado'],
            'msp_garantia_documento_aplicaciones' => ['id_documento_cobro','garantía aplicada'],
            'msp_movimientos_garantia' => ['id_documento_cobro','movimientos de garantía'],
            'msp_envio_lote_documentos' => ['id_documento_cobro','historial de envío'],
            'msp_pago_contrato_operacion_detalle' => ['id_documento_cobro','operaciones de pago'],
            'msp_pago_contrato_archivos' => ['id_documento_cobro','respaldos de pago'],
        ];
        foreach ($checks as $tabla => [$columna,$label]) {
            if (!msp2TableExists($conn, $tabla) || !msp2ColumnExists($conn, $tabla, $columna)) { continue; }
            $q = $conn->prepare('SELECT TOP(1) 1 FROM dbo.' . $tabla . ' WHERE ' . $columna . '=:documento');
            $q->execute([':documento'=>$idDocumento]);
            if ($q->fetchColumn() !== false) { $encontradas[] = $label; }
        }
        return array_values(array_unique($encontradas));
    }

    private static function numeroNuevo(mixed $raw): ?float
    {
        $texto = trim((string) $raw);
        if ($texto === '') { return null; }
        $decoded = json_decode($texto, true);
        if (is_numeric($decoded)) { return (float) $decoded; }
        if (is_array($decoded)) {
            foreach (['lectura_actual','valor','value'] as $campo) {
                if (isset($decoded[$campo]) && is_numeric($decoded[$campo])) { return (float) $decoded[$campo]; }
            }
        }
        $normalizado = str_replace(',', '.', $texto);
        return is_numeric($normalizado) ? (float) $normalizado : null;
    }

    private static function campoAnterior(mixed $raw, string $campo): ?float
    {
        $texto = trim((string) $raw);
        if ($texto === '') { return null; }
        $decoded = json_decode($texto, true);
        if (is_array($decoded) && isset($decoded[$campo]) && is_numeric($decoded[$campo])) {
            return (float) $decoded[$campo];
        }
        if (preg_match('/'.preg_quote($campo,'/').'\\s*[:=]\\s*([0-9]+(?:[\\.,][0-9]+)?)/iu', $texto, $m) === 1) {
            return (float) str_replace(',', '.', $m[1]);
        }
        return null;
    }

    private static function aplicarCambios(PDO $conn, array $corr, int $usuario, array $afectados, array $deltasDocumento): void
    {
        $idCorreccion = (int) ($corr['id_correccion'] ?? 0);
        $rehacerAsiento = [];
        foreach (array_keys($deltasDocumento) as $idDocumento) {
            self::versionarDocumento($conn, (int) $idDocumento, $idCorreccion, $usuario, (string) ($corr['motivo'] ?? ''));
            $rehacerAsiento[(int) $idDocumento] = self::revertirContabilidad($conn, (int) $idDocumento, $idCorreccion);
        }
        foreach ($afectados as $item) {
            self::actualizarLecturaYCobro($conn, $item, $idCorreccion);
        }
        foreach ($deltasDocumento as $idDocumento => $delta) {
            self::actualizarDocumento($conn, (int) $idDocumento, (float) $delta, $idCorreccion, $usuario,
                (bool) ($rehacerAsiento[(int) $idDocumento] ?? false));
        }
    }

    private static function revertirContabilidad(PDO $conn, int $idDocumento, int $idCorreccion): bool
    {
        if (!msp2TableExists($conn, 'msp_acc_asientos')) { return false; }
        $q = $conn->prepare("SELECT COUNT(*) FROM dbo.msp_acc_asientos WHERE tabla_origen=N'msp_documentos_cobro' AND id_origen=:id AND estado_asiento=1");
        $q->execute([':id'=>$idDocumento]);
        if ((int) $q->fetchColumn() <= 0) { return false; }
        if (!msp2ProcedureExists($conn, 'msp_acc_revertir_origen') || !msp2ProcedureExists($conn, 'msp_acc_generar_asiento_documento')) {
            throw new RuntimeException('Faltan procedimientos contables para aplicar la corrección de forma segura.');
        }
        $reversa = $conn->prepare("EXEC dbo.msp_acc_revertir_origen @tabla_origen=N'msp_documentos_cobro',
            @id_origen=:id,@fecha_reversa=:fecha,@motivo=N'Corrección de lectura de gas'");
        $reversa->execute([':id'=>$idDocumento, ':fecha'=>date('Y-m-d')]);
        $liberar = $conn->prepare("UPDATE dbo.msp_acc_asientos
            SET hash_origen=CONCAT(hash_origen,N'-CORRECCION-',:correccion,N'-',id_asiento_contable)
            WHERE tabla_origen=N'msp_documentos_cobro' AND id_origen=:id AND estado_asiento<>1
              AND hash_origen=CONCAT(N'DOCUMENTO_EMISION',CHAR(124),N'msp_documentos_cobro',CHAR(124),:id_hash)");
        $liberar->execute([':correccion'=>$idCorreccion, ':id'=>$idDocumento, ':id_hash'=>$idDocumento]);
        return true;
    }

    private static function actualizarLecturaYCobro(PDO $conn, array $item, int $idCorreccion): void
    {
        $updLectura = $conn->prepare('UPDATE dbo.msp_lecturas_medidores
            SET lectura_anterior=:anterior,lectura_actual=:actual,consumo_informado=:consumo,
                fecha_actualizacion=SYSDATETIME() WHERE id_lectura=:id');
        $updLectura->execute([
            ':anterior'=>(float) $item['lectura_anterior_nueva'],
            ':actual'=>(float) $item['lectura_actual_nueva'],
            ':consumo'=>(float) $item['consumo_nuevo'], ':id'=>(int) $item['id_lectura'],
        ]);
        if ($updLectura->rowCount() !== 1) {
            throw new RuntimeException('La lectura cambió o dejó de existir durante la corrección.');
        }
        $idCobro = (int) ($item['id_cobro'] ?? 0);
        if ($idCobro > 0) {
            $parametros = json_encode([
                'servicio'=>'GAS',
                'factor'=>(float) $item['factor'],
                'valor_litro'=>(float) $item['valor_litro'],
                'id_correccion'=>$idCorreccion,
            ], JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES);
            $updCobro = $conn->prepare("UPDATE dbo.msp_cobros_servicios
                SET consumo_cobrado=:consumo,subtotal_variable=:monto,cargo_fijo=0,monto_total=:total,
                    parametros_snapshot=:parametros,detalle_calculo=N'Corrección selectiva de lectura de gas',
                    fecha_calculo=SYSDATETIME() WHERE id_cobro_servicio=:id");
            $updCobro->execute([':consumo'=>(float) $item['consumo_nuevo'],':monto'=>(float) $item['monto_nuevo'],
                ':total'=>(float) $item['monto_nuevo'],':parametros'=>$parametros,':id'=>$idCobro]);
            if ($updCobro->rowCount() !== 1) {
                throw new RuntimeException('El cobro de gas asociado cambió o dejó de existir durante la corrección.');
            }
            $consumo = (float) $item['consumo_nuevo'];
            $monto = (float) $item['monto_nuevo'];
            $updDetalle = $conn->prepare('UPDATE dbo.msp_documentos_cobro_detalle
                SET cantidad=:cantidad,valor_unitario=:unitario,subtotal=:subtotal WHERE id_cobro_servicio=:id');
            $updDetalle->execute([':cantidad'=>$consumo > 0 ? $consumo : 1,
                ':unitario'=>$consumo > 0 ? round($monto/$consumo,2) : $monto,
                ':subtotal'=>$monto,':id'=>$idCobro]);
            if ((int) ($item['id_documento'] ?? 0) > 0 && $updDetalle->rowCount() < 1) {
                throw new RuntimeException('El documento ya no contiene el cobro de gas que se intentó corregir.');
            }
        }
        self::impacto($conn, $idCorreccion, 'LECTURA', (int) $item['id_lectura'], 'UPDATE', [
            'servicio'=>'GAS','lectura_anterior'=>$item['lectura_anterior_original'],'lectura_actual'=>$item['lectura_actual_original'],
            'consumo'=>$item['consumo_anterior'],'monto'=>$item['monto_anterior'],
        ], [
            'servicio'=>'GAS','lectura_anterior'=>$item['lectura_anterior_nueva'],'lectura_actual'=>$item['lectura_actual_nueva'],
            'consumo'=>$item['consumo_nuevo'],'monto'=>$item['monto_nuevo'],
            'factor'=>$item['factor'],'valor_litro'=>$item['valor_litro'],
        ], true);
    }

    private static function actualizarDocumento(PDO $conn, int $idDocumento, float $delta, int $idCorreccion, int $usuario, bool $rehacerAsiento): void
    {
        $actual = $conn->prepare('SELECT subtotal_servicios,monto_total,saldo_pendiente,estado_documento
            FROM dbo.msp_documentos_cobro WITH(UPDLOCK,HOLDLOCK) WHERE id_documento_cobro=:id');
        $actual->execute([':id'=>$idDocumento]);
        $documento = $actual->fetch(PDO::FETCH_ASSOC);
        if ($documento === false || (int) ($documento['estado_documento'] ?? 0) === 5) {
            throw new RuntimeException('El documento afectado ya no está disponible para corrección.');
        }
        if ((float) $documento['subtotal_servicios'] + $delta < -0.009
            || (float) $documento['monto_total'] + $delta < -0.009
            || (float) $documento['saldo_pendiente'] + $delta < -0.009) {
            throw new RuntimeException('La corrección dejaría valores negativos en el documento. No se modificó ningún dato.');
        }
        $upd = $conn->prepare('UPDATE dbo.msp_documentos_cobro
            SET subtotal_servicios=ROUND(subtotal_servicios+:d1,2),monto_total=ROUND(monto_total+:d2,2),
                saldo_pendiente=ROUND(saldo_pendiente+:d3,2) WHERE id_documento_cobro=:id');
        $upd->execute([':d1'=>$delta,':d2'=>$delta,':d3'=>$delta,':id'=>$idDocumento]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('El documento cambió o dejó de existir durante la corrección.');
        }
        if ($rehacerAsiento) {
            $asiento = $conn->prepare('EXEC dbo.msp_acc_generar_asiento_documento @id_documento_cobro=:id');
            $asiento->execute([':id'=>$idDocumento]);
        }
        require_once __DIR__ . '/DocumentoCobroTrazabilidadService.php';
        DocumentoCobroTrazabilidadService::registrar($conn, $idDocumento, 'RECALCULO', 'SISTEMA', $usuario, [
            'id_correccion'=>$idCorreccion,'tipo'=>'LECTURA_GAS','delta_documento'=>$delta,
        ]);
        self::impacto($conn, $idCorreccion, 'DOCUMENTO_COBRO', $idDocumento, 'RECALCULO',
            ['delta'=>0], ['delta'=>$delta], true);
    }

    private static function versionarDocumento(PDO $conn, int $idDocumento, int $idCorreccion, int $usuario, string $motivo): void
    {
        $qDoc = $conn->prepare('SELECT * FROM dbo.msp_documentos_cobro WHERE id_documento_cobro=:id');
        $qDoc->execute([':id'=>$idDocumento]);
        $documento = $qDoc->fetch(PDO::FETCH_ASSOC);
        if ($documento === false) { throw new RuntimeException('El documento afectado ya no existe.'); }
        $qDet = $conn->prepare('SELECT * FROM dbo.msp_documentos_cobro_detalle
            WHERE id_documento_cobro=:id ORDER BY orden_item,id_detalle_documento');
        $qDet->execute([':id'=>$idDocumento]);
        $detalle = $qDet->fetchAll(PDO::FETCH_ASSOC) ?: [];
        self::impacto($conn, $idCorreccion, 'DOCUMENTO_COBRO', $idDocumento, 'VERSION_ANTERIOR',
            ['documento'=>$documento,'detalle'=>$detalle], null, true);
        if (!msp2TableExists($conn, 'msp_documentos_cobro_versiones')) { return; }
        self::guardarVersionBase($conn, $documento, $detalle, $idCorreccion, $usuario, $motivo);
    }

    private static function guardarVersionBase(PDO $conn, array $doc, array $detalle, int $idCorreccion, int $usuario, string $motivo): void
    {
        $sql = "IF NOT EXISTS (SELECT 1 FROM dbo.msp_documentos_cobro_versiones WHERE id_documento_cobro_original=:buscar)
            INSERT dbo.msp_documentos_cobro_versiones(id_documento_cobro_original,uuid_documento,
                id_contrato_arriendo,id_tienda,periodo_facturacion,numero_documento,estado_documento_original,
                monto_total_original,saldo_pendiente_original,documento_json,detalle_json,motivo_version,id_usuario)
            VALUES(:id,:uuid,:contrato,:tienda,:periodo,:numero,:estado,:monto,:saldo,:documento,:detalle,:motivo,:usuario)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':buscar'=>(int) $doc['id_documento_cobro'], ':id'=>(int) $doc['id_documento_cobro'],
            ':uuid'=>$doc['uuid_documento'] ?? null, ':contrato'=>$doc['id_contrato_arriendo'] ?? null,
            ':tienda'=>(int) $doc['id_tienda'], ':periodo'=>(string) $doc['periodo_facturacion'],
            ':numero'=>$doc['numero_documento'] ?? null, ':estado'=>(int) $doc['estado_documento'],
            ':monto'=>(float) $doc['monto_total'], ':saldo'=>(float) $doc['saldo_pendiente'],
            ':documento'=>json_encode($doc, JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES),
            ':detalle'=>json_encode($detalle, JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES),
            ':motivo'=>'Corrección #'.$idCorreccion.': '.trim($motivo), ':usuario'=>$usuario > 0 ? $usuario : null,
        ]);
    }

    private static function impacto(
        PDO $conn,
        int $idCorreccion,
        string $tipoEntidad,
        ?int $idRegistro,
        string $accion,
        mixed $valorAnterior,
        mixed $valorNuevo,
        bool $esFinanciero
    ): void {
        if (!msp2TableExists($conn, 'msp_correcciones_impactos')) {
            throw new RuntimeException('Falta la bitácora de impactos requerida para ejecutar la corrección.');
        }

        $encode = static function (mixed $value): ?string {
            if ($value === null) {
                return null;
            }
            if (is_string($value)) {
                return $value;
            }
            $json = json_encode($value, JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('No fue posible serializar la trazabilidad de la corrección.');
            }
            return $json;
        };

        $stmt = $conn->prepare(
            'INSERT dbo.msp_correcciones_impactos
                (id_correccion,tipo_entidad,id_registro,accion_prevista,valor_anterior,valor_nuevo,es_financiero)
             VALUES
                (:correccion,:tipo,:registro,:accion,:anterior,:nuevo,:financiero)'
        );
        $stmt->bindValue(':correccion', $idCorreccion, PDO::PARAM_INT);
        $stmt->bindValue(':tipo', mb_substr(trim($tipoEntidad), 0, 50), PDO::PARAM_STR);
        if ($idRegistro === null || $idRegistro <= 0) {
            $stmt->bindValue(':registro', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':registro', $idRegistro, PDO::PARAM_INT);
        }
        $stmt->bindValue(':accion', mb_substr(trim($accion), 0, 50), PDO::PARAM_STR);
        $anterior = $encode($valorAnterior);
        $nuevo = $encode($valorNuevo);
        $stmt->bindValue(':anterior', $anterior, $anterior === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':nuevo', $nuevo, $nuevo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':financiero', $esFinanciero ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
    }
}
