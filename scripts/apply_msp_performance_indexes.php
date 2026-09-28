<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$mode = $argv[1] ?? '--verify';
if (!in_array($mode, ['--verify', '--apply', '--rollback'], true)) {
    fwrite(STDERR, "Uso: php scripts/apply_msp_performance_indexes.php [--verify|--apply|--rollback]\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/secret_paths.php';
$config = pgpLoadSecretConfig('database_remote.php');
if ($config === []) {
    throw new RuntimeException('No existe la configuración administrativa remota.');
}

$dsn = 'sqlsrv:Server=' . (string) ($config['server'] ?? '')
    . ';Database=' . (string) ($config['database'] ?? 'PORTALGP')
    . ';Encrypt=' . (!empty($config['encrypt']) ? '1' : '0')
    . ';TrustServerCertificate=' . (!empty($config['trust_server_certificate']) ? '1' : '0');
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
if (defined('PDO::SQLSRV_ATTR_DIRECT_QUERY')) {
    $options[PDO::SQLSRV_ATTR_DIRECT_QUERY] = true;
}
$db = new PDO(
    $dsn,
    (string) ($config['username'] ?? ''),
    (string) ($config['password'] ?? ''),
    $options
);

$identity = $db->query(
    "SELECT DB_NAME() database_name,
            HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','ALTER') can_alter"
)->fetch();
if (($identity['database_name'] ?? '') !== 'PORTALGP') {
    throw new RuntimeException('La conexión no apunta a PORTALGP.');
}
if ($mode !== '--verify' && (int) ($identity['can_alter'] ?? 0) !== 1) {
    throw new RuntimeException('La cuenta de mantenimiento no tiene permiso ALTER.');
}

$expected = [
    'IX_msp_dcd_cobro_servicio_documento',
    'IX_msp_acc_asientos_origen_estado',
    'IX_msp_pca_documento',
    'IX_msp_msft_documento',
    'IX_msp_sfpa_documento_estado',
];
$knownEquivalent = [
    'IX_msp_pagos_documento_estado',
    'IX_msp_gda_documento',
];

$started = hrtime(true);
if ($mode !== '--verify') {
    $file = $mode === '--apply'
        ? $root . '/msp/db/patch_indices_rendimiento_consultas.sql'
        : $root . '/msp/db/rollback_indices_rendimiento_consultas.sql';
    $sql = file_get_contents($file);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('No se pudo leer el parche SQL.');
    }
    $db->exec($sql);
}
$elapsedMs = round((hrtime(true) - $started) / 1_000_000, 3);

$placeholders = implode(',', array_fill(0, count($expected) + count($knownEquivalent), '?'));
$statement = $db->prepare(
    "SELECT OBJECT_NAME(i.object_id) table_name,i.name index_name,i.filter_definition,
            c.name column_name,ic.key_ordinal,ic.is_included_column
     FROM sys.indexes i
     INNER JOIN sys.index_columns ic
       ON ic.object_id=i.object_id AND ic.index_id=i.index_id
     INNER JOIN sys.columns c
       ON c.object_id=ic.object_id AND c.column_id=ic.column_id
     WHERE i.name IN ($placeholders)
     ORDER BY i.name,CASE WHEN ic.key_ordinal=0 THEN 32767 ELSE ic.key_ordinal END,ic.index_column_id"
);
$statement->execute(array_merge($expected, $knownEquivalent));

$indexes = [];
while ($row = $statement->fetch()) {
    $name = (string) $row['index_name'];
    if (!isset($indexes[$name])) {
        $indexes[$name] = [
            'table' => (string) $row['table_name'],
            'name' => $name,
            'keys' => [],
            'includes' => [],
            'filter' => $row['filter_definition'] !== null ? (string) $row['filter_definition'] : null,
        ];
    }
    if ((int) $row['is_included_column'] === 1) {
        $indexes[$name]['includes'][] = (string) $row['column_name'];
    } else {
        $indexes[$name]['keys'][] = (string) $row['column_name'];
    }
}

$present = array_values(array_intersect($expected, array_keys($indexes)));
$missing = array_values(array_diff($expected, array_keys($indexes)));
$equivalentPresent = array_values(array_intersect($knownEquivalent, array_keys($indexes)));

echo json_encode([
    'mode' => ltrim($mode, '-'),
    'database' => 'PORTALGP',
    'elapsed_ms' => $elapsedMs,
    'expected_present' => $present,
    'expected_missing' => $missing,
    'existing_equivalents_not_duplicated' => $equivalentPresent,
    'definitions' => array_values($indexes),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($mode === '--apply' && $missing !== []) {
    exit(1);
}
if ($mode === '--rollback' && $present !== []) {
    exit(1);
}
