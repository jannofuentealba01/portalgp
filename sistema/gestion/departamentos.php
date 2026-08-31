<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../templates/components/section_header.php';
require_once __DIR__ . '/../../templates/components/crud_table.php';
require_once __DIR__ . '/../../templates/components/confirm_action_modal.php';

gpGestionRequireSection('departamentos');

$flash = gpGestionPullFlash();
$search = trim((string) ($_GET['q'] ?? ''));

$catalogTableExists = (int) $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'cr_departamentos'")->fetchColumn() > 0;
$bridgeTableExists = (int) $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'cr_usuario_departamento'")->fetchColumn() > 0;
$departmentsSchemaReady = $catalogTableExists && $bridgeTableExists;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!$departmentsSchemaReady) {
            throw new RuntimeException('Falta crear el esquema de departamentos. Ejecuta primero sql/create_cr_departamentos.sql.');
        }

        if ($action === 'create_department') {
            $codigo = strtoupper(trim((string) ($_POST['codigo'] ?? '')));
            $nombre = mb_convert_case(trim((string) ($_POST['nombre'] ?? '')), MB_CASE_TITLE, 'UTF-8');
            $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
            $ordenVisual = (int) ($_POST['orden_visual'] ?? 0);

            if ($codigo === '' || $nombre === '') {
                throw new RuntimeException('Código y nombre son obligatorios.');
            }

            $existsStmt = $conn->prepare('SELECT COUNT(*) FROM cr_departamentos WHERE codigo = :codigo OR nombre = :nombre');
            $existsStmt->execute([
                ':codigo' => $codigo,
                ':nombre' => $nombre,
            ]);
            if ((int) $existsStmt->fetchColumn() > 0) {
                throw new RuntimeException('Ya existe un departamento con ese código o nombre.');
            }

            $insertStmt = $conn->prepare('
                INSERT INTO cr_departamentos (codigo, nombre, descripcion, orden_visual, activo)
                VALUES (:codigo, :nombre, :descripcion, :orden_visual, 1)
            ');
            $insertStmt->execute([
                ':codigo' => $codigo,
                ':nombre' => $nombre,
                ':descripcion' => $descripcion === '' ? null : $descripcion,
                ':orden_visual' => $ordenVisual,
            ]);

            gpGestionSetFlash('success', 'Departamento creado correctamente.');
            gpGestionRedirect('departamentos.php');
        }

        if ($action === 'update_department') {
            $departmentId = (int) ($_POST['id_departamento'] ?? 0);
            $codigo = strtoupper(trim((string) ($_POST['codigo'] ?? '')));
            $nombre = mb_convert_case(trim((string) ($_POST['nombre'] ?? '')), MB_CASE_TITLE, 'UTF-8');
            $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
            $ordenVisual = (int) ($_POST['orden_visual'] ?? 0);

            if ($departmentId <= 0 || $codigo === '' || $nombre === '') {
                throw new RuntimeException('Faltan datos para actualizar el departamento.');
            }

            $existsStmt = $conn->prepare('
                SELECT COUNT(*)
                FROM cr_departamentos
                WHERE (codigo = :codigo OR nombre = :nombre)
                  AND id_departamento <> :id_departamento
            ');
            $existsStmt->execute([
                ':codigo' => $codigo,
                ':nombre' => $nombre,
                ':id_departamento' => $departmentId,
            ]);
            if ((int) $existsStmt->fetchColumn() > 0) {
                throw new RuntimeException('Ya existe otro departamento con ese código o nombre.');
            }

            $updateStmt = $conn->prepare('
                UPDATE cr_departamentos
                SET codigo = :codigo,
                    nombre = :nombre,
                    descripcion = :descripcion,
                    orden_visual = :orden_visual
                WHERE id_departamento = :id_departamento
            ');
            $updateStmt->execute([
                ':codigo' => $codigo,
                ':nombre' => $nombre,
                ':descripcion' => $descripcion === '' ? null : $descripcion,
                ':orden_visual' => $ordenVisual,
                ':id_departamento' => $departmentId,
            ]);

            gpGestionSetFlash('success', 'Departamento actualizado correctamente.');
            gpGestionRedirect('departamentos.php');
        }

        if ($action === 'delete_department') {
            $departmentId = (int) ($_POST['id_departamento'] ?? 0);
            if ($departmentId <= 0) {
                throw new RuntimeException('Departamento inválido para eliminar.');
            }

            $usageStmt = $conn->prepare('SELECT COUNT(*) FROM cr_usuario_departamento WHERE departamento_id = :id_departamento');
            $usageStmt->execute([':id_departamento' => $departmentId]);
            $assignedUsers = (int) $usageStmt->fetchColumn();

            if ($assignedUsers > 0) {
                throw new RuntimeException('No se puede eliminar el departamento porque está asignado a ' . $assignedUsers . ' usuario(s).');
            }

            $deleteStmt = $conn->prepare('DELETE FROM cr_departamentos WHERE id_departamento = :id_departamento');
            $deleteStmt->execute([':id_departamento' => $departmentId]);

            gpGestionSetFlash('success', 'Departamento eliminado correctamente.');
            gpGestionRedirect('departamentos.php');
        }

        if ($action === 'toggle_department_status') {
            $departmentId = (int) ($_POST['id_departamento'] ?? 0);
            $targetStatus = (int) ($_POST['target_status'] ?? -1);
            if ($departmentId <= 0 || !in_array($targetStatus, [0, 1], true)) {
                throw new RuntimeException('Parámetros inválidos para cambiar estado.');
            }

            $departmentStmt = $conn->prepare('SELECT nombre, activo FROM cr_departamentos WHERE id_departamento = :id_departamento');
            $departmentStmt->execute([':id_departamento' => $departmentId]);
            $department = $departmentStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($department)) {
                throw new RuntimeException('Departamento no encontrado.');
            }

            $updateStatusStmt = $conn->prepare('UPDATE cr_departamentos SET activo = :activo WHERE id_departamento = :id_departamento');
            $updateStatusStmt->execute([
                ':activo' => $targetStatus,
                ':id_departamento' => $departmentId,
            ]);

            $statusLabel = $targetStatus === 1 ? 'activado' : 'desactivado';
            gpGestionSetFlash('success', 'Departamento "' . (string) ($department['nombre'] ?? '') . '" ' . $statusLabel . ' correctamente.');
            gpGestionRedirect('departamentos.php');
        }
    } catch (Throwable $e) {
        gpGestionSetFlash('danger', $e->getMessage());
        gpGestionRedirect('departamentos.php');
    }
}

$departments = [];

if ($departmentsSchemaReady) {
    $sql = '
        SELECT
            d.id_departamento,
            d.codigo,
            d.nombre,
            d.descripcion,
            d.orden_visual,
            d.activo,
            COUNT(DISTINCT ud.usuario_id) AS total_usuarios
        FROM cr_departamentos d
        LEFT JOIN cr_usuario_departamento ud ON ud.departamento_id = d.id_departamento';
    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE d.codigo LIKE :search OR d.nombre LIKE :search OR ISNULL(d.descripcion, \'\') LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= '
        GROUP BY d.id_departamento, d.codigo, d.nombre, d.descripcion, d.orden_visual, d.activo
        ORDER BY d.orden_visual ASC, d.nombre ASC';

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Departamentos</title>
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

        .gp-row-code {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            background: rgba(11, 58, 110, 0.08);
            color: var(--color-primary);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
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
            'title' => 'Departamentos',
            'back_url' => gpGestionBaseUrl('index.php'),
            'back_label' => 'Volver al menú',
            'help_text' => 'Separa la estructura organizacional y permite asignar uno o muchos departamentos por usuario.',
            'help_aria_label' => 'Información de la sección Departamentos',
        ]);
        ?>

        <?php if ($flash !== null): ?>
            <div class="alert <?php echo gpGestionFlashClass((string) ($flash['type'] ?? 'info')); ?>" role="alert">
                <?php echo gpGestionH($flash['message'] ?? ''); ?>
            </div>
        <?php endif; ?>

        <?php if (!$departmentsSchemaReady): ?>
            <div class="alert alert-warning" role="alert">
                Falta crear el esquema de departamentos. Ejecuta <code>sql/create_cr_departamentos.sql</code> y vuelve a cargar esta pantalla.
            </div>
        <?php else: ?>
            <form method="GET" class="row g-3 mb-3">
                <div class="col-md-11">
                    <label class="form-label" for="q">Buscar departamento</label>
                    <input type="text" class="form-control" id="q" name="q" value="<?php echo gpGestionH($search); ?>" placeholder="Código, nombre o descripción">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                </div>
            </form>

            <?php
            gpRenderCrudTable([
                'meta_left' => static function () use ($departments): void {
                    echo '<strong>' . gpGestionH((string) count($departments)) . ' departamento(s) en la vista actual</strong>';
                },
                'meta_right' => static function (): void {
                    gpRenderCrudPrimaryAction([
                        'label' => 'Nuevo departamento',
                        'icon' => 'bi bi-building-add',
                        'attrs' => [
                            'type' => 'button',
                            'data-bs-toggle' => 'modal',
                            'data-bs-target' => '#createDepartmentModal',
                        ],
                    ]);
                },
                'meta_class' => 'gp-table-meta',
                'shell_class' => 'gp-table-shell',
                'headers' => ['Código', 'Nombre', 'Descripción', 'Orden', 'Usuarios', 'Estado', 'Acciones'],
                'rows' => $departments,
                'row_render' => static function (array $department): void {
                    $isActive = (int) ($department['activo'] ?? 0) === 1;
                    $statusClass = $isActive ? 'text-bg-success' : 'text-bg-secondary';
                    $statusLabel = $isActive ? 'Activo' : 'Inactivo';
                    $statusActionLabel = $isActive ? 'Desactivar' : 'Activar';
                    $targetStatus = $isActive ? 0 : 1;
                    $departmentName = trim((string) ($department['nombre'] ?? ''));
                    $confirmTitle = $isActive ? 'Desactivar departamento' : 'Activar departamento';
                    $confirmMessage = ($isActive ? 'Vas a desactivar' : 'Vas a activar') . ' el departamento "' . $departmentName . '".';
                    ?>
                    <tr>
                        <td><span class="gp-row-code"><?php echo gpGestionH($department['codigo'] ?? ''); ?></span></td>
                        <td><?php echo gpGestionH($department['nombre'] ?? ''); ?></td>
                        <td class="text-start"><?php echo gpGestionH($department['descripcion'] ?? ''); ?></td>
                        <td><?php echo gpGestionH((string) ($department['orden_visual'] ?? 0)); ?></td>
                        <td><?php echo gpGestionH((string) ($department['total_usuarios'] ?? 0)); ?></td>
                        <td>
                            <span class="badge <?php echo gpGestionH($statusClass); ?>">
                                <?php echo gpGestionH($statusLabel); ?>
                            </span>
                        </td>
                        <td>
                            <div class="d-flex justify-content-center gap-2 flex-wrap">
                                <?php
                                gpRenderCrudActionsMenu([
                                    'items' => [
                                        [
                                            'type' => 'button',
                                            'label' => 'Editar departamento',
                                            'icon' => 'bi bi-pencil-square',
                                            'attrs' => [
                                                'type' => 'button',
                                                'data-bs-toggle' => 'modal',
                                                'data-bs-target' => '#editDepartmentModal',
                                                'data-department' => json_encode($department, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG) ?: '{}',
                                            ],
                                        ],
                                        [
                                            'type' => 'form',
                                            'label' => $statusActionLabel . ' departamento',
                                            'icon' => $isActive ? 'bi bi-toggle-off' : 'bi bi-toggle-on',
                                            'fields' => [
                                                'action' => 'toggle_department_status',
                                                'id_departamento' => (string) ($department['id_departamento'] ?? ''),
                                                'target_status' => (string) $targetStatus,
                                            ],
                                            'button_attrs' => [
                                                'type' => 'button',
                                                'data-gp-confirm' => true,
                                                'data-confirm-modal' => 'gp_toggle_department_status_modal',
                                                'data-confirm-title' => $confirmTitle,
                                                'data-confirm-message' => $confirmMessage,
                                                'data-confirm-accept-label' => $statusActionLabel,
                                                'data-confirm-accept-class' => $isActive ? 'btn btn-danger' : 'btn btn-success',
                                            ],
                                        ],
                                        ['type' => 'divider'],
                                        [
                                            'type' => 'form',
                                            'label' => 'Eliminar departamento',
                                            'icon' => 'bi bi-trash',
                                            'form_attrs' => [
                                                'onsubmit' => "return confirm('¿Eliminar este departamento?');",
                                            ],
                                            'fields' => [
                                                'action' => 'delete_department',
                                                'id_departamento' => (string) ($department['id_departamento'] ?? ''),
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
                'empty_message' => 'No hay departamentos que coincidan con la búsqueda.',
                'empty_colspan' => 7,
            ]);
            ?>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade" id="createDepartmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="create_department">
            <div class="modal-header">
                <h5 class="modal-title">Nuevo departamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="create_codigo">Código</label>
                        <input type="text" class="form-control" id="create_codigo" name="codigo" required maxlength="50" placeholder="Ejemplo: LEGAL">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="create_nombre">Nombre</label>
                        <input type="text" class="form-control" id="create_nombre" name="nombre" required maxlength="120" placeholder="Nombre visible del departamento">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label" for="create_descripcion">Descripción</label>
                        <input type="text" class="form-control" id="create_descripcion" name="descripcion" maxlength="255" placeholder="Contexto breve del departamento">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="create_orden_visual">Orden visual</label>
                        <input type="number" class="form-control" id="create_orden_visual" name="orden_visual" value="0" min="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear departamento</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editDepartmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_department">
            <input type="hidden" name="id_departamento" id="edit_department_id">
            <div class="modal-header">
                <h5 class="modal-title">Editar departamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="edit_department_codigo">Código</label>
                        <input type="text" class="form-control" id="edit_department_codigo" name="codigo" required maxlength="50">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="edit_department_nombre">Nombre</label>
                        <input type="text" class="form-control" id="edit_department_nombre" name="nombre" required maxlength="120">
                    </div>
                    <div class="col-md-9">
                        <label class="form-label" for="edit_department_descripcion">Descripción</label>
                        <input type="text" class="form-control" id="edit_department_descripcion" name="descripcion" maxlength="255">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="edit_department_orden_visual">Orden visual</label>
                        <input type="number" class="form-control" id="edit_department_orden_visual" name="orden_visual" min="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<?php
gpRenderConfirmActionModal([
    'id' => 'gp_toggle_department_status_modal',
    'title' => 'Confirmar cambio de estado',
    'message' => 'Deseas continuar con el cambio de estado?',
    'cancel_label' => 'Cancelar',
    'accept_label' => 'Confirmar',
    'accept_class' => 'btn btn-danger',
]);
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('editDepartmentModal').addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var payload = button ? button.getAttribute('data-department') : null;
    if (!payload) {
        return;
    }

    var department = JSON.parse(payload);
    document.getElementById('edit_department_id').value = department.id_departamento || '';
    document.getElementById('edit_department_codigo').value = department.codigo || '';
    document.getElementById('edit_department_nombre').value = department.nombre || '';
    document.getElementById('edit_department_descripcion').value = department.descripcion || '';
    document.getElementById('edit_department_orden_visual').value = department.orden_visual || 0;
});
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
</body>
</html>
