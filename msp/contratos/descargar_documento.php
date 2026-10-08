<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/ContratoDocumentoService.php';

msp2RequireAccess('MSP Operacion', 'lectura');

$idDocumento = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!is_int($idDocumento)) {
    http_response_code(404);
    exit('Documento adjunto no encontrado.');
}

try {
    $documento = ContratoDocumentoService::obtenerParaDescarga($conn, $idDocumento);
} catch (RuntimeException $error) {
    http_response_code(str_contains(strtolower($error->getMessage()), 'integridad') ? 409 : 404);
    exit($error->getMessage());
}

$ruta = (string) $documento['ruta_absoluta'];
$nombre = trim((string) ($documento['nombre_archivo'] ?? 'documento.pdf'));
$nombreAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nombre) ?: 'documento.pdf';
$modoInline = strtolower(trim((string) ($_GET['modo'] ?? ''))) === 'ver';
if (!str_ends_with(strtolower($nombreAscii), '.pdf')) {
    $nombreAscii .= '.pdf';
}

header('Content-Type: application/pdf');
header('Content-Length: ' . (int) $documento['bytes_archivo']);
header('Content-Disposition: ' . ($modoInline ? 'inline' : 'attachment') . '; filename="' . $nombreAscii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
readfile($ruta);
exit;
