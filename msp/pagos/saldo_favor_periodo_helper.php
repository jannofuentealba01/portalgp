<?php
declare(strict_types=1);

function msp2PagoResolveDateYmd(?string $raw): ?string
{
    $value = trim((string) $raw);
    if ($value === '') {
        return null;
    }
    $value = substr($value, 0, 10);
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
}

/**
 * Retorna el estado del cierre mensual de un período.
 *
 * Null significa que el período todavía no fue creado. Esta distinción es
 * importante: un saldo a favor manual puede quedar preparado para un período
 * futuro, mientras que un excedente automático debe esperar a que ese período
 * exista y esté en Borrador.
 */
function msp2PagoObtenerEstadoPeriodoSaldoFavor(PDO $conn, string $periodoFacturacion): ?int
{
    $periodo = msp2PagoResolveDateYmd($periodoFacturacion);
    if ($periodo === null) {
        return null;
    }

    if (
        !msp2TableExists($conn, 'msp_cierre_mensual')
        || !msp2ColumnExists($conn, 'msp_cierre_mensual', 'periodo_facturacion')
        || !msp2ColumnExists($conn, 'msp_cierre_mensual', 'estado_cierre')
    ) {
        return null;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT TOP 1 c.estado_cierre
             FROM dbo.msp_cierre_mensual c
             WHERE c.periodo_facturacion = :periodo_facturacion"
        );
        $stmt->bindValue(':periodo_facturacion', $periodo, PDO::PARAM_STR);
        $stmt->execute();
        $value = $stmt->fetchColumn();
        return $value === false ? null : (int) $value;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Uso automático: solo puede reservar saldo para un período ya abierto en
 * Borrador. Evita anticipar efectos automáticos sobre un cierre inexistente.
 */
function msp2PagoPeriodoPermiteAsignacionSaldoFavor(PDO $conn, string $periodoFacturacion): bool
{
    return msp2PagoObtenerEstadoPeriodoSaldoFavor($conn, $periodoFacturacion) === 1;
}

/**
 * Uso manual: permite registrar una rebaja, indemnización o devolución para un
 * mes que aún no ha sido creado. Cuando exista, el motor mensual lo tomará como
 * saldo pendiente del período. Un período existente que ya fue calculado,
 * cerrado o anulado sigue protegido contra cambios manuales.
 */
function msp2PagoPeriodoPermiteRegistroManualSaldoFavor(PDO $conn, string $periodoFacturacion): bool
{
    $periodo = msp2PagoResolveDateYmd($periodoFacturacion);
    if ($periodo === null) {
        return false;
    }

    if (
        !msp2TableExists($conn, 'msp_cierre_mensual')
        || !msp2ColumnExists($conn, 'msp_cierre_mensual', 'periodo_facturacion')
        || !msp2ColumnExists($conn, 'msp_cierre_mensual', 'estado_cierre')
    ) {
        return false;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT TOP 1 c.estado_cierre
             FROM dbo.msp_cierre_mensual c
             WHERE c.periodo_facturacion = :periodo_facturacion"
        );
        $stmt->bindValue(':periodo_facturacion', $periodo, PDO::PARAM_STR);
        $stmt->execute();
        $estadoCierre = $stmt->fetchColumn();
    } catch (Throwable) {
        return false;
    }

    return $estadoCierre === false || (int) $estadoCierre === 1;
}

function msp2PagoRegistrarSaldoFavorPeriodoSiguiente(
    PDO $conn,
    int $idPago,
    int $idDocumentoCobro,
    float $montoSaldoFavorGenerado,
    string $fechaPagoRaw
): bool {
    if ($idDocumentoCobro <= 0 || $montoSaldoFavorGenerado <= 0.005) {
        return false;
    }

    if (
        !msp2TableExists($conn, 'msp_saldo_favor_periodo_items')
        || !msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda')
        || !msp2TableExists($conn, 'msp_documentos_cobro')
        || !msp2ColumnExists($conn, 'msp_saldo_favor_periodo_items', 'id_movimiento_saldo_favor')
    ) {
        return false;
    }

    $movimientoRow = null;
    if ($idPago > 0 && msp2ColumnExists($conn, 'msp_movimientos_saldo_favor_tienda', 'id_pago')) {
        $movStmt = $conn->prepare(
            "SELECT TOP 1
                msf.id_movimiento_saldo_favor,
                msf.id_tienda,
                CONVERT(CHAR(10), msf.fecha_movimiento, 126) AS fecha_movimiento,
                CONVERT(CHAR(10), dc.periodo_facturacion, 126) AS periodo_facturacion
             FROM dbo.msp_movimientos_saldo_favor_tienda msf
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = msf.id_documento_cobro
             WHERE msf.id_pago = :id_pago
               AND msf.id_documento_cobro = :id_documento
               AND msf.tipo_movimiento = 1
               AND msf.monto_movimiento > 0
             ORDER BY msf.id_movimiento_saldo_favor DESC"
        );
        $movStmt->bindValue(':id_pago', $idPago, PDO::PARAM_INT);
        $movStmt->bindValue(':id_documento', $idDocumentoCobro, PDO::PARAM_INT);
        $movStmt->execute();
        $movimientoRow = $movStmt->fetch() ?: null;
    }

    if (!is_array($movimientoRow)) {
        $movStmtFallback = $conn->prepare(
            "SELECT TOP 1
                msf.id_movimiento_saldo_favor,
                msf.id_tienda,
                CONVERT(CHAR(10), msf.fecha_movimiento, 126) AS fecha_movimiento,
                CONVERT(CHAR(10), dc.periodo_facturacion, 126) AS periodo_facturacion
             FROM dbo.msp_movimientos_saldo_favor_tienda msf
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = msf.id_documento_cobro
             WHERE msf.id_documento_cobro = :id_documento
               AND msf.tipo_movimiento = 1
               AND msf.monto_movimiento > 0
               AND ABS(msf.monto_movimiento - :monto_excedente) <= 0.01
             ORDER BY msf.id_movimiento_saldo_favor DESC"
        );
        $movStmtFallback->bindValue(':id_documento', $idDocumentoCobro, PDO::PARAM_INT);
        $movStmtFallback->bindValue(':monto_excedente', $montoSaldoFavorGenerado, PDO::PARAM_STR);
        $movStmtFallback->execute();
        $movimientoRow = $movStmtFallback->fetch() ?: null;
    }

    if (!is_array($movimientoRow)) {
        return false;
    }

    $idMovimiento = (int) ($movimientoRow['id_movimiento_saldo_favor'] ?? 0);
    $idTienda = (int) ($movimientoRow['id_tienda'] ?? 0);
    if ($idMovimiento <= 0 || $idTienda <= 0) {
        return false;
    }

    $periodoDocumento = msp2PagoResolveDateYmd((string) ($movimientoRow['periodo_facturacion'] ?? ''));
    if ($periodoDocumento === null) {
        return false;
    }
    $periodoDocumentoDate = DateTimeImmutable::createFromFormat('Y-m-d', $periodoDocumento);
    if ($periodoDocumentoDate === false) {
        return false;
    }
    $periodoSiguiente = $periodoDocumentoDate->modify('first day of next month')->format('Y-m-d');
    if (!msp2PagoPeriodoPermiteAsignacionSaldoFavor($conn, $periodoSiguiente)) {
        return false;
    }

    $fechaMovimiento = msp2PagoResolveDateYmd((string) ($movimientoRow['fecha_movimiento'] ?? ''));
    if ($fechaMovimiento === null) {
        $fechaMovimiento = msp2PagoResolveDateYmd($fechaPagoRaw);
    }
    if ($fechaMovimiento === null) {
        $fechaMovimiento = (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    $observacion = 'Excedente automatico de pago documento #' . $idDocumentoCobro . ' (asignado al periodo siguiente).';
    $hasPeriodoAplicaciones = msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones');
    $idItemPeriodo = 0;

    $existingItemStmt = $conn->prepare(
        "SELECT TOP 1
            sfpi.id_saldo_favor_periodo_item,
            CONVERT(CHAR(10), sfpi.periodo_facturacion, 126) AS periodo_facturacion
         FROM dbo.msp_saldo_favor_periodo_items sfpi
         WHERE sfpi.id_movimiento_saldo_favor = :id_movimiento
         ORDER BY sfpi.id_saldo_favor_periodo_item DESC"
    );
    $existingItemStmt->bindValue(':id_movimiento', $idMovimiento, PDO::PARAM_INT);
    $existingItemStmt->execute();
    $existingItem = $existingItemStmt->fetch() ?: null;

    if (is_array($existingItem)) {
        $idItem = (int) ($existingItem['id_saldo_favor_periodo_item'] ?? 0);
        $periodoActualItem = msp2PagoResolveDateYmd((string) ($existingItem['periodo_facturacion'] ?? ''));
        if ($idItem <= 0) {
            return false;
        }

        if ($periodoActualItem !== $periodoSiguiente) {
            $tieneAplicaciones = false;
            if ($hasPeriodoAplicaciones) {
                $appsStmt = $conn->prepare(
                    "SELECT TOP 1 1
                     FROM dbo.msp_saldo_favor_periodo_aplicaciones
                     WHERE id_saldo_favor_periodo_item = :id_item
                       AND estado_aplicacion = 1"
                );
                $appsStmt->bindValue(':id_item', $idItem, PDO::PARAM_INT);
                $appsStmt->execute();
                $tieneAplicaciones = $appsStmt->fetchColumn() !== false;
            }

            if (!$tieneAplicaciones) {
                $updStmt = $conn->prepare(
                    "UPDATE dbo.msp_saldo_favor_periodo_items
                     SET
                        periodo_facturacion = :periodo_facturacion,
                        id_tienda = :id_tienda,
                        fecha_movimiento = :fecha_movimiento,
                        monto_original = :monto_original,
                        observaciones = CASE
                            WHEN LTRIM(RTRIM(ISNULL(observaciones, ''))) = '' THEN :observaciones
                            ELSE observaciones
                        END
                     WHERE id_saldo_favor_periodo_item = :id_item"
                );
                $updStmt->bindValue(':periodo_facturacion', $periodoSiguiente, PDO::PARAM_STR);
                $updStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
                $updStmt->bindValue(':fecha_movimiento', $fechaMovimiento, PDO::PARAM_STR);
                $updStmt->bindValue(':monto_original', (string) round($montoSaldoFavorGenerado, 2), PDO::PARAM_STR);
                $updStmt->bindValue(':observaciones', $observacion, PDO::PARAM_STR);
                $updStmt->bindValue(':id_item', $idItem, PDO::PARAM_INT);
                $updStmt->execute();
                $idItemPeriodo = $idItem;
            } else {
                return false;
            }
        } else {
            $idItemPeriodo = $idItem;
        }
    }
    if ($idItemPeriodo <= 0) {
        $insPeriodoItemStmt = $conn->prepare(
            "INSERT INTO dbo.msp_saldo_favor_periodo_items
                (periodo_facturacion, id_tienda, fecha_movimiento, monto_original, id_movimiento_saldo_favor, observaciones)
            VALUES
                (:periodo_facturacion, :id_tienda, :fecha_movimiento, :monto_original, :id_movimiento, :observaciones)"
        );
        $insPeriodoItemStmt->bindValue(':id_movimiento', $idMovimiento, PDO::PARAM_INT);
        $insPeriodoItemStmt->bindValue(':periodo_facturacion', $periodoSiguiente, PDO::PARAM_STR);
        $insPeriodoItemStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $insPeriodoItemStmt->bindValue(':fecha_movimiento', $fechaMovimiento, PDO::PARAM_STR);
        $insPeriodoItemStmt->bindValue(':monto_original', (string) round($montoSaldoFavorGenerado, 2), PDO::PARAM_STR);
        $insPeriodoItemStmt->bindValue(':observaciones', $observacion, PDO::PARAM_STR);
        $insPeriodoItemStmt->execute();

        $idItemStmt = $conn->prepare(
            "SELECT TOP 1 id_saldo_favor_periodo_item
             FROM dbo.msp_saldo_favor_periodo_items
             WHERE id_movimiento_saldo_favor = :id_movimiento
             ORDER BY id_saldo_favor_periodo_item DESC"
        );
        $idItemStmt->bindValue(':id_movimiento', $idMovimiento, PDO::PARAM_INT);
        $idItemStmt->execute();
        $idItemPeriodo = (int) ($idItemStmt->fetchColumn() ?: 0);
        if ($idItemPeriodo <= 0) {
            return false;
        }
    }

    if (
        !$hasPeriodoAplicaciones
        || !msp2TableExists($conn, 'msp_saldos_favor_tienda')
        || !msp2ColumnExists($conn, 'msp_saldo_favor_periodo_aplicaciones', 'estado_aplicacion')
    ) {
        return true;
    }

    $saldoItemStmt = $conn->prepare(
        "SELECT TOP 1
            ROUND(
                sfpi.monto_original
                - ISNULL(SUM(CASE WHEN sfa.estado_aplicacion = 1 THEN sfa.monto_aplicado ELSE 0 END), 0),
                2
            ) AS saldo_item_disponible
         FROM dbo.msp_saldo_favor_periodo_items sfpi
         LEFT JOIN dbo.msp_saldo_favor_periodo_aplicaciones sfa
            ON sfa.id_saldo_favor_periodo_item = sfpi.id_saldo_favor_periodo_item
         WHERE sfpi.id_saldo_favor_periodo_item = :id_item
           AND sfpi.estado_item = 1
         GROUP BY sfpi.monto_original"
    );
    $saldoItemStmt->bindValue(':id_item', $idItemPeriodo, PDO::PARAM_INT);
    $saldoItemStmt->execute();
    $saldoItemDisponible = round((float) ($saldoItemStmt->fetchColumn() ?: 0), 2);
    if ($saldoItemDisponible <= 0.005) {
        return true;
    }

    $docDestinoStmt = $conn->prepare(
        "SELECT TOP 1
            dc.id_documento_cobro,
            dc.saldo_pendiente
         FROM dbo.msp_documentos_cobro dc
         WHERE dc.id_tienda = :id_tienda
           AND dc.periodo_facturacion = :periodo
           AND dc.estado_documento <> 5
           AND dc.saldo_pendiente > 0
         ORDER BY ISNULL(dc.fecha_vencimiento, dc.periodo_facturacion) ASC, dc.id_documento_cobro ASC"
    );
    $docDestinoStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $docDestinoStmt->bindValue(':periodo', $periodoSiguiente, PDO::PARAM_STR);
    $docDestinoStmt->execute();
    $docDestino = $docDestinoStmt->fetch() ?: null;
    if (!is_array($docDestino)) {
        return true;
    }

    $idDocumentoDestino = (int) ($docDestino['id_documento_cobro'] ?? 0);
    $saldoDocumentoDestino = round((float) ($docDestino['saldo_pendiente'] ?? 0), 2);
    $montoAplicar = round(min($saldoItemDisponible, $saldoDocumentoDestino), 2);
    if ($idDocumentoDestino <= 0 || $montoAplicar <= 0.005) {
        return true;
    }

    try {
        $stmtAplicar = $conn->prepare(
            'EXEC dbo.msp_aplicar_saldo_favor_documento
                @id_documento_cobro = :id_documento_cobro,
                @fecha_pago = :fecha_pago,
                @monto_aplicar = :monto_aplicar,
                @observaciones = :observaciones'
        );
        $stmtAplicar->bindValue(':id_documento_cobro', $idDocumentoDestino, PDO::PARAM_INT);
        $stmtAplicar->bindValue(':fecha_pago', $fechaMovimiento, PDO::PARAM_STR);
        $stmtAplicar->bindValue(':monto_aplicar', (string) $montoAplicar, PDO::PARAM_STR);
        $stmtAplicar->bindValue(
            ':observaciones',
            'Aplicacion automatica saldo a favor periodo ' . $periodoSiguiente . ' (excedente documento #' . $idDocumentoCobro . ').',
            PDO::PARAM_STR
        );
        $stmtAplicar->execute();
        $resultadoAplicar = $stmtAplicar->fetch() ?: [];
        $idPagoAplicacion = (int) ($resultadoAplicar['id_pago_generado'] ?? 0);
        $montoAplicadoReal = round((float) ($resultadoAplicar['monto_aplicado'] ?? $montoAplicar), 2);

        if ($idPagoAplicacion > 0 && $montoAplicadoReal > 0.005) {
            $existeAppStmt = $conn->prepare(
                "SELECT TOP 1 1
                 FROM dbo.msp_saldo_favor_periodo_aplicaciones
                 WHERE id_pago = :id_pago"
            );
            $existeAppStmt->bindValue(':id_pago', $idPagoAplicacion, PDO::PARAM_INT);
            $existeAppStmt->execute();
            $yaExiste = $existeAppStmt->fetchColumn() !== false;

            if (!$yaExiste) {
                $insAppStmt = $conn->prepare(
                    "INSERT INTO dbo.msp_saldo_favor_periodo_aplicaciones
                        (
                            id_saldo_favor_periodo_item,
                            periodo_facturacion,
                            id_tienda,
                            id_documento_cobro,
                            id_pago,
                            fecha_aplicacion,
                            monto_aplicado,
                            estado_aplicacion,
                            observaciones
                        )
                     VALUES
                        (
                            :id_item,
                            :periodo,
                            :id_tienda,
                            :id_documento,
                            :id_pago,
                            :fecha_aplicacion,
                            :monto_aplicado,
                            1,
                            :observaciones
                        )"
                );
                $insAppStmt->bindValue(':id_item', $idItemPeriodo, PDO::PARAM_INT);
                $insAppStmt->bindValue(':periodo', $periodoSiguiente, PDO::PARAM_STR);
                $insAppStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
                $insAppStmt->bindValue(':id_documento', $idDocumentoDestino, PDO::PARAM_INT);
                $insAppStmt->bindValue(':id_pago', $idPagoAplicacion, PDO::PARAM_INT);
                $insAppStmt->bindValue(':fecha_aplicacion', $fechaMovimiento, PDO::PARAM_STR);
                $insAppStmt->bindValue(':monto_aplicado', (string) $montoAplicadoReal, PDO::PARAM_STR);
                $insAppStmt->bindValue(
                    ':observaciones',
                    'Aplicacion automatica saldo a favor desde excedente documento #' . $idDocumentoCobro . '.',
                    PDO::PARAM_STR
                );
                $insAppStmt->execute();
            }
        }
    } catch (Throwable) {
        return false;
    }

    return true;
}
