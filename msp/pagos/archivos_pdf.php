<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/archivos_pdf_helper.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';

msp2RequireAccess();

function buildArchivosPdfQuery(array $base, array $overrides = []): string
{
    $query = array_merge($base, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return http_build_query($query);
}

function msp2ArchivosPdfSplitLocalesLabel(string $label): array
{
    $parts = preg_split('/\s*[\/,;|]\s*/u', trim($label)) ?: [];
    $codes = [];

    foreach ($parts as $part) {
        $code = msp2NormalizeLocalCode((string) $part);
        if ($code === '' || in_array($code, $codes, true)) {
            continue;
        }
        $codes[] = $code;
    }

    if ($codes === []) {
        $normalized = msp2NormalizeLocalCode($label);
        return $normalized === '' ? [] : [$normalized];
    }

    usort($codes, static fn(string $a, string $b): int => msp2CompareLocalCode($a, $b));

    return $codes;
}

function msp2ArchivosPdfCompareLocalesLabel(string $a, string $b): int
{
    $codesA = msp2ArchivosPdfSplitLocalesLabel($a);
    $codesB = msp2ArchivosPdfSplitLocalesLabel($b);

    $aHasCodes = $codesA !== [];
    $bHasCodes = $codesB !== [];
    if ($aHasCodes !== $bHasCodes) {
        return $aHasCodes ? -1 : 1;
    }

    if ($aHasCodes && $bHasCodes) {
        $minLen = min(count($codesA), count($codesB));
        for ($i = 0; $i < $minLen; $i++) {
            $cmp = msp2CompareLocalCode($codesA[$i], $codesB[$i]);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        $cmpCount = count($codesA) <=> count($codesB);
        if ($cmpCount !== 0) {
            return $cmpCount;
        }
    }

    return strcasecmp(trim($a), trim($b));
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    msp2RequireValidCsrfToken();
    $accion = trim((string) ($_POST['accion'] ?? ''));
    if ($accion === 'limpiar_huerfanos_pdf') {
        $queryBase = [
            'filtroPeriodo' => trim((string) ($_POST['filtroPeriodo'] ?? '')),
            'filtroArrendatario' => msp2NormalizeText($_POST['filtroArrendatario'] ?? null),
            'filtroLocales' => msp2NormalizeText($_POST['filtroLocales'] ?? null),
            'filtroTipo' => trim((string) ($_POST['filtroTipo'] ?? '')),
            'filtroEstado' => trim((string) ($_POST['filtroEstado'] ?? '')),
            'lineas' => trim((string) ($_POST['lineas'] ?? '')),
            'pagina' => 1,
        ];

        try {
            $purge = msp2ArchivosPdfPurgeOrphans($conn);
            $msg = 'Huérfanos limpiados: registros borrados ' . (int) ($purge['rows_deleted'] ?? 0)
                . ' | archivos físicos borrados ' . (int) ($purge['files_deleted'] ?? 0) . '.';
            if ((int) ($purge['files_errors'] ?? 0) > 0) {
                $msg .= ' Archivos con error al borrar: ' . (int) ($purge['files_errors'] ?? 0) . '.';
                msp2SetFlash('warning', $msg);
            } else {
                msp2SetFlash('success', $msg);
            }
        } catch (Throwable $e) {
            msp2SetFlash('danger', 'No fue posible limpiar respaldos huérfanos.');
        }

        $query = buildArchivosPdfQuery($queryBase);
        $redirect = msp2ArchivosPdfRedirectListUrl();
        if ($query !== '') {
            $redirect .= '?' . $query;
        }
        msp2Redirect($redirect);
    }
}

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$loadError = null;
$rows = [];
$arrendatarioOptions = [];
$localesOptions = [];
$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];
$lineasPermitidas = [10, 25, 50, 100];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;
if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}

$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$filtroPeriodo = trim((string) ($_GET['filtroPeriodo'] ?? ''));
$filtroArrendatario = msp2NormalizeText($_GET['filtroArrendatario'] ?? null);
$filtroLocales = msp2NormalizeText($_GET['filtroLocales'] ?? null);
$filtroTipo = trim((string) ($_GET['filtroTipo'] ?? ''));
$filtroEstado = trim((string) ($_GET['filtroEstado'] ?? ''));

$tiposArchivo = [
    'VALE_PAGO' => 'Vale de pago',
    'COMPROBANTE_GASTOS' => 'Comprobante de gastos',
    'VALE_COBRO' => 'Vale de cobro',
];
$estadosArchivo = [
    'ACTIVO' => ['label' => 'Activo', 'badge' => 'text-bg-success'],
    'REGENERADO' => ['label' => 'Regenerado', 'badge' => 'text-bg-info'],
    'FALTANTE' => ['label' => 'Faltante', 'badge' => 'text-bg-warning'],
    'ERROR' => ['label' => 'Error', 'badge' => 'text-bg-danger'],
    'HUERFANO' => ['label' => 'Huérfano', 'badge' => 'text-bg-danger'],
];

try {
    if (!msp2TableExists($conn, 'msp_pago_contrato_archivos')) {
        throw new RuntimeException('Falta la tabla dbo.msp_pago_contrato_archivos. Ejecuta los patch de respaldo PDF.');
    }

    $conditions = [];
    $params = [];
    $orphanConditionSql = "(dc.id_documento_cobro IS NULL OR (UPPER(ISNULL(a.tipo_archivo, '')) <> 'VALE_COBRO' AND p.id_pago IS NULL))";

    if ($filtroPeriodo !== '' && preg_match('/^\d{4}-\d{2}$/', $filtroPeriodo) === 1) {
        $conditions[] = 'a.periodo_ym = :periodo_ym';
        $params[':periodo_ym'] = $filtroPeriodo;
    }
    if ($filtroArrendatario !== '') {
        $conditions[] = 'ISNULL(a.arrendatario_nombre, \'\') = :arrendatario';
        $params[':arrendatario'] = $filtroArrendatario;
    }
    if ($filtroLocales !== '') {
        $conditions[] = 'ISNULL(a.locales, \'\') = :locales';
        $params[':locales'] = $filtroLocales;
    }
    if ($filtroTipo !== '' && isset($tiposArchivo[$filtroTipo])) {
        $conditions[] = 'a.tipo_archivo = :tipo_archivo';
        $params[':tipo_archivo'] = $filtroTipo;
    }
    if ($filtroEstado !== '' && isset($estadosArchivo[$filtroEstado])) {
        if ($filtroEstado === 'HUERFANO') {
            $conditions[] = $orphanConditionSql;
        } else {
            $conditions[] = 'a.estado_archivo = :estado_archivo';
            $params[':estado_archivo'] = $filtroEstado;
            $conditions[] = 'NOT ' . $orphanConditionSql;
        }
    }

    $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);
    $fromClause = "FROM dbo.msp_pago_contrato_archivos a
         LEFT JOIN dbo.msp_pagos p
            ON p.id_pago = a.id_pago
         LEFT JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = a.id_documento_cobro";

    $countStmt = $conn->prepare(
        "SELECT COUNT(*)
         $fromClause
         WHERE $whereClause"
    );
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalRegistros = (int) $countStmt->fetchColumn();
    $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
    $paginaActual = min($paginaActual, $totalPaginas);
    $offset = ($paginaActual - 1) * $lineasPorPagina;

    $stmt = $conn->prepare(
        "SELECT
            a.id_pago_contrato_archivo,
            a.id_pago,
            a.id_documento_cobro,
            a.id_contrato_arriendo,
            a.id_arrendatario,
            a.modulo_origen,
            a.tipo_archivo,
            a.periodo_ym,
            a.fecha_pago,
            a.numero_documento,
            a.arrendatario_nombre,
            a.locales,
            a.ruta_relativa,
            a.bytes_archivo,
            a.estado_archivo,
            a.fecha_generacion,
            a.updated_at,
            CASE WHEN p.id_pago IS NULL THEN 0 ELSE 1 END AS pago_origen_existe,
            CASE WHEN dc.id_documento_cobro IS NULL THEN 0 ELSE 1 END AS documento_origen_existe
         $fromClause
         WHERE $whereClause
         ORDER BY a.fecha_pago DESC, a.id_documento_cobro DESC, a.id_pago_contrato_archivo DESC
         OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':lineas', $lineasPorPagina, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll() ?: [];

    $stmtArrendatarios = $conn->query(
        "SELECT DISTINCT
            LTRIM(RTRIM(ISNULL(arrendatario_nombre, ''))) AS arrendatario_nombre
         FROM dbo.msp_pago_contrato_archivos
         WHERE LTRIM(RTRIM(ISNULL(arrendatario_nombre, ''))) <> ''
         ORDER BY arrendatario_nombre ASC"
    );
    foreach ($stmtArrendatarios->fetchAll() ?: [] as $optionRow) {
        $label = trim((string) ($optionRow['arrendatario_nombre'] ?? ''));
        if ($label === '') {
            continue;
        }
        $arrendatarioOptions[] = [
            'value' => $label,
            'label' => $label,
            'search' => $label,
        ];
    }

    $localesSql = "SELECT DISTINCT
            LTRIM(RTRIM(ISNULL(locales, ''))) AS locales
         FROM dbo.msp_pago_contrato_archivos
         WHERE LTRIM(RTRIM(ISNULL(locales, ''))) <> ''";
    $localesParams = [];
    if ($filtroPeriodo !== '' && preg_match('/^\d{4}-\d{2}$/', $filtroPeriodo) === 1) {
        $localesSql .= " AND periodo_ym = :opt_periodo_ym";
        $localesParams[':opt_periodo_ym'] = $filtroPeriodo;
    }
    if ($filtroArrendatario !== '') {
        $localesSql .= " AND ISNULL(arrendatario_nombre, '') = :opt_arrendatario";
        $localesParams[':opt_arrendatario'] = $filtroArrendatario;
    }
    $stmtLocales = $conn->prepare($localesSql);
    foreach ($localesParams as $key => $value) {
        $stmtLocales->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmtLocales->execute();
    foreach ($stmtLocales->fetchAll() ?: [] as $optionRow) {
        $label = trim((string) ($optionRow['locales'] ?? ''));
        if ($label === '') {
            continue;
        }
        $localesOptions[] = [
            'value' => $label,
            'label' => $label,
            'search' => $label,
        ];
    }

    usort(
        $localesOptions,
        static fn(array $a, array $b): int => msp2ArchivosPdfCompareLocalesLabel(
            (string) ($a['label'] ?? ''),
            (string) ($b['label'] ?? '')
        )
    );
} catch (Throwable $exception) {
    $loadError = 'No fue posible cargar los respaldos PDF.';
}

if ($totalPaginas > 1) {
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

    $lastPage = 0;
    foreach ($pages as $page) {
        if ($lastPage > 0 && $page > ($lastPage + 1)) {
            $paginationItems[] = 'ellipsis';
        }
        $paginationItems[] = $page;
        $lastPage = $page;
    }
}

$queryBase = $_GET;
unset($queryBase['pagina']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Respaldo PDFs</title>
    <?php msp2RenderSearchableSelectAssets(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-xl-4">
    <div class="msp-management-index msp-pdf-backups-index">
        <header class="msp-management-page-header msp-pdf-backups-page-header">
            <div class="msp-pdf-backups-back">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
                </a>
            </div>
            <h1>Respaldo PDFs</h1>
            <div class="msp-pdf-backups-actions">
                <?php if ($loadError === null): ?>
                    <form method="post" class="d-inline" data-confirm-title="Limpiar huérfanos" data-confirm-message="Se eliminarán respaldos huérfanos de la base y archivos físicos cuando no tengan referencias. ¿Continuar?" data-confirm-variant="danger">
                        <input type="hidden" name="accion" value="limpiar_huerfanos_pdf">
                        <input type="hidden" name="filtroPeriodo" value="<?php echo msp2Escape($filtroPeriodo); ?>">
                        <input type="hidden" name="filtroArrendatario" value="<?php echo msp2Escape($filtroArrendatario); ?>">
                        <input type="hidden" name="filtroLocales" value="<?php echo msp2Escape($filtroLocales); ?>">
                        <input type="hidden" name="filtroTipo" value="<?php echo msp2Escape($filtroTipo); ?>">
                        <input type="hidden" name="filtroEstado" value="<?php echo msp2Escape($filtroEstado); ?>">
                        <input type="hidden" name="lineas" value="<?php echo (int) $lineasPorPagina; ?>">
                        <?php msp2CsrfField(); ?>
                        <button
                            type="submit"
                            class="btn btn-outline-danger btn-sm"
                            data-bs-toggle="tooltip"
                            data-bs-placement="bottom"
                            data-bs-title="Limpia respaldos huérfanos (sin pago/doc origen) en BD y disco.">
                            <i class="bi bi-trash me-1" aria-hidden="true"></i>Limpiar huérfanos
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </header>

        <?php msp2RenderFlash($flash); ?>
        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div>
        <?php else: ?>
            <form method="get" class="row g-2 align-items-end msp-management-filters msp-pdf-backups-filters">
                        <div class="col-12 col-md-6 col-xl-3">
                            <label for="filtroPeriodo" class="form-label">Periodo</label>
                            <input type="month" class="form-control" id="filtroPeriodo" name="filtroPeriodo" value="<?php echo msp2Escape($filtroPeriodo); ?>">
                        </div>
                        <?php
                        msp2RenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-md-6 col-xl-4',
                            'label' => 'Arrendatario',
                            'input_name' => 'filtroArrendatario',
                            'input_id' => 'filtroArrendatario',
                            'value' => $filtroArrendatario,
                            'button_placeholder' => 'Todos los arrendatarios',
                            'filter_placeholder' => 'Buscar arrendatario...',
                            'empty_message' => 'Sin arrendatarios disponibles.',
                            'options' => $arrendatarioOptions,
                        ]);
                        msp2RenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-xl-5',
                            'label' => 'Locales',
                            'input_name' => 'filtroLocales',
                            'input_id' => 'filtroLocales',
                            'value' => $filtroLocales,
                            'button_placeholder' => 'Todos los grupos de locales',
                            'filter_placeholder' => 'Buscar locales...',
                            'empty_message' => 'Sin grupos de locales disponibles.',
                            'options' => $localesOptions,
                        ]);
                        ?>
                        <div class="col-12 col-md-6 col-xl-3">
                            <label for="filtroTipo" class="form-label">Tipo PDF</label>
                            <select class="form-select" id="filtroTipo" name="filtroTipo">
                                <option value="">Todos</option>
                                <?php foreach ($tiposArchivo as $tipoKey => $tipoLabel): ?>
                                    <option value="<?php echo msp2Escape($tipoKey); ?>" <?php echo $filtroTipo === $tipoKey ? 'selected' : ''; ?>>
                                        <?php echo msp2Escape($tipoLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3 col-xl-2">
                            <label for="filtroEstado" class="form-label">Estado</label>
                            <select class="form-select" id="filtroEstado" name="filtroEstado">
                                <option value="">Todos</option>
                                <?php foreach ($estadosArchivo as $estadoKey => $estadoData): ?>
                                    <option value="<?php echo msp2Escape($estadoKey); ?>" <?php echo $filtroEstado === $estadoKey ? 'selected' : ''; ?>>
                                        <?php echo msp2Escape((string) $estadoData['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3 col-xl-2">
                            <label for="lineas" class="form-label">Líneas</label>
                            <select class="form-select" id="lineas" name="lineas">
                                <?php foreach ($lineasPermitidas as $lineas): ?>
                                    <option value="<?php echo $lineas; ?>" <?php echo $lineasPorPagina === $lineas ? 'selected' : ''; ?>>
                                        <?php echo $lineas; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-12 col-xl-5 d-flex gap-2 msp-pdf-backups-filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search me-1" aria-hidden="true"></i>Filtrar
                            </button>
                            <a href="<?php echo msp2Escape(msp2Url('pagos/archivos_pdf.php')); ?>" class="btn btn-outline-secondary">Limpiar</a>
                        </div>
            </form>

            <div class="msp-management-table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 msp-management-table msp-pdf-backups-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha ref.</th>
                                    <th>Periodo</th>
                                    <th>Arrendatario</th>
                                    <th>Locales</th>
                                    <th>Documento</th>
                                    <th>Referencia</th>
                                    <th>Tipo</th>
                                    <th>Tamaño</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows === []): ?>
                                    <tr>
                                        <td colspan="10" class="text-center text-muted py-4">No hay respaldos PDF para los filtros seleccionados.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $row): ?>
                                        <?php $estadoKey = strtoupper(trim((string) ($row['estado_archivo'] ?? 'ACTIVO'))); ?>
                                        <?php $tipoArchivoRow = strtoupper(trim((string) ($row['tipo_archivo'] ?? ''))); ?>
                                        <?php
                                        $documentoOrigenExiste = (int) ($row['documento_origen_existe'] ?? 0) === 1;
                                        $pagoOrigenExiste = (int) ($row['pago_origen_existe'] ?? 0) === 1;
                                        $requierePagoOrigen = $tipoArchivoRow !== 'VALE_COBRO';
                                        $esHuerfano = !$documentoOrigenExiste || ($requierePagoOrigen && !$pagoOrigenExiste);
                                        if ($esHuerfano) {
                                            $estadoKey = 'HUERFANO';
                                        }
                                        ?>
                                        <?php $rutaRelativa = trim((string) ($row['ruta_relativa'] ?? '')); ?>
                                        <?php if (!$esHuerfano && $rutaRelativa !== '' && !is_file(msp2ArchivosPdfAbsolutePath($rutaRelativa))): ?>
                                            <?php $estadoKey = 'FALTANTE'; ?>
                                        <?php endif; ?>
                                        <?php $estadoInfo = $estadosArchivo[$estadoKey] ?? ['label' => $estadoKey, 'badge' => 'text-bg-secondary']; ?>
                                        <?php
                                        $referencia = ((int) ($row['id_pago'] ?? 0)) > 0
                                            ? 'Pago #' . (int) ($row['id_pago'] ?? 0)
                                            : 'Doc #' . (int) ($row['id_documento_cobro'] ?? 0);
                                        $documentoTexto = (string) ($row['numero_documento'] ?? '-');
                                        $documentoQuery = [
                                            'filtroPeriodo' => (string) ($row['periodo_ym'] ?? ''),
                                        ];
                                        $idArrendatarioDoc = (int) ($row['id_arrendatario'] ?? 0);
                                        if ($idArrendatarioDoc > 0) {
                                            $documentoQuery['id_arrendatario'] = $idArrendatarioDoc;
                                        }
                                        $urlDocumento = msp2Url('documentos_cobro/index.php');
                                        $queryDocumento = http_build_query($documentoQuery);
                                        if ($queryDocumento !== '') {
                                            $urlDocumento .= '?' . $queryDocumento;
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo msp2Escape(substr((string) ($row['fecha_pago'] ?? ''), 0, 10)); ?></td>
                                            <td><?php echo msp2Escape((string) ($row['periodo_ym'] ?? '-')); ?></td>
                                            <td><?php echo msp2Escape((string) ($row['arrendatario_nombre'] ?? '-')); ?></td>
                                            <td><?php echo msp2Escape((string) ($row['locales'] ?? '-')); ?></td>
                                            <td>
                                                <a href="<?php echo msp2Escape($urlDocumento); ?>" class="link-primary text-decoration-none fw-semibold">
                                                    <?php echo msp2Escape($documentoTexto); ?>
                                                </a>
                                            </td>
                                            <td><?php echo msp2Escape($referencia); ?></td>
                                            <td><?php echo msp2Escape($tiposArchivo[(string) ($row['tipo_archivo'] ?? '')] ?? msp2ArchivosPdfTypeUiLabel((string) ($row['tipo_archivo'] ?? ''))); ?></td>
                                            <td><?php echo msp2Escape(msp2FormatBytes((int) ($row['bytes_archivo'] ?? 0))); ?></td>
                                            <td><span class="badge <?php echo msp2Escape((string) $estadoInfo['badge']); ?>"><?php echo msp2Escape((string) $estadoInfo['label']); ?></span></td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-1">
                                                    <a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2ArchivosPdfDownloadUrl((int) ($row['id_pago_contrato_archivo'] ?? 0), 'inline')); ?>" target="_blank" rel="noopener">
                                                        <i class="bi bi-eye" aria-hidden="true"></i>
                                                    </a>
                                                    <a class="btn btn-outline-success btn-sm" href="<?php echo msp2Escape(msp2ArchivosPdfDownloadUrl((int) ($row['id_pago_contrato_archivo'] ?? 0), 'attachment')); ?>">
                                                        <i class="bi bi-download" aria-hidden="true"></i>
                                                    </a>
                                                    <form method="post" action="<?php echo msp2Escape(msp2Url('pagos/regenerar_archivo_pdf.php')); ?>" class="d-inline">
                                                        <input type="hidden" name="id_pago_contrato_archivo" value="<?php echo (int) ($row['id_pago_contrato_archivo'] ?? 0); ?>">
                                                        <?php msp2CsrfField(); ?>
                                                        <button type="submit" class="btn btn-outline-warning btn-sm" <?php echo $esHuerfano ? 'disabled title="No se puede regenerar: origen huérfano"' : ''; ?>>
                                                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
                                                        </button>
                                                    </form>
                                                </div>
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
                            <nav aria-label="Paginacion archivos PDF">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo buildArchivosPdfQuery($queryBase, ['pagina' => max(1, $paginaActual - 1)]); ?>">&laquo;</a>
                                    </li>
                                    <?php foreach ($paginationItems as $item): ?>
                                        <?php if ($item === 'ellipsis'): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php else: ?>
                                            <li class="page-item <?php echo (int) $item === $paginaActual ? 'active' : ''; ?>">
                                                <a class="page-link" href="?<?php echo buildArchivosPdfQuery($queryBase, ['pagina' => $item]); ?>"><?php echo $item; ?></a>
                                            </li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo buildArchivosPdfQuery($queryBase, ['pagina' => min($totalPaginas, $paginaActual + 1)]); ?>">&raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
        <?php endif; ?>
    </div>
</main>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
    new bootstrap.Tooltip(element);
});
</script>
</body>
</html>
