<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/msp/bootstrap.php';
require_once dirname(__DIR__) . '/msp/services/CorreccionesService.php';

/**
 * Prueba integral reversible de la corrección controlada de agua.
 * Valida lectura, fórmula Excel, documento, contabilidad y rollback.
 */
function waterTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$testDatabase = trim((string) getenv('MSP_TEST_DB'));
$allowCurrentDatabase = getenv('MSP_ALLOW_CURRENT_DB_ROLLBACK_TEST') === '1';
waterTestAssert(
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
$conn = $testUser === '' ? new PDO($testDsn) : new PDO($testDsn, $testUser, $testPassword);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
waterTestAssert(
    strtoupper((string) $conn->query('SELECT DB_NAME()')->fetchColumn()) === strtoupper($testDatabase),
    'No fue posible confirmar la base de prueba.'
);

$readingId = isset($argv[1]) ? (int) $argv[1] : 1626;
waterTestAssert($readingId > 0, 'Debes indicar un ID de lectura válido.');
$snapshotSql =
    "SELECT TOP(1) lm.id_lectura,lm.lectura_anterior,lm.lectura_actual,lm.consumo_informado,
        m.id_local,dc.id_contrato_arriendo,dc.id_tienda,cs.id_cobro_servicio,
        cs.subtotal_variable,cs.cargo_fijo,cs.monto_total monto_cobro,
        pa.servicio_agua_potable,pa.servicio_alcantarillado,
        pa.tratamiento_aguas_servidas,pa.divisor,pa.cargo_fijo cargo_fijo_parametro,
        dc.id_documento_cobro,dc.periodo_facturacion,dc.monto_total monto_documento,dc.saldo_pendiente,
        (SELECT COUNT(*) FROM dbo.msp_pagos p
         WHERE p.id_documento_cobro=dc.id_documento_cobro) pagos_registrados,
        (SELECT COUNT(*) FROM dbo.msp_acc_asientos a
         WHERE a.tabla_origen=N'msp_documentos_cobro'
           AND a.id_origen=dc.id_documento_cobro AND a.estado_asiento=1) asientos_activos
     FROM dbo.msp_lecturas_medidores lm
     INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
     INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro=lm.id_proceso_cobro
     INNER JOIN dbo.msp_proceso_cobro_agua pa ON pa.id_proceso_cobro=p.id_proceso_cobro
     LEFT JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
     LEFT JOIN dbo.msp_documentos_cobro_detalle dd ON dd.id_cobro_servicio=cs.id_cobro_servicio
     LEFT JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=dd.id_documento_cobro
     WHERE lm.id_lectura=:lectura
     ORDER BY dc.id_documento_cobro DESC";
$snapshotStmt = $conn->prepare($snapshotSql);
$snapshotStmt->execute([':lectura'=>$readingId]);
$before = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
waterTestAssert(is_array($before), 'No se encontró la lectura de agua de prueba.');
waterTestAssert((int) ($before['id_documento_cobro'] ?? 0) > 0, 'La prueba requiere una lectura documentada.');
waterTestAssert((int) ($before['asientos_activos'] ?? 0) > 0, 'La prueba requiere un asiento contable activo.');
waterTestAssert((int) ($before['pagos_registrados'] ?? 0) === 0, 'La lectura base de prueba ya tiene pagos registrados.');

$previous = (float) $before['lectura_anterior'];
$current = (float) $before['lectura_actual'];
$newReading = round($current - 1, 4);
waterTestAssert($newReading >= $previous, 'La lectura no tiene margen para una prueba reversible.');
$rate = (
    (float) $before['servicio_agua_potable']
    + (float) $before['servicio_alcantarillado']
    + (float) $before['tratamiento_aguas_servidas']
) / (float) $before['divisor'];
$expectedVariable = round(($newReading - $previous) * $rate, 2);
$expectedFixed = round((float) $before['cargo_fijo_parametro'], 2);
$expectedTotal = round($expectedVariable + $expectedFixed, 2);

$userId = (int) ($conn->query("SELECT TOP(1) id FROM dbo.cr_usuarios WHERE UserName=N'admin_2'")->fetchColumn() ?: 0);
waterTestAssert($userId > 0, 'No se encontró admin_2 para atribuir la prueba.');
$archiveCountStmt = $conn->prepare(
    'SELECT COUNT(*) FROM dbo.msp_pago_contrato_archivos WHERE id_documento_cobro=:documento'
);
$archiveCountStmt->execute([':documento'=>(int) $before['id_documento_cobro']]);
$archiveCountBefore = (int) $archiveCountStmt->fetchColumn();

$conn->beginTransaction();
try {
    // El documento real de abril conserva un respaldo de pago. Se retira solo dentro de
    // esta transacción para ejercitar el camino completo; el rollback lo restaura.
    $detachArchiveStmt = $conn->prepare(
        'DELETE FROM dbo.msp_pago_contrato_archivos WHERE id_documento_cobro=:documento'
    );
    $detachArchiveStmt->execute([':documento'=>(int) $before['id_documento_cobro']]);
    $correctionId = CorreccionesService::crearSolicitud($conn, [
        'tipo_correccion'=>'LECTURA',
        'modulo_origen'=>'tests/msp_water_correction_rollback.php',
        'periodo_facturacion'=>substr((string) $before['periodo_facturacion'], 0, 7),
        'id_contrato_arriendo'=>(int) $before['id_contrato_arriendo'],
        'id_tienda'=>(int) $before['id_tienda'],
        'id_local'=>(int) $before['id_local'],
        'entidad_afectada'=>'lectura',
        'id_registro_origen'=>$readingId,
        'estado_correccion'=>'BORRADOR',
        'nivel_correcion'=>'AUTORIZACION',
        'valor_anterior'=>[
            'lectura_anterior'=>$previous,
            'lectura_actual'=>$current,
            'consumo'=>(float) $before['consumo_informado'],
        ],
        'valor_nuevo'=>$newReading,
        'motivo'=>'Prueba automatizada reversible de corrección de agua',
        'resultado_analisis'=>[
            'registro_exacto'=>['servicio'=>'AGUA','id_lectura'=>$readingId],
            'clasificacion'=>['nivel'=>'AUTORIZACION'],
        ],
    ], $userId);
    CorreccionesService::cambiarEstado($conn, $correctionId, 'APROBADA', $userId, 'Aprobación temporal con rollback.');
    $result = CorreccionesService::ejecutar($conn, $correctionId, $userId);

    waterTestAssert(
        in_array((int) $before['id_documento_cobro'], array_map('intval', (array) ($result['documentos_actualizados'] ?? [])), true),
        'El servicio no informó el documento actualizado.'
    );
    $snapshotStmt->execute([':lectura'=>$readingId]);
    $during = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
    waterTestAssert(is_array($during), 'No fue posible releer el resultado.');
    waterTestAssert(abs((float) $during['lectura_actual'] - $newReading) <= 0.0001, 'La lectura no fue actualizada.');
    waterTestAssert(abs((float) $during['subtotal_variable'] - $expectedVariable) <= 0.01, 'El subtotal variable no coincide con el Excel.');
    waterTestAssert(abs((float) $during['cargo_fijo'] - $expectedFixed) <= 0.01, 'El cargo fijo no se aplicó completo.');
    waterTestAssert(abs((float) $during['monto_cobro'] - $expectedTotal) <= 0.01, 'El total de agua no coincide con el Excel.');
    waterTestAssert((int) $during['asientos_activos'] === 1, 'La contabilidad no quedó con un asiento activo.');
    $impactStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_correcciones_impactos WHERE id_correccion=:id');
    $impactStmt->execute([':id'=>$correctionId]);
    waterTestAssert((int) $impactStmt->fetchColumn() >= 2, 'No se registraron los impactos esperados.');
    $statusStmt = $conn->prepare('SELECT estado_correccion FROM dbo.msp_correcciones WHERE id_correccion=:id');
    $statusStmt->execute([':id'=>$correctionId]);
    waterTestAssert($statusStmt->fetchColumn() === 'EJECUTADA', 'La corrección no terminó en EJECUTADA.');

    waterTestAssert(
        msp2ProcedureExists($conn, 'msp_registrar_pago_documento'),
        'La prueba protegida requiere el procedimiento de pago documental.'
    );
    $saldoFavorAntes = (float) ($conn->query(
        'SELECT ISNULL(saldo_disponible,0) FROM dbo.msp_saldos_favor_tienda WHERE id_tienda=' . (int) $before['id_tienda']
    )->fetchColumn() ?: 0);
    $registrarPago = $conn->prepare(
        'EXEC dbo.msp_registrar_pago_documento
            @id_documento_cobro=:documento,@fecha_pago=:fecha,@monto_pagado=:monto,
            @medio_pago=N\'PRUEBA\',@referencia_pago=N\'AGUA-AJUSTE-ROLLBACK\',
            @observaciones=N\'Pago temporal para validar ajuste de agua protegido\''
    );
    $registrarPago->execute([
        ':documento'=>(int) $before['id_documento_cobro'],
        ':fecha'=>date('Y-m-d'),
        ':monto'=>(float) $during['monto_documento'],
    ]);
    while ($registrarPago->nextRowset()) {
        // Consumir todos los resultados del procedimiento antes de continuar.
    }
    $pagosProtegidos = $conn->prepare(
        'SELECT COUNT(*) FROM dbo.msp_pagos
         WHERE id_documento_cobro=:documento AND estado_pago=1'
    );
    $pagosProtegidos->execute([':documento'=>(int) $before['id_documento_cobro']]);
    waterTestAssert((int) $pagosProtegidos->fetchColumn() === 1, 'No se creó el pago protegido de prueba.');

    $protectedReading = round($newReading - 1, 4);
    waterTestAssert($protectedReading >= $previous, 'La lectura no tiene margen para probar el ajuste protegido.');
    $protectedCorrectionId = CorreccionesService::crearSolicitud($conn, [
        'tipo_correccion'=>'LECTURA',
        'modulo_origen'=>'tests/msp_water_correction_rollback.php',
        'periodo_facturacion'=>substr((string) $before['periodo_facturacion'], 0, 7),
        'id_contrato_arriendo'=>(int) $before['id_contrato_arriendo'],
        'id_tienda'=>(int) $before['id_tienda'],
        'id_local'=>(int) $before['id_local'],
        'entidad_afectada'=>'lectura',
        'id_registro_origen'=>$readingId,
        'estado_correccion'=>'BORRADOR',
        'nivel_correcion'=>'AJUSTE_FINANCIERO',
        'valor_anterior'=>[
            'lectura_anterior'=>$previous,
            'lectura_actual'=>$newReading,
            'consumo'=>(float) $during['consumo_informado'],
        ],
        'valor_nuevo'=>$protectedReading,
        'motivo'=>'Prueba reversible de ajuste de agua protegido',
        'resultado_analisis'=>[
            'registro_exacto'=>['servicio'=>'AGUA','id_lectura'=>$readingId],
            'clasificacion'=>['nivel'=>'AJUSTE_FINANCIERO'],
        ],
    ], $userId);
    CorreccionesService::cambiarEstado(
        $conn,
        $protectedCorrectionId,
        'APROBADA',
        $userId,
        'Aprobación temporal del ajuste de agua protegido.'
    );
    $protectedResult = CorreccionesService::ejecutar($conn, $protectedCorrectionId, $userId);
    waterTestAssert(
        (string) ($protectedResult['estrategia'] ?? '') === 'AGUA_AJUSTE_FINANCIERO',
        'La corrección protegida no utilizó la estrategia financiera de agua.'
    );
    $documentResults = (array) ($protectedResult['resultado_documentos'] ?? []);
    $documentResult = (array) ($documentResults[(int) $before['id_documento_cobro']] ?? []);
    $saldoFavorGenerado = round((float) ($documentResult['saldo_favor_generado'] ?? 0), 2);
    waterTestAssert($saldoFavorGenerado > 0, 'La rebaja de agua protegida no generó saldo a favor.');

    $snapshotStmt->execute([':lectura'=>$readingId]);
    $protectedDuring = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
    waterTestAssert(is_array($protectedDuring), 'No fue posible releer el ajuste de agua protegido.');
    waterTestAssert(
        abs((float) $protectedDuring['lectura_actual'] - $protectedReading) <= 0.0001,
        'El ajuste protegido no actualizó la lectura.'
    );
    waterTestAssert(abs((float) $protectedDuring['saldo_pendiente']) <= 0.01, 'El documento pagado no permaneció saldado.');
    $pagosProtegidos->execute([':documento'=>(int) $before['id_documento_cobro']]);
    waterTestAssert((int) $pagosProtegidos->fetchColumn() === 1, 'El ajuste de agua alteró el pago existente.');
    $saldoFavorDurante = (float) ($conn->query(
        'SELECT ISNULL(saldo_disponible,0) FROM dbo.msp_saldos_favor_tienda WHERE id_tienda=' . (int) $before['id_tienda']
    )->fetchColumn() ?: 0);
    waterTestAssert(
        abs($saldoFavorDurante - ($saldoFavorAntes + $saldoFavorGenerado)) <= 0.01,
        'El saldo a favor de la tienda no refleja la diferencia de agua.'
    );

    $conn->rollBack();
    $snapshotStmt->execute([':lectura'=>$readingId]);
    $after = $snapshotStmt->fetch(PDO::FETCH_ASSOC);
    waterTestAssert(is_array($after), 'No fue posible comprobar el rollback.');
    waterTestAssert(abs((float) $after['lectura_actual'] - $current) <= 0.0001, 'El rollback no restauró la lectura.');
    waterTestAssert(abs((float) $after['monto_cobro'] - (float) $before['monto_cobro']) <= 0.01, 'El rollback no restauró el cobro.');
    waterTestAssert(abs((float) $after['monto_documento'] - (float) $before['monto_documento']) <= 0.01, 'El rollback no restauró el documento.');
    waterTestAssert((int) $after['asientos_activos'] === (int) $before['asientos_activos'], 'El rollback no restauró la contabilidad.');
    waterTestAssert((int) $after['pagos_registrados'] === 0, 'El rollback no eliminó el pago temporal.');
    $saldoFavorDespues = (float) ($conn->query(
        'SELECT ISNULL(saldo_disponible,0) FROM dbo.msp_saldos_favor_tienda WHERE id_tienda=' . (int) $before['id_tienda']
    )->fetchColumn() ?: 0);
    waterTestAssert(
        abs($saldoFavorDespues - $saldoFavorAntes) <= 0.01,
        'El rollback no restauró el saldo a favor de la tienda.'
    );
    $archiveCountStmt->execute([':documento'=>(int) $before['id_documento_cobro']]);
    waterTestAssert((int) $archiveCountStmt->fetchColumn() === $archiveCountBefore, 'El rollback no restauró el respaldo de pago.');
    echo "OK: agua simple y protegida, fórmula Excel, pagos, saldo a favor, documento, trazabilidad, contabilidad y rollback validados.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
