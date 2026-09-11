<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';msp2RequireAccess();
$id=filter_input(INPUT_GET,'id_aviso',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$returnTo=trim((string)($_GET['return_to']??''));if($returnTo===''||preg_match('#^cobranza/gestionar\.php\?id_contrato=\d+(?:&return_to=[A-Za-z0-9_\-\.\[%\]=&]*)?$#',$returnTo)!==1)$returnTo='pendientes/index.php';
$aviso=null;$docs=[];$error=null;
try{$stmt=$conn->prepare('SELECT a.*,p.nombre plantilla_nombre,c.id_arrendatario,ar.nombre_locatario,ar.rut,t.nombre_comercial FROM dbo.msp_cobranza_avisos a JOIN dbo.msp_cobranza_plantillas_aviso p ON p.id_plantilla_aviso=a.id_plantilla_aviso JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=a.id_contrato_arriendo JOIN dbo.msp_arrendatarios ar ON ar.id_arrendatario=c.id_arrendatario JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda WHERE a.id_aviso_cobranza=:id');$stmt->execute([':id'=>$id]);$aviso=$stmt->fetch(PDO::FETCH_ASSOC);if(!$aviso)throw new RuntimeException('El aviso solicitado no existe.');$stmt=$conn->prepare('SELECT numero_documento,fecha_vencimiento,saldo_pendiente,DATEDIFF(DAY,fecha_vencimiento,CONVERT(date,SYSDATETIME())) dias_mora FROM dbo.msp_documentos_cobro WHERE id_contrato_arriendo=:id AND estado_documento IN(2,3) AND saldo_pendiente>0 ORDER BY fecha_vencimiento');$stmt->execute([':id'=>(int)$aviso['id_contrato_arriendo']]);$docs=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){$error=pgpPublicOrBusinessException($e,'msp.cobranza.aviso','No fue posible cargar el aviso.');}
$flash=msp2PullFlash();function acMonto(mixed $v):string{return '$ '.number_format((float)$v,2,',','.');}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Aviso de cobranza | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light gp-module-msp">
<?php include dirname(__DIR__,2).'/templates/header.php'; ?>
<main class="gp-main">
    <div class="aviso mx-auto">
        <div class="d-flex justify-content-between gap-2 mb-3 no-print"><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url($returnTo)); ?>"><i class="bi bi-arrow-left me-1"></i>Volver al caso</a><button class="btn btn-primary btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir / guardar PDF</button></div>
        <?php msp2RenderFlash($flash); ?>
        <?php if($error): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php endif; ?>
        <?php if($aviso): ?>
            <article class="aviso-paper shadow-sm">
                <div class="d-flex justify-content-between border-bottom pb-2 mb-3"><div><div class="text-muted small">Mercado San Pedro</div><h1 class="h3 mb-0">Aviso de cobranza</h1></div><div class="text-end"><strong>#<?php echo (int)$aviso['id_aviso_cobranza']; ?></strong><div class="small text-muted"><?php echo date('d-m-Y',strtotime((string)$aviso['fecha_generacion'])); ?></div></div></div>
                <div class="row g-2 mb-3"><div class="col-md-7"><span class="text-muted small">Arrendatario</span><div><strong><?php echo msp2Escape((string)$aviso['nombre_locatario']); ?></strong></div><div class="small"><?php echo msp2Escape((string)$aviso['rut']); ?></div></div><div class="col-md-5"><span class="text-muted small">Contrato / tienda</span><div><strong>#<?php echo (int)$aviso['id_contrato_arriendo']; ?> · <?php echo msp2Escape((string)$aviso['nombre_comercial']); ?></strong></div></div></div>
                <h2 class="h5 mt-3"><?php echo msp2Escape((string)$aviso['asunto_snapshot']); ?></h2>
                <p><?php echo nl2br(msp2Escape((string)$aviso['cuerpo_snapshot'])); ?></p>
                <div class="table-responsive gp-table-shell mt-3"><table class="table table-sm table-bordered align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-notice-documents-table"><thead class="table-light"><tr><th>Documento</th><th>Vencimiento / mora</th><th class="text-end">Saldo</th></tr></thead><tbody>
                    <?php if($docs===[]): ?><tr><td colspan="3" class="text-muted gp-table-empty-cell">No hay documentos vencidos asociados.</td></tr><?php else: foreach($docs as $d): ?><tr><td data-gp-label="Documento"><strong><?php echo msp2Escape((string)($d['numero_documento']?:'-')); ?></strong></td><td data-gp-label="Vencimiento / mora"><?php echo msp2Escape(date('d-m-Y',strtotime((string)$d['fecha_vencimiento']))); ?><div class="small text-muted"><?php echo max(0,(int)$d['dias_mora']); ?> días de mora</div></td><td data-gp-label="Saldo" class="text-end fw-bold"><?php echo msp2Escape(acMonto($d['saldo_pendiente'])); ?></td></tr><?php endforeach; endif; ?>
                </tbody><tfoot><tr><th colspan="2">Deuda vencida al generar</th><th class="text-end"><?php echo msp2Escape(acMonto($aviso['deuda_vencida_snapshot'])); ?></th></tr></tfoot></table></div>
                <p class="small text-muted mt-3 mb-0">Este aviso es informativo y no modifica el saldo oficial de los documentos.</p>
            </article>
            <?php if($aviso['estado']==='GENERADO'): ?>
                <form method="post" action="accion_gestion.php" class="card mt-3 no-print"><div class="card-body row g-2 align-items-end"><?php msp2CsrfField(); ?><input type="hidden" name="accion" value="REGISTRAR_ENVIO_AVISO"><input type="hidden" name="id_aviso_cobranza" value="<?php echo (int)$id; ?>"><input type="hidden" name="id_contrato" value="<?php echo (int)$aviso['id_contrato_arriendo']; ?>"><div class="col-md-4"><label class="form-label">Medio de entrega</label><select class="form-select" name="medio_envio" required><option>CORREO</option><option>WHATSAPP</option><option>CARTA</option><option>PERSONAL</option><option>OTRO</option></select></div><div class="col-md-6"><label class="form-label">Observación</label><input class="form-control" name="observacion_envio" maxlength="1000"></div><div class="col-md-2 d-grid"><button class="btn btn-success">Registrar entrega</button></div></div></form>
            <?php else: ?><div class="alert alert-success mt-3 no-print">Aviso registrado como <?php echo msp2Escape((string)$aviso['estado']); ?> el <?php echo msp2Escape((string)$aviso['fecha_envio']); ?> por <?php echo msp2Escape((string)$aviso['medio_envio']); ?>.</div><?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<script src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__,2).'/templates/footer.php'; ?>
</body>
</html>
