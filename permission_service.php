<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

function pgpPermissionMap(PDO $db, int $roleId): array
{
    $stmt = $db->prepare('SELECT permiso_id,lectura,escritura,eliminacion FROM dbo.cr_rol_permisos WHERE rol_id=:role ORDER BY permiso_id');
    $stmt->execute([':role' => $roleId]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int) $row['permiso_id']] = [
            'lectura' => (int) $row['lectura'], 'escritura' => (int) $row['escritura'],
            'eliminacion' => (int) $row['eliminacion'],
        ];
    }
    return $map;
}

function pgpNormalizePermissionMap(mixed $raw): array
{
    if (!is_array($raw)) {
        throw new InvalidArgumentException('La matriz de permisos no es válida.');
    }
    $map = [];
    foreach ($raw as $key => $flags) {
        $id = filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || !is_array($flags) || array_diff(array_keys($flags), ['lectura', 'escritura', 'eliminacion']) !== []) {
            throw new InvalidArgumentException('Un permiso o sus acciones no son válidos.');
        }
        $normalized = [];
        foreach (['lectura', 'escritura', 'eliminacion'] as $action) {
            $value = $flags[$action] ?? 0;
            if (!in_array($value, [0, 1, '0', '1', 'on'], true)) {
                throw new InvalidArgumentException('Las acciones deben ser casillas válidas.');
            }
            $normalized[$action] = in_array($value, [1, '1', 'on'], true) ? 1 : 0;
        }
        if (($normalized['escritura'] && !$normalized['lectura'])
            || ($normalized['eliminacion'] && !$normalized['escritura'])) {
            throw new InvalidArgumentException('Escritura requiere lectura; eliminación requiere ambas.');
        }
        // Keep zero rows during validation so unknown IDs cannot disappear silently.
        $map[(int) $id] = $normalized;
    }
    ksort($map);
    return $map;
}

/** One role only, validated and committed atomically; no global delete. */
function pgpReplaceRolePermissions(PDO $db, int $actorId, int $roleId, mixed $raw): void
{
    $map = pgpNormalizePermissionMap($raw);
    if ($roleId <= 0 || $db->inTransaction()) {
        throw new InvalidArgumentException('El rol o el contexto de guardado no es válido.');
    }
    $db->beginTransaction();
    try {
        if (!pgpCanManagePermissions($db, $actorId)) {
            throw new RuntimeException('No tienes autorización para modificar permisos.');
        }
        $lock = $db->prepare('SELECT id FROM dbo.cr_roles WITH(UPDLOCK,HOLDLOCK) WHERE id=:role');
        $lock->execute([':role' => $roleId]);
        if (!$lock->fetchColumn()) {
            throw new InvalidArgumentException('El rol no existe.');
        }
        $available = array_fill_keys($db->query('SELECT id FROM dbo.cr_permisos')->fetchAll(PDO::FETCH_COLUMN), true);
        foreach ($map as $id => $flags) {
            if (!isset($available[$id])) {
                throw new InvalidArgumentException('Uno de los permisos seleccionados no existe.');
            }
        }
        $actor = pgpSecurityUser($db, $actorId);
        if ((int) ($actor['rol_id'] ?? 0) === $roleId) {
            foreach (pgpPermissionMap($db, $roleId) as $id => $flags) {
                foreach ($flags as $action => $granted) {
                    if ($granted === 1 && ($map[$id][$action] ?? 0) !== 1) {
                        throw new RuntimeException('Para evitar perder tu acceso, no puedes reducir permisos de tu propio rol desde esta sesión.');
                    }
                }
            }
        }
        $delete = $db->prepare('DELETE FROM dbo.cr_rol_permisos WHERE rol_id=:role');
        $delete->execute([':role' => $roleId]);
        $insert = $db->prepare('INSERT dbo.cr_rol_permisos(rol_id,permiso_id,lectura,escritura,eliminacion)
            VALUES(:role,:permission,:read,:write,:delete)');
        foreach ($map as $id => $flags) {
            if (!$flags['lectura']) {
                continue;
            }
            $insert->execute([':role' => $roleId, ':permission' => $id, ':read' => $flags['lectura'],
                ':write' => $flags['escritura'], ':delete' => $flags['eliminacion']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
