<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/database_connection.php';

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
    $username = (string) ($dbConfig['username'] ?? '');
    $password = (string) ($dbConfig['password'] ?? '');
    $dsn = pgpSqlConnectionDsn($dbConfig);

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
