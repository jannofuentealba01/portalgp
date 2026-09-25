<?php
declare(strict_types=1);

require __DIR__ . '/../db.php';
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

$userId = (int)$conn->query('SELECT TOP (1) id FROM dbo.cr_usuarios ORDER BY id')->fetchColumn();
if ($userId <= 0) {
    throw new RuntimeException('No existe un usuario para probar la auditoria del snapshot.');
}

$calendar = fte_calendar_calculate_month(2099, 11);
$people = [[
    'identifier' => '1-9',
    'normalized_identifier' => '19',
    'person_name' => 'Prueba transaccional FTE',
    'buk_employee_id' => 'fte-test-1',
    'active' => true,
    'active_since' => '2099-01-01',
    'active_until' => null,
    'jobs' => [[
        'job_id' => 999001,
        'start_date' => '2099-01-01',
        'end_date' => null,
        'cost_center_code' => 'FTE-TEST',
        'cost_center_name' => 'Prueba FTE',
    ]],
]];

$conn->beginTransaction();
try {
    $first = fte_headcount_snapshot_save(
        $conn,
        fte_headcount_snapshot_build($people, 2099, 11, $calendar),
        $userId
    );
    $assert(($first['created'] ?? false) === true, 'crea la primera version en borrador');
    $assert(($first['version'] ?? 0) === 1, 'la primera version es v1');
    $assert(($first['status'] ?? '') === 'BORRADOR', 'el snapshot queda en borrador');
    $assert(($first['detail_rows'] ?? 0) === 1, 'guarda el detalle trabajador-CECO');
    $detail = fte_headcount_snapshot_details($conn, '2099-11');
    $assert(count($detail['rows'] ?? []) === 1, 'consulta el detalle persistido');
    $assert(($detail['rows'][0]['person_name'] ?? '') === 'Prueba transaccional FTE', 'el detalle conserva el nombre historico');
    $assert(($detail['rows'][0]['cost_center_code'] ?? '') === 'FTE-TEST', 'el detalle conserva el CECO historico');

    $same = fte_headcount_snapshot_save(
        $conn,
        fte_headcount_snapshot_build($people, 2099, 11, $calendar),
        $userId
    );
    $assert(($same['unchanged'] ?? false) === true, 'un guardado idéntico es idempotente');
    $assert(($same['version'] ?? 0) === 1, 'un guardado idéntico no crea otra version');

    $people[0]['person_name'] = 'Prueba FTE corregida';
    $changed = fte_headcount_snapshot_save(
        $conn,
        fte_headcount_snapshot_build($people, 2099, 11, $calendar),
        $userId
    );
    $assert(($changed['created'] ?? false) === true, 'una correccion crea una nueva version');
    $assert(($changed['version'] ?? 0) === 2, 'la correccion queda como v2');

    $wrongControlRejected = false;
    try {
        fte_headcount_snapshot_approve($conn, '2099-11', $userId, 2, 'Control incorrecto');
    } catch (DomainException $exception) {
        $wrongControlRejected = str_contains($exception->getMessage(), 'no coincide');
    }
    $assert($wrongControlRejected, 'rechaza una dotacion de control distinta al borrador');

    $approved = fte_headcount_snapshot_approve($conn, '2099-11', $userId, 1, 'Conciliado por prueba automatica');
    $assert(($approved['approved'] ?? false) === true, 'aprueba la version vigente');
    $assert(($approved['status'] ?? '') === 'APROBADO', 'la version queda en estado APROBADO');
    $assert(($approved['is_official'] ?? false) === true, 'el estado identifica la fuente oficial');
    $assert(!empty($approved['approved_at']), 'registra la fecha de aprobacion');
    $assert(!empty($approved['approved_by']), 'registra el usuario aprobador');

    $official = fte_headcount_snapshot_load_approved($conn, '2099-11');
    $assert($official !== null && count($official['rows'] ?? []) === 1, 'carga el detalle de la version aprobada');
    $officialInputs = fte_headcount_snapshot_report_inputs($official ?? [], $calendar);
    $assert(($officialInputs['headcount']['unique_people_active_any_time'] ?? 0) === 1, 'reconstruye la dotacion oficial desde la base');

    $replacementRejected = false;
    try {
        $people[0]['person_name'] = 'Cambio posterior prohibido';
        fte_headcount_snapshot_save(
            $conn,
            fte_headcount_snapshot_build($people, 2099, 11, $calendar),
            $userId
        );
    } catch (DomainException $exception) {
        $replacementRejected = str_contains($exception->getMessage(), 'aprobada');
    }
    $assert($replacementRejected, 'impide reemplazar una fotografia aprobada');

    $historyStmt = $conn->query(
        "SELECT version_snapshot, estado_snapshot, es_vigente
         FROM dbo.fte_dotacion_snapshots
         WHERE periodo = '2099-11-01'
         ORDER BY version_snapshot"
    );
    $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($history) === 2, 'conserva ambas versiones dentro de la transaccion');
    $assert(($history[0]['estado_snapshot'] ?? '') === 'REEMPLAZADO' && (int)($history[0]['es_vigente'] ?? 1) === 0, 'la v1 queda reemplazada');
    $assert(($history[1]['estado_snapshot'] ?? '') === 'APROBADO' && (int)($history[1]['es_vigente'] ?? 0) === 1, 'solo la v2 aprobada queda vigente');

    $databaseImmutabilityRejected = false;
    try {
        $conn->exec(
            "UPDATE dbo.fte_dotacion_snapshots
                SET fuente = N'ALTERACION_NO_PERMITIDA'
              WHERE periodo = '2099-11-01' AND estado_snapshot = N'APROBADO'"
        );
    } catch (PDOException $exception) {
        $databaseImmutabilityRejected = str_contains($exception->getMessage(), 'inmutable');
    }
    $assert($databaseImmutabilityRejected, 'el trigger SQL rechaza cambios directos sobre la fotografia aprobada');
} finally {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
}

$remainingStmt = $conn->query("SELECT COUNT(*) FROM dbo.fte_dotacion_snapshots WHERE periodo = '2099-11-01'");
$assert((int)$remainingStmt->fetchColumn() === 0, 'la prueba revierte todos sus datos');

echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed === 0 ? 0 : 1);
