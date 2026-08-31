<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2RecaudacionMonto(mixed $monto): string
{
    return '$ ' . number_format((float) ($monto ?? 0), 2, ',', '.');
}

function msp2RecaudacionPeriodo(string $periodo): string
{
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', substr($periodo, 0, 10));
    return $fecha instanceof DateTimeImmutable ? $fecha->format('m-Y') : $periodo;
}

$periodoRaw = trim((string) ($_GET['periodo'] ?? ''));
$periodoFecha = DateTimeImmutable::createFromFormat('!Y-m', $periodoRaw);
if (!($periodoFecha instanceof DateTimeImmutable) || $periodoFecha->format('Y-m') !== $periodoRaw) {
    $periodoFecha = new DateTimeImmutable('first day of this month');
}
$periodo = $periodoFecha->format('Y-m');
$periodoInicio = $periodoFecha->format('Y-m-d');
$periodoFin = $periodoFecha->modify('last day of this month')->format('Y-m-d');
$periodoTitulo = $periodoFecha->format('m-Y');
$error = null;
$porPeriodo = [];
$porFechaPago = [];
$totalPeriodo = ['cobrado' => 0.0, 'pagado' => 0.0, 'pendiente' => 0.0];
$totalRecaudado = 0.0;
$pagoConceptoDisponible = false;

try {
    $tablas = ['msp_documentos_cobro', 'msp_documentos_cobro_detalle', 'msp_tipo_item_documento', 'msp_pagos'];
    foreach ($tablas as $tabla) {
        if (!msp2TableExists($conn, $tabla)) {
            throw new RuntimeException('Falta la tabla `' . $tabla . '` para generar el reporte.');
        }
    }
    $pagoConceptoDisponible = msp2TableExists($conn, 'msp_pagos_detalle_concepto');
    if (!$pagoConceptoDisponible) {
        throw new RuntimeException('Falta el detalle de pagos por concepto. Ejecuta el parche de pagos por concepto antes de usar este reporte.');
    }

    $stmtPeriodo = $conn->prepare(
        "WITH docs AS (
            SELECT dc.id_documento_cobro, dc.monto_total
            FROM dbo.msp_documentos_cobro dc
            WHERE dc.periodo_facturacion = :periodo
              AND dc.estado_documento <> 5
        ), conceptos AS (
            SELECT d.id_documento_cobro,
                SUM(CASE WHEN ti.codigo_item = N'ARRIENDO' THEN d.subtotal ELSE 0 END) AS arriendo_neto,
                SUM(CASE WHEN ti.codigo_item = N'SERVICIO_LUZ' THEN d.subtotal ELSE 0 END) AS luz,
                SUM(CASE WHEN ti.codigo_item = N'SERVICIO_GAS' THEN d.subtotal ELSE 0 END) AS gas,
                SUM(CASE WHEN ti.codigo_item = N'SERVICIO_AGUA' THEN d.subtotal ELSE 0 END) AS agua,
                SUM(CASE WHEN ti.codigo_item NOT IN (N'ARRIENDO', N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA') THEN d.subtotal ELSE 0 END) AS otros,
                SUM(d.subtotal) AS total_detalle
            FROM dbo.msp_documentos_cobro_detalle d
            INNER JOIN dbo.msp_tipo_item_documento ti ON ti.id_tipo_item_documento = d.id_tipo_item_documento
            INNER JOIN docs doc ON doc.id_documento_cobro = d.id_documento_cobro
            GROUP BY d.id_documento_cobro
        ), pagos AS (
            SELECT pdc.id_documento_cobro,
                SUM(CASE WHEN ti.codigo_item = N'ARRIENDO' THEN pdc.monto_aplicado ELSE 0 END) AS arriendo_total_pagado,
                SUM(CASE WHEN ti.codigo_item = N'SERVICIO_LUZ' THEN pdc.monto_aplicado ELSE 0 END) AS luz_pagado,
                SUM(CASE WHEN ti.codigo_item = N'SERVICIO_GAS' THEN pdc.monto_aplicado ELSE 0 END) AS gas_pagado,
                SUM(CASE WHEN ti.codigo_item = N'SERVICIO_AGUA' THEN pdc.monto_aplicado ELSE 0 END) AS agua_pagado,
                SUM(CASE WHEN ti.codigo_item NOT IN (N'ARRIENDO', N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA') THEN pdc.monto_aplicado ELSE 0 END) AS otros_pagado
            FROM dbo.msp_pagos_detalle_concepto pdc
            INNER JOIN dbo.msp_pagos p ON p.id_pago = pdc.id_pago AND p.estado_pago = 1
            INNER JOIN dbo.msp_tipo_item_documento ti ON ti.id_tipo_item_documento = pdc.id_tipo_item_documento
            INNER JOIN docs doc ON doc.id_documento_cobro = pdc.id_documento_cobro
            GROUP BY pdc.id_documento_cobro
        ), base AS (
            SELECT doc.id_documento_cobro,
                ISNULL(c.arriendo_neto, 0) AS arriendo_neto,
                CASE WHEN doc.monto_total - ISNULL(c.total_detalle, 0) > 0 THEN doc.monto_total - ISNULL(c.total_detalle, 0) ELSE 0 END AS iva_arriendo,
                ISNULL(c.luz, 0) AS luz, ISNULL(c.gas, 0) AS gas, ISNULL(c.agua, 0) AS agua, ISNULL(c.otros, 0) AS otros,
                ISNULL(p.arriendo_total_pagado, 0) AS arriendo_total_pagado,
                ISNULL(p.luz_pagado, 0) AS luz_pagado, ISNULL(p.gas_pagado, 0) AS gas_pagado,
                ISNULL(p.agua_pagado, 0) AS agua_pagado, ISNULL(p.otros_pagado, 0) AS otros_pagado
            FROM docs doc
            LEFT JOIN conceptos c ON c.id_documento_cobro = doc.id_documento_cobro
            LEFT JOIN pagos p ON p.id_documento_cobro = doc.id_documento_cobro
        )
        SELECT
            ROUND(SUM(arriendo_neto), 2) AS arriendo_neto_cobrado,
            ROUND(SUM(iva_arriendo), 2) AS iva_arriendo_cobrado,
            ROUND(SUM(luz), 2) AS luz_cobrado, ROUND(SUM(gas), 2) AS gas_cobrado,
            ROUND(SUM(agua), 2) AS agua_cobrado, ROUND(SUM(otros), 2) AS otros_cobrado,
            ROUND(SUM(CASE WHEN arriendo_neto + iva_arriendo > 0 THEN arriendo_total_pagado * arriendo_neto / (arriendo_neto + iva_arriendo) ELSE 0 END), 2) AS arriendo_neto_pagado,
            ROUND(SUM(CASE WHEN arriendo_neto + iva_arriendo > 0 THEN arriendo_total_pagado * iva_arriendo / (arriendo_neto + iva_arriendo) ELSE 0 END), 2) AS iva_arriendo_pagado,
            ROUND(SUM(luz_pagado), 2) AS luz_pagado, ROUND(SUM(gas_pagado), 2) AS gas_pagado,
            ROUND(SUM(agua_pagado), 2) AS agua_pagado, ROUND(SUM(otros_pagado), 2) AS otros_pagado
        FROM base"
    );
    $stmtPeriodo->execute([':periodo' => $periodoInicio]);
    $reportePeriodo = $stmtPeriodo->fetch() ?: [];
    $porPeriodo = [
        ['concepto' => 'Arriendo neto', 'cobrado' => (float) ($reportePeriodo['arriendo_neto_cobrado'] ?? 0), 'pagado' => (float) ($reportePeriodo['arriendo_neto_pagado'] ?? 0)],
        ['concepto' => 'IVA arriendo', 'cobrado' => (float) ($reportePeriodo['iva_arriendo_cobrado'] ?? 0), 'pagado' => (float) ($reportePeriodo['iva_arriendo_pagado'] ?? 0)],
        ['concepto' => 'Luz', 'cobrado' => (float) ($reportePeriodo['luz_cobrado'] ?? 0), 'pagado' => (float) ($reportePeriodo['luz_pagado'] ?? 0)],
        ['concepto' => 'Gas', 'cobrado' => (float) ($reportePeriodo['gas_cobrado'] ?? 0), 'pagado' => (float) ($reportePeriodo['gas_pagado'] ?? 0)],
        ['concepto' => 'Agua', 'cobrado' => (float) ($reportePeriodo['agua_cobrado'] ?? 0), 'pagado' => (float) ($reportePeriodo['agua_pagado'] ?? 0)],
        ['concepto' => 'Otros', 'cobrado' => (float) ($reportePeriodo['otros_cobrado'] ?? 0), 'pagado' => (float) ($reportePeriodo['otros_pagado'] ?? 0)],
    ];
    foreach ($porPeriodo as &$filaPeriodo) {
        $filaPeriodo['pendiente'] = round($filaPeriodo['cobrado'] - $filaPeriodo['pagado'], 2);
        $totalPeriodo['cobrado'] += $filaPeriodo['cobrado'];
        $totalPeriodo['pagado'] += $filaPeriodo['pagado'];
        $totalPeriodo['pendiente'] += $filaPeriodo['pendiente'];
    }
    unset($filaPeriodo);

    $stmtRecaudacion = $conn->prepare(
        "SELECT CONVERT(CHAR(10), dc.periodo_facturacion, 126) AS periodo_deuda,
                ROUND(SUM(p.monto_pagado), 2) AS monto_recaudado,
                COUNT(*) AS pagos
         FROM dbo.msp_pagos p
         INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
         WHERE p.estado_pago = 1
           AND p.fecha_pago >= :fecha_inicio
           AND p.fecha_pago <= :fecha_fin
         GROUP BY dc.periodo_facturacion
         ORDER BY dc.periodo_facturacion ASC"
    );
    $stmtRecaudacion->execute([':fecha_inicio' => $periodoInicio, ':fecha_fin' => $periodoFin]);
    $porFechaPago = $stmtRecaudacion->fetchAll() ?: [];
    foreach ($porFechaPago as $filaFecha) {
        $totalRecaudado += (float) ($filaFecha['monto_recaudado'] ?? 0);
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recaudación | MSP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="container-fluid py-4 px-3 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div><p class="text-uppercase small text-muted mb-1">MSP / Indicadores</p><h1 class="h2 mb-1">Recaudación y cumplimiento mensual</h1><p class="text-muted mb-0">Dos lecturas distintas: deuda del período y dinero recibido físicamente.</p></div>
        <a class="btn btn-outline-secondary" href="<?php echo msp2Escape(msp2Url('dashboard/index.php')); ?>">Volver al Dashboard</a>
    </div>
    <form method="get" class="card shadow-sm border-0 mb-4"><div class="card-body row g-2 align-items-end"><div class="col-12 col-md-4"><label class="form-label" for="periodo">Mes a analizar</label><input class="form-control" type="month" id="periodo" name="periodo" value="<?php echo msp2Escape($periodo); ?>"></div><div class="col-12 col-md-auto"><button class="btn btn-primary" type="submit">Actualizar reporte</button></div></div></form>
    <?php if ($error !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php else: ?>
        <section class="card shadow-sm border-0 mb-4"><div class="card-header bg-white"><h2 class="h5 mb-1">1. Devengado / período de cobro: <?php echo msp2Escape($periodoTitulo); ?></h2><div class="small text-muted">Cada pago se imputa al mes de la deuda, aunque haya sido recibido después. No mezcla pagos atrasados con el mes de ingreso.</div></div><div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>Concepto</th><th class="text-end">Cobrado</th><th class="text-end">Pagado contra esta deuda</th><th class="text-end">Pendiente</th></tr></thead><tbody><?php foreach ($porPeriodo as $fila): ?><tr><td><?php echo msp2Escape((string) $fila['concepto']); ?></td><td class="text-end"><?php echo msp2Escape(msp2RecaudacionMonto($fila['cobrado'])); ?></td><td class="text-end text-success"><?php echo msp2Escape(msp2RecaudacionMonto($fila['pagado'])); ?></td><td class="text-end <?php echo $fila['pendiente'] > 0.005 ? 'text-danger' : ''; ?>"><?php echo msp2Escape(msp2RecaudacionMonto($fila['pendiente'])); ?></td></tr><?php endforeach; ?></tbody><tfoot class="table-light fw-bold"><tr><td>Total</td><td class="text-end"><?php echo msp2Escape(msp2RecaudacionMonto($totalPeriodo['cobrado'])); ?></td><td class="text-end"><?php echo msp2Escape(msp2RecaudacionMonto($totalPeriodo['pagado'])); ?></td><td class="text-end"><?php echo msp2Escape(msp2RecaudacionMonto($totalPeriodo['pendiente'])); ?></td></tr></tfoot></table></div><div class="card-body pt-2"><small class="text-muted">El pago por concepto se toma desde la imputación registrada. Arriendo se muestra separado en neto e IVA.</small></div></section>
        <section class="card shadow-sm border-0"><div class="card-header bg-white"><h2 class="h5 mb-1">2. Recaudación real: dinero recibido entre <?php echo msp2Escape($periodoFecha->format('d-m-Y')); ?> y <?php echo msp2Escape($periodoFecha->modify('last day of this month')->format('d-m-Y')); ?></h2><div class="small text-muted">Aquí importa la fecha física de pago; se muestra a qué período de deuda correspondía cada ingreso.</div></div><div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>Período de la deuda pagada</th><th class="text-end">Pagos</th><th class="text-end">Dinero recibido</th><th>Estado</th></tr></thead><tbody><?php if ($porFechaPago === []): ?><tr><td colspan="4" class="text-center text-muted py-4">No hay pagos recibidos durante este mes.</td></tr><?php else: foreach ($porFechaPago as $fila): $esAtrasado = substr((string) ($fila['periodo_deuda'] ?? ''), 0, 7) !== $periodo; ?><tr><td><?php echo msp2Escape(msp2RecaudacionPeriodo((string) ($fila['periodo_deuda'] ?? ''))); ?></td><td class="text-end"><?php echo (int) ($fila['pagos'] ?? 0); ?></td><td class="text-end fw-semibold"><?php echo msp2Escape(msp2RecaudacionMonto($fila['monto_recaudado'] ?? 0)); ?></td><td><?php echo $esAtrasado ? '<span class="badge text-bg-warning">Pago atrasado</span>' : '<span class="badge text-bg-success">Mes actual</span>'; ?></td></tr><?php endforeach; endif; ?></tbody><tfoot class="table-light fw-bold"><tr><td colspan="2">Total entrado en <?php echo msp2Escape($periodoTitulo); ?></td><td class="text-end"><?php echo msp2Escape(msp2RecaudacionMonto($totalRecaudado)); ?></td><td></td></tr></tfoot></table></div></section>
    <?php endif; ?>
</main>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body></html>
