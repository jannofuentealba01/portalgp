<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/helper.php';

msp2RequireAccess('MSP Documentos Tienda Aprobacion', 'lectura');

$batchId = filter_var($_GET['id_lote'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$batch = $batchId > 0 ? msp2DocumentosTiendaFetchBatch($conn, $batchId) : null;
if (!is_array($batch)) {
    http_response_code(404);
    exit('Lote no encontrado.');
}
$files = msp2DocumentosTiendaFetchFiles($conn, $batchId);
$groups = [];
$unassociated = [];
$omitted = [];
foreach ($files as $file) {
    if ((string) $file['estado_archivo'] === 'OMITIDO') {
        $omitted[] = $file;
        continue;
    }
    $contractId = (int) ($file['id_contrato_arriendo'] ?? 0);
    if ($contractId <= 0) {
        $unassociated[] = $file;
        continue;
    }
    $groups[$contractId][] = $file;
}
$statusLabels = [
    'PENDIENTE' => ['Pendiente', 'secondary'], 'ASOCIADO' => ['Asociado', 'primary'],
    'ENVIADO' => ['Enviado demo', 'success'], 'ERROR' => ['Error', 'danger'], 'OMITIDO' => ['Omitido', 'dark'],
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Simulación de envío | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">

</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-4 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
        <div><div class="small text-muted">MSP / Documentos por tienda</div><h1 class="h3 mb-1">Simulación de envío</h1><p class="text-muted mb-0">Lote #<?php echo $batchId; ?> · <?php echo msp2Escape((string) $batch['nombre_lote']); ?></p></div>
        <div class="d-flex gap-2 no-print"><button class="btn btn-outline-primary" type="button" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button><a class="btn btn-outline-secondary" href="<?php echo msp2Escape(msp2Url('documentos_tienda/index.php?id_lote='.$batchId)); ?>"><i class="bi bi-arrow-left me-1"></i>Volver al lote</a></div>
    </div>
    <div class="alert alert-warning"><strong>Simulación solamente:</strong> esta pantalla no abre conexión SMTP, no valida direcciones y no envía correos.</div>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Correos simulados</div><div class="fs-3 fw-semibold"><?php echo count($groups); ?></div></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">PDF asociados</div><div class="fs-3 fw-semibold"><?php echo array_sum(array_map('count',$groups)); ?></div></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Sin asociación</div><div class="fs-3 fw-semibold text-warning"><?php echo count($unassociated); ?></div></div></div></div>
        <div class="col-md-3"><div class="card shadow-sm h-100"><div class="card-body"><div class="text-muted small">Omitidos</div><div class="fs-3 fw-semibold"><?php echo count($omitted); ?></div></div></div></div>
    </div>

    <?php if ($groups === []): ?><div class="alert alert-secondary">No existen documentos asociados para simular.</div><?php endif; ?>
    <div class="d-grid gap-3">
    <?php foreach ($groups as $contractId => $group):
        $first=$group[0];
        $totalBytes=array_sum(array_map(static fn(array $item):int=>(int)$item['bytes_archivo'],$group));
        $missingFiles=0;
        foreach($group as $item){try{$absolute=msp2DocumentosTiendaAbsolutePath((string)$item['ruta_relativa']);if(!is_file($absolute))$missingFiles++;}catch(Throwable){$missingFiles++;}}
        $recipient=trim((string)($first['correo_destino_snapshot']??''));
    ?>
        <section class="card shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between gap-2"><strong><?php echo msp2Escape((string)$first['nombre_comercial']); ?></strong><span>Contrato #<?php echo (int)$contractId; ?></span></div>
            <div class="card-body">
                <div class="row g-2 mb-3"><div class="col-lg-4"><strong>Arrendatario:</strong><br><?php echo msp2Escape((string)$first['nombre_arrendatario']); ?></div><div class="col-lg-3"><strong>Locales:</strong><br><?php echo msp2Escape((string)($first['locales_label']??'-')); ?></div><div class="col-lg-5"><strong>Destinatario registrado:</strong><br><?php echo msp2Escape($recipient!==''?$recipient:'No registrado'); ?></div></div>
                <?php if($recipient===''||$missingFiles>0||$totalBytes>18*1024*1024): ?><div class="alert alert-warning py-2"><strong>Requiere revisión:</strong><?php echo $recipient===''?' falta destinatario registrado.':''; ?><?php echo $missingFiles>0?' faltan '.$missingFiles.' archivos físicos.':''; ?><?php echo $totalBytes>18*1024*1024?' los adjuntos superan 18 MB.':''; ?></div><?php else: ?><div class="alert alert-success py-2">Grupo completo para una futura ejecución autorizada.</div><?php endif; ?>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Adjunto</th><th>Tamaño</th><th>Estado</th></tr></thead><tbody><?php foreach($group as $item):$state=$statusLabels[(string)$item['estado_archivo']]??[(string)$item['estado_archivo'],'secondary']; ?><tr><td><?php echo msp2Escape((string)$item['nombre_original']); ?></td><td><?php echo msp2Escape(msp2DocumentosTiendaFormatBytes((int)$item['bytes_archivo'])); ?></td><td><span class="badge text-bg-<?php echo msp2Escape($state[1]); ?>"><?php echo msp2Escape($state[0]); ?></span></td></tr><?php endforeach; ?></tbody></table></div>
                <div class="small text-muted mt-2">Total adjuntos: <?php echo count($group); ?> · <?php echo msp2Escape(msp2DocumentosTiendaFormatBytes($totalBytes)); ?></div>
            </div>
        </section>
    <?php endforeach; ?>
    </div>

    <?php if($unassociated!==[]): ?><section class="card border-warning mt-3"><div class="card-header fw-semibold">Documentos que impiden completar la preparación</div><ul class="list-group list-group-flush"><?php foreach($unassociated as $item): ?><li class="list-group-item"><?php echo msp2Escape((string)$item['nombre_original']); ?> <span class="badge text-bg-warning ms-2">Sin asociación</span></li><?php endforeach; ?></ul></section><?php endif; ?>
</main>
<script src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>
