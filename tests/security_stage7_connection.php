<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/database_connection.php';
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    $checks++;
    echo 'OK: ' . $label . PHP_EOL;
};
$rejects = static function (array $config): bool {
    try { pgpSqlConnectionDsn($config); return false; }
    catch (InvalidArgumentException|RuntimeException) { return true; }
};
$remote = ['server' => '216.155.78.65', 'database' => 'PORTALGP', 'encrypt' => true,
    'trust_server_certificate' => true, 'environment' => 'local-development'];
$assert(str_contains(pgpSqlConnectionDsn($remote), ';Encrypt=1;TrustServerCertificate=1'), 'compatibilidad cifrada actual conservada, sin fingir validación de identidad');
$assert($rejects(array_replace($remote, ['encrypt' => false])), 'servidor remoto nunca puede desactivar cifrado');
$assert($rejects(array_replace($remote, ['server' => '216.155.78.65;Encrypt=0'])), 'servidor no puede inyectar opciones DSN');
$assert($rejects(array_replace($remote, ['database' => 'PORTALGP;TrustServerCertificate=1'])), 'base no puede inyectar opciones DSN');
foreach (["PORTALGP\n", "PORTAL\0GP", '{PORTALGP}'] as $name) {
    $assert($rejects(array_replace($remote, ['database' => $name])), 'valor DSN malformado rechazado');
}
$assert($rejects(array_replace($remote, ['environment' => 'production'])), 'producción no admite confiar en cualquier certificado');
$assert(str_contains(pgpSqlConnectionDsn(array_replace($remote, ['environment' => 'prod', 'trust_server_certificate' => false])), 'TrustServerCertificate=0'), 'producción admite validación estricta sin requerir un dominio AWS');
$assert(str_contains(pgpSqlConnectionDsn(array_replace($remote, ['server' => 'localhost', 'encrypt' => false])), 'Encrypt=0'), 'desarrollo local explícito permanece compatible');
foreach (['tcp:127.0.0.1,1433', 'localhost\\SQLEXPRESS', 'tcp:[::1],1433', '(localdb)\\MSSQLLocalDB'] as $server) {
    $assert(pgpSqlServerIsLocal($server), 'reconoce servidor local: ' . $server);
}
$assert(!pgpSqlServerIsLocal('localhost.ejemplo.invalid'), 'nombre remoto no puede hacerse pasar por localhost');
$dsn = pgpSqlConnectionDsn(array_replace($remote, ['username' => 'existing-user', 'password' => 'not-in-dsn']));
$assert(!str_contains($dsn, 'existing-user') && !str_contains($dsn, 'not-in-dsn'), 'credenciales fuera de DSN y diagnóstico');
$assert(str_contains($dsn, ';APP=PortalGP;TraceOn=0'), 'marca diagnóstica y trazas desactivadas');
$source = (string) file_get_contents(dirname(__DIR__) . '/database_connection.php');
$assert(!preg_match('/(?:CREATE|ALTER|DROP)\s+(?:USER|LOGIN|ROLE)|(?:GRANT|REVOKE)\s/i', $source), 'helper no crea identidades ni modifica privilegios SQL');
echo "PASS security_stage7_connection ({$checks} comprobaciones sin conexión SQL)." . PHP_EOL;
