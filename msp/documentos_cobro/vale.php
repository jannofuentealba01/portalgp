<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/vale_lib.php';

msp2RequireAccess();

$idDocumento = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$expiresAt = filter_input(INPUT_GET, 'exp', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$signature = trim((string) ($_GET['sig'] ?? ''));

if ($idDocumento === false || $idDocumento === null) {
    http_response_code(400);
    echo 'Debes indicar un documento valido.';
    exit();
}

if (
    $expiresAt === false
    || $expiresAt === null
    || $signature === ''
    || !msp2VerifySignedParams('documento_cobro_vale', [
        'id' => $idDocumento,
        'exp' => $expiresAt,
        'sig' => $signature,
    ])
) {
    http_response_code(403);
    echo 'El enlace del vale no es válido o expiró. Vuelve a abrirlo desde la aplicación.';
    exit();
}

try {
    [$filename, $pdfOutput] = msp2BuildDocumentoCobroValePdf($conn, (int) $idDocumento);
} catch (Throwable $e) {
    http_response_code(500);
    echo msp2Escape($e->getMessage());
    exit();
}

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
echo $pdfOutput;
exit();
