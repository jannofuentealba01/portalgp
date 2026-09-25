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
            'theoretical_hours' => $hours,
            'rule_code' => $rule['code'] ?? null,
            'reason' => $reason,
        ];
    }

    $result = [
        'year' => $year,
        'month' => $month,
        'period' => sprintf('%04d-%02d', $year, $month),
        'theoretical_hours_per_person' => $totalHours,
        'workday_count' => $workdayCount,
        'workdays_by_weekday' => $workdaysByWeekday,
        'hours_by_weekday' => $hoursByWeekday,
        'hours_by_rule' => $ruleHours,
        'days' => $days,
        'warnings' => fte_calendar_month_warnings($days),
        'policy' => fte_calendar_policy_metadata($rules, $nonWorkingDays, $overrides),
    ];
    $result['validation'] = fte_calendar_validate_result($result, true);
    return $result;
}

function fte_calendar_canonicalize($value)
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_keys($value) === range(0, count($value) - 1)) {
        return array_map('fte_calendar_canonicalize', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = fte_calendar_canonicalize($item);
    }
    return $value;
}

function fte_calendar_policy_metadata(array $rules, array $nonWorkingDays, array $overrides): array
{
    $definition = fte_calendar_canonicalize([
        'work_rules' => $rules,
        'non_working_days' => $nonWorkingDays,
        'date_overrides' => $overrides,
    ]);
    return [
        'version' => 1,
        'source' => 'PORTALGP_CALENDAR',
        'config_hash' => hash('sha256', json_encode(
            $definition,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        )),
        'worker_date_rule' => 'VIGENCIA_LABORAL_Y_CARGO_VIGENTE_EN_CADA_FECHA',
        'intermediate_rounding' => 'NONE',
        'display_rounding' => 'PRESENTATION_ONLY',
    ];
}

/**
 * Comprueba que el total mensual sea exactamente la suma del detalle diario.
 * La tolerancia solo absorbe el error binario natural de float; no redondea.
 */
function fte_calendar_validate_result(array $calendar, bool $requireCompleteMonth = false): array
{
    $period = trim((string)($calendar['period'] ?? ''));
    if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period, $match)) {
        throw new InvalidArgumentException('El calendario no contiene un periodo mensual valido.');
    }
    if (!is_numeric($calendar['theoretical_hours_per_person'] ?? null)) {
        throw new InvalidArgumentException('El calendario no contiene un total de horas teoricas valido.');
    }
    $declaredHours = (float)$calendar['theoretical_hours_per_person'];
    if (!is_finite($declaredHours) || $declaredHours < 0) {
        throw new InvalidArgumentException('El total de horas teoricas del calendario es invalido.');
    }

    $seenDates = [];
    $summedHours = 0.0;
    $workdayCount = 0;
    $daysWithoutRule = 0;
    foreach (($calendar['days'] ?? []) as $index => $day) {
        if (!is_array($day)) {
            throw new InvalidArgumentException('El dia ' . ($index + 1) . ' del calendario es invalido.');
        }
        $date = fte_calendar_valid_date((string)($day['date'] ?? ''));
        if ($date === null || !str_starts_with($date, $period . '-')) {
            throw new InvalidArgumentException('El calendario contiene una fecha fuera del periodo.');
        }
        if (isset($seenDates[$date])) {
            throw new InvalidArgumentException('El calendario contiene la fecha duplicada ' . $date . '.');
        }
        $seenDates[$date] = true;
        $rawHours = $day['theoretical_hours'] ?? null;
        if (!is_numeric($rawHours)) {
            throw new InvalidArgumentException('El calendario contiene horas teoricas no numericas.');
        }
        $hours = (float)$rawHours;
        if (!is_finite($hours) || $hours < 0 || $hours > 24) {
            throw new InvalidArgumentException('El calendario contiene horas teoricas fuera de rango.');
        }
        $summedHours += $hours;
        if ($hours > 0) {
            $workdayCount++;
        }
        if (($day['reason'] ?? '') === 'Sin regla vigente') {
            $daysWithoutRule++;
        }
    }
    if ($seenDates === []) {
        throw new InvalidArgumentException('El calendario no contiene detalle diario.');
    }

    $difference = $declaredHours - $summedHours;
    if (abs($difference) > 0.000000001) {
        throw new DomainException('El total de horas teoricas no coincide con la suma del calendario diario.');
    }
    if (array_key_exists('workday_count', $calendar) && (int)$calendar['workday_count'] !== $workdayCount) {
        throw new DomainException('La cantidad de dias habiles no coincide con el detalle del calendario.');
    }
    $expectedDays = (int)(new DateTimeImmutable($period . '-01'))->format('t');
    if ($requireCompleteMonth && count($seenDates) !== $expectedDays) {
        throw new DomainException('El calendario mensual no contiene todos los dias del periodo.');
    }

    return [
        'valid' => true,
        'complete_month' => count($seenDates) === $expectedDays,
        'day_count' => count($seenDates),
        'expected_day_count' => $expectedDays,
        'workday_count' => $workdayCount,
        'days_without_work_rule' => $daysWithoutRule,
        'declared_theoretical_hours' => $declaredHours,
        'summed_theoretical_hours' => $summedHours,
        'difference' => $difference,
        'intermediate_rounding_applied' => false,
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
    ksort($normalized, SORT_STRING);
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
    ksort($normalized, SORT_STRING);
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
    $validation = fte_calendar_validate_result($calendar);
    $monthlyInput['theoretical_hours_per_person'] = (float)$validation['summed_theoretical_hours'];
    return $monthlyInput;
}
