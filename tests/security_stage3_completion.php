<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/auth_helper.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo 'OK: ' . $message . PHP_EOL;
};

$root = dirname(__DIR__);
$rootReal = strtolower((string) realpath($root));
$secretReal = strtolower((string) realpath(pgpSecretsDirectory()));
$assert($secretReal !== '' && !str_starts_with($secretReal . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR), 'el almacén de secretos está fuera del webroot');
foreach (['database.php', 'msp_mail.php', 'ct_mail.php', 'entra.php'] as $secretFile) {
    $assert(is_file(pgpSecretConfigPath($secretFile)), 'existe el secreto externo ' . $secretFile);
}
foreach (['config/database.local.php', 'msp/config/mail.php', 'ct/config/mail.php', 'microsoft_auth_config.local.php'] as $oldPath) {
    $assert(!is_file($root . '/' . $oldPath), 'no existe la antigua copia pública ' . $oldPath);
}

$databaseConfig = require $root . '/config/database.php';
$assert(!empty($databaseConfig['encrypt']), 'la conexión SQL solicita cifrado');
$databaseSource = (string) file_get_contents($root . '/db.php');
$assert(str_contains($databaseSource, "['production', 'prod']") && str_contains($databaseSource, 'requiere validar el certificado'), 'producción falla si no cifra o no valida el certificado SQL');

$broadPermissions = (int) $conn->query(
    "SELECT COUNT(*)
     FROM sys.database_permissions p
     JOIN sys.database_principals d ON d.principal_id=p.grantee_principal_id
     WHERE d.name=N'portalgp_runtime_role' AND p.class=3
       AND p.permission_name IN ('SELECT','INSERT','UPDATE','DELETE','EXECUTE')
       AND p.state IN ('G','W')"
)->fetchColumn();
$objectPermissions = (int) $conn->query(
    "SELECT COUNT(*)
     FROM sys.database_permissions p
     JOIN sys.database_principals d ON d.principal_id=p.grantee_principal_id
     WHERE d.name=N'portalgp_runtime_role' AND p.class=1 AND p.state IN ('G','W')"
)->fetchColumn();
$assert($broadPermissions === 0, 'runtime no tiene permisos DML/EXECUTE sobre todo dbo');
$assert($objectPermissions > 0, 'runtime usa concesiones específicas por objeto');
$identity = $conn->query("SELECT SUSER_SNAME() login_name,IS_SRVROLEMEMBER('sysadmin') sysadmin,IS_MEMBER('db_owner') db_owner")->fetch();
$assert(($identity['login_name'] ?? '') === 'portalgp_runtime', 'la aplicación usa portalgp_runtime');
$assert((int) ($identity['sysadmin'] ?? 1) === 0 && (int) ($identity['db_owner'] ?? 1) === 0, 'runtime no es sysadmin ni db_owner');

$admin = $conn->query(
    "SELECT TOP(1) u.id,u.UserName,u.estado_id,u.security_version,r.nombre_rol,
        (SELECT COUNT(DISTINCT rp.permiso_id) FROM dbo.cr_rol_permisos rp WHERE rp.rol_id=u.rol_id) permisos
     FROM dbo.cr_usuarios u JOIN dbo.cr_roles r ON r.id=u.rol_id
     WHERE u.UserName=N'admin_2'"
)->fetch();
$assert(is_array($admin) && (int) $admin['id'] === 1030, 'admin_2 conserva su registro');
$assert((int) $admin['estado_id'] === 1 && (string) $admin['nombre_rol'] === 'Administrador', 'admin_2 sigue habilitado y Administrador');
$assert((int) $admin['security_version'] === 0 && (int) $admin['permisos'] >= 22, 'admin_2 conserva versión de seguridad y no pierde permisos');

$bootstrap = (string) file_get_contents($root . '/msp/bootstrap.php');
$assert(!str_contains($bootstrap, "msp2CurrentUserHasPermission('MSP Arriendos'"), 'MSP ya no autoriza mediante el permiso heredado');
foreach (['MSP Operacion', 'MSP Cobranza', 'MSP Tesoreria', 'MSP Cierre Mensual', 'MSP Reportes', 'MSP Configuracion'] as $permission) {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.cr_permisos WHERE nombre_permiso=:permission');
    $stmt->execute([':permission' => $permission]);
    $assert((int) $stmt->fetchColumn() === 1, 'existe el permiso funcional ' . $permission);
}

$authSource = (string) file_get_contents($root . '/auth_helper.php');
$callbackSource = (string) file_get_contents($root . '/microsoft_callback.php');
$assert(str_contains($authSource, 'JWT::decode') && str_contains($authSource, 'JWK::parseKeySet'), 'Entra verifica la firma con JWT/JWK');
$assert(str_contains($authSource, "hash_equals('RS256'") && str_contains($authSource, "['nonce']"), 'Entra restringe algoritmo y valida nonce');
$assert(!str_contains($authSource, 'pgpDecodeMicrosoftIdToken') && !str_contains($callbackSource, 'pgpMicrosoftProfileFromIdToken'), 'no queda decodificación de identidad sin verificar');
$assert(str_contains($callbackSource, 'pgpValidateMicrosoftIdToken'), 'el callback exige el token validado antes de iniciar sesión');

$validClaims = [
    'iss' => 'https://login.microsoftonline.com/tenant-test/v2.0',
    'tid' => 'tenant-test',
    'aud' => 'client-test',
    'exp' => time() + 300,
    'nonce' => 'nonce-test',
    'oid' => 'user-test',
];
pgpValidateMicrosoftClaims($validClaims, ['tenant_id' => 'tenant-test', 'client_id' => 'client-test'], 'nonce-test');
$assert(true, 'las declaraciones Entra válidas son aceptadas');
$rejectedNonce = false;
try {
    pgpValidateMicrosoftClaims($validClaims, ['tenant_id' => 'tenant-test', 'client_id' => 'client-test'], 'otro-nonce');
} catch (RuntimeException) {
    $rejectedNonce = true;
}
$assert($rejectedNonce, 'un nonce Entra distinto es rechazado');
$rejectedUnsigned = false;
try {
    pgpValidateMicrosoftIdToken('e30.e30.firma', ['tenant_id' => 'tenant-test', 'client_id' => 'client-test'], 'nonce-test');
} catch (Throwable) {
    $rejectedUnsigned = true;
}
$assert($rejectedUnsigned, 'un token sin firma RS256 válida es rechazado');

$policy = require $root . '/config/data_protection.php';
$assert(($policy['automatic_deletion_enabled'] ?? true) === false, 'no se elimina información automáticamente sin aprobación');
$assert(($policy['legal_hold_overrides_deletion'] ?? false) === true, 'la retención legal prevalece');
foreach (['authentication', 'identity_contact', 'financial_contractual', 'uploaded_files', 'technical_logs'] as $category) {
    $assert(isset($policy['categories'][$category]), 'la política clasifica ' . $category);
}
foreach (['database_transport_encryption', 'validate_sql_server_certificate', 'database_or_volume_encryption_at_rest', 'encrypted_backups', 'external_secret_store'] as $requirement) {
    $assert(($policy['production_requirements'][$requirement] ?? false) === true, 'producción exige ' . $requirement);
}

$provisioner = (string) file_get_contents($root . '/scripts/provision_runtime_database.php');
$assert(!str_contains($provisioner, 'ON SCHEMA::dbo') && str_contains($provisioner, 'patch_seguridad_runtime_objetos.sql'), 'el aprovisionador no puede restaurar permisos amplios');
$assert(str_contains($provisioner, "pgpSecretConfigPath('database.php')") && !str_contains($provisioner, 'database.local.php'), 'el aprovisionador escribe fuera del webroot');

echo "PASS: {$checks}/{$checks} comprobaciones de cierre de etapa 3.\n";
