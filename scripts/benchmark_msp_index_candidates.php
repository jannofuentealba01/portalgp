<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$plansOnly = in_array('--plans-only', $argv ?? [], true);

$root = dirname(__DIR__);
require_once $root . '/secret_paths.php';

$config = pgpLoadSecretConfig('database_remote.php');
if ($config === []) {
    throw new RuntimeException('No existe la configuración administrativa remota para el laboratorio.');
}

$dsn = 'sqlsrv:Server=' . (string) ($config['server'] ?? '')
    . ';Database=' . (string) ($config['database'] ?? 'PORTALGP')
    . ';Encrypt=' . (!empty($config['encrypt']) ? '1' : '0')
    . ';TrustServerCertificate=' . (!empty($config['trust_server_certificate']) ? '1' : '0');
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
if (defined('PDO::SQLSRV_ATTR_DIRECT_QUERY')) {
    $pdoOptions[PDO::SQLSRV_ATTR_DIRECT_QUERY] = true;
}
$db = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), $pdoOptions);

$identity = $db->query(
    "SELECT DB_NAME() database_name,
            HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','SHOWPLAN') can_showplan,
            HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','CONTROL') can_control"
)->fetch();
if (($identity['database_name'] ?? '') !== 'PORTALGP'
    || (int) ($identity['can_showplan'] ?? 0) !== 1
    || (int) ($identity['can_control'] ?? 0) !== 1) {
    throw new RuntimeException('El laboratorio exige PORTALGP y una cuenta separada con SHOWPLAN/CONTROL.');
}

/** @return float */
$median = static function (array $values): float {
    sort($values, SORT_NUMERIC);
    $count = count($values);
    if ($count === 0) {
        return 0.0;
    }
    $middle = intdiv($count, 2);
    return $count % 2 === 1
        ? (float) $values[$middle]
        : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
};

/** @return array{median_ms:float,wall_median_ms:float,samples_ms:list<float>,wall_samples_ms:list<float>,checksum:mixed} */
$measure = static function (PDO $db, string $batch, int $samples = 5) use ($median): array {
    $times = [];
    $wallTimes = [];
    $checksum = null;
    for ($sample = 0; $sample < $samples + 1; $sample++) {
        $started = hrtime(true);
        $row = $db->query($batch)->fetch();
        $wallElapsed = (hrtime(true) - $started) / 1_000_000;
        if (!is_array($row)) {
            throw new RuntimeException('El benchmark no devolvió métricas.');
        }
        if ($sample > 0) {
            $times[] = (float) ($row['elapsed_ms'] ?? 0);
            $wallTimes[] = $wallElapsed;
        }
        $checksum = $row['checksum_value'] ?? null;
    }
    return [
        'median_ms' => round($median($times), 3),
        'wall_median_ms' => round($median($wallTimes), 3),
        'samples_ms' => array_map(static fn(float $value): float => round($value, 3), $times),
        'wall_samples_ms' => array_map(static fn(float $value): float => round($value, 3), $wallTimes),
        'checksum' => $checksum,
    ];
};

/** @return array{cost:?float,physical_ops:list<string>,indexes:list<string>,error?:string} */
$showPlan = static function (PDO $db, string $query): array {
    $enabled = false;
    try {
        $enableStatement = $db->prepare('SET SHOWPLAN_XML ON');
        $enableStatement->execute();
        while ($enableStatement->nextRowset()) {
            // SQLSRV requires consuming the fieldless SET result before the session option is active.
        }
        $enableStatement->closeCursor();
        $enabled = true;
        $statement = $db->query($query);
        $xml = $statement->fetchColumn();
        $statement->closeCursor();
        if (!is_string($xml) || trim($xml) === '') {
            throw new RuntimeException('SHOWPLAN_XML no devolvió XML.');
        }
        // PDO_SQLSRV entrega bytes UTF-8 aunque SQL Server declare UTF-16 en el XML.
        $xml = preg_replace('/encoding=("|\')utf-16\1/i', 'encoding="utf-8"', $xml) ?? $xml;
        $document = new DOMDocument();
        if (!@$document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('El plan XML no es válido.');
        }
        $xpath = new DOMXPath($document);
        $statementNode = $xpath->query('//*[local-name()="StmtSimple"]')->item(0);
        $cost = $statementNode instanceof DOMElement && $statementNode->hasAttribute('StatementSubTreeCost')
            ? (float) $statementNode->getAttribute('StatementSubTreeCost')
            : null;
        $physicalOps = [];
        foreach ($xpath->query('//*[local-name()="RelOp"]/@PhysicalOp') as $attribute) {
            $physicalOps[(string) $attribute->nodeValue] = true;
        }
        $indexes = [];
        foreach ($xpath->query('//*[local-name()="Object"]/@Index') as $attribute) {
            $indexes[(string) $attribute->nodeValue] = true;
        }
        return [
            'cost' => $cost !== null ? round($cost, 8) : null,
            'physical_ops' => array_keys($physicalOps),
            'indexes' => array_keys($indexes),
        ];
    } catch (Throwable $exception) {
        return ['cost' => null, 'physical_ops' => [], 'indexes' => [], 'error' => $exception->getMessage()];
    } finally {
        if ($enabled) {
            try {
                $disableStatement = $db->prepare('SET SHOWPLAN_XML OFF');
                $disableStatement->execute();
                while ($disableStatement->nextRowset()) {
                    // Consume the fieldless SET result so the session returns to normal execution.
                }
                $disableStatement->closeCursor();
            } catch (Throwable) {
                // The temporary connection closes immediately if SQL Server rejected SHOWPLAN cleanup.
            }
        }
    }
};

$db->exec("SET NOCOUNT ON;
WITH n AS (
    SELECT TOP (40) ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) - 1 AS n
    FROM sys.all_objects a CROSS JOIN sys.all_objects b
)
SELECT
    CAST(CASE WHEN d.id_cobro_servicio IS NULL THEN NULL ELSE CAST(d.id_cobro_servicio AS bigint) + CAST(n.n AS bigint) * 10000000 END AS bigint) id_cobro_servicio,
    CAST(CAST(d.id_documento_cobro AS bigint) + CAST(n.n AS bigint) * 10000000 AS bigint) id_documento_cobro,
    d.id_tipo_item_documento,d.subtotal,d.orden_item
INTO #lab_dcd
FROM dbo.msp_documentos_cobro_detalle d CROSS JOIN n;

WITH n AS (
    SELECT TOP (50) ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) - 1 AS n
    FROM sys.all_objects a CROSS JOIN sys.all_objects b
)
SELECT a.tabla_origen,CAST(CAST(a.id_origen AS bigint) + CAST(n.n AS bigint) * 10000000 AS bigint) id_origen,
       a.estado_asiento,a.fecha_contable
INTO #lab_asientos
FROM dbo.msp_acc_asientos a CROSS JOIN n;

WITH n AS (
    SELECT TOP (50) ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) - 1 AS n
    FROM sys.all_objects a CROSS JOIN sys.all_objects b
)
SELECT CAST(CAST(a.id_documento_cobro AS bigint) + CAST(n.n AS bigint) * 10000000 AS bigint) id_documento_cobro,
       CAST(CAST(a.id_pago AS bigint) + CAST(n.n AS bigint) * 10000000 AS bigint) id_pago,a.tipo_archivo
INTO #lab_archivos
FROM dbo.msp_pago_contrato_archivos a CROSS JOIN n;

WITH n AS (
    SELECT TOP (300) ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) - 1 AS n
    FROM sys.all_objects a CROSS JOIN sys.all_objects b
)
SELECT CASE WHEN m.id_documento_cobro IS NULL THEN NULL ELSE CAST(CAST(m.id_documento_cobro AS bigint) + CAST(n.n AS bigint) * 10000000 AS bigint) END id_documento_cobro,
       m.id_tienda,CASE WHEN m.id_pago IS NULL THEN NULL ELSE CAST(CAST(m.id_pago AS bigint) + CAST(n.n AS bigint) * 10000000 AS bigint) END id_pago,
       m.monto_movimiento
INTO #lab_saldo_mov
FROM dbo.msp_movimientos_saldo_favor_tienda m CROSS JOIN n;

WITH n AS (
    SELECT TOP (1000) ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) - 1 AS n
    FROM sys.all_objects a CROSS JOIN sys.all_objects b
)
SELECT CAST(CAST(a.id_documento_cobro AS bigint) + CAST(n.n AS bigint) * 10000000 AS bigint) id_documento_cobro,
       a.estado_aplicacion,a.id_saldo_favor_periodo_item,a.id_pago,a.monto_aplicado
INTO #lab_saldo_app
FROM dbo.msp_saldo_favor_periodo_aplicaciones a CROSS JOIN n;

UPDATE STATISTICS #lab_dcd WITH FULLSCAN;
UPDATE STATISTICS #lab_asientos WITH FULLSCAN;
UPDATE STATISTICS #lab_archivos WITH FULLSCAN;
UPDATE STATISTICS #lab_saldo_mov WITH FULLSCAN;
UPDATE STATISTICS #lab_saldo_app WITH FULLSCAN;");

$rowCounts = [];
foreach ([
    'detalle' => '#lab_dcd',
    'asientos' => '#lab_asientos',
    'archivos' => '#lab_archivos',
    'saldo_movimientos' => '#lab_saldo_mov',
    'saldo_aplicaciones' => '#lab_saldo_app',
] as $label => $table) {
    $rowCounts[$label] = (int) $db->query("SELECT COUNT_BIG(*) FROM $table")->fetchColumn();
}

$probeDcd = (int) $db->query('SELECT MIN(id_cobro_servicio) FROM #lab_dcd WHERE id_cobro_servicio IS NOT NULL')->fetchColumn();
$probeType = (int) $db->query('SELECT TOP(1) id_tipo_item_documento FROM #lab_dcd GROUP BY id_tipo_item_documento ORDER BY COUNT_BIG(*) DESC')->fetchColumn();
$probeAsiento = $db->query('SELECT TOP(1) tabla_origen,id_origen,estado_asiento FROM #lab_asientos ORDER BY id_origen')->fetch();
$probeArchivo = (int) $db->query('SELECT MIN(id_documento_cobro) FROM #lab_archivos')->fetchColumn();
$probeSaldoMov = (int) $db->query('SELECT MIN(id_documento_cobro) FROM #lab_saldo_mov WHERE id_documento_cobro IS NOT NULL')->fetchColumn();
$probeSaldoApp = (int) $db->query('SELECT MIN(id_documento_cobro) FROM #lab_saldo_app')->fetchColumn();

$literal = static fn(string $value): string => "N'" . str_replace("'", "''", $value) . "'";
$asientoTable = $literal((string) ($probeAsiento['tabla_origen'] ?? 'msp_documentos_cobro'));
$asientoId = (int) ($probeAsiento['id_origen'] ?? 0);
$asientoState = (int) ($probeAsiento['estado_asiento'] ?? 1);

$loopBatch = static function (string $body, int $iterations = 300): string {
    return "DECLARE @i int=0,@sink decimal(38,4)=0,@started datetime2(7)=SYSDATETIME();
            WHILE @i<$iterations BEGIN $body SET @i+=1; END;
            SELECT CAST(DATEDIFF_BIG(MICROSECOND,@started,SYSDATETIME())/1000.0 AS decimal(18,3)) elapsed_ms,@sink checksum_value;";
};
$writeBatch = static function (string $insertSql): string {
    return "DECLARE @started datetime2(7)=SYSDATETIME(),@elapsed decimal(18,3);
            BEGIN TRANSACTION;
            $insertSql;
            SET @elapsed=CAST(DATEDIFF_BIG(MICROSECOND,@started,SYSDATETIME())/1000.0 AS decimal(18,3));
            ROLLBACK TRANSACTION;
            SELECT @elapsed elapsed_ms,CAST(0 AS int) checksum_value;";
};

$cases = [
    [
        'name' => 'detalle_por_cobro_servicio',
        'create' => 'CREATE INDEX IX_lab_dcd_cobro_servicio ON #lab_dcd(id_cobro_servicio) INCLUDE(id_documento_cobro)',
        'drop' => 'DROP INDEX IX_lab_dcd_cobro_servicio ON #lab_dcd',
        'query' => "SELECT COUNT_BIG(*) FROM #lab_dcd WHERE id_cobro_servicio=$probeDcd",
        'read' => $loopBatch("SELECT @sink=COUNT_BIG(*) FROM #lab_dcd WHERE id_cobro_servicio=$probeDcd;"),
        'write' => $writeBatch('INSERT #lab_dcd(id_cobro_servicio,id_documento_cobro,id_tipo_item_documento,subtotal,orden_item) SELECT TOP(2000) id_cobro_servicio,id_documento_cobro,id_tipo_item_documento,subtotal,orden_item FROM #lab_dcd'),
    ],
    [
        'name' => 'detalle_por_tipo_item',
        'create' => 'CREATE INDEX IX_lab_dcd_tipo_item ON #lab_dcd(id_tipo_item_documento) INCLUDE(id_documento_cobro,subtotal)',
        'drop' => 'DROP INDEX IX_lab_dcd_tipo_item ON #lab_dcd',
        'query' => "SELECT SUM(subtotal) FROM #lab_dcd WHERE id_tipo_item_documento=$probeType",
        'read' => $loopBatch("SELECT @sink=ISNULL(SUM(subtotal),0) FROM #lab_dcd WHERE id_tipo_item_documento=$probeType;", 120),
        'write' => $writeBatch('INSERT #lab_dcd(id_cobro_servicio,id_documento_cobro,id_tipo_item_documento,subtotal,orden_item) SELECT TOP(2000) id_cobro_servicio,id_documento_cobro,id_tipo_item_documento,subtotal,orden_item FROM #lab_dcd'),
    ],
    [
        'name' => 'asientos_por_origen_estado',
        'create' => 'CREATE INDEX IX_lab_asientos_origen_estado ON #lab_asientos(tabla_origen,id_origen,estado_asiento)',
        'drop' => 'DROP INDEX IX_lab_asientos_origen_estado ON #lab_asientos',
        'query' => "SELECT COUNT_BIG(*) FROM #lab_asientos WHERE tabla_origen=$asientoTable AND id_origen=$asientoId AND estado_asiento=$asientoState",
        'read' => $loopBatch("SELECT @sink=COUNT_BIG(*) FROM #lab_asientos WHERE tabla_origen=$asientoTable AND id_origen=$asientoId AND estado_asiento=$asientoState;"),
        'write' => $writeBatch('INSERT #lab_asientos(tabla_origen,id_origen,estado_asiento,fecha_contable) SELECT TOP(2000) tabla_origen,id_origen,estado_asiento,fecha_contable FROM #lab_asientos'),
    ],
    [
        'name' => 'archivos_por_documento',
        'create' => 'CREATE INDEX IX_lab_archivos_documento ON #lab_archivos(id_documento_cobro) INCLUDE(id_pago,tipo_archivo)',
        'drop' => 'DROP INDEX IX_lab_archivos_documento ON #lab_archivos',
        'query' => "SELECT COUNT_BIG(*) FROM #lab_archivos WHERE id_documento_cobro=$probeArchivo",
        'read' => $loopBatch("SELECT @sink=COUNT_BIG(*) FROM #lab_archivos WHERE id_documento_cobro=$probeArchivo;"),
        'write' => $writeBatch('INSERT #lab_archivos(id_documento_cobro,id_pago,tipo_archivo) SELECT TOP(2000) id_documento_cobro,id_pago,tipo_archivo FROM #lab_archivos'),
    ],
    [
        'name' => 'saldo_movimiento_por_documento',
        'create' => 'CREATE INDEX IX_lab_saldo_mov_documento ON #lab_saldo_mov(id_documento_cobro) INCLUDE(id_tienda,id_pago,monto_movimiento) WHERE id_documento_cobro IS NOT NULL',
        'drop' => 'DROP INDEX IX_lab_saldo_mov_documento ON #lab_saldo_mov',
        'query' => "SELECT SUM(monto_movimiento) FROM #lab_saldo_mov WHERE id_documento_cobro=$probeSaldoMov",
        'read' => $loopBatch("SELECT @sink=ISNULL(SUM(monto_movimiento),0) FROM #lab_saldo_mov WHERE id_documento_cobro=$probeSaldoMov;"),
        'write' => $writeBatch('INSERT #lab_saldo_mov(id_documento_cobro,id_tienda,id_pago,monto_movimiento) SELECT TOP(2000) id_documento_cobro,id_tienda,id_pago,monto_movimiento FROM #lab_saldo_mov'),
    ],
    [
        'name' => 'saldo_aplicacion_por_documento',
        'create' => 'CREATE INDEX IX_lab_saldo_app_documento_estado ON #lab_saldo_app(id_documento_cobro,estado_aplicacion) INCLUDE(id_saldo_favor_periodo_item,id_pago,monto_aplicado)',
        'drop' => 'DROP INDEX IX_lab_saldo_app_documento_estado ON #lab_saldo_app',
        'query' => "SELECT SUM(monto_aplicado) FROM #lab_saldo_app WHERE id_documento_cobro=$probeSaldoApp AND estado_aplicacion=1",
        'read' => $loopBatch("SELECT @sink=ISNULL(SUM(monto_aplicado),0) FROM #lab_saldo_app WHERE id_documento_cobro=$probeSaldoApp AND estado_aplicacion=1;"),
        'write' => $writeBatch('INSERT #lab_saldo_app(id_documento_cobro,estado_aplicacion,id_saldo_favor_periodo_item,id_pago,monto_aplicado) SELECT TOP(2000) id_documento_cobro,estado_aplicacion,id_saldo_favor_periodo_item,id_pago,monto_aplicado FROM #lab_saldo_app'),
    ],
];

$results = [];
foreach ($cases as $case) {
    $planBefore = $showPlan($db, $case['query']);
    if ($plansOnly) {
        $db->exec($case['create']);
        $planAfter = $showPlan($db, $case['query']);
        $db->exec($case['drop']);
        $results[] = [
            'candidate' => $case['name'],
            'plan_before' => $planBefore,
            'plan_after' => $planAfter,
        ];
        continue;
    }
    $readBefore = $measure($db, $case['read']);
    $writeBefore = $measure($db, $case['write'], 3);

    $db->exec($case['create']);
    $planAfter = $showPlan($db, $case['query']);
    $readAfter = $measure($db, $case['read']);
    $writeAfter = $measure($db, $case['write'], 3);
    $db->exec($case['drop']);
    $readBaselineAgain = $measure($db, $case['read']);

    $baselineReadMs = round(($readBefore['median_ms'] + $readBaselineAgain['median_ms']) / 2, 3);
    $indexedReadMs = (float) $readAfter['median_ms'];
    $baselineReadWallMs = round(($readBefore['wall_median_ms'] + $readBaselineAgain['wall_median_ms']) / 2, 3);
    $indexedReadWallMs = (float) $readAfter['wall_median_ms'];
    $baselineWriteMs = (float) $writeBefore['median_ms'];
    $indexedWriteMs = (float) $writeAfter['median_ms'];
    $baselineWriteWallMs = (float) $writeBefore['wall_median_ms'];
    $indexedWriteWallMs = (float) $writeAfter['wall_median_ms'];
    $results[] = [
        'candidate' => $case['name'],
        'read_baseline_ms' => $baselineReadMs,
        'read_indexed_ms' => $indexedReadMs,
        'read_improvement_pct' => $baselineReadMs > 0
            ? round(($baselineReadMs - $indexedReadMs) * 100 / $baselineReadMs, 2)
            : null,
        'read_wall_baseline_ms' => $baselineReadWallMs,
        'read_wall_indexed_ms' => $indexedReadWallMs,
        'read_wall_improvement_pct' => $baselineReadWallMs > 0
            ? round(($baselineReadWallMs - $indexedReadWallMs) * 100 / $baselineReadWallMs, 2)
            : null,
        'write_baseline_ms' => $baselineWriteMs,
        'write_indexed_ms' => $indexedWriteMs,
        'write_overhead_pct' => $baselineWriteMs > 0
            ? round(($indexedWriteMs - $baselineWriteMs) * 100 / $baselineWriteMs, 2)
            : null,
        'write_wall_baseline_ms' => $baselineWriteWallMs,
        'write_wall_indexed_ms' => $indexedWriteWallMs,
        'write_wall_overhead_pct' => $baselineWriteWallMs > 0
            ? round(($indexedWriteWallMs - $baselineWriteWallMs) * 100 / $baselineWriteWallMs, 2)
            : null,
        'plan_before' => $planBefore,
        'plan_after' => $planAfter,
        'checksum_equal' => (string) $readBefore['checksum'] === (string) $readAfter['checksum'],
    ];
}

$db->exec('DROP TABLE IF EXISTS #lab_dcd,#lab_asientos,#lab_archivos,#lab_saldo_mov,#lab_saldo_app');

echo json_encode([
    'database' => 'PORTALGP',
    'environment' => $plansOnly ? 'temporary_tables_plans_only' : 'temporary_tables_only',
    'operational_tables_modified' => false,
    'row_counts' => $rowCounts,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
