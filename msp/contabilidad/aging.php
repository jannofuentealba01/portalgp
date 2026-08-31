<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();
$flash = msp2PullFlash();
$loadError = null;
$tablaExiste = false;

$filtroPeriodo = trim((string) ($_GET['periodo'] ?? 'all'));
if ($filtroPeriodo !== 'all' && preg_match('/^\d{4}-\d{2}$/', $filtroPeriodo) !== 1) {
    $filtroPeriodo = 'all';
}

$hoy = date('Y-m-d');
$corteAging = trim((string) ($_GET['corte_aging'] ?? $hoy));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $corteAging) !== 1) {
    $corteAging = $hoy;
}
$corteDocumentos = '';

$periodosDisponibles = [];
$resumen = ['total' => 0.0, 'b0_30' => 0.0, 'b31_60' => 0.0, 'b61_90' => 0.0, 'b91_plus' => 0.0];
$agingChartLabels = ['0-30 días', '31-60 días', '61-90 días', '91+ días'];
$agingChartData = [0.0, 0.0, 0.0, 0.0];
$filas = [];
$detallePorGrupo = [];
$localesPorArrendatario = [];
$localesPorDocumento = [];

function agFmtMonto(mixed $v): string
{
    return '$ ' . number_format((float) ($v ?? 0), 2, ',', '.');
}

function agFmtDias(mixed $v): string
{
    return number_format((int) ($v ?? 0), 0, ',', '.');
}

function agFmtFecha(?string $value): string
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

function agLocalSortTuple(string $raw): array
{
    $code = strtoupper(trim($raw));
    if ($code === '') {
        return [5, 999, '', 999999, '', $code];
    }

    // Formato bloque-letra-numero, p.ej. A-1, B-12, A-3A.
    if (preg_match('/^([A-Z])-([0-9]+)([A-Z]?)$/', $code, $m) === 1) {
        $block = $m[1];
        $num = (int) $m[2];
        $suffix = $m[3] ?? '';
        return [0, ord($block), $block, $num, $suffix, $code];
    }

    // Letra sola.
    if (preg_match('/^[A-Z]$/', $code) === 1) {
        return [1, ord($code), $code, 0, '', $code];
    }

    // Numérico puro (89..142, etc.).
    if (preg_match('/^[0-9]+$/', $code) === 1) {
        return [2, (int) $code, '', (int) $code, '', $code];
    }

    // Casos de cola nominal, alineado con regla SQL.
    $namedRank = match (true) {
        $code === 'PELUQUERIA' => 0,
        $code === 'GYM' => 1,
        $code === 'OBRA' => 2,
        $code === 'MODULAR' => 3,
        str_starts_with($code, 'ESPACIO') => 4,
        default => 999,
    };
    if ($namedRank !== 999) {
        return [3, $namedRank, '', 0, '', $code];
    }

    return [4, 999, '', 999999, '', $code];
}

function agLocalSortCompare(string $a, string $b): int
{
    $ka = agLocalSortTuple($a);
    $kb = agLocalSortTuple($b);
    $len = min(count($ka), count($kb));
    for ($i = 0; $i < $len; $i++) {
        if ($ka[$i] === $kb[$i]) {
            continue;
        }
        return ($ka[$i] <=> $kb[$i]);
    }
    return 0;
}

try {
    $required = ['msp_documentos_cobro', 'msp_tiendas', 'msp_arrendatarios'];
    $missing = [];
    foreach ($required as $t) {
        if (!msp2TableExists($conn, $t)) {
            $missing[] = $t;
        }
    }
    $tablaExiste = $missing === [];
    if (!$tablaExiste) {
        $loadError = 'Faltan tablas para Aging: `' . implode('`, `', $missing) . '`.';
    }
} catch (PDOException $e) {
    $loadError = 'No fue posible validar estructura de Aging.';
}

if ($tablaExiste) {
    try {
        $periodosDisponibles = $conn->query(
            "SELECT DISTINCT CONVERT(CHAR(7), periodo_facturacion, 126) AS periodo_ym
             FROM dbo.msp_documentos_cobro
             WHERE estado_documento <> 5
             ORDER BY periodo_ym DESC"
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($filtroPeriodo !== 'all' && !in_array($filtroPeriodo, $periodosDisponibles, true)) {
            $filtroPeriodo = 'all';
        }

        if ($filtroPeriodo !== 'all') {
            $dtCorteMes = DateTimeImmutable::createFromFormat('Y-m-d', $filtroPeriodo . '-01');
            $corteDocumentos = $dtCorteMes ? $dtCorteMes->modify('last day of this month')->format('Y-m-d') : $hoy;
        } else {
            $corteDocumentos = $corteAging;
        }

        $wherePeriodo = '1=1';
        $paramsBase = [':corte_documentos' => $corteDocumentos];
        if ($filtroPeriodo !== 'all') {
            $wherePeriodo = 'dc.periodo_facturacion = :periodo';
            $paramsBase[':periodo'] = $filtroPeriodo . '-01';
        }

        $sqlBase =
            "FROM dbo.msp_documentos_cobro dc
             INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
             INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = t.id_arrendatario
             WHERE {$wherePeriodo}
               AND dc.fecha_emision <= :corte_documentos
               AND dc.estado_documento <> 5
               AND dc.saldo_pendiente > 0";

        $stmtResumen = $conn->prepare(
            "SELECT
                ROUND(ISNULL(SUM(dc.saldo_pendiente), 0), 2) AS total,
                ROUND(ISNULL(SUM(CASE WHEN DATEDIFF(DAY, dc.fecha_vencimiento, :corte_1) BETWEEN 0 AND 30 THEN dc.saldo_pendiente ELSE 0 END), 0), 2) AS b0_30,
                ROUND(ISNULL(SUM(CASE WHEN DATEDIFF(DAY, dc.fecha_vencimiento, :corte_2) BETWEEN 31 AND 60 THEN dc.saldo_pendiente ELSE 0 END), 0), 2) AS b31_60,
                ROUND(ISNULL(SUM(CASE WHEN DATEDIFF(DAY, dc.fecha_vencimiento, :corte_3) BETWEEN 61 AND 90 THEN dc.saldo_pendiente ELSE 0 END), 0), 2) AS b61_90,
                ROUND(ISNULL(SUM(CASE WHEN DATEDIFF(DAY, dc.fecha_vencimiento, :corte_4) > 90 THEN dc.saldo_pendiente ELSE 0 END), 0), 2) AS b91_plus
             {$sqlBase}"
        );
        foreach ($paramsBase as $k => $v) {
            $stmtResumen->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmtResumen->bindValue(':corte_1', $corteAging, PDO::PARAM_STR);
        $stmtResumen->bindValue(':corte_2', $corteAging, PDO::PARAM_STR);
        $stmtResumen->bindValue(':corte_3', $corteAging, PDO::PARAM_STR);
        $stmtResumen->bindValue(':corte_4', $corteAging, PDO::PARAM_STR);
        $stmtResumen->execute();
        $resumenRow = $stmtResumen->fetch();
        if (is_array($resumenRow)) {
            $resumen = array_merge($resumen, $resumenRow);
        }
        $agingChartData = [
            (float) ($resumen['b0_30'] ?? 0),
            (float) ($resumen['b31_60'] ?? 0),
            (float) ($resumen['b61_90'] ?? 0),
            (float) ($resumen['b91_plus'] ?? 0),
        ];

        $stmtAgrupado = $conn->prepare(
            "SELECT
                a.id_arrendatario,
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut, CONCAT(N'Arrendatario #', a.id_arrendatario)) AS nombre_arrendatario,
                a.rut,
                COUNT(*) AS docs,
                ROUND(SUM(dc.saldo_pendiente), 2) AS total,
                ROUND(SUM(CASE WHEN DATEDIFF(DAY, dc.fecha_vencimiento, :corte_1) BETWEEN 0 AND 30 THEN dc.saldo_pendiente ELSE 0 END), 2) AS b0_30,
                ROUND(SUM(CASE WHEN DATEDIFF(DAY, dc.fecha_vencimiento, :corte_2) BETWEEN 31 AND 60 THEN dc.saldo_pendiente ELSE 0 END), 2) AS b31_60,
                ROUND(SUM(CASE WHEN DATEDIFF(DAY, dc.fecha_vencimiento, :corte_3) BETWEEN 61 AND 90 THEN dc.saldo_pendiente ELSE 0 END), 2) AS b61_90,
                ROUND(SUM(CASE WHEN DATEDIFF(DAY, dc.fecha_vencimiento, :corte_4) > 90 THEN dc.saldo_pendiente ELSE 0 END), 2) AS b91_plus
             {$sqlBase}
             GROUP BY a.id_arrendatario, a.nombre_locatario, a.nombre_representante, a.rut
             ORDER BY total DESC, nombre_arrendatario ASC"
        );
        foreach ($paramsBase as $k => $v) {
            $stmtAgrupado->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmtAgrupado->bindValue(':corte_1', $corteAging, PDO::PARAM_STR);
        $stmtAgrupado->bindValue(':corte_2', $corteAging, PDO::PARAM_STR);
        $stmtAgrupado->bindValue(':corte_3', $corteAging, PDO::PARAM_STR);
        $stmtAgrupado->bindValue(':corte_4', $corteAging, PDO::PARAM_STR);
        $stmtAgrupado->execute();
        $filas = $stmtAgrupado->fetchAll();

        $stmtDetalle = $conn->prepare(
            "SELECT
                a.id_arrendatario,
                dc.id_documento_cobro,
                COALESCE(dc.numero_documento, CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                dc.periodo_facturacion,
                dc.fecha_emision,
                dc.fecha_vencimiento,
                DATEDIFF(DAY, dc.fecha_vencimiento, :corte_det) AS dias_atraso,
                dc.monto_total,
                dc.saldo_pendiente,
                COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda
             {$sqlBase}
             ORDER BY a.id_arrendatario ASC, dc.fecha_vencimiento ASC, dc.id_documento_cobro ASC"
        );
        foreach ($paramsBase as $k => $v) {
            $stmtDetalle->bindValue($k, $v, PDO::PARAM_STR);
        }
        $stmtDetalle->bindValue(':corte_det', $corteAging, PDO::PARAM_STR);
        $stmtDetalle->execute();
        foreach ($stmtDetalle->fetchAll() as $d) {
            $arrId = (int) ($d['id_arrendatario'] ?? 0);
            if (!isset($detallePorGrupo[$arrId])) {
                $detallePorGrupo[$arrId] = [];
            }
            $detallePorGrupo[$arrId][] = $d;
        }

        $docIds = [];
        $arrIds = [];
        foreach ($detallePorGrupo as $arrId => $rowsArr) {
            $arrIds[] = (int) $arrId;
            foreach ($rowsArr as $rowDoc) {
                $docId = (int) ($rowDoc['id_documento_cobro'] ?? 0);
                if ($docId > 0) {
                    $docIds[$docId] = $docId;
                }
            }
        }

        if ($docIds !== []) {
            $docIds = array_values($docIds);
            $docPlaceholders = implode(', ', array_fill(0, count($docIds), '?'));
            $stmtLocDoc = $conn->prepare(
                "WITH detalle_local AS (
                    SELECT
                        dcd.id_documento_cobro,
                        COALESCE(
                            loc_serv.cdo_local,
                            CASE
                                WHEN tid.codigo_item = N'ARRIENDO'
                                 AND dcd.descripcion_item LIKE N'Arriendo local %'
                                    THEN LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo local ') + 1, 200))
                                ELSE NULL
                            END,
                            N'SIN LOCAL'
                        ) AS cdo_local
                    FROM dbo.msp_documentos_cobro_detalle dcd
                    INNER JOIN dbo.msp_tipo_item_documento tid
                        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                    LEFT JOIN dbo.msp_cobros_servicios cs
                        ON cs.id_cobro_servicio = dcd.id_cobro_servicio
                    LEFT JOIN dbo.msp_lecturas_medidores lm
                        ON lm.id_lectura = cs.id_lectura
                    LEFT JOIN dbo.msp_medidores m
                        ON m.id_medidor = lm.id_medidor
                    LEFT JOIN dbo.msp_locales loc_serv
                        ON loc_serv.id_local = m.id_local
                    WHERE dcd.id_documento_cobro IN ($docPlaceholders)
                )
                SELECT DISTINCT
                    id_documento_cobro,
                    cdo_local
                FROM detalle_local"
            );
            foreach ($docIds as $idx => $docId) {
                $stmtLocDoc->bindValue($idx + 1, $docId, PDO::PARAM_INT);
            }
            $stmtLocDoc->execute();
            foreach ($stmtLocDoc->fetchAll() as $rowLocalDoc) {
                $docId = (int) ($rowLocalDoc['id_documento_cobro'] ?? 0);
                $cdo = trim((string) ($rowLocalDoc['cdo_local'] ?? 'SIN LOCAL'));
                if ($docId <= 0 || $cdo === '') {
                    continue;
                }
                if (!isset($localesPorDocumento[$docId])) {
                    $localesPorDocumento[$docId] = [];
                }
                $localesPorDocumento[$docId][$cdo] = $cdo;
            }
            foreach ($localesPorDocumento as $docId => $mapLocal) {
                $codes = array_values($mapLocal);
                usort($codes, static fn(string $a, string $b): int => agLocalSortCompare($a, $b));
                $localesPorDocumento[$docId] = $codes;
            }

            if ($arrIds !== []) {
                foreach ($arrIds as $arrId) {
                    $set = [];
                    foreach (($detallePorGrupo[$arrId] ?? []) as $docRow) {
                        $docId = (int) ($docRow['id_documento_cobro'] ?? 0);
                        foreach (($localesPorDocumento[$docId] ?? []) as $cdo) {
                            $set[$cdo] = $cdo;
                        }
                    }
                    $codes = array_values($set);
                    usort($codes, static fn(string $a, string $b): int => agLocalSortCompare($a, $b));
                    $localesPorArrendatario[(int) $arrId] = $codes;
                }
            }
        }

        if ($filas !== []) {
            usort(
                $filas,
                static function (array $a, array $b) use ($localesPorArrendatario): int {
                    $arrA = (int) ($a['id_arrendatario'] ?? 0);
                    $arrB = (int) ($b['id_arrendatario'] ?? 0);
                    $firstA = (string) (($localesPorArrendatario[$arrA][0] ?? 'ZZZ'));
                    $firstB = (string) (($localesPorArrendatario[$arrB][0] ?? 'ZZZ'));
                    $cmpLocal = agLocalSortCompare($firstA, $firstB);
                    if ($cmpLocal !== 0) {
                        return $cmpLocal;
                    }

                    return strcasecmp(
                        (string) ($a['nombre_arrendatario'] ?? ''),
                        (string) ($b['nombre_arrendatario'] ?? '')
                    );
                }
            );
        }
    } catch (PDOException $e) {
        $loadError = 'No fue posible cargar Aging. Detalle: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Aging Deudores</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .ag-parent { background: #eef4fb; cursor: pointer; font-weight: 600; }
        .ag-parent:hover { background: #e2ecfa; }
        .ag-child td { border-top: 0; }
        .ag-hide { display: none; }
        .ag-subtable th { font-size: .75rem; color: #64748b; text-transform: uppercase; }
        .ag-toggle {
            display: inline-flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            border: 1px solid #cbd5e1;
            border-radius: 999px;
            background: #fff;
            margin-right: 8px;
            font-size: 12px;
        }
        .ag-parent[aria-expanded="true"] .ag-toggle { transform: rotate(180deg); }
        .ag-doc-link {
            color: #1d4ed8;
            text-decoration: underline;
            text-underline-offset: 2px;
            font-weight: 600;
        }
        .ag-col-main { width: 28%; min-width: 240px; }
        .ag-arr-name {
            display: inline-block;
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: bottom;
        }
        .ag-locales {
            display: inline-block;
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: bottom;
        }
        @media (max-width: 992px) {
            .ag-col-main { width: 40%; min-width: 220px; }
            .ag-arr-name, .ag-locales { max-width: 220px; }
        }
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-md-4">
    <div class="box-container-full mx-auto" style="max-width: 1400px;">
        <div class="d-flex align-items-center mb-3">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>">Volver a MSP</a>
        </div>

        <p class="section-kicker text-center">MSP / Contabilidad</p>
        <h1 class="form-title text-center mb-2">Aging de Deudores</h1>
        <p class="text-muted text-center mb-4">Cartera vencida por tramos de atraso, con resumen y detalle por documento.</p>

        <?php msp2RenderFlash($flash); ?>
        <?php if ($loadError !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div><?php endif; ?>

        <?php if ($tablaExiste && $loadError === null): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <form class="row g-2 align-items-end" method="get">
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="periodo">Periodo</label>
                            <select class="form-select" name="periodo" id="periodo">
                                <option value="all" <?php echo $filtroPeriodo === 'all' ? 'selected' : ''; ?>>Todos</option>
                                <?php foreach ($periodosDisponibles as $p): ?>
                                    <option value="<?php echo msp2Escape((string) $p); ?>" <?php echo $filtroPeriodo === $p ? 'selected' : ''; ?>><?php echo msp2Escape((string) $p); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="corte_documentos">Fecha corte documentos</label>
                            <input
                                class="form-control"
                                type="date"
                                name="corte_documentos"
                                id="corte_documentos"
                                value="<?php echo msp2Escape($corteDocumentos); ?>"
                                readonly>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label" for="corte_aging">Aging al día</label>
                            <input
                                class="form-control"
                                type="date"
                                name="corte_aging"
                                id="corte_aging"
                                value="<?php echo msp2Escape($corteAging); ?>">
                        </div>
                        <div class="col-12 col-md-auto">
                            <button class="btn btn-primary" type="submit">Aplicar</button>
                        </div>
                        <div class="col-12 col-md-auto">
                            <?php
                                $pdfParams = [
                                    'periodo' => $filtroPeriodo,
                                    'corte_documentos' => $corteDocumentos,
                                    'corte_aging' => $corteAging,
                                ];
                            ?>
                            <a
                                class="btn btn-outline-primary"
                                target="_blank"
                                rel="noopener"
                                href="<?php echo msp2Escape(msp2Url('contabilidad/aging_pdf.php?' . http_build_query($pdfParams))); ?>">
                                <i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir / PDF
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6 col-md"><div class="card p-2"><small class="text-muted">Total</small><div class="fw-bold"><?php echo msp2Escape(agFmtMonto($resumen['total'] ?? 0)); ?></div></div></div>
                <div class="col-6 col-md"><div class="card p-2"><small class="text-muted">0-30</small><div class="fw-bold"><?php echo msp2Escape(agFmtMonto($resumen['b0_30'] ?? 0)); ?></div></div></div>
                <div class="col-6 col-md"><div class="card p-2"><small class="text-muted">31-60</small><div class="fw-bold"><?php echo msp2Escape(agFmtMonto($resumen['b31_60'] ?? 0)); ?></div></div></div>
                <div class="col-6 col-md"><div class="card p-2"><small class="text-muted">61-90</small><div class="fw-bold"><?php echo msp2Escape(agFmtMonto($resumen['b61_90'] ?? 0)); ?></div></div></div>
                <div class="col-6 col-md"><div class="card p-2"><small class="text-muted">91+</small><div class="fw-bold"><?php echo msp2Escape(agFmtMonto($resumen['b91_plus'] ?? 0)); ?></div></div></div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <h2 class="h6 mb-3">Montos por clasificación</h2>
                    <div style="height: 280px;">
                        <canvas id="agingClasificacionChart" aria-label="Gráfico de clasificación de aging" role="img"></canvas>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th class="ag-col-main">Locales / Arrendatario</th>
                                <th class="text-end">Docs</th>
                                <th class="text-end">0-30</th>
                                <th class="text-end">31-60</th>
                                <th class="text-end">61-90</th>
                                <th class="text-end">91+</th>
                                <th class="text-end">Saldo</th>
                                <th class="text-center">Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filas)): ?>
                                <tr><td colspan="8" class="text-center text-muted py-3">Sin deudores para el filtro.</td></tr>
                            <?php else: ?>
                                <?php $ix = 0; foreach ($filas as $f): $ix++; $arrId = (int) ($f['id_arrendatario'] ?? 0); ?>
                                    <tr class="ag-parent" data-g="<?php echo $ix; ?>" aria-expanded="false">
                                        <td>
                                            <?php
                                                $localesTxt = implode(' / ', $localesPorArrendatario[$arrId] ?? ['SIN LOCAL']);
                                                $nombreArr = (string) ($f['nombre_arrendatario'] ?? '');
                                            ?>
                                            <div class="small text-muted ag-locales" title="<?php echo msp2Escape($localesTxt); ?>">
                                                <?php echo msp2Escape($localesTxt); ?>
                                            </div>
                                            <span class="ag-arr-name" title="<?php echo msp2Escape($nombreArr); ?>"><?php echo msp2Escape($nombreArr); ?></span>
                                            <small class="text-muted"><?php echo msp2Escape(msp2RutFormatDisplay((string) ($f['rut'] ?? ''))); ?></small>
                                        </td>
                                        <td class="text-end"><?php echo (int) ($f['docs'] ?? 0); ?></td>
                                        <td class="text-end"><?php echo msp2Escape(agFmtMonto($f['b0_30'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo msp2Escape(agFmtMonto($f['b31_60'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo msp2Escape(agFmtMonto($f['b61_90'] ?? 0)); ?></td>
                                        <td class="text-end"><?php echo msp2Escape(agFmtMonto($f['b91_plus'] ?? 0)); ?></td>
                                        <td class="text-end fw-bold"><?php echo msp2Escape(agFmtMonto($f['total'] ?? 0)); ?></td>
                                        <td class="text-center"><span class="ag-toggle"><i class="bi bi-chevron-down"></i></span></td>
                                    </tr>
                                    <tr class="ag-child ag-hide ag-g-<?php echo $ix; ?>">
                                        <td colspan="8" class="ps-4">
                                            <?php $detalles = $detallePorGrupo[$arrId] ?? []; ?>
                                            <?php if ($detalles === []): ?>
                                                <div class="text-muted">Sin documentos pendientes para este arrendatario.</div>
                                            <?php else: ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm ag-subtable mb-0">
                                                        <thead>
                                                            <tr>
                                                                <th>Locales</th>
                                                                <th>Tipo</th>
                                                                <th>Fecha Emisión</th>
                                                                <th>Fecha Vencimiento</th>
                                                                <th>N° Documento</th>
                                                                <th class="text-end">Días Atraso</th>
                                                                <th class="text-end">Monto Documento</th>
                                                                <th class="text-end">Saldo Pendiente</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($detalles as $d): ?>
                                                                <?php
                                                                    $docId = (int) ($d['id_documento_cobro'] ?? 0);
                                                                    $periodoDocRaw = (string) ($d['periodo_facturacion'] ?? '');
                                                                    $periodoDocYm = preg_match('/^\d{4}-\d{2}/', $periodoDocRaw) === 1
                                                                        ? substr($periodoDocRaw, 0, 7)
                                                                        : ($filtroPeriodo !== 'all' ? $filtroPeriodo : '');
                                                                    $docPortalUrl = msp2Url(
                                                                        'documentos_cobro/index.php?' . http_build_query([
                                                                            'id_arrendatario' => $arrId,
                                                                            'filtroPeriodo' => $periodoDocYm,
                                                                        ])
                                                                    );
                                                                    $dias = max(0, (int) ($d['dias_atraso'] ?? 0));
                                                                    $localesDoc = implode(' / ', $localesPorDocumento[$docId] ?? ['SIN LOCAL']);
                                                                ?>
                                                                <tr>
                                                                    <td><?php echo msp2Escape($localesDoc); ?></td>
                                                                    <td>Factura</td>
                                                                    <td><?php echo msp2Escape(agFmtFecha((string) ($d['fecha_emision'] ?? ''))); ?></td>
                                                                    <td><?php echo msp2Escape(agFmtFecha((string) ($d['fecha_vencimiento'] ?? ''))); ?></td>
                                                                    <td><a class="ag-doc-link" href="<?php echo msp2Escape($docPortalUrl); ?>" target="_blank" rel="noopener"><?php echo msp2Escape((string) ($d['numero_documento'] ?? '')); ?></a></td>
                                                                    <td class="text-end"><?php echo msp2Escape(agFmtDias($dias)); ?></td>
                                                                    <td class="text-end"><?php echo msp2Escape(agFmtMonto($d['monto_total'] ?? 0)); ?></td>
                                                                    <td class="text-end fw-semibold"><?php echo msp2Escape(agFmtMonto($d['saldo_pendiente'] ?? 0)); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php endif; ?>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
document.querySelectorAll('.ag-parent').forEach(function (r) {
  r.addEventListener('click', function () {
    const g = r.getAttribute('data-g');
    const open = r.getAttribute('aria-expanded') === 'true';
    r.setAttribute('aria-expanded', open ? 'false' : 'true');
    document.querySelectorAll('.ag-g-' + g).forEach(function (child) { child.classList.toggle('ag-hide', open); });
  });
});

const periodoEl = document.getElementById('periodo');
const corteDocsEl = document.getElementById('corte_documentos');
const corteAgingEl = document.getElementById('corte_aging');
if (periodoEl && corteDocsEl && corteAgingEl) {
  const toLastDayOfPeriod = function (periodoYm) {
    const year = Number(periodoYm.slice(0, 4));
    const month = Number(periodoYm.slice(5, 7));
    if (!Number.isInteger(year) || !Number.isInteger(month) || month < 1 || month > 12) {
      return '';
    }
    const d = new Date(year, month, 0);
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + mm + '-' + dd;
  };

  const syncCorteDocumentos = function () {
    const periodo = periodoEl.value || 'all';
    if (periodo === 'all') {
      corteDocsEl.value = corteAgingEl.value;
      return;
    }
    const finMes = toLastDayOfPeriod(periodo);
    if (finMes !== '') {
      corteDocsEl.value = finMes;
    }
  };

  periodoEl.addEventListener('change', syncCorteDocumentos);
  corteAgingEl.addEventListener('change', function () {
    if ((periodoEl.value || 'all') === 'all') {
      corteDocsEl.value = corteAgingEl.value;
    }
  });
  syncCorteDocumentos();
}

const agingChartEl = document.getElementById('agingClasificacionChart');
if (agingChartEl && window.Chart) {
  const labels = <?php echo json_encode($agingChartLabels, JSON_UNESCAPED_UNICODE); ?>;
  const values = <?php echo json_encode($agingChartData, JSON_UNESCAPED_UNICODE); ?>;
  const clp = new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP', maximumFractionDigits: 0 });

  new Chart(agingChartEl, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Saldo pendiente',
        data: values,
        borderWidth: 1,
        borderRadius: 6,
        backgroundColor: ['#3b82f6', '#0ea5e9', '#f59e0b', '#ef4444'],
        borderColor: ['#2563eb', '#0284c7', '#d97706', '#dc2626']
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      indexAxis: 'y',
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function (ctx) { return ' ' + clp.format(Number(ctx.raw || 0)); }
          }
        }
      },
      scales: {
        x: {
          ticks: {
            callback: function (value) { return clp.format(Number(value || 0)); }
          }
        },
        y: {
          ticks: { color: '#334155' }
        }
      }
    }
  });
}

</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
