<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/secret_paths.php';
$base = require $root . '/config/database.php';
$server = (string) ($base['server'] ?? 'localhost');
$database = (string) ($base['database'] ?? 'PORTALGP');
$login = 'portalgp_runtime';
$role = 'portalgp_runtime_role';
$password = 'Gp!' . rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=') . '9aA';

$encrypt = (bool) ($base['encrypt'] ?? true);
$trustCertificate = (bool) ($base['trust_server_certificate'] ?? true);
$dsn = 'sqlsrv:Server=' . $server . ';Database=' . $database
    . ';Encrypt=' . ($encrypt ? 'yes' : 'no')
    . ';TrustServerCertificate=' . ($trustCertificate ? 'yes' : 'no');
$admin = new PDO($dsn, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$quoteIdentifier = static fn(string $value): string => '[' . str_replace(']', ']]', $value) . ']';
$quoteLiteral = static fn(string $value): string => "N'" . str_replace("'", "''", $value) . "'";
$loginId = $quoteIdentifier($login);
$roleId = $quoteIdentifier($role);
$loginLiteral = $quoteLiteral($login);
$passwordSql = str_replace("'", "''", $password);
$createLoginSql = $quoteLiteral("CREATE LOGIN $loginId WITH PASSWORD = N'$passwordSql', CHECK_POLICY = ON, CHECK_EXPIRATION = OFF");
$alterLoginSql = $quoteLiteral("ALTER LOGIN $loginId WITH PASSWORD = N'$passwordSql', CHECK_POLICY = ON, CHECK_EXPIRATION = OFF");

$sql = "
IF SUSER_ID($loginLiteral) IS NULL
    EXEC($createLoginSql);
ELSE
    EXEC($alterLoginSql);
ALTER LOGIN $loginId ENABLE;

IF USER_ID($loginLiteral) IS NULL
    CREATE USER $loginId FOR LOGIN $loginId;

IF DATABASE_PRINCIPAL_ID(N'$role') IS NULL
    CREATE ROLE $roleId AUTHORIZATION dbo;

IF NOT EXISTS(
    SELECT 1 FROM sys.database_role_members drm
    WHERE drm.role_principal_id = DATABASE_PRINCIPAL_ID(N'$role')
      AND drm.member_principal_id = USER_ID($loginLiteral)
)
    ALTER ROLE $roleId ADD MEMBER $loginId;

GRANT CONNECT TO $roleId;
GRANT VIEW DEFINITION TO $roleId;
";
$admin->exec($sql);

$permissionPatch = $root . '/msp/db/patch_seguridad_runtime_objetos.sql';
if (!is_file($permissionPatch)) {
    throw new RuntimeException('No se encontró el parche de permisos mínimos por objeto.');
}
$admin->exec((string) file_get_contents($permissionPatch));

$runtime = new PDO($dsn, $login, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$identity = $runtime->query("SELECT SUSER_SNAME() login_name,IS_SRVROLEMEMBER('sysadmin') sysadmin,IS_MEMBER('db_owner') db_owner")->fetch();
if (($identity['login_name'] ?? '') !== $login || (int) ($identity['sysadmin'] ?? 0) !== 0 || (int) ($identity['db_owner'] ?? 0) !== 0) {
    throw new RuntimeException('La cuenta técnica no quedó aislada de los roles administrativos.');
}
$runtime->query('SELECT TOP(1) id FROM dbo.cr_usuarios')->fetchColumn();

$ddlWasDenied = false;
try {
    $runtime->exec('CREATE TABLE dbo.__portalgp_runtime_ddl_probe(id INT NULL)');
    $runtime->exec('DROP TABLE dbo.__portalgp_runtime_ddl_probe');
} catch (PDOException) {
    $ddlWasDenied = true;
}
if (!$ddlWasDenied) {
    throw new RuntimeException('La cuenta técnica todavía puede crear tablas; no se guardó la configuración.');
}

$localConfig = [
    'server' => $server,
    'database' => $database,
    'username' => $login,
    'password' => $password,
    'encrypt' => $encrypt,
    'trust_server_certificate' => $trustCertificate,
    'environment' => (string) ($base['environment'] ?? 'local-development'),
];
$configText = "<?php\ndeclare(strict_types=1);\n\n// Generado localmente; está excluido de Git. No compartir ni publicar.\nreturn " . var_export($localConfig, true) . ";\n";
$secretDirectory = pgpSecretsDirectory();
if (!is_dir($secretDirectory) && !mkdir($secretDirectory, 0700, true) && !is_dir($secretDirectory)) {
    throw new RuntimeException('No fue posible crear el almacén externo de secretos.');
}
$target = pgpSecretConfigPath('database.php');
$temporary = $target . '.tmp';
if (file_put_contents($temporary, $configText, LOCK_EX) === false || !rename($temporary, $target)) {
    @unlink($temporary);
    throw new RuntimeException('No fue posible guardar la configuración local de base de datos.');
}
@chmod($target, 0600);

echo "Cuenta técnica de ejecución configurada: $login\n";
echo "La contraseña quedó únicamente en el almacén externo de secretos.\n";
echo "Privilegios administrativos: no. Operación de aplicación: sí. DDL: bloqueado.\n";
