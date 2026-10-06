<?php
declare(strict_types=1);

// Todo se revierte. Nunca utiliza configuracion ni credenciales de produccion.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI.'); }
$options = getopt('', ['database:']);
$database = (string) ($options['database'] ?? '');
if (!preg_match('/^PORTALGP_TEST_CAJA_ADMIN_[0-9_]+$/D', $database)) { exit("Indique una copia local aislada.\n"); }
require_once dirname(__DIR__) . '/msp/garantias/devolucion_service.php';
require_once dirname(__DIR__) . '/msp/garantias/caja_admin_historial.php';
function msp2Escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$pdo = new PDO('sqlsrv:Server=localhost;Database=' . $database . ';Encrypt=1;TrustServerCertificate=1;MultipleActiveResultSets=false');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$passed = $failed = 0;
function q89(string $sql, array $params = []): array { global $pdo; return msp2DevolucionQuery($pdo, $sql, $params); }
function ok89(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
function totals89(): array
{
    return q89("SELECT
        (SELECT SUM(saldo_actual) FROM dbo.msp_vw_tesoreria_saldos WHERE tipo_cuenta=N'CAJA') AS caja,
        (SELECT SUM(saldo_actual) FROM dbo.msp_vw_tesoreria_saldos WHERE tipo_cuenta=N'BANCO') AS banco,
        (SELECT SUM(monto_disponible) FROM dbo.msp_vw_garantias_tienda_resumen) AS disponible,
        (SELECT SUM(d.haber-d.debe) FROM dbo.msp_acc_asientos a JOIN dbo.msp_acc_asientos_detalle d
         ON d.id_asiento_contable=a.id_asiento_contable JOIN dbo.msp_acc_plan_cuentas c
         ON c.id_cuenta_contable=d.id_cuenta_contable WHERE c.codigo_cuenta=N'2.1.02' AND a.estado_asiento IN(1,2,3)) AS pasivo")[0];
}
function baseline89(): array
{
    return array_merge(totals89(), q89("SELECT
        (SELECT COUNT_BIG(*) FROM dbo.msp_garantia_devoluciones) AS devoluciones,
        (SELECT COUNT_BIG(*) FROM dbo.msp_movimientos_garantia) AS garantia,
        (SELECT COUNT_BIG(*) FROM dbo.msp_tesoreria_movimientos) AS tesoreria,
        (SELECT COUNT_BIG(*) FROM dbo.msp_acc_asientos) AS asientos,
        (SELECT COUNT_BIG(*) FROM dbo.msp_garantia_reversas) AS reversas,
        (SELECT COUNT_BIG(*) FROM dbo.msp_garantia_devolucion_caja_admin) AS admin,
        (SELECT COUNT_BIG(*) FROM dbo.msp_tesoreria_cierres_caja) AS cierres,
        (SELECT COUNT_BIG(*) FROM dbo.msp_tesoreria_conciliaciones) AS conciliaciones,
        (SELECT COUNT_BIG(*) FROM dbo.msp_tesoreria_solicitudes_reapertura_caja) AS solicitudes_reapertura,
        (SELECT COUNT_BIG(*) FROM dbo.msp_tesoreria_bitacora_reapertura_caja) AS reaperturas")[0]);
}
function data89(string $medio = 'TRANSFERENCIA'): array
{
    $account = q89('SELECT TOP(1) id_cuenta_tesoreria FROM dbo.msp_vw_tesoreria_saldos WHERE activo=1 AND tipo_cuenta=? AND saldo_actual>=1 ORDER BY id_cuenta_tesoreria', [$medio === 'EFECTIVO' ? 'CAJA' : 'BANCO'])[0];
    $cash = q89("SELECT TOP(1) id_cuenta_tesoreria FROM dbo.msp_tesoreria_cuentas WHERE activo=1 AND tipo_cuenta=N'CAJA' ORDER BY id_cuenta_tesoreria")[0];
    $gt = q89('SELECT TOP(1) id_garantia_tienda FROM dbo.msp_vw_garantias_tienda_resumen WHERE monto_disponible>=1 AND monto_reservado=0 AND estado_garantia<>6 ORDER BY id_garantia_tienda')[0];
    return ['account' => (int) $account['id_cuenta_tesoreria'], 'cash' => (int) $cash['id_cuenta_tesoreria'], 'gt' => (int) $gt['id_garantia_tienda'], 'medio' => $medio];
}
function refund89(array $data): array
{
    $row = q89("EXEC dbo.msp_garantia_tienda_devolver_operativa @id_garantia_tienda=?,@id_cuenta_tesoreria=?,
        @fecha_devolucion='20261006',@monto_devolucion=1,@medio_devolucion=?,@beneficiario=N'PRUEBA AISLADA',
        @banco_destino=N'PRUEBA',@cuenta_destino=N'PRUEBA',@referencia_transferencia=N'PRUEBA',
        @id_usuario=1,@motivo_autorizacion=N'Prueba aislada de cierre y reversa',@id_usuario_autoriza=1",
        [$data['gt'], $data['account'], $data['medio']])[0];
    if ($data['medio'] === 'TRANSFERENCIA') {
        q89('EXEC dbo.msp_garantia_devolucion_registrar_caja_admin ?,?,1', [(int) $row['id_devolucion_garantia'], $data['cash']]);
    }
    return array_merge($data, ['refund' => (int) $row['id_devolucion_garantia'], 'mg' => (int) $row['id_movimiento_garantia']]);
}
function reverse89(array $fixture, string $fecha = '20261006', int $usuario = 1): void
{
    q89("EXEC dbo.msp_garantia_tienda_revertir_operacion N'DEVOLUCION',?,?,N'Prueba aislada de reversa',?", [$fixture['refund'], $fecha, $usuario]);
}
function close89(int $cash): array
{
    $saldo = q89("SELECT SUM(CASE naturaleza WHEN 'E' THEN monto ELSE -monto END) AS saldo FROM dbo.msp_tesoreria_movimientos WHERE id_cuenta_tesoreria=? AND fecha_movimiento<='20261006' AND estado_movimiento=N'VIGENTE'", [$cash])[0]['saldo'];
    return q89("EXEC dbo.msp_tesoreria_cerrar_caja ?,'20261006',?,N'Prueba aislada',1", [$cash, $saldo])[0];
}
function cashDay89(int $cash): array
{
    return q89("SELECT COALESCE(SUM(CASE naturaleza WHEN 'E' THEN monto ELSE 0 END),0) AS entradas,
        COALESCE(SUM(CASE naturaleza WHEN 'S' THEN monto ELSE 0 END),0) AS salidas
        FROM dbo.msp_tesoreria_movimientos WHERE id_cuenta_tesoreria=?
          AND fecha_movimiento='20261006' AND estado_movimiento=N'VIGENTE'", [$cash])[0];
}
function reject89(callable $callback, int $code): void
{
    try { $callback(); } catch (PDOException $error) {
        ok89((int) ($error->errorInfo[1] ?? 0) === $code, 'Rechazo inesperado: ' . $error->getMessage()); return;
    }
    throw new RuntimeException('Se acepto una operacion protegida.');
}
function test89(string $label, callable $callback): void
{
    global $pdo, $passed, $failed;
    $before = baseline89();
    try {
        $pdo->exec('BEGIN TRANSACTION;');
        $callback();
        $pdo->exec('IF XACT_STATE()<>0 ROLLBACK TRANSACTION;');
        ok89(baseline89() === $before, 'Quedaron datos de prueba.');
        ++$passed; echo "OK: {$label}\n";
    } catch (Throwable $error) {
        $pdo->exec('IF XACT_STATE()<>0 ROLLBACK TRANSACTION;');
        ++$failed; echo "FALLO: {$label}: {$error->getMessage()}\n";
    }
}

test89('Devolucion bancaria: cierre de caja excluye ambas lineas administrativas', static function (): void {
    $before = totals89(); $data = data89(); $day = cashDay89($data['cash']); $f = refund89($data);
    $close = close89($f['cash']);
    $row = q89('SELECT total_entradas,total_salidas,diferencia FROM dbo.msp_tesoreria_cierres_caja WHERE id_cierre_caja=?', [$close['id_cierre_caja']])[0];
    ok89($row['total_entradas'] === $day['entradas'] && $row['total_salidas'] === $day['salidas'] && (float) $row['diferencia'] === 0.0, 'El cierre sumo la pareja.');
    ok89(totals89()['caja'] === $before['caja'], 'La caja cambio por la transferencia.');
    // El cierre de la caja informativa no bloquea una reversa del banco real.
    reverse89($f); ok89(totals89() === $before, 'La reversa no restauro saldos/pasivo.');
});
test89('Devolucion efectivo: cierre registra un egreso real y bloquea su reversa', static function (): void {
    $data = data89('EFECTIVO'); $day = cashDay89($data['cash']); $f = refund89($data); $close = close89($f['cash']);
    $row = q89('SELECT total_salidas FROM dbo.msp_tesoreria_cierres_caja WHERE id_cierre_caja=?', [$close['id_cierre_caja']])[0];
    ok89(abs((float) $row['total_salidas'] - (float) $day['salidas'] - 1.0) < 0.001, 'No se contabilizo el egreso de efectivo.');
    reject89(static fn() => reverse89($f), 51908);
});
test89('Conciliacion bancaria incluye solo un egreso y protege su reversa', static function (): void {
    $f = refund89(data89());
    $tm = q89('SELECT id_movimiento_tesoreria FROM dbo.msp_tesoreria_movimientos WHERE id_devolucion_garantia=?', [$f['refund']])[0]['id_movimiento_tesoreria'];
    $saldo = q89('SELECT saldo_actual FROM dbo.msp_vw_tesoreria_saldos WHERE id_cuenta_tesoreria=?', [$f['account']])[0]['saldo_actual'];
    $c = q89("EXEC dbo.msp_tesoreria_conciliar_banco ?,'20261006','20261006',?,?,N'Prueba aislada',1", [$f['account'], $saldo, json_encode([(int) $tm])])[0];
    ok89($c['estado_conciliacion'] === 'CUADRADA', 'La conciliacion no cuadra.');
    $items = q89('SELECT monto_firmado FROM dbo.msp_tesoreria_conciliacion_items WHERE id_conciliacion_tesoreria=?', [$c['id_conciliacion_tesoreria']]);
    ok89(count($items) === 1 && (float) $items[0]['monto_firmado'] === -1.0, 'Se duplico el egreso.');
    reject89(static fn() => reverse89($f), 52003);
});
test89('Periodo bancario pendiente tambien bloquea una devolucion nueva', static function (): void {
    $data = data89();
    q89("EXEC dbo.msp_tesoreria_conciliar_banco ?,'20261006','20261006',0,N'[]',N'Prueba pendiente',1", [$data['account']]);
    reject89(static fn() => refund89($data), 51909);
});
test89('Caja cerrada impide devolver; reapertura autorizada permite devolver y revertir', static function (): void {
    $data = data89('EFECTIVO'); $before = totals89(); $close = close89($data['cash']);
    $authorizer = (int) q89("SELECT TOP(1) u.id FROM dbo.cr_usuarios u
        JOIN dbo.cr_rol_permisos rp ON rp.rol_id=u.rol_id JOIN dbo.cr_permisos p ON p.id=rp.permiso_id
        WHERE u.estado_id=1 AND u.id<>1 AND p.nombre_permiso=N'MSP Tesoreria'
          AND rp.lectura=1 AND rp.escritura=1 AND rp.eliminacion=1 ORDER BY u.id")[0]['id'];
    $request = q89("EXEC dbo.msp_tesoreria_solicitar_reapertura_caja ?,N'Prueba aislada de reapertura autorizada',1", [$close['id_cierre_caja']])[0];
    q89("EXEC dbo.msp_tesoreria_resolver_reapertura_caja ?,N'APROBAR',N'Prueba aislada aprobada',?", [$request['id_solicitud_reapertura'], $authorizer]);
    ok89((int) q89('SELECT COUNT(*) AS n FROM dbo.msp_tesoreria_bitacora_reapertura_caja WHERE id_cierre_caja=?', [$close['id_cierre_caja']])[0]['n'] === 1, 'Falta bitacora.');
    $f = refund89($data); reverse89($f);
    ok89(totals89() === $before, 'La reversa efectivo no restauro el saldo/pasivo.');
});
test89('Cierre activo impide registrar una nueva devolucion en efectivo', static function (): void {
    $data = data89('EFECTIVO'); close89($data['cash']);
    reject89(static fn() => refund89($data), 51908);
});
test89('Reversa conserva auditoria y ambos estados; historial no duplica impacto', static function (): void {
    global $pdo;
    $before = totals89(); $f = refund89(data89()); reverse89($f);
    ok89(totals89() === $before, 'Saldos/pasivo no restaurados.');
    $rows = msp2GarantiasCajaAdminHistorial($pdo, [$f['mg']])[$f['mg']];
    ok89(count($rows) === 2 && array_unique(array_column($rows, 'estado_movimiento')) === ['ANULADO'], 'La pareja no se anulo completa.');
    ok89(str_contains(msp2GarantiasCajaAdminDetalle($rows), 'Efecto en caja: $ 0,00'), 'Falta distincion visual.');
    $events = q89("SELECT SUM(impacto_total) AS impacto FROM dbo.msp_vw_garantias_historial_eventos WHERE id_garantia_tienda=?", [$f['gt']])[0];
    $gt = q89('SELECT monto_disponible+monto_reservado AS saldo FROM dbo.msp_vw_garantias_tienda_resumen WHERE id_garantia_tienda=?', [$f['gt']])[0];
    ok89($events['impacto'] === $gt['saldo'], 'El historial duplico la compensacion.');
    reject89(static fn() => reverse89($f), 52005);
});
test89('Reversa anterior al origen se rechaza sin cambios', static function (): void {
    $f = refund89(data89()); reject89(static fn() => reverse89($f, '20261005'), 53933);
});
test89('Reversa exige usuario habilitado', static function (): void {
    $f = refund89(data89()); reject89(static fn() => reverse89($f, '20261006', 0), 53932);
});
test89('Integracion no se habilita si se desactiva una proteccion de reversas', static function (): void {
    global $pdo;
    ok89(msp2DevolucionAdminDisponible($pdo), 'No esta instalada la version probada.');
    q89('DISABLE TRIGGER dbo.TR_msp_garantia_reversas_integridad ON dbo.msp_garantia_reversas;');
    ok89(!msp2DevolucionAdminDisponible($pdo), 'Se habilito el envio sin proteccion de integridad.');
    // El rollback exterior restaura el trigger; solo se hace en esta copia aislada.
});
test89('Endpoint conserva permiso de eliminacion y CSRF explicito', static function (): void {
    $source = file_get_contents(dirname(__DIR__) . '/msp/garantias/revertir.php');
    ok89(str_contains($source, "msp2RequireAccess('MSP Cobranza', 'eliminacion')") && strpos($source, 'msp2RequireValidCsrfToken();') < strpos($source, 'msp2DevolucionQuery($conn'), 'Falta autorizacion previa.');
});
echo "Resultado: {$passed} OK, {$failed} fallos. Copia aislada {$database}.\n";
exit($failed ? 1 : 0);
