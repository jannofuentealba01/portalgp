<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/login_security.php';

$canCreateDatabase = (int) $conn->query("SELECT HAS_PERMS_BY_NAME(NULL,NULL,'CREATE ANY DATABASE')")->fetchColumn();
if ($canCreateDatabase !== 1) {
    echo "SKIP: la prueba aislada de etapa 2 requiere una conexión administrativa capaz de crear una base temporal.\n";
    exit(0);
}

$testName = 'PORTALGP_SEC_STAGE2_' . bin2hex(random_bytes(6));
$created = false;
$checks = 0;
$assert = static function (bool $ok, string $name) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    $checks++;
    echo 'OK: ' . $name . PHP_EOL;
};

try {
    $conn->exec('CREATE DATABASE [' . $testName . ']');
    $created = true;
    $conn->exec('USE [' . $testName . ']');
    $sql = (string) file_get_contents(dirname(__DIR__) . '/msp/db/patch_seguridad_acceso_etapa2.sql');
    foreach (preg_split('/^GO\s*$/mi', $sql) as $batch) {
        if (trim($batch) !== '') { $conn->exec($batch); }
    }

    $server = ['REMOTE_ADDR' => '192.0.2.44'];
    for ($i = 1; $i <= PGP_LOGIN_THRESHOLD - 1; $i++) {
        $wait = pgpRecordLoginFailure($conn, 'stage2_test_user', $server);
    }
    $assert($wait === 0, 'siete fallos no producen bloqueo permanente ni anticipado');
    $wait = pgpRecordLoginFailure($conn, 'stage2_test_user', $server);
    $assert($wait > 0 && $wait <= 35, 'el octavo fallo aplica una espera temporal aproximada de 30 segundos');
    $assert(pgpLoginRetryAfter($conn, 'stage2_test_user', $server) > 0, 'el límite se consulta antes de autenticar');
    $assert(pgpLoginRetryAfter($conn, 'other_user', $server) > 0, 'el origen limitado protege también frente a usuarios rotativos');

    $rows = $conn->query('SELECT clave_hash,tipo_clave FROM dbo.cr_login_intentos')->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($rows) === 2, 'se registran exclusivamente las dos claves de control esperadas');
    $assert(array_reduce($rows, static fn(bool $ok, array $row): bool => $ok
        && preg_match('/^[a-f0-9]{64}$/D', (string)$row['clave_hash']) === 1, true), 'usuario y dirección se almacenan solo como hash SHA-256');
    $serialized = json_encode($rows);
    $assert(is_string($serialized) && !str_contains($serialized, 'stage2_test_user') && !str_contains($serialized, '192.0.2.44'), 'la tabla no conserva identificadores de acceso en texto');

    pgpRecordLoginSuccess($conn, 'stage2_test_user');
    $accountHash = pgpLoginKey('CUENTA', 'stage2_test_user');
    $originHash = pgpLoginKey('ORIGEN', '192.0.2.44');
    $query = $conn->prepare('SELECT COUNT(*) FROM dbo.cr_login_intentos WHERE clave_hash=:hash');
    $query->execute([':hash' => $accountHash]);
    $assert((int)$query->fetchColumn() === 0, 'un acceso correcto limpia los fallos de esa cuenta');
    $query->execute([':hash' => $originHash]);
    $assert((int)$query->fetchColumn() === 1, 'un acceso correcto no borra la protección compartida del origen');

    $conn->exec("UPDATE dbo.cr_login_intentos SET ventana_inicio=DATEADD(SECOND,-905,SYSDATETIME()),bloqueado_hasta=NULL WHERE clave_hash='" . $originHash . "'");
    pgpRecordLoginFailure($conn, 'fresh_user', $server);
    $query = $conn->prepare('SELECT intentos_fallidos FROM dbo.cr_login_intentos WHERE clave_hash=:hash');
    $query->execute([':hash' => $originHash]);
    $assert((int)$query->fetchColumn() === 1, 'la ventana vencida reinicia el contador automáticamente');

    $assert(pgpPasswordPolicyError('muy-corta', 'user', 'user@example.invalid') !== null, 'rechaza contraseña nueva menor a 12 caracteres');
    $assert(pgpPasswordPolicyError('password123', 'user', 'user@example.invalid') !== null, 'rechaza contraseña nueva predecible');
    $assert(pgpPasswordPolicyError('MiUsuario-frase-segura', 'miusuario', 'mail@example.invalid') !== null, 'rechaza contraseña que contiene el usuario');
    $assert(pgpPasswordPolicyError('Cedro lunar, río 84!', 'miusuario', 'mail@example.invalid') === null, 'acepta una frase larga no predecible');

    $assert(pgpSpreadsheetSafeCell('=HYPERLINK("https://invalid")') === '\'=HYPERLINK("https://invalid")', 'neutraliza fórmula CSV iniciada con igual');
    $assert(pgpSpreadsheetSafeCell('  +SUM(1,2)') === "'  +SUM(1,2)", 'neutraliza fórmula CSV precedida por espacios');
    $assert(pgpSpreadsheetSafeCell('@cmd') === "'@cmd", 'neutraliza fórmula CSV iniciada con arroba');
    $assert(pgpSpreadsheetSafeCell('Tienda A-1') === 'Tienda A-1' && pgpSpreadsheetSafeCell(150000) === 150000, 'conserva texto normal y montos numéricos');

    $public = pgpPublicException(new PDOException('SQLSTATE secreto tabla dbo.interna'), 'stage2.test', 'No fue posible completar la operación.');
    $assert(!str_contains($public, 'SQLSTATE') && !str_contains($public, 'dbo.interna') && str_contains($public, 'Referencia:'), 'el error público omite el diagnóstico y entrega referencia');
    $assert(pgpPublicOrBusinessException(new RuntimeException('Validación comprensible.'), 'stage2.test', 'fallback') === 'Validación comprensible.', 'los mensajes de validación propios siguen visibles');
    $hiddenPdo = pgpPublicOrBusinessException(new PDOException('SQLSTATE interno'), 'stage2.test', 'Error seguro.');
    $assert(!str_contains($hiddenPdo, 'SQLSTATE') && str_starts_with($hiddenPdo, 'Error seguro.'), 'PDOException no se confunde con una validación de negocio');
    $safeFlash = pgpSafePublicMessage('SQLSTATE[23000] Cannot insert duplicate key', 'stage2.flash', 'Operación no completada.');
    $assert(!str_contains($safeFlash, 'SQLSTATE') && str_starts_with($safeFlash, 'Operación no completada.'), 'la última barrera neutraliza diagnósticos en mensajes legacy');
    $assert(pgpSafePublicMessage('El monto supera el saldo disponible.', 'stage2.flash', 'fallback') === 'El monto supera el saldo disponible.', 'la última barrera conserva mensajes funcionales');

    echo 'RESULTADO: ' . $checks . ' comprobaciones correctas.' . PHP_EOL;
} finally {
    if ($created && preg_match('/^PORTALGP_SEC_STAGE2_[a-f0-9]{12}$/D', $testName)) {
        $conn->exec('USE master');
        $conn->exec('ALTER DATABASE [' . $testName . '] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
        $conn->exec('DROP DATABASE [' . $testName . ']');
        echo 'Base temporal de pruebas eliminada: ' . $testName . PHP_EOL;
    }
}
