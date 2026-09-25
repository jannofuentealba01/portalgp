<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/msp/bootstrap.php';
require_once dirname(__DIR__) . '/msp/cobros/services/OperacionMensualService.php';

function waterFormulaAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$testDatabase = trim((string) getenv('MSP_TEST_DB'));
$allowCurrentDatabase = getenv('MSP_ALLOW_CURRENT_DB_ROLLBACK_TEST') === '1';
waterFormulaAssert(
    $testDatabase !== ''
        && ($allowCurrentDatabase || strtoupper($testDatabase) !== 'PORTALGP')
        && preg_match('/^[A-Za-z0-9_]+$/', $testDatabase) === 1,
    'La prueba requiere una base aislada o MSP_ALLOW_CURRENT_DB_ROLLBACK_TEST=1.'
);
$dbConfig = require dirname(__DIR__) . '/config/database.php';
$server = (string) ($dbConfig['server'] ?? 'localhost');
$user = (string) ($dbConfig['username'] ?? '');
$password = (string) ($dbConfig['password'] ?? '');
$dsn = 'sqlsrv:Server=' . $server . ';Database=' . $testDatabase . ';Encrypt=0';
$conn = $user === '' ? new PDO($dsn) : new PDO($dsn, $user, $password);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$idCierreStmt = $conn->query(
    "SELECT TOP(1) id_cierre_mensual
     FROM dbo.msp_cierre_mensual
     WHERE periodo_facturacion='20260401'
     ORDER BY id_cierre_mensual DESC"
);
$idCierre = (int) ($idCierreStmt->fetchColumn() ?: 0);
waterFormulaAssert($idCierre > 0, 'No existe el período abril 2026.');

$readAmounts = static function (PDO $db): array {
    $stmt = $db->query(
        "SELECT l.cdo_local,cs.monto_total,cs.subtotal_variable,cs.cargo_fijo,cs.formula_version
         FROM dbo.msp_lecturas_medidores lm
         INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
         INNER JOIN dbo.msp_locales l ON l.id_local=m.id_local
         INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro=lm.id_proceso_cobro
         INNER JOIN dbo.msp_cierre_mensual cm ON cm.id_cierre_mensual=p.id_cierre_mensual
         INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=p.id_tipo_servicio
         INNER JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
         WHERE cm.periodo_facturacion='20260401' AND UPPER(ts.codigo_servicio)=N'AGUA'
         ORDER BY l.cdo_local"
    );
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $result[(string) $row['cdo_local']] = $row;
    }
    return $result;
};

$before = $readAmounts($conn);
$expected = ['OBRA'=>109106.70, 'MODULAR'=>4078.04, 'GYM'=>17543.26];
waterFormulaAssert(count($before) === 3, 'La prueba esperaba exactamente los tres medidores del Excel.');

$conn->beginTransaction();
try {
    $openStmt = $conn->prepare(
        'UPDATE dbo.msp_cierre_mensual SET estado_cierre=1 WHERE id_cierre_mensual=:id'
    );
    $openStmt->execute([':id'=>$idCierre]);
    OperacionMensualService::generarCobros($conn, $idCierre, false, ['AGUA']);
    $during = $readAmounts($conn);
    foreach ($expected as $local => $amount) {
        waterFormulaAssert(isset($during[$local]), 'Falta el local ' . $local . '.');
        waterFormulaAssert(
            abs((float) $during[$local]['monto_total'] - $amount) <= 0.01,
            'El total de ' . $local . ' no coincide con el Excel.'
        );
        waterFormulaAssert(
            abs((float) $during[$local]['cargo_fijo'] - 1385.00) <= 0.01,
            'El cargo fijo de ' . $local . ' no se aplicó completo.'
        );
        waterFormulaAssert(
            (string) $during[$local]['formula_version'] === 'V3_AGUA_EXCEL_2604',
            'La versión de fórmula de ' . $local . ' no fue registrada.'
        );
    }
    $conn->rollBack();

    $after = $readAmounts($conn);
    foreach ($before as $local => $row) {
        waterFormulaAssert(
            isset($after[$local])
                && abs((float) $after[$local]['monto_total'] - (float) $row['monto_total']) <= 0.01,
            'El rollback no restauró el cobro de ' . $local . '.'
        );
    }
    echo "OK: fórmula mensual de agua validada contra OBRA, MODULAR y GYM con rollback.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
