<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/db.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo 'OK: ' . $message . PHP_EOL;
};

$roleName = 'portalgp_runtime_role';
$runtimeUser = 'portalgp_runtime';
$immutableDml = [
    'msp_schema_migrations' => ['INSERT', 'UPDATE', 'DELETE'],
    'msp_documentos_cobro_versiones' => ['UPDATE', 'DELETE'],
    'msp_cierre_mensual_eliminaciones' => ['UPDATE', 'DELETE'],
    'msp_cierre_mensual_transiciones' => ['UPDATE', 'DELETE'],
    'msp_saldo_favor_auditoria_historica' => ['INSERT', 'UPDATE', 'DELETE'],
];

$objects = $conn->query(
    "SELECT o.object_id,s.name AS schema_name,o.name AS object_name,o.type,o.type_desc
     FROM sys.objects o
     INNER JOIN sys.schemas s ON s.schema_id=o.schema_id
     WHERE s.name=N'dbo'
       AND o.is_ms_shipped=0
       AND o.type IN ('U','V','P','FN','IF','TF')
     ORDER BY o.name"
)->fetchAll(PDO::FETCH_ASSOC);

$targets = [];
$unclassified = [];
foreach ($objects as $object) {
    $name = (string) $object['object_name'];
    if (preg_match('/^(?:cr_|msp_|ct_|sp_ct_)/', $name) === 1) {
        $targets[(int) $object['object_id']] = $object;
    } else {
        $unclassified[] = $name;
    }
}

$assert($targets !== [], 'existen objetos SQL de aplicación clasificados');
$assert($unclassified === [], 'no existen objetos dbo de aplicación fuera del inventario protegido');

$expectedGrants = [];
$expectedDenies = [];
$put = static function (array &$set, int $objectId, string $permission): void {
    $set[$objectId . ':0:' . $permission] = true;
};

foreach ($targets as $objectId => $object) {
    $name = (string) $object['object_name'];
    $type = trim((string) $object['type']);

    if (in_array($type, ['U', 'V', 'FN', 'IF', 'TF'], true)) {
        $put($expectedGrants, $objectId, 'SELECT');
    }
    if ($type === 'U') {
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $permission) {
            if (in_array($permission, $immutableDml[$name] ?? [], true)) {
                $put($expectedDenies, $objectId, $permission);
            } else {
                $put($expectedGrants, $objectId, $permission);
            }
        }
    }
    if ($type === 'P') {
        $put($expectedGrants, $objectId, 'EXECUTE');
    }
    if (in_array($type, ['FN', 'IF', 'TF'], true)) {
        $put($expectedGrants, $objectId, 'REFERENCES');
    }
}

$permissionStmt = $conn->prepare(
    "SELECT p.major_id,p.minor_id,p.permission_name,p.state
     FROM sys.database_permissions p
     INNER JOIN sys.database_principals d ON d.principal_id=p.grantee_principal_id
     WHERE d.name=:role AND p.class=1"
);
$permissionStmt->execute([':role' => $roleName]);
$actualGrants = [];
$actualDenies = [];
foreach ($permissionStmt->fetchAll(PDO::FETCH_ASSOC) as $permission) {
    $key = (int) $permission['major_id'] . ':'
        . (int) $permission['minor_id'] . ':'
        . (string) $permission['permission_name'];
    if (in_array((string) $permission['state'], ['G', 'W'], true)) {
        $actualGrants[$key] = true;
    } elseif ((string) $permission['state'] === 'D') {
        $actualDenies[$key] = true;
    }
}

$missingGrants = array_diff_key($expectedGrants, $actualGrants);
$unexpectedGrants = array_diff_key($actualGrants, $expectedGrants);
$missingDenies = array_diff_key($expectedDenies, $actualDenies);
$unexpectedDenies = array_diff_key($actualDenies, $expectedDenies);

echo sprintf(
    "Comparación: %d GRANT esperados/%d actuales; %d DENY esperados/%d actuales.%s",
    count($expectedGrants),
    count($actualGrants),
    count($expectedDenies),
    count($actualDenies),
    PHP_EOL
);

$describePermissions = static function (array $permissions) use ($targets): string {
    $labels = [];
    foreach (array_keys($permissions) as $key) {
        [$objectId, $minorId, $permission] = explode(':', $key, 3);
        $object = $targets[(int) $objectId] ?? null;
        $name = is_array($object)
            ? (string) $object['schema_name'] . '.' . (string) $object['object_name']
            : 'object_id=' . $objectId;
        $labels[] = $name . ($minorId !== '0' ? '.column=' . $minorId : '') . ':' . $permission;
    }

    return implode(', ', array_slice($labels, 0, 20));
};

$assert(
    $missingGrants === [],
    'ningún objeto carece de una concesión runtime esperada: ' . $describePermissions($missingGrants)
);
$assert(
    $unexpectedGrants === [],
    'runtime no tiene concesiones de objeto inesperadas: ' . $describePermissions($unexpectedGrants)
);
$assert(
    $missingDenies === [],
    'las tablas inmutables conservan todos sus DENY: ' . $describePermissions($missingDenies)
);
$assert(
    $unexpectedDenies === [],
    'runtime no tiene denegaciones de objeto fuera de la política: ' . $describePermissions($unexpectedDenies)
);

$schemaPermissionsStmt = $conn->prepare(
    "SELECT COUNT(*)
     FROM sys.database_permissions p
     INNER JOIN sys.database_principals d ON d.principal_id=p.grantee_principal_id
     WHERE d.name=:role
       AND p.class=3
       AND p.state IN ('G','W')
       AND p.permission_name IN ('SELECT','INSERT','UPDATE','DELETE','EXECUTE')"
);
$schemaPermissionsStmt->execute([':role' => $roleName]);
$assert((int) $schemaPermissionsStmt->fetchColumn() === 0, 'runtime no tiene DML o EXECUTE sobre todo un esquema');

$databasePermissionStmt = $conn->prepare(
    "SELECT p.permission_name,p.state
     FROM sys.database_permissions p
     INNER JOIN sys.database_principals d ON d.principal_id=p.grantee_principal_id
     WHERE d.name=:principal AND p.class=0
     ORDER BY p.permission_name"
);
$databasePermissionStmt->execute([':principal' => $roleName]);
$roleDatabasePermissions = [];
foreach ($databasePermissionStmt->fetchAll(PDO::FETCH_ASSOC) as $permission) {
    $roleDatabasePermissions[(string) $permission['permission_name']] = (string) $permission['state'];
}
$assert(
    $roleDatabasePermissions === ['CONNECT' => 'G', 'VIEW DEFINITION' => 'G'],
    'el rol conserva solamente CONNECT y VIEW DEFINITION a nivel de base'
);

$databasePermissionStmt->execute([':principal' => $runtimeUser]);
$userDatabasePermissions = [];
foreach ($databasePermissionStmt->fetchAll(PDO::FETCH_ASSOC) as $permission) {
    $userDatabasePermissions[(string) $permission['permission_name']] = (string) $permission['state'];
}
$assert($userDatabasePermissions === ['CONNECT' => 'G'], 'el usuario runtime solamente tiene CONNECT directo');

$membershipStmt = $conn->prepare(
    "SELECT r.name
     FROM sys.database_role_members drm
     INNER JOIN sys.database_principals r ON r.principal_id=drm.role_principal_id
     INNER JOIN sys.database_principals m ON m.principal_id=drm.member_principal_id
     WHERE m.name=:runtime
     ORDER BY r.name"
);
$membershipStmt->execute([':runtime' => $runtimeUser]);
$memberships = array_map('strval', $membershipStmt->fetchAll(PDO::FETCH_COLUMN));
$assert($memberships === [$roleName], 'runtime pertenece únicamente al rol técnico esperado');

$identity = $conn->query(
    "SELECT SUSER_SNAME() AS login_name,
            IS_SRVROLEMEMBER('sysadmin') AS sysadmin,
            IS_MEMBER('db_owner') AS db_owner,
            IS_MEMBER('db_datareader') AS db_datareader,
            IS_MEMBER('db_datawriter') AS db_datawriter"
)->fetch(PDO::FETCH_ASSOC);
$assert((string) ($identity['login_name'] ?? '') === $runtimeUser, 'la conexión de la aplicación usa portalgp_runtime');
$assert(
    (int) ($identity['sysadmin'] ?? 1) === 0
    && (int) ($identity['db_owner'] ?? 1) === 0
    && (int) ($identity['db_datareader'] ?? 1) === 0
    && (int) ($identity['db_datawriter'] ?? 1) === 0,
    'runtime no pertenece a roles fijos privilegiados'
);

$admin = $conn->query(
    "SELECT TOP(1) u.id,u.estado_id,u.rol_id,u.security_version,r.nombre_rol,
        (SELECT COUNT(DISTINCT rp.permiso_id)
         FROM dbo.cr_rol_permisos rp
         WHERE rp.rol_id=u.rol_id) AS permisos
     FROM dbo.cr_usuarios u
     INNER JOIN dbo.cr_roles r ON r.id=u.rol_id
     WHERE u.UserName=N'admin_2'"
)->fetch(PDO::FETCH_ASSOC);
$assert(is_array($admin) && (int) $admin['id'] === 1030, 'admin_2 conserva su registro original');
$assert(
    (int) ($admin['estado_id'] ?? 0) === 1
    && (int) ($admin['rol_id'] ?? 0) === 1
    && (string) ($admin['nombre_rol'] ?? '') === 'Administrador',
    'admin_2 continúa activo como Administrador'
);
$assert(
    (int) ($admin['security_version'] ?? -1) === 0
    && (int) ($admin['permisos'] ?? 0) >= 22,
    'admin_2 conserva su versión de seguridad y sus permisos'
);

$typeCounts = array_count_values(array_map(
    static fn(array $object): string => trim((string) $object['type']),
    $targets
));
echo sprintf(
    "Inventario: %d objetos, %d tablas, %d vistas, %d procedimientos, %d funciones, %d GRANT y %d DENY.%s",
    count($targets),
    (int) ($typeCounts['U'] ?? 0),
    (int) ($typeCounts['V'] ?? 0),
    (int) ($typeCounts['P'] ?? 0),
    (int) (($typeCounts['FN'] ?? 0) + ($typeCounts['IF'] ?? 0) + ($typeCounts['TF'] ?? 0)),
    count($actualGrants),
    count($actualDenies),
    PHP_EOL
);
echo "PASS security_runtime_objects ({$checks}/{$checks} comprobaciones)." . PHP_EOL;
