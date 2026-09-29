<?php
declare(strict_types=1);

require_once __DIR__ . '/DocumentoProteccionService.php';

/** Corrección controlada de UF Base en arriendos ya documentados o contabilizados. */
final class ArriendoCorreccionService
{
    public static function ejecutar(PDO $conn, array $corr, int $usuario): array
    {
        $idCorreccion = (int) ($corr['id_correccion'] ?? 0);
        $idSnapshot = (int) ($corr['id_registro_origen'] ?? 0);
        $idContrato = (int) ($corr['id_contrato_arriendo'] ?? 0);
        $netoNuevo = self::numero($corr['valor_nuevo'] ?? null);
        $analisis = json_decode((string) ($corr['resultado_analisis'] ?? ''), true);
        $registro = is_array($analisis) && is_array($analisis['registro_exacto'] ?? null)
            ? $analisis['registro_exacto']
            : [];
        $nivelCorreccion = strtoupper(trim((string) ($corr['nivel_correcion'] ?? '')));
        $esAjusteFinanciero = $nivelCorreccion === 'AJUSTE_FINANCIERO';
        $ufBaseNueva = self::numero($registro['valor_uf_base_nuevo'] ?? null);
        $ufPeriodoAnalizada = self::numero($registro['valor_uf_periodo_correccion'] ?? null);

        if ($idCorreccion <= 0 || $idSnapshot <= 0 || $idContrato <= 0
            || $netoNuevo === null || $netoNuevo < 0 || $ufBaseNueva === null || $ufBaseNueva < 0) {
            throw new RuntimeException('La corrección controlada de arriendo no contiene valores válidos.');
        }
        if ($ufBaseNueva === 0.0 && (int) ($registro['cero_autorizado'] ?? 0) !== 1) {
            throw new RuntimeException('El arriendo cero no cuenta con una confirmación expresa.');
        }
        foreach ([
            'msp_arriendo_local_snapshot_periodo','msp_contratos_arriendo','msp_contrato_locales',
            'msp_locales','msp_tipo_modalidad_arriendo','msp_documentos_cobro',
            'msp_documentos_cobro_detalle','msp_tipo_item_documento','msp_correcciones_impactos',
        ] as $tabla) {
            if (!msp2TableExists($conn, $tabla)) {
                throw new RuntimeException('Falta la estructura requerida para corregir el arriendo de forma segura.');
            }
        }

        $transaccionPropia = !$conn->inTransaction();
        if ($transaccionPropia) {
            $conn->beginTransaction();
        }
        try {
            $claim = $conn->prepare(
                "UPDATE dbo.msp_correcciones
                 SET estado_correccion=N'EJECUTANDO',estrategia_ejecucion=:estrategia,
                     fecha_actualizacion=SYSDATETIME()
                 WHERE id_correccion=:id AND estado_correccion=N'APROBADA'"
            );
            $claim->execute([
                ':estrategia' => $esAjusteFinanciero
                    ? 'ARRIENDO_UF_AJUSTE_FINANCIERO'
                    : 'ARRIENDO_UF_CONTROLADO',
                ':id' => $idCorreccion,
            ]);
            if ($claim->rowCount() !== 1) {
                throw new RuntimeException('La corrección ya fue ejecutada o está siendo procesada.');
            }

            $snapshot = self::cargarSnapshot($conn, $idSnapshot, $idContrato);
            if ($snapshot === null) {
                throw new RuntimeException('El arriendo mensual ya no existe o no pertenece al contrato.');
            }
            if (!in_array(strtoupper((string) ($snapshot['codigo_modalidad'] ?? '')), ['UF_ESTATICO','DINAMICO_MENSUAL'], true)) {
                throw new RuntimeException('La UF Base solo puede corregirse en modalidades expresadas en UF.');
            }
            $ufPeriodo = round((float) (($snapshot['valor_uf_periodo'] ?? null)
                ?: ($snapshot['valor_uf_cierre'] ?? 0)), 6);
            if ($ufPeriodo <= 0 || ($ufPeriodoAnalizada !== null && abs($ufPeriodo - $ufPeriodoAnalizada) > 0.000001)) {
                throw new RuntimeException('La UF del período cambió o ya no es válida. Vuelve a registrar la corrección.');
            }
            $netoCalculado = round($ufBaseNueva * $ufPeriodo, 2);
            if (abs($netoCalculado - $netoNuevo) > 0.01) {
                throw new RuntimeException('El neto recalculado no coincide con la UF Base solicitada.');
            }
            $netoAnteriorEsperado = self::campoAnterior($corr['valor_anterior'] ?? null, 'monto_neto_clp');
            $netoAnterior = round((float) ($snapshot['monto_neto_clp'] ?? 0), 2);
            if ($netoAnteriorEsperado !== null && abs($netoAnterior - $netoAnteriorEsperado) > 0.01) {
                throw new RuntimeException('El arriendo mensual cambió después del análisis. Vuelve a registrar la corrección.');
            }
            if (abs($netoNuevo - $netoAnterior) <= 0.01) {
                throw new RuntimeException('El nuevo arriendo produce el mismo monto neto; no existe una corrección que aplicar.');
            }

            $idDocumento = (int) ($snapshot['id_documento_cobro'] ?? 0);
            $idDocumentoAnalizado = (int) ($registro['id_documento_cobro'] ?? 0);
            if ($idDocumento !== $idDocumentoAnalizado) {
                throw new RuntimeException('El documento asociado cambió después del análisis. Vuelve a registrar la corrección.');
            }
            if ($idDocumento > 0) {
                self::bloquearDocumento($conn, $idDocumento);
                $dependencias = self::dependenciasProtegidas($conn, $idDocumento);
                if ($dependencias !== [] && !$esAjusteFinanciero) {
                    throw new RuntimeException(
                        'El documento tiene ' . implode(', ', $dependencias)
                        . '. Debe resolverse mediante un ajuste financiero; no se modificó ningún valor.'
                    );
                }
                self::versionarDocumento($conn, $idDocumento, $idCorreccion, $usuario, (string) ($corr['motivo'] ?? ''));
            }

            $rehacerAsiento = $idDocumento > 0
                ? self::revertirContabilidad($conn, $idDocumento, $idCorreccion)
                : false;
            self::guardarAjustePeriodo($conn, $snapshot, $netoNuevo, $corr, $idCorreccion, $usuario);
            self::actualizarSnapshot($conn, $snapshot, $ufBaseNueva, $netoNuevo, $idCorreccion);
            $resultadoDocumento = null;
            if ($idDocumento > 0) {
                $resultadoDocumento = self::actualizarDocumento(
                    $conn,
                    $snapshot,
                    $idDocumento,
                    $netoAnterior,
                    $netoNuevo,
                    $idCorreccion,
                    $usuario,
                    $rehacerAsiento,
                    $esAjusteFinanciero
                );
            }

            $resultado = [
                'estrategia' => $esAjusteFinanciero
                    ? 'ARRIENDO_UF_AJUSTE_FINANCIERO'
                    : 'ARRIENDO_UF_CONTROLADO',
                'id_snapshot' => $idSnapshot,
                'uf_base_anterior' => round((float) ($snapshot['valor_base_uf'] ?? 0), 6),
                'uf_base_nueva' => $ufBaseNueva,
                'valor_uf_periodo' => $ufPeriodo,
                'monto_neto_anterior' => $netoAnterior,
                'monto_neto_nuevo' => $netoNuevo,
                'documentos_actualizados' => $idDocumento > 0 ? [$idDocumento] : [],
                'documento' => $resultadoDocumento,
            ];
            CorreccionesService::cambiarEstado(
                $conn,
                $idCorreccion,
                'EJECUTADA',
                $usuario,
                $esAjusteFinanciero
                    ? 'UF Base corregida mediante ajuste financiero; movimientos anteriores conservados.'
                    : 'UF Base corregida con regeneración documental y contable controlada.',
                $resultado
            );
            if ($transaccionPropia) {
                $conn->commit();
            }
            return $resultado;
        } catch (Throwable $e) {
            if ($transaccionPropia && $conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    private static function cargarSnapshot(PDO $conn, int $idSnapshot, int $idContrato): ?array
    {
        $stmt = $conn->prepare(
            "SELECT TOP(1) s.*,l.cdo_local,tm.codigo_modalidad,cm.valor_uf AS valor_uf_cierre,
                cm.estado_cierre,doc.id_documento_cobro,doc.numero_documento,doc.estado_documento
             FROM dbo.msp_arriendo_local_snapshot_periodo s WITH(UPDLOCK,HOLDLOCK)
             INNER JOIN dbo.msp_locales l ON l.id_local=s.id_local
             INNER JOIN dbo.msp_tipo_modalidad_arriendo tm ON tm.id_modalidad_arriendo=s.id_modalidad_aplicada
             LEFT JOIN dbo.msp_cierre_mensual cm ON cm.periodo_facturacion=s.periodo_facturacion
             OUTER APPLY(
                SELECT TOP(1) dc.id_documento_cobro,dc.numero_documento,dc.estado_documento
                FROM dbo.msp_documentos_cobro dc
                WHERE dc.id_contrato_arriendo=s.id_contrato_arriendo
                  AND dc.periodo_facturacion=s.periodo_facturacion
                  AND dc.estado_documento<>5
                ORDER BY dc.id_documento_cobro DESC
             ) doc
             WHERE s.id_snapshot_arriendo=:snapshot
               AND s.id_contrato_arriendo=:contrato
               AND s.estado_snapshot IN(1,2,3)"
        );
        $stmt->execute([':snapshot' => $idSnapshot, ':contrato' => $idContrato]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private static function bloquearDocumento(PDO $conn, int $idDocumento): void
    {
        $q = $conn->prepare(
            'SELECT estado_documento FROM dbo.msp_documentos_cobro WITH(UPDLOCK,HOLDLOCK)
             WHERE id_documento_cobro=:documento'
        );
        $q->execute([':documento' => $idDocumento]);
        $estado = $q->fetchColumn();
        if ($estado === false || (int) $estado === 5) {
            throw new RuntimeException('El documento afectado ya no está disponible para corrección.');
        }
    }

    private static function dependenciasProtegidas(PDO $conn, int $idDocumento): array
    {
        return DocumentoProteccionService::listar($conn, $idDocumento);
    }

    private static function revertirContabilidad(PDO $conn, int $idDocumento, int $idCorreccion): bool
    {
        if (!msp2TableExists($conn, 'msp_acc_asientos')) {
            return false;
        }
        $q = $conn->prepare(
            "SELECT COUNT(*) FROM dbo.msp_acc_asientos
             WHERE tabla_origen=N'msp_documentos_cobro' AND id_origen=:id AND estado_asiento=1"
        );
        $q->execute([':id' => $idDocumento]);
        if ((int) $q->fetchColumn() <= 0) {
            return false;
        }
        if (!msp2ProcedureExists($conn, 'msp_acc_revertir_origen')
            || !msp2ProcedureExists($conn, 'msp_acc_generar_asiento_documento')) {
            throw new RuntimeException('Faltan procedimientos contables para aplicar la corrección de forma segura.');
        }
        $reversa = $conn->prepare(
            "EXEC dbo.msp_acc_revertir_origen
                @tabla_origen=N'msp_documentos_cobro',@id_origen=:id,@fecha_reversa=:fecha,
                @motivo=N'Corrección controlada de UF Base'"
        );
        $reversa->execute([':id' => $idDocumento, ':fecha' => date('Y-m-d')]);
        $liberar = $conn->prepare(
            "UPDATE dbo.msp_acc_asientos
             SET hash_origen=CONCAT(hash_origen,N'-CORRECCION-',:correccion,N'-',id_asiento_contable)
             WHERE tabla_origen=N'msp_documentos_cobro' AND id_origen=:id AND estado_asiento<>1
               AND hash_origen=CONCAT(N'DOCUMENTO_EMISION',CHAR(124),N'msp_documentos_cobro',CHAR(124),:id_hash)"
        );
        $liberar->execute([':correccion' => $idCorreccion, ':id' => $idDocumento, ':id_hash' => $idDocumento]);
        return true;
    }

    private static function guardarAjustePeriodo(
        PDO $conn,
        array $snapshot,
        float $netoNuevo,
        array $corr,
        int $idCorreccion,
        int $usuario
    ): void {
        if (!msp2TableExists($conn, 'msp_arriendo_ajustes_periodo')) {
            return;
        }
        $desactivar = $conn->prepare(
            'UPDATE dbo.msp_arriendo_ajustes_periodo SET estado_ajuste=0
             WHERE id_contrato_local=:contrato_local AND periodo_facturacion=:periodo AND estado_ajuste=1'
        );
        $desactivar->execute([
            ':contrato_local' => (int) $snapshot['id_contrato_local'],
            ':periodo' => (string) $snapshot['periodo_facturacion'],
        ]);
        $insert = $conn->prepare(
            'INSERT dbo.msp_arriendo_ajustes_periodo
                (id_contrato_local,periodo_facturacion,monto_correcto_clp,motivo,id_correccion,usuario_registro)
             VALUES(:contrato_local,:periodo,:monto,:motivo,:correccion,:usuario)'
        );
        $insert->execute([
            ':contrato_local' => (int) $snapshot['id_contrato_local'],
            ':periodo' => (string) $snapshot['periodo_facturacion'],
            ':monto' => $netoNuevo,
            ':motivo' => (string) ($corr['motivo'] ?? ''),
            ':correccion' => $idCorreccion,
            ':usuario' => $usuario > 0 ? $usuario : null,
        ]);
    }

    private static function actualizarSnapshot(
        PDO $conn,
        array $snapshot,
        float $ufBaseNueva,
        float $netoNuevo,
        int $idCorreccion
    ): void {
        $ivaNuevo = round($netoNuevo * 0.19, 2);
        $totalNuevo = round($netoNuevo + $ivaNuevo, 2);
        $update = $conn->prepare(
            "UPDATE dbo.msp_arriendo_local_snapshot_periodo
             SET valor_base_uf=:uf,monto_neto_clp=:neto,monto_iva_clp=:iva,monto_total_clp=:total,
                 fuente_calculo=N'CORRECCION_SELECTIVA',fecha_actualizacion=SYSDATETIME()
             WHERE id_snapshot_arriendo=:snapshot
               AND ABS(monto_neto_clp-:neto_anterior)<0.005"
        );
        $update->execute([
            ':uf' => $ufBaseNueva,
            ':neto' => $netoNuevo,
            ':iva' => $ivaNuevo,
            ':total' => $totalNuevo,
            ':snapshot' => (int) $snapshot['id_snapshot_arriendo'],
            ':neto_anterior' => (float) $snapshot['monto_neto_clp'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('El snapshot de arriendo cambió durante la corrección.');
        }
        self::impacto($conn, $idCorreccion, 'ARRIENDO_SNAPSHOT', (int) $snapshot['id_snapshot_arriendo'], 'UPDATE', [
            'valor_base_uf' => (float) ($snapshot['valor_base_uf'] ?? 0),
            'monto_neto_clp' => (float) $snapshot['monto_neto_clp'],
            'monto_iva_clp' => (float) $snapshot['monto_iva_clp'],
            'monto_total_clp' => (float) $snapshot['monto_total_clp'],
        ], [
            'valor_base_uf' => $ufBaseNueva,
            'monto_neto_clp' => $netoNuevo,
            'monto_iva_clp' => $ivaNuevo,
            'monto_total_clp' => $totalNuevo,
        ], (int) ($snapshot['id_documento_cobro'] ?? 0) > 0);
    }

    private static function actualizarDocumento(
        PDO $conn,
        array $snapshot,
        int $idDocumento,
        float $netoAnterior,
        float $netoNuevo,
        int $idCorreccion,
        int $usuario,
        bool $rehacerAsiento,
        bool $esAjusteFinanciero
    ): array {
        $qDocumento = $conn->prepare(
            'SELECT subtotal_arriendo,subtotal_servicios,monto_total,saldo_pendiente,estado_documento
             FROM dbo.msp_documentos_cobro WITH(UPDLOCK,HOLDLOCK)
             WHERE id_documento_cobro=:documento'
        );
        $qDocumento->execute([':documento' => $idDocumento]);
        $documento = $qDocumento->fetch(PDO::FETCH_ASSOC);
        if ($documento === false || (int) ($documento['estado_documento'] ?? 0) === 5) {
            throw new RuntimeException('El documento afectado ya no está disponible para corrección.');
        }
        if (!$esAjusteFinanciero
            && abs((float) $documento['monto_total'] - (float) $documento['saldo_pendiente']) > 0.01) {
            throw new RuntimeException('El documento presenta un saldo financiero distinto del total. Debe corregirse mediante un ajuste financiero.');
        }

        $qTipo = $conn->query("SELECT TOP(1) id_tipo_item_documento FROM dbo.msp_tipo_item_documento WHERE codigo_item=N'ARRIENDO'");
        $idTipoArriendo = (int) ($qTipo->fetchColumn() ?: 0);
        if ($idTipoArriendo <= 0) {
            throw new RuntimeException('No existe el tipo de detalle ARRIENDO.');
        }
        $descripcion = 'Arriendo local ' . trim((string) ($snapshot['cdo_local'] ?? ''));
        $qDetalle = $conn->prepare(
            'SELECT id_detalle_documento,subtotal
             FROM dbo.msp_documentos_cobro_detalle WITH(UPDLOCK,HOLDLOCK)
             WHERE id_documento_cobro=:documento AND id_tipo_item_documento=:tipo
               AND descripcion_item=:descripcion'
        );
        $qDetalle->execute([
            ':documento' => $idDocumento,
            ':tipo' => $idTipoArriendo,
            ':descripcion' => $descripcion,
        ]);
        $detalles = $qDetalle->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($detalles) > 1) {
            throw new RuntimeException('El documento contiene más de una línea de arriendo para el local seleccionado.');
        }
        $detalle = $detalles[0] ?? null;
        if ($detalle !== null && abs((float) $detalle['subtotal'] - $netoAnterior) > 0.01) {
            throw new RuntimeException('La línea de arriendo del documento no coincide con el snapshot mensual.');
        }
        if ($detalle === null && abs($netoAnterior) > 0.01) {
            throw new RuntimeException('No se encontró la línea exacta de arriendo del local en el documento.');
        }

        if ($netoNuevo <= 0.005) {
            if ($detalle !== null) {
                $delete = $conn->prepare('DELETE dbo.msp_documentos_cobro_detalle WHERE id_detalle_documento=:detalle');
                $delete->execute([':detalle' => (int) $detalle['id_detalle_documento']]);
            }
        } elseif ($detalle !== null) {
            $updateDetalle = $conn->prepare(
                'UPDATE dbo.msp_documentos_cobro_detalle
                 SET cantidad=1,valor_unitario=:valor_unitario,subtotal=:subtotal
                 WHERE id_detalle_documento=:detalle'
            );
            $updateDetalle->execute([
                ':valor_unitario' => $netoNuevo,
                ':subtotal' => $netoNuevo,
                ':detalle' => (int) $detalle['id_detalle_documento'],
            ]);
            if ($updateDetalle->rowCount() !== 1) {
                throw new RuntimeException('La línea de arriendo cambió durante la corrección.');
            }
        } else {
            $qOrden = $conn->prepare(
                'SELECT ISNULL(MAX(d.orden_item),0)+1
                 FROM dbo.msp_documentos_cobro_detalle d
                 WHERE d.id_documento_cobro=:documento AND d.id_tipo_item_documento=:tipo'
            );
            $qOrden->execute([':documento' => $idDocumento, ':tipo' => $idTipoArriendo]);
            $orden = max(1, (int) $qOrden->fetchColumn());
            $insertDetalle = $conn->prepare(
                'INSERT dbo.msp_documentos_cobro_detalle
                    (id_documento_cobro,orden_item,id_tipo_item_documento,descripcion_item,cantidad,valor_unitario,subtotal,id_cobro_servicio)
                 VALUES(:documento,:orden,:tipo,:descripcion,1,:valor_unitario,:subtotal,NULL)'
            );
            $insertDetalle->execute([
                ':documento' => $idDocumento,
                ':orden' => $orden,
                ':tipo' => $idTipoArriendo,
                ':descripcion' => $descripcion,
                ':valor_unitario' => $netoNuevo,
                ':subtotal' => $netoNuevo,
            ]);
        }

        $nuevoSubtotalArriendo = round((float) $documento['subtotal_arriendo'] - $netoAnterior + $netoNuevo, 2);
        $nuevoTotal = round($nuevoSubtotalArriendo * 1.19 + (float) $documento['subtotal_servicios'], 2);
        $deltaTotal = round($nuevoTotal - (float) $documento['monto_total'], 2);
        $saldoCalculado = round((float) $documento['saldo_pendiente'] + $deltaTotal, 2);
        $saldoFavorGenerado = $esAjusteFinanciero && $saldoCalculado < -0.009
            ? round(abs($saldoCalculado), 2)
            : 0.0;
        $nuevoSaldo = $esAjusteFinanciero ? max(0.0, $saldoCalculado) : $saldoCalculado;
        if ($nuevoSubtotalArriendo < -0.009 || $nuevoTotal < -0.009 || $nuevoSaldo < -0.009) {
            throw new RuntimeException('La corrección dejaría valores negativos en el documento.');
        }
        $qSumaDetalle = $conn->prepare(
            "SELECT ROUND(ISNULL(SUM(d.subtotal),0),2)
             FROM dbo.msp_documentos_cobro_detalle d
             INNER JOIN dbo.msp_tipo_item_documento ti ON ti.id_tipo_item_documento=d.id_tipo_item_documento
             WHERE d.id_documento_cobro=:documento AND ti.codigo_item=N'ARRIENDO'"
        );
        $qSumaDetalle->execute([':documento' => $idDocumento]);
        if (abs((float) $qSumaDetalle->fetchColumn() - $nuevoSubtotalArriendo) > 0.01) {
            throw new RuntimeException('El detalle de arriendo no concilia con el encabezado del documento.');
        }
        $nuevoEstado = $nuevoSaldo <= 0.009
            ? 4
            : ($nuevoSaldo < $nuevoTotal - 0.009 ? 3 : 2);
        $updateDocumento = $conn->prepare(
            'UPDATE dbo.msp_documentos_cobro
             SET subtotal_arriendo=:arriendo,monto_total=:total,saldo_pendiente=:saldo,
                 estado_documento=:estado
             WHERE id_documento_cobro=:documento'
        );
        $updateDocumento->execute([
            ':arriendo' => $nuevoSubtotalArriendo,
            ':total' => $nuevoTotal,
            ':saldo' => $nuevoSaldo,
            ':estado' => $nuevoEstado,
            ':documento' => $idDocumento,
        ]);
        if ($updateDocumento->rowCount() !== 1) {
            throw new RuntimeException('El documento cambió durante la corrección.');
        }
        if ($rehacerAsiento) {
            $asiento = $conn->prepare('EXEC dbo.msp_acc_generar_asiento_documento @id_documento_cobro=:documento');
            $asiento->execute([':documento' => $idDocumento]);
        }
        $idMovimientoSaldoFavor = $saldoFavorGenerado > 0
            ? self::registrarSaldoFavorCorreccion(
                $conn,
                (int) $snapshot['id_tienda'],
                (string) $snapshot['periodo_facturacion'],
                $idDocumento,
                $idCorreccion,
                $saldoFavorGenerado
            )
            : null;
        require_once __DIR__ . '/DocumentoCobroTrazabilidadService.php';
        DocumentoCobroTrazabilidadService::registrar($conn, $idDocumento, $esAjusteFinanciero ? 'AJUSTE' : 'RECALCULO', 'SISTEMA', $usuario, [
            'id_correccion' => $idCorreccion,
            'tipo' => 'ARRIENDO_UF',
            'delta_neto' => round($netoNuevo - $netoAnterior, 2),
            'delta_documento' => $deltaTotal,
            'saldo_favor_generado' => $saldoFavorGenerado,
        ]);
        self::impacto($conn, $idCorreccion, 'DOCUMENTO_COBRO', $idDocumento, $esAjusteFinanciero ? 'AJUSTE_FINANCIERO' : 'RECALCULO', [
            'subtotal_arriendo' => (float) $documento['subtotal_arriendo'],
            'monto_total' => (float) $documento['monto_total'],
            'saldo_pendiente' => (float) $documento['saldo_pendiente'],
            'estado_documento' => (int) $documento['estado_documento'],
        ], [
            'subtotal_arriendo' => $nuevoSubtotalArriendo,
            'monto_total' => $nuevoTotal,
            'saldo_pendiente' => $nuevoSaldo,
            'estado_documento' => $nuevoEstado,
            'saldo_favor_generado' => $saldoFavorGenerado,
        ], true);
        return [
            'id_documento' => $idDocumento,
            'subtotal_arriendo' => $nuevoSubtotalArriendo,
            'monto_total' => $nuevoTotal,
            'saldo_pendiente' => $nuevoSaldo,
            'delta_total' => $deltaTotal,
            'saldo_favor_generado' => $saldoFavorGenerado,
            'id_movimiento_saldo_favor' => $idMovimientoSaldoFavor,
        ];
    }

    private static function registrarSaldoFavorCorreccion(
        PDO $conn,
        int $idTienda,
        string $periodo,
        int $idDocumento,
        int $idCorreccion,
        float $monto
    ): int {
        if ($idTienda <= 0 || $idDocumento <= 0 || $idCorreccion <= 0 || $monto <= 0) {
            throw new RuntimeException('No fue posible determinar el saldo a favor de la corrección.');
        }
        if (!msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda')) {
            throw new RuntimeException('Falta el libro de saldo a favor requerido para completar la corrección.');
        }
        $observacion = 'Excedente por corrección UF Base #' . $idCorreccion
            . ' sobre documento #' . $idDocumento;
        $insert = $conn->prepare(
            'DECLARE @out TABLE(id_movimiento_saldo_favor INT);
             INSERT dbo.msp_movimientos_saldo_favor_tienda
                (id_tienda,fecha_movimiento,tipo_movimiento,monto_movimiento,
                 id_documento_cobro,observaciones)
             OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out(id_movimiento_saldo_favor)
             VALUES(:tienda,CONVERT(date,SYSDATETIME()),5,:monto,:documento,:observacion);
             SELECT TOP(1) id_movimiento_saldo_favor FROM @out;'
        );
        $insert->execute([
            ':tienda' => $idTienda,
            ':monto' => $monto,
            ':documento' => $idDocumento,
            ':observacion' => $observacion,
        ]);
        $idMovimiento = self::primerEscalar($insert);
        if ($idMovimiento <= 0) {
            throw new RuntimeException('No fue posible identificar el saldo a favor creado por la corrección.');
        }

        if (msp2TableExists($conn, 'msp_saldo_favor_periodo_items')) {
            $item = $conn->prepare(
                'INSERT dbo.msp_saldo_favor_periodo_items
                    (periodo_facturacion,id_tienda,fecha_movimiento,monto_original,
                     id_movimiento_saldo_favor,observaciones)
                 VALUES(:periodo,:tienda,CONVERT(date,SYSDATETIME()),:monto,:movimiento,:observacion)'
            );
            $item->execute([
                ':periodo' => $periodo,
                ':tienda' => $idTienda,
                ':monto' => $monto,
                ':movimiento' => $idMovimiento,
                ':observacion' => $observacion,
            ]);
        }
        self::impacto(
            $conn,
            $idCorreccion,
            'SALDO_FAVOR',
            $idMovimiento,
            'CREACION_EXCEDENTE',
            null,
            ['monto' => $monto, 'id_tienda' => $idTienda, 'id_documento' => $idDocumento],
            true
        );
        return $idMovimiento;
    }

    private static function primerEscalar(PDOStatement $statement): int
    {
        do {
            if ($statement->columnCount() > 0) {
                $value = $statement->fetchColumn();
                if ($value !== false) {
                    return (int) $value;
                }
            }
        } while ($statement->nextRowset());
        return 0;
    }

    private static function versionarDocumento(PDO $conn, int $idDocumento, int $idCorreccion, int $usuario, string $motivo): void
    {
        $qDoc = $conn->prepare('SELECT * FROM dbo.msp_documentos_cobro WHERE id_documento_cobro=:documento');
        $qDoc->execute([':documento' => $idDocumento]);
        $documento = $qDoc->fetch(PDO::FETCH_ASSOC);
        if ($documento === false) {
            throw new RuntimeException('El documento afectado ya no existe.');
        }
        $qDetalle = $conn->prepare(
            'SELECT * FROM dbo.msp_documentos_cobro_detalle
             WHERE id_documento_cobro=:documento ORDER BY orden_item,id_detalle_documento'
        );
        $qDetalle->execute([':documento' => $idDocumento]);
        $detalle = $qDetalle->fetchAll(PDO::FETCH_ASSOC) ?: [];
        self::impacto($conn, $idCorreccion, 'DOCUMENTO_COBRO', $idDocumento, 'VERSION_ANTERIOR', [
            'documento' => $documento,
            'detalle' => $detalle,
        ], null, true);
        if (!msp2TableExists($conn, 'msp_documentos_cobro_versiones')) {
            return;
        }
        $sql = "IF NOT EXISTS (
                    SELECT 1 FROM dbo.msp_documentos_cobro_versiones
                    WHERE id_documento_cobro_original=:buscar
                )
                INSERT dbo.msp_documentos_cobro_versiones(
                    id_documento_cobro_original,uuid_documento,id_contrato_arriendo,id_tienda,
                    periodo_facturacion,numero_documento,estado_documento_original,monto_total_original,
                    saldo_pendiente_original,documento_json,detalle_json,motivo_version,id_usuario
                )
                VALUES(
                    :id,:uuid,:contrato,:tienda,:periodo,:numero,:estado,:monto,:saldo,
                    :documento,:detalle,:motivo,:usuario
                )";
        $insert = $conn->prepare($sql);
        $insert->execute([
            ':buscar' => $idDocumento,
            ':id' => $idDocumento,
            ':uuid' => $documento['uuid_documento'] ?? null,
            ':contrato' => $documento['id_contrato_arriendo'] ?? null,
            ':tienda' => (int) $documento['id_tienda'],
            ':periodo' => (string) $documento['periodo_facturacion'],
            ':numero' => $documento['numero_documento'] ?? null,
            ':estado' => (int) $documento['estado_documento'],
            ':monto' => (float) $documento['monto_total'],
            ':saldo' => (float) $documento['saldo_pendiente'],
            ':documento' => json_encode($documento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':motivo' => 'Corrección #' . $idCorreccion . ': ' . trim($motivo),
            ':usuario' => $usuario > 0 ? $usuario : null,
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
        $encode = static function (mixed $value): ?string {
            if ($value === null || is_string($value)) {
                return $value;
            }
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('No fue posible serializar la trazabilidad de la corrección.');
            }
            return $json;
        };
        $stmt = $conn->prepare(
            'INSERT dbo.msp_correcciones_impactos
                (id_correccion,tipo_entidad,id_registro,accion_prevista,valor_anterior,valor_nuevo,es_financiero)
             VALUES(:correccion,:tipo,:registro,:accion,:anterior,:nuevo,:financiero)'
        );
        $stmt->bindValue(':correccion', $idCorreccion, PDO::PARAM_INT);
        $stmt->bindValue(':tipo', mb_substr(trim($tipoEntidad), 0, 50), PDO::PARAM_STR);
        $stmt->bindValue(':registro', $idRegistro, $idRegistro === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':accion', mb_substr(trim($accion), 0, 50), PDO::PARAM_STR);
        $anterior = $encode($valorAnterior);
        $nuevo = $encode($valorNuevo);
        $stmt->bindValue(':anterior', $anterior, $anterior === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':nuevo', $nuevo, $nuevo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':financiero', $esFinanciero ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function numero(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        $texto = trim((string) $raw);
        if ($texto === '') {
            return null;
        }
        $decoded = json_decode($texto, true);
        if (is_numeric($decoded)) {
            return (float) $decoded;
        }
        $normalizado = str_replace(',', '.', $texto);
        return is_numeric($normalizado) ? (float) $normalizado : null;
    }

    private static function campoAnterior(mixed $raw, string $campo): ?float
    {
        if (is_array($raw) && isset($raw[$campo]) && is_numeric($raw[$campo])) {
            return (float) $raw[$campo];
        }
        $texto = trim((string) $raw);
        if ($texto === '') {
            return null;
        }
        $decoded = json_decode($texto, true);
        if (is_array($decoded) && isset($decoded[$campo]) && is_numeric($decoded[$campo])) {
            return (float) $decoded[$campo];
        }
        if (preg_match('/' . preg_quote($campo, '/') . '\\s*[:=]\\s*([0-9]+(?:[\\.,][0-9]+)?)/iu', $texto, $match) === 1) {
            return (float) str_replace(',', '.', $match[1]);
        }
        return null;
    }
}
