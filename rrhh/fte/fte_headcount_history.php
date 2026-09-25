<?php
declare(strict_types=1);

function fte_headcount_build_month(array $people, int $year, int $month, ?array $calendar = null): array
{
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        throw new InvalidArgumentException('El periodo de dotacion historica no es valido.');
    }
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $last = $first->modify('last day of this month');
    $calendarDays = [];
    foreach (($calendar['days'] ?? []) as $day) {
        if (is_array($day) && isset($day['date'])) {
            $calendarDays[(string)$day['date']] = (float)($day['theoretical_hours'] ?? 0);
        }
    }

    $daily = [];
    $summary = [];
    $uniqueAny = [];
    $personCalendarDays = 0;
    $personWorkdays = 0;
    $unassignedPersonDays = 0;

    for ($date = $first; $date <= $last; $date = $date->modify('+1 day')) {
        $dateKey = $date->format('Y-m-d');
        $isWorkday = isset($calendarDays[$dateKey])
            ? $calendarDays[$dateKey] > 0
            : (int)$date->format('N') <= 5;
        $counts = [];
        $total = 0;
        foreach ($people as $person) {
            if (!is_array($person) || !fte_headcount_person_active_on($person, $dateKey)) {
                continue;
            }
            $identifier = trim((string)($person['normalized_identifier'] ?? ''));
            if ($identifier === '') {
                continue;
            }
            $job = fte_headcount_job_on_date($person, $dateKey);
            $code = trim((string)($job['cost_center_code'] ?? ''));
            $name = trim((string)($job['cost_center_name'] ?? ''));
            if ($code === '') {
                $code = 'SIN_CECO';
                $unassignedPersonDays++;
            }
            $counts[$code] = ($counts[$code] ?? 0) + 1;
            $total++;
            $uniqueAny[$identifier] = true;
            $personCalendarDays++;
            if ($isWorkday) {
                $personWorkdays++;
            }
            if (!isset($summary[$code])) {
                $summary[$code] = [
                    'cost_center_code' => $code,
                    'cost_center_name' => $name,
                    'person_calendar_days' => 0,
                    'person_workdays' => 0,
                    'active_any_time_identifiers' => [],
                    'end_of_month_identifiers' => [],
                ];
            }
            if ($summary[$code]['cost_center_name'] === '' && $name !== '') {
                $summary[$code]['cost_center_name'] = $name;
            }
            $summary[$code]['person_calendar_days']++;
            if ($isWorkday) {
                $summary[$code]['person_workdays']++;
            }
            $summary[$code]['active_any_time_identifiers'][$identifier] = true;
            if ($dateKey === $last->format('Y-m-d')) {
                $summary[$code]['end_of_month_identifiers'][$identifier] = true;
            }
        }
        ksort($counts, SORT_NATURAL);
        $daily[] = [
            'date' => $dateKey,
            'is_workday' => $isWorkday,
            'total_headcount' => $total,
            'headcount_by_cost_center' => $counts,
        ];
    }

    $calendarDayCount = count($daily);
    $workdayCount = count(array_filter($daily, static fn(array $day): bool => $day['is_workday']));
    foreach ($summary as &$row) {
        $row['active_any_time_headcount'] = count($row['active_any_time_identifiers']);
        $row['end_of_month_headcount'] = count($row['end_of_month_identifiers']);
        $row['average_calendar_day_headcount'] = $calendarDayCount > 0
            ? $row['person_calendar_days'] / $calendarDayCount
            : null;
        $row['average_workday_headcount'] = $workdayCount > 0
            ? $row['person_workdays'] / $workdayCount
            : null;
        unset($row['active_any_time_identifiers'], $row['end_of_month_identifiers']);
    }
    unset($row);
    ksort($summary, SORT_NATURAL);

    $lastDay = $daily ? $daily[count($daily) - 1] : ['total_headcount' => 0];
    $warnings = [];
    if ($unassignedPersonDays > 0) {
        $warnings[] = 'ACTIVE_PERSON_WITHOUT_COST_CENTER_ASSIGNMENT';
    }

    return [
        'period' => $first->format('Y-m'),
        'month_start' => $first->format('Y-m-d'),
        'month_end' => $last->format('Y-m-d'),
        'unique_people_active_any_time' => count($uniqueAny),
        'end_of_month_headcount' => (int)$lastDay['total_headcount'],
        'average_calendar_day_headcount' => $calendarDayCount > 0 ? $personCalendarDays / $calendarDayCount : null,
        'average_workday_headcount' => $workdayCount > 0 ? $personWorkdays / $workdayCount : null,
        'person_calendar_days' => $personCalendarDays,
        'person_workdays' => $personWorkdays,
        'unassigned_person_days' => $unassignedPersonDays,
        'cost_centers' => array_values($summary),
        'daily' => $daily,
        'warnings' => $warnings,
        'available_headcount_rules' => [
            'end_of_month_headcount',
            'unique_people_active_any_time',
            'average_calendar_day_headcount',
            'average_workday_headcount',
        ],
    ];
}

function fte_headcount_person_active_on(array $person, string $date): bool
{
    $start = fte_headcount_date($person['active_since'] ?? null);
    $end = fte_headcount_date($person['active_until'] ?? null);
    $jobs = is_array($person['jobs'] ?? null) ? $person['jobs'] : [];
    if ($start === null) {
        $jobStarts = array_values(array_filter(array_map(
            static fn(array $job): ?string => fte_headcount_date($job['start_date'] ?? null),
            array_filter($jobs, 'is_array')
        )));
        if ($jobStarts) {
            sort($jobStarts);
            $start = $jobStarts[0];
        }
    }
    if ($end === null && ($person['active'] ?? null) === false) {
        $jobEnds = array_values(array_filter(array_map(
            static fn(array $job): ?string => fte_headcount_date($job['end_date'] ?? null),
            array_filter($jobs, 'is_array')
        )));
        if ($jobEnds) {
            rsort($jobEnds);
            $end = $jobEnds[0];
        }
    }
    return ($start === null || $date >= $start) && ($end === null || $date <= $end);
}

function fte_headcount_job_on_date(array $person, string $date): ?array
{
    $matches = [];
    foreach (($person['jobs'] ?? []) as $job) {
        if (!is_array($job)) {
            continue;
        }
        $start = fte_headcount_date($job['start_date'] ?? null);
        $end = fte_headcount_date($job['end_date'] ?? null);
        if (($start === null || $date >= $start) && ($end === null || $date <= $end)) {
            $matches[] = $job;
        }
    }
    if ($matches) {
        usort($matches, static fn(array $left, array $right): int =>
            strcmp((string)($right['start_date'] ?? ''), (string)($left['start_date'] ?? ''))
            ?: ((int)($right['job_id'] ?? 0) <=> (int)($left['job_id'] ?? 0)));
        return $matches[0];
    }
    if (empty($person['jobs'])) {
        return [
            'cost_center_code' => trim((string)($person['cost_center_code'] ?? '')),
            'cost_center_name' => trim((string)($person['cost_center_name'] ?? '')),
        ];
    }
    return null;
}

function fte_headcount_date($value): ?string
{
    $text = substr(trim((string)$value), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    return $date && $date->format('Y-m-d') === $text ? $text : null;
}
