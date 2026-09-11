<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/helper.php';

msp2DocumentosTiendaRequireAny('lectura');

$batchId = filter_var($_GET['id_lote'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$batch = null;
$files = [];
$contracts = [];
$recentBatches = [];
$events = [];
$loadError = null;
$filterQuery = mb_substr(msp2NormalizeText((string) ($_GET['q'] ?? '')), 0, 120, 'UTF-8');
$filterStatus = strtoupper(trim((string) ($_GET['estado'] ?? '')));
if (!in_array($filterStatus, ['PENDIENTE', 'ASOCIADO', 'ENVIADO', 'ERROR', 'OMITIDO'], true)) {
    $filterStatus = '';
}
$page = max(1, (int) ($_GET['pagina'] ?? 1));
$perPage = in_array((int) ($_GET['lineas'] ?? 20), [10, 20, 50], true) ? (int) $_GET['lineas'] : 20;
$filesTotal = 0;
$totalPages = 1;
$historyPage = max(1, (int) ($_GET['historia_pagina'] ?? 1));
$historyTotal = 0;
$historyPages = 1;

$statusLabels = [
    'BORRADOR' => ['Borrador', 'secondary'],
    'EN_REVISION' => ['En revisión', 'warning'],
    'LISTO' => ['Listo para demo', 'primary'],
    'PARCIAL' => ['Envío parcial', 'info'],
    'COMPLETADO' => ['Completado', 'success'],
    'CERRADO' => ['Cerrado', 'dark'],
    'PENDIENTE' => ['Pendiente', 'secondary'],
    'ASOCIADO' => ['Asociado', 'primary'],
    'ENVIADO' => ['Enviado demo', 'success'],
    'ERROR' => ['Error', 'danger'],
    'OMITIDO' => ['Omitido', 'dark'],
];

function msp2DocumentosTiendaPageUrl(int $batchId, string $query, string $status, int $page, int $perPage): string
{
    $params = ['id_lote' => $batchId, 'pagina' => max(1, $page), 'lineas' => $perPage];
    if ($query !== '') {
        $params['q'] = $query;
    }
    if ($status !== '') {
        $params['estado'] = $status;
    }
    return msp2Url('documentos_tienda/index.php?' . http_build_query($params));
}

try {
    foreach (['msp_documentos_tienda_lotes', 'msp_documentos_tienda_archivos', 'msp_documentos_tienda_eventos'] as $table) {
        if (!msp2TableExists($conn, $table)) {
            throw new RuntimeException('Falta aplicar el parche SQL de carga masiva de documentos por tienda.');
        }
    }
    $recentBatches = $conn->query(
        'SELECT TOP (20) l.*,
                COUNT(a.id_documento_tienda_archivo) total_archivos,
                SUM(CASE WHEN a.estado_archivo=N\'ENVIADO\' THEN 1 ELSE 0 END) enviados
         FROM dbo.msp_documentos_tienda_lotes l
         LEFT JOIN dbo.msp_documentos_tienda_archivos a ON a.id_lote_documentos_tienda=l.id_lote_documentos_tienda
         GROUP BY l.id_lote_documentos_tienda,l.nombre_lote,l.estado_lote,l.id_usuario_creador,l.fecha_registro,l.updated_at,
                  l.estado_antes_cierre,l.fecha_cierre,l.id_usuario_cierre
         ORDER BY l.id_lote_documentos_tienda DESC'
    )->fetchAll() ?: [];
    if ($batchId === 0 && $recentBatches !== []) {
        $batchId = (int) $recentBatches[0]['id_lote_documentos_tienda'];
    }
    if ($batchId > 0) {
        $batch = msp2DocumentosTiendaFetchBatch($conn, $batchId);
        if ($batch === null) {
            throw new RuntimeException('El lote solicitado no existe.');
        }
        $filesPage = msp2DocumentosTiendaFetchFilesPage($conn, $batchId, $filterQuery, $filterStatus, $page, $perPage);
        $files = $filesPage['rows'];
        $filesTotal = (int) $filesPage['total'];
        $page = (int) $filesPage['page'];
        $totalPages = (int) $filesPage['pages'];
        $contracts = msp2DocumentosTiendaFetchContracts($conn);
        $eventsPage = msp2DocumentosTiendaFetchEventsPage($conn, $batchId, $historyPage, 25);
        $events = $eventsPage['rows'];
        $historyPage = (int) $eventsPage['page'];
        $historyTotal = (int) $eventsPage['total'];
        $historyPages = (int) $eventsPage['pages'];
    }
} catch (Throwable $e) {
    pgpLogException($e, 'msp.documentos_tienda.index');
    $loadError = $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible cargar el módulo.';
}

$contractOptions = [];
foreach ($contracts as $contract) {
    $stateText = match ((int) ($contract['estado_contrato'] ?? 0)) {
        1 => 'Borrador', 2 => 'Vigente', 3 => 'En cierre', 4 => 'Cerrado', default => 'Otro',
    };
    $label = (string) ($contract['nombre_comercial'] ?? '-')
        . ' — ' . ((string) ($contract['locales_label'] ?? '') !== '' ? (string) $contract['locales_label'] : 'sin locales')
        . ' — ' . (string) ($contract['nombre_arrendatario'] ?? '-')
        . ' — contrato #' . (int) $contract['id_contrato_arriendo']
        . ' (' . $stateText . ')';
    $contractOptions[] = [
        'id' => (int) $contract['id_contrato_arriendo'],
        'label' => $label,
        'email' => trim((string) ($contract['correo'] ?? '')),
    ];
}

$flash = msp2PullFlash();
$canLoad = msp2DocumentosTiendaCan('MSP Documentos Tienda Carga', 'escritura');
$canReview = msp2DocumentosTiendaCan('MSP Documentos Tienda Revision', 'escritura');
$canApprove = msp2DocumentosTiendaCan('MSP Documentos Tienda Aprobacion', 'escritura');
$canSimulate = msp2DocumentosTiendaCan('MSP Documentos Tienda Aprobacion', 'lectura');
$isClosed = is_array($batch) && (string) ($batch['estado_lote'] ?? '') === 'CERRADO';
$canEditFiles = $canReview && !$isClosed;
$canCloseBatch = is_array($batch)
    && (int) ($batch['total_archivos'] ?? 0) > 0
    && (int) ($batch['pendientes'] ?? 0) === 0
    && (int) ($batch['errores'] ?? 0) === 0;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documentos por tienda | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main container-fluid py-4 px-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3" data-gp-commandbar>
        <div>
            <h1 class="h3 mb-1">Carga masiva de PDF por tienda</h1>
            <p class="text-muted mb-0">Carga en varias tandas, asocia cada PDF y comprueba el resultado antes de enviar.</p>
        </div>
        <a class="btn btn-outline-secondary" href="<?php echo msp2Escape(msp2Url()); ?>"><i class="bi bi-arrow-left me-1"></i>Volver a MSP</a>
    </div>

    <?php msp2RenderFlash($flash); ?>
    <?php if ($loadError !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div><?php endif; ?>

    <details class="gp-disclosure mb-3">
        <summary><span>Alcance actual del flujo controlado</span></summary>
        <div class="gp-disclosure__body small text-muted">La simulación muestra cómo se agruparían los documentos, pero no conecta con el servidor de correo ni envía mensajes. La identificación automática y el OCR se incorporarán cuando existan PDFs reales de muestra.</div>
    </details>

    <?php if ($canLoad): ?>
    <section class="gp-functional-surface mb-3">
            <h2 class="h5 mb-3">1. Crear un lote y cargar PDFs</h2>
            <form method="post" action="<?php echo msp2Escape(msp2Url('documentos_tienda/cargar.php')); ?>" enctype="multipart/form-data" class="row g-3 align-items-end">
                <?php msp2CsrfField(); ?>
                <div class="col-lg-4">
                    <label class="form-label" for="nombre_lote">Nombre del lote</label>
                    <input class="form-control" id="nombre_lote" name="nombre_lote" maxlength="180" placeholder="Ej.: Circulares septiembre 2026">
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="archivos_nuevos">Archivos PDF</label>
                    <input class="form-control" id="archivos_nuevos" type="file" name="archivos[]" accept="application/pdf,.pdf" multiple required>
                    <div class="form-text">Hasta 20 por tanda y 20 MB por archivo. Puedes agregar más tandas al mismo lote hasta completar el conjunto.</div>
                </div>
                <div class="col-lg-2 d-grid"><button class="btn btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i>Crear y cargar</button></div>
            </form>
    </section>
    <?php endif; ?>

    <?php if ($recentBatches !== []): ?>
        <section class="mb-3">
            <div class="small fw-semibold mb-2">Lotes recientes</div>
            <div class="batch-strip d-flex gap-2 pb-2">
                <?php foreach ($recentBatches as $recent):
                    $recentId=(int)$recent['id_lote_documentos_tienda'];
                    $recentStatus=$statusLabels[(string)$recent['estado_lote']] ?? [(string)$recent['estado_lote'],'secondary']; ?>
                    <a class="btn <?php echo $recentId===$batchId ? 'btn-primary' : 'btn-outline-secondary'; ?> btn-sm" href="<?php echo msp2Escape(msp2Url('documentos_tienda/index.php?id_lote='.$recentId)); ?>">
                        #<?php echo $recentId; ?> <?php echo msp2Escape((string)$recent['nombre_lote']); ?>
                        <span class="badge text-bg-<?php echo msp2Escape($recentStatus[1]); ?> ms-1"><?php echo (int)$recent['total_archivos']; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (is_array($batch)): $batchStatus=$statusLabels[(string)$batch['estado_lote']] ?? [(string)$batch['estado_lote'],'secondary']; ?>
        <section class="gp-functional-surface mb-3">
                <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center mb-2">
                    <div>
                        <h2 class="h5 mb-1">Lote #<?php echo $batchId; ?>: <?php echo msp2Escape((string)$batch['nombre_lote']); ?></h2>
                        <span class="badge text-bg-<?php echo msp2Escape($batchStatus[1]); ?>"><?php echo msp2Escape($batchStatus[0]); ?></span>
                    </div>
                </div>
                <div class="gp-indicator-strip batch-status-strip mb-2" aria-label="Estado del lote">
                    <?php foreach (['Total' => 'total_archivos', 'Pendientes' => 'pendientes', 'Asociados' => 'asociados', 'Demo' => 'enviados', 'Errores' => 'errores', 'Omitidos' => 'omitidos'] as $batchMetricLabel => $batchMetricKey): ?>
                        <div class="gp-indicator"><span class="gp-indicator-label"><?php echo msp2Escape($batchMetricLabel); ?></span><strong class="gp-indicator-value"><?php echo (int) $batch[$batchMetricKey]; ?></strong></div>
                    <?php endforeach; ?>
                </div>
                <details class="gp-disclosure mb-2"><summary><span>Significado de los estados</span></summary><div class="gp-disclosure__body small text-muted">Pendiente: sin asociación · Asociado: preparado · Enviado demo: bloqueado · Omitido: excluido voluntariamente · Error: requiere revisión.</div></details>
                <?php if ($canLoad && !$isClosed): ?><form method="post" action="<?php echo msp2Escape(msp2Url('documentos_tienda/cargar.php')); ?>" enctype="multipart/form-data" class="row g-2 align-items-end">
                    <?php msp2CsrfField(); ?><input type="hidden" name="id_lote" value="<?php echo $batchId; ?>">
                    <div class="col-lg-10"><label class="form-label" for="archivos_lote">Agregar otra tanda al lote</label><input class="form-control" id="archivos_lote" type="file" name="archivos[]" accept="application/pdf,.pdf" multiple required></div>
                    <div class="col-lg-2 d-grid"><button class="btn btn-outline-primary">Agregar PDFs</button></div>
                </form><?php elseif ($isClosed): ?><div class="alert alert-secondary py-2 mb-0"><i class="bi bi-lock me-1"></i>El lote está cerrado y no admite nuevas cargas ni cambios de asociación.</div><?php endif; ?>
        </section>

        <section class="mb-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h2 class="h5 mb-0">2. Revisar y asociar documentos</h2>
            </div>
            <form method="get" class="gp-functional-surface mb-3">
                <input type="hidden" name="id_lote" value="<?php echo $batchId; ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-lg-6">
                        <label class="form-label" for="q">Buscar en el lote</label>
                        <input class="form-control" type="search" id="q" name="q" maxlength="120" value="<?php echo msp2Escape($filterQuery); ?>" placeholder="Archivo, tienda, local, arrendatario, RUT o contrato">
                    </div>
                    <div class="col-lg-3">
                        <label class="form-label" for="estado">Estado</label>
                        <select class="form-select" id="estado" name="estado">
                            <option value="">Todos</option>
                            <?php foreach (['PENDIENTE','ASOCIADO','ENVIADO','ERROR','OMITIDO'] as $filterOption): ?><option value="<?php echo $filterOption; ?>" <?php echo $filterStatus===$filterOption?'selected':''; ?>><?php echo msp2Escape($statusLabels[$filterOption][0]); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-1">
                        <label class="form-label" for="lineas">Mostrar</label>
                        <select class="form-select" id="lineas" name="lineas"><?php foreach ([10,20,50] as $amount): ?><option value="<?php echo $amount; ?>" <?php echo $perPage===$amount?'selected':''; ?>><?php echo $amount; ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Buscar</button><a class="btn btn-outline-secondary" href="<?php echo msp2Escape(msp2Url('documentos_tienda/index.php?id_lote='.$batchId)); ?>" title="Limpiar filtros" aria-label="Limpiar filtros"><i class="bi bi-x-lg" aria-hidden="true"></i></a></div>
                </div>
                <div class="small text-muted mt-2"><?php echo $filesTotal; ?> documento(s) encontrado(s).</div>
            </form>
            <?php if ($files === []): ?><div class="alert alert-secondary"><?php echo (int)$batch['total_archivos']===0 ? 'Este lote todavía no contiene documentos.' : 'No hay documentos que coincidan con los filtros.'; ?></div><?php endif; ?>
            <?php if ($files !== []): ?>
            <?php if ($canEditFiles): ?><form method="post" action="<?php echo msp2Escape(msp2Url('documentos_tienda/guardar_asociaciones.php')); ?>" id="association_form">
                <?php msp2CsrfField(); ?><input type="hidden" name="id_lote" value="<?php echo $batchId; ?>">
                <input type="hidden" name="return_q" value="<?php echo msp2Escape($filterQuery); ?>">
                <input type="hidden" name="return_estado" value="<?php echo msp2Escape($filterStatus); ?>">
                <input type="hidden" name="return_pagina" value="<?php echo $page; ?>">
                <input type="hidden" name="return_lineas" value="<?php echo $perPage; ?>">
            <?php endif; ?>
                <?php if ($canEditFiles): ?>
                <div class="gp-functional-surface mb-3">
                        <div class="row g-2 align-items-end">
                            <div class="col-xl-1 col-md-2">
                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="select_page"><label class="form-check-label" for="select_page">Página</label></div>
                            </div>
                            <div class="col-xl-9 col-md-7">
                                <label class="form-label fw-semibold" for="asociacion_masiva">Asociación o acción masiva</label>
                                <select class="form-select" id="asociacion_masiva" name="asociacion_masiva">
                                    <option value="">Seleccionar...</option>
                                    <option value="desasociar">— Quitar asociación y dejar pendiente —</option>
                                    <option value="omitir">— Omitir documentos seleccionados —</option>
                                    <?php foreach ($contractOptions as $option): ?><option value="<?php echo $option['id']; ?>"><?php echo msp2Escape($option['label']); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-xl-2 col-md-3 d-grid"><button class="btn btn-outline-primary" type="submit" name="modo" value="masivo">Aplicar a seleccionados</button></div>
                        </div>
                        <div class="form-text">Solo afecta los documentos marcados en esta página. Los enviados permanecen bloqueados.</div>
                </div>
                <?php endif; ?>
                <div class="d-grid gap-2" id="document_rows">
                <?php foreach ($files as $file):
                    $fileId=(int)$file['id_documento_tienda_archivo'];
                    $fileState=(string)$file['estado_archivo'];
                    $fileStatus=$statusLabels[$fileState] ?? [$fileState,'secondary'];
                    $sent=$fileState==='ENVIADO';
                    $locked=$sent||!$canEditFiles;
                ?>
                    <article class="document-row">
                        <div class="document-row-body">
                            <div class="row g-3 align-items-start">
                                <div class="col-xl-3">
                                    <div class="d-flex gap-2 align-items-start"><i class="bi bi-file-earmark-pdf text-danger fs-4"></i><div class="min-w-0">
                                        <?php if (!$locked): ?><div class="form-check mb-1"><input class="form-check-input js-file-check" type="checkbox" name="seleccionados[]" value="<?php echo $fileId; ?>" id="selected_<?php echo $fileId; ?>"><label class="form-check-label small" for="selected_<?php echo $fileId; ?>">Seleccionar</label></div><?php endif; ?>
                                        <div class="fw-semibold document-name"><?php echo msp2Escape((string)$file['nombre_original']); ?></div>
                                        <div class="small text-muted"><?php echo msp2Escape(msp2DocumentosTiendaFormatBytes((int)$file['bytes_archivo'])); ?> · <span class="badge text-bg-<?php echo msp2Escape($fileStatus[1]); ?>"><?php echo msp2Escape($fileStatus[0]); ?></span></div>
                                        <a class="small" target="_blank" rel="noopener" href="<?php echo msp2Escape(msp2Url('documentos_tienda/ver.php?id='.$fileId)); ?>">Previsualizar PDF</a>
                                    </div></div>
                                </div>
                                <div class="col-xl-6">
                                    <label class="form-label small fw-semibold" for="association_<?php echo $fileId; ?>">Tienda, locales, arrendatario y contrato</label>
                                    <?php if ($locked): ?>
                                        <div class="form-control bg-light"><?php echo msp2Escape((string)($file['nombre_comercial']??'-').' — '.(string)($file['locales_label']??'-').' — contrato #'.(int)($file['id_contrato_arriendo']??0)); ?></div>
                                    <?php else: ?>
                                        <select class="form-select form-select-sm" id="association_<?php echo $fileId; ?>" name="asociaciones[<?php echo $fileId; ?>]">
                                            <option value="">Sin cambios</option>
                                            <?php if ((int)($file['id_contrato_arriendo']??0)>0 || in_array($fileState,['OMITIDO','ERROR'],true)): ?><option value="desasociar">— Quitar asociación y dejar pendiente —</option><?php endif; ?>
                                            <option value="omitir" <?php echo $fileState==='OMITIDO'?'selected':''; ?>>— Omitir este documento —</option>
                                            <?php foreach ($contractOptions as $option): ?><option value="<?php echo $option['id']; ?>" <?php echo (int)($file['id_contrato_arriendo']??0)===$option['id']?'selected':''; ?>><?php echo msp2Escape($option['label']); ?></option><?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    <?php if ((string)($file['nombre_comercial']??'')!==''): ?><div class="small text-muted mt-1">Actual: <?php echo msp2Escape((string)$file['nombre_comercial']); ?> · <?php echo msp2Escape((string)($file['correo_destino_snapshot']??'sin correo registrado')); ?></div><?php endif; ?>
                                    <?php if ((string)($file['ultimo_error']??'')!==''): ?><div class="small text-danger mt-1"><?php echo msp2Escape((string)$file['ultimo_error']); ?></div><?php endif; ?>
                                </div>
                                <div class="col-xl-3"><label class="form-label small fw-semibold" for="note_<?php echo $fileId; ?>">Observación</label><input class="form-control form-control-sm" id="note_<?php echo $fileId; ?>" <?php if(!$locked): ?>name="observaciones[<?php echo $fileId; ?>]"<?php endif; ?> maxlength="1000" value="<?php echo msp2Escape((string)($file['observaciones']??'')); ?>" <?php echo $locked?'disabled':''; ?>></div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
                <?php if ($canEditFiles): ?><div class="d-flex justify-content-end mt-3"><button class="btn btn-primary" type="submit" name="modo" value="individual"><i class="bi bi-check2-circle me-1"></i>Guardar cambios individuales</button></div>
            </form><?php endif; ?>
            <?php if ($totalPages > 1): ?>
                <nav class="mt-3" aria-label="Paginación de documentos"><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
                    <li class="page-item <?php echo $page<=1?'disabled':''; ?>"><a class="page-link" href="<?php echo msp2Escape(msp2DocumentosTiendaPageUrl($batchId,$filterQuery,$filterStatus,max(1,$page-1),$perPage)); ?>">Anterior</a></li>
                    <?php for ($pageNumber=max(1,$page-2),$pageEnd=min($totalPages,$page+2);$pageNumber<=$pageEnd;$pageNumber++): ?><li class="page-item <?php echo $pageNumber===$page?'active':''; ?>"><a class="page-link" href="<?php echo msp2Escape(msp2DocumentosTiendaPageUrl($batchId,$filterQuery,$filterStatus,$pageNumber,$perPage)); ?>"><?php echo $pageNumber; ?></a></li><?php endfor; ?>
                    <li class="page-item <?php echo $page>=$totalPages?'disabled':''; ?>"><a class="page-link" href="<?php echo msp2Escape(msp2DocumentosTiendaPageUrl($batchId,$filterQuery,$filterStatus,min($totalPages,$page+1),$perPage)); ?>">Siguiente</a></li>
                </ul></nav>
            <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="gp-functional-surface mb-3">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div><h2 class="h5 mb-1">3. Control y preparación del lote</h2><p class="text-muted mb-0">Exporta el detalle o revisa una simulación sin enviar correos.</p></div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-success" href="<?php echo msp2Escape(msp2Url('documentos_tienda/exportar_resumen.php?id_lote='.$batchId)); ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Exportar resumen</a>
                        <?php if ($canSimulate): ?><a class="btn btn-outline-primary" href="<?php echo msp2Escape(msp2Url('documentos_tienda/simular_envio.php?id_lote='.$batchId)); ?>"><i class="bi bi-eye me-1"></i>Simular envío</a><?php endif; ?>
                    </div>
                </div>
                <?php if ($canApprove): ?>
                    <form method="post" action="<?php echo msp2Escape(msp2Url('documentos_tienda/cambiar_estado_lote.php')); ?>" class="row g-2 align-items-end" onsubmit="return confirm('<?php echo $isClosed ? '¿Reabrir este lote para permitir modificaciones?' : '¿Cerrar y bloquear este lote?'; ?>');">
                        <?php msp2CsrfField(); ?><input type="hidden" name="id_lote" value="<?php echo $batchId; ?>"><input type="hidden" name="accion_lote" value="<?php echo $isClosed?'reabrir':'cerrar'; ?>">
                        <div class="col-lg-9"><label class="form-label" for="motivo_lote">Motivo de <?php echo $isClosed?'reapertura':'cierre'; ?></label><input class="form-control" id="motivo_lote" name="motivo" minlength="5" maxlength="500" required placeholder="Deja constancia del motivo"></div>
                        <div class="col-lg-3 d-grid"><button class="btn btn-warning" <?php echo !$isClosed&&!$canCloseBatch?'disabled':''; ?>><i class="bi <?php echo $isClosed?'bi-unlock':'bi-lock'; ?> me-1"></i><?php echo $isClosed?'Reabrir lote':'Cerrar lote'; ?></button></div>
                    </form>
                    <?php if (!$isClosed && !$canCloseBatch): ?><div class="form-text mt-2">Para cerrar, el lote debe contener documentos y no tener pendientes ni errores.</div><?php endif; ?>
                    <?php if ($isClosed): ?><div class="small text-muted mt-2">Cerrado el <?php echo msp2Escape((string)($batch['fecha_cierre']??'-')); ?>. Las cargas y asociaciones están bloqueadas.</div><?php endif; ?>
                <?php endif; ?>
        </section>

        <details class="gp-disclosure">
            <summary><span>4. Historial completo del lote</span><span class="text-muted small"><?php echo $historyTotal; ?> evento(s)</span></summary>
            <div class="gp-disclosure__body p-0">
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Fecha</th><th>Usuario</th><th>Evento</th><th>Archivo</th><th>Detalle</th></tr></thead><tbody>
                <?php if ($events===[]): ?><tr><td colspan="5" class="text-center text-muted py-3">Sin eventos.</td></tr><?php endif; ?>
                <?php foreach ($events as $event): ?><tr><td class="text-nowrap"><?php echo msp2Escape((string)$event['fecha_evento']); ?></td><td><?php echo msp2Escape((string)($event['usuario']??'Sistema')); ?></td><td><?php echo msp2Escape(str_replace('_',' ',(string)$event['tipo_evento'])); ?></td><td class="document-name"><?php echo msp2Escape((string)($event['nombre_original']??'-')); ?></td><td><?php echo msp2Escape((string)($event['detalle_evento']??'-')); ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <?php if($historyPages>1): ?><div class="border-top p-2"><nav aria-label="Paginación del historial"><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
                <?php for($historyNumber=1;$historyNumber<=$historyPages;$historyNumber++): $historyParams=['id_lote'=>$batchId,'q'=>$filterQuery,'estado'=>$filterStatus,'pagina'=>$page,'lineas'=>$perPage,'historia_pagina'=>$historyNumber]; ?><li class="page-item <?php echo $historyNumber===$historyPage?'active':''; ?>"><a class="page-link" href="<?php echo msp2Escape(msp2Url('documentos_tienda/index.php?'.http_build_query($historyParams))); ?>"><?php echo $historyNumber; ?></a></li><?php endfor; ?>
            </ul></nav></div><?php endif; ?>
            </div>
        </details>
    <?php endif; ?>
</main>
<script src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const form = document.getElementById('association_form');
    const selectPage = document.getElementById('select_page');
    const fileChecks = () => Array.from(document.querySelectorAll('.js-file-check'));
    selectPage?.addEventListener('change', () => fileChecks().forEach((checkbox) => { checkbox.checked = selectPage.checked; }));
    fileChecks().forEach((checkbox) => checkbox.addEventListener('change', () => {
        if (!selectPage) return;
        selectPage.checked = fileChecks().length > 0 && fileChecks().every((item) => item.checked);
        selectPage.indeterminate = fileChecks().some((item) => item.checked) && !selectPage.checked;
    }));
    form?.addEventListener('submit', (event) => {
        if (event.submitter?.value !== 'masivo') return;
        const selected = fileChecks().filter((checkbox) => checkbox.checked);
        const action = document.getElementById('asociacion_masiva')?.value || '';
        if (selected.length === 0 || action === '') {
            event.preventDefault();
            window.alert('Selecciona al menos un documento y una acción masiva.');
            return;
        }
        if ((action === 'desasociar' || action === 'omitir') && !window.confirm('¿Aplicar esta acción a ' + selected.length + ' documento(s)?')) {
            event.preventDefault();
        }
    });
})();
</script>
</body>
</html>
