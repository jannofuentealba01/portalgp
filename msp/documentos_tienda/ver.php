<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/helper.php';

msp2DocumentosTiendaRequireAny('lectura');

$fileId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
if ($fileId <= 0) {
    http_response_code(404);
    exit('Documento no encontrado.');
}
$stmt = $conn->prepare(
    'SELECT nombre_original,ruta_relativa FROM dbo.msp_documentos_tienda_archivos WHERE id_documento_tienda_archivo=:id'
);
$stmt->execute([':id' => $fileId]);
$row = $stmt->fetch();
if (!is_array($row)) {
    http_response_code(404);
    exit('Documento no encontrado.');
}
try {
    $path = msp2DocumentosTiendaAbsolutePath((string) $row['ruta_relativa']);
} catch (Throwable) {
    http_response_code(404);
    exit('Documento no encontrado.');
}
if (!is_file($path)) {
    http_response_code(404);
    exit('Documento no encontrado.');
}
$downloadName = preg_replace('/[^A-Za-z0-9._ -]/u', '_', (string) $row['nombre_original']) ?: 'documento.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
session_write_close();
readfile($path);
exit;
$downloadName = preg_replace('/[^A-Za-z0-9._ -]/u', '_', (string) $row['nombre_original']) ?: 'documento.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
readfile($path);
exit;
