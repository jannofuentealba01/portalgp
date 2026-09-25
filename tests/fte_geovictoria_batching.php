<?php
declare(strict_types=1);

require __DIR__ . '/../rrhh/fte/fte_lib.php';

ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_id('ftebatchtest' . strtolower(bin2hex(random_bytes(6))));
session_start();

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('Fallo: ' . $message);
    }
    $passed++;
    echo "[OK] {$message}\n";
};

$makePeople = static function (int $count): array {
    $people = [];
    for ($index = 1; $index <= $count; $index++) {
        $identifier = (string)(10000000 + $index);
        $people[] = [
            'identifier' => $identifier,
            'normalized_identifier' => $identifier,
            'person_name' => 'Persona ' . $index,
        ];
    }
    return $people;
};

$baseConfig = [
    'geovictoria_attendance_batch_size' => 195,
    'geovictoria_attendance_batch_max_range_days' => 31,
    'geovictoria_attendance_max_records_per_request' => 1500,
    'geovictoria_short_range_timeout_seconds' => 45,
    'geovictoria_month_range_timeout_seconds' => 90,
    'geovictoria_attendance_min_interval_seconds' => 0,
];

$assert(fte_attendance_batch_size($baseConfig, 1) === 195, 'un dia utiliza lotes de 195');
$assert(fte_attendance_batch_size($baseConfig, 7) === 195, 'siete dias utilizan lotes de 195');
$assert(fte_attendance_batch_size($baseConfig, 8) === 187, 'ocho dias ajustan el lote a 187 personas');
$assert(fte_attendance_batch_size($baseConfig, 14) === 107, 'catorce dias utilizan lotes de 107');
$assert(fte_attendance_batch_size($baseConfig, 28) === 53, 'veintiocho dias utilizan lotes de 53');
$assert(fte_attendance_batch_size($baseConfig, 30) === 50, 'treinta dias utilizan lotes de 50');
$assert(fte_attendance_batch_size($baseConfig, 31) === 48, 'treinta y un dias utilizan lotes de 48');
$assert(fte_attendance_batch_size($baseConfig, 32) === 0, 'mas de un mes conserva el flujo individual');
$assert(fte_attendance_timeout_seconds($baseConfig, 7) === 45, 'hasta siete dias aplica el limite de 45 segundos');
$assert(fte_attendance_timeout_seconds($baseConfig, 8) === 90, 'desde ocho dias aplica el limite de 90 segundos');
$assert(fte_attendance_timeout_seconds($baseConfig, 31) === 90, 'un mes completo mantiene el limite de 90 segundos');
$assert(fte_attendance_timeout_seconds($baseConfig, 32) === 0, 'el limite mensual no altera periodos superiores a 31 dias');
$limitedConfig = $baseConfig;
$limitedConfig['geovictoria_attendance_max_records_per_request'] = 700;
$assert(fte_attendance_batch_size($limitedConfig, 7) === 100, 'el lote respeta el maximo de registros usuario-dia');
$assert(fte_geovictoria_failure_reason(['status' => 400, 'errno' => 0, 'json' => ['Code' => '0007', 'CategoryException' => 'NonExistenceOfData']]) === 'nonexistent_identity', 'reconoce una identidad inexistente');
$assert(fte_geovictoria_failure_reason(['status' => 400, 'errno' => 0, 'json' => ['Code' => '0008', 'CategoryException' => 'OutOfLimitException']]) === 'out_of_limit', 'reconoce el limite de registros');
$assert(fte_geovictoria_failure_reason(['status' => 429, 'errno' => 0, 'json' => []]) === 'rate_limit', 'reconoce la limitacion temporal del proveedor');
$assert(fte_geovictoria_failure_reason(['status' => 0, 'errno' => CURLE_OPERATION_TIMEDOUT, 'json' => null]) === 'timeout', 'reconoce un timeout de transporte');

$calls = [];
$missing = ['10000071', '10000077'];
$config = $baseConfig;
$config['_geovictoria_post_handler'] = static function (string $endpoint, array $payload, int $timeout) use (&$calls, $missing): array {
    $ids = array_values(array_filter(explode(',', (string)$payload['UserIds'])));
    $calls[] = ['endpoint' => $endpoint, 'ids' => $ids, 'timeout' => $timeout];
    if (count($ids) === 1 && in_array($ids[0], $missing, true)) {
        throw new FteGeoVictoriaException('nonexistent_identity', 400);
    }
    return [
        'Users' => array_values(array_map(
            static fn(string $identifier): array => ['Identifier' => $identifier, 'PlannedInterval' => []],
            array_values(array_diff($ids, $missing))
        )),
    ];
};
$diagnostics = [];
fte_fetch_attendance(
    $config,
    $makePeople(226),
    new DateTimeImmutable('2026-08-01'),
    new DateTimeImmutable('2026-08-07'),
    $diagnostics
);
$multiSizes = array_values(array_map(
    static fn(array $call): int => count($call['ids']),
    array_filter($calls, static fn(array $call): bool => count($call['ids']) > 1)
));
$assert($multiSizes === [195, 31], '226 personas se distribuyen en 195 y 31');
$assert(($diagnostics['strategy'] ?? '') === 'batch', 'el diagnostico identifica la estrategia por lotes');
$assert((int)($diagnostics['request_count'] ?? 0) === 4, 'registra dos lotes y dos comprobaciones puntuales');
$assert((int)($diagnostics['fallback_requests'] ?? 0) === 2, 'solo reconsulta las dos identidades omitidas');
$assert(count($diagnostics['successful_identifiers'] ?? []) === 226, 'clasifica las 226 respuestas sin perder personas');
$assert(($diagnostics['unmatched_identifiers'] ?? []) === $missing, 'distingue identidades inexistentes de una falla del proveedor');
$assert(($diagnostics['failed_identifiers'] ?? []) === [], 'no marca como caida de proveedor una identidad inexistente');

$calls = [];
$cachedDiagnostics = [];
fte_fetch_attendance(
    $config,
    $makePeople(226),
    new DateTimeImmutable('2026-08-01'),
    new DateTimeImmutable('2026-08-07'),
    $cachedDiagnostics
);
$assert((int)($cachedDiagnostics['fallback_requests'] ?? -1) === 0, 'no repite el fallback de identidades inexistentes ya comprobadas');
$assert((int)($cachedDiagnostics['cached_unmatched_count'] ?? 0) === 2, 'registra las identidades no conciliadas reutilizadas desde cache');
$serializedUnmatchedCache = json_encode($_SESSION['fte_geovictoria_unmatched_cache'] ?? []);
$assert(is_string($serializedUnmatchedCache) && !str_contains($serializedUnmatchedCache, $missing[0]), 'la cache de no conciliados guarda hashes y no identificadores');

$expectedDistributions = [
    8 => [187, 39],
    14 => [107, 107, 12],
    28 => [53, 53, 53, 53, 14],
    30 => [50, 50, 50, 50, 26],
    31 => [48, 48, 48, 48, 34],
];
foreach ($expectedDistributions as $rangeDays => $expectedSizes) {
    $rangeCalls = [];
    $rangeConfig = $baseConfig;
    $rangeConfig['_geovictoria_post_handler'] = static function (string $endpoint, array $payload, int $timeout) use (&$rangeCalls): array {
        $ids = array_values(array_filter(explode(',', (string)$payload['UserIds'])));
        $rangeCalls[] = ['ids' => $ids, 'timeout' => $timeout];
        return ['Users' => array_map(
            static fn(string $identifier): array => ['Identifier' => $identifier, 'PlannedInterval' => []],
            $ids
        )];
    };
    $rangeDiagnostics = [];
    $rangeFrom = new DateTimeImmutable('2026-08-01');
    fte_fetch_attendance(
        $rangeConfig,
        $makePeople(226),
        $rangeFrom,
        $rangeFrom->modify('+' . ($rangeDays - 1) . ' days'),
        $rangeDiagnostics
    );
    $actualSizes = array_map(static fn(array $call): int => count($call['ids']), $rangeCalls);
    $assert($actualSizes === $expectedSizes, "{$rangeDays} dias distribuyen las 226 personas sin superar 1500 registros por peticion");
    $assert(array_reduce($rangeCalls, static fn(bool $valid, array $call): bool => $valid && $call['timeout'] <= 75, true), "{$rangeDays} dias respetan el timeout maximo por peticion");
}

$splitCalls = [];
$splitConfig = $baseConfig;
$splitConfig['geovictoria_attendance_batch_size'] = 5;
$splitConfig['_geovictoria_post_handler'] = static function (string $endpoint, array $payload, int $timeout) use (&$splitCalls): array {
    $ids = array_values(array_filter(explode(',', (string)$payload['UserIds'])));
    $splitCalls[] = count($ids);
    if (count($ids) > 2) {
        throw new FteGeoVictoriaException('out_of_limit', 400);
    }
    return ['Users' => array_map(
        static fn(string $identifier): array => ['Identifier' => $identifier, 'PlannedInterval' => []],
        $ids
    )];
};
$splitDiagnostics = [];
fte_fetch_attendance(
    $splitConfig,
    $makePeople(5),
    new DateTimeImmutable('2026-08-01'),
    new DateTimeImmutable('2026-08-01'),
    $splitDiagnostics
);
$assert($splitCalls === [5, 3, 2, 1, 2], 'divide progresivamente un lote rechazado sin perder cobertura');
$assert(count($splitDiagnostics['successful_identifiers'] ?? []) === 5, 'la division recupera todas las identidades validas');

$integrityConfig = $baseConfig;
$integrityConfig['_geovictoria_post_handler'] = static function (string $endpoint, array $payload, int $timeout): array {
    $requested = (string)$payload['UserIds'];
    return ['Users' => [
        ['Identifier' => $requested, 'PlannedInterval' => []],
        ['Identifier' => '99999999', 'PlannedInterval' => []],
    ]];
};
try {
    $unused = [];
    fte_fetch_attendance(
        $integrityConfig,
        $makePeople(1),
        new DateTimeImmutable('2026-08-01'),
        new DateTimeImmutable('2026-08-01'),
        $unused
    );
    $integrityRejected = false;
} catch (FteAttendanceBatchException $exception) {
    $integrityRejected = $exception->reason() === 'identity_integrity';
}
$assert($integrityRejected, 'rechaza una respuesta que contiene identidades no solicitadas');

$deadlineCalls = 0;
$deadlineConfig = $baseConfig;
$deadlineConfig['_request_deadline_at'] = microtime(true) - 1;
$deadlineConfig['_geovictoria_post_handler'] = static function () use (&$deadlineCalls): array {
    $deadlineCalls++;
    return ['Users' => []];
};
try {
    $unused = [];
    fte_fetch_attendance(
        $deadlineConfig,
        $makePeople(1),
        new DateTimeImmutable('2026-08-01'),
        new DateTimeImmutable('2026-08-01'),
        $unused
    );
    $deadlineRejected = false;
} catch (FteAttendanceBatchException $exception) {
    $deadlineRejected = $exception->reason() === 'deadline';
}
$assert($deadlineRejected && $deadlineCalls === 0, 'detiene la consulta vencida antes de llamar al proveedor');

$noDeadlineCalls = 0;
$noDeadlineTimeout = 0;
$noDeadlineConfig = $baseConfig;
$noDeadlineConfig['_request_deadline_at'] = microtime(true) - 1;
$noDeadlineConfig['_attendance_total_deadline_disabled'] = true;
$noDeadlineConfig['_geovictoria_post_handler'] = static function (string $endpoint, array $payload, int $timeout) use (&$noDeadlineCalls, &$noDeadlineTimeout): array {
    $noDeadlineCalls++;
    $noDeadlineTimeout = $timeout;
    return ['Users' => [['Identifier' => (string)$payload['UserIds'], 'PlannedInterval' => []]]];
};
$noDeadlineDiagnostics = [];
fte_fetch_attendance(
    $noDeadlineConfig,
    $makePeople(1),
    new DateTimeImmutable('2026-08-01'),
    new DateTimeImmutable('2026-08-31'),
    $noDeadlineDiagnostics
);
$assert($noDeadlineCalls === 1, 'el modo mensual sin limite ignora el vencimiento total previo');
$assert($noDeadlineTimeout === 75, 'el modo sin limite conserva proteccion por peticion individual');
$assert(($noDeadlineDiagnostics['total_deadline_disabled'] ?? false) === true, 'el diagnostico informa que no existe limite total');

$individualCalls = 0;
$individualConfig = $baseConfig;
$individualConfig['_geovictoria_post_handler'] = static function (string $endpoint, array $payload) use (&$individualCalls): array {
    $individualCalls++;
    return ['Users' => [['Identifier' => (string)$payload['UserIds'], 'PlannedInterval' => []]]];
};
$individualDiagnostics = [];
fte_fetch_attendance(
    $individualConfig,
    $makePeople(3),
    new DateTimeImmutable('2026-08-01'),
    new DateTimeImmutable('2026-09-01'),
    $individualDiagnostics
);
$assert($individualCalls === 3 && ($individualDiagnostics['strategy'] ?? '') === 'individual', 'el cambio no altera rangos superiores a treinta y un dias');

$_SESSION = [];
session_destroy();
echo "Resultado: {$passed} pruebas aprobadas.\n";
