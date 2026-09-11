<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/helper.php';

msp2RequireAccess('MSP Documentos Tienda Aprobacion', 'escritura');

$batchId = filter_var($_POST['id_lote'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$action = trim((string) ($_POST['accion_lote'] ?? ''));
$reason = mb_substr(msp2NormalizeText((string) ($_POST['motivo'] ?? '')), 0, 500, 'UTF-8');

try {
    if ($batchId <= 0 || !in_array($action, ['cerrar', 'reabrir'], true)) {
        throw new RuntimeException('La acción solicitada no es válida.');
    }
    if (mb_strlen($reason, 'UTF-8') < 5) {
        throw new RuntimeException('Indica un motivo de al menos 5 caracteres.');
    }

    $conn->beginTransaction();
    $batchStmt = $conn->prepare(
        'SELECT * FROM dbo.msp_documentos_tienda_lotes WITH (UPDLOCK,HOLDLOCK)
         WHERE id_lote_documentos_tienda=:lote'
    );
    $batchStmt->execute([':lote' => $batchId]);
    $batch = $batchStmt->fetch();
    if (!is_array($batch)) {
        throw new RuntimeException('El lote no existe.');
    }

    if ($action === 'cerrar') {
        if ((string) $batch['estado_lote'] === 'CERRADO') {
            throw new RuntimeException('El lote ya está cerrado.');
        }
        $countStmt = $conn->prepare(
            "SELECT COUNT(*) total,
                    SUM(CASE WHEN estado_archivo=N'PENDIENTE' THEN 1 ELSE 0 END) pendientes,
                    SUM(CASE WHEN estado_archivo=N'ERROR' THEN 1 ELSE 0 END) errores
             FROM dbo.msp_documentos_tienda_archivos WITH (HOLDLOCK)
             WHERE id_lote_documentos_tienda=:lote"
        );
        $countStmt->execute([':lote' => $batchId]);
        $counts = $countStmt->fetch() ?: [];
        if ((int) ($counts['total'] ?? 0) === 0) {
            throw new RuntimeException('No se puede cerrar un lote vacío.');
        }
        if ((int) ($counts['pendientes'] ?? 0) > 0 || (int) ($counts['errores'] ?? 0) > 0) {
            throw new RuntimeException('Antes de cerrar, todos los documentos deben estar asociados, enviados u omitidos y no puede haber errores.');
        }
        $update = $conn->prepare(
            "UPDATE dbo.msp_documentos_tienda_lotes
             SET estado_antes_cierre=estado_lote,estado_lote=N'CERRADO',fecha_cierre=SYSDATETIME(),
                 id_usuario_cierre=:usuario,updated_at=SYSDATETIME()
             WHERE id_lote_documentos_tienda=:lote"
        );
        $update->execute([':usuario' => msp2DocumentosTiendaUserId(), ':lote' => $batchId]);
        msp2DocumentosTiendaEvent($conn, $batchId, null, 'LOTE_CERRADO', 'Lote cerrado. Motivo: ' . $reason);
        $message = 'El lote #' . $batchId . ' quedó cerrado y bloqueado para modificaciones.';
    } else {
        if ((string) $batch['estado_lote'] !== 'CERRADO') {
            throw new RuntimeException('Solo se puede reabrir un lote cerrado.');
        }
        $update = $conn->prepare(
            "UPDATE dbo.msp_documentos_tienda_lotes
             SET estado_lote=N'EN_REVISION',estado_antes_cierre=NULL,fecha_cierre=NULL,id_usuario_cierre=NULL,updated_at=SYSDATETIME()
             WHERE id_lote_documentos_tienda=:lote"
        );
        $update->execute([':lote' => $batchId]);
        $restoredState = msp2DocumentosTiendaRefreshBatch($conn, $batchId);
        msp2DocumentosTiendaEvent($conn, $batchId, null, 'LOTE_REABIERTO', 'Lote reabierto en estado ' . $restoredState . '. Motivo: ' . $reason);
        $message = 'El lote #' . $batchId . ' fue reabierto y volvió al estado ' . str_replace('_', ' ', $restoredState) . '.';
    }

    $conn->commit();
    msp2SetFlash('success', $message);
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    pgpLogException($e, 'msp.documentos_tienda.estado_lote');
    msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible cambiar el estado del lote.');
}

msp2Redirect('documentos_tienda/index.php?id_lote=' . max(0, $batchId));
