<?php
declare(strict_types=1);

/** Construye un alias con tipo para impedir colisiones entre RUT e IDs internos. */
function fte_identity_alias(string $type, $value): string
{
    $type = strtoupper(trim($type));
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    if ($type === 'RUT') {
        $text = fte_normalize_identifier($text);
    } else {
        $text = strtoupper(preg_replace('/\s+/', '', $text) ?? '');
    }
    return $text === '' ? '' : $type . ':' . $text;
}

function fte_identity_aliases_for_person(array $person): array
{
    $aliases = [];
    $blocked = [];
    foreach (($person['identity_blocked_aliases'] ?? []) as $alias) {
        $alias = strtoupper(trim((string)$alias));
        if ($alias !== '') {
            $blocked[$alias] = true;
        }
    }
    foreach (($person['identity_aliases'] ?? []) as $alias) {
        $alias = strtoupper(trim((string)$alias));
        if ($alias !== '' && str_contains($alias, ':') && empty($blocked[$alias])) {
            $aliases[$alias] = true;
        }
    }
    $rut = fte_identity_alias('RUT', $person['normalized_identifier'] ?? $person['identifier'] ?? '');
    if ($rut !== '') {
        $aliases[$rut] = true;
    }
    $bukIds = $person['buk_employee_ids'] ?? [];
    if (!is_array($bukIds)) {
        $bukIds = [];
    }
    $bukIds[] = $person['buk_employee_id'] ?? '';
    foreach (array_unique(array_map('strval', $bukIds)) as $bukId) {
        $buk = fte_identity_alias('BUK_EMPLOYEE', $bukId);
        if ($buk !== '' && empty($blocked[$buk])) {
            $aliases[$buk] = true;
        }
    }
    $result = array_keys($aliases);
    sort($result, SORT_STRING);
    return $result;
}

function fte_identity_exclusion_map(array $config): array
{
    $map = [];
    foreach (($config['identity_exclusions'] ?? []) as $entry) {
        if (is_array($entry)) {
            $identifier = fte_normalize_identifier($entry['identifier'] ?? '');
            $name = trim((string)($entry['name'] ?? ''));
            $reason = trim((string)($entry['reason'] ?? 'Exclusion definida por RR.HH.'));
        } else {
            $identifier = fte_normalize_identifier($entry);
            $name = '';
            $reason = 'Exclusion definida por RR.HH.';
        }
        if ($identifier === '') {
            continue;
        }
        $map[$identifier] = ['identifier' => $identifier, 'name' => $name, 'reason' => $reason];
    }
    ksort($map, SORT_STRING);
    return $map;
}

/** Trabajadores que integran la dotacion Buk, pero no se consultan en GeoVictoria. */
function fte_attendance_exclusion_map(array $config): array
{
    $map = [];
    foreach (($config['attendance_exclusions'] ?? []) as $entry) {
        if (is_array($entry)) {
            $identifier = fte_normalize_identifier($entry['identifier'] ?? '');
            $name = trim((string)($entry['name'] ?? ''));
            $reason = trim((string)($entry['reason'] ?? 'Cobertura GeoVictoria pendiente'));
        } else {
            $identifier = fte_normalize_identifier($entry);
            $name = '';
            $reason = 'Cobertura GeoVictoria pendiente';
        }
        if ($identifier !== '') {
            $map[$identifier] = ['identifier' => $identifier, 'name' => $name, 'reason' => $reason];
        }
    }
    ksort($map, SORT_STRING);
    return $map;
}

/**
 * Separa cobertura de asistencia sin retirar personas de la dotacion Buk.
 * La lista excluida queda nominalmente disponible para bloquear el cierre.
 */
function fte_attendance_partition_people(array $people, array $config): array
{
    $policy = fte_attendance_exclusion_map($config);
    $included = [];
    $excluded = [];
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        if ($identifier === '' || !isset($policy[$identifier])) {
            $included[] = $person;
            continue;
        }
        $excluded[] = [
            'identifier' => trim((string)($person['identifier'] ?? $identifier)),
            'normalized_identifier' => $identifier,
            'person_name' => trim((string)($person['person_name'] ?? '')) ?: $policy[$identifier]['name'],
            'cost_center_code' => trim((string)($person['cost_center_code'] ?? '')),
            'cost_center_name' => trim((string)($person['cost_center_name'] ?? '')),
            'reason' => $policy[$identifier]['reason'],
        ];
    }
    usort($excluded, static fn(array $left, array $right): int => strcmp(
        (string)$left['normalized_identifier'],
        (string)$right['normalized_identifier']
    ));
    return ['included' => $included, 'excluded' => $excluded];
}

function fte_identity_person_sort_key(array $person): string
{
    $latest = '';
    foreach (($person['jobs'] ?? []) as $job) {
        $latest = max($latest, (string)($job['start_date'] ?? ''));
    }
    return (!empty($person['active']) ? '0' : '1') . '|' . (99999999 - (int)str_replace('-', '', $latest))
        . '|' . strtoupper(trim((string)($person['buk_employee_id'] ?? '')));
}

function fte_identity_merge_person_group(array $group): array
{
    usort($group, static fn(array $a, array $b): int => strcmp(fte_identity_person_sort_key($a), fte_identity_person_sort_key($b)));
    $merged = $group[0];
    $jobs = [];
    $activeValues = [];
    $activeSince = [];
    $activeUntil = [];
    $employmentDatesVerified = [];
    $employmentPeriods = [];
    $bukIds = [];
    foreach ($group as $person) {
        if (array_key_exists('active', $person) && $person['active'] !== null) {
            $activeValues[] = (bool)$person['active'];
        }
        $employmentDatesVerified[] = ($person['employment_dates_verified'] ?? true) === true;
        $personPeriods = is_array($person['employment_periods'] ?? null)
            ? $person['employment_periods']
            : [];
        if (!$personPeriods && (!empty($person['active_since']) || !empty($person['active_until']))) {
            $personPeriods[] = [
                'start_date' => $person['active_since'] ?? null,
                'end_date' => $person['active_until'] ?? null,
                'start_source' => $person['employment_start_source'] ?? 'Buk employee.active_since',
                'end_source' => $person['employment_end_source'] ?? 'Buk employee.active_until',
                'verified' => ($person['employment_dates_verified'] ?? true) === true,
            ];
        }
        foreach ($personPeriods as $period) {
            if (!is_array($period)) {
                continue;
            }
            $start = fte_buk_date($period['start_date'] ?? null);
            $end = fte_buk_date($period['end_date'] ?? null);
            $key = ($start ?? '') . '|' . ($end ?? '');
            $employmentPeriods[$key] = [
                'start_date' => $start,
                'end_date' => $end,
                'start_source' => (string)($period['start_source'] ?? 'Buk employee.active_since'),
                'end_source' => (string)($period['end_source'] ?? 'Buk employee.active_until'),
                'verified' => ($period['verified'] ?? true) === true,
            ];
        }
        if (!empty($person['active_since'])) {
            $activeSince[] = (string)$person['active_since'];
        }
        if (!empty($person['active_until'])) {
            $activeUntil[] = (string)$person['active_until'];
        }
        $bukId = trim((string)($person['buk_employee_id'] ?? ''));
        if ($bukId !== '') {
            $bukIds[] = $bukId;
        }
        foreach (($person['buk_employee_ids'] ?? []) as $historicalBukId) {
            $historicalBukId = trim((string)$historicalBukId);
            if ($historicalBukId !== '') {
                $bukIds[] = $historicalBukId;
            }
        }
        foreach (($person['jobs'] ?? []) as $job) {
            if (!is_array($job)) {
                continue;
            }
            $jobKey = !empty($job['job_id'])
                ? 'ID:' . (string)$job['job_id']
                : 'ROW:' . hash('sha256', json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $jobs[$jobKey] = $job;
        }
        if (trim((string)($merged['person_name'] ?? '')) === '' && trim((string)($person['person_name'] ?? '')) !== '') {
            $merged['person_name'] = trim((string)$person['person_name']);
        }
        if (trim((string)($merged['buk_employee_id'] ?? '')) === '' && trim((string)($person['buk_employee_id'] ?? '')) !== '') {
            $merged['buk_employee_id'] = trim((string)$person['buk_employee_id']);
        }
    }
    $merged['jobs'] = array_values($jobs);
    usort($merged['jobs'], static fn(array $a, array $b): int =>
        strcmp((string)($a['start_date'] ?? ''), (string)($b['start_date'] ?? ''))
        ?: ((int)($a['job_id'] ?? 0) <=> (int)($b['job_id'] ?? 0)));
    $merged['active'] = in_array(true, $activeValues, true)
        ? true
        : ($activeValues !== [] && !in_array(true, $activeValues, true) ? false : null);
    sort($activeSince, SORT_STRING);
    sort($activeUntil, SORT_STRING);
    $merged['active_since'] = $activeSince[0] ?? ($merged['active_since'] ?? null);
    $merged['active_until'] = $merged['active'] === true ? null : ($activeUntil ? end($activeUntil) : ($merged['active_until'] ?? null));
    $merged['employment_start_source'] = 'Buk employee.active_since';
    $merged['employment_end_source'] = 'Buk employee.active_until';
    $merged['employment_dates_verified'] = !in_array(false, $employmentDatesVerified, true);
    $merged['employment_periods'] = array_values($employmentPeriods);
    usort($merged['employment_periods'], static fn(array $a, array $b): int =>
        strcmp((string)($a['start_date'] ?? ''), (string)($b['start_date'] ?? ''))
        ?: strcmp((string)($a['end_date'] ?? ''), (string)($b['end_date'] ?? '')));
    $merged['buk_employee_ids'] = array_values(array_unique($bukIds));
    sort($merged['buk_employee_ids'], SORT_STRING);
    return $merged;
}

/**
 * Devuelve una sola fila por trabajador, incorpora aliases tipados y aplica
 * exclusiones explícitas. Nunca usa el nombre como clave de conciliación.
 */
function fte_identity_unify_people(array $people, array $config = [], ?array &$diagnostics = null): array
{
    $groups = [];
    $missing = 0;
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        if ($identifier === '') {
            $missing++;
            continue;
        }
        $person['normalized_identifier'] = $identifier;
        $groups['RUT:' . $identifier][] = $person;
    }
    ksort($groups, SORT_STRING);
    $exclusions = fte_identity_exclusion_map($config);
    $unified = [];
    $excluded = [];
    $duplicates = [];
    foreach ($groups as $group) {
        $identifier = (string)($group[0]['normalized_identifier'] ?? '');
        $person = fte_identity_merge_person_group($group);
        $person['canonical_worker_id'] = 'RUT:' . $identifier;
        $person['identity_status'] = 'UNIFICADO';
        if (count($group) > 1) {
            $duplicates[] = ['identifier' => $identifier, 'records' => count($group)];
        }
        if (isset($exclusions[$identifier])) {
            $excluded[] = [
                'identifier' => trim((string)($person['identifier'] ?? $identifier)),
                'normalized_identifier' => $identifier,
                'person_name' => trim((string)($person['person_name'] ?? '')) ?: $exclusions[$identifier]['name'],
                'reason' => $exclusions[$identifier]['reason'],
            ];
            continue;
        }
        $unified['RUT:' . $identifier] = $person;
    }

    $owners = [];
    foreach ($unified as $person) {
        $identifier = (string)($person['normalized_identifier'] ?? '');
        $bukIds = $person['buk_employee_ids'] ?? [];
        if (!is_array($bukIds)) {
            $bukIds = [];
        }
        $bukIds[] = $person['buk_employee_id'] ?? '';
        foreach (array_unique(array_map('strval', $bukIds)) as $bukId) {
            $alias = fte_identity_alias('BUK_EMPLOYEE', $bukId);
            if ($alias !== '') {
                $owners[$alias][] = $identifier;
            }
        }
    }
    $ambiguous = [];
    foreach ($owners as $alias => $identifiers) {
        $identifiers = array_values(array_unique($identifiers));
        if (count($identifiers) > 1) {
            $ambiguous[] = ['alias' => $alias, 'identifiers' => $identifiers];
        }
    }
    foreach ($unified as &$person) {
        $identifier = (string)($person['normalized_identifier'] ?? '');
        $blockedAliases = [];
        foreach ($ambiguous as $entry) {
            if (in_array($identifier, $entry['identifiers'], true)) {
                $blockedAliases[] = $entry['alias'];
            }
        }
        $person['identity_blocked_aliases'] = $blockedAliases;
        $person['identity_aliases'] = fte_identity_aliases_for_person($person);
    }
    unset($person);

    usort($excluded, static fn(array $a, array $b): int => strcmp($a['normalized_identifier'], $b['normalized_identifier']));
    $diagnostics = [
        'policy_version' => 1,
        'source_records' => count($people),
        'unified_workers' => count($unified),
        'missing_identifier_records' => $missing,
        'duplicate_records_merged' => array_sum(array_map(static fn(array $row): int => max(0, $row['records'] - 1), $duplicates)),
        'duplicate_identifiers' => $duplicates,
        'ambiguous_aliases' => $ambiguous,
        'excluded_workers' => $excluded,
        'excluded_identifiers' => array_column($excluded, 'normalized_identifier'),
    ];
    return array_values($unified);
}

function fte_identity_aliases_for_absence(array $item): array
{
    $aliases = [];
    foreach (['employee_id','person_id','employee.id','person.id'] as $path) {
        $value = str_contains($path, '.') ? fte_arr_get($item, $path) : ($item[$path] ?? null);
        $alias = fte_identity_alias('BUK_EMPLOYEE', is_array($value) ? '' : $value);
        if ($alias !== '') {
            $aliases[$alias] = true;
        }
    }
    foreach (['employee.rut','person.rut','rut','employee.document_number','document_number','employee.identification','identification'] as $path) {
        $value = str_contains($path, '.') ? fte_arr_get($item, $path) : ($item[$path] ?? null);
        $alias = fte_identity_alias('RUT', is_array($value) ? '' : $value);
        if ($alias !== '') {
            $aliases[$alias] = true;
        }
    }
    $result = array_keys($aliases);
    sort($result, SORT_STRING);
    return $result;
}
