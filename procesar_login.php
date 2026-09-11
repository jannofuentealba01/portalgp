<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/login_security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit();
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

try {
    $retryAfter = pgpLoginRetryAfter($conn, $username, $_SERVER);
} catch (Throwable $exception) {
    pgpPublicException($exception, 'login.rate_check', 'No fue posible verificar el acceso.');
    pgpGenericLoginFailure(60);
}
if ($retryAfter > 0) {
    pgpGenericLoginFailure($retryAfter);
}

if ($username === '' || $password === '') {
    try {
        pgpRecordLoginFailure($conn, $username, $_SERVER);
    } catch (Throwable $exception) {
        pgpPublicException($exception, 'login.empty_failure', 'No fue posible registrar el acceso.');
    }
    pgpGenericLoginFailure();
}

$user = pgpFindUserByUsername($conn, $username);
// Valid bcrypt value to keep the verification path equivalent for unknown users.
$hash = is_array($user) ? (string)($user['password_hash'] ?? '') : '$2y$12$70q7SIzmmEAcQ7S6ilfQ2uJirssMK3fVSAIVf9NYUEzR0H6soPbdO';
$credentialsValid = password_verify($password, $hash);
$accountEnabled = is_array($user) && (int)($user['estado_id'] ?? 0) === 1;

if (!$credentialsValid || !$accountEnabled) {
    try {
        $retryAfter = pgpRecordLoginFailure($conn, $username, $_SERVER);
    } catch (Throwable $exception) {
        pgpPublicException($exception, 'login.failure', 'No fue posible registrar el acceso.');
        $retryAfter = 60;
    }
    pgpGenericLoginFailure($retryAfter);
}

pgpLoginUserRecord($conn, $user, 'local');
try {
    pgpRecordLoginSuccess($conn, $username);
} catch (Throwable $exception) {
    // A successful authentication must remain usable; cleanup can be retried later.
    pgpPublicException($exception, 'login.success_cleanup', 'No fue posible limpiar los intentos anteriores.');
}
header('Location: index.php');
exit();
