<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ContratosDevolverGarantiaRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['contratos/index.php', 'tiendas/index.php', 'arrendatarios/index.php'];
    $allowContratoEditar = preg_match('/^contratos\/editar\.php\?id_contrato_arriendo=[1-9][0-9]*$/', $redirectTo) === 1;

    if (!in_array($redirectTo, $allowed, true) && !$allowContratoEditar) {
        $redirectTo = 'contratos/index.php';
    }

    msp2Redirect($redirectTo);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('contratos/index.php');
}

$idGarantia = filter_input(INPUT_POST, 'id_garantia', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$montoMovimientoRaw = trim((string) ($_POST['monto_movimiento'] ?? ''));
$observaciones = msp2NormalizeText((string) ($_POST['observaciones'] ?? ''));

if ($idGarantia === false || $idGarantia === null) {
    msp2SetFlash('warning', 'La garantía indicada no es válida.');
    msp2ContratosDevolverGarantiaRedirectFromPost();
}

if ($observaciones !== '' && mb_strlen($observaciones) > 500) {
    msp2SetFlash('warning', 'Las observaciones no pueden superar 500 caracteres.');
    msp2ContratosDevolverGarantiaRedirectFromPost();
}

[$okMontoMovimiento, $montoMovimiento] = msp2NormalizeDecimalInput($montoMovimientoRaw, 2);
if (!$okMontoMovimiento || $montoMovimiento === null || (float) $montoMovimiento <= 0) {
    msp2SetFlash('warning', 'El monto de devolución no es válido.');
    msp2ContratosDevolverGarantiaRedirectFromPost();
}

try {
    if (msp2ProcedureExists($conn, 'msp_garantia_devolver')) {
        $stmtSpDevolverGarantia = $conn->prepare(
            'DECLARE @id_movimiento_garantia INT;
             EXEC dbo.msp_garantia_devolver
                @id_garantia = :id_garantia,
                @monto_movimiento = :monto_movimiento,
                @observaciones = :observaciones,
                @id_pago = NULL,
                @id_movimiento_garantia = @id_movimiento_garantia OUTPUT;'
        );
        $stmtSpDevolverGarantia->bindValue(':id_garantia', (int) $idGarantia, PDO::PARAM_INT);
        $stmtSpDevolverGarantia->bindValue(':monto_movimiento', $montoMovimiento, PDO::PARAM_STR);
        $stmtSpDevolverGarantia->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtSpDevolverGarantia->execute();

        msp2SetFlash('success', 'La devolución de garantía fue registrada correctamente.');
        msp2ContratosDevolverGarantiaRedirectFromPost();
    }

    throw new RuntimeException('No está disponible el procedimiento de devolución de garantía. Ejecuta la fase 4 de DB.');
} catch (Throwable $exception) {
    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2ContratosDevolverGarantiaRedirectFromPost();
    }

    msp2SetFlash('danger', 'No fue posible registrar la devolución de garantía.');
}

msp2ContratosDevolverGarantiaRedirectFromPost();

