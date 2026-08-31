<?php
declare(strict_types=1);

/*
 * Prueba sin mutación: el registro manual puede prepararse para un período no
 * creado, pero los excesos automáticos siguen exigiendo un período Borrador.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/msp/bootstrap.php';
require_once dirname(__DIR__) . '/msp/pagos/saldo_favor_periodo_helper.php';

$fallos = [];
$assert = static function (bool $condicion, string $mensaje) use (&$fallos): void {
    echo ($condicion ? '[OK] ' : '[FAIL] ') . $mensaje . PHP_EOL;
    if (!$condicion) $fallos[] = $mensaje;
};

$periodoFuturo = '2099-12-01';
$stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_cierre_mensual WHERE periodo_facturacion=:periodo');
$stmt->execute([':periodo' => $periodoFuturo]);
$assert((int) $stmt->fetchColumn() === 0, 'El período de prueba no existe');
$assert(msp2PagoPeriodoPermiteRegistroManualSaldoFavor($conn, $periodoFuturo), 'Se permite registrar saldo manual para un período aún no creado');
$assert(!msp2PagoPeriodoPermiteAsignacionSaldoFavor($conn, $periodoFuturo), 'El excedente automático no se asigna a un período inexistente');
$assert(msp2PagoPeriodoPermiteRegistroManualSaldoFavor($conn, '2026-02-01'), 'Se permite registrar saldo manual en Borrador');
$assert(!msp2PagoPeriodoPermiteRegistroManualSaldoFavor($conn, '2026-06-01'), 'Se bloquea registrar saldo manual en período Cerrado');
$assert(!msp2PagoPeriodoPermiteRegistroManualSaldoFavor($conn, '2026-04-01'), 'Se bloquea registrar saldo manual en período Anulado');

$idTienda = (int) ($conn->query('SELECT TOP (1) id_tienda FROM dbo.msp_tiendas ORDER BY id_tienda')->fetchColumn() ?: 0);
if ($idTienda <= 0) {
    $assert(false, 'Existe una tienda para validar la trazabilidad por período');
} else {
    try {
        $conn->beginTransaction();
        $movimiento = $conn->prepare(
            "DECLARE @out TABLE (id INT);
             INSERT INTO dbo.msp_movimientos_saldo_favor_tienda
                (id_tienda,fecha_movimiento,tipo_movimiento,monto_movimiento,observaciones)
             OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out(id)
             VALUES (:tienda,'2099-11-30',5,10.00,N'Prueba automática reversible saldo futuro');
             SELECT TOP (1) id FROM @out;"
        );
        $movimiento->execute([':tienda' => $idTienda]);
        $idMovimiento = 0;
        while (true) {
            try {
                $valor = $movimiento->fetchColumn();
                if ($valor !== false) {
                    $idMovimiento = (int) $valor;
                    break;
                }
            } catch (PDOException) {
                // Conteo de filas previo al SELECT del identificador.
            }
            try {
                if (!$movimiento->nextRowset()) {
                    break;
                }
            } catch (PDOException) {
                break;
            }
        }
        $assert($idMovimiento > 0, 'Se crea en transacción el movimiento manual de prueba');

        if ($idMovimiento > 0) {
            $item = $conn->prepare(
                "INSERT INTO dbo.msp_saldo_favor_periodo_items
                    (periodo_facturacion,id_tienda,fecha_movimiento,monto_original,id_movimiento_saldo_favor,observaciones)
                 VALUES (:periodo,:tienda,'2099-11-30',10.00,:movimiento,N'Prueba automática reversible saldo futuro')"
            );
            $item->execute([':periodo' => $periodoFuturo, ':tienda' => $idTienda, ':movimiento' => $idMovimiento]);
            $verificar = $conn->prepare(
                'SELECT COUNT(*) FROM dbo.msp_saldo_favor_periodo_items
                 WHERE periodo_facturacion=:periodo AND id_movimiento_saldo_favor=:movimiento AND estado_item=1'
            );
            $verificar->execute([':periodo' => $periodoFuturo, ':movimiento' => $idMovimiento]);
            $assert((int) $verificar->fetchColumn() === 1, 'El saldo queda asociado al período futuro sin crear');
        }
    } catch (Throwable $exception) {
        $assert(false, 'Trazabilidad del saldo futuro: ' . $exception->getMessage());
    } finally {
        if ($conn->inTransaction()) $conn->rollBack();
    }
}

exit($fallos === [] ? 0 : 1);
