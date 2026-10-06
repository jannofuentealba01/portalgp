<?php
declare(strict_types=1);

// Estas pruebas confirman transacciones: usar SOLO una copia local desechable.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}
$options = getopt('', ['database:', 'worker:']);
$database = (string) ($options['database'] ?? '');
if (!preg_match('/^PORTALGP_TEST_CAJA_ADMIN_[0-9_]+$/D', $database)) {
    fwrite(STDERR, "Indique una copia local desechable --database=PORTALGP_TEST_CAJA_ADMIN_<fecha>.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/msp/garantias/devolucion_service.php';
$pdo = new PDO('sqlsrv:Server=localhost;Database=' . $database . ';Encrypt=1;TrustServerCertificate=1');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function integrationData(PDO $pdo, string $medium = 'TRANSFERENCIA', ?string $key = null): array
{
    $type = $medium === 'TRANSFERENCIA' ? 'BANCO' : 'CAJA';
    $account = msp2DevolucionQuery($pdo, 'SELECT TOP(1) id_cuenta_tesoreria FROM dbo.msp_vw_tesoreria_saldos WHERE tipo_cuenta=? AND activo=1 AND saldo_actual>1 ORDER BY id_cuenta_tesoreria', [$type])[0];
    $cash = msp2DevolucionQuery($pdo, "SELECT TOP(1) id_cuenta_tesoreria FROM dbo.msp_tesoreria_cuentas WHERE tipo_cuenta=N'CAJA' AND activo=1 ORDER BY id_cuenta_tesoreria")[0];
    $guarantee = msp2DevolucionQuery($pdo, 'SELECT TOP(1) id_garantia_tienda FROM dbo.msp_vw_garantias_tienda_resumen WHERE monto_disponible>1 AND monto_reservado=0 AND estado_garantia<>6 ORDER BY id_garantia_tienda')[0];
    return ['id_solicitud' => $key ?? bin2hex(random_bytes(16)),
        'id_garantia_tienda' => (int) $guarantee['id_garantia_tienda'],
        'id_cuenta_tesoreria' => (int) $account['id_cuenta_tesoreria'],
        'id_cuenta_caja_admin' => $medium === 'TRANSFERENCIA' ? (int) $cash['id_cuenta_tesoreria'] : null,
        'fecha_devolucion' => '2026-10-06', 'monto_devolucion' => '1.00',
        'forma_devolucion' => 'PARCIAL', 'medio_devolucion' => $medium,
        'beneficiario' => 'PRUEBA AISLADA', 'rut_beneficiario' => null,
        'banco_destino' => $medium === 'TRANSFERENCIA' ? 'PRUEBA' : null,
        'cuenta_destino' => $medium === 'TRANSFERENCIA' ? 'PRUEBA' : null,
        'referencia_transferencia' => $medium === 'TRANSFERENCIA' ? 'PRUEBA AISLADA' : null,
        'observaciones' => null, 'motivo_autorizacion' => 'Validacion aislada de puntos 5 a 7', 'id_usuario' => 1];
}

if (isset($options['worker'])) {
    $result = msp2RegistrarDevolucionCompleta($pdo, integrationData($pdo, 'TRANSFERENCIA', (string) $options['worker']));
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}

$passed = 0;
$failed = 0;
function integrationEnsure(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
function integrationTest(string $label, callable $callback): void
{
    global $passed, $failed;
    try {
        $callback();
        ++$passed;
        echo "OK: {$label}\n";
    } catch (Throwable $error) {
        ++$failed;
        echo "FALLO: {$label}: {$error->getMessage()}\n";
    }
}
function integrationSnapshot(PDO $pdo): array
{
    return msp2DevolucionQuery($pdo, "SELECT
        (SELECT COUNT_BIG(*) FROM dbo.msp_garantia_devoluciones) AS devoluciones,
        (SELECT COUNT_BIG(*) FROM dbo.msp_tesoreria_movimientos) AS movimientos,
        (SELECT COUNT_BIG(*) FROM dbo.msp_movimientos_garantia) AS garantia,
        (SELECT COUNT_BIG(*) FROM dbo.msp_acc_asientos) AS asientos,
        (SELECT COUNT_BIG(*) FROM dbo.msp_garantia_devolucion_caja_admin) AS admin,
        (SELECT COUNT_BIG(*) FROM dbo.msp_garantia_devolucion_solicitudes) AS solicitudes,
        (SELECT SUM(saldo_actual) FROM dbo.msp_vw_tesoreria_saldos WHERE tipo_cuenta=N'CAJA') AS caja,
        (SELECT SUM(saldo_actual) FROM dbo.msp_vw_tesoreria_saldos WHERE tipo_cuenta=N'BANCO') AS banco,
        (SELECT SUM(monto_disponible) FROM dbo.msp_vw_garantias_tienda_resumen) AS disponible")[0];
}
function integrationFailure(PDO $pdo, array $data): void
{
    $before = integrationSnapshot($pdo);
    try {
        msp2RegistrarDevolucionCompleta($pdo, $data);
    } catch (Throwable) {
        integrationEnsure(integrationSnapshot($pdo) === $before, 'Un fallo dejo saldos/registros parciales.');
        integrationEnsure(!$pdo->inTransaction(), 'Quedo una transaccion abierta.');
        return;
    }
    throw new RuntimeException('La solicitud invalida fue aceptada.');
}

integrationTest('Transferencia atomica: 1 devolucion, 1 egreso bancario, 1 pareja y 1 asiento', static function () use ($pdo): void {
    $data = integrationData($pdo);
    $before = integrationSnapshot($pdo);
    $result = msp2RegistrarDevolucionCompleta($pdo, $data);
    $after = integrationSnapshot($pdo);
    foreach (['devoluciones', 'movimientos', 'garantia', 'asientos', 'admin', 'solicitudes'] as $key) {
        integrationEnsure((int) $after[$key] === (int) $before[$key] + 1, 'Conteo incorrecto: ' . $key);
    }
    integrationEnsure($before['caja'] === $after['caja'], 'La transferencia altero caja.');
    integrationEnsure(abs((float) $before['banco'] - (float) $after['banco'] - 1) < 0.001, 'No disminuyo banco exactamente una vez.');
    integrationEnsure(abs((float) $before['disponible'] - (float) $after['disponible'] - 1) < 0.001, 'La garantia no disminuyo una sola vez.');
    $rows = msp2DevolucionQuery($pdo, 'SELECT * FROM dbo.msp_vw_garantia_devolucion_caja_admin WHERE id_devolucion_garantia=?', [$result['id_devolucion_garantia']]);
    integrationEnsure(count($rows) === 2, 'Falta la pareja administrativa.');
    $repeat = msp2RegistrarDevolucionCompleta($pdo, $data);
    integrationEnsure($repeat['reutilizada'] && $repeat['id_devolucion_garantia'] === $result['id_devolucion_garantia'], 'El reintento no devolvio el original.');
    integrationEnsure(integrationSnapshot($pdo) === $after, 'El reintento modifico dinero o genero otro asiento.');
    $data['monto_devolucion'] = '2.00';
    integrationFailure($pdo, $data);
});

integrationTest('Efectivo: egreso real de caja sin pareja administrativa', static function () use ($pdo): void {
    $before = integrationSnapshot($pdo);
    msp2RegistrarDevolucionCompleta($pdo, integrationData($pdo, 'EFECTIVO'));
    $after = integrationSnapshot($pdo);
    integrationEnsure((int) $after['devoluciones'] === (int) $before['devoluciones'] + 1, 'No se emitio la devolucion.');
    integrationEnsure($after['admin'] === $before['admin'] && $after['banco'] === $before['banco'], 'Se agrego una pareja o se modifico banco.');
    integrationEnsure(abs((float) $before['caja'] - (float) $after['caja'] - 1) < 0.001, 'No disminuyo caja exactamente una vez.');
});

integrationTest('Falla administrativa posterior al egreso revierte TODO', static function () use ($pdo): void {
    $data = integrationData($pdo);
    $data['id_cuenta_caja_admin'] = 2147483647;
    integrationFailure($pdo, $data);
});

integrationTest('Falla al guardar solicitud final revierte egreso, asiento y pareja', static function () use ($pdo): void {
    $data = integrationData($pdo);
    $key = $data['id_solicitud'];
    $pdo->exec("CREATE OR ALTER TRIGGER dbo.TR_TEST_dev_falla_final ON dbo.msp_garantia_devolucion_solicitudes AFTER INSERT AS
        BEGIN IF EXISTS(SELECT 1 FROM inserted WHERE id_solicitud='{$key}') THROW 53999,N'Fallo aislado de prueba',1; END;");
    try {
        integrationFailure($pdo, $data);
    } finally {
        $pdo->exec('DROP TRIGGER dbo.TR_TEST_dev_falla_final;');
    }
});

integrationTest('Saldo de banco insuficiente conserva todas las tablas y saldos', static function () use ($pdo): void {
    $data = integrationData($pdo);
    $data['monto_devolucion'] = '999999999999.00';
    integrationFailure($pdo, $data);
});

integrationTest('Total obsoleto no cambia el importe silenciosamente', static function () use ($pdo): void {
    $data = integrationData($pdo, 'EFECTIVO');
    $data['forma_devolucion'] = 'TOTAL';
    integrationFailure($pdo, $data);
});

integrationTest('Total liquida exactamente el saldo disponible', static function () use ($pdo): void {
    $data = integrationData($pdo, 'EFECTIVO');
    $data['forma_devolucion'] = 'TOTAL';
    $data['monto_devolucion'] = (string) msp2DevolucionQuery($pdo, 'SELECT monto_disponible FROM dbo.msp_vw_garantias_tienda_resumen WHERE id_garantia_tienda=?', [$data['id_garantia_tienda']])[0]['monto_disponible'];
    $result = msp2RegistrarDevolucionCompleta($pdo, $data);
    $remaining = msp2DevolucionQuery($pdo, 'SELECT monto_disponible FROM dbo.msp_vw_garantias_tienda_resumen WHERE id_garantia_tienda=?', [$data['id_garantia_tienda']])[0];
    integrationEnsure((float) $remaining['monto_disponible'] === 0.0, 'No se devolvio todo el disponible.');
    integrationEnsure(msp2RegistrarDevolucionCompleta($pdo, $data)['id_devolucion_garantia'] === $result['id_devolucion_garantia'], 'El reintento total fallo con saldo cero.');
});

integrationTest('Dos solicitudes concurrentes con la misma clave no duplican', static function () use ($pdo, $database): void {
    $key = bin2hex(random_bytes(16));
    $processes = [];
    $before = integrationSnapshot($pdo);
    for ($i = 0; $i < 2; ++$i) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, '--database=' . $database, '--worker=' . $key],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        integrationEnsure(is_resource($process), 'No se pudo iniciar el trabajador.');
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        integrationEnsure(proc_close($process) === 0, 'Fallo el trabajador: ' . $errors . $output);
        $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
    integrationEnsure($results[0]['id_devolucion_garantia'] === $results[1]['id_devolucion_garantia'], 'Se emitieron dos devoluciones.');
    $after = integrationSnapshot($pdo);
    foreach (['devoluciones', 'movimientos', 'garantia', 'asientos', 'admin', 'solicitudes'] as $key) {
        integrationEnsure((int) $after[$key] === (int) $before[$key] + 1, 'La concurrencia duplico: ' . $key);
    }
});

integrationTest('Endpoint mantiene permiso y CSRF antes del servicio', static function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/msp/garantias/registrar_devolucion.php');
    integrationEnsure(str_contains($source, "msp2RequireAccess('MSP Cobranza', 'escritura')")
        && strpos($source, 'msp2RequireValidCsrfToken();') < strpos($source, 'msp2RegistrarDevolucionCompleta($conn'), 'Falta permiso/CSRF previo a la escritura.');
});

integrationTest('Reversa bancaria conserva clave y rechaza reemitir una solicitud anulada', static function () use ($pdo): void {
    $data = integrationData($pdo);
    $result = msp2RegistrarDevolucionCompleta($pdo, $data);
    msp2DevolucionQuery($pdo, "EXEC dbo.msp_garantia_tienda_revertir_operacion N'DEVOLUCION',?,'20261006',N'Prueba aislada de reversa',1", [$result['id_devolucion_garantia']]);
    integrationFailure($pdo, $data);
    $rows = msp2DevolucionQuery($pdo, 'SELECT estado_movimiento FROM dbo.msp_vw_garantia_devolucion_caja_admin WHERE id_devolucion_garantia=?', [$result['id_devolucion_garantia']]);
    integrationEnsure(count($rows) === 2 && array_unique(array_column($rows, 'estado_movimiento')) === ['ANULADO'], 'La reversa no dejo ambos estados.');
});

echo "Resultado: {$passed} OK, {$failed} fallos. SOLO copia desechable {$database}.\n";
exit($failed > 0 ? 1 : 0);
