<?php
declare(strict_types=1);

/** Prueba CLI de solo lectura del flujo productivo por lotes de hasta 31 dias. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/fte_lib.php';

$options = getopt('', ['execute', 'extended']);
if (!isset($options['execute'])) {
    echo "Prueba preparada. Usa --execute para 1 y 7 dias, o --execute --extended para 8, 14, 28, 30 y 31 dias.\n";
    exit(0);
}

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_id('fteshort' . strtolower(bin2hex(random_bytes(8))));
session_start();
set_time_limit(0);

$config = fte_load_config();
fte_assert_buk_config($config);
fte_assert_geovictoria_config($config);
$peopleStartedAt = hrtime(true);
$people = fte_fetch_buk_people($config, false);
$people = array_values(array_filter($people, static function (array $person): bool {
    return fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '') !== ''
        && fte_headcount_person_active_on($person, '2026-08-04');
}));
$peopleSeconds = round((hrtime(true) - $peopleStartedAt) / 1_000_000_000, 6);

$ranges = isset($options['extended'])
    ? [
        ['name' => 'eight_days', 'from' => '2026-08-01', 'to' => '2026-08-08'],
        ['name' => 'fourteen_days', 'from' => '2026-08-01', 'to' => '2026-08-14'],
        ['name' => 'twenty_eight_days', 'from' => '2026-08-01', 'to' => '2026-08-28'],
        ['name' => 'thirty_days', 'from' => '2026-08-01', 'to' => '2026-08-30'],
        ['name' => 'thirty_one_days', 'from' => '2026-08-01', 'to' => '2026-08-31'],
    ]
    : [
        ['name' => 'one_day', 'from' => '2026-08-04', 'to' => '2026-08-04'],
        ['name' => 'seven_days', 'from' => '2026-08-01', 'to' => '2026-08-07'],
    ];
$results = [];
foreach ($ranges as $range) {
    $rangeConfig = $config;
    $rangeDays = (int)(new DateTimeImmutable($range['from']))->diff(new DateTimeImmutable($range['to']))->days + 1;
    $timeoutSeconds = fte_attendance_timeout_seconds($rangeConfig, $rangeDays);
    $rangeConfig['_request_deadline_at'] = microtime(true) + $timeoutSeconds;
    $diagnostics = [];
    $startedAt = hrtime(true);
    try {
        fte_fetch_attendance(
            $rangeConfig,
            $people,
            new DateTimeImmutable($range['from']),
            new DateTimeImmutable($range['to']),
            $diagnostics
        );
        $error = null;
    } catch (FteAttendanceBatchException $exception) {
        $error = $exception->reason();
    }
    $results[] = [
        'range' => $range['name'],
        'from' => $range['from'],
        'to' => $range['to'],
        'workers' => count($people),
        'timeout_seconds' => $timeoutSeconds,
        'ok' => $error === null,
        'error_reason' => $error,
        'seconds' => round((hrtime(true) - $startedAt) / 1_000_000_000, 6),
        'strategy' => $diagnostics['strategy'] ?? null,
        'batch_size' => $diagnostics['batch_size'] ?? null,
        'request_count' => $diagnostics['request_count'] ?? null,
        'batch_requests' => $diagnostics['batch_requests'] ?? null,
        'fallback_requests' => $diagnostics['fallback_requests'] ?? null,
        'cached_unmatched_count' => $diagnostics['cached_unmatched_count'] ?? null,
        'successful_count' => count($diagnostics['successful_identifiers'] ?? []),
        'failed_count' => count($diagnostics['failed_identifiers'] ?? []),
        'unmatched_count' => count($diagnostics['unmatched_identifiers'] ?? []),
    ];
}

$_SESSION = [];
session_destroy();
echo json_encode([
    'scope' => 'read_only_anonymized_production_batch_smoke',
    'people_load_seconds' => $peopleSeconds,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(array_reduce($results, static fn(bool $ok, array $row): bool => $ok && $row['ok'], true) ? 0 : 4);
