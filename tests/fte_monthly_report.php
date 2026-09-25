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
$attendance = ['19' => ['2026-08-03' => ['accomplished_overtime_hours' => 1.0, 'delay_hours' => 0.5]]];
$report = fte_monthly_build_report($calendar, $headcount, $people, $reasons, $attendance);
$row = $report['cost_centers'][0] ?? [];

$assert(($row['headcount'] ?? null) === 2, 'usa dotacion vigente en algun momento del mes');
$assert(abs((float)($row['theoretical_headcount_hours'] ?? 0) - 32.0) < 0.0001, 'conserva horas teoricas brutas para conciliar dotacion por mes completo');
$assert(abs((float)($row['effective_theoretical_hours'] ?? 0) - 24.0) < 0.0001, 'prorratea horas teoricas por vigencia laboral real');
$assert(abs((float)($row['loss_components']['employment_movement_hours'] ?? 0) - 8.0) < 0.0001, 'calcula ingreso o salida desde horas no vigentes');
$assert(abs((float)($row['lost_hours'] ?? 0) - 8.5) < 0.0001, 'no suma movimientos laborales a las horas no disponibles');
$assert(abs((float)($row['loss_components']['employment_entry_hours'] ?? -1)) < 0.0001, 'expone ingresos por separado');
$assert(abs((float)($row['loss_components']['employment_exit_hours'] ?? 0) - 8.0) < 0.0001, 'expone salidas por separado');
$assert(abs((float)($row['loss_components']['vacation_hours'] ?? 0) - 8.0) < 0.0001, 'convierte vacaciones a horas del calendario');
$assert(abs((float)($row['authorized_overtime_hours'] ?? 0) - 1.0) < 0.0001, 'incorpora horas extra realizadas');
$assert(abs((float)($row['loss_components']['failure_delay_hours'] ?? 0) - 0.5) < 0.0001, 'incorpora atrasos GeoVictoria');
$assert(abs((float)($row['attendance_components']['delay_after_compensation_hours'] ?? 0) - 0.5) < 0.0001, 'expone atrasos por separado');
$assert(abs((float)($row['fte'] ?? 0) - 1.0313) < 0.0001, 'calcula FTE mensual con el motor unico');
$assert(($report['validation']['headcount_matches_rows'] ?? false) === true, 'conserva cuadratura de dotacion');
$assert(($report['rankings']['largest_gap']['cost_center_code'] ?? '') === 'A', 'genera ranking gerencial por CECO');
$assert(($row['headcount_metrics']['monthly_unique_active_any_time'] ?? null) === 2, 'expone dotacion mensual por CECO');
$assert(($row['headcount_metrics']['end_of_month'] ?? null) === 1, 'expone dotacion al cierre por CECO');
$assert(($report['headcount_definition']['metrics']['monthly_unique_active_any_time'] ?? null) === 2, 'define personas unicas del mes');
$assert(($report['headcount_definition']['metrics']['fte_headcount'] ?? null) === 2, 'declara la dotacion usada por el FTE');
$assert(($report['headcount_definition']['metrics']['end_of_month'] ?? null) === 1, 'define dotacion al cierre del mes');
$assert(abs((float)($report['headcount_definition']['metrics']['average_workday'] ?? 0) - (float)$headcount['average_workday_headcount']) < 0.0000001, 'conserva promedio laboral sin redondeo intermedio');
$assert(($report['headcount_definition']['cross_cost_center_duplicate_effect'] ?? null) === 0.0, 'informa ausencia de duplicidad entre CECO');

$assert(($report['employment_movement_definition']['source'] ?? '') === 'Buk active_since / active_until', 'declara las fechas laborales Buk como fuente de ingresos y salidas');
$assert(($report['employment_movement_definition']['cost_center_changes_counted'] ?? true) === false, 'declara que los cambios de CECO no son movimientos laborales');
$assert(($report['employment_movement_definition']['policy_version'] ?? 0) === 4, 'expone la version auditable de la regla de movimientos');
$assert(
    ($report['employment_movement_definition']['calculation_effect'] ?? '') === 'INFORMATIONAL_ONLY'
        && ($report['employment_movement_definition']['reduces_lost_hours'] ?? true) === false
        && ($report['employment_movement_definition']['theoretical_hours_are_prorated'] ?? false) === true,
    'declara que los movimientos son informativos y la vigencia se aplica en horas teoricas'
);
$assert(
    (int)($report['employment_movement_definition']['entry_event_count'] ?? -1) === 0
        && (int)($report['employment_movement_definition']['exit_event_count'] ?? -1) === 1
        && count($report['employment_movement_definition']['entry_events'] ?? []) === 0
        && count($report['employment_movement_definition']['exit_events'] ?? []) === 1,
    'expone cantidades y listas independientes para ingresos y salidas'
);
$assert(
    ($report['employment_movement_definition']['combined_total_is_reconciliation_only'] ?? false) === true,
    'declara el total combinado solo como control conciliatorio'
);
$assert(
    ($report['employment_movement_definition']['events'][0]['kind'] ?? '') === 'EXIT'
        && ($report['employment_movement_definition']['events'][0]['identifier'] ?? '') === '2-7'
        && ($report['employment_movement_definition']['events'][0]['date'] ?? '') === '2026-08-03'
        && abs((float)($report['employment_movement_definition']['events'][0]['hours'] ?? 0) - 8.0) < 0.0001,
    'cada movimiento conserva trabajador, tipo, fecha y horas'
);

$transferPerson = [[
    'identifier' => '3-5', 'normalized_identifier' => '35', 'active' => true,
    'active_since' => '2026-01-01', 'active_until' => null,
    'jobs' => [
        ['job_id' => 3, 'start_date' => '2026-01-01', 'end_date' => '2026-08-03', 'cost_center_code' => 'A', 'cost_center_name' => 'Area A'],
        ['job_id' => 4, 'start_date' => '2026-08-04', 'end_date' => null, 'cost_center_code' => 'B', 'cost_center_name' => 'Area B'],
    ],
]];
$transferHeadcount = fte_headcount_build_month($transferPerson, 2026, 8, $calendar);
$transferReport = fte_monthly_build_report($calendar, $transferHeadcount, $transferPerson);
$assert(abs((float)($transferReport['totals']['loss_components']['employment_movement_hours'] ?? -1)) < 0.0001, 'un cambio de CECO a mitad de mes no genera ingreso ni salida');
$assert(abs((float)($transferReport['totals']['loss_components']['employment_entry_hours'] ?? -1)) < 0.0001, 'un cambio de CECO no genera ingreso');
$assert(abs((float)($transferReport['totals']['loss_components']['employment_exit_hours'] ?? -1)) < 0.0001, 'un cambio de CECO no genera salida');
$assert((int)($transferReport['employment_movement_definition']['diagnostics']['mid_month_job_changes_ignored'] ?? 0) === 1, 'el cambio de CECO a mitad de mes queda diagnosticado para revision');
$assert(
    ($transferReport['employment_movement_definition']['issues'][0]['type'] ?? '') === 'MID_MONTH_CECO_CHANGE'
        && ($transferReport['employment_movement_definition']['issues'][0]['date'] ?? '') === '2026-08-04',
    'el traslado fuera del inicio de mes identifica trabajador y fecha para revision'
);

$monthStartTransferPerson = [[
    'identifier' => '6-K', 'normalized_identifier' => '6K', 'active' => true,
    'active_since' => '2026-01-01', 'active_until' => null,
    'jobs' => [
        ['job_id' => 8, 'start_date' => '2026-01-01', 'end_date' => '2026-07-31', 'cost_center_code' => 'A', 'cost_center_name' => 'Area A'],
        ['job_id' => 9, 'start_date' => '2026-08-01', 'end_date' => null, 'cost_center_code' => 'B', 'cost_center_name' => 'Area B'],
    ],
]];
$monthStartTransferHeadcount = fte_headcount_build_month($monthStartTransferPerson, 2026, 8, $calendar);
$monthStartTransferReport = fte_monthly_build_report($calendar, $monthStartTransferHeadcount, $monthStartTransferPerson);
$assert(
    (int)($monthStartTransferReport['employment_movement_definition']['diagnostics']['ceco_changes_at_month_start'] ?? 0) === 1
        && (int)($monthStartTransferReport['employment_movement_definition']['diagnostics']['mid_month_job_changes_ignored'] ?? -1) === 0
        && abs((float)($monthStartTransferReport['totals']['loss_components']['employment_movement_hours'] ?? -1)) < 0.0001,
    'un traslado efectivo el primer dia del mes es valido y no genera ingreso ni salida'
);

$jobGapPerson = [[
    'identifier' => '4-3', 'normalized_identifier' => '43', 'active' => true,
    'active_since' => '2026-01-01', 'active_until' => null,
    'jobs' => [
        ['job_id' => 5, 'start_date' => '2026-01-01', 'end_date' => '2026-08-03', 'cost_center_code' => 'A', 'cost_center_name' => 'Area A'],
        ['job_id' => 6, 'start_date' => '2026-08-05', 'end_date' => null, 'cost_center_code' => 'B', 'cost_center_name' => 'Area B'],
    ],
]];
$jobGapHeadcount = fte_headcount_build_month($jobGapPerson, 2026, 8, $calendar);
$jobGapReport = fte_monthly_build_report($calendar, $jobGapHeadcount, $jobGapPerson);
$assert(abs((float)($jobGapReport['totals']['loss_components']['employment_movement_hours'] ?? -1)) < 0.0001, 'un intervalo entre cargos no genera ingreso ni salida');

$newHirePerson = [[
    'identifier' => '5-1', 'normalized_identifier' => '51', 'active' => true,
    'active_since' => '2026-08-04', 'active_until' => null,
    'jobs' => [['job_id' => 7, 'start_date' => '2026-08-04', 'end_date' => null, 'cost_center_code' => 'B', 'cost_center_name' => 'Area B']],
]];
$newHireHeadcount = fte_headcount_build_month($newHirePerson, 2026, 8, $calendar);
$newHireReport = fte_monthly_build_report($calendar, $newHireHeadcount, $newHirePerson);
$assert(abs((float)($newHireReport['totals']['loss_components']['employment_movement_hours'] ?? 0) - 8.0) < 0.0001, 'un ingreso real identifica las horas anteriores a su fecha Buk');
$assert(abs((float)($newHireReport['totals']['effective_theoretical_hours'] ?? 0) - 8.0) < 0.0001, 'un ingreso real prorratea la jornada teorica desde su fecha Buk');
$assert(abs((float)($newHireReport['totals']['lost_hours'] ?? -1)) < 0.0001, 'un ingreso real no se vuelve a descontar como ausencia');
$assert(abs((float)($newHireReport['totals']['loss_components']['employment_entry_hours'] ?? 0) - 8.0) < 0.0001, 'un ingreso real queda separado de las salidas');
$assert(abs((float)($newHireReport['totals']['loss_components']['employment_exit_hours'] ?? -1)) < 0.0001, 'un ingreso real no genera horas de salida');
$assert(
    ($newHireReport['employment_movement_definition']['events'][0]['source'] ?? '') === 'Buk employee.active_since',
    'el ingreso conserva el campo Buk que origino el evento'
);

$legacyPerson = $newHirePerson;
$legacyPerson[0]['employment_dates_verified'] = false;
$legacyHeadcount = fte_headcount_build_month($legacyPerson, 2026, 8, $calendar);
$legacyReport = fte_monthly_build_report($calendar, $legacyHeadcount, $legacyPerson);
$assert(
    abs((float)($legacyReport['totals']['loss_components']['employment_movement_hours'] ?? -1)) < 0.0001
        && (int)($legacyReport['employment_movement_definition']['diagnostics']['unverified_employment_date_records'] ?? 0) === 1,
    'una fuente sin fechas laborales verificadas no inventa movimientos desde cargos o CECO'
);

$rehiredPerson = [[
    'identifier' => '7-8', 'normalized_identifier' => '78', 'active' => true,
    'active_since' => '2026-01-01', 'active_until' => null,
    'employment_dates_verified' => true,
    'employment_periods' => [
        ['start_date' => '2026-01-01', 'end_date' => '2026-08-03', 'verified' => true],
        ['start_date' => '2026-08-04', 'end_date' => null, 'verified' => true],
    ],
    'jobs' => [
        ['job_id' => 10, 'start_date' => '2026-01-01', 'end_date' => '2026-08-03', 'cost_center_code' => 'A', 'cost_center_name' => 'Area A'],
        ['job_id' => 11, 'start_date' => '2026-08-04', 'end_date' => null, 'cost_center_code' => 'B', 'cost_center_name' => 'Area B'],
    ],
]];
$rehiredHeadcount = fte_headcount_build_month($rehiredPerson, 2026, 8, $calendar);
$rehiredReport = fte_monthly_build_report($calendar, $rehiredHeadcount, $rehiredPerson);
$assert(
    abs((float)($rehiredReport['totals']['loss_components']['employment_movement_hours'] ?? -1)) < 0.0001
        && (int)($rehiredReport['employment_movement_definition']['diagnostics']['mid_month_job_changes_ignored'] ?? -1) === 0,
    'una recontratacion continua conserva ambos eventos sin duplicar horas ni confundirla con traslado de CECO'
);
$assert(abs((float)($report['totals']['loss_components']['employment_movement_hours'] ?? 0) - ((float)($report['totals']['loss_components']['employment_entry_hours'] ?? 0) + (float)($report['totals']['loss_components']['employment_exit_hours'] ?? 0))) < 0.0001, 'el total de movimientos cuadra con ingresos mas salidas');

$boundaryPeople = [
    [
        'identifier' => '8-6', 'normalized_identifier' => '86', 'active' => true,
        'active_since' => '2026-08-01', 'active_until' => null,
        'jobs' => [['job_id' => 12, 'start_date' => '2026-08-01', 'end_date' => null, 'cost_center_code' => 'A', 'cost_center_name' => 'Area A']],
    ],
    [
        'identifier' => '9-4', 'normalized_identifier' => '94', 'active' => false,
        'active_since' => '2026-01-01', 'active_until' => '2026-08-31',
        'jobs' => [['job_id' => 13, 'start_date' => '2026-01-01', 'end_date' => '2026-08-31', 'cost_center_code' => 'A', 'cost_center_name' => 'Area A']],
    ],
];
$boundaryHeadcount = fte_headcount_build_month($boundaryPeople, 2026, 8, $calendar);
$boundaryReport = fte_monthly_build_report($calendar, $boundaryHeadcount, $boundaryPeople);
$assert(
    (int)($boundaryReport['employment_movement_definition']['entry_event_count'] ?? 0) === 1
        && (int)($boundaryReport['employment_movement_definition']['exit_event_count'] ?? 0) === 1
        && abs((float)($boundaryReport['employment_movement_definition']['entry_hours'] ?? -1)) < 0.0001
        && abs((float)($boundaryReport['employment_movement_definition']['exit_hours'] ?? -1)) < 0.0001,
    'un ingreso el primer dia y una salida el ultimo quedan visibles aunque aporten cero horas'
);

$vacationCalendar = [
    'period' => '2026-08',
    'theoretical_hours_per_person' => 8.5,
    'workday_count' => 1,
    'days' => [['date' => '2026-08-03', 'theoretical_hours' => 8.5]],
    'warnings' => [],
];
$vacationPerson = [[
    'identifier' => '10-8', 'normalized_identifier' => '108', 'person_name' => 'Persona Vacaciones', 'active' => true,
    'active_since' => '2026-01-01', 'active_until' => null,
    'jobs' => [['job_id' => 14, 'start_date' => '2026-01-01', 'end_date' => null, 'cost_center_code' => 'A', 'cost_center_name' => 'Area A']],
]];
$vacationHeadcount = fte_headcount_build_month($vacationPerson, 2026, 8, $vacationCalendar);
$vacationReasons = ['108' => ['2026-08-03' => ['kind' => 'Vacaciones']]];
$partialVacationReport = fte_monthly_build_report(
    $vacationCalendar,
    $vacationHeadcount,
    $vacationPerson,
    $vacationReasons,
    ['108' => ['2026-08-03' => [
        'time_offs' => [['type_description' => 'Vacaciones media jornada', 'amount_hours' => '04:00:00']],
        'non_worked_hours' => 4.5,
    ]]],
    [],
    ['successful_identifiers' => ['108']]
);
$assert(
    abs((float)($partialVacationReport['totals']['loss_components']['vacation_hours'] ?? 0) - 4.0) < 0.0001
        && (int)($partialVacationReport['vacation_definition']['partial_days'] ?? 0) === 1
        && (int)($partialVacationReport['vacation_definition']['conflict_count'] ?? -1) === 0,
    'una vacacion parcial descuenta la duracion real y queda conciliada'
);
$geoOnlyVacationReport = fte_monthly_build_report(
    $vacationCalendar,
    $vacationHeadcount,
    $vacationPerson,
    [],
    ['108' => ['2026-08-03' => [
        'time_offs' => [['type_description' => 'Vacaciones media jornada', 'amount_hours' => '04:00:00']],
    ]]],
    [],
    ['successful_identifiers' => ['108']]
);
$assert(
    abs((float)($geoOnlyVacationReport['totals']['loss_components']['vacation_hours'] ?? -1)) < 0.0001
        && (int)($geoOnlyVacationReport['vacation_definition']['conflict_count'] ?? 0) === 1
        && ($geoOnlyVacationReport['vacation_definition']['conflicts'][0]['conflict_type'] ?? '') === 'SOLO_GEOVICTORIA',
    'una vacacion solo en GeoVictoria queda pendiente sin inventar un descuento'
);
$bukVsPermissionReport = fte_monthly_build_report(
    $vacationCalendar,
    $vacationHeadcount,
    $vacationPerson,
    $vacationReasons,
    ['108' => ['2026-08-03' => [
        'time_offs' => [['type_description' => 'Permisos por Horas', 'amount_hours' => '04:00:00']],
    ]]],
    [],
    ['successful_identifiers' => ['108']]
);
$assert(
    abs((float)($bukVsPermissionReport['totals']['loss_components']['vacation_hours'] ?? 0) - 8.5) < 0.0001
        && (int)($bukVsPermissionReport['vacation_definition']['conflict_count'] ?? 0) === 1
        && ($bukVsPermissionReport['vacation_definition']['conflicts'][0]['conflict_type'] ?? '') === 'BUK_VS_GEOVICTORIA_TIPO_DISTINTO',
    'una discrepancia entre vacaciones Buk y permiso GeoVictoria queda visible sin reclasificacion silenciosa'
);

$priorityUnit = fte_monthly_resolve_daily_loss_priority(8.5, [
    'ACCIDENTE' => ['coverage_hours' => 8.5, 'loss_hours' => 8.5],
    'LICENCIA' => ['coverage_hours' => 8.5, 'loss_hours' => 8.5],
    'VACACIONES' => ['coverage_hours' => 4.0, 'loss_hours' => 4.0],
]);
$assert(
    abs((float)$priorityUnit['applied_loss_hours'] - 8.5) < 0.0001
        && abs((float)$priorityUnit['avoided_duplicate_loss_hours'] - 12.5) < 0.0001
        && ($priorityUnit['allocations']['ACCIDENTE']['resolution'] ?? '') === 'APLICADO'
        && ($priorityUnit['allocations']['LICENCIA']['resolution'] ?? '') === 'OMITIDO_POR_PRIORIDAD',
    'la prioridad diaria aplica accidente una sola vez y audita licencia y vacaciones superpuestas'
);

$partialPriorityReport = fte_monthly_build_report(
    $vacationCalendar,
    $vacationHeadcount,
    $vacationPerson,
    $vacationReasons,
    ['108' => ['2026-08-03' => [
        'time_offs' => [
            ['type_description' => 'Vacaciones media jornada', 'amount_hours' => '04:00:00'],
            ['type_description' => 'Permisos por Horas', 'amount_hours' => '03:00:00'],
        ],
        'non_worked_hours' => 4.0,
        'accomplished_overtime_hours' => 2.0,
    ]]],
    [],
    ['successful_identifiers' => ['108']]
);
$partialPriorityLoss = $partialPriorityReport['totals']['loss_components'] ?? [];
$assert(
    abs((float)($partialPriorityLoss['vacation_hours'] ?? 0) - 4.0) < 0.0001
        && abs((float)($partialPriorityLoss['hour_permission_hours'] ?? -1)) < 0.0001
        && abs((float)($partialPriorityLoss['failure_delay_hours'] ?? -1)) < 0.0001
        && abs((float)($partialPriorityReport['totals']['lost_hours'] ?? 0) - 4.0) < 0.0001,
    'una vacacion parcial conserva su valor y evita volver a descontar permiso y falla del mismo dia'
);
$assert(
    abs((float)($partialPriorityReport['totals']['authorized_overtime_hours'] ?? 0) - 2.0) < 0.0001
        && (int)($partialPriorityReport['deduplication_definition']['overlap_days'] ?? 0) === 1
        && abs((float)($partialPriorityReport['deduplication_definition']['avoided_duplicate_loss_hours'] ?? 0) - 7.0) < 0.0001
        && ($partialPriorityReport['deduplication_definition']['matches_report_loss_components'] ?? false) === true,
    'las horas extra quedan separadas y la duplicidad diaria cuadra con los componentes del reporte'
);

$hourPermissionReport = fte_monthly_build_report(
    $vacationCalendar,
    $vacationHeadcount,
    $vacationPerson,
    [],
    ['108' => ['2026-08-03' => [
        'time_offs' => [[
            'external_id' => 'PERM-13',
            'type_description' => 'Permiso por horas',
            'amount_hours' => '02:15:00',
        ]],
    ]]],
    [],
    ['successful_identifiers' => ['108']]
);
$hourPermissionEvent = $hourPermissionReport['hour_permission_definition']['events'][0] ?? [];
$assert(
    abs((float)($hourPermissionReport['hour_permission_definition']['applied_hours'] ?? 0) - 2.25) < 0.0001
        && (int)($hourPermissionReport['hour_permission_definition']['raw_record_count'] ?? 0) === 1,
    'el detalle explicativo conserva el total y registro original del permiso por hora'
);
$assert(
    ($hourPermissionEvent['person_name'] ?? '') === 'Persona Vacaciones'
        && ($hourPermissionEvent['identifier'] ?? '') === '108'
        && ($hourPermissionEvent['rut'] ?? '') === '10-8'
        && ($hourPermissionEvent['date'] ?? '') === '2026-08-03'
        && ($hourPermissionEvent['cost_center_code'] ?? '') === 'A'
        && abs((float)($hourPermissionEvent['theoretical_hours'] ?? 0) - 8.5) < 0.0001
        && abs((float)($hourPermissionEvent['calculated_hours'] ?? 0) - 2.25) < 0.0001
        && ($hourPermissionEvent['source_records'][0]['external_id'] ?? '') === 'PERM-13'
        && ($hourPermissionEvent['rule_applied'] ?? '') !== '',
    'cada permiso por hora explica trabajador, RUT, fecha, CECO, jornada, fuente, horas y regla'
);

$measuredHourPermissionReport = fte_monthly_build_report(
    $vacationCalendar,
    $vacationHeadcount,
    $vacationPerson,
    [],
    ['108' => ['2026-08-03' => [
        'non_worked_hours' => 1.5,
        'time_offs' => [[
            'external_id' => 'PERM-14',
            'type_description' => 'Permiso por horas',
            'amount_hours' => '04:00:00',
        ]],
    ]]],
    [],
    ['successful_identifiers' => ['108']]
);
$measuredHourPermissionEvent = $measuredHourPermissionReport['hour_permission_definition']['events'][0] ?? [];
$assert(
    abs((float)($measuredHourPermissionReport['hour_permission_definition']['applied_hours'] ?? 0) - 1.5) < 0.0001
        && abs((float)($measuredHourPermissionEvent['requested_hours'] ?? 0) - 4.0) < 0.0001
        && abs((float)($measuredHourPermissionEvent['measured_non_worked_hours'] ?? 0) - 1.5) < 0.0001
        && ($measuredHourPermissionEvent['measurement_source'] ?? '') === 'GEOVICTORIA_NON_WORKED_HOURS_LIMITADO_POR_PERMISO',
    'un permiso por hora usa la perdida realmente medida por GeoVictoria sin superar la duracion autorizada'
);

$paidPermissionReport = fte_monthly_build_report(
    $vacationCalendar,
    $vacationHeadcount,
    $vacationPerson,
    [],
    ['108' => ['2026-08-03' => [
        'time_offs' => [['type_description' => 'Permiso con goce']],
        'scheduled_interval' => true,
        'present' => false,
        'punch_count' => 0,
    ]]]
);
$assert(
    abs((float)($paidPermissionReport['totals']['loss_components']['day_permission_hours'] ?? -1)) < 0.0001
        && abs((float)($paidPermissionReport['totals']['loss_components']['failure_delay_hours'] ?? -1)) < 0.0001
        && abs((float)($paidPermissionReport['totals']['attendance_components']['day_permission_with_pay_hours'] ?? 0) - 8.5) < 0.0001
        && abs((float)($paidPermissionReport['deduplication_definition']['avoided_duplicate_loss_hours'] ?? 0)) < 0.0001
        && (int)($paidPermissionReport['failure_delay_definition']['unconfirmed_no_punch_records'] ?? 0) === 0,
    'un permiso con goce justifica la jornada sin reducir FTE ni permitir una falla duplicada'
);

$accidentOverlapReport = fte_monthly_build_report(
    $vacationCalendar,
    $vacationHeadcount,
    $vacationPerson,
    ['108' => ['2026-08-03' => [
        'kind' => 'Accidente',
        'overlapping_absence_kinds' => ['ACCIDENTE', 'LICENCIA'],
    ]]]
);
$assert(
    abs((float)($accidentOverlapReport['totals']['loss_components']['accident_hours'] ?? 0) - 8.5) < 0.0001
        && abs((float)($accidentOverlapReport['totals']['loss_components']['medical_leave_hours'] ?? -1)) < 0.0001
        && abs((float)($accidentOverlapReport['deduplication_definition']['avoided_duplicate_loss_hours'] ?? 0) - 8.5) < 0.0001,
    'un cruce de accidente y licencia aplica solo accidente y conserva la licencia como superposicion evitada'
);

$filtered = fte_monthly_build_report($calendar, $headcount, $people, $reasons, $attendance, ['NO_EXISTE']);
$assert(($filtered['totals']['headcount'] ?? -1) === 0, 'respeta filtro de CECO');

echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed === 0 ? 0 : 1);
