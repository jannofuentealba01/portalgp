<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/msp/bootstrap.php';
require_once dirname(__DIR__) . '/msp/services/CorreccionesService.php';

function rentUfAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$testDatabase = trim((string) getenv('MSP_TEST_DB'));
$allowCurrentDatabase = getenv('MSP_ALLOW_CURRENT_DB_ROLLBACK_TEST') === '1';
rentUfAssert(
    $testDatabase !== ''
        && ($allowCurrentDatabase || strtoupper($testDatabase) !== 'PORTALGP')
        && preg_match('/^[A-Za-z0-9_]+$/', $testDatabase) === 1,
    'La prueba requiere una base aislada o MSP_ALLOW_CURRENT_DB_ROLLBACK_TEST=1.'
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
rentUfAssert(strtoupper((string) $conn->query('SELECT DB_NAME()')->fetchColumn()) === strtoupper($testDatabase), 'Base de prueba incorrecta.');

$base = $conn->query(
    "SELECT TOP(1) s.*
     FROM dbo.msp_arriendo_local_snapshot_periodo s
     INNER JOIN dbo.msp_tipo_modalidad_arriendo tm ON tm.id_modalidad_arriendo=s.id_modalidad_aplicada
     WHERE tm.codigo_modalidad IN(N'UF_ESTATICO',N'DINAMICO_MENSUAL')
     ORDER BY s.id_snapshot_arriendo"
)->fetch(PDO::FETCH_ASSOC);
rentUfAssert(is_array($base), 'No existe un snapshot UF que pueda utilizarse como base reversible.');

$userId = (int) ($conn->query("SELECT TOP(1) id FROM dbo.cr_usuarios WHERE UserName=N'admin_2'")->fetchColumn() ?: 0);
rentUfAssert($userId > 0, 'No se encontró el usuario de prueba.');

$period = '2099-01-01';
$periodUf = 40000.0;
$oldUf = 10.0;
$newUf = 10.5;
$oldNet = round($oldUf * $periodUf, 2);
$newNet = round($newUf * $periodUf, 2);

$conn->beginTransaction();
try {
    $insert = $conn->prepare(
        "INSERT dbo.msp_arriendo_local_snapshot_periodo(
            periodo_facturacion,id_tienda,id_contrato_arriendo,id_contrato_local,id_local,
            id_regla_arriendo,id_modalidad_aplicada,valor_base_uf,valor_base_clp,valor_uf_periodo,
            descuento_aplicado_clp,monto_neto_clp,monto_iva_clp,monto_total_clp,
            codigo_grupo_modalidad,estado_snapshot,es_congelado,fuente_calculo,
            formula_json,detalle_calculo,fecha_registro,fuente_descuento
        )
        OUTPUT INSERTED.id_snapshot_arriendo
        VALUES(
            :p,:t,:c,:cl,:l,:r,:m,:ub,NULL,:up,0,:n,:i,:total,
            :grupo,1,0,N'PRUEBA_ROLLBACK',NULL,N'Prueba reversible UF Base',SYSDATETIME(),N'SIN_DESCUENTO'
        )"
    );
    $insert->execute([
        ':p' => $period,
        ':t' => (int) $base['id_tienda'],
        ':c' => (int) $base['id_contrato_arriendo'],
        ':cl' => (int) $base['id_contrato_local'],
        ':l' => (int) $base['id_local'],
        ':r' => $base['id_regla_arriendo'] !== null ? (int) $base['id_regla_arriendo'] : null,
        ':m' => (int) $base['id_modalidad_aplicada'],
        ':ub' => $oldUf,
        ':up' => $periodUf,
        ':n' => $oldNet,
        ':i' => round($oldNet * 0.19, 2),
        ':total' => round($oldNet * 1.19, 2),
        ':grupo' => $base['codigo_grupo_modalidad'],
    ]);
    $snapshotId = (int) $insert->fetchColumn();
    rentUfAssert($snapshotId > 0, 'No se creó el snapshot temporal.');

    $correctionId = CorreccionesService::crearSolicitud($conn, [
        'tipo_correccion' => 'ARRIENDO_PERIODO',
        'modulo_origen' => 'tests/msp_rent_uf_correction_rollback.php',
        'periodo_facturacion' => '2099-01',
        'id_contrato_arriendo' => (int) $base['id_contrato_arriendo'],
        'id_tienda' => (int) $base['id_tienda'],
        'id_local' => (int) $base['id_local'],
        'entidad_afectada' => 'arriendo',
        'id_registro_origen' => $snapshotId,
        'estado_correccion' => 'BORRADOR',
        'nivel_correcion' => 'EDICION_SIMPLE',
        'valor_anterior' => ['monto_neto_clp' => $oldNet, 'valor_base_uf' => $oldUf],
        'valor_nuevo' => $newNet,
        'motivo' => 'Prueba automatizada reversible UF Base',
        'resultado_analisis' => [
            'registro_exacto' => [
                'unidad_correccion' => 'UF_BASE',
                'valor_uf_base_anterior' => $oldUf,
                'valor_uf_base_nuevo' => $newUf,
                'valor_uf_periodo_correccion' => $periodUf,
            ],
            'clasificacion' => ['nivel' => 'EDICION_SIMPLE'],
        ],
    ], $userId);
    CorreccionesService::cambiarEstado($conn, $correctionId, 'APROBADA', $userId, 'Aprobación temporal de prueba.');
    $result = CorreccionesService::ejecutar($conn, $correctionId, $userId);
    rentUfAssert(abs((float) ($result['uf_base_nueva'] ?? -1) - $newUf) < 0.000001, 'El servicio no informó la UF corregida.');

    $read = $conn->prepare('SELECT valor_base_uf,monto_neto_clp,monto_iva_clp,monto_total_clp,fuente_calculo FROM dbo.msp_arriendo_local_snapshot_periodo WHERE id_snapshot_arriendo=:s');
    $read->execute([':s' => $snapshotId]);
    $during = $read->fetch(PDO::FETCH_ASSOC);
    rentUfAssert(is_array($during), 'No fue posible releer el snapshot temporal.');
    rentUfAssert(abs((float) $during['valor_base_uf'] - $newUf) < 0.000001, 'La UF Base no fue actualizada.');
    rentUfAssert(abs((float) $during['monto_neto_clp'] - $newNet) < 0.01, 'El neto no fue recalculado.');
    rentUfAssert((string) $during['fuente_calculo'] === 'CORRECCION_SELECTIVA', 'La fuente de cálculo no quedó trazada.');

    $impact = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_correcciones_impactos WHERE id_correccion=:c');
    $impact->execute([':c' => $correctionId]);
    rentUfAssert((int) $impact->fetchColumn() >= 1, 'No se registró el impacto de la corrección.');

    $conn->rollBack();
    rentUfAssert((int) $conn->query('SELECT COUNT(*) FROM dbo.msp_arriendo_local_snapshot_periodo WHERE periodo_facturacion=' . $conn->quote($period) . " AND fuente_calculo=N'PRUEBA_ROLLBACK'")->fetchColumn() === 0, 'El rollback no retiró el snapshot temporal.');
    echo "OK: corrección UF Base, cálculo, trazabilidad y rollback validados.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
