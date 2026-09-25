<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/CobranzaContratoService.php';
require_once dirname(__DIR__) . '/services/CobranzaGestionService.php';

msp2RequireAccess();

$idContrato = filter_input(INPUT_GET, 'id_contrato', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$returnTo = trim((string) ($_GET['return_to'] ?? ''));
if ($returnTo === '' || preg_match('#^(?:pendientes/index\.php|cobranza/deudores_exarrendatarios\.php)(?:\?[A-Za-z0-9_\-\.\[\]%=&]*)?$#', $returnTo) !== 1) {
    $returnTo = 'pendientes/index.php';
}
$data = null;
$operacional = ['gestiones'=>[],'compromisos'=>[],'avisos'=>[],'caso'=>null];
$catalogos = ['tipos'=>[],'resultados'=>[],'plantillas'=>[]];
$error = null;
try {
    if (!$idContrato) {
        throw new RuntimeException('Debes indicar el contrato que deseas gestionar.');
    }
    $data = (new CobranzaContratoService($conn))->obtener((int) $idContrato);
    $gestionService = new CobranzaGestionService($conn);
    $operacional = $gestionService->datosContrato((int) $idContrato);
    $catalogos = $gestionService->catalogos();
} catch (Throwable $exception) {
    $error = pgpPublicOrBusinessException($exception, 'msp.cobranza.gestionar', 'No fue posible cargar la gestión de cobranza.');
}
$flash = msp2PullFlash();

function gccMonto(mixed $value): string
{
    return '$ ' . number_format((float) ($value ?? 0), 2, ',', '.');
}

function gccFecha(mixed $value): string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '') {
        return '-';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($raw, 0, 10));
    return $date instanceof DateTimeImmutable ? $date->format('d-m-Y') : $raw;
}

function gccEstadoDocumento(int $estado, float $saldo, int $diasMora): array
{
    if ($saldo <= 0.005) return ['Pagado', 'success'];
    if ($diasMora > 0) return ['Vencido', 'danger'];
    return [match ($estado) { 1 => 'Preparado', 2 => 'Emitido', 3 => 'Pago parcial', default => 'Pendiente' }, 'warning'];
}

$contrato = $data['contrato'] ?? [];
$resumen = $data['resumen'] ?? [];
$deuda = (float) ($resumen['deuda_total'] ?? 0);
$estadoOperacional = (string) (($operacional['caso']['estado_operacional'] ?? null) ?: ($deuda <= .005 ? 'RESUELTO' : 'SIN_GESTION'));
$compromisoActivo = null;
foreach ((array) $operacional['compromisos'] as $compromisoRow) {
    if (in_array((string) $compromisoRow['estado'], ['PENDIENTE','CUMPLIDO_PARCIAL','INCUMPLIDO'], true)) {
        $compromisoActivo = $compromisoRow;
        break;
    }
}
$timeline = [];
foreach ((array) $operacional['gestiones'] as $row) $timeline[]=['fecha'=>$row['fecha_gestion'],'clase'=>'gestion','icono'=>'bi-chat-left-text','titulo'=>$row['tipo_nombre'].' · '.$row['resultado_nombre'],'detalle'=>$row['observacion'],'usuario'=>$row['usuario_nombre']];
foreach ((array) $operacional['compromisos'] as $row) $timeline[]=['fecha'=>$row['fecha_creacion'],'clase'=>'compromiso','icono'=>'bi-calendar-check','titulo'=>'Compromiso '.$row['estado'].' · '.gccMonto($row['monto_comprometido']),'detalle'=>'Fecha '.gccFecha($row['fecha_comprometida']).' · Pagado evaluado '.gccMonto($row['monto_pagado_evaluado']),'usuario'=>$row['usuario_nombre']];
foreach ((array) $operacional['avisos'] as $row) $timeline[]=['fecha'=>$row['fecha_generacion'],'clase'=>'aviso','icono'=>'bi-envelope-paper','titulo'=>'Aviso '.$row['estado'].' · '.$row['plantilla_nombre'],'detalle'=>$row['asunto_snapshot'],'usuario'=>$row['usuario_generador_nombre']];
foreach ((array) ($data['eventos_financieros'] ?? []) as $row) $timeline[]=['fecha'=>$row['fecha_evento'],'clase'=>'financiero','icono'=>'bi-cash-coin','titulo'=>$row['titulo'].' · '.gccMonto($row['monto']),'detalle'=>'Documento '.($row['referencia']??'-'),'usuario'=>'Evento financiero'];
usort($timeline, static fn(array $a,array $b):int => strcmp((string)$b['fecha'],(string)$a['fecha']));
$pagoUrl = msp2Url('cobranza/registrar_pago_contrato.php?' . http_build_query([
    'id_contrato_arriendo' => (int) $idContrato,
    'id_arrendatario' => (int) ($contrato['id_arrendatario'] ?? 0),
    'contexto_contrato' => 1,
    'return_to' => 'cobranza/gestionar.php?' . http_build_query(['id_contrato' => (int) $idContrato, 'return_to' => $returnTo]),
]));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Gestión de cobranza | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light gp-module-msp">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main gcc-page p-3 p-xl-4"><div class="box-container-full gcc-shell mx-auto">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print" data-gp-commandbar>
        <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url($returnTo)); ?>"><i class="bi bi-arrow-left me-1"></i>Volver al listado</a>
        <div class="text-center">
            <p class="section-kicker text-center">MSP / Cobranza</p>
            <h1 class="form-title text-center mb-0">Gestión de cobranza por contrato</h1>
        </div>
        <div class="gcc-actions"><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/configuracion_gestion.php'));?>"><i class="bi bi-gear me-1"></i>Configurar</a><button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button></div>
    </header>
    <?php msp2RenderFlash($flash); ?>
    <?php if ($error !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php endif; ?>
    <?php if (is_array($data)): ?>
        <section class="gcc-context p-3 mb-3">
            <div class="row g-2 align-items-center"><div class="col-lg-4"><span class="small text-muted">Arrendatario</span><h2 class="mb-1"><?php echo msp2Escape((string) ($contrato['nombre_locatario'] ?: $contrato['nombre_representante'])); ?></h2><div class="small"><?php echo msp2Escape((string) $contrato['rut']); ?></div></div><div class="col-lg-3"><span class="small text-muted">Contrato y tienda</span><div class="fw-semibold">Contrato #<?php echo (int) $idContrato; ?></div><div class="small"><?php echo msp2Escape((string) $contrato['nombre_comercial']); ?></div></div><div class="col-lg-3"><span class="small text-muted">Locales</span><div class="fw-semibold"><?php echo msp2Escape(implode(' / ', array_map(static fn (array $l): string => (string) $l['cdo_local'], $data['locales'])) ?: '-'); ?></div><div class="small"><?php echo gccFecha($contrato['fecha_inicio']); ?> a <?php echo gccFecha($contrato['fecha_termino_efectiva'] ?: $contrato['fecha_termino_pactada']); ?></div></div><div class="col-lg-2 text-lg-end"><span class="badge text-bg-<?php echo (int) $contrato['estado_contrato'] === 2 ? 'success' : 'secondary'; ?> mb-1"><?php echo msp2Escape((string) $contrato['estado_contrato_nombre']); ?></span><br><span class="badge text-bg-dark"><?php echo msp2Escape(str_replace('_',' ',$estadoOperacional)); ?></span></div></div>
        </section>

        <div class="row g-2 mb-3">
            <?php foreach ([['Deuda pendiente',$resumen['deuda_total'],'danger'],['Deuda vencida',$resumen['deuda_vencida'],'warning'],['Documentos pendientes',$resumen['documentos_pendientes'],'primary'],['Documentos vencidos',$resumen['documentos_vencidos'],'danger'],['Mora máxima',(int)$resumen['mora_maxima'].' días','dark'],['Saldo a favor',$resumen['saldo_favor'],'success'],['Garantía disponible',$resumen['garantia_disponible'],'info']] as $kpi): ?>
                <div class="col-6 col-lg"><div class="gcc-kpi"><div class="gcc-kpi-label"><?php echo msp2Escape($kpi[0]); ?></div><div class="gcc-kpi-value text-<?php echo $kpi[2]; ?>"><?php echo is_numeric($kpi[1]) && !str_contains($kpi[0], 'Documentos') ? msp2Escape(gccMonto($kpi[1])) : msp2Escape((string) $kpi[1]); ?></div></div></div>
            <?php endforeach; ?>
        </div>

        <?php if ($deuda > .005): ?>
            <section class="row g-2 mb-3 no-print" aria-label="Acciones de cobranza disponibles">
                <div class="col-md-6 col-xl-3"><div class="gcc-action"><h3 class="h6"><i class="bi bi-cash-coin text-success me-1"></i>Registrar pago</h3><p class="small text-muted">Contrato precargado y saldo sugerido editable.</p><a class="btn btn-success btn-sm" href="<?php echo msp2Escape($pagoUrl); ?>">Registrar pago</a></div></div>
                <?php if ((float)$resumen['saldo_favor'] > .005): ?><div class="col-md-6 col-xl-3"><div class="gcc-action"><h3 class="h6"><i class="bi bi-wallet2 text-primary me-1"></i>Aplicar saldo a favor</h3><p class="small text-muted">Disponible <?php echo msp2Escape(gccMonto($resumen['saldo_favor'])); ?>.</p><form method="post" action="<?php echo msp2Escape(msp2Url('pagos/aplicar_saldo_favor_contrato.php')); ?>"><?php msp2CsrfField(); ?><input type="hidden" name="id_arrendatario" value="<?php echo (int)$contrato['id_arrendatario']; ?>"><input type="hidden" name="id_contrato_arriendo" value="<?php echo (int)$idContrato; ?>"><input type="hidden" name="return_to" value="<?php echo msp2Escape('cobranza/gestionar.php?' . http_build_query(['id_contrato'=>(int)$idContrato,'return_to'=>$returnTo])); ?>"><input type="hidden" name="monto_saldo_favor" value="<?php echo msp2Escape(number_format(min((float)$resumen['saldo_favor'],$deuda),2,'.','')); ?>"><button class="btn btn-primary btn-sm" onclick="return confirm('¿Aplicar el saldo a favor disponible a la deuda más antigua?')">Aplicar saldo</button></form></div></div><?php endif; ?>
                <?php if ((float)$resumen['garantia_disponible'] > .005): ?><div class="col-md-6 col-xl-3"><div class="gcc-action"><h3 class="h6"><i class="bi bi-shield-check text-warning me-1"></i>Revisar garantía</h3><p class="small text-muted">Mantiene las validaciones formales de Garantías.</p><a class="btn btn-warning btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/aplicaciones.php?' . http_build_query(['id_contrato_arriendo' => (int)$idContrato, 'return_to' => 'cobranza/gestionar.php?' . http_build_query(['id_contrato' => (int)$idContrato, 'return_to' => $returnTo])]))); ?>">Revisar aplicación</a></div></div><?php endif; ?>
                <div class="col-md-6 col-xl-3"><div class="gcc-action"><h3 class="h6"><i class="bi bi-chat-left-text text-secondary me-1"></i>Registrar gestión</h3><p class="small text-muted">Llamada, correo, WhatsApp, reunión o visita.</p><button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#modalGestion">Registrar gestión</button></div></div>
                <?php if (!is_array($compromisoActivo)): ?><div class="col-md-6 col-xl-3"><div class="gcc-action"><h3 class="h6"><i class="bi bi-calendar-check text-warning me-1"></i>Compromiso de pago</h3><p class="small text-muted">Registra una promesa sin crear un pago ficticio.</p><button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalCompromiso">Registrar compromiso</button></div></div><?php endif; ?>
                <div class="col-md-6 col-xl-3"><div class="gcc-action"><h3 class="h6"><i class="bi bi-envelope-paper text-primary me-1"></i>Aviso de cobranza</h3><p class="small text-muted">Vista previa antes de registrar la entrega.</p><button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAviso">Generar aviso</button></div></div>
            </section>
        <?php endif; ?>

        <?php if(is_array($compromisoActivo)):?><div class="alert <?php echo $compromisoActivo['estado']==='INCUMPLIDO'?'alert-danger':'alert-warning';?> d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 mb-3 no-print"><div><strong>Compromiso <?php echo msp2Escape(str_replace('_',' ',(string)$compromisoActivo['estado']));?></strong><div class="small">Monto <?php echo msp2Escape(gccMonto($compromisoActivo['monto_comprometido']));?> para el <?php echo msp2Escape(gccFecha($compromisoActivo['fecha_comprometida']));?> · Pagado evaluado <?php echo msp2Escape(gccMonto($compromisoActivo['monto_pagado_evaluado']));?></div></div><form method="post" action="accion_gestion.php" class="d-flex gap-2"><?php msp2CsrfField();?><input type="hidden" name="accion" value="CANCELAR_COMPROMISO"><input type="hidden" name="id_contrato" value="<?php echo (int)$idContrato;?>"><input type="hidden" name="id_compromiso_pago" value="<?php echo (int)$compromisoActivo['id_compromiso_pago'];?>"><input type="hidden" name="return_to" value="<?php echo msp2Escape($returnTo);?>"><input class="form-control form-control-sm" name="motivo_cancelacion" maxlength="500" placeholder="Motivo de cancelación" required><button class="btn btn-outline-danger btn-sm">Cancelar</button></form></div><?php endif;?>

        <section class="card border-0 shadow-sm mb-3 gcc-section"><div class="card-header bg-white d-flex justify-content-between align-items-center"><h2 class="h6 mb-0">Documentos del contrato</h2><span class="small text-muted"><?php echo count($data['documentos']); ?> documento(s)</span></div><div class="table-responsive gp-table-shell"><table class="table table-hover align-middle mb-0 gp-table-compact gp-table-mobile-cards gcc-documents-table"><thead class="table-light"><tr><th data-gp-column-kind="short"><span class="visually-hidden">Detalle</span></th><th>Documento / período</th><th>Emisión / vencimiento</th><th>Resumen financiero</th><th data-gp-column-kind="short">Mora</th><th data-gp-column-kind="state">Estado</th></tr></thead><tbody>
        <?php if ($data['documentos'] === []): ?><tr><td colspan="9" class="text-center text-muted py-2">No hay documentos asociados directamente a este contrato.</td></tr><?php endif; ?>
        <?php foreach ($data['documentos'] as $i => $doc): [$estadoDoc,$estadoColor]=gccEstadoDocumento((int)$doc['estado_documento'],(float)$doc['saldo_pendiente'],(int)$doc['dias_mora']); $collapse='doc-det-'.$i; ?><tr><td data-gp-label="Detalle" class="text-center"><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapse; ?>" aria-label="Ver desglose"><i class="bi bi-chevron-down"></i></button></td><td data-gp-label="Documento / período"><strong><?php echo msp2Escape((string)($doc['numero_documento'] ?: 'DOC-'.$doc['id_documento_cobro'])); ?></strong><div class="small text-muted"><?php echo msp2Escape(substr((string)$doc['periodo_facturacion'],0,7)); ?></div></td><td data-gp-label="Emisión / vencimiento"><span class="gp-data-pair"><span>Emisión</span><strong><?php echo gccFecha($doc['fecha_emision']); ?></strong></span><span class="gp-data-pair"><span>Vence</span><strong><?php echo gccFecha($doc['fecha_vencimiento']); ?></strong></span></td><td data-gp-label="Resumen financiero" class="gp-financial-cell"><span class="gp-data-pair"><span>Original</span><strong><?php echo msp2Escape(gccMonto($doc['monto_total'])); ?></strong></span><span class="gp-data-pair"><span>Pagado</span><strong><?php echo msp2Escape(gccMonto($doc['monto_pagado_calculado'])); ?></strong></span><span class="gp-data-pair gp-data-pair--total"><span>Saldo</span><strong><?php echo msp2Escape(gccMonto($doc['saldo_pendiente'])); ?></strong></span></td><td data-gp-label="Mora" class="text-center"><?php echo (int)$doc['dias_mora']; ?> días</td><td data-gp-label="Estado" class="gp-cell-state"><span class="badge text-bg-<?php echo $estadoColor; ?>"><?php echo msp2Escape($estadoDoc); ?></span></td></tr><tr class="collapse gcc-detail" id="<?php echo $collapse; ?>"><td colspan="6"><div class="p-2"><h3 class="h6">Desglose por concepto</h3><div class="table-responsive gp-table-shell"><table class="table table-sm mb-0 gp-table-compact gp-table-mobile-cards"><thead><tr><th>Concepto</th><th>Descripción</th><th class="text-end">Cantidad</th><th class="text-end">Valor unitario</th><th class="text-end">Subtotal</th></tr></thead><tbody><?php foreach ($doc['detalles'] as $detalle): ?><tr><td data-gp-label="Concepto"><?php echo msp2Escape((string)$detalle['nombre_item']); ?></td><td data-gp-label="Descripción"><?php echo msp2Escape((string)$detalle['descripcion_item']); ?></td><td data-gp-label="Cantidad" class="text-end"><?php echo msp2Escape(number_format((float)$detalle['cantidad'],2,',','.')); ?></td><td data-gp-label="Valor unitario" class="text-end"><?php echo msp2Escape(gccMonto($detalle['valor_unitario'])); ?></td><td data-gp-label="Subtotal" class="text-end"><?php echo msp2Escape(gccMonto($detalle['subtotal'])); ?></td></tr><?php endforeach; ?><?php if ($doc['detalles'] === []): ?><tr class="gp-table-empty-row"><td colspan="5" class="gp-table-empty-cell">Documento sin detalle registrado.</td></tr><?php endif; ?></tbody></table></div></div></td></tr><?php endforeach; ?>
        </tbody></table></div></section>

        <div class="row g-2 mb-3 align-items-start"><div class="col-xl-6"><section class="card border-0 shadow-sm gcc-section"><div class="card-header bg-white"><h2 class="h6 mb-0">Garantía</h2></div><div class="card-body"><div class="row g-2 mb-2"><?php foreach (['pactado'=>'Pactado','recibido'=>'Recibido','reservado'=>'Reservado','aplicado'=>'Aplicado','devuelto'=>'Devuelto','disponible'=>'Disponible'] as $campo=>$label): ?><div class="col-6"><span class="small text-muted"><?php echo $label; ?></span><div class="fw-semibold"><?php echo msp2Escape(gccMonto($data['garantia_totales'][$campo])); ?></div></div><?php endforeach; ?></div><?php foreach ($data['garantias'] as $g): ?><div class="border-top py-1 d-flex justify-content-between gap-2"><span>Local <?php echo msp2Escape((string)$g['cdo_local']); ?> · <?php echo msp2Escape(str_replace('_',' ',(string)$g['estado_recepcion'])); ?></span><strong><?php echo msp2Escape(gccMonto($g['monto_disponible'])); ?></strong></div><?php endforeach; ?><?php if ($data['garantias']===[]): ?><p class="text-muted mb-0">Sin garantías registradas.</p><?php endif; ?></div></section></div><div class="col-xl-6"><section class="card border-0 shadow-sm gcc-section"><div class="card-header bg-white"><h2 class="h6 mb-0">Cargos por mora emitidos</h2></div><div class="card-body"><?php foreach ($data['cargos_mora'] as $cargo): ?><div class="border-bottom py-1 d-flex justify-content-between gap-2"><div><strong><?php echo msp2Escape((string)$cargo['nombre_regla']); ?></strong><div class="small text-muted">Origen <?php echo msp2Escape((string)$cargo['documento_origen']); ?> · <?php echo (int)$cargo['dias_mora_calculados']; ?> días · <?php echo gccFecha($cargo['fecha_vencimiento_origen']); ?></div></div><strong><?php echo msp2Escape(gccMonto($cargo['monto_generado'])); ?></strong></div><?php endforeach; ?><?php if ($data['cargos_mora']===[]): ?><p class="text-muted mb-0">No hay multas o recargos automáticos emitidos para este contrato.</p><?php endif; ?></div></section></div></div>

        <section class="card border-0 shadow-sm gcc-section gcc-history"><div class="card-header bg-white"><h2 class="h6 mb-0">Historial combinado del caso</h2></div><div class="card-body"><?php foreach($timeline as $evento):?><div class="gcc-event <?php echo msp2Escape((string)$evento['clase']);?>"><div class="d-flex flex-wrap align-items-center gap-2"><strong><i class="bi <?php echo msp2Escape((string)$evento['icono']);?> me-1"></i><?php echo msp2Escape((string)$evento['titulo']);?></strong><time class="small text-muted"><?php echo msp2Escape(date('d-m-Y H:i',strtotime((string)$evento['fecha'])));?></time><?php if(trim((string)$evento['detalle'])!==''):?><span class="small"><?php echo msp2Escape((string)$evento['detalle']);?></span><?php endif;?><?php if(trim((string)$evento['usuario'])!==''):?><span class="small text-muted"><?php echo msp2Escape((string)$evento['usuario']);?></span><?php endif;?></div></div><?php endforeach;?><?php if($timeline===[]):?><p class="text-muted mb-0">Todavía no existen eventos para este caso.</p><?php endif;?></div></section>
    <?php endif; ?>
</div></main>
<?php if(is_array($data)) include __DIR__.'/formularios_gestion.php'; ?>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body></html>
