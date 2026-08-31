<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$requestStart = microtime(true);
msp2RequireAccess();
$accessCheckMs = round((microtime(true) - $requestStart) * 1000, 2);

$flash = msp2PullFlash();
$loadError = null;
$perfEnabled = (string) ($_GET['perf'] ?? '') === '1';
$perfStart = microtime(true);
$perfMarks = [];

$perfMark = static function (string $label) use (&$perfMarks, $perfEnabled, $perfStart): void {
    if (!$perfEnabled) {
        return;
    }
    $perfMarks[] = [
        'label' => $label,
        'ms' => round((microtime(true) - $perfStart) * 1000, 2),
        'mem_mb' => round(memory_get_usage(true) / 1048576, 2),
    ];
};

$yearRaw = trim((string) ($_GET['anio'] ?? date('Y')));
$requestedYear = ctype_digit($yearRaw) ? (int) $yearRaw : (int) date('Y');
if ($requestedYear < 2020 || $requestedYear > 2100) {
    $requestedYear = (int) date('Y');
}
$selectedYear = $requestedYear;
$availableYears = [];
$availablePeriodsByYear = [];

$months = [];
$monthNames = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre',
];
foreach ($monthNames as $monthNumber => $monthLabel) {
    $monthKey = sprintf('%04d-%02d', $selectedYear, $monthNumber);
    $months[$monthKey] = [
        'key' => $monthKey,
        'label' => $monthLabel,
        'month_number' => $monthNumber,
        'uf' => null,
        'has_cierre' => false,
        'is_available' => false,
    ];
}

$rows = [];
$serviceTotalsByLocalMonth = [];
$serviceTotalsByTiendaMonth = [];
$reservaByTiendaMonth = [];
$reservaBreakdownByTiendaMonth = [];
$docStatusByTiendaMonth = [];
$docTotalByTiendaMonth = [];
$docIdByTiendaMonth = [];
$docNumberByTiendaMonth = [];
$arriendoNetoByTiendaMonth = [];
$clpFijoContratoByTienda = [];
$garantiaAplicadaByTiendaMonth = [];
$clpFijoFallbackByTiendaMonth = [];
$ufFallbackByTiendaMonth = [];

function msp2ControlDiarioLocalSortWeight(string $code): array
{
    $normalized = strtoupper(trim($code));
    if ($normalized === '') {
        return [99, 999999, 999999, ''];
    }

    if (preg_match('/^([A-F])-([0-9]+)([A-Z]?)$/', $normalized, $matches) === 1) {
        $letterOrder = ord($matches[1]) - ord('A');
        $number = (int) $matches[2];
        $suffix = $matches[3] ?? '';
        $suffixOrder = $suffix === '' ? 0 : (ord($suffix[0]) - ord('A') + 1);
        return [0, $letterOrder, $number, str_pad((string) $suffixOrder, 4, '0', STR_PAD_LEFT)];
    }

    if (preg_match('/^[0-9]+$/', $normalized) === 1) {
        return [1, (int) $normalized, 0, ''];
    }

    $specialOrder = [
        'PELUQUERIA' => 0,
        'GYM' => 1,
        'OBRA' => 2,
        'MODULAR' => 3,
        'ESPACIO' => 4,
    ];
    if (isset($specialOrder[$normalized])) {
        return [2, $specialOrder[$normalized], 0, ''];
    }

    return [3, 0, 0, $normalized];
}

function msp2ControlDiarioCompareLocalCode(string $a, string $b): int
{
    $wa = msp2ControlDiarioLocalSortWeight($a);
    $wb = msp2ControlDiarioLocalSortWeight($b);
    $max = max(count($wa), count($wb));
    for ($i = 0; $i < $max; $i++) {
        $left = $wa[$i] ?? null;
        $right = $wb[$i] ?? null;
        if ($left === $right) {
            continue;
        }
        if (is_string($left) || is_string($right)) {
            return strcmp((string) $left, (string) $right);
        }
        return ((float) $left <=> (float) $right);
    }
    return 0;
}

function msp2ControlDiarioFormatSignedAmount(float $value): string
{
    $abs = number_format(abs($value), 2, ',', '.');
    return $value < 0 ? '-$' . $abs : '$' . $abs;
}

function msp2ControlDiarioMonthOverlapsRange(string $monthStart, string $monthEnd, string $rangeStart, string $rangeEnd = ''): bool
{
    if ($rangeStart === '' || $rangeStart > $monthEnd) {
        return false;
    }

    if ($rangeEnd !== '' && $rangeEnd < $monthStart) {
        return false;
    }

    return true;
}

try {
    $requiredTables = [
        'msp_locales',
        'msp_contrato_locales',
        'msp_contratos_arriendo',
        'msp_arrendatarios',
    ];
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }
    if ($missingTables !== []) {
        throw new RuntimeException('Faltan tablas requeridas para Control diario: `' . implode('`, `', $missingTables) . '`.');
    }
    $perfMark('validacion_tablas');

    if (msp2TableExists($conn, 'msp_cierre_mensual')) {
        $periodStmt = $conn->query(
            "SELECT
                YEAR(periodo_facturacion) AS anio,
                CONVERT(CHAR(7), periodo_facturacion, 126) AS periodo_ym,
                CAST(valor_uf AS DECIMAL(18, 4)) AS valor_uf
             FROM dbo.msp_cierre_mensual
             ORDER BY YEAR(periodo_facturacion) DESC, MONTH(periodo_facturacion) ASC"
        );
        while (($periodRow = $periodStmt->fetch()) !== false) {
            $anioPeriodo = (int) ($periodRow['anio'] ?? 0);
            $periodoYm = trim((string) ($periodRow['periodo_ym'] ?? ''));
            if ($anioPeriodo <= 0 || $periodoYm === '') {
                continue;
            }
            if (!isset($availablePeriodsByYear[$anioPeriodo])) {
                $availablePeriodsByYear[$anioPeriodo] = [];
            }
            $availablePeriodsByYear[$anioPeriodo][$periodoYm] = round((float) ($periodRow['valor_uf'] ?? 0), 4);
        }
    }

    if ($availablePeriodsByYear !== []) {
        $availableYears = array_map('intval', array_keys($availablePeriodsByYear));
        rsort($availableYears, SORT_NUMERIC);
        if (!in_array($selectedYear, $availableYears, true)) {
            $selectedYear = (int) ($availableYears[0] ?? $selectedYear);
        }
    }

    $months = [];
    foreach ($monthNames as $monthNumber => $monthLabel) {
        $monthKey = sprintf('%04d-%02d', $selectedYear, $monthNumber);
        $months[$monthKey] = [
            'key' => $monthKey,
            'label' => $monthLabel,
            'month_number' => $monthNumber,
            'uf' => null,
            'has_cierre' => false,
            'is_available' => false,
        ];
    }

    $periodsForSelectedYear = is_array($availablePeriodsByYear[$selectedYear] ?? null)
        ? $availablePeriodsByYear[$selectedYear]
        : [];
    foreach ($periodsForSelectedYear as $periodoYm => $valorUf) {
        if (!isset($months[$periodoYm])) {
            continue;
        }
        $months[$periodoYm]['uf'] = round((float) $valorUf, 4);
        $months[$periodoYm]['has_cierre'] = true;
        $months[$periodoYm]['is_available'] = true;
    }

    foreach ($months as $periodo => $monthData) {
        if ($monthData['uf'] === null || $monthData['uf'] <= 0) {
            $months[$periodo]['uf'] = 0.0;
        }
    }
    $perfMark('periodos_y_uf');

    $canLoadServicios =
        msp2TableExists($conn, 'msp_cobros_servicios')
        && msp2TableExists($conn, 'msp_lecturas_medidores')
        && msp2TableExists($conn, 'msp_procesos_cobro_servicio')
        && msp2TableExists($conn, 'msp_tipos_servicio')
        && msp2TableExists($conn, 'msp_medidores');

    $canLoadReservaCargos =
        msp2TableExists($conn, 'msp_documentos_cobro')
        && msp2TableExists($conn, 'msp_documentos_cobro_detalle')
        && msp2TableExists($conn, 'msp_tipo_item_documento');

    $canLoadReservaSaldoFavor =
        msp2TableExists($conn, 'msp_pagos')
        && msp2TableExists($conn, 'msp_documentos_cobro')
        && msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor');

    $canLoadDocStatus = msp2TableExists($conn, 'msp_documentos_cobro');
    $canLoadDocTotals = msp2TableExists($conn, 'msp_documentos_cobro');

    if ($canLoadServicios) {
        $serviciosStmt = $conn->prepare(
            "SELECT
                m.id_local,
                CONVERT(CHAR(7), c.periodo_facturacion, 126) AS periodo_ym,
                ts.codigo_servicio,
                ROUND(SUM(cs.monto_total), 2) AS monto_total
             FROM dbo.msp_cobros_servicios cs
             INNER JOIN dbo.msp_lecturas_medidores lm
                ON lm.id_lectura = cs.id_lectura
             INNER JOIN dbo.msp_procesos_cobro_servicio p
                ON p.id_proceso_cobro = lm.id_proceso_cobro
             INNER JOIN dbo.msp_cierre_mensual c
                ON c.id_cierre_mensual = p.id_cierre_mensual
             INNER JOIN dbo.msp_tipos_servicio ts
                ON ts.id_tipo_servicio = p.id_tipo_servicio
             INNER JOIN dbo.msp_medidores m
                ON m.id_medidor = lm.id_medidor
             WHERE YEAR(c.periodo_facturacion) = :anio
               AND ts.codigo_servicio IN (N'LUZ', N'GAS', N'AGUA')
             GROUP BY
                m.id_local,
                CONVERT(CHAR(7), c.periodo_facturacion, 126),
                ts.codigo_servicio"
        );
        $serviciosStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $serviciosStmt->execute();
        while (($servicioRow = $serviciosStmt->fetch()) !== false) {
            $idLocalServicio = (int) ($servicioRow['id_local'] ?? 0);
            $periodoServicio = trim((string) ($servicioRow['periodo_ym'] ?? ''));
            $codigoServicio = strtoupper(trim((string) ($servicioRow['codigo_servicio'] ?? '')));
            $montoServicio = round((float) ($servicioRow['monto_total'] ?? 0), 2);
            if ($idLocalServicio <= 0 || $periodoServicio === '' || !isset($months[$periodoServicio])) {
                continue;
            }
            if (!isset($serviceTotalsByLocalMonth[$idLocalServicio])) {
                $serviceTotalsByLocalMonth[$idLocalServicio] = [];
            }
            if (!isset($serviceTotalsByLocalMonth[$idLocalServicio][$periodoServicio])) {
                $serviceTotalsByLocalMonth[$idLocalServicio][$periodoServicio] = [
                    'electricidad' => 0.0,
                    'gas' => 0.0,
                    'agua' => 0.0,
                ];
            }
            if ($codigoServicio === 'LUZ') {
                $serviceTotalsByLocalMonth[$idLocalServicio][$periodoServicio]['electricidad'] = round($montoServicio, 2);
            } elseif ($codigoServicio === 'GAS') {
                $serviceTotalsByLocalMonth[$idLocalServicio][$periodoServicio]['gas'] = round($montoServicio, 2);
            } elseif ($codigoServicio === 'AGUA') {
                $serviceTotalsByLocalMonth[$idLocalServicio][$periodoServicio]['agua'] = round($montoServicio, 2);
            }
        }
    }
    $perfMark('carga_servicios');

    if ($canLoadReservaCargos) {
        $serviciosDocStmt = $conn->prepare(
            "SELECT
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                ROUND(SUM(CASE WHEN tid.codigo_item = N'SERVICIO_LUZ' THEN dcd.subtotal ELSE 0 END), 2) AS monto_luz,
                ROUND(SUM(CASE WHEN tid.codigo_item = N'SERVICIO_GAS' THEN dcd.subtotal ELSE 0 END), 2) AS monto_gas,
                ROUND(SUM(CASE WHEN tid.codigo_item = N'SERVICIO_AGUA' THEN dcd.subtotal ELSE 0 END), 2) AS monto_agua
             FROM dbo.msp_documentos_cobro_detalle dcd
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
             INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
             WHERE YEAR(dc.periodo_facturacion) = :anio
               AND dc.estado_documento <> 5
               AND tid.codigo_item IN (N'SERVICIO_LUZ', N'SERVICIO_GAS', N'SERVICIO_AGUA')
             GROUP BY
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126)"
        );
        $serviciosDocStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $serviciosDocStmt->execute();
        while (($serviciosDocRow = $serviciosDocStmt->fetch()) !== false) {
            $idTiendaServicioDoc = (int) ($serviciosDocRow['id_tienda'] ?? 0);
            $periodoServicioDoc = trim((string) ($serviciosDocRow['periodo_ym'] ?? ''));
            if ($idTiendaServicioDoc <= 0 || $periodoServicioDoc === '' || !isset($months[$periodoServicioDoc])) {
                continue;
            }
            if (!isset($serviceTotalsByTiendaMonth[$idTiendaServicioDoc])) {
                $serviceTotalsByTiendaMonth[$idTiendaServicioDoc] = [];
            }
            $serviceTotalsByTiendaMonth[$idTiendaServicioDoc][$periodoServicioDoc] = [
                'electricidad' => round((float) ($serviciosDocRow['monto_luz'] ?? 0), 2),
                'gas' => round((float) ($serviciosDocRow['monto_gas'] ?? 0), 2),
                'agua' => round((float) ($serviciosDocRow['monto_agua'] ?? 0), 2),
            ];
        }

        $reservaCargosStmt = $conn->prepare(
            "SELECT
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                ROUND(SUM(CASE WHEN tid.codigo_item IN (N'MULTA', N'DANO', N'DANOS') THEN dcd.subtotal ELSE 0 END), 2) AS monto_danos_multas,
                ROUND(SUM(
                    CASE
                        WHEN tid.codigo_item IN (N'AJUSTE', N'CARGO_EXTRA', N'EXTRA')
                        THEN dcd.subtotal
                        ELSE 0
                    END
                ), 2) AS monto_otros_cargos
             FROM dbo.msp_documentos_cobro_detalle dcd
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
             INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
             WHERE YEAR(dc.periodo_facturacion) = :anio
               AND dc.estado_documento <> 5
             GROUP BY
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126)"
        );
        $reservaCargosStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $reservaCargosStmt->execute();
        while (($reservaRow = $reservaCargosStmt->fetch()) !== false) {
            $idTiendaReserva = (int) ($reservaRow['id_tienda'] ?? 0);
            $periodoReserva = trim((string) ($reservaRow['periodo_ym'] ?? ''));
            $montoDanosMultas = round((float) ($reservaRow['monto_danos_multas'] ?? 0), 2);
            $montoOtrosCargos = round((float) ($reservaRow['monto_otros_cargos'] ?? 0), 2);
            $montoReserva = round($montoDanosMultas + $montoOtrosCargos, 2);
            if ($idTiendaReserva <= 0 || $periodoReserva === '' || !isset($months[$periodoReserva])) {
                continue;
            }
            if (!isset($reservaByTiendaMonth[$idTiendaReserva])) {
                $reservaByTiendaMonth[$idTiendaReserva] = [];
            }
            $reservaByTiendaMonth[$idTiendaReserva][$periodoReserva] = round(
                (float) ($reservaByTiendaMonth[$idTiendaReserva][$periodoReserva] ?? 0) + $montoReserva,
                2
            );

            if (!isset($reservaBreakdownByTiendaMonth[$idTiendaReserva])) {
                $reservaBreakdownByTiendaMonth[$idTiendaReserva] = [];
            }
            if (!isset($reservaBreakdownByTiendaMonth[$idTiendaReserva][$periodoReserva])) {
                $reservaBreakdownByTiendaMonth[$idTiendaReserva][$periodoReserva] = [
                    'danos_multas' => 0.0,
                    'otros_cargos' => 0.0,
                    'saldo_favor_aplicado' => 0.0,
                ];
            }
            $reservaBreakdownByTiendaMonth[$idTiendaReserva][$periodoReserva]['danos_multas'] = round(
                (float) ($reservaBreakdownByTiendaMonth[$idTiendaReserva][$periodoReserva]['danos_multas'] ?? 0) + $montoDanosMultas,
                2
            );
            $reservaBreakdownByTiendaMonth[$idTiendaReserva][$periodoReserva]['otros_cargos'] = round(
                (float) ($reservaBreakdownByTiendaMonth[$idTiendaReserva][$periodoReserva]['otros_cargos'] ?? 0) + $montoOtrosCargos,
                2
            );
        }
    }
    $perfMark('carga_reserva_cargos');

    if ($canLoadReservaSaldoFavor) {
        $reservaSaldoStmt = $conn->prepare(
            "SELECT
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                ROUND(SUM(-p.monto_pagado), 2) AS monto_saldo_favor
             FROM dbo.msp_pagos p
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = p.id_documento_cobro
             WHERE YEAR(dc.periodo_facturacion) = :anio
               AND dc.estado_documento <> 5
               AND p.estado_pago = 1
               AND ISNULL(p.aplica_desde_saldo_favor, 0) = 1
             GROUP BY
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126)"
        );
        $reservaSaldoStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $reservaSaldoStmt->execute();
        while (($saldoRow = $reservaSaldoStmt->fetch()) !== false) {
            $idTiendaReserva = (int) ($saldoRow['id_tienda'] ?? 0);
            $periodoReserva = trim((string) ($saldoRow['periodo_ym'] ?? ''));
            $montoSaldoFavor = round((float) ($saldoRow['monto_saldo_favor'] ?? 0), 2);
            if ($idTiendaReserva <= 0 || $periodoReserva === '' || !isset($months[$periodoReserva])) {
                continue;
            }
            if (!isset($reservaByTiendaMonth[$idTiendaReserva])) {
                $reservaByTiendaMonth[$idTiendaReserva] = [];
            }
            $reservaByTiendaMonth[$idTiendaReserva][$periodoReserva] = round(
                (float) ($reservaByTiendaMonth[$idTiendaReserva][$periodoReserva] ?? 0) + $montoSaldoFavor,
                2
            );

            if (!isset($reservaBreakdownByTiendaMonth[$idTiendaReserva])) {
                $reservaBreakdownByTiendaMonth[$idTiendaReserva] = [];
            }
            if (!isset($reservaBreakdownByTiendaMonth[$idTiendaReserva][$periodoReserva])) {
                $reservaBreakdownByTiendaMonth[$idTiendaReserva][$periodoReserva] = [
                    'danos_multas' => 0.0,
                    'otros_cargos' => 0.0,
                    'saldo_favor_aplicado' => 0.0,
                ];
            }
            $reservaBreakdownByTiendaMonth[$idTiendaReserva][$periodoReserva]['saldo_favor_aplicado'] = round(
                (float) ($reservaBreakdownByTiendaMonth[$idTiendaReserva][$periodoReserva]['saldo_favor_aplicado'] ?? 0) + $montoSaldoFavor,
                2
            );
        }
    }
    $perfMark('carga_reserva_saldo_favor');

    if ($canLoadDocStatus) {
        $docStatusStmt = $conn->prepare(
            "SELECT
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                CASE
                    WHEN MAX(CASE
                        WHEN ISNULL(dc.saldo_pendiente, 0) > 0.005
                         AND dc.fecha_vencimiento IS NOT NULL
                         AND dc.fecha_vencimiento < CAST(GETDATE() AS DATE)
                        THEN 1 ELSE 0 END) = 1 THEN N'ATRASADO'
                    WHEN MAX(CASE WHEN ISNULL(dc.saldo_pendiente, 0) > 0.005 THEN 1 ELSE 0 END) = 1 THEN N'PENDIENTE'
                    ELSE N'OK'
                END AS estado_control
             FROM dbo.msp_documentos_cobro dc
             WHERE YEAR(dc.periodo_facturacion) = :anio
               AND dc.estado_documento <> 5
             GROUP BY
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126)"
        );
        $docStatusStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $docStatusStmt->execute();
        while (($docStatusRow = $docStatusStmt->fetch()) !== false) {
            $idTiendaStatus = (int) ($docStatusRow['id_tienda'] ?? 0);
            $periodoStatus = trim((string) ($docStatusRow['periodo_ym'] ?? ''));
            $estadoControl = strtoupper(trim((string) ($docStatusRow['estado_control'] ?? 'PENDIENTE')));
            if ($idTiendaStatus <= 0 || $periodoStatus === '' || !isset($months[$periodoStatus])) {
                continue;
            }
            if (!isset($docStatusByTiendaMonth[$idTiendaStatus])) {
                $docStatusByTiendaMonth[$idTiendaStatus] = [];
            }
            $docStatusByTiendaMonth[$idTiendaStatus][$periodoStatus] = match ($estadoControl) {
                'OK' => 'OK',
                'ATRASADO' => 'ATRASADO',
                default => 'PENDIENTE',
            };
        }
    }
    $perfMark('carga_estado_documentos');

    if ($canLoadDocTotals) {
        $docTotalStmt = $conn->prepare(
            "SELECT
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                ROUND(SUM(dc.monto_total), 2) AS monto_total_documento
             FROM dbo.msp_documentos_cobro dc
             WHERE YEAR(dc.periodo_facturacion) = :anio
               AND dc.estado_documento <> 5
             GROUP BY
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126)"
        );
        $docTotalStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $docTotalStmt->execute();
        while (($docTotalRow = $docTotalStmt->fetch()) !== false) {
            $idTiendaDoc = (int) ($docTotalRow['id_tienda'] ?? 0);
            $periodoDoc = trim((string) ($docTotalRow['periodo_ym'] ?? ''));
            $montoTotalDoc = round((float) ($docTotalRow['monto_total_documento'] ?? 0), 2);
            if ($idTiendaDoc <= 0 || $periodoDoc === '' || !isset($months[$periodoDoc])) {
                continue;
            }
            if (!isset($docTotalByTiendaMonth[$idTiendaDoc])) {
                $docTotalByTiendaMonth[$idTiendaDoc] = [];
            }
            $docTotalByTiendaMonth[$idTiendaDoc][$periodoDoc] = $montoTotalDoc;
        }
    }
    $perfMark('carga_totales_documentos');

    if ($canLoadDocTotals) {
        $docLinkStmt = $conn->prepare(
            "WITH documentos_periodo AS (
                SELECT
                    dc.id_tienda,
                    CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                    dc.id_documento_cobro,
                    COALESCE(NULLIF(dc.numero_documento, N''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                    ROW_NUMBER() OVER (
                        PARTITION BY dc.id_tienda, CONVERT(CHAR(7), dc.periodo_facturacion, 126)
                        ORDER BY dc.id_documento_cobro DESC
                    ) AS rn
                 FROM dbo.msp_documentos_cobro dc
                 WHERE YEAR(dc.periodo_facturacion) = :anio
                   AND dc.estado_documento <> 5
            )
            SELECT
                id_tienda,
                periodo_ym,
                id_documento_cobro,
                numero_documento
            FROM documentos_periodo
            WHERE rn = 1"
        );
        $docLinkStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $docLinkStmt->execute();
        while (($docLinkRow = $docLinkStmt->fetch()) !== false) {
            $idTiendaDocLink = (int) ($docLinkRow['id_tienda'] ?? 0);
            $periodoDocLink = trim((string) ($docLinkRow['periodo_ym'] ?? ''));
            if ($idTiendaDocLink <= 0 || $periodoDocLink === '' || !isset($months[$periodoDocLink])) {
                continue;
            }
            if (!isset($docIdByTiendaMonth[$idTiendaDocLink])) {
                $docIdByTiendaMonth[$idTiendaDocLink] = [];
            }
            if (!isset($docNumberByTiendaMonth[$idTiendaDocLink])) {
                $docNumberByTiendaMonth[$idTiendaDocLink] = [];
            }
            $docIdByTiendaMonth[$idTiendaDocLink][$periodoDocLink] = (int) ($docLinkRow['id_documento_cobro'] ?? 0);
            $docNumberByTiendaMonth[$idTiendaDocLink][$periodoDocLink] = trim((string) ($docLinkRow['numero_documento'] ?? ''));
        }
    }
    $perfMark('carga_links_documentos');

    if (
        msp2TableExists($conn, 'msp_movimientos_garantia')
        && msp2TableExists($conn, 'msp_tipos_movimiento_garantia')
        && msp2TableExists($conn, 'msp_documentos_cobro')
        && msp2TableExists($conn, 'msp_pagos')
    ) {
        $garantiaAplicadaStmt = $conn->prepare(
            "SELECT
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                ROUND(SUM(mg.monto_movimiento), 2) AS monto_garantia_aplicada
             FROM dbo.msp_movimientos_garantia mg
             INNER JOIN dbo.msp_tipos_movimiento_garantia tmg
                ON tmg.id_tipo_movimiento_garantia = mg.id_tipo_movimiento_garantia
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = mg.id_documento_cobro
             WHERE YEAR(dc.periodo_facturacion) = :anio
               AND dc.estado_documento <> 5
               AND tmg.codigo_movimiento = N'APLICACION_CARGO'
             GROUP BY
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126)
             UNION ALL
             SELECT
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                ROUND(SUM(mg.monto_movimiento), 2) AS monto_garantia_aplicada
             FROM dbo.msp_movimientos_garantia mg
             INNER JOIN dbo.msp_tipos_movimiento_garantia tmg
                ON tmg.id_tipo_movimiento_garantia = mg.id_tipo_movimiento_garantia
             INNER JOIN dbo.msp_pagos p
                ON p.id_pago = mg.id_pago
               AND p.estado_pago = 1
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = p.id_documento_cobro
             WHERE YEAR(dc.periodo_facturacion) = :anio_pago
               AND dc.estado_documento <> 5
               AND tmg.codigo_movimiento = N'APLICACION_CARGO'
               AND mg.id_documento_cobro IS NULL
             GROUP BY
                dc.id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126)"
        );
        $garantiaAplicadaStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $garantiaAplicadaStmt->bindValue(':anio_pago', $selectedYear, PDO::PARAM_INT);
        $garantiaAplicadaStmt->execute();
        while (($garantiaAplicadaRow = $garantiaAplicadaStmt->fetch()) !== false) {
            $idTiendaGarantia = (int) ($garantiaAplicadaRow['id_tienda'] ?? 0);
            $periodoGarantia = trim((string) ($garantiaAplicadaRow['periodo_ym'] ?? ''));
            if ($idTiendaGarantia <= 0 || $periodoGarantia === '' || !isset($months[$periodoGarantia])) {
                continue;
            }
            if (!isset($garantiaAplicadaByTiendaMonth[$idTiendaGarantia])) {
                $garantiaAplicadaByTiendaMonth[$idTiendaGarantia] = [];
            }
            $garantiaAplicadaByTiendaMonth[$idTiendaGarantia][$periodoGarantia] = round(
                (float) ($garantiaAplicadaByTiendaMonth[$idTiendaGarantia][$periodoGarantia] ?? 0)
                - (float) ($garantiaAplicadaRow['monto_garantia_aplicada'] ?? 0),
                2
            );
        }
    }
    $perfMark('carga_garantia_aplicada');

    if ($canLoadReservaCargos) {
        $docArriendoStmt = $conn->prepare(
            "SELECT
                COALESCE(dc.id_tienda, ca.id_tienda) AS id_tienda,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                ROUND(SUM(dcd.subtotal), 2) AS arriendo_neto_clp
             FROM dbo.msp_documentos_cobro_detalle dcd
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
             LEFT JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = dc.id_contrato_arriendo
             INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
             WHERE YEAR(dc.periodo_facturacion) = :anio
               AND dc.estado_documento <> 5
               AND tid.codigo_item = N'ARRIENDO'
             GROUP BY
                COALESCE(dc.id_tienda, ca.id_tienda),
                CONVERT(CHAR(7), dc.periodo_facturacion, 126)"
        );
        $docArriendoStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $docArriendoStmt->execute();
        while (($docArriendoRow = $docArriendoStmt->fetch()) !== false) {
            $idTiendaArriendo = (int) ($docArriendoRow['id_tienda'] ?? 0);
            $periodoArriendo = trim((string) ($docArriendoRow['periodo_ym'] ?? ''));
            if ($idTiendaArriendo <= 0 || $periodoArriendo === '' || !isset($months[$periodoArriendo])) {
                continue;
            }
            if (!isset($arriendoNetoByTiendaMonth[$idTiendaArriendo])) {
                $arriendoNetoByTiendaMonth[$idTiendaArriendo] = [];
            }
            $arriendoNetoByTiendaMonth[$idTiendaArriendo][$periodoArriendo] = round((float) ($docArriendoRow['arriendo_neto_clp'] ?? 0), 2);
        }
    }
    $perfMark('carga_arriendo_neto_documento');

    if (msp2TableExists($conn, 'msp_arriendo_local_snapshot_periodo')) {
        $snapshotArriendoStmt = $conn->prepare(
            "SELECT
                COALESCE(s.id_tienda, ca.id_tienda) AS id_tienda,
                CONVERT(CHAR(7), s.periodo_facturacion, 126) AS periodo_ym,
                ROUND(SUM(s.monto_neto_clp), 2) AS arriendo_neto_clp
             FROM dbo.msp_arriendo_local_snapshot_periodo s
             LEFT JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = s.id_contrato_arriendo
             WHERE YEAR(s.periodo_facturacion) = :anio
               AND s.estado_snapshot IN (1,2,3)
             GROUP BY
                COALESCE(s.id_tienda, ca.id_tienda),
                CONVERT(CHAR(7), s.periodo_facturacion, 126)"
        );
        $snapshotArriendoStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $snapshotArriendoStmt->execute();
        while (($snapshotArriendoRow = $snapshotArriendoStmt->fetch()) !== false) {
            $idTiendaArriendo = (int) ($snapshotArriendoRow['id_tienda'] ?? 0);
            $periodoArriendo = trim((string) ($snapshotArriendoRow['periodo_ym'] ?? ''));
            if ($idTiendaArriendo <= 0 || $periodoArriendo === '' || !isset($months[$periodoArriendo])) {
                continue;
            }
            if (!isset($arriendoNetoByTiendaMonth[$idTiendaArriendo])) {
                $arriendoNetoByTiendaMonth[$idTiendaArriendo] = [];
            }
            $arriendoNetoByTiendaMonth[$idTiendaArriendo][$periodoArriendo] = round((float) ($snapshotArriendoRow['arriendo_neto_clp'] ?? 0), 2);
        }

        $snapshotClpFijoStmt = $conn->prepare(
            "SELECT DISTINCT
                COALESCE(s.id_tienda, ca.id_tienda) AS id_tienda
             FROM dbo.msp_arriendo_local_snapshot_periodo s
             LEFT JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = s.id_contrato_arriendo
             WHERE YEAR(s.periodo_facturacion) = :anio
               AND s.estado_snapshot IN (1,2,3)
               AND UPPER(LTRIM(RTRIM(ISNULL(s.codigo_grupo_modalidad, N'')))) = N'CLP_FIJO_CONTRATO'"
        );
        $snapshotClpFijoStmt->bindValue(':anio', $selectedYear, PDO::PARAM_INT);
        $snapshotClpFijoStmt->execute();
        while (($snapshotClpFijoRow = $snapshotClpFijoStmt->fetch()) !== false) {
            $idTiendaClp = (int) ($snapshotClpFijoRow['id_tienda'] ?? 0);
            if ($idTiendaClp > 0) {
                $clpFijoContratoByTienda[$idTiendaClp] = true;
            }
        }
    }
    $perfMark('carga_snapshots_arriendo');

    $yearStart = sprintf('%04d-01-01', $selectedYear);
    $yearEnd = sprintf('%04d-12-31', $selectedYear);

    if (
        msp2TableExists($conn, 'msp_contrato_local_arriendo_regla')
        && msp2TableExists($conn, 'msp_tipo_modalidad_arriendo')
    ) {
        $reglaClpFijoStmt = $conn->prepare(
            "SELECT DISTINCT
                c.id_tienda
             FROM dbo.msp_contratos_arriendo c
             INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_contrato_arriendo = c.id_contrato_arriendo
               AND cl.estado_relacion IN (1,2)
             INNER JOIN dbo.msp_contrato_local_arriendo_regla rr
                ON rr.id_contrato_local = cl.id_contrato_local
               AND rr.estado_regla = 1
               AND rr.fecha_inicio <= :year_end_clp
               AND (rr.fecha_termino IS NULL OR rr.fecha_termino >= :year_start_clp)
             INNER JOIN dbo.msp_tipo_modalidad_arriendo tm
                ON tm.id_modalidad_arriendo = rr.id_modalidad_arriendo
             WHERE c.estado_contrato IN (1,2,3,4)
               AND c.fecha_inicio <= :year_end_ca_clp
               AND (c.fecha_termino_efectiva IS NULL OR DATEADD(MONTH, 2, c.fecha_termino_efectiva) >= :year_start_ca_clp)
               AND UPPER(LTRIM(RTRIM(ISNULL(tm.codigo_modalidad, N'')))) = N'CLP_FIJO'"
        );
        $reglaClpFijoStmt->bindValue(':year_end_clp', $yearEnd, PDO::PARAM_STR);
        $reglaClpFijoStmt->bindValue(':year_start_clp', $yearStart, PDO::PARAM_STR);
        $reglaClpFijoStmt->bindValue(':year_end_ca_clp', $yearEnd, PDO::PARAM_STR);
        $reglaClpFijoStmt->bindValue(':year_start_ca_clp', $yearStart, PDO::PARAM_STR);
        $reglaClpFijoStmt->execute();
        while (($reglaClpFijoRow = $reglaClpFijoStmt->fetch()) !== false) {
            $idTiendaClp = (int) ($reglaClpFijoRow['id_tienda'] ?? 0);
            if ($idTiendaClp > 0) {
                $clpFijoContratoByTienda[$idTiendaClp] = true;
            }
        }

        $reglaClpFijoMontoStmt = $conn->prepare(
            "SELECT
                c.id_tienda,
                c.id_contrato_arriendo,
                cl.id_contrato_local,
                cl.fecha_inicio AS fecha_inicio_local,
                cl.fecha_termino AS fecha_termino_local,
                rr.fecha_inicio AS fecha_inicio_regla,
                rr.fecha_termino AS fecha_termino_regla,
                ISNULL(rr.codigo_grupo_modalidad, N'') AS codigo_grupo_modalidad,
                ROUND(ISNULL(rr.valor_base_clp, 0), 2) AS valor_base_clp,
                ROUND(ISNULL(rr.descuento_mensual_clp, 0), 2) AS descuento_mensual_clp
             FROM dbo.msp_contratos_arriendo c
             INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_contrato_arriendo = c.id_contrato_arriendo
               AND cl.estado_relacion IN (1,2)
             INNER JOIN dbo.msp_contrato_local_arriendo_regla rr
                ON rr.id_contrato_local = cl.id_contrato_local
               AND rr.estado_regla = 1
             INNER JOIN dbo.msp_tipo_modalidad_arriendo tm
                ON tm.id_modalidad_arriendo = rr.id_modalidad_arriendo
             WHERE c.estado_contrato IN (1,2,3,4)
               AND c.fecha_inicio <= :year_end_clp_monto
               AND (c.fecha_termino_efectiva IS NULL OR DATEADD(MONTH, 2, c.fecha_termino_efectiva) >= :year_start_clp_monto)
               AND rr.fecha_inicio <= :year_end_regla_clp_monto
               AND (rr.fecha_termino IS NULL OR rr.fecha_termino >= :year_start_regla_clp_monto)
               AND UPPER(LTRIM(RTRIM(ISNULL(tm.codigo_modalidad, N'')))) = N'CLP_FIJO'"
        );
        $reglaClpFijoMontoStmt->bindValue(':year_end_clp_monto', $yearEnd, PDO::PARAM_STR);
        $reglaClpFijoMontoStmt->bindValue(':year_start_clp_monto', $yearStart, PDO::PARAM_STR);
        $reglaClpFijoMontoStmt->bindValue(':year_end_regla_clp_monto', $yearEnd, PDO::PARAM_STR);
        $reglaClpFijoMontoStmt->bindValue(':year_start_regla_clp_monto', $yearStart, PDO::PARAM_STR);
        $reglaClpFijoMontoStmt->execute();
        while (($reglaMontoRow = $reglaClpFijoMontoStmt->fetch()) !== false) {
            $idTiendaMonto = (int) ($reglaMontoRow['id_tienda'] ?? 0);
            $idContratoMonto = (int) ($reglaMontoRow['id_contrato_arriendo'] ?? 0);
            $idContratoLocalMonto = (int) ($reglaMontoRow['id_contrato_local'] ?? 0);
            if ($idTiendaMonto <= 0 || $idContratoMonto <= 0 || $idContratoLocalMonto <= 0) {
                continue;
            }

            $valorBaseClp = round((float) ($reglaMontoRow['valor_base_clp'] ?? 0), 2);
            $descuentoClp = round((float) ($reglaMontoRow['descuento_mensual_clp'] ?? 0), 2);
            $montoNetoRegla = round($valorBaseClp - $descuentoClp, 2);

            $fechaInicioLocal = substr(trim((string) ($reglaMontoRow['fecha_inicio_local'] ?? '')), 0, 10);
            $fechaTerminoLocal = substr(trim((string) ($reglaMontoRow['fecha_termino_local'] ?? '')), 0, 10);
            $fechaInicioRegla = substr(trim((string) ($reglaMontoRow['fecha_inicio_regla'] ?? '')), 0, 10);
            $fechaTerminoRegla = substr(trim((string) ($reglaMontoRow['fecha_termino_regla'] ?? '')), 0, 10);
            $codigoGrupoRaw = strtoupper(trim((string) ($reglaMontoRow['codigo_grupo_modalidad'] ?? '')));
            $isGrupoContrato = $codigoGrupoRaw === 'CLP_FIJO_CONTRATO';
            $groupKey = $isGrupoContrato
                ? ('CT-' . $idContratoMonto . '-GRP-' . $codigoGrupoRaw)
                : ('CT-' . $idContratoMonto . '-CL-' . $idContratoLocalMonto);

            foreach ($months as $monthKey => $monthData) {
                $monthStart = (string) ($monthPeriodMeta[$monthKey]['start'] ?? ($monthKey . '-01'));
                $monthEnd = (string) ($monthPeriodMeta[$monthKey]['end'] ?? ($monthKey . '-31'));
                if (!msp2ControlDiarioMonthOverlapsRange($monthStart, $monthEnd, $fechaInicioLocal, $fechaTerminoLocal)) {
                    continue;
                }
                if (!msp2ControlDiarioMonthOverlapsRange($monthStart, $monthEnd, $fechaInicioRegla, $fechaTerminoRegla)) {
                    continue;
                }

                if (!isset($clpFijoFallbackByTiendaMonth[$idTiendaMonto])) {
                    $clpFijoFallbackByTiendaMonth[$idTiendaMonto] = [];
                }
                if (!isset($clpFijoFallbackByTiendaMonth[$idTiendaMonto][$monthKey])) {
                    $clpFijoFallbackByTiendaMonth[$idTiendaMonto][$monthKey] = [];
                }

                $currentMonto = round(
                    (float) ($clpFijoFallbackByTiendaMonth[$idTiendaMonto][$monthKey][$groupKey] ?? 0),
                    2
                );
                if ($montoNetoRegla > $currentMonto) {
                    $clpFijoFallbackByTiendaMonth[$idTiendaMonto][$monthKey][$groupKey] = $montoNetoRegla;
                }
            }
        }
    }
    $perfMark('fallback_clp_fijo_reglas');

    $monthPeriodMeta = [];
    foreach ($months as $monthKey => $monthData) {
        $monthStart = $monthKey . '-01';
        $monthDate = new DateTimeImmutable($monthStart);
        $monthPeriodMeta[$monthKey] = [
            'start' => $monthDate->format('Y-m-01'),
            'end' => $monthDate->format('Y-m-t'),
            'uf' => round((float) ($monthData['uf'] ?? 0), 4),
        ];
    }

    if (
        msp2TableExists($conn, 'msp_contrato_local_arriendo_regla')
        && msp2TableExists($conn, 'msp_tipo_modalidad_arriendo')
        && msp2TableExists($conn, 'msp_contrato_locales')
        && msp2TableExists($conn, 'msp_contratos_arriendo')
    ) {
        $periodoUfByContratoLocalMonth = [];
        if (msp2TableExists($conn, 'msp_contrato_local_arriendo_periodo')) {
            $periodoUfStmt = $conn->prepare(
                "SELECT
                    ap.id_contrato_local,
                    CONVERT(CHAR(7), ap.periodo_facturacion, 126) AS periodo_ym,
                    ROUND(ISNULL(ap.valor_periodo_uf, 0), 6) AS valor_periodo_uf
                 FROM dbo.msp_contrato_local_arriendo_periodo ap
                 WHERE YEAR(ap.periodo_facturacion) = :anio_uf_fallback
                   AND ap.estado_periodo = 1"
            );
            $periodoUfStmt->bindValue(':anio_uf_fallback', $selectedYear, PDO::PARAM_INT);
            $periodoUfStmt->execute();
            while (($periodoUfRow = $periodoUfStmt->fetch()) !== false) {
                $idContratoLocalPeriodo = (int) ($periodoUfRow['id_contrato_local'] ?? 0);
                $periodoYm = trim((string) ($periodoUfRow['periodo_ym'] ?? ''));
                if ($idContratoLocalPeriodo <= 0 || $periodoYm === '' || !isset($months[$periodoYm])) {
                    continue;
                }
                if (!isset($periodoUfByContratoLocalMonth[$idContratoLocalPeriodo])) {
                    $periodoUfByContratoLocalMonth[$idContratoLocalPeriodo] = [];
                }
                $periodoUfByContratoLocalMonth[$idContratoLocalPeriodo][$periodoYm] = round(
                    (float) ($periodoUfRow['valor_periodo_uf'] ?? 0),
                    6
                );
            }
        }

        $reglaUfStmt = $conn->prepare(
            "SELECT
                c.id_tienda,
                cl.id_contrato_local,
                cl.fecha_inicio AS fecha_inicio_local,
                cl.fecha_termino AS fecha_termino_local,
                rr.fecha_inicio AS fecha_inicio_regla,
                rr.fecha_termino AS fecha_termino_regla,
                UPPER(LTRIM(RTRIM(ISNULL(tm.codigo_modalidad, N'UF_ESTATICO')))) AS codigo_modalidad,
                ROUND(ISNULL(rr.valor_base_uf, 0), 6) AS valor_base_uf
             FROM dbo.msp_contratos_arriendo c
             INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_contrato_arriendo = c.id_contrato_arriendo
               AND cl.estado_relacion IN (1,2)
             INNER JOIN dbo.msp_contrato_local_arriendo_regla rr
                ON rr.id_contrato_local = cl.id_contrato_local
               AND rr.estado_regla = 1
             INNER JOIN dbo.msp_tipo_modalidad_arriendo tm
                ON tm.id_modalidad_arriendo = rr.id_modalidad_arriendo
             WHERE c.estado_contrato IN (1,2,3,4)
               AND c.fecha_inicio <= :year_end_uf_fallback
               AND (c.fecha_termino_efectiva IS NULL OR DATEADD(MONTH, 2, c.fecha_termino_efectiva) >= :year_start_uf_fallback)
               AND rr.fecha_inicio <= :year_end_regla_uf_fallback
               AND (rr.fecha_termino IS NULL OR rr.fecha_termino >= :year_start_regla_uf_fallback)
               AND UPPER(LTRIM(RTRIM(ISNULL(tm.codigo_modalidad, N'')))) IN (N'UF_ESTATICO', N'DINAMICO_MENSUAL')"
        );
        $reglaUfStmt->bindValue(':year_end_uf_fallback', $yearEnd, PDO::PARAM_STR);
        $reglaUfStmt->bindValue(':year_start_uf_fallback', $yearStart, PDO::PARAM_STR);
        $reglaUfStmt->bindValue(':year_end_regla_uf_fallback', $yearEnd, PDO::PARAM_STR);
        $reglaUfStmt->bindValue(':year_start_regla_uf_fallback', $yearStart, PDO::PARAM_STR);
        $reglaUfStmt->execute();
        while (($reglaUfRow = $reglaUfStmt->fetch()) !== false) {
            $idTiendaUf = (int) ($reglaUfRow['id_tienda'] ?? 0);
            $idContratoLocalUf = (int) ($reglaUfRow['id_contrato_local'] ?? 0);
            if ($idTiendaUf <= 0 || $idContratoLocalUf <= 0) {
                continue;
            }
            $modalidadUf = strtoupper(trim((string) ($reglaUfRow['codigo_modalidad'] ?? 'UF_ESTATICO')));
            $valorBaseUf = round((float) ($reglaUfRow['valor_base_uf'] ?? 0), 6);
            $fechaInicioLocal = substr(trim((string) ($reglaUfRow['fecha_inicio_local'] ?? '')), 0, 10);
            $fechaTerminoLocal = substr(trim((string) ($reglaUfRow['fecha_termino_local'] ?? '')), 0, 10);
            $fechaInicioRegla = substr(trim((string) ($reglaUfRow['fecha_inicio_regla'] ?? '')), 0, 10);
            $fechaTerminoRegla = substr(trim((string) ($reglaUfRow['fecha_termino_regla'] ?? '')), 0, 10);

            foreach ($months as $monthKey => $monthData) {
                $monthStart = (string) ($monthPeriodMeta[$monthKey]['start'] ?? ($monthKey . '-01'));
                $monthEnd = (string) ($monthPeriodMeta[$monthKey]['end'] ?? ($monthKey . '-31'));
                if (!msp2ControlDiarioMonthOverlapsRange($monthStart, $monthEnd, $fechaInicioLocal, $fechaTerminoLocal)) {
                    continue;
                }
                if (!msp2ControlDiarioMonthOverlapsRange($monthStart, $monthEnd, $fechaInicioRegla, $fechaTerminoRegla)) {
                    continue;
                }

                $valorUfMes = $valorBaseUf;
                if ($modalidadUf === 'DINAMICO_MENSUAL') {
                    $valorUfPeriodo = (float) ($periodoUfByContratoLocalMonth[$idContratoLocalUf][$monthKey] ?? 0);
                    $valorUfMes = $valorUfPeriodo > 0 ? round($valorUfPeriodo, 6) : $valorBaseUf;
                }
                if ($valorUfMes <= 0) {
                    continue;
                }

                if (!isset($ufFallbackByTiendaMonth[$idTiendaUf])) {
                    $ufFallbackByTiendaMonth[$idTiendaUf] = [];
                }
                if (!isset($ufFallbackByTiendaMonth[$idTiendaUf][$monthKey])) {
                    $ufFallbackByTiendaMonth[$idTiendaUf][$monthKey] = [];
                }

                $currentUf = round((float) ($ufFallbackByTiendaMonth[$idTiendaUf][$monthKey][$idContratoLocalUf] ?? 0), 6);
                if ($valorUfMes > $currentUf) {
                    $ufFallbackByTiendaMonth[$idTiendaUf][$monthKey][$idContratoLocalUf] = $valorUfMes;
                }
            }
        }
    }
    $perfMark('fallback_uf_reglas');

    $buildContratoPendienteSql = static function (string $contratoAlias) use ($conn, $selectedYear): string {
        $parts = [];
        if (msp2TableExists($conn, 'msp_documentos_cobro')) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_documentos_cobro dc_pend
                WHERE dc_pend.id_tienda = {$contratoAlias}.id_tienda
                  AND dc_pend.estado_documento IN (1,2,3)
                  AND ISNULL(dc_pend.saldo_pendiente, 0) > 0.005
            )";
        }
        if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_cargos_contrato_local ccl_pend
                INNER JOIN dbo.msp_contrato_locales cl_pend
                    ON cl_pend.id_contrato_local = ccl_pend.id_contrato_local
                WHERE cl_pend.id_contrato_arriendo = {$contratoAlias}.id_contrato_arriendo
                  AND ccl_pend.estado_cargo IN (1,2)
            )";
        }
        if (msp2TableExists($conn, 'msp_cargos_salida')) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_cargos_salida cs_pend
                WHERE cs_pend.id_contrato_arriendo = {$contratoAlias}.id_contrato_arriendo
                  AND cs_pend.estado_cargo IN (1,2)
            )";
        }
        if (msp2TableExists($conn, 'msp_garantias') && msp2TableExists($conn, 'msp_vw_garantias_resumen')) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_garantias g_pend
                INNER JOIN dbo.msp_vw_garantias_resumen gr_pend
                    ON gr_pend.id_garantia = g_pend.id_garantia
                WHERE g_pend.id_contrato_arriendo = {$contratoAlias}.id_contrato_arriendo
                  AND g_pend.estado_garantia <> 6
                  AND (ISNULL(gr_pend.saldo_disponible, 0) > 0.005 OR ISNULL(gr_pend.saldo_reservado, 0) > 0.005)
            )";
        }
        if (
            msp2TableExists($conn, 'msp_cobros_servicios')
            && msp2TableExists($conn, 'msp_lecturas_medidores')
            && msp2TableExists($conn, 'msp_procesos_cobro_servicio')
            && msp2TableExists($conn, 'msp_cierre_mensual')
            && msp2TableExists($conn, 'msp_medidores')
        ) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_contrato_locales cl_serv_pend
                INNER JOIN dbo.msp_medidores m_serv_pend
                    ON m_serv_pend.id_local = cl_serv_pend.id_local
                INNER JOIN dbo.msp_lecturas_medidores lm_serv_pend
                    ON lm_serv_pend.id_medidor = m_serv_pend.id_medidor
                INNER JOIN dbo.msp_cobros_servicios cs_serv_pend
                    ON cs_serv_pend.id_lectura = lm_serv_pend.id_lectura
                INNER JOIN dbo.msp_procesos_cobro_servicio p_serv_pend
                    ON p_serv_pend.id_proceso_cobro = lm_serv_pend.id_proceso_cobro
                INNER JOIN dbo.msp_cierre_mensual cm_serv_pend
                    ON cm_serv_pend.id_cierre_mensual = p_serv_pend.id_cierre_mensual
                WHERE cl_serv_pend.id_contrato_arriendo = {$contratoAlias}.id_contrato_arriendo
                  AND YEAR(cm_serv_pend.periodo_facturacion) = " . (int) $selectedYear . "
            )";
        }

        return $parts !== [] ? '(' . implode(' OR ', $parts) . ')' : '(1 = 0)';
    };

    $buildContratoVisibleSql = static function (string $contratoAlias, string $yearStartParam) use ($buildContratoPendienteSql): string {
        return "({$contratoAlias}.fecha_termino_efectiva IS NULL OR DATEADD(MONTH, 2, {$contratoAlias}.fecha_termino_efectiva) >= {$yearStartParam} OR " . $buildContratoPendienteSql($contratoAlias) . ')';
    };

    $buildContratoLocalPendienteSql = static function (string $contratoAlias, string $contratoLocalAlias) use ($conn, $selectedYear): string {
        $parts = [];
        if (msp2TableExists($conn, 'msp_documentos_cobro')) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_documentos_cobro dc_pend
                WHERE dc_pend.id_tienda = {$contratoAlias}.id_tienda
                  AND dc_pend.estado_documento IN (1,2,3)
                  AND ISNULL(dc_pend.saldo_pendiente, 0) > 0.005
            )";
        }
        if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_cargos_contrato_local ccl_pend
                WHERE ccl_pend.id_contrato_local = {$contratoLocalAlias}.id_contrato_local
                  AND ccl_pend.estado_cargo IN (1,2)
            )";
        }
        if (msp2TableExists($conn, 'msp_cargos_salida')) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_cargos_salida cs_pend
                WHERE cs_pend.id_contrato_arriendo = {$contratoAlias}.id_contrato_arriendo
                  AND cs_pend.id_local = {$contratoLocalAlias}.id_local
                  AND cs_pend.estado_cargo IN (1,2)
            )";
        }
        if (msp2TableExists($conn, 'msp_garantias') && msp2TableExists($conn, 'msp_vw_garantias_resumen')) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_garantias g_pend
                INNER JOIN dbo.msp_vw_garantias_resumen gr_pend
                    ON gr_pend.id_garantia = g_pend.id_garantia
                WHERE g_pend.id_contrato_local = {$contratoLocalAlias}.id_contrato_local
                  AND g_pend.estado_garantia <> 6
                  AND (ISNULL(gr_pend.saldo_disponible, 0) > 0.005 OR ISNULL(gr_pend.saldo_reservado, 0) > 0.005)
            )";
        }
        if (
            msp2TableExists($conn, 'msp_cobros_servicios')
            && msp2TableExists($conn, 'msp_lecturas_medidores')
            && msp2TableExists($conn, 'msp_procesos_cobro_servicio')
            && msp2TableExists($conn, 'msp_cierre_mensual')
            && msp2TableExists($conn, 'msp_medidores')
        ) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_medidores m_serv_pend
                INNER JOIN dbo.msp_lecturas_medidores lm_serv_pend
                    ON lm_serv_pend.id_medidor = m_serv_pend.id_medidor
                INNER JOIN dbo.msp_cobros_servicios cs_serv_pend
                    ON cs_serv_pend.id_lectura = lm_serv_pend.id_lectura
                INNER JOIN dbo.msp_procesos_cobro_servicio p_serv_pend
                    ON p_serv_pend.id_proceso_cobro = lm_serv_pend.id_proceso_cobro
                INNER JOIN dbo.msp_cierre_mensual cm_serv_pend
                    ON cm_serv_pend.id_cierre_mensual = p_serv_pend.id_cierre_mensual
                WHERE m_serv_pend.id_local = {$contratoLocalAlias}.id_local
                  AND YEAR(cm_serv_pend.periodo_facturacion) = " . (int) $selectedYear . "
            )";
        }

        return $parts !== [] ? '(' . implode(' OR ', $parts) . ')' : '(1 = 0)';
    };

    $tiendaRows = [];
    $contratoVisibleSql = $buildContratoVisibleSql('c', ':year_start');
    $tiendasStmt = $conn->prepare(
        "SELECT DISTINCT
            t.id_tienda,
            COALESCE(NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda,
            COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), N'Sin arrendatario') AS nombre_arrendatario,
            a.rut
         FROM dbo.msp_tiendas t
         INNER JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = t.id_arrendatario
         INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_tienda = t.id_tienda
           AND c.estado_contrato IN (1,2,3,4)
           AND c.fecha_inicio <= :year_end
           AND {$contratoVisibleSql}
         ORDER BY nombre_tienda ASC, t.id_tienda ASC"
    );
    $tiendasStmt->bindValue(':year_end', $yearEnd, PDO::PARAM_STR);
    $tiendasStmt->bindValue(':year_start', $yearStart, PDO::PARAM_STR);
    $tiendasStmt->execute();
    while (($row = $tiendasStmt->fetch()) !== false) {
        $idTienda = (int) ($row['id_tienda'] ?? 0);
        if ($idTienda <= 0) {
            continue;
        }
        $rutRaw = trim((string) ($row['rut'] ?? ''));
        $tiendaRows[$idTienda] = [
            'id_tienda' => $idTienda,
            'nombre_tienda' => trim((string) ($row['nombre_tienda'] ?? '')),
            'arrendatario' => trim((string) ($row['nombre_arrendatario'] ?? '')),
            'rut_raw' => $rutRaw,
            'rut_display' => msp2RutFormatDisplay($rutRaw),
            'uf_base' => 0.0,
            'local_ids' => [],
            'local_codes' => [],
            'contrato_local_ids' => [],
            'legacy_uf_entries' => [],
            'legacy_uf_by_contrato_local' => [],
            'uf_base_by_month' => [],
            'arrendatario_by_month' => [],
            'arrendatario_id_by_month' => [],
            'rut_display_by_month' => [],
            'termino_by_month' => [],
            'post_termino_by_month' => [],
        ];
    }
    $perfMark('carga_tiendas_base');

    if ($tiendaRows !== []) {
        $tiendaIds = array_keys($tiendaRows);
        $placeholders = [];
        foreach ($tiendaIds as $index => $tiendaId) {
            $placeholders[] = ':tid_' . $index;
        }

        $contratosStmt = $conn->prepare(
            'SELECT
                c.id_tienda,
                c.id_contrato_arriendo,
                c.id_arrendatario,
                c.estado_contrato,
                c.fecha_inicio,
                c.fecha_termino_efectiva,
                COALESCE(NULLIF(a.nombre_locatario, \'\'), NULLIF(a.nombre_representante, \'\'), N\'Sin arrendatario\') AS nombre_arrendatario,
                a.rut
             FROM dbo.msp_contratos_arriendo c
             LEFT JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = c.id_arrendatario
             WHERE c.id_tienda IN (' . implode(', ', $placeholders) . ')
               AND c.estado_contrato IN (1,2,3,4)
               AND c.fecha_inicio <= :year_end
               AND ' . $buildContratoVisibleSql('c', ':year_start') . '
             ORDER BY
                c.id_tienda ASC,
                c.fecha_inicio DESC,
                c.id_contrato_arriendo DESC'
        );
        foreach ($tiendaIds as $index => $tiendaId) {
            $contratosStmt->bindValue(':tid_' . $index, (int) $tiendaId, PDO::PARAM_INT);
        }
        $contratosStmt->bindValue(':year_end', $yearEnd, PDO::PARAM_STR);
        $contratosStmt->bindValue(':year_start', $yearStart, PDO::PARAM_STR);
        $contratosStmt->execute();
        $contratosByTienda = [];
        while (($contratoRow = $contratosStmt->fetch()) !== false) {
            $idTiendaContrato = (int) ($contratoRow['id_tienda'] ?? 0);
            if ($idTiendaContrato <= 0 || !isset($tiendaRows[$idTiendaContrato])) {
                continue;
            }
            if (!isset($contratosByTienda[$idTiendaContrato])) {
                $contratosByTienda[$idTiendaContrato] = [];
            }
            $contratosByTienda[$idTiendaContrato][] = $contratoRow;
        }

        $localesStmt = $conn->prepare(
            'SELECT
                c.id_tienda,
                cl.id_contrato_local,
                cl.id_local,
                l.cdo_local,
                cl.fecha_inicio,
                cl.fecha_termino,
                CAST(COALESCE(l.valor_arriendo_uf, 0) AS DECIMAL(18,6)) AS valor_arriendo_uf
             FROM dbo.msp_contratos_arriendo c
             INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_contrato_arriendo = c.id_contrato_arriendo
               AND cl.estado_relacion IN (1,2)
               AND (
                    (cl.fecha_inicio <= :year_end_loc_cl AND (cl.fecha_termino IS NULL OR DATEADD(MONTH, 2, cl.fecha_termino) >= :year_start_loc_cl))
                    OR ' . $buildContratoLocalPendienteSql('c', 'cl') . '
               )
             INNER JOIN dbo.msp_locales l
                ON l.id_local = cl.id_local
             WHERE c.id_tienda IN (' . implode(', ', $placeholders) . ')
               AND c.estado_contrato IN (1,2,3,4)
               AND c.fecha_inicio <= :year_end_loc_ca
               AND ' . $buildContratoVisibleSql('c', ':year_start_loc_ca') . '
             ORDER BY c.id_tienda ASC, ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
        );
        foreach ($tiendaIds as $index => $tiendaId) {
            $localesStmt->bindValue(':tid_' . $index, (int) $tiendaId, PDO::PARAM_INT);
        }
        $localesStmt->bindValue(':year_end_loc_cl', $yearEnd, PDO::PARAM_STR);
        $localesStmt->bindValue(':year_start_loc_cl', $yearStart, PDO::PARAM_STR);
        $localesStmt->bindValue(':year_end_loc_ca', $yearEnd, PDO::PARAM_STR);
        $localesStmt->bindValue(':year_start_loc_ca', $yearStart, PDO::PARAM_STR);
        $localesStmt->execute();

        $seenLocalByTienda = [];
        while (($localRow = $localesStmt->fetch()) !== false) {
            $idTienda = (int) ($localRow['id_tienda'] ?? 0);
            $idContratoLocal = (int) ($localRow['id_contrato_local'] ?? 0);
            $idLocal = (int) ($localRow['id_local'] ?? 0);
            if (!isset($tiendaRows[$idTienda]) || $idLocal <= 0) {
                continue;
            }
            if (!isset($seenLocalByTienda[$idTienda])) {
                $seenLocalByTienda[$idTienda] = [];
            }
            if (isset($seenLocalByTienda[$idTienda][$idLocal])) {
                continue;
            }
            $seenLocalByTienda[$idTienda][$idLocal] = true;

            $localCode = msp2NormalizeLocalCode((string) ($localRow['cdo_local'] ?? ''));
            if ($localCode === '') {
                continue;
            }
            $tiendaRows[$idTienda]['local_ids'][] = $idLocal;
            $tiendaRows[$idTienda]['local_codes'][] = $localCode;
            if ($idContratoLocal > 0) {
                $tiendaRows[$idTienda]['contrato_local_ids'][] = $idContratoLocal;
                $tiendaRows[$idTienda]['legacy_uf_by_contrato_local'][$idContratoLocal] = round(
                    (float) ($localRow['valor_arriendo_uf'] ?? 0),
                    6
                );
                $tiendaRows[$idTienda]['legacy_uf_entries'][] = [
                    'id_contrato_local' => $idContratoLocal,
                    'id_local' => $idLocal,
                    'valor_uf' => round((float) ($localRow['valor_arriendo_uf'] ?? 0), 6),
                    'fecha_inicio' => substr(trim((string) ($localRow['fecha_inicio'] ?? '')), 0, 10),
                    'fecha_termino' => substr(trim((string) ($localRow['fecha_termino'] ?? '')), 0, 10),
                ];
            }
            $tiendaRows[$idTienda]['uf_base'] = round(
                (float) $tiendaRows[$idTienda]['uf_base'] + (float) ($localRow['valor_arriendo_uf'] ?? 0),
                6
            );
        }
        $perfMark('carga_contratos_y_locales');

        foreach ($tiendaRows as $idTienda => &$tiendaDataRef) {
            $arrendatarioByMonth = [];
            $arrendatarioIdByMonth = [];
            $rutDisplayByMonth = [];
            $terminoByMonth = [];
            $postTerminoByMonth = [];
            $contratosTienda = is_array($contratosByTienda[$idTienda] ?? null) ? $contratosByTienda[$idTienda] : [];
            foreach ($months as $monthKey => $monthData) {
                $arrValue = '';
                $arrendatarioIdValue = 0;
                $rutValue = '-';
                $monthStart = (string) ($monthPeriodMeta[$monthKey]['start'] ?? ($monthKey . '-01'));
                $monthEnd = (string) ($monthPeriodMeta[$monthKey]['end'] ?? ($monthKey . '-31'));

                foreach ($contratosTienda as $contratoTiendaRow) {
                    $fechaInicio = trim((string) ($contratoTiendaRow['fecha_inicio'] ?? ''));
                    $fechaTermino = trim((string) ($contratoTiendaRow['fecha_termino_efectiva'] ?? ''));
                    $estadoContratoMes = (int) ($contratoTiendaRow['estado_contrato'] ?? 0);
                    if ($fechaInicio === '' || $fechaInicio > $monthEnd) {
                        continue;
                    }
                    $releaseMonthYm = null;
                    if ($fechaTermino !== '') {
                        $fechaTerminoDate = DateTimeImmutable::createFromFormat('Y-m-d', substr($fechaTermino, 0, 10));
                        if ($fechaTerminoDate !== false) {
                            $releaseMonthYm = $fechaTerminoDate->modify('first day of this month')->modify('+2 months')->format('Y-m');
                        }
                    }
                    if ($releaseMonthYm !== null && $monthKey >= $releaseMonthYm) {
                        continue;
                    }
                    if ($fechaTermino !== '' && $fechaTermino < $monthStart && !in_array($estadoContratoMes, [3, 4], true)) {
                        continue;
                    }

                    $arrTmp = trim((string) ($contratoTiendaRow['nombre_arrendatario'] ?? ''));
                    $rutTmpRaw = trim((string) ($contratoTiendaRow['rut'] ?? ''));
                    $arrValue = $arrTmp;
                    $arrendatarioIdValue = (int) ($contratoTiendaRow['id_arrendatario'] ?? 0);
                    $rutValue = $rutTmpRaw !== '' ? msp2RutFormatDisplay($rutTmpRaw) : '-';
                    break;
                }

                $arrendatarioByMonth[$monthKey] = $arrValue;
                $arrendatarioIdByMonth[$monthKey] = $arrendatarioIdValue;
                $rutDisplayByMonth[$monthKey] = $rutValue;
            }

            foreach ($months as $monthKey => $monthData) {
                $monthStart = (string) ($monthPeriodMeta[$monthKey]['start'] ?? ($monthKey . '-01'));
                $monthEnd = (string) ($monthPeriodMeta[$monthKey]['end'] ?? ($monthKey . '-31'));
                $arrCurrent = trim((string) ($arrendatarioByMonth[$monthKey] ?? ''));
                if ($arrCurrent === '') {
                    $terminoByMonth[$monthKey] = false;
                    continue;
                }

                $hasTerminoContratoMes = false;
                foreach ($contratosTienda as $contratoTiendaRow) {
                    $idContratoTermino = (int) ($contratoTiendaRow['id_contrato_arriendo'] ?? 0);
                    $fechaInicio = trim((string) ($contratoTiendaRow['fecha_inicio'] ?? ''));
                    $fechaTermino = trim((string) ($contratoTiendaRow['fecha_termino_efectiva'] ?? ''));
                    if ($fechaInicio === '' || $fechaTermino === '') {
                        continue;
                    }
                    if ($fechaInicio > $monthEnd) {
                        continue;
                    }
                    if ($fechaTermino < $monthStart || $fechaTermino > $monthEnd) {
                        continue;
                    }

                    $tieneContinuidadMismoMes = false;
                    foreach ($contratosTienda as $contratoSucesorRow) {
                        $idContratoSucesor = (int) ($contratoSucesorRow['id_contrato_arriendo'] ?? 0);
                        if ($idContratoSucesor <= 0 || $idContratoSucesor === $idContratoTermino) {
                            continue;
                        }
                        $fechaInicioSucesor = trim((string) ($contratoSucesorRow['fecha_inicio'] ?? ''));
                        if ($fechaInicioSucesor === '') {
                            continue;
                        }
                        if ($fechaInicioSucesor >= $fechaTermino && $fechaInicioSucesor <= $monthEnd) {
                            $tieneContinuidadMismoMes = true;
                            break;
                        }
                    }
                    if ($tieneContinuidadMismoMes) {
                        continue;
                    }

                    $hasTerminoContratoMes = true;
                    break;
                }

                $terminoByMonth[$monthKey] = $hasTerminoContratoMes;

                $hasPostTerminoContratoMes = false;
                foreach ($contratosTienda as $contratoTiendaRow) {
                    $fechaTermino = trim((string) ($contratoTiendaRow['fecha_termino_efectiva'] ?? ''));
                    if ($fechaTermino === '') {
                        continue;
                    }
                    $fechaTerminoDate = DateTimeImmutable::createFromFormat('Y-m-d', substr($fechaTermino, 0, 10));
                    if ($fechaTerminoDate === false) {
                        continue;
                    }
                    if ($monthKey >= $fechaTerminoDate->modify('first day of this month')->modify('+2 months')->format('Y-m')) {
                        $hasPostTerminoContratoMes = true;
                        break;
                    }
                }

                $hasPostTerminoContratoMes = $hasPostTerminoContratoMes
                    && trim((string) ($arrendatarioByMonth[$monthKey] ?? '')) === '';
                $postTerminoByMonth[$monthKey] = $hasPostTerminoContratoMes;
                if ($hasPostTerminoContratoMes) {
                    $arrendatarioByMonth[$monthKey] = '';
                    $arrendatarioIdByMonth[$monthKey] = 0;
                    $rutDisplayByMonth[$monthKey] = '-';
                }
            }

            $firstMonthKey = array_key_first($months);
            if ($firstMonthKey !== null) {
                $arrMesInicial = trim((string) ($arrendatarioByMonth[$firstMonthKey] ?? ''));
                $rutMesInicial = trim((string) ($rutDisplayByMonth[$firstMonthKey] ?? '-'));
                $tiendaDataRef['arrendatario'] = $arrMesInicial;
                $tiendaDataRef['rut_display'] = $rutMesInicial !== '' ? $rutMesInicial : '-';
            }
            $tiendaDataRef['arrendatario_by_month'] = $arrendatarioByMonth;
            $tiendaDataRef['arrendatario_id_by_month'] = $arrendatarioIdByMonth;
            $tiendaDataRef['rut_display_by_month'] = $rutDisplayByMonth;
            $tiendaDataRef['termino_by_month'] = $terminoByMonth;
            $tiendaDataRef['post_termino_by_month'] = $postTerminoByMonth;
        }
        unset($tiendaDataRef);
        $perfMark('armado_arrendatarios_y_terminos');

    }

    foreach ($tiendaRows as $idTienda => &$tiendaDataRef) {
        $ufBaseByMonth = [];
        $arriendoNetoByMonth = is_array($arriendoNetoByTiendaMonth[$idTienda] ?? null)
            ? $arriendoNetoByTiendaMonth[$idTienda]
            : [];
        $legacyUfEntries = is_array($tiendaDataRef['legacy_uf_entries'] ?? null)
            ? $tiendaDataRef['legacy_uf_entries']
            : [];

        foreach ($months as $monthKey => $monthData) {
            $monthUf = (float) ($monthPeriodMeta[$monthKey]['uf'] ?? 0);
            $arriendoNetoMes = array_key_exists($monthKey, $arriendoNetoByMonth)
                ? round((float) ($arriendoNetoByMonth[$monthKey] ?? 0), 2)
                : null;
            if ($arriendoNetoMes === null) {
                $clpFallbackMes = is_array($clpFijoFallbackByTiendaMonth[$idTienda][$monthKey] ?? null)
                    ? $clpFijoFallbackByTiendaMonth[$idTienda][$monthKey]
                    : [];
                if ($clpFallbackMes !== []) {
                    $arriendoNetoMes = round(array_sum(array_map(
                        static fn ($monto): float => round((float) $monto, 2),
                        $clpFallbackMes
                    )), 2);
                    if (!isset($arriendoNetoByTiendaMonth[$idTienda])) {
                        $arriendoNetoByTiendaMonth[$idTienda] = [];
                    }
                    $arriendoNetoByTiendaMonth[$idTienda][$monthKey] = $arriendoNetoMes;
                }
            }
            if ($arriendoNetoMes !== null) {
                $ufBaseByMonth[$monthKey] = $monthUf > 0 ? round($arriendoNetoMes / $monthUf, 6) : 0.0;
                continue;
            }

            $monthStart = (string) ($monthPeriodMeta[$monthKey]['start'] ?? ($monthKey . '-01'));
            $monthEnd = (string) ($monthPeriodMeta[$monthKey]['end'] ?? ($monthKey . '-31'));
            $legacyUfMes = 0.0;
            foreach ($legacyUfEntries as $legacyUfEntry) {
                $legacyUfValor = round((float) ($legacyUfEntry['valor_uf'] ?? 0), 6);
                $legacyUfInicio = trim((string) ($legacyUfEntry['fecha_inicio'] ?? ''));
                $legacyUfTermino = trim((string) ($legacyUfEntry['fecha_termino'] ?? ''));
                if ($legacyUfValor <= 0) {
                    continue;
                }
                if (!msp2ControlDiarioMonthOverlapsRange($monthStart, $monthEnd, $legacyUfInicio, $legacyUfTermino)) {
                    continue;
                }
                $legacyUfMes = round($legacyUfMes + $legacyUfValor, 6);
            }

            $ufFallbackMes = is_array($ufFallbackByTiendaMonth[$idTienda][$monthKey] ?? null)
                ? $ufFallbackByTiendaMonth[$idTienda][$monthKey]
                : [];
            if ($ufFallbackMes !== []) {
                $legacyUfMes = max(
                    $legacyUfMes,
                    round(array_sum(array_map(
                        static fn ($valor): float => round((float) $valor, 6),
                        $ufFallbackMes
                    )), 6)
                );
            }

            // Fallback operativo: si aun no existe snapshot/documento para el mes,
            // mostrar el arriendo base contractual mientras el contrato/local siga vigente.
            $ufBaseByMonth[$monthKey] = $legacyUfMes;
        }

        $tiendaDataRef['uf_base_by_month'] = $ufBaseByMonth;
        $firstMonthKey = array_key_first($ufBaseByMonth);
        if ($firstMonthKey !== null && isset($ufBaseByMonth[$firstMonthKey])) {
            $tiendaDataRef['uf_base'] = round((float) $ufBaseByMonth[$firstMonthKey], 6);
        }
    }
    unset($tiendaDataRef);
    $perfMark('armado_uf_base_por_mes');

    foreach ($tiendaRows as $tiendaData) {
        $serviciosByMonth = [];
        $reservaByMonth = [];
        $reservaBreakdownByMonth = [];
        $garantiaByMonth = [];
        $estadoDocByMonth = [];
        $totalDocByMonth = [];
        $idTienda = (int) ($tiendaData['id_tienda'] ?? 0);
        $localIds = is_array($tiendaData['local_ids'] ?? null) ? $tiendaData['local_ids'] : [];
        foreach ($localIds as $localId) {
            $serviciosLocal = is_array($serviceTotalsByLocalMonth[$localId] ?? null) ? $serviceTotalsByLocalMonth[$localId] : [];
            foreach ($serviciosLocal as $periodo => $servData) {
                if (!isset($months[$periodo])) {
                    continue;
                }
                if (!isset($serviciosByMonth[$periodo])) {
                    $serviciosByMonth[$periodo] = ['electricidad' => 0.0, 'gas' => 0.0, 'agua' => 0.0];
                }
                $serviciosByMonth[$periodo]['electricidad'] = round(
                    (float) $serviciosByMonth[$periodo]['electricidad'] + (float) ($servData['electricidad'] ?? 0),
                    2
                );
                $serviciosByMonth[$periodo]['gas'] = round(
                    (float) $serviciosByMonth[$periodo]['gas'] + (float) ($servData['gas'] ?? 0),
                    2
                );
                $serviciosByMonth[$periodo]['agua'] = round(
                    (float) $serviciosByMonth[$periodo]['agua'] + (float) ($servData['agua'] ?? 0),
                    2
                );
            }
        }
        $serviciosDocumentoTienda = is_array($serviceTotalsByTiendaMonth[$idTienda] ?? null)
            ? $serviceTotalsByTiendaMonth[$idTienda]
            : [];
        foreach ($serviciosDocumentoTienda as $periodoServicioDoc => $serviciosDocData) {
            if (!isset($months[$periodoServicioDoc]) || !is_array($serviciosDocData)) {
                continue;
            }
            $serviciosByMonth[$periodoServicioDoc] = [
                'electricidad' => round((float) ($serviciosDocData['electricidad'] ?? 0), 2),
                'gas' => round((float) ($serviciosDocData['gas'] ?? 0), 2),
                'agua' => round((float) ($serviciosDocData['agua'] ?? 0), 2),
            ];
        }

        $localCodes = is_array($tiendaData['local_codes'] ?? null) ? $tiendaData['local_codes'] : [];
        $localCodes = array_values(array_unique(array_filter($localCodes, static fn ($v): bool => trim((string) $v) !== '')));
        usort($localCodes, static fn (string $a, string $b): int => msp2ControlDiarioCompareLocalCode($a, $b));
        $localesLabel = $localCodes !== [] ? implode(' / ', $localCodes) : '-';
        $calcModeRow = 'UF';
        $netoFijoRow = 0.0;
        $ufBaseByMonthRow = is_array($tiendaData['uf_base_by_month'] ?? null) ? $tiendaData['uf_base_by_month'] : [];
        $arriendoNetoByMonthRow = is_array($arriendoNetoByTiendaMonth[$idTienda] ?? null)
            ? $arriendoNetoByTiendaMonth[$idTienda]
            : [];
        if ($ufBaseByMonthRow === []) {
            foreach ($months as $monthKey => $monthData) {
                $ufBaseByMonthRow[$monthKey] = round((float) ($tiendaData['uf_base'] ?? 0), 6);
            }
        }
        $arrendatarioByMonthRow = is_array($tiendaData['arrendatario_by_month'] ?? null) ? $tiendaData['arrendatario_by_month'] : [];
        $arrendatarioIdByMonthRow = is_array($tiendaData['arrendatario_id_by_month'] ?? null) ? $tiendaData['arrendatario_id_by_month'] : [];
        $rutDisplayByMonthRow = is_array($tiendaData['rut_display_by_month'] ?? null) ? $tiendaData['rut_display_by_month'] : [];
        $terminoByMonthRow = is_array($tiendaData['termino_by_month'] ?? null) ? $tiendaData['termino_by_month'] : [];
        $postTerminoByMonthRow = is_array($tiendaData['post_termino_by_month'] ?? null) ? $tiendaData['post_termino_by_month'] : [];
        if ($arrendatarioByMonthRow === []) {
            foreach ($months as $monthKey => $monthData) {
                $arrendatarioByMonthRow[$monthKey] = trim((string) ($tiendaData['arrendatario'] ?? ''));
            }
        }
        if ($arrendatarioIdByMonthRow === []) {
            foreach ($months as $monthKey => $monthData) {
                $arrendatarioIdByMonthRow[$monthKey] = 0;
            }
        }
        if ($rutDisplayByMonthRow === []) {
            foreach ($months as $monthKey => $monthData) {
                $rutDisplayByMonthRow[$monthKey] = trim((string) ($tiendaData['rut_display'] ?? '-'));
            }
        }
        if ($terminoByMonthRow === []) {
            foreach ($months as $monthKey => $monthData) {
                $terminoByMonthRow[$monthKey] = false;
            }
        }
        if ($postTerminoByMonthRow === []) {
            foreach ($months as $monthKey => $monthData) {
                $postTerminoByMonthRow[$monthKey] = false;
            }
        }
        $arrFirstMonthKey = array_key_first($arrendatarioByMonthRow);
        $arrDisplayRow = trim((string) ($arrFirstMonthKey !== null ? ($arrendatarioByMonthRow[$arrFirstMonthKey] ?? '') : ''));
        $rutDisplayRow = trim((string) ($arrFirstMonthKey !== null ? ($rutDisplayByMonthRow[$arrFirstMonthKey] ?? '-') : '-'));
        $ufBaseFirstMonthKey = array_key_first($ufBaseByMonthRow);
        $ufBaseRow = round((float) ($ufBaseFirstMonthKey !== null ? ($ufBaseByMonthRow[$ufBaseFirstMonthKey] ?? 0) : 0), 6);
        if (isset($clpFijoContratoByTienda[$idTienda]) && $clpFijoContratoByTienda[$idTienda] === true) {
            // Contratos CLP fijo: se calcula por neto mensual, sin exponer UF base.
            $calcModeRow = 'NETO_FIJO';
        }
        $localesLabelRow = $localesLabel;
        $reservaDataTienda = is_array($reservaByTiendaMonth[$idTienda] ?? null) ? $reservaByTiendaMonth[$idTienda] : [];
        foreach ($reservaDataTienda as $resPeriodo => $resMonto) {
            if (!isset($months[$resPeriodo])) {
                continue;
            }
            $reservaByMonth[$resPeriodo] = round((float) $resMonto, 2);
        }
        $reservaBreakdownDataTienda = is_array($reservaBreakdownByTiendaMonth[$idTienda] ?? null)
            ? $reservaBreakdownByTiendaMonth[$idTienda]
            : [];
        foreach ($reservaBreakdownDataTienda as $resPeriodo => $resBreakdown) {
            if (!isset($months[$resPeriodo])) {
                continue;
            }
            $reservaBreakdownByMonth[$resPeriodo] = [
                'danos_multas' => round((float) ($resBreakdown['danos_multas'] ?? 0), 2),
                'otros_cargos' => round((float) ($resBreakdown['otros_cargos'] ?? 0), 2),
                'saldo_favor_aplicado' => round((float) ($resBreakdown['saldo_favor_aplicado'] ?? 0), 2),
            ];
        }
        $garantiaAplicadaDataTienda = is_array($garantiaAplicadaByTiendaMonth[$idTienda] ?? null)
            ? $garantiaAplicadaByTiendaMonth[$idTienda]
            : [];
        foreach ($months as $monthKey => $monthData) {
            $garantiaByMonth[$monthKey] = round((float) ($garantiaAplicadaDataTienda[$monthKey] ?? 0), 2);
            if ((bool) ($postTerminoByMonthRow[$monthKey] ?? false)) {
                $ufBaseByMonthRow[$monthKey] = 0.0;
                unset($arriendoNetoByMonthRow[$monthKey]);
            }
        }
        $estadoDocDataTienda = is_array($docStatusByTiendaMonth[$idTienda] ?? null) ? $docStatusByTiendaMonth[$idTienda] : [];
        $docIdDataTienda = is_array($docIdByTiendaMonth[$idTienda] ?? null) ? $docIdByTiendaMonth[$idTienda] : [];
        $docNumberDataTienda = is_array($docNumberByTiendaMonth[$idTienda] ?? null) ? $docNumberByTiendaMonth[$idTienda] : [];
        foreach ($estadoDocDataTienda as $estadoPeriodo => $estadoValue) {
            if (!isset($months[$estadoPeriodo])) {
                continue;
            }
            $estadoDocByMonth[$estadoPeriodo] = (string) $estadoValue;
        }
        $totalDocDataTienda = is_array($docTotalByTiendaMonth[$idTienda] ?? null) ? $docTotalByTiendaMonth[$idTienda] : [];
        foreach ($totalDocDataTienda as $totalPeriodo => $totalValue) {
            if (!isset($months[$totalPeriodo])) {
                continue;
            }
            $totalDocByMonth[$totalPeriodo] = round((float) $totalValue, 2);
        }

        $rows[] = [
            'id_local' => $idTienda,
            'local_code' => $localesLabelRow,
            'local_desc' => '',
            'arrendatario' => $arrDisplayRow,
            'rut_raw' => trim((string) ($tiendaData['rut_raw'] ?? '')),
            'rut_display' => $rutDisplayRow !== '' ? $rutDisplayRow : '-',
            'arrendatario_by_month' => $arrendatarioByMonthRow,
            'arrendatario_id_by_month' => $arrendatarioIdByMonthRow,
            'rut_display_by_month' => $rutDisplayByMonthRow,
            'termino_by_month' => $terminoByMonthRow,
            'post_termino_by_month' => $postTerminoByMonthRow,
            'uf_base' => $ufBaseRow,
            'uf_base_by_month' => $ufBaseByMonthRow,
            'arriendo_neto_by_month' => $arriendoNetoByMonthRow,
            'calc_mode' => $calcModeRow,
            'neto_fijo' => $netoFijoRow,
            'servicios' => $serviciosByMonth,
            'garantia' => $garantiaByMonth,
            'reserva' => $reservaByMonth,
            'reserva_breakdown' => $reservaBreakdownByMonth,
            'estado_doc' => $estadoDocByMonth,
            'doc_id_by_month' => $docIdDataTienda,
            'doc_number_by_month' => $docNumberDataTienda,
            'total_doc' => $totalDocByMonth,
            'local_codes' => $localCodes,
        ];
    }
    $perfMark('transformacion_tiendas_a_rows');

    if ($rows !== []) {
        $rowByLocalSignature = [];
        foreach ($rows as $rowItem) {
            $rowCodes = array_values(array_unique(array_filter(
                (array) ($rowItem['local_codes'] ?? []),
                static fn ($v): bool => trim((string) $v) !== ''
            )));
            usort($rowCodes, static fn (string $a, string $b): int => msp2ControlDiarioCompareLocalCode($a, $b));
            $signature = implode('|', array_map(static fn (string $code): string => strtoupper(trim($code)), $rowCodes));
            if ($signature === '') {
                $signature = 'ID:' . (int) ($rowItem['id_local'] ?? 0);
            }

            if (!isset($rowByLocalSignature[$signature])) {
                $rowByLocalSignature[$signature] = $rowItem;
                continue;
            }

            $baseRow = $rowByLocalSignature[$signature];
            $baseUfByMonth = is_array($baseRow['uf_base_by_month'] ?? null) ? $baseRow['uf_base_by_month'] : [];
            $baseArrByMonth = is_array($baseRow['arrendatario_by_month'] ?? null) ? $baseRow['arrendatario_by_month'] : [];
            $baseRutByMonth = is_array($baseRow['rut_display_by_month'] ?? null) ? $baseRow['rut_display_by_month'] : [];
            $baseTerminoByMonth = is_array($baseRow['termino_by_month'] ?? null) ? $baseRow['termino_by_month'] : [];
            $basePostTerminoByMonth = is_array($baseRow['post_termino_by_month'] ?? null) ? $baseRow['post_termino_by_month'] : [];
            $baseArriendoNetoByMonth = is_array($baseRow['arriendo_neto_by_month'] ?? null) ? $baseRow['arriendo_neto_by_month'] : [];
            $baseServicios = is_array($baseRow['servicios'] ?? null) ? $baseRow['servicios'] : [];
            $baseGarantia = is_array($baseRow['garantia'] ?? null) ? $baseRow['garantia'] : [];
            $baseReserva = is_array($baseRow['reserva'] ?? null) ? $baseRow['reserva'] : [];
            $baseReservaBreakdown = is_array($baseRow['reserva_breakdown'] ?? null) ? $baseRow['reserva_breakdown'] : [];
            $baseEstadoDoc = is_array($baseRow['estado_doc'] ?? null) ? $baseRow['estado_doc'] : [];
            $baseTotalDoc = is_array($baseRow['total_doc'] ?? null) ? $baseRow['total_doc'] : [];

            $newUfByMonth = is_array($rowItem['uf_base_by_month'] ?? null) ? $rowItem['uf_base_by_month'] : [];
            $newArrByMonth = is_array($rowItem['arrendatario_by_month'] ?? null) ? $rowItem['arrendatario_by_month'] : [];
            $newRutByMonth = is_array($rowItem['rut_display_by_month'] ?? null) ? $rowItem['rut_display_by_month'] : [];
            $newTerminoByMonth = is_array($rowItem['termino_by_month'] ?? null) ? $rowItem['termino_by_month'] : [];
            $newPostTerminoByMonth = is_array($rowItem['post_termino_by_month'] ?? null) ? $rowItem['post_termino_by_month'] : [];
            $newArriendoNetoByMonth = is_array($rowItem['arriendo_neto_by_month'] ?? null) ? $rowItem['arriendo_neto_by_month'] : [];
            $newServicios = is_array($rowItem['servicios'] ?? null) ? $rowItem['servicios'] : [];
            $newGarantia = is_array($rowItem['garantia'] ?? null) ? $rowItem['garantia'] : [];
            $newReserva = is_array($rowItem['reserva'] ?? null) ? $rowItem['reserva'] : [];
            $newReservaBreakdown = is_array($rowItem['reserva_breakdown'] ?? null) ? $rowItem['reserva_breakdown'] : [];
            $newEstadoDoc = is_array($rowItem['estado_doc'] ?? null) ? $rowItem['estado_doc'] : [];
            $newTotalDoc = is_array($rowItem['total_doc'] ?? null) ? $rowItem['total_doc'] : [];

            foreach ($months as $monthKey => $monthData) {
                $baseArr = trim((string) ($baseArrByMonth[$monthKey] ?? ''));
                $newArr = trim((string) ($newArrByMonth[$monthKey] ?? ''));
                $baseUf = (float) ($baseUfByMonth[$monthKey] ?? 0);
                $newUf = (float) ($newUfByMonth[$monthKey] ?? 0);

                $useNew = false;
                if ($baseArr === '' && $newArr !== '') {
                    $useNew = true;
                } elseif ($baseArr === '' && $newArr === '' && $newUf > $baseUf) {
                    $useNew = true;
                }

                $baseTerminoMes = (bool) ($baseTerminoByMonth[$monthKey] ?? false);
                $newTerminoMes = (bool) ($newTerminoByMonth[$monthKey] ?? false);
                $baseTerminoByMonth[$monthKey] = $baseTerminoMes || $newTerminoMes;
                $basePostTerminoMes = (bool) ($basePostTerminoByMonth[$monthKey] ?? false);
                $newPostTerminoMes = (bool) ($newPostTerminoByMonth[$monthKey] ?? false);
                $basePostTerminoByMonth[$monthKey] = $basePostTerminoMes || $newPostTerminoMes;

                if ($useNew) {
                    $baseUfByMonth[$monthKey] = round($newUf, 6);
                    $baseArrByMonth[$monthKey] = $newArr;
                    $baseRutByMonth[$monthKey] = trim((string) ($newRutByMonth[$monthKey] ?? '-'));
                    if (array_key_exists($monthKey, $newArriendoNetoByMonth)) {
                        $baseArriendoNetoByMonth[$monthKey] = round((float) ($newArriendoNetoByMonth[$monthKey] ?? 0), 2);
                    }

                    if (array_key_exists($monthKey, $newServicios)) {
                        $baseServicios[$monthKey] = $newServicios[$monthKey];
                    }
                    if (array_key_exists($monthKey, $newGarantia)) {
                        $baseGarantia[$monthKey] = $newGarantia[$monthKey];
                    }
                    if (array_key_exists($monthKey, $newReserva)) {
                        $baseReserva[$monthKey] = $newReserva[$monthKey];
                    }
                    if (array_key_exists($monthKey, $newReservaBreakdown)) {
                        $baseReservaBreakdown[$monthKey] = $newReservaBreakdown[$monthKey];
                    }
                    if (array_key_exists($monthKey, $newEstadoDoc)) {
                        $baseEstadoDoc[$monthKey] = $newEstadoDoc[$monthKey];
                    }
                    if (array_key_exists($monthKey, $newTotalDoc)) {
                        $baseTotalDoc[$monthKey] = $newTotalDoc[$monthKey];
                    }
                }
            }

            $baseRow['local_ids'] = array_values(array_unique(array_map(
                'intval',
                array_merge((array) ($baseRow['local_ids'] ?? []), (array) ($rowItem['local_ids'] ?? []))
            )));
            $baseRow['local_codes'] = $rowCodes;
            $baseRow['local_code'] = $rowCodes !== [] ? implode(' / ', $rowCodes) : (string) ($baseRow['local_code'] ?? '-');
            $baseRow['uf_base_by_month'] = $baseUfByMonth;
            $baseRow['arrendatario_by_month'] = $baseArrByMonth;
            $baseRow['rut_display_by_month'] = $baseRutByMonth;
            $baseRow['termino_by_month'] = $baseTerminoByMonth;
            $baseRow['post_termino_by_month'] = $basePostTerminoByMonth;
            $baseRow['arriendo_neto_by_month'] = $baseArriendoNetoByMonth;
            $baseRow['servicios'] = $baseServicios;
            $baseRow['garantia'] = $baseGarantia;
            $baseRow['reserva'] = $baseReserva;
            $baseRow['reserva_breakdown'] = $baseReservaBreakdown;
            $baseRow['estado_doc'] = $baseEstadoDoc;
            $baseRow['total_doc'] = $baseTotalDoc;

            $firstMonthKey = array_key_first($months);
            if ($firstMonthKey !== null) {
                $baseRow['uf_base'] = round((float) ($baseUfByMonth[$firstMonthKey] ?? 0), 6);
                $baseRow['arrendatario'] = trim((string) ($baseArrByMonth[$firstMonthKey] ?? ''));
                $rutRow = trim((string) ($baseRutByMonth[$firstMonthKey] ?? '-'));
                $baseRow['rut_display'] = $rutRow !== '' ? $rutRow : '-';
            }

            $rowByLocalSignature[$signature] = $baseRow;
        }

        $rows = array_values($rowByLocalSignature);
    }

    usort($rows, static function (array $left, array $right): int {
        $leftCodes = array_values(array_filter((array) ($left['local_codes'] ?? []), static fn ($v): bool => trim((string) $v) !== ''));
        $rightCodes = array_values(array_filter((array) ($right['local_codes'] ?? []), static fn ($v): bool => trim((string) $v) !== ''));
        $leftFirst = $leftCodes[0] ?? '';
        $rightFirst = $rightCodes[0] ?? '';
        $compareFirst = msp2ControlDiarioCompareLocalCode($leftFirst, $rightFirst);
        if ($compareFirst !== 0) {
            return $compareFirst;
        }
        $leftFull = implode('|', $leftCodes);
        $rightFull = implode('|', $rightCodes);
        return strcmp($leftFull, $rightFull);
    });
    $perfMark('armado_filas_control_diario');

    // Se elimina consolidación legada OBRA/MODULAR: cada local mantiene su fila y métricas propias.
} catch (Throwable $exception) {
    $loadError = 'No fue posible cargar la vista de Control diario.';
}
$perfSummary = null;
if ($perfEnabled) {
    $perfSummary = [
        'access_ms' => $accessCheckMs,
        'total_ms' => round((microtime(true) - $perfStart) * 1000, 2),
        'rows' => count($rows),
        'months' => count($months),
        'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        'marks' => $perfMarks,
    ];
}
$compactDomMode = (string) ($_GET['full'] ?? '') !== '1';
$renderMonths = $months;
$viewMonthKey = '';
if ($compactDomMode && $months !== []) {
    $monthKeys = array_keys($months);
    $availableMonthKeys = array_values(array_filter(
        $monthKeys,
        static fn (string $k): bool => (bool) (($months[$k]['is_available'] ?? false) === true)
    ));
    $selectedMonthRaw = trim((string) ($_GET['mes'] ?? ''));
    $focusMonthKey = '';
    if ($selectedMonthRaw !== '' && isset($months[$selectedMonthRaw])) {
        $focusMonthKey = $selectedMonthRaw;
    } else {
        $currentYear = (int) date('Y');
        $currentMonthKey = sprintf('%04d-%02d', $selectedYear, (int) date('n'));
        if ($selectedYear === $currentYear && in_array($currentMonthKey, $monthKeys, true)) {
            $focusMonthKey = $currentMonthKey;
        } elseif ($availableMonthKeys !== []) {
            $focusMonthKey = (string) end($availableMonthKeys);
        } else {
            $focusMonthKey = (string) end($monthKeys);
        }
    }
    if ($focusMonthKey !== '' && isset($months[$focusMonthKey])) {
        $renderMonths = [$focusMonthKey => $months[$focusMonthKey]];
    }
}
$viewMonthKey = (string) (array_key_first($renderMonths) ?? '');
$allMonthKeys = array_keys($months);
$sliderInitialIndex = 1;
if ($viewMonthKey !== '' && $allMonthKeys !== []) {
    $monthIndex = array_search($viewMonthKey, $allMonthKeys, true);
    if ($monthIndex !== false) {
        $sliderInitialIndex = ((int) $monthIndex) + 1;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Control diario</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .cd-body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(11, 58, 110, 0.08), transparent 30%),
                linear-gradient(180deg, #f5f8fc 0%, #e9eef5 100%);
        }

        .cd-main {
            padding: 0.85rem;
        }

        .cd-shell {
            display: flex;
            flex-direction: column;
            gap: 0.65rem;
            width: 100%;
        }

        .cd-focusbar {
            position: sticky;
            top: 0;
            z-index: 70;
            border: 1px solid #d4deeb;
            border-radius: 14px;
            background: rgba(248, 251, 255, 0.96);
            box-shadow: 0 14px 28px rgba(15, 42, 76, 0.08);
            backdrop-filter: blur(10px);
            overflow: hidden;
        }

        .cd-focusbar-main {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 0.7rem 0.85rem;
        }

        .cd-focusbar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 0;
        }

        .cd-focusbar-title {
            margin: 0;
            font-size: 1.05rem;
            line-height: 1.1;
            color: #0e2f52;
        }

        .cd-focusbar-subtitle {
            margin: 2px 0 0;
            font-size: 12px;
            color: #5f7389;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .cd-focusbar-actions {
            display: flex;
            align-items: end;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .cd-year-form {
            display: flex;
            align-items: end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .cd-year-field {
            min-width: 92px;
        }

        .cd-focusbar-panel {
            border-top: 1px solid #e0e8f1;
            background: rgba(255, 255, 255, 0.92);
        }

        .cd-focusbar-panel-inner {
            display: flex;
            flex-direction: column;
        }

        .cd-focusbar.is-collapsed .cd-focusbar-panel {
            display: none;
        }

        .cd-focusbar.is-collapsed .cd-focusbar-subtitle {
            display: none;
        }

        .control-grid-card {
            border: 1px solid #d5dfec;
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            background: #fff;
            position: relative;
        }

        .control-grid-card.is-preparing .control-grid-content,
        .control-grid-card.is-loading .control-grid-content {
            filter: blur(1px);
            opacity: 0.42;
            pointer-events: none;
            user-select: none;
        }

        .control-loading-layer {
            position: absolute;
            inset: 0;
            z-index: 80;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(248, 251, 255, 0.88);
            backdrop-filter: blur(2px);
        }

        .control-grid-card.is-preparing .control-loading-layer,
        .control-grid-card.is-loading .control-loading-layer {
            display: flex;
        }

        .control-loading-panel {
            width: min(380px, 100%);
            border: 1px solid #d5dfec;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.96);
            box-shadow: var(--shadow-md);
            padding: 1.25rem;
            text-align: center;
        }

        .control-loading-icon {
            width: 44px;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #edf6ff;
            color: #123e6d;
            margin-bottom: 0.75rem;
        }

        .control-loading-title {
            color: #123e6d;
            font-size: 0.95rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .control-loading-text {
            color: #5c6f86;
            font-size: 0.82rem;
            margin-bottom: 1rem;
        }

        .control-loading-track {
            height: 4px;
            border-radius: 999px;
            background: #e3edf8;
            overflow: hidden;
        }

        .control-loading-bar {
            width: 38%;
            height: 100%;
            border-radius: inherit;
            background: #0b3a6e;
            animation: control-loading-slide 1.05s ease-in-out infinite;
        }

        @keyframes control-loading-slide {
            0% {
                transform: translateX(-120%);
            }

            100% {
                transform: translateX(280%);
            }
        }

        .control-grid-wrap {
            width: 100%;
            overflow-x: auto;
            overflow-y: auto;
            max-height: calc(100vh - 214px);
            background: #fff;
        }

        .control-grid {
            --control-head-row-1-height: 48px;
            --sticky-local-width: 160px;
            --sticky-arr-width: 228px;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 4200px;
            width: max-content;
            margin: 0;
            font-size: 12px;
        }

        .control-grid.month-single-mode {
            --sticky-local-width: 88px;
            --sticky-arr-width: 164px;
            min-width: 0;
            width: 100%;
        }

        .control-grid.month-single-mode tbody td {
            padding: 4px 5px;
            font-size: 11px;
        }

        .control-grid.month-single-mode .sticky-col {
            vertical-align: top;
        }

        .control-grid.month-single-mode .js-month-col {
            min-width: 70px;
        }

        .control-grid.month-single-mode .js-uf-base-head,
        .control-grid.month-single-mode .js-uf-base-cell {
            min-width: 54px;
            max-width: 54px;
            padding-left: 4px;
            padding-right: 4px;
        }

        .control-grid.month-single-mode .garantia-col {
            min-width: 56px;
        }

        .control-grid.month-single-mode .status-col {
            min-width: 64px;
        }

        .control-grid.month-single-mode .month-static {
            width: 82px;
            min-height: 24px;
            font-size: 10px;
            padding: 2px 5px;
        }

        .control-grid.month-single-mode .local-label {
            font-size: 10px;
            min-height: 2.35em;
        }

        .control-grid.month-single-mode .arr-label {
            max-width: 152px;
            min-height: 2.45em;
        }

        .control-grid.month-single-mode .arr-rut {
            font-size: 10px;
        }

        .control-grid-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding: 0.55rem 0.7rem;
            border-bottom: 1px solid #e6edf6;
            background: #fff;
            flex-wrap: nowrap;
        }

        .month-tracker-label {
            font-size: 12px;
            color: #1f3e62;
            font-weight: 600;
        }

        .month-switcher {
            display: grid;
            grid-template-columns: minmax(82px, 1fr) minmax(180px, 4fr) minmax(82px, 1fr);
            align-items: center;
            gap: 6px;
            width: 100%;
            min-width: 0;
        }

        .month-slider-stack {
            display: grid;
            grid-template-rows: auto auto;
            gap: 3px;
        }

        .month-switcher input[type="range"] {
            width: 100%;
            margin: 0;
        }

        .control-filters {
            display: flex;
            align-items: end;
            gap: 6px;
            flex-wrap: wrap;
            padding: 0.55rem 0.7rem 0.7rem;
            background: #fff;
        }

        .control-filter-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 152px;
        }

        .control-filter-item.search {
            flex: 1 1 260px;
        }

        .control-filter-item label {
            font-size: 11px;
            color: #4a5f78;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .control-filter-input {
            font-size: 0.875rem;
        }

        .control-filter-input:focus {
            box-shadow: none;
        }

        .month-scale {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10px;
            font-weight: 700;
            color: #b8c6d8;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            user-select: none;
        }

        .month-scale-item {
            text-align: center;
            padding-top: 1px;
            transition: color 0.16s ease;
            line-height: 1;
        }

        .month-scale-item.is-disabled {
            color: #d2dbe8;
        }

        .month-scale-item.is-active {
            color: #0f4f91;
        }

        .btn-month-nav {
            min-width: 76px;
            width: 100%;
        }

        .is-month-hidden {
            display: none !important;
        }

        .control-grid thead th {
            position: sticky;
            z-index: 30;
            background: #123e6d;
            color: #fff;
            border-right: 1px solid rgba(255, 255, 255, 0.15);
            border-bottom: 1px solid rgba(255, 255, 255, 0.22);
            text-align: center;
            padding: 6px 7px;
            white-space: nowrap;
        }

        .control-grid thead tr:first-child th {
            top: 0;
            z-index: 36;
        }

        .control-grid thead tr:nth-child(2) th {
            top: var(--control-head-row-1-height);
            z-index: 35;
        }

        .control-grid thead tr:first-child th[rowspan] {
            top: 0;
            z-index: 38;
        }

        .control-grid thead .month-group {
            background: #0b3a6e;
            font-size: 11px;
            letter-spacing: 0.02em;
            vertical-align: top;
        }

        .control-grid thead .month-head-inner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .control-grid .month-static {
            width: 96px;
            min-height: 26px;
            border-radius: 5px;
            border: 1px solid #cee0f5;
            padding: 2px 6px;
            text-align: right;
            font-size: 11px;
            background: #f5f9ff;
            color: #123a63;
            font-weight: 700;
        }

        .control-grid tbody td {
            background: #fff;
            border-right: 1px solid #dce3ee;
            border-bottom: 1px solid #dce3ee;
            padding: 5px 6px;
            white-space: nowrap;
            vertical-align: middle;
        }

        .control-grid tfoot td {
            background: #edf3fb;
            border-right: 1px solid #c5d3e6;
            border-bottom: 1px solid #c5d3e6;
            border-top: 2px solid #8fa8c5;
            padding: 6px;
            white-space: nowrap;
            vertical-align: middle;
            font-weight: 700;
        }

        .control-grid .js-month-col {
            min-width: 82px;
        }

        .control-grid .js-uf-base-head,
        .control-grid .js-uf-base-cell {
            min-width: 64px;
        }

        .control-grid .status-col {
            min-width: 82px;
        }

        .control-grid .garantia-col {
            min-width: 64px;
        }

        .control-grid tbody tr:nth-child(even) td {
            background: #f9fbfe;
        }

        .control-grid tbody tr.is-row-link {
            cursor: pointer;
        }

        .control-grid tbody tr.is-row-link:hover td {
            background: #eef5ff;
        }

        .control-grid tbody tr.is-row-link:focus-visible {
            outline: 2px solid #0b5ed7;
            outline-offset: -2px;
        }

        .control-grid .sticky-col {
            position: sticky;
            z-index: 25;
            background: #f4f8ff;
            box-shadow: inset -1px 0 0 #dce3ee;
        }

        .control-grid thead .sticky-col {
            z-index: 42;
            background: #143e6d;
        }

        .control-grid .sticky-col-local {
            left: 0;
            min-width: var(--sticky-local-width);
            max-width: var(--sticky-local-width);
        }

        .control-grid .sticky-col-arr {
            left: var(--sticky-local-width);
            min-width: var(--sticky-arr-width);
            max-width: var(--sticky-arr-width);
        }

        .cell-num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .cell-total {
            background: #edf6ff !important;
            font-weight: 700;
        }

        .has-tooltip {
            cursor: help;
            text-decoration: underline dotted #9fb6d6;
            text-underline-offset: 2px;
        }

        .reserva-cell-content {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            width: 100%;
        }

        .reserva-cell-info {
            color: #7395bf;
            font-size: 12px;
            line-height: 1;
            flex: 0 0 auto;
        }

        .cell-subtotal {
            font-weight: 600;
            background: #f5f8ff !important;
        }

        .month-input {
            width: 120px;
            text-align: right;
            font-variant-numeric: tabular-nums;
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid #cfd9e7;
            min-height: 31px;
            background: #fff;
        }

        .status-chip {
            border: 0;
            border-radius: 7px;
            min-width: 72px;
            padding: 3px 6px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #fff;
            cursor: pointer;
            box-shadow: none;
            transition: filter 0.16s ease;
        }

        .status-chip:hover {
            filter: brightness(1.05);
        }

        .status-chip.is-paid {
            background: #1f8a4d;
        }

        .status-chip.is-pending {
            background: #b68500;
        }

        .status-chip.is-late {
            background: #c3312f;
        }

        .status-chip.is-no-close {
            background: #6b7280;
        }

        .status-chip.is-terminated {
            background: #7c2d12;
        }

        .cell-readonly {
            background: #f8fbff !important;
            color: #173f6d;
            font-weight: 600;
        }

        .local-label {
            font-weight: 700;
            color: #12375f;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow: hidden;
            white-space: normal;
            word-break: break-word;
            line-height: 1.18;
            font-size: 11px;
        }

        .arr-label {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow: hidden;
            white-space: normal;
            word-break: break-word;
            line-height: 1.2;
            max-width: 180px;
        }

        .arr-cell-stack {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
        }

        .arr-rut {
            font-size: 11px;
            line-height: 1.15;
            color: #61748b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .control-footnote {
            color: var(--color-text-muted);
            font-size: 12px;
            margin-top: 10px;
        }

        .control-nav-summary {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px 14px;
        }

        .control-nav-item-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #5e7288;
            margin-bottom: 2px;
        }

        .control-nav-item-value {
            color: #16395f;
            font-size: 0.95rem;
            line-height: 1.25;
            word-break: break-word;
        }

        .control-nav-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #4a6077;
        }

        .control-nav-countdown {
            font-size: 12px;
            color: #5b6f85;
        }

        @media (max-width: 992px) {
            .control-grid-wrap {
                max-height: calc(100vh - 250px);
            }

            .control-grid {
                --sticky-local-width: 120px;
                --sticky-arr-width: 190px;
            }

            .month-switcher {
                grid-template-columns: 1fr;
                width: 100%;
            }

            .control-grid-meta {
                flex-wrap: wrap;
            }

            .cd-focusbar-main {
                flex-wrap: wrap;
                align-items: stretch;
            }

            .cd-focusbar-brand,
            .cd-focusbar-actions,
            .cd-year-form {
                width: 100%;
            }

            .cd-year-field {
                flex: 1 1 140px;
            }

            .control-filters {
                padding: 8px;
            }

            .control-filter-item,
            .control-filter-item.search {
                min-width: 100%;
                flex: 1 1 100%;
            }

            .cd-shell {
                gap: 0.5rem;
            }

            .control-loading-panel {
                padding: 0.85rem;
            }

            .control-nav-summary {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 993px) {
            .cd-body.cd-focusbar-collapsed .control-grid-wrap {
                max-height: calc(100vh - 146px);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .control-loading-bar {
                animation: none;
                width: 100%;
            }
        }
    </style>
</head>
<body class="gp-layout cd-body">
<main class="gp-main cd-main">
        <div class="cd-shell">
            <section class="cd-focusbar js-control-focusbar" aria-label="Controles de Control diario">
                <div class="cd-focusbar-main">
                    <div class="cd-focusbar-brand">
                        <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver
                        </a>
                        <div class="min-w-0">
                            <h1 class="cd-focusbar-title">Control diario</h1>
                            <p class="cd-focusbar-subtitle">Vista operativa mensual por local con cálculo de arriendo, extras y estado.</p>
                        </div>
                    </div>
                    <div class="cd-focusbar-actions">
                        <form method="get" class="cd-year-form js-control-year-form">
                            <div class="cd-year-field">
                                <label for="anio" class="form-label mb-1 small text-uppercase fw-semibold text-muted">Año</label>
                                <?php if ($availableYears !== []): ?>
                                    <select class="form-select form-select-sm" id="anio" name="anio">
                                        <?php foreach ($availableYears as $availableYear): ?>
                                            <option value="<?php echo (int) $availableYear; ?>" <?php echo $availableYear === $selectedYear ? 'selected' : ''; ?>>
                                                <?php echo (int) $availableYear; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="number" class="form-control form-control-sm" id="anio" name="anio" min="2020" max="2100" value="<?php echo (int) $selectedYear; ?>">
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary">Cargar</button>
                        </form>
                        <button
                            type="button"
                            class="btn btn-outline-primary btn-sm js-control-focusbar-toggle"
                            aria-expanded="true"
                            aria-controls="control-focusbar-panel"
                        >
                            Compactar
                        </button>
                    </div>
                </div>
                <div class="cd-focusbar-panel" id="control-focusbar-panel">
                    <div class="cd-focusbar-panel-inner">
                        <div class="control-grid-meta">
                            <div class="month-switcher">
                                <button type="button" class="btn btn-outline-secondary btn-sm btn-month-nav" id="month-prev-btn">Anterior</button>
                                <div class="month-slider-stack">
                                    <input type="range" id="month-slider" min="1" max="<?php echo count($months); ?>" value="<?php echo (int) $sliderInitialIndex; ?>" step="1" aria-label="Mes visible">
                                    <div class="month-scale" aria-hidden="true">
                                        <?php $monthScaleIndex = 1; ?>
                                        <?php foreach ($months as $month): ?>
                                            <?php $abbr = strtoupper(substr((string) $month['label'], 0, 3)); ?>
                                            <span
                                                class="month-scale-item js-month-scale-item<?php echo !((bool) ($month['is_available'] ?? false)) ? ' is-disabled' : ''; ?>"
                                                data-month-index="<?php echo (int) $monthScaleIndex; ?>"
                                                data-month-key="<?php echo msp2Escape($month['key']); ?>"
                                                data-month-available="<?php echo (bool) ($month['is_available'] ?? false) ? '1' : '0'; ?>"
                                            >
                                                <?php echo msp2Escape($abbr); ?>
                                            </span>
                                            <?php $monthScaleIndex++; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-outline-secondary btn-sm btn-month-nav" id="month-next-btn">Siguiente</button>
                            </div>
                        </div>
                        <div class="control-filters">
                            <div class="control-filter-item">
                                <label for="filter-status">Estado</label>
                                <select id="filter-status" class="form-select form-select-sm control-filter-input">
                                    <option value="">Todos</option>
                                    <option value="OK">OK</option>
                                    <option value="PENDIENTE">Pendiente</option>
                                    <option value="ATRASADO">Atrasado</option>
                                    <option value="TERMINO">Termino</option>
                                    <option value="SIN DOCUMENTO">Sin documento</option>
                                    <option value="SIN CIERRE">Sin cierre</option>
                                </select>
                            </div>
                            <div class="control-filter-item search">
                                <label for="filter-search">Buscar</label>
                                <input
                                    type="text"
                                    id="filter-search"
                                    class="form-control form-control-sm control-filter-input"
                                    placeholder="Locales, arrendatario o RUT"
                                    autocomplete="off"
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <?php msp2RenderFlash($flash); ?>
            <?php if ($perfSummary !== null): ?>
                <div class="alert alert-secondary py-2 mb-2 small">
                    <strong>Perf server:</strong>
                    acceso <?php echo msp2Escape(number_format((float) ($perfSummary['access_ms'] ?? 0), 2, ',', '.')); ?> ms,
                    total <?php echo msp2Escape(number_format((float) ($perfSummary['total_ms'] ?? 0), 2, ',', '.')); ?> ms,
                    filas <?php echo (int) ($perfSummary['rows'] ?? 0); ?>,
                    meses <?php echo (int) ($perfSummary['months'] ?? 0); ?>,
                    peak <?php echo msp2Escape(number_format((float) ($perfSummary['memory_peak_mb'] ?? 0), 2, ',', '.')); ?> MB.
                    <?php if (is_array($perfSummary['marks'] ?? null) && $perfSummary['marks'] !== []): ?>
                        <div class="mt-1">
                            <?php foreach ((array) $perfSummary['marks'] as $mark): ?>
                                <span class="me-2"><?php echo msp2Escape((string) ($mark['label'] ?? '')); ?>: <?php echo msp2Escape(number_format((float) ($mark['ms'] ?? 0), 2, ',', '.')); ?> ms</span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($loadError !== null): ?>
                <div class="alert alert-danger mb-0"><?php echo msp2Escape($loadError); ?></div>
            <?php elseif ($rows === []): ?>
                <div class="alert alert-info mb-0">No hay locales disponibles para mostrar en control diario.</div>
            <?php else: ?>
                <div class="control-grid-card is-preparing" aria-busy="true">
                <div class="control-grid-content">
                <div class="control-grid-wrap">
                    <table class="control-grid">
                        <thead>
                            <tr>
                                <th rowspan="2" class="sticky-col sticky-col-local">Locales</th>
                                <th rowspan="2" class="sticky-col sticky-col-arr">Arrendatario / RUT</th>
                                <?php foreach ($renderMonths as $month): ?>
                                    <th
                                        class="month-group js-month-group"
                                        colspan="11"
                                        data-month-key="<?php echo msp2Escape($month['key']); ?>"
                                        data-month-label="<?php echo msp2Escape($month['label']); ?>"
                                        data-month-available="<?php echo (bool) ($month['is_available'] ?? false) ? '1' : '0'; ?>"
                                    >
                                        <div class="month-head-inner">
                                            <span><?php echo msp2Escape($month['label']); ?></span>
                                            <span
                                                class="month-static js-uf-month"
                                                data-month-key="<?php echo msp2Escape($month['key']); ?>"
                                                data-uf-value="<?php echo msp2Escape(number_format((float) $month['uf'], 2, '.', '')); ?>"
                                                aria-label="Valor UF <?php echo msp2Escape($month['label']); ?>"
                                            >
                                                <?php echo msp2Escape(number_format((float) $month['uf'], 2, ',', '.')); ?>
                                            </span>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                            <tr>
                                <?php foreach ($renderMonths as $month): ?>
                                    <th class="js-uf-base-head js-month-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">UF base</th>
                                    <th class="js-month-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">Neto</th>
                                    <th class="js-month-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">IVA (19%)</th>
                                    <th class="js-month-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">Subtotal</th>
                                    <th class="js-month-col garantia-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">Garantía</th>
                                    <th class="js-month-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">Electricidad</th>
                                    <th class="js-month-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">Gas</th>
                                    <th class="js-month-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">Agua</th>
                                    <th class="js-month-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">Reserva</th>
                                    <th class="js-month-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">Total final</th>
                                    <th class="js-month-col status-col" data-month-key="<?php echo msp2Escape($month['key']); ?>">Estado</th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $arrByMonthRaw = is_array($row['arrendatario_by_month'] ?? null) ? $row['arrendatario_by_month'] : [];
                                $rutByMonthRaw = is_array($row['rut_display_by_month'] ?? null) ? $row['rut_display_by_month'] : [];
                                $arriendoNetoByMonthRaw = is_array($row['arriendo_neto_by_month'] ?? null) ? $row['arriendo_neto_by_month'] : [];
                                $arrByMonthJson = json_encode($arrByMonthRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                $rutByMonthJson = json_encode($rutByMonthRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                $arriendoNetoByMonthJson = json_encode($arriendoNetoByMonthRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                $arrendatarioIdByMonthRaw = is_array($row['arrendatario_id_by_month'] ?? null) ? $row['arrendatario_id_by_month'] : [];
                                $docIdByMonthRaw = is_array($row['doc_id_by_month'] ?? null) ? $row['doc_id_by_month'] : [];
                                $docNumberByMonthRaw = is_array($row['doc_number_by_month'] ?? null) ? $row['doc_number_by_month'] : [];
                                $arrendatarioIdByMonthJson = json_encode($arrendatarioIdByMonthRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                $docIdByMonthJson = json_encode($docIdByMonthRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                $docNumberByMonthJson = json_encode($docNumberByMonthRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                if (!is_string($arrByMonthJson) || $arrByMonthJson === '') {
                                    $arrByMonthJson = '{}';
                                }
                                if (!is_string($rutByMonthJson) || $rutByMonthJson === '') {
                                    $rutByMonthJson = '{}';
                                }
                                if (!is_string($arriendoNetoByMonthJson) || $arriendoNetoByMonthJson === '') {
                                    $arriendoNetoByMonthJson = '{}';
                                }
                                if (!is_string($arrendatarioIdByMonthJson) || $arrendatarioIdByMonthJson === '') {
                                    $arrendatarioIdByMonthJson = '{}';
                                }
                                if (!is_string($docIdByMonthJson) || $docIdByMonthJson === '') {
                                    $docIdByMonthJson = '{}';
                                }
                                if (!is_string($docNumberByMonthJson) || $docNumberByMonthJson === '') {
                                    $docNumberByMonthJson = '{}';
                                }
                                $terminoByMonthRaw = is_array($row['termino_by_month'] ?? null) ? $row['termino_by_month'] : [];
                                $postTerminoByMonthRaw = is_array($row['post_termino_by_month'] ?? null) ? $row['post_termino_by_month'] : [];
                                $terminoByMonth = [];
                                $postTerminoByMonth = [];
                                foreach ($renderMonths as $monthMeta) {
                                    $monthKeyTmp = (string) ($monthMeta['key'] ?? '');
                                    if ($monthKeyTmp === '') {
                                        continue;
                                    }
                                    $terminoByMonth[$monthKeyTmp] = (bool) ($terminoByMonthRaw[$monthKeyTmp] ?? false);
                                    $postTerminoByMonth[$monthKeyTmp] = (bool) ($postTerminoByMonthRaw[$monthKeyTmp] ?? false);
                                }
                                ?>
                                <tr
                                    data-local-id="<?php echo (int) $row['id_local']; ?>"
                                    data-uf-base="<?php echo msp2Escape(number_format((float) $row['uf_base'], 6, '.', '')); ?>"
                                    data-calc-mode="<?php echo msp2Escape((string) ($row['calc_mode'] ?? 'UF')); ?>"
                                    data-neto-fijo="<?php echo msp2Escape(number_format((float) ($row['neto_fijo'] ?? 0), 2, '.', '')); ?>"
                                    data-arrendatario-by-month="<?php echo msp2Escape($arrByMonthJson); ?>"
                                    data-arrendatario-id-by-month="<?php echo msp2Escape($arrendatarioIdByMonthJson); ?>"
                                    data-rut-by-month="<?php echo msp2Escape($rutByMonthJson); ?>"
                                    data-doc-id-by-month="<?php echo msp2Escape($docIdByMonthJson); ?>"
                                    data-doc-number-by-month="<?php echo msp2Escape($docNumberByMonthJson); ?>"
                                    data-arriendo-neto-by-month="<?php echo msp2Escape($arriendoNetoByMonthJson); ?>"
                                    data-local-label="<?php echo msp2Escape((string) ($row['local_code'] ?? '')); ?>"
                                >
                                    <td class="sticky-col sticky-col-local">
                                        <span class="local-label"><?php echo msp2Escape($row['local_code']); ?></span>
                                    </td>
                                    <td class="sticky-col sticky-col-arr">
                                        <div class="arr-cell-stack">
                                            <div class="arr-label js-arr-display"><?php echo msp2Escape($row['arrendatario']); ?></div>
                                            <div class="arr-rut js-rut-display"><?php echo msp2Escape($row['rut_display'] !== '' ? $row['rut_display'] : '-'); ?></div>
                                        </div>
                                    </td>

                                    <?php foreach ($renderMonths as $month): ?>
                                        <?php $monthKey = (string) $month['key']; ?>
                                        <?php
                                        $ufBaseByMonth = is_array($row['uf_base_by_month'] ?? null) ? $row['uf_base_by_month'] : [];
                                        $ufBaseMes = round((float) ($ufBaseByMonth[$monthKey] ?? ($row['uf_base'] ?? 0)), 6);
                                        $serviciosMes = is_array($row['servicios'][$monthKey] ?? null)
                                            ? $row['servicios'][$monthKey]
                                            : ['electricidad' => 0.0, 'gas' => 0.0, 'agua' => 0.0];
                                        $montoElectricidad = round((float) ($serviciosMes['electricidad'] ?? 0), 2);
                                        $montoGas = round((float) ($serviciosMes['gas'] ?? 0), 2);
                                        $montoAgua = round((float) ($serviciosMes['agua'] ?? 0), 2);
                                        $reservaMes = is_array($row['reserva'] ?? null) ? $row['reserva'] : [];
                                        $montoReserva = round((float) ($reservaMes[$monthKey] ?? 0), 2);
                                        $reservaBreakdownMes = is_array($row['reserva_breakdown'][$monthKey] ?? null)
                                            ? $row['reserva_breakdown'][$monthKey]
                                            : ['danos_multas' => 0.0, 'otros_cargos' => 0.0, 'saldo_favor_aplicado' => 0.0];
                                        $montoDanosMultas = round((float) ($reservaBreakdownMes['danos_multas'] ?? 0), 2);
                                        $montoOtrosCargos = round((float) ($reservaBreakdownMes['otros_cargos'] ?? 0), 2);
                                        $montoSaldoFavorAplicado = round((float) ($reservaBreakdownMes['saldo_favor_aplicado'] ?? 0), 2);
                                        $showReservaTooltip = abs($montoDanosMultas) > 0.009
                                            || abs($montoOtrosCargos) > 0.009
                                            || abs($montoSaldoFavorAplicado) > 0.009;
                                        $reservaTooltip = $showReservaTooltip
                                            ? implode("\n", [
                                                'Desglose reserva',
                                                'Daños/Multas: ' . msp2ControlDiarioFormatSignedAmount($montoDanosMultas),
                                                'Otros cargos: ' . msp2ControlDiarioFormatSignedAmount($montoOtrosCargos),
                                                'Saldo a favor aplicado: ' . msp2ControlDiarioFormatSignedAmount($montoSaldoFavorAplicado),
                                                'Total reserva: ' . msp2ControlDiarioFormatSignedAmount($montoReserva),
                                            ])
                                            : '';
                                        $garantiaMes = is_array($row['garantia'] ?? null) ? $row['garantia'] : [];
                                        $montoGarantia = round((float) ($garantiaMes[$monthKey] ?? 0), 2);
                                        $totalDocMes = is_array($row['total_doc'] ?? null) ? $row['total_doc'] : [];
                                        $montoTotalDoc = array_key_exists($monthKey, $totalDocMes)
                                            ? round((float) ($totalDocMes[$monthKey] ?? 0), 2)
                                            : null;
                                        $montoAjustePosteriorDoc = round($montoGarantia + $montoSaldoFavorAplicado, 2);
                                        $montoTotalFinalDoc = $montoTotalDoc !== null
                                            ? round($montoTotalDoc + $montoAjustePosteriorDoc, 2)
                                            : null;
                                        $showTotalTooltip = $montoTotalDoc !== null && abs($montoAjustePosteriorDoc) > 0.009;
                                        $totalTooltip = $showTotalTooltip
                                            ? implode("\n", [
                                                'Total documento: ' . msp2ControlDiarioFormatSignedAmount($montoTotalDoc),
                                                'Garantía aplicada: ' . msp2ControlDiarioFormatSignedAmount($montoGarantia),
                                                'Saldo a favor aplicado: ' . msp2ControlDiarioFormatSignedAmount($montoSaldoFavorAplicado),
                                                'Total final: ' . msp2ControlDiarioFormatSignedAmount((float) $montoTotalFinalDoc),
                                            ])
                                            : '';
                                        $calcModeMes = strtoupper(trim((string) ($row['calc_mode'] ?? 'UF')));
                                        $netoFijoMes = round((float) ($row['neto_fijo'] ?? 0), 2);
                                        $usaNetoFijoMes = $calcModeMes === 'NETO_FIJO' && $netoFijoMes > 0;
                                        $monthUf = round((float) ($month['uf'] ?? 0), 4);
                                        $hasArriendoNetoMes = array_key_exists($monthKey, $arriendoNetoByMonthRaw);
                                        $arriendoNetoMes = $hasArriendoNetoMes
                                            ? round((float) ($arriendoNetoByMonthRaw[$monthKey] ?? 0), 2)
                                            : 0.0;
                                        $netoMes = $hasArriendoNetoMes
                                            ? $arriendoNetoMes
                                            : ($usaNetoFijoMes ? $netoFijoMes : round($ufBaseMes * $monthUf, 2));
                                        $ivaMes = round($netoMes * 0.19, 2);
                                        $subtotalMes = round($netoMes + $ivaMes, 2);
                                        $totalFinalCalculadoMes = round(
                                            $subtotalMes + $montoGarantia + $montoElectricidad + $montoGas + $montoAgua + $montoReserva,
                                            2
                                        );
                                        $totalFinalMes = $montoTotalFinalDoc !== null
                                            ? round((float) $montoTotalFinalDoc, 2)
                                            : $totalFinalCalculadoMes;
                                        ?>
                                        <td
                                            class="cell-num js-uf-base-display js-uf-base-cell js-month-col"
                                            data-month-key="<?php echo msp2Escape($monthKey); ?>"
                                            data-uf-base-value="<?php echo msp2Escape(number_format($ufBaseMes, 6, '.', '')); ?>"
                                        >
                                            <?php if (($row['calc_mode'] ?? 'UF') === 'NETO_FIJO'): ?>
                                                -
                                            <?php else: ?>
                                                <?php echo msp2Escape(number_format($ufBaseMes, 2, ',', '.')); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="cell-num js-neto js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>" data-neto-monto="<?php echo msp2Escape(number_format($netoMes, 2, '.', '')); ?>">
                                            <?php echo msp2Escape(number_format($netoMes, 2, ',', '.')); ?>
                                        </td>
                                        <td class="cell-num js-iva js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>" data-iva-monto="<?php echo msp2Escape(number_format($ivaMes, 2, '.', '')); ?>">
                                            <?php echo msp2Escape(number_format($ivaMes, 2, ',', '.')); ?>
                                        </td>
                                        <td class="cell-num cell-subtotal js-subtotal js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>" data-subtotal-monto="<?php echo msp2Escape(number_format($subtotalMes, 2, '.', '')); ?>">
                                            <?php echo msp2Escape(number_format($subtotalMes, 2, ',', '.')); ?>
                                        </td>
                                        <td class="cell-num cell-readonly js-garantia js-month-col garantia-col" data-month-key="<?php echo msp2Escape($monthKey); ?>" data-garantia-monto="<?php echo msp2Escape(number_format($montoGarantia, 2, '.', '')); ?>">
                                            <?php echo msp2Escape(number_format($montoGarantia, 2, ',', '.')); ?>
                                        </td>
                                        <td class="cell-num js-servicio-electricidad js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>" data-servicio-monto="<?php echo msp2Escape(number_format($montoElectricidad, 2, '.', '')); ?>">
                                            <?php echo msp2Escape(number_format($montoElectricidad, 2, ',', '.')); ?>
                                        </td>
                                        <td class="cell-num js-servicio-gas js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>" data-servicio-monto="<?php echo msp2Escape(number_format($montoGas, 2, '.', '')); ?>">
                                            <?php echo msp2Escape(number_format($montoGas, 2, ',', '.')); ?>
                                        </td>
                                        <td class="cell-num js-servicio-agua js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>" data-servicio-monto="<?php echo msp2Escape(number_format($montoAgua, 2, '.', '')); ?>">
                                            <?php echo msp2Escape(number_format($montoAgua, 2, ',', '.')); ?>
                                        </td>
                                        <td
                                            class="cell-num js-reserva js-month-col<?php echo $showReservaTooltip ? ' has-tooltip' : ''; ?>"
                                            data-month-key="<?php echo msp2Escape($monthKey); ?>"
                                            data-reserva-monto="<?php echo msp2Escape(number_format($montoReserva, 2, '.', '')); ?>"
                                            <?php if ($showReservaTooltip): ?>
                                                title="<?php echo msp2Escape($reservaTooltip); ?>"
                                            <?php endif; ?>
                                        >
                                            <span class="reserva-cell-content">
                                                <?php if ($showReservaTooltip): ?>
                                                    <i class="bi bi-info-circle-fill reserva-cell-info" aria-hidden="true"></i>
                                                <?php endif; ?>
                                                <span><?php echo msp2Escape(number_format($montoReserva, 2, ',', '.')); ?></span>
                                            </span>
                                        </td>
                                        <td
                                            class="cell-num cell-total js-total js-month-col<?php echo $showTotalTooltip ? ' has-tooltip' : ''; ?>"
                                            data-month-key="<?php echo msp2Escape($monthKey); ?>"
                                            data-total-doc="<?php echo $montoTotalFinalDoc !== null ? msp2Escape(number_format($montoTotalFinalDoc, 2, '.', '')) : ''; ?>"
                                            data-total-final-monto="<?php echo msp2Escape(number_format($totalFinalMes, 2, '.', '')); ?>"
                                            <?php if ($showTotalTooltip): ?>
                                                title="<?php echo msp2Escape($totalTooltip); ?>"
                                            <?php endif; ?>
                                        >
                                            <?php echo msp2Escape(number_format($totalFinalMes, 2, ',', '.')); ?>
                                        </td>
                                        <td class="js-month-col status-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">
                                            <?php
                                            $hasCierreMes = (bool) ($month['has_cierre'] ?? false);
                                            $estadoDocMes = strtoupper(trim((string) (($row['estado_doc'][$monthKey] ?? ''))));
                                            $estadoTerminoMes = (bool) ($terminoByMonth[$monthKey] ?? false);
                                            $estadoPostTerminoMes = (bool) ($postTerminoByMonth[$monthKey] ?? false);
                                            if ($estadoTerminoMes || $estadoPostTerminoMes) {
                                                $statusLabel = 'TERMINO';
                                                $statusClass = 'is-terminated';
                                                $statusIndex = 4;
                                            } elseif (!$hasCierreMes) {
                                                $statusLabel = 'SIN CIERRE';
                                                $statusClass = 'is-no-close';
                                                $statusIndex = 0;
                                            } elseif ($estadoDocMes === '') {
                                                $statusLabel = 'SIN DOCUMENTO';
                                                $statusClass = 'is-no-close';
                                                $statusIndex = 0;
                                            } elseif ($estadoDocMes === 'OK') {
                                                $statusLabel = 'OK';
                                                $statusClass = 'is-paid';
                                                $statusIndex = 1;
                                            } elseif ($estadoDocMes === 'ATRASADO') {
                                                $statusLabel = 'ATRASADO';
                                                $statusClass = 'is-late';
                                                $statusIndex = 3;
                                            } else {
                                                $statusLabel = 'PENDIENTE';
                                                $statusClass = 'is-pending';
                                                $statusIndex = 2;
                                            }
                                            ?>
                                            <span class="status-chip <?php echo msp2Escape($statusClass); ?> js-status-chip">
                                                <?php echo msp2Escape($statusLabel); ?>
                                            </span>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="control-total-row">
                                <td class="sticky-col sticky-col-local"><strong>TOTAL</strong></td>
                                <td class="sticky-col sticky-col-arr"></td>
                                <?php foreach ($renderMonths as $month): ?>
                                    <?php $monthKey = (string) $month['key']; ?>
                                    <td class="cell-num js-total-uf-base js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">0,00</td>
                                    <td class="cell-num js-total-neto js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">0,00</td>
                                    <td class="cell-num js-total-iva js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">0,00</td>
                                    <td class="cell-num js-total-subtotal js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">0,00</td>
                                    <td class="cell-num js-total-garantia js-month-col garantia-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">0,00</td>
                                    <td class="cell-num js-total-electricidad js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">0,00</td>
                                    <td class="cell-num js-total-gas js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">0,00</td>
                                    <td class="cell-num js-total-agua js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">0,00</td>
                                    <td class="cell-num js-total-reserva js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">0,00</td>
                                    <td class="cell-num js-total-final js-month-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">0,00</td>
                                    <td class="js-month-col status-col" data-month-key="<?php echo msp2Escape($monthKey); ?>">-</td>
                                <?php endforeach; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                </div>
                <div class="control-loading-layer" role="status" aria-live="polite" aria-label="Cargando información de Control diario">
                    <div class="control-loading-panel">
                        <div class="control-loading-icon">
                            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        </div>
                        <div class="control-loading-title">Cargando información</div>
                        <div class="control-loading-text">Preparando control diario y totales del periodo.</div>
                        <div class="control-loading-track" aria-hidden="true">
                            <div class="control-loading-bar"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
    </div>
</main>

<div class="modal fade" id="controlRowRedirectModal" tabindex="-1" aria-labelledby="controlRowRedirectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="controlRowRedirectModalLabel">Abrir detalle de cobranza</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Se abrirá la vista de documentos de cobro para la fila seleccionada.</p>
                <div class="control-nav-summary mb-3">
                    <div>
                        <div class="control-nav-item-label">Período</div>
                        <div class="control-nav-item-value" id="control-nav-period">-</div>
                    </div>
                    <div>
                        <div class="control-nav-item-label">Locales</div>
                        <div class="control-nav-item-value" id="control-nav-locales">-</div>
                    </div>
                    <div>
                        <div class="control-nav-item-label">Arrendatario</div>
                        <div class="control-nav-item-value" id="control-nav-arrendatario">-</div>
                    </div>
                    <div>
                        <div class="control-nav-item-label">Documento</div>
                        <div class="control-nav-item-value" id="control-nav-documento">-</div>
                    </div>
                </div>
                <div class="control-nav-status">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <span id="control-nav-status-text">Preparando navegación.</span>
                </div>
            </div>
            <div class="modal-footer d-flex justify-content-between align-items-center">
                <div class="control-nav-countdown" id="control-nav-countdown">Redirección automática en 4 s.</div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <a href="#" class="btn btn-primary" id="control-nav-confirm-link">Abrir ahora</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
<script>
(function () {
    const FOCUSBAR_STORAGE_KEY = 'msp-control-diario-focusbar-collapsed';
    const ROW_REDIRECT_DELAY_SECONDS = 4;
    const PERF_FRONT_ENABLED = <?php echo $perfEnabled ? 'true' : 'false'; ?>;
    const COMPACT_DOM_MODE = <?php echo $compactDomMode ? 'true' : 'false'; ?>;
    let currentVisibleMonthKey = '';
    let rowRedirectTimer = null;
    const frontPerfStart = window.performance && typeof window.performance.now === 'function'
        ? window.performance.now()
        : Date.now();
    const frontPerfMarks = [];
    function markFront(label) {
        if (!PERF_FRONT_ENABLED) {
            return;
        }
        const now = window.performance && typeof window.performance.now === 'function'
            ? window.performance.now()
            : Date.now();
        frontPerfMarks.push({ label, ms: Math.round((now - frontPerfStart) * 100) / 100 });
    }
    function flushFrontPerf() {
        if (!PERF_FRONT_ENABLED || frontPerfMarks.length === 0) {
            return;
        }
        const msg = frontPerfMarks.map((m) => m.label + ': ' + m.ms.toFixed(2) + 'ms').join(' | ');
        // eslint-disable-next-line no-console
        console.log('[control_diario/front_perf] ' + msg);
    }

    function flushNavigationPerf() {
        if (!PERF_FRONT_ENABLED || !window.performance || typeof window.performance.getEntriesByType !== 'function') {
            return;
        }
        const nav = window.performance.getEntriesByType('navigation')[0];
        if (nav) {
            const navMsg = [
                'ttfb=' + (nav.responseStart - nav.requestStart).toFixed(2) + 'ms',
                'domContentLoaded=' + nav.domContentLoadedEventEnd.toFixed(2) + 'ms',
                'load=' + nav.loadEventEnd.toFixed(2) + 'ms'
            ].join(' | ');
            // eslint-disable-next-line no-console
            console.log('[control_diario/nav_perf] ' + navMsg);
        }

        const resources = window.performance.getEntriesByType('resource')
            .filter((entry) => typeof entry.duration === 'number' && entry.duration > 0)
            .sort((a, b) => b.duration - a.duration)
            .slice(0, 8);
        if (resources.length > 0) {
            const topMsg = resources.map((entry) => {
                const name = String(entry.name || '');
                const shortName = name.length > 80 ? ('...' + name.slice(-80)) : name;
                return shortName + ' (' + entry.duration.toFixed(2) + 'ms)';
            }).join(' | ');
            // eslint-disable-next-line no-console
            console.log('[control_diario/resource_top] ' + topMsg);
        }
    }
    function setControlGridLoading(isLoading) {
        const card = document.querySelector('.control-grid-card');
        if (!card) {
            return;
        }

        card.classList.toggle('is-preparing', false);
        card.classList.toggle('is-loading', isLoading);
        card.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    }

    function finishInitialGridLoading() {
        window.requestAnimationFrame(function () {
            syncStickyHeaderOffset();
            setControlGridLoading(false);
            markFront('first_paint_ready');
        });
    }

    function initYearLoading() {
        const form = document.querySelector('.js-control-year-form');
        if (!form) {
            return;
        }

        form.addEventListener('submit', function () {
            setControlGridLoading(true);
            form.querySelectorAll('button').forEach((control) => {
                control.disabled = true;
            });
        });

        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) {
                return;
            }
            form.querySelectorAll('button').forEach((control) => {
                control.disabled = false;
            });
            setControlGridLoading(false);
        });
    }

    function parseLocaleNumber(value, allowNegative = false) {
        if (typeof value !== 'string') {
            return 0;
        }
        let normalized = value.trim().replace(/\s+/g, '');
        if (normalized === '') {
            return 0;
        }

        const hasComma = normalized.includes(',');
        const hasDot = normalized.includes('.');
        if (hasComma && hasDot) {
            if (normalized.lastIndexOf(',') > normalized.lastIndexOf('.')) {
                normalized = normalized.replace(/\./g, '').replace(',', '.');
            } else {
                normalized = normalized.replace(/,/g, '');
            }
        } else if (hasComma) {
            normalized = normalized.replace(',', '.');
        }

        normalized = normalized.replace(/[^0-9.-]/g, '');
        const parsed = Number.parseFloat(normalized);
        if (!Number.isFinite(parsed)) {
            return 0;
        }
        if (!allowNegative && parsed < 0) {
            return 0;
        }
        return parsed;
    }

    function formatNumber(value, decimals) {
        return Number(value || 0).toLocaleString('es-CL', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    }

    function parseMonthMap(raw) {
        if (typeof raw !== 'string' || raw.trim() === '') {
            return {};
        }
        try {
            const parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                return parsed;
            }
        } catch (_error) {
            return {};
        }
        return {};
    }

    function getRowMonthMap(row, attrName, cacheKey) {
        if (Object.prototype.hasOwnProperty.call(row, cacheKey)) {
            return row[cacheKey] || {};
        }
        const parsed = parseMonthMap(row.getAttribute(attrName) || '{}');
        row[cacheKey] = parsed;
        return parsed;
    }

    function getRowSearchableText(row) {
        if (Object.prototype.hasOwnProperty.call(row, '__searchableText')) {
            return String(row.__searchableText || '');
        }
        const localText = (row.querySelector('.sticky-col-local')?.textContent || '').trim();
        const arrText = (row.querySelector('.js-arr-display')?.textContent || '').trim();
        const rutText = (row.querySelector('.js-rut-display')?.textContent || '').trim();
        const searchableText = (localText + ' ' + arrText + ' ' + rutText).toLowerCase();
        row.__searchableText = searchableText;
        return searchableText;
    }

    function getRowStatusByMonth(row) {
        if (Object.prototype.hasOwnProperty.call(row, '__statusByMonth')) {
            return row.__statusByMonth || {};
        }
        const map = {};
        row.querySelectorAll('td.status-col[data-month-key]').forEach((statusCell) => {
            const monthKey = String(statusCell.getAttribute('data-month-key') || '').trim();
            if (monthKey === '') {
                return;
            }
            const chip = statusCell.querySelector('.js-status-chip');
            map[monthKey] = chip ? String(chip.textContent || '').trim().toUpperCase() : '';
        });
        row.__statusByMonth = map;
        return map;
    }

    function getRowMetricsByMonth(row) {
        if (Object.prototype.hasOwnProperty.call(row, '__metricsByMonth')) {
            return row.__metricsByMonth || {};
        }
        const metrics = {};
        const ensureMonth = function (monthKey) {
            if (!Object.prototype.hasOwnProperty.call(metrics, monthKey)) {
                metrics[monthKey] = {
                    ufBase: 0,
                    neto: 0,
                    iva: 0,
                    subtotal: 0,
                    garantia: 0,
                    electricidad: 0,
                    gas: 0,
                    agua: 0,
                    reserva: 0,
                    totalFinal: 0,
                };
            }
            return metrics[monthKey];
        };
        row.querySelectorAll('[data-month-key]').forEach((cell) => {
            const monthKey = String(cell.getAttribute('data-month-key') || '').trim();
            if (monthKey === '') {
                return;
            }
            const target = ensureMonth(monthKey);
            if (cell.classList.contains('js-uf-base-display')) {
                target.ufBase = parseLocaleNumber(cell.getAttribute('data-uf-base-value') || '');
            } else if (cell.classList.contains('js-neto')) {
                target.neto = parseLocaleNumber(cell.getAttribute('data-neto-monto') || '');
            } else if (cell.classList.contains('js-iva')) {
                target.iva = parseLocaleNumber(cell.getAttribute('data-iva-monto') || '');
            } else if (cell.classList.contains('js-subtotal')) {
                target.subtotal = parseLocaleNumber(cell.getAttribute('data-subtotal-monto') || '');
            } else if (cell.classList.contains('js-garantia')) {
                target.garantia = parseLocaleNumber(cell.getAttribute('data-garantia-monto') || '', true);
            } else if (cell.classList.contains('js-servicio-electricidad')) {
                target.electricidad = parseLocaleNumber(cell.getAttribute('data-servicio-monto') || '');
            } else if (cell.classList.contains('js-servicio-gas')) {
                target.gas = parseLocaleNumber(cell.getAttribute('data-servicio-monto') || '');
            } else if (cell.classList.contains('js-servicio-agua')) {
                target.agua = parseLocaleNumber(cell.getAttribute('data-servicio-monto') || '');
            } else if (cell.classList.contains('js-reserva')) {
                target.reserva = parseLocaleNumber(cell.getAttribute('data-reserva-monto') || '', true);
            } else if (cell.classList.contains('js-total')) {
                target.totalFinal = parseLocaleNumber(cell.getAttribute('data-total-final-monto') || '', true);
            }
        });
        row.__metricsByMonth = metrics;
        return metrics;
    }

    function clearRowRedirectTimer() {
        if (rowRedirectTimer !== null) {
            window.clearInterval(rowRedirectTimer);
            rowRedirectTimer = null;
        }
    }

    function applyArrendatarioMonth(row, monthKey) {
        const arrDisplay = row.querySelector('.js-arr-display');
        const rutDisplay = row.querySelector('.js-rut-display');
        if (!arrDisplay && !rutDisplay) {
            return;
        }

        const arrMap = getRowMonthMap(row, 'data-arrendatario-by-month', '__arrendatarioByMonth');
        const rutMap = getRowMonthMap(row, 'data-rut-by-month', '__rutByMonth');

        const arrValue = Object.prototype.hasOwnProperty.call(arrMap, monthKey)
            ? String(arrMap[monthKey] || '').trim()
            : '';
        const rutValue = Object.prototype.hasOwnProperty.call(rutMap, monthKey)
            ? String(rutMap[monthKey] || '').trim()
            : '';

        if (arrDisplay) {
            arrDisplay.textContent = arrValue;
        }
        if (rutDisplay) {
            rutDisplay.textContent = rutValue !== '' ? rutValue : '-';
        }
    }

    function refreshTotalsRow() {
        const visibleRows = Array.from(document.querySelectorAll('tbody tr[data-local-id]')).filter((row) => {
            return row.style.display !== 'none';
        });
        const monthKeys = Array.from(document.querySelectorAll('.js-month-group')).map((head) => {
            return String(head.getAttribute('data-month-key') || '').trim();
        }).filter((key) => key !== '');

        monthKeys.forEach((monthKey) => {
            const totals = {
                ufBase: 0,
                neto: 0,
                iva: 0,
                subtotal: 0,
                garantia: 0,
                electricidad: 0,
                gas: 0,
                agua: 0,
                reserva: 0,
                totalFinal: 0,
            };

            visibleRows.forEach((row) => {
                const metricsByMonth = getRowMetricsByMonth(row);
                const values = metricsByMonth[monthKey] || null;
                if (!values) {
                    return;
                }
                totals.ufBase += Number(values.ufBase || 0);
                totals.neto += Number(values.neto || 0);
                totals.iva += Number(values.iva || 0);
                totals.subtotal += Number(values.subtotal || 0);
                totals.garantia += Number(values.garantia || 0);
                totals.electricidad += Number(values.electricidad || 0);
                totals.gas += Number(values.gas || 0);
                totals.agua += Number(values.agua || 0);
                totals.reserva += Number(values.reserva || 0);
                totals.totalFinal += Number(values.totalFinal || 0);
            });

            const setTotalCell = function (selector, value) {
                const cell = document.querySelector(selector + '[data-month-key="' + monthKey + '"]');
                if (cell) {
                    cell.textContent = formatNumber(value, 2);
                }
            };

            setTotalCell('.js-total-uf-base', totals.ufBase);
            setTotalCell('.js-total-neto', totals.neto);
            setTotalCell('.js-total-iva', totals.iva);
            setTotalCell('.js-total-subtotal', totals.subtotal);
            setTotalCell('.js-total-garantia', totals.garantia);
            setTotalCell('.js-total-electricidad', totals.electricidad);
            setTotalCell('.js-total-gas', totals.gas);
            setTotalCell('.js-total-agua', totals.agua);
            setTotalCell('.js-total-reserva', totals.reserva);
            setTotalCell('.js-total-final', totals.totalFinal);
        });
    }

    function applyFilters() {
        const statusInput = document.getElementById('filter-status');
        const searchInput = document.getElementById('filter-search');
        const statusFilter = statusInput ? (statusInput.value || '').trim().toUpperCase() : '';
        const searchFilter = searchInput ? (searchInput.value || '').trim().toLowerCase() : '';

        document.querySelectorAll('tbody tr[data-local-id]').forEach((row) => {
            const searchableText = getRowSearchableText(row);

            let matchesStatus = true;
            if (statusFilter !== '' && currentVisibleMonthKey !== '') {
                const statusMap = getRowStatusByMonth(row);
                const statusText = String(statusMap[currentVisibleMonthKey] || '').trim().toUpperCase();
                matchesStatus = statusText === statusFilter;
            }

            const matchesSearch = searchFilter === '' || searchableText.includes(searchFilter);
            row.style.display = matchesStatus && matchesSearch ? '' : 'none';
        });
        refreshTotalsRow();
    }

    function initFilters() {
        const statusInput = document.getElementById('filter-status');
        const searchInput = document.getElementById('filter-search');
        if (!statusInput || !searchInput) {
            return;
        }
        statusInput.addEventListener('change', applyFilters);
        searchInput.addEventListener('input', applyFilters);
    }

    function initMonthNavigator() {
        const table = document.querySelector('.control-grid');
        const monthGroups = Array.from(document.querySelectorAll('.js-month-group'));
        const monthCols = Array.from(document.querySelectorAll('.js-month-col'));
        const prevBtn = document.getElementById('month-prev-btn');
        const nextBtn = document.getElementById('month-next-btn');
        const slider = document.getElementById('month-slider');
        const monthScaleItems = Array.from(document.querySelectorAll('.js-month-scale-item'));
        if (!table || monthGroups.length === 0 || !prevBtn || !nextBtn || !slider) {
            return;
        }
        table.classList.add('month-single-mode');

        const allMonthKeys = <?php
            $allMonthKeysJson = json_encode(array_values(array_keys($months)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo is_string($allMonthKeysJson) ? $allMonthKeysJson : '[]';
        ?>;
        if (allMonthKeys.length === 0) {
            return;
        }
        const monthAvailabilitySource = <?php
            $monthAvailabilityMap = [];
            foreach ($months as $key => $meta) {
                $monthAvailabilityMap[(string) $key] = (bool) ($meta['is_available'] ?? false);
            }
            $monthAvailabilityJson = json_encode($monthAvailabilityMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo is_string($monthAvailabilityJson) ? $monthAvailabilityJson : '{}';
        ?>;
        const monthAvailabilityByKey = new Map(Object.entries(monthAvailabilitySource));
        const availableIndexes = [];
        allMonthKeys.forEach((key, index) => {
            if (monthAvailabilityByKey.get(key) === true) {
                availableIndexes.push(index);
            }
        });
        const hasAvailableMonths = COMPACT_DOM_MODE ? false : (availableIndexes.length > 0);

        const monthLabelSource = <?php
            $monthLabelMap = [];
            foreach ($months as $key => $meta) {
                $monthLabelMap[(string) $key] = (string) ($meta['label'] ?? $key);
            }
            $monthLabelJson = json_encode($monthLabelMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo is_string($monthLabelJson) ? $monthLabelJson : '{}';
        ?>;
        const monthLabelByKey = new Map(Object.entries(monthLabelSource));

        slider.min = '1';
        slider.max = String(allMonthKeys.length);
        const currentMonthKey = '<?php echo msp2Escape($viewMonthKey); ?>';

        const selectedYear = Number.parseInt('<?php echo (int) $selectedYear; ?>', 10);
        const now = new Date();
        const currentYear = now.getFullYear();
        const findClosestAvailableIndex = function (requestedIndex) {
            if (!hasAvailableMonths) {
                return Math.max(0, Math.min(allMonthKeys.length - 1, requestedIndex));
            }
            let closestIndex = availableIndexes[0];
            let closestDistance = Math.abs(closestIndex - requestedIndex);
            for (let i = 1; i < availableIndexes.length; i += 1) {
                const candidate = availableIndexes[i];
                const distance = Math.abs(candidate - requestedIndex);
                if (distance < closestDistance) {
                    closestDistance = distance;
                    closestIndex = candidate;
                }
            }
            return closestIndex;
        };
        const findPrevAvailableIndex = function (fromIndex) {
            if (!hasAvailableMonths) {
                return fromIndex > 0 ? fromIndex - 1 : null;
            }
            for (let i = fromIndex - 1; i >= 0; i -= 1) {
                const key = allMonthKeys[i] || '';
                if (monthAvailabilityByKey.get(key) === true) {
                    return i;
                }
            }
            return null;
        };
        const findNextAvailableIndex = function (fromIndex) {
            if (!hasAvailableMonths) {
                return fromIndex < (allMonthKeys.length - 1) ? fromIndex + 1 : null;
            }
            for (let i = fromIndex + 1; i < allMonthKeys.length; i += 1) {
                const key = allMonthKeys[i] || '';
                if (monthAvailabilityByKey.get(key) === true) {
                    return i;
                }
            }
            return null;
        };

        let activeIndex = Math.max(0, allMonthKeys.indexOf(currentMonthKey));
        if (activeIndex < 0) {
            activeIndex = hasAvailableMonths
                ? availableIndexes[availableIndexes.length - 1]
                : Math.max(0, allMonthKeys.length - 1);
            if (selectedYear === currentYear) {
                const currentMonthIndex = now.getMonth();
                if (hasAvailableMonths) {
                    let bestAvailableIndex = -1;
                    availableIndexes.forEach((index) => {
                        if (index <= currentMonthIndex) {
                            bestAvailableIndex = index;
                        }
                    });
                    activeIndex = bestAvailableIndex >= 0 ? bestAvailableIndex : availableIndexes[0];
                } else {
                    activeIndex = Math.max(0, Math.min(allMonthKeys.length - 1, currentMonthIndex));
                }
            }
        }

        const navigateToMonth = function (targetIndex) {
            const normalizedIndex = Math.max(0, Math.min(allMonthKeys.length - 1, targetIndex));
            const key = allMonthKeys[normalizedIndex] || '';
            if (!key) {
                return;
            }
            if (key === currentMonthKey) {
                return;
            }
            const url = new URL(window.location.href);
            url.searchParams.set('anio', String(selectedYear));
            url.searchParams.set('mes', key);
            if (<?php echo $perfEnabled ? 'true' : 'false'; ?>) {
                url.searchParams.set('perf', '1');
            } else {
                url.searchParams.delete('perf');
            }
            url.searchParams.delete('full');
            window.location.href = url.toString();
        };

        const apply = function () {
            const key = allMonthKeys[activeIndex] || allMonthKeys[0];
            currentVisibleMonthKey = key;
            monthGroups.forEach((node) => node.classList.remove('is-month-hidden'));
            monthCols.forEach((node) => node.classList.remove('is-month-hidden'));

            monthScaleItems.forEach((item) => {
                const itemKey = (item.getAttribute('data-month-key') || '').trim();
                item.classList.toggle('is-active', itemKey === key);
            });
            document.querySelectorAll('tbody tr[data-local-id]').forEach((row) => {
                applyArrendatarioMonth(row, key);
            });

            slider.value = String(activeIndex + 1);
            prevBtn.disabled = findPrevAvailableIndex(activeIndex) === null;
            nextBtn.disabled = findNextAvailableIndex(activeIndex) === null;
            applyFilters();
            decorateNavigableRows();
        };

        prevBtn.addEventListener('click', function () {
            const prevIndex = findPrevAvailableIndex(activeIndex);
            if (prevIndex === null) {
                return;
            }
            navigateToMonth(prevIndex);
        });

        nextBtn.addEventListener('click', function () {
            const nextIndex = findNextAvailableIndex(activeIndex);
            if (nextIndex === null) {
                return;
            }
            navigateToMonth(nextIndex);
        });

        slider.addEventListener('input', function () {
            const value = Number.parseInt(slider.value || '1', 10);
            if (!Number.isFinite(value)) {
                return;
            }
            const target = findClosestAvailableIndex(Math.max(0, Math.min(allMonthKeys.length - 1, value - 1)));
            navigateToMonth(target);
        });

        apply();
    }

    function decorateNavigableRows() {
        document.querySelectorAll('tbody tr[data-local-id]').forEach((row) => {
            const arrIdMap = getRowMonthMap(row, 'data-arrendatario-id-by-month', '__arrendatarioIdByMonth');
            const arrId = Number.parseInt(String(arrIdMap[currentVisibleMonthKey] || '0'), 10);
            const isNavigable = Number.isFinite(arrId) && arrId > 0 && currentVisibleMonthKey !== '';
            row.classList.toggle('is-row-link', isNavigable);
            row.tabIndex = isNavigable ? 0 : -1;
            row.setAttribute('aria-label', isNavigable ? 'Abrir detalle de cobranza' : '');
        });
    }

    function initRowRedirectModal() {
        const modalElement = document.getElementById('controlRowRedirectModal');
        const confirmLink = document.getElementById('control-nav-confirm-link');
        const periodNode = document.getElementById('control-nav-period');
        const localesNode = document.getElementById('control-nav-locales');
        const arrNode = document.getElementById('control-nav-arrendatario');
        const docNode = document.getElementById('control-nav-documento');
        const statusNode = document.getElementById('control-nav-status-text');
        const countdownNode = document.getElementById('control-nav-countdown');
        if (!modalElement || !confirmLink || !periodNode || !localesNode || !arrNode || !docNode || !statusNode || !countdownNode) {
            return;
        }

        const modal = new bootstrap.Modal(modalElement);

        const updateCountdown = function (secondsLeft, autoRedirect) {
            if (!autoRedirect) {
                countdownNode.textContent = 'La redirección automática está desactivada para esta fila.';
                return;
            }
            countdownNode.textContent = 'Redirección automática en ' + String(secondsLeft) + ' s.';
        };

        modalElement.addEventListener('hidden.bs.modal', function () {
            clearRowRedirectTimer();
            confirmLink.setAttribute('href', '#');
        });

        const openForRow = function (row) {
            if (!currentVisibleMonthKey) {
                return;
            }
            const arrMap = getRowMonthMap(row, 'data-arrendatario-by-month', '__arrendatarioByMonth');
            const arrIdMap = getRowMonthMap(row, 'data-arrendatario-id-by-month', '__arrendatarioIdByMonth');
            const docIdMap = getRowMonthMap(row, 'data-doc-id-by-month', '__docIdByMonth');
            const docNumberMap = getRowMonthMap(row, 'data-doc-number-by-month', '__docNumberByMonth');

            const arrendatario = String(arrMap[currentVisibleMonthKey] || '').trim();
            const arrendatarioId = Number.parseInt(String(arrIdMap[currentVisibleMonthKey] || '0'), 10);
            if (!Number.isFinite(arrendatarioId) || arrendatarioId <= 0) {
                return;
            }

            const monthGroup = document.querySelector('.js-month-group[data-month-key="' + currentVisibleMonthKey + '"]');
            const monthLabel = monthGroup ? String(monthGroup.getAttribute('data-month-label') || currentVisibleMonthKey).trim() : currentVisibleMonthKey;
            const periodLabel = monthLabel + ' ' + currentVisibleMonthKey.slice(0, 4);
            const localLabel = String(row.getAttribute('data-local-label') || '').trim() || '-';
            const docId = Number.parseInt(String(docIdMap[currentVisibleMonthKey] || '0'), 10);
            const docNumber = String(docNumberMap[currentVisibleMonthKey] || '').trim();
            const targetUrl = '<?php echo msp2Escape(msp2Url('documentos_cobro/index.php')); ?>?id_arrendatario='
                + encodeURIComponent(String(arrendatarioId))
                + '&filtroPeriodo='
                + encodeURIComponent(currentVisibleMonthKey);

            periodNode.textContent = periodLabel;
            localesNode.textContent = localLabel;
            arrNode.textContent = arrendatario !== '' ? arrendatario : '-';
            docNode.textContent = docId > 0 ? ((docNumber !== '' ? docNumber : ('#' + String(docId))) + ' · ID ' + String(docId)) : 'Sin documento emitido';
            statusNode.textContent = docId > 0
                ? 'Se abrirá el detalle del período y podrás revisar el documento existente.'
                : 'No hay documento emitido para este período. Se abrirá la consulta del arrendatario y mes seleccionado.';
            confirmLink.textContent = docId > 0 ? 'Ver documento' : 'Abrir consulta';
            confirmLink.setAttribute('href', targetUrl);

            clearRowRedirectTimer();
            let secondsLeft = ROW_REDIRECT_DELAY_SECONDS;
            updateCountdown(secondsLeft, true);
            rowRedirectTimer = window.setInterval(function () {
                secondsLeft -= 1;
                if (secondsLeft <= 0) {
                    clearRowRedirectTimer();
                    window.location.href = targetUrl;
                    return;
                }
                updateCountdown(secondsLeft, true);
            }, 1000);

            modal.show();
        };

        document.querySelectorAll('tbody tr[data-local-id]').forEach((row) => {
            row.addEventListener('click', function (event) {
                const interactive = event.target.closest('a, button, input, select, textarea, label');
                if (interactive) {
                    return;
                }
                if (!row.classList.contains('is-row-link')) {
                    return;
                }
                openForRow(row);
            });
            row.addEventListener('keydown', function (event) {
                if (!row.classList.contains('is-row-link')) {
                    return;
                }
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                event.preventDefault();
                openForRow(row);
            });
        });
    }

    function syncStickyHeaderOffset() {
        const table = document.querySelector('.control-grid');
        if (!table) {
            return;
        }
        const firstHeadRow = table.querySelector('thead tr:first-child');
        if (!firstHeadRow) {
            return;
        }
        const rowHeight = Math.max(1, Math.round(firstHeadRow.getBoundingClientRect().height));
        table.style.setProperty('--control-head-row-1-height', String(rowHeight) + 'px');
    }

    function initFocusBar() {
        const focusBar = document.querySelector('.js-control-focusbar');
        const toggleButton = document.querySelector('.js-control-focusbar-toggle');
        if (!focusBar || !toggleButton) {
            return;
        }

        const setCollapsed = function (collapsed) {
            focusBar.classList.toggle('is-collapsed', collapsed);
            document.body.classList.toggle('cd-focusbar-collapsed', collapsed);
            toggleButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggleButton.innerHTML = collapsed
                ? '<i class="bi bi-layout-text-sidebar-reverse me-1" aria-hidden="true"></i>Expandir'
                : '<i class="bi bi-arrows-collapse-vertical me-1" aria-hidden="true"></i>Compactar';
            try {
                window.localStorage.setItem(FOCUSBAR_STORAGE_KEY, collapsed ? '1' : '0');
            } catch (_error) {
                // Ignorar storage no disponible.
            }
            window.requestAnimationFrame(syncStickyHeaderOffset);
        };

        let initialCollapsed = false;
        try {
            initialCollapsed = window.localStorage.getItem(FOCUSBAR_STORAGE_KEY) === '1';
        } catch (_error) {
            initialCollapsed = false;
        }

        setCollapsed(initialCollapsed);
        toggleButton.addEventListener('click', function () {
            setCollapsed(!focusBar.classList.contains('is-collapsed'));
        });
    }

    initFocusBar();
    markFront('init_focusbar');
    initYearLoading();
    markFront('init_year_loading');
    try {
        initFilters();
        markFront('init_filters');
        initMonthNavigator();
        markFront('init_month_navigator');
        syncStickyHeaderOffset();
        markFront('sync_sticky_once');
        finishInitialGridLoading();

        const defer = window.requestIdleCallback
            ? window.requestIdleCallback.bind(window)
            : function (cb) { window.setTimeout(cb, 0); };
        defer(function () {
            refreshTotalsRow();
            markFront('refresh_totals_deferred');
            initRowRedirectModal();
            markFront('init_row_redirect_deferred');
            flushFrontPerf();
        });
    } finally {
        // Loading ya se cierra en first paint.
    }
    window.addEventListener('resize', syncStickyHeaderOffset);
    window.addEventListener('load', syncStickyHeaderOffset);
    window.addEventListener('load', flushNavigationPerf);
    window.requestAnimationFrame(syncStickyHeaderOffset);
})();
</script>
</body>
</html>
