<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}
$tablaExiste = false;
$locales = [];
$estados = [];
$medidoresPorLocal = [];
$tiposServicioMedidores = [];
$estadosMedidores = [
    ['id_estado_medidor' => 1, 'desc_estado' => 'Activo'],
    ['id_estado_medidor' => 2, 'desc_estado' => 'Retirado'],
    ['id_estado_medidor' => 3, 'desc_estado' => 'Inactivo'],
];
$gestionMedidoresDisponible = false;
$medidoresTieneValorInicial = false;
$medidoresWarning = null;
$loadError = null;
$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];

$lineasPermitidas = [10, 25, 50, 100, 200];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;

if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}

$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$filtroTexto = msp2NormalizeText($_GET['filtroTexto'] ?? null);
$filtroEstado = trim((string) ($_GET['filtroEstado'] ?? ''));

try {
    $requiredTables = ['msp_locales', 'msp_estado_locales'];
    $missingTables = [];

    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];

    if (!$tablaExiste) {
        $loadError = 'Faltan tablas requeridas para la gestión de locales: `' . implode('`, `', $missingTables) . '`. Ejecuta `msp/msp_a1.sql`.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura base del módulo de locales.';
}

if ($tablaExiste) {
    try {
        $estadosStmt = $conn->query('SELECT id_estado_local, desc_estado FROM dbo.msp_estado_locales ORDER BY id_estado_local ASC');
        $estados = $estadosStmt->fetchAll();

        $conditions = [];
        $params = [];

        if ($filtroTexto !== '') {
            $conditions[] = "ISNULL(l.cdo_local, '') LIKE :filtro_codigo";
            $params[':filtro_codigo'] = '%' . $filtroTexto . '%';
        }

        if ($filtroEstado !== '' && ctype_digit($filtroEstado)) {
            $conditions[] = 'l.id_estado_local = :filtro_estado';
            $params[':filtro_estado'] = (int) $filtroEstado;
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

        $countStmt = $conn->prepare(
            "SELECT COUNT(*)
             FROM dbo.msp_locales l
             INNER JOIN dbo.msp_estado_locales e ON e.id_estado_local = l.id_estado_local
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
                l.id_local,
                l.cdo_local,
                l.metros_cuadrados,
                l.valor_arriendo_uf,
                l.id_estado_local,
                e.desc_estado
             FROM dbo.msp_locales l
             INNER JOIN dbo.msp_estado_locales e ON e.id_estado_local = l.id_estado_local
             WHERE $whereClause
             ORDER BY " . msp2LocalCodeNaturalOrderSql('l.cdo_local') . "
             OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':lineas', $lineasPorPagina, PDO::PARAM_INT);
        $stmt->execute();
        $locales = $stmt->fetchAll();

        $tablasMedidores = ['msp_medidores', 'msp_tipos_servicio'];
        $faltantesMedidores = [];

        foreach ($tablasMedidores as $tablaMedidor) {
            if (!msp2TableExists($conn, $tablaMedidor)) {
                $faltantesMedidores[] = $tablaMedidor;
            }
        }

        if ($faltantesMedidores !== []) {
            $medidoresWarning = 'Gestión de medidores no disponible. Faltan tablas: `' . implode('`, `', $faltantesMedidores) . '`. Ejecuta `msp/db/msp_cobro_servicios.sql`.';
        } else {
            $medidoresTieneValorInicial = msp2ColumnExists($conn, 'msp_medidores', 'valor_inicial');

            $tiposServicioStmt = $conn->query(
                "SELECT
                    id_tipo_servicio,
                    codigo_servicio,
                    nombre_servicio
                 FROM dbo.msp_tipos_servicio
                 WHERE UPPER(codigo_servicio) IN ('AGUA', 'LUZ', 'GAS')
                 ORDER BY CASE UPPER(codigo_servicio)
                            WHEN 'AGUA' THEN 1
                            WHEN 'LUZ' THEN 2
                            WHEN 'GAS' THEN 3
                            ELSE 100
                          END, nombre_servicio ASC"
            );
            $tiposServicioMedidores = $tiposServicioStmt->fetchAll();

            if ($tiposServicioMedidores === []) {
                $medidoresWarning = 'Gestión de medidores no disponible. Verifica catálogos de tipos de servicio en `msp/db/msp_cobro_servicios.sql`.';
            } else {
                $gestionMedidoresDisponible = true;

                if (!$medidoresTieneValorInicial) {
                $medidoresWarning = 'La columna `msp_medidores.valor_inicial` no existe. Para importación masiva con valor inicial, ejecuta `msp/db/msp_cobro_servicios.sql` actualizado o un ALTER TABLE equivalente.';
            }
            }

            $idsLocalesPagina = [];
            foreach ($locales as $localRow) {
                $idLocalRow = (int) ($localRow['id_local'] ?? 0);
                if ($idLocalRow > 0) {
                    $idsLocalesPagina[] = $idLocalRow;
                }
            }

            $idsLocalesPagina = array_values(array_unique($idsLocalesPagina));

            if ($gestionMedidoresDisponible && $idsLocalesPagina !== []) {
                $placeholders = [];
                foreach ($idsLocalesPagina as $index => $_idLocal) {
                    $placeholders[] = ':id_local_' . $index;
                }

                $selectValorInicial = $medidoresTieneValorInicial
                    ? 'm.valor_inicial'
                    : 'CAST(NULL AS DECIMAL(18,6)) AS valor_inicial';

                $medidoresStmt = $conn->prepare(
                    'SELECT
                        m.id_medidor,
                        m.id_local,
                        m.id_tipo_servicio,
                        m.codigo_medidor,
                        m.alias_medidor,
                        m.numero_serie,
                        ' . $selectValorInicial . ',
                        m.fecha_instalacion,
                        m.fecha_retiro,
                        m.estado_medidor,
                        ult.lectura_actual AS lectura_actual_ultima,
                        ts.codigo_servicio,
                        ts.nombre_servicio
                     FROM dbo.msp_medidores m
                     OUTER APPLY (
                        SELECT TOP (1) lm.lectura_actual
                        FROM dbo.msp_lecturas_medidores lm
                        WHERE lm.id_medidor = m.id_medidor
                        ORDER BY lm.fecha_hasta_consumo DESC, lm.id_lectura DESC
                     ) ult
                     INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio = m.id_tipo_servicio
                     WHERE m.id_local IN (' . implode(', ', $placeholders) . ")
                       AND UPPER(ts.codigo_servicio) IN ('AGUA', 'LUZ', 'GAS')
                     ORDER BY m.id_local ASC, ts.nombre_servicio ASC, m.codigo_medidor ASC"
                );

                foreach ($idsLocalesPagina as $index => $idLocalRow) {
                    $medidoresStmt->bindValue(':id_local_' . $index, $idLocalRow, PDO::PARAM_INT);
                }

                $medidoresStmt->execute();
                $medidoresRows = $medidoresStmt->fetchAll();

                $estadosById = [];
                foreach ($estadosMedidores as $estadoMedidorRow) {
                    $estadosById[(int) $estadoMedidorRow['id_estado_medidor']] = (string) $estadoMedidorRow['desc_estado'];
                }

                foreach ($medidoresRows as $medidorRow) {
                    $idLocalRow = (int) ($medidorRow['id_local'] ?? 0);
                    if ($idLocalRow <= 0) {
                        continue;
                    }

                    if (!isset($medidoresPorLocal[$idLocalRow])) {
                        $medidoresPorLocal[$idLocalRow] = [];
                    }

                    $fechaInstalacion = '';
                    if (!empty($medidorRow['fecha_instalacion'])) {
                        try {
                            $fechaInstalacion = (new DateTimeImmutable((string) $medidorRow['fecha_instalacion']))->format('Y-m-d');
                        } catch (Throwable) {
                            $fechaInstalacion = '';
                        }
                    }

                    $fechaRetiro = '';
                    if (!empty($medidorRow['fecha_retiro'])) {
                        try {
                            $fechaRetiro = (new DateTimeImmutable((string) $medidorRow['fecha_retiro']))->format('Y-m-d');
                        } catch (Throwable) {
                            $fechaRetiro = '';
                        }
                    }

                    $valorInicial = '';
                    if (isset($medidorRow['valor_inicial']) && $medidorRow['valor_inicial'] !== null && $medidorRow['valor_inicial'] !== '') {
                        $valorInicial = is_numeric($medidorRow['valor_inicial'])
                            ? (string) (int) round((float) $medidorRow['valor_inicial'])
                            : msp2NormalizeText((string) $medidorRow['valor_inicial']);
                    }

                    $valorActual = '';
                    if (isset($medidorRow['lectura_actual_ultima']) && $medidorRow['lectura_actual_ultima'] !== null && $medidorRow['lectura_actual_ultima'] !== '') {
                        $valorActual = is_numeric($medidorRow['lectura_actual_ultima'])
                            ? (string) (int) round((float) $medidorRow['lectura_actual_ultima'])
                            : msp2NormalizeText((string) $medidorRow['lectura_actual_ultima']);
                    } elseif ($valorInicial !== '') {
                        $valorActual = $valorInicial;
                    }

                    $medidoresPorLocal[$idLocalRow][] = [
                        'id_medidor' => (int) ($medidorRow['id_medidor'] ?? 0),
                        'id_tipo_servicio' => (int) ($medidorRow['id_tipo_servicio'] ?? 0),
                        'codigo_servicio' => strtoupper(msp2NormalizeText((string) ($medidorRow['codigo_servicio'] ?? ''))),
                        'nombre_servicio' => msp2NormalizeText((string) ($medidorRow['nombre_servicio'] ?? '')),
                        'codigo_medidor' => msp2NormalizeText((string) ($medidorRow['codigo_medidor'] ?? '')),
                        'alias_medidor' => msp2NormalizeText((string) ($medidorRow['alias_medidor'] ?? '')),
                        'numero_serie' => msp2NormalizeText((string) ($medidorRow['numero_serie'] ?? '')),
                        'valor_inicial' => $valorInicial,
                        'valor_actual' => $valorActual,
                        'fecha_instalacion' => $fechaInstalacion,
                        'fecha_retiro' => $fechaRetiro,
                        'estado_medidor' => (int) ($medidorRow['estado_medidor'] ?? 0),
                        'desc_estado' => msp2NormalizeText($estadosById[(int) ($medidorRow['estado_medidor'] ?? 0)] ?? ''),
                    ];
                }
            }
        }
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar los locales. Detalle técnico: ' . $exception->getMessage();
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
$redirectToIndex = 'locales/index.php';
$redirectToQuery = http_build_query($_GET);
if ($redirectToQuery !== '') {
    $redirectToIndex .= '?' . $redirectToQuery;
}

function buildMsp2LocalesQuery(array $base, array $override = []): string
{
    return http_build_query(array_merge($base, $override));
}

function msp2LocalEstadoBadge(?string $estado): string
{
    $estadoNormalizado = mb_strtolower(trim((string) $estado));

    return match ($estadoNormalizado) {
        'activo', 'disponible' => 'bg-success',
        'ocupado', 'arrendado', 'en arriendo' => 'bg-primary',
        'mantencion', 'mantención' => 'bg-warning text-dark',
        'inactivo' => 'bg-secondary',
        default => 'bg-light text-dark',
    };
}

function msp2MedidorEstadoBadge(?string $estado): string
{
    $estadoNormalizado = mb_strtolower(trim((string) $estado));

    return match ($estadoNormalizado) {
        'activo' => 'bg-success',
        'retirado' => 'bg-secondary',
        'inactivo' => 'bg-warning text-dark',
        default => 'bg-light text-dark',
    };
}

function msp2ServicioMedidorBadge(?string $codigoServicio): string
{
    $codigo = strtoupper(trim((string) $codigoServicio));

    return match ($codigo) {
        'AGUA' => 'bg-info text-dark',
        'LUZ' => 'bg-warning text-dark',
        'GAS' => 'bg-secondary',
        default => 'bg-light text-dark',
    };
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Locales</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css?v=<?php echo rawurlencode((string) filemtime(dirname(__DIR__, 2) . '/styles.css')); ?>">
</head>
<body class="gp-layout bg-light">

<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main p-3 p-xl-4">
    <div class="msp-management-index msp-locals-index">
        <header class="msp-management-page-header msp-locals-page-header">
            <div class="msp-locals-back">
                <a href="<?php echo msp2Escape(msp2Url('locales_tiendas/index.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Locales y tiendas
                </a>
            </div>
            <h1>Locales</h1>
            <div class="d-flex flex-wrap gap-2 msp-management-actions msp-locals-actions">
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalImportarLocales">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Importar locales
                </button>
                <button
                    type="button"
                    class="btn btn-success btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#modalImportarMedidores"
                    <?php echo ($gestionMedidoresDisponible && $medidoresTieneValorInicial) ? '' : 'disabled'; ?>
                    title="<?php echo ($gestionMedidoresDisponible && $medidoresTieneValorInicial) ? 'Cargar medidores en bloque por local' : 'Requiere gestión de medidores disponible y columna valor_inicial en msp_medidores'; ?>">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Importar medidores
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearLocal">
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar local
                </button>
            </div>
        </header>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <?php if ($medidoresWarning !== null): ?>
                <div class="alert alert-warning" role="alert">
                    <?php echo msp2Escape($medidoresWarning); ?>
                </div>
            <?php endif; ?>

            <form method="get" class="row g-2 msp-management-filters msp-locals-filters align-items-end">
                <div class="col-12 col-md-5">
                    <label for="filtroTexto" class="form-label">Código</label>
                    <input type="text" id="filtroTexto" name="filtroTexto" class="form-control" value="<?php echo msp2Escape($filtroTexto); ?>" placeholder="Buscar por código">
                </div>
                <div class="col-12 col-md-3">
                    <label for="filtroEstado" class="form-label">Estado</label>
                    <select id="filtroEstado" name="filtroEstado" class="form-select">
                        <option value="">(Todos)</option>
                        <?php foreach ($estados as $estado): ?>
                            <option value="<?php echo (int) $estado['id_estado_local']; ?>" <?php echo $filtroEstado === (string) $estado['id_estado_local'] ? 'selected' : ''; ?>>
                                <?php echo msp2Escape($estado['desc_estado']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="lineas" class="form-label">Líneas</label>
                    <select id="lineas" name="lineas" class="form-select">
                        <?php foreach ($lineasPermitidas as $lineas): ?>
                            <option value="<?php echo $lineas; ?>" <?php echo $lineasPorPagina === $lineas ? 'selected' : ''; ?>>
                                <?php echo $lineas; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary msp-local-filter-submit">Filtrar</button>
                </div>
            </form>

            <div class="msp-management-table-responsive">
                <table class="table table-hover align-middle text-center msp-management-table msp-locals-table">
                    <thead class="table-light">
                        <tr>
                            <th class="local-number">#</th>
                            <th class="local-code">Código</th>
                            <th class="local-area">m²</th>
                            <th class="local-rent">Arriendo UF ref. (legado)</th>
                            <th class="local-state">Estado</th>
                            <th class="local-meters">Medidores</th>
                            <th class="local-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($locales)): ?>
                            <tr>
                                <td colspan="7" class="text-muted">
                                    <?php echo ($filtroTexto === '' && $filtroEstado === '') ? 'No hay locales registrados todavía.' : 'Sin resultados para los filtros actuales.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($locales as $index => $local): ?>
                                <?php
                                $idLocalActual = (int) ($local['id_local'] ?? 0);
                                $medidoresLocal = $medidoresPorLocal[$idLocalActual] ?? [];

                                $serviciosMedidoresLocal = [];
                                foreach ($medidoresLocal as $medidorLocal) {
                                    $idTipoServicioLocal = (int) ($medidorLocal['id_tipo_servicio'] ?? 0);
                                    $codigoServicioLocal = strtoupper(msp2NormalizeText((string) ($medidorLocal['codigo_servicio'] ?? '')));
                                    $claveServicioLocal = $idTipoServicioLocal > 0
                                        ? 'id:' . $idTipoServicioLocal
                                        : 'codigo:' . $codigoServicioLocal;

                                    if (!isset($serviciosMedidoresLocal[$claveServicioLocal])) {
                                        $serviciosMedidoresLocal[$claveServicioLocal] = [
                                            'id_tipo_servicio' => $idTipoServicioLocal,
                                            'codigo_servicio' => $codigoServicioLocal,
                                            'nombre_servicio' => msp2NormalizeText((string) ($medidorLocal['nombre_servicio'] ?? '')),
                                            'desc_estado' => msp2NormalizeText((string) ($medidorLocal['desc_estado'] ?? '')),
                                        ];
                                    }

                                    if (strcasecmp(msp2NormalizeText((string) ($medidorLocal['desc_estado'] ?? '')), 'Activo') === 0) {
                                        $serviciosMedidoresLocal[$claveServicioLocal]['desc_estado'] = 'Activo';
                                    }
                                }

                                $medidoresLocalJson = json_encode($medidoresLocal, JSON_UNESCAPED_UNICODE | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG);
                                if ($medidoresLocalJson === false) {
                                    $medidoresLocalJson = '[]';
                                }
                                ?>
                                <tr>
                                    <td class="local-number"><?php echo (($paginaActual - 1) * $lineasPorPagina) + $index + 1; ?></td>
                                    <td class="local-code fw-semibold"><?php echo msp2Escape($local['cdo_local']); ?></td>
                                    <td class="local-area"><?php echo msp2Escape(msp2FormatoDecimal($local['metros_cuadrados'])); ?></td>
                                    <td class="local-rent"><?php echo msp2Escape(msp2FormatoDecimal($local['valor_arriendo_uf'], 2)); ?></td>
                                    <td class="local-state">
                                        <span class="badge <?php echo msp2LocalEstadoBadge($local['desc_estado']); ?>">
                                            <?php echo msp2Escape($local['desc_estado']); ?>
                                        </span>
                                    </td>
                                    <td class="text-start local-meters">
                                        <?php if ($medidoresLocal === []): ?>
                                            <div class="msp-local-meter-empty">
                                                <span class="text-muted small">Sin medidores registrados.</span>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-success btn-sm js-gestionar-medidores"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalGestionMedidorLocal"
                                                    data-id-local="<?php echo $idLocalActual; ?>"
                                                    data-codigo-local="<?php echo msp2Escape((string) ($local['cdo_local'] ?? '')); ?>"
                                                    data-medidores="<?php echo msp2Escape($medidoresLocalJson); ?>"
                                                    data-id-tipo-servicio="0"
                                                    data-codigo-servicio=""
                                                    data-nombre-servicio=""
                                                    <?php echo $gestionMedidoresDisponible ? '' : 'disabled'; ?>
                                                    aria-label="Agregar medidor al local <?php echo msp2Escape((string) ($local['cdo_local'] ?? '')); ?>">
                                                    Agregar medidor
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div class="msp-local-meter-summary">
                                                <?php foreach ($serviciosMedidoresLocal as $servicioMedidorVista): ?>
                                                    <?php
                                                    $labelServicio = msp2NormalizeText((string) ($servicioMedidorVista['nombre_servicio'] ?? ''));
                                                    $codigoServicio = strtoupper(msp2NormalizeText((string) ($servicioMedidorVista['codigo_servicio'] ?? '')));
                                                    $labelEstado = msp2NormalizeText((string) ($servicioMedidorVista['desc_estado'] ?? ''));
                                                    $estadoActivo = strcasecmp($labelEstado, 'Activo') === 0;
                                                    ?>
                                                    <div class="msp-local-meter-summary-row">
                                                        <span class="msp-local-meter-service"><?php echo msp2Escape($labelServicio !== '' ? $labelServicio : $codigoServicio); ?></span>
                                                        <span class="badge <?php echo $estadoActivo ? 'bg-success' : 'bg-secondary'; ?>">
                                                            <?php echo msp2Escape($labelEstado !== '' ? $labelEstado : 'Sin estado'); ?>
                                                        </span>
                                                        <button
                                                            type="button"
                                                            class="btn btn-outline-primary btn-sm js-gestionar-medidores"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#modalGestionMedidorLocal"
                                                            data-id-local="<?php echo $idLocalActual; ?>"
                                                            data-codigo-local="<?php echo msp2Escape((string) ($local['cdo_local'] ?? '')); ?>"
                                                            data-medidores="<?php echo msp2Escape($medidoresLocalJson); ?>"
                                                            data-id-tipo-servicio="<?php echo (int) ($servicioMedidorVista['id_tipo_servicio'] ?? 0); ?>"
                                                            data-codigo-servicio="<?php echo msp2Escape($codigoServicio); ?>"
                                                            data-nombre-servicio="<?php echo msp2Escape($labelServicio !== '' ? $labelServicio : $codigoServicio); ?>"
                                                            <?php echo $gestionMedidoresDisponible ? '' : 'disabled'; ?>
                                                            aria-label="Gestionar medidores de <?php echo msp2Escape($labelServicio !== '' ? $labelServicio : $codigoServicio); ?> del local <?php echo msp2Escape((string) ($local['cdo_local'] ?? '')); ?>">
                                                            Gestionar
                                                        </button>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="local-actions">
                                        <div class="table-actions">
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary btn-sm js-edit-local"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEditarLocal"
                                                data-id="<?php echo (int) $local['id_local']; ?>"
                                                data-codigo="<?php echo msp2Escape($local['cdo_local']); ?>"
                                                data-metros="<?php echo msp2Escape((string) $local['metros_cuadrados']); ?>"
                                                data-arriendo="<?php echo msp2Escape((string) $local['valor_arriendo_uf']); ?>"
                                                data-estado="<?php echo (int) $local['id_estado_local']; ?>"
                                                aria-label="Editar local <?php echo msp2Escape($local['cdo_local']); ?>">
                                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                            </button>
                                            <form
                                                method="post"
                                                action="<?php echo msp2Escape(msp2Url('locales/eliminar.php')); ?>"
                                                class="d-inline"
                                                data-confirm-message="¿Eliminar el local &quot;<?php echo msp2Escape($local['cdo_local']); ?>&quot;?"
                                                data-confirm-title="Confirmar eliminación"
                                                data-confirm-variant="danger">
                                                <input type="hidden" name="redirect_to" value="<?php echo msp2Escape($redirectToIndex); ?>">
                                                <input type="hidden" name="id_local" value="<?php echo (int) $local['id_local']; ?>">
                                                <button
                                                    type="submit"
                                                    class="btn btn-outline-danger btn-sm"
                                                    aria-label="Eliminar local <?php echo msp2Escape($local['cdo_local']); ?>">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
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
                    | Página <strong><?php echo $paginaActual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                </div>

                <?php if ($totalPaginas > 1): ?>
                    <nav aria-label="Paginación de locales">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildMsp2LocalesQuery($queryBase, ['pagina' => max(1, $paginaActual - 1)]); ?>" aria-label="Anterior">&laquo;</a>
                            </li>
                            <?php foreach ($paginationItems as $item): ?>
                                <?php if ($item === 'ellipsis'): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php else: ?>
                                    <li class="page-item <?php echo (int) $item === $paginaActual ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo buildMsp2LocalesQuery($queryBase, ['pagina' => $item]); ?>"><?php echo $item; ?></a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildMsp2LocalesQuery($queryBase, ['pagina' => min($totalPaginas, $paginaActual + 1)]); ?>" aria-label="Siguiente">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade msp-local-modal" id="modalImportarLocales" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('locales/importar.php')); ?>" enctype="multipart/form-data">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Importar locales desde Excel</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="excel_file" class="form-label">Archivo</label>
                    <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="d-flex justify-content-end mb-3">
                    <a href="<?php echo msp2Escape(msp2Url('locales/plantilla.php')); ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Descargar plantilla Excel
                    </a>
                </div>
                <div class="alert alert-info mb-0">
                    Columnas requeridas: <code>cdo_local</code>, <code>metros_cuadrados</code>, <code>valor_arriendo_uf</code> (referencial). Opcional: <code>desc_local</code>. Estado se asigna automáticamente a <strong>Disponible</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Ver vista previa
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade msp-local-modal" id="modalImportarMedidores" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('medidores/importar.php')); ?>" enctype="multipart/form-data">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Importar medidores</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="redirect_to" value="locales/index.php">
                <div class="mb-3">
                    <label for="excel_medidores_file" class="form-label">Archivo Excel</label>
                    <input type="file" class="form-control" id="excel_medidores_file" name="excel_file" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="mb-3">
                    <label for="fecha_medicion_valor_inicial" class="form-label">Fecha medición valor inicial (opcional)</label>
                    <input type="date" class="form-control" id="fecha_medicion_valor_inicial" name="fecha_medicion_valor_inicial">
                    <div class="form-text">Se usa como referencia para <code>valor_inicial</code> cuando la planilla no trae una fecha por fila.</div>
                </div>
                <div class="d-flex justify-content-end mb-3">
                    <a href="<?php echo msp2Escape(msp2Url('medidores/plantilla_import.php')); ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Descargar plantilla Excel
                    </a>
                </div>
                <div class="alert alert-info mb-0">
                    <div><strong>Columnas requeridas:</strong> <code>cdo_local</code>, <code>tipo_servicio</code>, <code>valor_inicial</code> y <code>codigo_medidor</code> o <code>id_temporal</code>.</div>
                    <div class="mt-1"><strong>Opcional:</strong> <code>alias_medidor</code>, <code>fecha_medicion_valor_inicial</code> (si se omite, se usa la fecha del formulario).</div>
                    <div class="mt-1"><strong>Generación automática:</strong> si usas <code>id_temporal</code>, el código queda <code>cdo_local-tipo_servicio-id_temporal</code>.</div>
                    <div class="mt-1"><strong>Tipos permitidos:</strong> AGUA, LUZ, GAS.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Importar medidores
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade msp-local-modal" id="modalCrearLocal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('locales/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Agregar local</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="redirect_to" value="<?php echo msp2Escape($redirectToIndex); ?>">
                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <label for="crear_cdo_local" class="form-label">Código local</label>
                        <input type="text" class="form-control" id="crear_cdo_local" name="cdo_local" maxlength="20" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="crear_id_estado_local" class="form-label">Estado</label>
                        <select class="form-select" id="crear_id_estado_local" name="id_estado_local" required>
                            <option value="">Seleccionar estado</option>
                            <?php foreach ($estados as $estado): ?>
                                <option value="<?php echo (int) $estado['id_estado_local']; ?>">
                                    <?php echo msp2Escape($estado['desc_estado']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="crear_metros_cuadrados" class="form-label">m2</label>
                        <input type="number" class="form-control" id="crear_metros_cuadrados" name="metros_cuadrados" min="0" step="0.01" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="crear_valor_arriendo_uf" class="form-label">Arriendo UF referencial</label>
                        <input type="number" class="form-control" id="crear_valor_arriendo_uf" name="valor_arriendo_uf" min="0" step="0.01" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar local</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade msp-local-modal" id="modalEditarLocal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('locales/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Editar local</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_local" id="edit_id_local">
                <input type="hidden" name="redirect_to" value="<?php echo msp2Escape($redirectToIndex); ?>">
                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <label for="edit_cdo_local" class="form-label">Código local</label>
                        <input type="text" class="form-control" id="edit_cdo_local" name="cdo_local" maxlength="20" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_id_estado_local" class="form-label">Estado</label>
                        <select class="form-select" id="edit_id_estado_local" name="id_estado_local" required>
                            <option value="">Seleccionar estado</option>
                            <?php foreach ($estados as $estado): ?>
                                <option value="<?php echo (int) $estado['id_estado_local']; ?>">
                                    <?php echo msp2Escape($estado['desc_estado']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_metros_cuadrados" class="form-label">m2</label>
                        <input type="number" class="form-control" id="edit_metros_cuadrados" name="metros_cuadrados" min="0" step="0.01" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_valor_arriendo_uf" class="form-label">Arriendo UF referencial</label>
                        <input type="number" class="form-control" id="edit_valor_arriendo_uf" name="valor_arriendo_uf" min="0" step="0.01" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar cambios</button>
            </div>
        </form>
    </div>
</div>

<?php if ($gestionMedidoresDisponible): ?>
<div class="modal fade msp-local-modal" id="modalGestionMedidorLocal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable msp-meter-dialog">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('medidores/guardar.php')); ?>" id="formGestionMedidorLocal">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="titulo_modal_medidor_local">Gestionar medidores</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="redirect_to" value="locales/index.php">
                <input type="hidden" name="id_medidor" id="medidor_modal_id_medidor" value="">
                <input type="hidden" name="id_local" id="medidor_modal_id_local" value="">

                <div class="row g-2">
                    <div class="col-12">
                        <label for="medidor_modal_local_label" class="form-label">Local</label>
                        <input type="text" class="form-control" id="medidor_modal_local_label" readonly>
                    </div>
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                            <p class="fw-semibold mb-0" id="medidor_modal_lista_titulo">Medidores actuales</p>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btn_medidor_modal_nuevo">
                                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nuevo medidor
                            </button>
                        </div>
                        <div id="medidor_modal_existentes_container" class="vstack gap-2"></div>
                    </div>
                    <div class="col-12">
                        <div id="medidor_modal_form_section" class="d-none">
                            <hr class="my-1">
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                <p class="fw-semibold mb-0" id="medidor_modal_form_titulo">Nuevo medidor</p>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btn_medidor_modal_cancelar_edicion">
                                    Cerrar formulario
                                </button>
                            </div>
                            <div class="row g-2">
                                <div class="col-12 col-md-6">
                                    <label for="medidor_modal_id_tipo_servicio" class="form-label">Servicio</label>
                                    <select id="medidor_modal_id_tipo_servicio" name="id_tipo_servicio" class="form-select" required>
                                        <option value="">Seleccionar servicio</option>
                                        <?php foreach ($tiposServicioMedidores as $tipoServicioMedidor): ?>
                                            <option value="<?php echo (int) $tipoServicioMedidor['id_tipo_servicio']; ?>">
                                                <?php echo msp2Escape((string) $tipoServicioMedidor['nombre_servicio']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" id="medidor_modal_id_tipo_servicio_fijo" value="" disabled>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="medidor_modal_id_estado_medidor" class="form-label">Estado</label>
                                    <select id="medidor_modal_id_estado_medidor" name="id_estado_medidor" class="form-select" required>
                                        <option value="">Seleccionar estado</option>
                                        <?php foreach ($estadosMedidores as $estadoMedidor): ?>
                                            <option value="<?php echo (int) $estadoMedidor['id_estado_medidor']; ?>">
                                                <?php echo msp2Escape((string) $estadoMedidor['desc_estado']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="medidor_modal_codigo_medidor" class="form-label">Código medidor</label>
                                    <input type="text" class="form-control text-uppercase" id="medidor_modal_codigo_medidor" name="codigo_medidor" maxlength="100" required>
                                </div>
                                <input type="hidden" id="medidor_modal_alias_medidor" name="alias_medidor" value="">
                                <div class="col-12 col-md-6">
                                    <label for="medidor_modal_numero_serie" class="form-label">Número de serie</label>
                                    <input type="text" class="form-control" id="medidor_modal_numero_serie" name="numero_serie" maxlength="100">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="medidor_modal_valor_inicial" class="form-label">Valor inicial</label>
                                    <input
                                        type="number"
                                        class="form-control"
                                        id="medidor_modal_valor_inicial"
                                        name="valor_inicial"
                                        min="0"
                                        step="1"
                                        <?php echo $medidoresTieneValorInicial ? '' : 'disabled'; ?>>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="medidor_modal_fecha_instalacion" class="form-label">Fecha instalación</label>
                                    <input type="date" class="form-control" id="medidor_modal_fecha_instalacion" name="fecha_instalacion">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label for="medidor_modal_fecha_retiro" class="form-label">Fecha retiro</label>
                                    <input type="date" class="form-control" id="medidor_modal_fecha_retiro" name="fecha_retiro">
                                </div>
                                <div class="col-12">
                                    <div class="form-text">
                                        Puedes registrar y editar medidores de agua, luz y gas por local.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary d-none" id="btn_medidor_modal_guardar">Guardar medidor</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<form
    method="post"
    action="<?php echo msp2Escape(msp2Url('medidores/eliminar.php')); ?>"
    id="form_eliminar_medidor_local"
    class="d-none"
    data-confirm-title="Confirmar eliminación"
    data-confirm-variant="danger">
    <input type="hidden" name="redirect_to" value="locales/index.php">
    <input type="hidden" name="id_medidor" id="delete_medidor_local_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const parseJsonArray = (raw) => {
        try {
            const parsed = JSON.parse(raw || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    };

    const normalizeText = (value) => String(value || '').trim();
    const formatUfInput = (value) => {
        const normalized = String(value || '').replace(',', '.').trim();
        if (normalized === '') {
            return '';
        }
        const parsed = Number.parseFloat(normalized);
        if (!Number.isFinite(parsed)) {
            return normalizeText(value);
        }
        return parsed.toFixed(2);
    };
    const formatMedidorValor = (value) => {
        const normalized = String(value || '').replace(',', '.').trim();
        if (normalized === '') {
            return '';
        }
        const parsed = Number.parseFloat(normalized);
        if (!Number.isFinite(parsed)) {
            return normalizeText(value);
        }
        return String(Math.round(parsed));
    };

    document.querySelectorAll('.js-edit-local').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('edit_id_local').value = button.dataset.id || '';
            document.getElementById('edit_cdo_local').value = button.dataset.codigo || '';
            document.getElementById('edit_metros_cuadrados').value = button.dataset.metros || '';
            document.getElementById('edit_valor_arriendo_uf').value = formatUfInput(button.dataset.arriendo || '');
            document.getElementById('edit_id_estado_local').value = button.dataset.estado || '';
        });
    });

    const gestionarMedidoresButtons = document.querySelectorAll('.js-gestionar-medidores');
    const medidorModal = document.getElementById('modalGestionMedidorLocal');

    if (medidorModal && gestionarMedidoresButtons.length > 0) {
        const deleteForm = document.getElementById('form_eliminar_medidor_local');
        const formTitleEl = document.getElementById('medidor_modal_form_titulo');
        const localLabelEl = document.getElementById('medidor_modal_local_label');
        const idMedidorEl = document.getElementById('medidor_modal_id_medidor');
        const idLocalEl = document.getElementById('medidor_modal_id_local');
        const tipoServicioEl = document.getElementById('medidor_modal_id_tipo_servicio');
        const tipoServicioFijoEl = document.getElementById('medidor_modal_id_tipo_servicio_fijo');
        const estadoMedidorEl = document.getElementById('medidor_modal_id_estado_medidor');
        const codigoMedidorEl = document.getElementById('medidor_modal_codigo_medidor');
        const aliasMedidorEl = document.getElementById('medidor_modal_alias_medidor');
        const numeroSerieEl = document.getElementById('medidor_modal_numero_serie');
        const valorInicialEl = document.getElementById('medidor_modal_valor_inicial');
        const fechaInstalacionEl = document.getElementById('medidor_modal_fecha_instalacion');
        const fechaRetiroEl = document.getElementById('medidor_modal_fecha_retiro');
        const existentesContainerEl = document.getElementById('medidor_modal_existentes_container');
        const listaTituloEl = document.getElementById('medidor_modal_lista_titulo');
        const nuevoBtnEl = document.getElementById('btn_medidor_modal_nuevo');
        const cancelarEdicionBtnEl = document.getElementById('btn_medidor_modal_cancelar_edicion');
        const formSectionEl = document.getElementById('medidor_modal_form_section');
        const guardarBtnEl = document.getElementById('btn_medidor_modal_guardar');
        const tituloModalEl = document.getElementById('titulo_modal_medidor_local');
        const deleteIdEl = document.getElementById('delete_medidor_local_id');

        if (
            formTitleEl
            && localLabelEl
            && idMedidorEl
            && idLocalEl
            && tipoServicioEl
            && tipoServicioFijoEl
            && estadoMedidorEl
            && codigoMedidorEl
            && aliasMedidorEl
            && numeroSerieEl
            && valorInicialEl
            && fechaInstalacionEl
            && fechaRetiroEl
            && existentesContainerEl
            && listaTituloEl
            && nuevoBtnEl
            && cancelarEdicionBtnEl
            && formSectionEl
            && guardarBtnEl
            && tituloModalEl
            && deleteIdEl
            && deleteForm
        ) {
            const estadoDefaultOption = Array.from(estadoMedidorEl.options).find((option) => option.value !== '');
            const formControls = Array.from(formSectionEl.querySelectorAll('input, select, textarea'));
            const modalState = {
                idLocal: 0,
                localLabel: '',
                medidores: [],
                idTipoServicio: 0,
                codigoServicio: '',
                nombreServicio: '',
            };

            const applyServiceContextToForm = () => {
                const formVisible = !formSectionEl.classList.contains('d-none');
                const serviceLocked = modalState.idTipoServicio > 0;

                tipoServicioEl.name = serviceLocked ? '' : 'id_tipo_servicio';
                tipoServicioEl.disabled = !formVisible || serviceLocked;
                tipoServicioFijoEl.name = serviceLocked ? 'id_tipo_servicio' : '';
                tipoServicioFijoEl.disabled = !formVisible || !serviceLocked;

                if (serviceLocked) {
                    const idTipoServicio = String(modalState.idTipoServicio);
                    tipoServicioEl.value = idTipoServicio;
                    tipoServicioFijoEl.value = idTipoServicio;
                } else {
                    tipoServicioFijoEl.value = '';
                }
            };

            const setFormVisibility = (visible) => {
                formSectionEl.classList.toggle('d-none', !visible);
                guardarBtnEl.classList.toggle('d-none', !visible);
                formControls.forEach((control) => {
                    control.disabled = !visible;
                });
                applyServiceContextToForm();
            };

            const serviceColorClass = (codigoServicio) => {
                const code = normalizeText(codigoServicio).toUpperCase();

                if (code === 'AGUA') {
                    return 'bg-info text-dark';
                }
                if (code === 'LUZ') {
                    return 'bg-warning text-dark';
                }
                if (code === 'GAS') {
                    return 'bg-secondary';
                }

                return 'bg-light text-dark';
            };

            const normalizeMedidor = (item) => ({
                id_medidor: Number.parseInt(String(item?.id_medidor ?? ''), 10) || 0,
                id_tipo_servicio: Number.parseInt(String(item?.id_tipo_servicio ?? ''), 10) || 0,
                codigo_servicio: normalizeText(item?.codigo_servicio).toUpperCase(),
                nombre_servicio: normalizeText(item?.nombre_servicio),
                codigo_medidor: normalizeText(item?.codigo_medidor).toUpperCase(),
                alias_medidor: normalizeText(item?.alias_medidor),
                numero_serie: normalizeText(item?.numero_serie),
                valor_inicial: normalizeText(item?.valor_inicial),
                valor_actual: normalizeText(item?.valor_actual),
                fecha_instalacion: normalizeText(item?.fecha_instalacion),
                fecha_retiro: normalizeText(item?.fecha_retiro),
                estado_medidor: Number.parseInt(String(item?.estado_medidor ?? item?.id_estado_medidor ?? ''), 10) || 0,
                desc_estado: normalizeText(item?.desc_estado),
            });

            const renderMedidoresExistentes = () => {
                existentesContainerEl.innerHTML = '';
                const medidoresVisibles = modalState.idTipoServicio > 0
                    ? modalState.medidores.filter((medidor) => medidor.id_tipo_servicio === modalState.idTipoServicio)
                    : modalState.medidores;

                if (medidoresVisibles.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'text-muted small';
                    empty.textContent = modalState.nombreServicio
                        ? `Este local no tiene medidores de ${modalState.nombreServicio} registrados.`
                        : 'Este local no tiene medidores registrados.';
                    existentesContainerEl.appendChild(empty);
                    return;
                }

                medidoresVisibles.forEach((medidor) => {
                    const row = document.createElement('div');
                    row.className = 'msp-meter-detail-row';

                    const left = document.createElement('div');
                    left.className = 'msp-meter-detail-data';

                    const servicioBadge = document.createElement('span');
                    servicioBadge.className = `badge ${serviceColorClass(medidor.codigo_servicio)}`;
                    servicioBadge.textContent = medidor.nombre_servicio || medidor.codigo_servicio || 'Servicio';

                    const codigo = document.createElement('span');
                    codigo.className = 'fw-semibold';
                    codigo.textContent = medidor.codigo_medidor || '-';

                    const estado = document.createElement('span');
                    estado.className = 'badge bg-light text-dark border';
                    estado.textContent = medidor.desc_estado || 'Sin estado';

                    left.appendChild(servicioBadge);
                    left.appendChild(codigo);
                    if (medidor.valor_inicial !== '') {
                        const valorInicial = document.createElement('span');
                        valorInicial.className = 'small text-muted';
                        valorInicial.textContent = `Lectura inicial: ${formatMedidorValor(medidor.valor_inicial)}`;
                        left.appendChild(valorInicial);
                    }
                    if (medidor.valor_actual !== '') {
                        const valorActual = document.createElement('span');
                        valorActual.className = 'small text-muted';
                        valorActual.textContent = `Lectura actual: ${formatMedidorValor(medidor.valor_actual)}`;
                        left.appendChild(valorActual);
                    }
                    if (medidor.numero_serie !== '') {
                        const numeroSerie = document.createElement('span');
                        numeroSerie.className = 'small text-muted';
                        numeroSerie.textContent = `Serie: ${medidor.numero_serie}`;
                        left.appendChild(numeroSerie);
                    }
                    left.appendChild(estado);

                    const right = document.createElement('div');
                    right.className = 'd-flex gap-1';

                    const editButton = document.createElement('button');
                    editButton.type = 'button';
                    editButton.className = 'btn btn-outline-primary btn-sm';
                    editButton.dataset.action = 'edit';
                    editButton.dataset.idMedidor = String(medidor.id_medidor);
                    editButton.textContent = 'Editar';

                    const deleteButton = document.createElement('button');
                    deleteButton.type = 'button';
                    deleteButton.className = 'btn btn-outline-danger btn-sm';
                    deleteButton.dataset.action = 'delete';
                    deleteButton.dataset.idMedidor = String(medidor.id_medidor);
                    deleteButton.textContent = 'Eliminar';

                    right.appendChild(editButton);
                    right.appendChild(deleteButton);

                    row.appendChild(left);
                    row.appendChild(right);
                    existentesContainerEl.appendChild(row);
                });
            };

            const resetMedidorForm = () => {
                idMedidorEl.value = '';
                tipoServicioEl.value = '';
                codigoMedidorEl.value = '';
                aliasMedidorEl.value = '';
                numeroSerieEl.value = '';
                valorInicialEl.value = '';
                fechaInstalacionEl.value = '';
                fechaRetiroEl.value = '';
                estadoMedidorEl.value = estadoDefaultOption ? estadoDefaultOption.value : '';
                formTitleEl.textContent = modalState.nombreServicio
                    ? `Nuevo medidor de ${modalState.nombreServicio}`
                    : 'Nuevo medidor';
                setFormVisibility(false);
            };

            const setFormToEdit = (medidor) => {
                idMedidorEl.value = String(medidor.id_medidor || '');
                tipoServicioEl.value = String(medidor.id_tipo_servicio || '');
                codigoMedidorEl.value = medidor.codigo_medidor || '';
                aliasMedidorEl.value = medidor.alias_medidor || '';
                numeroSerieEl.value = medidor.numero_serie || '';
                valorInicialEl.value = formatMedidorValor(medidor.valor_inicial || '');
                fechaInstalacionEl.value = medidor.fecha_instalacion || '';
                fechaRetiroEl.value = medidor.fecha_retiro || '';
                estadoMedidorEl.value = String(medidor.estado_medidor || '');
                formTitleEl.textContent = `Editar medidor ${medidor.codigo_medidor || medidor.id_medidor}`;
                setFormVisibility(true);
            };

            const openDeleteModal = (medidor) => {
                deleteIdEl.value = String(medidor.id_medidor || '');
                deleteForm.dataset.confirmMessage = `¿Eliminar el medidor "${medidor.codigo_medidor || '-'}" del local "${modalState.localLabel || '-'}"?`;
                deleteForm.requestSubmit();
            };

            const setModalContext = (button) => {
                const idLocal = Number.parseInt(String(button.dataset.idLocal || ''), 10) || 0;
                const codigoLocal = normalizeText(button.dataset.codigoLocal);
                const localLabel = codigoLocal;
                const medidores = parseJsonArray(button.dataset.medidores).map((item) => normalizeMedidor(item));
                const idTipoServicio = Number.parseInt(String(button.dataset.idTipoServicio || ''), 10) || 0;
                const codigoServicio = normalizeText(button.dataset.codigoServicio).toUpperCase();
                const nombreServicio = normalizeText(button.dataset.nombreServicio);

                medidores.sort((a, b) => {
                    const servicioA = (a.nombre_servicio || a.codigo_servicio || '').toUpperCase();
                    const servicioB = (b.nombre_servicio || b.codigo_servicio || '').toUpperCase();

                    if (servicioA !== servicioB) {
                        return servicioA.localeCompare(servicioB, 'es');
                    }

                    return (a.codigo_medidor || '').localeCompare((b.codigo_medidor || ''), 'es');
                });

                modalState.idLocal = idLocal;
                modalState.localLabel = localLabel;
                modalState.medidores = medidores;
                modalState.idTipoServicio = idTipoServicio;
                modalState.codigoServicio = codigoServicio;
                modalState.nombreServicio = nombreServicio;

                idLocalEl.value = idLocal > 0 ? String(idLocal) : '';
                localLabelEl.value = localLabel;
                tituloModalEl.textContent = nombreServicio
                    ? `Gestionar ${nombreServicio} · Local ${codigoLocal}`
                    : `Gestionar medidores · Local ${codigoLocal}`;
                listaTituloEl.textContent = nombreServicio
                    ? `Medidores de ${nombreServicio}`
                    : 'Medidores actuales';
                nuevoBtnEl.textContent = nombreServicio
                    ? `Nuevo medidor de ${nombreServicio}`
                    : 'Nuevo medidor';

                renderMedidoresExistentes();
                resetMedidorForm();
            };

            codigoMedidorEl.addEventListener('blur', () => {
                codigoMedidorEl.value = normalizeText(codigoMedidorEl.value).toUpperCase();
                if (aliasMedidorEl.value.trim() === '') {
                    aliasMedidorEl.value = codigoMedidorEl.value.trim();
                }
            });

            nuevoBtnEl.addEventListener('click', () => {
                resetMedidorForm();
                setFormVisibility(true);
                if (modalState.idTipoServicio > 0) {
                    codigoMedidorEl.focus();
                } else {
                    tipoServicioEl.focus();
                }
            });

            cancelarEdicionBtnEl.addEventListener('click', () => {
                resetMedidorForm();
            });

            existentesContainerEl.addEventListener('click', (event) => {
                const button = event.target.closest('button[data-action][data-id-medidor]');
                if (!button) {
                    return;
                }

                const idMedidor = Number.parseInt(String(button.dataset.idMedidor || ''), 10) || 0;
                if (idMedidor <= 0) {
                    return;
                }

                const medidor = modalState.medidores.find((item) => item.id_medidor === idMedidor);
                if (!medidor) {
                    return;
                }

                if (button.dataset.action === 'delete') {
                    openDeleteModal(medidor);
                    return;
                }

                setFormToEdit(medidor);
                codigoMedidorEl.focus();
            });

            gestionarMedidoresButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    setModalContext(button);
                });
            });
        }
    }
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
