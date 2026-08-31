<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';

msp2RequireAccess();
if (!msp2DescuentosArriendoEnabled()) {
    msp2SetFlash('info', 'El módulo de descuentos de arriendo está oculto.');
    msp2Redirect('contratos/index.php');
}

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$loadError = null;
$rows = [];
$catalogoRows = [];
$contratosAsignables = [];
$descuentosActivos = [];
$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];

$filtroTexto = msp2NormalizeText((string) ($_GET['filtroTexto'] ?? ''));
$filtroEstado = strtolower(trim((string) ($_GET['filtroEstado'] ?? 'activos')));
if (!in_array($filtroEstado, ['activos', 'desasignados', 'catalogo_inactivo', 'todos'], true)) {
    $filtroEstado = 'activos';
}
$lineasPermitidas = [10, 25, 50, 100];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;
if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}
$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;

$queryBase = $_GET;
unset($queryBase['pagina']);
$redirectToSelf = 'contratos/descuentos_arriendo.php';
$redirectToQuery = http_build_query($_GET);
if ($redirectToQuery !== '') {
    $redirectToSelf .= '?' . $redirectToQuery;
}

function msp2DescuentoBuildQuery(array $base, array $override = []): string
{
    return http_build_query(array_merge($base, $override));
}

function msp2DescuentoFmtInput(mixed $value, int $decimals): string
{
    if ($value === null || $value === '' || !is_numeric((string) $value)) {
        return '';
    }

    return number_format((float) $value, $decimals, '.', '');
}

function msp2DescuentoFmtMonth(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable(substr($raw, 0, 10)))->format('Y-m');
    } catch (Throwable) {
        return substr($raw, 0, 7);
    }
}

function msp2DescuentoFmtDateTime(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '-';
    }

    try {
        return (new DateTimeImmutable($raw))->format('d-m-Y H:i');
    } catch (Throwable) {
        return $raw;
    }
}

function msp2DescuentoFmtValor(string $tipo, mixed $valor): string
{
    $amount = is_numeric((string) $valor) ? (float) $valor : 0.0;
    if ($tipo === 'UF_FIJO') {
        return 'UF ' . number_format($amount, 2, ',', '.');
    }

    return '$ ' . number_format($amount, 0, ',', '.');
}

try {
    $requiredTables = [
        'msp_contratos_arriendo',
        'msp_contrato_locales',
        'msp_tiendas',
        'msp_arrendatarios',
        'msp_locales',
        'msp_descuento_arriendo',
        'msp_descuento_arriendo_contrato_local',
    ];
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }
    if ($missingTables !== []) {
        throw new RuntimeException('Faltan tablas para administrar descuentos: `' . implode('`, `', $missingTables) . '`.');
    }

    $conditions = [];
    $params = [];

    if ($filtroTexto !== '') {
        $like = '%' . $filtroTexto . '%';
        $conditions[] = "(
            CAST(g.id_contrato_arriendo AS NVARCHAR(20)) LIKE :filtro_texto_contrato
            OR ISNULL(g.nombre_locatario, N'') LIKE :filtro_texto_arrendatario
            OR ISNULL(g.nombre_comercial, N'') LIKE :filtro_texto_tienda
            OR ISNULL(g.codigo_descuento, N'') LIKE :filtro_texto_codigo
            OR ISNULL(g.nombre_descuento, N'') LIKE :filtro_texto_descuento
            OR EXISTS (
                SELECT 1
                FROM dbo.msp_descuento_arriendo_contrato_local dclx
                INNER JOIN dbo.msp_contrato_locales clx
                    ON clx.id_contrato_local = dclx.id_contrato_local
                INNER JOIN dbo.msp_locales lx
                    ON lx.id_local = clx.id_local
                WHERE clx.id_contrato_arriendo = g.id_contrato_arriendo
                  AND dclx.id_descuento_arriendo = g.id_descuento_arriendo
                  AND dclx.estado_asignacion = g.estado_asignacion
                  AND (
                    ISNULL(lx.cdo_local, N'') LIKE :filtro_texto_local
                    OR ISNULL(lx.desc_local, N'') LIKE :filtro_texto_local_desc
                  )
            )
        )";
        $params[':filtro_texto_contrato'] = $like;
        $params[':filtro_texto_arrendatario'] = $like;
        $params[':filtro_texto_tienda'] = $like;
        $params[':filtro_texto_codigo'] = $like;
        $params[':filtro_texto_descuento'] = $like;
        $params[':filtro_texto_local'] = $like;
        $params[':filtro_texto_local_desc'] = $like;
    }

    if ($filtroEstado === 'activos') {
        $conditions[] = 'g.estado_asignacion = 1 AND g.estado_descuento = 1';
    } elseif ($filtroEstado === 'desasignados') {
        $conditions[] = 'g.estado_asignacion = 2';
    } elseif ($filtroEstado === 'catalogo_inactivo') {
        $conditions[] = 'g.estado_descuento = 2';
    }

    $whereSql = $conditions === [] ? '1=1' : implode(' AND ', $conditions);
    $groupedSql = "
        WITH grouped AS (
            SELECT
                ca.id_contrato_arriendo,
                t.nombre_comercial,
                a.nombre_locatario,
                d.id_descuento_arriendo,
                d.codigo_descuento,
                d.nombre_descuento,
                d.tipo_monto,
                d.valor_descuento,
                CONVERT(CHAR(10), d.periodo_desde, 126) AS periodo_desde,
                CONVERT(CHAR(10), d.periodo_hasta, 126) AS periodo_hasta,
                d.estado_descuento,
                dcl.estado_asignacion,
                COUNT(*) AS locales_count,
                CONVERT(CHAR(19), MIN(dcl.fecha_asignacion), 120) AS fecha_asignacion_min,
                CONVERT(CHAR(19), MAX(dcl.fecha_asignacion), 120) AS fecha_asignacion_max,
                CONVERT(CHAR(19), MAX(dcl.fecha_desasignacion), 120) AS fecha_desasignacion_max
            FROM dbo.msp_descuento_arriendo_contrato_local dcl
            INNER JOIN dbo.msp_descuento_arriendo d
                ON d.id_descuento_arriendo = dcl.id_descuento_arriendo
            INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_contrato_local = dcl.id_contrato_local
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = ca.id_tienda
            LEFT JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = ca.id_arrendatario
            GROUP BY
                ca.id_contrato_arriendo,
                t.nombre_comercial,
                a.nombre_locatario,
                d.id_descuento_arriendo,
                d.codigo_descuento,
                d.nombre_descuento,
                d.tipo_monto,
                d.valor_descuento,
                d.periodo_desde,
                d.periodo_hasta,
                d.estado_descuento,
                dcl.estado_asignacion
        )
    ";

    $countStmt = $conn->prepare($groupedSql . ' SELECT COUNT(*) FROM grouped g WHERE ' . $whereSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalRegistros = (int) $countStmt->fetchColumn();
    $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
    $paginaActual = min($paginaActual, $totalPaginas);
    $offset = ($paginaActual - 1) * $lineasPorPagina;

    $rowsStmt = $conn->prepare(
        $groupedSql . "
        SELECT
            g.*,
            locs.locales_afectados
        FROM grouped g
        OUTER APPLY (
            SELECT STUFF((
                SELECT N' / ' + x.cdo_local
                FROM (
                    SELECT DISTINCT l2.cdo_local
                    FROM dbo.msp_descuento_arriendo_contrato_local dcl2
                    INNER JOIN dbo.msp_contrato_locales cl2
                        ON cl2.id_contrato_local = dcl2.id_contrato_local
                    INNER JOIN dbo.msp_locales l2
                        ON l2.id_local = cl2.id_local
                    WHERE cl2.id_contrato_arriendo = g.id_contrato_arriendo
                      AND dcl2.id_descuento_arriendo = g.id_descuento_arriendo
                      AND dcl2.estado_asignacion = g.estado_asignacion
                ) x
                ORDER BY " . msp2LocalCodeNaturalOrderSql('x.cdo_local') . "
                FOR XML PATH(''), TYPE
            ).value('.', 'NVARCHAR(MAX)'), 1, 3, N'') AS locales_afectados
        ) locs
        WHERE " . $whereSql . "
        ORDER BY
            CASE WHEN g.estado_asignacion = 1 AND g.estado_descuento = 1 THEN 0 ELSE 1 END,
            g.fecha_asignacion_max DESC,
            g.id_contrato_arriendo DESC,
            g.nombre_descuento ASC
        OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY"
    );
    foreach ($params as $key => $value) {
        $rowsStmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $rowsStmt->bindValue(':lineas', $lineasPorPagina, PDO::PARAM_INT);
    $rowsStmt->execute();
    $rows = $rowsStmt->fetchAll();

    $catalogoStmt = $conn->query(
        "SELECT
            d.id_descuento_arriendo,
            d.codigo_descuento,
            d.nombre_descuento,
            d.tipo_monto,
            d.valor_descuento,
            CONVERT(CHAR(10), d.periodo_desde, 126) AS periodo_desde,
            CONVERT(CHAR(10), d.periodo_hasta, 126) AS periodo_hasta,
            d.estado_descuento,
            d.observaciones,
            ISNULL(asg.uso_activo, 0) AS usos_activos
         FROM dbo.msp_descuento_arriendo d
         OUTER APPLY (
            SELECT COUNT(*) AS uso_activo
            FROM dbo.msp_descuento_arriendo_contrato_local x
            WHERE x.id_descuento_arriendo = d.id_descuento_arriendo
              AND x.estado_asignacion = 1
         ) asg
         ORDER BY d.estado_descuento ASC, d.nombre_descuento ASC, d.id_descuento_arriendo DESC"
    );
    while (($row = $catalogoStmt->fetch()) !== false) {
        $catalogoRows[] = [
            'id_descuento_arriendo' => (int) ($row['id_descuento_arriendo'] ?? 0),
            'codigo_descuento' => (string) ($row['codigo_descuento'] ?? ''),
            'nombre_descuento' => (string) ($row['nombre_descuento'] ?? ''),
            'tipo_monto' => (string) ($row['tipo_monto'] ?? 'CLP_FIJO'),
            'valor_descuento' => msp2DescuentoFmtInput($row['valor_descuento'] ?? null, 6),
            'periodo_desde' => msp2DescuentoFmtMonth($row['periodo_desde'] ?? ''),
            'periodo_hasta' => msp2DescuentoFmtMonth($row['periodo_hasta'] ?? ''),
            'estado_descuento' => (int) ($row['estado_descuento'] ?? 1),
            'observaciones' => (string) ($row['observaciones'] ?? ''),
            'usos_activos' => (int) ($row['usos_activos'] ?? 0),
        ];
    }

    $descuentosStmt = $conn->query(
        "SELECT
            id_descuento_arriendo,
            codigo_descuento,
            nombre_descuento,
            tipo_monto,
            valor_descuento,
            CONVERT(CHAR(10), periodo_desde, 126) AS periodo_desde,
            CONVERT(CHAR(10), periodo_hasta, 126) AS periodo_hasta
         FROM dbo.msp_descuento_arriendo
         WHERE estado_descuento = 1
         ORDER BY nombre_descuento ASC, id_descuento_arriendo ASC"
    );
    $descuentosActivos = $descuentosStmt->fetchAll();

    $contratosStmt = $conn->query(
        "SELECT
            ca.id_contrato_arriendo,
            t.nombre_comercial,
            a.nombre_locatario,
            a.rut,
            locs.locales_count,
            locs.locales_afectados
         FROM dbo.msp_contratos_arriendo ca
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = ca.id_tienda
         LEFT JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = ca.id_arrendatario
         CROSS APPLY (
            SELECT
                COUNT(*) AS locales_count,
                STUFF((
                    SELECT N' / ' + x.cdo_local
                    FROM (
                        SELECT DISTINCT l2.cdo_local
                        FROM dbo.msp_contrato_locales cl2
                        INNER JOIN dbo.msp_locales l2
                            ON l2.id_local = cl2.id_local
                        WHERE cl2.id_contrato_arriendo = ca.id_contrato_arriendo
                          AND cl2.estado_relacion IN (1,2)
                    ) x
                    ORDER BY " . msp2LocalCodeNaturalOrderSql('x.cdo_local') . "
                    FOR XML PATH(''), TYPE
                ).value('.', 'NVARCHAR(MAX)'), 1, 3, N'') AS locales_afectados
            FROM dbo.msp_contrato_locales clc
            WHERE clc.id_contrato_arriendo = ca.id_contrato_arriendo
              AND clc.estado_relacion IN (1,2)
         ) locs
         WHERE locs.locales_count > 0
         ORDER BY ca.id_contrato_arriendo DESC"
    );
    $contratosAsignables = $contratosStmt->fetchAll();
} catch (Throwable $exception) {
    $loadError = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'No fue posible cargar los descuentos de arriendo.';
}

if ($loadError === null && $totalPaginas > 1) {
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Descuentos de arriendo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <?php msp2RenderSearchableSelectAssets(); ?>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <a href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a contratos
            </a>
            <?php if ($loadError === null): ?>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNuevoDescuento">
                        <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nuevo descuento
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAsociarDescuento">
                        <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Asociar descuento
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <p class="section-kicker text-center">MSP / Gestión de Contratos</p>
        <h1 class="form-title text-center mb-2">Descuentos de arriendo</h1>
        <p class="text-muted text-center mb-4">Administración operativa de descuentos asociados a contratos completos.</p>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div>
        <?php else: ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label for="filtroTexto" class="form-label">Contrato, arrendatario, tienda, local o descuento</label>
                            <input type="text" class="form-control" id="filtroTexto" name="filtroTexto" value="<?php echo msp2Escape($filtroTexto); ?>" placeholder="Buscar...">
                        </div>
                        <div class="col-12 col-md-3">
                            <label for="filtroEstado" class="form-label">Estado</label>
                            <select class="form-select" id="filtroEstado" name="filtroEstado">
                                <option value="activos" <?php echo $filtroEstado === 'activos' ? 'selected' : ''; ?>>Activos</option>
                                <option value="desasignados" <?php echo $filtroEstado === 'desasignados' ? 'selected' : ''; ?>>Desasignados</option>
                                <option value="catalogo_inactivo" <?php echo $filtroEstado === 'catalogo_inactivo' ? 'selected' : ''; ?>>Catálogo inactivo</option>
                                <option value="todos" <?php echo $filtroEstado === 'todos' ? 'selected' : ''; ?>>Todos</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-2">
                            <label for="lineas" class="form-label">Líneas</label>
                            <select class="form-select" id="lineas" name="lineas">
                                <?php foreach ($lineasPermitidas as $lineas): ?>
                                    <option value="<?php echo $lineas; ?>" <?php echo $lineasPorPagina === $lineas ? 'selected' : ''; ?>>
                                        <?php echo $lineas; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 d-grid">
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <strong>Asignaciones por contrato</strong>
                    <span class="small text-muted"><?php echo number_format($totalRegistros, 0, ',', '.'); ?> registro(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Contrato</th>
                                <th>Arrendatario / tienda</th>
                                <th>Locales afectados</th>
                                <th>Descuento</th>
                                <th>Tipo y valor</th>
                                <th>Vigencia</th>
                                <th>Estado</th>
                                <th>Asignación / desasignación</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No hay asignaciones de descuentos para los filtros seleccionados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $idContrato = (int) ($row['id_contrato_arriendo'] ?? 0);
                                $idDescuento = (int) ($row['id_descuento_arriendo'] ?? 0);
                                $estadoAsignacion = (int) ($row['estado_asignacion'] ?? 0);
                                $estadoCatalogo = (int) ($row['estado_descuento'] ?? 0);
                                $isActivo = $estadoAsignacion === 1 && $estadoCatalogo === 1;
                                $tipo = strtoupper((string) ($row['tipo_monto'] ?? 'CLP_FIJO'));
                                $periodoDesde = msp2DescuentoFmtMonth($row['periodo_desde'] ?? '');
                                $periodoHasta = msp2DescuentoFmtMonth($row['periodo_hasta'] ?? '');
                                ?>
                                <tr>
                                    <td>#<?php echo $idContrato; ?></td>
                                    <td>
                                        <div><?php echo msp2Escape((string) ($row['nombre_locatario'] ?? '')); ?></div>
                                        <div class="small text-muted"><?php echo msp2Escape((string) ($row['nombre_comercial'] ?? '')); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo msp2Escape((string) ($row['locales_afectados'] ?? '-')); ?></div>
                                        <div class="small text-muted"><?php echo (int) ($row['locales_count'] ?? 0); ?> local(es)</div>
                                    </td>
                                    <td>
                                        <div><?php echo msp2Escape((string) ($row['nombre_descuento'] ?? '')); ?></div>
                                        <div class="small text-muted"><?php echo msp2Escape((string) ($row['codigo_descuento'] ?? '')); ?></div>
                                    </td>
                                    <td><?php echo msp2Escape($tipo . ' · ' . msp2DescuentoFmtValor($tipo, $row['valor_descuento'] ?? null)); ?></td>
                                    <td><?php echo msp2Escape($periodoDesde . ' a ' . ($periodoHasta !== '' ? $periodoHasta : 'abierto')); ?></td>
                                    <td>
                                        <?php if ($isActivo): ?>
                                            <span class="badge text-bg-success">Activo</span>
                                        <?php elseif ($estadoAsignacion === 2): ?>
                                            <span class="badge text-bg-secondary">Desasignado</span>
                                        <?php elseif ($estadoCatalogo === 2): ?>
                                            <span class="badge text-bg-warning text-dark">Catálogo inactivo</span>
                                        <?php else: ?>
                                            <span class="badge text-bg-secondary">Sin estado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo msp2Escape(msp2DescuentoFmtDateTime($row['fecha_asignacion_min'] ?? null)); ?></div>
                                        <div class="small text-muted"><?php echo msp2Escape(msp2DescuentoFmtDateTime($row['fecha_desasignacion_max'] ?? null)); ?></div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <a href="<?php echo msp2Escape(msp2Url('contratos/arriendo_reglas.php?id_contrato_arriendo=' . $idContrato)); ?>" class="btn btn-outline-secondary btn-sm">
                                                <i class="bi bi-sliders me-1" aria-hidden="true"></i>Cobro local
                                            </a>
                                            <?php if ($estadoAsignacion === 1): ?>
                                                <form method="post" action="<?php echo msp2Escape(msp2Url('contratos/guardar_asignacion_descuento_arriendo.php')); ?>" onsubmit="return confirm('¿Desasignar este descuento del contrato completo?');">
                                                    <?php msp2CsrfField(); ?>
                                                    <input type="hidden" name="accion" value="desasignar">
                                                    <input type="hidden" name="id_contrato_arriendo" value="<?php echo $idContrato; ?>">
                                                    <input type="hidden" name="id_descuento_arriendo" value="<?php echo $idDescuento; ?>">
                                                    <input type="hidden" name="redirect_to" value="<?php echo msp2Escape($redirectToSelf); ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                                        <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Desasignar
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 mb-4 gap-2">
                <div class="small text-muted">
                    Total: <strong><?php echo number_format($totalRegistros, 0, ',', '.'); ?></strong>
                    | Página <strong><?php echo $paginaActual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                </div>
                <?php if ($totalPaginas > 1): ?>
                    <nav aria-label="Paginación de descuentos">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo msp2DescuentoBuildQuery($queryBase, ['pagina' => max(1, $paginaActual - 1)]); ?>" aria-label="Anterior">&laquo;</a>
                            </li>
                            <?php foreach ($paginationItems as $item): ?>
                                <?php if ($item === 'ellipsis'): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php else: ?>
                                    <li class="page-item <?php echo (int) $item === $paginaActual ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo msp2DescuentoBuildQuery($queryBase, ['pagina' => $item]); ?>"><?php echo $item; ?></a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo msp2DescuentoBuildQuery($queryBase, ['pagina' => min($totalPaginas, $paginaActual + 1)]); ?>" aria-label="Siguiente">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    <strong>Catálogo de descuentos</strong>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th class="text-end">Valor</th>
                            <th>Desde</th>
                            <th>Hasta</th>
                            <th class="text-center">Uso activo</th>
                            <th>Estado</th>
                            <th style="min-width: 260px;">Editar</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($catalogoRows === []): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No hay descuentos registrados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($catalogoRows as $row): ?>
                                <?php
                                $id = (int) ($row['id_descuento_arriendo'] ?? 0);
                                $isActivoCatalogo = (int) ($row['estado_descuento'] ?? 1) === 1;
                                $tipo = (string) ($row['tipo_monto'] ?? 'CLP_FIJO');
                                $valorUi = $tipo === 'UF_FIJO'
                                    ? number_format((float) ($row['valor_descuento'] ?? 0), 6, '.', '')
                                    : number_format((float) ($row['valor_descuento'] ?? 0), 2, '.', '');
                                ?>
                                <tr>
                                    <td><?php echo msp2Escape((string) ($row['codigo_descuento'] ?? '')); ?></td>
                                    <td><?php echo msp2Escape((string) ($row['nombre_descuento'] ?? '')); ?></td>
                                    <td><?php echo msp2Escape($tipo); ?></td>
                                    <td class="text-end"><?php echo msp2Escape(msp2DescuentoFmtValor($tipo, $valorUi)); ?></td>
                                    <td><?php echo msp2Escape((string) ($row['periodo_desde'] ?? '')); ?></td>
                                    <td><?php echo msp2Escape((string) ($row['periodo_hasta'] !== '' ? $row['periodo_hasta'] : '-')); ?></td>
                                    <td class="text-center"><?php echo (int) ($row['usos_activos'] ?? 0); ?></td>
                                    <td>
                                        <span class="badge <?php echo $isActivoCatalogo ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                            <?php echo $isActivoCatalogo ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="post" action="<?php echo msp2Escape(msp2Url('contratos/guardar_descuento_arriendo.php')); ?>" class="row g-1">
                                            <?php msp2CsrfField(); ?>
                                            <input type="hidden" name="accion" value="actualizar">
                                            <input type="hidden" name="id_descuento_arriendo" value="<?php echo $id; ?>">
                                            <input type="hidden" name="redirect_to" value="<?php echo msp2Escape($redirectToSelf); ?>">
                                            <div class="col-12">
                                                <input type="text" class="form-control form-control-sm" name="nombre_descuento" value="<?php echo msp2Escape((string) ($row['nombre_descuento'] ?? '')); ?>" maxlength="150" required>
                                            </div>
                                            <div class="col-4">
                                                <select class="form-select form-select-sm" name="tipo_monto" required>
                                                    <option value="CLP_FIJO" <?php echo $tipo === 'CLP_FIJO' ? 'selected' : ''; ?>>CLP</option>
                                                    <option value="UF_FIJO" <?php echo $tipo === 'UF_FIJO' ? 'selected' : ''; ?>>UF</option>
                                                </select>
                                            </div>
                                            <div class="col-4">
                                                <input type="text" class="form-control form-control-sm text-end" name="valor_descuento" value="<?php echo msp2Escape($valorUi); ?>" required>
                                            </div>
                                            <div class="col-4">
                                                <select class="form-select form-select-sm" name="estado_descuento">
                                                    <option value="1" <?php echo $isActivoCatalogo ? 'selected' : ''; ?>>Activo</option>
                                                    <option value="2" <?php echo !$isActivoCatalogo ? 'selected' : ''; ?>>Inactivo</option>
                                                </select>
                                            </div>
                                            <div class="col-6">
                                                <input type="month" class="form-control form-control-sm" name="periodo_desde" value="<?php echo msp2Escape((string) ($row['periodo_desde'] ?? '')); ?>" required>
                                            </div>
                                            <div class="col-6">
                                                <input type="month" class="form-control form-control-sm" name="periodo_hasta" value="<?php echo msp2Escape((string) ($row['periodo_hasta'] ?? '')); ?>">
                                            </div>
                                            <div class="col-12">
                                                <input type="text" class="form-control form-control-sm" name="observaciones" value="<?php echo msp2Escape((string) ($row['observaciones'] ?? '')); ?>" maxlength="500" placeholder="Observaciones">
                                            </div>
                                            <div class="col-12 d-grid">
                                                <button type="submit" class="btn btn-outline-primary btn-sm">Guardar</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>

<?php if ($loadError === null): ?>
    <div class="modal fade" id="modalNuevoDescuento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/guardar_descuento_arriendo.php')); ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Nuevo descuento</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php msp2CsrfField(); ?>
                    <input type="hidden" name="accion" value="crear">
                    <input type="hidden" name="redirect_to" value="<?php echo msp2Escape($redirectToSelf); ?>">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Código</label>
                            <input type="text" class="form-control" name="codigo_descuento" maxlength="40" placeholder="PROMO_INV_2026">
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label">Nombre</label>
                            <input type="text" class="form-control" name="nombre_descuento" maxlength="150" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Tipo</label>
                            <select class="form-select" name="tipo_monto" required>
                                <option value="CLP_FIJO">CLP fijo</option>
                                <option value="UF_FIJO">UF fijo</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Valor</label>
                            <input type="text" class="form-control text-end" name="valor_descuento" placeholder="0" inputmode="decimal" required>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label">Desde</label>
                            <input type="month" class="form-control" name="periodo_desde" required>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label">Hasta</label>
                            <input type="month" class="form-control" name="periodo_hasta">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observaciones</label>
                            <input type="text" class="form-control" name="observaciones" maxlength="500" placeholder="Opcional">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear descuento</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalAsociarDescuento" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/guardar_asignacion_descuento_arriendo.php')); ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Asociar descuento</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php msp2CsrfField(); ?>
                    <input type="hidden" name="accion" value="asociar">
                    <input type="hidden" name="redirect_to" value="<?php echo msp2Escape($redirectToSelf); ?>">
                    <div class="row g-3">
                        <?php
                        $contratoOptions = [];
                        foreach ($contratosAsignables as $contrato) {
                            $idContrato = (int) ($contrato['id_contrato_arriendo'] ?? 0);
                            if ($idContrato <= 0) {
                                continue;
                            }
                            $arrendatario = trim((string) ($contrato['nombre_locatario'] ?? ''));
                            $rutRaw = trim((string) ($contrato['rut'] ?? ''));
                            $rut = msp2RutFormatDisplay($rutRaw);
                            $tienda = trim((string) ($contrato['nombre_comercial'] ?? ''));
                            $locales = trim((string) ($contrato['locales_afectados'] ?? ''));
                            $localesCount = (int) ($contrato['locales_count'] ?? 0);
                            $arrendatarioLabel = ($rut !== '' ? '(' . $rut . ') ' : '') . ($arrendatario !== '' ? $arrendatario : 'Sin arrendatario');
                            $localesGrupo = $locales !== '' ? '(' . str_replace(' / ', ', ', $locales) . ')' : '(sin locales)';
                            $label = $arrendatarioLabel . ' ' . $localesGrupo;
                            $labelHtml = '<strong>' . msp2Escape($arrendatarioLabel) . '</strong> '
                                . '<span class="text-muted">' . msp2Escape($localesGrupo) . '</span>';
                            $contratoOptions[] = [
                                'value' => (string) $idContrato,
                                'label' => $label,
                                'label_html' => $labelHtml,
                                'search' => mb_strtolower($idContrato . ' ' . $rutRaw . ' ' . $rut . ' ' . $arrendatario . ' ' . $tienda . ' ' . $locales, 'UTF-8'),
                                'attrs' => [
                                    'locales' => $locales,
                                    'count' => (string) $localesCount,
                                ],
                            ];
                        }
                        msp2RenderSearchableSelectField([
                            'wrapper_class' => 'col-12',
                            'label' => 'Contrato',
                            'input_name' => 'id_contrato_arriendo',
                            'input_id' => 'asociar_id_contrato_arriendo',
                            'picker_id' => 'asociar_contrato_picker',
                            'button_id' => 'asociar_contrato_dropdown_btn',
                            'filter_id' => 'asociar_contrato_dropdown_filter',
                            'list_id' => 'asociar_contrato_dropdown_list',
                            'error_id' => 'asociar_contrato_error',
                            'error_message' => 'Debes seleccionar un contrato.',
                            'button_placeholder' => 'Selecciona contrato...',
                            'filter_placeholder' => 'Buscar por contrato, arrendatario, tienda o local',
                            'empty_message' => 'No hay contratos con locales activos.',
                            'required' => true,
                            'options' => $contratoOptions,
                        ]);

                        $descuentoOptions = [];
                        foreach ($descuentosActivos as $descuento) {
                            $idDescuento = (int) ($descuento['id_descuento_arriendo'] ?? 0);
                            if ($idDescuento <= 0) {
                                continue;
                            }
                            $tipo = strtoupper((string) ($descuento['tipo_monto'] ?? 'CLP_FIJO'));
                            $desde = msp2DescuentoFmtMonth($descuento['periodo_desde'] ?? '');
                            $hasta = msp2DescuentoFmtMonth($descuento['periodo_hasta'] ?? '');
                            $codigo = trim((string) ($descuento['codigo_descuento'] ?? ''));
                            $nombre = trim((string) ($descuento['nombre_descuento'] ?? ''));
                            $valor = msp2DescuentoFmtValor($tipo, $descuento['valor_descuento'] ?? null);
                            $vigencia = $desde . ' a ' . ($hasta !== '' ? $hasta : 'abierto');
                            $label = $nombre . ' · ' . $valor . ' · ' . $vigencia;
                            $labelHtml = '<strong>' . msp2Escape($nombre !== '' ? $nombre : ('Descuento #' . $idDescuento)) . '</strong>'
                                . '<div class="small text-muted">' . msp2Escape(($codigo !== '' ? ($codigo . ' · ') : '') . $tipo . ' · ' . $valor . ' · ' . $vigencia) . '</div>';
                            $descuentoOptions[] = [
                                'value' => (string) $idDescuento,
                                'label' => $label,
                                'label_html' => $labelHtml,
                                'search' => mb_strtolower($idDescuento . ' ' . $codigo . ' ' . $nombre . ' ' . $tipo . ' ' . $valor . ' ' . $vigencia, 'UTF-8'),
                            ];
                        }
                        msp2RenderSearchableSelectField([
                            'wrapper_class' => 'col-12',
                            'label' => 'Descuento activo',
                            'input_name' => 'id_descuento_arriendo',
                            'input_id' => 'asociar_id_descuento_arriendo',
                            'picker_id' => 'asociar_descuento_picker',
                            'button_id' => 'asociar_descuento_dropdown_btn',
                            'filter_id' => 'asociar_descuento_dropdown_filter',
                            'list_id' => 'asociar_descuento_dropdown_list',
                            'error_id' => 'asociar_descuento_error',
                            'error_message' => 'Debes seleccionar un descuento.',
                            'button_placeholder' => 'Selecciona descuento...',
                            'filter_placeholder' => 'Buscar por código, nombre, tipo o vigencia',
                            'empty_message' => 'No hay descuentos activos.',
                            'required' => true,
                            'options' => $descuentoOptions,
                        ]);
                        ?>
                        <div class="col-12">
                            <label class="form-label">Locales activos del contrato</label>
                            <div class="border rounded p-3 bg-light small js-locales-confirmacion">Selecciona un contrato para revisar los locales que recibirán el descuento.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Asociar a contrato completo</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const select = document.getElementById('asociar_id_contrato_arriendo');
    const list = document.getElementById('asociar_contrato_dropdown_list');
    const target = document.querySelector('.js-locales-confirmacion');
    if (!(select instanceof HTMLInputElement) || !(list instanceof HTMLElement) || !target) {
        return;
    }

    const refreshLocales = () => {
        const option = Array.from(list.querySelectorAll('.js-searchable-option'))
            .find((item) => item instanceof HTMLElement && item.dataset.value === select.value);
        const locales = option ? (option.dataset.locales || '') : '';
        const count = option ? (option.dataset.count || '0') : '0';
        target.textContent = locales !== ''
            ? `${locales} (${count} local(es))`
            : 'Selecciona un contrato para revisar los locales que recibirán el descuento.';
    };

    select.addEventListener('change', refreshLocales);
    refreshLocales();
});
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
