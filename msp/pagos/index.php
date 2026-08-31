<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/respaldo_excel_helper.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}
$tablaExiste = false;
$pagos = [];
$documentosDisponibles = [];
$loadError = null;
$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];
$previewImportacion = msp2PagosPreviewSessionRead();

$lineasPermitidas = [10, 25, 50, 100, 200];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;

if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}

$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$filters = msp2PagosNormalizeFilters($_GET);
$filtroDocumento = $filters['filtroDocumento'];
$filtroTienda = $filters['filtroTienda'];
$filtroArrendatario = $filters['filtroArrendatario'];
$filtroEstado = $filters['filtroEstado'];

$estadoPago = msp2PagosEstadoMap();

try {
    $requiredTables = [
        'msp_pagos',
        'msp_documentos_cobro',
        'msp_tiendas',
    ];

    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];
    if (!$tablaExiste) {
        $loadError = 'Faltan tablas requeridas para pagos: `' . implode('`, `', $missingTables) . '`. Ejecuta `msp/db/msp_documento_pago.sql`.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura base del modulo de pagos.';
}

if ($tablaExiste) {
    try {
        $filterSql = msp2PagosBuildFilters($filters, $estadoPago);
        $whereClause = $filterSql['where'];
        $params = $filterSql['params'];

        $documentosStmt = $conn->query(
            "SELECT
                dc.id_documento_cobro,
                dc.periodo_facturacion,
                dc.numero_documento,
                dc.monto_total,
                dc.saldo_pendiente,
                dc.estado_documento,
                dc.nombre_arrendatario_snapshot,
                dc.rut_arrendatario_snapshot,
                t.nombre_comercial
             FROM dbo.msp_documentos_cobro dc
             INNER JOIN dbo.msp_tiendas t
                 ON t.id_tienda = dc.id_tienda
             WHERE dc.estado_documento <> 5
               AND dc.saldo_pendiente > 0
             ORDER BY dc.periodo_facturacion DESC, t.nombre_comercial ASC"
        );
        $documentosDisponibles = $documentosStmt->fetchAll();

        $countStmt = $conn->prepare(
            "SELECT COUNT(*)
             FROM dbo.msp_pagos p
             INNER JOIN dbo.msp_documentos_cobro dc
                 ON dc.id_documento_cobro = p.id_documento_cobro
             INNER JOIN dbo.msp_tiendas t
                 ON t.id_tienda = dc.id_tienda
             WHERE $whereClause"
        );

        msp2PagosBindParams($countStmt, $params);

        $countStmt->execute();
        $totalRegistros = (int) $countStmt->fetchColumn();
        $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
        $paginaActual = min($paginaActual, $totalPaginas);
        $offset = ($paginaActual - 1) * $lineasPorPagina;

        $stmt = $conn->prepare(
            "SELECT
                p.id_pago,
                p.id_documento_cobro,
                p.fecha_pago,
                p.monto_pagado,
                p.estado_pago,
                p.fecha_anulacion,
                p.motivo_anulacion,
                p.medio_pago,
                p.referencia_pago,
                p.observaciones,
                dc.periodo_facturacion,
                dc.numero_documento,
                dc.monto_total,
                dc.saldo_pendiente,
                t.nombre_comercial,
                dc.nombre_arrendatario_snapshot,
                dc.rut_arrendatario_snapshot
             FROM dbo.msp_pagos p
             INNER JOIN dbo.msp_documentos_cobro dc
                 ON dc.id_documento_cobro = p.id_documento_cobro
             INNER JOIN dbo.msp_tiendas t
                 ON t.id_tienda = dc.id_tienda
             WHERE $whereClause
             ORDER BY p.fecha_pago DESC, p.id_pago DESC
             OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY"
        );

        msp2PagosBindParams($stmt, $params);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':lineas', $lineasPorPagina, PDO::PARAM_INT);
        $stmt->execute();
        $pagos = $stmt->fetchAll();
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar los pagos. Detalle tecnico: ' . $exception->getMessage();
    }
}

if ($tablaExiste && $totalPaginas > 1) {
    $pages = [1];
    $start = max(2, $paginaActual - 2);
    $end = min($totalPaginas - 1, $paginaActual + 2);

    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($totalPaginas > 1) {
        $pages[] = $totalPaginas;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    $prev = null;
    foreach ($pages as $page) {
        if ($prev !== null && $page > $prev + 1) {
            $paginationItems[] = 'ellipsis';
        }
        $paginationItems[] = $page;
        $prev = $page;
    }
}

$queryBase = $_GET;
unset($queryBase['pagina']);

function formatoPagoFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    if ($parsed === false) {
        return $value;
    }

    return $parsed->format('d-m-Y');
}

function formatoPagoMonto(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return '$ ' . number_format((float) $value, 2, ',', '.');
}

function formatoPagoPeriodo(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    if ($parsed === false) {
        return $value;
    }

    return $parsed->format('m-Y');
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Pagos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">

<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a MSP
                </a>
                <a href="<?php echo msp2Escape(msp2Url('documentos_cobro/index.php')); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-receipt me-1" aria-hidden="true"></i>Documentos
                </a>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo msp2Escape(msp2Url('pagos/exportar_respaldo.php?' . msp2PagosBuildQuery($_GET))); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Exportar respaldo XLSX
                </a>
                <a href="<?php echo msp2Escape(msp2Url('pagos/archivos_pdf.php')); ?>" class="btn btn-outline-dark btn-sm">
                    <i class="bi bi-archive me-1" aria-hidden="true"></i>Respaldo PDFs
                </a>
                <a href="<?php echo msp2Escape(msp2Url('pagos/simulacion_masiva.php')); ?>" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-upload me-1" aria-hidden="true"></i>Pago masivo
                </a>
                <button
                    type="button"
                    class="btn btn-primary btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#modalCrearPago"
                    <?php echo (!$tablaExiste || empty($documentosDisponibles)) ? 'disabled' : ''; ?>>
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Registrar pago
                </button>
                <button
                    type="button"
                    class="btn btn-outline-dark btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#modalAplicarGarantia"
                    <?php echo (!$tablaExiste || empty($documentosDisponibles)) ? 'disabled' : ''; ?>>
                    <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Aplicar garantia
                </button>
            </div>
        </div>

        <p class="section-kicker text-center">MSP / Cobros</p>
        <h1 class="form-title text-center mb-2">Pagos de Documentos</h1>
        <p class="text-muted text-center mb-4">Registro de pagos en cuotas no fijas, con recalculo automático de saldo.</p>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <h2 class="h5 mb-1">Respaldo e importación de pagos</h2>
                        <p class="text-muted mb-0">Exporta pagos aplicados a Excel y vuelve a cargarlos con validación previa.</p>
                    </div>
                    <div class="small text-muted">
                        Formato: <strong><?php echo msp2Escape(msp2PagosBackupVersion()); ?></strong>
                    </div>
                </div>
                <div class="row g-2 align-items-end mt-2">
                    <form method="post" action="<?php echo msp2Escape(msp2Url('pagos/importar_respaldo.php')); ?>" enctype="multipart/form-data" class="col-12 col-lg-9 row g-2 align-items-end m-0 p-0">
                        <div class="col-12 col-lg-8">
                            <label for="excel_file_pagos_backup" class="form-label">Archivo de respaldo</label>
                            <input type="file" class="form-control" id="excel_file_pagos_backup" name="excel_file" accept=".xlsx,.xls,.csv" required>
                        </div>
                        <div class="col-12 col-lg-4 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-upload me-1" aria-hidden="true"></i>Previsualizar importación
                            </button>
                        </div>
                    </form>
                    <div class="col-12 col-lg-3 d-flex flex-wrap gap-2 justify-content-lg-end">
                        <?php if (is_array($previewImportacion)): ?>
                            <form method="post" action="<?php echo msp2Escape(msp2Url('pagos/descartar_importacion_respaldo.php')); ?>">
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="bi bi-trash me-1" aria-hidden="true"></i>Descartar preview
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (is_array($previewImportacion)): ?>
            <?php $previewSummary = msp2PagosBackupPreviewSummary($previewImportacion); ?>
            <?php $previewRows = is_array($previewImportacion['rows'] ?? null) ? $previewImportacion['rows'] : []; ?>
            <div class="card border-<?php echo $previewSummary['error_rows'] > 0 ? 'warning' : 'success'; ?> shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <h2 class="h5 mb-1">Vista previa de importación</h2>
                            <p class="text-muted mb-0">
                                Archivo: <strong><?php echo msp2Escape((string) ($previewImportacion['original_name'] ?? 'respaldo.xlsx')); ?></strong>
                                | Registros OK: <strong><?php echo (int) $previewSummary['ok_rows']; ?></strong>
                                | Errores: <strong><?php echo (int) $previewSummary['error_rows']; ?></strong>
                            </p>
                        </div>
                        <div class="text-end small text-muted">
                            Documentos: <strong><?php echo (int) $previewSummary['document_count']; ?></strong><br>
                            Total pagos: <strong><?php echo msp2Escape(formatoPagoMonto($previewSummary['total_monto_pagado'])); ?></strong>
                        </div>
                    </div>

                    <?php if ($previewRows !== []): ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Estado</th>
                                        <th>Pago UID</th>
                                        <th>Documento destino</th>
                                        <th>Fecha pago</th>
                                        <th class="text-end">Monto</th>
                                        <th>Resultado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($previewRows as $previewRow): ?>
                                        <tr>
                                            <td>
                                                <span class="badge <?php echo ($previewRow['status'] ?? '') === 'OK' ? 'text-bg-success' : 'text-bg-warning'; ?>">
                                                    <?php echo msp2Escape((string) ($previewRow['status'] ?? 'ERROR')); ?>
                                                </span>
                                            </td>
                                            <td><?php echo msp2Escape((string) ($previewRow['pago_uid'] ?? '')); ?></td>
                                            <td>
                                                <div><?php echo msp2Escape((string) ($previewRow['document_label'] ?? '')); ?></div>
                                                <small class="text-muted"><?php echo msp2Escape((string) ($previewRow['document_key'] ?? '')); ?></small>
                                            </td>
                                            <td><?php echo msp2Escape(formatoPagoFecha((string) ($previewRow['fecha_pago'] ?? ''))); ?></td>
                                            <td class="text-end"><?php echo msp2Escape(formatoPagoMonto($previewRow['monto_pagado'] ?? null)); ?></td>
                                            <td>
                                                <?php if (($previewRow['status'] ?? '') === 'OK'): ?>
                                                    <span class="text-success">Listo para importar</span>
                                                <?php else: ?>
                                                    <span class="text-warning"><?php echo msp2Escape((string) ($previewRow['error'] ?? 'Error de validación.')); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <form method="post" action="<?php echo msp2Escape(msp2Url('pagos/confirmar_importacion_respaldo.php')); ?>">
                            <button type="submit" class="btn btn-success" <?php echo $previewSummary['ok_rows'] <= 0 ? 'disabled' : ''; ?>>
                                <i class="bi bi-check-circle me-1" aria-hidden="true"></i>Confirmar importación
                            </button>
                            <div class="small text-muted mt-1">
                                Se importan solo filas <strong>OK</strong>; las filas con error se descartan automáticamente.
                            </div>
                        </form>
                        <?php if ($previewSummary['error_rows'] > 0): ?>
                            <form method="post" action="<?php echo msp2Escape(msp2Url('pagos/limpiar_errores_importacion_respaldo.php')); ?>">
                                <button type="submit" class="btn btn-outline-warning">
                                    <i class="bi bi-funnel me-1" aria-hidden="true"></i>Quitar filas con error
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?php echo msp2Escape(msp2Url('pagos/descartar_importacion_respaldo.php')); ?>">
                            <button type="submit" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Descartar preview
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tablaExiste && empty($documentosDisponibles)): ?>
            <div class="alert alert-warning">No hay documentos con saldo pendiente para registrar pagos.</div>
        <?php endif; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <form method="get" class="row g-2 mb-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label for="filtroDocumento" class="form-label">Documento</label>
                    <input type="text" id="filtroDocumento" name="filtroDocumento" class="form-control" value="<?php echo msp2Escape($filtroDocumento); ?>" placeholder="Numero o ID">
                </div>
                <div class="col-12 col-md-3">
                    <label for="filtroTienda" class="form-label">Tienda</label>
                    <input type="text" id="filtroTienda" name="filtroTienda" class="form-control" value="<?php echo msp2Escape($filtroTienda); ?>" placeholder="Nombre tienda">
                </div>
                <div class="col-12 col-md-3">
                    <label for="filtroArrendatario" class="form-label">Arrendatario</label>
                    <input type="text" id="filtroArrendatario" name="filtroArrendatario" class="form-control" value="<?php echo msp2Escape($filtroArrendatario); ?>" placeholder="Nombre o RUT">
                </div>
                <div class="col-6 col-md-2">
                    <label for="filtroEstado" class="form-label">Estado pago</label>
                    <select id="filtroEstado" name="filtroEstado" class="form-select">
                        <option value="">(Todos)</option>
                        <?php foreach ($estadoPago as $idEstado => $estado): ?>
                            <option value="<?php echo (int) $idEstado; ?>" <?php echo $filtroEstado === (string) $idEstado ? 'selected' : ''; ?>>
                                <?php echo msp2Escape($estado['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label for="lineas" class="form-label">Lineas</label>
                    <select id="lineas" name="lineas" class="form-select">
                        <?php foreach ($lineasPermitidas as $lineas): ?>
                            <option value="<?php echo $lineas; ?>" <?php echo $lineasPorPagina === $lineas ? 'selected' : ''; ?>>
                                <?php echo $lineas; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-12 d-grid">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </div>
            </form>

            <div class="table-responsive mt-3">
                <table class="table table-bordered table-hover align-middle text-center">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 60px;">#</th>
                            <th style="width: 120px;">Fecha pago</th>
                            <th style="width: 140px;">Documento</th>
                            <th style="width: 120px;">Periodo</th>
                            <th>Tienda</th>
                            <th>Arrendatario</th>
                            <th style="width: 140px;">Monto pagado</th>
                            <th style="width: 140px;">Estado pago</th>
                            <th style="width: 160px;">Saldo documento</th>
                            <th style="width: 130px;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pagos)): ?>
                            <tr>
                                <td colspan="10" class="text-muted">
                                    <?php echo ($filtroDocumento === '' && $filtroTienda === '' && $filtroArrendatario === '' && $filtroEstado === '') ? 'No hay pagos registrados todavía.' : 'Sin resultados para los filtros actuales.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pagos as $index => $pago): ?>
                                <?php $estado = $estadoPago[(int) ($pago['estado_pago'] ?? 0)] ?? ['label' => 'Desconocido', 'badge' => 'text-bg-light text-dark']; ?>
                                <tr>
                                    <td><?php echo (($paginaActual - 1) * $lineasPorPagina) + $index + 1; ?></td>
                                    <td><?php echo msp2Escape(formatoPagoFecha($pago['fecha_pago'] ?? null)); ?></td>
                                    <td>
                                        <div><strong>#<?php echo (int) $pago['id_documento_cobro']; ?></strong></div>
                                        <small class="text-muted"><?php echo msp2Escape((string) ($pago['numero_documento'] ?? '')); ?></small>
                                    </td>
                                    <td><?php echo msp2Escape(formatoPagoPeriodo($pago['periodo_facturacion'] ?? null)); ?></td>
                                    <td class="text-start"><?php echo msp2Escape((string) ($pago['nombre_comercial'] ?? '')); ?></td>
                                    <td class="text-start">
                                        <div><?php echo msp2Escape((string) ($pago['nombre_arrendatario_snapshot'] ?? '')); ?></div>
                                        <small class="text-muted"><?php echo msp2Escape((string) ($pago['rut_arrendatario_snapshot'] ?? '')); ?></small>
                                    </td>
                                    <td class="text-end"><?php echo msp2Escape(formatoPagoMonto($pago['monto_pagado'] ?? null)); ?></td>
                                    <td>
                                        <span class="badge <?php echo $estado['badge']; ?>">
                                            <?php echo msp2Escape($estado['label']); ?>
                                        </span>
                                        <?php if ((int) ($pago['estado_pago'] ?? 0) === 2): ?>
                                            <div class="small text-muted mt-1">
                                                <?php echo msp2Escape('Anulado: ' . formatoPagoFecha($pago['fecha_anulacion'] ?? null)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?php echo msp2Escape(formatoPagoMonto($pago['saldo_pendiente'] ?? null)); ?></td>
                                    <td>
                                        <?php if ((int) ($pago['estado_pago'] ?? 0) === 1): ?>
                                            <button
                                                type="button"
                                                class="btn btn-outline-danger btn-sm js-anular-pago"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalAnularPago"
                                                data-id="<?php echo (int) $pago['id_pago']; ?>"
                                                data-pago-label="<?php echo msp2Escape('Pago #' . (int) $pago['id_pago'] . ' / Doc #' . (int) $pago['id_documento_cobro']); ?>">
                                                <i class="bi bi-x-circle" aria-hidden="true"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">Sin acciones</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                <div class="small text-muted">
                    Total: <strong><?php echo number_format($totalRegistros, 0, ',', '.'); ?></strong>
                    | Pagina <strong><?php echo $paginaActual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                </div>
                <?php if ($totalPaginas > 1): ?>
                    <nav aria-label="Paginacion de pagos">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo msp2PagosBuildQuery($queryBase, ['pagina' => max(1, $paginaActual - 1)]); ?>" aria-label="Anterior">&laquo;</a>
                            </li>
                            <?php foreach ($paginationItems as $item): ?>
                                <?php if ($item === 'ellipsis'): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php else: ?>
                                    <li class="page-item <?php echo (int) $item === $paginaActual ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo msp2PagosBuildQuery($queryBase, ['pagina' => $item]); ?>"><?php echo $item; ?></a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo msp2PagosBuildQuery($queryBase, ['pagina' => min($totalPaginas, $paginaActual + 1)]); ?>" aria-label="Siguiente">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade" id="modalCrearPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('pagos/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Registrar pago</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="crear_id_documento_cobro" class="form-label">Documento</label>
                    <select class="form-select" id="crear_id_documento_cobro" name="id_documento_cobro" required>
                        <option value="">Selecciona un documento</option>
                        <?php foreach ($documentosDisponibles as $documento): ?>
                            <option value="<?php echo (int) $documento['id_documento_cobro']; ?>">
                                <?php
                                $label = '#'
                                    . (int) $documento['id_documento_cobro']
                                    . ' | '
                                    . (string) ($documento['numero_documento'] ?? '')
                                    . ' | '
                                    . formatoPagoPeriodo((string) ($documento['periodo_facturacion'] ?? null))
                                    . ' | '
                                    . (string) ($documento['nombre_comercial'] ?? '')
                                    . ' | Saldo: '
                                    . formatoPagoMonto($documento['saldo_pendiente'] ?? null);
                                echo msp2Escape($label);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label for="crear_fecha_pago" class="form-label">Fecha pago</label>
                        <input type="date" class="form-control" id="crear_fecha_pago" name="fecha_pago" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="crear_monto_pagado" class="form-label">Monto pagado</label>
                        <input type="number" class="form-control" id="crear_monto_pagado" name="monto_pagado" min="0.01" step="0.01" required>
                    </div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-6">
                        <label for="crear_medio_pago" class="form-label">Medio pago</label>
                        <input type="text" class="form-control" id="crear_medio_pago" name="medio_pago" maxlength="50" placeholder="Transferencia, efectivo...">
                    </div>
                    <div class="col-md-6">
                        <label for="crear_referencia_pago" class="form-label">Referencia</label>
                        <input type="text" class="form-control" id="crear_referencia_pago" name="referencia_pago" maxlength="100" placeholder="Nro operación">
                    </div>
                </div>
                <div class="mt-3">
                    <label for="crear_observaciones_pago" class="form-label">Observaciones</label>
                    <textarea class="form-control" id="crear_observaciones_pago" name="observaciones" rows="2" maxlength="500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar pago</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalAplicarGarantia" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('pagos/aplicar_garantia.php')); ?>" data-confirm-message="¿Aplicar garantia al documento seleccionado?" data-confirm-title="Confirmar aplicacion" data-confirm-variant="warning">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Aplicar garantia</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="garantia_id_documento_cobro" class="form-label">Documento</label>
                    <select class="form-select" id="garantia_id_documento_cobro" name="id_documento_cobro" required>
                        <option value="">Selecciona un documento</option>
                        <?php foreach ($documentosDisponibles as $documento): ?>
                            <option value="<?php echo (int) $documento['id_documento_cobro']; ?>">
                                <?php
                                $label = '#'
                                    . (int) $documento['id_documento_cobro']
                                    . ' | '
                                    . (string) ($documento['numero_documento'] ?? '')
                                    . ' | '
                                    . (string) ($documento['nombre_arrendatario_snapshot'] ?? '')
                                    . ' | Saldo: '
                                    . formatoPagoMonto($documento['saldo_pendiente'] ?? null);
                                echo msp2Escape($label);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label for="garantia_fecha_pago" class="form-label">Fecha aplicacion</label>
                        <input type="date" class="form-control" id="garantia_fecha_pago" name="fecha_pago" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="garantia_monto_aplicar" class="form-label">Monto</label>
                        <input type="number" class="form-control" id="garantia_monto_aplicar" name="monto_aplicar" min="0.01" step="0.01" required>
                    </div>
                </div>
                <div class="mt-3">
                    <label for="garantia_observaciones" class="form-label">Observaciones</label>
                    <textarea class="form-control" id="garantia_observaciones" name="observaciones" rows="2" maxlength="500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark">Aplicar garantia</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalAnularPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('pagos/anular.php')); ?>" data-confirm-message="¿Confirmar anulación del pago?" data-confirm-title="Confirmar anulación" data-confirm-variant="danger">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Anular pago</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_pago" id="anular_id_pago">
                <p class="mb-2">Vas a anular <strong id="anular_pago_label"></strong>.</p>
                <div>
                    <label for="anular_motivo" class="form-label">Motivo de anulación</label>
                    <textarea class="form-control" id="anular_motivo" name="motivo_anulacion" rows="3" maxlength="500" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">Confirmar anulación</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    document.querySelectorAll('.js-anular-pago').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('anular_id_pago').value = button.dataset.id || '';
            document.getElementById('anular_pago_label').textContent = button.dataset.pagoLabel || '';
            document.getElementById('anular_motivo').value = '';
        });
    });
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
