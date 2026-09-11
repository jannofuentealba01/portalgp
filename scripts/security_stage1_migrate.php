<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/security.php';
if (!in_array('--apply', $argv, true)) { exit("Uso: php scripts/security_stage1_migrate.php --apply\n"); }
if ((string)$conn->query('SELECT DB_NAME()')->fetchColumn() !== 'PORTALGP') {
    throw new RuntimeException('La base configurada no es PORTALGP.');
}
$stmt = $conn->query("SELECT id,rol_id,estado_id,password_hash FROM dbo.cr_usuarios WHERE UserName=N'admin_2'");
$before = $stmt->fetch();
if (!$before || (int)$before['estado_id'] !== 1) { throw new RuntimeException('admin_2 no está disponible; no se aplicó nada.'); }
$roleId = (int)$before['rol_id'];
$ids = $conn->prepare('SELECT permiso_id FROM dbo.cr_rol_permisos WHERE rol_id=:role ORDER BY permiso_id');
$ids->execute([':role'=>$roleId]);
$beforeIds = $ids->fetchAll(PDO::FETCH_COLUMN);
$path = dirname(__DIR__) . '/msp/db/patch_seguridad_acceso_etapa1.sql';
$conn->beginTransaction();
try {
    foreach (preg_split('/^GO\s*$/mi', (string)file_get_contents($path)) as $batch) {
        if (trim($batch) !== '') { $conn->exec($batch); }
    }
    $after = $conn->query("SELECT id,rol_id,estado_id,password_hash FROM dbo.cr_usuarios WHERE UserName=N'admin_2'")->fetch();
    $ids->execute([':role'=>$roleId]);
    if ($after !== $before || $ids->fetchAll(PDO::FETCH_COLUMN) !== $beforeIds) {
        throw new RuntimeException('Cambió la cuenta o sus asignaciones; se cancela la migración.');
    }
    $count = $conn->prepare('SELECT COUNT(*) FROM dbo.cr_rol_permisos WHERE rol_id=:role AND lectura=1 AND escritura=1 AND eliminacion=1');
    $count->execute([':role'=>$roleId]);
    if ((int)$count->fetchColumn() !== count($beforeIds)) { throw new RuntimeException('No se conservó el acceso completo existente.'); }
    if ((int)$conn->query("SELECT COUNT(*) FROM sys.tables WHERE object_id=OBJECT_ID(N'dbo.msp_schema_migrations')")->fetchColumn() === 1) {
        $reg = $conn->prepare("IF NOT EXISTS(SELECT 1 FROM dbo.msp_schema_migrations WHERE migration_file=:file)
            INSERT dbo.msp_schema_migrations(migration_file,sha256,applied_by,source)
            VALUES(:file2,:hash,SUSER_SNAME(),N'SECURITY_STAGE1');");
        $reg->execute([':file'=>basename($path),':file2'=>basename($path),':hash'=>hash_file('sha256',$path)]);
    }
    $conn->commit();
    echo json_encode(['ok'=>true,'admin_2_habilitado'=>true,'cuenta_y_password_sin_cambios'=>true,
        'permisos_conservados'=>count($beforeIds),'respaldo'=>'dbo.cr_security_permisos_backup_etapa1'], JSON_PRETTY_PRINT), PHP_EOL;
} catch (Throwable $e) {
    if ($conn->inTransaction()) { $conn->rollBack(); }
    throw $e;
}
