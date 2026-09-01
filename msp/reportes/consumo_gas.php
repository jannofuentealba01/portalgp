<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';

msp2RequireAccess();

$flash = msp2PullFlash();

function reFmtPeriodoYm(string $periodoYm): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($d === false || $d->format('Y-m') !== $periodoYm) {
        return $periodoYm;
    }

    $months = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];

    $monthName = mb_strtoupper($months[(int) $d->format('n')] ?? $periodoYm, 'UTF-8');
    return $monthName . ' ' . $d->format('Y');
}

function rePrevPeriodoYm(string $periodoYm): ?string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($d === false || $d->format('Y-m') !== $periodoYm) {
        return null;
    }

    return $d->modify('-1 month')->format('Y-m');
}

function reFmtDdMm(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '--';
    }

    $d = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $d ? $d->format('d-m') : '--';
}

function reFmtFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '--';
    }

    $d = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $d ? $d->format('d-m-Y') : '--';
}

function reFmtNum(mixed $value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals, ',', '.');
}

function reFmtMoney(mixed $value): string
{
    return '$ ' . number_format((float) $value, 0, ',', '.');
}

function reResolveHeaderDates(array $rows, ?string $fechaProceso): array
{
    $fechaActual = '--';
    $fechaAnterior = '--';

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $fechaActualRaw = (string) ($row['fecha_actual'] ?? '');
        $fechaAnteriorRaw = (string) ($row['fecha_anterior'] ?? '');
        if ($fechaActual === '--' && $fechaActualRaw !== '') {
            $fechaActual = reFmtDdMm($fechaActualRaw);
        }
        if ($fechaAnterior === '--' && $fechaAnteriorRaw !== '') {
            $fechaAnterior = reFmtDdMm($fechaAnteriorRaw);
        }
        if ($fechaActual !== '--' && $fechaAnterior !== '--') {
            break;
        }
    }

    if ($fechaActual === '--' && $fechaProceso !== null && $fechaProceso !== '') {
        $fechaActual = reFmtDdMm($fechaProceso);
    }
    if ($fechaAnterior === '--' && $fechaProceso !== null && $fechaProceso !== '') {
        $base = DateTimeImmutable::createFromFormat('Y-m-d', substr($fechaProceso, 0, 10));
        if ($base !== false) {
            $fechaAnterior = $base->modify('-1 month')->format('d-m');
        }
    }

    return [$fechaAnterior, $fechaActual];
}

function reFetchConsumoDataset(PDO $conn, string $periodoConsumoYm): array
{
    $requiredTables = [
        'msp_procesos_cobro_servicio',
        'msp_tipos_servicio',
        'msp_proceso_cobro_gas',
        'msp_lecturas_medidores',
        'msp_medidores',
        'msp_locales',
    ];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla `' . $tableName . '` para construir el resumen.');
        }
    }

    $procesoStmt = $conn->prepare(
        "SELECT TOP (1)
            p.id_proceso_cobro,
            p.fecha_emision_origen,
            pg.factor,
            pg.valor_litro
         FROM dbo.msp_procesos_cobro_servicio p
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
         LEFT JOIN dbo.msp_proceso_cobro_gas pg
            ON pg.id_proceso_cobro = p.id_proceso_cobro
         WHERE UPPER(ts.codigo_servicio) = 'GAS'
           AND EXISTS (
                SELECT 1
                FROM dbo.msp_lecturas_medidores lm_check
                WHERE lm_check.id_proceso_cobro = p.id_proceso_cobro
                  AND CONVERT(CHAR(7), lm_check.fecha_hasta_consumo, 126) = :periodo_consumo
           )
         ORDER BY p.id_proceso_cobro DESC"
    );
    $procesoStmt->bindValue(':periodo_consumo', $periodoConsumoYm, PDO::PARAM_STR);
    $procesoStmt->execute();
    $proceso = $procesoStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $idProceso = (int) ($proceso['id_proceso_cobro'] ?? 0);
    if ($idProceso <= 0) {
        throw new RuntimeException('No existe proceso de GAS para el período de consumo seleccionado.');
    }

    $factor = is_numeric((string) ($proceso['factor'] ?? null))
        ? (float) $proceso['factor']
        : null;
    $valorLitro = is_numeric((string) ($proceso['valor_litro'] ?? null))
        ? (float) $proceso['valor_litro']
        : null;

    $lecturasStmt = $conn->prepare(
        "SELECT
            loc.cdo_local AS cod_local,
            m.codigo_medidor,
            lm.lectura_anterior,
            lm.lectura_actual,
            cs.consumo_cobrado,
            cs.monto_total,
            COALESCE(lm.fecha_desde_consumo, prev.fecha_hasta_consumo) AS fecha_anterior,
            lm.fecha_hasta_consumo AS fecha_actual
         FROM dbo.msp_lecturas_medidores lm
         INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
         INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         LEFT JOIN dbo.msp_cobros_servicios cs
            ON cs.id_lectura = lm.id_lectura
         OUTER APPLY (
            SELECT TOP (1)
                lprev.fecha_hasta_consumo
            FROM dbo.msp_lecturas_medidores lprev
            WHERE lprev.id_medidor = lm.id_medidor
              AND (
                    lprev.fecha_hasta_consumo < lm.fecha_hasta_consumo
                    OR (lprev.fecha_hasta_consumo = lm.fecha_hasta_consumo AND lprev.id_lectura < lm.id_lectura)
              )
            ORDER BY lprev.fecha_hasta_consumo DESC, lprev.id_lectura DESC
         ) prev
         WHERE lm.id_proceso_cobro = :id_proceso
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", m.codigo_medidor ASC"
    );
    $lecturasStmt->bindValue(':id_proceso', $idProceso, PDO::PARAM_INT);
    $lecturasStmt->execute();
    $dbRows = $lecturasStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = [];
    foreach ($dbRows as $row) {
        $lecturaAnterior = is_numeric((string) ($row['lectura_anterior'] ?? null)) ? (float) $row['lectura_anterior'] : 0.0;
        $lecturaActual = is_numeric((string) ($row['lectura_actual'] ?? null)) ? (float) $row['lectura_actual'] : 0.0;
        $consumoCalculado = max(0.0, round($lecturaActual - $lecturaAnterior, 4));
        $consumoCobrado = is_numeric((string) ($row['consumo_cobrado'] ?? null)) ? (float) $row['consumo_cobrado'] : null;
        $montoTotal = is_numeric((string) ($row['monto_total'] ?? null)) ? (float) $row['monto_total'] : null;
        $consumoUsado = $consumoCobrado !== null ? max(0.0, round($consumoCobrado, 4)) : $consumoCalculado;
        $montoUsado = $montoTotal !== null
            ? max(0.0, round($montoTotal, 2))
            : (($factor !== null && $factor > 0 && $valorLitro !== null && $valorLitro > 0)
                ? round($consumoUsado * $factor * $valorLitro, 2)
                : 0.0);

        $rows[] = [
            'cod_local' => (string) ($row['cod_local'] ?? ''),
            'codigo_medidor' => (string) ($row['codigo_medidor'] ?? ''),
            'lectura_anterior' => $lecturaAnterior,
            'lectura_actual' => $lecturaActual,
            'total_consumido' => $consumoUsado,
            'factor' => $factor,
            'valor_litro' => $valorLitro,
            'a_pagar' => $montoUsado,
            'fecha_anterior' => (string) ($row['fecha_anterior'] ?? ''),
            'fecha_actual' => (string) ($row['fecha_actual'] ?? ''),
        ];
    }

    return [
        'id_proceso' => $idProceso,
        'fecha_proceso' => (string) ($proceso['fecha_emision_origen'] ?? ''),
        'factor' => $factor,
        'valor_litro' => $valorLitro,
        'rows' => $rows,
    ];
}

function reBuildTrendSeries(PDO $conn, string $periodoYm, int $limit = 6, int $maxLookback = 24): array
{
    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($d === false || $d->format('Y-m') !== $periodoYm) {
        return [
            'labels' => [],
            'consumo' => [],
            'monto' => [],
        ];
    }

    $labels = [];
    $consumo = [];
    $monto = [];
    $cursor = $d;
    $steps = 0;

    while (count($labels) < $limit && $steps < $maxLookback) {
        $ym = $cursor->format('Y-m');
        try {
            $dataset = reFetchConsumoDataset($conn, $ym);
            $rows = is_array($dataset['rows'] ?? null) ? $dataset['rows'] : [];
            $sumConsumo = 0.0;
            $sumMonto = 0.0;
            foreach ($rows as $row) {
                $sumConsumo += (float) ($row['total_consumido'] ?? 0);
                $sumMonto += (float) ($row['a_pagar'] ?? 0);
            }
            $labels[] = reFmtPeriodoYm($ym);
            $consumo[] = round($sumConsumo, 2);
            $monto[] = round($sumMonto, 2);
        } catch (Throwable $e) {
            // Mes sin proceso GAS: se omite del gráfico.
        }
        $cursor = $cursor->modify('-1 month');
        $steps++;
    }

    return [
        'labels' => array_reverse($labels),
        'consumo' => array_reverse($consumo),
        'monto' => array_reverse($monto),
    ];
}

$periodoYm = trim((string) ($_GET['periodo'] ?? ''));
$periodoOptions = [];
$periodoValues = [];
$fallbackPeriodoYm = (new DateTimeImmutable('today'))->format('Y-m');

try {
    $requiredTables = [
        'msp_procesos_cobro_servicio',
        'msp_tipos_servicio',
        'msp_lecturas_medidores',
    ];
    $canLoadPeriodos = true;
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $canLoadPeriodos = false;
            break;
        }
    }

    if ($canLoadPeriodos) {
        $stmt = $conn->query(
            "SELECT DISTINCT CONVERT(CHAR(7), lm.fecha_hasta_consumo, 126) AS periodo_ym
             FROM dbo.msp_procesos_cobro_servicio p
             INNER JOIN dbo.msp_tipos_servicio ts
                ON ts.id_tipo_servicio = p.id_tipo_servicio
             INNER JOIN dbo.msp_lecturas_medidores lm
                ON lm.id_proceso_cobro = p.id_proceso_cobro
             WHERE UPPER(ts.codigo_servicio) = 'GAS'
             ORDER BY periodo_ym DESC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $ym = trim((string) ($row['periodo_ym'] ?? ''));
            if ($ym === '' || preg_match('/^\d{4}-\d{2}$/', $ym) !== 1) {
                continue;
            }
            $periodoValues[] = $ym;
            $label = reFmtPeriodoYm($ym);
            $periodoOptions[] = [
                'value' => $ym,
                'label' => $label,
                'search' => mb_strtolower($ym . ' ' . $label, 'UTF-8'),
            ];
        }
    }
} catch (Throwable $e) {
    // Si falla la carga de períodos, se usa fallback al mes actual.
}

if ($periodoYm === '') {
    $periodoYm = $periodoValues[0] ?? $fallbackPeriodoYm;
}

$periodoValido = preg_match('/^\d{4}-\d{2}$/', $periodoYm) === 1;
$periodoUi = $periodoValido ? reFmtPeriodoYm($periodoYm) : $periodoYm;

if ($periodoValido && !in_array($periodoYm, $periodoValues, true)) {
    $label = reFmtPeriodoYm($periodoYm);
    array_unshift($periodoOptions, [
        'value' => $periodoYm,
        'label' => $label,
        'search' => mb_strtolower($periodoYm . ' ' . $label, 'UTF-8'),
    ]);
}

if ($periodoOptions === []) {
    $label = reFmtPeriodoYm($periodoYm);
    $periodoOptions[] = [
        'value' => $periodoYm,
        'label' => $label,
        'search' => mb_strtolower($periodoYm . ' ' . $label, 'UTF-8'),
    ];
}

$reporteExcelUrl = null;
$reportePdfUrl = null;
if ($periodoValido) {
    $baseQuery = 'servicio=GAS&periodo=' . urlencode($periodoYm) . '&anadido_siguiente=1';
    $reporteExcelUrl = msp2Url('cobros/reporte_consumo_gas.php?' . $baseQuery . '&format=xlsx');
    $reportePdfUrl = msp2Url('cobros/reporte_consumo_gas.php?' . $baseQuery . '&format=pdf');
}

$resumenError = null;
$resumen = [
    'id_proceso' => 0,
    'fecha_proceso' => '',
    'factor' => null,
    'valor_litro' => null,
    'consumo_total' => 0.0,
    'monto_total' => 0.0,
    'fecha_ant_hdr' => '--',
    'fecha_act_hdr' => '--',
    'top_consumos' => [],
];
$chartTendencia = [
    'labels' => [],
    'consumo' => [],
    'monto' => [],
];

if ($periodoValido) {
    try {
        $dataset = reFetchConsumoDataset($conn, $periodoYm);
        $rows = is_array($dataset['rows'] ?? null) ? $dataset['rows'] : [];

        $consumoTotal = 0.0;
        $montoTotal = 0.0;
        $consumosPorLocal = [];

        foreach ($rows as $row) {
            $consumoTotal += (float) ($row['total_consumido'] ?? 0);
            $montoTotal += (float) ($row['a_pagar'] ?? 0);

            $codigoLocal = trim((string) ($row['cod_local'] ?? ''));
            if ($codigoLocal === '') {
                $codigoLocal = 'Sin local';
            }
            if (!isset($consumosPorLocal[$codigoLocal])) {
                $consumosPorLocal[$codigoLocal] = [
                    'cod_local' => $codigoLocal,
                    'medidores' => [],
                    'total_consumido' => 0.0,
                    'monto_total' => 0.0,
                ];
            }
            $codigoMedidor = trim((string) ($row['codigo_medidor'] ?? ''));
            if ($codigoMedidor !== '') {
                $consumosPorLocal[$codigoLocal]['medidores'][$codigoMedidor] = true;
            }
            $consumosPorLocal[$codigoLocal]['total_consumido'] += (float) ($row['total_consumido'] ?? 0);
            $consumosPorLocal[$codigoLocal]['monto_total'] += (float) ($row['a_pagar'] ?? 0);
        }
        [$fechaAntHdr, $fechaActHdr] = reResolveHeaderDates($rows, (string) ($dataset['fecha_proceso'] ?? ''));

        $topConsumos = array_values($consumosPorLocal);
        usort($topConsumos, static function (array $a, array $b): int {
            $porConsumo = (float) $b['total_consumido'] <=> (float) $a['total_consumido'];
            return $porConsumo !== 0 ? $porConsumo : strnatcasecmp((string) $a['cod_local'], (string) $b['cod_local']);
        });
        $topConsumos = array_slice($topConsumos, 0, 10);
        foreach ($topConsumos as &$topConsumo) {
            $topConsumo['codigo_medidor'] = implode(' / ', array_keys($topConsumo['medidores']));
            unset($topConsumo['medidores']);
        }
        unset($topConsumo);

        $resumen = [
            'id_proceso' => (int) ($dataset['id_proceso'] ?? 0),
            'fecha_proceso' => (string) ($dataset['fecha_proceso'] ?? ''),
            'factor' => $dataset['factor'] ?? null,
            'valor_litro' => $dataset['valor_litro'] ?? null,
            'consumo_total' => $consumoTotal,
            'monto_total' => $montoTotal,
            'fecha_ant_hdr' => $fechaAntHdr,
            'fecha_act_hdr' => $fechaActHdr,
            'top_consumos' => $topConsumos,
        ];
        $chartTendencia = reBuildTrendSeries($conn, $periodoYm, 6, 24);
    } catch (Throwable $e) {
        $resumenError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Reporte Consumo Gas</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css?v=<?php echo rawurlencode((string) filemtime(dirname(__DIR__, 2) . '/styles.css')); ?>">
    <?php msp2RenderSearchableSelectAssets(); ?>
    <style>
        .gas-report-shell{max-width:1640px;width:100%;margin:0 auto;font-family:"Segoe UI","Helvetica Neue",Arial,sans-serif}
        .gas-report-header{display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);align-items:center;gap:1rem;margin-bottom:.85rem}
        .gas-report-header h1{grid-column:2;grid-row:1;justify-self:center;margin:0;color:#003399;font-size:1.75rem;font-weight:600;line-height:1.2}
        .gas-report-back{grid-column:1;grid-row:1;justify-self:start}
        .gas-report-actions{grid-column:3;grid-row:1;justify-self:end;display:flex;flex-wrap:wrap;justify-content:flex-end;gap:.5rem}
        .gas-report-header .btn{min-height:34px;padding:.35rem .65rem;font-size:.825rem;line-height:1.2}
        .gas-report-filter{margin-bottom:.85rem}
        .gas-report-filter .form-label{margin-bottom:.25rem;font-size:.88rem}
        .gas-report-filter .btn{min-height:38px;padding-top:.38rem;padding-bottom:.38rem}
        .gas-report-kpi{height:100%;border:1px solid #dce5ef;border-radius:8px;background:#fff;padding:.65rem .75rem}
        .gas-report-kpi .small{font-size:.72rem}
        .gas-report-kpi .h4{color:#123f72;font-size:1.08rem;line-height:1.25}
        .gas-report-section{margin-top:.85rem;padding-top:.7rem;border-top:1px solid #dbe3ec}
        .gas-report-panel{height:100%;border:1px solid #dce5ef;border-radius:8px;background:#fff;padding:.65rem .75rem}
        .gas-report-panel h2,.gas-report-panel h3{margin-bottom:.45rem}
        .gas-report-panel .table>:not(caption)>*>*{padding:.38rem .45rem;font-size:.82rem}
        .msp-report-chart-box {
            position: relative;
            height: 220px;
            min-height: 220px;
        }

        .msp-report-chart-box canvas {
            display: block;
            width: 100% !important;
            height: 100% !important;
        }
        .gas-report-main-chart{height:320px;min-height:320px}
        @media(max-width:700px){.gas-report-header{display:flex;flex-direction:column;align-items:stretch}.gas-report-header h1{order:1;align-self:center;text-align:center}.gas-report-back{order:2}.gas-report-actions{order:3;justify-content:flex-start}.gas-report-actions .btn{flex:1 1 auto}.msp-report-chart-box,.gas-report-main-chart{height:240px;min-height:240px}}
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-xl-4">
    <div class="gas-report-shell">
        <header class="gas-report-header">
            <div class="gas-report-back">
            <a href="<?php echo msp2Escape(msp2Url('dashboard/index.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al Dashboard
            </a>
            </div>
            <h1>Consumo mensual de gas</h1>
            <div class="gas-report-actions">
                <?php if ($periodoValido): ?>
                    <a href="<?php echo msp2Escape((string) $reporteExcelUrl); ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Descargar Excel
                    </a>
                    <a href="<?php echo msp2Escape((string) $reportePdfUrl); ?>" class="btn btn-outline-danger btn-sm" target="_blank" rel="noopener">
                        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Ver PDF
                    </a>
                <?php endif; ?>
            </div>
        </header>

        <?php msp2RenderFlash($flash); ?>

                <form method="get" class="row g-2 align-items-end gas-report-filter" id="form_reporte_consumo_periodo">
                    <?php
                    msp2RenderSearchableSelectField([
                        'wrapper_class' => 'col-12 col-md-7 col-xl-5',
                        'label' => 'Período de consumo (mes/año)',
                        'input_name' => 'periodo',
                        'input_id' => 'reporte_consumo_periodo',
                        'picker_id' => 'reporte_consumo_periodo_picker',
                        'button_id' => 'reporte_consumo_periodo_btn',
                        'filter_id' => 'reporte_consumo_periodo_filter',
                        'list_id' => 'reporte_consumo_periodo_list',
                        'error_id' => 'reporte_consumo_periodo_error',
                        'error_message' => 'Debes seleccionar un período.',
                        'button_placeholder' => 'Seleccionar período...',
                        'filter_placeholder' => 'Buscar mes/año',
                        'empty_message' => 'No hay períodos disponibles.',
                        'button_class' => 'btn btn-outline-secondary dropdown-toggle w-100 text-start',
                        'required' => true,
                        'value' => $periodoYm,
                        'options' => $periodoOptions,
                    ]);
                    ?>
                </form>

                <?php if (!$periodoValido): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        Período inválido. Debe ser <code>YYYY-MM</code>.
                    </div>
                <?php else: ?>
                    <?php if ($resumenError !== null): ?>
                        <div class="alert alert-warning mb-0">
                            No fue posible obtener el resumen del período: <?php echo msp2Escape($resumenError); ?>
                        </div>
                    <?php else: ?>
                        <?php
                        $factor = $resumen['factor'];
                        $valorLitro = $resumen['valor_litro'];
                        ?>
                        <div class="row g-2">
                            <div class="col-6 col-md-4 col-xl-2">
                                <div class="gas-report-kpi">
                                    <div class="small text-muted">Consumo total (m3)</div>
                                    <div class="h4 mb-0"><?php echo msp2Escape(reFmtNum((float) $resumen['consumo_total'], 0)); ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-xl-2">
                                <div class="gas-report-kpi">
                                    <div class="small text-muted">Monto total</div>
                                    <div class="h4 mb-0"><?php echo msp2Escape(reFmtMoney((float) $resumen['monto_total'])); ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-xl-2">
                                <div class="gas-report-kpi">
                                    <div class="small text-muted">Fecha del proceso</div>
                                    <div class="h4 mb-0"><?php echo msp2Escape(reFmtFecha((string) $resumen['fecha_proceso'])); ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-xl-2">
                                <div class="gas-report-kpi">
                                    <div class="small text-muted">Factor</div>
                                    <div class="h4 mb-0"><?php echo msp2Escape($factor !== null ? reFmtNum((float) $factor, 4) : '--'); ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-xl-2">
                                <div class="gas-report-kpi">
                                    <div class="small text-muted">Valor litro</div>
                                    <div class="h4 mb-0"><?php echo msp2Escape($valorLitro !== null ? reFmtMoney((float) $valorLitro) : '--'); ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-4 col-xl-2">
                                <div class="gas-report-kpi">
                                    <div class="small text-muted">Rango de lecturas</div>
                                    <div class="h4 mb-0"><?php echo msp2Escape((string) $resumen['fecha_ant_hdr'] . ' → ' . (string) $resumen['fecha_act_hdr']); ?></div>
                                </div>
                            </div>
                        </div>

                        <section class="gas-report-section">
                            <div class="gas-report-panel">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                                    <h2 class="h6 mb-0">Tendencia de consumo y monto</h2>
                                    <span class="small text-muted">Toca o posiciona el cursor sobre un mes para ver su detalle.</span>
                                </div>
                                <div class="msp-report-chart-box gas-report-main-chart">
                                    <canvas id="chartTendenciaLine"></canvas>
                                </div>
                            </div>
                        </section>

                        <div class="row g-2 gas-report-section">
                            <div class="col-12">
                                <div class="gas-report-panel">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h2 class="h6 mb-0">10 locales con mayor consumo</h2>
                                        <span class="small text-muted">Ordenados de mayor a menor</span>
                                    </div>
                                    <?php $topConsumos = is_array($resumen['top_consumos'] ?? null) ? $resumen['top_consumos'] : []; ?>
                                    <?php if ($topConsumos === []): ?>
                                        <div class="small text-muted">No hay consumos para mostrar.</div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-striped align-middle mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>Local</th>
                                                        <th>Medidor(es)</th>
                                                        <th class="text-end">Consumo (m3)</th>
                                                        <th class="text-end">Monto</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ($topConsumos as $row): ?>
                                                    <tr>
                                                        <td><?php echo msp2Escape((string) ($row['cod_local'] ?? '')); ?></td>
                                                        <td><?php echo msp2Escape((string) ($row['codigo_medidor'] ?? '')); ?></td>
                                                        <td class="text-end"><?php echo msp2Escape(reFmtNum((float) ($row['total_consumido'] ?? 0), 0)); ?></td>
                                                        <td class="text-end"><?php echo msp2Escape(reFmtMoney((float) ($row['monto_total'] ?? 0))); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                    <?php endif; ?>
                <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(() => {
    const form = document.getElementById('form_reporte_consumo_periodo');
    const periodoInput = document.getElementById('reporte_consumo_periodo');
    if (!(form instanceof HTMLFormElement) || !(periodoInput instanceof HTMLInputElement)) {
        return;
    }

    periodoInput.addEventListener('change', () => {
        const value = String(periodoInput.value || '').trim();
        if (value !== '') {
            form.submit();
        }
    });

    const chartTendenciaData = <?php echo json_encode($chartTendencia, JSON_UNESCAPED_UNICODE); ?>;
    if (typeof window.Chart !== 'function') {
        return;
    }

    const tendenciaCanvas = document.getElementById('chartTendenciaLine');
    const trendLabels = Array.isArray(chartTendenciaData?.labels) ? chartTendenciaData.labels : [];
    if (tendenciaCanvas instanceof HTMLCanvasElement && trendLabels.length >= 2) {
        new Chart(tendenciaCanvas, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    {
                        label: 'Consumo (m3)',
                        data: chartTendenciaData.consumo || [],
                        borderColor: '#1f77b4',
                        backgroundColor: 'rgba(31, 119, 180, 0.18)',
                        tension: 0.25,
                        fill: false,
                        yAxisID: 'yConsumo',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointHitRadius: 18,
                    },
                    {
                        label: 'Monto ($)',
                        data: chartTendenciaData.monto || [],
                        borderColor: '#ff7f0e',
                        backgroundColor: 'rgba(255, 127, 14, 0.18)',
                        tension: 0.25,
                        fill: false,
                        yAxisID: 'yMonto',
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointHitRadius: 18,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (context) => {
                                const value = Number(context.parsed.y || 0);
                                if (context.dataset.yAxisID === 'yMonto') {
                                    return `Monto: $ ${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 0 }).format(value)}`;
                                }
                                return `Consumo: ${new Intl.NumberFormat('es-CL', { maximumFractionDigits: 2 }).format(value)} m³`;
                            },
                        },
                    },
                },
                scales: {
                    yConsumo: {
                        type: 'linear',
                        position: 'left',
                        beginAtZero: true,
                        title: { display: true, text: 'Consumo (m³)' },
                    },
                    yMonto: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: 'Monto ($)' },
                        ticks: {
                            callback: (value) => '$ ' + new Intl.NumberFormat('es-CL', { notation: 'compact', maximumFractionDigits: 1 }).format(value),
                        },
                    },
                },
            },
        });
    } else if (tendenciaCanvas instanceof HTMLCanvasElement) {
        const parent = tendenciaCanvas.parentElement;
        if (parent) {
            const note = document.createElement('div');
            note.className = 'small text-muted';
            note.textContent = 'Se requieren al menos 2 períodos con datos para la tendencia.';
            parent.appendChild(note);
        }
    }
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
