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

$assert(
    fte_monthly_buk_licence_kind(['licence_type' => 'Accidente de trabajo']) === 'Accidente',
    'Buk: separa accidente de trabajo'
);
$assert(
    fte_monthly_buk_licence_kind(['licence_type' => 'Accidente de trayecto']) === 'Accidente',
    'Buk: separa accidente de trayecto'
);
$assert(
    fte_monthly_buk_licence_kind(['licence_type' => 'Enfermedad o accidente comun']) === 'Licencia medica',
    'Buk: conserva enfermedad o accidente comun como licencia medica'
);
$partitioned = fte_monthly_partition_buk_licences([
    ['id' => 1, 'licence_type' => 'Enfermedad o accidente comun'],
    ['id' => 2, 'licence_type' => 'Accidente de trabajo'],
    ['id' => 3, 'licence_type' => 'Licencia Pre Natal'],
]);
$assert(count($partitioned['licences']) === 2, 'Buk: conserva dos licencias medicas');
$assert(count($partitioned['accidents']) === 1, 'Buk: identifica un accidente');

$merged = fte_monthly_merge_absence_indexes(
    ['19' => ['2026-08-03' => ['kind' => 'Vacaciones']]],
    ['19' => ['2026-08-03' => ['kind' => 'Licencia medica']]],
    ['19' => ['2026-08-03' => ['kind' => 'Accidente']]]
);
$assert(
    ($merged['19']['2026-08-03']['kind'] ?? '') === 'Accidente',
    'La prioridad mensual evita que licencia o vacaciones oculten un accidente'
);
$assert(
    in_array('LICENCIA', $merged['19']['2026-08-03']['overlapping_absence_kinds'] ?? [], true)
        && in_array('ACCIDENTE', $merged['19']['2026-08-03']['overlapping_absence_kinds'] ?? [], true),
    'La prioridad mensual conserva la trazabilidad del cruce licencia-accidente'
);

$assert(
    fte_monthly_time_off_kind(['type_description' => 'Permiso tramite personal dia']) === 'PERMISO_DIA',
    'GeoVictoria: clasifica permiso de dia'
);
$assert(
    fte_monthly_time_off_kind(['type_description' => 'Permiso tramite personal parcial']) === 'PERMISO_HORA',
    'GeoVictoria: clasifica permiso parcial por horas'
);
$assert(
    fte_monthly_time_off_kind(['type_description' => 'Permiso cumpleaños 1/2']) === 'PERMISO_HORA',
    'GeoVictoria: clasifica media jornada por horas'
);
$assert(
    fte_monthly_time_off_kind(['type_description' => 'Vacaciones']) === '',
    'GeoVictoria: no confunde vacaciones con permisos'
);
$assert(
    fte_monthly_is_medical_leave_time_off(['type_description' => 'Licencia médica']) === true,
    'GeoVictoria: reconoce una licencia medica'
);
$assert(
    fte_monthly_is_medical_leave_time_off(['type_description' => 'Accidente del trabajo']) === false
        && fte_monthly_is_accident_time_off(['type_description' => 'Accidente del trabajo']) === true,
    'GeoVictoria: no confunde accidentes con licencias medicas'
);
$assert(
    fte_monthly_is_medical_leave_time_off(['type_description' => 'Enfermedad o accidente común']) === true
        && fte_monthly_is_accident_time_off(['type_description' => 'Enfermedad o accidente común']) === false,
    'GeoVictoria: un accidente comun se mantiene como licencia medica'
);
$medicalTimeOffSummary = fte_monthly_summarize_permissions([
    '19' => ['2026-08-03' => ['time_offs' => [
        ['type_description' => 'Licencia médica'],
        ['type_description' => 'Accidente del trabajo'],
    ]]],
]);
$assert(
    (int)($medicalTimeOffSummary['medical_leave_records'] ?? 0) === 1
        && (int)($medicalTimeOffSummary['accident_records'] ?? 0) === 1
        && (int)($medicalTimeOffSummary['other_time_off_records'] ?? -1) === 0,
    'GeoVictoria: licencias y accidentes dejan de quedar sin clasificar'
);

$raw = [
    'Users' => [[
        'Identifier' => '1-9',
        'PlannedInterval' => [[
            'Date' => '20260803000000',
            'TotalAuthorizedOvertime' => '08:00:00',
            'AccomplishedExtraTime' => ['01:30:00', '00:30:00'],
            'TimeOffs' => [
                ['Id' => 'D1', 'TimeOffTypeDescription' => 'Permiso con Goce'],
                ['Id' => 'H1', 'TimeOffTypeDescription' => 'Permisos por Horas', 'StartTime' => '09:00:00', 'EndTime' => '11:00:00'],
                ['Id' => 'V1', 'TimeOffTypeDescription' => 'Vacaciones'],
            ],
        ]],
    ]],
];
$ordinaryAttendance = [];
fte_merge_attendance_payload($ordinaryAttendance, $raw);
$assert(
    !isset($ordinaryAttendance['19']['2026-08-03']['time_offs']),
    'El detalle TimeOff no altera las demas vistas FTE'
);

$monthlyAttendance = [];
fte_merge_attendance_payload($monthlyAttendance, $raw, true);
$summary = fte_monthly_summarize_permissions($monthlyAttendance);
$assert(
    abs((float)($monthlyAttendance['19']['2026-08-03']['authorized_overtime_hours'] ?? 0) - 8.0) < 0.0001,
    'GeoVictoria conserva las horas extra asignadas solo como antecedente'
);
$assert(
    abs((float)($monthlyAttendance['19']['2026-08-03']['accomplished_overtime_hours'] ?? 0) - 2.0) < 0.0001,
    'GeoVictoria obtiene las horas extra efectivamente realizadas'
);
$assert(
    ($monthlyAttendance['19']['2026-08-03']['accomplished_overtime_available'] ?? false) === true,
    'GeoVictoria identifica que AccomplishedExtraTime fue informado'
);
$assert(($summary['time_off_records'] ?? 0) === 3, 'FTE mensual captura los TimeOffs recibidos');
$assert(($summary['day_permission_records'] ?? 0) === 1, 'FTE mensual cuenta registros clasificados como permiso dia');
$assert(($summary['day_permission_with_pay_records'] ?? 0) === 1, 'FTE mensual separa permisos diarios con goce');
$assert(($summary['day_permission_without_pay_records'] ?? 0) === 0, 'FTE mensual no confunde un permiso con goce con uno sin goce');
$assert(($summary['day_permission_pending_records'] ?? 0) === 0, 'FTE mensual no deja pendiente un permiso explicitamente con goce');
$assert(($summary['hour_permission_records'] ?? 0) === 1, 'FTE mensual cuenta registros clasificados como permiso hora');
$assert(($summary['vacation_records'] ?? 0) === 1, 'FTE mensual reconoce vacaciones dentro de los TimeOffs');
$assert(($summary['other_time_off_records'] ?? -1) === 0, 'FTE mensual no deja vacaciones como categoria sin clasificar');

$assert(
    abs(fte_monthly_time_off_duration_hours(['amount_hours' => '04:15:00']) - 4.25) < 0.0001,
    'Permisos: utiliza AmountHours cuando GeoVictoria lo informa'
);
$assert(
    abs(fte_monthly_time_off_duration_hours(['start_time' => '09:15:00', 'end_time' => '11:45:00']) - 2.5) < 0.0001,
    'Permisos: calcula la diferencia entre hora inicial y final'
);
$partialVacation = fte_monthly_vacation_evidence([
    'time_offs' => [[
        'type_description' => 'Vacaciones media jornada',
        'amount_hours' => '04:00:00',
    ]],
    'non_worked_hours' => 4.5,
], 8.5);
$assert(
    ($partialVacation['has_vacation'] ?? false) === true
        && ($partialVacation['is_partial'] ?? false) === true
        && abs((float)($partialVacation['hours'] ?? 0) - 4.0) < 0.0001
        && ($partialVacation['source'] ?? '') === 'GEOVICTORIA_TIME_OFF_DURATION',
    'Vacaciones: una media jornada usa primero la duracion explicita de GeoVictoria'
);
$hntVacation = fte_monthly_vacation_evidence([
    'time_offs' => [['type_description' => 'Vacaciones']],
    'non_worked_hours' => 4.25,
], 8.5);
$assert(
    ($hntVacation['is_partial'] ?? false) === true
        && abs((float)($hntVacation['hours'] ?? 0) - 4.25) < 0.0001
        && ($hntVacation['source'] ?? '') === 'GEOVICTORIA_HNT',
    'Vacaciones: usa HNT cuando el TimeOff no informa duracion'
);
$fallbackVacation = fte_monthly_vacation_evidence([
    'time_offs' => [['type_description' => 'Vacaciones 1/2']],
], 8.5);
$assert(
    ($fallbackVacation['is_partial'] ?? false) === true
        && abs((float)($fallbackVacation['hours'] ?? 0) - 4.25) < 0.0001
        && ($fallbackVacation['source'] ?? '') === 'MEDIA_JORNADA_TEORICA_RESPALDO',
    'Vacaciones: una media jornada sin duracion usa la mitad de la jornada teorica'
);
$fullVacation = fte_monthly_vacation_evidence([
    'time_offs' => [['type_description' => 'Vacaciones']],
], 8.5);
$assert(
    ($fullVacation['is_partial'] ?? true) === false
        && abs((float)($fullVacation['hours'] ?? 0) - 8.5) < 0.0001,
    'Vacaciones: un TimeOff completo conserva la jornada teorica'
);
$dayHours = fte_monthly_permission_hours_for_mark([
    'time_offs' => [['type_description' => 'Permiso con Goce']],
], 8.5);
$assert(abs($dayHours['day_hours']) < 0.0001 && abs($dayHours['paid_day_hours'] - 8.5) < 0.0001, 'Permisos: un dia con goce se informa y no descuenta FTE');
$withoutPayHours = fte_monthly_permission_hours_for_mark([
    'time_offs' => [['type_description' => 'Permiso sin goce de remuneraciones']],
], 8.5);
$assert(abs($withoutPayHours['day_hours'] - 8.5) < 0.0001 && $withoutPayHours['day_status'] === 'SIN_GOCE', 'Permisos: un dia sin goce descuenta la jornada teorica');
$pendingDayHours = fte_monthly_permission_hours_for_mark([
    'time_offs' => [['type_description' => 'Permiso administrativo']],
], 8.5);
$assert(abs($pendingDayHours['day_hours']) < 0.0001 && abs($pendingDayHours['pending_day_hours'] - 8.5) < 0.0001 && $pendingDayHours['day_status'] === 'PENDIENTE', 'Permisos: un dia ambiguo no se descuenta y queda pendiente');
$partialHours = fte_monthly_permission_hours_for_mark([
    'time_offs' => [
        ['type_description' => 'Permisos por Horas', 'start_time' => '08:00:00', 'end_time' => '10:00:00'],
        ['type_description' => 'Permiso tramite personal parcial', 'amount_hours' => '01:30:00'],
    ],
], 8.0);
$assert(abs($partialHours['hour_hours'] - 3.5) < 0.0001, 'Permisos: suma duraciones parciales dentro de la jornada');
$cappedHours = fte_monthly_permission_hours_for_mark([
    'time_offs' => [['type_description' => 'Permisos por Horas', 'amount_hours' => '12:00:00']],
], 8.0);
$assert(abs($cappedHours['hour_hours'] - 8.0) < 0.0001, 'Permisos: limita el descuento a la jornada teorica');

$calendar = [
    'period' => '2026-08',
    'theoretical_hours_per_person' => 8.0,
    'workday_count' => 1,
    'days' => [['date' => '2026-08-03', 'theoretical_hours' => 8.0]],
    'warnings' => [],
];
$people = [[
    'identifier' => '1-9',
    'normalized_identifier' => '19',
    'active' => true,
    'active_since' => '2026-01-01',
    'jobs' => [[
        'job_id' => 1,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'cost_center_code' => 'A',
        'cost_center_name' => 'Area A',
    ]],
]];
$headcount = fte_headcount_build_month($people, 2026, 8, $calendar);
$monthlyReport = fte_monthly_build_report($calendar, $headcount, $people, [], [
    '19' => ['2026-08-03' => [
        'accomplished_overtime_hours' => 1.5,
        'delay_hours' => 0.5,
        'early_leave_hours' => 1.0,
        'non_worked_hours' => 2.0,
        'time_offs' => [[
            'type_description' => 'Permisos por Horas',
            'amount_hours' => '02:00:00',
        ]],
    ]],
]);
$monthlyTotals = $monthlyReport['totals'] ?? [];
$assert(abs((float)($monthlyTotals['loss_components']['hour_permission_hours'] ?? 0) - 2.0) < 0.0001, 'Informe mensual incorpora horas de permiso parcial');
$assert(abs((float)($monthlyTotals['authorized_overtime_hours'] ?? 0) - 1.5) < 0.0001, 'Informe mensual usa horas extra realizadas');
$assert(abs((float)($monthlyTotals['attendance_components']['delay_after_compensation_hours'] ?? 0) - 0.5) < 0.0001, 'Informe mensual separa atraso compensado');
$assert(abs((float)($monthlyTotals['attendance_components']['early_leave_after_compensation_hours'] ?? 0) - 1.0) < 0.0001, 'Informe mensual separa salida anticipada');
$assert(abs((float)($monthlyTotals['attendance_components']['non_worked_hours'] ?? 0) - 2.0) < 0.0001, 'Informe mensual separa horas no trabajadas');
$assert(abs((float)($monthlyTotals['loss_components']['failure_delay_hours'] ?? 0)) < 0.0001, 'No descuenta el atraso nuevamente cuando existe un permiso asociado');

$overtimePolicy = fte_monthly_overtime_policy();
$assert(
    ($overtimePolicy['allowed_iso_weekdays'] ?? []) === [1, 2, 3, 4, 5]
        && ($overtimePolicy['source_field'] ?? '') === 'AccomplishedExtraTime',
    'Horas extra: la politica usa AccomplishedExtraTime de lunes a viernes'
);
$weekdayOvertime = fte_monthly_overtime_hours_for_mark([
    'authorized_overtime_hours' => 8.0,
    'accomplished_overtime_hours' => 2.0,
    'accomplished_overtime_available' => true,
], '2026-08-03');
$assert(
    abs((float)$weekdayOvertime['applied_hours'] - 2.0) < 0.0001
        && abs((float)$weekdayOvertime['authorized_reference_hours'] - 8.0) < 0.0001,
    'Horas extra: aplica lo realizado y conserva lo autorizado solo como referencia'
);
$weekendOvertime = fte_monthly_overtime_hours_for_mark([
    'authorized_overtime_hours' => 5.0,
    'accomplished_overtime_hours' => 5.0,
    'accomplished_overtime_available' => true,
], '2026-08-08');
$assert(
    abs((float)$weekendOvertime['applied_hours']) < 0.0001
        && abs((float)$weekendOvertime['excluded_hours'] - 5.0) < 0.0001
        && ($weekendOvertime['excluded_by_weekday'] ?? false) === true,
    'Horas extra: excluye y audita lo realizado en sabado'
);
$missingAccomplished = fte_monthly_overtime_hours_for_mark([
    'authorized_overtime_hours' => 3.0,
    'accomplished_overtime_available' => false,
], '2026-08-03');
$assert(
    abs((float)$missingAccomplished['applied_hours']) < 0.0001
        && in_array('HORAS_REALIZADAS_NO_INFORMADAS', $missingAccomplished['conflict_types'] ?? [], true),
    'Horas extra: no convierte horas autorizadas en realizadas cuando falta AccomplishedExtraTime'
);
$overtimeCalendar = [
    'period' => '2026-08',
    'theoretical_hours_per_person' => 8.0,
    'workday_count' => 1,
    'days' => [
        ['date' => '2026-08-03', 'theoretical_hours' => 8.0],
        ['date' => '2026-08-08', 'theoretical_hours' => 0.0],
    ],
    'warnings' => [],
];
$overtimeHeadcount = fte_headcount_build_month($people, 2026, 8, $overtimeCalendar);
$overtimeReport = fte_monthly_build_report($overtimeCalendar, $overtimeHeadcount, $people, [], [
    '19' => [
        '2026-08-03' => [
            'authorized_overtime_hours' => 8.0,
            'accomplished_overtime_hours' => 2.0,
            'accomplished_overtime_available' => true,
        ],
        '2026-08-08' => [
            'authorized_overtime_hours' => 5.0,
            'accomplished_overtime_hours' => 5.0,
            'accomplished_overtime_available' => true,
        ],
    ],
]);
$assert(
    abs((float)($overtimeReport['totals']['authorized_overtime_hours'] ?? 0) - 2.0) < 0.0001
        && abs((float)($overtimeReport['overtime_definition']['reported_hours'] ?? 0) - 7.0) < 0.0001
        && abs((float)($overtimeReport['overtime_definition']['excluded_weekend_hours'] ?? 0) - 5.0) < 0.0001,
    'Informe: aplica horas extra laborales y conserva la exclusion nominal del fin de semana'
);

$failurePolicy = fte_monthly_failure_delay_policy();
$assert(
    abs((float)$failurePolicy['short_delay_threshold_minutes'] - 5.0) < 0.0001
        && abs((float)$failurePolicy['short_delay_required_compensation_minutes'] - 15.0) < 0.0001
        && ($failurePolicy['include_full_failures'] ?? true) === false
        && (int)($failurePolicy['policy_version'] ?? 0) >= 2,
    'Fallas: la politica 5/15 queda explicita y una jornada sin marcas requiere confirmacion'
);
$shortDelay = fte_monthly_failure_delay_hours_for_mark([
    'present' => true,
    'complete' => true,
    'punch_count' => 2,
    'scheduled_interval' => true,
    'delay_raw_hours' => 5 / 60,
    'delay_after_compensation_hours' => 5 / 60,
    'delay_after_compensation_available' => true,
], 8.0, false);
$assert(
    abs((float)$shortDelay['applied_hours'] - 0.25) < 0.0001
        && ($shortDelay['short_delay_minimum_applied'] ?? false) === true,
    'Fallas: un atraso de hasta cinco minutos sin compensar aplica el minimo de quince minutos'
);
$compensatedShortDelay = fte_monthly_failure_delay_hours_for_mark([
    'present' => true,
    'complete' => true,
    'punch_count' => 2,
    'scheduled_interval' => true,
    'delay_raw_hours' => 5 / 60,
    'delay_after_compensation_hours' => 0.0,
    'delay_after_compensation_available' => true,
], 8.0, false);
$assert(
    abs((float)$compensatedShortDelay['applied_hours']) < 0.0001,
    'Fallas: un atraso corto totalmente compensado no descuenta horas'
);
$fullFailure = fte_monthly_failure_delay_hours_for_mark([
    'present' => false,
    'complete' => false,
    'punch_count' => 0,
    'scheduled_interval' => true,
], 8.5, false, ['include_full_failures' => true]);
$assert(
    abs((float)$fullFailure['applied_hours'] - 8.5) < 0.0001
        && ($fullFailure['basis'] ?? '') === 'JORNADA_TEORICA',
    'Fallas: una ausencia confirmada puede aplicar la jornada teorica completa'
);
$unconfirmedFullFailure = fte_monthly_failure_delay_hours_for_mark([
    'present' => false,
    'complete' => false,
    'punch_count' => 0,
    'scheduled_interval' => true,
], 8.5, false);
$assert(
    abs((float)$unconfirmedFullFailure['applied_hours']) < 0.0001
        && abs((float)($unconfirmedFullFailure['pending_full_failure_hours'] ?? 0) - 8.5) < 0.0001
        && ($unconfirmedFullFailure['resolution'] ?? '') === 'PENDIENTE_CONFIRMACION_SIN_DESCUENTO'
        && in_array('JORNADA_SIN_MARCACION_REQUIERE_CONFIRMACION', $unconfirmedFullFailure['conflict_types'] ?? [], true),
    'Fallas: una jornada sin marcaciones queda pendiente y no descuenta horas automaticamente'
);
$partialFailure = fte_monthly_failure_delay_hours_for_mark([
    'present' => true,
    'complete' => true,
    'punch_count' => 2,
    'scheduled_interval' => true,
    'delay_raw_hours' => 0.5,
    'delay_after_compensation_hours' => 0.5,
    'delay_after_compensation_available' => true,
    'early_leave_raw_hours' => 1.0,
    'early_leave_after_compensation_hours' => 1.0,
    'early_leave_after_compensation_available' => true,
    'non_worked_hours' => 2.0,
], 8.0, false);
$assert(
    abs((float)$partialFailure['applied_hours'] - 2.0) < 0.0001
        && abs((float)$partialFailure['overlap_avoided_hours'] - 1.5) < 0.0001,
    'Fallas: consolida la perdida parcial sin duplicar NonWorkedHours, atraso y salida'
);
$justifiedFailure = fte_monthly_failure_delay_hours_for_mark([
    'present' => false,
    'complete' => false,
    'punch_count' => 0,
    'scheduled_interval' => true,
], 8.0, true);
$assert(
    abs((float)$justifiedFailure['applied_hours']) < 0.0001,
    'Fallas: una ausencia o permiso justificado evita duplicar la jornada'
);
$incompleteFailure = fte_monthly_failure_delay_hours_for_mark([
    'present' => true,
    'complete' => false,
    'punch_count' => 1,
    'scheduled_interval' => true,
], 8.0, false);
$assert(
    abs((float)$incompleteFailure['applied_hours']) < 0.0001
        && in_array('MARCACION_INCOMPLETA_SIN_PERDIDA_CUANTIFICABLE', $incompleteFailure['conflict_types'] ?? [], true),
    'Fallas: una marcacion incompleta sin medida queda pendiente y no inventa horas'
);
$fullFailureReport = fte_monthly_build_report($calendar, $headcount, $people, [], [
    '19' => ['2026-08-03' => [
        'present' => false,
        'complete' => false,
        'punch_count' => 0,
        'scheduled_interval' => true,
    ]],
], [], [], ['include_full_failures' => true]);
$assert(
    abs((float)($fullFailureReport['totals']['loss_components']['failure_delay_hours'] ?? 0) - 8.0) < 0.0001
        && (int)($fullFailureReport['failure_delay_definition']['full_failure_records'] ?? 0) === 1,
    'Informe: incorpora y audita una falla completa'
);
$pendingFullFailureReport = fte_monthly_build_report($calendar, $headcount, $people, [], [
    '19' => ['2026-08-03' => [
        'present' => false,
        'complete' => false,
        'punch_count' => 0,
        'scheduled_interval' => true,
    ]],
]);
$assert(
    abs((float)($pendingFullFailureReport['totals']['loss_components']['failure_delay_hours'] ?? -1)) < 0.0001
        && (int)($pendingFullFailureReport['failure_delay_definition']['unconfirmed_no_punch_records'] ?? 0) === 1
        && abs((float)($pendingFullFailureReport['failure_delay_definition']['unconfirmed_no_punch_hours'] ?? 0) - 8.0) < 0.0001,
    'Informe: mantiene visible la jornada sin marcas como pendiente sin alterar el FTE'
);

$paidDayReport = fte_monthly_build_report($calendar, $headcount, $people, [], [
    '19' => ['2026-08-03' => ['time_offs' => [['type_description' => 'Permiso con goce']]]],
]);
$assert(abs((float)($paidDayReport['totals']['loss_components']['day_permission_hours'] ?? -1)) < 0.0001, 'Informe: permiso con goce no reduce FTE');
$assert(abs((float)($paidDayReport['totals']['attendance_components']['day_permission_with_pay_hours'] ?? 0) - 8.0) < 0.0001, 'Informe: permiso con goce conserva sus horas informativas');

$unpaidDayReport = fte_monthly_build_report($calendar, $headcount, $people, [], [
    '19' => ['2026-08-03' => ['time_offs' => [['type_description' => 'Permiso sin goce']]]],
]);
$assert(abs((float)($unpaidDayReport['totals']['loss_components']['day_permission_hours'] ?? 0) - 8.0) < 0.0001, 'Informe: permiso sin goce reduce FTE');
$assert((int)($unpaidDayReport['day_permission_definition']['without_pay_records'] ?? 0) === 1, 'Informe: audita el permiso sin goce');

$pendingDayReport = fte_monthly_build_report($calendar, $headcount, $people, [], [
    '19' => ['2026-08-03' => ['time_offs' => [['type_description' => 'Permiso administrativo']]]],
]);
$assert(abs((float)($pendingDayReport['totals']['loss_components']['day_permission_hours'] ?? -1)) < 0.0001, 'Informe: permiso ambiguo no se descuenta silenciosamente');
$assert((int)($pendingDayReport['day_permission_definition']['pending_records'] ?? 0) === 1, 'Informe: permiso ambiguo queda pendiente con detalle nominal');
$pendingGate = fte_monthly_source_snapshot_permission_gate([
    '19' => ['2026-08-03' => ['time_offs' => [['type_description' => 'Permiso administrativo']]]],
]);
$assert(($pendingGate['can_approve'] ?? true) === false && (int)$pendingGate['pending_day_permission_records'] === 1, 'Aprobacion: un permiso diario ambiguo bloquea el congelamiento');
$resolvedGate = fte_monthly_source_snapshot_permission_gate([
    '19' => ['2026-08-03' => ['time_offs' => [
        ['type_description' => 'Permiso con goce'],
        ['type_description' => 'Permiso sin goce'],
    ]]],
]);
$assert(($resolvedGate['can_approve'] ?? false) === true && (int)$resolvedGate['pending_day_permission_records'] === 0, 'Aprobacion: permisos diarios explicitamente clasificados permiten continuar');

$absenceWithPermission = fte_monthly_build_report($calendar, $headcount, $people, [
    '19' => ['2026-08-03' => ['kind' => 'Licencia medica']],
], [
    '19' => ['2026-08-03' => [
        'time_offs' => [['type_description' => 'Permiso con Goce']],
    ]],
]);
$absenceLosses = $absenceWithPermission['totals']['loss_components'] ?? [];
$assert(abs((float)($absenceLosses['medical_leave_hours'] ?? 0) - 8.0) < 0.0001, 'Conserva la licencia de Buk como ausencia principal');
$assert(abs((float)($absenceLosses['day_permission_hours'] ?? 0)) < 0.0001, 'No duplica una licencia de Buk como permiso GeoVictoria');

$bukLicenceReason = [
    '19' => ['2026-08-03' => [
        'kind' => 'Licencia medica',
        'reason' => 'Enfermedad comun',
        'from' => '2026-08-03',
        'to' => '2026-08-03',
    ]],
];
$reconciledLicence = fte_monthly_build_report(
    $calendar,
    $headcount,
    $people,
    $bukLicenceReason,
    ['19' => ['2026-08-03' => ['time_offs' => [['type_description' => 'Licencia médica']]]]],
    [],
    ['successful_identifiers' => ['19']]
);
$assert(
    abs((float)($reconciledLicence['totals']['loss_components']['medical_leave_hours'] ?? 0) - 8.0) < 0.0001
        && (int)($reconciledLicence['medical_leave_definition']['reconciled_days'] ?? 0) === 1
        && (int)($reconciledLicence['medical_leave_definition']['conflict_count'] ?? -1) === 0,
    'Licencias: Buk calcula la jornada laboral y GeoVictoria permite conciliarla'
);

$bukOnlyLicence = fte_monthly_build_report(
    $calendar,
    $headcount,
    $people,
    $bukLicenceReason,
    ['19' => ['2026-08-03' => []]],
    [],
    ['successful_identifiers' => ['19']]
);
$assert(
    abs((float)($bukOnlyLicence['totals']['loss_components']['medical_leave_hours'] ?? 0) - 8.0) < 0.0001
        && (($bukOnlyLicence['medical_leave_definition']['conflicts'][0]['conflict_type'] ?? '') === 'SOLO_BUK'),
    'Licencias: una licencia solo Buk conserva el descuento y queda identificada'
);

$geoOnlyLicence = fte_monthly_build_report(
    $calendar,
    $headcount,
    $people,
    [],
    ['19' => ['2026-08-03' => ['time_offs' => [['type_description' => 'Licencia médica']]]]],
    [],
    ['successful_identifiers' => ['19']]
);
$assert(
    abs((float)($geoOnlyLicence['totals']['loss_components']['medical_leave_hours'] ?? -1)) < 0.0001
        && (($geoOnlyLicence['medical_leave_definition']['conflicts'][0]['conflict_type'] ?? '') === 'SOLO_GEOVICTORIA'),
    'Licencias: una licencia solo GeoVictoria no se descuenta automaticamente'
);

$calendarWithNonWorkingDay = [
    'period' => '2026-08',
    'theoretical_hours_per_person' => 8.0,
    'workday_count' => 1,
    'days' => [
        ['date' => '2026-08-03', 'theoretical_hours' => 8.0],
        ['date' => '2026-08-08', 'theoretical_hours' => 0.0],
    ],
    'warnings' => [],
];
$headcountWithNonWorkingDay = fte_headcount_build_month($people, 2026, 8, $calendarWithNonWorkingDay);
$nonWorkingLicence = fte_monthly_build_report(
    $calendarWithNonWorkingDay,
    $headcountWithNonWorkingDay,
    $people,
    ['19' => ['2026-08-08' => ['kind' => 'Licencia medica']]],
);
$assert(
    abs((float)($nonWorkingLicence['totals']['loss_components']['medical_leave_hours'] ?? -1)) < 0.0001
        && (int)($nonWorkingLicence['medical_leave_definition']['buk_non_working_days_ignored'] ?? 0) === 1,
    'Licencias: un dia no laborable no descuenta horas'
);

$bukAccidentReason = [
    '19' => ['2026-08-03' => [
        'kind' => 'Accidente',
        'reason' => 'Accidente del trabajo',
        'from' => '2026-08-03',
        'to' => '2026-08-03',
    ]],
];
$reconciledAccident = fte_monthly_build_report(
    $calendar,
    $headcount,
    $people,
    $bukAccidentReason,
    ['19' => ['2026-08-03' => ['time_offs' => [['type_description' => 'Accidente del trabajo']]]]],
    [],
    ['successful_identifiers' => ['19']]
);
$assert(
    abs((float)($reconciledAccident['totals']['loss_components']['accident_hours'] ?? 0) - 8.0) < 0.0001
        && abs((float)($reconciledAccident['totals']['loss_components']['medical_leave_hours'] ?? -1)) < 0.0001
        && (int)($reconciledAccident['accident_definition']['reconciled_days'] ?? 0) === 1
        && (int)($reconciledAccident['accident_definition']['conflict_count'] ?? -1) === 0,
    'Accidentes: Buk descuenta una sola jornada y GeoVictoria permite conciliarla'
);

$overlappingAccidentReason = fte_monthly_merge_absence_indexes(
    ['19' => ['2026-08-03' => ['kind' => 'Licencia medica', 'reason' => 'Licencia medica']]],
    $bukAccidentReason
);
$overlappingAccident = fte_monthly_build_report(
    $calendar,
    $headcount,
    $people,
    $overlappingAccidentReason,
    ['19' => ['2026-08-03' => ['time_offs' => [['type_description' => 'Accidente del trabajo']]]]],
    [],
    ['successful_identifiers' => ['19']]
);
$assert(
    abs((float)($overlappingAccident['totals']['loss_components']['accident_hours'] ?? 0) - 8.0) < 0.0001
        && abs((float)($overlappingAccident['totals']['loss_components']['medical_leave_hours'] ?? -1)) < 0.0001
        && (int)($overlappingAccident['accident_definition']['buk_medical_overlap_days'] ?? 0) === 1
        && (($overlappingAccident['accident_definition']['conflicts'][0]['conflict_type'] ?? '') === 'BUK_LICENCIA_Y_ACCIDENTE_MISMA_FECHA'),
    'Accidentes: un cruce Buk con licencia aplica solo accidente y queda auditado'
);

$bukOnlyAccident = fte_monthly_build_report(
    $calendar,
    $headcount,
    $people,
    $bukAccidentReason,
    ['19' => ['2026-08-03' => []]],
    [],
    ['successful_identifiers' => ['19']]
);
$assert(
    abs((float)($bukOnlyAccident['totals']['loss_components']['accident_hours'] ?? 0) - 8.0) < 0.0001
        && (($bukOnlyAccident['accident_definition']['conflicts'][0]['conflict_type'] ?? '') === 'SOLO_BUK'),
    'Accidentes: un accidente solo Buk conserva el descuento y queda identificado'
);

$geoOnlyAccident = fte_monthly_build_report(
    $calendar,
    $headcount,
    $people,
    [],
    ['19' => ['2026-08-03' => ['time_offs' => [['type_description' => 'Accidente del trabajo']]]]],
    [],
    ['successful_identifiers' => ['19']]
);
$assert(
    abs((float)($geoOnlyAccident['totals']['loss_components']['accident_hours'] ?? -1)) < 0.0001
        && (($geoOnlyAccident['accident_definition']['conflicts'][0]['conflict_type'] ?? '') === 'SOLO_GEOVICTORIA'),
    'Accidentes: un accidente solo GeoVictoria no se descuenta automaticamente'
);

$nonWorkingAccident = fte_monthly_build_report(
    $calendarWithNonWorkingDay,
    $headcountWithNonWorkingDay,
    $people,
    ['19' => ['2026-08-08' => ['kind' => 'Accidente']]],
);
$assert(
    abs((float)($nonWorkingAccident['totals']['loss_components']['accident_hours'] ?? -1)) < 0.0001
        && (int)($nonWorkingAccident['accident_definition']['buk_non_working_days_ignored'] ?? 0) === 1,
    'Accidentes: un dia no laborable no descuenta horas'
);

$quality = fte_monthly_build_data_quality(
    [
        ['identifier' => '1-9', 'normalized_identifier' => '19', 'person_name' => 'Persona Uno', 'cost_center_code' => 'A'],
        ['identifier' => '2-7', 'normalized_identifier' => '27', 'person_name' => 'Persona Dos', 'cost_center_code' => 'B'],
    ],
    ['19', '27'],
    [
        'successful_identifiers' => ['19', '27'],
        'failed_identifiers' => [],
        'unmatched_identifiers' => ['27'],
    ],
    [
        'types' => [
            ['type' => 'Capacitacion especial', 'classification' => 'OTRO', 'records' => 2],
            ['type' => 'Permiso por horas', 'classification' => 'PERMISO_HORA', 'records' => 1],
        ],
    ],
    [
        ['component' => 'Vacaciones', 'status' => 'NO_DISPONIBLE', 'source' => 'Buk'],
        ['component' => 'Permisos', 'status' => 'PARCIAL_REQUIERE_REVISION', 'source' => 'GeoVictoria'],
    ],
    true
);
$assert(($quality['workers_requested'] ?? 0) === 2, 'Calidad: cuenta trabajadores solicitados');
$assert(($quality['workers_matched'] ?? 0) === 1, 'Calidad: separa trabajadores conciliados');
$assert(($quality['workers_not_found_count'] ?? 0) === 1, 'Calidad: informa trabajadores no encontrados');
$assert(($quality['workers_not_found'][0]['person_name'] ?? '') === 'Persona Dos', 'Calidad: identifica al trabajador no encontrado');
$assert(($quality['unclassified_category_count'] ?? 0) === 1 && ($quality['unclassified_record_count'] ?? 0) === 2, 'Calidad: informa categorias sin clasificar');
$assert(count($quality['unavailable_sources'] ?? []) === 1, 'Calidad: informa fuentes que no respondieron');
$assert(($quality['status'] ?? '') === 'FUENTES_NO_DISPONIBLES', 'Calidad: prioriza una fuente no disponible');

$pendingPermissionQuality = fte_monthly_build_data_quality(
    [],
    [],
    ['successful_identifiers' => [], 'failed_identifiers' => [], 'unmatched_identifiers' => []],
    [
        'types' => [
            ['type' => 'Permiso administrativo', 'classification' => 'PERMISO_DIA_PENDIENTE', 'records' => 1],
        ],
    ],
    [],
    true
);
$assert(
    ($pendingPermissionQuality['status'] ?? '') === 'PARCIAL_REQUIERE_REVISION'
        && (int)($pendingPermissionQuality['pending_day_permission_record_count'] ?? 0) === 1,
    'Calidad: un permiso diario pendiente exige revision y conserva su conteo'
);

$availability = fte_monthly_metric_availability(false, true, 'NO_CARGADO', false);
$assert(($availability['vacation_hours'] ?? '') === 'NO_DISPONIBLE', 'Disponibilidad: vacaciones ausentes no se interpretan como cero');
$assert(($availability['authorized_overtime_hours'] ?? '') === 'NO_CARGADO', 'Disponibilidad: GeoVictoria no cargado no se interpreta como cero');
$assert(($availability['medical_leave_hours'] ?? '') === 'DISPONIBLE', 'Disponibilidad: conserva las fuentes que si respondieron');
$assert(($availability['fte'] ?? '') === 'PRELIMINAR_PARCIAL', 'Disponibilidad: marca el FTE derivado como parcial');

$monthlyView = (string)file_get_contents(__DIR__ . '/../rrhh/fte/fte_mensual.php');
$assert(
    str_contains($monthlyView, 'id="warningsPanel"')
        && str_contains($monthlyView, '<details id="warningsPanel"')
        && str_contains($monthlyView, 'warningsPanel.removeAttribute(\'open\')')
        && str_contains($monthlyView, 'Ver avisos'),
    'La vista mensual agrupa los avisos en un panel cerrado por defecto'
);
$assert(str_contains($monthlyView, 'data-attendance="delay"'), 'La vista mensual muestra el atraso separado');
$assert(str_contains($monthlyView, 'data-attendance="delay-raw"'), 'La vista mensual muestra el atraso bruto');
$assert(str_contains($monthlyView, 'data-attendance="delay-compensated"'), 'La vista mensual muestra la compensacion reconocida');
$assert(str_contains($monthlyView, 'data-attendance="early"'), 'La vista mensual muestra la salida anticipada separada');
$assert(str_contains($monthlyView, 'data-attendance="nonworked"'), 'La vista mensual muestra las horas no trabajadas separadas');
$assert(str_contains($monthlyView, 'data-attendance="failure-applied"'), 'La vista mensual muestra la falla o atraso finalmente aplicado');
$assert(str_contains($monthlyView, 'Casos pendientes de fallas y atrasos'), 'La vista mensual muestra los casos nominales pendientes');
$assert(str_contains($monthlyView, 'data-overtime="reported"'), 'La vista mensual muestra horas extra realizadas informadas');
$assert(str_contains($monthlyView, 'data-overtime="applied"'), 'La vista mensual muestra horas extra aplicadas');
$assert(str_contains($monthlyView, 'data-overtime="excluded"'), 'La vista mensual muestra horas extra excluidas por fin de semana');
$assert(str_contains($monthlyView, 'Horas extra excluidas por fin de semana'), 'La vista mensual muestra el detalle nominal de horas extra excluidas');
$assert(str_contains($monthlyView, 'id="conceptTotals"'), 'La vista mensual incorpora el desglose total por concepto');
$assert(str_contains($monthlyView, 'id="conceptByCeco"'), 'La vista mensual incorpora el desglose por CECO');
$assert(str_contains($monthlyView, 'id="conceptDetailDialog"'), 'Cada concepto mensual dispone de una ventana de detalle explicativo');
$assert(str_contains($monthlyView, 'id="conceptDetailBody"'), 'La ventana explicativa incorpora el detalle nominal');
$assert(str_contains($monthlyView, 'concept-detail-trigger'), 'Los totales mensuales y por CECO son consultables');
$assert(str_contains($monthlyView, 'Registro original') && str_contains($monthlyView, 'Regla aplicada') && str_contains($monthlyView, 'Exclusión / advertencia'), 'El detalle muestra fuente, regla y advertencias del calculo');
$assert(str_contains($monthlyView, 'id="excelComparisonBody"'), 'La vista mensual incorpora la comparacion manual con Excel');
$assert(str_contains($monthlyView, "['employment_entry_hours','Ingresos (informativo)','h']"), 'La vista mensual muestra ingresos por separado como informacion');
$assert(str_contains($monthlyView, "['employment_exit_hours','Salidas (informativo)','h']"), 'La vista mensual muestra salidas por separado como informacion');
$assert(str_contains($monthlyView, "['employment_movement_hours','Ingresos / salidas (control)','h']"), 'La comparacion conserva el total combinado de control');
$assert(str_contains($monthlyView, "['effective_theoretical_hours','Horas teóricas proporcionales','h']"), 'La comparacion muestra la jornada teorica proporcional usada por el FTE');
$assert(str_contains($monthlyView, 'data-kpi="effective-theoretical"'), 'La vista mensual distingue horas teoricas brutas y proporcionales');
$assert(str_contains($monthlyView, 'data-quality="attendance-excluded"'), 'La calidad distingue dotacion sin cobertura GeoVictoria');
$assert(str_contains($monthlyView, 'id="employmentMovementsPanel"'), 'La vista mensual incorpora el panel de movimientos laborales');
$assert(str_contains($monthlyView, 'id="employmentEntryBody"'), 'La vista mensual incorpora el detalle independiente de ingresos');
$assert(str_contains($monthlyView, 'id="employmentExitBody"'), 'La vista mensual incorpora el detalle independiente de salidas');
$assert(str_contains($monthlyView, 'renderEmploymentMovements(data)'), 'La vista mensual renderiza los movimientos separados');
$assert(str_contains($monthlyView, 'fte_monthly_excel_references_v1'), 'La referencia Excel se conserva solamente en el navegador');
$assert(str_contains($monthlyView, 'id="qualityDetails"'), 'La vista mensual muestra la calidad de la informacion');
$assert(str_contains($monthlyView, 'Conflictos de vacaciones Buk–GeoVictoria'), 'La vista mensual muestra los conflictos nominales de vacaciones');
$assert(str_contains($monthlyView, 'Conflictos de licencias médicas Buk–GeoVictoria'), 'La vista mensual muestra los conflictos nominales de licencias medicas');
$assert(str_contains($monthlyView, 'Conflictos de accidentes Buk–GeoVictoria'), 'La vista mensual muestra los conflictos nominales de accidentes');
$assert(str_contains($monthlyView, 'Permisos por día pendientes de clasificación'), 'La vista mensual muestra el detalle nominal de permisos diarios pendientes');
$assert(str_contains($monthlyView, 'sin clasificar como con o sin goce'), 'La vista mensual explica por que un borrador no puede aprobarse');
$assert(str_contains($monthlyView, "'No disponible'"), 'La vista mensual distingue una fuente ausente de un valor cero');
$assert(str_contains($monthlyView, 'para contrastar un CECO'), 'La vista explica la validacion por concepto y por CECO');
$assert(str_contains($monthlyView, 'Preliminar · no oficial'), 'La vista identifica el resultado como preliminar y no oficial');
$assert(str_contains($monthlyView, 'data-headcount-definition="fte"'), 'La vista distingue la dotacion usada en FTE');
$assert(str_contains($monthlyView, 'data-headcount-definition="monthly"'), 'La vista muestra personas unicas del mes');
$assert(str_contains($monthlyView, 'data-headcount-definition="end"'), 'La vista muestra dotacion al cierre');
$assert(str_contains($monthlyView, 'data-headcount-definition="average"'), 'La vista muestra dotacion promedio laboral');
$monthlyReportSource = (string)file_get_contents(__DIR__ . '/../rrhh/fte/fte_monthly_report.php');
$assert(str_contains($monthlyReportSource, "'result_status' = 'PRELIMINAR_NO_OFICIAL'") || str_contains($monthlyReportSource, "['result_status'] = 'PRELIMINAR_NO_OFICIAL'"), 'El backend marca el resultado mensual como preliminar no oficial');
$assert(str_contains($monthlyReportSource, "'reference_values_affect_calculation' => false"), 'La referencia Excel no forma parte del calculo productivo');
$assert(str_contains($monthlyReportSource, "'calculation_precision_is_not_rounded' => true"), 'La definicion de dotacion conserva precision sin redondeo intermedio');
$assert(str_contains($monthlyReportSource, "['component' => 'Ingresos'"), 'La cobertura informa ingresos como componente independiente');
$assert(str_contains($monthlyReportSource, "['component' => 'Salidas'"), 'La cobertura informa salidas como componente independiente');
$assert(str_contains($monthlyReportSource, "'combined_total_is_reconciliation_only' => true"), 'El backend reserva el total combinado para conciliacion');
$assert(str_contains($monthlyReportSource, "'partial_day_rule'"), 'El backend declara la regla auditable de vacaciones parciales');
$assert(str_contains($monthlyReportSource, "'vacation_conflicts'"), 'El backend expone conflictos de vacaciones en calidad de informacion');
$assert(str_contains($monthlyReportSource, "'medical_leave_conflicts'"), 'El backend expone conflictos de licencias medicas en calidad de informacion');
$assert(str_contains($monthlyReportSource, "'accident_conflicts'"), 'El backend expone conflictos de accidentes en calidad de informacion');
$assert(str_contains($monthlyReportSource, "'overlap_rule'"), 'El backend declara la regla que impide duplicar licencia y accidente');
$assert(str_contains($monthlyReportSource, "'non_working_day_rule'"), 'El backend declara que las licencias solo descuentan dias laborables');
$assert(str_contains($monthlyReportSource, "'day_permission_pending'"), 'El backend expone permisos diarios pendientes en calidad de informacion');
$assert(str_contains($monthlyReportSource, "'overtime_definition'"), 'El backend expone la regla auditable de horas extra');
$assert(str_contains($monthlyReportSource, "'hour_permission_definition'"), 'El backend expone el detalle auditable de permisos por hora');
$assert(str_contains($monthlyReportSource, "'overtime_excluded_weekend_events'"), 'El backend expone el detalle nominal excluido por fin de semana');
$snapshotSource = (string)file_get_contents(__DIR__ . '/../rrhh/fte/fte_monthly_source_snapshot.php');
$assert(str_contains($snapshotSource, "'pending_day_permission_records'"), 'La fotografia mensual conserva el resultado del control de permisos pendientes');
$assert(str_contains($snapshotSource, 'Clasificalos antes de aprobar el mes'), 'La aprobacion del servidor bloquea permisos diarios ambiguos');
$assert(str_contains($snapshotSource, 'fte_monthly_source_snapshot_preclose_validation'), 'La fotografia mensual ejecuta una validacion integral antes del cierre');
$assert(str_contains($snapshotSource, "'worker_reconciliation'"), 'La validacion previa controla trabajadores no conciliados');
$assert(str_contains($snapshotSource, "'identity_integrity'"), 'La validacion previa controla RUT e identificadores inconsistentes');
$assert(str_contains($snapshotSource, "'source_conflicts'"), 'La validacion previa controla conflictos entre Buk y GeoVictoria');
$assert(str_contains($snapshotSource, "'duplicate_control'"), 'La validacion previa controla registros duplicados');
$assert(str_contains($snapshotSource, "'ceco_changes'"), 'La validacion previa controla cambios de CECO fuera de regla');
$assert(str_contains($snapshotSource, "'nominal_reconciliation'"), 'La validacion previa exige conciliacion del detalle nominal');
$assert(str_contains($snapshotSource, 'La validacion previa impide aprobar el mes'), 'El servidor impide aprobar una fotografia con controles pendientes');
$assert(str_contains($monthlyView, 'id="precloseValidationStatus"') && str_contains($monthlyView, 'id="precloseValidationList"'), 'La vista muestra el resultado de la validacion previa al cierre');
$assert(str_contains($monthlyView, 'renderPrecloseValidation'), 'La vista representa cada control previo con estado y detalle');
$assert(
    str_contains($monthlyView, 'function metricPending')
        && str_contains($monthlyView, 'hidePendingPresentation'),
    'La vista oculta la palabra solicitada sin eliminar el estado interno de revision'
);
$assert(!str_contains($monthlyView, 'new AbortController'), 'La vista mensual no interrumpe la consulta a los 90 segundos');
$assert(str_contains($monthlyView, 'Tiempo real de la última consulta'), 'La vista mensual muestra el tiempo real transcurrido');
$monthlyApi = (string)file_get_contents(__DIR__ . '/../rrhh/fte/fte_api.php');
$assert(str_contains($monthlyApi, "'_attendance_total_deadline_disabled'"), 'La API mensual desactiva el limite total del procesamiento');

$oneSecondInHours = 1 / 3600;
$assert(
    abs(fte_duration_values_hours(['00:00:01', '00:00:01']) - (2 / 3600)) < 0.000000000001,
    'Precision: acumula segundos de GeoVictoria sin redondear cada duracion'
);
$assert(
    abs(fte_monthly_time_off_duration_hours([
        'start_time' => '09:00:00',
        'end_time' => '09:00:01',
    ]) - $oneSecondInHours) < 0.000000000001,
    'Precision: conserva segundos en permisos por hora'
);
$precisionAttendance = [];
fte_merge_attendance_payload($precisionAttendance, [
    'Users' => [[
        'Identifier' => '2-7',
        'PlannedInterval' => [[
            'Date' => '20260803000000',
            'WorkedHours' => '00:00:01',
            'TotalAuthorizedOvertime' => '00:00:01',
            'AccomplishedExtraTime' => ['00:00:01'],
            'DelayTimeAfterCompensation' => '00:00:01',
            'EarlyLeaveTimeAfterCompensation' => '00:00:01',
            'NonWorkedHours' => '00:00:01',
        ]],
    ]],
]);
foreach ([
    'geovictoria_worked_hours',
    'authorized_overtime_hours',
    'accomplished_overtime_hours',
    'delay_hours',
    'early_leave_hours',
    'non_worked_hours',
] as $precisionField) {
    $assert(
        abs((float)($precisionAttendance['27']['2026-08-03'][$precisionField] ?? 0) - $oneSecondInHours) < 0.000000000001,
        'Precision GeoVictoria: conserva segundos en ' . $precisionField
    );
}
echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed === 0 ? 0 : 1);
