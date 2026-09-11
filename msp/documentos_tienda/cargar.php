<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/helper.php';

msp2RequireAccess('MSP Documentos Tienda Carga', 'escritura');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    msp2Redirect('documentos_tienda/index.php');
}

$batchId = filter_var($_POST['id_lote'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$batchName = msp2NormalizeText((string) ($_POST['nombre_lote'] ?? ''));
$uploads = $_FILES['archivos'] ?? null;
$movedFiles = [];
$createdBatch = false;

try {
    if (!is_array($uploads) || !is_array($uploads['name'] ?? null)) {
        throw new RuntimeException('Selecciona al menos un archivo PDF.');
    }

    $totalInput = count($uploads['name']);
    if ($totalInput < 1 || $totalInput > 20) {
        throw new RuntimeException('Puedes cargar hasta 20 archivos por tanda y continuar agregando después.');
    }
    if ($batchId > 0) {
        $existingBatch = msp2DocumentosTiendaFetchBatch($conn, $batchId);
        if ($existingBatch === null) {
            throw new RuntimeException('El lote seleccionado no existe.');
        }
        msp2DocumentosTiendaAssertEditable($existingBatch);
    }
    if ($batchId === 0 && $batchName === '') {
        $batchName = 'Carga ' . date('d-m-Y H:i');
    }
    $batchName = mb_substr($batchName, 0, 180, 'UTF-8');

    $conn->beginTransaction();
    if ($batchId === 0) {
        $stmt = $conn->prepare(
            'INSERT INTO dbo.msp_documentos_tienda_lotes (nombre_lote,estado_lote,id_usuario_creador)
             OUTPUT INSERTED.id_lote_documentos_tienda
             VALUES (:nombre,N\'BORRADOR\',:usuario)'
        );
        $stmt->execute([':nombre' => $batchName, ':usuario' => msp2DocumentosTiendaUserId()]);
        $batchId = (int) $stmt->fetchColumn();
        $createdBatch = true;
        msp2DocumentosTiendaEvent($conn, $batchId, null, 'LOTE_CREADO', 'Lote creado para carga incremental.');
    }

    $batchDirRelative = 'lote_' . $batchId;
    $batchDir = msp2DocumentosTiendaAbsolutePath($batchDirRelative);
    msp2DocumentosTiendaEnsureDir($batchDir);

    $insert = $conn->prepare(
        'INSERT INTO dbo.msp_documentos_tienda_archivos
            (id_lote_documentos_tienda,nombre_original,nombre_almacenado,ruta_relativa,mime_type,hash_sha256,bytes_archivo,id_usuario_carga)
         OUTPUT INSERTED.id_documento_tienda_archivo
         VALUES (:lote,:original,:almacenado,:ruta,:mime,:hash,:bytes,:usuario)'
    );
    $exists = $conn->prepare(
        'SELECT TOP (1) a.id_documento_tienda_archivo,a.id_lote_documentos_tienda,a.nombre_original,l.nombre_lote
         FROM dbo.msp_documentos_tienda_archivos a
         INNER JOIN dbo.msp_documentos_tienda_lotes l ON l.id_lote_documentos_tienda=a.id_lote_documentos_tienda
         WHERE a.hash_sha256=:hash
         ORDER BY a.id_documento_tienda_archivo'
    );

    $loaded = 0;
    $skipped = [];
    for ($i = 0; $i < $totalInput; $i++) {
        $original = basename(trim((string) ($uploads['name'][$i] ?? '')));
        $tmp = (string) ($uploads['tmp_name'][$i] ?? '');
        $error = (int) ($uploads['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        $size = (int) ($uploads['size'][$i] ?? 0);

        if ($error !== UPLOAD_ERR_OK) {
            $skipped[] = ($original !== '' ? $original : 'Archivo #' . ($i + 1)) . ': carga incompleta';
            continue;
        }
        if ($original === '' || strtolower((string) pathinfo($original, PATHINFO_EXTENSION)) !== 'pdf') {
            $skipped[] = ($original !== '' ? $original : 'Archivo #' . ($i + 1)) . ': no es PDF';
            continue;
        }
        if ($size <= 0 || $size > 20 * 1024 * 1024 || !is_uploaded_file($tmp)) {
            $skipped[] = $original . ': archivo vacío, inválido o mayor a 20 MB';
            continue;
        }
        $handle = @fopen($tmp, 'rb');
        $magic = is_resource($handle) ? (string) fread($handle, 5) : '';
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($magic !== '%PDF-') {
            $skipped[] = $original . ': el contenido no corresponde a un PDF';
            continue;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string) $finfo->file($tmp));
        if (!in_array($mime, ['application/pdf', 'application/x-pdf', 'application/octet-stream'], true)) {
            $skipped[] = $original . ': tipo de contenido no permitido';
            continue;
        }
        $hash = hash_file('sha256', $tmp);
        if (!is_string($hash) || strlen($hash) !== 64) {
            $skipped[] = $original . ': no fue posible verificar su integridad';
            continue;
        }
        $exists->execute([':hash' => $hash]);
        $duplicate = $exists->fetch();
        if (is_array($duplicate)) {
            $skipped[] = $original . ': duplicado del lote #' . (int) $duplicate['id_lote_documentos_tienda']
                . ' (' . (string) $duplicate['nombre_lote'] . ')';
            continue;
        }

        $stored = bin2hex(random_bytes(16)) . '.pdf';
        $relative = $batchDirRelative . '/' . $stored;
        $absolute = msp2DocumentosTiendaAbsolutePath($relative);
        if (!move_uploaded_file($tmp, $absolute)) {
            throw new RuntimeException('No fue posible guardar uno de los PDFs en el almacenamiento seguro.');
        }
        $movedFiles[] = $absolute;

        $insert->execute([
            ':lote' => $batchId,
            ':original' => mb_substr($original, 0, 260, 'UTF-8'),
            ':almacenado' => $stored,
            ':ruta' => $relative,
            ':mime' => 'application/pdf',
            ':hash' => $hash,
            ':bytes' => $size,
            ':usuario' => msp2DocumentosTiendaUserId(),
        ]);
        $fileId = (int) $insert->fetchColumn();
        msp2DocumentosTiendaEvent($conn, $batchId, $fileId, 'ARCHIVO_CARGADO', 'PDF cargado: ' . $original);
        $loaded++;
    }

    if ($loaded === 0 && $createdBatch) {
        throw new RuntimeException('Ningún archivo pudo ser cargado.');
    }
    msp2DocumentosTiendaRefreshBatch($conn, $batchId);
    $conn->commit();

    $message = 'Se cargaron ' . $loaded . ' PDF(s) al lote #' . $batchId . '.';
    if ($skipped !== []) {
        $message .= ' Omitidos: ' . implode(' | ', array_slice($skipped, 0, 5));
        if (count($skipped) > 5) {
            $message .= ' y ' . (count($skipped) - 5) . ' más.';
        }
    }
    msp2SetFlash($loaded > 0 ? ($skipped === [] ? 'success' : 'warning') : 'warning', $message);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    foreach ($movedFiles as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    pgpLogException($e, 'msp.documentos_tienda.cargar');
    msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible cargar los documentos.');
}

msp2Redirect('documentos_tienda/index.php' . ($batchId > 0 ? '?id_lote=' . $batchId : ''));
