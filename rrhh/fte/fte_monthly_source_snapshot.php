<?php
declare(strict_types=1);

/** Fotografia integral de las fuentes usadas por el calculo FTE mensual. */
function fte_monthly_source_snapshot_keys(): array
{
    return [
        'BUK_PERSONAS',
        'BUK_VACACIONES',
        'BUK_LICENCIAS',
        'GEOVICTORIA_ASISTENCIA',
        'GEOVICTORIA_DIAGNOSTICOS',
    ];
}

function fte_monthly_source_snapshot_tables_ready(PDO $conn): bool
{
    $stmt = $conn->query(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.fte_dotacion_snapshots', N'U') IS NOT NULL
                       AND OBJECT_ID(N'dbo.fte_dotacion_snapshot_fuentes', N'U') IS NOT NULL
                     THEN 1 ELSE 0 END"
    );
    return (int)$stmt->fetchColumn() === 1;
}

function fte_monthly_source_snapshot_assert_period(string $period): array
{
    if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period, $match)) {
        throw new InvalidArgumentException('El periodo de la fotografia de fuentes no es valido.');
    }
    return [(int)$match[1], (int)$match[2]];
}

function fte_monthly_source_snapshot_assert_closed_period(string $period, ?DateTimeImmutable $today = null): void
{
    fte_monthly_source_snapshot_assert_period($period);
    $today = $today ?? new DateTimeImmutable('today');
    if ((new DateTimeImmutable($period . '-01'))->modify('last day of this month') >= $today) {
        throw new DomainException('Solo se pueden congelar meses finalizados.');
    }
}

function fte_monthly_source_snapshot_canonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_keys($value) === range(0, count($value) - 1)) {
        return array_map('fte_monthly_source_snapshot_canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = fte_monthly_source_snapshot_canonicalize($item);
    }
    return $value;
}

function fte_monthly_source_snapshot_json($value): string
{
    return json_encode(
        fte_monthly_source_snapshot_canonicalize($value),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    );
}

function fte_monthly_source_snapshot_record_count(string $key, array $payload): int
{
    if ($key === 'GEOVICTORIA_ASISTENCIA') {
        return array_sum(array_map(static fn($byDate): int => is_array($byDate) ? count($byDate) : 0, $payload));
    }
    if ($key === 'GEOVICTORIA_DIAGNOSTICOS') {
        return count($payload['successful_identifiers'] ?? [])
            + count($payload['failed_identifiers'] ?? [])
            + count($payload['unmatched_identifiers'] ?? []);
    }
    return count($payload);
}

function fte_monthly_source_snapshot_sort_payload(string $key, array $payload): array
{
    if ($key === 'BUK_PERSONAS') {
        usort($payload, static fn(array $left, array $right): int => strcmp(
            fte_normalize_identifier($left['normalized_identifier'] ?? $left['identifier'] ?? ''),
            fte_normalize_identifier($right['normalized_identifier'] ?? $right['identifier'] ?? '')
        ));
    } elseif (in_array($key, ['BUK_VACACIONES', 'BUK_LICENCIAS'], true)) {
        usort($payload, static fn(array $left, array $right): int => strcmp(
            fte_monthly_source_snapshot_json($left),
            fte_monthly_source_snapshot_json($right)
        ));
    } elseif ($key === 'GEOVICTORIA_ASISTENCIA') {
        ksort($payload, SORT_STRING);
        foreach ($payload as &$byDate) {
            if (is_array($byDate)) {
                ksort($byDate, SORT_STRING);
            }
        }
        unset($byDate);
    } else {
        foreach (['successful_identifiers', 'failed_identifiers', 'unmatched_identifiers'] as $listKey) {
            if (isset($payload[$listKey]) && is_array($payload[$listKey])) {
                $payload[$listKey] = array_values(array_unique(array_map('strval', $payload[$listKey])));
                sort($payload[$listKey], SORT_STRING);
            }
        }
    }
    return $payload;
}

function fte_monthly_source_snapshot_item(
    string $key,
    string $provider,
    array $payload,
    bool $complete,
    string $capturedAt
): array {
    if (!in_array($key, fte_monthly_source_snapshot_keys(), true)) {
        throw new InvalidArgumentException('La fuente mensual no esta permitida.');
    }
    $payload = fte_monthly_source_snapshot_sort_payload($key, $payload);
    $json = fte_monthly_source_snapshot_json($payload);
    return [
        'key' => $key,
        'provider' => $provider,
        'status' => $complete ? 'COMPLETA' : 'PARCIAL',
        'complete' => $complete,
        'schema_version' => 1,
        'record_count' => fte_monthly_source_snapshot_record_count($key, $payload),
        'payload' => $payload,
        'json' => $json,
        'hash' => hash('sha256', $json),
        'captured_at' => $capturedAt,
    ];
}

function fte_monthly_source_snapshot_from_data(
    string $period,
    array $people,
    array $vacations,
    array $licences,
    array $attendance,
    array $diagnostics,
    array $calendar,
    ?string $capturedAt = null
): array {
    [$year, $month] = fte_monthly_source_snapshot_assert_period($period);
    if (($calendar['period'] ?? '') !== $period) {
        throw new DomainException('El calendario no corresponde al periodo de la fotografia.');
    }
    fte_calendar_validate_result($calendar, true);
    $diagnostics['calendar'] = $calendar;
    $capturedAt = $capturedAt ?: date(DATE_ATOM);
    $geoComplete = empty($diagnostics['failed_identifiers'])
        && empty($diagnostics['unmatched_identifiers'])
        && empty($diagnostics['attendance_excluded_identifiers']);
    $sources = [
        'BUK_PERSONAS' => fte_monthly_source_snapshot_item('BUK_PERSONAS', 'BUK', $people, true, $capturedAt),
        'BUK_VACACIONES' => fte_monthly_source_snapshot_item('BUK_VACACIONES', 'BUK', $vacations, true, $capturedAt),
        'BUK_LICENCIAS' => fte_monthly_source_snapshot_item('BUK_LICENCIAS', 'BUK', $licences, true, $capturedAt),
        'GEOVICTORIA_ASISTENCIA' => fte_monthly_source_snapshot_item('GEOVICTORIA_ASISTENCIA', 'GEOVICTORIA', $attendance, $geoComplete, $capturedAt),
        'GEOVICTORIA_DIAGNOSTICOS' => fte_monthly_source_snapshot_item('GEOVICTORIA_DIAGNOSTICOS', 'GEOVICTORIA', $diagnostics, $geoComplete, $capturedAt),
    ];
    ksort($sources, SORT_STRING);
    $hashInput = ['period' => $period, 'schema_version' => 1, 'sources' => []];
    foreach ($sources as $key => $source) {
        $hashInput['sources'][$key] = [
            'hash' => $source['hash'],
            'record_count' => $source['record_count'],
            'status' => $source['status'],
            'schema_version' => $source['schema_version'],
        ];
    }
    $bundleHash = hash('sha256', fte_monthly_source_snapshot_json($hashInput));
    $headcount = fte_headcount_snapshot_build($people, $year, $month, $calendar);
    $headcount['hash'] = $bundleHash;
    $headcount['source'] = 'BUK_GEOVICTORIA_SNAPSHOT';
    return [
        'period' => $period,
        'captured_at' => $capturedAt,
        'schema_version' => 1,
        'hash' => $bundleHash,
        'complete' => $geoComplete,
        'headcount' => $headcount,
        'sources' => $sources,
    ];
}

function fte_monthly_source_snapshot_build(array $config, string $period): array
{
    [$year, $month] = fte_monthly_source_snapshot_assert_period($period);
    $calendar = fte_calendar_calculate_month($year, $month);
    $from = new DateTimeImmutable($period . '-01');
    $to = $from->modify('last day of this month');
    $identityDiagnostics = [];
    $people = fte_fetch_buk_people($config, false, $identityDiagnostics);
    $country = trim((string)$config['buk_country']);
    $vacations = fte_buk_fetch_all($config, "/api/v1/{$country}/vacations", [
        'start_before' => $to->format('Y-m-d'),
        'end_after' => $from->format('Y-m-d'),
    ]);
    $licences = fte_buk_fetch_all($config, "/api/v1/{$country}/absences/licence", [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
    ]);
    if (!$vacations['ok'] || !$licences['ok']) {
        throw new RuntimeException('Buk no entrego todas las fuentes mensuales; no se guardo una fotografia incompleta.');
    }
    $attendanceCandidates = array_values(array_filter($people, static function (array $person) use ($calendar): bool {
        foreach (($calendar['days'] ?? []) as $day) {
            $date = (string)($day['date'] ?? '');
            if ($date !== '' && fte_headcount_person_active_on($person, $date)) {
                return true;
            }
        }
        return false;
    }));
    $attendancePartition = fte_attendance_partition_people($attendanceCandidates, $config);
    $attendancePeople = $attendancePartition['included'];
    $attendanceConfig = $config;
    $attendanceConfig['_include_monthly_time_offs'] = true;
    $attendanceConfig['_attendance_total_deadline_disabled'] = true;
    $attendanceConfig['_request_timeout_seconds'] = 0;
    $diagnostics = [];
    $attendance = fte_fetch_attendance($attendanceConfig, $attendancePeople, $from, $to, $diagnostics);
    $diagnostics['attendance_excluded_workers'] = $attendancePartition['excluded'];
    $diagnostics['attendance_excluded_identifiers'] = array_values(array_map(
        static fn(array $worker): string => fte_normalize_identifier($worker['normalized_identifier'] ?? $worker['identifier'] ?? ''),
        $attendancePartition['excluded']
    ));
    $diagnostics['identity'] = $identityDiagnostics;
    return fte_monthly_source_snapshot_from_data(
        $period, $people, $vacations['items'], $licences['items'],
        $attendance, $diagnostics, $calendar
    );
}

function fte_monthly_source_snapshot_storage_dir(array $config): string
{
    $configured = trim((string)($config['monthly_snapshot_storage_dir'] ?? ''));
    if ($configured === '') {
        $configured = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'portal_data'
            . DIRECTORY_SEPARATOR . 'fte' . DIRECTORY_SEPARATOR . 'snapshots';
    }
    $configured = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured), DIRECTORY_SEPARATOR);
    if ($configured === '') {
        throw new RuntimeException('La ruta de respaldo de fotografias FTE no esta configurada.');
    }
    $webRoot = realpath(dirname(__DIR__, 2));
    $parent = realpath(dirname($configured));
    if ($webRoot !== false && $parent !== false) {
        $webRootCmp = strtolower(rtrim(str_replace('\\', '/', $webRoot), '/')) . '/';
        $targetCmp = strtolower(rtrim(str_replace('\\', '/', $parent), '/')) . '/';
        if (str_starts_with($targetCmp, $webRootCmp)) {
            throw new RuntimeException('El respaldo FTE debe guardarse fuera del directorio publico de PortalGP.');
        }
    }
    return $configured;
}

function fte_monthly_source_snapshot_write_file(string $path, string $contents): void
{
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('No fue posible escribir el respaldo mensual FTE.');
    }
}

function fte_monthly_source_snapshot_archive(string $storageDir, array $bundle, int $version): array
{
    $period = (string)($bundle['period'] ?? '');
    fte_monthly_source_snapshot_assert_period($period);
    if ($version < 1) {
        throw new InvalidArgumentException('La version del respaldo mensual no es valida.');
    }
    $relativeDir = $period . DIRECTORY_SEPARATOR . 'v' . $version;
    $absoluteDir = $storageDir . DIRECTORY_SEPARATOR . $relativeDir;
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0700, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('No fue posible crear la carpeta protegida de fotografias FTE.');
    }
    $manifest = [
        'period' => $period, 'version' => $version,
        'schema_version' => (int)($bundle['schema_version'] ?? 1),
        'captured_at' => (string)($bundle['captured_at'] ?? ''),
        'bundle_hash' => (string)($bundle['hash'] ?? ''), 'sources' => [],
    ];
    $paths = [];
    foreach (($bundle['sources'] ?? []) as $key => $source) {
        if (!in_array($key, fte_monthly_source_snapshot_keys(), true)) {
            throw new RuntimeException('La fotografia contiene una fuente no permitida.');
        }
        $fileName = strtolower($key) . '.json.gz';
        $compressed = gzencode((string)$source['json'], 9);
        if ($compressed === false) {
            throw new RuntimeException('No fue posible comprimir el respaldo mensual FTE.');
        }
        fte_monthly_source_snapshot_write_file($absoluteDir . DIRECTORY_SEPARATOR . $fileName, $compressed);
        $relativePath = str_replace('\\', '/', $relativeDir . DIRECTORY_SEPARATOR . $fileName);
        $paths[$key] = $relativePath;
        $manifest['sources'][$key] = [
            'provider' => $source['provider'], 'status' => $source['status'],
            'record_count' => $source['record_count'], 'hash' => $source['hash'],
            'file' => $relativePath,
        ];
    }
    fte_monthly_source_snapshot_write_file(
        $absoluteDir . DIRECTORY_SEPARATOR . 'manifest.json',
        fte_monthly_source_snapshot_json($manifest)
    );
    return ['directory' => $absoluteDir, 'paths' => $paths, 'manifest' => $manifest];
}

function fte_monthly_source_snapshot_insert_sources(PDO $conn, int $snapshotId, array $sources, array $paths): void
{
    $sql = 'INSERT INTO dbo.fte_dotacion_snapshot_fuentes (id_fte_dotacion_snapshot,clave_fuente,proveedor,estado_captura,version_esquema,cantidad_registros,payload_json,hash_fuente,archivo_relativo,fecha_captura) VALUES (:snapshot,:clave,:proveedor,:estado,:version,:cantidad,:payload,:hash,:archivo,:fecha)';
    $stmt = $conn->prepare($sql);
    foreach ($sources as $key => $source) {
        $captured = (new DateTimeImmutable((string)$source['captured_at']))->format('Y-m-d H:i:s');
        $stmt->execute([':snapshot'=>$snapshotId,':clave'=>$key,':proveedor'=>(string)$source['provider'],':estado'=>(string)$source['status'],':version'=>(int)$source['schema_version'],':cantidad'=>(int)$source['record_count'],':payload'=>(string)$source['json'],':hash'=>(string)$source['hash'],':archivo'=>(string)($paths[$key]??''),':fecha'=>$captured]);
    }
}

/** Evalua permisos diarios sobre la copia exacta que sera aprobada. */
function fte_monthly_source_snapshot_permission_gate(array $attendance): array
{
    if (!function_exists('fte_monthly_summarize_permissions')) {
        throw new RuntimeException('La regla de clasificacion de permisos FTE no esta disponible.');
    }
    $summary = fte_monthly_summarize_permissions($attendance);
    $pending = (int)($summary['day_permission_pending_records'] ?? 0);
    $categories = [];
    foreach (($summary['types'] ?? []) as $type) {
        if (!is_array($type) || ($type['classification'] ?? '') !== 'PERMISO_DIA_PENDIENTE') {
            continue;
        }
        $categories[] = [
            'type' => trim((string)($type['type'] ?? '')) ?: 'Permiso sin tipo informado',
            'records' => (int)($type['records'] ?? 0),
        ];
    }
    return [
        'can_approve' => $pending === 0,
        'pending_day_permission_records' => $pending,
        'pending_day_permission_categories' => $categories,
        'with_pay_records' => (int)($summary['day_permission_with_pay_records'] ?? 0),
        'without_pay_records' => (int)($summary['day_permission_without_pay_records'] ?? 0),
    ];
}

/**
 * Valida el paquete exacto que se pretende aprobar. Ningun pendiente se
 * interpreta como cero: cada control conserva conteo y detalle nominal.
 */
function fte_monthly_source_snapshot_preclose_validation(
    string $period,
    array $sources,
    bool $snapshotSaved = true,
    bool $sourcesComplete = true
): array {
    $checks = [];
    $append = static function (
        string $key,
        string $label,
        string $status,
        int $count,
        bool $blocking,
        string $message,
        array $details = []
    ) use (&$checks): void {
        $checks[] = [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'count' => max(0, $count),
            'blocking' => $blocking,
            'message' => $message,
            'details' => array_values($details),
        ];
    };

    $append(
        'snapshot_saved',
        'Fotografia mensual guardada',
        $snapshotSaved ? 'OK' : 'PENDIENTE',
        $snapshotSaved ? 0 : 1,
        !$snapshotSaved,
        $snapshotSaved
            ? 'La fotografia mensual existe y puede ser validada.'
            : 'Primero guarda la fotografia mensual de Buk y GeoVictoria.'
    );
    $append(
        'sources_complete',
        'Fuentes disponibles y completas',
        $sourcesComplete ? 'OK' : 'PENDIENTE',
        $sourcesComplete ? 0 : 1,
        !$sourcesComplete,
        $sourcesComplete
            ? 'Las cinco fuentes requeridas estan presentes y completas.'
            : 'Falta al menos una fuente completa de Buk o GeoVictoria.'
    );

    if (!$snapshotSaved || !$sourcesComplete) {
        foreach ([
            'worker_reconciliation' => 'Trabajadores conciliados',
            'identity_integrity' => 'RUT e identificadores consistentes',
            'permission_classification' => 'Permisos completamente clasificados',
            'validation_engine' => 'Reglas mensuales ejecutables',
            'source_conflicts' => 'Conflictos Buk–GeoVictoria resueltos',
            'duplicate_control' => 'Registros y horas sin duplicidad',
            'ceco_changes' => 'Cambios de CECO validos',
            'nominal_reconciliation' => 'Detalle nominal sin diferencias pendientes',
        ] as $key => $label) {
            $append(
                $key,
                $label,
                'PENDIENTE',
                0,
                true,
                'No evaluado: primero deben estar guardadas y completas todas las fuentes mensuales.'
            );
        }
        return [
            'status' => 'BLOQUEADA',
            'status_label' => 'Pendiente: faltan fuentes',
            'can_approve' => false,
            'blocking_check_count' => count(array_filter($checks, static fn(array $check): bool => $check['blocking'])),
            'pending_record_count' => array_sum(array_column($checks, 'count')),
            'checks' => $checks,
        ];
    }

    [$year, $month] = fte_monthly_source_snapshot_assert_period($period);
    $people = is_array($sources['BUK_PERSONAS'] ?? null) ? $sources['BUK_PERSONAS'] : [];
    $vacations = is_array($sources['BUK_VACACIONES'] ?? null) ? $sources['BUK_VACACIONES'] : [];
    $licences = is_array($sources['BUK_LICENCIAS'] ?? null) ? $sources['BUK_LICENCIAS'] : [];
    $attendance = is_array($sources['GEOVICTORIA_ASISTENCIA'] ?? null) ? $sources['GEOVICTORIA_ASISTENCIA'] : [];
    $diagnostics = is_array($sources['GEOVICTORIA_DIAGNOSTICOS'] ?? null) ? $sources['GEOVICTORIA_DIAGNOSTICOS'] : [];
    $identity = is_array($diagnostics['identity'] ?? null) ? $diagnostics['identity'] : [];
    $calendar = is_array($diagnostics['calendar'] ?? null) ? $diagnostics['calendar'] : [];

    $unmatched = array_values(array_unique(array_filter(array_map('fte_normalize_identifier', $diagnostics['unmatched_identifiers'] ?? []))));
    $failed = array_values(array_unique(array_filter(array_map('fte_normalize_identifier', $diagnostics['failed_identifiers'] ?? []))));
    $attendanceExcluded = array_values(array_unique(array_filter(array_map(
        'fte_normalize_identifier',
        $diagnostics['attendance_excluded_identifiers'] ?? []
    ))));
    $unreconciled = array_values(array_unique(array_merge($unmatched, $failed, $attendanceExcluded)));
    $append(
        'worker_reconciliation',
        'Trabajadores conciliados',
        $unreconciled ? 'PENDIENTE' : 'OK',
        count($unreconciled),
        $unreconciled !== [],
        $unreconciled
            ? 'Existen trabajadores sin conciliacion completa con GeoVictoria.'
            : 'Todos los trabajadores consultados fueron conciliados.',
        $unreconciled
    );

    $missingIdentifiers = (int)($identity['missing_identifier_records'] ?? 0);
    $ambiguousAliases = is_array($identity['ambiguous_aliases'] ?? null) ? $identity['ambiguous_aliases'] : [];
    $identityIssueCount = $missingIdentifiers + count($ambiguousAliases);
    $append(
        'identity_integrity',
        'RUT e identificadores consistentes',
        $identityIssueCount > 0 ? 'PENDIENTE' : 'OK',
        $identityIssueCount,
        $identityIssueCount > 0,
        $identityIssueCount > 0
            ? 'Hay registros sin RUT util o alias ambiguos que deben resolverse.'
            : 'Los identificadores necesarios son consistentes.',
        $ambiguousAliases
    );

    $permissionSummary = fte_monthly_summarize_permissions($attendance);
    $pendingPermissionCount = (int)($permissionSummary['day_permission_pending_records'] ?? 0)
        + (int)($permissionSummary['other_time_off_records'] ?? 0);
    $pendingPermissionDetails = array_values(array_filter(
        $permissionSummary['types'] ?? [],
        static fn(array $type): bool => in_array((string)($type['classification'] ?? ''), ['PERMISO_DIA_PENDIENTE', 'OTRO'], true)
    ));
    $append(
        'permission_classification',
        'Permisos completamente clasificados',
        $pendingPermissionCount > 0 ? 'PENDIENTE' : 'OK',
        $pendingPermissionCount,
        $pendingPermissionCount > 0,
        $pendingPermissionCount > 0
            ? 'Hay permisos sin clasificar; su valor permanece pendiente y no se asume como cero.'
            : 'Todos los permisos tienen una clasificacion util para el calculo.',
        $pendingPermissionDetails
    );

    $duplicateMerged = (int)($identity['duplicate_records_merged'] ?? 0);
    $duplicateDetails = is_array($identity['duplicate_identifiers'] ?? null) ? $identity['duplicate_identifiers'] : [];
    $report = null;
    $reportError = null;
    try {
        if (!$calendar) {
            throw new DomainException('La fotografia no contiene el calendario mensual congelado.');
        }
        if (($calendar['period'] ?? '') !== $period) {
            throw new DomainException('El calendario congelado corresponde a otro periodo.');
        }
        fte_calendar_validate_result($calendar, true);
        $headcount = fte_headcount_build_month($people, $year, $month, $calendar);
        $from = new DateTimeImmutable($period . '-01');
        $to = $from->modify('last day of this month');
        $partitionedLicences = fte_monthly_partition_buk_licences($licences);
        $rawReasons = fte_monthly_merge_absence_indexes(
            fte_index_absence_items($vacations, 'Vacaciones', $from, $to),
            fte_index_absence_items($partitionedLicences['licences'], 'Licencia medica', $from, $to),
            fte_index_absence_items($partitionedLicences['accidents'], 'Accidente', $from, $to)
        );
        $normalizedReasons = [];
        foreach ($people as $person) {
            if (!is_array($person)) {
                continue;
            }
            $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
            if ($identifier === '') {
                continue;
            }
            foreach (fte_identity_aliases_for_person($person) as $alias) {
                if (!isset($rawReasons[$alias])) {
                    continue;
                }
                $normalizedReasons[$identifier] = fte_monthly_merge_absence_indexes(
                    [$identifier => $normalizedReasons[$identifier] ?? []],
                    [$identifier => $rawReasons[$alias]]
                )[$identifier];
            }
        }
        $report = fte_monthly_build_report(
            $calendar,
            $headcount,
            $people,
            $normalizedReasons,
            $attendance,
            [],
            $diagnostics
        );
    } catch (Throwable $exception) {
        $reportError = $exception->getMessage();
    }

    $append(
        'validation_engine',
        'Reglas mensuales ejecutables',
        $reportError === null ? 'OK' : 'PENDIENTE',
        $reportError === null ? 0 : 1,
        $reportError !== null,
        $reportError === null
            ? 'El paquete se recalculo correctamente con las reglas mensuales.'
            : 'No fue posible recalcular el paquete: ' . $reportError
    );

    $conflictDetails = [];
    $employmentIssues = [];
    $deduplicationMatches = true;
    $overlapDays = 0;
    if (is_array($report)) {
        foreach (['vacation_definition', 'medical_leave_definition', 'accident_definition', 'failure_delay_definition', 'overtime_definition'] as $definitionKey) {
            foreach (($report[$definitionKey]['conflicts'] ?? []) as $conflict) {
                if (is_array($conflict)) {
                    $conflict['concept'] = $definitionKey;
                    $conflictDetails[] = $conflict;
                }
            }
        }
        $employmentIssues = array_values(array_filter(
            $report['employment_movement_definition']['issues'] ?? [],
            static fn(array $issue): bool => in_array((string)($issue['type'] ?? ''), [
                'MID_MONTH_CECO_CHANGE',
                'UNASSIGNED_EMPLOYMENT_EVENT',
                'INVALID_EMPLOYMENT_RANGE',
            ], true)
        ));
        $deduplicationMatches = ($report['deduplication_definition']['matches_report_loss_components'] ?? false) === true;
        $overlapDays = (int)($report['deduplication_definition']['overlap_days'] ?? 0);
    }

    $append(
        'source_conflicts',
        'Conflictos Buk–GeoVictoria resueltos',
        $conflictDetails ? 'PENDIENTE' : 'OK',
        count($conflictDetails),
        $conflictDetails !== [],
        $conflictDetails
            ? 'Existen diferencias nominales entre Buk y GeoVictoria que requieren revision.'
            : 'No existen conflictos pendientes entre las fuentes.',
        $conflictDetails
    );
    $append(
        'duplicate_control',
        'Registros y horas sin duplicidad',
        !$deduplicationMatches ? 'PENDIENTE' : (($duplicateMerged + $overlapDays) > 0 ? 'RESUELTO' : 'OK'),
        !$deduplicationMatches ? 1 : ($duplicateMerged + $overlapDays),
        !$deduplicationMatches,
        !$deduplicationMatches
            ? 'La suma diaria no cuadra con los componentes mensuales y debe revisarse.'
            : (($duplicateMerged + $overlapDays) > 0
                ? 'Los duplicados de identidad y cruces diarios fueron unificados sin doble contabilizacion.'
                : 'No se detectaron duplicidades.'),
        $duplicateDetails
    );
    $append(
        'ceco_changes',
        'Cambios de CECO validos',
        $employmentIssues ? 'PENDIENTE' : 'OK',
        count($employmentIssues),
        $employmentIssues !== [],
        $employmentIssues
            ? 'Hay cambios de CECO fuera del inicio del mes u otras fechas laborales invalidas.'
            : 'No existen cambios de CECO o fechas laborales pendientes.',
        $employmentIssues
    );

    $nominalPendingCount = count($unreconciled)
        + $identityIssueCount
        + $pendingPermissionCount
        + count($conflictDetails)
        + count($employmentIssues)
        + ($reportError === null ? 0 : 1);
    $append(
        'nominal_reconciliation',
        'Detalle nominal sin diferencias pendientes',
        $nominalPendingCount > 0 ? 'PENDIENTE' : 'OK',
        $nominalPendingCount,
        $nominalPendingCount > 0,
        $nominalPendingCount > 0
            ? 'El detalle nominal contiene pendientes; no se presentan como valor cero.'
            : 'Cada total dispone de respaldo nominal sin pendientes.'
    );

    $blockingChecks = array_values(array_filter($checks, static fn(array $check): bool => $check['blocking']));
    return [
        'status' => $blockingChecks ? 'BLOQUEADA' : 'LISTA',
        'status_label' => $blockingChecks ? 'Pendiente de correcciones' : 'Lista para aprobar',
        'can_approve' => $blockingChecks === [],
        'blocking_check_count' => count($blockingChecks),
        'pending_record_count' => array_sum(array_map(
            static fn(array $check): int => $check['blocking'] ? (int)$check['count'] : 0,
            $checks
        )),
        'checks' => $checks,
    ];
}

function fte_monthly_source_snapshot_status(PDO $conn, string $period): array
{
    $status = fte_headcount_snapshot_status($conn, $period);
    $status += ['source_snapshot_available'=>fte_monthly_source_snapshot_tables_ready($conn),'required_sources'=>count(fte_monthly_source_snapshot_keys()),'source_count'=>0,'complete_source_count'=>0,'source_record_count'=>0,'is_full_snapshot'=>false,'sources'=>[],'pending_day_permission_records'=>0,'pending_day_permission_categories'=>[],'day_permission_with_pay_records'=>0,'day_permission_without_pay_records'=>0];
    $status['available'] = !empty($status['available']) && !empty($status['source_snapshot_available']);
    if (empty($status['exists']) || !$status['source_snapshot_available']) {
        $status['preclose_validation'] = fte_monthly_source_snapshot_preclose_validation($period, [], false, false);
        $status['can_approve'] = false;
        return $status;
    }
    $sql = 'SELECT clave_fuente,proveedor,estado_captura,version_esquema,cantidad_registros,hash_fuente,archivo_relativo,fecha_captura,payload_json FROM dbo.fte_dotacion_snapshot_fuentes WHERE id_fte_dotacion_snapshot=:snapshot ORDER BY clave_fuente';
    $stmt = $conn->prepare($sql); $stmt->execute([':snapshot'=>(int)$status['id']]);
    $permissionGate = ['can_approve'=>false,'pending_day_permission_records'=>0,'pending_day_permission_categories'=>[],'with_pay_records'=>0,'without_pay_records'=>0,'error'=>'La fotografia no contiene la asistencia de GeoVictoria necesaria para clasificar permisos.'];
    $sourcePayloads = [];
    $payloadDecodeErrors = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $source=['key'=>(string)$row['clave_fuente'],'provider'=>(string)$row['proveedor'],'status'=>(string)$row['estado_captura'],'schema_version'=>(int)$row['version_esquema'],'record_count'=>(int)$row['cantidad_registros'],'hash'=>(string)$row['hash_fuente'],'archive_path'=>(string)$row['archivo_relativo'],'captured_at'=>(string)$row['fecha_captura']];
        $status['sources'][]=$source; $status['source_count']++; $status['source_record_count']+=$source['record_count'];
        if ($source['status']==='COMPLETA') { $status['complete_source_count']++; }
        try {
            $payload=json_decode((string)$row['payload_json'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new JsonException('El contenido no es una estructura JSON valida.');
            }
            $sourcePayloads[$source['key']]=$payload;
            if ($source['key']==='GEOVICTORIA_ASISTENCIA') {
                $permissionGate=fte_monthly_source_snapshot_permission_gate($payload);
            }
        } catch (JsonException $exception) {
            $payloadDecodeErrors[]=$source['key'];
            if ($source['key']==='GEOVICTORIA_ASISTENCIA') {
                $permissionGate['error']='La asistencia congelada no contiene un JSON valido.';
            }
        }
    }
    $stored=array_column($status['sources'],'key'); sort($stored,SORT_STRING);
    $required=fte_monthly_source_snapshot_keys(); sort($required,SORT_STRING);
    $status['is_full_snapshot']=$stored===$required && $status['complete_source_count']===count($required);
    $status['pending_day_permission_records']=(int)$permissionGate['pending_day_permission_records'];
    $status['pending_day_permission_categories']=$permissionGate['pending_day_permission_categories'];
    $status['day_permission_with_pay_records']=(int)$permissionGate['with_pay_records'];
    $status['day_permission_without_pay_records']=(int)$permissionGate['without_pay_records'];
    $status['permission_gate_error']=$permissionGate['error']??null;
    $status['source_payload_decode_errors']=$payloadDecodeErrors;
    $status['preclose_validation']=fte_monthly_source_snapshot_preclose_validation(
        $period,
        $sourcePayloads,
        true,
        $status['is_full_snapshot'] && $payloadDecodeErrors === []
    );
    $status['can_approve']=!empty($status['can_update'])
        && $status['is_full_snapshot']
        && !empty($permissionGate['can_approve'])
        && !empty($status['preclose_validation']['can_approve']);
    return $status;
}

function fte_monthly_source_snapshot_details(PDO $conn, string $period): array
{
    $details=fte_headcount_snapshot_details($conn,$period);
    $details['snapshot']=fte_monthly_source_snapshot_status($conn,$period);
    $details['sources']=$details['snapshot']['sources']??[];
    return $details;
}

function fte_monthly_source_snapshot_save(PDO $conn,array $bundle,int $userId,string $storageDir): array
{
    if (!fte_monthly_source_snapshot_tables_ready($conn)) { throw new RuntimeException('La estructura de fuentes mensuales FTE no esta instalada.'); }
    $period=trim((string)($bundle['period']??'')); fte_monthly_source_snapshot_assert_period($period);
    $required=fte_monthly_source_snapshot_keys(); $keys=array_keys($bundle['sources']??[]); sort($required); sort($keys);
    if ($keys!==$required || !is_array($bundle['headcount']??null)) { throw new InvalidArgumentException('La fotografia mensual no contiene todas las fuentes requeridas.'); }
    $archiveDirectory=null; $owns=!$conn->inTransaction(); if ($owns) { $conn->beginTransaction(); }
    try {
        $saved=fte_headcount_snapshot_save($conn,$bundle['headcount'],$userId);
        if (!empty($saved['created'])) {
            $archive=fte_monthly_source_snapshot_archive($storageDir,$bundle,(int)$saved['version']);
            $archiveDirectory=$archive['directory'];
            fte_monthly_source_snapshot_insert_sources($conn,(int)$saved['id'],$bundle['sources'],$archive['paths']);
        }
        if ($owns) { $conn->commit(); }
        $status=fte_monthly_source_snapshot_status($conn,$period);
        $status['created']=(bool)($saved['created']??false); $status['unchanged']=(bool)($saved['unchanged']??false);
        return $status;
    } catch (Throwable $exception) {
        if ($owns && $conn->inTransaction()) { $conn->rollBack(); }
        if ($archiveDirectory!==null && is_dir($archiveDirectory)) {
            foreach (glob($archiveDirectory.DIRECTORY_SEPARATOR.'*')?:[] as $file) { if (is_file($file)) { @unlink($file); } }
            @rmdir($archiveDirectory);
        }
        throw $exception;
    }
}

function fte_monthly_source_snapshot_verify_archive(string $storageDir,array $source): bool
{
    $relative=trim((string)($source['archive_path']??''));
    if ($relative==='' || str_contains($relative,'..')) { return false; }
    $path=$storageDir.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
    $compressed=is_file($path)?file_get_contents($path):false;
    if ($compressed===false) { return false; }
    $json=gzdecode($compressed);
    return is_string($json) && hash_equals((string)$source['hash'],hash('sha256',$json));
}

function fte_monthly_source_snapshot_approve(PDO $conn,string $period,int $userId,int $expected,string $note,string $storageDir): array
{
    $status=fte_monthly_source_snapshot_status($conn,$period);
    if (empty($status['exists'])) { throw new DomainException('Primero guarda la fotografia mensual de fuentes antes de aprobarla.'); }
    if (empty($status['is_full_snapshot'])) { throw new DomainException('La fotografia no contiene todas las fuentes completas de Buk y GeoVictoria.'); }
    if (!empty($status['permission_gate_error'])) { throw new DomainException((string)$status['permission_gate_error']); }
    if ((int)($status['pending_day_permission_records']??0)>0) {
        throw new DomainException(sprintf(
            'Existen %d permiso(s) por dia sin clasificar como con o sin goce. Clasificalos antes de aprobar el mes.',
            (int)$status['pending_day_permission_records']
        ));
    }
    $precloseValidation = is_array($status['preclose_validation'] ?? null)
        ? $status['preclose_validation']
        : [];
    if (empty($precloseValidation['can_approve'])) {
        $pendingLabels = [];
        foreach (($precloseValidation['checks'] ?? []) as $check) {
            if (is_array($check) && !empty($check['blocking'])) {
                $pendingLabels[] = sprintf(
                    '%s (%d)',
                    (string)($check['label'] ?? 'Control pendiente'),
                    (int)($check['count'] ?? 0)
                );
            }
        }
        throw new DomainException(
            'La validacion previa impide aprobar el mes. Corrige: '
            . ($pendingLabels ? implode('; ', $pendingLabels) : 'controles mensuales pendientes')
            . '.'
        );
    }
    foreach ($status['sources'] as $source) { if (!fte_monthly_source_snapshot_verify_archive($storageDir,$source)) { throw new DomainException('El respaldo externo de '.$source['key'].' no coincide con su hash.'); } }
    $approved=fte_headcount_snapshot_approve($conn,$period,$userId,$expected,$note);
    $result=fte_monthly_source_snapshot_status($conn,$period);
    $result['approved']=(bool)($approved['approved']??false); $result['unchanged']=(bool)($approved['unchanged']??false);
    return $result;
}

function fte_monthly_source_snapshot_load_approved(PDO $conn,string $period): ?array
{
    $status=fte_monthly_source_snapshot_status($conn,$period);
    if (empty($status['exists']) || ($status['status']??'')!=='APROBADO' || empty($status['is_full_snapshot'])) { return null; }
    $stmt=$conn->prepare('SELECT clave_fuente,estado_captura,payload_json,hash_fuente FROM dbo.fte_dotacion_snapshot_fuentes WHERE id_fte_dotacion_snapshot=:snapshot');
    $stmt->execute([':snapshot'=>(int)$status['id']]); $sources=[];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $json=(string)$row['payload_json'];
        if ($row['estado_captura']!=='COMPLETA' || !hash_equals((string)$row['hash_fuente'],hash('sha256',$json))) { throw new RuntimeException('Una fuente congelada no supera la verificacion de integridad.'); }
        $payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($payload)) { throw new RuntimeException('Una fuente congelada no contiene un arreglo valido.'); }
        $sources[(string)$row['clave_fuente']]=$payload;
    }
    return ['snapshot'=>$status,'sources'=>$sources];
}
