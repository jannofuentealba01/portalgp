<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/permission_service.php';
require_once dirname(__DIR__) . '/profile_service.php';
$canCreateDatabase = (int) $conn->query("SELECT HAS_PERMS_BY_NAME(NULL,NULL,'CREATE ANY DATABASE')")->fetchColumn();
if ($canCreateDatabase !== 1) {
    echo "SKIP: la prueba aislada de etapa 1 requiere una conexión administrativa capaz de crear una base temporal.\n";
    exit(0);
}
$testName = 'PORTALGP_SEC_STAGE1_' . bin2hex(random_bytes(6));
$created = false;
$checks = 0;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    $checks++;
    echo 'OK: ' . $name . PHP_EOL;
};
$rejects = static function (callable $fn): bool {
    try { $fn(); return false; } catch (InvalidArgumentException|RuntimeException $e) { return true; }
};
$migrate = static function (PDO $db): void {
    $sql = file_get_contents(dirname(__DIR__) . '/msp/db/patch_seguridad_acceso_etapa1.sql');
    foreach (preg_split('/^GO\s*$/mi', (string) $sql) as $batch) {
        if (trim($batch) !== '') { $db->exec($batch); }
    }
};
try {
    // A new, isolated database; never run mutation tests against PORTALGP.
    $conn->exec('CREATE DATABASE [' . $testName . ']');
    $created = true;
    $conn->exec('USE [' . $testName . ']');
    $conn->exec('CREATE TABLE dbo.cr_roles(id INT PRIMARY KEY,nombre_rol NVARCHAR(100));
        CREATE TABLE dbo.cr_permisos(id INT PRIMARY KEY,nombre_permiso NVARCHAR(150));
        CREATE TABLE dbo.cr_usuarios(id INT PRIMARY KEY,UserName NVARCHAR(100),correo_electronico NVARCHAR(200) NULL,estado_id INT,rol_id INT,
            password_hash VARCHAR(255));
        CREATE TABLE dbo.cr_rol_permisos(rol_id INT REFERENCES dbo.cr_roles(id),permiso_id INT REFERENCES dbo.cr_permisos(id),
            lectura BIT NULL,escritura BIT NULL,eliminacion BIT NULL,PRIMARY KEY(rol_id,permiso_id));
        INSERT dbo.cr_roles SELECT id,nombre_rol FROM PORTALGP.dbo.cr_roles;
        INSERT dbo.cr_permisos SELECT id,nombre_permiso FROM PORTALGP.dbo.cr_permisos;
        INSERT dbo.cr_rol_permisos SELECT rol_id,permiso_id,lectura,escritura,eliminacion FROM PORTALGP.dbo.cr_rol_permisos;
        INSERT dbo.cr_usuarios(id,UserName,correo_electronico,estado_id,rol_id,password_hash)
            SELECT id,UserName,correo_electronico,estado_id,rol_id,\'fictitious-test-hash\' FROM PORTALGP.dbo.cr_usuarios WHERE UserName=N\'admin_2\';
        INSERT dbo.cr_roles VALUES(99001,N\'Stage1 test role\');
        INSERT dbo.cr_usuarios VALUES(99001,N\'stage1_test_user\',N\'stage1@example.invalid\',1,99001,\'test\');');
    $before = (int) $conn->query('SELECT COUNT(*) FROM dbo.cr_rol_permisos')->fetchColumn();
    $migrate($conn);
    $assert((int) $conn->query('SELECT COUNT(*) FROM dbo.cr_rol_permisos WHERE lectura=1 AND escritura=1 AND eliminacion=1')->fetchColumn() === $before, 'migración conserva todas las asignaciones efectivas');
    $admin = (int) $conn->query("SELECT id FROM dbo.cr_usuarios WHERE UserName=N'admin_2'")->fetchColumn();
    $permission = (int) $conn->query("SELECT id FROM dbo.cr_permisos WHERE nombre_permiso=N'MSP Operacion'")->fetchColumn();
    $assert($admin > 0 && pgpCanManagePermissions($conn, $admin), 'admin_2 mantiene administración');
    pgpReplaceRolePermissions($conn, $admin, 99001, [$permission => ['lectura' => 1]]);
    $assert(pgpHasPermission($conn, 99001, 'MSP Operacion', 'lectura'), 'lector puede consultar');
    $assert(!pgpHasPermission($conn, 99001, 'MSP Operacion', 'escritura'), 'lector no puede escribir');
    $assert(!pgpHasPermission($conn, 99001, 'MSP Operacion', 'eliminacion'), 'lector no puede eliminar');
    $migrate($conn);
    $assert(!pgpHasPermission($conn, 99001, 'MSP Operacion', 'escritura'), 'migración repetida no vuelve a conceder derechos');
    $saved = pgpPermissionMap($conn, 99001);
    $assert($rejects(fn() => pgpReplaceRolePermissions($conn, $admin, 99001, [$permission => ['lectura' => 1], 999999 => ['lectura' => 1]])), 'rechaza ID inexistente antes de borrar');
    $assert(pgpPermissionMap($conn, 99001) === $saved, 'petición inválida conserva matriz');
    $assert($rejects(fn() => pgpReplaceRolePermissions($conn, 99001, 99001, [])), 'usuario no administrador no asigna permisos');
    $role = (int) pgpSecurityUser($conn, $admin)['rol_id'];
    $adminMap = pgpPermissionMap($conn, $role);
    $assert($rejects(fn() => pgpReplaceRolePermissions($conn, $admin, $role, [])), 'protección contra perder el acceso propio');
    $assert(pgpPermissionMap($conn, $role) === $adminMap, 'permisos de admin_2 intactos');
    // Force a SQL failure after DELETE to prove rollback, in the disposable DB only.
    $conn->exec("CREATE TRIGGER dbo.stage1_forced_insert_failure ON dbo.cr_rol_permisos AFTER INSERT AS
        BEGIN IF EXISTS(SELECT 1 FROM inserted WHERE rol_id=99001) THROW 54002,'forced test failure',1; END");
    $assert($rejects(fn() => pgpReplaceRolePermissions($conn, $admin, 99001, [$permission => ['lectura' => 1, 'escritura' => 1]])), 'fallo SQL intermedio detectado');
    $assert(pgpPermissionMap($conn, 99001) === $saved, 'rollback recupera permisos tras fallo de inserción');
    $conn->exec('DROP TRIGGER dbo.stage1_forced_insert_failure');
    pgpReplaceRolePermissions($conn, $admin, 99001, [$permission=>['lectura'=>1,'escritura'=>1]]);
    $assert(pgpHasPermission($conn,99001,'MSP Operacion','escritura') && !pgpHasPermission($conn,99001,'MSP Operacion','eliminacion'), 'editor escribe pero no elimina');
    pgpReplaceRolePermissions($conn, $admin, 99001, [$permission=>['lectura'=>1,'escritura'=>1,'eliminacion'=>1]]);
    $assert(pgpHasPermission($conn,99001,'MSP Operacion','eliminacion'), 'eliminación explícita permite eliminar');
    $assert($rejects(fn()=>pgpNormalizePermissionMap([$permission=>['eliminacion'=>1]])), 'no permite eliminar sin escritura y lectura');
    $assert($rejects(fn()=>pgpNormalizePermissionMap([$permission=>['lectura'=>['malformed']]])), 'matriz malformada rechazada');
    $_SESSION = ['usuario' => ['id' => 99001]];
    $assert(pgpValidateSession($conn), 'sesión existente habilitada aceptada');
    $oldSession = $_SESSION;
    $conn->exec('UPDATE dbo.cr_usuarios SET estado_id=2 WHERE id=99001');
    $assert(!pgpValidateSession($conn) && $_SESSION === [], 'deshabilitar invalida sesión');
    $conn->exec('UPDATE dbo.cr_usuarios SET estado_id=1 WHERE id=99001');
    $_SESSION = $oldSession;
    $assert(!pgpValidateSession($conn), 'rehabilitar no resucita sesión antigua');
    $_SESSION = ['usuario' => ['id' => 99001], 'pgp_security_version' => pgpSecurityUser($conn,99001)['security_version']];
    $assert(pgpValidateSession($conn), 'nueva autenticación habilitada funciona');
    $assert(pgpRequestPermissionAction(['REQUEST_METHOD'=>'POST','SCRIPT_NAME'=>'/msp/locales/guardar.php'], ['action'=>'lectura']) === 'escritura', 'POST no puede declararse lectura');
    $assert(pgpRequestPermissionAction(['REQUEST_METHOD'=>'POST','SCRIPT_NAME'=>'/msp/locales/eliminar.php'], ['action'=>'guardar']) === 'eliminacion', 'no se rebaja permiso de endpoint eliminar');
    $assert(pgpRequestPermissionAction(['REQUEST_METHOD'=>'POST','SCRIPT_NAME'=>'/roles.php'], ['action'=>'delete_role']) === 'eliminacion', 'eliminación en formulario mixto');
    $assert(pgpRequestPermissionAction(['REQUEST_METHOD'=>'POST','SCRIPT_NAME'=>'/catalogos/bancos.php'], ['accion'=>'  eliminar  ']) === 'eliminacion', 'espacios no eluden permiso de eliminación');
    $jsonPayload = ['nombre' => '</script><script>alert("x")</script>', 'amp' => 'A&B'];
    $jsonSeguro = pgpJsonForHtml($jsonPayload);
    $assert(!str_contains($jsonSeguro, '<') && !str_contains($jsonSeguro, '>') && !str_contains($jsonSeguro, '&'), 'JSON incrustado neutraliza cierre de script y entidades HTML');
    $assert(json_decode($jsonSeguro, true, 512, JSON_THROW_ON_ERROR) === $jsonPayload, 'JSON seguro conserva el valor original al decodificar');
    $assert(!pgpVerifyCsrf(null) && !pgpVerifyCsrf(['invalid']), 'CSRF ausente o malformado rechazado');
    $_SESSION['pgp_csrf'] = str_repeat('a',64);
    $assert(pgpVerifyCsrf(str_repeat('a',64)) && !pgpVerifyCsrf(str_repeat('b',64)), 'CSRF exacto validado');



$currentPassword = 'Stage1-Current-' . bin2hex(random_bytes(16));
$newPassword = 'Cedro-Lunar-' . bin2hex(random_bytes(16));
$otherPassword = 'Bosque-Seguro-' . bin2hex(random_bytes(16));
$wrongPassword = 'Stage1-Wrong-' . bin2hex(random_bytes(16));

$hash = password_hash($currentPassword, PASSWORD_BCRYPT);

$seed = $conn->prepare('UPDATE dbo.cr_usuarios SET password_hash=:hash WHERE id=99001');
$seed->execute([':hash' => $hash]);

$v = (int) pgpSecurityUser($conn, 99001)['security_version'];

$assert(
    $rejects(fn() => pgpUpdateOwnPassword($conn, 0, $v, [])),
    'perfil sin identidad rechazado'
);

$assert(
    $rejects(fn() => pgpUpdateOwnPassword(
        $conn,
        99001,
        $v,
        [
            'id' => (string) $admin,
            'password_actual' => $currentPassword,
            'nueva_password' => $otherPassword
        ]
    )),
    'ID de otro usuario rechazado'
);

$assert(
    $rejects(fn() => pgpUpdateOwnPassword(
        $conn,
        99001,
        $v,
        [
            'password_actual' => $wrongPassword,
            'nueva_password' => $otherPassword
        ]
    )),
    'contraseña actual incorrecta rechazada'
);

$assert(
    $rejects(fn() => pgpUpdateOwnPassword(
        $conn,
        99001,
        $v - 1,
        [
            'password_actual' => $currentPassword
        ]
    )),
    'sesión obsoleta no cambia contraseña'
);

$assert(
    pgpUpdateOwnPassword(
        $conn,
        99001,
        $v,
        [
            'password_actual' => $currentPassword,
            'nueva_password' => ''
        ]
    ) === $v,
    'guardar sin contraseña nueva no altera credenciales'
);

$newVersion = pgpUpdateOwnPassword(
    $conn,
    99001,
    $v,
    [
        'id' => '99001',
        'password_actual' => $currentPassword,
        'nueva_password' => $newPassword,
        'nombre_completo' => 'ignored'
    ]
);

$assert(
    $newVersion === $v + 1,
    'cambio propio incrementa versión para revocar otras sesiones'
);

$assert(
    password_verify(
        $newPassword,
        (string) $conn->query(
            'SELECT password_hash FROM dbo.cr_usuarios WHERE id=99001'
        )->fetchColumn()
    ),
    'se guarda la contraseña nueva del usuario correcto'
);

$_SESSION = [
    'usuario' => ['id' => 99001],
    'pgp_security_version' => $newVersion
];

$assert(
    pgpValidateSession($conn),
    'sesión propia puede continuar tras cambio de contraseña'
);

$conn->exec('UPDATE dbo.cr_usuarios SET estado_id=2 WHERE id=99001');

$assert(
    $rejects(fn() => pgpUpdateOwnPassword(
        $conn,
        99001,
        $newVersion,
        [
            'password_actual' => $newPassword
        ]
    )),
    'perfil de cuenta deshabilitada bloqueado'
);





    $guard = static function(string $mode,int $status) use ($testName,$assert):void {
        $pipes=[];
        $proc=proc_open([PHP_BINARY,__DIR__.'/security_stage1_guard_worker.php',$testName,$mode],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        if(!is_resource($proc))throw new RuntimeException('No se pudo iniciar el worker de prueba.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
        $result=json_decode($out,true);
        $assert($exit===0 && $err==='' && is_array($result) && $result['status']===$status && !$result['php_error'], 'receptor real '.$mode.' HTTP '.$status);
    };
    $guard('profile_anonymous',401);
    $guard('profile_no_csrf',403);
    $guard('roles_no_csrf',403);
    $guard('users_no_csrf',403);
    $guard('permissions_no_csrf',403);
    $guard('departments_no_csrf',403);
    $guard('disabled_user',401);
    $conn->exec('UPDATE dbo.cr_usuarios SET estado_id=1 WHERE id=99001');
    pgpReplaceRolePermissions($conn,$admin,99001,[$permission=>['lectura'=>1]]);
    $guard('msp_readonly_write',403);
    pgpReplaceRolePermissions($conn,$admin,99001,[$permission=>['lectura'=>1,'escritura'=>1]]);
    $guard('msp_editor_delete',403);
    echo 'RESULTADO: ' . $checks . ' comprobaciones correctas.' . PHP_EOL;
} finally {
    if ($created && preg_match('/^PORTALGP_SEC_STAGE1_[a-f0-9]{12}$/D', $testName)) {
        $conn->exec('USE master');
        $conn->exec('DROP DATABASE [' . $testName . ']');
        echo 'Base temporal de pruebas eliminada: ' . $testName . PHP_EOL;
    }
}
