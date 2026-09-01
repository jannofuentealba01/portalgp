<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$idContratoArriendo = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($idContratoArriendo === false || $idContratoArriendo === null) {
    msp2SetFlash('warning', 'Debes indicar un contrato válido para ver la liquidación final.');
    msp2Redirect('contratos/index.php');
}

function msp2LiquidacionMonto(mixed $value): string
{
    return '$ ' . number_format((float) ($value ?? 0), 0, ',', '.');
}

function msp2LiquidacionFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }
    try {
        return (new DateTimeImmutable($value))->format('d-m-Y');
    } catch (Throwable) {
        return '-';
    }
}

function msp2LiquidacionBadge(bool $ok, string $okText = 'COMPLETO', string $badText = 'PENDIENTE'): array
{
    return $ok ? [$okText, 'text-bg-success'] : [$badText, 'text-bg-warning text-dark'];
}

$contrato = null;
$locales = [];
$garantias = [];
$deuda = ['saldo_pendiente' => 0.0, 'documentos' => 0, 'vencida' => 0.0];
$cargosPendientes = 0;
$reservasGarantia = 0;
$documentosPendientes = [];
$lecturasFinales = [];
$bloqueos = [];

try {
    foreach (['msp_contratos_arriendo', 'msp_arrendatarios', 'msp_tiendas'] as $table) {
        if (!msp2TableExists($conn, $table)) {
            throw new RuntimeException('Falta la tabla `' . $table . '` para mostrar la liquidación final.');
        }
    }

    $stmtContrato = $conn->prepare(
        'SELECT c.id_contrato_arriendo, c.estado_contrato, c.id_tienda, c.id_arrendatario, c.fecha_inicio, c.fecha_termino_efectiva,
                t.nombre_comercial, a.nombre_locatario, a.rut
         FROM dbo.msp_contratos_arriendo c
         INNER JOIN dbo.msp_tiendas t ON t.id_tienda = c.id_tienda
         INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = c.id_arrendatario
         WHERE c.id_contrato_arriendo = :id_contrato'
    );
    $stmtContrato->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
    $stmtContrato->execute();
    $contrato = $stmtContrato->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$contrato) {
        throw new RuntimeException('El contrato solicitado no existe.');
    }

    $estadoContrato = (int) ($contrato['estado_contrato'] ?? 0);
    if ($estadoContrato !== 3) {
        $bloqueos[] = 'El contrato todavía no está en proceso de cierre.';
    }

    if (msp2TableExists($conn, 'msp_contrato_locales') && msp2TableExists($conn, 'msp_locales')) {
        $stmtLocales = $conn->prepare(
            'SELECT l.cdo_local, cl.fecha_inicio, cl.fecha_termino, cl.estado_relacion
             FROM dbo.msp_contrato_locales cl
             INNER JOIN dbo.msp_locales l ON l.id_local = cl.id_local
             WHERE cl.id_contrato_arriendo = :id_contrato
             ORDER BY ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
        );
        $stmtLocales->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtLocales->execute();
        $locales = $stmtLocales->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // Checklist operacional: última lectura disponible por medidor del contrato.
    if (msp2TableExists($conn, 'msp_medidores') && msp2TableExists($conn, 'msp_lecturas_medidores')) {
        $stmtLecturasFinales = $conn->prepare(
            'SELECT m.id_medidor, m.codigo_medidor, l.cdo_local, ts.codigo_servicio,
                    ult.periodo_facturacion, ult.fecha_lectura, ult.lectura_actual
             FROM dbo.msp_contrato_locales cl
             INNER JOIN dbo.msp_locales l ON l.id_local = cl.id_local
             INNER JOIN dbo.msp_medidores m ON m.id_local = l.id_local
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio = m.id_tipo_servicio
             OUTER APPLY (
                SELECT TOP (1) lm.periodo_facturacion, lm.fecha_lectura, lm.lectura_actual
                FROM dbo.msp_lecturas_medidores lm
                WHERE lm.id_medidor = m.id_medidor
                ORDER BY lm.periodo_facturacion DESC, lm.id_lectura DESC
             ) ult
             WHERE cl.id_contrato_arriendo = :id_contrato
             ORDER BY l.cdo_local, ts.id_tipo_servicio, m.codigo_medidor'
        );
        $stmtLecturasFinales->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtLecturasFinales->execute();
        $lecturasFinales = $stmtLecturasFinales->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (msp2TableExists($conn, 'msp_garantias') && msp2TableExists($conn, 'msp_vw_garantias_control_integral')) {
        $stmtGarantias = $conn->prepare(
            'SELECT g.id_garantia, l.cdo_local, gr.monto_pactado, gr.monto_recibido, gr.monto_aplicado, gr.monto_devuelto, gr.monto_disponible, gr.monto_reservado
             FROM dbo.msp_garantias g
             INNER JOIN dbo.msp_vw_garantias_control_integral gr ON gr.id_garantia = g.id_garantia
             INNER JOIN dbo.msp_locales l ON l.id_local = g.id_local
             WHERE g.id_contrato_arriendo = :id_contrato
               AND g.estado_garantia <> 6
             ORDER BY l.cdo_local ASC'
        );
        $stmtGarantias->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtGarantias->execute();
        $garantias = $stmtGarantias->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (msp2TableExists($conn, 'msp_documentos_cobro')) {
        $stmtDeuda = $conn->prepare(
            'SELECT
                COUNT(*) AS total_documentos,
                ISNULL(SUM(CASE WHEN dc.estado_documento IN (2,3) THEN dc.saldo_pendiente ELSE 0 END), 0) AS saldo_pendiente,
                ISNULL(SUM(CASE WHEN dc.estado_documento IN (2,3) AND dc.fecha_vencimiento <= CONVERT(date, SYSDATETIME()) THEN dc.saldo_pendiente ELSE 0 END), 0) AS deuda_vencida
             FROM dbo.msp_documentos_cobro dc
             WHERE dc.id_contrato_arriendo = :id_contrato
               AND dc.estado_documento IN (1,2,3)'
        );
        $stmtDeuda->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtDeuda->execute();
        $deuda = $stmtDeuda->fetch(PDO::FETCH_ASSOC) ?: $deuda;

        $stmtDocsPendientes = $conn->prepare(
            'SELECT TOP (20) dc.numero_documento, dc.periodo_facturacion, dc.fecha_vencimiento, dc.saldo_pendiente, dc.estado_documento
             FROM dbo.msp_documentos_cobro dc
             WHERE dc.id_contrato_arriendo = :id_contrato
               AND dc.estado_documento IN (1,2,3)
               AND dc.saldo_pendiente > 0
             ORDER BY dc.fecha_vencimiento ASC, dc.id_documento_cobro ASC'
        );
        $stmtDocsPendientes->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtDocsPendientes->execute();
        $documentosPendientes = $stmtDocsPendientes->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
        $stmtCargos = $conn->prepare(
            'SELECT SUM(CASE WHEN ccl.estado_cargo = 2 THEN 1 ELSE 0 END)
             FROM dbo.msp_cargos_contrato_local ccl
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local = ccl.id_contrato_local
             WHERE cl.id_contrato_arriendo = :id_contrato
               AND ccl.estado_cargo IN (1,2)'
        );
        $stmtCargos->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtCargos->execute();
        $cargosPendientes = (int) $stmtCargos->fetchColumn();
    }

    if (msp2TableExists($conn, 'msp_vw_garantias_control_integral')) {
        $stmtReservas = $conn->prepare(
            'SELECT COUNT(*)
              FROM dbo.msp_garantias g
             INNER JOIN dbo.msp_vw_garantias_control_integral gr ON gr.id_garantia = g.id_garantia
             WHERE g.id_contrato_arriendo = :id_contrato
               AND g.estado_garantia <> 6
               AND gr.monto_reservado > 0'
        );
        $stmtReservas->bindValue(':id_contrato', $idContratoArriendo, PDO::PARAM_INT);
        $stmtReservas->execute();
        $reservasGarantia = (int) $stmtReservas->fetchColumn();
    }

    if ($cargosPendientes > 0) {
        $bloqueos[] = 'Existen cargos reservados; libéralos o aplícalos antes del cierre.';
    }
    if ($reservasGarantia > 0) {
        $bloqueos[] = 'Existen saldos reservados en garantía.';
    }

    $periodoTermino = !empty($contrato['fecha_termino_efectiva'])
        ? substr((string) $contrato['fecha_termino_efectiva'], 0, 7)
        : null;
    $lecturasFaltantes = array_values(array_filter($lecturasFinales, static function (array $lectura) use ($periodoTermino): bool {
        if (($lectura['periodo_facturacion'] ?? null) === null) {
            return true;
        }
        return $periodoTermino !== null && substr((string) $lectura['periodo_facturacion'], 0, 7) < $periodoTermino;
    }));
} catch (Throwable $exception) {
    msp2SetFlash('danger', $exception->getMessage() !== '' ? $exception->getMessage() : 'No fue posible cargar la liquidación final.');
    msp2Redirect('contratos/index.php');
}

$lecturasFaltantes = $lecturasFaltantes ?? [];
$lecturasFinalesOk = $lecturasFaltantes === [];

$saldoGarantia = 0.0;
foreach ($garantias as $garantia) {
    $saldoGarantia += (float) ($garantia['monto_disponible'] ?? 0);
}
$deudaSaldo = (float) ($deuda['saldo_pendiente'] ?? 0);
$deudaVencida = (float) ($deuda['deuda_vencida'] ?? 0);
$deudaResidualAdmisible = $deudaSaldo > 0.005
    && $cargosPendientes === 0
    && $reservasGarantia === 0
    && $saldoGarantia <= 0.005
    && $lecturasFinalesOk;
$puedeCerrar = $bloqueos === []
    && (int) ($contrato['estado_contrato'] ?? 0) === 3
    && ($deudaSaldo <= 0.005 || $deudaResidualAdmisible);
$queryRetorno = http_build_query(['id_contrato_arriendo' => $idContratoArriendo]);
$periodoCorteSugerido = '-';
if (!empty($contrato['fecha_termino_efectiva'])) {
    try {
        $periodoCorteSugerido = (new DateTimeImmutable((string) $contrato['fecha_termino_efectiva']))->format('Y-m');
    } catch (Throwable) {
        $periodoCorteSugerido = '-';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Liquidación final | MSP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-4 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <p class="text-muted mb-1">MSP / Contratos</p>
            <h1 class="h3 mb-1">Liquidación final de contrato</h1>
            <p class="text-muted mb-0">Revisa deuda, garantía y bloqueos antes de cerrar definitivamente.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-dark btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?' . $queryRetorno)); ?>"><i class="bi bi-arrow-left me-1"></i>Volver a ficha</a>
            <a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2Url('cierre/index.php')); ?>">Volver a término y cierre</a>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4"><div class="small text-muted">Arrendatario</div><div class="fw-semibold"><?php echo msp2Escape((string) ($contrato['nombre_locatario'] ?? '-')); ?></div><div class="small text-muted"><?php echo msp2Escape((string) ($contrato['rut'] ?? '-')); ?></div></div>
                <div class="col-md-4"><div class="small text-muted">Contrato</div><div class="fw-semibold">#<?php echo (int) ($contrato['id_contrato_arriendo'] ?? 0); ?></div><div class="small text-muted">Estado: <?php echo (int) ($contrato['estado_contrato'] ?? 0) === 3 ? 'En proceso de cierre' : 'Otro'; ?></div></div>
                <div class="col-md-4"><div class="small text-muted">Local / tienda</div><div class="fw-semibold"><?php echo msp2Escape((string) ($contrato['nombre_comercial'] ?? '-')); ?></div><div class="small text-muted"><?php echo msp2Escape(implode(' / ', array_values(array_filter(array_map(static fn($r) => trim((string) ($r['cdo_local'] ?? '')), $locales)))) ?: '-'); ?></div></div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Deuda total</div><div class="h4 mb-0 text-danger"><?php echo msp2Escape(msp2LiquidacionMonto($deudaSaldo)); ?></div><div class="small text-muted"><?php echo (int) ($deuda['total_documentos'] ?? 0); ?> documentos</div></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Deuda vencida</div><div class="h4 mb-0 text-warning"><?php echo msp2Escape(msp2LiquidacionMonto($deudaVencida)); ?></div><div class="small text-muted">Hasta hoy</div></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Garantía disponible</div><div class="h4 mb-0 text-success"><?php echo msp2Escape(msp2LiquidacionMonto($saldoGarantia)); ?></div><div class="small text-muted"><?php echo count($garantias); ?> garantía(s)</div></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="small text-muted">Resultado estimado</div><div class="h4 mb-0"><?php echo msp2Escape(msp2LiquidacionMonto(max(0.0, $deudaSaldo - $saldoGarantia))); ?></div><div class="small text-muted">Deuda restante estimada</div></div></div></div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center gap-2">
            <span>Servicios finales y lecturas</span>
            <span class="badge <?php echo $lecturasFinalesOk ? 'text-bg-success' : 'text-bg-warning text-dark'; ?>"><?php echo $lecturasFinalesOk ? 'Revisado' : 'Pendiente'; ?></span>
        </div>
        <div class="card-body">
            <?php if ($lecturasFinales === []): ?>
                <p class="text-muted mb-0">No hay medidores asociados al contrato; no se requiere lectura final.</p>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Local</th><th>Servicio</th><th>Medidor</th><th>Último período</th><th>Fecha lectura</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($lecturasFinales as $lectura): $falta = in_array($lectura, $lecturasFaltantes, true); ?>
                        <tr>
                            <td><?php echo msp2Escape((string) ($lectura['cdo_local'] ?? '-')); ?></td>
                            <td><?php echo msp2Escape((string) ($lectura['codigo_servicio'] ?? '-')); ?></td>
                            <td><?php echo msp2Escape((string) ($lectura['codigo_medidor'] ?? '-')); ?></td>
                            <td><?php echo msp2Escape(substr((string) ($lectura['periodo_facturacion'] ?? ''), 0, 7) ?: '-'); ?></td>
                            <td><?php echo msp2Escape(msp2LiquidacionFecha((string) ($lectura['fecha_lectura'] ?? ''))); ?></td>
                            <td><span class="badge <?php echo $falta ? 'text-bg-warning text-dark' : 'text-bg-success'; ?>"><?php echo $falta ? 'Actualizar lectura' : 'Disponible'; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php if (!$lecturasFinalesOk): ?><div class="small text-warning-emphasis mt-2">Hay medidores sin lectura en el mes del término. Registra las lecturas en Operación mensual antes de cerrar.</div><?php endif; ?>
                <?php if ($periodoCorteSugerido !== '-'): ?><a class="btn btn-outline-primary btn-sm mt-3" href="<?php echo msp2Escape(msp2Url('cobros/operacion_mensual.php?periodo=' . urlencode($periodoCorteSugerido))); ?>">Ir a Operación mensual</a><?php else: ?><a class="btn btn-outline-primary btn-sm mt-3" href="<?php echo msp2Escape(msp2Url('cobros/operacion_mensual.php')); ?>">Ir a Operación mensual</a><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($bloqueos !== []): ?>
        <div class="alert alert-warning">
            <strong>Bloqueos para cierre definitivo:</strong>
            <ul class="mb-0 mt-2">
                <?php foreach ($bloqueos as $bloqueo): ?>
                    <li><?php echo msp2Escape($bloqueo); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($deudaSaldo > 0.005): ?>
        <div class="alert alert-info">
            <strong>Saldo residual detectado: <?php echo msp2Escape(msp2LiquidacionMonto($deudaSaldo)); ?>.</strong>
            <?php if ($deudaResidualAdmisible): ?>
                La garantía ya no tiene reservas y no quedan cargos reservados. Puedes cerrar el contrato confirmando la derivación de este saldo a <strong>Deudores exarrendatarios</strong>. Los documentos y cargos se conservarán con su saldo real.
            <?php else: ?>
                El saldo se podrá derivar a <strong>Deudores exarrendatarios</strong> cuando se completen las lecturas finales, se resuelvan los cargos o reservas pendientes y la garantía disponible haya sido aplicada o devuelta.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Garantías</div>
                <div class="card-body">
                    <?php if ($garantias === []): ?>
                        <p class="text-muted mb-0">No hay garantías registradas para este contrato.</p>
                    <?php else: ?>
                        <?php foreach ($garantias as $garantia): ?>
                            <div class="border-bottom py-2 d-flex justify-content-between gap-2">
                                <div>
                                    <div class="fw-semibold">Local <?php echo msp2Escape((string) ($garantia['cdo_local'] ?? '-')); ?></div>
                                    <div class="small text-muted">Garantía #<?php echo (int) ($garantia['id_garantia'] ?? 0); ?></div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-semibold">Disponible: <?php echo msp2Escape(msp2LiquidacionMonto((float) ($garantia['monto_disponible'] ?? 0))); ?></div>
                                    <div class="small text-muted">Reservado: <?php echo msp2Escape(msp2LiquidacionMonto((float) ($garantia['monto_reservado'] ?? 0))); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <?php if ($saldoGarantia > 0.005 && $deudaSaldo > 0.005): ?><a class="btn btn-warning btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/aplicaciones.php?id_contrato_arriendo=' . $idContratoArriendo)); ?>">Aplicar garantía a deuda</a><?php endif; ?>
                        <?php if ($saldoGarantia > 0.005 && $deudaSaldo <= 0.005 && $reservasGarantia === 0): ?><a class="btn btn-outline-success btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/devoluciones.php?id_contrato_arriendo=' . $idContratoArriendo)); ?>">Gestionar devolución</a><?php endif; ?>
                        <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('deuda_garantia/index.php?filtroTienda=' . (int) ($contrato['id_tienda'] ?? 0))); ?>">Ver historial de garantías</a>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Documentos pendientes</div>
                <div class="card-body">
                    <?php if ($documentosPendientes === []): ?>
                        <p class="text-muted mb-0">No se detectan documentos pendientes para este contrato.</p>
                    <?php else: ?>
                        <?php foreach ($documentosPendientes as $doc): ?>
                            <div class="border-bottom py-2 d-flex justify-content-between gap-2">
                                <div>
                                    <div class="fw-semibold"><?php echo msp2Escape((string) ($doc['numero_documento'] ?? 'Sin número')); ?></div>
                                    <div class="small text-muted">Periodo <?php echo msp2Escape(substr((string) ($doc['periodo_facturacion'] ?? ''), 0, 7)); ?> · Vence <?php echo msp2Escape(msp2LiquidacionFecha((string) ($doc['fecha_vencimiento'] ?? ''))); ?></div>
                                </div>
                                <div class="fw-semibold"><?php echo msp2Escape(msp2LiquidacionMonto((float) ($doc['saldo_pendiente'] ?? 0))); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">Checklist de liquidación</div>
        <div class="card-body">
            <div class="row g-3">
                <?php
                $checklist = [
                    ['Salida registrada', true, 'El contrato ya está en proceso de cierre.'],
                    ['Local liberado', true, 'La ocupación física ya no debe bloquear nuevos contratos.'],
                    ['Deuda determinada', $deudaSaldo >= 0, 'La deuda se puede medir desde documentos vigentes.'],
                    ['Garantía evaluada', true, 'Se conoce el saldo disponible y reservado.'],
                    ['Lecturas finales', $lecturasFinalesOk, $lecturasFinalesOk ? 'Los medidores tienen lectura suficiente para el término.' : 'Faltan lecturas del mes de término.'],
                    ['Sin cargos reservados', $cargosPendientes === 0, 'Los cargos pendientes sin reserva pueden formar parte de la deuda histórica; las reservas sí bloquean el cierre.'],
                    ['Sin reservas en garantía', $reservasGarantia === 0, 'No hay reservas que bloqueen la devolución o cierre.'],
                ];
                foreach ($checklist as [$titulo, $ok, $detalle]): [$label, $class] = msp2LiquidacionBadge((bool) $ok); ?>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div class="fw-semibold"><?php echo msp2Escape($titulo); ?></div>
                                <span class="badge <?php echo msp2Escape($class); ?>"><?php echo msp2Escape($label); ?></span>
                            </div>
                            <div class="small text-muted mt-2"><?php echo msp2Escape($detalle); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="post" action="<?php echo msp2Escape(msp2Url('contratos/finalizar_cierre_financiero.php')); ?>" class="row g-3 align-items-end">
                <?php msp2CsrfField(); ?>
                <input type="hidden" name="id_contrato_arriendo" value="<?php echo (int) $idContratoArriendo; ?>">
                <input type="hidden" name="redirect_to" value="contratos/ficha.php?id_contrato_arriendo=<?php echo (int) $idContratoArriendo; ?>">
                <div class="col-lg-4">
                    <label class="form-label">Periodo de corte</label>
                    <input type="month" class="form-control" name="periodo_corte_mes" value="<?php echo msp2Escape($periodoCorteSugerido); ?>" required <?php echo $puedeCerrar ? '' : 'disabled'; ?>>
                    <div class="form-text">Se sugiere el mes de término efectivo.</div>
                </div>
                <div class="col-lg-5">
                    <label class="form-label">Motivo opcional</label>
                    <input type="text" class="form-control" name="motivo_cierre_financiero" maxlength="500" placeholder="Cierre definitivo del contrato" <?php echo $puedeCerrar ? '' : 'disabled'; ?>>
                </div>
                <?php if ($deudaSaldo > 0.005): ?>
                    <div class="col-12">
                        <div class="form-check border rounded p-3 bg-light">
                            <input class="form-check-input" type="checkbox" name="derivar_deuda_historica" value="1" id="derivar_deuda_historica" required <?php echo $puedeCerrar ? '' : 'disabled'; ?>>
                            <label class="form-check-label" for="derivar_deuda_historica">
                                Confirmo que el saldo residual se deriva a Deudores exarrendatarios y que no se marcará como pagado.
                            </label>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="col-lg-3 d-grid">
                    <button type="submit" class="btn btn-dark" <?php echo $puedeCerrar ? '' : 'disabled'; ?>>Cerrar definitivamente</button>
                </div>
                <div class="col-12">
                    <div class="small text-muted">Este paso usa el cierre financiero existente y conserva todo el historial.</div>
                </div>
            </form>
        </div>
    </div>
</main>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
