<?php
declare(strict_types=1);

/** Monthly transport only: no calendar, identity policy or FTE formula changes. */
function fte_monthly_batch_transient(string $reason): bool
{
    return in_array($reason, ['timeout', 'rate_limit', 'provider_unavailable', 'transport_error', 'unexpected_response', 'invalid_response', 'missing_identity'], true);
}

function fte_monthly_batch_failure(array $ids, string $reason, array &$diagnostics): void
{
    foreach ($ids as $id) {
        $diagnostics['failed_identifiers'][] = $id;
        $diagnostics['failure_reasons'][$id] = $reason;
    }
}

/** Retain the non-monthly deadline contract; the monthly API disables it explicitly. */
function fte_monthly_batch_request(array $config, array $payload, int $timeout, array &$state): array
{
    if ($state['request_count'] > 0) {
        $pause = max(0.0, (float)($config['geovictoria_attendance_min_interval_seconds'] ?? 0.35));
        if ($pause > 0) {
            usleep((int)round($pause * 1e6));
        }
    }
    if ($state['deadline_at'] !== null) {
        $remaining = $state['deadline_at'] - microtime(true);
        if ($remaining <= 0) {
            throw new FteAttendanceBatchException('deadline', 0, 'La consulta supero el tiempo total permitido.');
        }
        $timeout = min($timeout, (int)ceil($remaining));
    }
    $state['request_count']++;
    $raw = fte_geovictoria_post($config, 'AttendanceBook', $payload, max(1, min(75, $timeout)));
    $shape = (isset($raw['Users']) && is_array($raw['Users']))
        || (isset($raw['Data']) && is_array($raw['Data'])) || array_is_list($raw);
    if (!$shape) {
        throw new FteGeoVictoriaException('invalid_response');
    }
    $expected = array_map('fte_normalize_identifier', explode(',', (string)$payload['UserIds']));
    $seen = [];
    foreach (fte_extract_users($raw) as $user) {
        if (!is_array($user) || ($id = fte_identifier_from_geo_user($user)) === '') {
            throw new FteGeoVictoriaException('invalid_response');
        }
        if (isset($seen[$id]) || !in_array($id, $expected, true)) {
            throw new FteAttendanceBatchException('identity_integrity', 0, 'GeoVictoria devolvio identidades duplicadas o no solicitadas.');
        }
        $seen[$id] = true;
        if (isset($user['PlannedInterval']) && !is_array($user['PlannedInterval'])) {
            throw new FteGeoVictoriaException('invalid_response');
        }
        foreach (($user['PlannedInterval'] ?? []) as $interval) {
            $date = is_array($interval) ? fte_parse_timestamp($interval['Date'] ?? null) : null;
            if ($date === null) {
                throw new FteGeoVictoriaException('invalid_response');
            }
            $day = $date->format('Ymd');
            if ($day < substr($payload['StartDate'], 0, 8) || $day > substr($payload['EndDate'], 0, 8)) {
                throw new FteAttendanceBatchException('identity_integrity', 0, 'GeoVictoria devolvio intervalos fuera del periodo solicitado.');
            }
        }
    }
    return $raw;
}

function fte_monthly_batch_single(array $config, array $person, string $start, string $end, array &$attendance, array &$diagnostics, array &$state): void
{
    $id = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
    if ($state['halt_reason'] !== null) {
        fte_monthly_batch_failure([$id], $state['halt_reason'], $diagnostics);
        return;
    }
    if (fte_attendance_known_unmatched($config, $id)) {
        $diagnostics['successful_identifiers'][] = $id;
        $diagnostics['unmatched_identifiers'][] = $id;
        $diagnostics['cached_unmatched_count']++;
        return;
    }
    $candidate = $id;
    $rawId = trim((string)($person['identifier'] ?? ''));
    $attempts = max(1, min(2, (int)($config['geovictoria_batch_single_attempts'] ?? 2)));
    $reason = 'missing_identity';
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $diagnostics['fallback_requests']++;
        try {
            $raw = fte_monthly_batch_request($config, ['StartDate' => $start, 'EndDate' => $end, 'UserIds' => $candidate], (int)($config['geovictoria_individual_timeout_seconds'] ?? 20), $state);
            if (in_array($id, fte_attendance_response_identifiers($raw), true)) {
                fte_merge_attendance_payload($attendance, $raw, !empty($config['_include_monthly_time_offs']));
                $diagnostics['successful_identifiers'][] = $id;
                $state['terminal_transient_failures'] = 0;
                return;
            }
            $reason = 'missing_identity'; // HTTP 200 without the identity is not proof of nonexistence.
        } catch (FteGeoVictoriaException $e) {
            $reason = $e->reason();
            if ($reason === 'nonexistent_identity') {
                fte_attendance_remember_unmatched($config, $id);
                $diagnostics['successful_identifiers'][] = $id;
                $diagnostics['unmatched_identifiers'][] = $id;
                $state['terminal_transient_failures'] = 0;
                return;
            }
            if ($reason === 'authentication') {
                $state['halt_reason'] = 'authentication';
                break;
            }
            if ($reason === 'bad_request' && $candidate !== $rawId && $rawId !== '') {
                $candidate = $rawId; // One distinct formatting alternative, not the same rejected request.
            } elseif (!fte_monthly_batch_transient($reason)) {
                break;
            }
        }
        if ($attempt < $attempts) {
            $diagnostics['retry_requests']++;
            usleep((int)(min(2.0, max(0.0, (float)($config['geovictoria_batch_retry_pause_seconds'] ?? 1.0))) * 1e6));
        }
    }
    fte_monthly_batch_failure([$id], $reason, $diagnostics);
    if (fte_monthly_batch_transient($reason)) {
        $state['terminal_transient_failures']++;
        if ($state['terminal_transient_failures'] >= max(1, (int)($config['geovictoria_consecutive_failure_limit'] ?? 3))) {
            $state['halt_reason'] = 'provider_unavailable';
            $diagnostics['circuit_open'] = true;
        }
    }
}

function fte_monthly_batch_chunk(array $config, array $people, array $ids, string $start, string $end, array &$attendance, array &$diagnostics, array &$state, int $depth = 0): void
{
    if (!$ids) {
        return;
    }
    if ($state['halt_reason'] !== null) {
        fte_monthly_batch_failure($ids, $state['halt_reason'], $diagnostics);
        return;
    }
    if (count($ids) === 1) {
        fte_monthly_batch_single($config, $people[$ids[0]], $start, $end, $attendance, $diagnostics, $state);
        return;
    }
    $diagnostics['batch_requests']++;
    $payload = ['StartDate' => $start, 'EndDate' => $end, 'UserIds' => implode(',', $ids)];
    $timeout = (int)($config[$depth > 0 ? 'geovictoria_batch_split_timeout_seconds' : 'geovictoria_batch_timeout_seconds'] ?? 25);
    try {
        try {
            $raw = fte_monthly_batch_request($config, $payload, $timeout, $state);
        } catch (FteGeoVictoriaException $e) {
            if ($e->reason() !== 'rate_limit') {
                throw $e;
            }
            // One paced retry of this group only; never replay successful groups.
            $diagnostics['retry_requests']++;
            usleep((int)(min(2.0, max(0.0, (float)($config['geovictoria_batch_retry_pause_seconds'] ?? 1.0))) * 1e6));
            $raw = fte_monthly_batch_request($config, $payload, min(15, $timeout), $state);
        }
    } catch (FteGeoVictoriaException $e) {
        if ($e->reason() === 'authentication') {
            $state['halt_reason'] = 'authentication';
            fte_monthly_batch_failure($ids, 'authentication', $diagnostics);
            return;
        }
        $splittable = in_array($e->reason(), ['out_of_limit', 'nonexistent_identity', 'bad_request'], true);
        $transient = fte_monthly_batch_transient($e->reason());
        if ($splittable || ($transient && $depth < max(0, min(3, (int)($config['geovictoria_batch_transient_split_depth'] ?? 1))))) {
            $diagnostics['split_batches']++;
            $middle = (int)ceil(count($ids) / 2);
            fte_monthly_batch_chunk($config, $people, array_slice($ids, 0, $middle), $start, $end, $attendance, $diagnostics, $state, $depth + 1);
            fte_monthly_batch_chunk($config, $people, array_slice($ids, $middle), $start, $end, $attendance, $diagnostics, $state, $depth + 1);
        } elseif ($transient) {
            foreach ($ids as $id) {
                fte_monthly_batch_single($config, $people[$id], $start, $end, $attendance, $diagnostics, $state);
            }
        } else {
            fte_monthly_batch_failure($ids, $e->reason(), $diagnostics);
        }
        return;
    }
    fte_merge_attendance_payload($attendance, $raw, !empty($config['_include_monthly_time_offs']));
    $returned = fte_attendance_response_identifiers($raw);
    $diagnostics['successful_identifiers'] = array_merge($diagnostics['successful_identifiers'], $returned);
    $state['terminal_transient_failures'] = 0;
    foreach (array_diff($ids, $returned) as $id) {
        fte_monthly_batch_single($config, $people[$id], $start, $end, $attendance, $diagnostics, $state);
    }
}

function fte_attendance_resilient_monthly(array $config, array $people, string $start, string $end, int $batchSize, array &$diagnostics): array
{
    $byId = [];
    foreach ($people as $person) {
        $id = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        if ($id !== '') {
            $byId[$id] = $person;
        }
    }
    $state = [
        'request_count' => 0, 'terminal_transient_failures' => 0, 'halt_reason' => null,
        'deadline_at' => !empty($config['_attendance_total_deadline_disabled']) ? null : ((float)($config['_request_deadline_at'] ?? 0) ?: microtime(true) + fte_attendance_timeout_seconds($config, (int)$diagnostics['range_days'])),
    ];
    $diagnostics += ['retry_requests' => 0, 'split_batches' => 0, 'failure_reasons' => [], 'circuit_open' => false];
    $diagnostics['strategy'] = 'resilient_monthly_batch';
    $attendance = [];
    foreach (array_chunk(array_keys($byId), $batchSize) as $ids) {
        fte_monthly_batch_chunk($config, $byId, $ids, $start, $end, $attendance, $diagnostics, $state);
    }
    $diagnostics['request_count'] = $state['request_count'];
    foreach (['successful_identifiers', 'failed_identifiers', 'unmatched_identifiers'] as $key) {
        $diagnostics[$key] = array_values(array_unique($diagnostics[$key]));
    }
    if (!$diagnostics['successful_identifiers'] && $diagnostics['failed_identifiers']) {
        throw new FteAttendanceBatchException($state['halt_reason'] ?? 'provider_unavailable', 0, 'No fue posible recuperar asistencia; no se reemplaza por cero.');
    }
    return $attendance;
}
