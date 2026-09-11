<?php
declare(strict_types=1);

function fte_monthly_build_report(
    array $calendar,
    array $headcount,
    array $people,
    array $absenceReasons = [],
    array $attendance = [],
    array $requestedCodes = [],
    array $attendanceDiagnostics = []
): array {
    $requestedCodes = array_values(array_unique(array_filter(array_map('fte_normalize_cost_center', $requestedCodes))));
    $dateHours = [];
    foreach (($calendar['days'] ?? []) as $day) {
        if (is_array($day) && isset($day['date'])) {
            $dateHours[(string)$day['date']] = max(0.0, (float)($day['theoretical_hours'] ?? 0));
        }
    }
    $hoursPerPerson = (float)($calendar['theoretical_hours_per_person'] ?? array_sum($dateHours));
    $rows = [];
    foreach (($headcount['cost_centers'] ?? []) as $center) {
        if (!is_array($center)) {
            continue;
        }
        $code = fte_normalize_cost_center($center['cost_center_code'] ?? '');
        if ($code === '' || ($requestedCodes && !in_array($code, $requestedCodes, true))) {
            continue;
        }
        $rows[$code] = [
            'cost_center_code' => $code,
            'cost_center_name' => trim((string)($center['cost_center_name'] ?? '')),
            'headcount' => (int)($center['active_any_time_headcount'] ?? 0),
            'theoretical_hours_per_person' => $hoursPerPerson,
            'authorized_overtime_hours' => 0.0,
            'vacation_hours' => 0.0,
            'employment_movement_hours' => 0.0,
            'medical_leave_hours' => 0.0,
            'accident_hours' => 0.0,
            'day_permission_hours' => 0.0,
            'hour_permission_hours' => 0.0,
            'failure_delay_hours' => 0.0,
        ];
    }

    $activeHours = array_fill_keys(array_keys($rows), 0.0);
    foreach (($headcount['daily'] ?? []) as $day) {
        if (!is_array($day)) {
            continue;
        }
        $date = (string)($day['date'] ?? '');
        $hours = (float)($dateHours[$date] ?? 0);
        foreach (($day['headcount_by_cost_center'] ?? []) as $code => $count) {
            $code = fte_normalize_cost_center($code);
            if (isset($activeHours[$code])) {
                $activeHours[$code] += max(0, (int)$count) * $hours;
            }
        }
    }
    foreach ($rows as $code => &$row) {
        $fullHours = $row['headcount'] * $hoursPerPerson;
        $row['employment_movement_hours'] = max(0.0, $fullHours - ($activeHours[$code] ?? 0.0));
    }
    unset($row);

    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        if ($identifier === '') {
            continue;
        }
        foreach ($dateHours as $date => $theoreticalHours) {
            if (!fte_headcount_person_active_on($person, $date)) {
                continue;
            }
            $job = fte_headcount_job_on_date($person, $date);
            $code = fte_normalize_cost_center($job['cost_center_code'] ?? '');
            if (!isset($rows[$code])) {
                continue;
            }
            $reason = $absenceReasons[$identifier][$date] ?? null;
            $kind = fte_attendance_normalize_absence_type((string)($reason['kind'] ?? ''));
            if ($kind === 'VACACIONES') {
                $rows[$code]['vacation_hours'] += $theoreticalHours;
            } elseif ($kind === 'LICENCIA') {
                $rows[$code]['medical_leave_hours'] += $theoreticalHours;
            } elseif ($kind === 'ACCIDENTE') {
                $rows[$code]['accident_hours'] += $theoreticalHours;
            } elseif ($kind === 'PERMISO_DIA') {
                $rows[$code]['day_permission_hours'] += $theoreticalHours;
            }

            $mark = $attendance[$identifier][$date] ?? null;
            if (is_array($mark)) {
                if ((int)(new DateTimeImmutable($date))->format('N') <= 5) {
                    $rows[$code]['authorized_overtime_hours'] += max(0.0, (float)($mark['authorized_overtime_hours'] ?? 0));
                }
                if ($kind === '' && $theoreticalHours > 0) {
                    $rows[$code]['failure_delay_hours'] += min($theoreticalHours, max(0.0, (float)($mark['delay_hours'] ?? 0)));
                }
            }
        }
    }

    $calculated = fte_monthly_calculate_report(array_values($rows));
    $rank = static function (array $items, callable $value): ?array {
        if (!$items) {
            return null;
        }
        usort($items, static fn(array $a, array $b): int => $value($b) <=> $value($a));
        $top = $items[0];
        return [
            'cost_center_code' => $top['cost_center_code'],
            'cost_center_name' => $top['cost_center_name'],
            'value' => $value($top),
        ];
    };

    $calculated['rankings'] = [
        'largest_gap' => $rank($calculated['cost_centers'], static fn(array $r): float => (float)($r['headcount_fte_gap'] ?? 0)),
        'largest_unavailable_rate' => $rank($calculated['cost_centers'], static fn(array $r): float => (float)($r['unavailable_hours_rate'] ?? 0)),
        'most_overtime' => $rank($calculated['cost_centers'], static fn(array $r): float => (float)($r['authorized_overtime_hours'] ?? 0)),
        'most_licence' => $rank($calculated['cost_centers'], static fn(array $r): float => (float)($r['loss_components']['medical_leave_hours'] ?? 0)),
        'most_vacation' => $rank($calculated['cost_centers'], static fn(array $r): float => (float)($r['loss_components']['vacation_hours'] ?? 0)),
    ];
    $calculated['calendar'] = [
        'period' => (string)($calendar['period'] ?? ''),
        'theoretical_hours_per_person' => $hoursPerPerson,
        'workday_count' => (int)($calendar['workday_count'] ?? 0),
        'warnings' => $calendar['warnings'] ?? [],
    ];
    $calculated['headcount_rule'] = 'active_any_time_headcount';
    $calculated['attendance_diagnostics'] = [
        'successful' => count($attendanceDiagnostics['successful_identifiers'] ?? []),
        'failed' => count($attendanceDiagnostics['failed_identifiers'] ?? []),
        'unmatched' => count($attendanceDiagnostics['unmatched_identifiers'] ?? []),
    ];
    return $calculated;
}

function fte_build_monthly_payload(array $config, array $params): array
{
    $period = trim((string)($params['period'] ?? date('Y-m')));
    if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period, $match)) {
        throw new InvalidArgumentException('El periodo mensual no es valido.');
    }
    $year = (int)$match[1];
    $month = (int)$match[2];
    $requestedCodes = $params['cost_center_code'] ?? [];
    if (!is_array($requestedCodes)) {
        $requestedCodes = [$requestedCodes];
    }
    $requestedCodes = array_values(array_unique(array_filter(array_map('fte_normalize_cost_center', $requestedCodes))));
    $includeAttendance = filter_var($params['include_attendance'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $calendar = fte_calendar_calculate_month($year, $month);
    $people = fte_fetch_buk_people($config, false);
    $headcount = fte_headcount_build_month($people, $year, $month, $calendar);
    $from = new DateTimeImmutable($calendar['period'] . '-01');
    $to = $from->modify('last day of this month');
    $warnings = [];

    $country = trim((string)$config['buk_country']);
    $vacations = fte_buk_fetch_all($config, "/api/v1/{$country}/vacations", [
        'start_before' => $to->format('Y-m-d'),
        'end_after' => $from->format('Y-m-d'),
    ]);
    $licences = fte_buk_fetch_all($config, "/api/v1/{$country}/absences/licence", [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
    ]);
    if (!$vacations['ok']) {
        $warnings[] = 'No fue posible incorporar vacaciones desde Buk.';
    }
    if (!$licences['ok']) {
        $warnings[] = 'No fue posible incorporar licencias desde Buk.';
    }
    $reasons = fte_merge_absence_index(
        $vacations['ok'] ? fte_index_absence_items($vacations['items'], 'Vacaciones', $from, $to) : [],
        $licences['ok'] ? fte_index_absence_items($licences['items'], 'Licencia medica', $from, $to) : []
    );

    $normalizedReasons = [];
    foreach ($people as $person) {
        $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        foreach (array_values(array_unique(array_filter([
            $identifier,
            (string)($person['buk_employee_id'] ?? ''),
        ]))) as $key) {
            if (isset($reasons[$key])) {
                $normalizedReasons[$identifier] = fte_merge_absence_index(
                    [$identifier => $normalizedReasons[$identifier] ?? []],
                    [$identifier => $reasons[$key]]
                )[$identifier];
            }
        }
    }

    $attendance = [];
    $diagnostics = ['successful_identifiers' => [], 'failed_identifiers' => [], 'unmatched_identifiers' => []];
    if ($includeAttendance) {
        $attendancePeople = array_values(array_filter($people, static function (array $person) use ($calendar, $requestedCodes): bool {
            foreach (($calendar['days'] ?? []) as $day) {
                $date = (string)($day['date'] ?? '');
                if ($date === '' || !fte_headcount_person_active_on($person, $date)) {
                    continue;
                }
                $job = fte_headcount_job_on_date($person, $date);
                $code = fte_normalize_cost_center($job['cost_center_code'] ?? '');
                if ($code !== '' && (!$requestedCodes || in_array($code, $requestedCodes, true))) {
                    return true;
                }
            }
            return false;
        }));
        $attendance = fte_fetch_attendance($config, $attendancePeople, $from, $to, $diagnostics);
        if ($diagnostics['failed_identifiers']) {
            $warnings[] = 'GeoVictoria fallo para ' . count($diagnostics['failed_identifiers']) . ' persona(s).';
        }
        if ($diagnostics['unmatched_identifiers']) {
            $warnings[] = 'GeoVictoria no concilio ' . count($diagnostics['unmatched_identifiers']) . ' persona(s).';
        }
    } else {
        $warnings[] = 'Horas extra autorizadas y atrasos GeoVictoria no fueron incorporados en este calculo.';
    }

    $report = fte_monthly_build_report($calendar, $headcount, $people, $normalizedReasons, $attendance, $requestedCodes, $diagnostics);
    $report['period'] = $period;
    $report['is_preliminary'] = true;
    $warnings[] = 'Dotacion provisional: personas vigentes en algun momento por CECO. Los traslados pueden contar una persona en varios CECO.';
    if (($headcount['unassigned_person_days'] ?? 0) > 0) {
        $warnings[] = 'Existen dias de trabajadores sin CECO; revisar asignaciones historicas.';
    }
    if (!$includeAttendance) {
        $report['rankings']['most_overtime'] = null;
    }
    $report['warnings'] = array_values(array_unique(array_merge($warnings, array_map(
        static fn(string $warning): string => 'Calendario: ' . $warning,
        $calendar['warnings'] ?? []
    ))));
    if ($period > '2026-08') {
        $report['warnings'][] = 'El calendario posterior a agosto de 2026 aun no ha sido contrastado con el cierre mensual de RR.HH.';
    }
    $report['source_coverage'] = [
        ['component' => 'Calendario y horas teoricas', 'status' => 'AUTOMATICO', 'source' => 'Calendario PortalGP'],
        ['component' => 'Dotacion e ingresos/salidas', 'status' => 'AUTOMATICO_PENDIENTE_REGLA', 'source' => 'Buk'],
        ['component' => 'Vacaciones', 'status' => $vacations['ok'] ? 'AUTOMATICO' : 'NO_DISPONIBLE', 'source' => 'Buk'],
        ['component' => 'Licencias', 'status' => $licences['ok'] ? 'AUTOMATICO' : 'NO_DISPONIBLE', 'source' => 'Buk'],
        ['component' => 'Horas extra y atrasos', 'status' => $includeAttendance ? 'PRELIMINAR' : 'NO_CARGADO', 'source' => 'GeoVictoria'],
        ['component' => 'Accidentes y permisos', 'status' => 'PENDIENTE_FUENTE_RRHH', 'source' => 'Pendiente'],
    ];
    return $report;
}
