<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../templates/components/searchable_select.php';
require_once __DIR__ . '/../../templates/components/searchable_multiselect.php';
require_once __DIR__ . '/../../templates/components/confirm_action_modal.php';
require_once __DIR__ . '/../../templates/components/section_header.php';
require_once __DIR__ . '/../../templates/components/crud_table.php';

gpGestionRequireSection('usuarios');

$flash = gpGestionPullFlash();
$search = trim((string) ($_GET['q'] ?? ''));
$roleFilter = (int) ($_GET['rol_id'] ?? 0);
$stateFilter = (int) ($_GET['estado_id'] ?? 0);
$departmentFilter = (int) ($_GET['departamento_id'] ?? 0);
$allowedLines = [10, 25, 50, 100];
$lines = isset($_GET['lineas']) && is_numeric((string) $_GET['lineas']) ? (int) $_GET['lineas'] : 10;
if (!in_array($lines, $allowedLines, true)) {
    $lines = 25;
}
$currentPage = isset($_GET['pagina']) && is_numeric((string) $_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;

$roles = $conn->query('SELECT id, nombre_rol FROM cr_roles ORDER BY nombre_rol ASC')->fetchAll(PDO::FETCH_ASSOC);
$states = $conn->query('SELECT id, estado FROM cr_estado_usuario ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
$departmentsEnabled = (int) $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'cr_departamentos'")->fetchColumn() > 0
    && (int) $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'cr_usuario_departamento'")->fetchColumn() > 0;
$usersHasLogoColumn = (int) $conn->query("
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo'
      AND TABLE_NAME = 'cr_usuarios'
      AND COLUMN_NAME = 'url_logo'
")->fetchColumn() > 0;
$departments = $departmentsEnabled
    ? $conn->query('SELECT id_departamento, codigo, nombre, activo FROM cr_departamentos ORDER BY orden_visual ASC, nombre ASC')->fetchAll(PDO::FETCH_ASSOC)
    : [];
$activeDepartments = array_values(array_filter(
    $departments,
    static fn (array $department): bool => (int) ($department['activo'] ?? 0) === 1
));

$roleSelectOptions = array_map(
    static fn (array $role): array => [
        'value' => (string) ($role['id'] ?? ''),
        'label' => (string) ($role['nombre_rol'] ?? ''),
        'search' => mb_strtolower((string) ($role['nombre_rol'] ?? ''), 'UTF-8'),
    ],
    $roles
);

$departmentSelectOptions = array_map(
    static function (array $department): array {
        return [
            'value' => (string) ($department['id_departamento'] ?? ''),
            'label' => (string) ($department['nombre'] ?? ''),
            'search' => mb_strtolower(trim((string) (($department['codigo'] ?? '') . ' ' . ($department['nombre'] ?? '') . ' activo')), 'UTF-8'),
        ];
    },
    $activeDepartments
);

/**
 * @return int[]
 */
function gpGestionParseDepartmentIds(string $raw): array
{
    $parts = preg_split('/[;|,\/\s]+/', trim($raw)) ?: [];
    $ids = [];
    foreach ($parts as $part) {
        if ($part === '' || !ctype_digit($part)) {
            continue;
        }
        $id = (int) $part;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

/**
 * @param int[] $departmentIds
 * @param array<int, bool> $allowedDepartmentIds
 */
function gpGestionSyncUserDepartments(PDO $conn, int $userId, array $departmentIds, array $allowedDepartmentIds): void
{
    $normalized = [];
    foreach ($departmentIds as $departmentId) {
        $id = (int) $departmentId;
        if ($id > 0 && isset($allowedDepartmentIds[$id])) {
            $normalized[$id] = $id;
        }
    }

    $conn->prepare('DELETE FROM cr_usuario_departamento WHERE usuario_id = :usuario_id')->execute([
        ':usuario_id' => $userId,
    ]);

    if ($normalized === []) {
        return;
    }

    $insertStmt = $conn->prepare('
        INSERT INTO cr_usuario_departamento (usuario_id, departamento_id, es_principal)
        VALUES (:usuario_id, :departamento_id, :es_principal)
    ');

    $isFirst = true;
    foreach (array_values($normalized) as $departmentId) {
        $insertStmt->execute([
            ':usuario_id' => $userId,
            ':departamento_id' => $departmentId,
            ':es_principal' => $isFirst ? 1 : 0,
        ]);
        $isFirst = false;
    }
}

/**
 * @return array<int, array{page:int|null,label:string,active?:bool}>
 */
function gpGestionBuildPaginationItems(int $currentPage, int $totalPages): array
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

function gpGestionBuildQuery(array $base, array $override = []): string
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

$departmentCatalogMap = [];
foreach ($departments as $department) {
    $departmentId = (int) ($department['id_departamento'] ?? 0);
    if ($departmentId > 0) {
        $departmentCatalogMap[$departmentId] = (int) ($department['activo'] ?? 0) === 1;
    }
}

$enabledStateId = 1;
$disabledStateId = 2;
foreach ($states as $stateRow) {
    $name = mb_strtolower(trim((string) ($stateRow['estado'] ?? '')), 'UTF-8');
    if (str_contains($name, 'inhabil') || str_contains($name, 'desactiv') || str_contains($name, 'bloque')) {
        $disabledStateId = (int) $stateRow['id'];
        continue;
    }
    if (str_contains($name, 'habil') || str_contains($name, 'activ')) {
        $enabledStateId = (int) $stateRow['id'];
    }
}

$stateSelectOptions = [
    [
        'value' => '',
        'label' => 'Usar estado por defecto',
        'search' => 'usar estado por defecto',
    ],
];

foreach ($states as $state) {
    $label = (string) ($state['estado'] ?? '');
    $stateSelectOptions[] = [
        'value' => (string) ($state['id'] ?? ''),
        'label' => $label,
        'search' => mb_strtolower($label, 'UTF-8'),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create_user') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $fullName = trim((string) ($_POST['nombre_completo'] ?? ''));
            $email = trim((string) ($_POST['correo_electronico'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
            $roleId = (int) ($_POST['rol_id'] ?? 0);
            $stateId = (int) ($_POST['estado_id'] ?? 0);
            if ($stateId <= 0) {
                $stateId = $enabledStateId;
            }

            if ($username === '' || $fullName === '' || $email === '' || $password === '' || $roleId <= 0) {
                throw new RuntimeException('Completa todos los campos obligatorios para crear el usuario.');
            }

            if ($password !== $passwordConfirm) {
                throw new RuntimeException('Las contraseñas del nuevo usuario no coinciden.');
            }

            $duplicateStmt = $conn->prepare('SELECT COUNT(*) FROM cr_usuarios WHERE UserName = :username OR correo_electronico = :email');
            $duplicateStmt->execute([
                ':username' => $username,
                ':email' => $email,
            ]);

            if ((int) $duplicateStmt->fetchColumn() > 0) {
                throw new RuntimeException('El nombre de usuario o el correo ya existen.');
            }

            $insertStmt = $conn->prepare('
                INSERT INTO cr_usuarios (UserName, nombre_completo, correo_electronico, password_hash, rol_id, estado_id)
                VALUES (:username, :nombre_completo, :correo_electronico, :password_hash, :rol_id, :estado_id)
            ');
            $insertStmt->execute([
                ':username' => $username,
                ':nombre_completo' => $fullName,
                ':correo_electronico' => $email,
                ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
                ':rol_id' => $roleId,
                ':estado_id' => $stateId,
            ]);

            gpGestionSetFlash('success', 'Usuario creado correctamente.');
            gpGestionRedirect('usuarios.php');
        }

        if ($action === 'update_user') {
            $userId = (int) ($_POST['id'] ?? 0);
            $fullName = trim((string) ($_POST['nombre_completo'] ?? ''));
            $email = trim((string) ($_POST['correo_electronico'] ?? ''));
            $urlLogo = trim((string) ($_POST['url_logo'] ?? ''));
            $roleId = (int) ($_POST['rol_id'] ?? 0);
            $stateId = (int) ($_POST['estado_id'] ?? 0);
            $password = trim((string) ($_POST['password'] ?? ''));
            $passwordConfirm = trim((string) ($_POST['password_confirm'] ?? ''));

            if ($userId <= 0 || $fullName === '' || $email === '' || $roleId <= 0 || $stateId <= 0) {
                throw new RuntimeException('Faltan datos para actualizar el usuario.');
            }

            if (($password === '' && $passwordConfirm !== '') || ($password !== '' && $passwordConfirm === '')) {
                throw new RuntimeException('Debes completar ambos campos de contraseña para actualizarla.');
            }

            if ($password !== '' && $password !== $passwordConfirm) {
                throw new RuntimeException('La nueva contraseña y su confirmación no coinciden.');
            }

            $duplicateStmt = $conn->prepare('SELECT COUNT(*) FROM cr_usuarios WHERE correo_electronico = :email AND id <> :id');
            $duplicateStmt->execute([
                ':email' => $email,
                ':id' => $userId,
            ]);

            if ((int) $duplicateStmt->fetchColumn() > 0) {
                throw new RuntimeException('El correo electrónico ya está asignado a otro usuario.');
            }

            if ($urlLogo !== '') {
                if (mb_strlen($urlLogo, 'UTF-8') > 500) {
                    throw new RuntimeException('La URL de foto/logo excede el largo máximo permitido (500).');
                }

                $isValidUrl = filter_var($urlLogo, FILTER_VALIDATE_URL) !== false;
                if (!$isValidUrl) {
                    throw new RuntimeException('La URL de foto/logo no tiene un formato válido.');
                }
            }

            $sql = '
                UPDATE cr_usuarios
                SET nombre_completo = :nombre_completo,
                    correo_electronico = :correo_electronico,
                    rol_id = :rol_id,
                    estado_id = :estado_id';
            $params = [
                ':nombre_completo' => $fullName,
                ':correo_electronico' => $email,
                ':rol_id' => $roleId,
                ':estado_id' => $stateId,
                ':id' => $userId,
            ];

            if ($usersHasLogoColumn) {
                $sql .= ', url_logo = :url_logo';
                $params[':url_logo'] = $urlLogo !== '' ? $urlLogo : null;
            }

            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params[':password_hash'] = password_hash($password, PASSWORD_BCRYPT);
            }

            $sql .= ' WHERE id = :id';

            $updateStmt = $conn->prepare($sql);
            $updateStmt->execute($params);

            gpGestionSetFlash('success', 'Usuario actualizado correctamente.');
            gpGestionRedirect('usuarios.php');
        }

        if ($action === 'assign_user_departments') {
            if (!$departmentsEnabled) {
                throw new RuntimeException('La asignación de departamentos no está disponible hasta ejecutar la migración.');
            }

            $userId = (int) ($_POST['id'] ?? 0);
            $departmentIds = gpGestionParseDepartmentIds((string) ($_POST['departamento_ids'] ?? ''));
            if ($userId <= 0) {
                throw new RuntimeException('Usuario inválido para asignar departamentos.');
            }

            $existsUserStmt = $conn->prepare('SELECT COUNT(*) FROM cr_usuarios WHERE id = :id');
            $existsUserStmt->execute([':id' => $userId]);
            if ((int) $existsUserStmt->fetchColumn() <= 0) {
                throw new RuntimeException('El usuario no existe.');
            }

            foreach ($departmentIds as $departmentId) {
                if (!isset($departmentCatalogMap[$departmentId])) {
                    throw new RuntimeException('Uno de los departamentos seleccionados no existe.');
                }
                if ($departmentCatalogMap[$departmentId] !== true) {
                    throw new RuntimeException('No puedes asignar departamentos inactivos.');
                }
            }

            $conn->beginTransaction();
            gpGestionSyncUserDepartments($conn, $userId, $departmentIds, $departmentCatalogMap);
            $conn->commit();

            gpGestionSetFlash('success', 'Departamentos del usuario actualizados correctamente.');
            gpGestionRedirect('usuarios.php');
        }

        if ($action === 'toggle_user_status') {
            $userId = (int) ($_POST['id'] ?? 0);

            if ($userId <= 0) {
                throw new RuntimeException('Usuario inválido para cambiar estado.');
            }

            if ($enabledStateId <= 0 || $disabledStateId <= 0 || $enabledStateId === $disabledStateId) {
                throw new RuntimeException('No se pudo resolver correctamente el estado habilitado/inhabilitado.');
            }

            $currentStateStmt = $conn->prepare('SELECT estado_id FROM cr_usuarios WHERE id = :id');
            $currentStateStmt->execute([':id' => $userId]);
            $currentStateId = (int) $currentStateStmt->fetchColumn();
            if ($currentStateId <= 0) {
                throw new RuntimeException('Usuario no encontrado para cambiar estado.');
            }

            $newStateId = $currentStateId === $enabledStateId ? $disabledStateId : $enabledStateId;
            $toggleStmt = $conn->prepare('UPDATE cr_usuarios SET estado_id = :estado_id WHERE id = :id');
            $toggleStmt->execute([
                ':estado_id' => $newStateId,
                ':id' => $userId,
            ]);

            gpGestionSetFlash('success', 'Estado del usuario actualizado.');
            gpGestionRedirect('usuarios.php');
        }

        if ($action === 'delete_user') {
            $userId = (int) ($_POST['id'] ?? 0);

            if ($userId <= 0) {
                throw new RuntimeException('Usuario inválido para eliminar.');
            }

            if ($userId === gpGestionUserId()) {
                throw new RuntimeException('No puedes eliminar tu propio usuario desde esta pantalla.');
            }

            if ($departmentsEnabled) {
                $conn->prepare('DELETE FROM cr_usuario_departamento WHERE usuario_id = :id')->execute([':id' => $userId]);
            }

            $deleteStmt = $conn->prepare('DELETE FROM cr_usuarios WHERE id = :id');
            $deleteStmt->execute([':id' => $userId]);

            gpGestionSetFlash('success', 'Usuario eliminado correctamente.');
            gpGestionRedirect('usuarios.php');
        }
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        gpGestionSetFlash('danger', $e->getMessage());
        gpGestionRedirect('usuarios.php');
    }
}

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(u.UserName LIKE :search OR u.nombre_completo LIKE :search OR u.correo_electronico LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($roleFilter > 0) {
    $where[] = 'u.rol_id = :rol_id';
    $params[':rol_id'] = $roleFilter;
}

if ($stateFilter > 0) {
    $where[] = 'u.estado_id = :estado_id';
    $params[':estado_id'] = $stateFilter;
}

if ($departmentsEnabled && $departmentFilter > 0) {
    $where[] = 'EXISTS (
        SELECT 1
        FROM cr_usuario_departamento udf
        WHERE udf.usuario_id = u.id
          AND udf.departamento_id = :departamento_id
    )';
    $params[':departamento_id'] = $departmentFilter;
}

$fromSql = '
    FROM cr_usuarios u
    INNER JOIN cr_roles r ON r.id = u.rol_id
    LEFT JOIN cr_estado_usuario eu ON eu.id = u.estado_id';

if ($where !== []) {
    $fromSql .= ' WHERE ' . implode(' AND ', $where);
}

$countSql = 'SELECT COUNT(*) ' . $fromSql;
$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRecords / $lines));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $lines;

$sql = '
    SELECT
        u.id,
        u.UserName,
        u.nombre_completo,
        u.correo_electronico,
        ' . ($usersHasLogoColumn ? 'u.url_logo' : 'CAST(NULL AS NVARCHAR(500)) AS url_logo') . ',
        u.rol_id,
        u.estado_id,
        r.nombre_rol,
        eu.estado ' . $fromSql . '
    ORDER BY u.nombre_completo ASC, u.UserName ASC
    OFFSET :offset ROWS FETCH NEXT :lines ROWS ONLY';

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue((string) $key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':lines', $lines, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$queryBase = [
    'q' => $search,
    'rol_id' => $roleFilter > 0 ? (string) $roleFilter : '',
    'estado_id' => $stateFilter > 0 ? (string) $stateFilter : '',
    'departamento_id' => $departmentFilter > 0 ? (string) $departmentFilter : '',
    'lineas' => (string) $lines,
];
$paginationItems = gpGestionBuildPaginationItems($currentPage, $totalPages);

$userDepartmentMap = [];
if ($departmentsEnabled && $users !== []) {
    $userIds = array_values(array_filter(array_map(static fn (array $user): int => (int) ($user['id'] ?? 0), $users)));
    if ($userIds !== []) {
        $placeholders = [];
        $departmentParams = [];
        foreach ($userIds as $index => $userId) {
            $paramName = ':user_' . $index;
            $placeholders[] = $paramName;
            $departmentParams[$paramName] = $userId;
        }

        $deptStmt = $conn->prepare('
            SELECT
                ud.usuario_id,
                ud.departamento_id,
                ud.es_principal,
                d.nombre,
                d.codigo
            FROM cr_usuario_departamento ud
            INNER JOIN cr_departamentos d ON d.id_departamento = ud.departamento_id
            WHERE ud.usuario_id IN (' . implode(', ', $placeholders) . ')
            ORDER BY ud.es_principal DESC, d.orden_visual ASC, d.nombre ASC
        ');
        $deptStmt->execute($departmentParams);

        foreach ($deptStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int) ($row['usuario_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $departmentId = (int) ($row['departamento_id'] ?? 0);
            $userDepartmentMap[$userId][] = [
                'id_departamento' => $departmentId,
                'nombre' => (string) ($row['nombre'] ?? ''),
                'codigo' => (string) ($row['codigo'] ?? ''),
                'es_principal' => (int) ($row['es_principal'] ?? 0) === 1,
                'activo' => $departmentCatalogMap[$departmentId] ?? true,
            ];
        }
    }
}

foreach ($users as &$user) {
    $departmentRows = $userDepartmentMap[(int) ($user['id'] ?? 0)] ?? [];
    $departmentIds = [];
    $departmentNames = [];
    foreach ($departmentRows as $departmentRow) {
        if (($departmentRow['activo'] ?? true) === true) {
            $departmentIds[] = (string) (int) ($departmentRow['id_departamento'] ?? 0);
        }
        $name = (string) ($departmentRow['nombre'] ?? '');
        if ($name !== '') {
            $departmentNames[] = $name;
        }
    }
    $user['departamento_ids'] = implode(';', $departmentIds);
    $user['departamentos'] = $departmentRows;
    $user['departamentos_texto'] = implode(', ', $departmentNames);
}
unset($user);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios</title>
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

        .badge-state {
            font-size: 12px;
            font-weight: 700;
            padding: 7px 10px;
        }

        .gp-department-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 999px;
            background: rgba(15, 118, 110, 0.12);
            color: #0f766e;
            font-size: 12px;
            font-weight: 700;
        }

        .gp-department-chip.gp-department-chip-secondary {
            background: rgba(11, 58, 110, 0.08);
            color: var(--color-primary);
        }

        .gp-modal-legend {
            font-size: 13px;
            color: var(--color-text-muted);
            margin-bottom: 16px;
        }

        .gp-user-profile-header {
            display: flex;
            align-items: center;
            gap: 16px;
            border: 1px solid var(--color-border);
            border-radius: 14px;
            background: #fff;
            padding: 14px;
        }

        .gp-user-profile-photo {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            border: 3px solid rgba(11, 58, 110, 0.12);
            object-fit: cover;
            background: #f3f5f8;
            flex: 0 0 84px;
        }

        .gp-user-profile-fallback {
            width: 84px;
            height: 84px;
            border-radius: 50%;
            border: 3px solid rgba(11, 58, 110, 0.12);
            background: linear-gradient(135deg, #0b3a6e, #0f766e);
            color: #fff;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
            flex: 0 0 84px;
        }

        .gp-user-profile-name {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            line-height: 1.2;
        }

        .gp-user-profile-username {
            color: var(--color-text-muted);
            font-size: 13px;
            margin-top: 2px;
        }

        .gp-user-profile-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin-top: 14px;
        }

        .gp-user-profile-item {
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: #fff;
            padding: 10px 12px;
        }

        .gp-user-profile-label {
            display: block;
            color: var(--color-text-muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 4px;
        }

        .gp-user-profile-value {
            display: block;
            color: var(--color-text);
            font-size: 15px;
            font-weight: 600;
            word-break: break-word;
        }

        .gp-required-mark {
            color: var(--color-danger);
            font-weight: 700;
        }

        .gp-optional-mark {
            color: var(--color-text-muted);
            font-weight: 500;
        }

        .gp-searchable-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 48px;
            padding: 10px 14px;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-sm);
            background: #fff;
            color: var(--color-text);
            font-weight: 400;
            box-shadow: none;
        }

        .gp-searchable-btn:hover,
        .gp-searchable-btn:focus,
        .gp-searchable-btn.show {
            background: #fff;
            color: var(--color-text);
            border-color: var(--color-primary);
            box-shadow: 0 0 0 0.15rem rgba(11, 58, 110, 0.2);
        }

        .gp-searchable-btn::after {
            margin-left: auto;
        }

        .gp-searchable-btn.dropdown-toggle {
            white-space: normal;
        }

        #assignDepartmentsModal .modal-dialog {
            max-width: 880px;
            margin-top: 1rem;
            margin-bottom: 1rem;
        }

        #assignDepartmentsModal .modal-content {
            max-height: min(68vh, 560px);
            display: flex;
            flex-direction: column;
            overflow: visible;
        }

        #assignDepartmentsModal .modal-header {
            flex: 0 0 auto;
            padding: 12px 16px;
        }

        #assignDepartmentsModal .modal-body {
            flex: 0 1 auto;
            overflow: visible;
            padding: 14px 16px;
        }

        #assignDepartmentsModal .modal-footer {
            flex: 0 0 auto;
            padding: 10px 16px;
        }

        #assignDepartmentsModal [data-gp-searchable-multiselect] {
            position: relative;
            z-index: 1065;
        }

        #assignDepartmentsModal .gp-assign-meta {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            border: 1px solid var(--color-border);
            border-radius: 12px;
            background: #fff;
            padding: 10px 12px;
        }

        #assignDepartmentsModal .gp-assign-meta-item {
            min-width: 0;
        }

        #assignDepartmentsModal .gp-assign-meta-label {
            display: block;
            color: var(--color-text-muted);
            font-size: 12px;
            line-height: 1.2;
            margin-bottom: 3px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        #assignDepartmentsModal .gp-assign-meta-value {
            display: block;
            color: var(--color-text);
            font-size: 17px;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #assignDepartmentsModal .gp-searchable-multiselect-btn {
            min-height: 42px;
            padding-top: 8px;
            padding-bottom: 8px;
        }

        #assignDepartmentsModal .gp-searchable-multiselect-menu {
            padding: 8px !important;
            max-height: min(42vh, 320px);
            overflow: hidden;
            z-index: 1080;
        }

        #assignDepartmentsModal [data-gp-ms-list] {
            max-height: min(30vh, 210px) !important;
        }

        #assignDepartmentsModal [data-gp-searchable-multiselect] .form-text {
            display: none;
        }

        #assignDepartmentsModal [data-gp-ms-selected] {
            margin-top: 10px;
            max-height: 170px;
            overflow-y: auto;
            padding-right: 4px;
        }

        @media (max-width: 767.98px) {
            .gp-table-meta {
                flex-direction: column;
                align-items: stretch;
            }

            .gp-user-profile-header {
                align-items: flex-start;
            }

            .gp-user-profile-grid {
                grid-template-columns: 1fr;
            }

            #assignDepartmentsModal .gp-assign-meta {
                grid-template-columns: 1fr;
            }

            #assignDepartmentsModal .modal-content {
                max-height: min(72vh, 620px);
            }

            #assignDepartmentsModal .gp-searchable-multiselect-menu {
                max-height: min(38vh, 300px);
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
            'title' => 'Usuarios',
            'back_url' => gpGestionBaseUrl('index.php'),
            'back_label' => 'Volver a Gestión',
            'help_text' => 'Administra cuentas, roles y estado operacional del acceso al portal, con asignación de departamentos desde acciones dedicadas por usuario.',
            'help_aria_label' => 'Información de la sección Usuarios',
        ]);
        ?>

        <?php if ($flash !== null): ?>
            <div class="alert <?php echo gpGestionFlashClass((string) ($flash['type'] ?? 'info')); ?>" role="alert">
                <?php echo gpGestionH($flash['message'] ?? ''); ?>
            </div>
        <?php endif; ?>

        <?php if (!$departmentsEnabled): ?>
            <div class="alert alert-warning" role="alert">
                La asignación múltiple por departamentos quedará disponible cuando ejecutes <code>sql/create_cr_departamentos.sql</code>.
            </div>
        <?php endif; ?>

        <form method="GET" class="row g-3" id="usersFilterForm" novalidate>
            <div class="col-md-4">
                <label for="q" class="form-label">Buscar</label>
                <input type="text" class="form-control" id="q" name="q" value="<?php echo gpGestionH($search); ?>" placeholder="Usuario, nombre o correo">
            </div>
            <div class="col-md-3">
                <label for="rol_id" class="form-label">Rol</label>
                <select class="form-select" id="rol_id" name="rol_id">
                    <option value="0">Todos</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?php echo gpGestionH($role['id']); ?>" <?php echo $roleFilter === (int) $role['id'] ? 'selected' : ''; ?>>
                            <?php echo gpGestionH($role['nombre_rol']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="estado_id" class="form-label">Estado</label>
                <select class="form-select" id="estado_id" name="estado_id">
                    <option value="0">Todos</option>
                    <?php foreach ($states as $state): ?>
                        <option value="<?php echo gpGestionH($state['id']); ?>" <?php echo $stateFilter === (int) $state['id'] ? 'selected' : ''; ?>>
                            <?php echo gpGestionH($state['estado']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="departamento_id" class="form-label">Departamento</label>
                <select class="form-select" id="departamento_id" name="departamento_id" <?php echo !$departmentsEnabled ? 'disabled' : ''; ?>>
                    <option value="0">Todos</option>
                    <?php foreach ($departments as $department): ?>
                        <?php if ((int) ($department['activo'] ?? 0) !== 1) { continue; } ?>
                        <option value="<?php echo gpGestionH($department['id_departamento']); ?>" <?php echo $departmentFilter === (int) $department['id_departamento'] ? 'selected' : ''; ?>>
                            <?php echo gpGestionH($department['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label for="lineas" class="form-label">Líneas</label>
                <select class="form-select" id="lineas" name="lineas">
                    <?php foreach ($allowedLines as $lineOption): ?>
                        <option value="<?php echo gpGestionH((string) $lineOption); ?>" <?php echo $lines === (int) $lineOption ? 'selected' : ''; ?>>
                            <?php echo gpGestionH((string) $lineOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

        </form>

        <?php
        gpRenderCrudTable([
            'meta_left' => static function () use ($users): void {
                echo '<strong>' . gpGestionH((string) count($users)) . ' usuario(s) en la vista actual</strong>';
            },
            'meta_right' => static function (): void {
                gpRenderCrudPrimaryAction([
                    'label' => 'Nuevo usuario',
                    'icon' => 'bi bi-person-plus-fill',
                    'attrs' => [
                        'type' => 'button',
                        'data-bs-toggle' => 'modal',
                        'data-bs-target' => '#createUserModal',
                    ],
                ]);
            },
            'meta_class' => 'gp-table-meta',
            'shell_class' => 'gp-table-shell',
            'headers' => ['Usuario', 'Nombre completo', 'Correo', 'Rol', 'Departamentos', 'Estado', 'Acciones'],
            'rows' => $users,
            'row_render' => static function (array $user, int $index, array $ctx): void {
                $isEnabled = (int) ($user['estado_id'] ?? 0) === (int) ($ctx['enabled_state_id'] ?? 1);
                $stateClass = $isEnabled ? 'text-bg-success' : 'text-bg-secondary';
                $roleName = mb_strtolower(trim((string) ($user['nombre_rol'] ?? '')), 'UTF-8');
                $isAdminRole = str_contains($roleName, 'admin');
                $departmentsEnabledContext = (bool) ($ctx['departments_enabled'] ?? false);
                ?>
                <tr>
                    <td><?php echo gpGestionH($user['UserName'] ?? ''); ?></td>
                    <td><?php echo gpGestionH($user['nombre_completo'] ?? ''); ?></td>
                    <td><?php echo gpGestionH($user['correo_electronico'] ?? ''); ?></td>
                    <td><?php echo gpGestionH($user['nombre_rol'] ?? ''); ?></td>
                    <td class="text-center">
                        <?php $userDepartments = is_array($user['departamentos'] ?? null) ? $user['departamentos'] : []; ?>
                        <?php if ($userDepartments === []): ?>
                            <span class="text-muted small">Sin asignación</span>
                        <?php else: ?>
                            <div class="d-flex flex-wrap gap-2 justify-content-center">
                                <?php foreach ($userDepartments as $department): ?>
                                    <span class="gp-department-chip <?php echo !($department['es_principal'] ?? false) ? 'gp-department-chip-secondary' : ''; ?>">
                                        <?php echo gpGestionH($department['nombre'] ?? ''); ?>
                                        <?php if ($department['es_principal'] ?? false): ?>
                                            <span>*</span>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $stateClass; ?> badge-state">
                            <?php echo gpGestionH($user['estado'] ?? ($isEnabled ? 'Habilitado' : 'Inhabilitado')); ?>
                        </span>
                    </td>
                    <td>
                        <div class="d-flex justify-content-center gap-2 flex-wrap">
                            <?php
                            $encodedUser = json_encode($user, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_TAG) ?: '{}';
                            $toggleButtonAttrs = [];
                            if ($isEnabled) {
                                $toggleButtonAttrs = [
                                    'data-gp-confirm' => true,
                                    'data-confirm-modal' => 'gp_toggle_user_status_modal',
                                    'data-confirm-title' => $isAdminRole ? 'Inhabilitar administrador' : 'Inhabilitar usuario',
                                    'data-confirm-message' => $isAdminRole ? 'Vas a inhabilitar una cuenta con rol administrador. Esta acción puede bloquear acceso crítico.' : 'Confirma que deseas inhabilitar este usuario.',
                                    'data-confirm-accept-label' => 'Inhabilitar',
                                    'data-confirm-accept-class' => 'btn btn-danger',
                                ];
                                if ($isAdminRole) {
                                    $toggleButtonAttrs['data-confirm-requires-pattern'] = '1';
                                    $toggleButtonAttrs['data-confirm-pattern'] = 'INHABILITAR';
                                    $toggleButtonAttrs['data-confirm-pattern-prompt'] = 'Escribe INHABILITAR para confirmar';
                                    $toggleButtonAttrs['data-confirm-requires-reason'] = '1';
                                }
                            }

                            $actions = [
                                [
                                    'type' => 'button',
                                    'label' => 'Ver ficha',
                                    'icon' => 'bi bi-person-vcard',
                                    'attrs' => [
                                        'type' => 'button',
                                        'data-bs-toggle' => 'modal',
                                        'data-bs-target' => '#viewUserModal',
                                        'data-user' => $encodedUser,
                                    ],
                                ],
                                [
                                    'type' => 'button',
                                    'label' => 'Editar usuario',
                                    'icon' => 'bi bi-pencil-square',
                                    'attrs' => [
                                        'type' => 'button',
                                        'data-bs-toggle' => 'modal',
                                        'data-bs-target' => '#editUserModal',
                                        'data-user' => $encodedUser,
                                    ],
                                ],
                            ];
                            if ($departmentsEnabledContext) {
                                $actions[] = [
                                    'type' => 'button',
                                    'label' => 'Asignar departamentos',
                                    'icon' => 'bi bi-building',
                                    'attrs' => [
                                        'type' => 'button',
                                        'data-bs-toggle' => 'modal',
                                        'data-bs-target' => '#assignDepartmentsModal',
                                        'data-user' => $encodedUser,
                                    ],
                                ];
                            }
                            $actions[] = ['type' => 'divider'];
                            $actions[] = [
                                'type' => 'form',
                                'label' => $isEnabled ? 'Inhabilitar usuario' : 'Habilitar usuario',
                                'icon' => $isEnabled ? 'bi bi-person-x' : 'bi bi-person-check',
                                'fields' => [
                                    'action' => 'toggle_user_status',
                                    'id' => (string) ($user['id'] ?? ''),
                                ],
                                'button_attrs' => $toggleButtonAttrs,
                            ];
                            $actions[] = [
                                'type' => 'form',
                                'label' => 'Eliminar usuario',
                                'icon' => 'bi bi-trash',
                                'form_attrs' => [
                                    'onsubmit' => "return confirm('¿Eliminar este usuario? La acción no se podrá deshacer.');",
                                ],
                                'fields' => [
                                    'action' => 'delete_user',
                                    'id' => (string) ($user['id'] ?? ''),
                                ],
                                'button_class' => 'dropdown-item text-danger',
                            ];

                            gpRenderCrudActionsMenu([
                                'items' => $actions,
                            ]);
                            ?>
                        </div>
                    </td>
                </tr>
                <?php
            },
            'row_context' => [
                'enabled_state_id' => $enabledStateId,
                'departments_enabled' => $departmentsEnabled,
            ],
            'empty_message' => 'No hay usuarios que coincidan con los filtros.',
            'empty_colspan' => 7,
            'pagination' => [
                'enabled' => true,
                'total_records' => $totalRecords,
                'current_page' => $currentPage,
                'total_pages' => $totalPages,
                'items' => $paginationItems,
                'aria_label' => 'Paginación de usuarios',
                'build_url' => static fn (int $page): string => gpGestionBuildQuery($queryBase, ['pagina' => $page]),
            ],
        ]);
        ?>
    </div>
</main>

<div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" class="modal-content" id="createUserForm" style="border-radius: var(--radius-lg); overflow: hidden;">
            <input type="hidden" name="action" value="create_user">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Nuevo usuario</h5>
                    <div class="small text-muted">Alta rápida de cuentas del portal con estructura base más clara.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" style="background: var(--color-bg);">
                <div class="gp-modal-legend">
                    <span class="gp-required-mark">*</span> Campo obligatorio
                    <span class="mx-1">|</span>
                    <span class="gp-optional-mark">(opcional)</span> Campo no obligatorio
                </div>
                <div class="row g-3 gp-assign-grid">
                    <div class="col-md-6">
                        <label class="form-label" for="create_username">Nombre de usuario <span class="gp-required-mark">*</span></label>
                        <input type="text" class="form-control" id="create_username" name="username" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="create_nombre_completo">Nombre completo <span class="gp-required-mark">*</span></label>
                        <input type="text" class="form-control" id="create_nombre_completo" name="nombre_completo" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="create_correo">Correo electrónico <span class="gp-required-mark">*</span></label>
                        <input type="email" class="form-control" id="create_correo" name="correo_electronico" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="create_password">Contraseña <span class="gp-required-mark">*</span></label>
                        <input type="password" class="form-control" id="create_password" name="password" required minlength="8">
                        <div class="form-text">Ingresa la contraseña inicial del usuario.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="create_password_confirm">Confirmar contraseña <span class="gp-required-mark">*</span></label>
                        <input type="password" class="form-control" id="create_password_confirm" name="password_confirm" required minlength="8">
                        <div class="form-text">Debe coincidir exactamente con la contraseña anterior.</div>
                    </div>
                    <div class="col-md-6">
                        <?php
                        gpRenderSearchableSelectField([
                            'wrapper_class' => 'col-12',
                            'label' => 'Rol *',
                            'input_name' => 'rol_id',
                            'input_id' => 'create_rol',
                            'picker_id' => 'create_rol_picker',
                            'button_id' => 'create_rol_btn',
                            'filter_id' => 'create_rol_filter',
                            'list_id' => 'create_rol_list',
                            'error_id' => 'create_rol_error',
                            'error_message' => 'Debes seleccionar un rol.',
                            'button_placeholder' => 'Selecciona un rol',
                            'filter_placeholder' => 'Buscar rol...',
                            'button_class' => 'btn gp-searchable-btn dropdown-toggle w-100 text-start',
                            'required' => true,
                            'options' => $roleSelectOptions,
                        ]);
                        ?>
                    </div>
                    <div class="col-md-6">
                        <?php
                        gpRenderSearchableSelectField([
                            'wrapper_class' => 'col-12',
                            'label' => 'Estado inicial (opcional)',
                            'input_name' => 'estado_id',
                            'input_id' => 'create_estado',
                            'picker_id' => 'create_estado_picker',
                            'button_id' => 'create_estado_btn',
                            'filter_id' => 'create_estado_filter',
                            'list_id' => 'create_estado_list',
                            'error_id' => 'create_estado_error',
                            'button_placeholder' => 'Usar estado por defecto',
                            'filter_placeholder' => 'Buscar estado...',
                            'button_class' => 'btn gp-searchable-btn dropdown-toggle w-100 text-start',
                            'options' => $stateSelectOptions,
                        ]);
                        ?>
                        <div class="form-text">Si no eliges uno, el sistema crea el usuario como habilitado.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear usuario</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" class="modal-content" id="editUserForm" style="border-radius: var(--radius-lg); overflow: hidden;">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="id" id="edit_id">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Editar usuario</h5>
                    <div class="small text-muted">Actualiza datos base, permisos operativos por rol y estado de la cuenta.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" style="background: var(--color-bg);">
                <div class="gp-modal-legend">
                    <span class="gp-required-mark">*</span> Campo obligatorio
                    <span class="mx-1">|</span>
                    <span class="gp-optional-mark">(opcional)</span> Campo no obligatorio
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="edit_username">Nombre de usuario</label>
                        <input type="text" class="form-control" id="edit_username" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="edit_nombre_completo">Nombre completo <span class="gp-required-mark">*</span></label>
                        <input type="text" class="form-control" id="edit_nombre_completo" name="nombre_completo" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="edit_correo">Correo electrónico <span class="gp-required-mark">*</span></label>
                        <input type="email" class="form-control" id="edit_correo" name="correo_electronico" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="edit_url_logo">URL foto/logo <span class="gp-optional-mark">(opcional)</span></label>
                        <input
                            type="url"
                            class="form-control"
                            id="edit_url_logo"
                            name="url_logo"
                            placeholder="https://.../foto.jpg"
                            maxlength="500"
                        >
                        <div class="form-text">Si existe, se muestra en la ficha del usuario.</div>
                    </div>
                    <div class="col-md-6">
                        <?php
                        gpRenderSearchableSelectField([
                            'wrapper_class' => 'col-12',
                            'label' => 'Rol *',
                            'input_name' => 'rol_id',
                            'input_id' => 'edit_rol',
                            'picker_id' => 'edit_rol_picker',
                            'button_id' => 'edit_rol_btn',
                            'filter_id' => 'edit_rol_filter',
                            'list_id' => 'edit_rol_list',
                            'error_id' => 'edit_rol_error',
                            'error_message' => 'Debes seleccionar un rol.',
                            'button_placeholder' => 'Selecciona un rol',
                            'filter_placeholder' => 'Buscar rol...',
                            'button_class' => 'btn gp-searchable-btn dropdown-toggle w-100 text-start',
                            'required' => true,
                            'options' => $roleSelectOptions,
                        ]);
                        ?>
                    </div>
                    <div class="col-md-6">
                        <?php
                        gpRenderSearchableSelectField([
                            'wrapper_class' => 'col-12',
                            'label' => 'Estado *',
                            'input_name' => 'estado_id',
                            'input_id' => 'edit_estado',
                            'picker_id' => 'edit_estado_picker',
                            'button_id' => 'edit_estado_btn',
                            'filter_id' => 'edit_estado_filter',
                            'list_id' => 'edit_estado_list',
                            'error_id' => 'edit_estado_error',
                            'error_message' => 'Debes seleccionar un estado.',
                            'button_placeholder' => 'Selecciona un estado',
                            'filter_placeholder' => 'Buscar estado...',
                            'button_class' => 'btn gp-searchable-btn dropdown-toggle w-100 text-start',
                            'required' => true,
                            'options' => array_values(array_filter(
                                $stateSelectOptions,
                                static fn (array $option): bool => ($option['value'] ?? '') !== ''
                            )),
                        ]);
                        ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="edit_password">Nueva contraseña <span class="gp-optional-mark">(opcional)</span></label>
                        <input type="password" class="form-control" id="edit_password" name="password" placeholder="Dejar vacío para mantener la actual" minlength="8">
                        <div class="form-text">Solo completa este campo si quieres reemplazar la contraseña actual.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="edit_password_confirm">Confirmar nueva contraseña <span class="gp-optional-mark">(opcional)</span></label>
                        <input type="password" class="form-control" id="edit_password_confirm" name="password_confirm" placeholder="Repite la nueva contraseña" minlength="8">
                        <div class="form-text">Si cambias contraseña, debes confirmarla aquí.</div>
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

<div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: var(--radius-lg); overflow: hidden;">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Ficha de usuario</h5>
                    <div class="small text-muted">Resumen de datos del usuario seleccionado.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" style="background: var(--color-bg);">
                <div class="gp-user-profile-header">
                    <img id="profile_user_photo" class="gp-user-profile-photo" src="" alt="Foto del usuario">
                    <div id="profile_user_fallback" class="gp-user-profile-fallback" aria-hidden="true">?</div>
                    <div class="flex-grow-1 min-w-0">
                        <h6 id="profile_user_name" class="gp-user-profile-name">-</h6>
                        <div id="profile_user_username" class="gp-user-profile-username">@-</div>
                    </div>
                </div>
                <div class="gp-user-profile-grid">
                    <div class="gp-user-profile-item">
                        <span class="gp-user-profile-label">Correo electrónico</span>
                        <span id="profile_user_email" class="gp-user-profile-value">-</span>
                    </div>
                    <div class="gp-user-profile-item">
                        <span class="gp-user-profile-label">Rol</span>
                        <span id="profile_user_role" class="gp-user-profile-value">-</span>
                    </div>
                    <div class="gp-user-profile-item">
                        <span class="gp-user-profile-label">Estado</span>
                        <span id="profile_user_state" class="gp-user-profile-value">-</span>
                    </div>
                    <div class="gp-user-profile-item">
                        <span class="gp-user-profile-label">Departamentos</span>
                        <span id="profile_user_departments" class="gp-user-profile-value">Sin asignación</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<?php if ($departmentsEnabled): ?>
<div class="modal fade" id="assignDepartmentsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content" id="assignDepartmentsForm" style="border-radius: var(--radius-lg);">
            <input type="hidden" name="action" value="assign_user_departments">
            <input type="hidden" name="id" id="assign_departments_user_id">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0">Asignar departamentos</h5>
                    <div class="small text-muted">Vincula uno o varios departamentos al usuario seleccionado.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" style="background: var(--color-bg);">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="gp-assign-meta">
                            <div class="gp-assign-meta-item">
                                <span class="gp-assign-meta-label">Usuario</span>
                                <span class="gp-assign-meta-value" id="assign_departments_username">-</span>
                            </div>
                            <div class="gp-assign-meta-item">
                                <span class="gp-assign-meta-label">Nombre completo</span>
                                <span class="gp-assign-meta-value" id="assign_departments_fullname">-</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <?php
                        gpRenderSearchableMultiSelectField([
                            'wrapper_class' => 'col-12',
                            'label' => 'Departamentos',
                            'input_name' => 'departamento_ids',
                            'input_id' => 'assign_departamentos',
                            'picker_id' => 'assign_departamentos_picker',
                            'button_id' => 'assign_departamentos_btn',
                            'search_id' => 'assign_departamentos_search',
                            'list_id' => 'assign_departamentos_list',
                            'selected_container_id' => 'assign_departamentos_selected',
                            'button_placeholder' => 'Selecciona uno o varios departamentos',
                            'search_placeholder' => 'Buscar departamento...',
                            'empty_selected_message' => 'Sin departamentos seleccionados.',
                            'hide_selected_options' => true,
                            'close_on_select' => true,
                            'selected_view' => 'table',
                            'options' => $departmentSelectOptions,
                        ]);
                        ?>
                        <div class="form-text">El primer departamento seleccionado queda marcado como principal.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar departamentos</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
gpRenderConfirmActionModal([
    'id' => 'gp_toggle_user_status_modal',
    'title' => 'Confirmar cambio de estado',
    'message' => 'Deseas continuar con el cambio de estado?',
    'cancel_label' => 'Cancelar',
    'accept_label' => 'Confirmar',
    'accept_class' => 'btn btn-danger',
]);
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php gpRenderSearchableSelectAssets(); ?>
<?php gpRenderSearchableMultiSelectAssets(); ?>
<script>
function validatePasswordPair(passwordId, confirmId, options) {
    var passwordInput = document.getElementById(passwordId);
    var confirmInput = document.getElementById(confirmId);
    if (!passwordInput || !confirmInput) {
        return true;
    }

    var passwordValue = passwordInput.value || '';
    var confirmValue = confirmInput.value || '';
    var allowEmpty = options && options.allowEmpty === true;

    if (allowEmpty && passwordValue === '' && confirmValue === '') {
        passwordInput.setCustomValidity('');
        confirmInput.setCustomValidity('');
        return true;
    }

    if (passwordValue === '' || confirmValue === '') {
        var message = 'Debes completar ambos campos de contraseña.';
        passwordInput.setCustomValidity(message);
        confirmInput.setCustomValidity(message);
        return false;
    }

    if (passwordValue !== confirmValue) {
        var mismatchMessage = 'Las contraseñas no coinciden.';
        passwordInput.setCustomValidity(mismatchMessage);
        confirmInput.setCustomValidity(mismatchMessage);
        return false;
    }

    passwordInput.setCustomValidity('');
    confirmInput.setCustomValidity('');
    return true;
}

function bindPasswordPair(formId, passwordId, confirmId, options) {
    var form = document.getElementById(formId);
    var passwordInput = document.getElementById(passwordId);
    var confirmInput = document.getElementById(confirmId);
    if (!form || !passwordInput || !confirmInput) {
        return;
    }

    var validate = function () {
        return validatePasswordPair(passwordId, confirmId, options || {});
    };

    passwordInput.addEventListener('input', validate);
    confirmInput.addEventListener('input', validate);
    form.addEventListener('submit', function (event) {
        if (!validate()) {
            event.preventDefault();
            if (passwordInput.validationMessage) {
                confirmInput.reportValidity();
            }
        }
    });
}

function setSearchableSelectValue(inputId, value) {
    var hiddenInput = document.getElementById(inputId);
    if (!hiddenInput) {
        return;
    }

    hiddenInput.value = value || '';

    var root = hiddenInput.closest('[data-gp-searchable-select]');
    if (!root) {
        return;
    }

    var button = root.querySelector('[data-searchable-btn]');
    var filter = root.querySelector('[data-searchable-filter]');
    var options = root.querySelectorAll('.js-searchable-option');
    var errorTargetId = root.getAttribute('data-error-target') || '';
    var errorTarget = errorTargetId ? document.getElementById(errorTargetId) : null;
    var selectedLabel = button ? button.getAttribute('data-placeholder') || 'Selecciona...' : 'Selecciona...';

    options.forEach(function (option) {
        option.classList.remove('active', 'd-none');
        if ((option.getAttribute('data-value') || '') === String(value || '')) {
            selectedLabel = option.getAttribute('data-label') || selectedLabel;
        }
    });

    if (button) {
        button.textContent = selectedLabel;
        button.title = selectedLabel;
        button.classList.remove('is-invalid');
    }

    if (filter) {
        filter.value = '';
    }

    if (errorTarget) {
        errorTarget.classList.add('d-none');
    }
}

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

function setFieldValueOrText(elementId, value) {
    var element = document.getElementById(elementId);
    if (!element) {
        return;
    }
    var nextValue = value || '';
    if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement || element instanceof HTMLSelectElement) {
        element.value = nextValue;
        return;
    }
    element.textContent = nextValue === '' ? '-' : nextValue;
}

function getUserInitial(nameValue, usernameValue) {
    var source = (nameValue || '').trim();
    if (source === '') {
        source = (usernameValue || '').trim();
    }
    if (source === '') {
        return '?';
    }
    return source.charAt(0).toUpperCase();
}

function setUserProfileImage(urlLogo, fullName, username) {
    var photoElement = document.getElementById('profile_user_photo');
    var fallbackElement = document.getElementById('profile_user_fallback');
    if (!photoElement || !fallbackElement) {
        return;
    }

    var cleanUrl = (urlLogo || '').trim();
    var initial = getUserInitial(fullName, username);
    fallbackElement.textContent = initial;

    if (cleanUrl === '') {
        photoElement.style.display = 'none';
        fallbackElement.style.display = 'inline-flex';
        photoElement.removeAttribute('src');
        return;
    }

    photoElement.style.display = 'block';
    fallbackElement.style.display = 'none';
    photoElement.src = cleanUrl;
    photoElement.onerror = function () {
        photoElement.style.display = 'none';
        fallbackElement.style.display = 'inline-flex';
    };
}

function bindAutoFilterForm(formId) {
    var form = document.getElementById(formId);
    if (!form) {
        return;
    }

    var textInput = form.querySelector('#q');
    var selectInputs = form.querySelectorAll('select');
    var debounceTimer = null;

    var submitForm = function () {
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        form.requestSubmit();
    };

    if (textInput) {
        textInput.addEventListener('input', function () {
            if (debounceTimer !== null) {
                clearTimeout(debounceTimer);
            }
            debounceTimer = window.setTimeout(submitForm, 350);
        });

        textInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitForm();
            }
        });
    }

    selectInputs.forEach(function (selectInput) {
        selectInput.addEventListener('change', submitForm);
    });
}

document.getElementById('editUserModal').addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var payload = button ? button.getAttribute('data-user') : null;
    if (!payload) {
        return;
    }

    var user = JSON.parse(payload);
    document.getElementById('edit_id').value = user.id || '';
    document.getElementById('edit_username').value = user.UserName || '';
    document.getElementById('edit_nombre_completo').value = user.nombre_completo || '';
    document.getElementById('edit_correo').value = user.correo_electronico || '';
    document.getElementById('edit_url_logo').value = user.url_logo || '';
    document.getElementById('edit_password').value = '';
    document.getElementById('edit_password_confirm').value = '';
    setSearchableSelectValue('edit_rol', user.rol_id || '');
    setSearchableSelectValue('edit_estado', user.estado_id || '');
    validatePasswordPair('edit_password', 'edit_password_confirm', { allowEmpty: true });
});

document.getElementById('viewUserModal').addEventListener('show.bs.modal', function (event) {
    var button = event.relatedTarget;
    var payload = button ? button.getAttribute('data-user') : null;
    if (!payload) {
        return;
    }

    var user = JSON.parse(payload);
    var fullName = user.nombre_completo || '';
    var username = user.UserName || '';
    var departmentsText = user.departamentos_texto || '';

    setFieldValueOrText('profile_user_name', fullName);
    setFieldValueOrText('profile_user_username', username !== '' ? '@' + username : '-');
    setFieldValueOrText('profile_user_email', user.correo_electronico || '');
    setFieldValueOrText('profile_user_role', user.nombre_rol || '');
    setFieldValueOrText('profile_user_state', user.estado || '');
    setFieldValueOrText('profile_user_departments', departmentsText !== '' ? departmentsText : 'Sin asignación');
    setUserProfileImage(user.url_logo || '', fullName, username);
});

var assignDepartmentsModal = document.getElementById('assignDepartmentsModal');
if (assignDepartmentsModal) {
    assignDepartmentsModal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        var payload = button ? button.getAttribute('data-user') : null;
        if (!payload) {
            return;
        }

        var user = JSON.parse(payload);
        document.getElementById('assign_departments_user_id').value = user.id || '';
        setFieldValueOrText('assign_departments_username', user.UserName || '');
        setFieldValueOrText('assign_departments_fullname', user.nombre_completo || '');
        setSearchableMultiSelectValue('assign_departamentos_picker', user.departamento_ids || '');
    });
}

bindPasswordPair('createUserForm', 'create_password', 'create_password_confirm', { allowEmpty: false });
bindPasswordPair('editUserForm', 'edit_password', 'edit_password_confirm', { allowEmpty: true });
bindAutoFilterForm('usersFilterForm');
</script>
<?php include __DIR__ . '/../../templates/footer.php'; ?>
</body>
</html>
