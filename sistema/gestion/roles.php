<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../templates/components/section_header.php';
require_once __DIR__ . '/../../templates/components/crud_table.php';
require_once __DIR__ . '/../../templates/components/searchable_multiselect.php';

gpGestionRequireSection('roles');

$flash = gpGestionPullFlash();
$search = trim((string) ($_GET['q'] ?? ''));
$allowedLines = [10, 25, 50, 100];
$lines = isset($_GET['lineas']) && is_numeric((string) $_GET['lineas']) ? (int) $_GET['lineas'] : 10;
if (!in_array($lines, $allowedLines, true)) {
    $lines = 10;
}
$currentPage = isset($_GET['pagina']) && is_numeric((string) $_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;

/**
 * @return array<int, array{page:int|null,label:string,active?:bool}>
 */
function gpGestionRolesBuildPaginationItems(int $currentPage, int $totalPages): array
{
    if ($totalPages <= 1) {
        return [];
    }

    $pages = [1, $totalPages];
    for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
        $pages[] = $i;
    }

    $pages = array_values(array_unique($pages));
    sort($pages);

    $items = [];
    $last = null;
    foreach ($pages as $page) {
        if ($last !== null && $page > $last + 1) {
            $items[] = ['page' => null, 'label' => '...'];
        }
        $items[] = ['page' => $page, 'label' => (string) $page, 'active' => $page === $currentPage];
        $last = $page;
    }

    return $items;
}

function gpGestionRolesBuildQuery(array $base, array $override = []): string
{
    $merged = array_merge($base, $override);
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null) {
            unset($merged[$key]);
        }
    }

    $query = http_build_query($merged);
    return $query === '' ? '' : ('?' . $query);
}

/**
 * @return string[]
 */
function gpGestionRolesParsePermissionIds(string $raw): array
{
    $parts = preg_split('/[;|,\/\s]+/', trim($raw)) ?: [];
    $ids = [];
    foreach ($parts as $part) {
        if ($part === '' || !ctype_digit($part)) {
            continue;
        }
        $id = (int) $part;
        if ($id > 0) {
            $ids[(string) $id] = (string) $id;
        }
    }

    return array_values($ids);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create_role') {
            $name = mb_convert_case(trim((string) ($_POST['nombre_rol'] ?? '')), MB_CASE_TITLE, 'UTF-8');
            if ($name === '') {
                throw new RuntimeException('El nombre del rol es obligatorio.');
            }

            $existsStmt = $conn->prepare('SELECT COUNT(*) FROM cr_roles WHERE nombre_rol = :nombre_rol');
            $existsStmt->execute([':nombre_rol' => $name]);
            if ((int) $existsStmt->fetchColumn() > 0) {
                throw new RuntimeException('Ya existe un rol con ese nombre.');
            }

            $createStmt = $conn->prepare('INSERT INTO cr_roles (nombre_rol) VALUES (:nombre_rol)');
            $createStmt->execute([':nombre_rol' => $name]);

            gpGestionSetFlash('success', 'Rol creado correctamente.');
            gpGestionRedirect('roles.php');
        }

        if ($action === 'update_role') {
            $roleId = (int) ($_POST['id'] ?? 0);
            $name = mb_convert_case(trim((string) ($_POST['nombre_rol'] ?? '')), MB_CASE_TITLE, 'UTF-8');

            if ($roleId <= 0 || $name === '') {
                throw new RuntimeException('Faltan datos para actualizar el rol.');
            }

            $existsStmt = $conn->prepare('SELECT COUNT(*) FROM cr_roles WHERE nombre_rol = :nombre_rol AND id <> :id');
            $existsStmt->execute([
                ':nombre_rol' => $name,
                ':id' => $roleId,
            ]);

            if ((int) $existsStmt->fetchColumn() > 0) {
                throw new RuntimeException('Ya existe otro rol con ese nombre.');
            }

            $updateStmt = $conn->prepare('UPDATE cr_roles SET nombre_rol = :nombre_rol WHERE id = :id');
            $updateStmt->execute([
                ':nombre_rol' => $name,
                ':id' => $roleId,
            ]);

            gpGestionSetFlash('success', 'Rol actualizado correctamente.');
            gpGestionRedirect('roles.php');
        }

        if ($action === 'delete_role') {
            $roleId = (int) ($_POST['id'] ?? 0);
            if ($roleId <= 0) {
                throw new RuntimeException('Rol inválido para eliminar.');
            }

            $usageStmt = $conn->prepare('SELECT COUNT(*) FROM cr_usuarios WHERE rol_id = :rol_id');
            $usageStmt->execute([':rol_id' => $roleId]);
            $userCount = (int) $usageStmt->fetchColumn();

            if ($userCount > 0) {
                throw new RuntimeException('No se puede eliminar el rol porque está asignado a ' . $userCount . ' usuario(s).');
            }

            $conn->beginTransaction();

            $conn->prepare('DELETE FROM cr_rol_permisos WHERE rol_id = :rol_id')->execute([':rol_id' => $roleId]);
            $conn->prepare('DELETE FROM cr_roles WHERE id = :id')->execute([':id' => $roleId]);

            $conn->commit();

            gpGestionSetFlash('success', 'Rol eliminado correctamente.');
            gpGestionRedirect('roles.php');
        }

        if ($action === 'save_role_permissions') {
            $roleId = (int) ($_POST['id'] ?? 0);
            $permissionIds = gpGestionRolesParsePermissionIds((string) ($_POST['permiso_ids'] ?? ''));

            if ($roleId <= 0) {
                throw new RuntimeException('Rol inválido para actualizar permisos.');
            }

            $roleExistsStmt = $conn->prepare('SELECT COUNT(*) FROM cr_roles WHERE id = :id');
            $roleExistsStmt->execute([':id' => $roleId]);
            if ((int) $roleExistsStmt->fetchColumn() <= 0) {
                throw new RuntimeException('El rol seleccionado no existe.');
            }

            $availablePermissionMap = [];
            $availablePermissionStmt = $conn->query('SELECT id FROM cr_permisos');
            foreach (($availablePermissionStmt ? $availablePermissionStmt->fetchAll(PDO::FETCH_COLUMN) : []) as $permissionId) {
                $id = (int) $permissionId;
                if ($id > 0) {
                    $availablePermissionMap[(string) $id] = true;
                }
            }

            foreach ($permissionIds as $permissionId) {
                if (!isset($availablePermissionMap[$permissionId])) {
                    throw new RuntimeException('Uno de los permisos seleccionados no existe.');
                }
            }

            $conn->beginTransaction();
            $conn->prepare('DELETE FROM cr_rol_permisos WHERE rol_id = :rol_id')->execute([':rol_id' => $roleId]);

            if ($permissionIds !== []) {
                $insertStmt = $conn->prepare('INSERT INTO cr_rol_permisos (rol_id, permiso_id) VALUES (:rol_id, :permiso_id)');
                foreach ($permissionIds as $permissionId) {
                    $insertStmt->execute([
                        ':rol_id' => $roleId,
                        ':permiso_id' => (int) $permissionId,
                    ]);
                }
            }

            $conn->commit();
            gpGestionSetFlash('success', 'Permisos del rol actualizados correctamente.');
            gpGestionRedirect('roles.php');
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        gpGestionSetFlash('danger', $e->getMessage());
        gpGestionRedirect('roles.php');
    }
}

$params = [];
$whereSql = '';

if ($search !== '') {
    $whereSql = ' WHERE r.nombre_rol LIKE :search';
    $params[':search'] = '%' . $search . '%';
}

$countSql = 'SELECT COUNT(*) FROM cr_roles r' . $whereSql;
$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $lines));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $lines;

$sql = '
    SELECT
        r.id,
        r.nombre_rol,
        COUNT(DISTINCT u.id) AS total_usuarios,
        COUNT(DISTINCT rp.permiso_id) AS total_permisos
    FROM cr_roles r
    LEFT JOIN cr_usuarios u ON u.rol_id = r.id
    LEFT JOIN cr_rol_permisos rp ON rp.rol_id = r.id'
    . $whereSql . '
    GROUP BY r.id, r.nombre_rol
    ORDER BY r.nombre_rol ASC
    OFFSET :offset ROWS FETCH NEXT :lines ROWS ONLY';

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue((string) $key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':lines', $lines, PDO::PARAM_INT);
$stmt->execute();
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

$permissionCatalog = $conn->query('SELECT id, nombre_permiso, descripcion FROM cr_permisos ORDER BY nombre_permiso ASC')->fetchAll(PDO::FETCH_ASSOC);
$permissionSelectOptions = array_map(
    static function (array $permission): array {
        $name = (string) ($permission['nombre_permiso'] ?? '');
        $description = (string) ($permission['descripcion'] ?? '');
        return [
            'value' => (string) ($permission['id'] ?? ''),
            'label' => $name,
            'search' => mb_strtolower(trim($name . ' ' . $description), 'UTF-8'),
        ];
    },
    $permissionCatalog
);

$rolePermissionMap = [];
if ($roles !== []) {
    $roleIds = array_values(array_filter(array_map(static fn (array $role): int => (int) ($role['id'] ?? 0), $roles)));
    if ($roleIds !== []) {
        $placeholders = [];
        $roleParams = [];
        foreach ($roleIds as $index => $roleId) {
            $param = ':role_' . $index;
            $placeholders[] = $param;
            $roleParams[$param] = $roleId;
        }

        $rolePermissionStmt = $conn->prepare('
            SELECT rol_id, permiso_id
            FROM cr_rol_permisos
            WHERE rol_id IN (' . implode(', ', $placeholders) . ')
            ORDER BY rol_id ASC, permiso_id ASC
        ');
        $rolePermissionStmt->execute($roleParams);
        foreach ($rolePermissionStmt->fetchAll(PDO::FETCH_ASSOC) as $linkRow) {
            $roleId = (int) ($linkRow['rol_id'] ?? 0);
            $permissionId = (int) ($linkRow['permiso_id'] ?? 0);
            if ($roleId <= 0 || $permissionId <= 0) {
                continue;
            }
            $rolePermissionMap[$roleId][] = (string) $permissionId;
        }
    }
}

foreach ($roles as &$role) {
    $roleId = (int) ($role['id'] ?? 0);
    $role['permiso_ids'] = implode(';', $rolePermissionMap[$roleId] ?? []);
}
unset($role);
$queryBase = [
    'q' => $search,
    'lineas' => (string) $lines,
];
$paginationItems = gpGestionRolesBuildPaginationItems($currentPage, $totalPages);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Roles</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .gp-table-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 16px 0 10px;
        }

        .gp-table-shell {
            border: 1px solid var(--color-border);
            border-radius: 16px;
            background: #fff;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        @media (max-width: 767.98px) {
            .gp-table-meta {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body class="gp-layout">
<main class="gp-main">
    <div class="gp-container">
        <?php
        gpRenderSectionHeader([
            'kicker' => 'Sistema / Gestión',
            'title' => 'Roles',
            'back_url' => gpGestionBaseUrl('index.php'),
            'back_label' => 'Volver al menú',
            'help_text' => 'Cada rol resume responsabilidades y queda conectado con usuarios y permisos.',
            'help_aria_label' => 'Información de la sección Roles',
        ]);
        ?>

        <?php if ($flash !== null): ?>
            <div class="alert <?php echo gpGestionFlashClass((string) ($flash['type'] ?? 'info')); ?>" role="alert">
                <?php echo gpGestionH($flash['message'] ?? ''); ?>
            </div>
        <?php endif; ?>

        <form method="GET" class="row g-3 mb-3">
            <div class="col-md-9">
                <label class="form-label" for="q">Buscar rol</label>
                <input type="text" class="form-control" id="q" name="q" value="<?php echo gpGestionH($search); ?>" placeholder="Ejemplo: Administrador">
            </div>
            <div class="col-md-2">
                <label for="lineas" class="form-label">Líneas</label>
                <select class="form-select" id="lineas" name="lineas">
                    <?php foreach ($allowedLines as $lineOption): ?>
                        <option value="<?php echo gpGestionH((string) $lineOption); ?>" <?php echo $lines === (int) $lineOption ? 'selected' : ''; ?>>
                            <?php echo gpGestionH((string) $lineOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">Filtrar</button>
            </div>
        </form>

        <?php
        gpRenderCrudTable([
            'meta_left' => static function () use ($roles): void {
                echo '<strong>' . gpGestionH((string) count($roles)) . ' rol(es) en la vista actual</strong>';
            },
            'meta_right' => static function (): void {
                gpRenderCrudPrimaryAction([
                    'label' => 'Nuevo rol',
                    'icon' => 'bi bi-plus-circle',
                    'attrs' => [
                        'type' => 'button',
                        'data-bs-toggle' => 'modal',
                        'data-bs-target' => '#createRoleModal',
                    ],
                ]);
            },
            'meta_class' => 'gp-table-meta',
            'shell_class' => 'gp-table-shell',
            'headers' => ['Rol', 'Usuarios asignados', 'Permisos asignados', 'Acciones'],
            'rows' => $roles,
            'row_render' => static function (array $role): void {
                ?>
                <tr>
                    <td><?php echo gpGestionH($role['nombre_rol'] ?? ''); ?></td>
                    <td><?php echo gpGestionH((string) ($role['total_usuarios'] ?? '0')); ?></td>
                    <td><?php echo gpGestionH((string) ($role['total_permisos'] ?? '0')); ?></td>
                    <td>
                        <div class="d-flex justify-content-center gap-2 flex-wrap">
                            <?php
                            gpRenderCrudActionsMenu([
                                'items' => [
                                    [
                                        'type' => 'button',
                                        'label' => 'Editar rol',
                                        'icon' => 'bi bi-pencil-square',
                                        'attrs' => [
                                            'type' => 'button',
                                            'data-bs-toggle' => 'modal',
                                            'data-bs-target' => '#editRoleModal',
                                            'data-role-id' => (string) ($role['id'] ?? ''),
                                            'data-role-name' => (string) ($role['nombre_rol'] ?? ''),
                                        ],
                                    ],
                                    [
                                        'type' => 'button',
                                        'label' => 'Asignar permisos',
                                        'icon' => 'bi bi-shield-check',
                                        'attrs' => [
                                            'type' => 'button',
                                            'data-bs-toggle' => 'modal',
                                            'data-bs-target' => '#assignPermissionsModal',
                                            'data-role' => json_encode($role, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG) ?: '{}',
                                        ],
                                    ],
                                    ['type' => 'divider'],
                                    [
                                        'type' => 'form',
                                        'label' => 'Eliminar rol',
                                        'icon' => 'bi bi-trash',
                                        'form_attrs' => [
                                            'onsubmit' => "return confirm('¿Eliminar este rol?');",
                                        ],
                                        'fields' => [
                                            'action' => 'delete_role',
                                            'id' => (string) ($role['id'] ?? ''),
                                        ],
                                        'button_class' => 'dropdown-item text-danger',
                                    ],
                                ],
                            ]);
                            ?>
                        </div>
                    </td>
                </tr>
                <?php
            },
            'empty_message' => 'No hay roles que coincidan con la búsqueda.',
            'empty_colspan' => 4,
            'pagination' => [
                'enabled' => true,
                'total_records' => $totalRecords,
                'current_page' => $currentPage,
                'total_pages' => $totalPages,
                'items' => $paginationItems,
                'aria_label' => 'Paginación de roles',
                'build_url' => static fn (int $page): string => gpGestionRolesBuildQuery($queryBase, ['pagina' => $page]),
            ],
        ]);
        ?>
    </div>
</main>

<div class="modal fade" id="createRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="create_role">
            <div class="modal-header">
                <h5 class="modal-title">Nuevo rol</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="create_role_name">Nombre del rol</label>
                <input type="text" class="form-control" id="create_role_name" name="nombre_rol" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear rol</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editRoleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_role">
            <input type="hidden" name="id" id="edit_role_id">
            <div class="modal-header">
                <h5 class="modal-title">Editar rol</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="edit_role_name">Nombre del rol</label>
                <input type="text" class="form-control" id="edit_role_name" name="nombre_rol" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="assignPermissionsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content" id="assignPermissionsForm" style="border-radius: var(--radius-lg);">
            <input type="hidden" name="action" value="save_role_permissions">
            <input type="hidden" name="id" id="assign_permissions_role_id">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Asignar permisos</h5>
                    <div class="small text-muted">Define los permisos operativos para el rol seleccionado.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" style="background: var(--color-bg);">
                <div class="mb-3">
                    <label class="form-label mb-1">Rol</label>
                    <div class="form-control" id="assign_permissions_role_name" style="background:#f8fafc;">-</div>
                </div>
                <?php
                gpRenderSearchableMultiSelectField([
                    'wrapper_class' => 'col-12',
                    'label' => 'Permisos',
                    'input_name' => 'permiso_ids',
                    'input_id' => 'assign_permiso_ids',
                    'picker_id' => 'assign_permiso_ids_picker',
                    'button_id' => 'assign_permiso_ids_btn',
                    'search_id' => 'assign_permiso_ids_search',
                    'list_id' => 'assign_permiso_ids_list',
                    'selected_container_id' => 'assign_permiso_ids_selected',
                    'button_placeholder' => 'Selecciona uno o varios permisos',
                    'search_placeholder' => 'Buscar permiso...',
                    'empty_selected_message' => 'Sin permisos seleccionados.',
                    'hide_selected_options' => true,
                    'selected_view' => 'table',
                    'table_show_principal' => false,
                    'close_on_select' => true,
                    'options' => $permissionSelectOptions,
                ]);
                ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar permisos</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php gpRenderSearchableMultiSelectAssets(); ?>
<script>
document.getElementById('editRoleModal').addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    if (!button) {
        return;
    }

    document.getElementById('edit_role_id').value = button.getAttribute('data-role-id') || '';
    document.getElementById('edit_role_name').value = button.getAttribute('data-role-name') || '';
});

function setSearchableMultiSelectValue(pickerId, value) {
    if (!window.GpSearchableMultiSelect || typeof window.GpSearchableMultiSelect.get !== 'function') {
        return;
    }
    var instance = window.GpSearchableMultiSelect.get(pickerId);
    if (!instance || typeof instance.setSelectedFromString !== 'function') {
        return;
    }
    instance.setSelectedFromString(value || '');
}

var assignPermissionsModal = document.getElementById('assignPermissionsModal');
if (assignPermissionsModal) {
    assignPermissionsModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        var payload = button ? button.getAttribute('data-role') : null;
        if (!payload) {
            return;
        }

        var role = JSON.parse(payload);
        document.getElementById('assign_permissions_role_id').value = role.id || '';
        document.getElementById('assign_permissions_role_name').textContent = role.nombre_rol || '-';
        setSearchableMultiSelectValue('assign_permiso_ids_picker', role.permiso_ids || '');
    });
}
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
</body>
</html>
