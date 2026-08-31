<?php
declare(strict_types=1);

$currentErrorLevel = error_reporting();
if ($currentErrorLevel !== 0) {
    error_reporting($currentErrorLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

try {
    $dbConfig = require __DIR__ . '/config/database.php';
    $serverName = (string) ($dbConfig['server'] ?? 'localhost');
    $database = (string) ($dbConfig['database'] ?? 'PORTALGP');
    $username = (string) ($dbConfig['username'] ?? '');
    $password = (string) ($dbConfig['password'] ?? '');
    $encrypt = !empty($dbConfig['encrypt']);
    $trustServerCertificate = !empty($dbConfig['trust_server_certificate']);
    $dsn = "sqlsrv:Server=$serverName;Database=$database";
    if ($encrypt) {
        $dsn .= ';Encrypt=1;TrustServerCertificate=' . ($trustServerCertificate ? '1' : '0');
    }

    $conn = $username === ''
        ? new PDO($dsn)
        : new PDO($dsn, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('[PortalGP][DB] ' . $exception->getMessage());
    http_response_code(503);
    exit('No fue posible conectar con la base de datos del ambiente configurado.');
}
