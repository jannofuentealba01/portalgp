<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('pagos/index.php');
}

$idDocumentoCobro = filter_input(INPUT_POST, 'id_documento_cobro', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$fechaPagoRaw = trim((string) ($_POST['fecha_pago'] ?? ''));
[$montoValido, $montoAplicar] = msp2NormalizeDecimalInput($_POST['monto_aplicar'] ?? null, 2);
$observaciones = msp2NormalizeText($_POST['observaciones'] ?? null);

try {
    if (!$idDocumentoCobro) {
        throw new RuntimeException('Debes seleccionar un documento.');
    }
    if ($fechaPagoRaw === '') {
        throw new RuntimeException('Debes indicar fecha de aplicacion.');
    }
    $fechaPago = DateTimeImmutable::createFromFormat('Y-m-d', $fechaPagoRaw);
    if ($fechaPago === false || $fechaPago->format('Y-m-d') !== $fechaPagoRaw) {
        throw new RuntimeException('La fecha de aplicacion no es valida.');
    }
    if (!$montoValido || $montoAplicar === null || $montoAplicar <= 0) {
        throw new RuntimeException('El monto a aplicar debe ser mayor a cero.');
    }
    if (!msp2ProcedureExists($conn, 'msp_garantia_aplicar_documento')) {
        throw new RuntimeException('No existe el procedimiento dbo.msp_garantia_aplicar_documento. Ejecuta los patches de garantia.');
    }

    $stmt = $conn->prepare(
        'DECLARE @id_pago INT, @id_mov INT;
         EXEC dbo.msp_garantia_aplicar_documento
            @id_documento_cobro = :id_documento_cobro,
            @id_garantia = NULL,
            @fecha_pago = :fecha_pago,
            @monto_aplicar = :monto_aplicar,
            @observaciones = :observaciones,
            @id_pago_generado = @id_pago OUTPUT,
            @id_movimiento_garantia = @id_mov OUTPUT;
         SELECT @id_pago AS id_pago_generado, @id_mov AS id_movimiento_garantia;'
    );
    $stmt->bindValue(':id_documento_cobro', (int) $idDocumentoCobro, PDO::PARAM_INT);
    $stmt->bindValue(':fecha_pago', $fechaPagoRaw, PDO::PARAM_STR);
    $stmt->bindValue(':monto_aplicar', (float) $montoAplicar);
    $stmt->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->execute();
    $row = $stmt->fetch() ?: [];

    msp2SetFlash(
        'success',
        'Garantia aplicada correctamente. Pago #' . (int) ($row['id_pago_generado'] ?? 0) . '.'
    );
} catch (Throwable $exception) {
    msp2SetFlash('danger', $exception->getMessage());
}

msp2Redirect('pagos/index.php');
