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
$returnDetailLocal = filter_input(INPUT_GET, 'detalle_local', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$returnDetailArrendatario = filter_input(INPUT_GET, 'detalle_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$returnDetailLocal = $returnDetailLocal !== false && $returnDetailLocal !== null ? (int) $returnDetailLocal : 0;
$returnDetailArrendatario = $returnDetailArrendatario !== false && $returnDetailArrendatario !== null
    ? (int) $returnDetailArrendatario
    : 0;
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
$electricityReadingsByTiendaMonth = [];
$gasReadingsByTiendaMonth = [];
$waterReadingsByTiendaMonth = [];
$reservaByTiendaMonth = [];
$reservaBreakdownByTiendaMonth = [];
$docStatusByTiendaMonth = [];
$docTotalByTiendaMonth = [];
$docIdByTiendaMonth = [];
$docNumberByTiendaMonth = [];
$arriendoNetoByTiendaMonth = [];
$operationalStateByTiendaMonth = [];
$clpFijoContratoByTienda = [];
$garantiaAplicadaByTiendaMonth = [];
$clpFijoFallbackByTiendaMonth = [];
$ufFallbackByTiendaMonth = [];
$rentSnapshotsByTiendaMonth = [];
$canCorrectElectricity = msp2CurrentUserHasPermission('MSP Operacion', 'escritura')
    && msp2TableExists($conn, 'msp_correcciones')
    && msp2TableExists($conn, 'msp_correcciones_impactos');
$canCorrectGas = $canCorrectElectricity;
$canCorrectWater = $canCorrectElectricity;
$canCorrectRent = $canCorrectElectricity;

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

    if ($canCorrectElectricity
        && $canLoadServicios
        && msp2TableExists($conn, 'msp_proceso_cobro_luz')
        && msp2TableExists($conn, 'msp_documentos_cobro')
        && msp2TableExists($conn, 'msp_documentos_cobro_detalle')) {
        $dependenciasProtegidasSql = [];
        $dependenciasDocumento = [
            'msp_pagos' => 'id_documento_cobro',
            'msp_saldo_favor_periodo_aplicaciones' => 'id_documento_cobro',
            'msp_garantia_documento_aplicaciones' => 'id_documento_cobro',
            'msp_movimientos_garantia' => 'id_documento_cobro',
            'msp_envio_lote_documentos' => 'id_documento_cobro',
            'msp_pago_contrato_operacion_detalle' => 'id_documento_cobro',
            'msp_pago_contrato_archivos' => 'id_documento_cobro',
        ];
        foreach ($dependenciasDocumento as $tablaDependencia => $columnaDependencia) {
            if (!msp2TableExists($conn, $tablaDependencia)
                || !msp2ColumnExists($conn, $tablaDependencia, $columnaDependencia)) {
                continue;
            }
            $dependenciasProtegidasSql[] = 'EXISTS (SELECT 1 FROM dbo.' . $tablaDependencia
                . ' dep WHERE dep.' . $columnaDependencia . '=doc.id_documento_cobro)';
        }
        $proteccionSql = $dependenciasProtegidasSql === []
            ? 'CAST(0 AS BIT)'
            : 'CAST(CASE WHEN ' . implode(' OR ', $dependenciasProtegidasSql) . ' THEN 1 ELSE 0 END AS BIT)';
        $asientoSql = msp2TableExists($conn, 'msp_acc_asientos')
            ? "CAST(CASE WHEN EXISTS (SELECT 1 FROM dbo.msp_acc_asientos acc WHERE acc.tabla_origen=N'msp_documentos_cobro' AND acc.id_origen=doc.id_documento_cobro AND acc.estado_asiento=1) THEN 1 ELSE 0 END AS BIT)"
            : 'CAST(0 AS BIT)';

        $lecturasElectricidadStmt = $conn->prepare(
            "SELECT
                COALESCE(doc.id_tienda, contrato_periodo.id_tienda) AS id_tienda,
                COALESCE(doc.id_contrato_arriendo, contrato_periodo.id_contrato_arriendo) AS id_contrato_arriendo,
                lm.id_lectura,lm.id_medidor,m.id_local,l.cdo_local,m.codigo_medidor,
                CONVERT(char(7),cm.periodo_facturacion,126) AS periodo_ym,
                lm.lectura_anterior,lm.lectura_actual,
                COALESCE(cs.consumo_cobrado,lm.consumo_informado,lm.lectura_actual-ISNULL(lm.lectura_anterior,0)) AS consumo,
                pl.valor_kwh,cs.id_cobro_servicio,cs.monto_total AS monto_cobro,
                doc.id_documento_cobro,doc.numero_documento,doc.monto_documento,
                doc.saldo_pendiente,doc.estado_documento,cm.estado_cierre,
                siguiente.id_lectura AS id_lectura_siguiente,
                siguiente.lectura_actual AS lectura_siguiente,
                $proteccionSql AS tiene_dependencias_protegidas,
                $asientoSql AS tiene_asiento_activo
             FROM dbo.msp_lecturas_medidores lm
             INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
             INNER JOIN dbo.msp_locales l ON l.id_local=m.id_local
             INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro=lm.id_proceso_cobro
             INNER JOIN dbo.msp_cierre_mensual cm ON cm.id_cierre_mensual=p.id_cierre_mensual
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=p.id_tipo_servicio
             INNER JOIN dbo.msp_proceso_cobro_luz pl ON pl.id_proceso_cobro=p.id_proceso_cobro
             LEFT JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
             OUTER APPLY (
                SELECT TOP(1) dc.id_documento_cobro,dc.id_tienda,dc.id_contrato_arriendo,
                    dc.numero_documento,dc.monto_total AS monto_documento,
                    dc.saldo_pendiente,dc.estado_documento
                FROM dbo.msp_documentos_cobro_detalle dcd
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=dcd.id_documento_cobro
                WHERE dcd.id_cobro_servicio=cs.id_cobro_servicio
                ORDER BY CASE WHEN dc.estado_documento=5 THEN 1 ELSE 0 END,dc.id_documento_cobro DESC
             ) doc
             OUTER APPLY (
                SELECT TOP(1) ca.id_tienda,ca.id_contrato_arriendo
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca ON ca.id_contrato_arriendo=cl.id_contrato_arriendo
                WHERE cl.id_local=m.id_local
                  AND cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio<=EOMONTH(cm.periodo_facturacion)
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino>=cm.periodo_facturacion)
                  AND ca.fecha_inicio<=EOMONTH(cm.periodo_facturacion)
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva>=cm.periodo_facturacion)
                  AND ca.estado_contrato IN (1,2,3,4)
                ORDER BY ca.fecha_inicio DESC,ca.id_contrato_arriendo DESC
             ) contrato_periodo
             OUTER APPLY (
                SELECT TOP(1) lm_sig.id_lectura,lm_sig.lectura_actual
                FROM dbo.msp_lecturas_medidores lm_sig
                WHERE lm_sig.id_medidor=lm.id_medidor
                  AND lm_sig.periodo_facturacion>lm.periodo_facturacion
                ORDER BY lm_sig.periodo_facturacion,lm_sig.id_lectura
             ) siguiente
             WHERE YEAR(cm.periodo_facturacion)=:anio_lecturas
               AND UPPER(ts.codigo_servicio)=N'LUZ'
               AND COALESCE(doc.id_tienda,contrato_periodo.id_tienda) IS NOT NULL
             ORDER BY cm.periodo_facturacion,l.cdo_local,m.codigo_medidor,lm.id_lectura"
        );
        $lecturasElectricidadStmt->bindValue(':anio_lecturas', $selectedYear, PDO::PARAM_INT);
        $lecturasElectricidadStmt->execute();
        while (($lecturaElectricidad = $lecturasElectricidadStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $idTiendaLectura = (int) ($lecturaElectricidad['id_tienda'] ?? 0);
            $periodoLectura = trim((string) ($lecturaElectricidad['periodo_ym'] ?? ''));
            $idDocumentoLectura = (int) ($lecturaElectricidad['id_documento_cobro'] ?? 0);
            if ($idTiendaLectura <= 0 || $periodoLectura === '' || !isset($months[$periodoLectura])) {
                continue;
            }
            $nivelCorreccion = 'EDICION_SIMPLE';
            if ($idDocumentoLectura > 0) {
                if ((int) ($lecturaElectricidad['estado_documento'] ?? 0) === 5) {
                    $nivelCorreccion = 'REVISION';
                } elseif ((int) ($lecturaElectricidad['tiene_dependencias_protegidas'] ?? 0) === 1) {
                    $nivelCorreccion = 'AJUSTE_FINANCIERO';
                } elseif ((int) ($lecturaElectricidad['tiene_asiento_activo'] ?? 0) === 1) {
                    $nivelCorreccion = 'AUTORIZACION';
                } else {
                    $nivelCorreccion = 'REGENERACION_CONTROLADA';
                }
            } elseif (in_array((int) ($lecturaElectricidad['estado_cierre'] ?? 0), [3, 5], true)) {
                // Una lectura de un período cerrado nunca se modifica como edición simple,
                // aunque aún no exista un documento asociado.
                $nivelCorreccion = 'AUTORIZACION';
            }
            $puedeAplicar = in_array($nivelCorreccion, ['EDICION_SIMPLE','REGENERACION_CONTROLADA'], true)
                || ($nivelCorreccion === 'AUTORIZACION'
                    && (msp2CurrentUserHasPermission('MSP Cierre Mensual', 'escritura')
                        || msp2CurrentUserHasPermission('MSP Configuracion', 'escritura')));
            $payloadLectura = [
                'id_lectura' => (int) ($lecturaElectricidad['id_lectura'] ?? 0),
                'id_contrato_arriendo' => (int) ($lecturaElectricidad['id_contrato_arriendo'] ?? 0),
                'id_local' => (int) ($lecturaElectricidad['id_local'] ?? 0),
                'local' => (string) ($lecturaElectricidad['cdo_local'] ?? ''),
                'medidor' => (string) ($lecturaElectricidad['codigo_medidor'] ?? ''),
                'periodo' => $periodoLectura,
                'lectura_anterior' => round((float) ($lecturaElectricidad['lectura_anterior'] ?? 0), 4),
                'lectura_actual' => round((float) ($lecturaElectricidad['lectura_actual'] ?? 0), 4),
                'consumo' => round((float) ($lecturaElectricidad['consumo'] ?? 0), 4),
                'valor_kwh' => round((float) ($lecturaElectricidad['valor_kwh'] ?? 0), 6),
                'monto' => round((float) ($lecturaElectricidad['monto_cobro'] ?? 0), 2),
                'id_documento' => $idDocumentoLectura,
                'numero_documento' => (string) ($lecturaElectricidad['numero_documento'] ?? ''),
                'monto_documento' => round((float) ($lecturaElectricidad['monto_documento'] ?? 0), 2),
                'saldo_documento' => round((float) ($lecturaElectricidad['saldo_pendiente'] ?? 0), 2),
                'estado_documento' => (int) ($lecturaElectricidad['estado_documento'] ?? 0),
                'estado_cierre' => (int) ($lecturaElectricidad['estado_cierre'] ?? 0),
                'lectura_siguiente' => $lecturaElectricidad['lectura_siguiente'] !== null
                    ? round((float) $lecturaElectricidad['lectura_siguiente'], 4)
                    : null,
                'nivel' => $nivelCorreccion,
                'puede_aplicar' => $puedeAplicar,
            ];
            $electricityReadingsByTiendaMonth[$idTiendaLectura][$periodoLectura][] = $payloadLectura;
        }
    }
    $perfMark('carga_lecturas_electricidad_corregibles');

    if ($canCorrectGas
        && $canLoadServicios
        && msp2TableExists($conn, 'msp_proceso_cobro_gas')
        && msp2TableExists($conn, 'msp_documentos_cobro')
        && msp2TableExists($conn, 'msp_documentos_cobro_detalle')) {
        $dependenciasGasSql = [];
        $dependenciasDocumentoGas = [
            'msp_pagos' => 'id_documento_cobro',
            'msp_saldo_favor_periodo_aplicaciones' => 'id_documento_cobro',
            'msp_garantia_documento_aplicaciones' => 'id_documento_cobro',
            'msp_movimientos_garantia' => 'id_documento_cobro',
            'msp_envio_lote_documentos' => 'id_documento_cobro',
            'msp_pago_contrato_operacion_detalle' => 'id_documento_cobro',
            'msp_pago_contrato_archivos' => 'id_documento_cobro',
        ];
        foreach ($dependenciasDocumentoGas as $tablaDependencia => $columnaDependencia) {
            if (!msp2TableExists($conn, $tablaDependencia)
                || !msp2ColumnExists($conn, $tablaDependencia, $columnaDependencia)) {
                continue;
            }
            $dependenciasGasSql[] = 'EXISTS (SELECT 1 FROM dbo.' . $tablaDependencia
                . ' dep WHERE dep.' . $columnaDependencia . '=doc.id_documento_cobro)';
        }
        $proteccionGasSql = $dependenciasGasSql === []
            ? 'CAST(0 AS BIT)'
            : 'CAST(CASE WHEN ' . implode(' OR ', $dependenciasGasSql) . ' THEN 1 ELSE 0 END AS BIT)';
        $asientoGasSql = msp2TableExists($conn, 'msp_acc_asientos')
            ? "CAST(CASE WHEN EXISTS (SELECT 1 FROM dbo.msp_acc_asientos acc WHERE acc.tabla_origen=N'msp_documentos_cobro' AND acc.id_origen=doc.id_documento_cobro AND acc.estado_asiento=1) THEN 1 ELSE 0 END AS BIT)"
            : 'CAST(0 AS BIT)';

        $lecturasGasStmt = $conn->prepare(
            "SELECT
                COALESCE(doc.id_tienda, contrato_periodo.id_tienda) AS id_tienda,
                COALESCE(doc.id_contrato_arriendo, contrato_periodo.id_contrato_arriendo) AS id_contrato_arriendo,
                lm.id_lectura,lm.id_medidor,m.id_local,l.cdo_local,m.codigo_medidor,
                CONVERT(char(7),cm.periodo_facturacion,126) AS periodo_ym,
                lm.lectura_anterior,lm.lectura_actual,
                COALESCE(cs.consumo_cobrado,lm.consumo_informado,lm.lectura_actual-ISNULL(lm.lectura_anterior,0)) AS consumo,
                pg.factor,pg.valor_litro,cs.id_cobro_servicio,cs.monto_total AS monto_cobro,
                doc.id_documento_cobro,doc.numero_documento,doc.monto_documento,
                doc.saldo_pendiente,doc.estado_documento,cm.estado_cierre,
                siguiente.id_lectura AS id_lectura_siguiente,
                siguiente.lectura_actual AS lectura_siguiente,
                $proteccionGasSql AS tiene_dependencias_protegidas,
                $asientoGasSql AS tiene_asiento_activo
             FROM dbo.msp_lecturas_medidores lm
             INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
             INNER JOIN dbo.msp_locales l ON l.id_local=m.id_local
             INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro=lm.id_proceso_cobro
             INNER JOIN dbo.msp_cierre_mensual cm ON cm.id_cierre_mensual=p.id_cierre_mensual
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=p.id_tipo_servicio
             INNER JOIN dbo.msp_proceso_cobro_gas pg ON pg.id_proceso_cobro=p.id_proceso_cobro
             LEFT JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
             OUTER APPLY (
                SELECT TOP(1) dc.id_documento_cobro,dc.id_tienda,dc.id_contrato_arriendo,
                    dc.numero_documento,dc.monto_total AS monto_documento,
                    dc.saldo_pendiente,dc.estado_documento
                FROM dbo.msp_documentos_cobro_detalle dcd
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=dcd.id_documento_cobro
                WHERE dcd.id_cobro_servicio=cs.id_cobro_servicio
                ORDER BY CASE WHEN dc.estado_documento=5 THEN 1 ELSE 0 END,dc.id_documento_cobro DESC
             ) doc
             OUTER APPLY (
                SELECT TOP(1) ca.id_tienda,ca.id_contrato_arriendo
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca ON ca.id_contrato_arriendo=cl.id_contrato_arriendo
                WHERE cl.id_local=m.id_local
                  AND cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio<=EOMONTH(cm.periodo_facturacion)
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino>=cm.periodo_facturacion)
                  AND ca.fecha_inicio<=EOMONTH(cm.periodo_facturacion)
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva>=cm.periodo_facturacion)
                  AND ca.estado_contrato IN (1,2,3,4)
                ORDER BY ca.fecha_inicio DESC,ca.id_contrato_arriendo DESC
             ) contrato_periodo
             OUTER APPLY (
                SELECT TOP(1) lm_sig.id_lectura,lm_sig.lectura_actual
                FROM dbo.msp_lecturas_medidores lm_sig
                WHERE lm_sig.id_medidor=lm.id_medidor
                  AND lm_sig.periodo_facturacion>lm.periodo_facturacion
                ORDER BY lm_sig.periodo_facturacion,lm_sig.id_lectura
             ) siguiente
             WHERE YEAR(cm.periodo_facturacion)=:anio_lecturas_gas
               AND UPPER(ts.codigo_servicio)=N'GAS'
               AND COALESCE(doc.id_tienda,contrato_periodo.id_tienda) IS NOT NULL
             ORDER BY cm.periodo_facturacion,l.cdo_local,m.codigo_medidor,lm.id_lectura"
        );
        $lecturasGasStmt->bindValue(':anio_lecturas_gas', $selectedYear, PDO::PARAM_INT);
        $lecturasGasStmt->execute();
        while (($lecturaGas = $lecturasGasStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $idTiendaLectura = (int) ($lecturaGas['id_tienda'] ?? 0);
            $periodoLectura = trim((string) ($lecturaGas['periodo_ym'] ?? ''));
            $idDocumentoLectura = (int) ($lecturaGas['id_documento_cobro'] ?? 0);
            if ($idTiendaLectura <= 0 || $periodoLectura === '' || !isset($months[$periodoLectura])) {
                continue;
            }
            $nivelCorreccion = 'EDICION_SIMPLE';
            if ($idDocumentoLectura > 0) {
                if ((int) ($lecturaGas['estado_documento'] ?? 0) === 5) {
                    $nivelCorreccion = 'REVISION';
                } elseif ((int) ($lecturaGas['tiene_dependencias_protegidas'] ?? 0) === 1) {
                    $nivelCorreccion = 'AJUSTE_FINANCIERO';
                } elseif ((int) ($lecturaGas['tiene_asiento_activo'] ?? 0) === 1) {
                    $nivelCorreccion = 'AUTORIZACION';
                } else {
                    $nivelCorreccion = 'REGENERACION_CONTROLADA';
                }
            } elseif (in_array((int) ($lecturaGas['estado_cierre'] ?? 0), [3, 5], true)) {
                $nivelCorreccion = 'AUTORIZACION';
            }
            $puedeAplicar = in_array($nivelCorreccion, ['EDICION_SIMPLE','REGENERACION_CONTROLADA'], true)
                || ($nivelCorreccion === 'AUTORIZACION'
                    && (msp2CurrentUserHasPermission('MSP Cierre Mensual', 'escritura')
                        || msp2CurrentUserHasPermission('MSP Configuracion', 'escritura')));
            $gasReadingsByTiendaMonth[$idTiendaLectura][$periodoLectura][] = [
                'id_lectura' => (int) ($lecturaGas['id_lectura'] ?? 0),
                'id_contrato_arriendo' => (int) ($lecturaGas['id_contrato_arriendo'] ?? 0),
                'id_local' => (int) ($lecturaGas['id_local'] ?? 0),
                'local' => (string) ($lecturaGas['cdo_local'] ?? ''),
                'medidor' => (string) ($lecturaGas['codigo_medidor'] ?? ''),
                'periodo' => $periodoLectura,
                'lectura_anterior' => round((float) ($lecturaGas['lectura_anterior'] ?? 0), 4),
                'lectura_actual' => round((float) ($lecturaGas['lectura_actual'] ?? 0), 4),
                'consumo' => round((float) ($lecturaGas['consumo'] ?? 0), 4),
                'factor' => round((float) ($lecturaGas['factor'] ?? 0), 6),
                'valor_litro' => round((float) ($lecturaGas['valor_litro'] ?? 0), 6),
                'monto' => round((float) ($lecturaGas['monto_cobro'] ?? 0), 2),
                'id_documento' => $idDocumentoLectura,
                'numero_documento' => (string) ($lecturaGas['numero_documento'] ?? ''),
                'monto_documento' => round((float) ($lecturaGas['monto_documento'] ?? 0), 2),
                'saldo_documento' => round((float) ($lecturaGas['saldo_pendiente'] ?? 0), 2),
                'estado_documento' => (int) ($lecturaGas['estado_documento'] ?? 0),
                'estado_cierre' => (int) ($lecturaGas['estado_cierre'] ?? 0),
                'lectura_siguiente' => $lecturaGas['lectura_siguiente'] !== null
                    ? round((float) $lecturaGas['lectura_siguiente'], 4)
                    : null,
                'nivel' => $nivelCorreccion,
                'puede_aplicar' => $puedeAplicar,
            ];
        }
    }
    $perfMark('carga_lecturas_gas_corregibles');

    if ($canCorrectWater
        && $canLoadServicios
        && msp2TableExists($conn, 'msp_proceso_cobro_agua')
        && msp2TableExists($conn, 'msp_documentos_cobro')
        && msp2TableExists($conn, 'msp_documentos_cobro_detalle')) {
        $dependenciasAguaSql = [];
        $dependenciasDocumentoAgua = [
            'msp_pagos' => 'id_documento_cobro',
            'msp_saldo_favor_periodo_aplicaciones' => 'id_documento_cobro',
            'msp_garantia_documento_aplicaciones' => 'id_documento_cobro',
            'msp_movimientos_garantia' => 'id_documento_cobro',
            'msp_envio_lote_documentos' => 'id_documento_cobro',
            'msp_pago_contrato_operacion_detalle' => 'id_documento_cobro',
            'msp_pago_contrato_archivos' => 'id_documento_cobro',
        ];
        foreach ($dependenciasDocumentoAgua as $tablaDependencia => $columnaDependencia) {
            if (!msp2TableExists($conn, $tablaDependencia)
                || !msp2ColumnExists($conn, $tablaDependencia, $columnaDependencia)) {
                continue;
            }
            $dependenciasAguaSql[] = 'EXISTS (SELECT 1 FROM dbo.' . $tablaDependencia
                . ' dep WHERE dep.' . $columnaDependencia . '=doc.id_documento_cobro)';
        }
        $proteccionAguaSql = $dependenciasAguaSql === []
            ? 'CAST(0 AS BIT)'
            : 'CAST(CASE WHEN ' . implode(' OR ', $dependenciasAguaSql) . ' THEN 1 ELSE 0 END AS BIT)';
        $asientoAguaSql = msp2TableExists($conn, 'msp_acc_asientos')
            ? "CAST(CASE WHEN EXISTS (SELECT 1 FROM dbo.msp_acc_asientos acc WHERE acc.tabla_origen=N'msp_documentos_cobro' AND acc.id_origen=doc.id_documento_cobro AND acc.estado_asiento=1) THEN 1 ELSE 0 END AS BIT)"
            : 'CAST(0 AS BIT)';

        $lecturasAguaStmt = $conn->prepare(
            "SELECT
                COALESCE(doc.id_tienda, contrato_consumo.id_tienda) AS id_tienda,
                COALESCE(doc.id_contrato_arriendo, contrato_consumo.id_contrato_arriendo) AS id_contrato_arriendo,
                lm.id_lectura,lm.id_medidor,m.id_local,l.cdo_local,m.codigo_medidor,
                CONVERT(char(7),cm.periodo_facturacion,126) AS periodo_ym,
                CONVERT(char(10),lm.fecha_desde_consumo,23) AS fecha_desde_consumo,
                CONVERT(char(10),lm.fecha_hasta_consumo,23) AS fecha_hasta_consumo,
                lm.lectura_anterior,lm.lectura_actual,
                COALESCE(cs.consumo_cobrado,lm.consumo_informado,lm.lectura_actual-ISNULL(lm.lectura_anterior,0)) AS consumo,
                pa.servicio_agua_potable,pa.servicio_alcantarillado,pa.tratamiento_aguas_servidas,
                pa.divisor,pa.cargo_fijo AS cargo_fijo_parametro,
                cs.id_cobro_servicio,cs.subtotal_variable,cs.cargo_fijo AS cargo_fijo_cobro,
                cs.monto_total AS monto_cobro,
                doc.id_documento_cobro,doc.numero_documento,doc.monto_documento,
                doc.saldo_pendiente,doc.estado_documento,cm.estado_cierre,
                siguiente.id_lectura AS id_lectura_siguiente,
                siguiente.lectura_actual AS lectura_siguiente,
                $proteccionAguaSql AS tiene_dependencias_protegidas,
                $asientoAguaSql AS tiene_asiento_activo
             FROM dbo.msp_lecturas_medidores lm
             INNER JOIN dbo.msp_medidores m ON m.id_medidor=lm.id_medidor
             INNER JOIN dbo.msp_locales l ON l.id_local=m.id_local
             INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro=lm.id_proceso_cobro
             INNER JOIN dbo.msp_cierre_mensual cm ON cm.id_cierre_mensual=p.id_cierre_mensual
             INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=p.id_tipo_servicio
             INNER JOIN dbo.msp_proceso_cobro_agua pa ON pa.id_proceso_cobro=p.id_proceso_cobro
             LEFT JOIN dbo.msp_cobros_servicios cs ON cs.id_lectura=lm.id_lectura
             OUTER APPLY (
                SELECT TOP(1) dc.id_documento_cobro,dc.id_tienda,dc.id_contrato_arriendo,
                    dc.numero_documento,dc.monto_total AS monto_documento,
                    dc.saldo_pendiente,dc.estado_documento
                FROM dbo.msp_documentos_cobro_detalle dcd
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=dcd.id_documento_cobro
                WHERE dcd.id_cobro_servicio=cs.id_cobro_servicio
                ORDER BY CASE WHEN dc.estado_documento=5 THEN 1 ELSE 0 END,dc.id_documento_cobro DESC
             ) doc
             OUTER APPLY (
                SELECT TOP(1) ca.id_tienda,ca.id_contrato_arriendo
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca ON ca.id_contrato_arriendo=cl.id_contrato_arriendo
                WHERE cl.id_local=m.id_local
                  AND cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio<=COALESCE(lm.fecha_hasta_consumo,lm.fecha_lectura,EOMONTH(cm.periodo_facturacion))
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino>=COALESCE(lm.fecha_hasta_consumo,lm.fecha_lectura,cm.periodo_facturacion))
                  AND ca.fecha_inicio<=COALESCE(lm.fecha_hasta_consumo,lm.fecha_lectura,EOMONTH(cm.periodo_facturacion))
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva>=COALESCE(lm.fecha_hasta_consumo,lm.fecha_lectura,cm.periodo_facturacion))
                  AND ca.estado_contrato IN (1,2,3,4)
                ORDER BY ca.fecha_inicio DESC,ca.id_contrato_arriendo DESC
             ) contrato_consumo
             OUTER APPLY (
                SELECT TOP(1) lm_sig.id_lectura,lm_sig.lectura_actual
                FROM dbo.msp_lecturas_medidores lm_sig
                WHERE lm_sig.id_medidor=lm.id_medidor
                  AND (
                    COALESCE(lm_sig.fecha_hasta_consumo,lm_sig.fecha_lectura,lm_sig.periodo_facturacion)
                        > COALESCE(lm.fecha_hasta_consumo,lm.fecha_lectura,lm.periodo_facturacion)
                    OR (
                        COALESCE(lm_sig.fecha_hasta_consumo,lm_sig.fecha_lectura,lm_sig.periodo_facturacion)
                            = COALESCE(lm.fecha_hasta_consumo,lm.fecha_lectura,lm.periodo_facturacion)
                        AND lm_sig.id_lectura>lm.id_lectura
                    )
                  )
                ORDER BY COALESCE(lm_sig.fecha_hasta_consumo,lm_sig.fecha_lectura,lm_sig.periodo_facturacion),
                    lm_sig.periodo_facturacion,lm_sig.id_lectura
             ) siguiente
             WHERE YEAR(cm.periodo_facturacion)=:anio_lecturas_agua
               AND UPPER(ts.codigo_servicio)=N'AGUA'
               AND COALESCE(doc.id_tienda,contrato_consumo.id_tienda) IS NOT NULL
             ORDER BY cm.periodo_facturacion,l.cdo_local,m.codigo_medidor,lm.id_lectura"
        );
        $lecturasAguaStmt->bindValue(':anio_lecturas_agua', $selectedYear, PDO::PARAM_INT);
        $lecturasAguaStmt->execute();
        while (($lecturaAgua = $lecturasAguaStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $idTiendaLectura = (int) ($lecturaAgua['id_tienda'] ?? 0);
            $periodoLectura = trim((string) ($lecturaAgua['periodo_ym'] ?? ''));
            $idDocumentoLectura = (int) ($lecturaAgua['id_documento_cobro'] ?? 0);
            if ($idTiendaLectura <= 0 || $periodoLectura === '' || !isset($months[$periodoLectura])) {
                continue;
            }
            $nivelCorreccion = 'EDICION_SIMPLE';
            if ($idDocumentoLectura > 0) {
                if ((int) ($lecturaAgua['estado_documento'] ?? 0) === 5) {
                    $nivelCorreccion = 'REVISION';
                } elseif ((int) ($lecturaAgua['tiene_dependencias_protegidas'] ?? 0) === 1) {
                    $nivelCorreccion = 'AJUSTE_FINANCIERO';
                } elseif ((int) ($lecturaAgua['tiene_asiento_activo'] ?? 0) === 1) {
                    $nivelCorreccion = 'AUTORIZACION';
                } else {
                    $nivelCorreccion = 'REGENERACION_CONTROLADA';
                }
            } elseif (in_array((int) ($lecturaAgua['estado_cierre'] ?? 0), [3, 5], true)) {
                $nivelCorreccion = 'AUTORIZACION';
            }
            $puedeAplicar = in_array($nivelCorreccion, ['EDICION_SIMPLE','REGENERACION_CONTROLADA'], true)
                || ($nivelCorreccion === 'AUTORIZACION'
                    && (msp2CurrentUserHasPermission('MSP Cierre Mensual', 'escritura')
                        || msp2CurrentUserHasPermission('MSP Configuracion', 'escritura')));
            $divisorAgua = (float) ($lecturaAgua['divisor'] ?? 0);
            $tarifaVariableAgua = $divisorAgua > 0
                ? (
                    (float) ($lecturaAgua['servicio_agua_potable'] ?? 0)
                    + (float) ($lecturaAgua['servicio_alcantarillado'] ?? 0)
                    + (float) ($lecturaAgua['tratamiento_aguas_servidas'] ?? 0)
                ) / $divisorAgua
                : 0.0;
            $waterReadingsByTiendaMonth[$idTiendaLectura][$periodoLectura][] = [
                'id_lectura' => (int) ($lecturaAgua['id_lectura'] ?? 0),
                'id_contrato_arriendo' => (int) ($lecturaAgua['id_contrato_arriendo'] ?? 0),
                'id_local' => (int) ($lecturaAgua['id_local'] ?? 0),
                'local' => (string) ($lecturaAgua['cdo_local'] ?? ''),
                'medidor' => (string) ($lecturaAgua['codigo_medidor'] ?? ''),
                'periodo' => $periodoLectura,
                'periodo_consumo_desde' => (string) ($lecturaAgua['fecha_desde_consumo'] ?? ''),
                'periodo_consumo_hasta' => (string) ($lecturaAgua['fecha_hasta_consumo'] ?? ''),
                'lectura_anterior' => round((float) ($lecturaAgua['lectura_anterior'] ?? 0), 4),
                'lectura_actual' => round((float) ($lecturaAgua['lectura_actual'] ?? 0), 4),
                'consumo' => round((float) ($lecturaAgua['consumo'] ?? 0), 4),
                'servicio_agua_potable' => round((float) ($lecturaAgua['servicio_agua_potable'] ?? 0), 2),
                'servicio_alcantarillado' => round((float) ($lecturaAgua['servicio_alcantarillado'] ?? 0), 2),
                'tratamiento_aguas_servidas' => round((float) ($lecturaAgua['tratamiento_aguas_servidas'] ?? 0), 2),
                'divisor' => round($divisorAgua, 6),
                'tarifa_variable' => round($tarifaVariableAgua, 6),
                'subtotal_variable' => round((float) ($lecturaAgua['subtotal_variable'] ?? 0), 2),
                'cargo_fijo' => round((float) ($lecturaAgua['cargo_fijo_parametro'] ?? 0), 2),
                'monto' => round((float) ($lecturaAgua['monto_cobro'] ?? 0), 2),
                'id_documento' => $idDocumentoLectura,
                'numero_documento' => (string) ($lecturaAgua['numero_documento'] ?? ''),
                'monto_documento' => round((float) ($lecturaAgua['monto_documento'] ?? 0), 2),
                'saldo_documento' => round((float) ($lecturaAgua['saldo_pendiente'] ?? 0), 2),
                'estado_documento' => (int) ($lecturaAgua['estado_documento'] ?? 0),
                'estado_cierre' => (int) ($lecturaAgua['estado_cierre'] ?? 0),
                'lectura_siguiente' => $lecturaAgua['lectura_siguiente'] !== null
                    ? round((float) $lecturaAgua['lectura_siguiente'], 4)
                    : null,
                'nivel' => $nivelCorreccion,
                'puede_aplicar' => $puedeAplicar,
            ];
        }
    }
    $perfMark('carga_lecturas_agua_corregibles');

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

        if ($canCorrectRent
            && msp2TableExists($conn, 'msp_contrato_locales')
            && msp2TableExists($conn, 'msp_locales')
            && msp2TableExists($conn, 'msp_contratos_arriendo')
            && msp2TableExists($conn, 'msp_tipo_modalidad_arriendo')
            && msp2TableExists($conn, 'msp_documentos_cobro')
            && msp2TableExists($conn, 'msp_cierre_mensual')) {
            $dependenciasRentSql = [];
            $dependenciasDocumentoRent = [
                'msp_pagos' => 'id_documento_cobro',
                'msp_saldo_favor_periodo_aplicaciones' => 'id_documento_cobro',
                'msp_garantia_documento_aplicaciones' => 'id_documento_cobro',
                'msp_movimientos_garantia' => 'id_documento_cobro',
                'msp_envio_lote_documentos' => 'id_documento_cobro',
                'msp_pago_contrato_operacion_detalle' => 'id_documento_cobro',
                'msp_pago_contrato_archivos' => 'id_documento_cobro',
            ];
            foreach ($dependenciasDocumentoRent as $tablaDependencia => $columnaDependencia) {
                if (!msp2TableExists($conn, $tablaDependencia)
                    || !msp2ColumnExists($conn, $tablaDependencia, $columnaDependencia)) {
                    continue;
                }
                $dependenciasRentSql[] = 'EXISTS (SELECT 1 FROM dbo.' . $tablaDependencia
                    . ' dep WHERE dep.' . $columnaDependencia . '=doc.id_documento_cobro)';
            }
            $proteccionRentSql = $dependenciasRentSql === []
                ? 'CAST(0 AS BIT)'
                : 'CAST(CASE WHEN ' . implode(' OR ', $dependenciasRentSql) . ' THEN 1 ELSE 0 END AS BIT)';
            $asientoRentSql = msp2TableExists($conn, 'msp_acc_asientos')
                ? "CAST(CASE WHEN EXISTS (SELECT 1 FROM dbo.msp_acc_asientos acc WHERE acc.tabla_origen=N'msp_documentos_cobro' AND acc.id_origen=doc.id_documento_cobro AND acc.estado_asiento=1) THEN 1 ELSE 0 END AS BIT)"
                : 'CAST(0 AS BIT)';
            $rentSnapshotStmt = $conn->prepare(
                "SELECT
                    s.id_snapshot_arriendo,
                    s.id_tienda,
                    s.id_contrato_arriendo,
                    s.id_contrato_local,
                    s.id_local,
                    CONVERT(CHAR(7), s.periodo_facturacion, 126) AS periodo_ym,
                    l.cdo_local,
                    ca.id_arrendatario,
                    tm.codigo_modalidad,
                    s.valor_base_uf,
                    s.valor_uf_periodo,
                    s.monto_neto_clp,
                    s.monto_iva_clp,
                    s.monto_total_clp,
                    s.estado_snapshot,
                    cm.valor_uf AS valor_uf_cierre,
                    cm.estado_cierre,
                    doc.id_documento_cobro,
                    doc.numero_documento,
                    doc.estado_documento,
                    doc.monto_documento,
                    doc.saldo_pendiente,
                    $proteccionRentSql AS tiene_dependencias_protegidas,
                    $asientoRentSql AS tiene_asiento_activo
                 FROM dbo.msp_arriendo_local_snapshot_periodo s
                 INNER JOIN dbo.msp_contrato_locales cl
                    ON cl.id_contrato_local = s.id_contrato_local
                   AND cl.id_contrato_arriendo = s.id_contrato_arriendo
                   AND cl.id_local = s.id_local
                 INNER JOIN dbo.msp_locales l
                    ON l.id_local = s.id_local
                 INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = s.id_contrato_arriendo
                 INNER JOIN dbo.msp_tipo_modalidad_arriendo tm
                    ON tm.id_modalidad_arriendo = s.id_modalidad_aplicada
                 LEFT JOIN dbo.msp_cierre_mensual cm
                    ON cm.periodo_facturacion = s.periodo_facturacion
                 OUTER APPLY (
                    SELECT TOP (1) dc.id_documento_cobro, dc.numero_documento,
                        dc.estado_documento,dc.monto_total AS monto_documento,dc.saldo_pendiente
                    FROM dbo.msp_documentos_cobro dc
                    WHERE dc.id_contrato_arriendo = s.id_contrato_arriendo
                      AND dc.periodo_facturacion = s.periodo_facturacion
                      AND dc.estado_documento <> 5
                    ORDER BY dc.id_documento_cobro DESC
                 ) doc
                 WHERE YEAR(s.periodo_facturacion) = :anio_uf_edit
                   AND s.estado_snapshot IN (1,2,3)
                   AND tm.codigo_modalidad IN (N'UF_ESTATICO', N'DINAMICO_MENSUAL')
                 ORDER BY s.periodo_facturacion, " . msp2LocalCodeNaturalOrderSql('l.cdo_local') . ", s.id_snapshot_arriendo"
            );
            $rentSnapshotStmt->bindValue(':anio_uf_edit', $selectedYear, PDO::PARAM_INT);
            $rentSnapshotStmt->execute();
            while (($rentSnapshotRow = $rentSnapshotStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $idTiendaRent = (int) ($rentSnapshotRow['id_tienda'] ?? 0);
                $periodoRent = trim((string) ($rentSnapshotRow['periodo_ym'] ?? ''));
                $valorUfPeriodoRent = round((float) (($rentSnapshotRow['valor_uf_periodo'] ?? null)
                    ?: ($rentSnapshotRow['valor_uf_cierre'] ?? 0)), 6);
                $montoNetoRent = round((float) ($rentSnapshotRow['monto_neto_clp'] ?? 0), 2);
                $ufBaseRent = $valorUfPeriodoRent > 0
                    ? round($montoNetoRent / $valorUfPeriodoRent, 6)
                    : round((float) ($rentSnapshotRow['valor_base_uf'] ?? 0), 6);
                $idDocumentoRent = (int) ($rentSnapshotRow['id_documento_cobro'] ?? 0);
                $estadoCierreRent = (int) ($rentSnapshotRow['estado_cierre'] ?? 0);
                $nivelCorreccionRent = 'EDICION_SIMPLE';
                if ($idDocumentoRent > 0) {
                    if ((int) ($rentSnapshotRow['estado_documento'] ?? 0) === 5) {
                        $nivelCorreccionRent = 'REVISION';
                    } elseif ((int) ($rentSnapshotRow['tiene_dependencias_protegidas'] ?? 0) === 1) {
                        $nivelCorreccionRent = 'AJUSTE_FINANCIERO';
                    } elseif ((int) ($rentSnapshotRow['tiene_asiento_activo'] ?? 0) === 1
                        || in_array($estadoCierreRent, [3, 5], true)) {
                        $nivelCorreccionRent = 'AUTORIZACION';
                    } else {
                        $nivelCorreccionRent = 'REGENERACION_CONTROLADA';
                    }
                } elseif (in_array($estadoCierreRent, [3, 5], true)) {
                    $nivelCorreccionRent = 'AUTORIZACION';
                }
                $puedeAplicarRent = $valorUfPeriodoRent > 0
                    && (in_array($nivelCorreccionRent, ['EDICION_SIMPLE','REGENERACION_CONTROLADA'], true)
                        || ($nivelCorreccionRent === 'AUTORIZACION'
                            && (msp2CurrentUserHasPermission('MSP Cierre Mensual', 'escritura')
                                || msp2CurrentUserHasPermission('MSP Configuracion', 'escritura'))));
                if ($idTiendaRent <= 0 || $periodoRent === '' || !isset($months[$periodoRent])) {
                    continue;
                }
                $rentSnapshotsByTiendaMonth[$idTiendaRent][$periodoRent][] = [
                    'id_snapshot' => (int) ($rentSnapshotRow['id_snapshot_arriendo'] ?? 0),
                    'id_contrato' => (int) ($rentSnapshotRow['id_contrato_arriendo'] ?? 0),
                    'id_contrato_local' => (int) ($rentSnapshotRow['id_contrato_local'] ?? 0),
                    'id_local' => (int) ($rentSnapshotRow['id_local'] ?? 0),
                    'id_arrendatario' => (int) ($rentSnapshotRow['id_arrendatario'] ?? 0),
                    'local' => trim((string) ($rentSnapshotRow['cdo_local'] ?? '')),
                    'periodo' => $periodoRent,
                    'modalidad' => trim((string) ($rentSnapshotRow['codigo_modalidad'] ?? '')),
                    'uf_base' => $ufBaseRent,
                    'valor_uf_periodo' => $valorUfPeriodoRent,
                    'monto_neto' => $montoNetoRent,
                    'monto_iva' => round((float) ($rentSnapshotRow['monto_iva_clp'] ?? 0), 2),
                    'monto_total' => round((float) ($rentSnapshotRow['monto_total_clp'] ?? 0), 2),
                    'estado_cierre' => $estadoCierreRent,
                    'id_documento' => $idDocumentoRent,
                    'numero_documento' => trim((string) ($rentSnapshotRow['numero_documento'] ?? '')),
                    'monto_documento' => round((float) ($rentSnapshotRow['monto_documento'] ?? 0), 2),
                    'saldo_documento' => round((float) ($rentSnapshotRow['saldo_pendiente'] ?? 0), 2),
                    'nivel' => $nivelCorreccionRent,
                    'puede_aplicar' => $puedeAplicarRent,
                ];
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
        if (msp2TableExists($conn, 'msp_liquidacion_servicios')
            && msp2TableExists($conn, 'msp_liquidacion_servicio_consumos')) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_liquidacion_servicios ls_tardio
                INNER JOIN dbo.msp_contrato_locales cl_tardio
                    ON cl_tardio.id_contrato_local = ls_tardio.id_contrato_local
                LEFT JOIN dbo.msp_liquidacion_servicio_consumos c_tardio
                    ON c_tardio.id_liquidacion_servicio = ls_tardio.id_liquidacion_servicio
                   AND c_tardio.estado_consumo <> 3
                WHERE cl_tardio.id_contrato_arriendo = {$contratoAlias}.id_contrato_arriendo
                  AND (ls_tardio.estado_liquidacion IN (1,2)
                       OR YEAR(c_tardio.periodo_emision) = " . (int) $selectedYear . ")
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
        if (msp2TableExists($conn, 'msp_liquidacion_servicios')
            && msp2TableExists($conn, 'msp_liquidacion_servicio_consumos')) {
            $parts[] = "EXISTS (
                SELECT 1
                FROM dbo.msp_liquidacion_servicios ls_tardio
                LEFT JOIN dbo.msp_liquidacion_servicio_consumos c_tardio
                    ON c_tardio.id_liquidacion_servicio = ls_tardio.id_liquidacion_servicio
                   AND c_tardio.estado_consumo <> 3
                WHERE ls_tardio.id_contrato_local = {$contratoLocalAlias}.id_contrato_local
                  AND (ls_tardio.estado_liquidacion IN (1,2)
                       OR YEAR(c_tardio.periodo_emision) = " . (int) $selectedYear . ")
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

    if (msp2TableExists($conn, 'msp_vw_control_diario_base')) {
        $estadoOperativoStmt = $conn->prepare(
            "SELECT
                id_tienda,
                id_contrato_arriendo,
                id_arrendatario,
                CONVERT(char(7), periodo_facturacion, 126) AS periodo_ym,
                arrendatario,
                rut,
                vigente_arriendo,
                en_liquidacion,
                cerrado_financiero,
                tiene_pendientes,
                marca_termino
             FROM dbo.msp_vw_control_diario_base
             WHERE YEAR(periodo_facturacion) = :anio_estado
             ORDER BY
                id_tienda,
                periodo_facturacion,
                CASE WHEN vigente_arriendo = 1 THEN 0 WHEN en_liquidacion = 1 THEN 1 ELSE 2 END,
                id_contrato_arriendo DESC"
        );
        $estadoOperativoStmt->bindValue(':anio_estado', $selectedYear, PDO::PARAM_INT);
        $estadoOperativoStmt->execute();
        while (($estadoOperativoRow = $estadoOperativoStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $idTiendaEstado = (int) ($estadoOperativoRow['id_tienda'] ?? 0);
            $periodoEstado = trim((string) ($estadoOperativoRow['periodo_ym'] ?? ''));
            if ($idTiendaEstado <= 0 || $periodoEstado === '' || isset($operationalStateByTiendaMonth[$idTiendaEstado][$periodoEstado])) {
                continue;
            }
            $operationalStateByTiendaMonth[$idTiendaEstado][$periodoEstado] = $estadoOperativoRow;
        }
        $perfMark('estado_operativo_historico');
    }

    if (msp2TableExists($conn, 'msp_liquidacion_servicios')
        && msp2TableExists($conn, 'msp_liquidacion_servicio_consumos')) {
        $estadoTardioStmt = $conn->prepare(
            "SELECT DISTINCT
                ca.id_tienda,
                ca.id_contrato_arriendo,
                ca.id_arrendatario,
                CONVERT(char(7), c.periodo_emision, 126) AS periodo_ym,
                COALESCE(NULLIF(a.nombre_locatario, N''), NULLIF(a.nombre_representante, N''), N'Sin arrendatario') AS arrendatario,
                a.rut
             FROM dbo.msp_liquidacion_servicio_consumos c
             INNER JOIN dbo.msp_liquidacion_servicios ls
                ON ls.id_liquidacion_servicio = c.id_liquidacion_servicio
             INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_contrato_local = ls.id_contrato_local
             INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
             INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = ca.id_arrendatario
             WHERE YEAR(c.periodo_emision) = :anio_tardio
               AND c.estado_consumo IN (1,2)"
        );
        $estadoTardioStmt->bindValue(':anio_tardio', $selectedYear, PDO::PARAM_INT);
        $estadoTardioStmt->execute();
        while (($estadoTardioRow = $estadoTardioStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $idTiendaTardio = (int) ($estadoTardioRow['id_tienda'] ?? 0);
            $periodoTardio = trim((string) ($estadoTardioRow['periodo_ym'] ?? ''));
            if ($idTiendaTardio <= 0 || $periodoTardio === '') {
                continue;
            }
            $operationalStateByTiendaMonth[$idTiendaTardio][$periodoTardio] = [
                'id_tienda' => $idTiendaTardio,
                'id_contrato_arriendo' => (int) ($estadoTardioRow['id_contrato_arriendo'] ?? 0),
                'id_arrendatario' => (int) ($estadoTardioRow['id_arrendatario'] ?? 0),
                'periodo_ym' => $periodoTardio,
                'arrendatario' => (string) ($estadoTardioRow['arrendatario'] ?? ''),
                'rut' => (string) ($estadoTardioRow['rut'] ?? ''),
                'vigente_arriendo' => 0,
                'en_liquidacion' => 1,
                'cerrado_financiero' => 0,
                'tiene_pendientes' => 1,
                'marca_termino' => 'SERVICIO TARDÍO',
            ];
        }
        $perfMark('estado_servicios_tardios');
    }

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
                $isLiquidacionPendiente = false;
                $estadoOperativoMes = is_array($operationalStateByTiendaMonth[$idTienda][$monthKey] ?? null)
                    ? $operationalStateByTiendaMonth[$idTienda][$monthKey]
                    : null;

                if ($estadoOperativoMes !== null) {
                    $esVigenteMes = (bool) ($estadoOperativoMes['vigente_arriendo'] ?? false);
                    $isLiquidacionPendiente = !$esVigenteMes
                        && (bool) ($estadoOperativoMes['en_liquidacion'] ?? false);
                    if ($esVigenteMes || $isLiquidacionPendiente) {
                        $arrValue = trim((string) ($estadoOperativoMes['arrendatario'] ?? ''));
                        $arrendatarioIdValue = (int) ($estadoOperativoMes['id_arrendatario'] ?? 0);
                        $rutTmpRaw = trim((string) ($estadoOperativoMes['rut'] ?? ''));
                        $rutValue = $rutTmpRaw !== '' ? msp2RutFormatDisplay($rutTmpRaw) : '-';
                    }
                } else {
                    // Respaldo para instalaciones antiguas: la ocupación se determina
                    // solo por las fechas reales, sin extenderla dos meses artificialmente.
                    foreach ($contratosTienda as $contratoTiendaRow) {
                        $fechaInicio = trim((string) ($contratoTiendaRow['fecha_inicio'] ?? ''));
                        $fechaTermino = trim((string) ($contratoTiendaRow['fecha_termino_efectiva'] ?? ''));
                        if ($fechaInicio === '' || $fechaInicio > $monthEnd) {
                            continue;
                        }
                        if ($fechaTermino !== '' && $fechaTermino < $monthStart) {
                            continue;
                        }

                        $arrValue = trim((string) ($contratoTiendaRow['nombre_arrendatario'] ?? ''));
                        $arrendatarioIdValue = (int) ($contratoTiendaRow['id_arrendatario'] ?? 0);
                        $rutTmpRaw = trim((string) ($contratoTiendaRow['rut'] ?? ''));
                        $rutValue = $rutTmpRaw !== '' ? msp2RutFormatDisplay($rutTmpRaw) : '-';
                        break;
                    }
                }

                $arrendatarioByMonth[$monthKey] = $arrValue;
                $arrendatarioIdByMonth[$monthKey] = $arrendatarioIdValue;
                $rutDisplayByMonth[$monthKey] = $rutValue;
                $postTerminoByMonth[$monthKey] = $isLiquidacionPendiente;
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
            'electricity_readings_by_month' => is_array($electricityReadingsByTiendaMonth[$idTienda] ?? null)
                ? $electricityReadingsByTiendaMonth[$idTienda]
                : [],
            'gas_readings_by_month' => is_array($gasReadingsByTiendaMonth[$idTienda] ?? null)
                ? $gasReadingsByTiendaMonth[$idTienda]
                : [],
            'water_readings_by_month' => is_array($waterReadingsByTiendaMonth[$idTienda] ?? null)
                ? $waterReadingsByTiendaMonth[$idTienda]
                : [],
            'rent_snapshots_by_month' => is_array($rentSnapshotsByTiendaMonth[$idTienda] ?? null)
                ? $rentSnapshotsByTiendaMonth[$idTienda]
                : [],
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
            $baseArrIdByMonth = is_array($baseRow['arrendatario_id_by_month'] ?? null) ? $baseRow['arrendatario_id_by_month'] : [];
            $baseRutByMonth = is_array($baseRow['rut_display_by_month'] ?? null) ? $baseRow['rut_display_by_month'] : [];
            $baseTerminoByMonth = is_array($baseRow['termino_by_month'] ?? null) ? $baseRow['termino_by_month'] : [];
            $basePostTerminoByMonth = is_array($baseRow['post_termino_by_month'] ?? null) ? $baseRow['post_termino_by_month'] : [];
            $baseArriendoNetoByMonth = is_array($baseRow['arriendo_neto_by_month'] ?? null) ? $baseRow['arriendo_neto_by_month'] : [];
            $baseServicios = is_array($baseRow['servicios'] ?? null) ? $baseRow['servicios'] : [];
            $baseElectricityReadings = is_array($baseRow['electricity_readings_by_month'] ?? null)
                ? $baseRow['electricity_readings_by_month']
                : [];
            $baseGasReadings = is_array($baseRow['gas_readings_by_month'] ?? null)
                ? $baseRow['gas_readings_by_month']
                : [];
            $baseWaterReadings = is_array($baseRow['water_readings_by_month'] ?? null)
                ? $baseRow['water_readings_by_month']
                : [];
            $baseRentSnapshots = is_array($baseRow['rent_snapshots_by_month'] ?? null)
                ? $baseRow['rent_snapshots_by_month']
                : [];
            $baseGarantia = is_array($baseRow['garantia'] ?? null) ? $baseRow['garantia'] : [];
            $baseReserva = is_array($baseRow['reserva'] ?? null) ? $baseRow['reserva'] : [];
            $baseReservaBreakdown = is_array($baseRow['reserva_breakdown'] ?? null) ? $baseRow['reserva_breakdown'] : [];
            $baseEstadoDoc = is_array($baseRow['estado_doc'] ?? null) ? $baseRow['estado_doc'] : [];
            $baseTotalDoc = is_array($baseRow['total_doc'] ?? null) ? $baseRow['total_doc'] : [];

            $newUfByMonth = is_array($rowItem['uf_base_by_month'] ?? null) ? $rowItem['uf_base_by_month'] : [];
            $newArrByMonth = is_array($rowItem['arrendatario_by_month'] ?? null) ? $rowItem['arrendatario_by_month'] : [];
            $newArrIdByMonth = is_array($rowItem['arrendatario_id_by_month'] ?? null) ? $rowItem['arrendatario_id_by_month'] : [];
            $newRutByMonth = is_array($rowItem['rut_display_by_month'] ?? null) ? $rowItem['rut_display_by_month'] : [];
            $newTerminoByMonth = is_array($rowItem['termino_by_month'] ?? null) ? $rowItem['termino_by_month'] : [];
            $newPostTerminoByMonth = is_array($rowItem['post_termino_by_month'] ?? null) ? $rowItem['post_termino_by_month'] : [];
            $newArriendoNetoByMonth = is_array($rowItem['arriendo_neto_by_month'] ?? null) ? $rowItem['arriendo_neto_by_month'] : [];
            $newServicios = is_array($rowItem['servicios'] ?? null) ? $rowItem['servicios'] : [];
            $newElectricityReadings = is_array($rowItem['electricity_readings_by_month'] ?? null)
                ? $rowItem['electricity_readings_by_month']
                : [];
            $newGasReadings = is_array($rowItem['gas_readings_by_month'] ?? null)
                ? $rowItem['gas_readings_by_month']
                : [];
            $newWaterReadings = is_array($rowItem['water_readings_by_month'] ?? null)
                ? $rowItem['water_readings_by_month']
                : [];
            $newRentSnapshots = is_array($rowItem['rent_snapshots_by_month'] ?? null)
                ? $rowItem['rent_snapshots_by_month']
                : [];
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
                $baseTerminoMes = (bool) ($baseTerminoByMonth[$monthKey] ?? false);
                $newTerminoMes = (bool) ($newTerminoByMonth[$monthKey] ?? false);
                $basePostTerminoMes = (bool) ($basePostTerminoByMonth[$monthKey] ?? false);
                $newPostTerminoMes = (bool) ($newPostTerminoByMonth[$monthKey] ?? false);

                $useNew = false;
                if ($baseArr === '' && $newArr !== '') {
                    $useNew = true;
                } elseif ($baseArr !== '' && $newArr !== '' && $basePostTerminoMes && !$newPostTerminoMes) {
                    // Si el local ya tiene un nuevo ocupante, no mezclarlo con la liquidación del anterior.
                    $useNew = true;
                } elseif ($baseArr === '' && $newArr === '' && $newUf > $baseUf) {
                    $useNew = true;
                }

                if ($useNew) {
                    $baseUfByMonth[$monthKey] = round($newUf, 6);
                    $baseArrByMonth[$monthKey] = $newArr;
                    $baseArrIdByMonth[$monthKey] = (int) ($newArrIdByMonth[$monthKey] ?? 0);
                    $baseRutByMonth[$monthKey] = trim((string) ($newRutByMonth[$monthKey] ?? '-'));
                    $baseTerminoByMonth[$monthKey] = $newTerminoMes;
                    $basePostTerminoByMonth[$monthKey] = $newPostTerminoMes;
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

                $lecturasMes = array_merge(
                    is_array($baseElectricityReadings[$monthKey] ?? null) ? $baseElectricityReadings[$monthKey] : [],
                    is_array($newElectricityReadings[$monthKey] ?? null) ? $newElectricityReadings[$monthKey] : []
                );
                if ($lecturasMes !== []) {
                    $lecturasUnicas = [];
                    foreach ($lecturasMes as $lecturaMes) {
                        $idLecturaMes = (int) ($lecturaMes['id_lectura'] ?? 0);
                        if ($idLecturaMes > 0) {
                            $lecturasUnicas[$idLecturaMes] = $lecturaMes;
                        }
                    }
                    $baseElectricityReadings[$monthKey] = array_values($lecturasUnicas);
                }

                $lecturasGasMes = array_merge(
                    is_array($baseGasReadings[$monthKey] ?? null) ? $baseGasReadings[$monthKey] : [],
                    is_array($newGasReadings[$monthKey] ?? null) ? $newGasReadings[$monthKey] : []
                );
                if ($lecturasGasMes !== []) {
                    $lecturasGasUnicas = [];
                    foreach ($lecturasGasMes as $lecturaGasMes) {
                        $idLecturaGasMes = (int) ($lecturaGasMes['id_lectura'] ?? 0);
                        if ($idLecturaGasMes > 0) {
                            $lecturasGasUnicas[$idLecturaGasMes] = $lecturaGasMes;
                        }
                    }
                    $baseGasReadings[$monthKey] = array_values($lecturasGasUnicas);
                }

                $lecturasAguaMes = array_merge(
                    is_array($baseWaterReadings[$monthKey] ?? null) ? $baseWaterReadings[$monthKey] : [],
                    is_array($newWaterReadings[$monthKey] ?? null) ? $newWaterReadings[$monthKey] : []
                );
                if ($lecturasAguaMes !== []) {
                    $lecturasAguaUnicas = [];
                    foreach ($lecturasAguaMes as $lecturaAguaMes) {
                        $idLecturaAguaMes = (int) ($lecturaAguaMes['id_lectura'] ?? 0);
                        if ($idLecturaAguaMes > 0) {
                            $lecturasAguaUnicas[$idLecturaAguaMes] = $lecturaAguaMes;
                        }
                    }
                    $baseWaterReadings[$monthKey] = array_values($lecturasAguaUnicas);
                }

                $snapshotsMes = array_merge(
                    is_array($baseRentSnapshots[$monthKey] ?? null) ? $baseRentSnapshots[$monthKey] : [],
                    is_array($newRentSnapshots[$monthKey] ?? null) ? $newRentSnapshots[$monthKey] : []
                );
                if ($snapshotsMes !== []) {
                    $snapshotsUnicos = [];
                    foreach ($snapshotsMes as $snapshotMes) {
                        $idSnapshotMes = (int) ($snapshotMes['id_snapshot'] ?? 0);
                        if ($idSnapshotMes > 0) {
                            $snapshotsUnicos[$idSnapshotMes] = $snapshotMes;
                        }
                    }
                    $baseRentSnapshots[$monthKey] = array_values($snapshotsUnicos);
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
            $baseRow['arrendatario_id_by_month'] = $baseArrIdByMonth;
            $baseRow['rut_display_by_month'] = $baseRutByMonth;
            $baseRow['termino_by_month'] = $baseTerminoByMonth;
            $baseRow['post_termino_by_month'] = $basePostTerminoByMonth;
            $baseRow['arriendo_neto_by_month'] = $baseArriendoNetoByMonth;
            $baseRow['servicios'] = $baseServicios;
            $baseRow['electricity_readings_by_month'] = $baseElectricityReadings;
            $baseRow['gas_readings_by_month'] = $baseGasReadings;
            $baseRow['water_readings_by_month'] = $baseWaterReadings;
            $baseRow['rent_snapshots_by_month'] = $baseRentSnapshots;
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
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout cd-body">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main cd-main">
        <div class="cd-shell">
            <section class="cd-focusbar js-control-focusbar" aria-label="Controles de Control diario">
                <div class="cd-focusbar-main" data-gp-commandbar>
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
                                    <option value="LIQUIDACION">Liquidación</option>
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
                <div class="control-grid-wrap gp-table-matrix-wrap">
                    <table class="control-grid gp-table-matrix">
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
                                $postTerminoByMonthJson = json_encode($postTerminoByMonthRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                if (!is_string($postTerminoByMonthJson) || $postTerminoByMonthJson === '') {
                                    $postTerminoByMonthJson = '{}';
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
                                    data-liquidacion-by-month="<?php echo msp2Escape($postTerminoByMonthJson); ?>"
                                    data-local-label="<?php echo msp2Escape((string) ($row['local_code'] ?? '')); ?>"
                                >
                                    <td class="sticky-col sticky-col-local">
                                        <span class="local-label"><?php echo msp2Escape($row['local_code']); ?></span>
                                    </td>
                                    <td class="sticky-col sticky-col-arr">
                                        <div class="arr-cell-stack">
                                            <div class="arr-label js-arr-display"><?php echo msp2Escape($row['arrendatario']); ?></div>
                                            <div class="arr-rut js-rut-display"><?php echo msp2Escape($row['rut_display'] !== '' ? $row['rut_display'] : '-'); ?></div>
                                            <div class="small text-warning-emphasis fw-semibold d-none js-arr-liquidacion">Liquidación de servicios pendiente</div>
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
                                        $lecturasElectricidadMap = is_array($row['electricity_readings_by_month'] ?? null)
                                            ? $row['electricity_readings_by_month']
                                            : [];
                                        $lecturasElectricidadMes = is_array($lecturasElectricidadMap[$monthKey] ?? null)
                                            ? $lecturasElectricidadMap[$monthKey]
                                            : [];
                                        $lecturasElectricidadJson = json_encode(
                                            $lecturasElectricidadMes,
                                            JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES
                                        );
                                        if (!is_string($lecturasElectricidadJson) || $lecturasElectricidadJson === '') {
                                            $lecturasElectricidadJson = '[]';
                                        }
                                        $lecturasGasMap = is_array($row['gas_readings_by_month'] ?? null)
                                            ? $row['gas_readings_by_month']
                                            : [];
                                        $lecturasGasMes = is_array($lecturasGasMap[$monthKey] ?? null)
                                            ? $lecturasGasMap[$monthKey]
                                            : [];
                                        $lecturasGasJson = json_encode(
                                            $lecturasGasMes,
                                            JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES
                                        );
                                        if (!is_string($lecturasGasJson) || $lecturasGasJson === '') {
                                            $lecturasGasJson = '[]';
                                        }
                                        $lecturasAguaMap = is_array($row['water_readings_by_month'] ?? null)
                                            ? $row['water_readings_by_month']
                                            : [];
                                        $lecturasAguaMes = is_array($lecturasAguaMap[$monthKey] ?? null)
                                            ? $lecturasAguaMap[$monthKey]
                                            : [];
                                        $lecturasAguaJson = json_encode(
                                            $lecturasAguaMes,
                                            JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES
                                        );
                                        if (!is_string($lecturasAguaJson) || $lecturasAguaJson === '') {
                                            $lecturasAguaJson = '[]';
                                        }
                                        $snapshotsArriendoMap = is_array($row['rent_snapshots_by_month'] ?? null)
                                            ? $row['rent_snapshots_by_month']
                                            : [];
                                        $snapshotsArriendoMes = is_array($snapshotsArriendoMap[$monthKey] ?? null)
                                            ? $snapshotsArriendoMap[$monthKey]
                                            : [];
                                        $arrendatarioIdMes = (int) (($row['arrendatario_id_by_month'][$monthKey] ?? 0));
                                        if ($arrendatarioIdMes > 0) {
                                            $snapshotsArriendoMes = array_values(array_filter(
                                                $snapshotsArriendoMes,
                                                static fn (array $snapshot): bool => (int) ($snapshot['id_arrendatario'] ?? 0) === $arrendatarioIdMes
                                            ));
                                        }
                                        if ((bool) ($row['post_termino_by_month'][$monthKey] ?? false)) {
                                            $snapshotsArriendoMes = [];
                                        }
                                        $snapshotsArriendoJson = json_encode(
                                            $snapshotsArriendoMes,
                                            JSON_UNESCAPED_UNICODE + JSON_UNESCAPED_SLASHES
                                        );
                                        if (!is_string($snapshotsArriendoJson) || $snapshotsArriendoJson === '') {
                                            $snapshotsArriendoJson = '[]';
                                        }
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
                                            class="cell-num js-uf-base-display js-uf-base-cell js-month-col<?php echo $snapshotsArriendoMes !== [] && ($row['calc_mode'] ?? 'UF') !== 'NETO_FIJO' ? ' rent-edit-cell' : ''; ?>"
                                            data-month-key="<?php echo msp2Escape($monthKey); ?>"
                                            data-uf-base-value="<?php echo msp2Escape(number_format($ufBaseMes, 6, '.', '')); ?>"
                                        >
                                            <?php if (($row['calc_mode'] ?? 'UF') === 'NETO_FIJO'): ?>
                                                -
                                            <?php else: ?>
                                                <span class="rent-cell-value"><?php echo msp2Escape(number_format($ufBaseMes, 2, ',', '.')); ?></span>
                                                <?php if ($snapshotsArriendoMes !== [] && $canCorrectRent): ?>
                                                    <button
                                                        type="button"
                                                        class="rent-edit-btn js-rent-edit"
                                                        data-rent-snapshots="<?php echo msp2Escape($snapshotsArriendoJson); ?>"
                                                        aria-label="Corregir UF base del período"
                                                        title="Corregir UF base del período"
                                                    ><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                                <?php endif; ?>
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
                                        <td class="cell-num js-servicio-electricidad js-month-col<?php echo $lecturasElectricidadMes !== [] ? ' electricity-edit-cell' : ''; ?>" data-month-key="<?php echo msp2Escape($monthKey); ?>" data-servicio-monto="<?php echo msp2Escape(number_format($montoElectricidad, 2, '.', '')); ?>">
                                            <span class="electricity-cell-value"><?php echo msp2Escape(number_format($montoElectricidad, 2, ',', '.')); ?></span>
                                            <?php if ($lecturasElectricidadMes !== [] && $canCorrectElectricity): ?>
                                                <button
                                                    type="button"
                                                    class="electricity-edit-btn js-electricity-edit"
                                                    data-electricity-readings="<?php echo msp2Escape($lecturasElectricidadJson); ?>"
                                                    aria-label="Corregir lectura de electricidad"
                                                    title="Corregir lectura de electricidad"
                                                ><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                            <?php endif; ?>
                                        </td>
                                        <td class="cell-num js-servicio-gas js-month-col<?php echo $lecturasGasMes !== [] ? ' gas-edit-cell' : ''; ?>" data-month-key="<?php echo msp2Escape($monthKey); ?>" data-servicio-monto="<?php echo msp2Escape(number_format($montoGas, 2, '.', '')); ?>">
                                            <span class="gas-cell-value"><?php echo msp2Escape(number_format($montoGas, 2, ',', '.')); ?></span>
                                            <?php if ($lecturasGasMes !== [] && $canCorrectGas): ?>
                                                <button
                                                    type="button"
                                                    class="gas-edit-btn js-gas-edit"
                                                    data-gas-readings="<?php echo msp2Escape($lecturasGasJson); ?>"
                                                    aria-label="Corregir lectura de gas"
                                                    title="Corregir lectura de gas"
                                                ><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                            <?php endif; ?>
                                        </td>
                                        <td class="cell-num js-servicio-agua js-month-col<?php echo $lecturasAguaMes !== [] ? ' water-edit-cell' : ''; ?>" data-month-key="<?php echo msp2Escape($monthKey); ?>" data-servicio-monto="<?php echo msp2Escape(number_format($montoAgua, 2, '.', '')); ?>">
                                            <span class="water-cell-value"><?php echo msp2Escape(number_format($montoAgua, 2, ',', '.')); ?></span>
                                            <?php if ($lecturasAguaMes !== [] && $canCorrectWater): ?>
                                                <button
                                                    type="button"
                                                    class="water-edit-btn js-water-edit"
                                                    data-water-readings="<?php echo msp2Escape($lecturasAguaJson); ?>"
                                                    aria-label="Corregir lectura de agua"
                                                    title="Corregir lectura de agua"
                                                ><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                            <?php endif; ?>
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
                                            if ($estadoPostTerminoMes) {
                                                $statusLabel = 'LIQUIDACION';
                                                $statusClass = 'is-pending';
                                                $statusIndex = 2;
                                            } elseif ($estadoTerminoMes) {
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

<div class="modal fade" id="rentCorrectionModal" tabindex="-1" aria-labelledby="rentCorrectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?php echo msp2Escape(msp2Url('correcciones/guardar.php')); ?>" id="rent-correction-form">
                <?php msp2CsrfField(); ?>
                <input type="hidden" name="accion" value="crear">
                <input type="hidden" name="entidad_afectada" value="arriendo">
                <input type="hidden" name="unidad_valor" value="UF_BASE">
                <input type="hidden" name="modulo_origen" value="control_diario/index.php">
                <input type="hidden" name="id_contrato_arriendo" id="rent-id-contrato" value="">
                <input type="hidden" name="id_local" id="rent-id-local" value="">
                <input type="hidden" name="id_registro_origen" id="rent-id-snapshot" value="">
                <input type="hidden" name="periodo_facturacion" id="rent-periodo" value="">
                <input type="hidden" name="valor_anterior" id="rent-valor-anterior" value="">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="rentCorrectionModalLabel">Corregir UF base del período</h2>
                        <div class="small text-muted">La corrección afecta solamente al contrato-local y mes seleccionados.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 d-none" id="rent-snapshot-select-wrap">
                        <label class="form-label" for="rent-snapshot-select">Local que deseas corregir</label>
                        <select class="form-select" id="rent-snapshot-select"></select>
                    </div>

                    <div class="rent-correction-context mb-3">
                        <div><span>Local</span><strong id="rent-local-label">-</strong></div>
                        <div><span>Modalidad</span><strong id="rent-modality-label">-</strong></div>
                        <div><span>Período</span><strong id="rent-period-label">-</strong></div>
                        <div><span>Documento</span><strong id="rent-document-label">-</strong></div>
                    </div>

                    <h3 class="h6 mb-2">Cómo se obtuvo el arriendo actual</h3>
                    <div class="rent-calculation-grid mb-3">
                        <div><span>UF base actual</span><strong id="rent-uf-current">0</strong></div>
                        <div><span>UF del período</span><strong id="rent-period-uf">$ 0</strong></div>
                        <div><span>Neto actual</span><strong id="rent-net-current">$ 0</strong></div>
                        <div><span>IVA actual</span><strong id="rent-vat-current">$ 0</strong></div>
                    </div>

                    <div class="alert alert-success mb-3" id="rent-correction-level" role="status"></div>

                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="rent-new-uf">Nueva UF base correcta</label>
                            <input class="form-control" type="number" min="0" step="0.000001" name="valor_nuevo" id="rent-new-uf" required>
                            <div class="form-text">Ingresa las UF del local seleccionado, no el total agrupado de la fila.</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="rent-reason">Motivo de la corrección</label>
                            <input class="form-control" type="text" name="motivo" id="rent-reason" maxlength="500" required placeholder="Ej.: valor UF mensual registrado incorrectamente">
                        </div>
                    </div>

                    <div class="form-check mt-3 d-none" id="rent-zero-confirm-wrap">
                        <input class="form-check-input" type="checkbox" name="confirmar_cero" value="1" id="rent-zero-confirm">
                        <label class="form-check-label" for="rent-zero-confirm">
                            Confirmo que este contrato-local tendrá arriendo cero durante el período seleccionado.
                        </label>
                    </div>

                    <div class="rent-result-panel mt-3">
                        <div><span>Nuevo neto</span><strong id="rent-net-new">-</strong></div>
                        <div><span>Nuevo IVA</span><strong id="rent-vat-new">-</strong></div>
                        <div><span>Nuevo subtotal</span><strong id="rent-total-new">-</strong></div>
                        <div><span>Diferencia</span><strong id="rent-difference">-</strong></div>
                    </div>
                    <div class="alert alert-danger py-2 mt-3 mb-0 d-none" id="rent-validation-message"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="rent-submit">Registrar corrección</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="electricityCorrectionModal" tabindex="-1" aria-labelledby="electricityCorrectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?php echo msp2Escape(msp2Url('correcciones/guardar.php')); ?>" id="electricity-correction-form">
                <?php msp2CsrfField(); ?>
                <input type="hidden" name="accion" value="crear">
                <input type="hidden" name="entidad_afectada" value="lectura">
                <input type="hidden" name="servicio" value="LUZ">
                <input type="hidden" name="modulo_origen" value="control_diario/index.php">
                <input type="hidden" name="id_contrato_arriendo" id="electricity-id-contrato" value="">
                <input type="hidden" name="id_local" id="electricity-id-local" value="">
                <input type="hidden" name="id_registro_origen" id="electricity-id-lectura" value="">
                <input type="hidden" name="periodo_facturacion" id="electricity-periodo" value="">
                <input type="hidden" name="valor_anterior" id="electricity-valor-anterior" value="">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="electricityCorrectionModalLabel">Corregir lectura de electricidad</h2>
                        <div class="small text-muted">La plataforma recalculará consumo, cobro y efectos posteriores según el estado del documento.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 d-none" id="electricity-reading-select-wrap">
                        <label class="form-label" for="electricity-reading-select">Medidor que deseas corregir</label>
                        <select class="form-select" id="electricity-reading-select"></select>
                    </div>

                    <div class="electricity-correction-context mb-3">
                        <div><span>Local</span><strong id="electricity-local-label">-</strong></div>
                        <div><span>Medidor</span><strong id="electricity-meter-label">-</strong></div>
                        <div><span>Período</span><strong id="electricity-period-label">-</strong></div>
                        <div><span>Documento</span><strong id="electricity-document-label">-</strong></div>
                    </div>

                    <h3 class="h6 mb-2">Cómo se obtuvo el monto actual</h3>
                    <div class="electricity-calculation-grid mb-3">
                        <div><span>Lectura anterior</span><strong id="electricity-reading-previous">0</strong></div>
                        <div><span>Lectura registrada</span><strong id="electricity-reading-current">0</strong></div>
                        <div><span>Consumo</span><strong id="electricity-consumption-current">0 kWh</strong></div>
                        <div><span>Tarifa</span><strong id="electricity-rate-current">$ 0</strong></div>
                        <div><span>Cálculo</span><strong id="electricity-formula-current">0 × 0</strong></div>
                        <div><span>Monto electricidad</span><strong id="electricity-amount-current">$ 0</strong></div>
                    </div>

                    <div class="alert mb-3" id="electricity-correction-level" role="status"></div>

                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="electricity-new-reading">Nueva lectura correcta</label>
                            <input class="form-control" type="number" min="0" step="0.0001" name="valor_nuevo" id="electricity-new-reading" required>
                            <div class="form-text" id="electricity-reading-limits">Debe ser igual o mayor que la lectura anterior.</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="electricity-reason">Motivo de la corrección</label>
                            <input class="form-control" type="text" name="motivo" id="electricity-reason" maxlength="500" required placeholder="Ej.: lectura digitada incorrectamente">
                        </div>
                    </div>

                    <div class="electricity-result-panel mt-3">
                        <div><span>Nuevo consumo</span><strong id="electricity-consumption-new">-</strong></div>
                        <div><span>Nuevo monto</span><strong id="electricity-amount-new">-</strong></div>
                        <div><span>Diferencia</span><strong id="electricity-difference">-</strong></div>
                        <div><span>Nuevo total documento</span><strong id="electricity-document-total-new">-</strong></div>
                    </div>
                    <div class="alert alert-danger py-2 mt-3 mb-0 d-none" id="electricity-validation-message"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="electricity-submit">Registrar corrección</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="gasCorrectionModal" tabindex="-1" aria-labelledby="gasCorrectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?php echo msp2Escape(msp2Url('correcciones/guardar.php')); ?>" id="gas-correction-form">
                <?php msp2CsrfField(); ?>
                <input type="hidden" name="accion" value="crear">
                <input type="hidden" name="entidad_afectada" value="lectura">
                <input type="hidden" name="servicio" value="GAS">
                <input type="hidden" name="modulo_origen" value="control_diario/index.php">
                <input type="hidden" name="id_contrato_arriendo" id="gas-id-contrato" value="">
                <input type="hidden" name="id_local" id="gas-id-local" value="">
                <input type="hidden" name="id_registro_origen" id="gas-id-lectura" value="">
                <input type="hidden" name="periodo_facturacion" id="gas-periodo" value="">
                <input type="hidden" name="valor_anterior" id="gas-valor-anterior" value="">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="gasCorrectionModalLabel">Corregir lectura de gas</h2>
                        <div class="small text-muted">La plataforma recalculará consumo, cobro y efectos posteriores según el estado del documento.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 d-none" id="gas-reading-select-wrap">
                        <label class="form-label" for="gas-reading-select">Medidor que deseas corregir</label>
                        <select class="form-select" id="gas-reading-select"></select>
                    </div>

                    <div class="gas-correction-context mb-3">
                        <div><span>Local</span><strong id="gas-local-label">-</strong></div>
                        <div><span>Medidor</span><strong id="gas-meter-label">-</strong></div>
                        <div><span>Período</span><strong id="gas-period-label">-</strong></div>
                        <div><span>Documento</span><strong id="gas-document-label">-</strong></div>
                    </div>

                    <h3 class="h6 mb-2">Cómo se obtuvo el monto actual</h3>
                    <div class="gas-calculation-grid mb-3">
                        <div><span>Lectura anterior</span><strong id="gas-reading-previous">0</strong></div>
                        <div><span>Lectura registrada</span><strong id="gas-reading-current">0</strong></div>
                        <div><span>Consumo</span><strong id="gas-consumption-current">0</strong></div>
                        <div><span>Factor</span><strong id="gas-factor-current">0</strong></div>
                        <div><span>Valor litro</span><strong id="gas-liter-value-current">$ 0</strong></div>
                        <div><span>Cálculo</span><strong id="gas-formula-current">0 × 0 × 0</strong></div>
                        <div><span>Monto gas</span><strong id="gas-amount-current">$ 0</strong></div>
                    </div>

                    <div class="alert mb-3" id="gas-correction-level" role="status"></div>

                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="gas-new-reading">Nueva lectura correcta</label>
                            <input class="form-control" type="number" min="0" step="0.0001" name="valor_nuevo" id="gas-new-reading" required>
                            <div class="form-text" id="gas-reading-limits">Debe ser igual o mayor que la lectura anterior.</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="gas-reason">Motivo de la corrección</label>
                            <input class="form-control" type="text" name="motivo" id="gas-reason" maxlength="500" required placeholder="Ej.: lectura de gas digitada incorrectamente">
                        </div>
                    </div>

                    <div class="gas-result-panel mt-3">
                        <div><span>Nuevo consumo</span><strong id="gas-consumption-new">-</strong></div>
                        <div><span>Nuevo monto</span><strong id="gas-amount-new">-</strong></div>
                        <div><span>Diferencia</span><strong id="gas-difference">-</strong></div>
                        <div><span>Nuevo total documento</span><strong id="gas-document-total-new">-</strong></div>
                    </div>
                    <div class="alert alert-danger py-2 mt-3 mb-0 d-none" id="gas-validation-message"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="gas-submit">Registrar corrección</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="waterCorrectionModal" tabindex="-1" aria-labelledby="waterCorrectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" action="<?php echo msp2Escape(msp2Url('correcciones/guardar.php')); ?>" id="water-correction-form">
                <?php msp2CsrfField(); ?>
                <input type="hidden" name="accion" value="crear">
                <input type="hidden" name="entidad_afectada" value="lectura">
                <input type="hidden" name="servicio" value="AGUA">
                <input type="hidden" name="modulo_origen" value="control_diario/index.php">
                <input type="hidden" name="id_contrato_arriendo" id="water-id-contrato" value="">
                <input type="hidden" name="id_local" id="water-id-local" value="">
                <input type="hidden" name="id_registro_origen" id="water-id-lectura" value="">
                <input type="hidden" name="periodo_facturacion" id="water-periodo" value="">
                <input type="hidden" name="valor_anterior" id="water-valor-anterior" value="">
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-5" id="waterCorrectionModalLabel">Corregir lectura de agua</h2>
                        <div class="small text-muted">Se usa la fórmula del Excel: cargo fijo completo más consumo por tarifa variable.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 d-none" id="water-reading-select-wrap">
                        <label class="form-label" for="water-reading-select">Medidor que deseas corregir</label>
                        <select class="form-select" id="water-reading-select"></select>
                    </div>

                    <div class="water-correction-context mb-3">
                        <div><span>Local</span><strong id="water-local-label">-</strong></div>
                        <div><span>Medidor</span><strong id="water-meter-label">-</strong></div>
                        <div><span>Período de cobro</span><strong id="water-period-label">-</strong></div>
                        <div><span>Consumo medido</span><strong id="water-consumption-period-label">-</strong></div>
                        <div><span>Documento</span><strong id="water-document-label">-</strong></div>
                    </div>

                    <h3 class="h6 mb-2">Cómo se obtuvo el monto actual</h3>
                    <div class="water-calculation-grid mb-3">
                        <div><span>Lectura anterior</span><strong id="water-reading-previous">0</strong></div>
                        <div><span>Lectura registrada</span><strong id="water-reading-current">0</strong></div>
                        <div><span>Consumo</span><strong id="water-consumption-current">0 m³</strong></div>
                        <div><span>Tarifa variable</span><strong id="water-rate-current">$ 0 / m³</strong></div>
                        <div><span>Subtotal variable</span><strong id="water-variable-current">$ 0</strong></div>
                        <div><span>Cargo fijo completo</span><strong id="water-fixed-current">$ 0</strong></div>
                        <div><span>Cálculo</span><strong id="water-formula-current">0 × 0 + 0</strong></div>
                        <div><span>Monto agua</span><strong id="water-amount-current">$ 0</strong></div>
                    </div>

                    <div class="alert mb-3" id="water-correction-level" role="status"></div>

                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="water-new-reading">Nueva lectura correcta</label>
                            <input class="form-control" type="number" min="0" step="0.0001" name="valor_nuevo" id="water-new-reading" required>
                            <div class="form-text" id="water-reading-limits">Debe ser igual o mayor que la lectura anterior.</div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="water-reason">Motivo de la corrección</label>
                            <input class="form-control" type="text" name="motivo" id="water-reason" maxlength="500" required placeholder="Ej.: lectura de agua digitada incorrectamente">
                        </div>
                    </div>

                    <div class="water-result-panel mt-3">
                        <div><span>Nuevo consumo</span><strong id="water-consumption-new">-</strong></div>
                        <div><span>Nuevo subtotal variable</span><strong id="water-variable-new">-</strong></div>
                        <div><span>Cargo fijo</span><strong id="water-fixed-new">-</strong></div>
                        <div><span>Nuevo monto</span><strong id="water-amount-new">-</strong></div>
                        <div><span>Diferencia</span><strong id="water-difference">-</strong></div>
                        <div><span>Nuevo total documento</span><strong id="water-document-total-new">-</strong></div>
                    </div>
                    <div class="alert alert-danger py-2 mt-3 mb-0 d-none" id="water-validation-message"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="water-submit">Registrar corrección</button>
                </div>
            </form>
        </div>
    </div>
</div>

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

<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?>>
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
        const liquidacionDisplay = row.querySelector('.js-arr-liquidacion');
        if (!arrDisplay && !rutDisplay) {
            return;
        }

        const arrMap = getRowMonthMap(row, 'data-arrendatario-by-month', '__arrendatarioByMonth');
        const rutMap = getRowMonthMap(row, 'data-rut-by-month', '__rutByMonth');
        const liquidacionMap = getRowMonthMap(row, 'data-liquidacion-by-month', '__liquidacionByMonth');

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
        if (liquidacionDisplay) {
            const isLiquidacion = liquidacionMap[monthKey] === true || liquidacionMap[monthKey] === 1;
            liquidacionDisplay.classList.toggle('d-none', !isLiquidacion);
        }
        delete row.__searchableText;
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
            const arrendatarioIdMap = getRowMonthMap(
                row,
                'data-arrendatario-id-by-month',
                '__arrendatarioIdByMonth'
            );
            const arrendatarioId = Number.parseInt(
                String(arrendatarioIdMap[currentVisibleMonthKey] || '0'),
                10
            );
            const hasMonthlyContext = Number.isFinite(arrendatarioId) && arrendatarioId > 0;
            row.style.display = hasMonthlyContext && matchesStatus && matchesSearch ? '' : 'none';
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

        let navigationPending = false;
        let navigationTimer = null;
        const previewMonth = function (targetIndex) {
            const key = allMonthKeys[targetIndex] || '';
            if (!key) {
                return;
            }
            const label = monthLabelByKey.get(key) || key;
            monthScaleItems.forEach((item) => {
                item.classList.toggle('is-target', item.dataset.monthKey === key && key !== currentMonthKey);
            });
            const targetDescription = label + (key === currentMonthKey ? '' : ': suelta la barra para cargar');
            slider.setAttribute('aria-valuetext', targetDescription);
            slider.title = targetDescription;
        };
        const navigateToMonth = function (targetIndex) {
            const normalizedIndex = Math.max(0, Math.min(allMonthKeys.length - 1, targetIndex));
            const key = allMonthKeys[normalizedIndex] || '';
            if (!key || navigationPending) {
                return;
            }
            if (key === currentMonthKey) {
                previewMonth(activeIndex);
                return;
            }
            navigationPending = true;
            const url = new URL(window.location.href);
            url.searchParams.set('anio', String(selectedYear));
            url.searchParams.set('mes', key);
            if (<?php echo $perfEnabled ? 'true' : 'false'; ?>) {
                url.searchParams.set('perf', '1');
            } else {
                url.searchParams.delete('perf');
            }
            url.searchParams.delete('full');
            setControlGridLoading(true);
            navigationTimer = window.setTimeout(() => {
                window.location.assign(url.toString());
            }, 100);
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
            previewMonth(activeIndex);
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
            previewMonth(Math.max(0, Math.min(allMonthKeys.length - 1, value - 1)));
        });

        slider.addEventListener('change', function () {
            const value = Number.parseInt(slider.value || '1', 10);
            if (Number.isFinite(value)) {
                navigateToMonth(value - 1);
            }
        });

        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) {
                return;
            }
            window.clearTimeout(navigationTimer);
            navigationPending = false;
            setControlGridLoading(false);
            apply();
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
                countdownNode.textContent = 'Detalle restaurado al volver desde Documentos de cobro.';
                return;
            }
            countdownNode.textContent = 'Redirección automática en ' + String(secondsLeft) + ' s.';
        };

        modalElement.addEventListener('hidden.bs.modal', function () {
            clearRowRedirectTimer();
            confirmLink.setAttribute('href', '#');
        });

        const openForRow = function (row, autoRedirect = true) {
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
            const returnTo = 'control_diario/index.php?' + new URLSearchParams({
                anio: currentVisibleMonthKey.slice(0, 4),
                mes: currentVisibleMonthKey,
                detalle_local: String(row.getAttribute('data-local-id') || ''),
                detalle_arrendatario: String(arrendatarioId),
            }).toString();
            const targetUrl = '<?php echo msp2Escape(msp2Url('documentos_cobro/index.php')); ?>?id_arrendatario='
                + encodeURIComponent(String(arrendatarioId))
                + '&filtroPeriodo='
                + encodeURIComponent(currentVisibleMonthKey)
                + '&return_to='
                + encodeURIComponent(returnTo);

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
            updateCountdown(secondsLeft, autoRedirect);
            if (autoRedirect) {
                rowRedirectTimer = window.setInterval(function () {
                    secondsLeft -= 1;
                    if (secondsLeft <= 0) {
                        clearRowRedirectTimer();
                        window.location.href = targetUrl;
                        return;
                    }
                    updateCountdown(secondsLeft, true);
                }, 1000);
            }

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

        const returnDetailLocal = <?php echo (int) $returnDetailLocal; ?>;
        const returnDetailArrendatario = <?php echo (int) $returnDetailArrendatario; ?>;
        if (returnDetailLocal > 0 && returnDetailArrendatario > 0) {
            const returnRow = Array.from(document.querySelectorAll('tbody tr[data-local-id]')).find((row) => {
                if (Number.parseInt(String(row.getAttribute('data-local-id') || '0'), 10) !== returnDetailLocal) {
                    return false;
                }
                const arrIdMap = getRowMonthMap(row, 'data-arrendatario-id-by-month', '__arrendatarioIdByMonth');
                return Number.parseInt(String(arrIdMap[currentVisibleMonthKey] || '0'), 10) === returnDetailArrendatario;
            });
            if (returnRow && returnRow.classList.contains('is-row-link')) {
                window.setTimeout(function () {
                    returnRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    openForRow(returnRow, false);
                    const cleanUrl = new URL(window.location.href);
                    cleanUrl.searchParams.delete('detalle_local');
                    cleanUrl.searchParams.delete('detalle_arrendatario');
                    window.history.replaceState({}, document.title, cleanUrl.toString());
                }, 100);
            }
        }
    }

    function initRentCorrection() {
        const modalElement = document.getElementById('rentCorrectionModal');
        const form = document.getElementById('rent-correction-form');
        const selectorWrap = document.getElementById('rent-snapshot-select-wrap');
        const selector = document.getElementById('rent-snapshot-select');
        const newUfInput = document.getElementById('rent-new-uf');
        const reasonInput = document.getElementById('rent-reason');
        const zeroWrap = document.getElementById('rent-zero-confirm-wrap');
        const zeroConfirm = document.getElementById('rent-zero-confirm');
        const submitButton = document.getElementById('rent-submit');
        const validationMessage = document.getElementById('rent-validation-message');
        const levelMessage = document.getElementById('rent-correction-level');
        if (!modalElement || !form || !selectorWrap || !selector || !newUfInput
            || !reasonInput || !zeroWrap || !zeroConfirm || !submitButton || !validationMessage
            || !levelMessage || typeof bootstrap === 'undefined') {
            return;
        }

        const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
        let snapshots = [];
        let activeSnapshot = null;
        let submissionStarted = false;

        const setText = function (id, value) {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = String(value);
            }
        };
        const roundMoney = function (value) {
            return Math.round((Number(value) + Number.EPSILON) * 100) / 100;
        };
        const numberValue = function (value) {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : 0;
        };
        const displayNumber = function (value, decimals) {
            return formatNumber(numberValue(value), decimals);
        };
        const displayAmount = function (value) {
            return '$ ' + displayNumber(value, 2);
        };

        const validateAndCalculate = function () {
            validationMessage.classList.add('d-none');
            validationMessage.textContent = '';
            if (!activeSnapshot) {
                submitButton.disabled = true;
                return false;
            }

            const newUf = Number.parseFloat(String(newUfInput.value || '').replace(',', '.'));
            const currentUf = Number(activeSnapshot.uf_base || 0);
            const periodUf = Number(activeSnapshot.valor_uf_periodo || 0);
            const currentTotal = Number(activeSnapshot.monto_total || 0);
            const isZero = Number.isFinite(newUf) && Math.abs(newUf) < 0.0000005;
            zeroWrap.classList.toggle('d-none', !isZero);
            if (!isZero) {
                zeroConfirm.checked = false;
            }

            let error = '';
            if (activeSnapshot.puede_aplicar !== true) {
                error = activeSnapshot.nivel === 'AJUSTE_FINANCIERO'
                    ? 'El documento tiene pagos, garantía, saldo a favor o historial de envío. La UF no puede sobrescribirse; corresponde un ajuste financiero.'
                    : 'El estado actual requiere revisión antes de permitir la corrección de UF Base.';
            } else if (!Number.isFinite(newUf) || newUf < 0) {
                error = 'Ingresa un valor UF igual o mayor que cero.';
            } else if (periodUf <= 0) {
                error = 'El período no tiene un valor UF válido para recalcular el arriendo.';
            } else if (Math.abs(newUf - currentUf) < 0.0000005) {
                error = 'El nuevo valor UF debe ser diferente del valor actual.';
            } else if (isZero && !zeroConfirm.checked) {
                error = 'Debes confirmar expresamente el arriendo cero para continuar.';
            }

            if (error !== '') {
                setText('rent-net-new', '-');
                setText('rent-vat-new', '-');
                setText('rent-total-new', '-');
                setText('rent-difference', '-');
                validationMessage.textContent = error;
                validationMessage.classList.remove('d-none');
                submitButton.disabled = true;
                return false;
            }

            const newNet = roundMoney(newUf * periodUf);
            const newVat = roundMoney(newNet * 0.19);
            const newTotal = roundMoney(newNet + newVat);
            setText('rent-net-new', displayAmount(newNet));
            setText('rent-vat-new', displayAmount(newVat));
            setText('rent-total-new', displayAmount(newTotal));
            setText('rent-difference', displayAmount(roundMoney(newTotal - currentTotal)));
            submitButton.disabled = false;
            return true;
        };

        const showSnapshot = function (index) {
            activeSnapshot = snapshots[index] || null;
            if (!activeSnapshot) {
                submitButton.disabled = true;
                return;
            }
            document.getElementById('rent-id-contrato').value = String(activeSnapshot.id_contrato || '');
            document.getElementById('rent-id-local').value = String(activeSnapshot.id_local || '');
            document.getElementById('rent-id-snapshot').value = String(activeSnapshot.id_snapshot || '');
            document.getElementById('rent-periodo').value = String(activeSnapshot.periodo || '');
            document.getElementById('rent-valor-anterior').value =
                'monto_neto_clp=' + String(activeSnapshot.monto_neto || 0)
                + '; valor_base_uf=' + String(activeSnapshot.uf_base || 0);
            setText('rent-local-label', activeSnapshot.local || '-');
            setText('rent-modality-label', activeSnapshot.modalidad || '-');
            setText('rent-period-label', activeSnapshot.periodo || '-');
            setText('rent-uf-current', displayNumber(Number(activeSnapshot.uf_base || 0), 6));
            setText('rent-period-uf', displayAmount(Number(activeSnapshot.valor_uf_periodo || 0)));
            setText('rent-net-current', displayAmount(Number(activeSnapshot.monto_neto || 0)));
            setText('rent-vat-current', displayAmount(Number(activeSnapshot.monto_iva || 0)));
            setText(
                'rent-document-label',
                Number(activeSnapshot.id_documento || 0) > 0
                    ? String(activeSnapshot.numero_documento || ('#' + String(activeSnapshot.id_documento)))
                    : 'Sin documento emitido'
            );
            const level = String(activeSnapshot.nivel || 'EDICION_SIMPLE');
            const levelTexts = {
                EDICION_SIMPLE: 'Sin documento emitido: se actualizará solamente el snapshot mensual y su trazabilidad.',
                REGENERACION_CONTROLADA: 'Documento emitido sin movimientos protegidos: se conservará su versión anterior y se recalcularán detalle, total, saldo y PDF.',
                AUTORIZACION: 'Período cerrado o contabilizado: al autorizar se conservará la versión anterior y se revertirá y regenerará el asiento contable.',
                AJUSTE_FINANCIERO: 'Documento con pagos, garantía, saldo a favor o envío registrado: no se sobrescribirá; corresponde un ajuste financiero.',
                REVISION: 'El documento no está disponible para una corrección automática y debe revisarse.',
            };
            levelMessage.textContent = levelTexts[level] || 'La corrección requiere revisión.';
            levelMessage.className = 'alert mb-3 '
                + (level === 'EDICION_SIMPLE' ? 'alert-success' : (activeSnapshot.puede_aplicar === true ? 'alert-warning' : 'alert-danger'));
            newUfInput.value = String(Number(activeSnapshot.uf_base || 0));
            zeroConfirm.checked = false;
            zeroWrap.classList.add('d-none');
            validateAndCalculate();
        };

        document.querySelectorAll('.js-rent-edit').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                let parsed = [];
                try {
                    const raw = button.getAttribute('data-rent-snapshots') ?? '[]';
                    const decoded = JSON.parse(raw);
                    parsed = Array.isArray(decoded) ? decoded : [];
                } catch (_error) {
                    parsed = [];
                }
                snapshots = parsed.filter(function (snapshot) {
                    return snapshot && Number(snapshot.id_snapshot || 0) > 0;
                });
                if (snapshots.length === 0) {
                    return;
                }
                selector.innerHTML = '';
                snapshots.forEach(function (snapshot, index) {
                    const option = document.createElement('option');
                    option.value = String(index);
                    option.textContent = String(snapshot.local || ('Local #' + String(snapshot.id_local || '')))
                        + ' · Contrato #' + String(snapshot.id_contrato || '');
                    selector.appendChild(option);
                });
                selectorWrap.classList.toggle('d-none', snapshots.length <= 1);
                selector.value = '0';
                reasonInput.value = '';
                showSnapshot(0);
                modal.show();
                window.setTimeout(function () {
                    newUfInput.focus();
                    newUfInput.select();
                }, 200);
            });
        });

        selector.addEventListener('change', function () {
            const index = Number.parseInt(selector.value, 10);
            showSnapshot(Number.isFinite(index) ? index : 0);
        });
        newUfInput.addEventListener('input', validateAndCalculate);
        zeroConfirm.addEventListener('change', validateAndCalculate);
        form.addEventListener('submit', function (event) {
            if (!validateAndCalculate()) {
                event.preventDefault();
                return;
            }
            submissionStarted = true;
            submitButton.disabled = true;
            submitButton.textContent = 'Registrando…';
        });
        modalElement.addEventListener('hidden.bs.modal', function () {
            if (submissionStarted) {
                return;
            }
            snapshots = [];
            activeSnapshot = null;
            selector.innerHTML = '';
            form.reset();
            submitButton.textContent = 'Registrar corrección';
            validationMessage.classList.add('d-none');
        });
    }

    function initElectricityCorrection() {
        const modalElement = document.getElementById('electricityCorrectionModal');
        const form = document.getElementById('electricity-correction-form');
        const selectorWrap = document.getElementById('electricity-reading-select-wrap');
        const selector = document.getElementById('electricity-reading-select');
        const newReadingInput = document.getElementById('electricity-new-reading');
        const reasonInput = document.getElementById('electricity-reason');
        const submitButton = document.getElementById('electricity-submit');
        const levelAlert = document.getElementById('electricity-correction-level');
        const validationMessage = document.getElementById('electricity-validation-message');
        if (!modalElement || !form || !selectorWrap || !selector || !newReadingInput
            || !reasonInput || !submitButton || !levelAlert || !validationMessage
            || typeof bootstrap === 'undefined') {
            return;
        }

        const modal = new bootstrap.Modal(modalElement);
        let readings = [];
        let activeReading = null;
        let submissionStarted = false;

        const numberValue = function (value) {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : 0;
        };
        const displayNumber = function (value, decimals) {
            return formatNumber(numberValue(value), decimals);
        };
        const displayAmount = function (value) {
            return '$ ' + displayNumber(value, 2);
        };
        const setText = function (id, value) {
            const node = document.getElementById(id);
            if (node) {
                node.textContent = String(value);
            }
        };
        const setHidden = function (id, value) {
            const node = document.getElementById(id);
            if (node) {
                node.value = String(value ?? '');
            }
        };
        const periodLabel = function (period) {
            const text = String(period ?? '');
            const parts = text.split('-');
            if (parts.length !== 2) {
                return text;
            }
            const monthNames = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            const monthIndex = Number.parseInt(parts[1], 10) - 1;
            return monthNames[monthIndex] ? monthNames[monthIndex] + ' de ' + parts[0] : text;
        };

        const levelDescription = function (reading) {
            const level = String(reading.nivel ?? '').toUpperCase();
            const documentNumber = String(reading.numero_documento ?? '').trim();
            const documentLabel = documentNumber !== '' ? documentNumber : ('#' + String(reading.id_documento ?? ''));
            let className = 'alert alert-info mb-3';
            let message = 'La corrección se registrará con trazabilidad y no modificará datos hasta que la confirmes.';
            let allowed = reading.puede_aplicar === true;

            if (level === 'EDICION_SIMPLE') {
                className = 'alert alert-success mb-3';
                message = 'Todavía no existe un documento emitido. Al confirmar la solicitud se actualizarán la lectura, el consumo y el cobro calculado.';
            } else if (level === 'REGENERACION_CONTROLADA') {
                className = 'alert alert-warning mb-3';
                message = 'El documento ' + documentLabel + ' será versionado. Luego se recalcularán su detalle, total y saldo dentro de una sola transacción.';
            } else if (level === 'AUTORIZACION') {
                className = 'alert alert-warning mb-3';
                message = 'El período está cerrado o posee un asiento contable. La aplicación requerirá autorización; si existe asiento, se revertirá y regenerará con trazabilidad.';
            } else if (level === 'AJUSTE_FINANCIERO') {
                className = 'alert alert-danger mb-3';
                message = 'El documento tiene pagos, aplicaciones, respaldos o envíos asociados. No puede sobrescribirse: debe resolverse mediante un ajuste financiero formal.';
                allowed = false;
            } else if (level === 'REVISION') {
                className = 'alert alert-danger mb-3';
                message = 'El documento está anulado o no se encuentra disponible. La lectura requiere revisión antes de cualquier cambio.';
                allowed = false;
            }
            if (reading.puede_aplicar !== true && level !== 'AJUSTE_FINANCIERO' && level !== 'REVISION') {
                className = 'alert alert-danger mb-3';
                message = 'Tu usuario no posee el permiso requerido para autorizar esta corrección.';
                allowed = false;
            }
            levelAlert.className = className;
            levelAlert.textContent = message;
            return allowed;
        };

        const validateAndCalculate = function () {
            if (!activeReading) {
                submitButton.disabled = true;
                return false;
            }
            const previous = numberValue(activeReading.lectura_anterior);
            const current = numberValue(activeReading.lectura_actual);
            const rate = numberValue(activeReading.valor_kwh);
            const nextRaw = activeReading.lectura_siguiente;
            const hasNext = nextRaw !== null && nextRaw !== undefined && String(nextRaw) !== '';
            const next = hasNext ? numberValue(nextRaw) : null;
            const valueText = String(newReadingInput.value ?? '').trim();
            const newReading = valueText === '' ? Number.NaN : Number(valueText);
            let error = '';

            if (!Number.isFinite(newReading) || newReading < 0) {
                error = 'Ingresa una lectura nueva válida, igual o mayor que cero.';
            } else if (newReading < previous) {
                error = 'La lectura nueva no puede ser menor que la lectura anterior (' + displayNumber(previous, 4) + ').';
            } else if (hasNext && next !== null && newReading > next) {
                error = 'La lectura nueva no puede superar la lectura siguiente (' + displayNumber(next, 4) + ').';
            } else if (Math.abs(newReading - current) <= 0.0001) {
                error = 'La lectura nueva debe ser distinta de la registrada.';
            }

            const newConsumption = Number.isFinite(newReading) ? Math.max(0, newReading - previous) : 0;
            const newAmount = Math.round(newConsumption * rate * 100) / 100;
            const oldAmount = numberValue(activeReading.monto);
            const difference = Math.round((newAmount - oldAmount) * 100) / 100;
            const documentId = Number.parseInt(String(activeReading.id_documento ?? '0'), 10);
            const documentTotal = numberValue(activeReading.monto_documento);
            setText('electricity-consumption-new', Number.isFinite(newReading) ? displayNumber(newConsumption, 4) + ' kWh' : '-');
            setText('electricity-amount-new', Number.isFinite(newReading) ? displayAmount(newAmount) : '-');
            setText('electricity-difference', Number.isFinite(newReading) ? displayAmount(difference) : '-');
            setText('electricity-document-total-new', documentId > 0 && Number.isFinite(newReading)
                ? displayAmount(documentTotal + difference)
                : 'Sin documento emitido');

            const levelAllowed = levelDescription(activeReading);
            validationMessage.textContent = error;
            validationMessage.classList.toggle('d-none', error === '');
            submitButton.disabled = submissionStarted || error !== '' || !levelAllowed;
            return error === '' && levelAllowed;
        };

        const showReading = function (index) {
            activeReading = readings[index] ?? null;
            if (!activeReading) {
                submitButton.disabled = true;
                return;
            }
            const previous = numberValue(activeReading.lectura_anterior);
            const current = numberValue(activeReading.lectura_actual);
            const consumption = numberValue(activeReading.consumo);
            const rate = numberValue(activeReading.valor_kwh);
            const documentId = Number.parseInt(String(activeReading.id_documento ?? '0'), 10);
            const documentNumber = String(activeReading.numero_documento ?? '').trim();
            const nextRaw = activeReading.lectura_siguiente;
            const hasNext = nextRaw !== null && nextRaw !== undefined && String(nextRaw) !== '';

            setHidden('electricity-id-contrato', activeReading.id_contrato_arriendo);
            setHidden('electricity-id-local', activeReading.id_local);
            setHidden('electricity-id-lectura', activeReading.id_lectura);
            setHidden('electricity-periodo', activeReading.periodo);
            setHidden('electricity-valor-anterior', JSON.stringify({
                lectura_anterior: previous,
                lectura_actual: current,
                consumo: consumption
            }));
            setText('electricity-local-label', String(activeReading.local ?? '').trim() || '-');
            setText('electricity-meter-label', String(activeReading.medidor ?? '').trim() || '-');
            setText('electricity-period-label', periodLabel(activeReading.periodo));
            setText('electricity-document-label', documentId > 0
                ? (documentNumber !== '' ? documentNumber : ('#' + String(documentId)))
                : 'Sin documento emitido');
            setText('electricity-reading-previous', displayNumber(previous, 4));
            setText('electricity-reading-current', displayNumber(current, 4));
            setText('electricity-consumption-current', displayNumber(consumption, 4) + ' kWh');
            setText('electricity-rate-current', displayAmount(rate) + ' / kWh');
            setText('electricity-formula-current', displayNumber(consumption, 4) + ' × ' + displayAmount(rate));
            setText('electricity-amount-current', displayAmount(activeReading.monto));
            newReadingInput.value = String(current);
            document.getElementById('electricity-reading-limits').textContent = hasNext
                ? 'Rango permitido: ' + displayNumber(previous, 4) + ' a ' + displayNumber(nextRaw, 4) + '.'
                : 'Debe ser igual o mayor que ' + displayNumber(previous, 4) + '.';
            validateAndCalculate();
        };

        document.querySelectorAll('.js-electricity-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                let parsed = [];
                try {
                    const raw = button.getAttribute('data-electricity-readings') ?? '[]';
                    const decoded = JSON.parse(raw);
                    parsed = Array.isArray(decoded) ? decoded : [];
                } catch (_error) {
                    parsed = [];
                }
                readings = parsed.filter(function (reading) {
                    return reading && Number.parseInt(String(reading.id_lectura ?? '0'), 10) > 0;
                });
                if (readings.length === 0) {
                    return;
                }

                submissionStarted = false;
                submitButton.textContent = 'Registrar corrección';
                reasonInput.value = '';
                selector.innerHTML = '';
                readings.forEach(function (reading, index) {
                    const option = document.createElement('option');
                    option.value = String(index);
                    const meter = String(reading.medidor ?? '').trim();
                    const local = String(reading.local ?? '').trim();
                    option.textContent = (meter !== '' ? meter : ('Lectura #' + String(reading.id_lectura)))
                        + (local !== '' ? ' · ' + local : '');
                    selector.appendChild(option);
                });
                selectorWrap.classList.toggle('d-none', readings.length <= 1);
                selector.value = '0';
                showReading(0);
                modal.show();
                window.setTimeout(function () {
                    newReadingInput.focus();
                    newReadingInput.select();
                }, 200);
            });
        });

        selector.addEventListener('change', function () {
            const index = Number.parseInt(selector.value, 10);
            showReading(Number.isFinite(index) ? index : 0);
        });
        newReadingInput.addEventListener('input', validateAndCalculate);
        form.addEventListener('submit', function (event) {
            if (!validateAndCalculate()) {
                event.preventDefault();
                return;
            }
            submissionStarted = true;
            submitButton.disabled = true;
            submitButton.textContent = 'Registrando…';
        });
        modalElement.addEventListener('hidden.bs.modal', function () {
            readings = [];
            activeReading = null;
            submissionStarted = false;
            selector.innerHTML = '';
            form.reset();
            submitButton.textContent = 'Registrar corrección';
            validationMessage.classList.add('d-none');
        });
    }

    function initGasCorrection() {
        const modalElement = document.getElementById('gasCorrectionModal');
        const form = document.getElementById('gas-correction-form');
        const selectorWrap = document.getElementById('gas-reading-select-wrap');
        const selector = document.getElementById('gas-reading-select');
        const newReadingInput = document.getElementById('gas-new-reading');
        const reasonInput = document.getElementById('gas-reason');
        const submitButton = document.getElementById('gas-submit');
        const levelAlert = document.getElementById('gas-correction-level');
        const validationMessage = document.getElementById('gas-validation-message');
        if (!modalElement || !form || !selectorWrap || !selector || !newReadingInput
            || !reasonInput || !submitButton || !levelAlert || !validationMessage
            || typeof bootstrap === 'undefined') {
            return;
        }

        const modal = new bootstrap.Modal(modalElement);
        let readings = [];
        let activeReading = null;
        let submissionStarted = false;

        const numberValue = function (value) {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : 0;
        };
        const displayNumber = function (value, decimals) {
            return formatNumber(numberValue(value), decimals);
        };
        const displayAmount = function (value) {
            return '$ ' + displayNumber(value, 2);
        };
        const setText = function (id, value) {
            const node = document.getElementById(id);
            if (node) {
                node.textContent = String(value);
            }
        };
        const setHidden = function (id, value) {
            const node = document.getElementById(id);
            if (node) {
                node.value = String(value ?? '');
            }
        };
        const periodLabel = function (period) {
            const text = String(period ?? '');
            const parts = text.split('-');
            if (parts.length !== 2) {
                return text;
            }
            const monthNames = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            const monthIndex = Number.parseInt(parts[1], 10) - 1;
            return monthNames[monthIndex] ? monthNames[monthIndex] + ' de ' + parts[0] : text;
        };

        const levelDescription = function (reading) {
            const level = String(reading.nivel ?? '').toUpperCase();
            const documentNumber = String(reading.numero_documento ?? '').trim();
            const documentLabel = documentNumber !== '' ? documentNumber : ('#' + String(reading.id_documento ?? ''));
            let className = 'alert alert-info mb-3';
            let message = 'La corrección se registrará con trazabilidad y no modificará datos hasta que la confirmes.';
            let allowed = reading.puede_aplicar === true;

            if (level === 'EDICION_SIMPLE') {
                className = 'alert alert-success mb-3';
                message = 'Todavía no existe un documento emitido. Al confirmar la solicitud se actualizarán la lectura, el consumo y el cobro calculado.';
            } else if (level === 'REGENERACION_CONTROLADA') {
                className = 'alert alert-warning mb-3';
                message = 'El documento ' + documentLabel + ' será versionado. Luego se recalcularán su detalle, total y saldo dentro de una sola transacción.';
            } else if (level === 'AUTORIZACION') {
                className = 'alert alert-warning mb-3';
                message = 'El período está cerrado o posee un asiento contable. La aplicación requerirá autorización; si existe asiento, se revertirá y regenerará con trazabilidad.';
            } else if (level === 'AJUSTE_FINANCIERO') {
                className = 'alert alert-danger mb-3';
                message = 'El documento tiene pagos, aplicaciones, respaldos o envíos asociados. No puede sobrescribirse: debe resolverse mediante un ajuste financiero formal.';
                allowed = false;
            } else if (level === 'REVISION') {
                className = 'alert alert-danger mb-3';
                message = 'El documento está anulado o no se encuentra disponible. La lectura requiere revisión antes de cualquier cambio.';
                allowed = false;
            }
            if (reading.puede_aplicar !== true && level !== 'AJUSTE_FINANCIERO' && level !== 'REVISION') {
                className = 'alert alert-danger mb-3';
                message = 'Tu usuario no posee el permiso requerido para autorizar esta corrección.';
                allowed = false;
            }
            levelAlert.className = className;
            levelAlert.textContent = message;
            return allowed;
        };

        const validateAndCalculate = function () {
            if (!activeReading) {
                submitButton.disabled = true;
                return false;
            }
            const previous = numberValue(activeReading.lectura_anterior);
            const current = numberValue(activeReading.lectura_actual);
            const factor = numberValue(activeReading.factor);
            const literValue = numberValue(activeReading.valor_litro);
            const nextRaw = activeReading.lectura_siguiente;
            const hasNext = nextRaw !== null && nextRaw !== undefined && String(nextRaw) !== '';
            const next = hasNext ? numberValue(nextRaw) : null;
            const valueText = String(newReadingInput.value ?? '').trim();
            const newReading = valueText === '' ? Number.NaN : Number(valueText);
            let error = '';

            if (!Number.isFinite(newReading) || newReading < 0) {
                error = 'Ingresa una lectura nueva válida, igual o mayor que cero.';
            } else if (newReading < previous) {
                error = 'La lectura nueva no puede ser menor que la lectura anterior (' + displayNumber(previous, 4) + ').';
            } else if (hasNext && next !== null && newReading > next) {
                error = 'La lectura nueva no puede superar la lectura siguiente (' + displayNumber(next, 4) + ').';
            } else if (Math.abs(newReading - current) <= 0.0001) {
                error = 'La lectura nueva debe ser distinta de la registrada.';
            }

            const newConsumption = Number.isFinite(newReading) ? Math.max(0, newReading - previous) : 0;
            const newAmount = Math.round(newConsumption * factor * literValue * 100) / 100;
            const oldAmount = numberValue(activeReading.monto);
            const difference = Math.round((newAmount - oldAmount) * 100) / 100;
            const documentId = Number.parseInt(String(activeReading.id_documento ?? '0'), 10);
            const documentTotal = numberValue(activeReading.monto_documento);
            setText('gas-consumption-new', Number.isFinite(newReading) ? displayNumber(newConsumption, 4) + ' unid.' : '-');
            setText('gas-amount-new', Number.isFinite(newReading) ? displayAmount(newAmount) : '-');
            setText('gas-difference', Number.isFinite(newReading) ? displayAmount(difference) : '-');
            setText('gas-document-total-new', documentId > 0 && Number.isFinite(newReading)
                ? displayAmount(documentTotal + difference)
                : 'Sin documento emitido');

            const levelAllowed = levelDescription(activeReading);
            validationMessage.textContent = error;
            validationMessage.classList.toggle('d-none', error === '');
            submitButton.disabled = submissionStarted || error !== '' || !levelAllowed;
            return error === '' && levelAllowed;
        };

        const showReading = function (index) {
            activeReading = readings[index] ?? null;
            if (!activeReading) {
                submitButton.disabled = true;
                return;
            }
            const previous = numberValue(activeReading.lectura_anterior);
            const current = numberValue(activeReading.lectura_actual);
            const consumption = numberValue(activeReading.consumo);
            const factor = numberValue(activeReading.factor);
            const literValue = numberValue(activeReading.valor_litro);
            const documentId = Number.parseInt(String(activeReading.id_documento ?? '0'), 10);
            const documentNumber = String(activeReading.numero_documento ?? '').trim();
            const nextRaw = activeReading.lectura_siguiente;
            const hasNext = nextRaw !== null && nextRaw !== undefined && String(nextRaw) !== '';

            setHidden('gas-id-contrato', activeReading.id_contrato_arriendo);
            setHidden('gas-id-local', activeReading.id_local);
            setHidden('gas-id-lectura', activeReading.id_lectura);
            setHidden('gas-periodo', activeReading.periodo);
            setHidden('gas-valor-anterior', JSON.stringify({
                lectura_anterior: previous,
                lectura_actual: current,
                consumo: consumption
            }));
            setText('gas-local-label', String(activeReading.local ?? '').trim() || '-');
            setText('gas-meter-label', String(activeReading.medidor ?? '').trim() || '-');
            setText('gas-period-label', periodLabel(activeReading.periodo));
            setText('gas-document-label', documentId > 0
                ? (documentNumber !== '' ? documentNumber : ('#' + String(documentId)))
                : 'Sin documento emitido');
            setText('gas-reading-previous', displayNumber(previous, 4));
            setText('gas-reading-current', displayNumber(current, 4));
            setText('gas-consumption-current', displayNumber(consumption, 4) + ' unid.');
            setText('gas-factor-current', displayNumber(factor, 6));
            setText('gas-liter-value-current', displayAmount(literValue) + ' / litro');
            setText('gas-formula-current', displayNumber(consumption, 4) + ' × ' + displayNumber(factor, 6) + ' × ' + displayAmount(literValue));
            setText('gas-amount-current', displayAmount(activeReading.monto));
            newReadingInput.value = String(current);
            document.getElementById('gas-reading-limits').textContent = hasNext
                ? 'Rango permitido: ' + displayNumber(previous, 4) + ' a ' + displayNumber(nextRaw, 4) + '.'
                : 'Debe ser igual o mayor que ' + displayNumber(previous, 4) + '.';
            validateAndCalculate();
        };

        document.querySelectorAll('.js-gas-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                let parsed = [];
                try {
                    const raw = button.getAttribute('data-gas-readings') ?? '[]';
                    const decoded = JSON.parse(raw);
                    parsed = Array.isArray(decoded) ? decoded : [];
                } catch (_error) {
                    parsed = [];
                }
                readings = parsed.filter(function (reading) {
                    return reading && Number.parseInt(String(reading.id_lectura ?? '0'), 10) > 0;
                });
                if (readings.length === 0) {
                    return;
                }

                submissionStarted = false;
                submitButton.textContent = 'Registrar corrección';
                reasonInput.value = '';
                selector.innerHTML = '';
                readings.forEach(function (reading, index) {
                    const option = document.createElement('option');
                    option.value = String(index);
                    const meter = String(reading.medidor ?? '').trim();
                    const local = String(reading.local ?? '').trim();
                    option.textContent = (meter !== '' ? meter : ('Lectura #' + String(reading.id_lectura)))
                        + (local !== '' ? ' · ' + local : '');
                    selector.appendChild(option);
                });
                selectorWrap.classList.toggle('d-none', readings.length <= 1);
                selector.value = '0';
                showReading(0);
                modal.show();
                window.setTimeout(function () {
                    newReadingInput.focus();
                    newReadingInput.select();
                }, 200);
            });
        });

        selector.addEventListener('change', function () {
            const index = Number.parseInt(selector.value, 10);
            showReading(Number.isFinite(index) ? index : 0);
        });
        newReadingInput.addEventListener('input', validateAndCalculate);
        form.addEventListener('submit', function (event) {
            if (!validateAndCalculate()) {
                event.preventDefault();
                return;
            }
            submissionStarted = true;
            submitButton.disabled = true;
            submitButton.textContent = 'Registrando…';
        });
        modalElement.addEventListener('hidden.bs.modal', function () {
            readings = [];
            activeReading = null;
            submissionStarted = false;
            selector.innerHTML = '';
            form.reset();
            submitButton.textContent = 'Registrar corrección';
            validationMessage.classList.add('d-none');
        });
    }

    function initWaterCorrection() {
        const modalElement = document.getElementById('waterCorrectionModal');
        const form = document.getElementById('water-correction-form');
        const selectorWrap = document.getElementById('water-reading-select-wrap');
        const selector = document.getElementById('water-reading-select');
        const newReadingInput = document.getElementById('water-new-reading');
        const reasonInput = document.getElementById('water-reason');
        const submitButton = document.getElementById('water-submit');
        const levelAlert = document.getElementById('water-correction-level');
        const validationMessage = document.getElementById('water-validation-message');
        if (!modalElement || !form || !selectorWrap || !selector || !newReadingInput
            || !reasonInput || !submitButton || !levelAlert || !validationMessage
            || typeof bootstrap === 'undefined') {
            return;
        }

        const modal = new bootstrap.Modal(modalElement);
        let readings = [];
        let activeReading = null;
        let submissionStarted = false;
        const numberValue = function (value) {
            const parsed = Number(value);
            return Number.isFinite(parsed) ? parsed : 0;
        };
        const displayNumber = function (value, decimals) {
            return formatNumber(numberValue(value), decimals);
        };
        const displayAmount = function (value) {
            return '$ ' + displayNumber(value, 2);
        };
        const setText = function (id, value) {
            const node = document.getElementById(id);
            if (node) {
                node.textContent = String(value);
            }
        };
        const setHidden = function (id, value) {
            const node = document.getElementById(id);
            if (node) {
                node.value = String(value ?? '');
            }
        };
        const periodLabel = function (period) {
            const text = String(period ?? '');
            const parts = text.split('-');
            if (parts.length !== 2) {
                return text;
            }
            const monthNames = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
            const monthIndex = Number.parseInt(parts[1], 10) - 1;
            return monthNames[monthIndex] ? monthNames[monthIndex] + ' de ' + parts[0] : text;
        };
        const consumptionPeriodLabel = function (reading) {
            const from = String(reading.periodo_consumo_desde ?? '').trim();
            const to = String(reading.periodo_consumo_hasta ?? '').trim();
            if (from !== '' && to !== '') {
                return from + ' al ' + to;
            }
            return to !== '' ? ('Hasta ' + to) : (from !== '' ? ('Desde ' + from) : 'Sin fechas informadas');
        };

        const levelDescription = function (reading) {
            const level = String(reading.nivel ?? '').toUpperCase();
            const documentNumber = String(reading.numero_documento ?? '').trim();
            const documentLabel = documentNumber !== '' ? documentNumber : ('#' + String(reading.id_documento ?? ''));
            let className = 'alert alert-info mb-3';
            let message = 'La corrección se registrará con trazabilidad y no modificará datos hasta que la confirmes.';
            let allowed = reading.puede_aplicar === true;
            if (level === 'EDICION_SIMPLE') {
                className = 'alert alert-success mb-3';
                message = 'Todavía no existe un documento emitido. Al confirmar se actualizarán la lectura, el consumo y el cobro de agua.';
            } else if (level === 'REGENERACION_CONTROLADA') {
                className = 'alert alert-warning mb-3';
                message = 'El documento ' + documentLabel + ' será versionado y su detalle, total y saldo se recalcularán en una sola transacción.';
            } else if (level === 'AUTORIZACION') {
                className = 'alert alert-warning mb-3';
                message = 'El período está cerrado o posee asiento contable. La aplicación requiere autorización y regeneración contable trazable.';
            } else if (level === 'AJUSTE_FINANCIERO') {
                className = 'alert alert-danger mb-3';
                message = 'El documento tiene pagos, aplicaciones, respaldos o envíos asociados. Debe resolverse con un ajuste financiero formal.';
                allowed = false;
            } else if (level === 'REVISION') {
                className = 'alert alert-danger mb-3';
                message = 'El documento está anulado o no está disponible. La lectura requiere revisión antes de cualquier cambio.';
                allowed = false;
            }
            if (reading.puede_aplicar !== true && level !== 'AJUSTE_FINANCIERO' && level !== 'REVISION') {
                className = 'alert alert-danger mb-3';
                message = 'Tu usuario no posee el permiso requerido para autorizar esta corrección.';
                allowed = false;
            }
            levelAlert.className = className;
            levelAlert.textContent = message;
            return allowed;
        };

        const calculate = function (reading, newReading) {
            const previous = numberValue(reading.lectura_anterior);
            const divisor = numberValue(reading.divisor);
            const variableRate = divisor > 0
                ? (numberValue(reading.servicio_agua_potable)
                    + numberValue(reading.servicio_alcantarillado)
                    + numberValue(reading.tratamiento_aguas_servidas)) / divisor
                : 0;
            const consumption = Math.max(0, newReading - previous);
            const variableSubtotal = Math.round(consumption * variableRate * 100) / 100;
            const fixedCharge = Math.round(numberValue(reading.cargo_fijo) * 100) / 100;
            return {
                consumption: consumption,
                rate: variableRate,
                variableSubtotal: variableSubtotal,
                fixedCharge: fixedCharge,
                total: Math.round((variableSubtotal + fixedCharge) * 100) / 100
            };
        };

        const validateAndCalculate = function () {
            if (!activeReading) {
                submitButton.disabled = true;
                return false;
            }
            const previous = numberValue(activeReading.lectura_anterior);
            const current = numberValue(activeReading.lectura_actual);
            const nextRaw = activeReading.lectura_siguiente;
            const hasNext = nextRaw !== null && nextRaw !== undefined && String(nextRaw) !== '';
            const next = hasNext ? numberValue(nextRaw) : null;
            const valueText = String(newReadingInput.value ?? '').trim();
            const newReading = valueText === '' ? Number.NaN : Number(valueText);
            let error = '';
            if (!Number.isFinite(newReading) || newReading < 0) {
                error = 'Ingresa una lectura nueva válida, igual o mayor que cero.';
            } else if (newReading < previous) {
                error = 'La lectura nueva no puede ser menor que la lectura anterior (' + displayNumber(previous, 4) + ').';
            } else if (hasNext && next !== null && newReading > next) {
                error = 'La lectura nueva no puede superar la lectura siguiente (' + displayNumber(next, 4) + ').';
            } else if (Math.abs(newReading - current) <= 0.0001) {
                error = 'La lectura nueva debe ser distinta de la registrada.';
            } else if (numberValue(activeReading.divisor) <= 0) {
                error = 'Los parámetros de agua no tienen un divisor válido.';
            }

            const result = calculate(activeReading, Number.isFinite(newReading) ? newReading : previous);
            const oldAmount = numberValue(activeReading.monto);
            const difference = Math.round((result.total - oldAmount) * 100) / 100;
            const documentId = Number.parseInt(String(activeReading.id_documento ?? '0'), 10);
            const documentTotal = numberValue(activeReading.monto_documento);
            setText('water-consumption-new', Number.isFinite(newReading) ? displayNumber(result.consumption, 4) + ' m³' : '-');
            setText('water-variable-new', Number.isFinite(newReading) ? displayAmount(result.variableSubtotal) : '-');
            setText('water-fixed-new', Number.isFinite(newReading) ? displayAmount(result.fixedCharge) : '-');
            setText('water-amount-new', Number.isFinite(newReading) ? displayAmount(result.total) : '-');
            setText('water-difference', Number.isFinite(newReading) ? displayAmount(difference) : '-');
            setText('water-document-total-new', documentId > 0 && Number.isFinite(newReading)
                ? displayAmount(documentTotal + difference)
                : 'Sin documento emitido');

            const levelAllowed = levelDescription(activeReading);
            validationMessage.textContent = error;
            validationMessage.classList.toggle('d-none', error === '');
            submitButton.disabled = submissionStarted || error !== '' || !levelAllowed;
            return error === '' && levelAllowed;
        };

        const showReading = function (index) {
            activeReading = readings[index] ?? null;
            if (!activeReading) {
                submitButton.disabled = true;
                return;
            }
            const previous = numberValue(activeReading.lectura_anterior);
            const current = numberValue(activeReading.lectura_actual);
            const currentResult = calculate(activeReading, current);
            const documentId = Number.parseInt(String(activeReading.id_documento ?? '0'), 10);
            const documentNumber = String(activeReading.numero_documento ?? '').trim();
            const nextRaw = activeReading.lectura_siguiente;
            const hasNext = nextRaw !== null && nextRaw !== undefined && String(nextRaw) !== '';

            setHidden('water-id-contrato', activeReading.id_contrato_arriendo);
            setHidden('water-id-local', activeReading.id_local);
            setHidden('water-id-lectura', activeReading.id_lectura);
            setHidden('water-periodo', activeReading.periodo);
            setHidden('water-valor-anterior', JSON.stringify({
                lectura_anterior: previous,
                lectura_actual: current,
                consumo: numberValue(activeReading.consumo)
            }));
            setText('water-local-label', String(activeReading.local ?? '').trim() || '-');
            setText('water-meter-label', String(activeReading.medidor ?? '').trim() || '-');
            setText('water-period-label', periodLabel(activeReading.periodo));
            setText('water-consumption-period-label', consumptionPeriodLabel(activeReading));
            setText('water-document-label', documentId > 0
                ? (documentNumber !== '' ? documentNumber : ('#' + String(documentId)))
                : 'Sin documento emitido');
            setText('water-reading-previous', displayNumber(previous, 4));
            setText('water-reading-current', displayNumber(current, 4));
            setText('water-consumption-current', displayNumber(activeReading.consumo, 4) + ' m³');
            setText('water-rate-current', displayAmount(currentResult.rate) + ' / m³');
            setText('water-variable-current', displayAmount(currentResult.variableSubtotal));
            setText('water-fixed-current', displayAmount(currentResult.fixedCharge));
            setText('water-formula-current', displayNumber(activeReading.consumo, 4)
                + ' × ' + displayAmount(currentResult.rate) + ' + ' + displayAmount(currentResult.fixedCharge));
            setText('water-amount-current', displayAmount(activeReading.monto));
            newReadingInput.value = String(current);
            const limits = document.getElementById('water-reading-limits');
            if (limits) {
                limits.textContent = hasNext
                    ? 'Rango permitido: ' + displayNumber(previous, 4) + ' a ' + displayNumber(nextRaw, 4) + '.'
                    : 'Debe ser igual o mayor que ' + displayNumber(previous, 4) + '.';
            }
            validateAndCalculate();
        };

        document.querySelectorAll('.js-water-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                let parsed = [];
                try {
                    const raw = button.getAttribute('data-water-readings') ?? '[]';
                    const decoded = JSON.parse(raw);
                    parsed = Array.isArray(decoded) ? decoded : [];
                } catch (_error) {
                    parsed = [];
                }
                readings = parsed.filter(function (reading) {
                    return reading && Number.parseInt(String(reading.id_lectura ?? '0'), 10) > 0;
                });
                if (readings.length === 0) {
                    return;
                }
                submissionStarted = false;
                submitButton.textContent = 'Registrar corrección';
                reasonInput.value = '';
                selector.innerHTML = '';
                readings.forEach(function (reading, index) {
                    const option = document.createElement('option');
                    option.value = String(index);
                    const meter = String(reading.medidor ?? '').trim();
                    const local = String(reading.local ?? '').trim();
                    option.textContent = (meter !== '' ? meter : ('Lectura #' + String(reading.id_lectura)))
                        + (local !== '' ? ' · ' + local : '');
                    selector.appendChild(option);
                });
                selectorWrap.classList.toggle('d-none', readings.length <= 1);
                selector.value = '0';
                showReading(0);
                modal.show();
                window.setTimeout(function () {
                    newReadingInput.focus();
                    newReadingInput.select();
                }, 200);
            });
        });
        selector.addEventListener('change', function () {
            const index = Number.parseInt(selector.value, 10);
            showReading(Number.isFinite(index) ? index : 0);
        });
        newReadingInput.addEventListener('input', validateAndCalculate);
        form.addEventListener('submit', function (event) {
            if (!validateAndCalculate()) {
                event.preventDefault();
                return;
            }
            submissionStarted = true;
            submitButton.disabled = true;
            submitButton.textContent = 'Registrando…';
        });
        modalElement.addEventListener('hidden.bs.modal', function () {
            readings = [];
            activeReading = null;
            submissionStarted = false;
            selector.innerHTML = '';
            form.reset();
            submitButton.textContent = 'Registrar corrección';
            validationMessage.classList.add('d-none');
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
            initRentCorrection();
            markFront('init_rent_correction_deferred');
            initElectricityCorrection();
            markFront('init_electricity_correction_deferred');
            initGasCorrection();
            markFront('init_gas_correction_deferred');
            initWaterCorrection();
            markFront('init_water_correction_deferred');
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
<?php require_once dirname(__DIR__, 2) . '/templates/components/page_navigation.php'; ?>
</body>
</html>
