<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/pago_contrato_import_helper.php';

msp2RequireAccess();

if (!rpcPagoContratoImportIsAdminUser($conn)) {
    rpcPagoContratoImportPreviewClear();
    msp2SetFlash('warning', 'La importación masiva de pagos por contrato está disponible solo para administradores.');
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}

$preview = rpcPagoContratoImportPreviewRead();
$volverQuery = is_array($preview) ? trim((string) ($preview['volver_query'] ?? '')) : '';
if ($volverQuery !== '' && preg_match('/^[A-Za-z0-9_\-\.\[\]%=&]*$/', $volverQuery) !== 1) {
    $volverQuery = '';
}

rpcPagoContratoImportPreviewClear();
msp2SetFlash('info', 'Previsualización descartada.');
msp2Redirect('cobranza/registrar_pago_contrato.php' . ($volverQuery !== '' ? ('?' . $volverQuery) : ''));
