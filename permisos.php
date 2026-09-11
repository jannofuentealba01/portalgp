<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function tienePermiso($userId, $nombrePermiso, ?string $accion = null): bool
{
    global $conn;
    if (!pgpValidateSession($conn) || (int)($_SESSION['usuario']['id'] ?? 0) !== (int)$userId) {
        return false;
    }
    return pgpHasPermission($conn, (int)$userId, (string)$nombrePermiso, $accion ?? pgpRequestPermissionAction());
}
