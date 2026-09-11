<?php
declare(strict_types=1);

/*
 * Suite transversal de integridad comercial y financiera de MSP.
 * Es de solo lectura: no crea, modifica ni elimina registros.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/db.php';

$checks = 0;
$failed = 0;
$warnings = 0;

$scalar = static function (string $sql) use ($conn): mixed {
    return $conn->query($sql)->fetchColumn();
};

$check = static function (string $name, callable $test) use (&$checks, &$failed): void {
    $checks++;
    try {
        $result = $test();
        if ($result === true) {
            echo '[OK] ' . $name . PHP_EOL;
            return;
        }
        $failed++;
        echo '[FAIL] ' . $name . ' - ' . (string) $result . PHP_EOL;
    } catch (Throwable $e) {
        $failed++;
        echo '[FAIL] ' . $name . ' - ' . $e->getMessage() . PHP_EOL;
    }
};

$zero = static function (string $name, string $sql) use ($check, $scalar): void {
    $check($name, static function () use ($sql, $scalar): true|string {
        $count = (int) $scalar($sql);
        return $count === 0 ? true : $count . ' caso(s) detectado(s)';
    });
};

$warn = static function (string $name, string $sql) use (&$warnings, $scalar): void {
    $count = (int) $scalar($sql);
    if ($count > 0) {
        $warnings++;
        echo '[WARN] ' . $name . ' - ' . $count . ' caso(s)' . PHP_EOL;
    } else {
        echo '[OK] ' . $name . ' - sin observaciones' . PHP_EOL;
    }
};

echo "== Entorno y maestros ==\n";
$check('La conexión apunta a PORTALGP', static fn(): bool => (string) $scalar('SELECT DB_NAME()') === 'PORTALGP');
$zero('No existen restricciones referenciales incumplidas', "SELECT COUNT(*) FROM (SELECT 1 AS n FROM sys.foreign_keys WHERE is_disabled=1 OR is_not_trusted=1) x");
$zero('Contrato y tienda pertenecen al mismo arrendatario', "SELECT COUNT(*) FROM dbo.msp_contratos_arriendo c JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda WHERE c.id_arrendatario<>t.id_arrendatario");
$zero('No existen RUT duplicados después de normalizar', "SELECT COUNT(*) FROM (SELECT UPPER(REPLACE(REPLACE(REPLACE(LTRIM(RTRIM(rut)),'.',''),'-',''),' ','')) k FROM dbo.msp_arrendatarios GROUP BY UPPER(REPLACE(REPLACE(REPLACE(LTRIM(RTRIM(rut)),'.',''),'-',''),' ','')) HAVING COUNT(*)>1)x");
$zero('No existen códigos de local duplicados después de normalizar', "SELECT COUNT(*) FROM (SELECT UPPER(REPLACE(LTRIM(RTRIM(cdo_local)),' ','')) k FROM dbo.msp_locales GROUP BY UPPER(REPLACE(LTRIM(RTRIM(cdo_local)),' ','')) HAVING COUNT(*)>1)x");
$zero('No existen fechas contractuales invertidas', "SELECT COUNT(*) FROM dbo.msp_contratos_arriendo WHERE COALESCE(fecha_termino_efectiva,fecha_termino_pactada)<fecha_inicio");
$zero('No existen relaciones contrato-local con fechas invertidas', "SELECT COUNT(*) FROM dbo.msp_contrato_locales WHERE fecha_termino<fecha_inicio");
$zero('No existen ocupaciones de un mismo local solapadas', "SELECT COUNT(*) FROM dbo.msp_ocupacion_locales a JOIN dbo.msp_ocupacion_locales b ON b.id_local=a.id_local AND b.id_ocupacion_local>a.id_ocupacion_local AND a.fecha_inicio<=COALESCE(b.fecha_termino,CONVERT(date,'99991231')) AND b.fecha_inicio<=COALESCE(a.fecha_termino,CONVERT(date,'99991231'))");
$zero('No existen medidores con retiro anterior a instalación', "SELECT COUNT(*) FROM dbo.msp_medidores WHERE fecha_retiro<fecha_instalacion");
$zero('Las garantías pertenecen a un local de su contrato', "SELECT COUNT(*) FROM dbo.msp_garantias g WHERE NOT EXISTS(SELECT 1 FROM dbo.msp_contrato_locales cl WHERE cl.id_contrato_arriendo=g.id_contrato_arriendo AND cl.id_local=g.id_local)");

echo "\n== Documentos y cobranza ==\n";
$zero('El detalle de cada documento suma sus subtotales', "SELECT COUNT(*) FROM dbo.msp_documentos_cobro d OUTER APPLY(SELECT SUM(x.subtotal) total FROM dbo.msp_documentos_cobro_detalle x WHERE x.id_documento_cobro=d.id_documento_cobro) q WHERE ABS((d.subtotal_arriendo+d.subtotal_servicios)-ISNULL(q.total,0))>0.01");
$zero('El total documental aplica IVA al arriendo y conserva servicios', "SELECT COUNT(*) FROM dbo.msp_documentos_cobro WHERE ABS(monto_total-ROUND(subtotal_arriendo*1.19+subtotal_servicios,2))>0.01");
$zero('El saldo documental coincide con pagos vigentes', "SELECT COUNT(*) FROM dbo.msp_documentos_cobro d OUTER APPLY(SELECT SUM(p.monto_pagado) aplicado FROM dbo.msp_pagos p WHERE p.id_documento_cobro=d.id_documento_cobro AND p.estado_pago=1)x WHERE ABS(d.saldo_pendiente-CASE WHEN d.monto_total-ISNULL(x.aplicado,0)>0 THEN d.monto_total-ISNULL(x.aplicado,0) ELSE 0 END)>0.01");
$zero('El estado de cobro coincide con el saldo', "SELECT COUNT(*) FROM dbo.msp_documentos_cobro WHERE (estado_documento=4 AND saldo_pendiente>0.01) OR (estado_documento=3 AND (saldo_pendiente<=0.01 OR saldo_pendiente>=monto_total-0.01)) OR (estado_documento=2 AND saldo_pendiente<monto_total-0.01)");
$zero('Todo documento pertenece a la tienda de su contrato', "SELECT COUNT(*) FROM dbo.msp_documentos_cobro d JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=d.id_contrato_arriendo WHERE d.id_tienda<>c.id_tienda");
$zero('Los casos de cobranza conservan un contrato válido', "SELECT COUNT(*) FROM dbo.msp_cobranza_casos c LEFT JOIN dbo.msp_contratos_arriendo a ON a.id_contrato_arriendo=c.id_contrato_arriendo WHERE a.id_contrato_arriendo IS NULL");

echo "\n== Pagos y saldos a favor ==\n";
$zero('Cada pago vigente coincide con su distribución conceptual', "SELECT COUNT(*) FROM dbo.msp_pagos p OUTER APPLY(SELECT SUM(d.monto_aplicado) total FROM dbo.msp_pagos_detalle_concepto d WHERE d.id_pago=p.id_pago)x WHERE p.estado_pago=1 AND ABS(p.monto_pagado-ISNULL(x.total,0))>0.01");
$zero('Las operaciones por contrato cuadran sus cuatro componentes', "SELECT COUNT(*) FROM dbo.msp_pago_contrato_operaciones WHERE ABS(monto_total_pagado-monto_total_aplicado-monto_total_excedente-monto_total_no_imputado)>0.01");
$zero('Las operaciones de pago pertenecen al arrendatario del contrato', "SELECT COUNT(*) FROM dbo.msp_pago_contrato_operaciones o JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=o.id_contrato_arriendo WHERE o.id_arrendatario<>c.id_arrendatario");
$zero('El saldo a favor coincide con su libro de movimientos', "SELECT COUNT(*) FROM dbo.msp_saldos_favor_tienda s OUTER APPLY(SELECT SUM(m.monto_movimiento) total FROM dbo.msp_movimientos_saldo_favor_tienda m WHERE m.id_tienda=s.id_tienda)x WHERE ABS(s.saldo_disponible-ISNULL(x.total,0))>0.01 OR s.saldo_disponible<0");
$zero('Cada excedente vigente tiene un ingreso de saldo a favor', "SELECT COUNT(*) FROM dbo.msp_pagos p LEFT JOIN dbo.msp_movimientos_saldo_favor_tienda m ON m.id_pago=p.id_pago AND m.tipo_movimiento=1 WHERE p.estado_pago=1 AND p.monto_saldo_favor_generado>0 AND (m.id_movimiento_saldo_favor IS NULL OR ABS(m.monto_movimiento-p.monto_saldo_favor_generado)>0.01)");
$zero('Cada uso vigente de saldo tiene su egreso', "SELECT COUNT(*) FROM dbo.msp_pagos p LEFT JOIN dbo.msp_movimientos_saldo_favor_tienda m ON m.id_pago=p.id_pago AND m.tipo_movimiento=2 WHERE p.estado_pago=1 AND p.aplica_desde_saldo_favor=1 AND (m.id_movimiento_saldo_favor IS NULL OR ABS(m.monto_movimiento+p.monto_pagado)>0.01)");
$zero('Ningún saldo por período está sobreaplicado', "SELECT COUNT(*) FROM dbo.msp_saldo_favor_periodo_items i OUTER APPLY(SELECT SUM(a.monto_aplicado) total FROM dbo.msp_saldo_favor_periodo_aplicaciones a WHERE a.id_saldo_favor_periodo_item=i.id_saldo_favor_periodo_item AND a.estado_aplicacion=1)x WHERE ISNULL(x.total,0)>i.monto_original+0.01");
$zero('Las aplicaciones de saldo no cruzan tienda ni documento', "SELECT COUNT(*) FROM dbo.msp_saldo_favor_periodo_aplicaciones a JOIN dbo.msp_saldo_favor_periodo_items i ON i.id_saldo_favor_periodo_item=a.id_saldo_favor_periodo_item JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=a.id_documento_cobro WHERE a.id_tienda<>i.id_tienda OR a.periodo_facturacion<>i.periodo_facturacion OR d.id_tienda<>a.id_tienda");
$zero('Las referencias históricas eliminadas de saldo tienen efecto neto cero', "SELECT COUNT(*) FROM (SELECT m.id_tienda,SUM(m.monto_movimiento) neto FROM dbo.msp_movimientos_saldo_favor_tienda m LEFT JOIN dbo.msp_pagos p ON p.id_pago=m.id_pago LEFT JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=m.id_documento_cobro WHERE (m.id_pago IS NOT NULL AND p.id_pago IS NULL) OR (m.id_documento_cobro IS NOT NULL AND d.id_documento_cobro IS NULL) GROUP BY m.id_tienda HAVING ABS(SUM(m.monto_movimiento))>0.01)x");

echo "\n== Garantías y tesorería ==\n";
$zero('No existen garantías con saldo neto negativo', "SELECT COUNT(*) FROM dbo.msp_vw_garantias_control_integral WHERE alerta_nivel=3");
$zero('Ninguna recepción confirmada supera lo pactado', "SELECT COUNT(*) FROM dbo.msp_garantias g OUTER APPLY(SELECT SUM(r.monto_recibido) total FROM dbo.msp_garantia_recepciones r WHERE r.id_garantia=g.id_garantia AND r.estado_recepcion=N'CONFIRMADA')x WHERE ISNULL(x.total,0)>g.monto_inicial+0.01");
$zero('Cada recepción confirmada tiene un único ingreso por el mismo monto', "SELECT COUNT(*) FROM dbo.msp_garantia_recepciones r OUTER APPLY(SELECT COUNT(*) cantidad,SUM(t.monto) total FROM dbo.msp_tesoreria_movimientos t WHERE t.id_recepcion_garantia=r.id_recepcion_garantia AND t.estado_movimiento=N'VIGENTE')x WHERE r.estado_recepcion=N'CONFIRMADA' AND (ISNULL(x.cantidad,0)<>1 OR ABS(ISNULL(x.total,0)-r.monto_recibido)>0.01)");
$zero('Cada devolución emitida tiene una única salida por el mismo monto', "SELECT COUNT(*) FROM dbo.msp_garantia_devoluciones d OUTER APPLY(SELECT COUNT(*) cantidad,SUM(t.monto) total FROM dbo.msp_tesoreria_movimientos t WHERE t.id_devolucion_garantia=d.id_devolucion_garantia AND t.estado_movimiento=N'VIGENTE')x WHERE d.estado_devolucion=N'EMITIDA' AND (ISNULL(x.cantidad,0)<>1 OR ABS(ISNULL(x.total,0)-d.monto_devolucion)>0.01)");
$zero('Cada depósito confirmado tiene una salida y una entrada equivalentes', "SELECT COUNT(*) FROM dbo.msp_tesoreria_depositos d OUTER APPLY(SELECT COUNT(*) cantidad,SUM(CASE WHEN m.naturaleza='E' THEN m.monto ELSE -m.monto END) neto,SUM(CASE WHEN m.naturaleza='E' THEN m.monto ELSE 0 END) entrada,SUM(CASE WHEN m.naturaleza='S' THEN m.monto ELSE 0 END) salida FROM dbo.msp_tesoreria_movimientos m WHERE m.id_deposito_tesoreria=d.id_deposito_tesoreria AND m.estado_movimiento=N'VIGENTE')x WHERE d.estado_deposito=N'CONFIRMADO' AND (ISNULL(x.cantidad,0)<>2 OR ABS(ISNULL(x.neto,0))>0.01 OR ABS(ISNULL(x.entrada,0)-d.monto_deposito)>0.01 OR ABS(ISNULL(x.salida,0)-d.monto_deposito)>0.01)");
$zero('Los saldos de caja y banco coinciden con el libro de tesorería', "SELECT COUNT(*) FROM dbo.msp_vw_tesoreria_saldos v OUTER APPLY(SELECT SUM(CASE WHEN m.naturaleza='E' THEN m.monto ELSE -m.monto END) total FROM dbo.msp_tesoreria_movimientos m WHERE m.id_cuenta_tesoreria=v.id_cuenta_tesoreria AND m.estado_movimiento=N'VIGENTE')x WHERE ABS(v.saldo_actual-ISNULL(x.total,0))>0.01");
$zero('Todo ítem conciliado pertenece a la cuenta de su conciliación', "SELECT COUNT(*) FROM dbo.msp_tesoreria_conciliacion_items i JOIN dbo.msp_tesoreria_conciliaciones c ON c.id_conciliacion_tesoreria=i.id_conciliacion_tesoreria JOIN dbo.msp_tesoreria_movimientos m ON m.id_movimiento_tesoreria=i.id_movimiento_tesoreria WHERE m.id_cuenta_tesoreria<>c.id_cuenta_tesoreria OR m.conciliado<>1 OR m.id_conciliacion_tesoreria<>c.id_conciliacion_tesoreria");
$zero('Los cierres de caja almacenan una cuadratura válida', "SELECT COUNT(*) FROM dbo.msp_tesoreria_cierres_caja WHERE ABS(saldo_sistema-(saldo_apertura+total_entradas-total_salidas))>0.01 OR ABS(diferencia-(efectivo_contado-saldo_sistema))>0.01");

echo "\n== Contabilidad y períodos ==\n";
$zero('Todos los asientos vigentes están balanceados', "SELECT COUNT(*) FROM (SELECT a.id_asiento_contable FROM dbo.msp_acc_asientos a LEFT JOIN dbo.msp_acc_asientos_detalle d ON d.id_asiento_contable=a.id_asiento_contable WHERE a.estado_asiento=1 GROUP BY a.id_asiento_contable HAVING COUNT(d.id_asiento_detalle)=0 OR ABS(SUM(d.debe)-SUM(d.haber))>0.01)x");
$zero('No existen asientos vigentes con documento o pago inexistente', "SELECT COUNT(*) FROM dbo.msp_acc_asientos a WHERE a.estado_asiento=1 AND ((a.tabla_origen=N'msp_documentos_cobro' AND NOT EXISTS(SELECT 1 FROM dbo.msp_documentos_cobro d WHERE d.id_documento_cobro=a.id_origen)) OR (a.tabla_origen=N'msp_pagos' AND NOT EXISTS(SELECT 1 FROM dbo.msp_pagos p WHERE p.id_pago=a.id_origen)))");
$zero('Cada documento no anulado tiene exactamente un asiento vigente', "SELECT COUNT(*) FROM dbo.msp_documentos_cobro d OUTER APPLY(SELECT COUNT(*) cantidad FROM dbo.msp_acc_asientos a WHERE a.tabla_origen=N'msp_documentos_cobro' AND a.id_origen=d.id_documento_cobro AND a.estado_asiento=1)x WHERE d.estado_documento<>5 AND ISNULL(x.cantidad,0)<>1");
$zero('Cada pago monetario vigente tiene exactamente un asiento', "SELECT COUNT(*) FROM dbo.msp_pagos p OUTER APPLY(SELECT COUNT(*) cantidad FROM dbo.msp_acc_asientos a WHERE a.tabla_origen=N'msp_pagos' AND a.id_origen=p.id_pago AND a.estado_asiento=1)x WHERE p.estado_pago=1 AND p.aplica_desde_saldo_favor=0 AND ISNULL(x.cantidad,0)<>1");
$zero('El asiento de cada pago registra pago más excedente recibido', "SELECT COUNT(*) FROM dbo.msp_pagos p OUTER APPLY(SELECT SUM(d.debe) total FROM dbo.msp_acc_asientos a JOIN dbo.msp_acc_asientos_detalle d ON d.id_asiento_contable=a.id_asiento_contable WHERE a.tabla_origen=N'msp_pagos' AND a.id_origen=p.id_pago AND a.estado_asiento=1)x WHERE p.estado_pago=1 AND p.aplica_desde_saldo_favor=0 AND ABS(ISNULL(x.total,0)-(p.monto_pagado+p.monto_saldo_favor_generado))>0.01");
$zero('Todo asiento vigente cae dentro de un período contable existente', "SELECT COUNT(*) FROM dbo.msp_acc_asientos a LEFT JOIN dbo.msp_acc_periodos_contables p ON a.fecha_contable BETWEEN p.fecha_inicio AND p.fecha_fin WHERE a.estado_asiento=1 AND p.id_periodo_contable IS NULL");
$zero('Todo período con documentos tiene control mensual', "SELECT COUNT(*) FROM (SELECT DISTINCT periodo_facturacion FROM dbo.msp_documentos_cobro)x LEFT JOIN dbo.msp_cierre_mensual c ON c.periodo_facturacion=x.periodo_facturacion WHERE c.id_cierre_mensual IS NULL");
$zero('Un período anulado no conserva documentos vigentes', "SELECT COUNT(*) FROM dbo.msp_cierre_mensual c JOIN dbo.msp_documentos_cobro d ON d.periodo_facturacion=c.periodo_facturacion WHERE c.estado_cierre=4 AND d.estado_documento<>5");
$zero('La última transición coincide con el estado mensual actual', "SELECT COUNT(*) FROM dbo.msp_cierre_mensual c CROSS APPLY(SELECT TOP(1) t.estado_destino FROM dbo.msp_cierre_mensual_transiciones t WHERE t.id_cierre_mensual=c.id_cierre_mensual ORDER BY t.fecha_transicion DESC,t.id_transicion DESC)x WHERE c.estado_cierre<>x.estado_destino");

echo "\n== Rutas críticas ==\n";
$root = dirname(__DIR__);
foreach ([
    'msp/contratos/index.php',
    'msp/cobros/operacion_mensual.php',
    'msp/cobranza/registrar_pago_contrato.php',
    'msp/garantias/index.php',
    'msp/tesoreria/control_diario.php',
    'msp/contabilidad/libro.php',
    'msp/cierre_mensual/index.php',
    'msp/correcciones/index.php',
] as $route) {
    $check('Existe ' . $route, static fn(): bool => is_file($root . '/' . $route));
}

echo "\n== Observaciones no bloqueantes ==\n";
$warn('Operaciones históricas sin desglose ni constancia archivada', "SELECT COUNT(*) FROM dbo.msp_pago_contrato_operaciones o WHERE NOT EXISTS(SELECT 1 FROM dbo.msp_pago_contrato_operacion_detalle d WHERE d.id_pago_contrato_operacion=o.id_pago_contrato_operacion) AND (COL_LENGTH(N'dbo.msp_pago_contrato_operaciones', N'trazabilidad_incompleta') IS NULL OR ISNULL(o.trazabilidad_incompleta, 0)=0)");
$warn('Movimientos de saldo con referencia eliminada y no archivada', "SELECT COUNT(*) FROM dbo.msp_movimientos_saldo_favor_tienda m LEFT JOIN dbo.msp_pagos p ON p.id_pago=m.id_pago LEFT JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=m.id_documento_cobro WHERE ((m.id_pago IS NOT NULL AND p.id_pago IS NULL) OR (m.id_documento_cobro IS NOT NULL AND d.id_documento_cobro IS NULL)) AND NOT EXISTS(SELECT 1 FROM dbo.msp_referencias_financieras_historicas rh WHERE rh.tipo_origen=N'SALDO_FAVOR_MOVIMIENTO' AND rh.id_origen=m.id_movimiento_saldo_favor)");
$warn('Movimientos bancarios vigentes aún no conciliados', "SELECT COUNT(*) FROM dbo.msp_tesoreria_movimientos m JOIN dbo.msp_tesoreria_cuentas c ON c.id_cuenta_tesoreria=m.id_cuenta_tesoreria WHERE c.tipo_cuenta=N'BANCO' AND m.estado_movimiento=N'VIGENTE' AND m.conciliado=0");
$warn('Movimientos de caja sin cierre registrado', "SELECT COUNT(*) FROM dbo.msp_tesoreria_movimientos m JOIN dbo.msp_tesoreria_cuentas c ON c.id_cuenta_tesoreria=m.id_cuenta_tesoreria WHERE c.tipo_cuenta=N'CAJA' AND m.estado_movimiento=N'VIGENTE' AND NOT EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cierres_caja z WHERE z.id_cuenta_tesoreria=m.id_cuenta_tesoreria AND z.fecha_cierre>=m.fecha_movimiento AND z.estado_cierre=N'CERRADO')");

echo PHP_EOL . 'Resultado: ' . ($checks - $failed) . '/' . $checks . ' pruebas correctas; ' . $warnings . ' advertencia(s) operativa(s).' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
