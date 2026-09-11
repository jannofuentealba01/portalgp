<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/db.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo '[OK] ' . $message . PHP_EOL;
};
$mustFail = static function (callable $operation, string $message) use ($conn, $assert): void {
    $failed = false;
    try {
        $conn->beginTransaction();
        $operation();
        $conn->rollBack();
    } catch (Throwable) {
        $failed = true;
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
    }
    $assert($failed, $message);
};

foreach ([
    'msp_documento_preparar_regeneracion',
    'msp_cierre_mensual_eliminar_borrador',
    'msp_cierre_mensual_transicionar',
] as $procedure) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM sys.procedures WHERE name=:name");
    $stmt->execute([':name' => $procedure]);
    $assert((int) $stmt->fetchColumn() === 1, 'procedimiento instalado: ' . $procedure);
}

foreach ([
    'TR_msp_documentos_cobro_delete_guard',
    'TR_msp_cierre_mensual_delete_guard',
] as $trigger) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM sys.triggers WHERE name=:name AND is_disabled=0");
    $stmt->execute([':name' => $trigger]);
    $assert((int) $stmt->fetchColumn() === 1, 'protección de borrado activa: ' . $trigger);
}

$definition = (string) $conn->query(
    "SELECT OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_generar_documentos_cobro_periodo'))"
)->fetchColumn();
$assert(str_contains($definition, 'msp_documento_preparar_regeneracion'), 'la regeneración mensual usa el archivado controlado');

$deleteDefinition = (string) $conn->query(
    "SELECT OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_borrar_generacion_periodo'))"
)->fetchColumn();
$assert(str_contains($deleteDefinition, 'no se eliminan fisicamente'), 'los pagos históricos no se borran durante una corrección mensual');

$documentId = (int) $conn->query(
    'SELECT TOP(1) id_documento_cobro FROM dbo.msp_documentos_cobro ORDER BY id_documento_cobro DESC'
)->fetchColumn();
$mustFail(static function () use ($conn, $documentId): void {
    $stmt = $conn->prepare('DELETE FROM dbo.msp_documentos_cobro WHERE id_documento_cobro=:id');
    $stmt->execute([':id' => $documentId]);
}, 'SQL rechaza el borrado directo de documentos');

$closureId = (int) $conn->query(
    'SELECT TOP(1) id_cierre_mensual FROM dbo.msp_cierre_mensual ORDER BY id_cierre_mensual DESC'
)->fetchColumn();
$mustFail(static function () use ($conn, $closureId): void {
    $stmt = $conn->prepare('DELETE FROM dbo.msp_cierre_mensual WHERE id_cierre_mensual=:id');
    $stmt->execute([':id' => $closureId]);
}, 'SQL rechaza el borrado directo de cierres mensuales');

$paidDocumentId = (int) $conn->query(
    'SELECT TOP(1) p.id_documento_cobro
     FROM dbo.msp_pagos p
     INNER JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=p.id_documento_cobro
     ORDER BY p.id_pago DESC'
)->fetchColumn();
$mustFail(static function () use ($conn, $paidDocumentId): void {
    $stmt = $conn->prepare(
        "EXEC dbo.msp_documento_preparar_regeneracion
            @id_documento_cobro=:id,@motivo=N'Prueba revertida',@id_usuario=NULL"
    );
    $stmt->execute([':id' => $paidDocumentId]);
}, 'la regeneración rechaza documentos con cualquier historial de pago');

$eligibleDocument = $conn->query(
    "SELECT TOP(1) d.id_documento_cobro
     FROM dbo.msp_documentos_cobro d
     WHERE NOT EXISTS(SELECT 1 FROM dbo.msp_pagos p WHERE p.id_documento_cobro=d.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_saldo_favor_periodo_aplicaciones a WHERE a.id_documento_cobro=d.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_garantia_documento_aplicaciones a WHERE a.id_documento_cobro=d.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_movimientos_garantia a WHERE a.id_documento_cobro=d.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_cargos_salida a WHERE a.id_documento_cobro=d.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_cargos_contrato_local a WHERE a.id_documento_cobro=d.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_cargos_auto_generados a WHERE a.id_documento_cobro=d.id_documento_cobro OR a.id_documento_origen_deuda=d.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_envio_lote_documentos a WHERE a.id_documento_cobro=d.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_pago_contrato_operacion_detalle a WHERE a.id_documento_cobro=d.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_pago_contrato_archivos a WHERE a.id_documento_cobro=d.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_documentos_cobro_eventos a WHERE a.id_documento_cobro=d.id_documento_cobro AND UPPER(a.tipo_evento)<>N'EMISION')
     ORDER BY d.id_documento_cobro DESC"
)->fetch();
$assert(is_array($eligibleDocument), 'existe un documento sin movimientos para probar regeneración segura');
$eligibleId = (int) $eligibleDocument['id_documento_cobro'];

$conn->beginTransaction();
try {
    $stmt = $conn->prepare(
        "EXEC dbo.msp_documento_preparar_regeneracion
            @id_documento_cobro=:id,@motivo=N'Prueba transaccional revertida',@id_usuario=NULL"
    );
    $stmt->execute([':id' => $eligibleId]);
    $stmt->closeCursor();
    $assert((int) $conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro WHERE id_documento_cobro=$eligibleId")->fetchColumn() === 0, 'el documento anterior sale del conjunto operativo');
    $assert((int) $conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro_versiones WHERE id_documento_cobro_original=$eligibleId")->fetchColumn() === 1, 'se conserva una versión inmutable del documento anterior');
    $assert((int) $conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro_eventos WHERE id_documento_cobro_historico=$eligibleId AND id_documento_cobro IS NULL")->fetchColumn() >= 1, 'los eventos conservan el identificador histórico');
    $conn->rollBack();
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    throw $exception;
}
$assert((int) $conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro WHERE id_documento_cobro=$eligibleId")->fetchColumn() === 1, 'la prueba de regeneración no dejó cambios persistentes');

$draft = $conn->query(
    'SELECT TOP(1) id_cierre_mensual FROM dbo.msp_cierre_mensual WHERE estado_cierre=1 ORDER BY id_cierre_mensual DESC'
)->fetch();
$assert(is_array($draft), 'existe un período Borrador para probar la reapertura');
$draftId = (int) $draft['id_cierre_mensual'];
$conn->beginTransaction();
try {
    $stmt = $conn->prepare(
        'EXEC dbo.msp_cierre_mensual_transicionar
            @id_cierre_mensual=:id,@estado_esperado=1,@estado_destino=2,
            @motivo=N\'Prueba de cálculo revertida\',@id_usuario=NULL'
    );
    $stmt->execute([':id' => $draftId]);
    $stmt->closeCursor();
    $stmt = $conn->prepare(
        'EXEC dbo.msp_cierre_mensual_transicionar
            @id_cierre_mensual=:id,@estado_esperado=2,@estado_destino=1,
            @motivo=N\'Prueba de reapertura revertida\',@id_usuario=NULL'
    );
    $stmt->execute([':id' => $draftId]);
    $stmt->closeCursor();
    $snapshot = $conn->query(
        "SELECT TOP(1) dependencias_json FROM dbo.msp_cierre_mensual_transiciones
         WHERE id_cierre_mensual=$draftId AND es_reapertura=1 ORDER BY id_transicion DESC"
    )->fetchColumn();
    $assert(is_string($snapshot) && json_decode($snapshot, true) !== null, 'la reapertura registra una fotografía válida de dependencias');
    $conn->rollBack();
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    throw $exception;
}

$conn->beginTransaction();
try {
    $stmt = $conn->prepare(
        "INSERT dbo.msp_cierre_mensual(periodo_facturacion,fecha_valor_uf,valor_uf,estado_cierre,observaciones)
         OUTPUT INSERTED.id_cierre_mensual
         VALUES('2099-12-01','2099-11-30',50000,1,N'Prueba transaccional revertida')"
    );
    $stmt->execute();
    $temporaryClosureId = (int) $stmt->fetchColumn();
    $stmt->closeCursor();
    $delete = $conn->prepare(
        "EXEC dbo.msp_cierre_mensual_eliminar_borrador
            @id_cierre_mensual=:id,@motivo=N'Prueba transaccional revertida',@id_usuario=NULL"
    );
    $delete->execute([':id' => $temporaryClosureId]);
    $delete->closeCursor();
    $assert((int) $conn->query("SELECT COUNT(*) FROM dbo.msp_cierre_mensual WHERE id_cierre_mensual=$temporaryClosureId")->fetchColumn() === 0, 'el procedimiento elimina solo un borrador vacío');
    $assert((int) $conn->query("SELECT COUNT(*) FROM dbo.msp_cierre_mensual_eliminaciones WHERE id_cierre_mensual_original=$temporaryClosureId")->fetchColumn() === 1, 'la eliminación conserva su auditoría');
    $conn->rollBack();
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    throw $exception;
}

$admin = $conn->query(
    "SELECT estado_id,rol_id FROM dbo.cr_usuarios WHERE UserName=N'admin_2'"
)->fetch();
$assert(is_array($admin) && (int) $admin['estado_id'] === 1 && (int) $admin['rol_id'] === 1, 'admin_2 continúa activo como Administrador');

echo "OK: $checks comprobaciones de eliminación, regeneración y reapertura.\n";
