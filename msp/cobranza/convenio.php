<?php
require_once __DIR__ . '/../bootstrap.php';
if (function_exists('msp2RequireAccess')) msp2RequireAccess();
$pdo = $pdo ?? ($conn ?? null);
if (!$pdo instanceof PDO) { http_response_code(500); exit('Conexión no disponible.'); }
$idContrato = (int)($_GET['id_contrato'] ?? $_POST['id_contrato_arriendo'] ?? 0);
$mensaje = $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_convenio') {
    try {
        msp2RequireValidCsrf();
        $stmt = $pdo->prepare("EXEC dbo.msp_convenio_crear @id_contrato_arriendo=:c,@monto_total=:m,@numero_cuotas=:n,@fecha_primera_vencimiento=:f,@observaciones=:o,@id_usuario=:u");
        $stmt->execute([':c'=>$idContrato,':m'=>(float)str_replace(',','.',$_POST['monto_total'] ?? 0),':n'=>(int)($_POST['numero_cuotas'] ?? 0),':f'=>$_POST['fecha_primera_vencimiento'] ?? null,':o'=>$_POST['observaciones'] ?? null,':u'=>$_SESSION['id_usuario'] ?? null]);
        $mensaje='Convenio creado correctamente con sus cuotas.';
    } catch (Throwable $e) { $error=pgpPublicOrBusinessException($e,'msp.cobranza.convenio','No fue posible cargar el convenio.'); }
}
$contrato = $pdo->prepare("SELECT c.id_contrato_arriendo, c.fecha_inicio, c.fecha_termino_pactada, COALESCE(a.nombre_locatario,a.nombre_representante,a.rut) arrendatario FROM dbo.msp_contratos_arriendo c LEFT JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario WHERE c.id_contrato_arriendo=:id");
$contrato->execute([':id'=>$idContrato]); $contrato=$contrato->fetch(PDO::FETCH_ASSOC);
$convenios=[]; $cuotas=[];
if ($contrato) { $q=$pdo->prepare('SELECT * FROM dbo.msp_vw_convenios_pago_estado WHERE id_contrato_arriendo=:id ORDER BY id_convenio_pago DESC'); $q->execute([':id'=>$idContrato]); $convenios=$q->fetchAll(PDO::FETCH_ASSOC); $q=$pdo->prepare('SELECT q.*,c.id_contrato_arriendo FROM dbo.msp_convenio_pago_cuotas q JOIN dbo.msp_convenios_pago c ON c.id_convenio_pago=q.id_convenio_pago WHERE c.id_contrato_arriendo=:id ORDER BY q.id_convenio_pago DESC,q.numero_cuota'); $q->execute([':id'=>$idContrato]); $cuotas=$q->fetchAll(PDO::FETCH_ASSOC); }
function convenioMonto(mixed $valor): string { return '$ ' . number_format((float) $valor, 2, ',', '.'); }
function convenioFecha(mixed $valor): string { $raw=substr(trim((string)$valor),0,10); $fecha=DateTimeImmutable::createFromFormat('!Y-m-d',$raw); return $fecha instanceof DateTimeImmutable?$fecha->format('d-m-Y'):($raw!==''?$raw:'-'); }
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Convenio de pago | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light gp-module-msp">
<?php include dirname(__DIR__,2).'/templates/header.php'; ?>
<main class="gp-main">
    <div class="box-container-wide convenio-page">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div><p class="section-kicker mb-1">MSP / Cobranza</p><h1 class="form-title mb-1">Convenio de pago</h1><p class="text-muted mb-0">Crea y consulta el plan de cuotas del contrato.</p></div>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/gestionar.php?id_contrato='.$idContrato)); ?>"><i class="bi bi-arrow-left me-1"></i>Volver a cobranza</a>
        </div>
        <?php if($mensaje): ?><div class="alert alert-success py-2"><?php echo msp2Escape($mensaje); ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger py-2"><?php echo msp2Escape($error); ?></div><?php endif; ?>
        <?php if(!$contrato): ?>
            <div class="alert alert-warning">Contrato no encontrado.</div>
        <?php else: ?>
            <section class="card mb-3">
                <div class="card-body py-3">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><span class="text-muted small">Contrato</span><h2 class="h5 mb-0">#<?php echo $idContrato; ?> · <?php echo msp2Escape((string)($contrato['arrendatario']??'-')); ?></h2></div><div class="small text-muted text-md-end">Inicio <?php echo msp2Escape(convenioFecha($contrato['fecha_inicio']??null)); ?><br>Término pactado <?php echo msp2Escape(convenioFecha($contrato['fecha_termino_pactada']??null)); ?></div></div>
                    <form method="post" class="row g-2 align-items-end">
                        <?php msp2CsrfField(); ?>
                        <input type="hidden" name="accion" value="crear_convenio"><input type="hidden" name="id_contrato_arriendo" value="<?php echo $idContrato; ?>">
                        <div class="col-12 col-md-3"><label class="form-label" for="monto_total">Monto total</label><input class="form-control" id="monto_total" name="monto_total" required type="number" step="0.01" min="0.01"></div>
                        <div class="col-6 col-md-2"><label class="form-label" for="numero_cuotas">Cuotas</label><input class="form-control" id="numero_cuotas" name="numero_cuotas" required type="number" min="1" max="120"></div>
                        <div class="col-6 col-md-3"><label class="form-label" for="fecha_primera_vencimiento">Primer vencimiento</label><input class="form-control" id="fecha_primera_vencimiento" name="fecha_primera_vencimiento" required type="date"></div>
                        <div class="col-12 col-md"><label class="form-label" for="observaciones">Observaciones</label><input class="form-control" id="observaciones" name="observaciones" maxlength="1000"></div>
                        <div class="col-12 col-md-auto"><button class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i>Crear convenio</button></div>
                    </form>
                </div>
            </section>

            <section class="card mb-3">
                <div class="card-header bg-white"><h2 class="h6 mb-0">Convenios existentes</h2></div>
                <?php if(!$convenios): ?><div class="card-body text-muted">No hay convenios registrados.</div><?php else: ?>
                <div class="table-responsive gp-table-shell"><table class="table table-sm table-bordered align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-convenios-table"><thead class="table-light"><tr><th>Convenio</th><th>Resumen financiero</th><th>Cuotas atrasadas</th><th>Estado</th></tr></thead><tbody>
                <?php foreach($convenios as $c): ?><tr><td data-gp-label="Convenio"><strong>#<?php echo (int)$c['id_convenio_pago']; ?></strong></td><td data-gp-label="Resumen financiero" class="gp-financial-cell"><span class="gp-data-pair"><span>Total</span><strong><?php echo msp2Escape(convenioMonto($c['monto_total'])); ?></strong></span><span class="gp-data-pair"><span>Pagado</span><strong><?php echo msp2Escape(convenioMonto($c['total_pagado'])); ?></strong></span><span class="gp-data-pair gp-data-pair--total"><span>Saldo</span><strong><?php echo msp2Escape(convenioMonto($c['saldo_pendiente'])); ?></strong></span></td><td data-gp-label="Cuotas atrasadas"><?php echo (int)$c['cuotas_atrasadas']; ?></td><td data-gp-label="Estado"><span class="badge text-bg-light border text-dark"><?php echo msp2Escape((string)$c['estado']); ?></span></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </section>

            <section class="card"><div class="card-header bg-white"><h2 class="h6 mb-0">Plan de cuotas</h2></div>
                <?php if(!$cuotas): ?><div class="card-body text-muted">No hay cuotas registradas.</div><?php else: ?><div class="table-responsive gp-table-shell"><table class="table table-sm table-bordered align-middle mb-0 gp-table-compact gp-table-mobile-cards msp-cuotas-table"><thead class="table-light"><tr><th>Convenio / cuota</th><th>Vencimiento / estado</th><th>Resumen financiero</th></tr></thead><tbody>
                <?php foreach($cuotas as $q): ?><tr><td data-gp-label="Convenio / cuota"><strong>#<?php echo (int)$q['id_convenio_pago']; ?></strong><div class="small text-muted">Cuota <?php echo (int)$q['numero_cuota']; ?></div></td><td data-gp-label="Vencimiento / estado"><strong><?php echo msp2Escape(convenioFecha($q['fecha_vencimiento'])); ?></strong><div class="mt-1"><span class="badge text-bg-light border text-dark"><?php echo msp2Escape((string)$q['estado']); ?></span></div></td><td data-gp-label="Resumen financiero" class="gp-financial-cell"><span class="gp-data-pair"><span>Monto</span><strong><?php echo msp2Escape(convenioMonto($q['monto_cuota'])); ?></strong></span><span class="gp-data-pair gp-data-pair--total"><span>Pagado</span><strong><?php echo msp2Escape(convenioMonto($q['monto_pagado'])); ?></strong></span></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__,2).'/templates/footer.php'; ?>
</body>
</html>
