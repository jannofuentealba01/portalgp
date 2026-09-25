<?php
declare(strict_types=1);

require __DIR__ . '/../rrhh/fte/fte_lib.php';

$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('Fallo: ' . $message);
    }
    $passed++;
    echo "[OK] {$message}\n";
};

$assert(
    fte_identity_alias('RUT', '12.345.678-5') === 'RUT:123456785',
    'el RUT se normaliza y conserva su tipo'
);
$assert(
    fte_identity_alias('BUK_EMPLOYEE', ' 123 ') === 'BUK_EMPLOYEE:123',
    'el ID Buk queda separado del espacio de RUT'
);

$people = [
    [
        'identifier' => '12.345.678-5',
        'normalized_identifier' => '123456785',
        'person_name' => 'Persona Unificada',
        'buk_employee_id' => '101',
        'active' => false,
        'active_since' => '2025-01-01',
        'active_until' => '2025-12-31',
        'jobs' => [['job_id' => 1, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']],
    ],
    [
        'identifier' => '12.345.678-5',
        'normalized_identifier' => '123456785',
        'person_name' => 'Persona Unificada',
        'buk_employee_id' => '202',
        'active' => true,
        'active_since' => '2026-01-01',
        'jobs' => [['job_id' => 2, 'start_date' => '2026-01-01', 'end_date' => null]],
    ],
    [
        'identifier' => '11.111.111-1',
        'normalized_identifier' => '111111111',
        'person_name' => 'Nombre Repetido',
        'buk_employee_id' => '999',
        'active' => true,
        'jobs' => [],
    ],
    [
        'identifier' => '22.222.222-2',
        'normalized_identifier' => '222222222',
        'person_name' => 'Nombre Repetido',
        'buk_employee_id' => '999',
        'active' => true,
        'jobs' => [],
    ],
    [
        'identifier' => '15.615.841-0',
        'normalized_identifier' => '156158410',
        'person_name' => 'Cesar Esteban Villarroel Valencia',
        'buk_employee_id' => '303',
        'active' => true,
        'jobs' => [],
    ],
    [
        'identifier' => '',
        'person_name' => 'Sin identificador',
        'buk_employee_id' => '404',
        'active' => true,
        'jobs' => [],
    ],
];
$config = [
    'identity_exclusions' => [[
        'identifier' => '15.615.841-0',
        'name' => 'Cesar Esteban Villarroel Valencia',
        'reason' => 'Exclusion autorizada',
    ]],
];
$diagnostics = [];
$unified = fte_identity_unify_people($people, $config, $diagnostics);
$byRut = [];
foreach ($unified as $person) {
    $byRut[$person['normalized_identifier']] = $person;
}

$assert(count($unified) === 3, 'unifica duplicados y retira solo la exclusion autorizada');
$assert(count($byRut['123456785']['jobs'] ?? []) === 2, 'conserva todos los historiales laborales del mismo RUT');
$assert(
    count($byRut['123456785']['employment_periods'] ?? []) === 2
        && ($byRut['123456785']['employment_periods'][0]['start_date'] ?? '') === '2025-01-01'
        && ($byRut['123456785']['employment_periods'][1]['start_date'] ?? '') === '2026-01-01',
    'conserva por separado los periodos laborales de una recontratacion'
);
$assert(
    ($byRut['123456785']['buk_employee_ids'] ?? []) === ['101', '202'],
    'conserva todos los ID historicos de Buk'
);
$assert(
    in_array('RUT:123456785', $byRut['123456785']['identity_aliases'] ?? [], true)
        && in_array('BUK_EMPLOYEE:101', $byRut['123456785']['identity_aliases'] ?? [], true)
        && in_array('BUK_EMPLOYEE:202', $byRut['123456785']['identity_aliases'] ?? [], true),
    'la persona conciliada usa RUT e IDs Buk tipados'
);
$assert(
    !in_array('BUK_EMPLOYEE:999', $byRut['111111111']['identity_aliases'] ?? [], true)
        && !in_array('BUK_EMPLOYEE:999', $byRut['222222222']['identity_aliases'] ?? [], true),
    'un ID Buk compartido por dos RUT no se usa para cruzar datos'
);
$assert(
    count($diagnostics['ambiguous_aliases'] ?? []) === 1
        && ($diagnostics['duplicate_records_merged'] ?? 0) === 1
        && ($diagnostics['missing_identifier_records'] ?? 0) === 1,
    'informa aliases ambiguos, duplicados fusionados y filas sin RUT'
);
$assert(
    count($diagnostics['excluded_workers'] ?? []) === 1
        && ($diagnostics['excluded_workers'][0]['normalized_identifier'] ?? '') === '156158410'
        && ($diagnostics['excluded_workers'][0]['reason'] ?? '') === 'Exclusion autorizada',
    'la exclusion queda registrada con trabajador y motivo'
);
$assert(
    isset($byRut['111111111'], $byRut['222222222']),
    'el nombre nunca fusiona trabajadores con RUT distintos'
);

$absenceAliases = fte_absence_employee_keys([
    'employee_id' => '202',
    'employee' => ['rut' => '12.345.678-5'],
]);
$assert(
    $absenceAliases === ['BUK_EMPLOYEE:202', 'RUT:123456785'],
    'las ausencias se indexan solamente con identificadores tipados'
);
$assert(
    count(array_intersect($absenceAliases, $byRut['123456785']['identity_aliases'])) === 2,
    'una ausencia por RUT o por ID Buk converge en la misma persona'
);

$qualityWithExclusion = fte_monthly_build_data_quality(
    $unified,
    [],
    ['identity' => $diagnostics],
    ['types' => []],
    [],
    true
);
$assert(
    ($qualityWithExclusion['workers_excluded_count'] ?? 0) === 1
        && ($qualityWithExclusion['status'] ?? '') === 'PARCIAL_REQUIERE_REVISION',
    'la exclusion es visible y las incidencias reales de identidad mantienen la revision pendiente'
);
$cleanIdentityDiagnostics = $diagnostics;
$cleanIdentityDiagnostics['ambiguous_aliases'] = [];
$cleanIdentityDiagnostics['missing_identifier_records'] = 0;
$qualityOnlyAuthorizedExclusion = fte_monthly_build_data_quality(
    $unified,
    [],
    ['identity' => $cleanIdentityDiagnostics],
    ['types' => []],
    [],
    true
);
$assert(
    ($qualityOnlyAuthorizedExclusion['workers_excluded_count'] ?? 0) === 1
        && ($qualityOnlyAuthorizedExclusion['status'] ?? '') === 'COMPLETA_PRELIMINAR',
    'una exclusion autorizada se informa sin convertirla en error de calidad'
);

$defaultConfig = fte_load_config();
$defaultExclusions = fte_identity_exclusion_map($defaultConfig);
$assert(count($defaultExclusions) === 1, 'la politica excluye de dotacion solo a la persona sin CECO confirmado');
$assert(isset($defaultExclusions['89937760']), 'Carlos queda fuera de dotacion hasta regularizar su CECO');
$attendanceExclusions = fte_attendance_exclusion_map($defaultConfig);
$assert(count($attendanceExclusions) === 5, 'la politica conserva cinco trabajadores en dotacion con cobertura GeoVictoria pendiente');
foreach (['156158410', '155927852', '108149892', '123045602', '271366264'] as $identifier) {
    $assert(isset($attendanceExclusions[$identifier]), 'cobertura GeoVictoria pendiente para ' . $identifier);
}

echo "Pruebas aprobadas: {$passed}\n";
