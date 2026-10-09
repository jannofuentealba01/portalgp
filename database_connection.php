<?php
declare(strict_types=1);

/** Pure connection configuration: no SQL, account changes or privilege grants. */
function pgpSqlServerIsLocal(string $server): bool
{
    $host = preg_replace('/^(?:tcp|np|lpc):/i', '', trim($server)) ?? '';
    $host = preg_replace('/,\d+$/D', '', $host) ?? '';
    $host = explode('\\', $host, 2)[0];
    return in_array(strtolower($host), ['localhost', '127.0.0.1', '[::1]', '::1', '.', '(local)', '(localdb)'], true);
}

function pgpSqlConnectionDsn(array $config): string
{
    $server = (string) ($config['server'] ?? 'localhost');
    $database = (string) ($config['database'] ?? 'PORTALGP');
    // Values come from external configuration, never from an HTTP parameter.
    // Reject injected DSN options rather than letting them override TLS/auth.
    foreach ([$server, $database] as $value) {
        if (trim($value) === '' || preg_match('/[;{}\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Configuración SQL inválida.');
        }
    }
    $server = trim($server);
    $database = trim($database);
    $encrypt = (bool) ($config['encrypt'] ?? true);
    $trust = (bool) ($config['trust_server_certificate'] ?? true);
    $environment = strtolower(trim((string) ($config['environment'] ?? 'local-development')));
    $production = in_array($environment, ['production', 'prod'], true);
    if (!$encrypt && ($production || !pgpSqlServerIsLocal($server))) {
        throw new RuntimeException('La conexión SQL remota debe estar cifrada.');
    }
    if ($production && $trust) {
        throw new RuntimeException('Producción requiere validar el certificado de SQL Server.');
    }
    // Credentials stay in PDO constructor arguments, never inside the DSN.
    // APP is a diagnostic tag, not an authentication/authorization boundary.
    return 'sqlsrv:Server=' . $server . ';Database=' . $database
        . ';Encrypt=' . ($encrypt ? '1' : '0')
        . ';TrustServerCertificate=' . ($trust ? '1' : '0')
        . ';APP=PortalGP;TraceOn=0';
}
