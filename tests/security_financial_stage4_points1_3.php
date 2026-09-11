<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(__DIR__) . '/db.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
    echo '[OK] ' . $message . PHP_EOL;
};
$scalar = static fn(string $sql): int => (int) $conn->query($sql)->fetchColumn();
$mustFail = static function (callable $operation, string $message) use ($conn, $assert): void {
    $failed = false;
    try {
        $conn->beginTransaction();
        $operation();
        $conn->rollBack();
    } catch (Throwable) {
        $failed = true;
        if ($conn->inTransaction()) $conn->rollBack();
    }
    $assert($failed, $message);
};

$assert($scalar("SELECT COUNT(*) FROM dbo.msp_pagos WHERE monto_pagado<=0 OR monto_saldo_favor_generado<0") === 0, 'pagos y excedentes tienen montos válidos');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_pagos WHERE (estado_pago=2 AND (fecha_anulacion IS NULL OR NULLIF(LTRIM(RTRIM(motivo_anulacion)),N'') IS NULL)) OR (estado_pago<>2 AND (fecha_anulacion IS NOT NULL OR motivo_anulacion IS NOT NULL))") === 0, 'estado y datos de anulación de pagos son coherentes');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_documentos_cobro d OUTER APPLY(SELECT SUM(p.monto_pagado) aplicado FROM dbo.msp_pagos p WHERE p.id_documento_cobro=d.id_documento_cobro AND p.estado_pago=1)x WHERE ABS(d.saldo_pendiente-CASE WHEN d.monto_total-ISNULL(x.aplicado,0)>0 THEN d.monto_total-ISNULL(x.aplicado,0) ELSE 0 END)>0.005") === 0, 'el saldo de documentos coincide con pagos activos');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_pagos p OUTER APPLY(SELECT SUM(c.monto_aplicado) detalle FROM dbo.msp_pagos_detalle_concepto c WHERE c.id_pago=p.id_pago)x WHERE p.estado_pago=1 AND ABS(p.monto_pagado-ISNULL(x.detalle,0))>0.005") === 0, 'cada pago activo coincide con su distribución por concepto');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_pago_contrato_operaciones WHERE ABS(monto_total_pagado-monto_total_aplicado-monto_total_excedente-monto_total_no_imputado)>0.005") === 0, 'las operaciones por contrato cuadran pagado, aplicado, excedente y no imputado');

$assert($scalar("SELECT COUNT(*) FROM dbo.msp_vw_garantias_resumen WHERE saldo_disponible<0 OR saldo_reservado<0") === 0, 'no existen garantías con saldo disponible o reservado negativo');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_garantias g OUTER APPLY(SELECT SUM(r.monto_recibido) recibido FROM dbo.msp_garantia_recepciones r WHERE r.id_garantia=g.id_garantia AND r.estado_recepcion=N'CONFIRMADA')x WHERE ISNULL(x.recibido,0)>g.monto_inicial+0.005") === 0, 'ninguna recepción confirmada supera la garantía pactada');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_garantia_devoluciones d LEFT JOIN dbo.msp_movimientos_garantia m ON m.id_movimiento_garantia=d.id_movimiento_garantia LEFT JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia WHERE d.estado_devolucion=N'EMITIDA' AND (m.id_movimiento_garantia IS NULL OR m.id_garantia<>d.id_garantia OR t.codigo_movimiento<>N'DEVOLUCION' OR ABS(m.monto_movimiento-d.monto_devolucion)>0.005)") === 0, 'las devoluciones emitidas coinciden con el libro de garantía');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_garantia_recepciones r LEFT JOIN dbo.msp_tesoreria_movimientos t ON t.id_recepcion_garantia=r.id_recepcion_garantia AND t.estado_movimiento=N'VIGENTE' WHERE r.estado_recepcion=N'CONFIRMADA' AND t.id_movimiento_tesoreria IS NULL") === 0, 'cada recepción confirmada tiene ingreso vigente de tesorería');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_garantia_devoluciones d LEFT JOIN dbo.msp_tesoreria_movimientos t ON t.id_devolucion_garantia=d.id_devolucion_garantia AND t.estado_movimiento=N'VIGENTE' WHERE d.estado_devolucion=N'EMITIDA' AND t.id_movimiento_tesoreria IS NULL") === 0, 'cada devolución emitida tiene salida vigente de tesorería');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_garantia_reversas GROUP BY tipo_origen,id_origen HAVING COUNT(*)>1") === 0, 'no existen reversas duplicadas sobre el mismo origen');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_garantia_reversas r JOIN dbo.msp_garantia_devoluciones d ON r.tipo_origen=N'DEVOLUCION' AND d.id_devolucion_garantia=r.id_origen JOIN dbo.msp_movimientos_garantia m ON m.id_movimiento_garantia=d.id_movimiento_garantia JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia OUTER APPLY(SELECT SUM(x.monto_movimiento) compensado FROM dbo.msp_movimientos_garantia x JOIN dbo.msp_tipos_movimiento_garantia xt ON xt.id_tipo_movimiento_garantia=x.id_tipo_movimiento_garantia WHERE x.id_garantia=r.id_garantia AND xt.codigo_movimiento=N'AJUSTE_POSITIVO' AND x.observaciones LIKE CONCAT(N'%[[]REVERSA_GARANTIA:',r.id_reversa_garantia,N']%')) c WHERE t.codigo_movimiento<>N'DEVOLUCION' OR ABS(ISNULL(c.compensado,0)-r.monto_reversa)>0.005") === 0, 'las devoluciones revertidas conservan débito original y compensación exacta');

$assert($scalar("SELECT COUNT(*) FROM dbo.msp_saldos_favor_tienda s OUTER APPLY(SELECT SUM(m.monto_movimiento) total FROM dbo.msp_movimientos_saldo_favor_tienda m WHERE m.id_tienda=s.id_tienda)x WHERE ABS(s.saldo_disponible-ISNULL(x.total,0))>0.005 OR s.saldo_disponible<0") === 0, 'el saldo a favor coincide con su libro y nunca es negativo');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_pagos p LEFT JOIN dbo.msp_movimientos_saldo_favor_tienda m ON m.id_pago=p.id_pago AND m.tipo_movimiento=1 WHERE p.estado_pago=1 AND p.monto_saldo_favor_generado>0 AND (m.id_movimiento_saldo_favor IS NULL OR ABS(m.monto_movimiento-p.monto_saldo_favor_generado)>0.005)") === 0, 'cada excedente activo tiene su movimiento de saldo a favor');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_pagos p LEFT JOIN dbo.msp_movimientos_saldo_favor_tienda m ON m.id_pago=p.id_pago AND m.tipo_movimiento=2 WHERE p.estado_pago=1 AND p.aplica_desde_saldo_favor=1 AND (m.id_movimiento_saldo_favor IS NULL OR ABS(m.monto_movimiento+p.monto_pagado)>0.005)") === 0, 'cada aplicación activa tiene su egreso de saldo a favor');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_saldo_favor_periodo_items i OUTER APPLY(SELECT SUM(a.monto_aplicado) aplicado FROM dbo.msp_saldo_favor_periodo_aplicaciones a WHERE a.id_saldo_favor_periodo_item=i.id_saldo_favor_periodo_item AND a.estado_aplicacion=1)x WHERE ISNULL(x.aplicado,0)>i.monto_original+0.005") === 0, 'ningún item por periodo está sobreaplicado');
$assert($scalar("SELECT COUNT(*) FROM dbo.msp_saldo_favor_periodo_aplicaciones a JOIN dbo.msp_saldo_favor_periodo_items i ON i.id_saldo_favor_periodo_item=a.id_saldo_favor_periodo_item JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=a.id_documento_cobro WHERE a.id_tienda<>i.id_tienda OR a.periodo_facturacion<>i.periodo_facturacion OR d.id_tienda<>a.id_tienda") === 0, 'las aplicaciones por periodo no cruzan tienda, periodo ni documento');

foreach (['TR_msp_garantia_recepciones_integridad','TR_msp_movimientos_garantia_integridad_saldos','TR_msp_garantia_reversas_integridad','TR_msp_movimientos_saldo_favor_integridad','TR_msp_pagos_protege_saldo_favor','TR_msp_documentos_protege_saldo_favor','TR_msp_saldo_favor_periodo_aplicaciones_integridad'] as $trigger) {
    $stmt=$conn->prepare('SELECT COUNT(*) FROM sys.triggers WHERE name=:name AND is_disabled=0');
    $stmt->execute([':name'=>$trigger]);
    $assert((int)$stmt->fetchColumn()===1, 'trigger activo: '.$trigger);
}
$assert($scalar("SELECT COUNT(*) FROM sys.check_constraints WHERE name=N'CK_msp_pco_montos' AND is_disabled=0 AND is_not_trusted=0 AND definition LIKE '%abs%'") === 1, 'la cuadratura de operaciones está forzada por un CHECK confiable');

$mustFail(function () use ($conn): void {
    $shop=(int)$conn->query('SELECT TOP(1) id_tienda FROM dbo.msp_tiendas ORDER BY id_tienda')->fetchColumn();
    $stmt=$conn->prepare("INSERT dbo.msp_movimientos_saldo_favor_tienda(id_tienda,fecha_movimiento,tipo_movimiento,monto_movimiento,observaciones) VALUES(:shop,CONVERT(date,GETDATE()),1,-1,N'prueba revertida')");
    $stmt->execute([':shop'=>$shop]);
}, 'SQL rechaza signos inválidos de saldo a favor');

$mustFail(function () use ($conn): void {
    $row=$conn->query("SELECT TOP(1) id_garantia,monto_inicial FROM dbo.msp_garantias WHERE monto_inicial>0 AND estado_garantia<>6 ORDER BY id_garantia")->fetch();
    $stmt=$conn->prepare("INSERT dbo.msp_garantia_recepciones(id_garantia,fecha_recepcion,monto_recibido,medio_recepcion,estado_recepcion) VALUES(:id,CONVERT(date,GETDATE()),:monto,N'EFECTIVO',N'CONFIRMADA')");
    $stmt->execute([':id'=>(int)$row['id_garantia'],':monto'=>(string)((float)$row['monto_inicial']+1)]);
}, 'SQL rechaza recepciones que exceden lo pactado');

$mustFail(function () use ($conn): void {
    $id=(int)$conn->query('SELECT TOP(1) id_movimiento_saldo_favor FROM dbo.msp_movimientos_saldo_favor_tienda ORDER BY id_movimiento_saldo_favor')->fetchColumn();
    $stmt=$conn->prepare('DELETE dbo.msp_movimientos_saldo_favor_tienda WHERE id_movimiento_saldo_favor=:id');$stmt->execute([':id'=>$id]);
}, 'el libro de saldo a favor no admite borrado físico');

$mustFail(function () use ($conn): void {
    $id=(int)$conn->query('SELECT TOP(1) id_reversa_garantia FROM dbo.msp_garantia_reversas ORDER BY id_reversa_garantia')->fetchColumn();
    $stmt=$conn->prepare('DELETE dbo.msp_garantia_reversas WHERE id_reversa_garantia=:id');$stmt->execute([':id'=>$id]);
}, 'el historial de reversas de garantía es inmutable');

/* Flujos reales dentro de una transacción que siempre se revierte. */
$doc=$conn->query("SELECT TOP(1) id_documento_cobro,saldo_pendiente FROM dbo.msp_documentos_cobro WHERE estado_documento IN(2,3) AND saldo_pendiente>1 ORDER BY id_documento_cobro DESC")->fetch();
$before=(float)$doc['saldo_pendiente'];
$conn->beginTransaction();
$stmt=$conn->prepare("EXEC dbo.msp_registrar_pago_documento @id_documento_cobro=:doc,@fecha_pago=:fecha,@monto_pagado=:monto,@medio_pago=N'PRUEBA_ETAPA4',@referencia_pago=NULL,@observaciones=N'Prueba transaccional revertida',@detalle_conceptos_json=NULL");
$stmt->execute([':doc'=>(int)$doc['id_documento_cobro'],':fecha'=>date('Y-m-d'),':monto'=>'0.10']);
$paymentResult=$stmt->fetch();$stmt->closeCursor();
$during=(float)$conn->query('SELECT saldo_pendiente FROM dbo.msp_documentos_cobro WHERE id_documento_cobro='.(int)$doc['id_documento_cobro'])->fetchColumn();
$assert((int)($paymentResult['id_pago_generado']??0)>0 && abs(($before-0.10)-$during)<0.005, 'registro real de pago actualiza documento dentro de una unidad atómica');
$testPayment=(int)$paymentResult['id_pago_generado'];
$conn->rollBack();
$assert($scalar('SELECT COUNT(*) FROM dbo.msp_pagos WHERE id_pago='.$testPayment)===0, 'la prueba de pago se revirtió completamente');

$saldoDoc=$conn->query("SELECT TOP(1) d.id_documento_cobro,d.id_tienda,d.saldo_pendiente,s.saldo_disponible FROM dbo.msp_documentos_cobro d JOIN dbo.msp_saldos_favor_tienda s ON s.id_tienda=d.id_tienda WHERE d.estado_documento IN(2,3) AND d.saldo_pendiente>1 AND s.saldo_disponible>1 ORDER BY d.id_documento_cobro DESC")->fetch();
$saldoBefore=(float)$saldoDoc['saldo_disponible'];
$conn->beginTransaction();
$stmt=$conn->prepare("EXEC dbo.msp_aplicar_saldo_favor_documento @id_documento_cobro=:doc,@fecha_pago=:fecha,@monto_aplicar=:monto,@observaciones=N'Prueba transaccional revertida',@detalle_conceptos_json=NULL");
$stmt->execute([':doc'=>(int)$saldoDoc['id_documento_cobro'],':fecha'=>date('Y-m-d'),':monto'=>'0.10']);
$saldoResult=$stmt->fetch();$stmt->closeCursor();
$saldoDuring=(float)$conn->query('SELECT saldo_disponible FROM dbo.msp_saldos_favor_tienda WHERE id_tienda='.(int)$saldoDoc['id_tienda'])->fetchColumn();
$assert((int)($saldoResult['id_pago_generado']??0)>0 && abs(($saldoBefore-0.10)-$saldoDuring)<0.005, 'aplicación real de saldo a favor descuenta libro y crea pago atómicamente');
$saldoTestPayment=(int)$saldoResult['id_pago_generado'];
$conn->rollBack();
$assert($scalar('SELECT COUNT(*) FROM dbo.msp_pagos WHERE id_pago='.$saldoTestPayment)===0, 'la prueba de saldo a favor se revirtió completamente');

$root=dirname(__DIR__);
$sources=[
    'msp/pagos/guardar.php'=>['beginTransaction()', 'msp_registrar_pago_documento'],
    'msp/pagos/aplicar_saldo_favor.php'=>['beginTransaction()', 'msp_aplicar_saldo_favor_documento'],
    'msp/pagos/anular.php'=>['beginTransaction()', 'msp_anular_pago_documento'],
    'msp/garantias/aplicar_documento.php'=>['beginTransaction()', 'categoria_aplicacion', 'commit()'],
    'msp/contratos/movimiento_garantia_cargo.php'=>['beginTransaction()', 'motivo_autorizacion', 'commit()'],
];
foreach($sources as $relative=>$needles){$source=(string)file_get_contents($root.'/'.$relative);foreach($needles as $needle)$assert(str_contains($source,$needle),$relative.' contiene '.$needle);}

$admin=$conn->query("SELECT TOP(1) u.id,u.estado_id,u.security_version,r.nombre_rol,(SELECT COUNT(DISTINCT rp.permiso_id) FROM dbo.cr_rol_permisos rp WHERE rp.rol_id=u.rol_id) permisos FROM dbo.cr_usuarios u JOIN dbo.cr_roles r ON r.id=u.rol_id WHERE u.UserName=N'admin_2'")->fetch();
$assert(is_array($admin) && (int)$admin['id']===1030 && (int)$admin['estado_id']===1 && (int)$admin['security_version']===0 && (string)$admin['nombre_rol']==='Administrador' && (int)$admin['permisos']>=22, 'admin_2 permanece activo y conserva al menos sus permisos protegidos');

echo "PASS: {$checks}/{$checks} comprobaciones.\n";
