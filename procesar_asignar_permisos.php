<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permission_service.php';
pgpRequireEnabledSession($conn);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /portalgp/sistema/gestion/roles.php', true, 303);
    exit;
}
pgpRequireCsrf();
if (!pgpCanManagePermissions($conn, (int)$_SESSION['usuario']['id'])) {
    pgpSecurityAbort(403, 'No tienes autorización para modificar permisos.');
}
try {
    $roles = $_POST['permisos'] ?? null;
    if (!is_array($roles) || count($roles) !== 1) {
        throw new InvalidArgumentException('Selecciona un solo rol; no se permite reemplazar todos los permisos.');
    }
    $id = filter_var(array_key_first($roles), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$id) { throw new InvalidArgumentException('Rol inválido.'); }
    pgpReplaceRolePermissions($conn, (int)$_SESSION['usuario']['id'], (int)$id, reset($roles));
    $_SESSION['gp_gestion_flash'] = ['type'=>'success', 'message'=>'Permisos actualizados correctamente.'];
} catch (InvalidArgumentException $e) {
    pgpSecurityAbort(422, $e->getMessage());
} catch (Throwable $e) {
    pgpLogException($e, 'permissions.update');
    pgpSecurityAbort(409, 'No se actualizaron los permisos. Revisa la selección y evita reducir tu propio acceso.');
}
header('Location: /portalgp/sistema/gestion/roles.php', true, 303);
exit;
