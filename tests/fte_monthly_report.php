<?php
declare(strict_types=1);

require __DIR__ . '/../rrhh/fte/fte_lib.php';

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

$calendar = [
    'period' => '2026-08',
    'theoretical_hours_per_person' => 16.0,
    'workday_count' => 2,
    'days' => [
        ['date' => '2026-08-03', 'theoretical_hours' => 8.0],
        ['date' => '2026-08-04', 'theoretical_hours' => 8.0],
    ],
    'warnings' => [],
];
$people = [
    [
        'identifier' => '1-9', 'normalized_identifier' => '19', 'active' => true,
        'active_since' => '2026-01-01', 'active_until' => null,
        'jobs' => [['job_id' => 1, 'start_date' => '2026-01-01', 'end_date' => null, 'cost_center_code' => 'A', 'cost_center_name' => 'Area A']],
    ],
    [
        'identifier' => '2-7', 'normalized_identifier' => '27', 'active' => false,
        'active_since' => '2026-01-01', 'active_until' => '2026-08-03',
        'jobs' => [['job_id' => 2, 'start_date' => '2026-01-01', 'end_date' => '2026-08-03', 'cost_center_code' => 'A', 'cost_center_name' => 'Area A']],
    ],
];
$headcount = fte_headcount_build_month($people, 2026, 8, $calendar);
$reasons = ['19' => ['2026-08-04' => ['kind' => 'Vacaciones']]];
$attendance = ['19' => ['2026-08-03' => ['authorized_overtime_hours' => 1.0, 'delay_hours' => 0.5]]];
$report = fte_monthly_build_report($calendar, $headcount, $people, $reasons, $attendance);
$row = $report['cost_centers'][0] ?? [];

$assert(($row['headcount'] ?? null) === 2, 'usa dotacion vigente en algun momento del mes');
$assert(abs((float)($row['loss_components']['employment_movement_hours'] ?? 0) - 8.0) < 0.0001, 'calcula ingreso o salida desde horas no vigentes');
$assert(abs((float)($row['loss_components']['vacation_hours'] ?? 0) - 8.0) < 0.0001, 'convierte vacaciones a horas del calendario');
$assert(abs((float)($row['authorized_overtime_hours'] ?? 0) - 1.0) < 0.0001, 'incorpora horas extra autorizadas');
$assert(abs((float)($row['loss_components']['failure_delay_hours'] ?? 0) - 0.5) < 0.0001, 'incorpora atrasos GeoVictoria');
$assert(abs((float)($row['fte'] ?? 0) - 1.0313) < 0.0001, 'calcula FTE mensual con el motor unico');
$assert(($report['validation']['headcount_matches_rows'] ?? false) === true, 'conserva cuadratura de dotacion');
$assert(($report['rankings']['largest_gap']['cost_center_code'] ?? '') === 'A', 'genera ranking gerencial por CECO');

$filtered = fte_monthly_build_report($calendar, $headcount, $people, $reasons, $attendance, ['NO_EXISTE']);
$assert(($filtered['totals']['headcount'] ?? -1) === 0, 'respeta filtro de CECO');

echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed === 0 ? 0 : 1);
