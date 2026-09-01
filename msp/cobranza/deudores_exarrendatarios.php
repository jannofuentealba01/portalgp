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

$buscar = trim((string) ($_GET['buscar'] ?? ''));
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
        $conditions[] = '(CAST(c.id_contrato_arriendo AS NVARCHAR(20)) LIKE :buscar_contrato
            OR a.nombre_locatario LIKE :buscar_arrendatario
            OR a.rut LIKE :buscar_rut
            OR t.nombre_comercial LIKE :buscar_tienda
            OR locales.locales LIKE :buscar_local)';
        $like = '%' . $buscar . '%';
        $params = [
            ':buscar_contrato' => $like,
            ':buscar_arrendatario' => $like,
            ':buscar_rut' => $like,
            ':buscar_tienda' => $like,
            ':buscar_local' => $like,
        ];
    }

    $sql = "SELECT TOP (250)
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css?v=<?php echo rawurlencode((string) filemtime(dirname(__DIR__, 2) . '/styles.css')); ?>">
    <style>
        .dex-shell{max-width:1640px;width:100%;margin:0 auto;font-family:"Segoe UI","Helvetica Neue",Arial,sans-serif}
        .dex-page-header{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:1rem;margin-bottom:.85rem}
        .dex-page-header h1{grid-column:2;grid-row:1;justify-self:center;margin:0;color:#003399;font-size:1.75rem;font-weight:600;line-height:1.2}
        .dex-page-back{grid-column:1;grid-row:1;justify-self:start}
        .dex-page-actions{grid-column:3;grid-row:1;justify-self:end;display:flex;flex-wrap:wrap;justify-content:flex-end;gap:.5rem}
        .dex-page-header .btn{min-height:34px;padding:.35rem .65rem;font-size:.825rem;line-height:1.2}
        .dex-filters{margin-bottom:.85rem}
        .dex-filters .form-label{margin-bottom:.25rem;font-size:.88rem}
        .dex-filters .form-control,.dex-filters .form-select,.dex-filters .btn{min-height:38px;padding-top:.38rem;padding-bottom:.38rem}
        .dex-kpis{margin-bottom:.85rem}
        .dex-kpi{height:100%;border:1px solid #dce5ef;border-radius:8px;background:#fff;padding:.65rem .75rem}
        .dex-kpi label{display:block;color:#64748b;font-size:.68rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
        .dex-kpi strong{display:block;margin-top:.15rem;color:#123f72;font-size:1.08rem;line-height:1.25}
        .dex-sub{color:#64748b;font-size:.75rem;line-height:1.2}
        .dex-list-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.35rem .75rem;margin-bottom:.35rem}
        .dex-list-head strong{font-size:1rem}
        .dex-table-wrap{overflow-x:auto;border-top:1px solid #dbe3ec}
        .dex-table{min-width:1120px;margin-bottom:0;border-collapse:collapse}
        .dex-table>:not(caption)>*>*{padding:.55rem .6rem;border:0;border-bottom:1px solid #dfe6ef;background:#fff}
        .dex-table thead th{background:#eef2f7;color:#172b4d;font-size:.82rem;font-weight:600;white-space:nowrap}
        .dex-table tbody tr:hover>*{background:#f8fafc}
        .dex-table td{vertical-align:middle;font-size:.86rem}
        .dex-table .tenant-name{font-weight:700}.dex-table .store{color:#64748b;font-size:.76rem;line-height:1.25}
        .dex-row-total{font-size:.95rem;font-weight:700;color:#9f1239}.dex-amount{white-space:nowrap}
        .dex-table .btn{padding:.3rem .48rem;font-size:.76rem;white-space:nowrap}
        @media(max-width:700px){.dex-page-header{display:flex;flex-direction:column;align-items:stretch}.dex-page-header h1{order:1;align-self:center}.dex-page-back{order:2}.dex-page-actions{order:3;justify-content:flex-start}.dex-page-actions .btn{flex:1 1 auto}.dex-list-head{align-items:flex-start}}
        @media print{.no-print,.msp-quick-access-hot-edge,.offcanvas,header,footer{display:none!important}.gp-main{padding:0!important}.dex-shell{max-width:none!important}.dex-table{min-width:0!important}}
    </style>
</head>
<body class="gp-layout bg-light">
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
                <button class="btn btn-outline-dark btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
            </div>
        </header>

        <form class="dex-filters no-print" method="get">
            <div class="row g-2 align-items-end">
                <div class="col-lg-7"><label class="form-label fw-semibold">Buscar por contrato, tienda, arrendatario, RUT o local</label><input class="form-control" name="buscar" value="<?php echo msp2Escape($buscar); ?>" placeholder="Ej.: 61, Yesenia, 17.647.451-3, óptica o A-5"></div>
                <div class="col-lg-3"><label class="form-label fw-semibold">Estado contractual</label><select class="form-select" name="estado"><option value="TODOS" <?php echo $estado === 'TODOS' ? 'selected' : ''; ?>>Todos con término operativo</option><option value="EN_CIERRE" <?php echo $estado === 'EN_CIERRE' ? 'selected' : ''; ?>>En proceso de cierre</option><option value="TERMINADO" <?php echo $estado === 'TERMINADO' ? 'selected' : ''; ?>>Terminados</option></select></div>
                <div class="col-lg-2 d-grid"><button class="btn btn-primary"><i class="bi bi-search me-1"></i>Buscar</button></div>
            </div>
        </form>

        <?php if ($error !== null): ?>
            <div class="alert alert-danger"><?php echo msp2Escape($error); ?></div>
        <?php else: ?>
            <div class="row g-2 dex-kpis">
                <div class="col-sm-6 col-xl"><div class="dex-kpi"><label>Casos pendientes</label><strong><?php echo (int) $totales['casos']; ?></strong><span class="dex-sub">máximo 250 resultados</span></div></div>
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
                    <table class="table table-hover mb-0 dex-table">
                        <thead class="table-light"><tr><th>Exarrendatario</th><th>Contrato / tienda</th><th>Término</th><th>Deuda documental</th><th>Cargos pendientes</th><th>Saldo residual</th><th>Garantía</th><th class="text-end">Acciones</th></tr></thead>
                        <tbody>
                        <?php if ($registros === []): ?>
                            <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-check-circle text-success me-1"></i>No hay exarrendatarios con saldo pendiente para los filtros indicados.</td></tr>
                        <?php else: foreach ($registros as $registro): ?>
                            <?php $idContrato = (int) $registro['id_contrato_arriendo']; ?>
                            <tr>
                                <td><div class="tenant-name"><?php echo msp2Escape((string) $registro['nombre_locatario']); ?></div><div class="store"><?php echo msp2Escape((string) ($registro['rut'] ?: '-')); ?></div></td>
                                <td><div><strong>#<?php echo $idContrato; ?></strong> <span class="badge text-bg-<?php echo (int) $registro['estado_contrato'] === 3 ? 'warning' : 'secondary'; ?>"><?php echo msp2Escape((string) $registro['estado_nombre']); ?></span></div><div class="store"><?php echo msp2Escape((string) $registro['nombre_comercial']); ?> · <?php echo msp2Escape((string) $registro['locales']); ?></div><?php if (!empty($registro['fecha_derivacion'])): ?><div class="store">Derivada el <?php echo msp2Escape(dexFecha((string) $registro['fecha_derivacion'])); ?></div><?php endif; ?></td>
                                <td class="dex-amount"><?php echo msp2Escape(dexFecha($registro['fecha_termino_efectiva'])); ?></td>
                                <td><div class="dex-amount"><?php echo msp2Escape(dexMonto($registro['saldo_documental'])); ?></div><div class="store"><?php echo (int) $registro['documentos_pendientes']; ?> documento(s)</div></td>
                                <td><div class="dex-amount"><?php echo msp2Escape(dexMonto($registro['saldo_cargos'])); ?></div><div class="store"><?php echo (int) $registro['cargos_pendientes']; ?> cargo(s)</div></td>
                                <td class="dex-row-total dex-amount"><?php echo msp2Escape(dexMonto($registro['saldo_residual'])); ?></td>
                                <td><div class="dex-amount text-success">Aplicada: <?php echo msp2Escape(dexMonto($registro['garantia_aplicada'])); ?></div><div class="dex-amount text-primary">Disponible: <?php echo msp2Escape(dexMonto($registro['garantia_disponible'])); ?></div></td>
                                <td class="text-end"><div class="btn-group btn-group-sm"><a class="btn btn-outline-dark" href="<?php echo msp2Escape(msp2Url('cobranza/deudor_historico.php?id_contrato=' . $idContrato)); ?>">Ver desglose</a><a class="btn btn-primary" href="<?php echo msp2Escape(msp2Url('cobranza/gestionar.php?id_contrato=' . $idContrato . '&return_to=cobranza/deudores_exarrendatarios.php')); ?>">Seguimiento</a><a class="btn btn-outline-secondary" href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?id_contrato_arriendo=' . $idContrato)); ?>" title="Ver ficha de contrato"><i class="bi bi-file-earmark-text"></i></a></div></td>
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
