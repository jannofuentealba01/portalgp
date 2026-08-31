<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ResolvePagoAnularRedirect(): string
{
    $volverA = trim((string) ($_POST['volver_a'] ?? ''));
    $volverQuery = trim((string) ($_POST['volver_query'] ?? ''));

    $path = 'pagos/index.php';
    if ($volverA === 'documentos_cobro') {
        $path = 'documentos_cobro/index.php';
    }

    if ($volverQuery === '' || preg_match('/^[A-Za-z0-9_\-\.\[\]%=&]*$/', $volverQuery) !== 1) {
        return $path;
    }

    return $path . '?' . $volverQuery;
}

$redirectTarget = msp2ResolvePagoAnularRedirect();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect($redirectTarget);
}

$idPago = filter_input(INPUT_POST, 'id_pago', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$motivoAnulacion = msp2NormalizeText($_POST['motivo_anulacion'] ?? null);

if ($idPago === false || $idPago === null) {
    msp2SetFlash('warning', 'El pago indicado no es valido.');
    msp2Redirect($redirectTarget);
}

if ($motivoAnulacion === '') {
    msp2SetFlash('warning', 'Debes ingresar un motivo de anulación.');
    msp2Redirect($redirectTarget);
}

if (mb_strlen($motivoAnulacion) > 500) {
    msp2SetFlash('warning', 'El motivo de anulación supera el largo permitido.');
    msp2Redirect($redirectTarget);
}

try {
    $fechaAnulacion = date('Y-m-d');
    $stmt = $conn->prepare(
        'EXEC dbo.msp_anular_pago_documento
            @id_pago = :id_pago,
            @fecha_anulacion = :fecha_anulacion,
            @motivo_anulacion = :motivo_anulacion'
    );
    $stmt->bindValue(':id_pago', $idPago, PDO::PARAM_INT);
    $stmt->bindValue(':fecha_anulacion', $fechaAnulacion, PDO::PARAM_STR);
    $stmt->bindValue(':motivo_anulacion', $motivoAnulacion, PDO::PARAM_STR);
    $stmt->execute();

    msp2SetFlash('success', 'El pago fue anulado correctamente.');
} catch (PDOException $exception) {
    $message = $exception->getMessage();

    if (str_contains($message, '50071')) {
        msp2SetFlash('warning', 'El pago indicado no es válido.');
    } elseif (str_contains($message, '50072')) {
        msp2SetFlash('warning', 'Debes indicar la fecha de anulación.');
    } elseif (str_contains($message, '50073')) {
        msp2SetFlash('warning', 'Debes ingresar un motivo de anulación.');
    } elseif (str_contains($message, '50074')) {
        msp2SetFlash('warning', 'El pago no existe o ya estaba anulado.');
    } elseif (str_contains($message, '50075')) {
        msp2SetFlash('warning', 'No puedes anular este pago porque el saldo a favor que generó ya fue usado total o parcialmente. Primero debes anular esas aplicaciones de saldo a favor.');
    } else {
        msp2SetFlash('danger', 'No fue posible anular el pago. Revisa la estructura de la base o intenta nuevamente.');
    }
}

msp2Redirect($redirectTarget);
