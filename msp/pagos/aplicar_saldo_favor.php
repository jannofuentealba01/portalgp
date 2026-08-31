<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ResolvePagoSaldoFavorRedirect(): string
{
    $volverA = trim((string) ($_POST['volver_a'] ?? ''));
    $volverQuery = trim((string) ($_POST['volver_query'] ?? ''));

    $path = 'pagos/index.php';
    if ($volverA === 'documentos_cobro') {
        $path = 'documentos_cobro/index.php';
    } elseif ($volverA === 'cobranza_registrar_pago') {
        $path = 'cobranza/registrar_pago.php';
    }

    if ($volverQuery === '' || preg_match('/^[A-Za-z0-9_\-\.\[\]%=&]*$/', $volverQuery) !== 1) {
        return $path;
    }

    return $path . '?' . $volverQuery;
}

$redirectTarget = msp2ResolvePagoSaldoFavorRedirect();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect($redirectTarget);
}

$idDocumentoCobro = filter_input(INPUT_POST, 'id_documento_cobro', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$fechaPagoRaw = trim((string) ($_POST['fecha_pago'] ?? ''));
[$montoValido, $montoAplicar] = msp2NormalizeDecimalInput($_POST['monto_aplicar'] ?? null, 2);
$observaciones = msp2NormalizeText($_POST['observaciones'] ?? null);

if ($idDocumentoCobro === false || $idDocumentoCobro === null) {
    msp2SetFlash('warning', 'Debes seleccionar un documento válido.');
    msp2Redirect($redirectTarget);
}

$fechaPago = DateTime::createFromFormat('Y-m-d', $fechaPagoRaw);
if ($fechaPago === false || $fechaPago->format('Y-m-d') !== $fechaPagoRaw) {
    msp2SetFlash('warning', 'La fecha de aplicación no tiene un formato valido.');
    msp2Redirect($redirectTarget);
}

if (!$montoValido || $montoAplicar === null || (float) $montoAplicar <= 0) {
    msp2SetFlash('warning', 'Debes ingresar un monto a aplicar mayor a cero.');
    msp2Redirect($redirectTarget);
}

if (mb_strlen($observaciones) > 500) {
    msp2SetFlash('warning', 'Las observaciones superan el largo permitido.');
    msp2Redirect($redirectTarget);
}

try {
    $stmt = $conn->prepare(
        'EXEC dbo.msp_aplicar_saldo_favor_documento
            @id_documento_cobro = :id_documento_cobro,
            @fecha_pago = :fecha_pago,
            @monto_aplicar = :monto_aplicar,
            @observaciones = :observaciones'
    );
    $stmt->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
    $stmt->bindValue(':fecha_pago', $fechaPagoRaw, PDO::PARAM_STR);
    $stmt->bindValue(':monto_aplicar', $montoAplicar, PDO::PARAM_STR);
    $stmt->bindValue(':observaciones', $observaciones === '' ? null : $observaciones, $observaciones === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->execute();
    $resultado = $stmt->fetch() ?: [];
    $montoAplicado = isset($resultado['monto_aplicado']) ? (float) $resultado['monto_aplicado'] : (float) $montoAplicar;
    $saldoRestante = isset($resultado['saldo_favor_restante']) ? (float) $resultado['saldo_favor_restante'] : 0.0;

    msp2SetFlash(
        'success',
        'Se aplicaron $ ' . number_format($montoAplicado, 2, ',', '.')
        . ' desde el saldo a favor de la tienda. Saldo restante: $ '
        . number_format($saldoRestante, 2, ',', '.')
        . '.'
    );
} catch (PDOException $exception) {
    $message = $exception->getMessage();

    if (str_contains($message, '50081')) {
        msp2SetFlash('warning', 'Debes seleccionar un documento válido.');
    } elseif (str_contains($message, '50082')) {
        msp2SetFlash('warning', 'Debes indicar la fecha de aplicación.');
    } elseif (str_contains($message, '50083')) {
        msp2SetFlash('warning', 'El documento seleccionado no existe.');
    } elseif (str_contains($message, '50084')) {
        msp2SetFlash('warning', 'El documento ya no tiene saldo pendiente para aplicar saldo a favor.');
    } elseif (str_contains($message, '50085')) {
        msp2SetFlash('warning', 'La tienda no tiene saldo a favor disponible.');
    } elseif (str_contains($message, '50086')) {
        msp2SetFlash('warning', 'Debes ingresar un monto a aplicar mayor a cero.');
    } elseif (str_contains($message, '50087')) {
        msp2SetFlash('warning', 'El monto supera el saldo a favor disponible de la tienda.');
    } elseif (str_contains($message, '50088')) {
        msp2SetFlash('warning', 'El monto supera el saldo pendiente del documento.');
    } elseif (str_contains($message, '50041')) {
        msp2SetFlash('warning', 'No se pueden registrar movimientos sobre documentos anulados.');
    } else {
        msp2SetFlash('danger', 'No fue posible aplicar el saldo a favor. Revisa la estructura de la base o intenta nuevamente.');
    }
}

msp2Redirect($redirectTarget);
