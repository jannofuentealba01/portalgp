<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess('MSP Tesoreria', 'lectura');

$flash = msp2PullFlash();
$error = null;
$cierres = $pendientes = $historial = [];
$idUsuarioActual = (int) ($_SESSION['usuario']['id'] ?? 0);
$puedeResolver = msp2CurrentUserHasPermission('MSP Tesoreria', 'eliminacion');

try {
    if (!msp2TableExists($conn, 'msp_tesoreria_solicitudes_reapertura_caja')) {
        throw new RuntimeException('El flujo seguro de reapertura aún no está instalado en la base de datos.');
    }
    $cierres = $conn->query("SELECT TOP(100)c.*,t.nombre_cuenta FROM dbo.msp_tesoreria_cierres_caja c INNER JOIN dbo.msp_tesoreria_cuentas t ON t.id_cuenta_tesoreria=c.id_cuenta_tesoreria WHERE c.estado_cierre IN(N'CUADRADO',N'CON_DIFERENCIA') AND NOT EXISTS(SELECT 1 FROM dbo.msp_tesoreria_solicitudes_reapertura_caja s WHERE s.id_cierre_caja=c.id_cierre_caja AND s.estado_solicitud=N'PENDIENTE') ORDER BY c.fecha_cierre DESC,c.id_cierre_caja DESC")->fetchAll() ?: [];
    $pendientes = $conn->query("SELECT s.*,c.fecha_cierre,c.estado_cierre,c.diferencia,t.nombre_cuenta,COALESCE(NULLIF(u.nombre_completo,N''),u.UserName) solicitante FROM dbo.msp_tesoreria_solicitudes_reapertura_caja s INNER JOIN dbo.msp_tesoreria_cierres_caja c ON c.id_cierre_caja=s.id_cierre_caja INNER JOIN dbo.msp_tesoreria_cuentas t ON t.id_cuenta_tesoreria=c.id_cuenta_tesoreria INNER JOIN dbo.cr_usuarios u ON u.id=s.id_usuario_solicita WHERE s.estado_solicitud=N'PENDIENTE' ORDER BY s.fecha_solicitud,s.id_solicitud_reapertura")->fetchAll() ?: [];
    $historial = $conn->query("SELECT TOP(100)s.*,c.fecha_cierre,t.nombre_cuenta,COALESCE(NULLIF(us.nombre_completo,N''),us.UserName) solicitante,COALESCE(NULLIF(ur.nombre_completo,N''),ur.UserName) resolutor FROM dbo.msp_tesoreria_solicitudes_reapertura_caja s INNER JOIN dbo.msp_tesoreria_cierres_caja c ON c.id_cierre_caja=s.id_cierre_caja INNER JOIN dbo.msp_tesoreria_cuentas t ON t.id_cuenta_tesoreria=c.id_cuenta_tesoreria INNER JOIN dbo.cr_usuarios us ON us.id=s.id_usuario_solicita INNER JOIN dbo.cr_usuarios ur ON ur.id=s.id_usuario_resuelve WHERE s.estado_solicitud IN(N'APROBADA',N'RECHAZADA') ORDER BY s.fecha_resolucion DESC,s.id_solicitud_reapertura DESC")->fetchAll() ?: [];
} catch (Throwable $e) {
    $error = $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible cargar las reaperturas de caja.';
}
$monto = static fn(mixed $v): string => '$ ' . number_format((float) $v, 2, ',', '.');
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reapertura de caja | MSP</title><link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css"><link rel="stylesheet" href="/portalgp/styles.css"></head><body class="gp-layout bg-light">
<?php include dirname(__DIR__,2).'/templates/header.php'; ?>
<main class="gp-main container py-4">
<div class="d-flex flex-wrap justify-content-between gap-3 mb-3"><div><p class="text-muted mb-1">MSP / Tesorería</p><h1 class="h3">Reapertura de caja con doble control</h1><p class="text-muted mb-0">Una persona solicita y otra, desde su propia sesión, aprueba o rechaza. La aprobación nunca se atribuye escogiendo un nombre.</p></div><a class="btn btn-outline-secondary btn-sm align-self-start" href="<?php echo msp2Escape(msp2Url('tesoreria/conciliacion.php')); ?>">Volver a conciliación</a></div>
<?php if(is_array($flash)):?><div class="alert alert-<?php echo msp2Escape((string)($flash['type']??'info'));?>"><?php echo msp2Escape((string)($flash['message']??''));?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger"><?php echo msp2Escape($error);?></div><?php endif;?>

<section class="card shadow-sm mb-4"><div class="card-header fw-semibold">Solicitudes pendientes de decisión</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Solicitud</th><th>Cierre</th><th>Solicitante</th><th>Motivo</th><th style="min-width:360px">Decisión</th></tr></thead><tbody>
<?php if($pendientes===[]):?><tr><td colspan="5" class="text-center text-muted p-4">No hay solicitudes pendientes.</td></tr><?php endif;?>
<?php foreach($pendientes as $s):?><tr><td>#<?php echo (int)$s['id_solicitud_reapertura'];?><div class="small text-muted"><?php echo msp2Escape(substr((string)$s['fecha_solicitud'],0,19));?></div></td><td><?php echo msp2Escape((string)$s['nombre_cuenta']);?><div class="small text-muted"><?php echo msp2Escape(substr((string)$s['fecha_cierre'],0,10).' · '.(string)$s['estado_cierre'].' · '.$monto($s['diferencia']));?></div></td><td><?php echo msp2Escape((string)$s['solicitante']);?></td><td><?php echo nl2br(msp2Escape((string)$s['motivo']));?></td><td>
<?php if((int)$s['id_usuario_solicita']===$idUsuarioActual):?><span class="badge text-bg-warning">Esperando a otro autorizador</span>
<?php elseif(!$puedeResolver):?><span class="text-muted">Tu perfil no puede resolver reaperturas.</span>
<?php else:?><form method="post" action="<?php echo msp2Escape(msp2Url('tesoreria/resolver_reapertura_caja.php'));?>" class="d-flex gap-2 mb-2"><?php msp2CsrfField();?><input type="hidden" name="id_solicitud_reapertura" value="<?php echo (int)$s['id_solicitud_reapertura'];?>"><input type="hidden" name="decision" value="APROBAR"><input name="observacion" class="form-control form-control-sm" maxlength="1000" placeholder="Observación opcional"><button class="btn btn-success btn-sm" onclick="return confirm('¿Aprobar y reabrir esta caja?');">Aprobar</button></form><form method="post" action="<?php echo msp2Escape(msp2Url('tesoreria/resolver_reapertura_caja.php'));?>" class="d-flex gap-2"><?php msp2CsrfField();?><input type="hidden" name="id_solicitud_reapertura" value="<?php echo (int)$s['id_solicitud_reapertura'];?>"><input type="hidden" name="decision" value="RECHAZAR"><input name="observacion" class="form-control form-control-sm" minlength="5" maxlength="1000" placeholder="Motivo del rechazo" required><button class="btn btn-outline-danger btn-sm">Rechazar</button></form><?php endif;?>
</td></tr><?php endforeach;?></tbody></table></div></section>

<section class="card shadow-sm mb-4"><div class="card-header fw-semibold">Cierres disponibles para solicitar reapertura</div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Fecha</th><th>Caja</th><th>Estado</th><th>Diferencia</th><th style="min-width:460px">Solicitud</th></tr></thead><tbody>
<?php if($cierres===[]):?><tr><td colspan="5" class="text-center text-muted p-4">No hay cierres disponibles.</td></tr><?php endif;?>
<?php foreach($cierres as $c):?><tr><td><?php echo msp2Escape(substr((string)$c['fecha_cierre'],0,10));?></td><td><?php echo msp2Escape((string)$c['nombre_cuenta']);?></td><td><?php echo msp2Escape((string)$c['estado_cierre']);?></td><td><?php echo msp2Escape($monto($c['diferencia']));?></td><td><form method="post" action="<?php echo msp2Escape(msp2Url('tesoreria/reabrir_caja.php'));?>" class="d-flex gap-2"><?php msp2CsrfField();?><input type="hidden" name="id_cierre_caja" value="<?php echo (int)$c['id_cierre_caja'];?>"><input name="motivo" class="form-control form-control-sm" minlength="10" maxlength="1000" placeholder="Motivo obligatorio (mínimo 10 caracteres)" required><button class="btn btn-outline-primary btn-sm">Solicitar</button></form></td></tr><?php endforeach;?></tbody></table></div></section>

<section class="card shadow-sm"><div class="card-header fw-semibold">Historial de decisiones</div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Resolución</th><th>Cierre</th><th>Estado</th><th>Solicitó</th><th>Resolvió</th><th>Observación</th></tr></thead><tbody>
<?php if($historial===[]):?><tr><td colspan="6" class="text-center text-muted p-4">Aún no hay solicitudes resueltas.</td></tr><?php endif;?>
<?php foreach($historial as $h):?><tr><td><?php echo msp2Escape(substr((string)$h['fecha_resolucion'],0,19));?></td><td><?php echo msp2Escape((string)$h['nombre_cuenta']);?><div class="small text-muted"><?php echo msp2Escape(substr((string)$h['fecha_cierre'],0,10));?></div></td><td><span class="badge text-bg-<?php echo $h['estado_solicitud']==='APROBADA'?'success':'secondary';?>"><?php echo msp2Escape((string)$h['estado_solicitud']);?></span></td><td><?php echo msp2Escape((string)$h['solicitante']);?></td><td><?php echo msp2Escape((string)$h['resolutor']);?></td><td><?php echo msp2Escape((string)($h['observacion_resolucion']??'-'));?></td></tr><?php endforeach;?></tbody></table></div></section>
</main><?php include dirname(__DIR__,2).'/templates/footer.php';?></body></html>
