<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

/**
 * Confirma tablas o vistas opcionales sin asumir que todos los parches históricos
 * están instalados. El módulo sigue mostrando la deuda documental aunque falte
 * algún complemento de garantía o cargos.
 */
function dexObjetoExiste(PDO $conn, string $nombre): bool
{
    static $cache = [];
    if (array_key_exists($nombre, $cache)) {
        return $cache[$nombre];
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*)
         FROM sys.objects o
         INNER JOIN sys.schemas s ON s.schema_id=o.schema_id
         WHERE s.name=N'dbo' AND o.name=:nombre AND o.type IN ('U','V')"
    );
    $stmt->execute([':nombre' => $nombre]);
    return $cache[$nombre] = (int) $stmt->fetchColumn() > 0;
}

function dexMonto(mixed $monto): string
{
    return '$ ' . number_format((float) ($monto ?? 0), 0, ',', '.');
}

function dexFecha(mixed $fecha): string
{
    $raw = trim((string) ($fecha ?? ''));
    if ($raw === '') {
        return '-';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($raw, 0, 10));
    return $date instanceof DateTimeImmutable ? $date->format('d-m-Y') : $raw;
}

$buscar = msp2SearchQuery($_GET['buscar'] ?? '');
$estado = strtoupper(trim((string) ($_GET['estado'] ?? 'TODOS')));
if (!in_array($estado, ['TODOS', 'EN_CIERRE', 'TERMINADO'], true)) {
    $estado = 'TODOS';
}

$registros = [];
$error = null;
$complementos = [
    'cargos' => dexObjetoExiste($conn, 'msp_cargos_contrato_local'),
    'garantias' => dexObjetoExiste($conn, 'msp_vw_garantias_control_integral'),
    'historico' => dexObjetoExiste($conn, 'msp_deudas_historicas'),
];

try {
    $historicoJoin = $complementos['historico']
        ? "LEFT JOIN dbo.msp_deudas_historicas dh
             ON dh.id_contrato_arriendo = c.id_contrato_arriendo
            AND dh.estado_deuda = N'ACTIVA'"
        : '';
    $historicoSelect = $complementos['historico']
        ? "dh.id_deuda_historica,
           dh.fecha_derivacion,
           CASE WHEN dh.id_deuda_historica IS NOT NULL THEN N'Deudor histórico' ELSE N'Pendiente de derivación' END AS estado_deuda_nombre"
        : "CAST(NULL AS BIGINT) AS id_deuda_historica,
           CAST(NULL AS DATETIME2) AS fecha_derivacion,
           N'Pendiente de derivación' AS estado_deuda_nombre";
    $estadoNombreSql = $complementos['historico']
        ? "CASE c.estado_contrato
                WHEN 3 THEN N'En proceso de cierre'
                WHEN 4 THEN CASE WHEN dh.id_deuda_historica IS NOT NULL THEN N'Deudor histórico' ELSE N'Terminado (pendiente de derivación)' END
                ELSE N'Sin estado'
           END AS estado_nombre"
        : "CASE c.estado_contrato WHEN 3 THEN N'En proceso de cierre' WHEN 4 THEN N'Terminado' ELSE N'Sin estado' END AS estado_nombre";
    $cargosSql = $complementos['cargos']
        ? "OUTER APPLY (
                SELECT
                    CAST(ISNULL(SUM(CASE
                        WHEN ccl.estado_cargo IN (1,2) AND ccl.id_documento_cobro IS NULL
                        THEN CASE
                            WHEN ISNULL(ccl.monto_cargo,0)-ISNULL(ccl.monto_aplicado_garantia,0)-ISNULL(ccl.monto_pagado_directo,0)>0
                            THEN ISNULL(ccl.monto_cargo,0)-ISNULL(ccl.monto_aplicado_garantia,0)-ISNULL(ccl.monto_pagado_directo,0)
                            ELSE 0
                        END
                        ELSE 0
                    END),0) AS DECIMAL(18,2)) AS saldo_cargos,
                    SUM(CASE WHEN ccl.estado_cargo IN (1,2) AND ccl.id_documento_cobro IS NULL
                             AND ISNULL(ccl.monto_cargo,0)-ISNULL(ccl.monto_aplicado_garantia,0)-ISNULL(ccl.monto_pagado_directo,0)>0
                        THEN 1 ELSE 0 END) AS cargos_pendientes
                FROM dbo.msp_cargos_contrato_local ccl
                INNER JOIN dbo.msp_contrato_locales ccl_rel ON ccl_rel.id_contrato_local=ccl.id_contrato_local
                WHERE ccl_rel.id_contrato_arriendo=c.id_contrato_arriendo
            ) cargos"
        : "CROSS APPLY (SELECT CAST(0 AS DECIMAL(18,2)) AS saldo_cargos, CAST(0 AS INT) AS cargos_pendientes) cargos";

    $garantiasSql = $complementos['garantias']
        ? "OUTER APPLY (
                SELECT
                    CAST(ISNULL(SUM(ISNULL(g.monto_aplicado,0)),0) AS DECIMAL(18,2)) AS garantia_aplicada,
                    CAST(ISNULL(SUM(ISNULL(g.monto_disponible,0)),0) AS DECIMAL(18,2)) AS garantia_disponible
                FROM dbo.msp_vw_garantias_control_integral g
                WHERE g.id_contrato_arriendo=c.id_contrato_arriendo
            ) garantia"
        : "CROSS APPLY (SELECT CAST(0 AS DECIMAL(18,2)) AS garantia_aplicada, CAST(0 AS DECIMAL(18,2)) AS garantia_disponible) garantia";

    $conditions = [
        'c.fecha_termino_efectiva IS NOT NULL',
        'c.estado_contrato IN (3,4)',
        '(ISNULL(documentos.saldo_documental,0)+ISNULL(cargos.saldo_cargos,0)) > 0.005',
    ];
    $params = [];
    if ($estado === 'EN_CIERRE') {
        $conditions[] = 'c.estado_contrato=3';
    } elseif ($estado === 'TERMINADO') {
        $conditions[] = 'c.estado_contrato=4';
    }
    if ($buscar !== '') {
        $search = msp2BuildSearchCondition($buscar, [
            'c.id_contrato_arriendo',
            'a.nombre_locatario',
            'a.rut',
            "REPLACE(REPLACE(REPLACE(a.rut,N'.',N''),N'-',N''),N' ',N'')",
            't.nombre_comercial',
            'locales.locales',
            "REPLACE(REPLACE(locales.locales,N'-',N''),N'.',N'')",
        ], 'cobranza_buscar', 'c.id_contrato_arriendo');
        $conditions[] = $search['sql'];
        $params = array_merge($params, $search['params']);
    }

    $sql = "SELECT
                c.id_contrato_arriendo,
                c.fecha_termino_efectiva,
                c.estado_contrato,
                a.id_arrendatario,
                a.nombre_locatario,
                a.rut,
                t.nombre_comercial,
                ISNULL(locales.locales,N'Sin local asociado') AS locales,
                ISNULL(locales.cantidad_locales,0) AS cantidad_locales,
                ISNULL(documentos.saldo_documental,0) AS saldo_documental,
                ISNULL(documentos.documentos_pendientes,0) AS documentos_pendientes,
                documentos.primer_vencimiento,
                ISNULL(cargos.saldo_cargos,0) AS saldo_cargos,
                ISNULL(cargos.cargos_pendientes,0) AS cargos_pendientes,
                ISNULL(garantia.garantia_aplicada,0) AS garantia_aplicada,
                ISNULL(garantia.garantia_disponible,0) AS garantia_disponible,
                CAST(ISNULL(documentos.saldo_documental,0)+ISNULL(cargos.saldo_cargos,0) AS DECIMAL(18,2)) AS saldo_residual,
                {$historicoSelect},
                {$estadoNombreSql}
            FROM dbo.msp_contratos_arriendo c
            INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
            INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
            {$historicoJoin}
            OUTER APPLY (
                SELECT
                    STRING_AGG(l.cdo_local,N' / ') WITHIN GROUP (ORDER BY cl.orden_visual,l.cdo_local) AS locales,
                    COUNT(*) AS cantidad_locales
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local
                WHERE cl.id_contrato_arriendo=c.id_contrato_arriendo
            ) locales
            OUTER APPLY (
                SELECT
                    CAST(ISNULL(SUM(CASE WHEN dc.estado_documento IN (2,3) AND ISNULL(dc.saldo_pendiente,0)>0.005
                        THEN dc.saldo_pendiente ELSE 0 END),0) AS DECIMAL(18,2)) AS saldo_documental,
                    SUM(CASE WHEN dc.estado_documento IN (2,3) AND ISNULL(dc.saldo_pendiente,0)>0.005 THEN 1 ELSE 0 END) AS documentos_pendientes,
                    MIN(CASE WHEN dc.estado_documento IN (2,3) AND ISNULL(dc.saldo_pendiente,0)>0.005
                        THEN dc.fecha_vencimiento END) AS primer_vencimiento
                FROM dbo.msp_documentos_cobro dc
                OUTER APPLY (
                    SELECT TOP (1) c_hist.id_contrato_arriendo
                    FROM dbo.msp_contratos_arriendo c_hist
                    WHERE c_hist.id_tienda=dc.id_tienda
                      AND c_hist.fecha_inicio<=EOMONTH(dc.periodo_facturacion)
                      AND (c_hist.fecha_termino_efectiva IS NULL OR c_hist.fecha_termino_efectiva>=dc.periodo_facturacion)
                      AND c_hist.estado_contrato IN (1,2,3,4)
                    ORDER BY c_hist.fecha_inicio DESC,c_hist.id_contrato_arriendo DESC
                ) contrato_documento
                WHERE COALESCE(dc.id_contrato_arriendo,contrato_documento.id_contrato_arriendo)=c.id_contrato_arriendo
            ) documentos
            {$cargosSql}
            {$garantiasSql}
            WHERE " . implode("\n              AND ", $conditions) . "
            ORDER BY saldo_residual DESC,c.fecha_termino_efectiva DESC,c.id_contrato_arriendo DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $exception) {
    $error = 'No fue posible cargar el listado de deudores exarrendatarios.';
}

$totales = ['casos' => count($registros), 'saldo' => 0.0, 'documentos' => 0.0, 'cargos' => 0.0, 'garantia_aplicada' => 0.0, 'garantia_disponible' => 0.0];
foreach ($registros as $registro) {
    $totales['saldo'] += (float) $registro['saldo_residual'];
    $totales['documentos'] += (float) $registro['saldo_documental'];
    $totales['cargos'] += (float) $registro['saldo_cargos'];
    $totales['garantia_aplicada'] += (float) $registro['garantia_aplicada'];
    $totales['garantia_disponible'] += (float) $registro['garantia_disponible'];
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Deudores exarrendatarios | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css?v=<?php echo rawurlencode((string) filemtime(dirname(__DIR__, 2) . '/styles.css')); ?>">
</head>
<body class="gp-layout bg-light gp-module-msp">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-xl-4">
    <div class="dex-shell">
        <header class="dex-page-header no-print">
            <div class="dex-page-back">
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('cierre/index.php')); ?>"><i class="bi bi-arrow-left me-1"></i>Volver a término y cierre</a>
            </div>
            <h1>Deudores exarrendatarios</h1>
            <div class="dex-page-actions">
                <a class="btn btn-outline-primary btn-sm" href="<?php echo msp2Escape(msp2Url('contabilidad/aging.php')); ?>"><i class="bi bi-bar-chart-line me-1"></i>Ver Aging general</a>
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
            </div>
        </header>

        <form class="dex-filters no-print gp-filter-bar" method="get">
            <div class="row g-2 align-items-end">
                <div class="col-lg-7"><label class="form-label fw-semibold">Buscar por contrato, tienda, arrendatario, RUT o local</label><input type="search" class="form-control" name="buscar" value="<?php echo msp2Escape($buscar); ?>" placeholder="Ej.: óptica A-5, nombre y tienda, o #61"></div>
                <div class="col-lg-3"><label class="form-label fw-semibold">Estado contractual</label><select class="form-select" name="estado"><option value="TODOS" <?php echo $estado === 'TODOS' ? 'selected' : ''; ?>>Todos con término operativo</option><option value="EN_CIERRE" <?php echo $estado === 'EN_CIERRE' ? 'selected' : ''; ?>>En proceso de cierre</option><option value="TERMINADO" <?php echo $estado === 'TERMINADO' ? 'selected' : ''; ?>>Terminados</option></select></div>
                <div class="col-lg-2 d-flex gap-2" data-gp-filter-actions><button class="btn btn-primary flex-grow-1"><i class="bi bi-search me-1"></i>Buscar</button><?php if ($buscar !== '' || $estado !== 'TODOS'): ?><a class="btn btn-outline-secondary" href="<?php echo msp2Escape(msp2Url('cobranza/deudores_exarrendatarios.php')); ?>" title="Limpiar filtros" aria-label="Limpiar filtros"><i class="bi bi-x-lg"></i></a><?php endif; ?></div>
            </div>
        </form>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger"><?php echo msp2Escape($error); ?></div>
        <?php else: ?>
            <div class="row g-2 dex-kpis">
                <div class="col-sm-6 col-xl"><div class="dex-kpi"><label>Casos pendientes</label><strong><?php echo (int) $totales['casos']; ?></strong><span class="dex-sub">resultados encontrados</span></div></div>
                <div class="col-sm-6 col-xl"><div class="dex-kpi"><label>Deuda residual</label><strong><?php echo msp2Escape(dexMonto($totales['saldo'])); ?></strong><span class="dex-sub">documentos y cargos pendientes</span></div></div>
                <div class="col-sm-6 col-xl"><div class="dex-kpi"><label>Garantía aplicada</label><strong><?php echo msp2Escape(dexMonto($totales['garantia_aplicada'])); ?></strong><span class="dex-sub">ya usada para cubrir deuda</span></div></div>
                <div class="col-sm-6 col-xl"><div class="dex-kpi"><label>Garantía disponible</label><strong><?php echo msp2Escape(dexMonto($totales['garantia_disponible'])); ?></strong><span class="dex-sub">aún no aplicada</span></div></div>
            </div>

            <?php if (!$complementos['cargos'] || !$complementos['garantias']): ?>
                <div class="alert alert-warning small">Algunos complementos no están disponibles en este ambiente: <?php echo !$complementos['cargos'] ? 'cargos por contrato/local' : ''; ?><?php echo (!$complementos['cargos'] && !$complementos['garantias']) ? ' y ' : ''; ?><?php echo !$complementos['garantias'] ? 'control integral de garantías' : ''; ?>. La deuda documental se mantiene visible.</div>
            <?php endif; ?>

            <section>
                <div class="dex-list-head"><strong>Listado de deuda residual</strong><span class="text-muted small">La garantía disponible no se descuenta hasta que sea aplicada formalmente.</span></div>
                <div class="dex-table-wrap">
                    <table class="table table-hover mb-0 dex-table gp-table-compact gp-table-mobile-cards">
                        <thead class="table-light"><tr><th>Exarrendatario</th><th>Contrato / tienda / locales</th><th>Deuda documental / cargos</th><th>Saldo / garantía</th><th class="text-end">Acciones</th></tr></thead>
                        <tbody>
                        <?php if ($registros === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-check-circle text-success me-1"></i>No hay exarrendatarios con saldo pendiente para los filtros indicados.</td></tr>
                        <?php else: foreach ($registros as $registro): ?>
                            <?php $idContrato = (int) $registro['id_contrato_arriendo']; ?>
                            <tr>
                                <td><div class="tenant-name"><?php echo msp2Escape((string) $registro['nombre_locatario']); ?></div><div class="store"><?php echo msp2Escape((string) ($registro['rut'] ?: '-')); ?></div></td>
                                <td><div><strong>#<?php echo $idContrato; ?></strong> <span class="badge text-bg-<?php echo (int) $registro['estado_contrato'] === 3 ? 'warning' : 'secondary'; ?>"><?php echo msp2Escape((string) $registro['estado_nombre']); ?></span></div><div class="store"><?php echo msp2Escape((string) $registro['nombre_comercial']); ?> · <?php echo msp2Escape((string) $registro['locales']); ?></div><div class="store">Término: <?php echo msp2Escape(dexFecha($registro['fecha_termino_efectiva'])); ?></div><?php if (!empty($registro['fecha_derivacion'])): ?><div class="store">Derivada el <?php echo msp2Escape(dexFecha((string) $registro['fecha_derivacion'])); ?></div><?php endif; ?></td>
                                <td><div class="gp-data-pair"><span>Documentos (<?php echo (int) $registro['documentos_pendientes']; ?>)</span><strong><?php echo msp2Escape(dexMonto($registro['saldo_documental'])); ?></strong></div><div class="gp-data-pair"><span>Cargos (<?php echo (int) $registro['cargos_pendientes']; ?>)</span><strong><?php echo msp2Escape(dexMonto($registro['saldo_cargos'])); ?></strong></div></td>
                                <td><div class="dex-row-total dex-amount">Saldo: <?php echo msp2Escape(dexMonto($registro['saldo_residual'])); ?></div><div class="store text-success">Garantía aplicada: <?php echo msp2Escape(dexMonto($registro['garantia_aplicada'])); ?></div><div class="store text-primary">Garantía disponible: <?php echo msp2Escape(dexMonto($registro['garantia_disponible'])); ?></div></td>
                                <td class="text-end"><div class="dex-actions"><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/deudor_historico.php?id_contrato=' . $idContrato)); ?>">Desglose</a><a class="btn btn-primary btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/gestionar.php?id_contrato=' . $idContrato . '&return_to=cobranza/deudores_exarrendatarios.php')); ?>">Seguimiento</a><a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?id_contrato_arriendo=' . $idContrato)); ?>" title="Ver ficha de contrato" aria-label="Ver ficha de contrato"><i class="bi bi-file-earmark-text"></i></a></div></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
