<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/pagos/saldo_favor_periodo_helper.php';
require_once dirname(__DIR__) . '/templates/components/monto_clp_input.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$loadError = null;
$tablaExiste = false;
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$periodoYm = trim((string) ($_GET['periodo'] ?? $_POST['periodo'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $periodoYm)) {
    $periodoYm = (new DateTimeImmutable('today'))->format('Y-m');
}

function sfParseMonthToFirstDay(string $periodo): ?string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodo);
    if (!$d || $d->format('Y-m') !== $periodo) {
        return null;
    }

    return $d->format('Y-m-01');
}

function sfRedirect(string $periodo): never
{
    msp2Redirect('cobranza/saldo_favor_manual.php?periodo=' . urlencode($periodo));
}

function sfManualAdjustmentWindow(string $periodoFacturacion): ?array
{
    $periodoDate = DateTimeImmutable::createFromFormat('Y-m-d', $periodoFacturacion);
    if ($periodoDate === false || $periodoDate->format('Y-m-d') !== $periodoFacturacion) {
        return null;
    }

    $prevMonth = $periodoDate->modify('first day of previous month');
    if ($prevMonth === false) {
        return null;
    }

    $minDate = $prevMonth->format('Y-m-01');
    $maxDate = $prevMonth->modify('last day of this month')->format('Y-m-d');

    return [
        'min' => $minDate,
        'max' => $maxDate,
        'default' => $maxDate,
    ];
}

function sfFmtFecha(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    $d = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $d ? $d->format('d-m-Y') : $value;
}

function sfFmtNum(mixed $value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '0';
    }

    return number_format((float) $value, $decimals, ',', '.');
}

function sfDecimal(?string $raw, int $scale, bool $required = false): array
{
    [$ok, $value] = msp2NormalizeDecimalInput($raw, $scale);
    if (!$ok) {
        return [false, null];
    }

    if ($required && $value === null) {
        return [false, null];
    }

    return [true, $value];
}

function sfFetchFirstScalar(PDOStatement $stmt): mixed
{
    /*
     * SQL Server puede emitir conteos de filas antes del SELECT final de un
     * bloque INSERT/OUTPUT. Esos rowsets no tienen columnas y PDO lanza una
     * excepción al intentar leerlos. Recorremos todos hasta encontrar el
     * identificador solicitado.
     */
    while (true) {
        try {
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                return $value;
            }
        } catch (PDOException) {
            // Rowset de conteo de SQL Server: continuar al siguiente.
        }

        try {
            if (!$stmt->nextRowset()) {
                break;
            }
        } catch (PDOException) {
            break;
        }
    }

    return null;
}

try {
    $requiredTables = ['msp_movimientos_saldo_favor_tienda', 'msp_tiendas'];
    $missing = [];
    foreach ($requiredTables as $table) {
        if (!msp2TableExists($conn, $table)) {
            $missing[] = $table;
        }
    }

    if ($missing !== []) {
        $loadError = 'Faltan tablas requeridas: `' . implode('`, `', $missing) . '`.';
    } else {
        $tablaExiste = true;
    }
} catch (PDOException) {
    $loadError = 'No fue posible validar la estructura para saldo a favor.';
}

if ($tablaExiste && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));
    $periodoYmPost = trim((string) ($_POST['periodo'] ?? $periodoYm));
    if (!preg_match('/^\d{4}-\d{2}$/', $periodoYmPost)) {
        $periodoYmPost = $periodoYm;
    }

    if ($accion === 'crear_saldo_favor') {
        $periodoFacturacion = sfParseMonthToFirstDay($periodoYmPost);
        $manualAdjustWindow = $periodoFacturacion !== null ? sfManualAdjustmentWindow($periodoFacturacion) : null;
        $idTienda = filter_input(INPUT_POST, 'id_tienda', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $fechaMovimiento = trim((string) ($_POST['fecha_movimiento'] ?? ''));
        [$okMonto, $montoSaldoFavor] = sfDecimal((string) ($_POST['saldo_favor_monto'] ?? ''), 2, true);
        $observaciones = mb_substr(msp2NormalizeText((string) ($_POST['observaciones'] ?? '')), 0, 500, 'UTF-8');

        if ($periodoFacturacion === null || !is_array($manualAdjustWindow)) {
            msp2SetFlash('warning', 'Periodo invalido para registrar saldo a favor.');
            sfRedirect($periodoYmPost);
        }
        if (!msp2PagoPeriodoPermiteRegistroManualSaldoFavor($conn, $periodoFacturacion)) {
            msp2SetFlash('warning', 'Solo puedes registrar saldo a favor en un período no creado o en estado Borrador.');
            sfRedirect($periodoYmPost);
        }

        if (!msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda') || !msp2TableExists($conn, 'msp_tiendas')) {
            msp2SetFlash('warning', 'El flujo de saldo a favor no está disponible en este ambiente.');
            sfRedirect($periodoYmPost);
        }

        if ($idTienda === false || $idTienda === null) {
            msp2SetFlash('warning', 'Debes seleccionar una tienda válida.');
            sfRedirect($periodoYmPost);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaMovimiento) !== 1) {
            $fechaMovimiento = (string) ($manualAdjustWindow['default'] ?? '');
        }

        $manualAdjustMin = (string) ($manualAdjustWindow['min'] ?? '');
        $manualAdjustMax = (string) ($manualAdjustWindow['max'] ?? '');
        if ($manualAdjustMin === '' || $manualAdjustMax === '' || $fechaMovimiento < $manualAdjustMin || $fechaMovimiento > $manualAdjustMax) {
            msp2SetFlash('warning', 'La fecha del ajuste manual debe estar entre ' . sfFmtFecha($manualAdjustMin) . ' y ' . sfFmtFecha($manualAdjustMax) . '.');
            sfRedirect($periodoYmPost);
        }

        if (!$okMonto || $montoSaldoFavor === null || (float) $montoSaldoFavor <= 0) {
            msp2SetFlash('warning', 'El monto de saldo a favor debe ser mayor a 0.');
            sfRedirect($periodoYmPost);
        }

        try {
            $checkTiendaStmt = $conn->prepare('SELECT TOP 1 1 FROM dbo.msp_tiendas WHERE id_tienda = :id_tienda');
            $checkTiendaStmt->bindValue(':id_tienda', (int) $idTienda, PDO::PARAM_INT);
            $checkTiendaStmt->execute();
            if ($checkTiendaStmt->fetchColumn() === false) {
                throw new RuntimeException('La tienda seleccionada no existe.');
            }

            $hasPeriodoItemsTable = msp2TableExists($conn, 'msp_saldo_favor_periodo_items');
            $conn->beginTransaction();

            $insSaldoStmt = $conn->prepare(
                'DECLARE @out TABLE (id_movimiento_saldo_favor INT);
                 INSERT INTO dbo.msp_movimientos_saldo_favor_tienda
                    (id_tienda, fecha_movimiento, tipo_movimiento, monto_movimiento, observaciones)
                 OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out(id_movimiento_saldo_favor)
                 VALUES
                    (:id_tienda, :fecha_movimiento, 5, :monto, :observaciones);
                 SELECT TOP 1 id_movimiento_saldo_favor FROM @out;'
            );
            $insSaldoStmt->bindValue(':id_tienda', (int) $idTienda, PDO::PARAM_INT);
            $insSaldoStmt->bindValue(':fecha_movimiento', $fechaMovimiento, PDO::PARAM_STR);
            $insSaldoStmt->bindValue(':monto', (string) $montoSaldoFavor, PDO::PARAM_STR);
            $insSaldoStmt->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insSaldoStmt->execute();
            $idMovimientoSaldoFavorCreado = (int) (sfFetchFirstScalar($insSaldoStmt) ?: 0);
            if ($idMovimientoSaldoFavorCreado <= 0) {
                throw new RuntimeException('No fue posible identificar el movimiento creado de saldo a favor.');
            }

            if ($hasPeriodoItemsTable) {
                $insPeriodoItemStmt = $conn->prepare(
                    'INSERT INTO dbo.msp_saldo_favor_periodo_items
                        (periodo_facturacion, id_tienda, fecha_movimiento, monto_original, id_movimiento_saldo_favor, observaciones)
                     VALUES
                        (:periodo, :id_tienda, :fecha_movimiento, :monto_original, :id_movimiento, :observaciones)'
                );
                $insPeriodoItemStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':id_tienda', (int) $idTienda, PDO::PARAM_INT);
                $insPeriodoItemStmt->bindValue(':fecha_movimiento', $fechaMovimiento, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':monto_original', (string) $montoSaldoFavor, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':id_movimiento', $idMovimientoSaldoFavorCreado, PDO::PARAM_INT);
                $insPeriodoItemStmt->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insPeriodoItemStmt->execute();
            }

            $conn->commit();
            msp2SetFlash('success', 'Saldo a favor manual registrado correctamente.');
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible registrar el saldo a favor manual.');
        }

        sfRedirect($periodoYmPost);
    }

    if ($accion === 'actualizar_saldo_favor_manual') {
        $periodoFacturacion = sfParseMonthToFirstDay($periodoYmPost);
        $manualAdjustWindow = $periodoFacturacion !== null ? sfManualAdjustmentWindow($periodoFacturacion) : null;
        $idMovimientoSaldoFavor = filter_input(INPUT_POST, 'id_movimiento_saldo_favor', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $idTiendaNueva = filter_input(INPUT_POST, 'id_tienda', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $fechaMovimientoNueva = trim((string) ($_POST['fecha_movimiento'] ?? ''));
        [$okMontoNuevo, $montoSaldoFavorNuevo] = sfDecimal((string) ($_POST['saldo_favor_monto'] ?? ''), 2, true);
        $observacionesNuevas = mb_substr(msp2NormalizeText((string) ($_POST['observaciones'] ?? '')), 0, 500, 'UTF-8');

        if ($periodoFacturacion === null || !is_array($manualAdjustWindow)) {
            msp2SetFlash('warning', 'Periodo invalido para editar saldo a favor.');
            sfRedirect($periodoYmPost);
        }
        if (!msp2PagoPeriodoPermiteRegistroManualSaldoFavor($conn, $periodoFacturacion)) {
            msp2SetFlash('warning', 'Solo puedes editar saldo a favor en un período no creado o en estado Borrador.');
            sfRedirect($periodoYmPost);
        }

        if (
            $idMovimientoSaldoFavor === false
            || $idMovimientoSaldoFavor === null
            || $idTiendaNueva === false
            || $idTiendaNueva === null
            || !msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda')
            || !msp2TableExists($conn, 'msp_tiendas')
            || !msp2TableExists($conn, 'msp_saldos_favor_tienda')
        ) {
            msp2SetFlash('warning', 'No fue posible identificar el ingreso manual a editar.');
            sfRedirect($periodoYmPost);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaMovimientoNueva) !== 1) {
            $fechaMovimientoNueva = (string) ($manualAdjustWindow['default'] ?? '');
        }

        $manualAdjustMin = (string) ($manualAdjustWindow['min'] ?? '');
        $manualAdjustMax = (string) ($manualAdjustWindow['max'] ?? '');
        if (
            $manualAdjustMin === ''
            || $manualAdjustMax === ''
            || $fechaMovimientoNueva < $manualAdjustMin
            || $fechaMovimientoNueva > $manualAdjustMax
        ) {
            msp2SetFlash('warning', 'La fecha del ajuste manual debe estar entre ' . sfFmtFecha($manualAdjustMin) . ' y ' . sfFmtFecha($manualAdjustMax) . '.');
            sfRedirect($periodoYmPost);
        }

        if (!$okMontoNuevo || $montoSaldoFavorNuevo === null || (float) $montoSaldoFavorNuevo <= 0) {
            msp2SetFlash('warning', 'El monto de saldo a favor debe ser mayor a 0.');
            sfRedirect($periodoYmPost);
        }

        try {
            $checkTiendaNuevaStmt = $conn->prepare('SELECT TOP 1 1 FROM dbo.msp_tiendas WHERE id_tienda = :id_tienda');
            $checkTiendaNuevaStmt->bindValue(':id_tienda', (int) $idTiendaNueva, PDO::PARAM_INT);
            $checkTiendaNuevaStmt->execute();
            if ($checkTiendaNuevaStmt->fetchColumn() === false) {
                throw new RuntimeException('La tienda seleccionada no existe.');
            }

            $conn->beginTransaction();
            $hasPeriodoItemsTable = msp2TableExists($conn, 'msp_saldo_favor_periodo_items');

            $movStmt = $conn->prepare(
                'SELECT
                    id_movimiento_saldo_favor,
                    id_tienda,
                    fecha_movimiento,
                    monto_movimiento,
                    observaciones
                 FROM dbo.msp_movimientos_saldo_favor_tienda WITH (UPDLOCK, HOLDLOCK)
                 WHERE id_movimiento_saldo_favor = :id_mov
                   AND tipo_movimiento = 5
                   AND monto_movimiento > 0'
            );
            $movStmt->bindValue(':id_mov', (int) $idMovimientoSaldoFavor, PDO::PARAM_INT);
            $movStmt->execute();
            $movRow = $movStmt->fetch() ?: null;

            if (!is_array($movRow)) {
                throw new RuntimeException('El movimiento manual seleccionado no existe o ya no está disponible.');
            }

            $idTiendaActual = (int) ($movRow['id_tienda'] ?? 0);
            $fechaMovimientoActual = substr((string) ($movRow['fecha_movimiento'] ?? ''), 0, 10);
            $montoMovimientoActual = round((float) ($movRow['monto_movimiento'] ?? 0), 2);
            $observacionesActuales = mb_substr(trim((string) ($movRow['observaciones'] ?? '')), 0, 500, 'UTF-8');

            if ($idTiendaActual <= 0 || $montoMovimientoActual <= 0) {
                throw new RuntimeException('El movimiento manual seleccionado no es válido para edición.');
            }

            if ($fechaMovimientoActual < $manualAdjustMin || $fechaMovimientoActual > $manualAdjustMax) {
                throw new RuntimeException('Solo puedes editar ingresos manuales de la ventana de ajuste actual.');
            }

            if (!$hasPeriodoItemsTable) {
                $reversaMarker = '[REVERSA_MANUAL:' . (int) $idMovimientoSaldoFavor . ']';
                $checkReversaStmt = $conn->prepare(
                    'SELECT TOP 1 1
                     FROM dbo.msp_movimientos_saldo_favor_tienda
                     WHERE id_tienda = :id_tienda
                       AND CHARINDEX(:marker, ISNULL(observaciones, \'\')) > 0'
                );
                $checkReversaStmt->bindValue(':id_tienda', $idTiendaActual, PDO::PARAM_INT);
                $checkReversaStmt->bindValue(':marker', $reversaMarker, PDO::PARAM_STR);
                $checkReversaStmt->execute();
                if ($checkReversaStmt->fetchColumn() !== false) {
                    throw new RuntimeException('Ese ingreso manual ya fue revertido anteriormente.');
                }
            }

            $saldoStmt = $conn->prepare(
                'SELECT saldo_disponible
                 FROM dbo.msp_saldos_favor_tienda WITH (UPDLOCK, HOLDLOCK)
                 WHERE id_tienda = :id_tienda'
            );
            $saldoStmt->bindValue(':id_tienda', $idTiendaActual, PDO::PARAM_INT);
            $saldoStmt->execute();
            $saldoDisponibleActual = round((float) ($saldoStmt->fetchColumn() ?: 0), 2);
            if ($saldoDisponibleActual < $montoMovimientoActual) {
                throw new RuntimeException('No se puede editar: el monto ya fue usado total o parcialmente.');
            }

            $idItemPeriodo = 0;
            if ($hasPeriodoItemsTable) {
                $itemStmt = $conn->prepare(
                    'SELECT TOP 1 id_saldo_favor_periodo_item
                     FROM dbo.msp_saldo_favor_periodo_items WITH (UPDLOCK, HOLDLOCK)
                     WHERE id_movimiento_saldo_favor = :id_mov
                       AND periodo_facturacion = :periodo
                       AND estado_item = 1'
                );
                $itemStmt->bindValue(':id_mov', (int) $idMovimientoSaldoFavor, PDO::PARAM_INT);
                $itemStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $itemStmt->execute();
                $idItemPeriodo = (int) ($itemStmt->fetchColumn() ?: 0);
                if ($idItemPeriodo <= 0) {
                    throw new RuntimeException('El ingreso manual no pertenece al periodo seleccionado o ya fue revertido.');
                }

                if (msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones')) {
                    $itemAplicacionesStmt = $conn->prepare(
                        'SELECT COUNT(*)
                         FROM dbo.msp_saldo_favor_periodo_aplicaciones
                         WHERE id_saldo_favor_periodo_item = :id_item
                           AND estado_aplicacion = 1'
                    );
                    $itemAplicacionesStmt->bindValue(':id_item', $idItemPeriodo, PDO::PARAM_INT);
                    $itemAplicacionesStmt->execute();
                    $aplicacionesActivasItem = (int) ($itemAplicacionesStmt->fetchColumn() ?: 0);
                    if ($aplicacionesActivasItem > 0) {
                        throw new RuntimeException('No se puede editar: el ingreso manual ya tiene aplicaciones activas en documentos.');
                    }
                }
            }

            $montoNuevoValue = round((float) $montoSaldoFavorNuevo, 2);
            if (
                $idTiendaActual === (int) $idTiendaNueva
                && $fechaMovimientoActual === $fechaMovimientoNueva
                && abs($montoMovimientoActual - $montoNuevoValue) <= 0.0001
                && $observacionesActuales === $observacionesNuevas
            ) {
                $conn->rollBack();
                msp2SetFlash('info', 'No se detectaron cambios para actualizar en el ingreso manual.');
                sfRedirect($periodoYmPost);
            }

            $reversaMarker = '[REVERSA_MANUAL:' . (int) $idMovimientoSaldoFavor . ']';
            $obsReversa = 'Ajuste manual (reversa) de ingreso #' . (int) $idMovimientoSaldoFavor . ' ' . $reversaMarker;
            $insReversaStmt = $conn->prepare(
                'DECLARE @out TABLE (id_movimiento_saldo_favor INT);
                 INSERT INTO dbo.msp_movimientos_saldo_favor_tienda
                    (id_tienda, fecha_movimiento, tipo_movimiento, monto_movimiento, observaciones)
                 OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out(id_movimiento_saldo_favor)
                 VALUES
                    (:id_tienda, :fecha_movimiento, 3, :monto_reversa, :observaciones);
                 SELECT TOP 1 id_movimiento_saldo_favor FROM @out;'
            );
            $insReversaStmt->bindValue(':id_tienda', $idTiendaActual, PDO::PARAM_INT);
            $insReversaStmt->bindValue(':fecha_movimiento', $fechaMovimientoActual, PDO::PARAM_STR);
            $insReversaStmt->bindValue(':monto_reversa', (string) (-1 * $montoMovimientoActual), PDO::PARAM_STR);
            $insReversaStmt->bindValue(':observaciones', $obsReversa, PDO::PARAM_STR);
            $insReversaStmt->execute();
            $idMovimientoReversa = (int) (sfFetchFirstScalar($insReversaStmt) ?: 0);
            if ($idMovimientoReversa <= 0) {
                throw new RuntimeException('No fue posible registrar la reversa del ingreso manual original.');
            }

            if ($hasPeriodoItemsTable && $idItemPeriodo > 0) {
                $updItemStmt = $conn->prepare(
                    'UPDATE dbo.msp_saldo_favor_periodo_items
                     SET estado_item = 5,
                         id_movimiento_reversa = :id_mov_reversa,
                         fecha_actualizacion = SYSDATETIME()
                     WHERE id_saldo_favor_periodo_item = :id_item
                       AND estado_item = 1'
                );
                $updItemStmt->bindValue(':id_mov_reversa', $idMovimientoReversa, PDO::PARAM_INT);
                $updItemStmt->bindValue(':id_item', $idItemPeriodo, PDO::PARAM_INT);
                $updItemStmt->execute();
                if ($updItemStmt->rowCount() <= 0) {
                    throw new RuntimeException('No fue posible cerrar el ingreso manual original.');
                }
            }

            $insNuevoIngresoStmt = $conn->prepare(
                'DECLARE @out TABLE (id_movimiento_saldo_favor INT);
                 INSERT INTO dbo.msp_movimientos_saldo_favor_tienda
                    (id_tienda, fecha_movimiento, tipo_movimiento, monto_movimiento, observaciones)
                 OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out(id_movimiento_saldo_favor)
                 VALUES
                    (:id_tienda, :fecha_movimiento, 5, :monto, :observaciones);
                 SELECT TOP 1 id_movimiento_saldo_favor FROM @out;'
            );
            $insNuevoIngresoStmt->bindValue(':id_tienda', (int) $idTiendaNueva, PDO::PARAM_INT);
            $insNuevoIngresoStmt->bindValue(':fecha_movimiento', $fechaMovimientoNueva, PDO::PARAM_STR);
            $insNuevoIngresoStmt->bindValue(':monto', (string) $montoNuevoValue, PDO::PARAM_STR);
            $insNuevoIngresoStmt->bindValue(':observaciones', $observacionesNuevas !== '' ? $observacionesNuevas : null, $observacionesNuevas !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insNuevoIngresoStmt->execute();
            $idMovimientoNuevo = (int) (sfFetchFirstScalar($insNuevoIngresoStmt) ?: 0);
            if ($idMovimientoNuevo <= 0) {
                throw new RuntimeException('No fue posible registrar el ingreso manual actualizado.');
            }

            if ($hasPeriodoItemsTable) {
                $insPeriodoItemStmt = $conn->prepare(
                    'INSERT INTO dbo.msp_saldo_favor_periodo_items
                        (periodo_facturacion, id_tienda, fecha_movimiento, monto_original, id_movimiento_saldo_favor, observaciones)
                     VALUES
                        (:periodo, :id_tienda, :fecha_movimiento, :monto_original, :id_movimiento, :observaciones)'
                );
                $insPeriodoItemStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':id_tienda', (int) $idTiendaNueva, PDO::PARAM_INT);
                $insPeriodoItemStmt->bindValue(':fecha_movimiento', $fechaMovimientoNueva, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':monto_original', (string) $montoNuevoValue, PDO::PARAM_STR);
                $insPeriodoItemStmt->bindValue(':id_movimiento', $idMovimientoNuevo, PDO::PARAM_INT);
                $insPeriodoItemStmt->bindValue(':observaciones', $observacionesNuevas !== '' ? $observacionesNuevas : null, $observacionesNuevas !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insPeriodoItemStmt->execute();
            }

            $conn->commit();
            msp2SetFlash('success', 'Ingreso manual actualizado correctamente.');
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible actualizar el ingreso manual.');
        }

        sfRedirect($periodoYmPost);
    }

    if ($accion === 'revertir_saldo_favor_manual' || $accion === 'cancelar_saldo_favor_manual') {
        $esCancelacionSaldoManual = $accion === 'cancelar_saldo_favor_manual';
        $periodoFacturacion = sfParseMonthToFirstDay($periodoYmPost);
        $manualAdjustWindow = $periodoFacturacion !== null ? sfManualAdjustmentWindow($periodoFacturacion) : null;
        $motivoCancelacion = mb_substr(msp2NormalizeText((string) ($_POST['confirm_reason'] ?? '')), 0, 500, 'UTF-8');
        $idMovimientoSaldoFavor = filter_input(INPUT_POST, 'id_movimiento_saldo_favor', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($periodoFacturacion === null || !is_array($manualAdjustWindow)) {
            msp2SetFlash('warning', 'Periodo invalido para revertir saldo a favor.');
            sfRedirect($periodoYmPost);
        }

        if (
            $idMovimientoSaldoFavor === false
            || $idMovimientoSaldoFavor === null
            || !msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda')
            || !msp2TableExists($conn, 'msp_tiendas')
            || !msp2TableExists($conn, 'msp_saldos_favor_tienda')
        ) {
            msp2SetFlash('warning', 'No fue posible identificar el ingreso manual a revertir.');
            sfRedirect($periodoYmPost);
        }

        try {
            $conn->beginTransaction();
            $hasPeriodoItemsTable = msp2TableExists($conn, 'msp_saldo_favor_periodo_items');

            $movStmt = $conn->prepare(
                'SELECT
                    id_movimiento_saldo_favor,
                    id_tienda,
                    fecha_movimiento,
                    monto_movimiento
                 FROM dbo.msp_movimientos_saldo_favor_tienda WITH (UPDLOCK, HOLDLOCK)
                 WHERE id_movimiento_saldo_favor = :id_mov
                   AND tipo_movimiento = 5
                   AND monto_movimiento > 0'
            );
            $movStmt->bindValue(':id_mov', (int) $idMovimientoSaldoFavor, PDO::PARAM_INT);
            $movStmt->execute();
            $movRow = $movStmt->fetch() ?: null;

            if (!is_array($movRow)) {
                throw new RuntimeException('El movimiento manual seleccionado no existe o ya no está disponible.');
            }

            $idTienda = (int) ($movRow['id_tienda'] ?? 0);
            $fechaMovimiento = substr((string) ($movRow['fecha_movimiento'] ?? ''), 0, 10);
            $montoMovimiento = round((float) ($movRow['monto_movimiento'] ?? 0), 2);
            if ($idTienda <= 0 || $montoMovimiento <= 0) {
                throw new RuntimeException('El movimiento manual seleccionado no es válido para reversa.');
            }

            $manualAdjustMin = (string) ($manualAdjustWindow['min'] ?? '');
            $manualAdjustMax = (string) ($manualAdjustWindow['max'] ?? '');
            if (
                $manualAdjustMin === ''
                || $manualAdjustMax === ''
                || $fechaMovimiento < $manualAdjustMin
                || $fechaMovimiento > $manualAdjustMax
            ) {
                throw new RuntimeException('Solo puedes revertir ingresos manuales de la ventana de ajuste actual.');
            }

            $reversaMarker = '[REVERSA_MANUAL:' . (int) $idMovimientoSaldoFavor . ']';
            $checkReversaStmt = $conn->prepare(
                'SELECT TOP 1 1
                 FROM dbo.msp_movimientos_saldo_favor_tienda
                 WHERE id_tienda = :id_tienda
                   AND CHARINDEX(:marker, ISNULL(observaciones, \'\')) > 0'
            );
            $checkReversaStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $checkReversaStmt->bindValue(':marker', $reversaMarker, PDO::PARAM_STR);
            $checkReversaStmt->execute();
            if ($checkReversaStmt->fetchColumn() !== false) {
                throw new RuntimeException('Ese ingreso manual ya fue revertido anteriormente.');
            }

            $saldoStmt = $conn->prepare(
                'SELECT saldo_disponible
                 FROM dbo.msp_saldos_favor_tienda WITH (UPDLOCK, HOLDLOCK)
                 WHERE id_tienda = :id_tienda'
            );
            $saldoStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $saldoStmt->execute();
            $saldoDisponibleActual = round((float) ($saldoStmt->fetchColumn() ?: 0), 2);
            if ($saldoDisponibleActual < $montoMovimiento) {
                throw new RuntimeException('No se puede revertir: el monto ya fue usado total o parcialmente.');
            }

            $idItemPeriodo = 0;
            if ($hasPeriodoItemsTable) {
                $itemStmt = $conn->prepare(
                    'SELECT TOP 1 id_saldo_favor_periodo_item
                     FROM dbo.msp_saldo_favor_periodo_items WITH (UPDLOCK, HOLDLOCK)
                     WHERE id_movimiento_saldo_favor = :id_mov
                       AND periodo_facturacion = :periodo
                       AND estado_item = 1'
                );
                $itemStmt->bindValue(':id_mov', (int) $idMovimientoSaldoFavor, PDO::PARAM_INT);
                $itemStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $itemStmt->execute();
                $idItemPeriodo = (int) ($itemStmt->fetchColumn() ?: 0);
                if ($idItemPeriodo <= 0) {
                    throw new RuntimeException('El ingreso manual no pertenece al periodo seleccionado o ya fue revertido.');
                }

                if (msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones')) {
                    $itemAplicacionesStmt = $conn->prepare(
                        'SELECT COUNT(*)
                         FROM dbo.msp_saldo_favor_periodo_aplicaciones
                         WHERE id_saldo_favor_periodo_item = :id_item
                           AND estado_aplicacion = 1'
                    );
                    $itemAplicacionesStmt->bindValue(':id_item', $idItemPeriodo, PDO::PARAM_INT);
                    $itemAplicacionesStmt->execute();
                    $aplicacionesActivasItem = (int) ($itemAplicacionesStmt->fetchColumn() ?: 0);
                    if ($aplicacionesActivasItem > 0) {
                        throw new RuntimeException('No se puede revertir: el ingreso manual ya tiene aplicaciones activas en documentos.');
                    }
                }
            }

            $obsReversa = 'Reversa manual de ingreso #' . (int) $idMovimientoSaldoFavor . ' ' . $reversaMarker;
            if ($motivoCancelacion !== '') {
                $obsReversa .= ' | Motivo: ' . $motivoCancelacion;
            }
            $insReversaStmt = $conn->prepare(
                'DECLARE @out TABLE (id_movimiento_saldo_favor INT);
                 INSERT INTO dbo.msp_movimientos_saldo_favor_tienda
                    (id_tienda, fecha_movimiento, tipo_movimiento, monto_movimiento, observaciones)
                 OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out(id_movimiento_saldo_favor)
                 VALUES
                    (:id_tienda, :fecha_movimiento, 3, :monto_reversa, :observaciones);
                 SELECT TOP 1 id_movimiento_saldo_favor FROM @out;'
            );
            $insReversaStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $insReversaStmt->bindValue(':fecha_movimiento', $fechaMovimiento, PDO::PARAM_STR);
            $insReversaStmt->bindValue(':monto_reversa', (string) (-1 * $montoMovimiento), PDO::PARAM_STR);
            $insReversaStmt->bindValue(':observaciones', $obsReversa, PDO::PARAM_STR);
            $insReversaStmt->execute();
            $idMovimientoReversa = (int) (sfFetchFirstScalar($insReversaStmt) ?: 0);
            if ($idMovimientoReversa <= 0) {
                throw new RuntimeException('No fue posible registrar la reversa del ingreso manual.');
            }

            if ($hasPeriodoItemsTable && $idItemPeriodo > 0) {
                $updItemStmt = $conn->prepare(
                    'UPDATE dbo.msp_saldo_favor_periodo_items
                     SET estado_item = 5,
                         id_movimiento_reversa = :id_mov_reversa,
                         fecha_actualizacion = SYSDATETIME()
                     WHERE id_saldo_favor_periodo_item = :id_item
                       AND estado_item = 1'
                );
                $updItemStmt->bindValue(':id_mov_reversa', $idMovimientoReversa, PDO::PARAM_INT);
                $updItemStmt->bindValue(':id_item', $idItemPeriodo, PDO::PARAM_INT);
                $updItemStmt->execute();
                if ($updItemStmt->rowCount() <= 0) {
                    throw new RuntimeException('No fue posible marcar el ingreso manual como revertido.');
                }
            }

            $conn->commit();
            msp2SetFlash('success', $esCancelacionSaldoManual ? 'Ingreso manual eliminado correctamente.' : 'Ingreso manual revertido correctamente.');
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            msp2SetFlash(
                'danger',
                $e instanceof RuntimeException
                    ? $e->getMessage()
                    : ($esCancelacionSaldoManual ? 'No fue posible eliminar el ingreso manual.' : 'No fue posible revertir el ingreso manual.')
            );
        }

        sfRedirect($periodoYmPost);
    }
}

$saldoFavorManualFlow = [
    'disponible' => false,
    'tiendas' => [],
    'locales_por_tienda' => [],
    'manual_rows' => [],
    'resumen' => [],
    'total_disponible' => 0.0,
    'total_ingresado_periodo' => 0.0,
];
$saldoFavorAppliedFlow = [
    'disponible' => false,
    'rows' => [],
    'count' => 0,
    'total' => 0.0,
    'columns_ok' => false,
];

$defaultFecha = (new DateTimeImmutable('today'))->format('Y-m-d');
$manualAdjustDateMin = $defaultFecha;
$manualAdjustDateMax = $defaultFecha;
$manualAdjustDateDefault = $defaultFecha;
$periodoFacturacion = sfParseMonthToFirstDay($periodoYm);
$estadoPeriodoSaldoFavor = $periodoFacturacion !== null
    ? msp2PagoObtenerEstadoPeriodoSaldoFavor($conn, $periodoFacturacion)
    : null;
$periodoSaldoFavorNoCreado = $periodoFacturacion !== null
    && $estadoPeriodoSaldoFavor === null
    && msp2TableExists($conn, 'msp_cierre_mensual');
if ($periodoFacturacion !== null) {
    $manualWindow = sfManualAdjustmentWindow($periodoFacturacion);
    if (is_array($manualWindow)) {
        $manualAdjustDateMin = (string) ($manualWindow['min'] ?? $defaultFecha);
        $manualAdjustDateMax = (string) ($manualWindow['max'] ?? $defaultFecha);
        $manualAdjustDateDefault = (string) ($manualWindow['default'] ?? $defaultFecha);
    }
}
$manualAdjustDateRangeUi = sfFmtFecha($manualAdjustDateMin) . ' al ' . sfFmtFecha($manualAdjustDateMax);

if ($tablaExiste && $periodoFacturacion !== null) {
    if (
        msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda')
        && msp2TableExists($conn, 'msp_tiendas')
    ) {
        $saldoFavorManualFlow['disponible'] = true;

        if (msp2TableExists($conn, 'msp_arrendatarios')) {
            $saldoTiendasStmt = $conn->query(
                'SELECT t.id_tienda, t.nombre_comercial, a.nombre_locatario
                 FROM dbo.msp_tiendas t
                 LEFT JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = t.id_arrendatario
                 ORDER BY t.nombre_comercial ASC'
            );
        } else {
            $saldoTiendasStmt = $conn->query(
                'SELECT t.id_tienda, t.nombre_comercial, NULL AS nombre_locatario
                 FROM dbo.msp_tiendas t
                 ORDER BY t.nombre_comercial ASC'
            );
        }
        $saldoFavorManualFlow['tiendas'] = $saldoTiendasStmt->fetchAll() ?: [];

        if (
            msp2TableExists($conn, 'msp_contratos_arriendo')
            && msp2TableExists($conn, 'msp_contrato_locales')
            && msp2TableExists($conn, 'msp_locales')
        ) {
            $localesStmt = $conn->prepare(
                "DECLARE @periodo DATE = :periodo;
                 SELECT
                    ca.id_tienda,
                    l.cdo_local
                 FROM dbo.msp_contrato_locales cl
                 INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                 INNER JOIN dbo.msp_locales l
                    ON l.id_local = cl.id_local
                 WHERE cl.estado_relacion = 1
                   AND cl.fecha_inicio <= EOMONTH(@periodo)
                   AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                   AND ca.fecha_inicio <= EOMONTH(@periodo)
                   AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                   AND ca.estado_contrato IN (1,2,3)
                 ORDER BY ca.id_tienda ASC, " . msp2LocalCodeNaturalOrderSql('l.cdo_local')
            );
            $localesStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $localesStmt->execute();
            while (($localRow = $localesStmt->fetch()) !== false) {
                $localTiendaId = (int) ($localRow['id_tienda'] ?? 0);
                $localCode = msp2NormalizeLocalCode((string) ($localRow['cdo_local'] ?? ''));
                if ($localTiendaId <= 0 || $localCode === '') {
                    continue;
                }
                if (!isset($saldoFavorManualFlow['locales_por_tienda'][$localTiendaId])) {
                    $saldoFavorManualFlow['locales_por_tienda'][$localTiendaId] = [];
                }
                $saldoFavorManualFlow['locales_por_tienda'][$localTiendaId][] = $localCode;
            }

            foreach ($saldoFavorManualFlow['locales_por_tienda'] as $localTiendaId => $localCodesRaw) {
                if (!is_array($localCodesRaw) || $localCodesRaw === []) {
                    continue;
                }
                $localCodesMap = [];
                foreach ($localCodesRaw as $localRaw) {
                    $localNorm = msp2NormalizeLocalCode((string) $localRaw);
                    $localKey = msp2LocalCodeKey($localNorm);
                    if ($localKey === '' || isset($localCodesMap[$localKey])) {
                        continue;
                    }
                    $localCodesMap[$localKey] = $localNorm;
                }
                $localCodesSorted = array_values($localCodesMap);
                usort($localCodesSorted, static fn (string $a, string $b): int => msp2CompareLocalCode($a, $b));
                $saldoFavorManualFlow['locales_por_tienda'][$localTiendaId] = $localCodesSorted;
            }
        }

        $saldoDisponiblePorTienda = [];
        if (msp2TableExists($conn, 'msp_saldos_favor_tienda')) {
            $saldoDisponibleStmt = $conn->query(
                'SELECT id_tienda, saldo_disponible
                 FROM dbo.msp_saldos_favor_tienda
                 WHERE saldo_disponible > 0'
            );
            while (($saldoRow = $saldoDisponibleStmt->fetch()) !== false) {
                $saldoTiendaId = (int) ($saldoRow['id_tienda'] ?? 0);
                if ($saldoTiendaId <= 0) {
                    continue;
                }
                $saldoDisponiblePorTienda[$saldoTiendaId] = round((float) ($saldoRow['saldo_disponible'] ?? 0), 2);
            }
        }

        $ingresadoPeriodoPorTienda = [];
        $hasPeriodoItemsTable = msp2TableExists($conn, 'msp_saldo_favor_periodo_items');
        $hasPeriodoAplicacionesTable = $hasPeriodoItemsTable && msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones');
        if ($hasPeriodoItemsTable) {
            if ($hasPeriodoAplicacionesTable) {
                $ingresadoPeriodoStmt = $conn->prepare(
                    'SELECT
                        sfpi.id_tienda,
                        ROUND(SUM(
                            sfpi.monto_original
                            - ISNULL(sfa_res.total_aplicado_activo, 0)
                        ), 2) AS total_ingresado
                     FROM dbo.msp_saldo_favor_periodo_items sfpi
                     OUTER APPLY (
                        SELECT ROUND(SUM(sfa.monto_aplicado), 2) AS total_aplicado_activo
                        FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa
                        WHERE sfa.id_saldo_favor_periodo_item = sfpi.id_saldo_favor_periodo_item
                          AND sfa.estado_aplicacion = 1
                     ) sfa_res
                     WHERE sfpi.periodo_facturacion = :periodo
                       AND sfpi.estado_item = 1
                     GROUP BY sfpi.id_tienda'
                );
            } else {
                $ingresadoPeriodoStmt = $conn->prepare(
                    'SELECT id_tienda, ROUND(SUM(monto_original), 2) AS total_ingresado
                     FROM dbo.msp_saldo_favor_periodo_items
                     WHERE periodo_facturacion = :periodo
                       AND estado_item = 1
                     GROUP BY id_tienda'
                );
            }
            $ingresadoPeriodoStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $ingresadoPeriodoStmt->execute();
            while (($ingRow = $ingresadoPeriodoStmt->fetch()) !== false) {
                $ingTiendaId = (int) ($ingRow['id_tienda'] ?? 0);
                if ($ingTiendaId <= 0) {
                    continue;
                }
                $ingresadoPeriodoPorTienda[$ingTiendaId] = round((float) ($ingRow['total_ingresado'] ?? 0), 2);
            }
        } else {
            $ingresadoPeriodoStmt = $conn->prepare(
                'DECLARE @periodo DATE = :periodo;
                 SELECT id_tienda, ROUND(SUM(monto_movimiento), 2) AS total_ingresado
                 FROM dbo.msp_movimientos_saldo_favor_tienda
                 WHERE tipo_movimiento = 5
                   AND fecha_movimiento >= @periodo
                   AND fecha_movimiento <= EOMONTH(@periodo)
                 GROUP BY id_tienda'
            );
            $ingresadoPeriodoStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $ingresadoPeriodoStmt->execute();
            while (($ingRow = $ingresadoPeriodoStmt->fetch()) !== false) {
                $ingTiendaId = (int) ($ingRow['id_tienda'] ?? 0);
                if ($ingTiendaId <= 0) {
                    continue;
                }
                $ingresadoPeriodoPorTienda[$ingTiendaId] = round((float) ($ingRow['total_ingresado'] ?? 0), 2);
            }
        }

        $tiendaInfoById = [];
        foreach ($saldoFavorManualFlow['tiendas'] as $tiendaRow) {
            $tiendaIdRow = (int) ($tiendaRow['id_tienda'] ?? 0);
            if ($tiendaIdRow <= 0) {
                continue;
            }
            $tiendaInfoById[$tiendaIdRow] = $tiendaRow;
        }

        if ($hasPeriodoItemsTable) {
            if ($hasPeriodoAplicacionesTable) {
                $manualRowsStmt = $conn->prepare(
                    'SELECT
                        sfpi.id_saldo_favor_periodo_item,
                        sfpi.id_movimiento_saldo_favor,
                        sfpi.id_tienda,
                        sfpi.fecha_movimiento,
                        sfpi.monto_original AS monto_movimiento,
                        ROUND(
                            sfpi.monto_original
                            - ISNULL(SUM(CASE WHEN sfa.estado_aplicacion = 1 THEN sfa.monto_aplicado ELSE 0 END), 0),
                            2
                        ) AS monto_pendiente,
                        sfpi.observaciones
                     FROM dbo.msp_saldo_favor_periodo_items sfpi
                     LEFT JOIN dbo.msp_saldo_favor_periodo_aplicaciones sfa
                        ON sfa.id_saldo_favor_periodo_item = sfpi.id_saldo_favor_periodo_item
                     WHERE sfpi.periodo_facturacion = :periodo
                       AND sfpi.estado_item = 1
                     GROUP BY
                        sfpi.id_saldo_favor_periodo_item,
                        sfpi.id_movimiento_saldo_favor,
                        sfpi.id_tienda,
                        sfpi.fecha_movimiento,
                        sfpi.monto_original,
                        sfpi.observaciones
                     HAVING ROUND(
                        sfpi.monto_original
                        - ISNULL(SUM(CASE WHEN sfa.estado_aplicacion = 1 THEN sfa.monto_aplicado ELSE 0 END), 0),
                        2
                     ) > 0
                     ORDER BY sfpi.fecha_movimiento DESC, sfpi.id_saldo_favor_periodo_item DESC'
                );
            } else {
                $manualRowsStmt = $conn->prepare(
                    'SELECT
                        id_movimiento_saldo_favor,
                        id_tienda,
                        fecha_movimiento,
                        monto_original AS monto_movimiento,
                        monto_original AS monto_pendiente,
                        observaciones
                     FROM dbo.msp_saldo_favor_periodo_items
                     WHERE periodo_facturacion = :periodo
                       AND estado_item = 1
                     ORDER BY fecha_movimiento DESC, id_saldo_favor_periodo_item DESC'
                );
            }
            $manualRowsStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $manualRowsStmt->execute();
            while (($manualRow = $manualRowsStmt->fetch()) !== false) {
                $manualTiendaId = (int) ($manualRow['id_tienda'] ?? 0);
                if ($manualTiendaId <= 0) {
                    continue;
                }

                $manualMovId = (int) ($manualRow['id_movimiento_saldo_favor'] ?? 0);
                $montoMovimientoRow = round((float) ($manualRow['monto_movimiento'] ?? 0), 2);
                $montoPendienteRow = round((float) ($manualRow['monto_pendiente'] ?? $montoMovimientoRow), 2);
                if ($montoPendienteRow <= 0.005) {
                    continue;
                }
                $tiendaInfo = $tiendaInfoById[$manualTiendaId] ?? [];
                $arrLabel = trim((string) ($tiendaInfo['nombre_locatario'] ?? ''));
                if ($arrLabel === '') {
                    $arrLabel = trim((string) ($tiendaInfo['nombre_comercial'] ?? ''));
                }
                if ($arrLabel === '') {
                    $arrLabel = 'Arrendatario #' . $manualTiendaId;
                }

                $saldoFavorManualFlow['manual_rows'][] = [
                    'id_movimiento_saldo_favor' => $manualMovId,
                    'id_tienda' => $manualTiendaId,
                    'fecha_movimiento' => substr((string) ($manualRow['fecha_movimiento'] ?? ''), 0, 10),
                    'monto_movimiento' => $montoMovimientoRow,
                    'monto_pendiente' => $montoPendienteRow,
                    'observaciones' => (string) ($manualRow['observaciones'] ?? ''),
                    'locales' => $saldoFavorManualFlow['locales_por_tienda'][$manualTiendaId] ?? [],
                    'nombre_arrendatario' => $arrLabel,
                    'saldo_disponible' => round((float) ($saldoDisponiblePorTienda[$manualTiendaId] ?? 0), 2),
                ];
            }
        } else {
            $reversasManualById = [];
            $reversasStmt = $conn->query(
                "SELECT observaciones
                 FROM dbo.msp_movimientos_saldo_favor_tienda
                 WHERE tipo_movimiento = 3
                   AND (
                        CHARINDEX('[REVERSA_MANUAL:', ISNULL(observaciones, '')) > 0
                        OR observaciones LIKE 'Reversa manual de ingreso #%'
                   )"
            );
            while (($reversaRow = $reversasStmt->fetch()) !== false) {
                $obsReversa = (string) ($reversaRow['observaciones'] ?? '');
                if (preg_match('/\[REVERSA_MANUAL:(\d+)\]/', $obsReversa, $m) === 1) {
                    $reversasManualById[(int) ($m[1] ?? 0)] = true;
                } elseif (preg_match('/Reversa manual de ingreso #(\d+)/i', $obsReversa, $m) === 1) {
                    $reversasManualById[(int) ($m[1] ?? 0)] = true;
                }
            }

            $manualWindowRows = sfManualAdjustmentWindow($periodoFacturacion);
            if (is_array($manualWindowRows)) {
                $windowMin = (string) ($manualWindowRows['min'] ?? '');
                $windowMax = (string) ($manualWindowRows['max'] ?? '');
                if ($windowMin !== '' && $windowMax !== '') {
                    $manualRowsStmt = $conn->prepare(
                        'SELECT
                            id_movimiento_saldo_favor,
                            id_tienda,
                            fecha_movimiento,
                            monto_movimiento,
                            observaciones
                         FROM dbo.msp_movimientos_saldo_favor_tienda
                         WHERE tipo_movimiento = 5
                           AND monto_movimiento > 0
                           AND fecha_movimiento >= :fecha_min
                           AND fecha_movimiento <= :fecha_max
                         ORDER BY fecha_movimiento DESC, id_movimiento_saldo_favor DESC'
                    );
                    $manualRowsStmt->bindValue(':fecha_min', $windowMin, PDO::PARAM_STR);
                    $manualRowsStmt->bindValue(':fecha_max', $windowMax, PDO::PARAM_STR);
                    $manualRowsStmt->execute();
                    while (($manualRow = $manualRowsStmt->fetch()) !== false) {
                        $manualTiendaId = (int) ($manualRow['id_tienda'] ?? 0);
                        if ($manualTiendaId <= 0) {
                            continue;
                        }

                        $manualMovId = (int) ($manualRow['id_movimiento_saldo_favor'] ?? 0);
                        if (isset($reversasManualById[$manualMovId])) {
                            continue;
                        }

                        $tiendaInfo = $tiendaInfoById[$manualTiendaId] ?? [];
                        $arrLabel = trim((string) ($tiendaInfo['nombre_locatario'] ?? ''));
                        if ($arrLabel === '') {
                            $arrLabel = trim((string) ($tiendaInfo['nombre_comercial'] ?? ''));
                        }
                        if ($arrLabel === '') {
                            $arrLabel = 'Arrendatario #' . $manualTiendaId;
                        }

                        $saldoFavorManualFlow['manual_rows'][] = [
                            'id_movimiento_saldo_favor' => $manualMovId,
                            'id_tienda' => $manualTiendaId,
                            'fecha_movimiento' => substr((string) ($manualRow['fecha_movimiento'] ?? ''), 0, 10),
                            'monto_movimiento' => round((float) ($manualRow['monto_movimiento'] ?? 0), 2),
                            'monto_pendiente' => round((float) ($manualRow['monto_movimiento'] ?? 0), 2),
                            'observaciones' => (string) ($manualRow['observaciones'] ?? ''),
                            'locales' => $saldoFavorManualFlow['locales_por_tienda'][$manualTiendaId] ?? [],
                            'nombre_arrendatario' => $arrLabel,
                            'saldo_disponible' => round((float) ($saldoDisponiblePorTienda[$manualTiendaId] ?? 0), 2),
                        ];
                    }
                }
            }
        }

        foreach ($saldoFavorManualFlow['tiendas'] as $tiendaRow) {
            $tiendaId = (int) ($tiendaRow['id_tienda'] ?? 0);
            if ($tiendaId <= 0) {
                continue;
            }

            $saldoDisponible = (float) ($saldoDisponiblePorTienda[$tiendaId] ?? 0.0);
            $ingresadoPeriodo = (float) ($ingresadoPeriodoPorTienda[$tiendaId] ?? 0.0);
            if ($saldoDisponible <= 0 && $ingresadoPeriodo <= 0) {
                continue;
            }

            $saldoFavorManualFlow['resumen'][] = [
                'id_tienda' => $tiendaId,
                'nombre_comercial' => (string) ($tiendaRow['nombre_comercial'] ?? ''),
                'nombre_locatario' => (string) ($tiendaRow['nombre_locatario'] ?? ''),
                'locales' => $saldoFavorManualFlow['locales_por_tienda'][$tiendaId] ?? [],
                'saldo_disponible' => $saldoDisponible,
                'ingresado_periodo' => $ingresadoPeriodo,
            ];
            $saldoFavorManualFlow['total_disponible'] += $saldoDisponible;
            $saldoFavorManualFlow['total_ingresado_periodo'] += $ingresadoPeriodo;
        }

        usort(
            $saldoFavorManualFlow['resumen'],
            static function (array $a, array $b): int {
                $cmp = ((float) ($b['saldo_disponible'] ?? 0)) <=> ((float) ($a['saldo_disponible'] ?? 0));
                if ($cmp !== 0) {
                    return $cmp;
                }
                return strcasecmp((string) ($a['nombre_comercial'] ?? ''), (string) ($b['nombre_comercial'] ?? ''));
            }
        );

        if (msp2TableExists($conn, 'msp_saldo_favor_periodo_aplicaciones')) {
            $saldoFavorAppliedFlow['disponible'] = true;
            $saldoFavorAppliedFlow['columns_ok'] = true;
            $saldoAplicadoStmt = $conn->prepare(
                "SELECT
                    sfa.id_saldo_favor_periodo_aplicacion AS id_pago,
                    sfa.fecha_aplicacion AS fecha_pago,
                    sfa.id_documento_cobro,
                    COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                    dc.fecha_vencimiento,
                    COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda,
                    ROUND(sfa.monto_aplicado, 2) AS monto_aplicado
                 FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa
                 INNER JOIN dbo.msp_documentos_cobro dc
                    ON dc.id_documento_cobro = sfa.id_documento_cobro
                 INNER JOIN dbo.msp_tiendas t
                    ON t.id_tienda = sfa.id_tienda
                 WHERE sfa.periodo_facturacion = :periodo
                   AND sfa.estado_aplicacion = 1
                   AND sfa.monto_aplicado > 0
                 ORDER BY sfa.fecha_aplicacion ASC, sfa.id_saldo_favor_periodo_aplicacion ASC"
            );
            $saldoAplicadoStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $saldoAplicadoStmt->execute();
            $saldoFavorAppliedFlow['rows'] = $saldoAplicadoStmt->fetchAll() ?: [];
        } elseif (msp2TableExists($conn, 'msp_pagos')) {
            $saldoFavorAppliedFlow['disponible'] = true;
            $hasAplicaSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor');
            $hasMontoSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'monto_saldo_favor_generado');
            $hasMontoPagado = msp2ColumnExists($conn, 'msp_pagos', 'monto_pagado');
            $hasMontoPago = msp2ColumnExists($conn, 'msp_pagos', 'monto_pago');
            $saldoFavorAppliedFlow['columns_ok'] = $hasAplicaSaldoFavor;

            if ($hasAplicaSaldoFavor) {
                $montoPagoExpr = $hasMontoPagado
                    ? 'ISNULL(p.monto_pagado, 0)'
                    : ($hasMontoPago ? 'ISNULL(p.monto_pago, 0)' : '0');
                $montoAplicadoExpr = $hasMontoSaldoFavor
                    ? "CASE
                        WHEN ISNULL(p.monto_saldo_favor_generado, 0) > 0 THEN p.monto_saldo_favor_generado
                        ELSE $montoPagoExpr
                       END"
                    : $montoPagoExpr;
                $estadoPagoFilter = msp2ColumnExists($conn, 'msp_pagos', 'estado_pago')
                    ? ' AND p.estado_pago = 1'
                    : '';

                $saldoAplicadoStmt = $conn->prepare(
                    "SELECT
                        p.id_pago,
                        p.fecha_pago,
                        p.id_documento_cobro,
                        COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                        dc.fecha_vencimiento,
                        COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda,
                        ROUND($montoAplicadoExpr, 2) AS monto_aplicado
                     FROM dbo.msp_pagos p
                     INNER JOIN dbo.msp_documentos_cobro dc
                        ON dc.id_documento_cobro = p.id_documento_cobro
                     INNER JOIN dbo.msp_tiendas t
                        ON t.id_tienda = dc.id_tienda
                     WHERE dc.periodo_facturacion = :periodo"
                     . $estadoPagoFilter . "
                       AND ISNULL(p.aplica_desde_saldo_favor, 0) = 1
                       AND ($montoAplicadoExpr) > 0
                     ORDER BY p.fecha_pago ASC, p.id_pago ASC"
                );
                $saldoAplicadoStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                $saldoAplicadoStmt->execute();
                $saldoFavorAppliedFlow['rows'] = $saldoAplicadoStmt->fetchAll() ?: [];
            }
        }

        if (($saldoFavorAppliedFlow['rows'] ?? []) !== []) {
            $saldoFavorAppliedFlow['count'] = count($saldoFavorAppliedFlow['rows']);
            $totalSaldoAplicado = 0.0;
            foreach ($saldoFavorAppliedFlow['rows'] as $saldoAplicadoRow) {
                $totalSaldoAplicado += (float) ($saldoAplicadoRow['monto_aplicado'] ?? 0);
            }
            $saldoFavorAppliedFlow['total'] = round($totalSaldoAplicado, 2);
        }
    }
}

$saldoPendItems = [];
$saldoPendItemsTotal = 0.0;
foreach ((array) ($saldoFavorManualFlow['manual_rows'] ?? []) as $saldoPendItemRow) {
    $montoPendItem = round((float) ($saldoPendItemRow['monto_pendiente'] ?? $saldoPendItemRow['monto_movimiento'] ?? 0), 2);
    if ($montoPendItem <= 0.005) {
        continue;
    }
    $saldoPendItems[] = [
        'id_movimiento_saldo_favor' => (int) ($saldoPendItemRow['id_movimiento_saldo_favor'] ?? 0),
        'id_tienda' => (int) ($saldoPendItemRow['id_tienda'] ?? 0),
        'fecha_movimiento' => (string) ($saldoPendItemRow['fecha_movimiento'] ?? ''),
        'locales' => is_array($saldoPendItemRow['locales'] ?? null) ? $saldoPendItemRow['locales'] : [],
        'nombre_arrendatario' => (string) ($saldoPendItemRow['nombre_arrendatario'] ?? ''),
        'monto_movimiento' => round((float) ($saldoPendItemRow['monto_movimiento'] ?? 0), 2),
        'monto_pendiente' => $montoPendItem,
        'observaciones' => (string) ($saldoPendItemRow['observaciones'] ?? ''),
    ];
    $saldoPendItemsTotal = round($saldoPendItemsTotal + $montoPendItem, 2);
}
$saldoPendItemsCount = count($saldoPendItems);

$saldoFavorOptionRows = [];
foreach (($saldoFavorManualFlow['tiendas'] ?? []) as $tiendaRow) {
    $tiendaId = (int) ($tiendaRow['id_tienda'] ?? 0);
    if ($tiendaId <= 0) {
        continue;
    }
    $tiendaNombre = trim((string) ($tiendaRow['nombre_comercial'] ?? ''));
    $arrNombre = trim((string) ($tiendaRow['nombre_locatario'] ?? ''));
    $locales = $saldoFavorManualFlow['locales_por_tienda'][$tiendaId] ?? [];
    $localesLabel = $locales !== [] ? ('(' . implode(' / ', $locales) . ') ') : '(Sin locales) ';
    $arrLabel = $arrNombre !== '' ? $arrNombre : ('Arrendatario #' . $tiendaId);
    $label = $localesLabel . $arrLabel;
    $saldoFavorOptionRows[] = [
        'value' => (string) $tiendaId,
        'label' => $label,
        'search' => mb_strtolower($label . ' ' . $arrNombre . ' ' . $tiendaNombre, 'UTF-8'),
        'first_local' => (string) ($locales[0] ?? ''),
        'nombre_tienda' => $tiendaNombre,
    ];
}

usort(
    $saldoFavorOptionRows,
    static function (array $a, array $b): int {
        $firstA = trim((string) ($a['first_local'] ?? ''));
        $firstB = trim((string) ($b['first_local'] ?? ''));
        if ($firstA !== '' && $firstB !== '') {
            $cmpLocal = msp2CompareLocalCode($firstA, $firstB);
            if ($cmpLocal !== 0) {
                return $cmpLocal;
            }
        } elseif ($firstA === '' && $firstB !== '') {
            return 1;
        } elseif ($firstA !== '' && $firstB === '') {
            return -1;
        }

        $cmpTienda = strcasecmp((string) ($a['nombre_tienda'] ?? ''), (string) ($b['nombre_tienda'] ?? ''));
        if ($cmpTienda !== 0) {
            return $cmpTienda;
        }
        return strcmp((string) ($a['value'] ?? ''), (string) ($b['value'] ?? ''));
    }
);

$saldoFavorTiendaOptions = [];
$saldoFavorEditOptions = [];
foreach ($saldoFavorOptionRows as $optionRow) {
    $saldoFavorEditOptions[] = [
        'value' => (string) ($optionRow['value'] ?? ''),
        'label' => (string) ($optionRow['label'] ?? ''),
    ];

    unset($optionRow['first_local'], $optionRow['nombre_tienda']);
    $saldoFavorTiendaOptions[] = $optionRow;
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Cobranza | Saldo a favor manual</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        #saldo_favor_dropdown_btn {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding-right: 2rem;
        }
    </style>
    <?php msp2RenderMontoClpAssets(); ?>
    <?php msp2RenderSearchableSelectAssets(); ?>
</head>
<body class="d-flex flex-column min-vh-100">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2" data-gp-commandbar>
            <a href="<?php echo msp2Escape(msp2Url('cobranza/ajustes.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Volver a Ajustes de cobranza
            </a>
            <div>
                <p class="section-kicker text-center mb-0">MSP / Cobranza</p>
                <h1 class="h4 text-center mb-0">Saldo a Favor Manual</h1>
            </div>
            <a href="<?php echo msp2Escape(msp2Url('documentos_cobro/index.php')); ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-receipt me-1"></i>Ir a Documentos de cobro
            </a>
        </div>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-warning"><?php echo msp2Escape($loadError); ?></div>
        <?php else: ?>
            <div class="card mb-3">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end mb-3" id="form_periodo_saldo_favor">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Periodo</label>
                            <input type="month" class="form-control" id="periodo_saldo_favor" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>" required>
                            <div class="small text-muted mt-1">Filtra ingresos y aplicaciones del período.</div>
                        </div>
                    </form>

                    <?php if ($periodoSaldoFavorNoCreado): ?>
                        <div class="alert alert-info py-2 small mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Este período aún no ha sido creado. Puedes registrar el saldo a favor ahora: quedará asociado a este mes y se propondrá para disminuir sus documentos cuando se generen.
                        </div>
                    <?php endif; ?>

                    <?php if (!$saldoFavorManualFlow['disponible']): ?>
                                <div class="alert alert-info mb-0">El módulo de saldo a favor manual no está disponible en este ambiente.</div>
                            <?php else: ?>
                                <form method="post" class="border rounded p-3 bg-light mb-3" id="form_saldo_favor_manual_paso2">
                                    <input type="hidden" name="accion" value="crear_saldo_favor">
                                    <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>">
                                    <h2 class="h6 mb-2">Registro saldo a favor</h2>
                                    <div class="row g-2 align-items-start">
                                        <div class="col-12 col-lg-5">
                                            <?php
                                            msp2RenderSearchableSelectField([
                                                'wrapper_class' => 'col-12',
                                                'label' => 'Arrendatario',
                                                'input_name' => 'id_tienda',
                                                'input_id' => 'saldo_favor_id_tienda',
                                                'picker_id' => 'saldo_favor_picker',
                                                'button_id' => 'saldo_favor_dropdown_btn',
                                                'filter_id' => 'saldo_favor_dropdown_filter',
                                                'list_id' => 'saldo_favor_dropdown_list',
                                                'error_id' => 'saldo_favor_picker_error',
                                                'error_message' => 'Debes seleccionar un arrendatario.',
                                                'button_placeholder' => 'Selecciona arrendatario...',
                                                'filter_placeholder' => 'Buscar arrendatario o local',
                                                'empty_message' => 'No hay arrendatarios disponibles.',
                                                'required' => true,
                                                'options' => $saldoFavorTiendaOptions,
                                            ]);
                                            ?>
                                        </div>
                                        <div class="col-12 col-lg-2">
                                            <label class="form-label">Fecha</label>
                                            <input
                                                type="date"
                                                class="form-control"
                                                name="fecha_movimiento"
                                                value="<?php echo msp2Escape($manualAdjustDateDefault); ?>"
                                                min="<?php echo msp2Escape($manualAdjustDateMin); ?>"
                                                max="<?php echo msp2Escape($manualAdjustDateMax); ?>"
                                                required>
                                            <div class="form-text" title="Rango permitido: <?php echo msp2Escape($manualAdjustDateRangeUi); ?>"><?php echo msp2Escape($manualAdjustDateRangeUi); ?></div>
                                        </div>
                                        <?php msp2RenderMontoClpField([
                                            'wrapper_class' => 'col-12 col-lg-2',
                                            'id' => 'monto_saldo_favor_manual',
                                            'name' => 'saldo_favor_monto',
                                            'label' => 'Monto',
                                            'hint' => '',
                                        ]); ?>
                                        <div class="col-12 col-lg-3">
                                            <label class="form-label">Observaciones</label>
                                            <input type="text" class="form-control" name="observaciones" maxlength="500" placeholder="Opcional">
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap justify-content-end align-items-center mt-2 gap-2">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Agregar saldo a favor</button>
                                    </div>
                                </form>

                                <hr>

                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                    <div>
                                        <strong>Pendientes por aplicar</strong>
                                        <div class="small text-muted">Ingresos de saldo a favor aún no aplicados a documentos del período.</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="small text-muted">Items</div>
                                        <strong><?php echo (int) $saldoPendItemsCount; ?></strong>
                                        <div class="small text-muted mt-1">Total: <strong>$ <?php echo sfFmtNum((float) $saldoPendItemsTotal, 2); ?></strong></div>
                                    </div>
                                </div>

                                <?php if ($saldoPendItemsCount <= 0): ?>
                                    <div class="small text-muted mb-3">No hay saldo pendiente para aplicar en este período.</div>
                                <?php else: ?>
                                    <div class="table-responsive mb-3">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                            <tr>
                                                <th>Fecha</th>
                                                <th>Locales</th>
                                                <th>Arrendatario</th>
                                                <th class="text-end">Monto pendiente</th>
                                                <th class="text-end">Acciones</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($saldoPendItems as $saldoPendItemRow): ?>
                                                <?php
                                                $saldoPendLocales = is_array($saldoPendItemRow['locales'] ?? null) ? implode(' / ', $saldoPendItemRow['locales']) : '';
                                                $saldoPendMovId = (int) ($saldoPendItemRow['id_movimiento_saldo_favor'] ?? 0);
                                                $saldoPendTiendaId = (int) ($saldoPendItemRow['id_tienda'] ?? 0);
                                                $saldoPendMontoMov = round((float) ($saldoPendItemRow['monto_movimiento'] ?? 0), 2);
                                                $saldoPendMonto = round((float) ($saldoPendItemRow['monto_pendiente'] ?? 0), 2);
                                                $saldoPendCanManage = $saldoPendMovId > 0 && $saldoPendMontoMov > 0 && $saldoPendMonto + 0.0001 >= $saldoPendMontoMov;
                                                $saldoPendObs = (string) ($saldoPendItemRow['observaciones'] ?? '');
                                                ?>
                                                <tr>
                                                    <td><?php echo msp2Escape(sfFmtFecha((string) ($saldoPendItemRow['fecha_movimiento'] ?? ''))); ?></td>
                                                    <td><?php echo $saldoPendLocales !== '' ? msp2Escape($saldoPendLocales) : '-'; ?></td>
                                                    <td><?php echo msp2Escape((string) ($saldoPendItemRow['nombre_arrendatario'] ?? '-')); ?></td>
                                                    <td class="text-end fw-semibold">$ <?php echo sfFmtNum((float) ($saldoPendItemRow['monto_pendiente'] ?? 0), 2); ?></td>
                                                    <td class="text-end">
                                                        <div class="d-inline-flex gap-1">
                                                            <button
                                                                type="button"
                                                                class="btn btn-outline-warning btn-sm"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#modal_editar_saldo_favor_manual"
                                                                data-edit-id="<?php echo $saldoPendMovId; ?>"
                                                                data-edit-id-tienda="<?php echo $saldoPendTiendaId; ?>"
                                                                data-edit-fecha="<?php echo msp2Escape(substr((string) ($saldoPendItemRow['fecha_movimiento'] ?? ''), 0, 10)); ?>"
                                                                data-edit-monto="<?php echo msp2Escape(number_format($saldoPendMontoMov, 2, '.', '')); ?>"
                                                                data-edit-observaciones="<?php echo msp2Escape($saldoPendObs); ?>"
                                                                <?php echo $saldoPendCanManage ? '' : 'disabled'; ?>>
                                                                <i class="bi bi-pencil-square me-1"></i>Editar
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="btn btn-outline-danger btn-sm"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#modal_cancelar_saldo_favor_manual"
                                                                data-cancel-id="<?php echo $saldoPendMovId; ?>"
                                                                data-cancel-descripcion="<?php echo msp2Escape(($saldoPendLocales !== '' ? $saldoPendLocales . ' - ' : '') . (string) ($saldoPendItemRow['nombre_arrendatario'] ?? '-') . ' | Ingreso: $ ' . sfFmtNum($saldoPendMontoMov, 2)); ?>"
                                                                <?php echo $saldoPendCanManage ? '' : 'disabled'; ?>>
                                                                <i class="bi bi-x-circle me-1"></i>Eliminar
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="modal fade" id="modal_editar_saldo_favor_manual" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Editar ingreso manual</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="accion" value="actualizar_saldo_favor_manual">
                                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>">
                                                        <input type="hidden" name="id_movimiento_saldo_favor" id="sf_edit_movimiento_id" value="">

                                                        <div class="row g-2">
                                                            <div class="col-12">
                                                                <label class="form-label">Arrendatario</label>
                                                                <select class="form-select" name="id_tienda" id="sf_edit_id_tienda" required>
                                                                    <option value="">Selecciona...</option>
                                                                    <?php foreach ($saldoFavorEditOptions as $editOption): ?>
                                                                        <option value="<?php echo msp2Escape((string) ($editOption['value'] ?? '')); ?>"><?php echo msp2Escape((string) ($editOption['label'] ?? '')); ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label">Fecha</label>
                                                                <input
                                                                    type="date"
                                                                    class="form-control"
                                                                    name="fecha_movimiento"
                                                                    id="sf_edit_fecha_movimiento"
                                                                    min="<?php echo msp2Escape($manualAdjustDateMin); ?>"
                                                                    max="<?php echo msp2Escape($manualAdjustDateMax); ?>"
                                                                    required>
                                                            </div>
                                                            <div class="col-12 col-md-4">
                                                                <label class="form-label">Monto</label>
                                                                <input type="text" class="form-control" name="saldo_favor_monto" id="sf_edit_monto" inputmode="decimal" required>
                                                            </div>
                                                            <div class="col-12">
                                                                <label class="form-label">Observaciones</label>
                                                                <textarea class="form-control" name="observaciones" id="sf_edit_observaciones" rows="3" maxlength="500"></textarea>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                                                        <button type="submit" class="btn btn-warning"><i class="bi bi-check2-circle me-1"></i>Guardar cambios</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="modal fade" id="modal_cancelar_saldo_favor_manual" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Eliminar ingreso manual</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="accion" value="cancelar_saldo_favor_manual">
                                                        <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>">
                                                        <input type="hidden" name="id_movimiento_saldo_favor" id="sf_cancel_movimiento_id" value="">
                                                        <p class="mb-2">Se eliminará el ingreso:</p>
                                                        <p class="small text-muted mb-3" id="sf_cancel_descripcion">-</p>
                                                        <label class="form-label" for="sf_cancel_reason">Motivo (opcional)</label>
                                                        <textarea class="form-control" id="sf_cancel_reason" name="confirm_reason" rows="3" maxlength="500" placeholder="Puedes indicar por qué eliminas este ingreso"></textarea>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver</button>
                                                        <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Eliminar</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                    <div>
                                        <strong>Ya asignados en este período</strong>
                                        <div class="small text-muted">Aplicaciones de saldo a favor ya asociadas a documentos del período.</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="small text-muted">Aplicaciones</div>
                                        <strong><?php echo (int) ($saldoFavorAppliedFlow['count'] ?? 0); ?></strong>
                                        <div class="small text-muted mt-1">Total: <strong>$ <?php echo sfFmtNum((float) ($saldoFavorAppliedFlow['total'] ?? 0), 2); ?></strong></div>
                                    </div>
                                </div>
                                <?php if (!(bool) ($saldoFavorAppliedFlow['disponible'] ?? false)): ?>
                                    <div class="small text-muted">No hay trazabilidad de aplicaciones disponible en este ambiente.</div>
                                <?php elseif ((int) ($saldoFavorAppliedFlow['count'] ?? 0) <= 0): ?>
                                    <div class="small text-muted">Todavía no hay saldo aplicado en este período.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-0">
                                            <thead>
                                            <tr>
                                                <th>Fecha</th>
                                                <th>Documento</th>
                                                <th>Tienda</th>
                                                <th class="text-end">Monto aplicado</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ((array) ($saldoFavorAppliedFlow['rows'] ?? []) as $saldoAplicadoRow): ?>
                                                <tr>
                                                    <td><?php echo msp2Escape(sfFmtFecha((string) ($saldoAplicadoRow['fecha_pago'] ?? ''))); ?></td>
                                                    <td><?php echo msp2Escape((string) ($saldoAplicadoRow['numero_documento'] ?? ('#' . (int) ($saldoAplicadoRow['id_documento_cobro'] ?? 0)))); ?></td>
                                                    <td><?php echo msp2Escape((string) ($saldoAplicadoRow['nombre_tienda'] ?? '-')); ?></td>
                                                    <td class="text-end fw-semibold">$ <?php echo sfFmtNum((float) ($saldoAplicadoRow['monto_aplicado'] ?? 0), 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const formPeriodo = document.getElementById('form_periodo_saldo_favor');
    const inputPeriodo = document.getElementById('periodo_saldo_favor');

    if (formPeriodo instanceof HTMLFormElement && inputPeriodo instanceof HTMLInputElement) {
        inputPeriodo.addEventListener('change', () => {
            if (inputPeriodo.value.trim() !== '') {
                formPeriodo.requestSubmit();
            }
        });
    }

    const saldoFavorForm = document.getElementById('form_saldo_favor_manual_paso2');
    const saldoFavorHidden = document.getElementById('saldo_favor_id_tienda');
    const saldoFavorDropdownBtn = document.getElementById('saldo_favor_dropdown_btn');
    const saldoFavorPickerError = document.getElementById('saldo_favor_picker_error');

    const saldoFavorPickerReady = saldoFavorHidden instanceof HTMLInputElement
        && saldoFavorDropdownBtn instanceof HTMLButtonElement;

    if (saldoFavorForm instanceof HTMLFormElement) {
        saldoFavorForm.addEventListener('submit', (event) => {
            if (!saldoFavorPickerReady) {
                return;
            }
            if (saldoFavorHidden.value.trim() !== '') {
                return;
            }
            event.preventDefault();
            saldoFavorDropdownBtn.classList.add('is-invalid');
            if (saldoFavorPickerError instanceof HTMLDivElement) {
                saldoFavorPickerError.classList.remove('d-none');
            }
            saldoFavorDropdownBtn.focus();
        });
    }

    const modalEditarSaldoFavor = document.getElementById('modal_editar_saldo_favor_manual');
    if (modalEditarSaldoFavor) {
        modalEditarSaldoFavor.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            if (!trigger) {
                return;
            }

            const idInput = modalEditarSaldoFavor.querySelector('#sf_edit_movimiento_id');
            const tiendaInput = modalEditarSaldoFavor.querySelector('#sf_edit_id_tienda');
            const fechaInput = modalEditarSaldoFavor.querySelector('#sf_edit_fecha_movimiento');
            const montoInput = modalEditarSaldoFavor.querySelector('#sf_edit_monto');
            const observacionesInput = modalEditarSaldoFavor.querySelector('#sf_edit_observaciones');

            if (idInput instanceof HTMLInputElement) {
                idInput.value = String(trigger.getAttribute('data-edit-id') || '');
            }
            if (tiendaInput instanceof HTMLSelectElement) {
                tiendaInput.value = String(trigger.getAttribute('data-edit-id-tienda') || '');
            }
            if (fechaInput instanceof HTMLInputElement) {
                fechaInput.value = String(trigger.getAttribute('data-edit-fecha') || '');
            }
            if (montoInput instanceof HTMLInputElement) {
                montoInput.value = String(trigger.getAttribute('data-edit-monto') || '');
            }
            if (observacionesInput instanceof HTMLTextAreaElement) {
                observacionesInput.value = String(trigger.getAttribute('data-edit-observaciones') || '');
            }
        });
    }

    const modalCancelarSaldoFavor = document.getElementById('modal_cancelar_saldo_favor_manual');
    if (modalCancelarSaldoFavor) {
        modalCancelarSaldoFavor.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            if (!trigger) {
                return;
            }

            const idInput = modalCancelarSaldoFavor.querySelector('#sf_cancel_movimiento_id');
            const descripcionNode = modalCancelarSaldoFavor.querySelector('#sf_cancel_descripcion');
            const motivoInput = modalCancelarSaldoFavor.querySelector('#sf_cancel_reason');

            if (idInput instanceof HTMLInputElement) {
                idInput.value = String(trigger.getAttribute('data-cancel-id') || '');
            }
            if (descripcionNode instanceof HTMLElement) {
                descripcionNode.textContent = String(trigger.getAttribute('data-cancel-descripcion') || '-');
            }
            if (motivoInput instanceof HTMLTextAreaElement) {
                motivoInput.value = '';
            }
        });
    }
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
