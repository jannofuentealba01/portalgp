<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

function msp2ValeRequireDompdf(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoloadPath)) {
        throw new RuntimeException('No se encontro vendor/autoload.php para cargar DomPDF.');
    }

    require_once $autoloadPath;

    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new RuntimeException('DomPDF no esta disponible en el proyecto.');
    }

    $loaded = true;
}

function valeMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 2, ',', '.');
}

function valeMontoPayable(mixed $value): string
{
    $num = max(0.0, (float) $value);
    $roundedUp = ceil($num);
    return '$ ' . number_format($roundedUp, 0, ',', '.');
}

function valeFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $parsed ? $parsed->format('d-m-Y') : (string) $value;
}

function valePeriodo(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $parsed ? $parsed->format('m-Y') : (string) $value;
}

function valePeriodoNombre(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    if (!$parsed) {
        $periodoYm = substr($value, 0, 7);
        $parsed = DateTime::createFromFormat('!Y-m', $periodoYm);
        if ($parsed && $parsed->format('Y-m') !== $periodoYm) {
            $parsed = false;
        }
    }
    if (!$parsed) {
        return (string) $value;
    }

    $months = [
        1 => 'ENERO',
        2 => 'FEBRERO',
        3 => 'MARZO',
        4 => 'ABRIL',
        5 => 'MAYO',
        6 => 'JUNIO',
        7 => 'JULIO',
        8 => 'AGOSTO',
        9 => 'SEPTIEMBRE',
        10 => 'OCTUBRE',
        11 => 'NOVIEMBRE',
        12 => 'DICIEMBRE',
    ];

    $monthNum = (int) $parsed->format('n');
    $monthName = $months[$monthNum] ?? $parsed->format('m');
    return $monthName . ' ' . $parsed->format('Y');
}

function valePeriodoArchivo(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'sin_periodo';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    if ($parsed) {
        return $parsed->format('Y-m');
    }

    $fallback = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim((string) $value));
    return $fallback !== null && $fallback !== '' ? $fallback : 'sin_periodo';
}

function valeLocalLabel(?string $value): ?string
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }

    $text = preg_replace('/[^A-Za-z0-9-]+/', '', $text) ?? '';
    return $text !== '' ? $text : null;
}

function valeBuildFilename(array $documento, array $arriendoDetalles, array $electricidadDetalles, array $gasDetalles = [], array $aguaDetalles = []): string
{
    $periodo = valePeriodoArchivo((string) ($documento['periodo_facturacion'] ?? ''));
    $locals = [];

    foreach ([$arriendoDetalles, $electricidadDetalles, $gasDetalles, $aguaDetalles] as $detalles) {
        foreach ($detalles as $detalle) {
            $label = valeLocalLabel((string) ($detalle['cdo_local'] ?? ''));
            if ($label !== null) {
                $locals[$label] = true;
            }
        }
    }

    $parts = [$periodo, 'vale_cobro'];
    $localLabels = array_keys($locals);

    if ($localLabels !== []) {
        $filenameBase = implode('_', $parts);
        $extraCount = 0;
        foreach ($localLabels as $index => $label) {
            $candidate = $filenameBase . '_' . $label;
            if (strlen($candidate) > 180) {
                $extraCount = count($localLabels) - $index;
                break;
            }
            $parts[] = $label;
            $filenameBase = $candidate;
        }
        if ($extraCount > 0) {
            $parts[] = 'y_' . $extraCount . '_mas';
        }
    }

    return implode('_', $parts) . '.pdf';
}

function msp2DocumentoCobroValeData(PDO $conn, int $idDocumento): array
{
    $requiredTables = [
        'msp_documentos_cobro',
        'msp_documentos_cobro_detalle',
        'msp_tiendas',
        'msp_locales',
    ];

    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla `' . $tableName . '`.');
        }
    }

    $stmtDocumento = $conn->prepare(
        "SELECT
            dc.id_documento_cobro,
            dc.id_tienda,
            dc.numero_documento,
            dc.periodo_facturacion,
            dc.fecha_emision,
            dc.fecha_vencimiento,
            dc.nombre_arrendatario_snapshot,
            dc.rut_arrendatario_snapshot,
            dc.nombre_tienda_snapshot,
            dc.subtotal_arriendo,
            dc.subtotal_servicios,
            dc.monto_total
         FROM dbo.msp_documentos_cobro dc
         WHERE dc.id_documento_cobro = :id"
    );
    $stmtDocumento->bindValue(':id', $idDocumento, PDO::PARAM_INT);
    $stmtDocumento->execute();
    $documento = $stmtDocumento->fetch();

    if ($documento === false) {
        throw new RuntimeException('El documento solicitado no existe.');
    }

    $stmtArriendo = $conn->prepare(
        "WITH detalle_arriendo AS (
            SELECT
                dcd.orden_item,
                dcd.descripcion_item,
                dcd.subtotal,
                CASE
                    WHEN dcd.descripcion_item LIKE N'Arriendo local %'
                        THEN LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo local ') + 1, 200))
                    WHEN dcd.descripcion_item LIKE N'Arriendo %'
                        THEN LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo ') + 1, 200))
                    ELSE NULL
                END AS cdo_local
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
            WHERE dcd.id_documento_cobro = :id
              AND tid.codigo_item = N'ARRIENDO'
        )
        SELECT
            da.orden_item,
            ISNULL(loc.cdo_local, ISNULL(da.cdo_local, N'-')) AS cdo_local,
            loc.metros_cuadrados,
            da.descripcion_item,
            da.subtotal AS valor_neto
        FROM detalle_arriendo da
        LEFT JOIN dbo.msp_locales loc
            ON loc.cdo_local = da.cdo_local
        ORDER BY da.orden_item ASC"
    );
    $stmtArriendo->bindValue(':id', $idDocumento, PDO::PARAM_INT);
    $stmtArriendo->execute();
    $arriendoDetalles = $stmtArriendo->fetchAll();
    if (
        $arriendoDetalles === []
        && (float) ($documento['subtotal_arriendo'] ?? 0) > 0.005
        && msp2TableExists($conn, 'msp_cierre_mensual')
        && msp2TableExists($conn, 'msp_contratos_arriendo')
        && msp2TableExists($conn, 'msp_contrato_locales')
    ) {
        $stmtArriendoFallback = $conn->prepare(
            "DECLARE @periodo DATE = :periodo;
             SELECT
                ROW_NUMBER() OVER (ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ") AS orden_item,
                loc.cdo_local,
                loc.metros_cuadrados,
                CONCAT(N'Arriendo local ', loc.cdo_local) AS descripcion_item,
                CASE
                    WHEN UPPER(LTRIM(RTRIM(loc.cdo_local))) IN (N'OBRA', N'MODULAR') THEN CAST(140000 AS DECIMAL(18,2))
                    ELSE ROUND(ISNULL(loc.valor_arriendo_uf, 0) * ISNULL(cm.valor_uf, 0), 2)
                END AS valor_neto
             FROM dbo.msp_contratos_arriendo ca
             INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
               AND cl.estado_relacion IN (1,2)
               AND cl.fecha_inicio <= EOMONTH(@periodo)
               AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
             INNER JOIN dbo.msp_locales loc
                ON loc.id_local = cl.id_local
             LEFT JOIN dbo.msp_cierre_mensual cm
                ON cm.periodo_facturacion = @periodo
             WHERE ca.id_tienda = :id_tienda
               AND ca.fecha_inicio <= EOMONTH(@periodo)
               AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
               AND ca.estado_contrato IN (1,2,3)"
        );
        $stmtArriendoFallback->bindValue(':periodo', (string) ($documento['periodo_facturacion'] ?? ''), PDO::PARAM_STR);
        $stmtArriendoFallback->bindValue(':id_tienda', (int) ($documento['id_tienda'] ?? 0), PDO::PARAM_INT);
        $stmtArriendoFallback->execute();
        $arriendoFallback = $stmtArriendoFallback->fetchAll() ?: [];
        if ($arriendoFallback !== []) {
            $arriendoDetalles = $arriendoFallback;
        }
    }

    $stmtServicios = $conn->prepare(
        "SELECT
            dcd.orden_item,
            tid.codigo_item,
            dcd.descripcion_item,
            dcd.subtotal
         FROM dbo.msp_documentos_cobro_detalle dcd
         INNER JOIN dbo.msp_tipo_item_documento tid
            ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
         WHERE dcd.id_documento_cobro = :id
           AND tid.codigo_item <> N'ARRIENDO'
         ORDER BY dcd.orden_item ASC, dcd.id_detalle_documento ASC"
    );
    $stmtServicios->bindValue(':id', $idDocumento, PDO::PARAM_INT);
    $stmtServicios->execute();
    $servicioDetalles = $stmtServicios->fetchAll();

    $stmtElectricidad = $conn->prepare(
        "SELECT
            loc.cdo_local,
            lm.lectura_anterior,
            lm.lectura_actual,
            cs.consumo_cobrado,
            ISNULL(pl.valor_kwh, 0) AS valor_kwh,
            cs.monto_total
         FROM dbo.msp_cobros_servicios cs
         INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
         INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
         INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
         INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         INNER JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_local = loc.id_local
           AND ol.id_tienda = :id_tienda
           AND ol.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
           AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
         LEFT JOIN dbo.msp_proceso_cobro_luz pl
            ON pl.id_proceso_cobro = p.id_proceso_cobro
         WHERE lm.periodo_facturacion = :periodo
           AND ts.codigo_servicio = N'LUZ'
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local')
    );
    $stmtElectricidad->bindValue(':id_tienda', (int) ($documento['id_tienda'] ?? 0), PDO::PARAM_INT);
    $stmtElectricidad->bindValue(':periodo', (string) ($documento['periodo_facturacion'] ?? ''), PDO::PARAM_STR);
    $stmtElectricidad->execute();
    $electricidadDetalles = $stmtElectricidad->fetchAll();

    $stmtGas = $conn->prepare(
        "SELECT
            loc.cdo_local,
            lm.lectura_anterior,
            lm.lectura_actual,
            cs.consumo_cobrado,
            ISNULL(pg.factor, 0) AS factor,
            ISNULL(pg.valor_litro, 0) AS valor_litro,
            cs.monto_total
         FROM dbo.msp_cobros_servicios cs
         INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
         INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
         INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
         INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         INNER JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_local = loc.id_local
           AND ol.id_tienda = :id_tienda
           AND ol.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
           AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
         LEFT JOIN dbo.msp_proceso_cobro_gas pg
            ON pg.id_proceso_cobro = p.id_proceso_cobro
         WHERE lm.periodo_facturacion = :periodo
           AND ts.codigo_servicio = N'GAS'
           AND ISNULL(cs.consumo_cobrado, 0) > 0
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local')
    );
    $stmtGas->bindValue(':id_tienda', (int) ($documento['id_tienda'] ?? 0), PDO::PARAM_INT);
    $stmtGas->bindValue(':periodo', (string) ($documento['periodo_facturacion'] ?? ''), PDO::PARAM_STR);
    $stmtGas->execute();
    $gasDetalles = $stmtGas->fetchAll();

    $stmtAgua = $conn->prepare(
        "SELECT
            loc.cdo_local,
            lm.lectura_anterior,
            lm.lectura_actual,
            cs.consumo_cobrado,
            cs.monto_total,
            cs.parametros_snapshot,
            m.codigo_medidor,
            m.id_medidor
         FROM dbo.msp_cobros_servicios cs
         INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
         INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
         INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
         INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
         INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         INNER JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_local = loc.id_local
           AND ol.id_tienda = :id_tienda
           AND ol.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
           AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
         WHERE lm.periodo_facturacion = :periodo
           AND ts.codigo_servicio = N'AGUA'
         ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local')
    );
    $stmtAgua->bindValue(':id_tienda', (int) ($documento['id_tienda'] ?? 0), PDO::PARAM_INT);
    $stmtAgua->bindValue(':periodo', (string) ($documento['periodo_facturacion'] ?? ''), PDO::PARAM_STR);
    $stmtAgua->execute();
    $aguaDetalles = $stmtAgua->fetchAll();

    $ivaArriendo = round((float) ($documento['subtotal_arriendo'] ?? 0) * 0.19, 2);
    $totalElectricidad = 0.0;
    foreach ($electricidadDetalles as $electricidad) {
        $totalElectricidad += (float) ($electricidad['monto_total'] ?? 0);
    }
    $totalElectricidad = round($totalElectricidad, 2);
    $totalGas = 0.0;
    foreach ($gasDetalles as $gas) {
        $totalGas += (float) ($gas['monto_total'] ?? 0);
    }
    $totalGas = round($totalGas, 2);
    $totalAgua = 0.0;
    foreach ($aguaDetalles as $agua) {
        $totalAgua += (float) ($agua['monto_total'] ?? 0);
    }
    $totalAgua = round($totalAgua, 2);
    $otrosCargosDetalles = [];
    $totalOtrosCargos = 0.0;
    foreach ($servicioDetalles as $detalleServicio) {
        $codigo = strtoupper(trim((string) ($detalleServicio['codigo_item'] ?? '')));
        if (in_array($codigo, ['SERVICIO_LUZ', 'SERVICIO_GAS', 'SERVICIO_AGUA'], true)) {
            continue;
        }
        $subtotalDetalle = (float) ($detalleServicio['subtotal'] ?? 0);
        $otrosCargosDetalles[] = $detalleServicio;
        $totalOtrosCargos += $subtotalDetalle;
    }
    $totalOtrosCargos = round($totalOtrosCargos, 2);
    $montoTotalDocumento = round((float) ($documento['monto_total'] ?? 0), 2);

    return [
        'documento' => $documento,
        'arriendo' => $arriendoDetalles,
        'electricidad' => $electricidadDetalles,
        'gas' => $gasDetalles,
        'agua' => $aguaDetalles,
        'otros' => $otrosCargosDetalles,
        'iva_arriendo' => $ivaArriendo,
        'total_electricidad' => $totalElectricidad,
        'total_gas' => $totalGas,
        'total_agua' => $totalAgua,
        'total_otros' => $totalOtrosCargos,
        'monto_total' => $montoTotalDocumento,
    ];
}

function msp2DocumentoCobroValeHtml(array $data): string
{
    $documento = $data['documento'];
    $arriendoDetalles = $data['arriendo'];
    $electricidadDetalles = $data['electricidad'];
    $gasDetalles = $data['gas'];
    $aguaDetalles = $data['agua'];
    $otrosCargosDetalles = $data['otros'];
    $ivaArriendo = (float) $data['iva_arriendo'];
    $totalElectricidad = (float) $data['total_electricidad'];
    $totalGas = (float) $data['total_gas'];
    $totalAgua = (float) $data['total_agua'];
    $totalOtrosCargos = (float) $data['total_otros'];
    $montoTotalDocumento = (float) $data['monto_total'];
    $totalPagarRedondeado = max(0.0, ceil($montoTotalDocumento));
    $logoPath = __DIR__ . '/../assets/logo_msp.jpg';
    $logoDataUri = '';
    if (is_file($logoPath)) {
        $logoRaw = @file_get_contents($logoPath);
        if ($logoRaw !== false) {
            $logoDataUri = 'data:image/jpeg;base64,' . base64_encode($logoRaw);
        }
    }

    $obraKey = null;
    $modularKey = null;
    foreach ($arriendoDetalles as $detalle) {
        $localRaw = trim((string) ($detalle['cdo_local'] ?? ''));
        if ($localRaw === '') {
            continue;
        }
        $localNorm = mb_strtoupper($localRaw, 'UTF-8');
        if ($localNorm === 'OBRA') {
            $obraKey = $localRaw;
        } elseif ($localNorm === 'MODULAR') {
            $modularKey = $localRaw;
        }
    }
    $mergeObraModular = $obraKey !== null && $modularKey !== null;
    $arriendoDisplay = [];
    if ($mergeObraModular) {
        foreach ($arriendoDetalles as $detalle) {
            $localRaw = trim((string) ($detalle['cdo_local'] ?? ''));
            $localNorm = mb_strtoupper($localRaw, 'UTF-8');
            $key = ($localNorm === 'OBRA' || $localNorm === 'MODULAR') ? 'OBRA/MODULAR' : ($localRaw !== '' ? $localRaw : '-');
            if (!isset($arriendoDisplay[$key])) {
                $arriendoDisplay[$key] = [
                    'cdo_local' => $key,
                    'metros_cuadrados' => 0.0,
                    'valor_neto' => 0.0,
                ];
            }
            $arriendoDisplay[$key]['metros_cuadrados'] += (float) ($detalle['metros_cuadrados'] ?? 0);
            $arriendoDisplay[$key]['valor_neto'] += (float) ($detalle['valor_neto'] ?? 0);
        }
    } else {
        foreach ($arriendoDetalles as $detalle) {
            $arriendoDisplay[] = $detalle;
        }
    }
    if ($mergeObraModular) {
        $arriendoDisplay = array_values($arriendoDisplay);
    }

    $html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Vale Informativo</title>
    <style>
        @page { margin: 28px 24px; }
        body { font-family: "Segoe UI", DejaVu Sans, sans-serif; color: #1f2937; font-size: 12px; line-height: 1.45; }
        .header { border-bottom: 3px solid #0b3a6e; padding-bottom: 14px; margin-bottom: 20px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; }
        .header-left { padding-right: 16px; width: 22%; }
        .header-right { width: 78%; }
        .logo { width: 90px; height: auto; display: block; margin-bottom: 8px; }
        .header-docbox { border: 1px solid #d7dee8; border-radius: 8px; padding: 10px 12px; background: #f8fafc; margin-top: 10px; }
        .header-docbox-table { width: 100%; border-collapse: collapse; }
        .header-docbox-table td { vertical-align: top; padding: 2px 8px; }
        .brand-kicker { color: #5b6778; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 4px; }
        .title { font-size: 22px; font-weight: bold; margin: 0 0 6px 0; color: #0b3a6e; }
        .meta-label { font-size: 10px; text-transform: uppercase; color: #5b6778; letter-spacing: 0.05em; margin-bottom: 6px; }
        .meta-strong { font-size: 14px; font-weight: 600; color: #1f2937; margin-bottom: 2px; }
        .meta-line { font-size: 12px; color: #1f2937; margin-bottom: 2px; }
        .meta-inline { margin-top: 6px; font-size: 11px; color: #5b6778; }
        .box { border: 1px solid #d7dee8; border-radius: 8px; padding: 12px 14px; margin-bottom: 18px; background: #ffffff; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.items th, table.items td { border: 1px solid #d7dee8; padding: 8px 9px; }
        table.items th { background: #eef2f7; color: #0b3a6e; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        table.items tbody tr:nth-child(even) td { background: #fbfcfe; }
        table.items thead { display: table-header-group; }
        table.items tr { page-break-inside: avoid; }
        .box { page-break-inside: avoid; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .summary { margin-top: 18px; width: 48%; margin-left: auto; border-collapse: collapse; page-break-inside: avoid; }
        .summary td { padding: 8px 10px; border: 1px solid #d7dee8; }
        .summary tr td:first-child { background: #f8fafc; color: #5b6778; }
        .summary .total-pay td { background: #0b3a6e; color: #ffffff; font-weight: 700; font-size: 14px; }
        .section-title { font-size: 12px; text-transform: uppercase; color: #0b3a6e; margin: 0 0 10px 0; letter-spacing: 0.04em; font-weight: 600; }
        .total-row td { background: #f5f7fb; font-weight: bold; color: #1f2937; }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td class="header-left">
                    ' . ($logoDataUri !== '' ? '<img class="logo" src="' . msp2Escape($logoDataUri) . '" alt="Logo">' : '') . '
                </td>
                <td class="header-right">
                    <div class="title">Vale Informativo ' . msp2Escape(valePeriodoNombre((string) ($documento['periodo_facturacion'] ?? ''))) . '</div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <div class="header-docbox">
                        <table class="header-docbox-table">
                            <tr>
                                <td>
                                    <div class="meta-label">Documento</div>
                                    <div class="meta-strong">Nro ' . msp2Escape((string) ($documento['numero_documento'] ?? '')) . '</div>
                                    <div class="meta-line">Arrendatario ' . msp2Escape((string) ($documento['nombre_arrendatario_snapshot'] ?? '')) . '</div>
                                    <div class="meta-line">RUT ' . msp2Escape(msp2RutFormatDisplay((string) ($documento['rut_arrendatario_snapshot'] ?? ''))) . '</div>
                                </td>
                                <td>
                                    <div class="meta-label">Periodo</div>
                                    <div class="meta-line">' . msp2Escape(valePeriodo((string) ($documento['periodo_facturacion'] ?? ''))) . '</div>
                                    <div class="meta-line">Emision ' . msp2Escape(valeFecha((string) ($documento['fecha_emision'] ?? ''))) . '</div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="box">
        <div class="section-title">Arriendo</div>
        <table class="items">
            <thead>
                <tr>
                    <th width="22%" class="text-center">Local</th>
                    <th width="20%" class="text-right">m2</th>
                    <th width="26%" class="text-right">Valor Neto</th>
                </tr>
            </thead>
            <tbody>';

    if ($arriendoDisplay === []) {
        $html .= '<tr><td colspan="3">Sin detalle de arriendo registrado.</td></tr>';
    } else {
        foreach ($arriendoDisplay as $detalle) {
            $html .= '<tr>
                <td class="text-center">' . msp2Escape((string) ($detalle['cdo_local'] ?? '-')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['metros_cuadrados'] ?? 0), 2, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(valeMonto($detalle['valor_neto'] ?? 0)) . '</td>
            </tr>';
        }
    }

    $html .= '<tr class="total-row">
                <td colspan="2" class="text-right">IVA arriendo 19%</td>
                <td class="text-right">' . msp2Escape(valeMonto($ivaArriendo)) . '</td>
            </tr>
            <tr class="total-row">
                <td colspan="2" class="text-right">Total arriendo</td>
                <td class="text-right">' . msp2Escape(valeMonto(((float) ($documento['subtotal_arriendo'] ?? 0)) + $ivaArriendo)) . '</td>
            </tr>
            </tbody>
            </table>
        </div>';

    if ($electricidadDetalles !== []) {
        $html .= '
        <div class="box">
            <div class="section-title">Electricidad</div>
            <table class="items">
                <thead>
                    <tr>
                        <th width="16%" class="text-center">Cod. Local</th>
                        <th width="16%" class="text-right">Marc. Anterior</th>
                        <th width="16%" class="text-right">Marc. Actual</th>
                        <th width="14%" class="text-right">Consumo</th>
                        <th width="16%" class="text-right">Valor kWh</th>
                        <th width="18%" class="text-right">Valor Total</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($electricidadDetalles as $detalle) {
            $html .= '<tr>
                <td class="text-center">' . msp2Escape((string) ($detalle['cdo_local'] ?? '-')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_anterior'] ?? 0), 0, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_actual'] ?? 0), 0, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['consumo_cobrado'] ?? 0), 0, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['valor_kwh'] ?? 0), 2, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(valeMonto($detalle['monto_total'] ?? 0)) . '</td>
            </tr>';
        }

        $html .= '<tr class="total-row">
                    <td colspan="5" class="text-right">Total Electricidad</td>
                    <td class="text-right">' . msp2Escape(valeMonto($totalElectricidad)) . '</td>
                </tr>
                </tbody>
            </table>
        </div>';
    }

    if ($gasDetalles !== []) {
        $html .= '
        <div class="box">
            <div class="section-title">Gas</div>
            <table class="items">
                <thead>
                    <tr>
                        <th width="14%" class="text-center">Cod. Local</th>
                        <th width="14%" class="text-right">Marc. Anterior</th>
                        <th width="14%" class="text-right">Marc. Actual</th>
                        <th width="12%" class="text-right">Consumo</th>
                        <th width="14%" class="text-right">Factor</th>
                        <th width="14%" class="text-right">Valor litro</th>
                        <th width="18%" class="text-right">Valor Total</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($gasDetalles as $detalle) {
            $html .= '<tr>
                <td class="text-center">' . msp2Escape((string) ($detalle['cdo_local'] ?? '-')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_anterior'] ?? 0), 0, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_actual'] ?? 0), 0, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['consumo_cobrado'] ?? 0), 0, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['factor'] ?? 0), 2, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['valor_litro'] ?? 0), 2, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(valeMonto($detalle['monto_total'] ?? 0)) . '</td>
            </tr>';
        }

        $html .= '<tr class="total-row">
                    <td colspan="6" class="text-right">Total Gas</td>
                    <td class="text-right">' . msp2Escape(valeMonto($totalGas)) . '</td>
                </tr>
                </tbody>
            </table>
        </div>';
    }

    if ($aguaDetalles !== []) {
        $html .= '
        <div class="box">
            <div class="section-title">Agua</div>
            <table class="items">
                <thead>
                    <tr>
                        <th width="20%" class="text-center">Cod. Local</th>
                        <th width="20%" class="text-right">Marc. Anterior</th>
                        <th width="20%" class="text-right">Marc. Actual</th>
                        <th width="20%" class="text-right">Consumo</th>
                        <th width="20%" class="text-right">Valor Total</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($aguaDetalles as $detalle) {
            $html .= '<tr>
                <td class="text-center">' . msp2Escape((string) ($detalle['cdo_local'] ?? '-')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_anterior'] ?? 0), 0, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['lectura_actual'] ?? 0), 0, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(number_format((float) ($detalle['consumo_cobrado'] ?? 0), 0, ',', '.')) . '</td>
                <td class="text-right">' . msp2Escape(valeMonto($detalle['monto_total'] ?? 0)) . '</td>
            </tr>';
        }

        $html .= '<tr class="total-row">
                    <td colspan="4" class="text-right">Total Agua</td>
                    <td class="text-right">' . msp2Escape(valeMonto($totalAgua)) . '</td>
                </tr>
                </tbody>
            </table>
        </div>';
    }

    if ($otrosCargosDetalles !== []) {
        $html .= '
        <div class="box">
            <div class="section-title">Otros cargos</div>
            <table class="items">
                <thead>
                    <tr>
                        <th width="78%">Descripción</th>
                        <th width="22%" class="text-right">Monto</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($otrosCargosDetalles as $detalle) {
            $html .= '<tr>
                <td>' . msp2Escape((string) ($detalle['descripcion_item'] ?? '-')) . '</td>
                <td class="text-right">' . msp2Escape(valeMonto($detalle['subtotal'] ?? 0)) . '</td>
            </tr>';
        }

        $html .= '<tr class="total-row">
                    <td class="text-right">Total otros cargos</td>
                    <td class="text-right">' . msp2Escape(valeMonto($totalOtrosCargos)) . '</td>
                </tr>
                </tbody>
            </table>
        </div>';
    }

    if ($electricidadDetalles === [] && $gasDetalles === [] && $aguaDetalles === [] && $otrosCargosDetalles === [] && (float) ($documento['subtotal_servicios'] ?? 0) > 0) {
        $html .= '
        <div class="box">
            <div class="section-title">Servicios</div>
            <div>El documento tiene servicios, pero no fue posible reconstruir su detalle para este vale.</div>
        </div>';
    }

    $html .= '
    <table class="summary">
        <tr>
            <td>Total arriendo</td>
            <td class="text-right">' . msp2Escape(valeMonto(((float) ($documento['subtotal_arriendo'] ?? 0)) + $ivaArriendo)) . '</td>
        </tr>
        <tr>
            <td>Total electricidad</td>
            <td class="text-right">' . msp2Escape(valeMonto($totalElectricidad)) . '</td>
        </tr>';

    if ($gasDetalles !== []) {
        $html .= '
        <tr>
            <td>Total gas</td>
            <td class="text-right">' . msp2Escape(valeMonto($totalGas)) . '</td>
        </tr>';
    }

    if ($aguaDetalles !== []) {
        $html .= '
        <tr>
            <td>Total agua</td>
            <td class="text-right">' . msp2Escape(valeMonto($totalAgua)) . '</td>
        </tr>';
    }

    if ($totalOtrosCargos > 0) {
        $html .= '
        <tr>
            <td>Total otros cargos</td>
            <td class="text-right">' . msp2Escape(valeMonto($totalOtrosCargos)) . '</td>
        </tr>';
    }

    $html .= '
        <tr class="total-pay">
            <td>Total a pagar</td>
            <td class="text-right">' . msp2Escape(valeMontoPayable($totalPagarRedondeado)) . '</td>
        </tr>
    </table>
</body>
</html>';

    return $html;
}

function msp2BuildDocumentoCobroValePdf(PDO $conn, int $idDocumento): array
{
    msp2ValeRequireDompdf();

    $data = msp2DocumentoCobroValeData($conn, $idDocumento);
    $documento = $data['documento'];

    $filename = valeBuildFilename(
        $documento,
        $data['arriendo'],
        $data['electricidad'],
        $data['gas'],
        $data['agua']
    );

    $html = msp2DocumentoCobroValeHtml($data);

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    return [$filename, $dompdf->output()];
}

function valeResumenBuildFilename(array $documento, array $arriendoDetalles, array $electricidadDetalles, array $gasDetalles = [], array $aguaDetalles = []): string
{
    $base = valeBuildFilename($documento, $arriendoDetalles, $electricidadDetalles, $gasDetalles, $aguaDetalles);
    if (str_ends_with($base, '.pdf')) {
        return substr($base, 0, -4) . '_resumen.pdf';
    }

    return $base . '_resumen.pdf';
}

function msp2DocumentoCobroValeResumenHtml(array $data): string
{
    $documento = $data['documento'];
    $arriendoDetalles = $data['arriendo'];
    $electricidadDetalles = $data['electricidad'];
    $gasDetalles = $data['gas'];
    $aguaDetalles = $data['agua'];
    $ivaArriendo = (float) ($data['iva_arriendo'] ?? 0);
    $totalElectricidad = (float) ($data['total_electricidad'] ?? 0);
    $totalGas = (float) ($data['total_gas'] ?? 0);
    $totalAgua = (float) ($data['total_agua'] ?? 0);
    $totalOtros = (float) ($data['total_otros'] ?? 0);
    $montoTotal = (float) ($data['monto_total'] ?? 0);
    $totalPagar = max(0.0, ceil($montoTotal));
    $arriendoConIva = round((float) ($documento['subtotal_arriendo'] ?? 0) + $ivaArriendo, 2);

    $locales = [];
    foreach ([$arriendoDetalles, $electricidadDetalles, $gasDetalles, $aguaDetalles] as $detalles) {
        foreach ($detalles as $detalle) {
            $local = trim((string) ($detalle['cdo_local'] ?? ''));
            if ($local !== '') {
                $locales[$local] = true;
            }
        }
    }
    $localesList = array_keys($locales);
    sort($localesList);
    $localesLabel = $localesList !== [] ? implode(' / ', $localesList) : '-';

    $rows = [];
    $rows[] = ['Arriendo (incluye IVA 19%)', $arriendoConIva];
    if ($totalElectricidad > 0.005) {
        $rows[] = ['Consumo electricidad', $totalElectricidad];
    }
    if ($totalGas > 0.005) {
        $rows[] = ['Consumo gas', $totalGas];
    }
    if ($totalAgua > 0.005) {
        $rows[] = ['Consumo agua', $totalAgua];
    }
    if ($totalOtros > 0.005) {
        $rows[] = ['Otros cargos', $totalOtros];
    }

    $rowsHtml = '';
    foreach ($rows as [$label, $amount]) {
        $rowsHtml .= '<tr>'
            . '<td>' . msp2Escape((string) $label) . '</td>'
            . '<td class="text-right">' . msp2Escape(valeMonto($amount)) . '</td>'
            . '</tr>';
    }

    return '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen de Cobro</title>
    <style>
        @page { margin: 24px; }
        body { font-family: "Segoe UI", DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
        .card { border: 1px solid #cbd5e1; border-radius: 10px; padding: 14px 16px; margin-bottom: 14px; }
        .kicker { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-bottom: 6px; }
        .title { font-size: 24px; font-weight: 700; color: #0b3a6e; margin: 0; }
        .subtitle { margin-top: 4px; color: #475569; font-size: 12px; }
        .meta { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .meta td { padding: 3px 0; vertical-align: top; }
        .meta .label { width: 120px; color: #64748b; }
        .items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .items th, .items td { border: 1px solid #dbe3ee; padding: 8px 10px; }
        .items th { background: #f1f5f9; color: #334155; text-transform: uppercase; font-size: 10px; letter-spacing: .05em; }
        .text-right { text-align: right; white-space: nowrap; }
        .total-doc td { font-weight: 700; background: #f8fafc; }
        .payable { margin-top: 12px; border: 2px solid #0b3a6e; border-radius: 8px; padding: 10px 12px; background: #eff6ff; }
        .payable-label { color: #1e3a8a; text-transform: uppercase; letter-spacing: .06em; font-size: 11px; font-weight: 700; }
        .payable-amount { font-size: 30px; line-height: 1; font-weight: 800; color: #0b3a6e; margin-top: 4px; }
        .note { margin-top: 8px; color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="kicker">Mercado San Pedro</div>
        <h1 class="title">Resumen de Cobro</h1>
        <div class="subtitle">' . msp2Escape(valePeriodoNombre((string) ($documento['periodo_facturacion'] ?? ''))) . '</div>

        <table class="meta">
            <tr>
                <td class="label">Documento</td>
                <td>Nro ' . msp2Escape((string) ($documento['numero_documento'] ?? '-')) . '</td>
            </tr>
            <tr>
                <td class="label">Arrendatario</td>
                <td>' . msp2Escape((string) ($documento['nombre_arrendatario_snapshot'] ?? '-')) . '</td>
            </tr>
            <tr>
                <td class="label">RUT</td>
                <td>' . msp2Escape(msp2RutFormatDisplay((string) ($documento['rut_arrendatario_snapshot'] ?? ''))) . '</td>
            </tr>
            <tr>
                <td class="label">Locales</td>
                <td>' . msp2Escape($localesLabel) . '</td>
            </tr>
            <tr>
                <td class="label">Emisión</td>
                <td>' . msp2Escape(valeFecha((string) ($documento['fecha_emision'] ?? ''))) . '</td>
            </tr>
        </table>
    </div>

    <div class="card">
        <table class="items">
            <thead>
                <tr>
                    <th>Detalle</th>
                    <th class="text-right">Monto</th>
                </tr>
            </thead>
            <tbody>
                ' . $rowsHtml . '
                <tr class="total-doc">
                    <td>Total documento</td>
                    <td class="text-right">' . msp2Escape(valeMonto($montoTotal)) . '</td>
                </tr>
            </tbody>
        </table>

        <div class="payable">
            <div class="payable-label">Total a pagar</div>
            <div class="payable-amount">' . msp2Escape(valeMontoPayable($totalPagar)) . '</div>
            <div class="note">Monto redondeado hacia arriba para pago.</div>
        </div>
    </div>
</body>
</html>';
}

function msp2BuildDocumentoCobroValeResumenPdf(PDO $conn, int $idDocumento): array
{
    msp2ValeRequireDompdf();

    $data = msp2DocumentoCobroValeData($conn, $idDocumento);
    $documento = $data['documento'];

    $filename = valeResumenBuildFilename(
        $documento,
        $data['arriendo'],
        $data['electricidad'],
        $data['gas'],
        $data['agua']
    );

    $html = msp2DocumentoCobroValeResumenHtml($data);

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();

    return [$filename, $dompdf->output()];
}
