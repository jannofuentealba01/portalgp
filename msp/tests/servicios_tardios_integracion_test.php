<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/cobros/support/OperacionMensualCommon.php';
require_once dirname(__DIR__) . '/cobros/services/PoolDocumentosPeriodoService.php';
require_once dirname(__DIR__) . '/cobros/services/DocumentosCobroService.php';

function assertLateService(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn->beginTransaction();
try {
    $fixtureStmt = $conn->query(
        "SELECT TOP (1)
            ca.id_contrato_arriendo,
            ca.id_tienda,
            cl.id_contrato_local,
            m.id_medidor,
            m.id_tipo_servicio,
            cm.id_cierre_mensual,
            cm.periodo_facturacion
         FROM dbo.msp_contratos_arriendo ca
         INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
         INNER JOIN dbo.msp_medidores m
            ON m.id_local = cl.id_local
         CROSS JOIN dbo.msp_cierre_mensual cm
         WHERE ca.estado_contrato IN (1,2)
           AND cm.periodo_facturacion = CONVERT(date, '20260401', 112)
           AND NOT EXISTS (
                SELECT 1
                FROM dbo.msp_liquidacion_servicios ls
                WHERE ls.id_contrato_local = cl.id_contrato_local
                  AND ls.id_medidor = m.id_medidor
           )
         ORDER BY ca.id_contrato_arriendo, cl.id_contrato_local, m.id_medidor"
    );
    $fixture = $fixtureStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    assertLateService(is_array($fixture), 'No existe una combinación segura para la prueba transaccional.');

    $idContrato = (int) $fixture['id_contrato_arriendo'];
    $idTienda = (int) $fixture['id_tienda'];
    $idContratoLocal = (int) $fixture['id_contrato_local'];
    $idMedidor = (int) $fixture['id_medidor'];
    $idTipoServicio = (int) $fixture['id_tipo_servicio'];
    $idCierre = (int) $fixture['id_cierre_mensual'];
    $periodo = (string) $fixture['periodo_facturacion'];

    $conn->prepare(
        "UPDATE dbo.msp_contratos_arriendo
         SET estado_contrato = 3,
             fecha_termino_efectiva = CONVERT(date, '20260228', 112)
         WHERE id_contrato_arriendo = :id"
    )->execute([':id' => $idContrato]);
    $conn->prepare(
        "UPDATE dbo.msp_contrato_locales
         SET fecha_termino = CONVERT(date, '20260228', 112), estado_relacion = 2
         WHERE id_contrato_arriendo = :id"
    )->execute([':id' => $idContrato]);

    $insertServicio = $conn->prepare(
        "INSERT INTO dbo.msp_liquidacion_servicios
            (id_contrato_local, id_tipo_servicio, id_medidor, fecha_termino_operativo, estado_liquidacion, observaciones)
         OUTPUT INSERTED.id_liquidacion_servicio
         VALUES
            (:id_contrato_local, :id_tipo_servicio, :id_medidor, CONVERT(date, '20260228', 112), 2, N'Prueba transaccional')"
    );
    $insertServicio->execute([
        ':id_contrato_local' => $idContratoLocal,
        ':id_tipo_servicio' => $idTipoServicio,
        ':id_medidor' => $idMedidor,
    ]);
    $idServicio = (int) $insertServicio->fetchColumn();
    assertLateService($idServicio > 0, 'No se creó el servicio de prueba.');

    $referencia = 'TEST-TARDIO-' . bin2hex(random_bytes(5));
    $insertConsumo = $conn->prepare(
        "INSERT INTO dbo.msp_liquidacion_servicio_consumos
            (id_liquidacion_servicio, periodo_emision, fecha_desde_consumo, fecha_hasta_consumo,
             referencia_origen, consumo_asignado, monto_asignado, tipo_asignacion, estado_consumo, observaciones)
         OUTPUT INSERTED.id_consumo_liquidacion
         VALUES
            (:id_servicio, :periodo, CONVERT(date, '20260201', 112), CONVERT(date, '20260228', 112),
             :referencia, 10, 12345, 2, 1, N'Prueba transaccional')"
    );
    $insertConsumo->execute([
        ':id_servicio' => $idServicio,
        ':periodo' => $periodo,
        ':referencia' => $referencia,
    ]);
    $idConsumo = (int) $insertConsumo->fetchColumn();
    assertLateService($idConsumo > 0, 'No se creó el consumo de prueba.');

    PoolDocumentosPeriodoService::syncPeriodo($conn, $periodo);
    $poolStmt = $conn->prepare(
        'SELECT estado_pool FROM dbo.msp_pool_documentos_periodo
         WHERE periodo_facturacion = :periodo AND id_tienda = :id_tienda AND id_contrato_arriendo = :id_contrato'
    );
    $poolStmt->execute([':periodo' => $periodo, ':id_tienda' => $idTienda, ':id_contrato' => $idContrato]);
    assertLateService(in_array((int) $poolStmt->fetchColumn(), [2,3], true), 'El consumo tardío no quedó listo en el pool.');

    DocumentosCobroService::generateDocumentsForCierre($conn, $idCierre, 5, 1, true, 'ALL', [$idTienda]);
    $verifyStmt = $conn->prepare(
        'SELECT c.estado_consumo, c.id_documento_cobro, d.id_detalle_documento, d.subtotal
         FROM dbo.msp_liquidacion_servicio_consumos c
         LEFT JOIN dbo.msp_documentos_cobro_detalle d
            ON d.id_consumo_liquidacion = c.id_consumo_liquidacion
         WHERE c.id_consumo_liquidacion = :id_consumo'
    );
    $verifyStmt->execute([':id_consumo' => $idConsumo]);
    $emision = $verifyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    assertLateService((int) ($emision['estado_consumo'] ?? 0) === 2, 'El consumo no quedó emitido.');
    assertLateService((int) ($emision['id_documento_cobro'] ?? 0) > 0, 'El consumo no quedó vinculado al documento.');
    assertLateService((int) ($emision['id_detalle_documento'] ?? 0) > 0, 'No se creó el detalle de servicio tardío.');
    assertLateService(abs((float) ($emision['subtotal'] ?? 0) - 12345.0) < 0.005, 'El monto emitido no coincide.');

    DocumentosCobroService::generateDocumentsForCierre($conn, $idCierre, 5, 1, true, 'ALL', [$idTienda]);
    $duplicateStmt = $conn->prepare(
        'SELECT COUNT(*) FROM dbo.msp_documentos_cobro_detalle WHERE id_consumo_liquidacion = :id_consumo'
    );
    $duplicateStmt->execute([':id_consumo' => $idConsumo]);
    assertLateService((int) $duplicateStmt->fetchColumn() === 1, 'La regeneración duplicó el consumo tardío.');

    $conn->rollBack();
    echo "OK: emisión y regeneración de servicio tardío sin duplicados.\n";
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
