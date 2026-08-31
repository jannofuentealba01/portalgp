<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$tablaExiste = false;
$loadError = null;
$detalleLocalesDisponible = false;

$fechaHoyDashboard = new DateTimeImmutable('today');
$fechaInicioSistemaDashboard = new DateTimeImmutable('2026-01-01');
$filtroFechaInicio = trim((string) ($_GET['fecha_inicio'] ?? ''));
$filtroFechaTermino = trim((string) ($_GET['fecha_termino'] ?? ''));

$fechaInicioDashboard = DateTimeImmutable::createFromFormat('!Y-m-d', $filtroFechaInicio);
if (!($fechaInicioDashboard instanceof DateTimeImmutable) || $fechaInicioDashboard->format('Y-m-d') !== $filtroFechaInicio) {
    $fechaInicioDashboard = $fechaInicioSistemaDashboard;
}

$fechaTerminoDashboardDate = DateTimeImmutable::createFromFormat('!Y-m-d', $filtroFechaTermino);
if (!($fechaTerminoDashboardDate instanceof DateTimeImmutable) || $fechaTerminoDashboardDate->format('Y-m-d') !== $filtroFechaTermino) {
    $fechaTerminoDashboardDate = $fechaHoyDashboard;
}

if ($fechaInicioDashboard > $fechaTerminoDashboardDate) {
    [$fechaInicioDashboard, $fechaTerminoDashboardDate] = [$fechaTerminoDashboardDate, $fechaInicioDashboard];
}

$filtroFechaInicio = $fechaInicioDashboard->format('Y-m-d');
$filtroFechaTermino = $fechaTerminoDashboardDate->format('Y-m-d');
$fechaInicioPeriodoDashboardDate = $fechaInicioDashboard->modify('first day of this month');
$fechaTerminoPeriodoDashboardDate = $fechaTerminoDashboardDate->modify('first day of this month');
$fechaInicioPeriodoDashboard = $fechaInicioPeriodoDashboardDate->format('Y-m-d');
$fechaTerminoPeriodoDashboard = $fechaTerminoPeriodoDashboardDate->format('Y-m-d');
$fechaInicioChartDashboardDate = $fechaTerminoPeriodoDashboardDate->modify('-11 months');
$fechaInicioChartDashboard = $fechaInicioChartDashboardDate->format('Y-m-d');
$fechaTerminoChartDashboard = $fechaTerminoPeriodoDashboard;
$mesesRangoDashboard = (($fechaTerminoPeriodoDashboardDate->format('Y') - $fechaInicioPeriodoDashboardDate->format('Y')) * 12)
    + ((int) $fechaTerminoPeriodoDashboardDate->format('n') - (int) $fechaInicioPeriodoDashboardDate->format('n'))
    + 1;
$fechaInicioPeriodoAnteriorDashboard = $fechaInicioPeriodoDashboardDate->modify('-' . $mesesRangoDashboard . ' months');
$fechaTerminoPeriodoAnteriorDashboard = $fechaInicioPeriodoDashboardDate->modify('-1 month');

$resumenKpi = [
    'documentos' => 0,
    'arrendatarios' => 0,
    'arrendatarios_al_dia' => 0,
    'arrendatarios_con_deuda' => 0,
    'facturado' => 0.0,
    'cobrado' => 0.0,
    'saldo' => 0.0,
    'recaudacion_pct' => 0.0,
];

$registrosPorTienda = [];
$historialMensual = [];
$detalleLocalesPorTienda = [];
$documentosPorTienda = [];
$topDeudores = [];
$fechaCorteDashboard = $filtroFechaTermino;
$insightsKpi = [
    'ticket_promedio' => 0.0,
    'saldo_promedio_deudor' => 0.0,
    'morosidad_vencida_pct' => 0.0,
    'tiendas_con_deuda' => 0,
];
$composicionFacturacion = [
    'arriendo' => 0.0,
    'luz' => 0.0,
    'gas' => 0.0,
    'agua' => 0.0,
    'otros' => 0.0,
];
$consumoKpi = [
    'total_actual' => 0.0,
    'total_anterior' => 0.0,
    'variacion_pct' => 0.0,
    'variacion_nominal' => 0.0,
    'promedio_local' => 0.0,
    'locales_con_consumo' => 0,
    'local_top_nombre' => '-',
    'local_top_monto' => 0.0,
];
$consumoServicios = [
    'luz' => [
        'label' => 'Luz',
        'codigo' => 'SERVICIO_LUZ',
        'unidad' => 'kWh',
        'consumo_actual' => 0.0,
        'consumo_anterior' => 0.0,
        'variacion_pct' => 0.0,
        'costo_actual' => 0.0,
        'costo_promedio' => 0.0,
    ],
    'gas' => [
        'label' => 'Gas',
        'codigo' => 'SERVICIO_GAS',
        'unidad' => 'm3',
        'consumo_actual' => 0.0,
        'consumo_anterior' => 0.0,
        'variacion_pct' => 0.0,
        'costo_actual' => 0.0,
        'costo_promedio' => 0.0,
    ],
    'agua' => [
        'label' => 'Agua',
        'codigo' => 'SERVICIO_AGUA',
        'unidad' => 'm3',
        'consumo_actual' => 0.0,
        'consumo_anterior' => 0.0,
        'variacion_pct' => 0.0,
        'costo_actual' => 0.0,
        'costo_promedio' => 0.0,
    ],
];
$consumoTopLocales = [];
$chartSeries = [
    'historial_labels' => [],
    'historial_facturado' => [],
    'historial_cobrado' => [],
    'historial_saldo' => [],
    'top_deudores_labels' => [],
    'top_deudores_values' => [],
    'consumo_labels' => [],
    'consumo_luz' => [],
    'consumo_gas' => [],
    'consumo_agua' => [],
    'servicio_combo_labels' => [],
    'servicio_combo' => [
        'luz' => ['consumo' => [], 'costo' => []],
        'gas' => ['consumo' => [], 'costo' => []],
        'agua' => ['consumo' => [], 'costo' => []],
    ],
];

$operacionKpi = [
    'locales_total' => 0,
    'locales_ocupados' => 0,
    'ocupacion_pct' => 0.0,
    'tiendas_activas' => 0,
    'contratos_vigentes' => 0,
    'contratos_en_liquidacion' => 0,
    'documentos_vencidos' => 0,
    'monto_vencido' => 0.0,
];

$dashboardScopeLabel = sprintf(
    '%s al %s',
    $fechaInicioDashboard->format('d-m-Y'),
    $fechaTerminoDashboardDate->format('d-m-Y')
);

function dashboardFmtMonto(mixed $value): string
{
    return '$ ' . number_format((float) ($value ?? 0), 2, ',', '.');
}

function dashboardFmtPorcentaje(mixed $value): string
{
    return number_format((float) ($value ?? 0), 1, ',', '.') . '%';
}

function dashboardFmtDelta(mixed $value): string
{
    $number = (float) ($value ?? 0);
    if ($number > 0) {
        return '+' . dashboardFmtPorcentaje($number);
    }
    if ($number < 0) {
        return dashboardFmtPorcentaje($number);
    }

    return '0,0%';
}

function dashboardFmtPeriodo(?string $value): string
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

function dashboardMonthKey(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
    if (!($parsed instanceof DateTimeImmutable)) {
        return '';
    }

    return $parsed->format('Y-m');
}

function dashboardJson(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

try {
    $requiredTables = [
        'msp_documentos_cobro',
        'msp_pagos',
        'msp_tiendas',
        'msp_arrendatarios',
    ];

    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];
    if (!$tablaExiste) {
        $loadError = 'Faltan tablas base para el dashboard: `' . implode('`, `', $missingTables) . '`.';
    }

    $detalleLocalesDisponible =
        msp2TableExists($conn, 'msp_documentos_cobro_detalle')
        && msp2TableExists($conn, 'msp_tipo_item_documento')
        && msp2TableExists($conn, 'msp_cobros_servicios')
        && msp2TableExists($conn, 'msp_lecturas_medidores')
        && msp2TableExists($conn, 'msp_medidores')
        && msp2TableExists($conn, 'msp_locales');
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura base del Dashboard.';
}

if ($tablaExiste) {
    try {
        $documentosParams = [
            ':periodo_inicio' => $fechaInicioPeriodoDashboard,
            ':periodo_fin' => $fechaTerminoPeriodoDashboard,
        ];
        $pagosParams = [
            ':fecha_pago_inicio' => $filtroFechaInicio,
            ':fecha_pago_fin' => $filtroFechaTermino,
        ];
        $documentosWhere = 'dc.periodo_facturacion >= :periodo_inicio AND dc.periodo_facturacion <= :periodo_fin';

        $stmtKpi = $conn->prepare(
            "WITH pagos_doc AS (
                SELECT
                    p.id_documento_cobro,
                    SUM(CASE WHEN p.estado_pago = 1 THEN p.monto_pagado ELSE 0 END) AS total_pagado
                FROM dbo.msp_pagos p
                WHERE p.fecha_pago >= :fecha_pago_inicio
                  AND p.fecha_pago <= :fecha_pago_fin
                GROUP BY p.id_documento_cobro
            ),
            docs_filtrados AS (
                SELECT
                    dc.id_documento_cobro,
                    t.id_arrendatario,
                    dc.monto_total,
                    dc.saldo_pendiente,
                    ISNULL(pd.total_pagado, 0) AS total_pagado
                FROM dbo.msp_documentos_cobro dc
                INNER JOIN dbo.msp_tiendas t
                    ON t.id_tienda = dc.id_tienda
                LEFT JOIN pagos_doc pd
                    ON pd.id_documento_cobro = dc.id_documento_cobro
                WHERE {$documentosWhere}
                  AND dc.estado_documento <> 5
            ),
            deuda_arr AS (
                SELECT
                    df.id_arrendatario,
                    SUM(df.saldo_pendiente) AS saldo_arrendatario
                FROM docs_filtrados df
                GROUP BY df.id_arrendatario
            )
            SELECT
                COUNT(*) AS documentos,
                COUNT(DISTINCT df.id_arrendatario) AS arrendatarios,
                ISNULL(SUM(df.monto_total), 0) AS facturado,
                ISNULL(SUM(df.total_pagado), 0) AS cobrado,
                ISNULL(SUM(df.saldo_pendiente), 0) AS saldo,
                ISNULL(SUM(CASE WHEN da.saldo_arrendatario <= 0 THEN 1 ELSE 0 END), 0) AS arrendatarios_al_dia,
                ISNULL(SUM(CASE WHEN da.saldo_arrendatario > 0 THEN 1 ELSE 0 END), 0) AS arrendatarios_con_deuda
            FROM docs_filtrados df
            LEFT JOIN deuda_arr da
                ON da.id_arrendatario = df.id_arrendatario"
        );

        foreach (array_merge($documentosParams, $pagosParams) as $key => $value) {
            $stmtKpi->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmtKpi->execute();
        $kpiRow = $stmtKpi->fetch() ?: null;
        if (is_array($kpiRow)) {
            $resumenKpi['documentos'] = (int) ($kpiRow['documentos'] ?? 0);
            $resumenKpi['arrendatarios'] = (int) ($kpiRow['arrendatarios'] ?? 0);
            $resumenKpi['arrendatarios_al_dia'] = (int) ($kpiRow['arrendatarios_al_dia'] ?? 0);
            $resumenKpi['arrendatarios_con_deuda'] = (int) ($kpiRow['arrendatarios_con_deuda'] ?? 0);
            $resumenKpi['facturado'] = (float) ($kpiRow['facturado'] ?? 0);
            $resumenKpi['cobrado'] = (float) ($kpiRow['cobrado'] ?? 0);
            $resumenKpi['saldo'] = (float) ($kpiRow['saldo'] ?? 0);
        }

        $resumenKpi['recaudacion_pct'] = $resumenKpi['facturado'] > 0
            ? (($resumenKpi['cobrado'] / $resumenKpi['facturado']) * 100)
            : 0.0;

        if (msp2TableExists($conn, 'msp_locales')) {
            $stmtLocales = $conn->query(
                "SELECT COUNT(*) AS total_locales
                 FROM dbo.msp_locales"
            );
            $operacionKpi['locales_total'] = (int) ($stmtLocales->fetchColumn() ?: 0);
        }

        if (msp2TableExists($conn, 'msp_locales') && msp2TableExists($conn, 'msp_ocupacion_locales')) {
            $stmtLocalesOcupados = $conn->prepare(
                "SELECT COUNT(DISTINCT ol.id_local) AS locales_ocupados
                 FROM dbo.msp_ocupacion_locales ol
                 WHERE ol.fecha_inicio <= :fecha_corte_inicio
                   AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= :fecha_corte_termino)"
            );
            $stmtLocalesOcupados->bindValue(':fecha_corte_inicio', $fechaCorteDashboard, PDO::PARAM_STR);
            $stmtLocalesOcupados->bindValue(':fecha_corte_termino', $fechaCorteDashboard, PDO::PARAM_STR);
            $stmtLocalesOcupados->execute();
            $operacionKpi['locales_ocupados'] = (int) ($stmtLocalesOcupados->fetchColumn() ?: 0);
        }

        $operacionKpi['ocupacion_pct'] = $operacionKpi['locales_total'] > 0
            ? (($operacionKpi['locales_ocupados'] / $operacionKpi['locales_total']) * 100)
            : 0.0;

        if (msp2TableExists($conn, 'msp_tiendas')) {
            $stmtTiendasActivas = $conn->prepare(
                "SELECT COUNT(*) AS tiendas_activas
                 FROM dbo.msp_tiendas t
                 WHERE (t.fecha_inicio IS NULL OR t.fecha_inicio <= :fecha_corte_inicio)
                   AND (t.fecha_termino IS NULL OR t.fecha_termino >= :fecha_corte_termino)"
            );
            $stmtTiendasActivas->bindValue(':fecha_corte_inicio', $fechaCorteDashboard, PDO::PARAM_STR);
            $stmtTiendasActivas->bindValue(':fecha_corte_termino', $fechaCorteDashboard, PDO::PARAM_STR);
            $stmtTiendasActivas->execute();
            $operacionKpi['tiendas_activas'] = (int) ($stmtTiendasActivas->fetchColumn() ?: 0);
        }

        if (msp2TableExists($conn, 'msp_contratos_arriendo')) {
            $stmtContratos = $conn->prepare(
                "SELECT
                    SUM(CASE WHEN estado_contrato IN (1, 2) THEN 1 ELSE 0 END) AS contratos_vigentes,
                    SUM(CASE WHEN estado_contrato = 3 THEN 1 ELSE 0 END) AS contratos_en_liquidacion
                 FROM dbo.msp_contratos_arriendo
                 WHERE fecha_inicio <= :fecha_corte_contrato_inicio
                   AND (fecha_termino_efectiva IS NULL OR fecha_termino_efectiva >= :fecha_corte_contrato_fin)"
            );
            $stmtContratos->bindValue(':fecha_corte_contrato_inicio', $fechaCorteDashboard, PDO::PARAM_STR);
            $stmtContratos->bindValue(':fecha_corte_contrato_fin', $fechaCorteDashboard, PDO::PARAM_STR);
            $stmtContratos->execute();
            $contratosRow = $stmtContratos->fetch() ?: [];
            $operacionKpi['contratos_vigentes'] = (int) ($contratosRow['contratos_vigentes'] ?? 0);
            $operacionKpi['contratos_en_liquidacion'] = (int) ($contratosRow['contratos_en_liquidacion'] ?? 0);
        }

        $stmtVencidos = $conn->prepare(
            "SELECT
                COUNT(*) AS documentos_vencidos,
                ISNULL(SUM(dc.saldo_pendiente), 0) AS monto_vencido
             FROM dbo.msp_documentos_cobro dc
             WHERE {$documentosWhere}
               AND dc.estado_documento <> 5
               AND ISNULL(dc.saldo_pendiente, 0) > 0.005
               AND dc.fecha_vencimiento < :fecha_corte"
        );
        foreach ($documentosParams as $key => $value) {
            $stmtVencidos->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmtVencidos->bindValue(':fecha_corte', $fechaCorteDashboard, PDO::PARAM_STR);
        $stmtVencidos->execute();
        $vencidosRow = $stmtVencidos->fetch() ?: [];
        $operacionKpi['documentos_vencidos'] = (int) ($vencidosRow['documentos_vencidos'] ?? 0);
        $operacionKpi['monto_vencido'] = (float) ($vencidosRow['monto_vencido'] ?? 0);

        $stmtTiendas = $conn->prepare(
            "WITH pagos_doc AS (
                SELECT
                    p.id_documento_cobro,
                    SUM(CASE WHEN p.estado_pago = 1 THEN p.monto_pagado ELSE 0 END) AS total_pagado
                FROM dbo.msp_pagos p
                WHERE p.fecha_pago >= :fecha_pago_inicio
                  AND p.fecha_pago <= :fecha_pago_fin
                GROUP BY p.id_documento_cobro
            )
            SELECT
                t.id_tienda,
                COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda,
                t.id_arrendatario,
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut, CONCAT(N'Arrendatario #', a.id_arrendatario)) AS nombre_arrendatario,
                a.rut,
                COUNT(*) AS cantidad_documentos,
                ROUND(SUM(dc.monto_total), 2) AS monto_facturado,
                ROUND(SUM(ISNULL(pd.total_pagado, 0)), 2) AS monto_cobrado,
                ROUND(SUM(dc.saldo_pendiente), 2) AS monto_saldo
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
            INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = t.id_arrendatario
            LEFT JOIN pagos_doc pd
                ON pd.id_documento_cobro = dc.id_documento_cobro
            WHERE {$documentosWhere}
              AND dc.estado_documento <> 5
            GROUP BY
                t.id_tienda,
                COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)),
                t.id_arrendatario,
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut, CONCAT(N'Arrendatario #', a.id_arrendatario)),
                a.rut
            ORDER BY nombre_tienda ASC"
        );

        foreach (array_merge($documentosParams, $pagosParams) as $key => $value) {
            $stmtTiendas->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmtTiendas->execute();
        $registrosPorTienda = $stmtTiendas->fetchAll();

        if ($detalleLocalesDisponible) {
            $localesConsumoActual = [];
            $stmtDetalleLocales = $conn->prepare(
                "WITH docs_filtrados AS (
                    SELECT
                        dc.id_documento_cobro,
                        dc.id_tienda
                    FROM dbo.msp_documentos_cobro dc
                    WHERE {$documentosWhere}
                      AND dc.estado_documento <> 5
                ),
                detalle_base AS (
                    SELECT
                        df.id_tienda,
                        dcd.id_documento_cobro,
                        tid.codigo_item,
                        dcd.subtotal,
                        COALESCE(
                            loc.cdo_local,
                            parseo.cdo_local,
                            N'SIN LOCAL'
                        ) AS cdo_local
                    FROM docs_filtrados df
                    INNER JOIN dbo.msp_documentos_cobro_detalle dcd
                        ON dcd.id_documento_cobro = df.id_documento_cobro
                    INNER JOIN dbo.msp_tipo_item_documento tid
                        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                    OUTER APPLY (
                        SELECT
                            CASE
                                WHEN tid.codigo_item = N'ARRIENDO'
                                 AND dcd.descripcion_item LIKE N'Arriendo local %'
                                    THEN LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo local ') + 1, 200))
                                WHEN CHARINDEX(N' local ', dcd.descripcion_item) > 0
                                    THEN LTRIM(RTRIM(
                                        CASE
                                            WHEN CHARINDEX(
                                                N':',
                                                SUBSTRING(
                                                    dcd.descripcion_item,
                                                    CHARINDEX(N' local ', dcd.descripcion_item) + LEN(N' local '),
                                                    200
                                                )
                                            ) > 0
                                                THEN LEFT(
                                                    SUBSTRING(
                                                        dcd.descripcion_item,
                                                        CHARINDEX(N' local ', dcd.descripcion_item) + LEN(N' local '),
                                                        200
                                                    ),
                                                    CHARINDEX(
                                                        N':',
                                                        SUBSTRING(
                                                            dcd.descripcion_item,
                                                            CHARINDEX(N' local ', dcd.descripcion_item) + LEN(N' local '),
                                                            200
                                                        )
                                                    ) - 1
                                                )
                                            ELSE SUBSTRING(
                                                dcd.descripcion_item,
                                                CHARINDEX(N' local ', dcd.descripcion_item) + LEN(N' local '),
                                                200
                                            )
                                        END
                                    ))
                                ELSE NULL
                            END AS cdo_local
                    ) parseo
                    LEFT JOIN dbo.msp_cobros_servicios cs
                        ON cs.id_cobro_servicio = dcd.id_cobro_servicio
                    LEFT JOIN dbo.msp_lecturas_medidores lm
                        ON lm.id_lectura = cs.id_lectura
                    LEFT JOIN dbo.msp_medidores m
                        ON m.id_medidor = lm.id_medidor
                    LEFT JOIN dbo.msp_locales loc
                        ON loc.id_local = m.id_local
                )
                SELECT
                    db.id_tienda,
                    db.cdo_local,
                    ROUND(SUM(db.subtotal), 2) AS monto_total_local,
                    ROUND(SUM(CASE WHEN db.codigo_item = N'ARRIENDO' THEN db.subtotal ELSE 0 END), 2) AS monto_arriendo,
                    ROUND(SUM(CASE WHEN db.codigo_item = N'SERVICIO_LUZ' THEN db.subtotal ELSE 0 END), 2) AS monto_luz,
                    ROUND(SUM(CASE WHEN db.codigo_item = N'SERVICIO_GAS' THEN db.subtotal ELSE 0 END), 2) AS monto_gas,
                    ROUND(SUM(CASE WHEN db.codigo_item = N'SERVICIO_AGUA' THEN db.subtotal ELSE 0 END), 2) AS monto_agua,
                    ROUND(SUM(CASE WHEN db.codigo_item NOT IN (N'ARRIENDO', N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA') THEN db.subtotal ELSE 0 END), 2) AS monto_otros
                FROM detalle_base db
                GROUP BY
                    db.id_tienda,
                    db.cdo_local
                ORDER BY
                    db.id_tienda,
                    " . msp2LocalCodeNaturalOrderSql('db.cdo_local') . ""
            );

            foreach ($documentosParams as $key => $value) {
                $stmtDetalleLocales->bindValue($key, $value, PDO::PARAM_STR);
            }
            $stmtDetalleLocales->execute();
            foreach ($stmtDetalleLocales->fetchAll() as $detalleRow) {
                $tiendaId = (int) ($detalleRow['id_tienda'] ?? 0);
                if (!isset($detalleLocalesPorTienda[$tiendaId])) {
                    $detalleLocalesPorTienda[$tiendaId] = [];
                }
                $detalleLocalesPorTienda[$tiendaId][] = $detalleRow;
                $composicionFacturacion['arriendo'] += (float) ($detalleRow['monto_arriendo'] ?? 0);
                $composicionFacturacion['luz'] += (float) ($detalleRow['monto_luz'] ?? 0);
                $composicionFacturacion['gas'] += (float) ($detalleRow['monto_gas'] ?? 0);
                $composicionFacturacion['agua'] += (float) ($detalleRow['monto_agua'] ?? 0);
                $composicionFacturacion['otros'] += (float) ($detalleRow['monto_otros'] ?? 0);
                $totalServiciosLocal = (float) ($detalleRow['monto_luz'] ?? 0)
                    + (float) ($detalleRow['monto_gas'] ?? 0)
                    + (float) ($detalleRow['monto_agua'] ?? 0);
                $codigoLocalConsumo = trim((string) ($detalleRow['cdo_local'] ?? ''));
                if ($totalServiciosLocal > 0.005 && $codigoLocalConsumo !== '') {
                    $localesConsumoActual[$codigoLocalConsumo] = true;
                }
            }
            $consumoKpi['locales_con_consumo'] = count($localesConsumoActual);

            $stmtConsumoComparativo = $conn->prepare(
                "WITH docs_periodo AS (
                    SELECT
                        dc.id_documento_cobro,
                        CASE
                            WHEN dc.periodo_facturacion >= :consumo_periodo_actual_inicio
                             AND dc.periodo_facturacion <= :consumo_periodo_actual_fin THEN N'actual'
                            WHEN dc.periodo_facturacion >= :consumo_periodo_prev_inicio
                             AND dc.periodo_facturacion <= :consumo_periodo_prev_fin THEN N'anterior'
                            ELSE NULL
                        END AS bloque
                    FROM dbo.msp_documentos_cobro dc
                    WHERE dc.estado_documento <> 5
                      AND (
                        (dc.periodo_facturacion >= :consumo_periodo_actual_inicio_filtro
                         AND dc.periodo_facturacion <= :consumo_periodo_actual_fin_filtro)
                        OR
                        (dc.periodo_facturacion >= :consumo_periodo_prev_inicio_filtro
                         AND dc.periodo_facturacion <= :consumo_periodo_prev_fin_filtro)
                      )
                )
                SELECT
                    dp.bloque,
                    ROUND(SUM(CASE WHEN tid.codigo_item = N'SERVICIO_LUZ' THEN dcd.subtotal ELSE 0 END), 2) AS monto_luz,
                    ROUND(SUM(CASE WHEN tid.codigo_item = N'SERVICIO_GAS' THEN dcd.subtotal ELSE 0 END), 2) AS monto_gas,
                    ROUND(SUM(CASE WHEN tid.codigo_item = N'SERVICIO_AGUA' THEN dcd.subtotal ELSE 0 END), 2) AS monto_agua
                FROM docs_periodo dp
                INNER JOIN dbo.msp_documentos_cobro_detalle dcd
                    ON dcd.id_documento_cobro = dp.id_documento_cobro
                INNER JOIN dbo.msp_tipo_item_documento tid
                    ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                WHERE dp.bloque IS NOT NULL
                  AND tid.codigo_item IN (N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA')
                GROUP BY dp.bloque"
            );
            $stmtConsumoComparativo->bindValue(':consumo_periodo_actual_inicio', $fechaInicioPeriodoDashboard, PDO::PARAM_STR);
            $stmtConsumoComparativo->bindValue(':consumo_periodo_actual_fin', $fechaTerminoPeriodoDashboard, PDO::PARAM_STR);
            $stmtConsumoComparativo->bindValue(':consumo_periodo_prev_inicio', $fechaInicioPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtConsumoComparativo->bindValue(':consumo_periodo_prev_fin', $fechaTerminoPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtConsumoComparativo->bindValue(':consumo_periodo_actual_inicio_filtro', $fechaInicioPeriodoDashboard, PDO::PARAM_STR);
            $stmtConsumoComparativo->bindValue(':consumo_periodo_actual_fin_filtro', $fechaTerminoPeriodoDashboard, PDO::PARAM_STR);
            $stmtConsumoComparativo->bindValue(':consumo_periodo_prev_inicio_filtro', $fechaInicioPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtConsumoComparativo->bindValue(':consumo_periodo_prev_fin_filtro', $fechaTerminoPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtConsumoComparativo->execute();
            foreach ($stmtConsumoComparativo->fetchAll() ?: [] as $consumoRow) {
                $bloque = (string) ($consumoRow['bloque'] ?? '');
                $totalBloque = (float) ($consumoRow['monto_luz'] ?? 0)
                    + (float) ($consumoRow['monto_gas'] ?? 0)
                    + (float) ($consumoRow['monto_agua'] ?? 0);
                if ($bloque === 'actual') {
                    $consumoKpi['total_actual'] = $totalBloque;
                } elseif ($bloque === 'anterior') {
                    $consumoKpi['total_anterior'] = $totalBloque;
                }
            }

            $stmtServiciosComparativo = $conn->prepare(
                "WITH docs_periodo AS (
                    SELECT
                        dc.id_documento_cobro,
                        CASE
                            WHEN dc.periodo_facturacion >= :svc_periodo_actual_inicio
                             AND dc.periodo_facturacion <= :svc_periodo_actual_fin THEN N'actual'
                            WHEN dc.periodo_facturacion >= :svc_periodo_prev_inicio
                             AND dc.periodo_facturacion <= :svc_periodo_prev_fin THEN N'anterior'
                            ELSE NULL
                        END AS bloque
                    FROM dbo.msp_documentos_cobro dc
                    WHERE dc.estado_documento <> 5
                      AND (
                        (dc.periodo_facturacion >= :svc_periodo_actual_inicio_filtro
                         AND dc.periodo_facturacion <= :svc_periodo_actual_fin_filtro)
                        OR
                        (dc.periodo_facturacion >= :svc_periodo_prev_inicio_filtro
                         AND dc.periodo_facturacion <= :svc_periodo_prev_fin_filtro)
                      )
                )
                SELECT
                    dp.bloque,
                    tid.codigo_item,
                    ROUND(SUM(ISNULL(cs.consumo_cobrado, 0)), 4) AS consumo_unidad,
                    ROUND(SUM(ISNULL(cs.monto_total, 0)), 2) AS monto_clp
                FROM docs_periodo dp
                INNER JOIN dbo.msp_documentos_cobro_detalle dcd
                    ON dcd.id_documento_cobro = dp.id_documento_cobro
                INNER JOIN dbo.msp_tipo_item_documento tid
                    ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                INNER JOIN dbo.msp_cobros_servicios cs
                    ON cs.id_cobro_servicio = dcd.id_cobro_servicio
                WHERE dp.bloque IS NOT NULL
                  AND tid.codigo_item IN (N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA')
                GROUP BY dp.bloque, tid.codigo_item"
            );
            $stmtServiciosComparativo->bindValue(':svc_periodo_actual_inicio', $fechaInicioPeriodoDashboard, PDO::PARAM_STR);
            $stmtServiciosComparativo->bindValue(':svc_periodo_actual_fin', $fechaTerminoPeriodoDashboard, PDO::PARAM_STR);
            $stmtServiciosComparativo->bindValue(':svc_periodo_prev_inicio', $fechaInicioPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtServiciosComparativo->bindValue(':svc_periodo_prev_fin', $fechaTerminoPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtServiciosComparativo->bindValue(':svc_periodo_actual_inicio_filtro', $fechaInicioPeriodoDashboard, PDO::PARAM_STR);
            $stmtServiciosComparativo->bindValue(':svc_periodo_actual_fin_filtro', $fechaTerminoPeriodoDashboard, PDO::PARAM_STR);
            $stmtServiciosComparativo->bindValue(':svc_periodo_prev_inicio_filtro', $fechaInicioPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtServiciosComparativo->bindValue(':svc_periodo_prev_fin_filtro', $fechaTerminoPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtServiciosComparativo->execute();
            $codigoServicioKeyMap = [
                'SERVICIO_LUZ' => 'luz',
                'SERVICIO_GAS' => 'gas',
                'SERVICIO_AGUA' => 'agua',
            ];
            foreach ($stmtServiciosComparativo->fetchAll() ?: [] as $servicioRow) {
                $codigoServicio = strtoupper(trim((string) ($servicioRow['codigo_item'] ?? '')));
                $servicioKey = $codigoServicioKeyMap[$codigoServicio] ?? null;
                if ($servicioKey === null) {
                    continue;
                }
                $bloque = (string) ($servicioRow['bloque'] ?? '');
                if ($bloque === 'actual') {
                    $consumoServicios[$servicioKey]['consumo_actual'] = (float) ($servicioRow['consumo_unidad'] ?? 0);
                    $consumoServicios[$servicioKey]['costo_actual'] = (float) ($servicioRow['monto_clp'] ?? 0);
                } elseif ($bloque === 'anterior') {
                    $consumoServicios[$servicioKey]['consumo_anterior'] = (float) ($servicioRow['consumo_unidad'] ?? 0);
                }
            }

            $stmtConsumoHistorial = $conn->prepare(
                "SELECT
                    dc.periodo_facturacion,
                    ROUND(SUM(CASE WHEN tid.codigo_item = N'SERVICIO_LUZ' THEN dcd.subtotal ELSE 0 END), 2) AS monto_luz,
                    ROUND(SUM(CASE WHEN tid.codigo_item = N'SERVICIO_GAS' THEN dcd.subtotal ELSE 0 END), 2) AS monto_gas,
                    ROUND(SUM(CASE WHEN tid.codigo_item = N'SERVICIO_AGUA' THEN dcd.subtotal ELSE 0 END), 2) AS monto_agua
                FROM dbo.msp_documentos_cobro dc
                INNER JOIN dbo.msp_documentos_cobro_detalle dcd
                    ON dcd.id_documento_cobro = dc.id_documento_cobro
                INNER JOIN dbo.msp_tipo_item_documento tid
                    ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                WHERE dc.estado_documento <> 5
                  AND dc.periodo_facturacion >= :consumo_hist_inicio
                  AND dc.periodo_facturacion <= :consumo_hist_fin
                  AND tid.codigo_item IN (N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA')
                GROUP BY dc.periodo_facturacion
                ORDER BY dc.periodo_facturacion ASC"
            );
            $stmtConsumoHistorial->bindValue(':consumo_hist_inicio', $fechaInicioChartDashboard, PDO::PARAM_STR);
            $stmtConsumoHistorial->bindValue(':consumo_hist_fin', $fechaTerminoChartDashboard, PDO::PARAM_STR);
            $stmtConsumoHistorial->execute();
            $consumoHistorialMap = [];
            foreach ($stmtConsumoHistorial->fetchAll() ?: [] as $consumoHistRow) {
                $monthKey = dashboardMonthKey((string) ($consumoHistRow['periodo_facturacion'] ?? ''));
                if ($monthKey === '') {
                    continue;
                }
                $consumoHistorialMap[$monthKey] = [
                    'luz' => round((float) ($consumoHistRow['monto_luz'] ?? 0), 2),
                    'gas' => round((float) ($consumoHistRow['monto_gas'] ?? 0), 2),
                    'agua' => round((float) ($consumoHistRow['monto_agua'] ?? 0), 2),
                ];
            }

            $stmtServiciosHistorial = $conn->prepare(
                "SELECT
                    dc.periodo_facturacion,
                    tid.codigo_item,
                    ROUND(SUM(ISNULL(cs.consumo_cobrado, 0)), 4) AS consumo_unidad,
                    ROUND(SUM(ISNULL(cs.monto_total, 0)), 2) AS monto_clp
                FROM dbo.msp_documentos_cobro dc
                INNER JOIN dbo.msp_documentos_cobro_detalle dcd
                    ON dcd.id_documento_cobro = dc.id_documento_cobro
                INNER JOIN dbo.msp_tipo_item_documento tid
                    ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                INNER JOIN dbo.msp_cobros_servicios cs
                    ON cs.id_cobro_servicio = dcd.id_cobro_servicio
                WHERE dc.estado_documento <> 5
                  AND dc.periodo_facturacion >= :svc_hist_inicio
                  AND dc.periodo_facturacion <= :svc_hist_fin
                  AND tid.codigo_item IN (N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA')
                GROUP BY dc.periodo_facturacion, tid.codigo_item
                ORDER BY dc.periodo_facturacion ASC"
            );
            $stmtServiciosHistorial->bindValue(':svc_hist_inicio', $fechaInicioChartDashboard, PDO::PARAM_STR);
            $stmtServiciosHistorial->bindValue(':svc_hist_fin', $fechaTerminoChartDashboard, PDO::PARAM_STR);
            $stmtServiciosHistorial->execute();
            $serviciosHistorialMap = [
                'luz' => [],
                'gas' => [],
                'agua' => [],
            ];
            foreach ($stmtServiciosHistorial->fetchAll() ?: [] as $servicioHistRow) {
                $monthKey = dashboardMonthKey((string) ($servicioHistRow['periodo_facturacion'] ?? ''));
                $codigoServicio = strtoupper(trim((string) ($servicioHistRow['codigo_item'] ?? '')));
                $servicioKey = $codigoServicioKeyMap[$codigoServicio] ?? null;
                if ($monthKey === '' || $servicioKey === null) {
                    continue;
                }
                $serviciosHistorialMap[$servicioKey][$monthKey] = [
                    'consumo' => round((float) ($servicioHistRow['consumo_unidad'] ?? 0), 4),
                    'costo' => round((float) ($servicioHistRow['monto_clp'] ?? 0), 2),
                ];
            }

            $stmtConsumoTopLocales = $conn->prepare(
                "WITH docs_periodo AS (
                    SELECT
                        dc.id_documento_cobro,
                        CASE
                            WHEN dc.periodo_facturacion >= :top_local_actual_inicio
                             AND dc.periodo_facturacion <= :top_local_actual_fin THEN N'actual'
                            WHEN dc.periodo_facturacion >= :top_local_prev_inicio
                             AND dc.periodo_facturacion <= :top_local_prev_fin THEN N'anterior'
                            ELSE NULL
                        END AS bloque
                    FROM dbo.msp_documentos_cobro dc
                    WHERE dc.estado_documento <> 5
                      AND (
                        (dc.periodo_facturacion >= :top_local_actual_inicio_filtro
                         AND dc.periodo_facturacion <= :top_local_actual_fin_filtro)
                        OR
                        (dc.periodo_facturacion >= :top_local_prev_inicio_filtro
                         AND dc.periodo_facturacion <= :top_local_prev_fin_filtro)
                      )
                ),
                detalle_servicios AS (
                    SELECT
                        dp.bloque,
                        COALESCE(loc.cdo_local, parseo.cdo_local, N'SIN LOCAL') AS cdo_local,
                        dcd.subtotal
                    FROM docs_periodo dp
                    INNER JOIN dbo.msp_documentos_cobro_detalle dcd
                        ON dcd.id_documento_cobro = dp.id_documento_cobro
                    INNER JOIN dbo.msp_tipo_item_documento tid
                        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                    OUTER APPLY (
                        SELECT
                            CASE
                                WHEN CHARINDEX(N' local ', dcd.descripcion_item) > 0
                                    THEN LTRIM(RTRIM(
                                        CASE
                                            WHEN CHARINDEX(
                                                N':',
                                                SUBSTRING(
                                                    dcd.descripcion_item,
                                                    CHARINDEX(N' local ', dcd.descripcion_item) + LEN(N' local '),
                                                    200
                                                )
                                            ) > 0
                                                THEN LEFT(
                                                    SUBSTRING(
                                                        dcd.descripcion_item,
                                                        CHARINDEX(N' local ', dcd.descripcion_item) + LEN(N' local '),
                                                        200
                                                    ),
                                                    CHARINDEX(
                                                        N':',
                                                        SUBSTRING(
                                                            dcd.descripcion_item,
                                                            CHARINDEX(N' local ', dcd.descripcion_item) + LEN(N' local '),
                                                            200
                                                        )
                                                    ) - 1
                                                )
                                            ELSE SUBSTRING(
                                                dcd.descripcion_item,
                                                CHARINDEX(N' local ', dcd.descripcion_item) + LEN(N' local '),
                                                200
                                            )
                                        END
                                    ))
                                ELSE NULL
                            END AS cdo_local
                    ) parseo
                    LEFT JOIN dbo.msp_cobros_servicios cs
                        ON cs.id_cobro_servicio = dcd.id_cobro_servicio
                    LEFT JOIN dbo.msp_lecturas_medidores lm
                        ON lm.id_lectura = cs.id_lectura
                    LEFT JOIN dbo.msp_medidores m
                        ON m.id_medidor = lm.id_medidor
                    LEFT JOIN dbo.msp_locales loc
                        ON loc.id_local = m.id_local
                    WHERE dp.bloque IS NOT NULL
                      AND tid.codigo_item IN (N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA')
                ),
                agregados AS (
                    SELECT
                        cdo_local,
                        ROUND(SUM(CASE WHEN bloque = N'actual' THEN subtotal ELSE 0 END), 2) AS total_actual,
                        ROUND(SUM(CASE WHEN bloque = N'anterior' THEN subtotal ELSE 0 END), 2) AS total_anterior
                    FROM detalle_servicios
                    GROUP BY cdo_local
                )
                SELECT TOP 10
                    cdo_local,
                    total_actual,
                    total_anterior
                FROM agregados
                WHERE total_actual > 0.005 OR total_anterior > 0.005
                ORDER BY total_actual DESC, total_anterior DESC, " . msp2LocalCodeNaturalOrderSql('cdo_local')
            );
            $stmtConsumoTopLocales->bindValue(':top_local_actual_inicio', $fechaInicioPeriodoDashboard, PDO::PARAM_STR);
            $stmtConsumoTopLocales->bindValue(':top_local_actual_fin', $fechaTerminoPeriodoDashboard, PDO::PARAM_STR);
            $stmtConsumoTopLocales->bindValue(':top_local_prev_inicio', $fechaInicioPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtConsumoTopLocales->bindValue(':top_local_prev_fin', $fechaTerminoPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtConsumoTopLocales->bindValue(':top_local_actual_inicio_filtro', $fechaInicioPeriodoDashboard, PDO::PARAM_STR);
            $stmtConsumoTopLocales->bindValue(':top_local_actual_fin_filtro', $fechaTerminoPeriodoDashboard, PDO::PARAM_STR);
            $stmtConsumoTopLocales->bindValue(':top_local_prev_inicio_filtro', $fechaInicioPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtConsumoTopLocales->bindValue(':top_local_prev_fin_filtro', $fechaTerminoPeriodoAnteriorDashboard->format('Y-m-d'), PDO::PARAM_STR);
            $stmtConsumoTopLocales->execute();
            $consumoTopLocales = $stmtConsumoTopLocales->fetchAll() ?: [];

            if ($consumoTopLocales !== []) {
                $consumoKpi['local_top_nombre'] = (string) ($consumoTopLocales[0]['cdo_local'] ?? '-');
                $consumoKpi['local_top_monto'] = (float) ($consumoTopLocales[0]['total_actual'] ?? 0);
            }
        }

        $stmtDocsLinks = $conn->prepare(
            "WITH pagos_doc AS (
                SELECT
                    p.id_documento_cobro,
                    SUM(CASE WHEN p.estado_pago = 1 THEN p.monto_pagado ELSE 0 END) AS total_pagado
                FROM dbo.msp_pagos p
                WHERE p.fecha_pago >= :fecha_pago_inicio
                  AND p.fecha_pago <= :fecha_pago_fin
                GROUP BY p.id_documento_cobro
            )
            SELECT
                dc.id_tienda,
                dc.id_documento_cobro,
                COALESCE(dc.numero_documento, CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                ISNULL(pd.total_pagado, 0) AS total_pagado_doc,
                ISNULL(dc.monto_total, 0) AS monto_total_doc
            FROM dbo.msp_documentos_cobro dc
            LEFT JOIN pagos_doc pd
                ON pd.id_documento_cobro = dc.id_documento_cobro
            WHERE {$documentosWhere}
              AND dc.estado_documento <> 5
            ORDER BY
                dc.id_tienda ASC,
                dc.id_documento_cobro DESC"
        );

        foreach (array_merge($documentosParams, $pagosParams) as $key => $value) {
            $stmtDocsLinks->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmtDocsLinks->execute();
        $docRows = $stmtDocsLinks->fetchAll();
        foreach ($docRows as $docRow) {
            $tiendaId = (int) ($docRow['id_tienda'] ?? 0);
            if (!isset($documentosPorTienda[$tiendaId])) {
                $documentosPorTienda[$tiendaId] = [];
            }
            $documentosPorTienda[$tiendaId][] = [
                'id_documento_cobro' => (int) ($docRow['id_documento_cobro'] ?? 0),
                'numero_documento' => (string) ($docRow['numero_documento'] ?? ''),
                'total_pagado_doc' => (float) ($docRow['total_pagado_doc'] ?? 0),
                'monto_total_doc' => (float) ($docRow['monto_total_doc'] ?? 0),
            ];
        }

            $stmtHistorial = $conn->prepare(
            "WITH pagos_doc AS (
                SELECT
                    p.id_documento_cobro,
                    SUM(CASE WHEN p.estado_pago = 1 THEN p.monto_pagado ELSE 0 END) AS total_pagado
                FROM dbo.msp_pagos p
                WHERE p.fecha_pago >= :fecha_pago_inicio
                  AND p.fecha_pago <= :fecha_pago_fin
                GROUP BY p.id_documento_cobro
            )
            SELECT
                dc.periodo_facturacion,
                ISNULL(SUM(dc.monto_total), 0) AS monto_facturado,
                ISNULL(SUM(ISNULL(pd.total_pagado, 0)), 0) AS monto_cobrado,
                ISNULL(SUM(dc.saldo_pendiente), 0) AS monto_saldo,
                COUNT(*) AS cantidad_documentos,
                COUNT(DISTINCT t.id_arrendatario) AS cantidad_arrendatarios
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
            LEFT JOIN pagos_doc pd
                ON pd.id_documento_cobro = dc.id_documento_cobro
            WHERE dc.periodo_facturacion >= :hist_periodo_inicio
              AND dc.periodo_facturacion <= :hist_periodo_fin
              AND dc.estado_documento <> 5
            GROUP BY dc.periodo_facturacion
            ORDER BY dc.periodo_facturacion DESC"
        );

        foreach ($pagosParams as $key => $value) {
            $stmtHistorial->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmtHistorial->bindValue(':hist_periodo_inicio', $fechaInicioChartDashboard, PDO::PARAM_STR);
        $stmtHistorial->bindValue(':hist_periodo_fin', $fechaTerminoChartDashboard, PDO::PARAM_STR);
        $stmtHistorial->execute();
        $historialMensual = $stmtHistorial->fetchAll();

        $stmtTopDeudores = $conn->prepare(
            "SELECT TOP 5
                t.id_tienda,
                COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda,
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut, CONCAT(N'Arrendatario #', a.id_arrendatario)) AS nombre_arrendatario,
                a.rut,
                COUNT(*) AS cantidad_documentos,
                ROUND(SUM(dc.saldo_pendiente), 2) AS saldo_pendiente
             FROM dbo.msp_documentos_cobro dc
             INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
             INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = t.id_arrendatario
             WHERE {$documentosWhere}
               AND dc.estado_documento <> 5
             GROUP BY
                t.id_tienda,
                COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)),
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut, CONCAT(N'Arrendatario #', a.id_arrendatario)),
                a.rut
             HAVING SUM(dc.saldo_pendiente) > 0.005
             ORDER BY SUM(dc.saldo_pendiente) DESC, COUNT(*) DESC, nombre_tienda ASC"
        );
        foreach ($documentosParams as $key => $value) {
            $stmtTopDeudores->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmtTopDeudores->execute();
        $topDeudores = $stmtTopDeudores->fetchAll();

        $insightsKpi['ticket_promedio'] = $resumenKpi['documentos'] > 0
            ? ($resumenKpi['facturado'] / $resumenKpi['documentos'])
            : 0.0;
        $insightsKpi['saldo_promedio_deudor'] = $resumenKpi['arrendatarios_con_deuda'] > 0
            ? ($resumenKpi['saldo'] / $resumenKpi['arrendatarios_con_deuda'])
            : 0.0;
        $insightsKpi['morosidad_vencida_pct'] = $resumenKpi['saldo'] > 0
            ? (($operacionKpi['monto_vencido'] / $resumenKpi['saldo']) * 100)
            : 0.0;

        foreach ($registrosPorTienda as $tiendaRow) {
            if ((float) ($tiendaRow['monto_saldo'] ?? 0) > 0.005) {
                $insightsKpi['tiendas_con_deuda']++;
            }
        }

        $consumoKpi['variacion_nominal'] = $consumoKpi['total_actual'] - $consumoKpi['total_anterior'];
        $consumoKpi['variacion_pct'] = $consumoKpi['total_anterior'] > 0
            ? (($consumoKpi['variacion_nominal'] / $consumoKpi['total_anterior']) * 100)
            : ($consumoKpi['total_actual'] > 0 ? 100.0 : 0.0);
        foreach ($consumoServicios as $servicioKey => $servicioData) {
            $consumoAnterior = (float) ($servicioData['consumo_anterior'] ?? 0);
            $consumoActual = (float) ($servicioData['consumo_actual'] ?? 0);
            $costoActual = (float) ($servicioData['costo_actual'] ?? 0);
            $consumoServicios[$servicioKey]['variacion_pct'] = $consumoAnterior > 0
                ? ((($consumoActual - $consumoAnterior) / $consumoAnterior) * 100)
                : ($consumoActual > 0 ? 100.0 : 0.0);
            $consumoServicios[$servicioKey]['costo_promedio'] = $consumoActual > 0
                ? ($costoActual / $consumoActual)
                : 0.0;
        }
        $consumoKpi['promedio_local'] = $consumoKpi['locales_con_consumo'] > 0
            ? ($consumoKpi['total_actual'] / $consumoKpi['locales_con_consumo'])
            : 0.0;

        $historialMap = [];
        foreach ($historialMensual as $histRow) {
            $monthKey = dashboardMonthKey((string) ($histRow['periodo_facturacion'] ?? ''));
            if ($monthKey === '') {
                continue;
            }
            $historialMap[$monthKey] = [
                'facturado' => round((float) ($histRow['monto_facturado'] ?? 0), 2),
                'cobrado' => round((float) ($histRow['monto_cobrado'] ?? 0), 2),
                'saldo' => round((float) ($histRow['monto_saldo'] ?? 0), 2),
            ];
        }

        $chartCursor = $fechaInicioChartDashboardDate;
        for ($i = 0; $i < 12; $i++) {
            $monthKey = $chartCursor->format('Y-m');
            $chartSeries['historial_labels'][] = $chartCursor->format('m-Y');
            $chartSeries['historial_facturado'][] = (float) ($historialMap[$monthKey]['facturado'] ?? 0);
            $chartSeries['historial_cobrado'][] = (float) ($historialMap[$monthKey]['cobrado'] ?? 0);
            $chartSeries['historial_saldo'][] = (float) ($historialMap[$monthKey]['saldo'] ?? 0);
            $chartSeries['consumo_labels'][] = $chartCursor->format('m-Y');
            $chartSeries['consumo_luz'][] = (float) ($consumoHistorialMap[$monthKey]['luz'] ?? 0);
            $chartSeries['consumo_gas'][] = (float) ($consumoHistorialMap[$monthKey]['gas'] ?? 0);
            $chartSeries['consumo_agua'][] = (float) ($consumoHistorialMap[$monthKey]['agua'] ?? 0);
            $chartSeries['servicio_combo_labels'][] = $chartCursor->format('m-Y');
            foreach (['luz', 'gas', 'agua'] as $servicioKey) {
                $chartSeries['servicio_combo'][$servicioKey]['consumo'][] = (float) ($serviciosHistorialMap[$servicioKey][$monthKey]['consumo'] ?? 0);
                $chartSeries['servicio_combo'][$servicioKey]['costo'][] = (float) ($serviciosHistorialMap[$servicioKey][$monthKey]['costo'] ?? 0);
            }
            $chartCursor = $chartCursor->modify('+1 month');
        }

        foreach ($topDeudores as $deudorRow) {
            $chartSeries['top_deudores_labels'][] = (string) ($deudorRow['nombre_tienda'] ?? 'Tienda');
            $chartSeries['top_deudores_values'][] = round((float) ($deudorRow['saldo_pendiente'] ?? 0), 2);
        }
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar el dashboard. Detalle tecnico: ' . $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Panel de gestión</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css?v=<?php echo rawurlencode((string) filemtime(dirname(__DIR__, 2) . '/styles.css')); ?>">
    <style>
        .dashboard-shell { width: 100%; max-width: 1640px; margin: 0 auto; }
        .dash-page-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
            align-items: center;
            gap: 1rem;
        }
        .dash-page-title { color: #003c96; font-size: clamp(1.7rem, 2.4vw, 2.25rem); }
        .dash-page-actions { justify-self: end; }
        .dash-filter-card .card-body { padding: .72rem .8rem; }
        .dash-filter-card .form-label { margin-bottom: .25rem; font-size: .82rem; }
        .dash-filter-card .form-control,
        .dash-filter-card .btn { min-height: 40px; }
        .dash-filter-tools { min-width: min(100%, 440px); }
        .dash-consumption-links {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: .45rem;
        }
        .dash-consumption-links-title {
            color: #123f72;
            font-weight: 700;
            margin-right: .2rem;
        }
        .dash-consumption-links .btn { min-height: 34px; padding: .3rem .62rem; }
        .dash-kpi-card { border: 1px solid var(--color-border); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); }
        .dash-kpi-label { font-size: .68rem; text-transform: uppercase; color: var(--color-text-muted); letter-spacing: .04em; }
        .dash-kpi-value { font-weight: 700; font-size: 1.12rem; line-height: 1.18; }
        .dash-kpi-sub { font-size: .72rem; line-height: 1.25; color: var(--color-text-muted); }
        .dash-summary-kpis {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: .55rem;
        }
        .dash-summary-kpis > [class*="col-"] { width: auto; padding: 0; }
        .dash-summary-kpis .dash-kpi-card,
        .dash-summary-kpis .dash-overview-card {
            min-height: 92px;
            padding: .65rem .72rem !important;
        }
        .dash-summary-kpis .dash-overview-value { font-size: 1.12rem; line-height: 1.18; }
        .dash-summary-kpis .dash-overview-label { font-size: .68rem; margin-bottom: .2rem; }
        .dash-summary-kpis .dash-overview-sub { font-size: .72rem; line-height: 1.25; }
        .dash-table thead th { white-space: nowrap; position: sticky; top: 0; z-index: 1; background-color: #f1f5f9; }
        .dash-col-money { text-align: right; white-space: nowrap; }
        .dash-local-row { background: #eef4fb; font-weight: 700; cursor: pointer; }
        .dash-local-row:hover { background: #e3edf9; }
        .dash-local-detail { background: #ffffff; }
        .dash-local-detail td { border-top: 0; }
        .dash-toggle {
            display: inline-flex;
            width: 28px;
            height: 28px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #334155;
            transition: transform .2s ease;
        }
        .dash-local-row[aria-expanded="true"] .dash-toggle {
            transform: rotate(180deg);
        }
        .d-none-local { display: none; }
        .dash-doc-link {
            color: #1d4ed8;
            text-decoration: underline;
            text-underline-offset: 2px;
            font-weight: 600;
        }
        .dash-doc-link:hover,
        .dash-doc-link:focus {
            color: #1e40af;
        }
        .dash-doc-list {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
        }
        .dash-section-title {
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--color-text-muted);
            margin-bottom: .75rem;
        }
        .dash-kpi-card.is-accent {
            background: linear-gradient(135deg, #0f3d68 0%, #1d5f91 100%);
            color: #fff;
            border-color: transparent;
        }
        .dash-kpi-card.is-accent .dash-kpi-label,
        .dash-kpi-card.is-accent .dash-kpi-sub {
            color: rgba(255, 255, 255, 0.82);
        }
        .dash-mini-table td,
        .dash-mini-table th {
            vertical-align: middle;
        }
        .dash-chart-card {
            border: 1px solid #dbe4f0;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-sm);
            background: linear-gradient(135deg, #f8fbff 0%, #eef4fb 100%);
            padding: .8rem;
            height: 100%;
        }
        .dash-chart-wrap {
            position: relative;
            min-height: 280px;
        }
        .dash-chart-wrap.is-tall {
            min-height: 235px;
        }
        .dash-primary-chart .dash-chart-wrap {
            min-height: 365px;
        }
        .dash-consumption-kpis {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .4rem;
            align-content: start;
        }
        .dash-consumption-kpis .dash-kpi-card,
        .dash-consumption-kpis .dash-overview-card {
            min-height: 0;
            height: auto;
            padding: .45rem .55rem !important;
        }
        .dash-consumption-kpis .dash-kpi-label,
        .dash-consumption-kpis .dash-overview-label { font-size: .63rem; margin-bottom: .15rem; }
        .dash-consumption-kpis .dash-kpi-value,
        .dash-consumption-kpis .dash-overview-value { font-size: .98rem; line-height: 1.15; }
        .dash-consumption-kpis .dash-kpi-sub,
        .dash-consumption-kpis .dash-overview-sub { font-size: .66rem; line-height: 1.2; }
        .dash-axis-note { font-size: .7rem; color: var(--color-text-muted); }
        .dash-history-collapse .card-header { padding: .55rem .75rem; }
        .dash-top-extra.d-none { display: none !important; }
        .dash-composition-chart { min-height: 205px; }
        .dash-composition-detail {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .35rem;
            margin-top: .35rem;
        }
        .dash-composition-item {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
            gap: .35rem;
            padding: .32rem .42rem;
            border: 1px solid rgba(148, 163, 184, .25);
            border-radius: 7px;
            background: rgba(255, 255, 255, .55);
            font-size: .7rem;
        }
        .dash-composition-dot { width: 8px; height: 8px; border-radius: 50%; }
        .dash-composition-value { font-weight: 700; white-space: nowrap; }
        .dash-composition-percent { color: var(--color-text-muted); font-size: .64rem; }
        .dash-composition-item:last-child:nth-child(odd) { grid-column: 1 / -1; }
        .dash-service-charts .dash-chart-card {
            padding: .7rem;
        }
        .dash-service-charts .dash-chart-heading {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: .45rem;
            margin-bottom: .35rem;
        }
        .dash-service-charts .dash-toggle-group {
            width: 100%;
            justify-content: center;
        }
        .dash-service-charts .dash-toggle-chip {
            flex: 1 1 auto;
            padding: .3rem .45rem;
        }
        .dash-filter-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .45rem .75rem;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: .85rem;
            font-weight: 600;
        }
        .dash-overview-card {
            border: 1px solid #dbe4f0;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, #f8fbff 0%, #eef4fb 100%);
            padding: 1rem 1.1rem;
            height: 100%;
        }
        .dash-overview-label {
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #5b6b82;
            margin-bottom: .35rem;
        }
        .dash-overview-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: #12324f;
        }
        .dash-overview-sub {
            color: #64748b;
            font-size: .84rem;
        }
        .dash-toggle-group {
            display: inline-flex;
            gap: .35rem;
            padding: .3rem;
            border: 1px solid #dbe4f0;
            border-radius: 999px;
            background: #f8fafc;
        }
        .dash-toggle-chip {
            border: 0;
            border-radius: 999px;
            background: transparent;
            color: #475569;
            font-size: .78rem;
            font-weight: 600;
            padding: .35rem .7rem;
            line-height: 1.1;
        }
        .dash-toggle-chip.is-active {
            background: #123f72;
            color: #fff;
            box-shadow: 0 8px 20px rgba(18, 63, 114, 0.18);
        }
        @media (max-width: 767.98px) {
            .dash-page-header { grid-template-columns: 1fr; text-align: center; }
            .dash-page-header > * { justify-self: center; }
            .dash-page-title { grid-row: 1; }
            .dash-page-back { grid-row: 2; }
            .dash-page-actions { grid-row: 3; }
            .dash-primary-chart .dash-chart-wrap { min-height: 285px; }
            .dash-summary-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .dash-consumption-kpis { grid-template-columns: 1fr; }
            .dash-filter-tools { min-width: 0; }
            .dash-consumption-links { justify-content: flex-start; flex-wrap: wrap; }
        }
        @media (min-width: 768px) and (max-width: 1199.98px) {
            .dash-summary-kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-2 p-xl-3">
    <div class="dashboard-shell">
        <div class="dash-page-header mb-2" data-gp-commandbar>
            <div class="dash-page-back">
            <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a MSP
            </a>
            </div>
            <h1 class="dash-page-title fw-semibold text-center mb-0">Dashboard Mercado</h1>
            <div class="dash-page-actions d-flex gap-2">
                <a href="<?php echo msp2Escape(msp2Url('dashboard/recaudacion.php')); ?>" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Recaudación
                </a>
            </div>
        </div>

        <?php msp2RenderFlash($flash); ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div>
        <?php endif; ?>

        <?php if ($tablaExiste && $loadError === null): ?>
            <div class="card border-0 shadow-sm mb-2 dash-filter-card">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end">
                        <div class="col-12 col-md-4 col-lg-3">
                            <label for="fecha_inicio" class="form-label">Desde</label>
                            <input type="date" id="fecha_inicio" name="fecha_inicio" class="form-control" value="<?php echo msp2Escape($filtroFechaInicio); ?>">
                        </div>
                        <div class="col-12 col-md-4 col-lg-3">
                            <label for="fecha_termino" class="form-label">Hasta</label>
                            <input type="date" id="fecha_termino" name="fecha_termino" class="form-control" value="<?php echo msp2Escape($filtroFechaTermino); ?>">
                        </div>
                        <div class="col-12 col-md-auto">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-funnel me-1" aria-hidden="true"></i>Aplicar
                            </button>
                        </div>
                        <div class="col-12 col-lg-auto ms-lg-auto dash-filter-tools">
                            <div class="dash-consumption-links mb-1">
                                <span class="dash-consumption-links-title">Consumo</span>
                                <a href="<?php echo msp2Escape(msp2Url('reportes/consumo_electrico.php')); ?>" class="btn btn-outline-warning btn-sm">
                                    <i class="bi bi-lightning-charge me-1" aria-hidden="true"></i>Eléctrico
                                </a>
                                <a href="<?php echo msp2Escape(msp2Url('reportes/consumo_gas.php')); ?>" class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-fire me-1" aria-hidden="true"></i>Gas
                                </a>
                                <a href="<?php echo msp2Escape(msp2Url('reportes/consumo_agua.php')); ?>" class="btn btn-outline-info btn-sm">
                                    <i class="bi bi-droplet me-1" aria-hidden="true"></i>Agua
                                </a>
                            </div>
                            <div class="d-flex flex-wrap justify-content-lg-end gap-1">
                                <span class="dash-filter-chip">
                                    <i class="bi bi-calendar-range" aria-hidden="true"></i>
                                    <?php echo msp2Escape($dashboardScopeLabel); ?>
                                </span>
                                <span class="dash-filter-chip">
                                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                                    Corte <?php echo msp2Escape((new DateTimeImmutable($fechaCorteDashboard))->format('d-m-Y')); ?>
                                </span>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <section>
                    <div class="dash-summary-kpis mb-2">
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="dash-kpi-card is-accent p-3 h-100">
                                <div class="dash-kpi-label">Emitido bruto</div>
                                <div class="dash-kpi-value"><?php echo msp2Escape(dashboardFmtMonto($resumenKpi['facturado'])); ?></div>
                                <div class="dash-kpi-sub"><?php echo (int) $resumenKpi['documentos']; ?> documentos | arriendo + servicios + otros</div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="dash-kpi-card p-3 bg-white h-100">
                                <div class="dash-kpi-label">Cobrado</div>
                                <div class="dash-kpi-value"><?php echo msp2Escape(dashboardFmtMonto($resumenKpi['cobrado'])); ?></div>
                                <div class="dash-kpi-sub">Recaudación <?php echo msp2Escape(dashboardFmtPorcentaje($resumenKpi['recaudacion_pct'])); ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="dash-kpi-card p-3 bg-white h-100">
                                <div class="dash-kpi-label">Saldo pendiente</div>
                                <div class="dash-kpi-value"><?php echo msp2Escape(dashboardFmtMonto($resumenKpi['saldo'])); ?></div>
                                <div class="dash-kpi-sub"><?php echo (int) $resumenKpi['arrendatarios_con_deuda']; ?> arrendatarios con deuda</div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="dash-kpi-card p-3 bg-white h-100">
                                <div class="dash-kpi-label">Morosidad vencida</div>
                                <div class="dash-kpi-value"><?php echo msp2Escape(dashboardFmtMonto($operacionKpi['monto_vencido'])); ?></div>
                                <div class="dash-kpi-sub"><?php echo (int) $operacionKpi['documentos_vencidos']; ?> documentos vencidos</div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="dash-overview-card">
                                <div class="dash-overview-label">Contratos vigentes</div>
                                <div class="dash-overview-value"><?php echo (int) $operacionKpi['contratos_vigentes']; ?></div>
                                <div class="dash-overview-sub"><?php echo (int) $operacionKpi['contratos_en_liquidacion']; ?> en liquidación</div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="dash-overview-card">
                                <div class="dash-overview-label">Ocupación</div>
                                <div class="dash-overview-value"><?php echo (int) $operacionKpi['locales_ocupados']; ?> / <?php echo (int) $operacionKpi['locales_total']; ?></div>
                                <div class="dash-overview-sub"><?php echo msp2Escape(dashboardFmtPorcentaje($operacionKpi['ocupacion_pct'])); ?> de locales ocupados</div>
                            </div>
                        </div>
                        <div class="col-12 col-md-6 col-xl-3">
                            <div class="dash-overview-card">
                                <div class="dash-overview-label">Tiendas con deuda</div>
                                <div class="dash-overview-value"><?php echo (int) $insightsKpi['tiendas_con_deuda']; ?></div>
                                <div class="dash-overview-sub">Sobre <?php echo count($registrosPorTienda); ?> tiendas con movimiento</div>
                            </div>
                        </div>
                    </div>

                    <?php if ($detalleLocalesDisponible): ?>
                        <div class="row g-2 mb-2 align-items-stretch">
                            <div class="col-12 col-xl-8">
                                <div class="dash-chart-card dash-primary-chart h-100">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                        <h2 class="h5 mb-0">Consumo mensual por servicio</h2>
                                        <span class="small text-muted">Montos facturados de luz, gas y agua</span>
                                    </div>
                                    <div class="dash-chart-wrap">
                                        <canvas id="consumoChart" aria-label="Grafico de consumo mensual por servicio"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-xl-4">
                                <div class="dash-consumption-kpis">
                                    <div class="dash-kpi-card bg-white">
                                        <div class="dash-kpi-label">Servicios facturados</div>
                                        <div class="dash-kpi-value"><?php echo msp2Escape(dashboardFmtMonto($consumoKpi['total_actual'])); ?></div>
                                        <div class="dash-kpi-sub">Luz, gas y agua del rango actual</div>
                                    </div>
                                    <div class="dash-kpi-card bg-white">
                                        <div class="dash-kpi-label">Variación comparable</div>
                                        <div class="dash-kpi-value"><?php echo msp2Escape(dashboardFmtDelta($consumoKpi['variacion_pct'])); ?></div>
                                        <div class="dash-kpi-sub"><?php echo msp2Escape(dashboardFmtMonto($consumoKpi['variacion_nominal'])); ?> vs ventana anterior</div>
                                    </div>
                                    <div class="dash-kpi-card bg-white">
                                        <div class="dash-kpi-label">Promedio por local</div>
                                        <div class="dash-kpi-value"><?php echo msp2Escape(dashboardFmtMonto($consumoKpi['promedio_local'])); ?></div>
                                        <div class="dash-kpi-sub"><?php echo (int) $consumoKpi['locales_con_consumo']; ?> locales con consumo</div>
                                    </div>
                                    <div class="dash-kpi-card bg-white">
                                        <div class="dash-kpi-label">Local más costoso</div>
                                        <div class="dash-kpi-value"><?php echo msp2Escape((string) $consumoKpi['local_top_nombre']); ?></div>
                                        <div class="dash-kpi-sub"><?php echo msp2Escape(dashboardFmtMonto($consumoKpi['local_top_monto'])); ?> en servicios</div>
                                    </div>
                                    <?php foreach (['luz', 'agua', 'gas'] as $servicioKey): ?>
                                        <?php $servicio = $consumoServicios[$servicioKey]; ?>
                                        <div class="dash-overview-card">
                                            <div class="dash-overview-label"><?php echo msp2Escape($servicio['label']); ?> consumid<?php echo $servicioKey === 'agua' ? 'a' : 'o'; ?></div>
                                            <div class="dash-overview-value"><?php echo number_format((float) $servicio['consumo_actual'], 0, ',', '.'); ?> <?php echo msp2Escape($servicio['unidad']); ?></div>
                                            <div class="dash-overview-sub"><?php echo msp2Escape(dashboardFmtDelta($servicio['variacion_pct'])); ?> vs ventana anterior</div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php foreach (['luz', 'agua', 'gas'] as $servicioKey): ?>
                                        <?php $servicio = $consumoServicios[$servicioKey]; ?>
                                        <div class="dash-overview-card">
                                            <div class="dash-overview-label">Costo promedio <?php echo msp2Escape(mb_strtolower($servicio['label'], 'UTF-8')); ?></div>
                                            <div class="dash-overview-value"><?php echo msp2Escape(dashboardFmtMonto($servicio['costo_promedio'])); ?></div>
                                            <div class="dash-overview-sub"><?php echo msp2Escape(dashboardFmtMonto($servicio['costo_actual'])); ?> facturados</div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="row g-2">
                        <div class="col-12 col-xl-7">
                            <div class="dash-chart-card">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h2 class="h6 mb-0">Evolución mensual</h2>
                                    <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#detalleMensualDashboard" aria-expanded="false" aria-controls="detalleMensualDashboard">
                                        <i class="bi bi-chevron-down me-1" aria-hidden="true"></i>Ver detalle mensual
                                    </button>
                                </div>
                                <div class="dash-chart-wrap">
                                    <canvas id="historialChart" aria-label="Grafico de evolucion mensual"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-xl-5">
                            <div class="dash-chart-card">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h2 class="h6 mb-0">Composición facturada</h2>
                                    <span class="small text-muted">Monto y participación</span>
                                </div>
                                <div class="dash-chart-wrap dash-composition-chart">
                                    <canvas id="composicionChart" aria-label="Grafico de composicion facturada"></canvas>
                                </div>
                                <?php
                                    $composicionDetalle = [
                                        ['label' => 'Arriendo', 'monto' => (float) $composicionFacturacion['arriendo'], 'color' => '#0f766e'],
                                        ['label' => 'Luz', 'monto' => (float) $composicionFacturacion['luz'], 'color' => '#f59e0b'],
                                        ['label' => 'Gas', 'monto' => (float) $composicionFacturacion['gas'], 'color' => '#ef4444'],
                                        ['label' => 'Agua', 'monto' => (float) $composicionFacturacion['agua'], 'color' => '#06b6d4'],
                                        ['label' => 'Otros', 'monto' => (float) $composicionFacturacion['otros'], 'color' => '#64748b'],
                                    ];
                                    $totalComposicionDetalle = array_sum(array_column($composicionDetalle, 'monto'));
                                ?>
                                <div class="dash-composition-detail" aria-label="Detalle de composición facturada">
                                    <?php foreach ($composicionDetalle as $conceptoComposicion): ?>
                                        <?php $porcentajeComposicion = $totalComposicionDetalle > 0 ? (((float) $conceptoComposicion['monto'] / $totalComposicionDetalle) * 100) : 0.0; ?>
                                        <div class="dash-composition-item">
                                            <span class="dash-composition-dot" style="background: <?php echo msp2Escape((string) $conceptoComposicion['color']); ?>;"></span>
                                            <span>
                                                <?php echo msp2Escape((string) $conceptoComposicion['label']); ?>
                                                <span class="dash-composition-percent"><?php echo msp2Escape(number_format($porcentajeComposicion, 1, ',', '.')); ?>%</span>
                                            </span>
                                            <span class="dash-composition-value"><?php echo msp2Escape(dashboardFmtMonto((float) $conceptoComposicion['monto'])); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="collapse mt-2 dash-history-collapse" id="detalleMensualDashboard">
                    <div class="row g-2">
                        <div class="col-12">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                    <h2 class="h6 mb-0">Historial mensual</h2>
                                    <span class="small text-muted">Emitido bruto, cobrado y saldo por período</span>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Periodo</th>
                                                <th class="text-end">Documentos</th>
                                                <th class="text-end">Arrendatarios</th>
                                                <th class="text-end">Emitido bruto</th>
                                                <th class="text-end">Cobrado</th>
                                                <th class="text-end">Saldo</th>
                                                <th class="text-end">Recaudación</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($historialMensual)): ?>
                                                <tr>
                                                    <td colspan="7" class="text-center text-muted py-3">No hay historial para mostrar.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($historialMensual as $row): ?>
                                                    <?php
                                                        $montoFacturado = (float) ($row['monto_facturado'] ?? 0);
                                                        $montoCobrado = (float) ($row['monto_cobrado'] ?? 0);
                                                        $recaudacion = $montoFacturado > 0 ? ($montoCobrado / $montoFacturado) * 100 : 0;
                                                    ?>
                                                    <tr>
                                                        <td><?php echo msp2Escape(dashboardFmtPeriodo((string) ($row['periodo_facturacion'] ?? ''))); ?></td>
                                                        <td class="text-end"><?php echo (int) ($row['cantidad_documentos'] ?? 0); ?></td>
                                                        <td class="text-end"><?php echo (int) ($row['cantidad_arrendatarios'] ?? 0); ?></td>
                                                        <td class="text-end"><?php echo msp2Escape(dashboardFmtMonto($montoFacturado)); ?></td>
                                                        <td class="text-end"><?php echo msp2Escape(dashboardFmtMonto($montoCobrado)); ?></td>
                                                        <td class="text-end"><?php echo msp2Escape(dashboardFmtMonto($row['monto_saldo'] ?? 0)); ?></td>
                                                        <td class="text-end"><?php echo msp2Escape(dashboardFmtPorcentaje($recaudacion)); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>

                    <div class="dash-section-title mt-3">Consumo por servicio</div>
                    <?php if ($detalleLocalesDisponible): ?>
                        <div class="row g-2 mb-2 dash-service-charts">
                            <div class="col-12 col-lg-4">
                                <div class="dash-chart-card h-100">
                                    <div class="dash-chart-heading">
                                        <h2 class="h6 mb-0">Luz: kWh vs CLP</h2>
                                        <div class="dash-toggle-group" role="group" aria-label="Vista grafico luz">
                                            <button type="button" class="dash-toggle-chip is-active" data-servicio-chart="luz" data-chart-mode="ambos">Ambos</button>
                                            <button type="button" class="dash-toggle-chip" data-servicio-chart="luz" data-chart-mode="costo">Costo</button>
                                            <button type="button" class="dash-toggle-chip" data-servicio-chart="luz" data-chart-mode="consumo">Consumo</button>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between dash-axis-note px-1"><span>Consumo (kWh)</span><span>Costo (CLP)</span></div>
                                    <div class="dash-chart-wrap is-tall">
                                        <canvas id="luzConsumoChart" aria-label="Grafico de consumo electrico en kWh y CLP"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-4">
                                <div class="dash-chart-card h-100">
                                    <div class="dash-chart-heading">
                                        <h2 class="h6 mb-0">Agua: m3 vs CLP</h2>
                                        <div class="dash-toggle-group" role="group" aria-label="Vista grafico agua">
                                            <button type="button" class="dash-toggle-chip is-active" data-servicio-chart="agua" data-chart-mode="ambos">Ambos</button>
                                            <button type="button" class="dash-toggle-chip" data-servicio-chart="agua" data-chart-mode="costo">Costo</button>
                                            <button type="button" class="dash-toggle-chip" data-servicio-chart="agua" data-chart-mode="consumo">Consumo</button>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between dash-axis-note px-1"><span>Consumo (m³)</span><span>Costo (CLP)</span></div>
                                    <div class="dash-chart-wrap is-tall">
                                        <canvas id="aguaConsumoChart" aria-label="Grafico de consumo de agua en m3 y CLP"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-4">
                                <div class="dash-chart-card h-100">
                                    <div class="dash-chart-heading">
                                        <h2 class="h6 mb-0">Gas: m3 vs CLP</h2>
                                        <div class="dash-toggle-group" role="group" aria-label="Vista grafico gas">
                                            <button type="button" class="dash-toggle-chip is-active" data-servicio-chart="gas" data-chart-mode="ambos">Ambos</button>
                                            <button type="button" class="dash-toggle-chip" data-servicio-chart="gas" data-chart-mode="costo">Costo</button>
                                            <button type="button" class="dash-toggle-chip" data-servicio-chart="gas" data-chart-mode="consumo">Consumo</button>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between dash-axis-note px-1"><span>Consumo (m³)</span><span>Costo (CLP)</span></div>
                                    <div class="dash-chart-wrap is-tall">
                                        <canvas id="gasConsumoChart" aria-label="Grafico de consumo de gas en m3 y CLP"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-12">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                        <h2 class="h6 mb-0">Top locales con consumo</h2>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="small text-muted d-none d-md-inline">Comparación contra ventana anterior</span>
                                            <?php if (count($consumoTopLocales) > 4): ?>
                                                <button type="button" class="btn btn-outline-primary btn-sm" id="toggleTopConsumo" aria-expanded="false">
                                                    Ver más
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover align-middle mb-0 dash-mini-table">
                                            <thead>
                                                <tr>
                                                    <th>Local</th>
                                                    <th class="text-end">Actual</th>
                                                    <th class="text-end">Anterior</th>
                                                    <th class="text-end">Var. %</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if ($consumoTopLocales === []): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center text-muted py-3">Sin consumo facturado para el rango actual.</td>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($consumoTopLocales as $indiceConsumoLocal => $consumoLocal): ?>
                                                        <?php
                                                            $montoActualLocal = (float) ($consumoLocal['total_actual'] ?? 0);
                                                            $montoAnteriorLocal = (float) ($consumoLocal['total_anterior'] ?? 0);
                                                            $variacionLocal = $montoAnteriorLocal > 0
                                                                ? ((($montoActualLocal - $montoAnteriorLocal) / $montoAnteriorLocal) * 100)
                                                                : ($montoActualLocal > 0 ? 100.0 : 0.0);
                                                        ?>
                                                        <tr class="<?php echo $indiceConsumoLocal >= 4 ? 'dash-top-extra d-none' : ''; ?>">
                                                            <td><?php echo msp2Escape((string) ($consumoLocal['cdo_local'] ?? '-')); ?></td>
                                                            <td class="text-end"><?php echo msp2Escape(dashboardFmtMonto($montoActualLocal)); ?></td>
                                                            <td class="text-end"><?php echo msp2Escape(dashboardFmtMonto($montoAnteriorLocal)); ?></td>
                                                            <td class="text-end"><?php echo msp2Escape(dashboardFmtDelta($variacionLocal)); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-0">El análisis de consumo requiere tablas de detalle, servicios y medidores instaladas.</div>
                    <?php endif; ?>
            </section>

        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const historialLabels = <?php echo dashboardJson($chartSeries['historial_labels']); ?>;
const historialFacturado = <?php echo dashboardJson($chartSeries['historial_facturado']); ?>;
const historialCobrado = <?php echo dashboardJson($chartSeries['historial_cobrado']); ?>;
const historialSaldo = <?php echo dashboardJson($chartSeries['historial_saldo']); ?>;
const topDeudaLabels = <?php echo dashboardJson($chartSeries['top_deudores_labels']); ?>;
const topDeudaValues = <?php echo dashboardJson($chartSeries['top_deudores_values']); ?>;
const consumoLabels = <?php echo dashboardJson($chartSeries['consumo_labels']); ?>;
const consumoLuz = <?php echo dashboardJson($chartSeries['consumo_luz']); ?>;
const consumoGas = <?php echo dashboardJson($chartSeries['consumo_gas']); ?>;
const consumoAgua = <?php echo dashboardJson($chartSeries['consumo_agua']); ?>;
const servicioComboLabels = <?php echo dashboardJson($chartSeries['servicio_combo_labels']); ?>;
const servicioComboSeries = <?php echo dashboardJson($chartSeries['servicio_combo']); ?>;
const composicionFacturacion = <?php echo dashboardJson([
    $composicionFacturacion['arriendo'],
    $composicionFacturacion['luz'],
    $composicionFacturacion['gas'],
    $composicionFacturacion['agua'],
    $composicionFacturacion['otros'],
]); ?>;

const chartMoney = new Intl.NumberFormat('es-CL', {
    style: 'currency',
    currency: 'CLP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});
const chartWholeNumber = new Intl.NumberFormat('es-CL', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});
const chartCompactNumber = new Intl.NumberFormat('es-CL', {
    notation: 'compact',
    maximumFractionDigits: 1,
});
const chartCompactMoney = new Intl.NumberFormat('es-CL', {
    style: 'currency',
    currency: 'CLP',
    notation: 'compact',
    maximumFractionDigits: 1,
});
const chartGridColor = 'rgba(100, 116, 139, 0.11)';
const chartBorderColor = 'rgba(100, 116, 139, 0.22)';

const toggleTopConsumo = document.getElementById('toggleTopConsumo');
if (toggleTopConsumo) {
    toggleTopConsumo.addEventListener('click', function () {
        const expanded = toggleTopConsumo.getAttribute('aria-expanded') === 'true';
        document.querySelectorAll('.dash-top-extra').forEach(function (row) {
            row.classList.toggle('d-none', expanded);
        });
        toggleTopConsumo.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        toggleTopConsumo.textContent = expanded ? 'Ver más' : 'Ver menos';
    });
}

const detalleMensualDashboard = document.getElementById('detalleMensualDashboard');
if (detalleMensualDashboard) {
    const detailButton = document.querySelector('[data-bs-target="#detalleMensualDashboard"]');
    detalleMensualDashboard.addEventListener('shown.bs.collapse', function () {
        if (detailButton) detailButton.innerHTML = '<i class="bi bi-chevron-up me-1" aria-hidden="true"></i>Ocultar detalle mensual';
    });
    detalleMensualDashboard.addEventListener('hidden.bs.collapse', function () {
        if (detailButton) detailButton.innerHTML = '<i class="bi bi-chevron-down me-1" aria-hidden="true"></i>Ver detalle mensual';
    });
}

if (typeof Chart !== 'undefined') {
    const historialCanvas = document.getElementById('historialChart');
    if (historialCanvas && historialLabels.length > 0) {
        new Chart(historialCanvas, {
            data: {
                labels: historialLabels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Emitido bruto',
                        data: historialFacturado,
                        backgroundColor: 'rgba(15, 118, 110, 0.75)',
                        borderColor: '#0f766e',
                        borderWidth: 1,
                        borderRadius: 6,
                    },
                    {
                        type: 'bar',
                        label: 'Cobrado',
                        data: historialCobrado,
                        backgroundColor: 'rgba(37, 99, 235, 0.72)',
                        borderColor: '#2563eb',
                        borderWidth: 1,
                        borderRadius: 6,
                    },
                    {
                        type: 'line',
                        label: 'Saldo',
                        data: historialSaldo,
                        borderColor: '#dc2626',
                        backgroundColor: 'rgba(220, 38, 38, 0.12)',
                        tension: 0.28,
                        fill: false,
                        yAxisID: 'y',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                layout: {
                    padding: {
                        top: 12,
                    }
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                const parsedValue = context.dataset.type === 'bar' ? context.parsed.y : context.parsed.y;
                                return context.dataset.label + ': ' + chartMoney.format(parsedValue || 0);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: chartGridColor },
                        border: { color: chartBorderColor },
                        ticks: {
                            callback: function (value) {
                                return chartMoney.format(value);
                            }
                        }
                    },
                    x: {
                        grid: { color: chartGridColor },
                        border: { color: chartBorderColor }
                    }
                }
            }
        });
    }

    const consumoCanvas = document.getElementById('consumoChart');
    if (consumoCanvas && consumoLabels.length > 0) {
        new Chart(consumoCanvas, {
            type: 'bar',
            data: {
                labels: consumoLabels,
                datasets: [
                    {
                        label: 'Luz',
                        data: consumoLuz,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.75)',
                        borderWidth: 1,
                        borderRadius: 6,
                    },
                    {
                        label: 'Gas',
                        data: consumoGas,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239, 68, 68, 0.72)',
                        borderWidth: 1,
                        borderRadius: 6,
                    },
                    {
                        label: 'Agua',
                        data: consumoAgua,
                        borderColor: '#06b6d4',
                        backgroundColor: 'rgba(6, 182, 212, 0.72)',
                        borderWidth: 1,
                        borderRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.dataset.label + ': ' + chartMoney.format(context.parsed.y || 0);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: chartGridColor },
                        border: { color: chartBorderColor },
                        ticks: {
                            callback: function (value) {
                                return chartMoney.format(value);
                            }
                        }
                    },
                    x: {
                        grid: { color: chartGridColor },
                        border: { color: chartBorderColor }
                    }
                }
            }
        });
    }

    const servicioBarLabelsPlugin = {
        id: 'servicioBarLabelsPlugin',
        afterDatasetsDraw: function (chart) {
            const ctx = chart.ctx;
            ctx.save();
            ctx.font = '600 11px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'top';

            chart.data.datasets.forEach(function (dataset, datasetIndex) {
                if (dataset.type !== 'bar' || dataset.hidden) {
                    return;
                }

                const meta = chart.getDatasetMeta(datasetIndex);
                meta.data.forEach(function (bar, index) {
                    const rawValue = Number(dataset.data[index] || 0);
                    if (!Number.isFinite(rawValue) || rawValue <= 0) {
                        return;
                    }

                    const labelText = chartWholeNumber.format(rawValue);
                    const textY = bar.y + 8;
                    const textWidth = ctx.measureText(labelText).width;
                    const badgeWidth = textWidth + 10;
                    const badgeHeight = 18;
                    const badgeX = bar.x - (badgeWidth / 2);
                    const badgeY = textY - 3;

                    ctx.fillStyle = 'rgba(255, 255, 255, 0.9)';
                    ctx.beginPath();
                    ctx.roundRect(badgeX, badgeY, badgeWidth, badgeHeight, 6);
                    ctx.fill();

                    ctx.fillStyle = '#7c2d12';
                    ctx.fillText(labelText, bar.x, textY);
                });
            });

            ctx.restore();
        }
    };

    const renderServicioComboChart = function (serviceKey, canvasId, unitLabel) {
        const canvas = document.getElementById(canvasId);
        const series = servicioComboSeries[serviceKey];
        if (!canvas || !series || !servicioComboLabels.length) {
            return;
        }

        const comboChart = new Chart(canvas, {
            plugins: [servicioBarLabelsPlugin],
            data: {
                labels: servicioComboLabels,
                datasets: [
                    {
                        type: 'bar',
                        label: unitLabel,
                        data: series.consumo || [],
                        backgroundColor: 'rgba(245, 158, 11, 0.78)',
                        borderColor: '#f59e0b',
                        borderWidth: 1,
                        borderRadius: 6,
                        yAxisID: 'yConsumo',
                    },
                    {
                        type: 'line',
                        label: 'CLP',
                        data: series.costo || [],
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.12)',
                        tension: 0.28,
                        fill: false,
                        yAxisID: 'yClp',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { left: 0, right: 0, top: 4, bottom: 0 } },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                if (context.dataset.yAxisID === 'yConsumo') {
                                    return context.dataset.label + ': ' + chartWholeNumber.format(context.parsed.y || 0) + ' ' + unitLabel;
                                }
                                return context.dataset.label + ': ' + chartMoney.format(context.parsed.y || 0);
                            }
                        }
                    }
                },
                scales: {
                    yConsumo: {
                        type: 'linear',
                        position: 'left',
                        beginAtZero: true,
                        title: {
                            display: false,
                        },
                        grid: { color: chartGridColor },
                        border: { color: chartBorderColor },
                        ticks: {
                            maxTicksLimit: 5,
                            padding: 2,
                            font: { size: 10 },
                            callback: function (value) {
                                return chartCompactNumber.format(value);
                            }
                        }
                    },
                    yClp: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        title: {
                            display: false,
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                        border: { color: chartBorderColor },
                        ticks: {
                            maxTicksLimit: 5,
                            padding: 2,
                            font: { size: 10 },
                            callback: function (value) {
                                return chartCompactMoney.format(value);
                            }
                        }
                    },
                    x: {
                        grid: { color: chartGridColor },
                        border: { color: chartBorderColor },
                        ticks: {
                            autoSkip: true,
                            maxTicksLimit: 6,
                            maxRotation: 45,
                            minRotation: 35,
                            font: { size: 10 },
                        }
                    }
                }
            }
        });

        const applyChartMode = function (mode) {
            const normalizedMode = mode === 'costo' || mode === 'consumo' ? mode : 'ambos';
            const datasets = comboChart.data.datasets || [];
            if (datasets[0]) datasets[0].hidden = normalizedMode === 'costo';
            if (datasets[1]) datasets[1].hidden = normalizedMode === 'consumo';
            comboChart.update();

            document.querySelectorAll('[data-servicio-chart="' + serviceKey + '"]').forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-chart-mode') === normalizedMode);
            });
        };

        document.querySelectorAll('[data-servicio-chart="' + serviceKey + '"]').forEach(function (button) {
            button.addEventListener('click', function () {
                applyChartMode(button.getAttribute('data-chart-mode') || 'ambos');
            });
        });

        applyChartMode('ambos');
    };

    renderServicioComboChart('luz', 'luzConsumoChart', 'kWh');
    renderServicioComboChart('gas', 'gasConsumoChart', 'm3');
    renderServicioComboChart('agua', 'aguaConsumoChart', 'm3');

    const composicionCanvas = document.getElementById('composicionChart');
    if (composicionCanvas && composicionFacturacion.some(function (value) { return Number(value) > 0; })) {
        new Chart(composicionCanvas, {
            type: 'doughnut',
            data: {
                labels: ['Arriendo', 'Luz', 'Gas', 'Agua', 'Otros'],
                datasets: [{
                    data: composicionFacturacion,
                    backgroundColor: ['#0f766e', '#f59e0b', '#ef4444', '#06b6d4', '#64748b'],
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return context.label + ': ' + chartMoney.format(context.parsed || 0);
                            }
                        }
                    }
                }
            }
        });
    }

    const deudaCanvas = document.getElementById('deudaChart');
    if (deudaCanvas && topDeudaLabels.length > 0) {
        new Chart(deudaCanvas, {
            type: 'bar',
            data: {
                labels: topDeudaLabels,
                datasets: [{
                    label: 'Saldo pendiente',
                    data: topDeudaValues,
                    backgroundColor: '#b91c1c',
                    borderRadius: 8,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                return 'Saldo: ' + chartMoney.format(context.parsed.x || 0);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { color: chartGridColor },
                        border: { color: chartBorderColor },
                        ticks: {
                            callback: function (value) {
                                return chartMoney.format(value);
                            }
                        }
                    },
                    y: {
                        grid: { color: chartGridColor },
                        border: { color: chartBorderColor }
                    }
                }
            }
        });
    }
}

document.querySelectorAll('.dash-local-row').forEach(function (row) {
    row.addEventListener('click', function () {
        const group = row.getAttribute('data-local-group');
        if (!group) return;
        const isExpanded = row.getAttribute('aria-expanded') === 'true';
        row.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
        document.querySelectorAll('.local-detail-' + group).forEach(function (detailRow) {
            detailRow.classList.toggle('d-none-local', isExpanded);
        });
    });
});

</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
