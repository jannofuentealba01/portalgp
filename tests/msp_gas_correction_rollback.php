<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/msp/bootstrap.php';
require_once dirname(__DIR__) . '/msp/services/CorreccionesService.php';

/**
 * Prueba integral reversible de la corrección controlada de gas.
 * Toda escritura se ejecuta dentro de una transacción que siempre termina en rollback.
 */

function gasTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$testDatabase = trim((string) getenv('MSP_TEST_DB'));
$allowCurrentDatabase = getenv('MSP_ALLOW_CURRENT_DB_ROLLBACK_TEST') === '1';
gasTestAssert(
    $testDatabase !== ''
        && ($allowCurrentDatabase || strtoupper($testDatabase) !== 'PORTALGP')
        && preg_match('/^[A-Za-z0-9_]+$/', $testDatabase) === 1,
    'La prueba requiere una base aislada o MSP_ALLOW_CURRENT_DB_ROLLBACK_TEST=1 para un rollback explícito.'
);
$dbConfig = require dirname(__DIR__) . '/config/database.php';
$testServer = (string) ($dbConfig['server'] ?? 'localhost');
$testUser = (string) ($dbConfig['username'] ?? '');
$testPassword = (string) ($dbConfig['password'] ?? '');
$testDsn = 'sqlsrv:Server=' . $testServer . ';Database=' . $testDatabase . ';Encrypt=0';
$conn = $testUser === ''
    ? new PDO($testDsn)
    : new PDO($testDsn, $testUser, $testPassword);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$connectedDatabase = (string) $conn->query('SELECT DB_NAME()')->fetchColumn();
gasTestAssert(
    strtoupper($connectedDatabase) === strtoupper($testDatabase),
    'No fue posible confirmar la conexión a la base aislada.'
);

$readingId = isset($argv[1]) ? (int) $argv[1] : 1281;
gasTestAssert($readingId > 0, 'Debes indicar un ID de lectura válido.');

$snapshotSql =
    "SELECT TOP(1) lm.id_lectura,lm.id_medidor,lm.lectura_anterior,lm.lectura_actual,
        lm.consumo_informado,m.id_local,cl.id_contrato_arriendo,ca.id_tienda,
        cs.id_cobro_servicio,cs.monto_total monto_cobro,
        dc.id_documento_cobro,dc.monto_total monto_documento,dc.saldo_pendiente,
        (SELECT COUNT(*) FROM dbo.msp_acc_asientos a
         WHERE a.tabla_origen=N'msp_documentos_cobro'
           AND a.id_origen=dc.id_documento_cobro AND a.estado_asiento=1) asientos_activos
     FROM dbo.msp_lecturas_medidores lm
     INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
     INNER JOIN dbo.msp_contrato_locales cl ON cl.id_local=m.id_local
     INNER JOIN dbo.msp_contratos_arriendo ca ON ca.id_contrato_arriendo=cl.id_contrato_arriendo
     LEFT JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
     LEFT JOIN dbo.msp_documentos_cobro_detalle dd ON dd.id_cobro_servicio=cs.id_cobro_servicio
     LEFT JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=dd.id_documento_cobro
     WHERE lm.id_lectura=:lectura
     ORDER BY dc.id_documento_cobro DESC,cl.id_contrato_arriendo DESC";

$snapshotStmt = $conn->prepare($snapshotSql);
$snapshotStmt->execute([':lectura' => $readingId]);
$before = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
gasTestAssert(is_array($before), 'No se encontró la lectura de prueba.');
gasTestAssert((int) ($before['id_documento_cobro'] ?? 0) > 0, 'La prueba requiere una lectura documentada.');
gasTestAssert((int) ($before['id_cobro_servicio'] ?? 0) > 0, 'La prueba requiere un cobro de gas calculado.');
gasTestAssert((int) ($before['asientos_activos'] ?? 0) > 0, 'La prueba requiere un asiento contable activo.');

$previous = (float) $before['lectura_anterior'];
$current = (float) $before['lectura_actual'];
$newReading = round($current - 1, 4);
gasTestAssert($newReading >= $previous, 'La lectura no tiene margen suficiente para una prueba reversible.');

$userStmt = $conn->query("SELECT TOP(1) id FROM dbo.cr_usuarios WHERE UserName=N'admin_2'");
$userId = (int) ($userStmt->fetchColumn() ?: 0);
gasTestAssert($userId > 0, 'No se encontró el usuario admin_2 para atribuir la prueba.');

$conn->beginTransaction();
try {
    $correctionId = CorreccionesService::crearSolicitud($conn, [
        'tipo_correccion' => 'LECTURA',
        'modulo_origen' => 'tests/msp_gas_correction_rollback.php',
        'periodo_facturacion' => '2026-04',
        'id_contrato_arriendo' => (int) $before['id_contrato_arriendo'],
        'id_tienda' => (int) $before['id_tienda'],
        'id_local' => (int) $before['id_local'],
        'entidad_afectada' => 'lectura',
        'id_registro_origen' => $readingId,
        'estado_correccion' => 'BORRADOR',
        'nivel_correcion' => 'AUTORIZACION',
        'valor_anterior' => [
            'lectura_anterior' => $previous,
            'lectura_actual' => $current,
            'consumo' => (float) $before['consumo_informado'],
        ],
        'valor_nuevo' => $newReading,
        'motivo' => 'Prueba automatizada reversible de corrección de gas',
        'resultado_analisis' => [
            'registro_exacto' => ['servicio' => 'GAS', 'id_lectura' => $readingId],
            'clasificacion' => ['nivel' => 'AUTORIZACION'],
        ],
    ], $userId);

    CorreccionesService::cambiarEstado(
        $conn,
        $correctionId,
        'APROBADA',
        $userId,
        'Aprobación temporal de prueba con rollback.'
    );
    $result = CorreccionesService::ejecutar($conn, $correctionId, $userId);

    gasTestAssert(
        in_array((int) $before['id_documento_cobro'], array_map('intval', (array) ($result['documentos_actualizados'] ?? [])), true),
        'El servicio no informó el documento actualizado.'
    );

    $snapshotStmt->execute([':lectura' => $readingId]);
    $during = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
    gasTestAssert(is_array($during), 'No fue posible releer el resultado transaccional.');
    gasTestAssert(abs((float) $during['lectura_actual'] - $newReading) <= 0.0001, 'La lectura no fue actualizada.');
    gasTestAssert((float) $during['monto_documento'] < (float) $before['monto_documento'], 'El documento no fue recalculado.');
    gasTestAssert((int) $during['asientos_activos'] === 1, 'La contabilidad no quedó con un único asiento activo.');

    $statusStmt = $conn->prepare('SELECT estado_correccion FROM dbo.msp_correcciones WHERE id_correccion=:id');
    $statusStmt->execute([':id' => $correctionId]);
    gasTestAssert($statusStmt->fetchColumn() === 'EJECUTADA', 'La corrección no terminó en estado EJECUTADA.');

    $impactStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_correcciones_impactos WHERE id_correccion=:id');
    $impactStmt->execute([':id' => $correctionId]);
    gasTestAssert((int) $impactStmt->fetchColumn() >= 2, 'No se registraron todos los impactos esperados.');

    $balanceStmt = $conn->prepare(
        "SELECT ABS(ISNULL(SUM(d.debe),0)-ISNULL(SUM(d.haber),0))
         FROM dbo.msp_acc_asientos a
         INNER JOIN dbo.msp_acc_asientos_detalle d ON d.id_asiento_contable=a.id_asiento_contable
         WHERE a.tabla_origen=N'msp_documentos_cobro' AND a.id_origen=:documento AND a.estado_asiento=1"
    );
    $balanceStmt->execute([':documento' => (int) $before['id_documento_cobro']]);
    gasTestAssert((float) $balanceStmt->fetchColumn() <= 0.01, 'El asiento regenerado no quedó cuadrado.');

    $conn->rollBack();

    $snapshotStmt->execute([':lectura' => $readingId]);
    $after = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
    gasTestAssert(is_array($after), 'No fue posible comprobar el rollback.');
    gasTestAssert(abs((float) $after['lectura_actual'] - $current) <= 0.0001, 'El rollback no restauró la lectura.');
    gasTestAssert(
        abs((float) $after['monto_documento'] - (float) $before['monto_documento']) <= 0.01,
        'El rollback no restauró el documento.'
    );
    gasTestAssert(
        (int) $after['asientos_activos'] === (int) $before['asientos_activos'],
        'El rollback no restauró la contabilidad.'
    );

    echo "OK: corrección de gas, documento, trazabilidad y contabilidad validados con rollback.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
