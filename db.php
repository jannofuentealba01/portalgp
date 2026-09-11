<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';

pgpApplySecurityHeaders();

// Production-safe defaults: detailed failures are logged, never rendered in HTTP.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
if (PHP_SAPI !== 'cli') {
    header_remove('X-Powered-By');
}

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
    $environment = strtolower(trim((string) ($dbConfig['environment'] ?? 'local-development')));
    if (in_array($environment, ['production', 'prod'], true) && !$encrypt) {
        throw new RuntimeException('La conexión SQL cifrada es obligatoria en producción.');
    }
    if (in_array($environment, ['production', 'prod'], true) && $trustServerCertificate) {
        throw new RuntimeException('Producción requiere validar el certificado de SQL Server.');
    }
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
    pgpLogException($exception, 'db.connection');
    http_response_code(503);
    exit('No fue posible conectar con la base de datos del ambiente configurado.');
}
