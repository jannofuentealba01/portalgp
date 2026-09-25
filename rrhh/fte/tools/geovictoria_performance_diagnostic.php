<?php
declare(strict_types=1);

/**
 * Diagnostico controlado de rendimiento GeoVictoria para FTE.
 *
 * - Solo se ejecuta por CLI.
 * - No modifica el flujo web ni persiste respuestas laborales.
 * - Los trabajadores se identifican en la salida exclusivamente como W001, W002, etc.
 * - Los secretos y los identificadores reales nunca se escriben en el resultado.
 *
 * Uso:
 *   php tools/geovictoria_performance_diagnostic.php
 *   php tools/geovictoria_performance_diagnostic.php --execute --date=2026-08-04 --month=2026-08 --sample=5
 *   php tools/geovictoria_performance_diagnostic.php --execute --date=2026-08-04 --month=2026-08 --period-matrix
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/fte_lib.php';

function fte_diag_usage(): void
{
    echo "Diagnostico GeoVictoria FTE\n";
    echo "  Sin --execute: valida configuracion y muestra el plan sin conectarse.\n";
    echo "  --execute     : ejecuta una prueba controlada contra Buk y GeoVictoria.\n";
    echo "  --date=Y-m-d  : dia historico estable (predeterminado: 2026-08-04).\n";
    echo "  --month=Y-m   : mes historico para comparar (predeterminado: 2026-08).\n";
    echo "  --sample=N    : muestra entre 1 y 5 (predeterminado: 5).\n";
    echo "  --batch-sizes=5,15,71,all : prueba progresiva solo de lotes para un dia.\n";
    echo "  --batch-offset=N : omite N trabajadores anonimos al formar cada lote.\n";
    echo "  --chunk-size=N : divide toda la nomina en lotes y prueba dia y mes.\n";
    echo "  --period-matrix : prueba 1, 2, 7, 14, 28, 30 y 31 dias con lotes seguros.\n";
    echo "  --output=RUTA : JSON anonimizado (predeterminado: storage/fte_diagnostics/...).\n";
}

function fte_diag_seconds(int $startedAt): float
{
    return round((hrtime(true) - $startedAt) / 1_000_000_000, 6);
}

function fte_diag_redact(string $value, array $secrets, array $identifiers): string
{
    foreach (array_merge($secrets, $identifiers) as $sensitive) {
        $sensitive = trim((string)$sensitive);
        if ($sensitive !== '') {
            $value = str_ireplace($sensitive, '[REDACTED]', $value);
        }
    }
    $value = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[EMAIL]', $value) ?? $value;
    $value = preg_replace('/(?<!\d)\d{7,9}[0-9Kk](?!\d)/', '[IDENTIFIER]', $value) ?? $value;
    $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;
    return mb_substr(trim($value), 0, 500, 'UTF-8');
}

function fte_diag_error_category(int $errno, int $status): ?string
{
    if ($errno !== 0) {
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => 'transport_timeout',
            CURLE_COULDNT_RESOLVE_HOST => 'dns_failure',
            CURLE_COULDNT_CONNECT => 'connection_failure',
            CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION => 'tls_failure',
            default => 'transport_error_' . $errno,
        };
    }
    return $status >= 400 ? 'http_' . $status : null;
}

/**
 * Devuelve metricas seguras y mantiene raw/json solo en memoria para el analisis.
 */
function fte_diag_http_json(
    string $url,
    array $headers,
    array $payload,
    int $timeout,
    array $secrets,
    array $identifiers
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('No fue posible inicializar cURL.');
    }
    $httpHeaders = array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $startedAt = hrtime(true);
    $raw = curl_exec($ch);
    $monotonicTotal = fte_diag_seconds($startedAt);
    $errno = curl_errno($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    $rawText = is_string($raw) ? $raw : '';
    $parseStartedAt = hrtime(true);
    $json = null;
    $jsonError = null;
    if ($rawText !== '') {
        try {
            $json = json_decode($rawText, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $jsonError = 'invalid_json';
        }
    }
    $parseSeconds = fte_diag_seconds($parseStartedAt);
    $status = (int)($info['http_code'] ?? 0);
    $ok = $errno === 0 && $status >= 200 && $status < 300 && $jsonError === null;

    $dns = (float)($info['namelookup_time'] ?? 0.0);
    $connect = (float)($info['connect_time'] ?? 0.0);
    $tls = (float)($info['appconnect_time'] ?? 0.0);
    $ttfb = (float)($info['starttransfer_time'] ?? 0.0);
    $curlTotal = (float)($info['total_time'] ?? $monotonicTotal);
    $connectionReady = $tls > 0 ? $tls : $connect;

    return [
        'ok' => $ok,
        'status' => $status,
        'errno' => $errno,
        'error_category' => $jsonError ?? fte_diag_error_category($errno, $status),
        'error_body_summary' => $ok ? null : fte_diag_redact($rawText, $secrets, $identifiers),
        'timing' => [
            'monotonic_total_seconds' => $monotonicTotal,
            'curl_total_seconds' => round($curlTotal, 6),
            'dns_cumulative_seconds' => round($dns, 6),
            'connect_cumulative_seconds' => round($connect, 6),
            'tls_cumulative_seconds' => round($tls, 6),
            'ttfb_cumulative_seconds' => round($ttfb, 6),
            'tcp_phase_seconds' => round(max(0.0, $connect - $dns), 6),
            'tls_phase_seconds' => round(max(0.0, $tls - $connect), 6),
            'server_wait_after_connection_seconds' => round(max(0.0, $ttfb - $connectionReady), 6),
            'download_after_first_byte_seconds' => round(max(0.0, $curlTotal - $ttfb), 6),
            'json_decode_seconds' => $parseSeconds,
        ],
        'bytes_received' => strlen($rawText),
        'json' => is_array($json) ? $json : null,
    ];
}

function fte_diag_payload_counts(array $payload): array
{
    $users = fte_extract_users($payload);
    $intervals = 0;
    $punches = 0;
    $byIdentifier = [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $identifier = fte_identifier_from_geo_user($user);
        $userIntervals = is_array($user['PlannedInterval'] ?? null) ? $user['PlannedInterval'] : [];
        $userPunches = 0;
        foreach ($userIntervals as $interval) {
            if (is_array($interval) && is_array($interval['Punches'] ?? null)) {
                $userPunches += count($interval['Punches']);
            }
        }
        $intervals += count($userIntervals);
        $punches += $userPunches;
        if ($identifier !== '') {
            $byIdentifier[$identifier] = [
                'intervals' => count($userIntervals),
                'punches' => $userPunches,
            ];
        }
    }
    return [
        'users' => count($users),
        'intervals' => $intervals,
        'punches' => $punches,
        'by_identifier' => $byIdentifier,
    ];
}

function fte_diag_pause(float $requestedSeconds): array
{
    if ($requestedSeconds <= 0) {
        return ['requested_seconds' => 0.0, 'actual_seconds' => 0.0];
    }
    $startedAt = hrtime(true);
    usleep((int)round($requestedSeconds * 1_000_000));
    return [
        'requested_seconds' => round($requestedSeconds, 6),
        'actual_seconds' => fte_diag_seconds($startedAt),
    ];
}

function fte_diag_percentile(array $values, float $percentile): ?float
{
    $values = array_values(array_filter($values, 'is_numeric'));
    if (!$values) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $position = ($percentile / 100) * (count($values) - 1);
    $lower = (int)floor($position);
    $upper = (int)ceil($position);
    if ($lower === $upper) {
        return round((float)$values[$lower], 6);
    }
    $weight = $position - $lower;
    return round(((float)$values[$lower] * (1 - $weight)) + ((float)$values[$upper] * $weight), 6);
}

function fte_diag_safe_request(
    string $runId,
    array $config,
    string $token,
    array $payload,
    string $workerAlias,
    string $rangeType,
    int $attempt,
    string $identifierFormat,
    array $pause,
    array $allIdentifiers,
    array $secrets
): array {
    $endpoint = 'AttendanceBook';
    $url = rtrim((string)$config['geovictoria_base_url'], '/') . '/' . $endpoint;
    $http = fte_diag_http_json(
        $url,
        ['Authorization: Bearer ' . $token],
        $payload,
        75,
        array_merge($secrets, [$token]),
        $allIdentifiers
    );
    $expected = array_values(array_filter(array_map('fte_normalize_identifier', explode(',', (string)$payload['UserIds']))));
    $counts = is_array($http['json']) ? fte_diag_payload_counts($http['json']) : [
        'users' => 0,
        'intervals' => 0,
        'punches' => 0,
        'by_identifier' => [],
    ];
    $returned = array_keys($counts['by_identifier']);
    $missingExpected = array_values(array_diff($expected, $returned));
    $unexpectedReturned = array_values(array_diff($returned, $expected));
    $allExpectedPresent = count($missingExpected) === 0;
    $processingStartedAt = hrtime(true);
    $attendance = [];
    if (is_array($http['json'])) {
        fte_merge_attendance_payload($attendance, $http['json']);
    }
    $processingSeconds = fte_diag_seconds($processingStartedAt);

    $safeCountsByAlias = [];
    $missingExpectedAliases = [];
    foreach ($expected as $index => $identifier) {
        $alias = count($expected) === 1 ? $workerAlias : 'B' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
        $safeCountsByAlias[$alias] = $counts['by_identifier'][$identifier] ?? ['intervals' => 0, 'punches' => 0];
        if (in_array($identifier, $missingExpected, true)) {
            $missingExpectedAliases[] = $alias;
        }
    }

    unset($http['json']);
    return [
        'run_id' => $runId,
        'provider' => 'GeoVictoria',
        'endpoint' => $endpoint,
        'worker_alias' => $workerAlias,
        'range_type' => $rangeType,
        'range_start' => DateTimeImmutable::createFromFormat('YmdHis', (string)$payload['StartDate'])->format('Y-m-d H:i:s'),
        'range_end' => DateTimeImmutable::createFromFormat('YmdHis', (string)$payload['EndDate'])->format('Y-m-d H:i:s'),
        'attempt' => $attempt,
        'identifier_format' => $identifierFormat,
        'http_status' => $http['status'],
        'transport_errno' => $http['errno'],
        'error_category' => $http['error_category'],
        'error_body_summary' => $http['error_body_summary'],
        'timing' => $http['timing'],
        'bytes_received' => $http['bytes_received'],
        'returned_users' => $counts['users'],
        'returned_intervals' => $counts['intervals'],
        'returned_punches' => $counts['punches'],
        'counts_by_alias' => $safeCountsByAlias,
        'expected_identity_present' => $allExpectedPresent,
        'missing_expected_aliases' => $missingExpectedAliases,
        'unexpected_returned_identity_count' => count($unexpectedReturned),
        'pause_before_request' => $pause,
        'token_renewed' => false,
        'authentication_mode' => 'reused_run_token',
        'processing_seconds' => $processingSeconds,
        '_counts_by_identifier' => $counts['by_identifier'],
        '_attendance_by_identifier' => $attendance,
    ];
}

$options = getopt('', ['help', 'execute', 'date:', 'month:', 'sample:', 'batch-sizes:', 'batch-offset:', 'chunk-size:', 'period-matrix', 'output:']);
if (isset($options['help'])) {
    fte_diag_usage();
    exit(0);
}

$dateText = (string)($options['date'] ?? '2026-08-04');
$monthText = (string)($options['month'] ?? '2026-08');
$sampleSize = max(1, min(5, (int)($options['sample'] ?? 5)));
$batchSizesText = trim((string)($options['batch-sizes'] ?? ''));
$batchOnly = $batchSizesText !== '';
$batchOffset = max(0, (int)($options['batch-offset'] ?? 0));
$chunkSize = max(0, (int)($options['chunk-size'] ?? 0));
$chunkMode = $chunkSize > 0;
$periodMatrixMode = isset($options['period-matrix']);
if (($batchOnly ? 1 : 0) + ($chunkMode ? 1 : 0) + ($periodMatrixMode ? 1 : 0) > 1) {
    fwrite(STDERR, "Usa solo uno entre --batch-sizes, --chunk-size o --period-matrix.\n");
    exit(2);
}
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
if (!$date || $date->format('Y-m-d') !== $dateText) {
    fwrite(STDERR, "Fecha invalida. Usa --date=Y-m-d.\n");
    exit(2);
}
if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $monthText, $monthMatch)) {
    fwrite(STDERR, "Mes invalido. Usa --month=Y-m.\n");
    exit(2);
}

$config = fte_load_config();
fte_assert_buk_config($config);
fte_assert_geovictoria_config($config);
$defaultOutput = dirname(__DIR__, 3) . '/storage/fte_diagnostics/geovictoria_performance_' . date('Ymd_His') . '.json';
$outputPath = (string)($options['output'] ?? $defaultOutput);
$plan = [
    'mode' => isset($options['execute']) ? 'execute' : 'dry_run',
    'historical_day' => $dateText,
    'historical_month' => $monthText,
    'sample_size' => $sampleSize,
    'batch_sizes' => $batchOnly ? $batchSizesText : null,
    'batch_offset' => $batchOnly ? $batchOffset : null,
    'chunk_size' => $chunkMode ? $chunkSize : null,
    'period_matrix' => $periodMatrixMode,
    'attendance_min_interval_seconds' => (float)($config['geovictoria_attendance_min_interval_seconds'] ?? 0.35),
    'attendance_batch_size_configured' => (int)($config['geovictoria_attendance_batch_size'] ?? 1),
    'output_path' => $outputPath,
];

if (!isset($options['execute'])) {
    echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    echo "No se realizaron consultas externas. Agrega --execute para iniciar la muestra controlada.\n";
    exit(0);
}

set_time_limit(0);
$runStartedAt = hrtime(true);
$runId = 'GV-' . gmdate('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(3)));
$secrets = [
    (string)$config['buk_token'],
    (string)$config['geovictoria_user'],
    (string)$config['geovictoria_password'],
];

echo "[$runId] Cargando nomina Buk para seleccionar una muestra anonima...\n";
$bukStartedAt = hrtime(true);
$people = fte_fetch_buk_people($config, false);
$bukSeconds = fte_diag_seconds($bukStartedAt);
$eligible = array_values(array_filter($people, static function (array $person) use ($dateText): bool {
    return fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '') !== ''
        && fte_headcount_person_active_on($person, $dateText);
}));
usort($eligible, static function (array $a, array $b): int {
    $aKey = hash('sha256', fte_normalize_identifier($a['normalized_identifier'] ?? $a['identifier'] ?? ''));
    $bKey = hash('sha256', fte_normalize_identifier($b['normalized_identifier'] ?? $b['identifier'] ?? ''));
    return strcmp($aKey, $bKey);
});
$sample = ($batchOnly || $chunkMode || $periodMatrixMode) ? $eligible : array_slice($eligible, 0, $sampleSize);
if (count($sample) < $sampleSize) {
    throw new RuntimeException('No hay suficientes trabajadores elegibles para la muestra solicitada.');
}
$identifiers = array_map(static fn(array $p): string => fte_normalize_identifier($p['normalized_identifier'] ?? $p['identifier'] ?? ''), $sample);

echo "[$runId] Autenticando una vez en GeoVictoria...\n";
$loginUrl = rtrim((string)$config['geovictoria_base_url'], '/') . '/Login';
$login = fte_diag_http_json(
    $loginUrl,
    [],
    ['User' => (string)$config['geovictoria_user'], 'Password' => (string)$config['geovictoria_password']],
    30,
    $secrets,
    $identifiers
);
$token = is_array($login['json']) ? trim((string)($login['json']['token'] ?? '')) : '';
if (!$login['ok'] || $token === '') {
    $safe = $login;
    unset($safe['json']);
    fwrite(STDERR, "GeoVictoria no entrego un token. Estado: " . $login['status'] . "\n");
    exit(3);
}
$loginSafe = $login;
unset($loginSafe['json']);
$loginSafe['provider'] = 'GeoVictoria';
$loginSafe['endpoint'] = 'Login';
$loginSafe['authentication_mode'] = 'initial';

$dayStart = $date->format('Ymd') . '000000';
$dayEnd = $date->format('Ymd') . '235959';
$monthStartDate = new DateTimeImmutable($monthText . '-01');
$monthEndDate = $monthStartDate->modify('last day of this month');
$monthStart = $monthStartDate->format('Ymd') . '000000';
$monthEnd = $monthEndDate->format('Ymd') . '235959';
$minInterval = max(0.0, (float)($config['geovictoria_attendance_min_interval_seconds'] ?? 0.35));

if ($periodMatrixMode) {
    if ((int)$monthEndDate->format('j') < 31) {
        throw new RuntimeException('La matriz requiere un mes de 31 dias; usa por ejemplo --month=2026-08.');
    }

    $periodPlans = [
        ['days' => 1, 'chunk_size' => 195],
        ['days' => 2, 'chunk_size' => 195],
        ['days' => 7, 'chunk_size' => 195],
        ['days' => 14, 'chunk_size' => 107],
        ['days' => 28, 'chunk_size' => 53],
        ['days' => 30, 'chunk_size' => 50],
        ['days' => 31, 'chunk_size' => 48],
    ];
    $identifierAliases = [];
    foreach ($identifiers as $index => $identifier) {
        $identifierAliases[$identifier] = 'W' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
    }

    $periodResults = [];
    $allMissingIdentifiers = [];
    $requestOrdinal = 0;
    $totalPause = 0.0;
    $globalStopReason = null;

    foreach ($periodPlans as $planIndex => $periodPlan) {
        $days = (int)$periodPlan['days'];
        $periodChunkSize = (int)$periodPlan['chunk_size'];
        $periodStartDate = $monthStartDate;
        $periodEndDate = $monthStartDate->modify('+' . ($days - 1) . ' days');
        $periodStart = $periodStartDate->format('Ymd') . '000000';
        $periodEnd = $periodEndDate->format('Ymd') . '235959';
        $periodChunks = array_chunk($identifiers, $periodChunkSize);
        $periodRequests = [];
        $periodMissing = [];
        $periodStopReason = null;

        foreach ($periodChunks as $chunkIndex => $chunkIdentifiers) {
            $pause = $requestOrdinal > 0
                ? fte_diag_pause($minInterval)
                : ['requested_seconds' => 0.0, 'actual_seconds' => 0.0];
            $requestOrdinal++;
            $totalPause += (float)$pause['actual_seconds'];
            $alias = 'D' . str_pad((string)$days, 2, '0', STR_PAD_LEFT)
                . '-C' . str_pad((string)($chunkIndex + 1), 2, '0', STR_PAD_LEFT);
            echo "[$runId] period_{$days}_days $alias (" . count($chunkIdentifiers) . ")...\n";
            $internal = fte_diag_safe_request(
                $runId,
                $config,
                $token,
                [
                    'StartDate' => $periodStart,
                    'EndDate' => $periodEnd,
                    'UserIds' => implode(',', $chunkIdentifiers),
                ],
                $alias,
                'period_' . $days . '_days',
                1,
                'normalized_comma_separated',
                $pause,
                $identifiers,
                $secrets
            );
            $returnedIdentifiers = array_keys($internal['_counts_by_identifier']);
            $missing = array_values(array_diff($chunkIdentifiers, $returnedIdentifiers));
            unset($internal['_counts_by_identifier'], $internal['_attendance_by_identifier'], $internal['counts_by_alias']);
            $internal['chunk_index'] = $chunkIndex + 1;
            $internal['requested_workers'] = count($chunkIdentifiers);
            $internal['requested_worker_days'] = count($chunkIdentifiers) * $days;
            $internal['missing_expected_aliases'] = array_values(array_map(
                static fn(string $identifier): string => $identifierAliases[$identifier] ?? 'UNKNOWN',
                $missing
            ));
            $internal['request_accepted'] = $internal['http_status'] >= 200 && $internal['http_status'] < 300;
            foreach ($missing as $missingIdentifier) {
                $periodMissing[$missingIdentifier] = $identifierAliases[$missingIdentifier] ?? 'UNKNOWN';
                $allMissingIdentifiers[$missingIdentifier] = $identifierAliases[$missingIdentifier] ?? 'UNKNOWN';
            }
            $periodRequests[] = $internal;

            if ($internal['http_status'] === 429) {
                $periodStopReason = 'http_429';
                $globalStopReason = 'http_429_at_' . $days . '_days_chunk_' . ($chunkIndex + 1);
                break;
            }
            if (!$internal['request_accepted']) {
                $periodStopReason = 'chunk_rejected_' . ($chunkIndex + 1);
                break;
            }
            if ((int)$internal['unexpected_returned_identity_count'] > 0) {
                $periodStopReason = 'unexpected_identities_chunk_' . ($chunkIndex + 1);
                break;
            }
            if ((float)$internal['timing']['monotonic_total_seconds'] >= 70.0) {
                $periodStopReason = 'degradation_threshold_chunk_' . ($chunkIndex + 1);
                break;
            }
        }

        $periodResults[] = [
            'days' => $days,
            'range_start' => $periodStartDate->format('Y-m-d'),
            'range_end' => $periodEndDate->format('Y-m-d'),
            'chunk_size_base' => $periodChunkSize,
            'chunk_sizes' => array_map('count', $periodChunks),
            'expected_requests' => count($periodChunks),
            'completed_requests' => count($periodRequests),
            'all_requests_accepted' => count($periodRequests) === count($periodChunks)
                && array_reduce($periodRequests, static fn(bool $carry, array $row): bool => $carry && $row['request_accepted'], true),
            'missing_identity_aliases' => array_values($periodMissing),
            'stop_reason' => $periodStopReason,
            'requests' => $periodRequests,
        ];

        if ($globalStopReason !== null) {
            break;
        }
    }

    $fallbackRequests = [];
    $confirmedNonexistent = [];
    $validButOmitted = [];
    $unresolvedMissing = [];
    if ($globalStopReason === null && $allMissingIdentifiers) {
        $fallbackStart = $monthStartDate->format('Ymd') . '000000';
        $fallbackEnd = $monthStartDate->format('Ymd') . '235959';
        foreach ($allMissingIdentifiers as $missingIdentifier => $globalAlias) {
            $pause = fte_diag_pause($minInterval);
            $totalPause += (float)$pause['actual_seconds'];
            echo "[$runId] fallback_day $globalAlias...\n";
            $fallback = fte_diag_safe_request(
                $runId,
                $config,
                $token,
                ['StartDate' => $fallbackStart, 'EndDate' => $fallbackEnd, 'UserIds' => $missingIdentifier],
                $globalAlias,
                'fallback_day',
                1,
                'normalized_single',
                $pause,
                $identifiers,
                $secrets
            );
            unset($fallback['_counts_by_identifier'], $fallback['_attendance_by_identifier'], $fallback['counts_by_alias']);
            $fallback['worker_alias'] = $globalAlias;
            $fallbackRequests[] = $fallback;
            if ($fallback['http_status'] >= 200 && $fallback['http_status'] < 300 && $fallback['expected_identity_present']) {
                $validButOmitted[] = $globalAlias;
            } elseif ($fallback['http_status'] === 400
                && str_contains((string)$fallback['error_body_summary'], 'NonExistenceOfData')) {
                $confirmedNonexistent[] = $globalAlias;
            } else {
                $unresolvedMissing[] = $globalAlias;
            }
        }
    }

    $allPlansExecuted = count($periodResults) === count($periodPlans);
    $allRequestsAccepted = $allPlansExecuted && array_reduce(
        $periodResults,
        static fn(bool $carry, array $period): bool => $carry && $period['all_requests_accepted'],
        true
    );
    $matrixConfirmed = $allRequestsAccepted && !$validButOmitted && !$unresolvedMissing;
    $matrixResult = [
        'schema_version' => 1,
        'run_id' => $runId,
        'generated_at' => date(DATE_ATOM),
        'scope' => 'controlled_cli_period_matrix_diagnostic_no_production_behavior_change',
        'privacy' => [
            'personal_data_persisted' => false,
            'tokens_or_passwords_persisted' => false,
            'worker_aliases_only' => true,
        ],
        'sample' => [
            'historical_month' => $monthText,
            'eligibility_reference_date' => $dateText,
            'buk_people_loaded' => count($people),
            'eligible_workers' => count($eligible),
            'period_plans' => $periodPlans,
        ],
        'buk' => [
            'load_and_normalization_seconds' => $bukSeconds,
            'cache_mode' => 'cli_without_php_session_cache',
        ],
        'authentication' => $loginSafe,
        'configuration' => [
            'attendance_min_interval_seconds' => $minInterval,
            'attendance_timeout_seconds' => 75,
            'connect_timeout_seconds' => 10,
            'maximum_requested_users_observed' => 200,
            'maximum_requested_worker_days_observed' => 1500,
        ],
        'period_results' => $periodResults,
        'fallback_requests' => $fallbackRequests,
        'summary' => [
            'matrix_confirmed' => $matrixConfirmed,
            'all_period_plans_executed' => $allPlansExecuted,
            'all_chunk_requests_accepted' => $allRequestsAccepted,
            'periods_completed' => count($periodResults),
            'periods_expected' => count($periodPlans),
            'attendance_requests_completed' => array_sum(array_column($periodResults, 'completed_requests')),
            'unique_missing_identity_count' => count($allMissingIdentifiers),
            'confirmed_nonexistent_in_geovictoria_aliases' => array_values(array_unique($confirmedNonexistent)),
            'valid_but_omitted_by_batch_aliases' => array_values(array_unique($validButOmitted)),
            'unresolved_missing_aliases' => array_values(array_unique($unresolvedMissing)),
            'stop_reason' => $globalStopReason,
            'total_pause_seconds' => round($totalPause, 6),
            'total_run_seconds' => fte_diag_seconds($runStartedAt),
        ],
        'limitations' => [
            'Prueba historica controlada de una ejecucion; no constituye una prueba de carga repetitiva.',
            'No se probo concurrencia ni se modifico el flujo productivo.',
            'Cada respuesta se valido por identidades anonimizadas; HTTP 200 por si solo no se considero suficiente.',
        ],
    ];

    $outputDirectory = dirname($outputPath);
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0770, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('No fue posible crear el directorio de salida.');
    }
    $encoded = json_encode($matrixResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($outputPath, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No fue posible escribir el diagnostico de matriz de periodos.');
    }
    echo "[$runId] Prueba de matriz de periodos finalizada.\n";
    echo "Resultado anonimizado: $outputPath\n";
    echo json_encode($matrixResult['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($matrixConfirmed ? 0 : 4);
}

if ($chunkMode) {
    if ($chunkSize > 200) {
        throw new RuntimeException('GeoVictoria rechazo solicitudes superiores a 200 usuarios; usa un lote menor o igual a 200.');
    }
    $identifierAliases = [];
    foreach ($identifiers as $index => $identifier) {
        $identifierAliases[$identifier] = 'W' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
    }
    $chunks = array_chunk($identifiers, $chunkSize);
    $rangeDefinitions = [
        ['name' => 'day', 'start' => $dayStart, 'end' => $dayEnd],
        ['name' => 'month', 'start' => $monthStart, 'end' => $monthEnd],
    ];
    $chunkRequests = [];
    $requestOrdinal = 0;
    $totalPause = 0.0;
    $missingIdentifiers = [];
    $stopReason = null;

    foreach ($rangeDefinitions as $range) {
        foreach ($chunks as $chunkIndex => $chunkIdentifiers) {
            $pause = $requestOrdinal > 0
                ? fte_diag_pause($minInterval)
                : ['requested_seconds' => 0.0, 'actual_seconds' => 0.0];
            $requestOrdinal++;
            $totalPause += (float)$pause['actual_seconds'];
            $alias = 'C' . str_pad((string)($chunkIndex + 1), 2, '0', STR_PAD_LEFT)
                . '-' . strtoupper((string)$range['name']);
            echo "[$runId] chunk_{$range['name']} $alias (" . count($chunkIdentifiers) . ")...\n";
            $internal = fte_diag_safe_request(
                $runId,
                $config,
                $token,
                [
                    'StartDate' => $range['start'],
                    'EndDate' => $range['end'],
                    'UserIds' => implode(',', $chunkIdentifiers),
                ],
                $alias,
                'chunk_' . $range['name'],
                1,
                'normalized_comma_separated',
                $pause,
                $identifiers,
                $secrets
            );
            $returnedIdentifiers = array_keys($internal['_counts_by_identifier']);
            $missing = array_values(array_diff($chunkIdentifiers, $returnedIdentifiers));
            unset($internal['_counts_by_identifier'], $internal['_attendance_by_identifier'], $internal['counts_by_alias']);
            $internal['chunk_index'] = $chunkIndex + 1;
            $internal['requested_workers'] = count($chunkIdentifiers);
            $internal['missing_expected_aliases'] = array_values(array_map(
                static fn(string $identifier): string => $identifierAliases[$identifier] ?? 'UNKNOWN',
                $missing
            ));
            $internal['request_accepted'] = $internal['http_status'] >= 200 && $internal['http_status'] < 300;
            if ($internal['request_accepted']) {
                foreach ($missing as $missingIdentifier) {
                    $missingIdentifiers[$missingIdentifier] = $identifierAliases[$missingIdentifier] ?? 'UNKNOWN';
                }
            }
            $chunkRequests[] = $internal;

            if ($internal['http_status'] === 429) {
                $stopReason = 'http_429';
                break 2;
            }
            if (!$internal['request_accepted']) {
                $stopReason = 'chunk_rejected_' . $range['name'] . '_' . ($chunkIndex + 1);
                break 2;
            }
            if ((int)$internal['unexpected_returned_identity_count'] > 0) {
                $stopReason = 'unexpected_identities_' . $range['name'] . '_' . ($chunkIndex + 1);
                break 2;
            }
            if ((float)$internal['timing']['monotonic_total_seconds'] >= 70.0) {
                $stopReason = 'chunk_degradation_threshold_' . $range['name'] . '_' . ($chunkIndex + 1);
                break 2;
            }
        }
    }

    $fallbackRequests = [];
    $confirmedNonexistent = [];
    $validButOmitted = [];
    $unresolvedMissing = [];
    if ($stopReason !== 'http_429') {
        foreach ($missingIdentifiers as $missingIdentifier => $globalAlias) {
            $pause = fte_diag_pause($minInterval);
            $totalPause += (float)$pause['actual_seconds'];
            echo "[$runId] fallback_day $globalAlias...\n";
            $fallback = fte_diag_safe_request(
                $runId,
                $config,
                $token,
                ['StartDate' => $dayStart, 'EndDate' => $dayEnd, 'UserIds' => $missingIdentifier],
                $globalAlias,
                'fallback_day',
                1,
                'normalized_single',
                $pause,
                $identifiers,
                $secrets
            );
            unset($fallback['_counts_by_identifier'], $fallback['_attendance_by_identifier'], $fallback['counts_by_alias']);
            $fallback['worker_alias'] = $globalAlias;
            $fallbackRequests[] = $fallback;
            if ($fallback['http_status'] >= 200 && $fallback['http_status'] < 300 && $fallback['expected_identity_present']) {
                $validButOmitted[] = $globalAlias;
            } elseif ($fallback['http_status'] === 400
                && str_contains((string)$fallback['error_body_summary'], 'NonExistenceOfData')) {
                $confirmedNonexistent[] = $globalAlias;
            } else {
                $unresolvedMissing[] = $globalAlias;
            }
        }
    }

    $expectedChunkRequests = count($rangeDefinitions) * count($chunks);
    $twoChunkSchemeConfirmed = $stopReason === null
        && count($chunks) === 2
        && count($chunkRequests) === $expectedChunkRequests
        && !$validButOmitted
        && !$unresolvedMissing;
    $chunkResult = [
        'schema_version' => 1,
        'run_id' => $runId,
        'generated_at' => date(DATE_ATOM),
        'scope' => 'controlled_cli_two_chunk_day_and_month_diagnostic_no_production_behavior_change',
        'privacy' => [
            'personal_data_persisted' => false,
            'tokens_or_passwords_persisted' => false,
            'worker_aliases_only' => true,
        ],
        'sample' => [
            'historical_day' => $dateText,
            'historical_month' => $monthText,
            'buk_people_loaded' => count($people),
            'eligible_workers' => count($eligible),
            'chunk_size_base' => $chunkSize,
            'chunk_sizes' => array_map('count', $chunks),
        ],
        'buk' => [
            'load_and_normalization_seconds' => $bukSeconds,
            'cache_mode' => 'cli_without_php_session_cache',
        ],
        'authentication' => $loginSafe,
        'configuration' => [
            'attendance_min_interval_seconds' => $minInterval,
            'attendance_timeout_seconds' => 75,
            'connect_timeout_seconds' => 10,
        ],
        'chunk_requests' => $chunkRequests,
        'fallback_requests' => $fallbackRequests,
        'summary' => [
            'two_chunk_scheme_confirmed_for_day_and_month' => $twoChunkSchemeConfirmed,
            'completed_chunk_requests' => count($chunkRequests),
            'expected_chunk_requests' => $expectedChunkRequests,
            'unique_missing_identity_count' => count($missingIdentifiers),
            'confirmed_nonexistent_in_geovictoria_aliases' => array_values(array_unique($confirmedNonexistent)),
            'valid_but_omitted_by_batch_aliases' => array_values(array_unique($validButOmitted)),
            'unresolved_missing_aliases' => array_values(array_unique($unresolvedMissing)),
            'stop_reason' => $stopReason,
            'total_pause_seconds' => round($totalPause, 6),
            'total_run_seconds' => fte_diag_seconds($runStartedAt),
        ],
        'limitations' => [
            'Capacidad comprobada con un dia y un mes historicos; no constituye prueba de carga repetitiva.',
            'No se probo concurrencia ni se modifico el flujo productivo.',
            'El tamano 195 queda sujeto a validar identidades devueltas en cada ejecucion.',
        ],
    ];

    $outputDirectory = dirname($outputPath);
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0770, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('No fue posible crear el directorio de salida.');
    }
    $encoded = json_encode($chunkResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($outputPath, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No fue posible escribir el diagnostico de lotes.');
    }
    echo "[$runId] Prueba de dos lotes finalizada.\n";
    echo "Resultado anonimizado: $outputPath\n";
    echo json_encode($chunkResult['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($twoChunkSchemeConfirmed ? 0 : 4);
}

if ($batchOnly) {
    $tokens = array_values(array_filter(array_map('trim', explode(',', strtolower($batchSizesText)))));
    $availableAfterOffset = max(0, count($identifiers) - $batchOffset);
    $sizes = [];
    foreach ($tokens as $sizeToken) {
        $size = $sizeToken === 'all' ? $availableAfterOffset : (int)$sizeToken;
        if ($size < 1 || $size > $availableAfterOffset) {
            throw new RuntimeException('Tamano de lote invalido: ' . $sizeToken);
        }
        if (!in_array($size, $sizes, true)) {
            $sizes[] = $size;
        }
    }
    if (!$sizes) {
        throw new RuntimeException('No se indicaron tamanos de lote validos.');
    }

    $batchRequests = [];
    $stopReason = null;
    $totalPause = 0.0;
    foreach ($sizes as $index => $size) {
        $pause = $index > 0
            ? fte_diag_pause($minInterval)
            : ['requested_seconds' => 0.0, 'actual_seconds' => 0.0];
        $totalPause += (float)$pause['actual_seconds'];
        $alias = 'B' . str_pad((string)$size, 3, '0', STR_PAD_LEFT);
        echo "[$runId] batch_day $alias...\n";
        $batchInternal = fte_diag_safe_request(
            $runId,
            $config,
            $token,
            [
                'StartDate' => $dayStart,
                'EndDate' => $dayEnd,
                'UserIds' => implode(',', array_slice($identifiers, $batchOffset, $size)),
            ],
            $alias,
            'batch_day',
            1,
            'normalized_comma_separated',
            $pause,
            $identifiers,
            $secrets
        );
        unset($batchInternal['_counts_by_identifier'], $batchInternal['_attendance_by_identifier']);
        $exactIdentitySet = $batchInternal['expected_identity_present']
            && (int)$batchInternal['returned_users'] === $size;
        $batchInternal['requested_workers'] = $size;
        $batchInternal['exact_identity_set_returned'] = $exactIdentitySet;
        $batchInternal['result'] = $batchInternal['http_status'] >= 200 && $batchInternal['http_status'] < 300
            ? ($exactIdentitySet ? 'confirmed' : 'http_success_but_incomplete_or_extra')
            : 'rejected';
        $batchRequests[] = $batchInternal;

        if ($batchInternal['http_status'] === 429) {
            $stopReason = 'http_429';
            break;
        }
        if ($batchInternal['result'] !== 'confirmed') {
            $stopReason = 'batch_not_confirmed_at_' . $size;
            break;
        }
        if ((float)$batchInternal['timing']['monotonic_total_seconds'] >= 60.0) {
            $stopReason = 'batch_degradation_threshold_at_' . $size;
            break;
        }
    }

    $batchResult = [
        'schema_version' => 1,
        'run_id' => $runId,
        'generated_at' => date(DATE_ATOM),
        'scope' => 'controlled_cli_progressive_batch_diagnostic_no_production_behavior_change',
        'privacy' => [
            'personal_data_persisted' => false,
            'tokens_or_passwords_persisted' => false,
            'worker_aliases_only' => true,
        ],
        'sample' => [
            'historical_day' => $dateText,
            'buk_people_loaded' => count($people),
            'eligible_workers' => count($eligible),
            'requested_batch_sizes' => $sizes,
            'batch_offset' => $batchOffset,
        ],
        'buk' => [
            'load_and_normalization_seconds' => $bukSeconds,
            'cache_mode' => 'cli_without_php_session_cache',
        ],
        'authentication' => $loginSafe,
        'configuration' => [
            'attendance_min_interval_seconds' => $minInterval,
            'attendance_timeout_seconds' => 75,
            'connect_timeout_seconds' => 10,
        ],
        'batch_requests' => $batchRequests,
        'summary' => [
            'completed_all_requested_sizes' => count($batchRequests) === count($sizes) && $stopReason === null,
            'largest_confirmed_batch' => max(array_merge([0], array_map(
                static fn(array $row): int => $row['result'] === 'confirmed' ? (int)$row['requested_workers'] : 0,
                $batchRequests
            ))),
            'stop_reason' => $stopReason,
            'total_pause_seconds' => round($totalPause, 6),
            'total_run_seconds' => fte_diag_seconds($runStartedAt),
        ],
        'limitations' => [
            'Prueba de capacidad observada para un unico dia historico; no establece limites contractuales.',
            'No se probo concurrencia ni se intento descubrir el limite mediante carga repetitiva.',
            'Un lote exitoso no garantiza igual comportamiento para un mes o un payload de mayor volumen.',
        ],
    ];

    $outputDirectory = dirname($outputPath);
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0770, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('No fue posible crear el directorio de salida.');
    }
    $encoded = json_encode($batchResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($outputPath, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('No fue posible escribir el diagnostico de lotes.');
    }
    echo "[$runId] Prueba progresiva finalizada.\n";
    echo "Resultado anonimizado: $outputPath\n";
    echo json_encode($batchResult['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($stopReason === null ? 0 : 4);
}

$requests = [];
$individualDayCounts = [];
$individualDayAttendance = [];
$requestOrdinal = 0;
$stopReason = null;

$executeAttendance = static function (
    string $identifier,
    string $alias,
    string $start,
    string $end,
    string $rangeType
) use (&$requests, &$requestOrdinal, &$stopReason, $runId, $config, $token, $minInterval, $identifiers, $secrets): ?array {
    $pause = $requestOrdinal > 0 ? fte_diag_pause($minInterval) : ['requested_seconds' => 0.0, 'actual_seconds' => 0.0];
    $requestOrdinal++;
    echo "[$runId] $rangeType $alias...\n";
    $result = fte_diag_safe_request(
        $runId,
        $config,
        $token,
        ['StartDate' => $start, 'EndDate' => $end, 'UserIds' => $identifier],
        $alias,
        $rangeType,
        1,
        'normalized_single',
        $pause,
        $identifiers,
        $secrets
    );
    $internalCounts = $result['_counts_by_identifier'];
    $internalAttendance = $result['_attendance_by_identifier'];
    unset($result['_counts_by_identifier'], $result['_attendance_by_identifier']);
    $requests[] = $result;
    if ($result['http_status'] === 429) {
        $stopReason = 'http_429';
    } elseif (($result['timing']['monotonic_total_seconds'] ?? 0) >= 60) {
        $stopReason = 'single_request_degradation_threshold';
    }
    return ['safe' => $result, 'counts' => $internalCounts, 'attendance' => $internalAttendance];
};

foreach ($identifiers as $index => $identifier) {
    $alias = 'W' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
    $result = $executeAttendance($identifier, $alias, $dayStart, $dayEnd, 'single_day');
    if ($result !== null) {
        $individualDayCounts[$identifier] = $result['counts'][$identifier] ?? ['intervals' => 0, 'punches' => 0];
        $individualDayAttendance[$identifier] = $result['attendance'][$identifier] ?? [];
    }
    if ($stopReason !== null) {
        break;
    }
}

if ($stopReason === null) {
    foreach ($identifiers as $index => $identifier) {
        $alias = 'W' . str_pad((string)($index + 1), 3, '0', STR_PAD_LEFT);
        $executeAttendance($identifier, $alias, $monthStart, $monthEnd, 'single_month');
        if ($stopReason !== null) {
            break;
        }
    }
}

$batchConclusion = [
    'tested' => false,
    'format' => 'comma_separated_without_spaces',
    'requested_workers' => min(2, count($identifiers)),
    'http_status' => null,
    'all_expected_identities_present' => false,
    'counts_equivalent_to_individual_day_requests' => false,
    'result' => 'not_executed',
];
if ($stopReason === null && count($identifiers) >= 2) {
    $batchIdentifiers = array_slice($identifiers, 0, 2);
    $pause = fte_diag_pause($minInterval);
    $requestOrdinal++;
    echo "[$runId] multi_day B002...\n";
    $batchInternal = fte_diag_safe_request(
        $runId,
        $config,
        $token,
        ['StartDate' => $dayStart, 'EndDate' => $dayEnd, 'UserIds' => implode(',', $batchIdentifiers)],
        'B002',
        'multi_day',
        1,
        'normalized_comma_separated',
        $pause,
        $identifiers,
        $secrets
    );
    $batchCounts = $batchInternal['_counts_by_identifier'];
    $batchAttendance = $batchInternal['_attendance_by_identifier'];
    unset($batchInternal['_counts_by_identifier'], $batchInternal['_attendance_by_identifier']);
    $requests[] = $batchInternal;
    $equivalent = true;
    foreach ($batchIdentifiers as $identifier) {
        if (($batchCounts[$identifier] ?? null) !== ($individualDayCounts[$identifier] ?? null)) {
            $equivalent = false;
            break;
        }
    }
    $attendanceEquivalent = true;
    foreach ($batchIdentifiers as $identifier) {
        if (($batchAttendance[$identifier] ?? null) !== ($individualDayAttendance[$identifier] ?? null)) {
            $attendanceEquivalent = false;
            break;
        }
    }
    $batchConclusion = [
        'tested' => true,
        'format' => 'comma_separated_without_spaces',
        'requested_workers' => 2,
        'http_status' => $batchInternal['http_status'],
        'all_expected_identities_present' => $batchInternal['expected_identity_present'],
        'counts_equivalent_to_individual_day_requests' => $equivalent,
        'attendance_fields_equivalent_to_individual_day_requests' => $attendanceEquivalent,
        'result' => $batchInternal['http_status'] >= 200 && $batchInternal['http_status'] < 300
            ? ($batchInternal['expected_identity_present'] && $equivalent && $attendanceEquivalent ? 'confirmed_for_two_workers' : 'http_success_but_not_equivalent')
            : 'rejected_for_tested_format',
        'error_body_summary' => $batchInternal['error_body_summary'],
    ];
}

$successfulSingleDayTimes = [];
$successfulSingleMonthTimes = [];
$totalPause = 0.0;
$totalHttp = (float)($loginSafe['timing']['monotonic_total_seconds'] ?? 0.0);
$totalProcessing = 0.0;
foreach ($requests as $request) {
    $duration = (float)($request['timing']['monotonic_total_seconds'] ?? 0.0);
    $totalHttp += $duration;
    $totalPause += (float)($request['pause_before_request']['actual_seconds'] ?? 0.0);
    $totalProcessing += (float)($request['processing_seconds'] ?? 0.0) + (float)($request['timing']['json_decode_seconds'] ?? 0.0);
    if ($request['http_status'] >= 200 && $request['http_status'] < 300) {
        if ($request['range_type'] === 'single_day') {
            $successfulSingleDayTimes[] = $duration;
        } elseif ($request['range_type'] === 'single_month') {
            $successfulSingleMonthTimes[] = $duration;
        }
    }
}
$monthMedian = fte_diag_percentile($successfulSingleMonthTimes, 50);
$dayMedian = fte_diag_percentile($successfulSingleDayTimes, 50);
$eligibleCount = count($eligible);
$configuredPayrollEstimate = 236;
$estimate = static function (?float $median, int $workers, float $interval): ?array {
    if ($median === null) {
        return null;
    }
    $pause = max(0, $workers - 1) * $interval;
    $http = $workers * $median;
    return [
        'workers' => $workers,
        'assumption' => 'sequential_single_request_using_sample_median_without_retries',
        'estimated_http_seconds' => round($http, 2),
        'estimated_pause_seconds' => round($pause, 2),
        'estimated_total_seconds_excluding_buk_ui_and_serialization' => round($http + $pause, 2),
    ];
};

$result = [
    'schema_version' => 1,
    'run_id' => $runId,
    'generated_at' => date(DATE_ATOM),
    'scope' => 'controlled_cli_diagnostic_no_production_behavior_change',
    'privacy' => [
        'personal_data_persisted' => false,
        'tokens_or_passwords_persisted' => false,
        'worker_aliases_only' => true,
    ],
    'configuration' => [
        'geovictoria_host' => parse_url((string)$config['geovictoria_base_url'], PHP_URL_HOST),
        'attendance_min_interval_seconds' => $minInterval,
        'attendance_batch_size_configured' => (int)($config['geovictoria_attendance_batch_size'] ?? 1),
        'attendance_timeout_seconds' => 75,
        'login_timeout_seconds' => 30,
        'connect_timeout_seconds' => 10,
        'token_local_ttl_seconds' => (int)($config['geovictoria_token_ttl_seconds'] ?? 1200),
    ],
    'sample' => [
        'historical_day' => $dateText,
        'historical_month' => $monthText,
        'buk_people_loaded' => count($people),
        'eligible_on_historical_day' => $eligibleCount,
        'selected_workers' => count($sample),
        'aliases' => array_map(static fn(int $i): string => 'W' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT), array_keys($sample)),
    ],
    'buk' => [
        'load_and_normalization_seconds' => $bukSeconds,
        'cache_mode' => 'cli_without_php_session_cache',
    ],
    'authentication' => $loginSafe,
    'requests' => $requests,
    'multiple_query' => $batchConclusion,
    'observed_summary' => [
        'stopped_early' => $stopReason !== null,
        'stop_reason' => $stopReason,
        'request_count' => count($requests),
        'single_day_observation_count' => count($successfulSingleDayTimes),
        'single_month_observation_count' => count($successfulSingleMonthTimes),
        'single_day_seconds' => [
            'min' => fte_diag_percentile($successfulSingleDayTimes, 0),
            'median' => $dayMedian,
            'p95' => fte_diag_percentile($successfulSingleDayTimes, 95),
            'max' => fte_diag_percentile($successfulSingleDayTimes, 100),
        ],
        'single_month_seconds' => [
            'min' => fte_diag_percentile($successfulSingleMonthTimes, 0),
            'median' => $monthMedian,
            'p95' => fte_diag_percentile($successfulSingleMonthTimes, 95),
            'max' => fte_diag_percentile($successfulSingleMonthTimes, 100),
        ],
        'total_measured_http_seconds_including_login' => round($totalHttp, 6),
        'total_measured_pause_seconds' => round($totalPause, 6),
        'total_measured_json_and_merge_seconds' => round($totalProcessing, 6),
        'total_run_seconds' => fte_diag_seconds($runStartedAt),
    ],
    'estimates' => [
        'eligible_payroll_day_using_day_median' => $estimate($dayMedian, $eligibleCount, $minInterval),
        'eligible_payroll_month_using_month_median' => $estimate($monthMedian, $eligibleCount, $minInterval),
        'reference_236_day_using_day_median' => $estimate($dayMedian, $configuredPayrollEstimate, $minInterval),
        'reference_236_month_using_month_median' => $estimate($monthMedian, $configuredPayrollEstimate, $minInterval),
    ],
    'limitations' => [
        'Muestra pequena y controlada; no constituye prueba de carga.',
        'No se midieron limites contractuales ni concurrencia porque no estan documentados para la cuenta.',
        'La estimacion usa la mediana observada y no predice variaciones del proveedor.',
        'El tiempo Buk se midio sin cache de sesion PHP por ejecutarse desde CLI.',
    ],
];

$outputDirectory = dirname($outputPath);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0770, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('No fue posible crear el directorio de salida.');
}
$encoded = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($outputPath, $encoded . PHP_EOL, LOCK_EX) === false) {
    throw new RuntimeException('No fue posible escribir el diagnostico.');
}

echo "[$runId] Diagnostico finalizado.\n";
echo "Resultado anonimizado: $outputPath\n";
echo json_encode([
    'buk_seconds' => $bukSeconds,
    'day_median_seconds' => $dayMedian,
    'month_median_seconds' => $monthMedian,
    'pause_seconds' => round($totalPause, 3),
    'multiple_query' => $batchConclusion['result'],
    'total_run_seconds' => $result['observed_summary']['total_run_seconds'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
