<?php
declare(strict_types=1);

require __DIR__ . '/../rrhh/fte/fte_lib.php';
require __DIR__ . '/../db.php';

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[OK] {$label}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$label}\n";
};

$fteDir = realpath(__DIR__ . '/../rrhh/fte');
if ($fteDir === false) {
    throw new RuntimeException('No se encontro rrhh/fte.');
}

$activeFiles = [
    'fte_api.php',
    'fte_ausencias.php',
    'fte_config.php',
    'fte_dashboard.php',
    'fte_grafico_asistencia.php',
    'fte_identity.php',
    'fte_lib.php',
    'fte_mensual.php',
    'fte_monthly_report.php',
    'fte_ui.js',
];
$sources = [];
foreach ($activeFiles as $file) {
    $content = file_get_contents($fteDir . DIRECTORY_SEPARATOR . $file);
    $sources[$file] = is_string($content) ? $content : '';
}
$allSources = implode("\n", $sources);

$check(!str_contains($allSources, 'cr_pho_trabajadores'), 'ninguna ruta FTE activa depende de cr_pho_trabajadores');
$check(!str_contains($allSources, 'fte_fetch_local_active_worker_map'), 'se retiro el filtro de trabajadores locales');
$check(!str_contains($allSources, 'fte_fetch_local_cost_centers'), 'se retiro la obtencion local de centros de costo');
$check(str_contains($sources['fte_api.php'], 'fte_build_buk_cost_centers($people)'), 'la API construye centros de costo desde personas Buk');
$check(str_contains($sources['fte_lib.php'], '/api/v1/{$country}/employees'), 'Buk usa primero el endpoint documentado de empleados');
$check(!str_contains($sources['fte_lib.php'], '/people'), 'no hay fallback Buk sin evidencia previa');
$check(str_contains($sources['fte_lib.php'], "'auth_token: ' . \$token"), 'Buk usa solamente el encabezado de autenticacion documentado');
$check(!str_contains($sources['fte_lib.php'], 'X-Api-Key:'), 'no se envia X-Api-Key a Buk');
$check(!str_contains($sources['fte_lib.php'], "'Authorization: Bearer ' . \$token,\n        'auth_token:"), 'no se envian tres esquemas de autenticacion simultaneos a Buk');
$check(str_contains($sources['fte_lib.php'], 'CURLOPT_SSL_VERIFYPEER => true'), 'cURL valida el certificado TLS');
$check(str_contains($sources['fte_lib.php'], 'CURLOPT_SSL_VERIFYHOST => 2'), 'cURL valida el host TLS');
$check(!str_contains($sources['fte_lib.php'], '$timeout, false'), 'no existe reintento con TLS deshabilitado');
$check(!str_contains($allSources, 'innerHTML'), 'los datos externos se renderizan sin innerHTML');
$sourcesWithoutMonthly = $sources;
unset($sourcesWithoutMonthly['fte_mensual.php']);
$monthlyLocalStorageIsReferenceOnly = !str_contains(implode("\n", $sourcesWithoutMonthly), 'localStorage')
    && str_contains($sources['fte_mensual.php'], "const referenceStorageKey='fte_monthly_excel_references_v1'")
    && !str_contains($sources['fte_mensual.php'], 'localStorage.setItem(\'fte_attendance')
    && !str_contains($sources['fte_mensual.php'], 'localStorage.setItem(\'fte_people');
$check($monthlyLocalStorageIsReferenceOnly, 'localStorage conserva solo referencias manuales del Excel y no datos laborales');
$check(!str_contains($sources['fte_dashboard.php'], 'target="_blank"'), 'las vistas secundarias conservan el sessionStorage en la misma pestaña');
$check(!str_contains($allSources, 'cdn.jsdelivr.net'), 'los assets FTE se sirven localmente');
$check(!str_contains($allSources, 'styles_portal.css'), 'no se solicita el asset inexistente styles_portal.css');
$check(!str_contains($allSources, '../menu.php?section=personal'), 'la navegacion no apunta al menu RRHH inexistente');

$configSource = $sources['fte_config.php'];
$check(str_contains($configSource, "'permission' => 'Ver Dashboard FTE'"), 'el permiso predeterminado es Ver Dashboard FTE');
$check(str_contains($configSource, "getenv('BUK_TOKEN') ?: ''"), 'el codigo no contiene un token Buk de respaldo');
$check(str_contains($sources['fte_api.php'], "'reference' => \$reference"), 'los errores publicos usan una referencia controlada');
$check(!str_contains($sources['fte_api.php'], "'error' => \$e->getMessage()"), 'la API no expone excepciones internas');

$people = [
    ['normalized_identifier' => '111111111', 'cost_center_code' => ' ceco 2 ', 'cost_center_name' => 'Operaciones'],
    ['normalized_identifier' => '11.111.111-1', 'cost_center_code' => 'CECO 2', 'cost_center_name' => ''],
    ['normalized_identifier' => '222222222', 'cost_center_code' => 'CECO 1', 'cost_center_name' => 'Administracion'],
    ['normalized_identifier' => '333333333', 'cost_center_code' => '', 'cost_center_name' => 'Sin centro'],
];
$centers = fte_build_buk_cost_centers($people);
$check(count($centers) === 2, 'el constructor ignora personas sin CECO y agrupa codigos repetidos');
$check(($centers[0]['cost_center_code'] ?? '') === 'CECO 1' && ($centers[1]['cost_center_code'] ?? '') === 'CECO 2', 'los centros de costo tienen orden estable');
$check((int)($centers[1]['people_count'] ?? 0) === 1, 'el conteo de personas es unico por RUT normalizado');
$check(fte_normalize_identifier('12.345.678-k') === '12345678K', 'la identidad se normaliza por RUT y conserva K');
$inactive = fte_normalize_buk_person([
    'rut' => '12.345.678-9',
    'full_name' => 'Persona de prueba',
    'status' => 'inactivo',
    'current_job' => ['cost_center' => 'CECO 1'],
]);
$check(is_array($inactive) && $inactive['active'] === false, 'el estado inactivo real de Buk queda excluible');
$check(
    is_array($inactive)
        && ($inactive['employment_dates_verified'] ?? false) === true
        && ($inactive['employment_start_source'] ?? '') === 'Buk employee.active_since'
        && ($inactive['employment_end_source'] ?? '') === 'Buk employee.active_until',
    'las fechas laborales quedan identificadas como campos reales del trabajador Buk'
);
$check(
    is_array($inactive) && ($inactive['active_since'] ?? null) === null,
    'la fecha laboral nunca se infiere desde current_job'
);
$check(abs(fte_duration_hours('08:30:00') - 8.5) < 0.0001, 'las duraciones GeoVictoria HH:mm:ss se convierten a horas decimales');
$attendanceFixture = [];
fte_merge_attendance_payload($attendanceFixture, [
    'Users' => [[
        'Identifier' => '12.345.678-9',
        'PlannedInterval' => [[
            'Date' => '20260907000000',
            'WorkedHours' => '08:30:00',
            'TotalAuthorizedOvertime' => '00:30:00',
            'DelayTimeAfterCompensation' => '00:05:00',
            'EarlyLeaveTimeAfterCompensation' => '00:00:00',
            'NonWorkedHours' => '00:05:00',
            'Punches' => [
                ['Date' => '20260907080000', 'ShiftPunchType' => 'Entrada'],
                ['Date' => '20260907170000', 'ShiftPunchType' => 'Salida'],
            ],
        ]],
    ]],
]);
$fixtureDay = $attendanceFixture['123456789']['2026-09-07'] ?? [];
$check(($fixtureDay['complete'] ?? false) === true && (int)($fixtureDay['punch_count'] ?? 0) === 2, 'el parser distingue una jornada con entrada y salida completas');
$check(abs((float)($fixtureDay['authorized_overtime_hours'] ?? 0) - 0.5) < 0.0001, 'el parser conserva horas extra autorizadas de GeoVictoria');
$incompleteFixture = [];
fte_merge_attendance_payload($incompleteFixture, [
    'Users' => [[
        'Identifier' => '12.345.678-9',
        'PlannedInterval' => [[
            'Date' => '20260907000000',
            'Punches' => [['Date' => '20260907080000', 'ShiftPunchType' => 'Entrada']],
        ]],
    ]],
]);
$check(($incompleteFixture['123456789']['2026-09-07']['complete'] ?? true) === false, 'el parser detecta marcacion incompleta');

$admin = $conn->query(
    "SELECT TOP(1) u.id,u.estado_id,r.nombre_rol,rp.lectura,rp.escritura,rp.eliminacion
     FROM dbo.cr_usuarios u
     INNER JOIN dbo.cr_roles r ON r.id=u.rol_id
     INNER JOIN dbo.cr_rol_permisos rp ON rp.rol_id=u.rol_id
     INNER JOIN dbo.cr_permisos p ON p.id=rp.permiso_id
     WHERE u.UserName=N'admin_2' AND p.nombre_permiso=N'Ver Dashboard FTE'"
)->fetch(PDO::FETCH_ASSOC);
$check(is_array($admin) && (int)$admin['id'] === 1030 && (int)$admin['estado_id'] === 1 && $admin['nombre_rol'] === 'Administrador', 'admin_2 sigue habilitado y Administrador');
$check(is_array($admin) && (int)$admin['lectura'] === 1 && (int)$admin['escritura'] === 1 && (int)$admin['eliminacion'] === 1, 'admin_2 conserva acceso completo a Ver Dashboard FTE');

$config = fte_load_config();
$check(trim((string)($config['geovictoria_user'] ?? '')) !== '' && trim((string)($config['geovictoria_password'] ?? '')) !== '', 'GeoVictoria tiene configuracion local presente, sin mostrarla');
if (trim((string)($config['buk_token'] ?? '')) === '') {
    echo "[PENDIENTE EXTERNO] Falta instalar un token Buk vigente antes de la primera conexion.\n";
} else {
    echo "[LISTO] Hay una credencial Buk configurada; no fue utilizada por esta prueba.\n";
}

echo "Resultado: {$passed} OK, {$failed} fallidas. No se realizaron llamadas a Buk ni GeoVictoria.\n";
exit($failed === 0 ? 0 : 1);
