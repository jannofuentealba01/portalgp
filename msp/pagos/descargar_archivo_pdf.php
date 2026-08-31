<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/archivos_pdf_helper.php';

msp2RequireAccess();

function msp2DescargaArchivoPdfLogPath(): string
{
    $primary = 'C:\\wamp64\\logs\\msp_pdf_debug.log';
    $primaryDir = dirname($primary);
    if (is_dir($primaryDir) && is_writable($primaryDir)) {
        return $primary;
    }

    return rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'msp_pdf_debug.log';
}

function msp2DescargaArchivoPdfDebug(array $context): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ';
    $parts = [];
    foreach ($context as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        } elseif (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $value = is_string($encoded) ? $encoded : '[array]';
        }
        $parts[] = $key . '=' . str_replace(["\r", "\n"], [' ', ' '], (string) $value);
    }
    $line .= implode(' | ', $parts) . PHP_EOL;
    @file_put_contents(msp2DescargaArchivoPdfLogPath(), $line, FILE_APPEND);
}

function msp2DescargaArchivoPdfAbort(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit();
}

function msp2DescargaArchivoPdfDebugMessage(Throwable $exception, string $fallback): string
{
    $message = trim($exception->getMessage());
    if ($message === '') {
        return $fallback;
    }

    return $fallback . ' Detalle: ' . $message;
}

$idArchivo = filter_input(INPUT_GET, 'id_archivo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$disposition = strtolower(trim((string) ($_GET['disposition'] ?? 'attachment')));
if (!in_array($disposition, ['attachment', 'inline'], true)) {
    $disposition = 'attachment';
}

msp2DescargaArchivoPdfDebug([
    'event' => 'request_start',
    'id_archivo' => $idArchivo === false ? 'false' : ($idArchivo ?? 'null'),
    'disposition' => $disposition,
    'request_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'user_id' => (int) ($_SESSION['usuario']['id'] ?? 0),
]);

if ($idArchivo !== false && $idArchivo !== null) {
    try {
        $row = msp2ArchivosPdfFindById($conn, (int) $idArchivo);
        if (!is_array($row)) {
            msp2DescargaArchivoPdfDebug([
                'event' => 'row_not_found',
                'id_archivo' => (int) $idArchivo,
            ]);
            msp2DescargaArchivoPdfAbort(404, 'El archivo solicitado no existe.');
        }

        msp2DescargaArchivoPdfDebug([
            'event' => 'row_loaded',
            'id_archivo' => (int) $idArchivo,
            'tipo_archivo' => (string) ($row['tipo_archivo'] ?? ''),
            'estado_archivo' => (string) ($row['estado_archivo'] ?? ''),
            'ruta_relativa' => (string) ($row['ruta_relativa'] ?? ''),
            'nombre_archivo' => (string) ($row['nombre_archivo'] ?? ''),
            'bytes_archivo_db' => (int) ($row['bytes_archivo'] ?? 0),
        ]);

        $materialized = msp2ArchivosPdfRefreshMaterialized($conn, $row, false);
        $absolutePath = (string) ($materialized['absolute_path'] ?? '');
        msp2DescargaArchivoPdfDebug([
            'event' => 'materialized',
            'id_archivo' => (int) $idArchivo,
            'absolute_path' => $absolutePath,
            'is_file' => $absolutePath !== '' ? is_file($absolutePath) : false,
            'is_readable' => $absolutePath !== '' ? is_readable($absolutePath) : false,
        ]);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            msp2DescargaArchivoPdfAbort(404, 'El archivo respaldado no está disponible.');
        }

        $filename = trim((string) ($materialized['filename'] ?? 'documento.pdf'));
        $mimeType = trim((string) ($materialized['mime_type'] ?? 'application/pdf'));
        $fileSize = @filesize($absolutePath);
        msp2DescargaArchivoPdfDebug([
            'event' => 'file_stats',
            'id_archivo' => (int) $idArchivo,
            'absolute_path' => $absolutePath,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'filesize' => $fileSize === false ? 'false' : $fileSize,
        ]);
        if ($fileSize === false || $fileSize <= 0) {
            msp2DescargaArchivoPdfAbort(500, 'El archivo respaldado existe, pero no se pudo determinar su tamaño.');
        }
    } catch (Throwable $exception) {
        msp2DescargaArchivoPdfDebug([
            'event' => 'exception',
            'id_archivo' => (int) $idArchivo,
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ]);
        msp2DescargaArchivoPdfAbort(500, msp2DescargaArchivoPdfDebugMessage($exception, 'No fue posible generar el PDF solicitado.'));
    }

    $safeFilename = str_replace(['"', "\r", "\n"], '', $filename);
    if ($safeFilename === '') {
        $safeFilename = 'documento.pdf';
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');
    header('Content-Length: ' . (string) $fileSize);
    msp2DescargaArchivoPdfDebug([
        'event' => 'before_readfile',
        'id_archivo' => (int) $idArchivo,
        'absolute_path' => $absolutePath,
        'content_length' => $fileSize,
    ]);
    $result = @readfile($absolutePath);
    if ($result === false) {
        msp2DescargaArchivoPdfDebug([
            'event' => 'readfile_failed',
            'id_archivo' => (int) $idArchivo,
            'absolute_path' => $absolutePath,
        ]);
        msp2DescargaArchivoPdfAbort(500, 'No fue posible transmitir el archivo respaldado.');
    }
    msp2DescargaArchivoPdfDebug([
        'event' => 'readfile_ok',
        'id_archivo' => (int) $idArchivo,
        'bytes_sent' => $result,
    ]);
    exit();
}

$token = trim((string) ($_GET['token'] ?? ''));
$index = filter_input(INPUT_GET, 'i', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0],
]);

if ($token === '' || preg_match('/^[a-f0-9]{32}$/', $token) !== 1 || $index === false || $index === null) {
    msp2DescargaArchivoPdfAbort(400, 'Solicitud de descarga inválida.');
}

$store = $_SESSION['msp2_pago_contrato_pdf_downloads'] ?? [];
if (!is_array($store) || !isset($store[$token]) || !is_array($store[$token])) {
    msp2DescargaArchivoPdfAbort(403, 'El enlace de descarga no existe o expiró.');
}

$batch = $store[$token];
$expiresAt = (int) ($batch['expires_at'] ?? 0);
$items = is_array($batch['items'] ?? null) ? $batch['items'] : [];
if ($expiresAt < time() || !isset($items[$index]) || !is_array($items[$index])) {
    unset($_SESSION['msp2_pago_contrato_pdf_downloads'][$token]);
    msp2DescargaArchivoPdfAbort(403, 'El enlace de descarga expiró.');
}

$item = $items[$index];
$item['module'] = trim((string) ($item['module'] ?? '')) !== '' ? (string) $item['module'] : 'PAGO_CONTRATO';
$type = trim((string) ($item['type'] ?? ''));

try {
    $normalized = msp2ArchivosPdfNormalizeItem($conn, $item);
    $rendered = msp2ArchivosPdfBuildPdf($conn, $type, $normalized);
    $filename = trim((string) ($rendered['filename'] ?? 'documento.pdf'));
    $mimeType = trim((string) ($rendered['mime_type'] ?? 'application/pdf'));
    $output = (string) ($rendered['bytes'] ?? '');
    if ($output === '') {
        throw new RuntimeException('PDF vacío.');
    }
} catch (Throwable) {
    msp2DescargaArchivoPdfAbort(500, 'No fue posible generar el PDF solicitado.');
}

$safeFilename = str_replace(['"', "\r", "\n"], '', $filename);
if ($safeFilename === '') {
    $safeFilename = 'documento.pdf';
}

header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('Content-Length: ' . (string) strlen($output));
echo $output;
exit();
