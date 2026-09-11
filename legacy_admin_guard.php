<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/permisos.php';

pgpRequireEnabledSession($conn);

$legacyUserId = (int) ($_SESSION['usuario']['id'] ?? 0);

if (!tienePermiso($legacyUserId, 'Administrar Usuarios')) {
    http_response_code(403);
    echo 'Acceso no autorizado.';
    exit;
}