<?php
declare(strict_types=1);

require __DIR__ . '/../rrhh/fte/fte_monthly_engine.php';

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
$near = static fn($actual, float $expected, float $epsilon = 0.0001): bool =>
    is_float($actual) || is_int($actual) ? abs((float)$actual - $expected) <= $epsilon : false;

$august = fte_monthly_calculate([
    'headcount' => 236,
    'theoretical_hours_per_person' => 176.5,
    'authorized_overtime_hours' => 1336,
    'vacation_hours' => 731.5,
    'employment_movement_hours' => 756,
    'medical_leave_hours' => 1622,
    'accident_hours' => 58.5,
    'day_permission_hours' => 176,
    'hour_permission_hours' => 164.94,
    'failure_delay_hours' => 114.01,
]);

$assert($near($august['theoretical_headcount_hours'], 41654), 'agosto: horas teoricas de la dotacion');
$assert($near($august['lost_hours'], 3622.95), 'agosto: suma de horas no disponibles');
$assert($near($august['adjusted_hours'], 39367.05), 'agosto: horas ajustadas');
$assert($near($august['fte'], 223.0428), 'agosto: FTE mensual');
$assert($near($august['headcount_fte_gap'], 12.9572), 'agosto: brecha Dotacion/FTE');
$assert($near($august['unavailable_hours_rate'], 0.086977, 0.000001), 'agosto: tasa de horas no disponibles');
$assert($near($august['availability_index'], 0.913023, 0.000001), 'agosto: indice global calculado desde totales');
$assert($august['warnings'] === [], 'agosto: cuadratura sin alertas');

$prorated = fte_monthly_calculate([
    'headcount' => 3,
    'theoretical_hours_per_person' => 176.5,
    'effective_theoretical_hours' => 159.5,
    'employment_movement_hours' => 370,
]);
$assert($near($prorated['theoretical_headcount_hours'], 529.5), 'proporcional: conserva las horas teoricas brutas para conciliacion');
$assert($near($prorated['effective_theoretical_hours'], 159.5), 'proporcional: usa la jornada vigente informada');
$assert($near($prorated['loss_components']['employment_movement_hours'], 370), 'proporcional: conserva movimientos como trazabilidad');
$assert($near($prorated['lost_hours'], 0), 'proporcional: no descuenta ingresos y salidas una segunda vez');
$assert($near($prorated['adjusted_hours'], 159.5), 'proporcional: calcula horas ajustadas desde la jornada vigente');
$assert(($prorated['uses_prorated_theoretical_hours'] ?? false) === true, 'proporcional: declara la nueva base de calculo');

// Referencias del Excel de agosto. Permanecen en la prueba y nunca ingresan al
// motor productivo ni sustituyen los valores obtenidos desde las fuentes.
$augustByCeco = fte_monthly_calculate_report([
    [
        'cost_center_code' => '02-01-01',
        'cost_center_name' => 'Paneles',
        'headcount' => 16,
        'theoretical_hours_per_person' => 176.5,
        'authorized_overtime_hours' => 159.45,
        'vacation_hours' => 45.5,
        'employment_movement_hours' => 0,
        'medical_leave_hours' => 84,
        'accident_hours' => 8.5,
        'day_permission_hours' => 8.5,
        'hour_permission_hours' => 8.22,
        'failure_delay_hours' => 0.38,
    ],
    [
        'cost_center_code' => '04-01-01',
        'cost_center_name' => 'Terminaciones',
        'headcount' => 72,
        'theoretical_hours_per_person' => 176.5,
        'authorized_overtime_hours' => 717.39,
        'vacation_hours' => 191,
        'employment_movement_hours' => 168,
        'medical_leave_hours' => 706,
        'accident_hours' => 0,
        'day_permission_hours' => 42.5,
        'hour_permission_hours' => 56.19,
        'failure_delay_hours' => 24.42,
    ],
]);
$augustCecoRows = [];
foreach ($augustByCeco['cost_centers'] as $augustCecoRow) {
    $augustCecoRows[$augustCecoRow['cost_center_code']] = $augustCecoRow;
}
$assert($near($augustCecoRows['02-01-01']['lost_hours'] ?? null, 155.1), 'agosto por CECO: Paneles conserva horas perdidas del Excel');
$assert($near($augustCecoRows['02-01-01']['fte'] ?? null, 16.0246), 'agosto por CECO: Paneles conserva FTE del Excel');
$assert($near($augustCecoRows['04-01-01']['lost_hours'] ?? null, 1188.11), 'agosto por CECO: Terminaciones conserva horas perdidas del Excel');
$assert($near($augustCecoRows['04-01-01']['fte'] ?? null, 69.333), 'agosto por CECO: Terminaciones conserva FTE del Excel');

$zero = fte_monthly_calculate(['headcount' => 0]);
$assert($zero['fte'] === null, 'dotacion cero: FTE es N/A');
$assert($zero['unavailable_hours_rate'] === null, 'dotacion cero: tasa es N/A y no division por cero');
$assert(in_array('ZERO_THEORETICAL_HOURS', $zero['warnings'], true), 'dotacion cero: genera alerta explicita');

$overtime = fte_monthly_calculate([
    'dotacion' => 16,
    'horas_teoricas_persona' => 176.5,
    'horas_extra_autorizadas' => 10,
]);
$assert($overtime['fte'] > 16, 'las horas extra pueden producir FTE superior a la dotacion');
$assert(in_array('FTE_EXCEEDS_HEADCOUNT', $overtime['warnings'], true), 'FTE superior a dotacion queda advertido');

$negativeRejected = false;
try {
    fte_monthly_calculate(['headcount' => 1, 'vacation_hours' => -1]);
} catch (InvalidArgumentException $e) {
    $negativeRejected = true;
}
$assert($negativeRejected, 'rechaza componentes negativos');

$fractionalHeadcountRejected = false;
try {
    fte_monthly_calculate(['headcount' => 1.5]);
} catch (InvalidArgumentException $e) {
    $fractionalHeadcountRejected = true;
}
$assert($fractionalHeadcountRejected, 'rechaza dotacion fraccionaria');

$losses = fte_monthly_calculate([
    'headcount' => 1,
    'theoretical_hours_per_person' => 8,
    'medical_leave_hours' => 10,
]);
$assert(in_array('NEGATIVE_ADJUSTED_HOURS', $losses['warnings'], true), 'detecta horas ajustadas negativas');
$assert(in_array('LOSSES_EXCEED_AVAILABLE_HOURS', $losses['warnings'], true), 'detecta descuentos mayores que horas disponibles');

$report = fte_monthly_calculate_report([
    [
        'cost_center_code' => 'CECO-1',
        'headcount' => 10,
        'theoretical_hours_per_person' => 100,
        'vacation_hours' => 100,
    ],
    [
        'cost_center_code' => 'CECO-2',
        'headcount' => 20,
        'theoretical_hours_per_person' => 100,
        'vacation_hours' => 100,
    ],
]);
$assert($report['totals']['headcount'] === 30, 'informe: suma dotacion de todos los CECO');
$assert($near($report['totals']['fte'], 28), 'informe: suma FTE de los CECO');
$assert($near($report['totals']['unavailable_hours_rate'], 0.066667, 0.000001), 'informe: tasa global ponderada desde horas totales');
$assert($near($report['totals']['availability_index'], 0.933333, 0.000001), 'informe: indice global no suma indices por CECO');
$assert($report['validation']['headcount_matches_rows'] === true, 'informe: valida cuadratura de dotacion');

$duplicateRejected = false;
try {
    fte_monthly_calculate_report([
        ['cost_center_code' => 'CECO 1', 'headcount' => 1],
        ['cost_center_code' => ' ceco 1 ', 'headcount' => 1],
    ]);
} catch (InvalidArgumentException $e) {
    $duplicateRejected = true;
}
$assert($duplicateRejected, 'informe: rechaza CECO duplicados normalizados');

$precisionReport = fte_monthly_calculate_report([
    [
        'cost_center_code' => 'PRECISION-1',
        'headcount' => 1,
        'theoretical_hours_per_person' => 1,
        'authorized_overtime_hours' => 0.00006,
    ],
    [
        'cost_center_code' => 'PRECISION-2',
        'headcount' => 1,
        'theoretical_hours_per_person' => 1,
        'authorized_overtime_hours' => 0.00006,
    ],
]);
$assert(
    abs((float)$precisionReport['totals']['authorized_overtime_hours'] - 0.00012) < 0.000000000001,
    'precision: suma horas crudas sin redondear cada CECO'
);
$assert(
    abs((float)$precisionReport['totals']['fte'] - 2.00012) < 0.000000000001,
    'precision: calcula FTE total sin redondeos intermedios'
);
echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed === 0 ? 0 : 1);
