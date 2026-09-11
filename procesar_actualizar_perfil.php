<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/profile_service.php';
pgpRequireEnabledSession($conn);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    pgpSecurityAbort(405, 'Usa el formulario de Mi perfil.');
}
pgpRequireCsrf();
try {
    $_SESSION['pgp_security_version'] = pgpUpdateOwnPassword($conn,
        (int)$_SESSION['usuario']['id'], (int)$_SESSION['pgp_security_version'], $_POST);
    session_regenerate_id(true);
    $_SESSION['mensaje'] = 'Perfil actualizado correctamente.';
} catch (InvalidArgumentException $e) {
    $_SESSION['mensaje'] = pgpPublicOrBusinessException($e, 'profile.update', 'No fue posible actualizar el perfil.');
} catch (Throwable $e) {
    $_SESSION['mensaje'] = pgpPublicException($e, 'profile.update', 'No fue posible actualizar el perfil. Verifica tu sesión e inténtalo nuevamente.');
}
header('Location: /portalgp/profile.php', true, 303);
exit;
