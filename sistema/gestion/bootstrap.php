<?php
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../permisos.php';
require_once __DIR__ . '/../../templates/header.php';

function gpGestionH(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function gpGestionBaseUrl(string $path = 'index.php'): string
{
    $path = ltrim($path, '/');
    return '/portalgp/sistema/gestion/' . ($path === '' ? 'index.php' : $path);
}

function gpGestionRedirect(string $path = 'index.php'): never
{
    header('Location: ' . gpGestionBaseUrl($path));
    exit;
}

function gpGestionUserId(): int
{
    return (int) ($_SESSION['usuario']['id'] ?? 0);
}

function gpGestionSetFlash(string $type, string $message): void
{
    $_SESSION['gp_gestion_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function gpGestionPullFlash(): ?array
{
    if (!isset($_SESSION['gp_gestion_flash'])) {
        return null;
    }

    $flash = $_SESSION['gp_gestion_flash'];
    unset($_SESSION['gp_gestion_flash']);

    return is_array($flash) ? $flash : null;
}

function gpGestionHasPermission(string $permissionName): bool
{
    $userId = gpGestionUserId();

    return $userId > 0 && tienePermiso($userId, $permissionName);
}

function gpGestionHasAnyPermission(array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (gpGestionHasPermission((string) $permission)) {
            return true;
        }
    }

    return false;
}

function gpGestionCanAccessUsuarios(): bool
{
    return gpGestionHasAnyPermission(['Administrar Usuarios']);
}

function gpGestionCanAccessRoles(): bool
{
    return gpGestionHasAnyPermission(['Administrar Roles', 'Administrar Usuarios']);
}

function gpGestionCanAccessPermisos(): bool
{
    return gpGestionHasAnyPermission(['Administrar Permisos', 'Permisos', 'Administrar Usuarios']);
}

function gpGestionCanAccessDepartamentos(): bool
{
    return gpGestionHasAnyPermission(['Administrar Usuarios']);
}

function gpGestionCanAccessModule(): bool
{
    return gpGestionCanAccessUsuarios() || gpGestionCanAccessRoles() || gpGestionCanAccessPermisos() || gpGestionCanAccessDepartamentos();
}

function gpGestionDenyAccess(string $message = 'No tienes permisos para acceder a esta sección.'): never
{
    gpGestionSetFlash('danger', $message);
    header('Location: /portalgp/index.php');
    exit;
}

function gpGestionRequireModuleAccess(): void
{
    if (!gpGestionCanAccessModule()) {
        gpGestionDenyAccess('No tienes permisos para acceder a Gestión del Sistema.');
    }
}

function gpGestionRequireSection(string $section): void
{
    $allowed = match ($section) {
        'usuarios' => gpGestionCanAccessUsuarios(),
        'roles' => gpGestionCanAccessRoles(),
        'permisos' => gpGestionCanAccessPermisos(),
        'departamentos' => gpGestionCanAccessDepartamentos(),
        default => false,
    };

    if (!$allowed) {
        gpGestionDenyAccess('No tienes permisos para acceder a esta sección.');
    }
}

function gpGestionFlashClass(string $type): string
{
    return match ($type) {
        'success' => 'alert-success',
        'warning' => 'alert-warning',
        'danger' => 'alert-danger',
        default => 'alert-info',
    };
}
