<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$tablaExiste = false;
$loadError = null;

$lineasPermitidas = [10, 25, 50, 100, 200];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;

if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}

$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$filtroAnio = trim((string) ($_GET['filtroAnio'] ?? ''));
$filtroServicio = trim((string) ($_GET['filtroServicio'] ?? ''));
$filtroTienda = msp2NormalizeText($_GET['filtroTienda'] ?? null);
$filtroLocal = msp2NormalizeText($_GET['filtroLocal'] ?? null);
$filtroMedidor = msp2NormalizeText($_GET['filtroMedidor'] ?? null);
$filtroEstado = trim((string) ($_GET['filtroEstado'] ?? ''));

$tiposServicio = [];
$estadosDocumento = [
    1 => ['label' => 'Borrador', 'badge' => 'text-bg-secondary'],
    2 => ['label' => 'Emitido', 'badge' => 'text-bg-primary'],
    3 => ['label' => 'Pagado Parcial', 'badge' => 'text-bg-warning'],
    4 => ['label' => 'Pagado', 'badge' => 'text-bg-success'],
    5 => ['label' => 'Anulado', 'badge' => 'text-bg-danger'],
];
$registros = [];
$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];
$totalMonto = 0.0;
$totalSaldoPendiente = 0.0;

$queryBase = $_GET;
unset($queryBase['pagina']);

function buildMsp2TrazabilidadQuery(array $base, array $override = []): string
{
    return http_build_query(array_merge($base, $override));
}

function formatoTrazabilidadPeriodo(?string $value): string
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

function formatoTrazabilidadNumero(mixed $value, int $decimals = 4): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return number_format((float) $value, $decimals, ',', '.');
}

function formatoTrazabilidadMonto(mixed $value): string
{
    if ($value === null || $value === '') {
        return '$ 0,00';
    }

    return '$ ' . number_format((float) $value, 2, ',', '.');
}

try {
    $requiredTables = [
        'msp_cobros_servicios',
        'msp_lecturas_medidores',
        'msp_procesos_cobro_servicio',
        'msp_tipos_servicio',
        'msp_medidores',
        'msp_locales',
        'msp_ocupacion_locales',
        'msp_tiendas',
        'msp_arrendatarios',
        'msp_documentos_cobro',
    ];

    $missingTables = [];

    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];

    if (!$tablaExiste) {
        $loadError = 'Faltan tablas para reporte de trazabilidad: `' . implode('`, `', $missingTables) . '`. Ejecuta `msp_cobro_servicios.sql` y `msp_documento_pago.sql`.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura del reporte de trazabilidad.';
}

if ($tablaExiste) {
    try {
        $tiposStmt = $conn->query(
            'SELECT id_tipo_servicio, nombre_servicio
             FROM dbo.msp_tipos_servicio
             ORDER BY id_tipo_servicio ASC'
        );
        $tiposServicio = $tiposStmt->fetchAll();

        $conditions = [];
        $params = [];

        if ($filtroAnio !== '' && ctype_digit($filtroAnio)) {
            $conditions[] = 'YEAR(lm.periodo_facturacion) = :filtro_anio';
            $params[':filtro_anio'] = (int) $filtroAnio;
        }

        if ($filtroServicio !== '' && ctype_digit($filtroServicio)) {
            $conditions[] = 'pcs.id_tipo_servicio = :filtro_servicio';
            $params[':filtro_servicio'] = (int) $filtroServicio;
        }

        if ($filtroTienda !== '') {
            $conditions[] = "(ISNULL(t.nombre_comercial, '') LIKE :filtro_tienda OR CAST(ISNULL(t.id_tienda, 0) AS NVARCHAR(20)) LIKE :filtro_tienda_id)";
            $params[':filtro_tienda'] = '%' . $filtroTienda . '%';
            $params[':filtro_tienda_id'] = '%' . $filtroTienda . '%';
        }

        if ($filtroLocal !== '') {
            $conditions[] = "(ISNULL(l.cdo_local, '') LIKE :filtro_local_codigo OR ISNULL(l.desc_local, '') LIKE :filtro_local_desc)";
            $params[':filtro_local_codigo'] = '%' . $filtroLocal . '%';
            $params[':filtro_local_desc'] = '%' . $filtroLocal . '%';
        }

        if ($filtroMedidor !== '') {
            $conditions[] = "ISNULL(m.codigo_medidor, '') LIKE :filtro_medidor";
            $params[':filtro_medidor'] = '%' . $filtroMedidor . '%';
        }

        if ($filtroEstado !== '' && ctype_digit($filtroEstado)) {
            $conditions[] = 'dc.estado_documento = :filtro_estado';
            $params[':filtro_estado'] = (int) $filtroEstado;
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

        $baseSql =
            'FROM dbo.msp_cobros_servicios cs
             INNER JOIN dbo.msp_lecturas_medidores lm
                ON lm.id_lectura = cs.id_lectura
             INNER JOIN dbo.msp_procesos_cobro_servicio pcs
                ON pcs.id_proceso_cobro = lm.id_proceso_cobro
             INNER JOIN dbo.msp_tipos_servicio ts
                ON ts.id_tipo_servicio = pcs.id_tipo_servicio
             INNER JOIN dbo.msp_medidores m
                ON m.id_medidor = lm.id_medidor
             INNER JOIN dbo.msp_locales l
                ON l.id_local = m.id_local
             OUTER APPLY (
                SELECT TOP 1 ol.id_tienda
                FROM dbo.msp_ocupacion_locales ol
                WHERE ol.id_local = m.id_local
                  AND ol.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
                  AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                ORDER BY ol.fecha_inicio DESC, ol.id_ocupacion_local DESC
             ) olv
             LEFT JOIN dbo.msp_tiendas t
                ON t.id_tienda = olv.id_tienda
             LEFT JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = t.id_arrendatario
             LEFT JOIN dbo.msp_documentos_cobro dc
                ON dc.id_tienda = t.id_tienda
               AND dc.periodo_facturacion = lm.periodo_facturacion
             WHERE ' . $whereClause;

        $countStmt = $conn->prepare('SELECT COUNT(*) ' . $baseSql);

        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $countStmt->execute();
        $totalRegistros = (int) $countStmt->fetchColumn();
        $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
        $paginaActual = min($paginaActual, $totalPaginas);
        $offset = ($paginaActual - 1) * $lineasPorPagina;

        $totalStmt = $conn->prepare(
            'SELECT
                ISNULL(SUM(cs.monto_total), 0) AS total_monto,
                ISNULL(SUM(ISNULL(dc.saldo_pendiente, 0)), 0) AS total_saldo
             ' . $baseSql
        );

        foreach ($params as $key => $value) {
            $totalStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $totalStmt->execute();
        $totales = $totalStmt->fetch();
        $totalMonto = (float) (($totales['total_monto'] ?? 0) ?? 0);
        $totalSaldoPendiente = (float) (($totales['total_saldo'] ?? 0) ?? 0);

        $dataStmt = $conn->prepare(
            "SELECT
                cs.id_cobro_servicio,
                lm.periodo_facturacion,
                ts.nombre_servicio,
                ts.codigo_servicio,
                t.id_tienda,
                t.nombre_comercial,
                a.nombre_locatario,
                a.rut,
                l.id_local,
                l.cdo_local,
                l.desc_local,
                m.id_medidor,
                m.codigo_medidor,
                lm.lectura_anterior,
                lm.lectura_actual,
                lm.consumo_informado,
                cs.consumo_cobrado,
                cs.subtotal_variable,
                cs.cargo_fijo,
                cs.monto_total,
                dc.id_documento_cobro,
                dc.numero_documento,
                dc.estado_documento,
                dc.saldo_pendiente
             " . $baseSql . '
              ORDER BY lm.periodo_facturacion DESC, ts.nombre_servicio ASC, t.nombre_comercial ASC, ' . msp2LocalCodeNaturalOrderSql('l.cdo_local') . ', m.codigo_medidor ASC, cs.id_cobro_servicio DESC
              OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY'
        );

        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $dataStmt->bindValue(':lineas', $lineasPorPagina, PDO::PARAM_INT);
        $dataStmt->execute();
        $registros = $dataStmt->fetchAll();
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar el reporte de trazabilidad. Detalle tecnico: ' . $exception->getMessage();
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
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Trazabilidad Cobros</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a MSP
                </a>
            </div>
            <a href="<?php echo msp2Escape(msp2Url('documentos_cobro/index.php')); ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-receipt me-1" aria-hidden="true"></i>Documentos
            </a>
        </div>

        <p class="section-kicker text-center">MSP / Reportes</p>
        <h1 class="form-title text-center mb-2">Trazabilidad por Local y Servicio</h1>
        <p class="text-muted text-center mb-4">Detalle de lectura, cobro y documento asociado para auditoria operacional.</p>

        <?php msp2RenderFlash($flash); ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <form method="get" class="row g-2 mb-3 align-items-end">
                <div class="col-12 col-md-2">
                    <label for="filtroAnio" class="form-label">Año</label>
                    <input type="number" id="filtroAnio" name="filtroAnio" class="form-control" min="2000" max="2100" value="<?php echo msp2Escape($filtroAnio); ?>">
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroServicio" class="form-label">Servicio</label>
                    <select id="filtroServicio" name="filtroServicio" class="form-select">
                        <option value="">(Todos)</option>
                        <?php foreach ($tiposServicio as $tipo): ?>
                            <option value="<?php echo (int) $tipo['id_tipo_servicio']; ?>" <?php echo $filtroServicio === (string) $tipo['id_tipo_servicio'] ? 'selected' : ''; ?>>
                                <?php echo msp2Escape((string) $tipo['nombre_servicio']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroEstado" class="form-label">Estado documento</label>
                    <select id="filtroEstado" name="filtroEstado" class="form-select">
                        <option value="">(Todos)</option>
                        <?php foreach ($estadosDocumento as $idEstado => $estado): ?>
                            <option value="<?php echo (int) $idEstado; ?>" <?php echo (int) $filtroEstado === $idEstado ? 'selected' : ''; ?>>
                                <?php echo msp2Escape((string) $estado['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroTienda" class="form-label">Tienda</label>
                    <input type="text" id="filtroTienda" name="filtroTienda" class="form-control" value="<?php echo msp2Escape($filtroTienda); ?>" placeholder="Nombre o ID">
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroLocal" class="form-label">Local</label>
                    <input type="text" id="filtroLocal" name="filtroLocal" class="form-control" value="<?php echo msp2Escape($filtroLocal); ?>" placeholder="Codigo/desc">
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroMedidor" class="form-label">Medidor</label>
                    <input type="text" id="filtroMedidor" name="filtroMedidor" class="form-control" value="<?php echo msp2Escape($filtroMedidor); ?>" placeholder="Codigo">
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
                <div class="col-6 col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                </div>
            </form>

            <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                <div class="small text-muted">
                    Registros: <strong><?php echo number_format($totalRegistros, 0, ',', '.'); ?></strong>
                    | Monto total filtrado: <strong><?php echo msp2Escape(formatoTrazabilidadMonto($totalMonto)); ?></strong>
                    | Saldo pendiente: <strong><?php echo msp2Escape(formatoTrazabilidadMonto($totalSaldoPendiente)); ?></strong>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle text-center">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 90px;">Periodo</th>
                            <th style="width: 90px;">Servicio</th>
                            <th style="width: 140px;">Tienda</th>
                            <th style="width: 140px;">Arrendatario</th>
                            <th style="width: 100px;">Local</th>
                            <th style="width: 110px;">Medidor</th>
                            <th style="width: 100px;">Lectura ant.</th>
                            <th style="width: 100px;">Lectura act.</th>
                            <th style="width: 90px;">Consumo</th>
                            <th style="width: 110px;">Monto cobro</th>
                            <th style="width: 120px;">Documento</th>
                            <th style="width: 140px;">Estado documento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($registros === []): ?>
                            <tr>
                                <td colspan="12" class="text-muted">No hay datos de trazabilidad para los filtros actuales.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($registros as $row): ?>
                                <?php $estado = $estadosDocumento[(int) ($row['estado_documento'] ?? 0)] ?? ['label' => 'Sin documento', 'badge' => 'text-bg-light text-dark']; ?>
                                <tr>
                                    <td><?php echo msp2Escape(formatoTrazabilidadPeriodo($row['periodo_facturacion'] ?? null)); ?></td>
                                    <td>
                                        <span class="badge text-bg-info"><?php echo msp2Escape((string) ($row['nombre_servicio'] ?? '')); ?></span>
                                    </td>
                                    <td class="text-start">
                                        <?php echo msp2Escape((string) ($row['nombre_comercial'] ?? 'Sin tienda')); ?>
                                        <?php if (!empty($row['id_tienda'])): ?>
                                            <div class="small text-muted">#<?php echo (int) $row['id_tienda']; ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-start">
                                        <?php echo msp2Escape((string) ($row['nombre_locatario'] ?? 'Sin arrendatario')); ?>
                                        <?php if (!empty($row['rut'])): ?>
                                            <div class="small text-muted"><?php echo msp2Escape((string) $row['rut']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-start">
                                        <?php echo msp2Escape((string) ($row['cdo_local'] ?? '')); ?>
                                        <div class="small text-muted"><?php echo msp2Escape((string) ($row['desc_local'] ?? '')); ?></div>
                                    </td>
                                    <td><?php echo msp2Escape((string) ($row['codigo_medidor'] ?? '')); ?></td>
                                    <td class="text-end"><?php echo msp2Escape(formatoTrazabilidadNumero($row['lectura_anterior'] ?? null, 4)); ?></td>
                                    <td class="text-end"><?php echo msp2Escape(formatoTrazabilidadNumero($row['lectura_actual'] ?? null, 4)); ?></td>
                                    <td class="text-end"><?php echo msp2Escape(formatoTrazabilidadNumero($row['consumo_cobrado'] ?? null, 4)); ?></td>
                                    <td class="text-end"><?php echo msp2Escape(formatoTrazabilidadMonto($row['monto_total'] ?? null)); ?></td>
                                    <td class="text-start">
                                        <?php if (!empty($row['id_documento_cobro'])): ?>
                                            <a href="<?php echo msp2Escape(msp2Url('documentos_cobro/index.php?filtroDocumento=' . (int) $row['id_documento_cobro'])); ?>">
                                                #<?php echo (int) $row['id_documento_cobro']; ?>
                                            </a>
                                            <?php if (!empty($row['numero_documento'])): ?>
                                                <div class="small text-muted"><?php echo msp2Escape((string) $row['numero_documento']); ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">Sin doc.</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $estado['badge']; ?>">
                                            <?php echo msp2Escape((string) $estado['label']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                <div class="small text-muted">
                    Pagina <strong><?php echo $paginaActual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                </div>

                <?php if ($totalPaginas > 1): ?>
                    <nav aria-label="Paginacion trazabilidad">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildMsp2TrazabilidadQuery($queryBase, ['pagina' => max(1, $paginaActual - 1)]); ?>" aria-label="Anterior">&laquo;</a>
                            </li>
                            <?php foreach ($paginationItems as $item): ?>
                                <?php if ($item === 'ellipsis'): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php else: ?>
                                    <li class="page-item <?php echo (int) $item === $paginaActual ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo buildMsp2TrazabilidadQuery($queryBase, ['pagina' => $item]); ?>"><?php echo $item; ?></a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildMsp2TrazabilidadQuery($queryBase, ['pagina' => min($totalPaginas, $paginaActual + 1)]); ?>" aria-label="Siguiente">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
