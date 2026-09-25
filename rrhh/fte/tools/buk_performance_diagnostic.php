<?php
declare(strict_types=1);

/**
 * Diagnostico controlado y anonimizado de las consultas Buk usadas por FTE.
 *
 * Uso:
 *   php tools/buk_performance_diagnostic.php --month=2026-08
 *   php tools/buk_performance_diagnostic.php --execute --month=2026-08
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/fte_lib.php';

function buk_diag_seconds(int $startedAt): float
{
    return round((hrtime(true) - $startedAt) / 1_000_000_000, 6);
}

function buk_diag_http_get(
    string $url,
    array $headers,
    string $source,
    array &$requests
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('No fue posible inicializar cURL.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $startedAt = hrtime(true);
    $raw = curl_exec($ch);
    $elapsed = buk_diag_seconds($startedAt);
    $errno = curl_errno($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $rawText = is_string($raw) ? $raw : '';
    $decoded = null;
    $jsonValid = false;
    if ($rawText !== '') {
        try {
            $decoded = json_decode($rawText, true, 512, JSON_THROW_ON_ERROR);
            $jsonValid = true;
        } catch (JsonException $e) {
            $jsonValid = false;
        }
    }
    $status = (int)($info['http_code'] ?? 0);
    $ok = $errno === 0 && $status >= 200 && $status < 300 && $jsonValid;
    $rows = is_array($decoded) ? fte_extract_list($decoded) : [];
    $query = [];
    parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $query);

    $request = [
        'source' => $source,
        'endpoint' => (string)(parse_url($url, PHP_URL_PATH) ?? ''),
        'page' => isset($query['page']) ? (int)$query['page'] : null,
        'page_size' => isset($query['page_size']) ? (int)$query['page_size'] : null,
        'per_page' => isset($query['per_page']) ? (int)$query['per_page'] : null,
        'http_status' => $status,
        'transport_errno' => $errno,
        'ok' => $ok,
        'attempts' => 1,
        'retries' => 0,
        'records_received' => count($rows),
        'bytes_received' => strlen($rawText),
        'timing' => [
            'total_seconds' => $elapsed,
            'dns_cumulative_seconds' => round((float)($info['namelookup_time'] ?? 0), 6),
            'connect_cumulative_seconds' => round((float)($info['connect_time'] ?? 0), 6),
            'tls_cumulative_seconds' => round((float)($info['appconnect_time'] ?? 0), 6),
            'ttfb_cumulative_seconds' => round((float)($info['starttransfer_time'] ?? 0), 6),
        ],
        'error_category' => $ok
            ? null
            : ($errno !== 0 ? 'transport_error_' . $errno : 'http_' . $status),
    ];
    $requests[] = $request;

    return [
        'ok' => $ok,
        'status' => $status,
        'json' => is_array($decoded) ? $decoded : null,
        'rows' => $rows,
    ];
}

function buk_diag_fetch_all(
    array $config,
    string $path,
    array $query,
    int $maxPages,
    string $source,
    array &$requests
): array {
    $base = rtrim((string)$config['buk_base_url'], '/');
    $items = [];
    for ($page = 1; $page <= $maxPages; $page++) {
        $pageQuery = $query;
        $pageQuery['page'] = $page;
        $pageQuery['page_size'] = (int)($pageQuery['page_size'] ?? 100);
        $pageQuery['per_page'] = (int)($pageQuery['per_page'] ?? 100);
        $url = $base . $path . '?' . http_build_query($pageQuery);
        $response = buk_diag_http_get($url, fte_buk_headers($config), $source, $requests);
        if (!$response['ok'] || !is_array($response['json'])) {
            throw new RuntimeException('Buk rechazo la consulta ' . $source . ' (HTTP ' . $response['status'] . ').');
        }
        $pageRows = $response['rows'];
        if (!$pageRows) {
            break;
        }
        foreach ($pageRows as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }
        if (!fte_response_has_next_page($response['json'], $page, count($pageRows), (int)$pageQuery['page_size'])) {
            break;
        }
        if ($page === $maxPages) {
            throw new RuntimeException('Buk excedio el limite de paginas durante ' . $source . '.');
        }
    }
    return $items;
}

function buk_diag_request_summary(array $requests, string $source): array
{
    $selected = array_values(array_filter(
        $requests,
        static fn(array $request): bool => $request['source'] === $source
    ));
    return [
        'source' => $source,
        'requests' => count($selected),
        'pages' => array_values(array_filter(array_column($selected, 'page'), static fn($page): bool => $page !== null)),
        'all_http_ok' => array_reduce($selected, static fn(bool $carry, array $row): bool => $carry && $row['ok'], true),
        'http_statuses' => array_values(array_unique(array_column($selected, 'http_status'))),
        'retries' => array_sum(array_column($selected, 'retries')),
        'records_received' => array_sum(array_column($selected, 'records_received')),
        'bytes_received' => array_sum(array_column($selected, 'bytes_received')),
        'total_seconds' => round(array_sum(array_map(
            static fn(array $row): float => (float)$row['timing']['total_seconds'],
            $selected
        )), 6),
        'slowest_request_seconds' => round(max(array_merge([0.0], array_map(
            static fn(array $row): float => (float)$row['timing']['total_seconds'],
            $selected
        ))), 6),
    ];
}

$options = getopt('', ['help', 'execute', 'month:', 'output:']);
if (isset($options['help'])) {
    echo "Diagnostico Buk FTE\n";
    echo "  --execute    Ejecuta consultas de solo lectura.\n";
    echo "  --month=Y-m  Periodo para vacaciones y licencias (predeterminado: 2026-08).\n";
    echo "  --output=RUTA Resultado JSON anonimizado.\n";
    exit(0);
}

$monthText = (string)($options['month'] ?? '2026-08');
if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $monthText)) {
    fwrite(STDERR, "Mes invalido. Usa --month=Y-m.\n");
    exit(2);
}

$config = fte_load_config();
fte_assert_buk_config($config);
$defaultOutput = dirname(__DIR__, 3) . '/storage/fte_diagnostics/buk_performance_' . date('Ymd_His') . '.json';
$outputPath = (string)($options['output'] ?? $defaultOutput);
if (!isset($options['execute'])) {
    echo json_encode([
        'mode' => 'dry_run',
        'month' => $monthText,
        'sources' => ['employees', 'cost_centers', 'vacations', 'licences'],
        'cache_ttl_seconds' => (int)($config['buk_people_cache_ttl_seconds'] ?? 300),
        'output_path' => $outputPath,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo "No se realizaron consultas externas. Agrega --execute para iniciar.\n";
    exit(0);
}

set_time_limit(0);
$runStartedAt = hrtime(true);
$runId = 'BUK-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
$requests = [];
$base = rtrim((string)$config['buk_base_url'], '/');
$country = trim((string)$config['buk_country']);
$monthStart = new DateTimeImmutable($monthText . '-01');
$monthEnd = $monthStart->modify('last day of this month');
$cacheTtl = max(0, (int)($config['buk_people_cache_ttl_seconds'] ?? 300));
$diagnosticSessionStarted = false;
if ($cacheTtl > 0) {
    ini_set('session.use_cookies', '0');
    ini_set('session.cache_limiter', '');
    session_id('bukdiag' . strtolower(bin2hex(random_bytes(8))));
    $diagnosticSessionStarted = session_start();
    unset($_SESSION['fte_buk_people_cache']);
}

echo "[$runId] Consultando trabajadores paginados...\n";
$peopleStartedAt = hrtime(true);
$employeeRows = buk_diag_fetch_all(
    $config,
    "/api/v1/{$country}/employees",
    [
        'page_size' => 100,
        'per_page' => 100,
        'include' => 'department,sub_department,position,employment,contract,organizational_unit,company_area,team,current_job,current_employment',
    ],
    50,
    'employees',
    $requests
);
$people = [];
foreach ($employeeRows as $row) {
    $person = fte_normalize_buk_person($row);
    if ($person !== null) {
        $people[$person['normalized_identifier']] = $person;
    }
}
$people = array_values($people);

echo "[$runId] Consultando centros de costo...\n";
$areaRows = buk_diag_fetch_all(
    $config,
    "/api/v1/{$country}/organization/areas/",
    ['status' => 'both', 'page_size' => 1000, 'per_page' => 1000],
    50,
    'cost_centers',
    $requests
);
$peopleColdSeconds = buk_diag_seconds($peopleStartedAt);

echo "[$runId] Consultando vacaciones del mes...\n";
$vacationRows = buk_diag_fetch_all(
    $config,
    "/api/v1/{$country}/vacations",
    [
        'start_before' => $monthEnd->format('Y-m-d'),
        'end_after' => $monthStart->format('Y-m-d'),
    ],
    20,
    'vacations',
    $requests
);

echo "[$runId] Consultando licencias del mes...\n";
$licenceRows = buk_diag_fetch_all(
    $config,
    "/api/v1/{$country}/absences/licence",
    [
        'from' => $monthStart->format('Y-m-d'),
        'to' => $monthEnd->format('Y-m-d'),
    ],
    20,
    'licences',
    $requests
);

// Comprueba la rama real de lectura desde cache sin repetir las consultas externas.
$cacheTest = [
    'enabled' => $cacheTtl > 0,
    'ttl_seconds' => $cacheTtl,
    'seeded_from_this_measured_cold_run' => false,
    'returned_same_people_count' => false,
    'read_seconds' => null,
    'external_requests_expected' => null,
];
if ($cacheTtl > 0 && $diagnosticSessionStarted) {
    $cacheKey = hash('sha256', $base . '|' . $country . '|' . (string)$config['buk_token'] . '|history');
    $_SESSION['fte_buk_people_cache'] = [
        'key' => $cacheKey,
        'expires_at' => time() + $cacheTtl,
        'people' => $people,
    ];
    $cacheStartedAt = hrtime(true);
    $cachedPeople = fte_fetch_buk_people($config, false);
    $cacheSeconds = buk_diag_seconds($cacheStartedAt);
    $cacheTest = [
        'enabled' => true,
        'ttl_seconds' => $cacheTtl,
        'seeded_from_this_measured_cold_run' => true,
        'returned_same_people_count' => count($cachedPeople) === count($people),
        'read_seconds' => $cacheSeconds,
        'external_requests_expected' => 0,
    ];
    $_SESSION = [];
    session_destroy();
}

$sourceSummaries = [];
foreach (['employees', 'cost_centers', 'vacations', 'licences'] as $source) {
    $sourceSummaries[] = buk_diag_request_summary($requests, $source);
}
$allOk = array_reduce($requests, static fn(bool $carry, array $row): bool => $carry && $row['ok'], true);
$result = [
    'schema_version' => 1,
    'run_id' => $runId,
    'generated_at' => date(DATE_ATOM),
    'scope' => 'controlled_cli_buk_read_only_performance_diagnostic',
    'privacy' => [
        'personal_data_persisted' => false,
        'tokens_persisted' => false,
        'response_bodies_persisted' => false,
    ],
    'period' => [
        'month' => $monthText,
        'from' => $monthStart->format('Y-m-d'),
        'to' => $monthEnd->format('Y-m-d'),
    ],
    'configuration' => [
        'host' => parse_url($base, PHP_URL_HOST),
        'page_size_employees' => 100,
        'page_size_cost_centers' => 1000,
        'page_size_absences' => 100,
        'timeout_seconds' => 30,
        'cache_ttl_seconds' => $cacheTtl,
    ],
    'counts' => [
        'employee_rows_received' => count($employeeRows),
        'normalized_unique_people' => count($people),
        'cost_center_rows_received' => count($areaRows),
        'vacation_rows_received' => count($vacationRows),
        'licence_rows_received' => count($licenceRows),
    ],
    'requests' => $requests,
    'source_summaries' => $sourceSummaries,
    'cache_test' => $cacheTest,
    'summary' => [
        'all_requests_ok' => $allOk,
        'total_external_requests' => count($requests),
        'total_retries' => array_sum(array_column($requests, 'retries')),
        'total_bytes_received' => array_sum(array_column($requests, 'bytes_received')),
        'total_http_seconds' => round(array_sum(array_map(
            static fn(array $row): float => (float)$row['timing']['total_seconds'],
            $requests
        )), 6),
        'people_and_cost_centers_cold_seconds' => $peopleColdSeconds,
        'total_run_seconds' => buk_diag_seconds($runStartedAt),
        'http_429_count' => count(array_filter($requests, static fn(array $row): bool => $row['http_status'] === 429)),
    ],
    'limitations' => [
        'Una sola ejecucion historica no caracteriza toda la variabilidad del proveedor.',
        'La cache actual cubre trabajadores y centros de costo; vacaciones y licencias no tienen la misma cache persistente.',
        'La lectura de cache se comprobo usando los datos anonimizados obtenidos en esta misma ejecucion y la rama real de cache de FTE.',
    ],
];

$outputDirectory = dirname($outputPath);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0770, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('No fue posible crear el directorio de salida.');
}
$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($outputPath, $encoded . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('No fue posible guardar el diagnostico Buk.');
}

echo "[$runId] Diagnostico finalizado.\n";
echo "Resultado anonimizado: $outputPath\n";
echo json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($allOk ? 0 : 4);
