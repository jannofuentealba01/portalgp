<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Este diagnóstico solo está disponible desde la consola del servidor.');
}

require_once dirname(__DIR__) . '/bootstrap.php';

const MSP_SCHEMA_OPTIONAL_PATCHES = [
    'patch_sql_agent_envio_lotes_job.sql',
    'patch_seguridad_runtime_objetos.sql',
];

/** @return list<string> */
function mspSchemaMigratorFiles(string $path): array
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('No se pudo leer el migrador incremental.');
    }
    preg_match_all('~^\s*:r\s+(.+?\.sql)\s*$~mi', $sql, $matches);
    $files = [];
    foreach ($matches[1] ?? [] as $includedPath) {
        $file = preg_replace('~^.*[\\\\/]~', '', trim((string) $includedPath));
        if (is_string($file) && preg_match('/^[A-Za-z0-9_.-]+\.sql$/', $file) === 1) {
            $files[] = $file;
        }
    }

    return array_values(array_unique($files));
}

/** @return list<string> */
function mspSchemaOperationalPatches(string $root): array
{
    $paths = glob($root . DIRECTORY_SEPARATOR . 'patch_*.sql') ?: [];
    $files = array_map('basename', $paths);
    $files = array_values(array_diff($files, MSP_SCHEMA_OPTIONAL_PATCHES));
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    return $files;
}

/** @return array<string,string> */
function mspSchemaExpectedObjects(string $sql): array
{
    $patterns = [
        'TABLE' => '/\bCREATE\s+(?:OR\s+ALTER\s+)?TABLE\s+(?:\[?dbo\]?\.)\[?([A-Za-z0-9_]+)\]?/i',
        'VIEW' => '/\bCREATE\s+(?:OR\s+ALTER\s+)?VIEW\s+(?:\[?dbo\]?\.)\[?([A-Za-z0-9_]+)\]?/i',
        'PROCEDURE' => '/\bCREATE\s+(?:OR\s+ALTER\s+)?PROC(?:EDURE)?\s+(?:\[?dbo\]?\.)\[?([A-Za-z0-9_]+)\]?/i',
        'FUNCTION' => '/\bCREATE\s+(?:OR\s+ALTER\s+)?FUNCTION\s+(?:\[?dbo\]?\.)\[?([A-Za-z0-9_]+)\]?/i',
        'TRIGGER' => '/\bCREATE\s+(?:OR\s+ALTER\s+)?TRIGGER\s+(?:\[?dbo\]?\.)\[?([A-Za-z0-9_]+)\]?/i',
    ];
    $objects = [];
    foreach ($patterns as $type => $pattern) {
        preg_match_all($pattern, $sql, $matches);
        foreach ($matches[1] ?? [] as $name) {
            $objects[(string) $name] = $type;
        }
    }
    ksort($objects, SORT_NATURAL | SORT_FLAG_CASE);

    return $objects;
}

/** @return array<string,array{table:string,column:string}> */
function mspSchemaExpectedColumns(string $sql): array
{
    preg_match_all(
        "/COL_LENGTH\s*\(\s*N?'dbo\.([A-Za-z0-9_]+)'\s*,\s*N?'([A-Za-z0-9_]+)'\s*\)/i",
        $sql,
        $matches,
        PREG_SET_ORDER
    );
    $columns = [];
    foreach ($matches as $match) {
        $key = strtolower($match[1] . '.' . $match[2]);
        $columns[$key] = ['table' => $match[1], 'column' => $match[2]];
    }
    ksort($columns, SORT_NATURAL | SORT_FLAG_CASE);

    return $columns;
}

/** @return array<string,array{table:string,index:string}> */
function mspSchemaExpectedIndexes(string $sql): array
{
    preg_match_all(
        '/\bCREATE\s+(?:UNIQUE\s+)?(?:(?:NON)?CLUSTERED\s+)?INDEX\s+\[?([A-Za-z0-9_]+)\]?\s+ON\s+(?:\[?dbo\]?\.)\[?([A-Za-z0-9_]+)\]?/i',
        $sql,
        $matches,
        PREG_SET_ORDER
    );
    $indexes = [];
    foreach ($matches as $match) {
        $key = strtolower($match[2] . '.' . $match[1]);
        $indexes[$key] = ['table' => $match[2], 'index' => $match[1]];
    }
    ksort($indexes, SORT_NATURAL | SORT_FLAG_CASE);

    return $indexes;
}

/** @return array<string,true> */
function mspSchemaSupersededIndexes(): array
{
    return [
        'msp_liquidaciones_finales.ux_msp_liq_cierre_contrato' => true,
    ];
}

$root = __DIR__;
$migratorPath = $root . DIRECTORY_SEPARATOR . 'core_msp_migrate.sql';
$record = PHP_SAPI === 'cli' && in_array('--registrar', $argv ?? [], true);
$errors = [];
$warnings = [];

try {
    $migratorFiles = mspSchemaMigratorFiles($migratorPath);
    $operationalPatches = mspSchemaOperationalPatches($root);
    $notIncluded = array_values(array_diff($operationalPatches, $migratorFiles));
    if ($notIncluded !== []) {
        $errors[] = 'Parches operativos fuera del migrador: ' . implode(', ', $notIncluded);
    }

    foreach ($migratorFiles as $file) {
        if (!is_file($root . DIRECTORY_SEPARATOR . $file)) {
            $errors[] = 'Falta archivo incluido por el migrador: ' . $file;
        }
    }

    $databaseInfo = $conn->query(
        "SELECT @@SERVERNAME AS servidor, DB_NAME() AS base_datos, SUSER_SNAME() AS usuario"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $registryExists = (int) $conn->query(
        "SELECT COUNT(*) FROM sys.tables WHERE object_id=OBJECT_ID(N'dbo.msp_schema_migrations')"
    )->fetchColumn() > 0;

    if (!$registryExists) {
        $errors[] = 'Falta dbo.msp_schema_migrations; ejecuta core_msp_migrate.sql.';
    } elseif ($record) {
        $update = $conn->prepare(
            "UPDATE dbo.msp_schema_migrations
                SET sha256=:sha,applied_at=SYSDATETIME(),applied_by=SUSER_SNAME(),source=N'CORE_MIGRATE'
              WHERE migration_file=:file;
             IF @@ROWCOUNT=0
                INSERT dbo.msp_schema_migrations(migration_file,sha256,applied_by,source)
                VALUES(:file_insert,:sha_insert,SUSER_SNAME(),N'CORE_MIGRATE');"
        );
        foreach ($migratorFiles as $file) {
            $path = $root . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path)) {
                continue;
            }
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                $errors[] = 'No se pudo calcular SHA-256 de ' . $file;
                continue;
            }
            $update->execute([
                ':sha' => $hash,
                ':file' => $file,
                ':file_insert' => $file,
                ':sha_insert' => $hash,
            ]);
        }
    }

    $registered = [];
    if ($registryExists) {
        $rows = $conn->query(
            'SELECT migration_file,sha256 FROM dbo.msp_schema_migrations'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $registered[(string) $row['migration_file']] = strtolower((string) $row['sha256']);
        }
    }

    $unregistered = [];
    $modified = [];
    $expectedObjects = [];
    $expectedColumns = [];
    $expectedIndexes = [];

    foreach ($migratorFiles as $file) {
        $path = $root . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            continue;
        }
        $hash = hash_file('sha256', $path);
        if (!isset($registered[$file])) {
            $unregistered[] = $file;
        } elseif ($hash === false || !hash_equals($registered[$file], strtolower($hash))) {
            $modified[] = $file;
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            continue;
        }
        $expectedObjects += mspSchemaExpectedObjects($sql);
        $expectedColumns += mspSchemaExpectedColumns($sql);
        $expectedIndexes += mspSchemaExpectedIndexes($sql);
    }

    if ($unregistered !== []) {
        $errors[] = 'Parches sin registro de aplicación: ' . implode(', ', $unregistered);
    }
    if ($modified !== []) {
        $errors[] = 'Parches modificados después de aplicarse: ' . implode(', ', $modified);
    }

    $objectStmt = $conn->prepare(
        "SELECT COUNT(*) FROM sys.objects WHERE object_id=OBJECT_ID(:qualified) AND is_ms_shipped=0"
    );
    $missingObjects = [];
    foreach ($expectedObjects as $name => $type) {
        $objectStmt->execute([':qualified' => 'dbo.' . $name]);
        if ((int) $objectStmt->fetchColumn() === 0) {
            $missingObjects[] = $type . ' dbo.' . $name;
        }
    }
    if ($missingObjects !== []) {
        $errors[] = 'Objetos de esquema ausentes: ' . implode(', ', $missingObjects);
    }

    $columnStmt = $conn->prepare(
        "SELECT COUNT(*) FROM sys.columns WHERE object_id=OBJECT_ID(:qualified) AND name=:column"
    );
    $missingColumns = [];
    foreach ($expectedColumns as $column) {
        $columnStmt->execute([
            ':qualified' => 'dbo.' . $column['table'],
            ':column' => $column['column'],
        ]);
        if ((int) $columnStmt->fetchColumn() === 0) {
            $missingColumns[] = 'dbo.' . $column['table'] . '.' . $column['column'];
        }
    }
    if ($missingColumns !== []) {
        $errors[] = 'Columnas de esquema ausentes: ' . implode(', ', $missingColumns);
    }

    $indexStmt = $conn->prepare(
        "SELECT COUNT(*) FROM sys.indexes WHERE object_id=OBJECT_ID(:qualified) AND name=:index"
    );
    $missingIndexes = [];
    $supersededIndexes = mspSchemaSupersededIndexes();
    foreach ($expectedIndexes as $indexKey => $index) {
        if (isset($supersededIndexes[$indexKey])) {
            continue;
        }
        $indexStmt->execute([
            ':qualified' => 'dbo.' . $index['table'],
            ':index' => $index['index'],
        ]);
        if ((int) $indexStmt->fetchColumn() === 0) {
            $missingIndexes[] = 'dbo.' . $index['table'] . '.' . $index['index'];
        }
    }
    if ($missingIndexes !== []) {
        $warnings[] = 'Índices declarados históricamente y no presentes: ' . implode(', ', $missingIndexes);
    }

    $permissionNames = [
        'MSP Operacion',
        'MSP Cobranza',
        'MSP Cierre Mensual',
        'MSP Reportes',
        'MSP Configuracion',
    ];
    $permissionStmt = $conn->prepare(
        'SELECT COUNT(*) FROM dbo.cr_permisos WHERE nombre_permiso=:name'
    );
    $missingPermissions = [];
    foreach ($permissionNames as $permissionName) {
        $permissionStmt->execute([':name' => $permissionName]);
        if ((int) $permissionStmt->fetchColumn() === 0) {
            $missingPermissions[] = $permissionName;
        }
    }
    if ($missingPermissions !== []) {
        $errors[] = 'Permisos MSP ausentes: ' . implode(', ', $missingPermissions);
    }

    $result = [
        'ok' => $errors === [],
        'modo' => $record ? 'registrar_y_verificar' : 'solo_verificar',
        'servidor' => (string) ($databaseInfo['servidor'] ?? ''),
        'base_datos' => (string) ($databaseInfo['base_datos'] ?? ''),
        'parches_en_migrador' => count($migratorFiles),
        'parches_operativos_detectados' => count($operationalPatches),
        'parches_registrados' => count($registered),
        'objetos_verificados' => count($expectedObjects),
        'columnas_verificadas' => count($expectedColumns),
        'indices_verificados' => count($expectedIndexes),
        'errores' => $errors,
        'advertencias' => $warnings,
        'parche_opcional_no_automatico' => MSP_SCHEMA_OPTIONAL_PATCHES,
        'fecha' => date(DATE_ATOM),
    ];
} catch (Throwable $exception) {
    $result = [
        'ok' => false,
        'modo' => $record ? 'registrar_y_verificar' : 'solo_verificar',
        'errores' => ['Error del verificador: ' . $exception->getMessage()],
        'advertencias' => [],
        'fecha' => date(DATE_ATOM),
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit(($result['ok'] ?? false) ? 0 : 1);
