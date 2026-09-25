<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAnyAccess(['MSP Operacion', 'MSP Cobranza']);

$flash = msp2PullFlash();
$buscar = msp2SearchQuery($_GET['buscar'] ?? '');
$contratos = [];
$errorCarga = '';
try {
    $search = msp2BuildSearchCondition($buscar, [
        'c.id_contrato_arriendo',
        't.nombre_comercial',
        'a.nombre_locatario',
        'a.rut',
        "REPLACE(REPLACE(REPLACE(a.rut,N'.',N''),N'-',N''),N' ',N'')",
        'loc.locales',
        "REPLACE(REPLACE(loc.locales,N'-',N''),N'.',N'')",
    ], 'cierre_buscar', 'c.id_contrato_arriendo');
    $stmt = $conn->prepare(
        "SELECT c.id_contrato_arriendo,c.estado_contrato,c.fecha_inicio,c.fecha_termino_efectiva,
                t.nombre_comercial,a.nombre_locatario,a.rut,
                ISNULL(loc.locales,N'Sin local') locales,
                ISNULL(d.saldo_pendiente,0) saldo_pendiente,ISNULL(d.documentos_pendientes,0) documentos_pendientes
         FROM dbo.msp_contratos_arriendo c
         INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
         INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
         OUTER APPLY (
            SELECT STRING_AGG(CONVERT(nvarchar(max),z.cdo_local),N' / ') WITHIN GROUP (ORDER BY z.cdo_local) locales
            FROM (SELECT DISTINCT l.cdo_local FROM dbo.msp_contrato_locales cl INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local WHERE cl.id_contrato_arriendo=c.id_contrato_arriendo) z
         ) loc
         OUTER APPLY (
            SELECT SUM(CASE WHEN dc.estado_documento IN (2,3) THEN dc.saldo_pendiente ELSE 0 END) saldo_pendiente,
                   SUM(CASE WHEN dc.estado_documento IN (2,3) AND dc.saldo_pendiente>0 THEN 1 ELSE 0 END) documentos_pendientes
            FROM dbo.msp_documentos_cobro dc WHERE dc.id_contrato_arriendo=c.id_contrato_arriendo
         ) d
         WHERE c.estado_contrato IN (1,2,3,4)
           AND {$search['sql']}
         ORDER BY CASE c.estado_contrato WHEN 3 THEN 1 WHEN 1 THEN 2 WHEN 2 THEN 2 ELSE 3 END,c.id_contrato_arriendo DESC"
    );
    foreach ($search['params'] as $name => $value) {
        $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $contratos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {
    $errorCarga = 'No fue posible cargar los contratos para término y cierre.';
}

$activos = array_values(array_filter($contratos, static fn(array $r): bool => in_array((int) $r['estado_contrato'], [1, 2], true)));
$enCierre = array_values(array_filter($contratos, static fn(array $r): bool => (int) $r['estado_contrato'] === 3));
$cerrados = array_values(array_filter($contratos, static fn(array $r): bool => (int) $r['estado_contrato'] === 4));
$totalActivos = count($activos);
$totalEnCierre = count($enCierre);
$totalCerrados = count($cerrados);
$activosVisibles = $activos;
$cerradosVisibles = $cerrados;
$fmtFecha = static function (mixed $v): string {
    if (trim((string) $v) === '') return '—';
    try { return (new DateTimeImmutable((string) $v))->format('d-m-Y'); } catch (Throwable) { return (string) $v; }
};
$fmtMonto = static fn(mixed $v): string => '$ ' . number_format((float) $v, 0, ',', '.');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Término y cierre de contratos | MSP</title>
<link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
<link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-xl-4">
<header class="cc-head"><div><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Volver al menú MSP</a></div><div><div class="cc-crumb"><a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>">MSP</a> › Término y cierre</div><h1>Término y cierre de contratos</h1></div><div></div></header>
<?php if (is_array($flash)): ?><div class="alert alert-<?php echo msp2Escape((string) ($flash['type'] ?? 'info')); ?> py-2"><?php echo msp2Escape((string) ($flash['message'] ?? '')); ?></div><?php endif; ?>
<?php if ($errorCarga !== ''): ?><div class="alert alert-danger py-2"><?php echo msp2Escape($errorCarga); ?></div><?php endif; ?>
<form class="row g-2 align-items-end mb-3"><div class="col"><label class="form-label fw-semibold mb-1" for="buscar">Contrato, tienda, arrendatario, RUT o local</label><input type="search" class="form-control form-control-sm" id="buscar" name="buscar" value="<?php echo msp2Escape($buscar); ?>" placeholder="Ej.: óptica A5 o #4"></div><div class="col-auto"><button class="btn btn-primary btn-sm px-4"><i class="bi bi-search me-1"></i>Buscar</button></div><?php if ($buscar !== ''): ?><div class="col-auto"><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('cierre/index.php')); ?>">Limpiar</a></div><?php endif; ?></form>
<div class="cc-kpis"><div class="cc-kpi"><i class="bi bi-file-earmark-check text-primary"></i><div><strong><?php echo $totalActivos; ?></strong><span>Activos para iniciar término</span></div></div><div class="cc-kpi"><i class="bi bi-hourglass-split text-warning"></i><div><strong><?php echo $totalEnCierre; ?></strong><span>En liquidación</span></div></div><div class="cc-kpi"><i class="bi bi-archive text-secondary"></i><div><strong><?php echo $totalCerrados; ?></strong><span>Cerrados en el resultado</span></div></div></div>
<?php
$render = static function (array $rows, string $kind) use ($fmtFecha, $fmtMonto, $buscar): void {
    $empty = $kind === 'active' ? 'No hay contratos activos que coincidan.' : ($kind === 'closing' ? 'No hay contratos en proceso de cierre.' : 'No hay contratos cerrados que coincidan.'); ?>
    <div class="table-responsive"><table class="table table-hover cc-table"><thead class="table-light"><tr><th class="w-id">Contrato</th><th class="w-name">Arrendatario</th><th class="w-store">Tienda</th><th class="w-local">Locales</th><th class="w-date"><?php echo $kind === 'active' ? 'Inicio' : 'Término'; ?></th><th class="w-money">Saldo</th><th class="w-actions text-end">Acciones</th></tr></thead><tbody>
    <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-muted py-3"><?php echo msp2Escape($empty); ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $id=(int)$r['id_contrato_arriendo']; $returnToCierre='cierre/index.php'.($buscar!==''?'?buscar='.rawurlencode($buscar):''); $fichaUrl='contratos/ficha.php?'.http_build_query(['id_contrato_arriendo'=>$id,'return_to'=>$returnToCierre]); ?><tr><td><strong>#<?php echo $id; ?></strong></td><td class="clip" title="<?php echo msp2Escape((string)$r['nombre_locatario']); ?>"><?php echo msp2Escape((string)$r['nombre_locatario']); ?><div class="small text-muted"><?php echo msp2Escape((string)$r['rut']); ?></div></td><td class="clip" title="<?php echo msp2Escape((string)$r['nombre_comercial']); ?>"><?php echo msp2Escape((string)$r['nombre_comercial']); ?></td><td class="clip" title="<?php echo msp2Escape((string)$r['locales']); ?>"><?php echo msp2Escape((string)$r['locales']); ?></td><td><?php echo msp2Escape($fmtFecha($kind==='active'?$r['fecha_inicio']:$r['fecha_termino_efectiva'])); ?></td><td class="<?php echo (float)$r['saldo_pendiente']>.005?'text-danger fw-semibold':''; ?>"><?php echo msp2Escape($fmtMonto($r['saldo_pendiente'])); ?><?php if ((int)$r['documentos_pendientes']>0): ?><div class="small text-muted"><?php echo (int)$r['documentos_pendientes']; ?> doc.</div><?php endif; ?></td><td class="actions"><a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2Url($fichaUrl)); ?>">Ver ficha</a> <?php if($kind==='active'): ?><button class="btn btn-warning btn-sm js-terminar" type="button" data-bs-toggle="modal" data-bs-target="#modalTermino" data-id="<?php echo $id; ?>" data-inicio="<?php echo msp2Escape(substr((string)$r['fecha_inicio'],0,10)); ?>" data-label="Contrato #<?php echo $id; ?> · <?php echo msp2Escape((string)$r['nombre_comercial']); ?>">Iniciar término</button><?php elseif($kind==='closing'): ?><a class="btn btn-outline-warning btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/liquidacion_final.php?id_contrato_arriendo='.$id)); ?>">Revisar liquidación</a><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php
}; ?>
<section class="cc-section"><h2>1. Iniciar término operativo</h2><p>Libera los locales y deja el contrato en proceso de cierre; no elimina su historia.</p><?php $render($activosVisibles,'active'); ?></section>
<section class="cc-section"><h2>2. Liquidación y cierre financiero</h2><p>Revisa lecturas, cargos, garantías y deuda antes del cierre definitivo.</p><?php $render($enCierre,'closing'); ?></section>
<section class="cc-section"><h2>3. Historial cerrado</h2><p>Consulta contratos finalizados y su trazabilidad.</p><?php $render($cerradosVisibles,'closed'); ?></section>
</main>
<div class="modal fade" id="modalTermino" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/cerrar.php')); ?>" data-confirm-message="¿Registrar el término operativo de este contrato?" data-confirm-title="Terminar contrato" data-confirm-variant="warning"><div class="modal-header"><h2 class="modal-title fs-5">Iniciar término operativo</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar término de contrato" title="Cerrar término de contrato"></button></div><div class="modal-body"><p class="small text-muted" id="terminoLabel"></p><input type="hidden" name="id_contrato_arriendo" id="terminoId"><input type="hidden" name="redirect_to" value="cierre/index.php"><div class="mb-3"><label class="form-label" for="terminoFecha">Fecha de término efectiva</label><input type="date" class="form-control" id="terminoFecha" name="fecha_termino_efectiva" value="<?php echo date('Y-m-d'); ?>" required></div><div class="alert alert-light border small py-2" id="terminoPrecheck">Selecciona una fecha para validar el término.</div><div class="mb-3"><label class="form-label" for="terminoMotivo">Motivo</label><textarea class="form-control" id="terminoMotivo" name="motivo_cierre" rows="3" maxlength="500" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-warning" id="terminoSubmit" disabled>Registrar término</button></div></form></div></div>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script><script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?>>
const precheckUrl=<?php echo pgpJsonForHtml(msp2Url('contratos/precheck_termino.php'), '""'); ?>;
const esc=function(v){const e=document.createElement('div');e.textContent=String(v||'');return e.innerHTML;};
const validarTermino=function(){const id=terminoId.value,fecha=terminoFecha.value;terminoSubmit.disabled=true;terminoPrecheck.textContent='Validando condiciones...';if(!id||!fecha)return;fetch(precheckUrl+'?'+new URLSearchParams({id_contrato_arriendo:id,fecha_termino_efectiva:fecha}),{headers:{Accept:'application/json'}}).then(r=>r.json()).then(data=>{if(!data||data.ok!==true){throw new Error(data&&data.message?data.message:'No fue posible validar.');}const bloqueos=Array.isArray(data.bloqueos)?data.bloqueos:[],avisos=Array.isArray(data.avisos)?data.avisos:[];if(bloqueos.length){terminoPrecheck.innerHTML='<span class="text-danger">'+esc(bloqueos.join(' '))+'</span>';return;}terminoSubmit.disabled=false;terminoPrecheck.innerHTML='<span class="text-success">Sin bloqueos. Puedes continuar.</span>'+(avisos.length?'<div class="text-warning mt-1">'+esc(avisos.join(' '))+'</div>':'');}).catch(e=>{terminoPrecheck.innerHTML='<span class="text-danger">'+esc(e.message||'No fue posible validar.')+'</span>';});};
document.querySelectorAll('.js-terminar').forEach(function(b){b.addEventListener('click',function(){terminoId.value=this.dataset.id||'';terminoFecha.min=this.dataset.inicio||'';terminoLabel.textContent=this.dataset.label||'';terminoMotivo.value='';validarTermino();});});terminoFecha.addEventListener('change',validarTermino);
</script>
<?php echo msp2RenderCsrfAutoFieldScript(); ?>
</body></html>
