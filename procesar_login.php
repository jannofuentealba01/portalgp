<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit();
}

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    pgpRedirectToLogin('Por favor ingresa tus credenciales.');
}

$user = pgpFindUserByUsername($conn, $username);
if (!$user) {
    pgpRedirectToLogin('Usuario no encontrado.');
}

if ((int)($user['estado_id'] ?? 0) !== 1) {
    pgpRedirectToLogin('Usuario inhabilitado. Contacta al administrador.');
}

if (!password_verify($password, (string)($user['password_hash'] ?? ''))) {
    pgpRedirectToLogin('Contraseña incorrecta.');
}

pgpLoginUserRecord($conn, $user, 'local');
header('Location: index.php');
exit();
