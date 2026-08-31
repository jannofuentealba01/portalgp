<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../templates/components/section_header.php';
require_once __DIR__ . '/../../templates/components/crud_table.php';

gpGestionRequireSection('permisos');

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
function gpGestionPermisosBuildPaginationItems(int $currentPage, int $totalPages): array
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

function gpGestionPermisosBuildQuery(array $base, array $override = []): string
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create_permission') {
            $name = trim((string) ($_POST['nombre_permiso'] ?? ''));
            $description = trim((string) ($_POST['descripcion'] ?? ''));

            if ($name === '' || $description === '') {
                throw new RuntimeException('Nombre y descripción son obligatorios.');
            }

            $existsStmt = $conn->prepare('SELECT COUNT(*) FROM cr_permisos WHERE nombre_permiso = :nombre_permiso');
            $existsStmt->execute([':nombre_permiso' => $name]);
            if ((int) $existsStmt->fetchColumn() > 0) {
                throw new RuntimeException('Ya existe un permiso con ese nombre.');
            }

            $createStmt = $conn->prepare('INSERT INTO cr_permisos (nombre_permiso, descripcion) VALUES (:nombre_permiso, :descripcion)');
            $createStmt->execute([
                ':nombre_permiso' => $name,
                ':descripcion' => $description,
            ]);

            gpGestionSetFlash('success', 'Permiso creado correctamente.');
            gpGestionRedirect('permisos.php');
        }

        if ($action === 'update_permission') {
            $permissionId = (int) ($_POST['id'] ?? 0);
            $name = trim((string) ($_POST['nombre_permiso'] ?? ''));
            $description = trim((string) ($_POST['descripcion'] ?? ''));

            if ($permissionId <= 0 || $name === '' || $description === '') {
                throw new RuntimeException('Faltan datos para actualizar el permiso.');
            }

            $existsStmt = $conn->prepare('SELECT COUNT(*) FROM cr_permisos WHERE nombre_permiso = :nombre_permiso AND id <> :id');
            $existsStmt->execute([
                ':nombre_permiso' => $name,
                ':id' => $permissionId,
            ]);
            if ((int) $existsStmt->fetchColumn() > 0) {
                throw new RuntimeException('Ya existe otro permiso con ese nombre.');
            }

            $updateStmt = $conn->prepare('UPDATE cr_permisos SET nombre_permiso = :nombre_permiso, descripcion = :descripcion WHERE id = :id');
            $updateStmt->execute([
                ':nombre_permiso' => $name,
                ':descripcion' => $description,
                ':id' => $permissionId,
            ]);

            gpGestionSetFlash('success', 'Permiso actualizado correctamente.');
            gpGestionRedirect('permisos.php');
        }

        if ($action === 'delete_permission') {
            $permissionId = (int) ($_POST['id'] ?? 0);
            if ($permissionId <= 0) {
                throw new RuntimeException('Permiso inválido para eliminar.');
            }

            $linkStmt = $conn->prepare('
                SELECT COUNT(*)
                FROM cr_rol_permisos
                WHERE permiso_id = :permiso_id
            ');
            $linkStmt->execute([':permiso_id' => $permissionId]);
            $linkedCount = (int) $linkStmt->fetchColumn();

            if ($linkedCount > 0) {
                throw new RuntimeException('No se puede eliminar el permiso porque está asignado a uno o más roles.');
            }

            $deleteStmt = $conn->prepare('DELETE FROM cr_permisos WHERE id = :id');
            $deleteStmt->execute([':id' => $permissionId]);

            gpGestionSetFlash('success', 'Permiso eliminado correctamente.');
            gpGestionRedirect('permisos.php');
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        gpGestionSetFlash('danger', $e->getMessage());
        gpGestionRedirect('permisos.php');
    }
}

$whereSql = '';
$params = [];
if ($search !== '') {
    $whereSql = ' WHERE p.nombre_permiso LIKE :search OR p.descripcion LIKE :search';
    $params[':search'] = '%' . $search . '%';
}

$countSql = 'SELECT COUNT(*) FROM cr_permisos p' . $whereSql;
$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $lines));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $lines;

$permissionsSql = '
    SELECT
        p.id,
        p.nombre_permiso,
        p.descripcion,
        COUNT(DISTINCT rp.rol_id) AS total_roles
    FROM cr_permisos p
    LEFT JOIN cr_rol_permisos rp ON rp.permiso_id = p.id'
    . $whereSql . '
    GROUP BY p.id, p.nombre_permiso, p.descripcion
    ORDER BY p.nombre_permiso ASC
    OFFSET :offset ROWS FETCH NEXT :lines ROWS ONLY';
$permissionsStmt = $conn->prepare($permissionsSql);
foreach ($params as $key => $value) {
    $permissionsStmt->bindValue((string) $key, $value);
}
$permissionsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$permissionsStmt->bindValue(':lines', $lines, PDO::PARAM_INT);
$permissionsStmt->execute();
$permissions = $permissionsStmt->fetchAll(PDO::FETCH_ASSOC);
$queryBase = [
    'q' => $search,
    'lineas' => (string) $lines,
];
$paginationItems = gpGestionPermisosBuildPaginationItems($currentPage, $totalPages);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Permisos</title>
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
    <div class="gp-container gp-container--wide">
        <?php
        gpRenderSectionHeader([
            'kicker' => 'Sistema / Gestión',
            'title' => 'Permisos',
            'back_url' => gpGestionBaseUrl('index.php'),
            'back_label' => 'Volver al menú',
            'help_text' => 'Catálogo global de permisos del portal. La asignación por rol se gestiona desde la sección Roles.',
            'help_aria_label' => 'Información de la sección Permisos',
        ]);
        ?>

        <?php if ($flash !== null): ?>
            <div class="alert <?php echo gpGestionFlashClass((string) ($flash['type'] ?? 'info')); ?>" role="alert">
                <?php echo gpGestionH($flash['message'] ?? ''); ?>
            </div>
        <?php endif; ?>

        <form method="GET" class="row g-3 mb-3">
            <div class="col-md-9">
                <label class="form-label" for="q">Buscar permiso</label>
                <input type="text" class="form-control" id="q" name="q" value="<?php echo gpGestionH($search); ?>" placeholder="Nombre o descripción">
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
            'meta_left' => static function () use ($permissions): void {
                echo '<strong>' . gpGestionH((string) count($permissions)) . ' permiso(s) en la vista actual</strong>';
            },
            'meta_right' => static function (): void {
                gpRenderCrudPrimaryAction([
                    'label' => 'Nuevo permiso',
                    'icon' => 'bi bi-shield-plus',
                    'attrs' => [
                        'type' => 'button',
                        'data-bs-toggle' => 'modal',
                        'data-bs-target' => '#createPermissionModal',
                    ],
                ]);
            },
            'meta_class' => 'gp-table-meta',
            'shell_class' => 'gp-table-shell',
            'headers' => ['Permiso', 'Descripción', 'Roles asignados', 'Acciones'],
            'rows' => $permissions,
            'row_render' => static function (array $permission): void {
                ?>
                <tr>
                    <td><?php echo gpGestionH($permission['nombre_permiso'] ?? ''); ?></td>
                    <td class="text-start"><?php echo gpGestionH($permission['descripcion'] ?? ''); ?></td>
                    <td><?php echo gpGestionH((string) ($permission['total_roles'] ?? '0')); ?></td>
                    <td>
                        <div class="d-flex justify-content-center gap-2 flex-wrap">
                            <?php
                            gpRenderCrudActionsMenu([
                                'items' => [
                                    [
                                        'type' => 'button',
                                        'label' => 'Editar permiso',
                                        'icon' => 'bi bi-pencil-square',
                                        'attrs' => [
                                            'type' => 'button',
                                            'data-bs-toggle' => 'modal',
                                            'data-bs-target' => '#editPermissionModal',
                                            'data-permission' => json_encode($permission, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG) ?: '{}',
                                        ],
                                    ],
                                    ['type' => 'divider'],
                                    [
                                        'type' => 'form',
                                        'label' => 'Eliminar permiso',
                                        'icon' => 'bi bi-trash',
                                        'form_attrs' => [
                                            'onsubmit' => "return confirm('¿Eliminar este permiso?');",
                                        ],
                                        'fields' => [
                                            'action' => 'delete_permission',
                                            'id' => (string) ($permission['id'] ?? ''),
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
            'empty_message' => 'No hay permisos creados.',
            'empty_colspan' => 4,
            'pagination' => [
                'enabled' => true,
                'total_records' => $totalRecords,
                'current_page' => $currentPage,
                'total_pages' => $totalPages,
                'items' => $paginationItems,
                'aria_label' => 'Paginación de permisos',
                'build_url' => static fn (int $page): string => gpGestionPermisosBuildQuery($queryBase, ['pagina' => $page]),
            ],
        ]);
        ?>
    </div>
</main>

<div class="modal fade" id="createPermissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="create_permission">
            <div class="modal-header">
                <h5 class="modal-title">Nuevo permiso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="create_permission_name">Nombre del permiso</label>
                    <input type="text" class="form-control" id="create_permission_name" name="nombre_permiso" required>
                </div>
                <div>
                    <label class="form-label" for="create_permission_description">Descripción</label>
                    <textarea class="form-control" id="create_permission_description" name="descripcion" rows="4" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear permiso</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editPermissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_permission">
            <input type="hidden" name="id" id="edit_permission_id">
            <div class="modal-header">
                <h5 class="modal-title">Editar permiso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="edit_permission_name">Nombre del permiso</label>
                    <input type="text" class="form-control" id="edit_permission_name" name="nombre_permiso" required>
                </div>
                <div>
                    <label class="form-label" for="edit_permission_description">Descripción</label>
                    <textarea class="form-control" id="edit_permission_description" name="descripcion" rows="4" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('editPermissionModal').addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var payload = button ? button.getAttribute('data-permission') : null;
    if (!payload) {
        return;
    }

    var permission = JSON.parse(payload);
    document.getElementById('edit_permission_id').value = permission.id || '';
    document.getElementById('edit_permission_name').value = permission.nombre_permiso || '';
    document.getElementById('edit_permission_description').value = permission.descripcion || '';
});
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
</body>
</html>
