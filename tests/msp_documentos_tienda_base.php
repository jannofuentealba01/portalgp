<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/msp/bootstrap.php';
require_once dirname(__DIR__) . '/msp/documentos_tienda/helper.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '[OK] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach (['msp_documentos_tienda_lotes', 'msp_documentos_tienda_archivos', 'msp_documentos_tienda_eventos'] as $table) {
    $check(msp2TableExists($conn, $table), 'Existe dbo.' . $table);
}

$webRoot = strtolower(str_replace('\\', '/', dirname(__DIR__)));
$storageRoot = strtolower(str_replace('\\', '/', msp2DocumentosTiendaRoot()));
$check(!str_starts_with($storageRoot . '/', rtrim($webRoot, '/') . '/'), 'El almacenamiento queda fuera del webroot');
$check(count(msp2DocumentosTiendaFetchContracts($conn)) > 0, 'Existen contratos seleccionables para asociación');

$sourceChecks = [
    dirname(__DIR__) . '/msp/documentos_tienda/cargar.php' => ['%PDF-', 'is_uploaded_file', 'WHERE a.hash_sha256=:hash', "'MSP Documentos Tienda Carga', 'escritura'"],
    dirname(__DIR__) . '/msp/documentos_tienda/guardar_asociaciones.php' => ['asociacion_masiva', 'seleccionados', "'desasociar'"],
    dirname(__DIR__) . '/msp/documentos_tienda/cambiar_estado_lote.php' => ['LOTE_CERRADO', 'LOTE_REABIERTO', "'MSP Documentos Tienda Aprobacion', 'escritura'"],
    dirname(__DIR__) . '/msp/documentos_tienda/exportar_resumen.php' => ['pgpSpreadsheetSafeRow', 'text/csv'],
    dirname(__DIR__) . '/msp/documentos_tienda/simular_envio.php' => ['no abre conexión SMTP', 'msp2DocumentosTiendaFetchFiles'],
    dirname(__DIR__) . '/msp/documentos_tienda/enviar_demo.php' => ['confirmar_envio_demo', 'addAddress($demoTo)', "'MSP Documentos Tienda Aprobacion', 'escritura'"],
    dirname(__DIR__) . '/msp/documentos_tienda/ver.php' => ['X-Content-Type-Options: nosniff', 'msp2DocumentosTiendaRequireAny'],
];
foreach ($sourceChecks as $file => $needles) {
    $source = (string) file_get_contents($file);
    foreach ($needles as $needle) {
        $check(str_contains($source, $needle), basename($file) . ' contiene control ' . $needle);
    }
}

try {
    $conn->beginTransaction();
    $stmt = $conn->prepare(
        "INSERT INTO dbo.msp_documentos_tienda_lotes (nombre_lote,estado_lote,id_usuario_creador)
         OUTPUT INSERTED.id_lote_documentos_tienda VALUES (N'PRUEBA_ROLLBACK',N'BORRADOR',NULL)"
    );
    $stmt->execute();
    $batchId = (int) $stmt->fetchColumn();
    msp2DocumentosTiendaEvent($conn, $batchId, null, 'LOTE_CREADO', 'Prueba transaccional con rollback.');
    $insertFile = $conn->prepare(
        "INSERT INTO dbo.msp_documentos_tienda_archivos
            (id_lote_documentos_tienda,nombre_original,nombre_almacenado,ruta_relativa,mime_type,hash_sha256,bytes_archivo,estado_archivo)
         VALUES (:lote,:original,:almacenado,:ruta,N'application/pdf',:hash,100,N'PENDIENTE')"
    );
    for ($i = 1; $i <= 23; $i++) {
        $insertFile->execute([
            ':lote' => $batchId,
            ':original' => 'archivo_' . $i . '.pdf',
            ':almacenado' => 'archivo_' . $i . '.pdf',
            ':ruta' => 'prueba_' . $batchId . '/archivo_' . $i . '.pdf',
            ':hash' => hash('sha256', 'prueba-documento-' . $batchId . '-' . $i),
        ]);
    }
    $page = msp2DocumentosTiendaFetchFilesPage($conn, $batchId, '', 'PENDIENTE', 2, 10);
    $check((int) $page['total'] === 23 && count($page['rows']) === 10 && (int) $page['pages'] === 3, 'Paginación SQL de documentos');
    $searched = msp2DocumentosTiendaFetchFilesPage($conn, $batchId, 'archivo_17', '', 1, 20);
    $check((int) $searched['total'] === 1, 'Búsqueda parcial con comodines escapados');
    $check(msp2DocumentosTiendaRefreshBatch($conn, $batchId) === 'EN_REVISION', 'Estado de lote en revisión');
    $updateAll = $conn->prepare("UPDATE dbo.msp_documentos_tienda_archivos SET estado_archivo=N'ASOCIADO' WHERE id_lote_documentos_tienda=:lote");
    $updateAll->execute([':lote' => $batchId]);
    $check(msp2DocumentosTiendaRefreshBatch($conn, $batchId) === 'LISTO', 'Estado de lote listo con todos sus archivos asociados');
    for ($i = 1; $i <= 28; $i++) {
        msp2DocumentosTiendaEvent($conn, $batchId, null, 'ASOCIACION', 'Evento de prueba ' . $i);
    }
    $eventPage = msp2DocumentosTiendaFetchEventsPage($conn, $batchId, 2, 25);
    $check((int) $eventPage['total'] === 29 && count($eventPage['rows']) === 4, 'Historial completo paginado');
    $closeStmt = $conn->prepare(
        "UPDATE dbo.msp_documentos_tienda_lotes SET estado_antes_cierre=estado_lote,estado_lote=N'CERRADO',fecha_cierre=SYSDATETIME() WHERE id_lote_documentos_tienda=:lote"
    );
    $closeStmt->execute([':lote' => $batchId]);
    $closedBatch = msp2DocumentosTiendaFetchBatch($conn, $batchId);
    $blocked = false;
    try {
        msp2DocumentosTiendaAssertEditable(is_array($closedBatch) ? $closedBatch : []);
    } catch (RuntimeException) {
        $blocked = true;
    }
    $check($blocked && msp2DocumentosTiendaRefreshBatch($conn, $batchId) === 'CERRADO', 'El cierre bloquea cambios y no es sobrescrito por el recálculo');
    $reopenStmt = $conn->prepare(
        "UPDATE dbo.msp_documentos_tienda_lotes SET estado_lote=N'EN_REVISION',estado_antes_cierre=NULL,fecha_cierre=NULL,id_usuario_cierre=NULL WHERE id_lote_documentos_tienda=:lote"
    );
    $reopenStmt->execute([':lote' => $batchId]);
    $check(msp2DocumentosTiendaRefreshBatch($conn, $batchId) === 'LISTO', 'La reapertura recupera el estado calculado del lote');
    $conn->rollBack();
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $check(false, 'Prueba transaccional del lote: ' . $e->getMessage());
}

$hashIndex = $conn->query(
    "SELECT COUNT(*) FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.msp_documentos_tienda_archivos') AND name=N'IX_msp_dta_hash_global'"
)->fetchColumn();
$check((int) $hashIndex === 1, 'Índice global disponible para detectar duplicados entre lotes');

$adminPermissions = $conn->query(
    "SELECT COUNT(*)
     FROM dbo.cr_usuarios u
     INNER JOIN dbo.cr_rol_permisos rp ON rp.rol_id=u.rol_id
     INNER JOIN dbo.cr_permisos p ON p.id=rp.permiso_id
     WHERE u.UserName='admin_2'
       AND p.nombre_permiso IN ('MSP Documentos Tienda Carga','MSP Documentos Tienda Revision','MSP Documentos Tienda Aprobacion')
       AND rp.lectura=1 AND rp.escritura=1 AND rp.eliminacion=1"
)->fetchColumn();
$check((int) $adminPermissions === 3, 'admin_2 conserva acceso completo a carga, revisión y aprobación');

$simulationSource = (string) file_get_contents(dirname(__DIR__) . '/msp/documentos_tienda/simular_envio.php');
$check(!str_contains($simulationSource, 'mspMailBuildSmtp') && !str_contains($simulationSource, '->send()'), 'La simulación no contiene lógica de envío de correo');

if ($failures !== []) {
    exit(1);
}
echo 'RESULTADO: módulo base de documentos por tienda verificado sin enviar correos.' . PHP_EOL;
