<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/msp/bootstrap.php';
require_once dirname(__DIR__) . '/msp/services/CorreccionesService.php';

function rentDocumentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$testDatabase = trim((string) getenv('MSP_TEST_DB'));
$allowCurrentDatabase = getenv('MSP_ALLOW_CURRENT_DB_ROLLBACK_TEST') === '1';
rentDocumentAssert(
    $testDatabase !== ''
        && ($allowCurrentDatabase || strtoupper($testDatabase) !== 'PORTALGP')
        && preg_match('/^[A-Za-z0-9_]+$/', $testDatabase) === 1,
    'La prueba requiere una base aislada o MSP_ALLOW_CURRENT_DB_ROLLBACK_TEST=1.'
);
$dbConfig = require dirname(__DIR__) . '/config/database.php';
$dsn = 'sqlsrv:Server=' . (string) ($dbConfig['server'] ?? 'localhost')
    . ';Database=' . $testDatabase . ';Encrypt=0';
$conn = ((string) ($dbConfig['username'] ?? '')) === ''
    ? new PDO($dsn)
    : new PDO($dsn, (string) $dbConfig['username'], (string) ($dbConfig['password'] ?? ''));
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
rentDocumentAssert(
    strtoupper((string) $conn->query('SELECT DB_NAME()')->fetchColumn()) === strtoupper($testDatabase),
    'Base de prueba incorrecta.'
);

$candidate = $conn->query(
    "SELECT TOP(1) s.id_snapshot_arriendo,s.id_contrato_arriendo,s.id_tienda,s.id_local,
        s.periodo_facturacion,s.valor_base_uf,s.valor_uf_periodo,s.monto_neto_clp,
        dc.id_documento_cobro,dc.monto_total monto_documento,dc.saldo_pendiente,
        d.id_detalle_documento,d.subtotal subtotal_detalle,
        (SELECT COUNT(*) FROM dbo.msp_acc_asientos a
         WHERE a.tabla_origen=N'msp_documentos_cobro'
           AND a.id_origen=dc.id_documento_cobro AND a.estado_asiento=1) asientos_activos
     FROM dbo.msp_arriendo_local_snapshot_periodo s
     INNER JOIN dbo.msp_tipo_modalidad_arriendo tm ON tm.id_modalidad_arriendo=s.id_modalidad_aplicada
     INNER JOIN dbo.msp_locales l ON l.id_local=s.id_local
     INNER JOIN dbo.msp_documentos_cobro dc
        ON dc.id_contrato_arriendo=s.id_contrato_arriendo
       AND dc.periodo_facturacion=s.periodo_facturacion AND dc.estado_documento<>5
     INNER JOIN dbo.msp_documentos_cobro_detalle d
        ON d.id_documento_cobro=dc.id_documento_cobro
       AND d.descripcion_item=CONCAT(N'Arriendo local ',l.cdo_local)
     INNER JOIN dbo.msp_tipo_item_documento ti
        ON ti.id_tipo_item_documento=d.id_tipo_item_documento AND ti.codigo_item=N'ARRIENDO'
     WHERE s.estado_snapshot IN(1,2,3)
       AND tm.codigo_modalidad IN(N'UF_ESTATICO',N'DINAMICO_MENSUAL')
       AND ABS(dc.monto_total-dc.saldo_pendiente)<0.01
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_pagos x WHERE x.id_documento_cobro=dc.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_envio_lote_documentos x WHERE x.id_documento_cobro=dc.id_documento_cobro)
       AND NOT EXISTS(SELECT 1 FROM dbo.msp_movimientos_garantia x WHERE x.id_documento_cobro=dc.id_documento_cobro)
     ORDER BY CASE WHEN EXISTS(
        SELECT 1 FROM dbo.msp_acc_asientos a
        WHERE a.tabla_origen=N'msp_documentos_cobro'
          AND a.id_origen=dc.id_documento_cobro AND a.estado_asiento=1
     ) THEN 0 ELSE 1 END,s.periodo_facturacion DESC,s.id_snapshot_arriendo"
)->fetch(PDO::FETCH_ASSOC);
rentDocumentAssert(is_array($candidate), 'No existe un arriendo documentado sin dependencias para la prueba reversible.');
rentDocumentAssert((int) $candidate['asientos_activos'] > 0, 'La prueba requiere un documento con asiento activo.');

$userId = (int) ($conn->query("SELECT TOP(1) id FROM dbo.cr_usuarios WHERE UserName=N'admin_2'")->fetchColumn() ?: 0);
rentDocumentAssert($userId > 0, 'No se encontró el usuario de prueba.');

$ufAnterior = (float) $candidate['valor_base_uf'];
$ufNueva = round($ufAnterior + 0.01, 6);
$ufPeriodo = (float) $candidate['valor_uf_periodo'];
$netoAnterior = (float) $candidate['monto_neto_clp'];
$netoNuevo = round($ufNueva * $ufPeriodo, 2);
$deltaTotalEsperado = round(($netoNuevo - $netoAnterior) * 1.19, 2);

$conn->beginTransaction();
try {
    $correctionId = CorreccionesService::crearSolicitud($conn, [
        'tipo_correccion' => 'ARRIENDO_PERIODO',
        'modulo_origen' => 'tests/msp_rent_uf_document_correction_rollback.php',
        'periodo_facturacion' => substr((string) $candidate['periodo_facturacion'], 0, 7),
        'id_contrato_arriendo' => (int) $candidate['id_contrato_arriendo'],
        'id_tienda' => (int) $candidate['id_tienda'],
        'id_local' => (int) $candidate['id_local'],
        'entidad_afectada' => 'arriendo',
        'id_registro_origen' => (int) $candidate['id_snapshot_arriendo'],
        'estado_correccion' => 'BORRADOR',
        'nivel_correcion' => 'AUTORIZACION',
        'valor_anterior' => [
            'monto_neto_clp' => $netoAnterior,
            'valor_base_uf' => $ufAnterior,
        ],
        'valor_nuevo' => $netoNuevo,
        'motivo' => 'Prueba reversible UF Base con documento',
        'resultado_analisis' => [
            'registro_exacto' => [
                'unidad_correccion' => 'UF_BASE',
                'valor_uf_base_anterior' => $ufAnterior,
                'valor_uf_base_nuevo' => $ufNueva,
                'valor_uf_periodo_correccion' => $ufPeriodo,
                'id_documento_cobro' => (int) $candidate['id_documento_cobro'],
            ],
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
    rentDocumentAssert(
        in_array((int) $candidate['id_documento_cobro'], array_map('intval', (array) ($result['documentos_actualizados'] ?? [])), true),
        'El servicio no informó el documento actualizado.'
    );

    $qSnapshot = $conn->prepare(
        'SELECT valor_base_uf,monto_neto_clp FROM dbo.msp_arriendo_local_snapshot_periodo
         WHERE id_snapshot_arriendo=:snapshot'
    );
    $qSnapshot->execute([':snapshot' => (int) $candidate['id_snapshot_arriendo']]);
    $duringSnapshot = $qSnapshot->fetch(PDO::FETCH_ASSOC);
    rentDocumentAssert(abs((float) $duringSnapshot['valor_base_uf'] - $ufNueva) < 0.000001, 'La UF Base no se actualizó.');
    rentDocumentAssert(abs((float) $duringSnapshot['monto_neto_clp'] - $netoNuevo) < 0.01, 'El neto no se actualizó.');

    $qDoc = $conn->prepare(
        'SELECT monto_total,saldo_pendiente,
            (SELECT COUNT(*) FROM dbo.msp_acc_asientos a
             WHERE a.tabla_origen=N\'msp_documentos_cobro\'
               AND a.id_origen=d.id_documento_cobro AND a.estado_asiento=1) asientos_activos
         FROM dbo.msp_documentos_cobro d WHERE id_documento_cobro=:documento'
    );
    $qDoc->execute([':documento' => (int) $candidate['id_documento_cobro']]);
    $duringDoc = $qDoc->fetch(PDO::FETCH_ASSOC);
    rentDocumentAssert(
        abs((float) $duringDoc['monto_total'] - ((float) $candidate['monto_documento'] + $deltaTotalEsperado)) < 0.01,
        'El total del documento no fue recalculado.'
    );
    rentDocumentAssert((int) $duringDoc['asientos_activos'] === 1, 'La contabilidad no quedó con un único asiento activo.');

    $qDetalle = $conn->prepare(
        'SELECT subtotal FROM dbo.msp_documentos_cobro_detalle WHERE id_detalle_documento=:detalle'
    );
    $qDetalle->execute([':detalle' => (int) $candidate['id_detalle_documento']]);
    rentDocumentAssert(abs((float) $qDetalle->fetchColumn() - $netoNuevo) < 0.01, 'La línea del PDF no se actualizó.');

    $qImpactos = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_correcciones_impactos WHERE id_correccion=:correccion');
    $qImpactos->execute([':correccion' => $correctionId]);
    rentDocumentAssert((int) $qImpactos->fetchColumn() >= 3, 'No se registraron todos los impactos esperados.');

    $zeroCorrectionId = CorreccionesService::crearSolicitud($conn, [
        'tipo_correccion' => 'ARRIENDO_PERIODO',
        'modulo_origen' => 'tests/msp_rent_uf_document_correction_rollback.php',
        'periodo_facturacion' => substr((string) $candidate['periodo_facturacion'], 0, 7),
        'id_contrato_arriendo' => (int) $candidate['id_contrato_arriendo'],
        'id_tienda' => (int) $candidate['id_tienda'],
        'id_local' => (int) $candidate['id_local'],
        'entidad_afectada' => 'arriendo',
        'id_registro_origen' => (int) $candidate['id_snapshot_arriendo'],
        'estado_correccion' => 'BORRADOR',
        'nivel_correcion' => 'AUTORIZACION',
        'valor_anterior' => ['monto_neto_clp' => $netoNuevo, 'valor_base_uf' => $ufNueva],
        'valor_nuevo' => 0,
        'motivo' => 'Prueba reversible de arriendo cero con servicios',
        'resultado_analisis' => [
            'registro_exacto' => [
                'unidad_correccion' => 'UF_BASE',
                'valor_uf_base_anterior' => $ufNueva,
                'valor_uf_base_nuevo' => 0,
                'valor_uf_periodo_correccion' => $ufPeriodo,
                'id_documento_cobro' => (int) $candidate['id_documento_cobro'],
                'cero_autorizado' => 1,
            ],
            'clasificacion' => ['nivel' => 'AUTORIZACION'],
        ],
    ], $userId);
    CorreccionesService::cambiarEstado($conn, $zeroCorrectionId, 'APROBADA', $userId, 'Prueba temporal de arriendo cero.');
    CorreccionesService::ejecutar($conn, $zeroCorrectionId, $userId);
    $qSnapshot->execute([':snapshot' => (int) $candidate['id_snapshot_arriendo']]);
    $zeroSnapshot = $qSnapshot->fetch(PDO::FETCH_ASSOC);
    rentDocumentAssert(abs((float) $zeroSnapshot['valor_base_uf']) < 0.000001, 'La UF Base cero no fue conservada.');
    rentDocumentAssert(abs((float) $zeroSnapshot['monto_neto_clp']) < 0.01, 'El neto cero no fue conservado.');
    $qDetalle->execute([':detalle' => (int) $candidate['id_detalle_documento']]);
    rentDocumentAssert($qDetalle->fetchColumn() === false, 'La línea de arriendo cero no fue retirada del PDF.');
    $qDoc->execute([':documento' => (int) $candidate['id_documento_cobro']]);
    $zeroDoc = $qDoc->fetch(PDO::FETCH_ASSOC);
    rentDocumentAssert((int) $zeroDoc['asientos_activos'] === 1, 'El documento de servicios no conservó un asiento activo.');

    $conn->rollBack();
    $qSnapshot->execute([':snapshot' => (int) $candidate['id_snapshot_arriendo']]);
    $afterSnapshot = $qSnapshot->fetch(PDO::FETCH_ASSOC);
    $qDoc->execute([':documento' => (int) $candidate['id_documento_cobro']]);
    $afterDoc = $qDoc->fetch(PDO::FETCH_ASSOC);
    rentDocumentAssert(abs((float) $afterSnapshot['valor_base_uf'] - $ufAnterior) < 0.000001, 'El rollback no restauró la UF Base.');
    rentDocumentAssert(abs((float) $afterDoc['monto_total'] - (float) $candidate['monto_documento']) < 0.01, 'El rollback no restauró el documento.');
    rentDocumentAssert((int) $afterDoc['asientos_activos'] === (int) $candidate['asientos_activos'], 'El rollback no restauró la contabilidad.');

    echo "OK: UF Base, arriendo cero, documento de servicios, PDF, trazabilidad, contabilidad y rollback validados.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
