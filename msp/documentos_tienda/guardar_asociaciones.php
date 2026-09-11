<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/helper.php';

msp2RequireAccess('MSP Documentos Tienda Revision', 'escritura');

$batchId = filter_var($_POST['id_lote'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$associations = is_array($_POST['asociaciones'] ?? null) ? $_POST['asociaciones'] : [];
$notes = is_array($_POST['observaciones'] ?? null) ? $_POST['observaciones'] : [];
$mode = trim((string) ($_POST['modo'] ?? 'individual'));
$returnParams = [
    'id_lote' => $batchId,
    'q' => mb_substr(msp2NormalizeText((string) ($_POST['return_q'] ?? '')), 0, 120, 'UTF-8'),
    'estado' => trim((string) ($_POST['return_estado'] ?? '')),
    'pagina' => max(1, (int) ($_POST['return_pagina'] ?? 1)),
    'lineas' => in_array((int) ($_POST['return_lineas'] ?? 20), [10, 20, 50], true)
        ? (int) $_POST['return_lineas']
        : 20,
];

if ($mode === 'masivo') {
    $bulkValue = trim((string) ($_POST['asociacion_masiva'] ?? ''));
    $selected = is_array($_POST['seleccionados'] ?? null) ? array_slice($_POST['seleccionados'], 0, 200) : [];
    $associations = [];
    foreach ($selected as $selectedId) {
        $fileId = filter_var($selectedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        if ($fileId > 0) {
            $associations[$fileId] = $bulkValue;
        }
    }
}

try {
    $batch = $batchId > 0 ? msp2DocumentosTiendaFetchBatch($conn, $batchId) : null;
    if ($batch === null) {
        throw new RuntimeException('El lote no existe.');
    }
    msp2DocumentosTiendaAssertEditable($batch);
    if ($associations === [] || ($mode === 'masivo' && trim((string) reset($associations)) === '')) {
        throw new RuntimeException($mode === 'masivo'
            ? 'Selecciona documentos y una acción masiva.'
            : 'No hay asociaciones para guardar.');
    }

    $conn->beginTransaction();
    $contractMap = [];
    foreach (msp2DocumentosTiendaFetchContracts($conn) as $contractRow) {
        $contractMap[(int) $contractRow['id_contrato_arriendo']] = $contractRow;
    }
    $findFile = $conn->prepare(
        'SELECT id_documento_tienda_archivo,estado_archivo,id_tienda,id_contrato_arriendo,id_arrendatario,
                correo_destino_snapshot,observaciones
         FROM dbo.msp_documentos_tienda_archivos WITH (UPDLOCK,ROWLOCK)
         WHERE id_documento_tienda_archivo=:archivo AND id_lote_documentos_tienda=:lote'
    );
    $update = $conn->prepare(
        'UPDATE dbo.msp_documentos_tienda_archivos
         SET id_tienda=:tienda,id_contrato_arriendo=:contrato,id_arrendatario=:arrendatario,
             correo_destino_snapshot=:correo,estado_archivo=:estado,observaciones=:observaciones,
             ultimo_error=NULL,updated_at=SYSDATETIME()
         WHERE id_documento_tienda_archivo=:archivo AND id_lote_documentos_tienda=:lote'
    );
    $updateNote = $conn->prepare(
        'UPDATE dbo.msp_documentos_tienda_archivos SET observaciones=:observaciones,updated_at=SYSDATETIME()
         WHERE id_documento_tienda_archivo=:archivo AND id_lote_documentos_tienda=:lote'
    );
    $changed = 0;
    foreach ($associations as $fileIdRaw => $valueRaw) {
        $fileId = filter_var($fileIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $value = trim((string) $valueRaw);
        if ($fileId <= 0) {
            continue;
        }
        $findFile->execute([':archivo' => $fileId, ':lote' => $batchId]);
        $file = $findFile->fetch();
        if (!is_array($file) || (string) ($file['estado_archivo'] ?? '') === 'ENVIADO') {
            continue;
        }
        $hasPostedNote = array_key_exists($fileId, $notes) || array_key_exists((string) $fileId, $notes);
        $observation = $hasPostedNote
            ? mb_substr(msp2NormalizeText((string) ($notes[$fileId] ?? $notes[(string) $fileId] ?? '')), 0, 1000, 'UTF-8')
            : (string) ($file['observaciones'] ?? '');
        $observationDb = $observation !== '' ? $observation : null;
        if ($value === '') {
            if ($hasPostedNote && $observation !== (string) ($file['observaciones'] ?? '')) {
                $updateNote->execute([':observaciones' => $observationDb, ':archivo' => $fileId, ':lote' => $batchId]);
                msp2DocumentosTiendaEvent($conn, $batchId, $fileId, 'ASOCIACION', 'Observación de revisión actualizada.');
                $changed++;
            }
            continue;
        }
        if ($value === 'desasociar') {
            if ((string) ($file['estado_archivo'] ?? '') === 'PENDIENTE'
                && (int) ($file['id_contrato_arriendo'] ?? 0) === 0
                && $observation === (string) ($file['observaciones'] ?? '')) {
                continue;
            }
            $update->execute([
                ':tienda' => null, ':contrato' => null, ':arrendatario' => null, ':correo' => null,
                ':estado' => 'PENDIENTE', ':observaciones' => $observationDb,
                ':archivo' => $fileId, ':lote' => $batchId,
            ]);
            msp2DocumentosTiendaEvent($conn, $batchId, $fileId, 'ASOCIACION', 'Asociación eliminada; documento devuelto a pendiente.');
            $changed++;
            continue;
        }
        if ($value === 'omitir') {
            if ((string) ($file['estado_archivo'] ?? '') === 'OMITIDO'
                && $observation === (string) ($file['observaciones'] ?? '')) {
                continue;
            }
            $update->execute([
                ':tienda' => null, ':contrato' => null, ':arrendatario' => null, ':correo' => null,
                ':estado' => 'OMITIDO', ':observaciones' => $observationDb,
                ':archivo' => $fileId, ':lote' => $batchId,
            ]);
            msp2DocumentosTiendaEvent($conn, $batchId, $fileId, 'OMISION', 'Documento marcado para omitir.');
            $changed++;
            continue;
        }
        $contractId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $contract = $contractId > 0 ? ($contractMap[$contractId] ?? null) : null;
        if (!is_array($contract)) {
            throw new RuntimeException('Una de las asociaciones seleccionadas ya no está disponible.');
        }
        $email = trim((string) ($contract['correo'] ?? ''));
        $emailDb = filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
        if ((string) ($file['estado_archivo'] ?? '') === 'ASOCIADO'
            && (int) ($file['id_tienda'] ?? 0) === (int) $contract['id_tienda']
            && (int) ($file['id_contrato_arriendo'] ?? 0) === (int) $contract['id_contrato_arriendo']
            && (int) ($file['id_arrendatario'] ?? 0) === (int) $contract['id_arrendatario']
            && (string) ($file['correo_destino_snapshot'] ?? '') === (string) ($emailDb ?? '')
            && $observation === (string) ($file['observaciones'] ?? '')) {
            continue;
        }
        $update->execute([
            ':tienda' => (int) $contract['id_tienda'],
            ':contrato' => (int) $contract['id_contrato_arriendo'],
            ':arrendatario' => (int) $contract['id_arrendatario'],
            ':correo' => $emailDb,
            ':estado' => 'ASOCIADO',
            ':observaciones' => $observationDb,
            ':archivo' => $fileId,
            ':lote' => $batchId,
        ]);
        msp2DocumentosTiendaEvent(
            $conn,
            $batchId,
            $fileId,
            'ASOCIACION',
            'Asociado al contrato #' . (int) $contract['id_contrato_arriendo'] . ' y tienda ' . (string) $contract['nombre_comercial'] . '.'
        );
        $changed++;
    }
    msp2DocumentosTiendaRefreshBatch($conn, $batchId);
    $conn->commit();
    msp2SetFlash('success', $mode === 'masivo'
        ? 'La acción masiva se aplicó a ' . $changed . ' documento(s).'
        : 'Se guardaron ' . $changed . ' cambio(s).');
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    pgpLogException($e, 'msp.documentos_tienda.asociar');
    msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible guardar las asociaciones.');
}

$returnParams['id_lote'] = max(0, $batchId);
if ($returnParams['q'] === '') {
    unset($returnParams['q']);
}
if (!in_array($returnParams['estado'], ['PENDIENTE', 'ASOCIADO', 'ENVIADO', 'ERROR', 'OMITIDO'], true)) {
    unset($returnParams['estado']);
}
msp2Redirect('documentos_tienda/index.php?' . http_build_query($returnParams));
