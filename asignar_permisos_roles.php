<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
pgpRequireEnabledSession($conn);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    pgpSecurityAbort(409, 'Este formulario fue reemplazado. Abre Gestión del sistema / Roles y vuelve a seleccionar los permisos.');
}
header('Location: /portalgp/sistema/gestion/roles.php', true, 303);
exit;
