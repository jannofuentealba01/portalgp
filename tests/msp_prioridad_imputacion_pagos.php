<?php
declare(strict_types=1);

/*
 * Prueba transaccional: verifica la imputación automática del SP y revierte
 * todo al final. No deja pagos ni movimientos en la base de datos.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/db.php';

$fallos = [];
$assert = static function (bool $condicion, string $mensaje) use (&$fallos): void {
    echo ($condicion ? '[OK] ' : '[FAIL] ') . $mensaje . PHP_EOL;
    if (!$condicion) $fallos[] = $mensaje;
};

$prioridadesEsperadas = [
    'ARRIENDO' => 10, 'SERVICIO_LUZ' => 20, 'SERVICIO_GAS' => 30,
    'SERVICIO_AGUA' => 40, 'MULTA' => 50, 'DANO' => 60, 'AJUSTE' => 70,
];
$stmtPrioridades = $conn->query('SELECT codigo_item,prioridad FROM dbo.msp_prioridades_imputacion_pago WHERE activo=1');
$prioridadesActuales = [];
foreach ($stmtPrioridades->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $prioridadesActuales[(string) $row['codigo_item']] = (int) $row['prioridad'];
foreach ($prioridadesEsperadas as $codigo => $prioridad) {
    $assert(($prioridadesActuales[$codigo] ?? null) === $prioridad, "Prioridad $codigo = $prioridad");
}

$docStmt = $conn->query(
    "SELECT TOP (1)
        dc.id_documento_cobro,
        ROUND(SUM(CASE WHEN tid.codigo_item=N'ARRIENDO' THEN d.subtotal ELSE 0 END)
            + (dc.monto_total-dc.subtotal_arriendo-dc.subtotal_servicios),2) AS arriendo_disponible,
        ROUND(SUM(CASE WHEN tid.codigo_item=N'SERVICIO_LUZ' THEN d.subtotal ELSE 0 END),2) AS luz_disponible
     FROM dbo.msp_documentos_cobro dc
     INNER JOIN dbo.msp_documentos_cobro_detalle d ON d.id_documento_cobro=dc.id_documento_cobro
     INNER JOIN dbo.msp_tipo_item_documento tid ON tid.id_tipo_item_documento=d.id_tipo_item_documento
     WHERE dc.estado_documento IN (2,3) AND dc.saldo_pendiente>0
       AND NOT EXISTS (SELECT 1 FROM dbo.msp_pagos p WHERE p.id_documento_cobro=dc.id_documento_cobro AND p.estado_pago=1)
     GROUP BY dc.id_documento_cobro,dc.monto_total,dc.subtotal_arriendo,dc.subtotal_servicios
     HAVING SUM(CASE WHEN tid.codigo_item=N'ARRIENDO' THEN d.subtotal ELSE 0 END)
                + (dc.monto_total-dc.subtotal_arriendo-dc.subtotal_servicios) > 1
        AND SUM(CASE WHEN tid.codigo_item=N'SERVICIO_LUZ' THEN d.subtotal ELSE 0 END) > 1
     ORDER BY dc.id_documento_cobro DESC"
);
$doc = $docStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!is_array($doc)) {
    echo "[SKIP] No existe un documento impago con Arriendo y Luz para la prueba transaccional.\n";
} else {
    $montoArriendo = round((float) $doc['arriendo_disponible'], 2);
    $montoLuz = round((float) $doc['luz_disponible'], 2);
    $restoLuz = min(100.00, $montoLuz);
    $montoPrueba = round($montoArriendo + $restoLuz, 2);
    $referencia = 'TEST-PRIORIDAD-ROLLBACK-' . getmypid();

    try {
        $conn->beginTransaction();
        $pago = $conn->prepare(
            'EXEC dbo.msp_registrar_pago_documento
                @id_documento_cobro=:documento,@fecha_pago=:fecha,@monto_pagado=:monto,
                @medio_pago=N\'PRUEBA\',@referencia_pago=:referencia,@observaciones=N\'Prueba automática reversible\',
                @detalle_conceptos_json=NULL'
        );
        $pago->execute([
            ':documento' => (int) $doc['id_documento_cobro'],
            ':fecha' => date('Y-m-d'),
            ':monto' => number_format($montoPrueba, 2, '.', ''),
            ':referencia' => $referencia,
        ]);
        $pago->closeCursor();

        $detalle = $conn->prepare(
            'SELECT tid.codigo_item,pdc.monto_aplicado
             FROM dbo.msp_pagos_detalle_concepto pdc
             INNER JOIN dbo.msp_pagos p ON p.id_pago=pdc.id_pago
             INNER JOIN dbo.msp_tipo_item_documento tid ON tid.id_tipo_item_documento=pdc.id_tipo_item_documento
             WHERE p.referencia_pago=:referencia'
        );
        $detalle->execute([':referencia' => $referencia]);
        $aplicado = [];
        foreach ($detalle->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $aplicado[(string) $row['codigo_item']] = round((float) $row['monto_aplicado'], 2);

        $assert(abs(($aplicado['ARRIENDO'] ?? 0) - $montoArriendo) < 0.01, 'El pago automático completa Arriendo antes de servicios');
        $assert(abs(($aplicado['SERVICIO_LUZ'] ?? 0) - $restoLuz) < 0.01, 'El excedente inmediato se aplica a Luz');
        $assert(!isset($aplicado['SERVICIO_GAS']) && !isset($aplicado['SERVICIO_AGUA']) && !isset($aplicado['MULTA']), 'No salta a Gas, Agua ni cargos posteriores');
    } catch (Throwable $exception) {
        $assert(false, 'Prueba transaccional: ' . $exception->getMessage());
    } finally {
        if ($conn->inTransaction()) $conn->rollBack();
    }

    $persistencia = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_pagos WHERE referencia_pago=:referencia');
    $persistencia->execute([':referencia' => $referencia]);
    $assert((int) $persistencia->fetchColumn() === 0, 'La prueba no dejó pagos persistentes');
}

exit($fallos === [] ? 0 : 1);
