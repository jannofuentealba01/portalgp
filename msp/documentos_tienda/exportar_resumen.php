<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/helper.php';

msp2DocumentosTiendaRequireAny('lectura');

$batchId = filter_var($_GET['id_lote'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$batch = $batchId > 0 ? msp2DocumentosTiendaFetchBatch($conn, $batchId) : null;
if (!is_array($batch)) {
    http_response_code(404);
    exit('Lote no encontrado.');
}
$files = msp2DocumentosTiendaFetchFiles($conn, $batchId);
$safeBatchName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $batch['nombre_lote']) ?: 'lote';
$filename = 'documentos_tienda_' . $batchId . '_' . trim($safeBatchName, '_') . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
echo "\xEF\xBB\xBF";
$output = fopen('php://output', 'wb');
if ($output === false) {
    exit('No fue posible generar el resumen.');
}
$headers = [
    'Lote', 'Nombre lote', 'Estado lote', 'ID archivo', 'Archivo', 'Tamaño bytes', 'Hash SHA-256',
    'Estado documento', 'Contrato', 'Tienda', 'Locales', 'Arrendatario', 'RUT', 'Correo registrado',
    'Observaciones', 'Último error', 'Intentos envío', 'Fecha envío demo', 'Fecha carga',
];
fputcsv($output, pgpSpreadsheetSafeRow($headers), ';', '"', '\\');
foreach ($files as $file) {
    $row = [
        $batchId,
        $batch['nombre_lote'],
        $batch['estado_lote'],
        $file['id_documento_tienda_archivo'],
        $file['nombre_original'],
        $file['bytes_archivo'],
        $file['hash_sha256'],
        $file['estado_archivo'],
        $file['id_contrato_arriendo'],
        $file['nombre_comercial'],
        $file['locales_label'],
        $file['nombre_arrendatario'],
        $file['rut'],
        $file['correo_destino_snapshot'],
        $file['observaciones'],
        $file['ultimo_error'],
        $file['intentos_envio'],
        $file['enviado_at'],
        $file['fecha_registro'],
    ];
    fputcsv($output, pgpSpreadsheetSafeRow($row), ';', '"', '\\');
}
fclose($output);
exit;
