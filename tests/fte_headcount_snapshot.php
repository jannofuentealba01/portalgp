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

$calendar = fte_calendar_calculate_month(2026, 8);
$people = [
    [
        'identifier' => '1-9',
        'normalized_identifier' => '19',
        'person_name' => 'Persona Trasladada',
        'buk_employee_id' => 'buk-1',
        'active' => true,
        'active_since' => '2026-01-01',
        'active_until' => null,
        'jobs' => [
            ['job_id' => 10, 'start_date' => '2026-01-01', 'end_date' => '2026-08-15', 'cost_center_code' => 'A', 'cost_center_name' => 'Area A'],
            ['job_id' => 11, 'start_date' => '2026-08-16', 'end_date' => null, 'cost_center_code' => 'B', 'cost_center_name' => 'Area B'],
        ],
    ],
    [
        'identifier' => '2-7',
        'normalized_identifier' => '27',
        'person_name' => 'Persona Saliente',
        'buk_employee_id' => 'buk-2',
        'active' => false,
        'active_since' => '2026-01-01',
        'active_until' => '2026-08-10',
        'jobs' => [
            ['job_id' => 20, 'start_date' => '2026-01-01', 'end_date' => '2026-08-10', 'cost_center_code' => 'A', 'cost_center_name' => 'Area A'],
        ],
    ],
];

$snapshot = fte_headcount_snapshot_build($people, 2026, 8, $calendar);
$rows = [];
foreach ($snapshot['rows'] as $row) {
    $rows[$row['normalized_identifier'] . '|' . $row['cost_center_code']] = $row;
}

$assert($snapshot['period'] === '2026-08', 'identifica el periodo mensual');
$assert($snapshot['counting_rule'] === 'monthly_unique_active_any_time_by_cost_center', 'registra la regla de conteo');
$assert($snapshot['total_unique_people'] === 2, 'cuenta personas unicas una sola vez');
$assert($snapshot['total_cost_center_assignments'] === 3, 'conserva dos CECO para una persona trasladada');
$assert($snapshot['end_of_month_headcount'] === 1, 'calcula la dotacion al cierre');
$assert(($rows['19|A']['first_active_date'] ?? '') === '2026-08-01', 'conserva inicio de la asignacion anterior');
$assert(($rows['19|A']['last_active_date'] ?? '') === '2026-08-15', 'conserva termino de la asignacion anterior');
$assert(($rows['19|B']['first_active_date'] ?? '') === '2026-08-16', 'conserva inicio del nuevo CECO');
$assert(($rows['19|B']['active_at_month_end'] ?? false) === true, 'marca el CECO vigente al cierre');
$assert(($rows['27|A']['active_at_month_end'] ?? true) === false, 'no marca al trabajador saliente al cierre');
$assert(count($rows['19|A']['active_dates'] ?? []) === 15, 'conserva cada fecha vigente del primer CECO');
$assert(count($rows['19|B']['active_dates'] ?? []) === 16, 'conserva cada fecha vigente del segundo CECO');
$assert(strlen((string)$snapshot['hash']) === 64, 'genera hash de integridad SHA-256');

$approvedFixture = [
    'snapshot' => [
        'status' => 'APROBADO',
        'period' => '2026-08',
        'total_unique_people' => $snapshot['total_unique_people'],
        'unassigned_person_days' => $snapshot['unassigned_person_days'],
    ],
    'rows' => $snapshot['rows'],
];
$officialInputs = fte_headcount_snapshot_report_inputs($approvedFixture, $calendar);
$officialPeople = [];
foreach ($officialInputs['people'] as $person) {
    $officialPeople[$person['normalized_identifier']] = $person;
}
$officialDaily = [];
foreach ($officialInputs['headcount']['daily'] as $day) {
    $officialDaily[$day['date']] = $day;
}
$assert($officialInputs['headcount']['unique_people_active_any_time'] === 2, 'la fotografia aprobada reconstruye personas unicas');
$assert(count($officialInputs['headcount']['cost_centers']) === 2, 'la fotografia aprobada reconstruye los CECO del mes');
$assert(count($officialPeople['19']['jobs'] ?? []) === 2, 'la fotografia aprobada conserva el traslado diario entre CECO');
$assert(
    ($officialPeople['19']['employment_dates_verified'] ?? true) === false
        && array_key_exists('active_since', $officialPeople['19'])
        && $officialPeople['19']['active_since'] === null
        && array_key_exists('active_until', $officialPeople['19'])
        && $officialPeople['19']['active_until'] === null,
    'la fotografia antigua no convierte rangos de CECO en fechas laborales'
);
$assert((int)($officialDaily['2026-08-15']['headcount_by_cost_center']['A'] ?? 0) === 1, 'el dia anterior al traslado conserva el CECO A');
$assert((int)($officialDaily['2026-08-16']['headcount_by_cost_center']['B'] ?? 0) === 1, 'el dia del traslado usa el CECO B');

$sameSnapshot = fte_headcount_snapshot_build($people, 2026, 8, $calendar);
$assert(hash_equals($snapshot['hash'], $sameSnapshot['hash']), 'el mismo contenido genera el mismo hash');
$people[0]['person_name'] = 'Nombre corregido';
$changedSnapshot = fte_headcount_snapshot_build($people, 2026, 8, $calendar);
$assert(!hash_equals($snapshot['hash'], $changedSnapshot['hash']), 'una correccion nominal genera una nueva version potencial');

$apiSource = (string)file_get_contents(__DIR__ . '/../rrhh/fte/fte_api.php');
$viewSource = (string)file_get_contents(__DIR__ . '/../rrhh/fte/fte_mensual.php');
$sqlSource = (string)file_get_contents(__DIR__ . '/../rrhh/fte/db/patch_fte_dotacion_snapshot.sql');
$assert(str_contains($apiSource, '$action === \'save_headcount_snapshot\''), 'la API expone el guardado mensual');
$assert(str_contains($apiSource, '$action === \'approve_headcount_snapshot\''), 'la API expone la aprobacion mensual');
$assert(str_contains($apiSource, "'eliminacion'"), 'la aprobacion exige el nivel superior del permiso FTE');
$assert(str_contains($apiSource, 'pgpVerifyCsrf'), 'el guardado y la aprobacion exigen token CSRF');
$assert(str_contains($viewSource, 'id="saveSnapshot"'), 'la vista ofrece guardar la fotografia');
$assert(str_contains($viewSource, 'id="approveSnapshot"'), 'la vista ofrece aprobar y congelar la fotografia');
$assert(str_contains($viewSource, 'id="snapshotExpectedHeadcount"'), 'la vista exige una dotacion de control explicita');
$assert(str_contains($viewSource, 'id="viewSnapshot"'), 'la vista permite revisar el detalle guardado');
$assert(str_contains($apiSource, "headcount_snapshot_details"), 'la API expone el detalle mensual guardado');
$assert(str_contains($viewSource, 'pasa a ser la fuente oficial de dotación del mes'), 'la vista explica el alcance de la aprobacion');
$assert(str_contains($sqlSource, 'UX_fte_dotacion_snapshot_vigente'), 'la base impide dos versiones vigentes del mismo mes');
$assert(str_contains($sqlSource, 'trg_fte_dotacion_snapshot_inmutable'), 'la base vuelve inmutable la cabecera aprobada');
$assert(str_contains($sqlSource, 'trg_fte_dotacion_snapshot_detalle_inmutable'), 'la base vuelve inmutable el detalle aprobado');
$assert(str_contains((string)file_get_contents(__DIR__ . '/../rrhh/fte/fte_monthly_report.php'), 'APPROVED_SNAPSHOT'), 'el informe mensual reconoce la fuente aprobada');

echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed === 0 ? 0 : 1);
