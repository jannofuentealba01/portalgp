<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAnyAccess(['MSP Operacion', 'MSP Cobranza']);

$flash = msp2PullFlash();
$buscar = trim((string) ($_GET['buscar'] ?? ''));
$contratos = [];
$errorCarga = '';
try {
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
           AND (:vacio=N'' OR CONVERT(nvarchar(20),c.id_contrato_arriendo) LIKE :buscar_id OR t.nombre_comercial LIKE :buscar_tienda OR a.nombre_locatario LIKE :buscar_arrendatario OR a.rut LIKE :buscar_rut OR ISNULL(loc.locales,N'') LIKE :buscar_local)
         ORDER BY CASE c.estado_contrato WHEN 3 THEN 1 WHEN 1 THEN 2 WHEN 2 THEN 2 ELSE 3 END,c.id_contrato_arriendo DESC"
    );
    $patron = '%' . $buscar . '%';
    $stmt->execute([
        ':vacio' => $buscar,
        ':buscar_id' => $patron,
        ':buscar_tienda' => $patron,
        ':buscar_arrendatario' => $patron,
        ':buscar_rut' => $patron,
        ':buscar_local' => $patron,
    ]);
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
$activosVisibles = array_slice($activos, 0, 25);
$cerradosVisibles = array_slice($cerrados, 0, 25);
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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="/portalgp/styles.css">
<style>
.cc-head{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;margin-bottom:12px}.cc-head h1{margin:0;color:var(--color-primary);font-size:clamp(1.4rem,2vw,2rem);text-align:center}.cc-crumb{text-align:center;font-size:.88rem;color:var(--color-text-muted)}
.cc-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.cc-kpi{background:#fff;border:1px solid var(--color-border);border-radius:10px;padding:10px 12px;display:flex;align-items:center;gap:10px}.cc-kpi i{font-size:1.25rem}.cc-kpi strong{font-size:1.25rem;line-height:1}.cc-kpi span{display:block;font-size:.8rem;color:var(--color-text-muted)}
.cc-section{margin-top:14px}.cc-section h2{font-size:1.05rem;margin:0}.cc-section p{font-size:.82rem;color:var(--color-text-muted);margin:0 0 6px}.cc-table{table-layout:fixed;margin:0}.cc-table th,.cc-table td{padding:.46rem .5rem;vertical-align:middle;font-size:.84rem}.cc-table .clip{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.cc-table .actions{text-align:right;white-space:nowrap}.w-id{width:7%}.w-name{width:21%}.w-store{width:19%}.w-local{width:13%}.w-date{width:10%}.w-money{width:10%}.w-actions{width:20%}
@media(max-width:991.98px){.cc-head{grid-template-columns:1fr}.cc-head h1{grid-row:1}.cc-head>div:first-child{grid-row:2}.cc-crumb{display:none}.cc-kpis{grid-template-columns:1fr}.cc-table{min-width:900px}}
</style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-xl-4">
<header class="cc-head"><div><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Volver al menú MSP</a></div><div><div class="cc-crumb"><a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>">MSP</a> › Término y cierre</div><h1>Término y cierre de contratos</h1></div><div></div></header>
<?php if (is_array($flash)): ?><div class="alert alert-<?php echo msp2Escape((string) ($flash['type'] ?? 'info')); ?> py-2"><?php echo msp2Escape((string) ($flash['message'] ?? '')); ?></div><?php endif; ?>
<?php if ($errorCarga !== ''): ?><div class="alert alert-danger py-2"><?php echo msp2Escape($errorCarga); ?></div><?php endif; ?>
<form class="row g-2 align-items-end mb-3"><div class="col"><label class="form-label fw-semibold mb-1" for="buscar">Contrato, tienda, arrendatario, RUT o local</label><input class="form-control form-control-sm" id="buscar" name="buscar" value="<?php echo msp2Escape($buscar); ?>" placeholder="Buscar..."></div><div class="col-auto"><button class="btn btn-primary btn-sm px-4"><i class="bi bi-search me-1"></i>Buscar</button></div><?php if ($buscar !== ''): ?><div class="col-auto"><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('cierre/index.php')); ?>">Limpiar</a></div><?php endif; ?></form>
<div class="cc-kpis"><div class="cc-kpi"><i class="bi bi-file-earmark-check text-primary"></i><div><strong><?php echo $totalActivos; ?></strong><span>Activos para iniciar término</span></div></div><div class="cc-kpi"><i class="bi bi-hourglass-split text-warning"></i><div><strong><?php echo $totalEnCierre; ?></strong><span>En liquidación</span></div></div><div class="cc-kpi"><i class="bi bi-archive text-secondary"></i><div><strong><?php echo $totalCerrados; ?></strong><span>Cerrados en el resultado</span></div></div></div>
<?php
$render = static function (array $rows, string $kind) use ($fmtFecha, $fmtMonto): void {
    $empty = $kind === 'active' ? 'No hay contratos activos que coincidan.' : ($kind === 'closing' ? 'No hay contratos en proceso de cierre.' : 'No hay contratos cerrados que coincidan.'); ?>
    <div class="table-responsive border rounded bg-white"><table class="table table-hover cc-table"><thead class="table-light"><tr><th class="w-id">Contrato</th><th class="w-name">Arrendatario</th><th class="w-store">Tienda</th><th class="w-local">Locales</th><th class="w-date"><?php echo $kind === 'active' ? 'Inicio' : 'Término'; ?></th><th class="w-money">Saldo</th><th class="w-actions text-end">Acciones</th></tr></thead><tbody>
    <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-muted py-3"><?php echo msp2Escape($empty); ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $id=(int)$r['id_contrato_arriendo']; ?><tr><td><strong>#<?php echo $id; ?></strong></td><td class="clip" title="<?php echo msp2Escape((string)$r['nombre_locatario']); ?>"><?php echo msp2Escape((string)$r['nombre_locatario']); ?><div class="small text-muted"><?php echo msp2Escape((string)$r['rut']); ?></div></td><td class="clip" title="<?php echo msp2Escape((string)$r['nombre_comercial']); ?>"><?php echo msp2Escape((string)$r['nombre_comercial']); ?></td><td class="clip" title="<?php echo msp2Escape((string)$r['locales']); ?>"><?php echo msp2Escape((string)$r['locales']); ?></td><td><?php echo msp2Escape($fmtFecha($kind==='active'?$r['fecha_inicio']:$r['fecha_termino_efectiva'])); ?></td><td class="<?php echo (float)$r['saldo_pendiente']>.005?'text-danger fw-semibold':''; ?>"><?php echo msp2Escape($fmtMonto($r['saldo_pendiente'])); ?><?php if ((int)$r['documentos_pendientes']>0): ?><div class="small text-muted"><?php echo (int)$r['documentos_pendientes']; ?> doc.</div><?php endif; ?></td><td class="actions"><a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?id_contrato_arriendo='.$id)); ?>">Ver ficha</a> <?php if($kind==='active'): ?><button class="btn btn-warning btn-sm js-terminar" type="button" data-bs-toggle="modal" data-bs-target="#modalTermino" data-id="<?php echo $id; ?>" data-inicio="<?php echo msp2Escape(substr((string)$r['fecha_inicio'],0,10)); ?>" data-label="Contrato #<?php echo $id; ?> · <?php echo msp2Escape((string)$r['nombre_comercial']); ?>">Iniciar término</button><?php elseif($kind==='closing'): ?><a class="btn btn-dark btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/liquidacion_final.php?id_contrato_arriendo='.$id)); ?>">Revisar liquidación</a><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php
}; ?>
<section class="cc-section"><h2>1. Iniciar término operativo</h2><p>Libera los locales y deja el contrato en proceso de cierre; no elimina su historia.<?php if ($totalActivos > count($activosVisibles)): ?> Se muestran los primeros 25; usa el buscador para localizar otro contrato.<?php endif; ?></p><?php $render($activosVisibles,'active'); ?></section>
<section class="cc-section"><h2>2. Liquidación y cierre financiero</h2><p>Revisa lecturas, cargos, garantías y deuda antes del cierre definitivo.</p><?php $render($enCierre,'closing'); ?></section>
<section class="cc-section"><h2>3. Historial cerrado</h2><p>Consulta contratos finalizados y su trazabilidad.<?php if ($totalCerrados > count($cerradosVisibles)): ?> Se muestran los 25 cierres más recientes.<?php endif; ?></p><?php $render($cerradosVisibles,'closed'); ?></section>
</main>
<div class="modal fade" id="modalTermino" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/cerrar.php')); ?>" data-confirm-message="¿Registrar el término operativo de este contrato?" data-confirm-title="Terminar contrato" data-confirm-variant="warning"><div class="modal-header"><h2 class="modal-title fs-5">Iniciar término operativo</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="small text-muted" id="terminoLabel"></p><input type="hidden" name="id_contrato_arriendo" id="terminoId"><input type="hidden" name="redirect_to" value="cierre/index.php"><div class="mb-3"><label class="form-label" for="terminoFecha">Fecha de término efectiva</label><input type="date" class="form-control" id="terminoFecha" name="fecha_termino_efectiva" value="<?php echo date('Y-m-d'); ?>" required></div><div class="alert alert-light border small py-2" id="terminoPrecheck">Selecciona una fecha para validar el término.</div><div class="mb-3"><label class="form-label" for="terminoMotivo">Motivo</label><textarea class="form-control" id="terminoMotivo" name="motivo_cierre" rows="3" maxlength="500" required></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-warning" id="terminoSubmit" disabled>Registrar término</button></div></form></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><script>
const precheckUrl=<?php echo json_encode(msp2Url('contratos/precheck_termino.php'), JSON_UNESCAPED_SLASHES); ?>;
const esc=function(v){const e=document.createElement('div');e.textContent=String(v||'');return e.innerHTML;};
const validarTermino=function(){const id=terminoId.value,fecha=terminoFecha.value;terminoSubmit.disabled=true;terminoPrecheck.textContent='Validando condiciones...';if(!id||!fecha)return;fetch(precheckUrl+'?'+new URLSearchParams({id_contrato_arriendo:id,fecha_termino_efectiva:fecha}),{headers:{Accept:'application/json'}}).then(r=>r.json()).then(data=>{if(!data||data.ok!==true){throw new Error(data&&data.message?data.message:'No fue posible validar.');}const bloqueos=Array.isArray(data.bloqueos)?data.bloqueos:[],avisos=Array.isArray(data.avisos)?data.avisos:[];if(bloqueos.length){terminoPrecheck.innerHTML='<span class="text-danger">'+esc(bloqueos.join(' '))+'</span>';return;}terminoSubmit.disabled=false;terminoPrecheck.innerHTML='<span class="text-success">Sin bloqueos. Puedes continuar.</span>'+(avisos.length?'<div class="text-warning mt-1">'+esc(avisos.join(' '))+'</div>':'');}).catch(e=>{terminoPrecheck.innerHTML='<span class="text-danger">'+esc(e.message||'No fue posible validar.')+'</span>';});};
document.querySelectorAll('.js-terminar').forEach(function(b){b.addEventListener('click',function(){terminoId.value=this.dataset.id||'';terminoFecha.min=this.dataset.inicio||'';terminoLabel.textContent=this.dataset.label||'';terminoMotivo.value='';validarTermino();});});terminoFecha.addEventListener('change',validarTermino);
</script>
<?php echo msp2RenderCsrfAutoFieldScript(); ?>
</body></html>
