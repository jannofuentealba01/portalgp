<?php
declare(strict_types=1);

function fte_calendar_load_config(): array
{
    $config = require __DIR__ . '/fte_calendar_config.php';
    if (!is_array($config)) {
        throw new RuntimeException('La configuracion del calendario FTE no es valida.');
    }
    return $config;
}

function fte_calendar_calculate_month(int $year, int $month, ?array $config = null): array
{
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        throw new InvalidArgumentException('El mes o anio solicitado no es valido.');
    }
    $config ??= fte_calendar_load_config();
    $rules = fte_calendar_validate_rules($config['work_rules'] ?? []);
    $nonWorkingDays = fte_calendar_normalize_date_map($config['non_working_days'] ?? []);
    $overrides = fte_calendar_normalize_overrides($config['date_overrides'] ?? []);

    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $last = $first->modify('last day of this month');
    $days = [];
    $hoursByWeekday = array_fill(1, 7, 0.0);
    $workdaysByWeekday = array_fill(1, 7, 0);
    $ruleHours = [];
    $totalHours = 0.0;
    $workdayCount = 0;

    for ($date = $first; $date <= $last; $date = $date->modify('+1 day')) {
        $dateKey = $date->format('Y-m-d');
        $weekday = (int)$date->format('N');
        $rule = fte_calendar_rule_for_date($rules, $dateKey);
        $hours = $rule !== null ? (float)($rule['weekday_hours'][$weekday] ?? 0) : 0.0;
        $status = $hours > 0 ? 'WORKDAY' : 'NON_WORKING_WEEKDAY';
        $reason = $rule === null ? 'Sin regla vigente' : '';

        if (isset($nonWorkingDays[$dateKey])) {
            $hours = 0.0;
            $status = 'NON_WORKING_DAY';
            $reason = $nonWorkingDays[$dateKey];
        }
        if (isset($overrides[$dateKey])) {
            $hours = $overrides[$dateKey]['hours'];
            $status = 'OVERRIDE';
            $reason = $overrides[$dateKey]['reason'];
        }

        if ($hours > 0) {
            $workdayCount++;
            $workdaysByWeekday[$weekday]++;
            $hoursByWeekday[$weekday] += $hours;
            $ruleCode = (string)($rule['code'] ?? 'OVERRIDE');
            $ruleHours[$ruleCode] = ($ruleHours[$ruleCode] ?? 0.0) + $hours;
        }
        $totalHours += $hours;
        $days[] = [
            'date' => $dateKey,
            'weekday' => $weekday,
            'status' => $status,
            'theoretical_hours' => round($hours, 4),
            'rule_code' => $rule['code'] ?? null,
            'reason' => $reason,
        ];
    }

    return [
        'year' => $year,
        'month' => $month,
        'period' => sprintf('%04d-%02d', $year, $month),
        'theoretical_hours_per_person' => round($totalHours, 4),
        'workday_count' => $workdayCount,
        'workdays_by_weekday' => $workdaysByWeekday,
        'hours_by_weekday' => array_map(static fn(float $hours): float => round($hours, 4), $hoursByWeekday),
        'hours_by_rule' => array_map(static fn(float $hours): float => round($hours, 4), $ruleHours),
        'days' => $days,
        'warnings' => fte_calendar_month_warnings($days),
    ];
}

function fte_calendar_validate_rules(array $rules): array
{
    $validated = [];
    foreach ($rules as $index => $rule) {
        if (!is_array($rule)) {
            throw new InvalidArgumentException('La regla de jornada ' . ($index + 1) . ' no es valida.');
        }
        $code = trim((string)($rule['code'] ?? ''));
        $from = fte_calendar_valid_date((string)($rule['effective_from'] ?? ''));
        $toRaw = trim((string)($rule['effective_to'] ?? ''));
        $to = $toRaw === '' ? null : fte_calendar_valid_date($toRaw);
        if ($code === '' || $from === null || ($toRaw !== '' && $to === null) || ($to !== null && $to < $from)) {
            throw new InvalidArgumentException('La regla de jornada ' . ($index + 1) . ' tiene vigencia invalida.');
        }
        $weekdayHours = [];
        foreach (($rule['weekday_hours'] ?? []) as $weekday => $hours) {
            $day = (int)$weekday;
            if ($day < 1 || $day > 7 || !is_numeric($hours) || (float)$hours < 0 || (float)$hours > 24) {
                throw new InvalidArgumentException('La regla ' . $code . ' contiene horas invalidas.');
            }
            $weekdayHours[$day] = (float)$hours;
        }
        $validated[] = [
            'code' => $code,
            'effective_from' => $from,
            'effective_to' => $to,
            'weekday_hours' => $weekdayHours,
        ];
    }
    usort($validated, static fn(array $left, array $right): int => strcmp($left['effective_from'], $right['effective_from']));
    for ($index = 1, $count = count($validated); $index < $count; $index++) {
        $previousTo = $validated[$index - 1]['effective_to'];
        if ($previousTo === null || $validated[$index]['effective_from'] <= $previousTo) {
            throw new InvalidArgumentException('Las reglas de jornada no pueden superponerse.');
        }
    }
    return $validated;
}

function fte_calendar_rule_for_date(array $rules, string $date): ?array
{
    foreach ($rules as $rule) {
        if ($date >= $rule['effective_from'] && ($rule['effective_to'] === null || $date <= $rule['effective_to'])) {
            return $rule;
        }
    }
    return null;
}

function fte_calendar_normalize_date_map(array $items): array
{
    $normalized = [];
    foreach ($items as $date => $reason) {
        $validDate = fte_calendar_valid_date((string)$date);
        if ($validDate === null) {
            throw new InvalidArgumentException('Existe un dia no laborable con fecha invalida.');
        }
        $normalized[$validDate] = trim((string)$reason);
    }
    return $normalized;
}

function fte_calendar_normalize_overrides(array $items): array
{
    $normalized = [];
    foreach ($items as $date => $override) {
        $validDate = fte_calendar_valid_date((string)$date);
        if ($validDate === null || !is_array($override) || !is_numeric($override['hours'] ?? null)) {
            throw new InvalidArgumentException('Existe una excepcion de calendario invalida.');
        }
        $hours = (float)$override['hours'];
        if ($hours < 0 || $hours > 24) {
            throw new InvalidArgumentException('Las horas de una excepcion deben estar entre 0 y 24.');
        }
        $normalized[$validDate] = [
            'hours' => $hours,
            'reason' => trim((string)($override['reason'] ?? 'Excepcion manual')),
        ];
    }
    return $normalized;
}

function fte_calendar_valid_date(string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $date->format('Y-m-d') === trim($value) ? $date->format('Y-m-d') : null;
}

function fte_calendar_month_warnings(array $days): array
{
    $warnings = [];
    foreach ($days as $day) {
        if (($day['reason'] ?? '') === 'Sin regla vigente') {
            $warnings[] = 'MONTH_HAS_DAYS_WITHOUT_WORK_RULE';
            break;
        }
    }
    return $warnings;
}

function fte_calendar_apply_to_monthly_input(array $monthlyInput, array $calendar): array
{
    if (!array_key_exists('theoretical_hours_per_person', $calendar)
        || !is_numeric($calendar['theoretical_hours_per_person'])) {
        throw new InvalidArgumentException('El resultado de calendario no contiene horas teoricas validas.');
    }
    $monthlyInput['theoretical_hours_per_person'] = (float)$calendar['theoretical_hours_per_person'];
    return $monthlyInput;
}
