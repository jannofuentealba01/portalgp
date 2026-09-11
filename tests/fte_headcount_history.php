<?php
declare(strict_types=1);

require __DIR__ . '/../rrhh/fte/fte_calendar_engine.php';
require __DIR__ . '/../rrhh/fte/fte_headcount_history.php';

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
$job = static fn(int $id, string $from, ?string $to, string $ceco): array => [
    'job_id' => $id,
    'start_date' => $from,
    'end_date' => $to,
    'cost_center_code' => $ceco,
    'cost_center_name' => 'Nombre ' . $ceco,
];

$people = [
    ['normalized_identifier' => '1', 'active' => true, 'active_since' => '2020-01-01', 'active_until' => null, 'jobs' => [$job(1, '2020-01-01', null, 'A')]],
    ['normalized_identifier' => '2', 'active' => true, 'active_since' => '2026-08-15', 'active_until' => null, 'jobs' => [$job(2, '2026-08-15', null, 'A')]],
    ['normalized_identifier' => '3', 'active' => false, 'active_since' => '2020-01-01', 'active_until' => '2026-08-10', 'jobs' => [$job(3, '2020-01-01', '2026-08-10', 'B')]],
    ['normalized_identifier' => '4', 'active' => true, 'active_since' => '2020-01-01', 'active_until' => null, 'jobs' => [
        $job(4, '2020-01-01', '2026-08-15', 'A'),
        $job(5, '2026-08-16', null, 'B'),
    ]],
];

$history = fte_headcount_build_month($people, 2026, 8, fte_calendar_calculate_month(2026, 8));
$assert($history['unique_people_active_any_time'] === 4, 'cuenta personas vigentes en algun momento del mes');
$assert($history['end_of_month_headcount'] === 3, 'calcula dotacion vigente al cierre del mes');
$assert($near($history['average_calendar_day_headcount'], 89 / 31), 'calcula dotacion promedio por dia calendario');
$assert(count($history['daily']) === 31, 'conserva trazabilidad para cada dia del mes');
$assert($history['unassigned_person_days'] === 0, 'todos los dias-persona tienen CECO en la muestra');

$byCeco = [];
foreach ($history['cost_centers'] as $row) {
    $byCeco[$row['cost_center_code']] = $row;
}
$assert(($byCeco['A']['end_of_month_headcount'] ?? 0) === 2, 'CECO A tiene dos personas al cierre');
$assert(($byCeco['B']['end_of_month_headcount'] ?? 0) === 1, 'traspaso interno asigna la persona al CECO B al cierre');
$assert(($byCeco['B']['active_any_time_headcount'] ?? 0) === 2, 'CECO B conserva personas vigentes durante alguna parte del mes');

$day15 = array_values(array_filter($history['daily'], static fn(array $day): bool => $day['date'] === '2026-08-15'))[0] ?? [];
$day16 = array_values(array_filter($history['daily'], static fn(array $day): bool => $day['date'] === '2026-08-16'))[0] ?? [];
$assert(($day15['headcount_by_cost_center']['A'] ?? 0) === 3, 'el CECO anterior se conserva hasta la fecha final del cargo');
$assert(($day16['headcount_by_cost_center']['B'] ?? 0) === 1, 'el nuevo CECO rige desde la fecha inicial del cargo');

$missingCeco = fte_headcount_build_month([[
    'normalized_identifier' => '5',
    'active' => true,
    'active_since' => '2026-08-01',
    'active_until' => null,
    'jobs' => [],
]], 2026, 8);
$assert(in_array('ACTIVE_PERSON_WITHOUT_COST_CENTER_ASSIGNMENT', $missingCeco['warnings'], true), 'advierte persona activa sin CECO');
$assert($missingCeco['unassigned_person_days'] === 31, 'cuantifica dias-persona sin asignacion CECO');

echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed === 0 ? 0 : 1);
