<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/db.php';
if (!in_array('--apply', $argv, true)) { exit("Uso: php scripts/security_stage2_migrate.php --apply\n"); }
if ((string)$conn->query('SELECT DB_NAME()')->fetchColumn() !== 'PORTALGP') {
    throw new RuntimeException('La base configurada no es PORTALGP.');
}
$before = $conn->query("SELECT id,rol_id,estado_id,password_hash,security_version FROM dbo.cr_usuarios WHERE UserName=N'admin_2'")->fetch();
if (!$before || (int)$before['estado_id'] !== 1) { throw new RuntimeException('admin_2 no está disponible; no se aplicó nada.'); }
$ids = $conn->prepare('SELECT permiso_id,lectura,escritura,eliminacion FROM dbo.cr_rol_permisos WHERE rol_id=:role ORDER BY permiso_id');
$ids->execute([':role'=>(int)$before['rol_id']]);$beforePermissions=$ids->fetchAll();
$path=dirname(__DIR__).'/msp/db/patch_seguridad_acceso_etapa2.sql';
$conn->beginTransaction();
try {
    foreach(preg_split('/^GO\s*$/mi',(string)file_get_contents($path)) as $batch){if(trim($batch)!=='')$conn->exec($batch);}
    $after=$conn->query("SELECT id,rol_id,estado_id,password_hash,security_version FROM dbo.cr_usuarios WHERE UserName=N'admin_2'")->fetch();
    $ids->execute([':role'=>(int)$before['rol_id']]);
    if($after!==$before||$ids->fetchAll()!==$beforePermissions)throw new RuntimeException('Cambió admin_2; se cancela la migración.');
    if((int)$conn->query("SELECT COUNT(*) FROM sys.tables WHERE object_id=OBJECT_ID(N'dbo.msp_schema_migrations')")->fetchColumn()===1){
        $reg=$conn->prepare("IF NOT EXISTS(SELECT 1 FROM dbo.msp_schema_migrations WHERE migration_file=:file)
            INSERT dbo.msp_schema_migrations(migration_file,sha256,applied_by,source) VALUES(:file2,:hash,SUSER_SNAME(),N'SECURITY_STAGE2')");
        $reg->execute([':file'=>basename($path),':file2'=>basename($path),':hash'=>hash_file('sha256',$path)]);
    }
    $conn->commit();
    echo json_encode(['ok'=>true,'admin_2_habilitado'=>true,'cuenta_password_y_permisos_sin_cambios'=>true,
        'permisos_conservados'=>count($beforePermissions)],JSON_PRETTY_PRINT),PHP_EOL;
} catch(Throwable $e){if($conn->inTransaction())$conn->rollBack();throw $e;}
