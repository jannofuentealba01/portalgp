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
$loadError = null;

$lineasPermitidas = [10, 25, 50, 100, 200];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;
if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}

$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$filtroTienda = msp2NormalizeText($_GET['filtroTienda'] ?? null);
$filtroArrendatario = msp2NormalizeText($_GET['filtroArrendatario'] ?? null);
$filtroLocal = msp2NormalizeText($_GET['filtroLocal'] ?? null);
$filtroEstadoContrato = trim((string) ($_GET['filtroEstadoContrato'] ?? ''));
$filtroEstadoCargo = trim((string) ($_GET['filtroEstadoCargo'] ?? ''));

$estadoContratoCatalogo = [
    1 => ['label' => 'Borrador', 'badge' => 'bg-secondary'],
    2 => ['label' => 'Vigente', 'badge' => 'bg-success'],
    3 => ['label' => 'En revisión', 'badge' => 'bg-warning text-dark'],
    4 => ['label' => 'Cerrado', 'badge' => 'bg-dark'],
    5 => ['label' => 'Anulado', 'badge' => 'bg-danger'],
];

$estadoCargoCatalogo = [
    1 => ['label' => 'Pendiente'],
    2 => ['label' => 'Reservado'],
    3 => ['label' => 'Aplicado'],
    4 => ['label' => 'Pagado'],
    5 => ['label' => 'Anulado'],
];

$registros = [];
$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];
$totalGarantiaDisponible = 0.0;
$totalGarantiaReservada = 0.0;
$totalGarantiaAplicada = 0.0;
$totalDeudaActiva = 0.0;
$totalCargos = 0.0;

$queryBase = $_GET;
unset($queryBase['pagina']);

function buildMsp2DeudaGarantiaQuery(array $base, array $override = []): string
{
    return http_build_query(array_merge($base, $override));
}

function msp2DeudaGarantiaMonto(mixed $value): string
{
    if ($value === null || $value === '') {
        return '$ 0,00';
    }

    return '$ ' . number_format((float) $value, 2, ',', '.');
}

try {
    $requiredTables = [
        'msp_vw_deuda_garantia_local',
        'msp_contratos_arriendo',
        'msp_tiendas',
        'msp_arrendatarios',
        'msp_locales',
        'msp_cargos_salida',
    ];

    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];
    if (!$tablaExiste) {
        $loadError = 'Faltan tablas para deuda/garantía: `' . implode('`, `', $missingTables) . '`. Ejecuta `msp/db/msp_deudores_garantia.sql`.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura de deuda/garantía.';
}

if ($tablaExiste) {
    try {
        $conditions = [];
        $params = [];

        if ($filtroTienda !== '') {
            $conditions[] = "(ISNULL(t.nombre_comercial, '') LIKE :filtro_tienda OR CAST(dg.id_tienda AS NVARCHAR(20)) LIKE :filtro_tienda_id)";
            $params[':filtro_tienda'] = '%' . $filtroTienda . '%';
            $params[':filtro_tienda_id'] = '%' . $filtroTienda . '%';
        }

        if ($filtroArrendatario !== '') {
            $conditions[] = "(ISNULL(a.nombre_locatario, '') LIKE :filtro_arrendatario_nombre OR ISNULL(a.rut, '') LIKE :filtro_arrendatario_rut)";
            $params[':filtro_arrendatario_nombre'] = '%' . $filtroArrendatario . '%';
            $params[':filtro_arrendatario_rut'] = '%' . $filtroArrendatario . '%';
        }

        if ($filtroLocal !== '') {
            $conditions[] = "(ISNULL(l.cdo_local, '') LIKE :filtro_local_codigo OR ISNULL(l.desc_local, '') LIKE :filtro_local_desc)";
            $params[':filtro_local_codigo'] = '%' . $filtroLocal . '%';
            $params[':filtro_local_desc'] = '%' . $filtroLocal . '%';
        }

        if ($filtroEstadoContrato !== '' && ctype_digit($filtroEstadoContrato)) {
            $estadoContratoInt = (int) $filtroEstadoContrato;
            if (isset($estadoContratoCatalogo[$estadoContratoInt])) {
                $conditions[] = 'c.estado_contrato = :filtro_estado_contrato';
                $params[':filtro_estado_contrato'] = $estadoContratoInt;
            }
        }

        if ($filtroEstadoCargo !== '' && ctype_digit($filtroEstadoCargo)) {
            $estadoCargoInt = (int) $filtroEstadoCargo;
            if (isset($estadoCargoCatalogo[$estadoCargoInt])) {
                $conditions[] = 'EXISTS (
                    SELECT 1
                    FROM dbo.msp_cargos_salida csf
                    WHERE csf.id_contrato_arriendo = dg.id_contrato_arriendo
                      AND csf.id_local = dg.id_local
                      AND csf.estado_cargo = :filtro_estado_cargo
                )';
                $params[':filtro_estado_cargo'] = $estadoCargoInt;
            }
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

        $baseSql =
            'FROM dbo.msp_vw_deuda_garantia_local dg
             INNER JOIN dbo.msp_contratos_arriendo c
                ON c.id_contrato_arriendo = dg.id_contrato_arriendo
             INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dg.id_tienda
             INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = dg.id_arrendatario
             INNER JOIN dbo.msp_locales l
                ON l.id_local = dg.id_local
             OUTER APPLY (
                SELECT
                    SUM(CASE WHEN cs.estado_cargo = 1 THEN 1 ELSE 0 END) AS cantidad_pendiente,
                    SUM(CASE WHEN cs.estado_cargo = 2 THEN 1 ELSE 0 END) AS cantidad_reservado,
                    SUM(CASE WHEN cs.estado_cargo = 3 THEN 1 ELSE 0 END) AS cantidad_aplicado,
                    SUM(CASE WHEN cs.estado_cargo = 4 THEN 1 ELSE 0 END) AS cantidad_pagado,
                    COUNT(*) AS cantidad_total
                FROM dbo.msp_cargos_salida cs
                WHERE cs.id_contrato_arriendo = dg.id_contrato_arriendo
                  AND cs.id_local = dg.id_local
                  AND cs.estado_cargo <> 5
             ) cc
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

        $totalesStmt = $conn->prepare(
            'SELECT
                ISNULL(SUM(dg.saldo_disponible), 0) AS total_garantia_disponible,
                ISNULL(SUM(dg.saldo_reservado), 0) AS total_garantia_reservada,
                ISNULL(SUM(dg.saldo_aplicado), 0) AS total_garantia_aplicada,
                ISNULL(SUM(dg.total_cargos), 0) AS total_cargos,
                ISNULL(SUM(dg.total_cargos_pendientes + dg.total_cargos_reservados), 0) AS total_deuda_activa
             ' . $baseSql
        );
        foreach ($params as $key => $value) {
            $totalesStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $totalesStmt->execute();
        $totales = $totalesStmt->fetch();
        $totalGarantiaDisponible = (float) (($totales['total_garantia_disponible'] ?? 0) ?? 0);
        $totalGarantiaReservada = (float) (($totales['total_garantia_reservada'] ?? 0) ?? 0);
        $totalGarantiaAplicada = (float) (($totales['total_garantia_aplicada'] ?? 0) ?? 0);
        $totalCargos = (float) (($totales['total_cargos'] ?? 0) ?? 0);
        $totalDeudaActiva = (float) (($totales['total_deuda_activa'] ?? 0) ?? 0);

        $dataStmt = $conn->prepare(
            'SELECT
                dg.id_contrato_arriendo,
                c.estado_contrato,
                dg.id_tienda,
                t.nombre_comercial,
                dg.id_arrendatario,
                a.nombre_locatario,
                a.rut,
                dg.id_local,
                l.cdo_local,
                l.desc_local,
                dg.id_garantia,
                dg.monto_inicial,
                dg.saldo_disponible,
                dg.saldo_reservado,
                dg.saldo_aplicado,
                dg.total_cargos,
                dg.total_cargos_pendientes,
                dg.total_cargos_reservados,
                dg.total_cargos_aplicados,
                ISNULL(cc.cantidad_total, 0) AS cantidad_cargos_total,
                ISNULL(cc.cantidad_pendiente, 0) AS cantidad_cargos_pendiente,
                ISNULL(cc.cantidad_reservado, 0) AS cantidad_cargos_reservado,
                ISNULL(cc.cantidad_aplicado, 0) AS cantidad_cargos_aplicado,
                ISNULL(cc.cantidad_pagado, 0) AS cantidad_cargos_pagado
             ' . $baseSql . '
             ORDER BY t.nombre_comercial ASC, ' . msp2LocalCodeNaturalOrderSql('l.cdo_local') . ', dg.id_contrato_arriendo DESC
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
        $loadError = 'No fue posible cargar la vista de deuda/garantía. Detalle tecnico: ' . $exception->getMessage();
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
    <title>MSP | Deuda y Garantía</title>
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
                <a href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Gestionar contratos
                </a>
            </div>
        </div>

        <p class="section-kicker text-center">MSP / Deudores</p>
        <h1 class="form-title text-center mb-2">Deuda y Garantía por Contrato/Local</h1>
        <p class="text-muted text-center mb-4">Vista operativa para revisar saldo de garantía y deuda activa por local.</p>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <form method="get" class="row g-2 mb-3 align-items-end">
                <div class="col-12 col-md-2">
                    <label for="filtroTienda" class="form-label">Tienda</label>
                    <input type="text" id="filtroTienda" name="filtroTienda" class="form-control" value="<?php echo msp2Escape($filtroTienda); ?>" placeholder="Nombre o ID">
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroArrendatario" class="form-label">Arrendatario</label>
                    <input type="text" id="filtroArrendatario" name="filtroArrendatario" class="form-control" value="<?php echo msp2Escape($filtroArrendatario); ?>" placeholder="Nombre o RUT">
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroLocal" class="form-label">Local</label>
                    <input type="text" id="filtroLocal" name="filtroLocal" class="form-control" value="<?php echo msp2Escape($filtroLocal); ?>" placeholder="Código o descripción">
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroEstadoContrato" class="form-label">Estado contrato</label>
                    <select id="filtroEstadoContrato" name="filtroEstadoContrato" class="form-select">
                        <option value="">(Todos)</option>
                        <?php foreach ($estadoContratoCatalogo as $idEstado => $estado): ?>
                            <option value="<?php echo (int) $idEstado; ?>" <?php echo $filtroEstadoContrato === (string) $idEstado ? 'selected' : ''; ?>>
                                <?php echo msp2Escape((string) $estado['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroEstadoCargo" class="form-label">Estado cargo</label>
                    <select id="filtroEstadoCargo" name="filtroEstadoCargo" class="form-select">
                        <option value="">(Todos)</option>
                        <?php foreach ($estadoCargoCatalogo as $idEstado => $estado): ?>
                            <option value="<?php echo (int) $idEstado; ?>" <?php echo $filtroEstadoCargo === (string) $idEstado ? 'selected' : ''; ?>>
                                <?php echo msp2Escape((string) $estado['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label for="lineas" class="form-label">Líneas</label>
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

            <div class="small text-muted mb-2">
                Registros: <strong><?php echo number_format($totalRegistros, 0, ',', '.'); ?></strong>
                | Deuda activa: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($totalDeudaActiva)); ?></strong>
                | Cargos: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($totalCargos)); ?></strong>
                | Garantía disponible: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($totalGarantiaDisponible)); ?></strong>
                | Garantía reservada: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($totalGarantiaReservada)); ?></strong>
                | Garantía aplicada: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($totalGarantiaAplicada)); ?></strong>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light text-center">
                        <tr>
                            <th style="width: 130px;">Contrato</th>
                            <th style="width: 170px;">Tienda</th>
                            <th style="width: 200px;">Arrendatario</th>
                            <th style="width: 170px;">Local</th>
                            <th style="width: 220px;">Garantía</th>
                            <th style="width: 220px;">Deuda</th>
                            <th style="width: 180px;">Cargos (cantidad)</th>
                            <th style="width: 130px;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($registros === []): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">
                                    No hay resultados para los filtros actuales.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($registros as $row): ?>
                                <?php
                                $estadoContrato = (int) ($row['estado_contrato'] ?? 0);
                                $estadoContratoData = $estadoContratoCatalogo[$estadoContrato] ?? ['label' => 'Sin estado', 'badge' => 'bg-light text-dark'];
                                $deudaActivaFila = (float) ($row['total_cargos_pendientes'] ?? 0) + (float) ($row['total_cargos_reservados'] ?? 0);
                                $filtroTextoTienda = trim((string) ($row['nombre_comercial'] ?? '')) !== ''
                                    ? (string) ($row['nombre_comercial'] ?? '')
                                    : (string) ((int) ($row['id_tienda'] ?? 0));
                                $puedeCerrarContrato = in_array($estadoContrato, [1, 2, 3], true);
                                ?>
                                <tr>
                                    <td class="text-center">
                                        <div><strong>#<?php echo (int) ($row['id_contrato_arriendo'] ?? 0); ?></strong></div>
                                        <span class="badge <?php echo msp2Escape((string) $estadoContratoData['badge']); ?>">
                                            <?php echo msp2Escape((string) $estadoContratoData['label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo msp2Escape((string) ($row['nombre_comercial'] ?? 'Sin tienda')); ?></div>
                                        <div class="small text-muted">#<?php echo (int) ($row['id_tienda'] ?? 0); ?></div>
                                    </td>
                                    <td>
                                        <div><?php echo msp2Escape((string) ($row['nombre_locatario'] ?? 'Sin arrendatario')); ?></div>
                                        <div class="small text-muted"><?php echo msp2Escape((string) ($row['rut'] ?? '-')); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo msp2Escape((string) ($row['cdo_local'] ?? '')); ?></div>
                                        <div class="small text-muted"><?php echo msp2Escape((string) ($row['desc_local'] ?? '')); ?></div>
                                        <div class="small text-muted">Garantía #<?php echo (int) ($row['id_garantia'] ?? 0); ?></div>
                                    </td>
                                    <td>
                                        <div class="small">Inicial: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($row['monto_inicial'] ?? 0)); ?></strong></div>
                                        <div class="small">Disponible: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($row['saldo_disponible'] ?? 0)); ?></strong></div>
                                        <div class="small">Reservado: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($row['saldo_reservado'] ?? 0)); ?></strong></div>
                                        <div class="small">Aplicado: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($row['saldo_aplicado'] ?? 0)); ?></strong></div>
                                    </td>
                                    <td>
                                        <div class="small">Cargos totales: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($row['total_cargos'] ?? 0)); ?></strong></div>
                                        <div class="small">Pendiente: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($row['total_cargos_pendientes'] ?? 0)); ?></strong></div>
                                        <div class="small">Reservado: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($row['total_cargos_reservados'] ?? 0)); ?></strong></div>
                                        <div class="small">Aplicado: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($row['total_cargos_aplicados'] ?? 0)); ?></strong></div>
                                        <div class="small">Deuda activa: <strong><?php echo msp2Escape(msp2DeudaGarantiaMonto($deudaActivaFila)); ?></strong></div>
                                    </td>
                                    <td>
                                        <div class="small">Total: <strong><?php echo (int) ($row['cantidad_cargos_total'] ?? 0); ?></strong></div>
                                        <div class="small">Pend: <strong><?php echo (int) ($row['cantidad_cargos_pendiente'] ?? 0); ?></strong></div>
                                        <div class="small">Res: <strong><?php echo (int) ($row['cantidad_cargos_reservado'] ?? 0); ?></strong></div>
                                        <div class="small">Apl: <strong><?php echo (int) ($row['cantidad_cargos_aplicado'] ?? 0); ?></strong></div>
                                        <div class="small">Pag: <strong><?php echo (int) ($row['cantidad_cargos_pagado'] ?? 0); ?></strong></div>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-grid gap-1">
                                            <a href="<?php echo msp2Escape(msp2Url('contratos/index.php?filtroTexto=' . urlencode($filtroTextoTienda))); ?>" class="btn btn-outline-primary btn-sm">
                                                Contrato
                                            </a>
                                            <?php if ($puedeCerrarContrato): ?>
                                                <form method="post" action="<?php echo msp2Escape(msp2Url('contratos/cerrar.php')); ?>" class="js-form-cerrar-contrato">
                                                    <input type="hidden" name="id_contrato_arriendo" value="<?php echo (int) ($row['id_contrato_arriendo'] ?? 0); ?>">
                                                    <input type="hidden" name="redirect_to" value="deuda_garantia/index.php">
                                                    <input type="hidden" name="motivo_cierre" value="">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm">Cerrar</button>
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

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                <div class="small text-muted">
                    Pagina <strong><?php echo $paginaActual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                </div>
                <?php if ($totalPaginas > 1): ?>
                    <nav aria-label="Paginacion deuda garantia">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildMsp2DeudaGarantiaQuery($queryBase, ['pagina' => max(1, $paginaActual - 1)]); ?>" aria-label="Anterior">&laquo;</a>
                            </li>
                            <?php foreach ($paginationItems as $item): ?>
                                <?php if ($item === 'ellipsis'): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php else: ?>
                                    <li class="page-item <?php echo (int) $item === $paginaActual ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo buildMsp2DeudaGarantiaQuery($queryBase, ['pagina' => $item]); ?>"><?php echo $item; ?></a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildMsp2DeudaGarantiaQuery($queryBase, ['pagina' => min($totalPaginas, $paginaActual + 1)]); ?>" aria-label="Siguiente">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    document.querySelectorAll('.js-form-cerrar-contrato').forEach((form) => {
        form.dataset.confirmTitle = 'Confirmar cierre de contrato';
        form.dataset.confirmVariant = 'danger';
        const ensureErrorBox = () => {
            let box = form.querySelector('.js-close-contract-error');
            if (!(box instanceof HTMLElement)) {
                box = document.createElement('div');
                box.className = 'alert alert-warning py-2 px-3 mt-2 mb-0 small js-close-contract-error';
                form.appendChild(box);
            }
            return box;
        };
        const clearError = () => {
            const box = form.querySelector('.js-close-contract-error');
            if (box instanceof HTMLElement) {
                box.remove();
            }
        };
        form.addEventListener('submit', (event) => {
            const motivoInput = form.querySelector('input[name="motivo_cierre"]');
            const motivoPrompt = window.prompt('Motivo de cierre del contrato:');
            const motivo = String(motivoPrompt ?? '').trim();

            if (motivo === '') {
                event.preventDefault();
                ensureErrorBox().textContent = 'Debes indicar un motivo para cerrar el contrato.';
                return;
            }

            if (motivo.length > 500) {
                event.preventDefault();
                ensureErrorBox().textContent = 'El motivo no puede superar 500 caracteres.';
                return;
            }

            clearError();
            if (motivoInput) {
                motivoInput.value = motivo;
            }
            form.dataset.confirmMessage = '¿Confirmar cierre de contrato? Se bloqueará si tiene cargos activos o reservas en garantía.';
        });
    });
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
