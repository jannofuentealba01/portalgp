<?php
declare(strict_types=1);

require __DIR__ . '/../rrhh/fte/fte_lib.php';

$reference = require __DIR__ . '/fixtures/fte_monthly_reference_2026.php';
$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[OK] {$label}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$label}\n";
};
$near = static fn($actual, float $expected, float $epsilon = 0.000000001): bool =>
    (is_float($actual) || is_int($actual)) && abs((float)$actual - $expected) <= $epsilon;

$fixtureRows = static function (array $month): array {
    $hours = (float)$month['theoretical_hours_per_person'];
    return array_map(static function (array $row) use ($hours): array {
        return [
            'cost_center_code' => (string)$row[0],
            'headcount' => (int)$row[1],
            'theoretical_hours_per_person' => $hours,
            'authorized_overtime_hours' => (float)$row[2],
            'vacation_hours' => (float)$row[3],
            'employment_movement_hours' => (float)$row[4],
            'medical_leave_hours' => (float)$row[5],
            'accident_hours' => (float)$row[6],
            'day_permission_hours' => (float)$row[7],
            'hour_permission_hours' => (float)$row[8],
            'failure_delay_hours' => (float)$row[9],
        ];
    }, $month['rows']);
};

foreach ($reference as $period => $month) {
    $report = fte_monthly_calculate_report($fixtureRows($month));
    $actual = $report['totals'];
    $expected = $month['totals'];
    $prefix = $period . ': ';

    $assert($actual['headcount'] === $expected['headcount'], $prefix . 'dotacion completa coincide con Excel');
    foreach (['theoretical_headcount_hours', 'authorized_overtime_hours', 'lost_hours', 'adjusted_hours', 'fte'] as $key) {
        $assert($near($actual[$key] ?? null, (float)$expected[$key]), $prefix . $key . ' coincide sin redondeo intermedio');
    }
    foreach (array_keys($actual['loss_components']) as $key) {
        $assert($near($actual['loss_components'][$key] ?? null, (float)$expected[$key]), $prefix . $key . ' coincide con Excel');
    }
    $assert(
        $near($actual['fte'] ?? null, (float)$actual['adjusted_hours'] / (float)$month['theoretical_hours_per_person']),
        $prefix . 'FTE global se recalcula desde horas absolutas'
    );
    $assert(
        count($report['cost_centers']) === count($month['rows'])
            && ($report['validation']['headcount_matches_rows'] ?? false) === true,
        $prefix . 'todos los CECO quedan incluidos y cuadran'
    );
    foreach ($report['cost_centers'] as $row) {
        $expectedAdjusted = (float)$row['theoretical_headcount_hours']
            + (float)$row['authorized_overtime_hours']
            - (float)$row['lost_hours'];
        $assert($near($row['adjusted_hours'] ?? null, $expectedAdjusted), $prefix . $row['cost_center_code'] . ' conserva precision por CECO');
    }
}

// 1. Media jornada: usa la duracion real informada, no redondea al dia completo.
$halfDay = fte_monthly_vacation_evidence([
    'time_offs' => [['type_description' => 'Vacaciones media jornada', 'amount_hours' => '04:15:00']],
], 8.5);
$assert(
    ($halfDay['is_partial'] ?? false) === true && $near($halfDay['hours'] ?? null, 4.25),
    'regla: media jornada conserva 4,25 horas reales'
);

// 2. Permiso con goce: queda informado, pero no reduce el FTE.
$paidPermission = fte_monthly_permission_hours_for_mark([
    'time_offs' => [['type_description' => 'Permiso con goce']],
], 8.5);
$assert(
    $near($paidPermission['day_hours'] ?? null, 0.0)
        && $near($paidPermission['paid_day_hours'] ?? null, 8.5),
    'regla: permiso con goce no descuenta horas'
);

// 3. Sabado: AccomplishedExtraTime se audita, pero no suma horas extra FTE.
$saturdayOvertime = fte_monthly_overtime_hours_for_mark([
    'accomplished_overtime_hours' => 5.75,
    'accomplished_overtime_available' => true,
], '2026-08-08');
$assert(
    $near($saturdayOvertime['applied_hours'] ?? null, 0.0)
        && $near($saturdayOvertime['excluded_hours'] ?? null, 5.75),
    'regla: horas extra de sabado quedan excluidas y auditadas'
);

// 4. Atraso compensado: no aplica el minimo 5/15 cuando el residuo es cero.
$compensatedDelay = fte_monthly_failure_delay_hours_for_mark([
    'present' => true,
    'complete' => true,
    'punch_count' => 2,
    'scheduled_interval' => true,
    'delay_raw_hours' => 5 / 60,
    'delay_after_compensation_hours' => 0.0,
    'delay_after_compensation_available' => true,
], 8.0, false);
$assert($near($compensatedDelay['applied_hours'] ?? null, 0.0), 'regla: atraso totalmente compensado no descuenta');

// 5. Licencia superpuesta: la prioridad diaria evita descontar dos veces.
$overlap = fte_monthly_resolve_daily_loss_priority(8.5, [
    'ACCIDENTE' => ['coverage_hours' => 8.5, 'loss_hours' => 8.5],
    'LICENCIA' => ['coverage_hours' => 8.5, 'loss_hours' => 8.5],
]);
$assert(
    $near($overlap['applied_loss_hours'] ?? null, 8.5)
        && $near($overlap['avoided_duplicate_loss_hours'] ?? null, 8.5),
    'regla: licencia superpuesta se descuenta una sola vez'
);

// 6. Cambio de CECO: conserva la asignacion diaria, sin inventar ingreso/salida.
$calendar = fte_calendar_calculate_month(2026, 8);
$transferPerson = [[
    'identifier' => '1-9',
    'normalized_identifier' => '19',
    'active' => true,
    'active_since' => '2026-01-01',
    'active_until' => null,
    'jobs' => [
        ['job_id' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-08-15', 'cost_center_code' => 'A', 'cost_center_name' => 'Area A'],
        ['job_id' => 2, 'start_date' => '2026-08-16', 'end_date' => null, 'cost_center_code' => 'B', 'cost_center_name' => 'Area B'],
    ],
]];
$transferHeadcount = fte_headcount_build_month($transferPerson, 2026, 8, $calendar);
$transferReport = fte_monthly_build_report($calendar, $transferHeadcount, $transferPerson);
$movement = $transferReport['totals']['loss_components']['employment_movement_hours'] ?? null;
$assert(
    $near($movement, 0.0)
        && (int)($transferReport['employment_movement_definition']['diagnostics']['mid_month_job_changes_ignored'] ?? 0) === 1,
    'regla: cambio de CECO no se convierte en ingreso o salida'
);

echo "Resultado punto 15: {$passed} OK, {$failed} fallidas.\n";
exit($failed === 0 ? 0 : 1);
