<?php
declare(strict_types=1);

require __DIR__ . '/../rrhh/fte/fte_calendar_engine.php';
require __DIR__ . '/../rrhh/fte/fte_monthly_engine.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[OK] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
};
$near = static fn($actual, float $expected): bool => abs((float)$actual - $expected) < 0.0001;

$expected = [1 => 176.0, 2 => 176.0, 3 => 194.0, 4 => 184.0, 5 => 159.5, 6 => 176.5, 7 => 184.5, 8 => 176.5];
foreach ($expected as $month => $hours) {
    $calendar = fte_calendar_calculate_month(2026, $month);
    $assert($near($calendar['theoretical_hours_per_person'], $hours), sprintf('2026-%02d reproduce horas teoricas del Excel', $month));
}

$april = fte_calendar_calculate_month(2026, 4);
$assert($near($april['hours_by_rule']['JORNADA_44_HORAS'] ?? 0, 150), 'abril conserva 126 horas lunes-jueves y 24 horas de viernes antes del cambio');
$assert($near($april['hours_by_rule']['JORNADA_42_HORAS'] ?? 0, 34), 'abril aplica 4 dias de 8,5 horas tras el cambio');
$assert($near($april['hours_by_weekday'][5] ?? 0, 24), 'abril contabiliza tres viernes de 8 horas');

$august = fte_calendar_calculate_month(2026, 8);
$assert(($august['workdays_by_weekday'][5] ?? 0) === 4, 'agosto contabiliza cuatro viernes');
$assert(($august['warnings'] ?? []) === [], 'agosto no contiene dias sin regla vigente');
$augustFte = fte_monthly_calculate(fte_calendar_apply_to_monthly_input([
    'headcount' => 236,
    'authorized_overtime_hours' => 1336,
    'vacation_hours' => 731.5,
    'employment_movement_hours' => 756,
    'medical_leave_hours' => 1622,
    'accident_hours' => 58.5,
    'day_permission_hours' => 176,
    'hour_permission_hours' => 164.94,
    'failure_delay_hours' => 114.01,
], $august));
$assert($near($augustFte['fte'], 223.0428), 'calendario de agosto alimenta el motor mensual y reproduce 223,04 FTE');

$custom = fte_calendar_load_config();
$custom['date_overrides']['2026-08-03'] = ['hours' => 4, 'reason' => 'Jornada especial de prueba'];
$augustOverride = fte_calendar_calculate_month(2026, 8, $custom);
$assert($near($augustOverride['theoretical_hours_per_person'], 172), 'una excepcion reemplaza las horas del dia indicado');
$overrideDay = array_values(array_filter($augustOverride['days'], static fn(array $day): bool => $day['date'] === '2026-08-03'))[0] ?? [];
$assert(($overrideDay['status'] ?? '') === 'OVERRIDE' && $near($overrideDay['theoretical_hours'] ?? 0, 4), 'la excepcion conserva estado y horas trazables');

$noRuleConfig = ['work_rules' => [], 'non_working_days' => [], 'date_overrides' => []];
$noRule = fte_calendar_calculate_month(2026, 8, $noRuleConfig);
$assert(in_array('MONTH_HAS_DAYS_WITHOUT_WORK_RULE', $noRule['warnings'], true), 'advierte meses sin regla de jornada');

$overlapRejected = false;
try {
    fte_calendar_calculate_month(2026, 8, [
        'work_rules' => [
            ['code' => 'A', 'effective_from' => '2026-01-01', 'effective_to' => null, 'weekday_hours' => [1 => 8]],
            ['code' => 'B', 'effective_from' => '2026-02-01', 'effective_to' => null, 'weekday_hours' => [1 => 7]],
        ],
    ]);
} catch (InvalidArgumentException $e) {
    $overlapRejected = true;
}
$assert($overlapRejected, 'rechaza reglas de jornada superpuestas');

echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed === 0 ? 0 : 1);
