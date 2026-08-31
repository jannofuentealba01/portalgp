<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function dhdMonto(mixed $monto): string { return '$ ' . number_format((float) ($monto ?? 0), 0, ',', '.'); }
function dhdFecha(mixed $fecha): string
{
    $raw = trim((string) ($fecha ?? ''));
    if ($raw === '') return '-';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($raw, 0, 10));
    return $date instanceof DateTimeImmutable ? $date->format('d-m-Y') : $raw;
}
function dhdVistaExiste(PDO $conn, string $nombre): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM sys.objects o INNER JOIN sys.schemas s ON s.schema_id=o.schema_id WHERE s.name=N'dbo' AND o.name=:nombre AND o.type IN ('V','U')");
    $stmt->execute([':nombre' => $nombre]);
    return (int) $stmt->fetchColumn() > 0;
}

$idContrato = filter_input(INPUT_GET, 'id_contrato', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$error = null;
$contrato = null;
$documentos = [];
$detallesPorDocumento = [];
$cargos = [];
$garantia = ['aplicada' => 0.0, 'disponible' => 0.0, 'devuelta' => 0.0];
$seguimiento = ['caso' => null, 'gestiones' => []];
$derivacionHistorica = null;

try {
    if (!$idContrato) throw new RuntimeException('Debes indicar un contrato válido.');

    $stmt = $conn->prepare(
        "SELECT c.id_contrato_arriendo,c.id_arrendatario,c.fecha_inicio,c.fecha_termino_efectiva,c.estado_contrato,
                a.nombre_locatario,a.rut,t.nombre_comercial,
                STRING_AGG(l.cdo_local,N' / ') WITHIN GROUP (ORDER BY cl.orden_visual,l.cdo_local) AS locales,
                CASE c.estado_contrato WHEN 3 THEN N'En proceso de cierre' WHEN 4 THEN N'Terminado' ELSE N'Sin estado' END AS estado_nombre
         FROM dbo.msp_contratos_arriendo c
         INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
         INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
         LEFT JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_arriendo=c.id_contrato_arriendo
         LEFT JOIN dbo.msp_locales l ON l.id_local=cl.id_local
         WHERE c.id_contrato_arriendo=:id
         GROUP BY c.id_contrato_arriendo,c.id_arrendatario,c.fecha_inicio,c.fecha_termino_efectiva,c.estado_contrato,a.nombre_locatario,a.rut,t.nombre_comercial"
    );
    $stmt->execute([':id' => (int) $idContrato]);
    $contrato = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$contrato || empty($contrato['fecha_termino_efectiva']) || !in_array((int) $contrato['estado_contrato'], [3, 4], true)) {
        throw new RuntimeException('Este contrato no corresponde a un deudor histórico: debe tener término operativo y estar en proceso de cierre o terminado.');
    }

    if (dhdVistaExiste($conn, 'msp_deudas_historicas')) {
        $derivacionStmt = $conn->prepare(
            'SELECT TOP (1) id_deuda_historica, periodo_corte, saldo_residual, estado_deuda, fecha_derivacion, motivo
             FROM dbo.msp_deudas_historicas
             WHERE id_contrato_arriendo = :id_contrato
             ORDER BY id_deuda_historica DESC'
        );
        $derivacionStmt->execute([':id_contrato' => (int) $idContrato]);
        $derivacionHistorica = $derivacionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $documentosStmt = $conn->prepare(
        "SELECT dc.id_documento_cobro,dc.numero_documento,dc.periodo_facturacion,dc.fecha_vencimiento,dc.monto_total,dc.saldo_pendiente,dc.estado_documento
         FROM dbo.msp_documentos_cobro dc
         OUTER APPLY (
             SELECT TOP (1) c_hist.id_contrato_arriendo
             FROM dbo.msp_contratos_arriendo c_hist
             WHERE c_hist.id_tienda=dc.id_tienda
               AND c_hist.fecha_inicio<=EOMONTH(dc.periodo_facturacion)
               AND (c_hist.fecha_termino_efectiva IS NULL OR c_hist.fecha_termino_efectiva>=dc.periodo_facturacion)
               AND c_hist.estado_contrato IN (1,2,3,4)
             ORDER BY c_hist.fecha_inicio DESC,c_hist.id_contrato_arriendo DESC
         ) contrato_documento
         WHERE COALESCE(dc.id_contrato_arriendo,contrato_documento.id_contrato_arriendo)=:id
           AND dc.estado_documento IN (2,3) AND ISNULL(dc.saldo_pendiente,0)>0.005
         ORDER BY dc.periodo_facturacion,dc.id_documento_cobro"
    );
    $documentosStmt->execute([':id' => (int) $idContrato]);
    $documentos = $documentosStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($documentos !== []) {
        $placeholders = [];
        $params = [];
        foreach ($documentos as $index => $documento) {
            $key = ':doc' . $index;
            $placeholders[] = $key;
            $params[$key] = (int) $documento['id_documento_cobro'];
        }
        $detallesStmt = $conn->prepare(
            'SELECT d.id_documento_cobro,d.orden_item,d.descripcion_item,d.cantidad,d.valor_unitario,d.subtotal,ti.nombre_item
             FROM dbo.msp_documentos_cobro_detalle d
             LEFT JOIN dbo.msp_tipo_item_documento ti ON ti.id_tipo_item_documento=d.id_tipo_item_documento
             WHERE d.id_documento_cobro IN (' . implode(',', $placeholders) . ')
             ORDER BY d.id_documento_cobro,d.orden_item,d.id_detalle_documento'
        );
        $detallesStmt->execute($params);
        foreach ($detallesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $detalle) {
            $detallesPorDocumento[(int) $detalle['id_documento_cobro']][] = $detalle;
        }
    }

    if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
        $cargosStmt = $conn->prepare(
            "SELECT ccl.id_cargo_contrato_local,ccl.fecha_cargo,ccl.periodo_referencia,ccl.descripcion_cargo,ccl.monto_cargo,
                    ccl.monto_aplicado_garantia,ccl.monto_pagado_directo,ccl.estado_cargo,l.cdo_local,
                    CAST(CASE WHEN ISNULL(ccl.monto_cargo,0)-ISNULL(ccl.monto_aplicado_garantia,0)-ISNULL(ccl.monto_pagado_directo,0)>0
                        THEN ISNULL(ccl.monto_cargo,0)-ISNULL(ccl.monto_aplicado_garantia,0)-ISNULL(ccl.monto_pagado_directo,0) ELSE 0 END AS DECIMAL(18,2)) AS saldo_pendiente
             FROM dbo.msp_cargos_contrato_local ccl
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local=ccl.id_contrato_local
             INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local
             WHERE cl.id_contrato_arriendo=:id AND ccl.estado_cargo IN (1,2) AND ccl.id_documento_cobro IS NULL
               AND ISNULL(ccl.monto_cargo,0)-ISNULL(ccl.monto_aplicado_garantia,0)-ISNULL(ccl.monto_pagado_directo,0)>0.005
             ORDER BY ccl.fecha_cargo,ccl.id_cargo_contrato_local"
        );
        $cargosStmt->execute([':id' => (int) $idContrato]);
        $cargos = $cargosStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (dhdVistaExiste($conn, 'msp_vw_garantias_control_integral')) {
        $garantiaStmt = $conn->prepare('SELECT ISNULL(SUM(monto_aplicado),0) aplicada,ISNULL(SUM(monto_disponible),0) disponible,ISNULL(SUM(monto_devuelto),0) devuelta FROM dbo.msp_vw_garantias_control_integral WHERE id_contrato_arriendo=:id');
        $garantiaStmt->execute([':id' => (int) $idContrato]);
        $garantia = $garantiaStmt->fetch(PDO::FETCH_ASSOC) ?: $garantia;
    }

    if (msp2TableExists($conn, 'msp_cobranza_casos')
        && msp2TableExists($conn, 'msp_cobranza_gestiones')
        && msp2TableExists($conn, 'msp_cobranza_tipos_gestion')
        && msp2TableExists($conn, 'msp_cobranza_resultados_gestion')) {
        $casoStmt = $conn->prepare('SELECT * FROM dbo.msp_cobranza_casos WHERE id_contrato_arriendo=:id');
        $casoStmt->execute([':id' => (int) $idContrato]);
        $seguimiento['caso'] = $casoStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $gestionesStmt = $conn->prepare(
            'SELECT TOP (5) g.fecha_gestion,g.observacion,t.nombre AS tipo_nombre,r.nombre AS resultado_nombre
             FROM dbo.msp_cobranza_gestiones g
             INNER JOIN dbo.msp_cobranza_tipos_gestion t ON t.id_tipo_gestion=g.id_tipo_gestion
             INNER JOIN dbo.msp_cobranza_resultados_gestion r ON r.id_resultado_gestion=g.id_resultado_gestion
             WHERE g.id_contrato_arriendo=:id ORDER BY g.fecha_gestion DESC,g.id_gestion_cobranza DESC'
        );
        $gestionesStmt->execute([':id' => (int) $idContrato]);
        $seguimiento['gestiones'] = $gestionesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $exception) {
    $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible cargar el detalle del deudor histórico.';
}

$saldoDocumentos = array_sum(array_map(static fn(array $row): float => (float) $row['saldo_pendiente'], $documentos));
$saldoCargos = array_sum(array_map(static fn(array $row): float => (float) $row['saldo_pendiente'], $cargos));
$saldoResidual = $saldoDocumentos + $saldoCargos;
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Deudor histórico | MSP</title><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"><link rel="stylesheet" href="/portalgp/styles.css"><style>.dhd-shell{max-width:1450px}.dhd-kpi{height:100%;border:1px solid #dbe4ef;border-radius:12px;background:#fff;padding:1rem}.dhd-kpi label{font-size:.76rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b}.dhd-kpi strong{display:block;font-size:1.25rem;color:#123f72;margin-top:.35rem}.dhd-card{border:1px solid #dbe4ef;border-radius:12px;background:#fff}.dhd-documento{border-left:4px solid #c2410c}.dhd-small{font-size:.86rem;color:#64748b}@media print{.no-print,.msp-quick-access-hot-edge,.offcanvas,header,footer{display:none!important}.gp-main{padding:0!important}.box-container-full{box-shadow:none!important;border:0!important}}</style></head>
<body class="gp-layout bg-light"><?php include dirname(__DIR__, 2) . '/templates/header.php'; ?><main class="gp-main p-4"><div class="box-container-full dhd-shell mx-auto">
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4 no-print"><div><p class="section-kicker mb-1">MSP / Cobranza / Historial</p><h1 class="form-title mb-1">Deudor histórico</h1><p class="text-muted mb-0">Detalle financiero y seguimiento del contrato después de su término operativo.</p></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/deudores_exarrendatarios.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Volver al listado</a><?php if ($contrato): ?><a class="btn btn-primary btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/gestionar.php?id_contrato=' . (int) $contrato['id_contrato_arriendo'] . '&return_to=cobranza/deudores_exarrendatarios.php')); ?>"><i class="bi bi-chat-square-text me-1"></i>Gestionar seguimiento</a><?php endif; ?></div></div>
<?php if ($error !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php else: ?>
<div class="dhd-card p-3 mb-4"><div class="row g-3"><div class="col-lg-4"><div class="text-muted small">Exarrendatario</div><strong class="fs-5"><?php echo msp2Escape((string) $contrato['nombre_locatario']); ?></strong><div class="dhd-small"><?php echo msp2Escape((string) $contrato['rut']); ?></div></div><div class="col-lg-4"><div class="text-muted small">Contrato / tienda</div><strong>#<?php echo (int) $contrato['id_contrato_arriendo']; ?> · <?php echo msp2Escape((string) $contrato['nombre_comercial']); ?></strong><div class="dhd-small"><?php echo msp2Escape((string) ($contrato['locales'] ?: 'Sin local asociado')); ?></div></div><div class="col-lg-4"><div class="text-muted small">Término operativo</div><strong><?php echo msp2Escape(dhdFecha($contrato['fecha_termino_efectiva'])); ?></strong><div><span class="badge text-bg-<?php echo (int) $contrato['estado_contrato'] === 3 ? 'warning' : 'secondary'; ?>"><?php echo msp2Escape((string) $contrato['estado_nombre']); ?></span></div><?php if ($derivacionHistorica !== null && (string) ($derivacionHistorica['estado_deuda'] ?? '') === 'ACTIVA'): ?><div class="dhd-small mt-1">Deuda derivada el <?php echo msp2Escape(dhdFecha((string) ($derivacionHistorica['fecha_derivacion'] ?? ''))); ?></div><?php elseif ((int) $contrato['estado_contrato'] === 3): ?><div class="dhd-small mt-1">La derivación se registra al cerrar financieramente.</div><?php endif; ?></div></div></div>
<div class="row g-3 mb-4"><div class="col-sm-6 col-xl"><div class="dhd-kpi"><label>Saldo documentos</label><strong><?php echo msp2Escape(dhdMonto($saldoDocumentos)); ?></strong></div></div><div class="col-sm-6 col-xl"><div class="dhd-kpi"><label>Cargos pendientes</label><strong><?php echo msp2Escape(dhdMonto($saldoCargos)); ?></strong></div></div><div class="col-sm-6 col-xl"><div class="dhd-kpi"><label>Garantía aplicada</label><strong><?php echo msp2Escape(dhdMonto($garantia['aplicada'])); ?></strong></div></div><div class="col-sm-6 col-xl"><div class="dhd-kpi"><label>Garantía disponible</label><strong><?php echo msp2Escape(dhdMonto($garantia['disponible'])); ?></strong></div></div><div class="col-sm-6 col-xl"><div class="dhd-kpi"><label>Deuda residual</label><strong class="text-danger"><?php echo msp2Escape(dhdMonto($saldoResidual)); ?></strong></div></div></div>
<div class="alert alert-warning"><strong>Lectura correcta del desglose:</strong> cada documento conserva su saldo pendiente real. Sus líneas muestran los conceptos originales —arriendo, agua, luz, gas u otros—; si el documento tuvo pagos parciales, el sistema no inventa una distribución del saldo entre líneas.</div>
<div class="dhd-card mb-4"><div class="card-header bg-white"><strong>Documentos pendientes y sus conceptos</strong></div><div class="card-body"><?php if ($documentos === []): ?><p class="text-muted mb-0">No hay documentos pendientes asociados al contrato.</p><?php else: foreach ($documentos as $documento): ?><div class="dhd-documento ps-3 py-2 mb-3"><div class="d-flex flex-wrap justify-content-between gap-2"><div><strong><?php echo msp2Escape((string) ($documento['numero_documento'] ?: ('Documento #' . $documento['id_documento_cobro']))); ?></strong><span class="dhd-small ms-2">Período <?php echo msp2Escape(dhdFecha($documento['periodo_facturacion'])); ?> · Vence <?php echo msp2Escape(dhdFecha($documento['fecha_vencimiento'])); ?></span></div><div><span class="me-3">Total: <?php echo msp2Escape(dhdMonto($documento['monto_total'])); ?></span><strong class="text-danger">Pendiente: <?php echo msp2Escape(dhdMonto($documento['saldo_pendiente'])); ?></strong></div></div><ul class="mb-0 mt-2 small"><?php foreach ($detallesPorDocumento[(int) $documento['id_documento_cobro']] ?? [] as $detalle): ?><li><?php echo msp2Escape((string) ($detalle['descripcion_item'] ?: $detalle['nombre_item'] ?: 'Concepto sin descripción')); ?>: <strong><?php echo msp2Escape(dhdMonto($detalle['subtotal'])); ?></strong></li><?php endforeach; ?></ul></div><?php endforeach; endif; ?></div></div>
<div class="dhd-card mb-4"><div class="card-header bg-white"><strong>Cargos directos pendientes</strong></div><div class="table-responsive"><table class="table mb-0"><thead class="table-light"><tr><th>Fecha</th><th>Local</th><th>Concepto</th><th>Original</th><th>Garantía aplicada</th><th>Saldo</th></tr></thead><tbody><?php if ($cargos === []): ?><tr><td colspan="6" class="text-center text-muted py-3">No hay cargos directos pendientes.</td></tr><?php else: foreach ($cargos as $cargo): ?><tr><td><?php echo msp2Escape(dhdFecha($cargo['fecha_cargo'])); ?></td><td><?php echo msp2Escape((string) $cargo['cdo_local']); ?></td><td><?php echo msp2Escape((string) $cargo['descripcion_cargo']); ?></td><td><?php echo msp2Escape(dhdMonto($cargo['monto_cargo'])); ?></td><td class="text-success"><?php echo msp2Escape(dhdMonto($cargo['monto_aplicado_garantia'])); ?></td><td class="fw-bold text-danger"><?php echo msp2Escape(dhdMonto($cargo['saldo_pendiente'])); ?></td></tr><?php endforeach; endif; ?></tbody></table></div></div>
<div class="dhd-card"><div class="card-header bg-white d-flex justify-content-between gap-2"><strong>Seguimiento de cobranza</strong><?php if ($seguimiento['caso']): ?><span class="badge text-bg-primary"><?php echo msp2Escape(str_replace('_', ' ', (string) $seguimiento['caso']['estado_operacional'])); ?></span><?php endif; ?></div><div class="card-body"><?php if ($seguimiento['gestiones'] === []): ?><p class="text-muted mb-0">No hay gestiones registradas aún. Usa “Gestionar seguimiento” para registrar contacto, aviso o compromiso de pago.</p><?php else: ?><ul class="mb-0"><?php foreach ($seguimiento['gestiones'] as $gestion): ?><li class="mb-2"><strong><?php echo msp2Escape(dhdFecha($gestion['fecha_gestion'])); ?> · <?php echo msp2Escape((string) $gestion['tipo_nombre']); ?> · <?php echo msp2Escape((string) $gestion['resultado_nombre']); ?></strong><div class="dhd-small"><?php echo msp2Escape((string) ($gestion['observacion'] ?: 'Sin observación.')); ?></div></li><?php endforeach; ?></ul><?php endif; ?></div></div>
<?php endif; ?></div></main><?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?></body></html>
