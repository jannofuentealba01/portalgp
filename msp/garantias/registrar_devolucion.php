<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/devolucion_service.php';
msp2RequireAccess('MSP Cobranza', 'escritura');
msp2RequireValidCsrfToken();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('garantias/devoluciones.php');
}

$returnTo = msp2PendingReturnTo($_POST['return_to'] ?? '');
$idGarantia = filter_input(INPUT_POST, 'id_garantia_tienda', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$idCuenta = filter_input(INPUT_POST, 'id_cuenta_tesoreria', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$idCaja = filter_input(INPUT_POST, 'id_cuenta_caja_admin', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$medio = strtoupper(trim((string) ($_POST['medio_devolucion'] ?? '')));
$forma = strtoupper(trim((string) ($_POST['forma_devolucion'] ?? '')));
$fecha = trim((string) ($_POST['fecha_devolucion'] ?? ''));
$solicitud = trim((string) ($_POST['id_solicitud'] ?? ''));
[$okMonto, $monto] = msp2NormalizeDecimalInput((string) ($_POST['monto_devolucion'] ?? ''), 2);
$data = [
    'id_solicitud' => $solicitud, 'id_garantia_tienda' => (int) $idGarantia,
    'id_cuenta_tesoreria' => (int) $idCuenta,
    'id_cuenta_caja_admin' => $medio === 'TRANSFERENCIA' ? (int) $idCaja : null,
    'fecha_devolucion' => $fecha, 'monto_devolucion' => $monto,
    'forma_devolucion' => $forma, 'medio_devolucion' => $medio,
    'id_usuario' => (int) $_SESSION['usuario']['id'],
];
foreach (['beneficiario', 'rut_beneficiario', 'banco_destino', 'cuenta_destino', 'referencia_transferencia', 'observaciones', 'motivo_autorizacion'] as $key) {
    $data[$key] = msp2NormalizeText((string) ($_POST[$key] ?? ''));
}
$redirect = msp2WithPendingReturn('garantias/devoluciones.php?id_garantia_tienda=' . (int) $idGarantia, $returnTo);
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
$invalid = !$idGarantia || !$idCuenta || !$okMonto || $monto === null || (float) $monto <= 0
    || !$date || $date->format('Y-m-d') !== $fecha
    || !preg_match('/^[0-9a-f]{32}$/D', $solicitud)
    || !in_array($medio, ['EFECTIVO', 'TRANSFERENCIA'], true)
    || !in_array($forma, ['PARCIAL', 'TOTAL'], true)
    || $data['beneficiario'] === '' || $data['motivo_autorizacion'] === '';
foreach (['beneficiario' => 200, 'rut_beneficiario' => 20, 'banco_destino' => 120, 'cuenta_destino' => 100,
    'referencia_transferencia' => 200, 'observaciones' => 500, 'motivo_autorizacion' => 500] as $key => $limit) {
    $invalid = $invalid || mb_strlen($data[$key]) > $limit;
}
if ($invalid) {
    msp2SetFlash('warning', 'Completa correctamente la devolución, su monto y el motivo de autorización.');
    msp2Redirect($redirect);
}
if ($medio === 'TRANSFERENCIA' && (!$idCaja || $data['banco_destino'] === '' || $data['cuenta_destino'] === '' || $data['referencia_transferencia'] === '')) {
    msp2SetFlash('warning', 'La transferencia requiere banco, cuenta destino, referencia y caja de registro administrativo.');
    msp2Redirect($redirect);
}
foreach (['rut_beneficiario', 'observaciones'] as $key) {
    $data[$key] = $data[$key] !== '' ? $data[$key] : null;
}
foreach (['banco_destino', 'cuenta_destino', 'referencia_transferencia'] as $key) {
    $data[$key] = $medio === 'TRANSFERENCIA' ? $data[$key] : null;
}

// Conserva tambien una confirmacion incierta: reintentar usa la misma clave y datos.
$_SESSION['msp2_devolucion_reintento'] = $data;
try {
    $result = msp2RegistrarDevolucionCompleta($conn, $data);
    unset($_SESSION['msp2_devolucion_reintento']);
    $message = 'Devolución #' . (int) $result['id_devolucion_garantia'];
    if ($result['reutilizada']) {
        $message .= ' ya registrada; no se duplicó ningún movimiento.';
    } else {
        $message .= ' emitida correctamente. ';
        $message .= $medio === 'TRANSFERENCIA'
            ? 'El dinero salió del banco; caja conserva la entrada y salida administrativas sin movimiento de efectivo.'
            : 'El dinero salió de caja; no se generaron movimientos administrativos adicionales.';
    }
    msp2SetFlash('success', $message);
} catch (Throwable $error) {
    $businessErrors = [
        51908 => 'La caja está cerrada para esa fecha.',
        51909 => 'La cuenta bancaria está conciliada para esa fecha.',
        51910 => 'La devolución supera el saldo de la cuenta de origen.',
        51911 => 'La garantía mantiene fondos reservados. Libéralos o aplícalos antes de devolver.',
        51912 => 'La devolución supera la garantía recibida y disponible.',
        53924 => 'El saldo total cambió. Recarga y confirma el nuevo importe antes de devolverlo.',
        53907 => 'La caja de registro debe estar activa y tener la misma moneda que el banco.',
    ];
    $code = $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? 0) : 0;
    $message = $businessErrors[$code] ?? pgpPublicOrBusinessException($error, 'msp.garantias.registrar_devolucion',
        'No fue posible confirmar la devolución. La solicitud se conservó para reintentar sin duplicarla.');
    msp2SetFlash('warning', $message);
}
msp2Redirect($redirect);


