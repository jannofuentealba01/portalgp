<?php
declare(strict_types=1);

require_once __DIR__ . '/DocumentoProteccionService.php';

/** Corrige una multa manual ya incluida en un documento abierto y sin movimientos protegidos. */
final class MultaCorreccionService
{
    public static function ejecutar(PDO $conn, array $corr, int $usuario): array
    {
        $idCorreccion = (int) ($corr['id_correccion'] ?? 0);
        $idCargo = (int) ($corr['id_registro_origen'] ?? 0);
        $idContrato = (int) ($corr['id_contrato_arriendo'] ?? 0);
        $nuevo = self::numero($corr['valor_nuevo'] ?? null);
        if ($idCorreccion <= 0 || $idCargo <= 0 || $idContrato <= 0 || $nuevo === null || $nuevo < 0) {
            throw new RuntimeException('La corrección de multa no contiene un monto válido.');
        }
        foreach (['msp_cargos_contrato_local','msp_cargos_salida','msp_tipos_cargo_salida',
                  'msp_contrato_locales','msp_documentos_cobro','msp_documentos_cobro_detalle',
                  'msp_tipo_item_documento'] as $tabla) {
            if (!msp2TableExists($conn, $tabla)) {
                throw new RuntimeException('Falta la estructura requerida para corregir la multa documentada.');
            }
        }

        $transaccionPropia = !$conn->inTransaction();
        if ($transaccionPropia) {
            $conn->beginTransaction();
        }
        try {
            $claim = $conn->prepare(
                "UPDATE dbo.msp_correcciones
                 SET estado_correccion=N'EJECUTANDO',estrategia_ejecucion=N'MULTA_DOCUMENTO_CONTROLADA',
                     fecha_actualizacion=SYSDATETIME()
                 WHERE id_correccion=:id AND estado_correccion=N'APROBADA'"
            );
            $claim->execute([':id'=>$idCorreccion]);
            if ($claim->rowCount() !== 1) {
                throw new RuntimeException('La corrección ya fue ejecutada o está siendo procesada.');
            }

            $cargo = self::cargarCargo($conn, $idCargo, $idContrato);
            if ($cargo === null) {
                throw new RuntimeException('La multa ya no existe o no pertenece al contrato indicado.');
            }
            self::validarCargo($conn, $cargo, $corr, $nuevo);

            $idDocumento = (int) $cargo['id_documento_cobro'];
            $idCargoLegacy = (int) $cargo['id_cargo_salida_legacy'];
            $detalle = self::cargarDetalle($conn, $idDocumento, $idCargoLegacy);
            if ($detalle === null) {
                throw new RuntimeException('No fue posible identificar de forma segura el ítem de multa dentro del documento. No se modificó ningún dato.');
            }

            $montoAnterior = round((float) $cargo['monto_cargo'], 2);
            if (abs((float) $detalle['subtotal'] - $montoAnterior) > 0.01) {
                throw new RuntimeException('El detalle del documento no coincide con el monto actual de la multa. Vuelve a revisar el registro.');
            }
            $delta = round($nuevo - $montoAnterior, 2);
            $anular = abs($nuevo) < 0.005;
            self::versionarDocumento($conn, $idDocumento, $idCorreccion, $usuario, (string) ($corr['motivo'] ?? ''));

            if ($anular) {
                $del = $conn->prepare('DELETE FROM dbo.msp_documentos_cobro_detalle WHERE id_detalle_documento=:detalle AND id_documento_cobro=:documento');
                $del->execute([':detalle'=>(int) $detalle['id_detalle_documento'], ':documento'=>$idDocumento]);
                if ($del->rowCount() !== 1) {
                    throw new RuntimeException('El ítem de multa cambió durante la corrección. No se modificó ningún dato.');
                }
                $updLegacy = $conn->prepare('UPDATE dbo.msp_cargos_salida SET estado_cargo=5 WHERE id_cargo_salida=:id AND id_documento_cobro=:documento AND estado_cargo=3');
                $updLegacy->execute([':id'=>$idCargoLegacy, ':documento'=>$idDocumento]);
                $updCargo = $conn->prepare('UPDATE dbo.msp_cargos_contrato_local SET estado_cargo=5 WHERE id_cargo_contrato_local=:id AND id_documento_cobro=:documento');
                $updCargo->execute([':id'=>$idCargo, ':documento'=>$idDocumento]);
            } else {
                $updDetalle = $conn->prepare('UPDATE dbo.msp_documentos_cobro_detalle
                    SET cantidad=1,valor_unitario=:monto,subtotal=:subtotal
                    WHERE id_detalle_documento=:detalle AND id_documento_cobro=:documento');
                $updDetalle->execute([
                    ':monto'=>$nuevo,
                    ':subtotal'=>$nuevo,
                    ':detalle'=>(int) $detalle['id_detalle_documento'],
                    ':documento'=>$idDocumento,
                ]);
                if ($updDetalle->rowCount() !== 1) {
                    throw new RuntimeException('El ítem de multa cambió durante la corrección. No se modificó ningún dato.');
                }
                $updLegacy = $conn->prepare('UPDATE dbo.msp_cargos_salida SET monto_cargo=:monto WHERE id_cargo_salida=:id AND id_documento_cobro=:documento AND estado_cargo=3');
                $updLegacy->execute([':monto'=>$nuevo, ':id'=>$idCargoLegacy, ':documento'=>$idDocumento]);
                $updCargo = $conn->prepare('UPDATE dbo.msp_cargos_contrato_local SET monto_cargo=:monto WHERE id_cargo_contrato_local=:id AND id_documento_cobro=:documento');
                $updCargo->execute([':monto'=>$nuevo, ':id'=>$idCargo, ':documento'=>$idDocumento]);
            }
            if ($updLegacy->rowCount() !== 1) {
                throw new RuntimeException('La multa original cambió durante la corrección. No se modificó ningún dato.');
            }
            if ($updCargo->rowCount() !== 1) {
                throw new RuntimeException('El registro mensual de la multa cambió durante la corrección. No se modificó ningún dato.');
            }

            $documento = self::actualizarDocumento($conn, $idDocumento, $delta);
            self::registrarImpacto($conn, $idCorreccion, 'CARGO', $idCargo, $anular ? 'ANULAR' : 'UPDATE', [
                'monto'=>$montoAnterior,
                'estado_cargo'=>(int) $cargo['estado_cargo'],
            ], [
                'monto'=>$nuevo,
                'estado_cargo'=>$anular ? 5 : (int) $cargo['estado_cargo'],
            ]);
            self::registrarImpacto($conn, $idCorreccion, 'DOCUMENTO_COBRO', $idDocumento, 'RECALCULO', [
                'monto_total'=>(float) $cargo['monto_documento'],
                'saldo_pendiente'=>(float) $cargo['saldo_documento'],
            ], $documento);

            require_once __DIR__ . '/DocumentoCobroTrazabilidadService.php';
            DocumentoCobroTrazabilidadService::registrar($conn, $idDocumento, 'RECALCULO', 'SISTEMA', $usuario > 0 ? $usuario : null, [
                'id_correccion'=>$idCorreccion,
                'tipo'=>'MULTA',
                'id_cargo'=>$idCargo,
                'monto_anterior'=>$montoAnterior,
                'monto_nuevo'=>$nuevo,
                'anulada'=>$anular,
                'delta_documento'=>$delta,
            ]);
            CorreccionesService::cambiarEstado(
                $conn,
                $idCorreccion,
                'EJECUTADA',
                $usuario,
                $anular ? 'Multa anulada y documento actualizado.' : 'Multa y documento actualizados.',
                ['estrategia'=>'MULTA_DOCUMENTO_CONTROLADA','id_cargo'=>$idCargo,'id_documento'=>$idDocumento]
            );
            msp2SyncHistoricalDebt($conn, $idContrato);
            if ($transaccionPropia) {
                $conn->commit();
            }
        } catch (Throwable $e) {
            if ($transaccionPropia && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }

        return [
            'id_cargo'=>$idCargo,
            'monto_anterior'=>$montoAnterior,
            'monto_nuevo'=>$nuevo,
            'anulada'=>$anular,
            'documentos_actualizados'=>[$idDocumento],
        ];
    }

    private static function cargarCargo(PDO $conn, int $idCargo, int $idContrato): ?array
    {
        $stmt = $conn->prepare(
            "SELECT ccl.id_cargo_contrato_local,ccl.id_cargo_salida_legacy,ccl.id_documento_cobro,
                    ccl.monto_cargo,ccl.estado_cargo,ccl.monto_aplicado_garantia,ccl.monto_pagado_directo,
                    ccl.periodo_referencia,ccl.fecha_cargo,
                    UPPER(LTRIM(RTRIM(tc.codigo_tipo_cargo))) codigo_tipo_cargo,
                    ISNULL(cm.estado_cierre,0) estado_cierre,
                    dc.estado_documento,dc.monto_total monto_documento,dc.saldo_pendiente saldo_documento
             FROM dbo.msp_cargos_contrato_local ccl WITH(UPDLOCK,HOLDLOCK)
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local=ccl.id_contrato_local
             INNER JOIN dbo.msp_tipos_cargo_salida tc ON tc.id_tipo_cargo_salida=ccl.id_tipo_cargo_salida
             LEFT JOIN dbo.msp_cierre_mensual cm
                ON cm.periodo_facturacion=COALESCE(ccl.periodo_referencia,DATEFROMPARTS(YEAR(ccl.fecha_cargo),MONTH(ccl.fecha_cargo),1))
             LEFT JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=ccl.id_documento_cobro
             WHERE ccl.id_cargo_contrato_local=:cargo AND cl.id_contrato_arriendo=:contrato"
        );
        $stmt->execute([':cargo'=>$idCargo, ':contrato'=>$idContrato]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function validarCargo(PDO $conn, array $cargo, array $corr, float $nuevo): void
    {
        if ((string) ($cargo['codigo_tipo_cargo'] ?? '') !== 'MULTA') {
            throw new RuntimeException('La corrección controlada está habilitada solamente para multas.');
        }
        if ((int) ($cargo['id_cargo_salida_legacy'] ?? 0) <= 0) {
            throw new RuntimeException('La multa no conserva una referencia segura al registro original y requiere revisión manual.');
        }
        if ((int) ($cargo['id_documento_cobro'] ?? 0) <= 0
            || (int) ($cargo['estado_cargo'] ?? 0) !== 3
            || !in_array((int) ($cargo['estado_documento'] ?? 0), [1,2], true)) {
            throw new RuntimeException('La multa o su documento cambiaron después del análisis. Vuelve a registrar la corrección.');
        }
        if (in_array((int) ($cargo['estado_cierre'] ?? 0), [3,5], true)) {
            throw new RuntimeException('El período se cerró después del análisis. La multa no se modificó.');
        }
        if ((float) ($cargo['monto_aplicado_garantia'] ?? 0) > 0.005
            || (float) ($cargo['monto_pagado_directo'] ?? 0) > 0.005
            || DocumentoProteccionService::listar($conn, (int) $cargo['id_documento_cobro']) !== []) {
            throw new RuntimeException('El documento adquirió pagos, garantía, saldo a favor o envíos. La multa no se modificó.');
        }
        if (msp2TableExists($conn, 'msp_acc_asientos')) {
            $asiento = $conn->prepare("SELECT COUNT(*) FROM dbo.msp_acc_asientos WHERE tabla_origen=N'msp_documentos_cobro' AND id_origen=:documento AND estado_asiento=1");
            $asiento->execute([':documento'=>(int) $cargo['id_documento_cobro']]);
            if ((int) $asiento->fetchColumn() > 0) {
                throw new RuntimeException('El documento adquirió un asiento contable activo. La multa no se modificó.');
            }
        }
        $esperado = self::campoAnterior($corr['valor_anterior'] ?? null, 'monto_cargo');
        if ($esperado !== null && abs((float) $cargo['monto_cargo'] - $esperado) > 0.01) {
            throw new RuntimeException('La multa cambió después del análisis. Vuelve a registrar la corrección.');
        }
        if (abs($nuevo - (float) $cargo['monto_cargo']) < 0.005) {
            throw new RuntimeException('El nuevo monto es igual al actual; no existe una corrección que aplicar.');
        }
    }

    private static function cargarDetalle(PDO $conn, int $idDocumento, int $idCargoLegacy): ?array
    {
        $rank = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_cargos_salida WHERE id_documento_cobro=:documento AND id_cargo_salida<=:cargo');
        $rank->execute([':documento'=>$idDocumento, ':cargo'=>$idCargoLegacy]);
        $orden = 3000 + (int) $rank->fetchColumn();
        if ($orden <= 3000) {
            return null;
        }
        $stmt = $conn->prepare("SELECT TOP(1) dcd.id_detalle_documento,dcd.orden_item,dcd.subtotal,dcd.descripcion_item
            FROM dbo.msp_documentos_cobro_detalle dcd WITH(UPDLOCK,HOLDLOCK)
            INNER JOIN dbo.msp_tipo_item_documento tid ON tid.id_tipo_item_documento=dcd.id_tipo_item_documento
            WHERE dcd.id_documento_cobro=:documento AND dcd.orden_item=:orden
              AND dcd.id_cobro_servicio IS NULL AND UPPER(LTRIM(RTRIM(tid.codigo_item)))=N'MULTA'");
        $stmt->execute([':documento'=>$idDocumento, ':orden'=>$orden]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function actualizarDocumento(PDO $conn, int $idDocumento, float $delta): array
    {
        $stmt = $conn->prepare('SELECT subtotal_servicios,monto_total,saldo_pendiente,estado_documento
            FROM dbo.msp_documentos_cobro WITH(UPDLOCK,HOLDLOCK) WHERE id_documento_cobro=:documento');
        $stmt->execute([':documento'=>$idDocumento]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($doc === false || !in_array((int) ($doc['estado_documento'] ?? 0), [1,2], true)) {
            throw new RuntimeException('El documento dejó de estar disponible para esta corrección.');
        }
        $subtotal = round((float) $doc['subtotal_servicios'] + $delta, 2);
        $total = round((float) $doc['monto_total'] + $delta, 2);
        $saldo = round((float) $doc['saldo_pendiente'] + $delta, 2);
        if ($subtotal < -0.009 || $total < -0.009 || $saldo < -0.009) {
            throw new RuntimeException('La corrección dejaría valores negativos en el documento. No se modificó ningún dato.');
        }
        $estado = $saldo <= 0.009 ? 4 : ($saldo < $total - 0.009 ? 3 : 2);
        $upd = $conn->prepare('UPDATE dbo.msp_documentos_cobro
            SET subtotal_servicios=:subtotal,monto_total=:total,saldo_pendiente=:saldo,estado_documento=:estado
            WHERE id_documento_cobro=:documento');
        $upd->execute([':subtotal'=>$subtotal, ':total'=>$total, ':saldo'=>$saldo, ':estado'=>$estado, ':documento'=>$idDocumento]);
        if ($upd->rowCount() !== 1) {
            throw new RuntimeException('El documento cambió durante la corrección. No se modificó ningún dato.');
        }
        return ['subtotal_servicios'=>$subtotal,'monto_total'=>$total,'saldo_pendiente'=>$saldo,'estado_documento'=>$estado,'delta'=>$delta];
    }

    private static function versionarDocumento(PDO $conn, int $idDocumento, int $idCorreccion, int $usuario, string $motivo): void
    {
        if (!msp2TableExists($conn, 'msp_documentos_cobro_versiones')) {
            return;
        }
        $qDoc = $conn->prepare('SELECT * FROM dbo.msp_documentos_cobro WHERE id_documento_cobro=:documento');
        $qDoc->execute([':documento'=>$idDocumento]);
        $doc = $qDoc->fetch(PDO::FETCH_ASSOC);
        if ($doc === false) {
            throw new RuntimeException('El documento afectado ya no existe.');
        }
        $qDetalle = $conn->prepare('SELECT * FROM dbo.msp_documentos_cobro_detalle WHERE id_documento_cobro=:documento ORDER BY orden_item,id_detalle_documento');
        $qDetalle->execute([':documento'=>$idDocumento]);
        $detalle = $qDetalle->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt = $conn->prepare("IF NOT EXISTS (SELECT 1 FROM dbo.msp_documentos_cobro_versiones WHERE id_documento_cobro_original=:buscar)
            INSERT dbo.msp_documentos_cobro_versiones(id_documento_cobro_original,uuid_documento,
                id_contrato_arriendo,id_tienda,periodo_facturacion,numero_documento,estado_documento_original,
                monto_total_original,saldo_pendiente_original,documento_json,detalle_json,motivo_version,id_usuario)
            VALUES(:id,:uuid,:contrato,:tienda,:periodo,:numero,:estado,:monto,:saldo,:documento,:detalle,:motivo,:usuario)");
        $stmt->execute([
            ':buscar'=>$idDocumento,
            ':id'=>$idDocumento,
            ':uuid'=>$doc['uuid_documento'] ?? null,
            ':contrato'=>$doc['id_contrato_arriendo'] ?? null,
            ':tienda'=>(int) $doc['id_tienda'],
            ':periodo'=>(string) $doc['periodo_facturacion'],
            ':numero'=>$doc['numero_documento'] ?? null,
            ':estado'=>(int) $doc['estado_documento'],
            ':monto'=>(float) $doc['monto_total'],
            ':saldo'=>(float) $doc['saldo_pendiente'],
            ':documento'=>json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':detalle'=>json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':motivo'=>'Corrección #'.$idCorreccion.': '.trim($motivo),
            ':usuario'=>$usuario > 0 ? $usuario : null,
        ]);
    }

    private static function registrarImpacto(PDO $conn, int $idCorreccion, string $tipo, int $idRegistro, string $accion, mixed $anterior, mixed $nuevo): void
    {
        if (!msp2TableExists($conn, 'msp_correcciones_impactos')) {
            throw new RuntimeException('Falta la bitácora de impactos requerida para corregir la multa.');
        }
        $encode = static fn (mixed $valor): string => is_string($valor)
            ? $valor
            : (string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $conn->prepare("INSERT dbo.msp_correcciones_impactos
            (id_correccion,tipo_entidad,id_registro,accion_prevista,valor_anterior,valor_nuevo,es_financiero)
            VALUES(:correccion,:tipo,:registro,:accion,:anterior,:nuevo,1)");
        $stmt->execute([
            ':correccion'=>$idCorreccion,
            ':tipo'=>$tipo,
            ':registro'=>$idRegistro,
            ':accion'=>$accion,
            ':anterior'=>$encode($anterior),
            ':nuevo'=>$encode($nuevo),
        ]);
    }

    private static function numero(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return is_finite((float) $valor) ? round((float) $valor, 2) : null;
        }
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }
        $texto = str_replace(['$', ' '], '', $texto);
        if (str_contains($texto, ',') && str_contains($texto, '.')) {
            $texto = str_replace('.', '', $texto);
        }
        $texto = str_replace(',', '.', $texto);
        return is_numeric($texto) && is_finite((float) $texto) ? round((float) $texto, 2) : null;
    }

    private static function campoAnterior(mixed $valor, string $campo): ?float
    {
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }
        $decoded = json_decode($texto, true);
        if (is_string($decoded)) {
            $texto = $decoded;
        } elseif (is_array($decoded) && isset($decoded[$campo]) && is_numeric($decoded[$campo])) {
            return (float) $decoded[$campo];
        }
        if (preg_match('/(?:^|;\\s*)'.preg_quote($campo, '/').'\\s*=\\s*(-?[0-9]+(?:[.,][0-9]+)?)/i', $texto, $match) === 1) {
            return (float) str_replace(',', '.', $match[1]);
        }
        return null;
    }
}
