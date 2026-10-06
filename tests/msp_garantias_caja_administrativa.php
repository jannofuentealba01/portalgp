<?php
declare(strict_types=1);

// Solo copia local aislada. Nunca carga db.php ni usa las credenciales productivas.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}
$options = getopt('', ['database:', 'check-reversal']);
$database = (string) ($options['database'] ?? '');
if (!preg_match('/^PORTALGP_TEST_CAJA_ADMIN_[0-9_]+$/D', $database)) {
    fwrite(STDERR, "Indique --database=PORTALGP_TEST_CAJA_ADMIN_<fecha> (copia aislada local).\n");
    exit(2);
}

$pdo = new PDO('sqlsrv:Server=localhost;Database=' . $database . ';Encrypt=1;TrustServerCertificate=1;MultipleActiveResultSets=false');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$passed = 0;
$failed = 0;

function query(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    do {
        if ($stmt->columnCount() > 0) {
            $rows = $stmt->fetchAll();
        }
    } while ($stmt->nextRowset());
    $stmt->closeCursor();
    return $rows;
}

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rollbackFixture(PDO $pdo): void
{
    $pdo->exec('IF XACT_STATE()<>0 ROLLBACK TRANSACTION;');
}

function financialSnapshot(PDO $pdo): array
{
    return query($pdo, "SELECT
        (SELECT COUNT_BIG(*) FROM dbo.msp_tesoreria_movimientos) AS tesoreria,
        (SELECT COUNT_BIG(*) FROM dbo.msp_garantia_devoluciones) AS devoluciones,
        (SELECT COUNT_BIG(*) FROM dbo.msp_garantia_recepciones) AS recepciones,
        (SELECT COUNT_BIG(*) FROM dbo.msp_movimientos_garantia) AS garantia,
        (SELECT COUNT_BIG(*) FROM dbo.msp_acc_asientos) AS asientos,
        (SELECT SUM(saldo_actual) FROM dbo.msp_vw_tesoreria_saldos) AS saldo_tesoreria,
        (SELECT SUM(saldo_disponible) FROM dbo.msp_vw_garantias_tienda_resumen) AS disponible,
        (SELECT SUM(monto_reservado) FROM dbo.msp_vw_garantias_tienda_resumen) AS reservado")[0];
}

function fixture(PDO $pdo, string $medium = 'TRANSFERENCIA'): array
{
    $pdo->exec('BEGIN TRANSACTION;');
    $accountType = $medium === 'TRANSFERENCIA' ? 'BANCO' : 'CAJA';
    $account = query($pdo, "SELECT TOP(1) id_cuenta_tesoreria FROM dbo.msp_vw_tesoreria_saldos
        WHERE tipo_cuenta=? AND activo=1 AND saldo_actual>=1 ORDER BY id_cuenta_tesoreria", [$accountType]);
    $cash = query($pdo, "SELECT TOP(1) id_cuenta_tesoreria FROM dbo.msp_tesoreria_cuentas
        WHERE tipo_cuenta=N'CAJA' AND activo=1 ORDER BY id_cuenta_tesoreria");
    $guarantee = query($pdo, "SELECT TOP(1) id_garantia_tienda FROM dbo.msp_vw_garantias_tienda_resumen
        WHERE saldo_disponible>=1 AND monto_reservado=0 AND estado_garantia<>6 ORDER BY id_garantia_tienda");
    ensure($account !== [] && $cash !== [] && $guarantee !== [], 'La copia necesita una garantia disponible y cuentas con saldo.');
    $rows = query($pdo, "EXEC dbo.msp_garantia_tienda_devolver_operativa
        @id_garantia_tienda=?,@id_cuenta_tesoreria=?,@fecha_devolucion='20261006',@monto_devolucion=1,
        @medio_devolucion=?,@beneficiario=N'PRUEBA AISLADA',@banco_destino=N'PRUEBA',
        @cuenta_destino=N'PRUEBA',@referencia_transferencia=N'PRUEBA AISLADA',
        @id_usuario=1,@motivo_autorizacion=N'Validacion aislada con rollback',@id_usuario_autoriza=1",
        [(int) $guarantee[0]['id_garantia_tienda'], (int) $account[0]['id_cuenta_tesoreria'], $medium]);
    return ['refund' => (int) $rows[0]['id_devolucion_garantia'], 'cash' => (int) $cash[0]['id_cuenta_tesoreria']];
}

function registerPair(PDO $pdo, array $fixture): array
{
    return query($pdo, 'EXEC dbo.msp_garantia_devolucion_registrar_caja_admin @id_devolucion_garantia=?,@id_cuenta_caja=?,@id_usuario=1',
        [$fixture['refund'], $fixture['cash']])[0];
}

function test(string $label, callable $callback): void
{
    global $pdo, $passed, $failed;
    try {
        $callback();
        ++$passed;
        echo "OK: {$label}\n";
    } catch (Throwable $error) {
        ++$failed;
        echo "FALLO: {$label}: {$error->getMessage()}\n";
    } finally {
        rollbackFixture($pdo);
    }
}

function rejection(string $label, callable $callback, int $expected): void
{
    test($label, static function () use ($callback, $expected): void {
        try {
            $callback();
        } catch (PDOException $error) {
            ensure((int) ($error->errorInfo[1] ?? 0) === $expected, 'Codigo de rechazo inesperado: ' . $error->getMessage());
            return;
        }
        throw new RuntimeException('Se acepto una operacion que debia rechazarse.');
    });
}

$initial = financialSnapshot($pdo);
$adminInitial = query($pdo, 'SELECT COUNT_BIG(*) AS n FROM dbo.msp_garantia_devolucion_caja_admin')[0];

test('Pareja E/S completa, efecto real cero y sin segundo asiento/devolucion', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    $before = financialSnapshot($pdo);
    $result = registerPair($pdo, $fixture);
    $rows = query($pdo, 'SELECT * FROM dbo.msp_vw_garantia_devolucion_caja_admin WHERE id_registro_caja_admin=? ORDER BY orden_movimiento', [$result['id_registro_caja_admin']]);
    ensure(count($rows) === 2 && $rows[0]['naturaleza'] === 'E' && $rows[1]['naturaleza'] === 'S', 'La pareja no esta completa/ordenada.');
    foreach ($rows as $row) {
        ensure((string) $row['monto'] === '1.00' && (string) $row['impacto_efectivo'] === '.00', 'Monto o impacto inesperados.');
        ensure((int) $row['afecta_saldo_caja'] === 0 && (int) $row['es_administrativo'] === 1, 'Debe estar claramente identificado como administrativo.');
        ensure($row['etiqueta'] === 'Sin movimiento de efectivo' && $row['estado_movimiento'] === 'VIGENTE', 'Etiqueta/estado incorrectos.');
    }
    ensure(financialSnapshot($pdo) === $before, 'Se alteraron saldos o tablas financieras.');
    ensure((int) query($pdo, 'SELECT @@TRANCOUNT AS n')[0]['n'] === 1, 'El servicio confirmo la transaccion exterior.');
});

test('Reintento devuelve el mismo ID, conserva autor y no duplica', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    $one = registerPair($pdo, $fixture);
    $two = query($pdo, 'EXEC dbo.msp_garantia_devolucion_registrar_caja_admin @id_devolucion_garantia=?,@id_cuenta_caja=?,@id_usuario=2', [$fixture['refund'], $fixture['cash']])[0];
    ensure($one['id_registro_caja_admin'] === $two['id_registro_caja_admin'], 'El reintento genero otro registro.');
    $rows = query($pdo, 'SELECT id_usuario FROM dbo.msp_garantia_devolucion_caja_admin WHERE id_devolucion_garantia=?', [$fixture['refund']]);
    ensure(count($rows) === 1 && (int) $rows[0]['id_usuario'] === 1, 'Se duplico o sobrescribio la auditoria.');
});

test('Anulacion del origen anula ambas lineas sin borrar auditoria', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    registerPair($pdo, $fixture);
    // Solo comprueba la propagacion de estado; la integracion de reversas es el punto 9.
    query($pdo, "UPDATE dbo.msp_garantia_devoluciones SET estado_devolucion=N'ANULADA' WHERE id_devolucion_garantia=?;
        UPDATE dbo.msp_tesoreria_movimientos SET estado_movimiento=N'ANULADO' WHERE id_devolucion_garantia=?",
        [$fixture['refund'], $fixture['refund']]);
    $rows = query($pdo, 'SELECT estado_movimiento FROM dbo.msp_vw_garantia_devolucion_caja_admin WHERE id_devolucion_garantia=?', [$fixture['refund']]);
    ensure(count($rows) === 2 && array_unique(array_column($rows, 'estado_movimiento')) === ['ANULADO'], 'La reversa dejo una linea vigente.');
});

if (array_key_exists('check-reversal', $options)) {
    test('Diagnostico adicional del procedimiento de reversa existente (punto 9)', static function () use ($pdo): void {
        $fixture = fixture($pdo);
        registerPair($pdo, $fixture);
        query($pdo, "EXEC dbo.msp_garantia_tienda_revertir_operacion @tipo_origen=N'DEVOLUCION',@id_origen=?,
            @fecha_reversa='20261006',@motivo=N'Reversa de prueba aislada',@id_usuario=1", [$fixture['refund']]);
        $rows = query($pdo, 'SELECT estado_movimiento FROM dbo.msp_vw_garantia_devolucion_caja_admin WHERE id_devolucion_garantia=?', [$fixture['refund']]);
        ensure(count($rows) === 2 && array_unique(array_column($rows, 'estado_movimiento')) === ['ANULADO'], 'La reversa dejo una linea vigente.');
    });
}

test('Cambio posterior de origen se senala como inconsistente', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    registerPair($pdo, $fixture);
    query($pdo, 'UPDATE dbo.msp_garantia_devoluciones SET monto_devolucion=2 WHERE id_devolucion_garantia=?', [$fixture['refund']]);
    $rows = query($pdo, 'SELECT estado_movimiento FROM dbo.msp_vw_garantia_devolucion_caja_admin WHERE id_devolucion_garantia=?', [$fixture['refund']]);
    ensure(count($rows) === 2 && array_unique(array_column($rows, 'estado_movimiento')) === ['INCONSISTENTE'], 'Se oculto una inconsistencia del origen.');
});

rejection('No admite devolucion en efectivo (ya tiene egreso real en caja)', static function () use ($pdo): void {
    registerPair($pdo, fixture($pdo, 'EFECTIVO'));
}, 53904);

rejection('No admite devolucion inexistente', static function () use ($pdo): void {
    $pdo->exec('BEGIN TRANSACTION;');
    registerPair($pdo, ['refund' => 2147483647, 'cash' => 1]);
}, 53904);

rejection('No admite origen bancario anulado', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    query($pdo, "UPDATE dbo.msp_tesoreria_movimientos SET estado_movimiento=N'ANULADO' WHERE id_devolucion_garantia=?", [$fixture['refund']]);
    registerPair($pdo, $fixture);
}, 53905);

rejection('No admite monto bancario diferente', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    query($pdo, 'UPDATE dbo.msp_tesoreria_movimientos SET monto=2 WHERE id_devolucion_garantia=?', [$fixture['refund']]);
    registerPair($pdo, $fixture);
}, 53905);

rejection('No admite fecha bancaria diferente', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    query($pdo, "UPDATE dbo.msp_tesoreria_movimientos SET fecha_movimiento='20261007' WHERE id_devolucion_garantia=?", [$fixture['refund']]);
    registerPair($pdo, $fixture);
}, 53905);

rejection('No admite usuario sin identificar', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    query($pdo, 'EXEC dbo.msp_garantia_devolucion_registrar_caja_admin @id_devolucion_garantia=?,@id_cuenta_caja=?,@id_usuario=NULL', [$fixture['refund'], $fixture['cash']]);
}, 53903);

rejection('No admite banco como caja administrativa', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    $fixture['cash'] = (int) query($pdo, "SELECT TOP(1) id_cuenta_tesoreria FROM dbo.msp_tesoreria_cuentas WHERE tipo_cuenta=N'BANCO'")[0]['id_cuenta_tesoreria'];
    registerPair($pdo, $fixture);
}, 53907);

rejection('No admite caja inactiva', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    query($pdo, 'UPDATE dbo.msp_tesoreria_cuentas SET activo=0 WHERE id_cuenta_tesoreria=?', [$fixture['cash']]);
    registerPair($pdo, $fixture);
}, 53907);

rejection('No admite caja con otra moneda', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    query($pdo, "UPDATE dbo.msp_tesoreria_cuentas SET moneda='USD' WHERE id_cuenta_tesoreria=?", [$fixture['cash']]);
    registerPair($pdo, $fixture);
}, 53907);

rejection('No cambia la caja de una pareja existente', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    registerPair($pdo, $fixture);
    query($pdo, "INSERT dbo.msp_tesoreria_cuentas(codigo_cuenta,nombre_cuenta,tipo_cuenta) VALUES(N'PRUEBA_ADMIN',N'Prueba aislada',N'CAJA')");
    $fixture['cash'] = (int) query($pdo, "SELECT id_cuenta_tesoreria FROM dbo.msp_tesoreria_cuentas WHERE codigo_cuenta=N'PRUEBA_ADMIN'")[0]['id_cuenta_tesoreria'];
    registerPair($pdo, $fixture);
}, 53906);

rejection('INSERT directo no evade la comprobacion de origen', static function () use ($pdo): void {
    $fixture = fixture($pdo);
    query($pdo, "INSERT dbo.msp_garantia_devolucion_caja_admin(id_devolucion_garantia,id_movimiento_tesoreria_origen,id_cuenta_caja,fecha_movimiento,monto,id_usuario)
        SELECT id_devolucion_garantia,id_movimiento_tesoreria,?,'20261006',2,1 FROM dbo.msp_tesoreria_movimientos WHERE id_devolucion_garantia=?",
        [$fixture['cash'], $fixture['refund']]);
}, 53902);

foreach (['UPDATE' => 'SET monto=2', 'DELETE' => ''] as $verb => $clause) {
    rejection($verb . ' no altera el historial administrativo', static function () use ($pdo, $verb, $clause): void {
        $fixture = fixture($pdo);
        registerPair($pdo, $fixture);
        query($pdo, $verb . ($verb === 'DELETE' ? ' FROM' : '') . ' dbo.msp_garantia_devolucion_caja_admin ' . $clause . ' WHERE id_devolucion_garantia=?', [$fixture['refund']]);
    }, 53901);
}

test('Pruebas revertidas: todos los saldos y conteos originales intactos', static function () use ($pdo, $initial, $adminInitial): void {
    ensure(financialSnapshot($pdo) === $initial, 'Una prueba dejo cambios financieros.');
    ensure(query($pdo, 'SELECT COUNT_BIG(*) AS n FROM dbo.msp_garantia_devolucion_caja_admin')[0] === $adminInitial, 'Quedaron registros administrativos de prueba.');
});

echo "Resultado: {$passed} OK, {$failed} fallos. Copia aislada: {$database}.\n";
exit($failed > 0 ? 1 : 0);
