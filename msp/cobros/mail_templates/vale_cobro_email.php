<?php
declare(strict_types=1);

function omEmailMonthName(int $month, bool $uppercase = false): string
{
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

    $name = $months[$month] ?? '';
    if ($name === '') {
        return '';
    }

    return $uppercase ? mb_strtoupper($name, 'UTF-8') : $name;
}

function omEmailFmtMoney(mixed $value): string
{
    $num = (float) $value;
    $decimals = abs($num - round($num)) < 0.005 ? 0 : 2;
    return '$' . number_format($num, $decimals, ',', '.');
}

function omEmailFmtMoneyPayable(mixed $value): string
{
    $num = max(0.0, (float) $value);
    $roundedUp = ceil($num);
    return '$' . number_format($roundedUp, 0, ',', '.');
}

function omEmailFmtNumber(mixed $value, int $decimals = 0): string
{
    if (!is_numeric((string) $value)) {
        return '-';
    }

    return number_format((float) $value, $decimals, ',', '.');
}

function omEmailFmtDateDm(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return '--';
    }

    try {
        return (new DateTimeImmutable($raw))->format('d-m');
    } catch (Throwable $e) {
        return '--';
    }
}

function omEmailSimplifyMedidorLabel(?string $rawCode, string $fallback): string
{
    $code = trim((string) $rawCode);
    if ($code === '') {
        return $fallback;
    }

    $code = preg_replace('/^\s*medidor\s+/iu', '', $code) ?? $code;
    $code = trim($code);

    if (str_contains($code, '-')) {
        $parts = explode('-', $code);
        $first = trim((string) ($parts[0] ?? ''));
        if ($first !== '') {
            $code = $first;
        }
    } elseif (str_contains($code, '_')) {
        $parts = explode('_', $code);
        $first = trim((string) ($parts[0] ?? ''));
        if ($first !== '') {
            $code = $first;
        }
    }

    $code = trim($code);
    if ($code === '') {
        return $fallback;
    }

    return mb_convert_case(mb_strtolower($code, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

function omEmailResolveHeaderDates(array $items): array
{
    $fechaAnterior = '--';
    $fechaAnteriorFallback = '--';
    $fechaActual = '--';

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        if ($fechaAnterior === '--') {
            $candAnterior = omEmailFmtDateDm((string) ($item['fecha_anterior'] ?? ''));
            if ($candAnterior !== '--') {
                $fechaAnterior = $candAnterior;
            }
        }

        if ($fechaAnteriorFallback === '--') {
            $candInicial = omEmailFmtDateDm((string) ($item['fecha_valor_inicial'] ?? ''));
            if ($candInicial !== '--') {
                $fechaAnteriorFallback = $candInicial;
            }
        }

        if ($fechaActual === '--') {
            $candActual = omEmailFmtDateDm((string) ($item['fecha_lectura'] ?? $item['fecha_hasta_consumo'] ?? ''));
            if ($candActual !== '--') {
                $fechaActual = $candActual;
            }
        }

        if ($fechaAnterior !== '--' && $fechaActual !== '--') {
            break;
        }
    }

    if ($fechaAnterior === '--') {
        $fechaAnterior = $fechaAnteriorFallback;
    }

    return [$fechaAnterior, $fechaActual];
}

function omEmailExtractLocalFromDescription(string $description): ?string
{
    if (preg_match('/local\\s+([A-Za-z0-9-]+)/iu', $description, $matches) !== 1) {
        return null;
    }

    $code = trim((string) ($matches[1] ?? ''));
    return $code !== '' ? $code : null;
}

function omEmailValeLogoDataUri(): string
{
    $logoPath = dirname(__DIR__, 2) . '/assets/logo_msp.jpg';
    if (!is_file($logoPath)) {
        return '';
    }

    $bin = @file_get_contents($logoPath);
    if (!is_string($bin) || $bin === '') {
        return '';
    }

    return 'data:image/jpeg;base64,' . base64_encode($bin);
}

function omEmailValeV2DetailsHtml(string $rawHtml): string
{
    if (trim($rawHtml) === '') {
        return '';
    }

    $search = [
        'border:4px solid #000',
        'border:3px solid #000',
        'border-top:4px solid #000',
        'padding:12px',
        'padding:8px',
        'padding:6px 8px',
        'font-weight:900',
        'font-size:46px',
        'font-size:38px',
        'font-size:28px',
        'height:18px',
        'height:8px',
        'margin-top:14px',
        'margin-top:10px',
    ];
    $replace = [
        'border:1px solid #5b5b5b',
        'border:1px solid #5b5b5b',
        'border-top:2px solid #1f1f1f',
        'padding:7px',
        'padding:6px',
        'padding:5px 6px',
        'font-weight:800',
        'font-size:34px',
        'font-size:26px',
        'font-size:17px',
        'height:10px',
        'height:5px',
        'margin-top:10px',
        'margin-top:8px',
    ];

    $styled = str_replace($search, $replace, $rawHtml);
    $styled = str_replace('background:#efefef;', 'background:#fff;', $styled);
    $styled = str_replace(
        'font-weight:800;text-decoration:underline;',
        'font-weight:800;text-decoration:underline;background:#f7f7f7;',
        $styled
    );

    return '<div class="vale-v2-detail">' . $styled . '</div>';
}

function omBuildCobroEmailContent(PDO $conn, array $arrRow, array $docs, string $periodoYm): array
{
    $arrId = (int) ($arrRow['id_arrendatario'] ?? 0);
    $nombreArr = trim((string) ($arrRow['nombre_arrendatario'] ?? ''));
    if ($nombreArr === '') {
        $nombreArr = $arrId > 0 ? 'Arrendatario #' . $arrId : 'Arrendatario';
    }
    $periodoDate = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($periodoDate === false || $periodoDate->format('Y-m') !== $periodoYm) {
        $periodoTitle = mb_strtoupper($periodoYm, 'UTF-8');
        $mesConsumoLuzGasLabel = mb_strtolower($periodoYm, 'UTF-8');
        $mesConsumoAguaLabel = mb_strtolower($periodoYm, 'UTF-8');
    } else {
        $periodoTitle = omEmailMonthName((int) $periodoDate->format('n'), true) . ' ' . $periodoDate->format('Y');
        $prev = $periodoDate->modify('-1 month');
        $prev2 = $periodoDate->modify('-2 month');
        $mesConsumoLuzGasLabel = omEmailMonthName((int) $prev->format('n'), false);
        $mesConsumoAguaLabel = omEmailMonthName((int) $prev2->format('n'), false);
    }

    $detalleStmt = $conn->prepare(
        "SELECT
            dcd.orden_item,
            tid.codigo_item,
            dcd.descripcion_item,
            dcd.cantidad,
            dcd.valor_unitario,
            dcd.subtotal,
            dcd.id_cobro_servicio,
            cs.consumo_cobrado,
            cs.parametros_snapshot,
            lm.id_medidor,
            lm.lectura_anterior,
            lm.lectura_actual,
            lm.fecha_hasta_consumo,
            lm.fecha_lectura,
            prev.fecha_hasta_consumo AS fecha_anterior,
            m.fecha_instalacion AS fecha_valor_inicial,
            loc.cdo_local,
            m.codigo_medidor,
            pl.valor_kwh AS valor_kwh_proceso
         FROM dbo.msp_documentos_cobro_detalle dcd
         INNER JOIN dbo.msp_tipo_item_documento tid
            ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
         LEFT JOIN dbo.msp_cobros_servicios cs
            ON cs.id_cobro_servicio = dcd.id_cobro_servicio
         LEFT JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
         LEFT JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
         LEFT JOIN dbo.msp_proceso_cobro_luz pl
            ON pl.id_proceso_cobro = p.id_proceso_cobro
         OUTER APPLY (
            SELECT TOP 1 lmPrev.fecha_hasta_consumo
            FROM dbo.msp_lecturas_medidores lmPrev
            WHERE lm.id_medidor IS NOT NULL
              AND lmPrev.id_medidor = lm.id_medidor
              AND lmPrev.id_lectura <> lm.id_lectura
              AND lmPrev.fecha_hasta_consumo <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
            ORDER BY lmPrev.fecha_hasta_consumo DESC, lmPrev.id_lectura DESC
         ) prev
         LEFT JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
         LEFT JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
         WHERE dcd.id_documento_cobro = :id
         ORDER BY dcd.orden_item ASC"
    );
    $saldoFavorPagoStmt = null;
    $pagosTieneAplicaSaldoFavor = false;
    $pagosTieneMontoSaldoFavor = false;
    if (msp2TableExists($conn, 'msp_pagos')) {
        $pagosTieneAplicaSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor');
        $pagosTieneMontoSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'monto_saldo_favor_generado');
        if ($pagosTieneAplicaSaldoFavor) {
            $montoAplicadoExpr = $pagosTieneMontoSaldoFavor
                ? "CASE
                    WHEN ISNULL(p.monto_saldo_favor_generado, 0) > 0 THEN p.monto_saldo_favor_generado
                    ELSE ISNULL(p.monto_pagado, 0)
                   END"
                : "ISNULL(p.monto_pagado, 0)";
            $saldoFavorPagoStmt = $conn->prepare(
                "SELECT ROUND(SUM($montoAplicadoExpr), 2) AS monto_saldo_aplicado
                 FROM dbo.msp_pagos p
                 WHERE p.id_documento_cobro = :id_documento
                   AND p.estado_pago = 1
                   AND ISNULL(p.aplica_desde_saldo_favor, 0) = 1"
            );
        }
    }

    $cardsHtml = '';
    $linesText = [];
    $logoSrc = omEmailValeLogoDataUri();
    $logoHtml = $logoSrc !== ''
        ? '<img src="' . htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8') . '" alt="Logo MSP" style="max-width:118px;height:auto;display:block;margin:0 auto;">'
        : '<div class="vale-v2-logo-fallback">MSP</div>';

    foreach ($docs as $doc) {
        $docId = (int) ($doc['id_documento_cobro'] ?? 0);
        if ($docId <= 0) {
            continue;
        }

        $detalleStmt->bindValue(':id', $docId, PDO::PARAM_INT);
        $detalleStmt->execute();
        $items = $detalleStmt->fetchAll() ?: [];

        $totArriendo = 0.0;
        $totLuz = 0.0;
        $totGas = 0.0;
        $totAgua = 0.0;
        $totOtros = 0.0;
        $localesMap = [];
        $obraLocalKey = null;
        $modularLocalKey = null;
        $arriendoItems = [];
        $luzItems = [];
        $gasItems = [];
        $aguaItems = [];
        $otrosItems = [];

        foreach ($items as $item) {
            $codigoItem = trim((string) ($item['codigo_item'] ?? ''));
            $subtotal = (float) ($item['subtotal'] ?? 0);
            if ($codigoItem === 'ARRIENDO') {
                $totArriendo += $subtotal;
                $arriendoItems[] = $item;
            } elseif ($codigoItem === 'SERVICIO_LUZ') {
                $totLuz += $subtotal;
                $luzItems[] = $item;
            } elseif ($codigoItem === 'SERVICIO_GAS') {
                $totGas += $subtotal;
                $gasItems[] = $item;
            } elseif ($codigoItem === 'SERVICIO_AGUA') {
                $totAgua += $subtotal;
                $aguaItems[] = $item;
            } else {
                $totOtros += $subtotal;
                $otrosItems[] = $item;
            }

            $localCode = trim((string) ($item['cdo_local'] ?? ''));
            if ($localCode === '') {
                $localCode = (string) (omEmailExtractLocalFromDescription((string) ($item['descripcion_item'] ?? '')) ?? '');
            }
            if ($localCode !== '') {
                $localesMap[$localCode] = true;
                $localCodeNorm = mb_strtoupper($localCode, 'UTF-8');
                if ($localCodeNorm === 'OBRA') {
                    $obraLocalKey = $localCode;
                } elseif ($localCodeNorm === 'MODULAR') {
                    $modularLocalKey = $localCode;
                }
            }
        }

        $mergeObraModular = $obraLocalKey !== null && $modularLocalKey !== null;
        if ($mergeObraModular) {
            unset($localesMap[$obraLocalKey], $localesMap[$modularLocalKey]);
            $localesMap['OBRA/MODULAR'] = true;
        }
        $desglosePorMedidor = $mergeObraModular;

        $locales = array_keys($localesMap);
        sort($locales);
        $localLabel = $locales !== [] ? implode(' / ', $locales) : '-';
        $docNumero = trim((string) ($doc['numero_documento'] ?? ''));
        $totalDocumento = (float) ($doc['monto_total'] ?? (($totArriendo * 1.19) + $totLuz + $totGas + $totAgua + $totOtros));
        $saldoFavorAplicadoDocumento = 0.0;
        if ($saldoFavorPagoStmt instanceof PDOStatement) {
            $saldoFavorPagoStmt->bindValue(':id_documento', $docId, PDO::PARAM_INT);
            $saldoFavorPagoStmt->execute();
            $saldoFavorPagoRow = $saldoFavorPagoStmt->fetch() ?: [];
            $saldoFavorAplicadoDocumento = round((float) ($saldoFavorPagoRow['monto_saldo_aplicado'] ?? 0), 2);
        }
        $totalPagar = round(max(0.0, $totalDocumento - $saldoFavorAplicadoDocumento), 2);
        $ivaArriendo = round($totArriendo * 0.19, 2);
        $totArriendoConIva = round($totArriendo + $ivaArriendo, 2);
        $totalPagarConIvaArriendo = $totalPagar;

        $arriendoHtml = '';

        $luzHtml = '';
        if ($luzItems !== []) {
            $luzConsumoTotal = 0.0;
            $luzMontoTotal = 0.0;
            $luzLecturaAnteriorTotal = 0.0;
            $luzLecturaActualTotal = 0.0;
            $luzMedidores = [];
            $luzKwhUnit = null;
            [$luzFechaAnteriorHeader, $luzFechaActualHeader] = omEmailResolveHeaderDates($luzItems);
            $luzMarcAntHeader = 'Marc. ant' . ($luzFechaAnteriorHeader !== '--' ? ' (' . $luzFechaAnteriorHeader . ')' : '');
            $luzMarcActHeader = 'Marc. act' . ($luzFechaActualHeader !== '--' ? ' (' . $luzFechaActualHeader . ')' : '');
            $luzHtml = '<table role="presentation" style="width:100%;border-collapse:collapse;margin-top:10px;table-layout:fixed;">'
                . '<tr><td colspan="5" style="border:3px solid #000;border-bottom:none;padding:8px;font-weight:800;text-decoration:underline;">Consumo de electricidad</td></tr>'
                . '<tr>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;">Medidores</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">' . htmlspecialchars($luzMarcAntHeader, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">' . htmlspecialchars($luzMarcActHeader, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">Consumo</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">kWh</td>'
                . '</tr>';

            foreach ($luzItems as $index => $luz) {
                if (is_numeric((string) ($luz['consumo_cobrado'] ?? ''))) {
                    $luzConsumoTotal += (float) $luz['consumo_cobrado'];
                }
                if (is_numeric((string) ($luz['subtotal'] ?? ''))) {
                    $luzMontoTotal += (float) $luz['subtotal'];
                }
                if (is_numeric((string) ($luz['lectura_anterior'] ?? ''))) {
                    $luzLecturaAnteriorTotal += (float) $luz['lectura_anterior'];
                }
                if (is_numeric((string) ($luz['lectura_actual'] ?? ''))) {
                    $luzLecturaActualTotal += (float) $luz['lectura_actual'];
                }
                $valorKwh = null;
                if (is_numeric((string) ($luz['valor_kwh_proceso'] ?? ''))) {
                    $valorKwh = (float) $luz['valor_kwh_proceso'];
                } elseif (is_numeric((string) ($luz['valor_unitario'] ?? ''))) {
                    $valorKwh = (float) $luz['valor_unitario'];
                }
                $medidorCodigo = trim((string) ($luz['codigo_medidor'] ?? ''));
                if ($medidorCodigo === '') {
                    $medidorCodigo = trim((string) ($luz['id_medidor'] ?? ''));
                }
                $medidorLabelSimple = omEmailSimplifyMedidorLabel($medidorCodigo, 'Medidor ' . ($index + 1));
                $luzMedidores[$medidorLabelSimple] = true;
                $medidorLabel = $medidorLabelSimple;

                if ($desglosePorMedidor) {
                    $luzHtml .= '<tr>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;">' . htmlspecialchars($medidorLabel, ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">'
                        . omEmailFmtNumber($luz['lectura_anterior'] ?? null, 0)
                        . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">'
                        . omEmailFmtNumber($luz['lectura_actual'] ?? null, 0)
                        . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($luz['consumo_cobrado'] ?? null, 0) . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;"></td>'
                        . '</tr>';
                }

                if ($index === 0) {
                    $luzKwhUnit = $valorKwh ?? 0;
                }
            }

            $luzMedidoresList = array_keys($luzMedidores);
            sort($luzMedidoresList);
            $luzMedidoresLabel = $luzMedidoresList !== [] ? implode(' / ', $luzMedidoresList) : 'Medidores';

            if ($desglosePorMedidor) {
                $luzHtml .= '<tr>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;" colspan="3">Total kWh</td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;text-align:right;">'
                    . omEmailFmtNumber($luzConsumoTotal, 0)
                    . ' <span style="font-weight:700;">kWh</span>'
                    . '</td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;text-align:right;white-space:nowrap;">'
                    . omEmailFmtMoney($luzKwhUnit ?? 0)
                    . '</td>'
                    . '</tr>'
                    . '<tr>'
                    . '<td style="border:3px solid #000;border-top:none;padding:8px;font-weight:800;" colspan="3">Monto servicio luz</td>'
                    . '<td style="border:3px solid #000;border-top:none;padding:8px;font-weight:800;text-align:right;">' . omEmailFmtMoney($luzMontoTotal) . '</td>'
                    . '<td style="border:3px solid #000;border-top:none;padding:8px;"></td>'
                    . '</tr>';
            } else {
                $luzHtml .= '<tr>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;">' . htmlspecialchars($luzMedidoresLabel, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($luzLecturaAnteriorTotal, 0) . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($luzLecturaActualTotal, 0) . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($luzConsumoTotal, 0) . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;white-space:nowrap;">'
                    . omEmailFmtMoney($luzKwhUnit ?? 0)
                    . '</td>'
                    . '</tr>'
                    . '<tr>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;" colspan="3">Monto servicio luz</td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;text-align:right;">' . omEmailFmtMoney($luzMontoTotal) . '</td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;"></td>'
                    . '</tr>';
            }
            $luzHtml .= '</table>';
        }

        $gasHtml = '';
        if ($gasItems !== []) {
            $gasConsumoTotal = 0.0;
            $gasMontoTotal = 0.0;
            $gasLecturaAnteriorTotal = 0.0;
            $gasLecturaActualTotal = 0.0;
            $gasMedidores = [];
            $gasFactorUnit = null;
            $gasValorLitro = null;
            $gasFactorConsistente = true;
            $gasValorLitroConsistente = true;
            [$gasFechaAnteriorHeader, $gasFechaActualHeader] = omEmailResolveHeaderDates($gasItems);
            $gasMarcAntHeader = 'Marc. ant' . ($gasFechaAnteriorHeader !== '--' ? ' (' . $gasFechaAnteriorHeader . ')' : '');
            $gasMarcActHeader = 'Marc. act' . ($gasFechaActualHeader !== '--' ? ' (' . $gasFechaActualHeader . ')' : '');

            $gasHtml = '<table style="width:100%;border-collapse:collapse;margin-top:14px;table-layout:fixed;">'
                . '<tr><td colspan="6" style="border:3px solid #000;border-bottom:none;padding:8px;font-weight:800;text-decoration:underline;">Consumo de gas</td></tr>'
                . '<tr>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;">Medidores</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">' . htmlspecialchars($gasMarcAntHeader, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">' . htmlspecialchars($gasMarcActHeader, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">Consumo</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">Factor</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">$/Lt</td>'
                . '</tr>';

            foreach ($gasItems as $index => $gas) {
                if (is_numeric((string) ($gas['consumo_cobrado'] ?? ''))) {
                    $gasConsumoTotal += (float) $gas['consumo_cobrado'];
                }
                if (is_numeric((string) ($gas['subtotal'] ?? ''))) {
                    $gasMontoTotal += (float) $gas['subtotal'];
                }
                if (is_numeric((string) ($gas['lectura_anterior'] ?? ''))) {
                    $gasLecturaAnteriorTotal += (float) $gas['lectura_anterior'];
                }
                if (is_numeric((string) ($gas['lectura_actual'] ?? ''))) {
                    $gasLecturaActualTotal += (float) $gas['lectura_actual'];
                }

                $snapshot = json_decode((string) ($gas['parametros_snapshot'] ?? ''), true);
                $factor = null;
                $valorLitro = null;
                if (is_array($snapshot)) {
                    if (isset($snapshot['factor']) && is_numeric((string) $snapshot['factor'])) {
                        $factor = (float) $snapshot['factor'];
                    }
                    if (isset($snapshot['valor_litro']) && is_numeric((string) $snapshot['valor_litro'])) {
                        $valorLitro = (float) $snapshot['valor_litro'];
                    }
                }

                $medidorCodigo = trim((string) ($gas['codigo_medidor'] ?? ''));
                if ($medidorCodigo === '') {
                    $medidorCodigo = trim((string) ($gas['id_medidor'] ?? ''));
                }
                $medidorLabelSimple = omEmailSimplifyMedidorLabel($medidorCodigo, 'Medidor ' . ($index + 1));
                $gasMedidores[$medidorLabelSimple] = true;
                $medidorLabel = $medidorLabelSimple;

                if ($index === 0) {
                    $gasFactorUnit = $factor;
                    $gasValorLitro = $valorLitro;
                } else {
                    if (($gasFactorUnit === null && $factor !== null)
                        || ($gasFactorUnit !== null && ($factor === null || abs($gasFactorUnit - $factor) > 0.0001))
                    ) {
                        $gasFactorConsistente = false;
                    }
                    if (($gasValorLitro === null && $valorLitro !== null)
                        || ($gasValorLitro !== null && ($valorLitro === null || abs($gasValorLitro - $valorLitro) > 0.0001))
                    ) {
                        $gasValorLitroConsistente = false;
                    }
                }

                if ($desglosePorMedidor) {
                    $gasHtml .= '<tr>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;">' . htmlspecialchars($medidorLabel, ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">'
                        . omEmailFmtNumber($gas['lectura_anterior'] ?? null, 0)
                        . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">'
                        . omEmailFmtNumber($gas['lectura_actual'] ?? null, 0)
                        . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($gas['consumo_cobrado'] ?? null, 0) . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;text-align:right;">' . ($factor !== null ? omEmailFmtNumber($factor, 2) : '-') . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;text-align:right;">' . ($valorLitro !== null ? omEmailFmtNumber($valorLitro, 2) : '-') . '</td>'
                        . '</tr>';
                }
            }

            $gasMedidoresList = array_keys($gasMedidores);
            sort($gasMedidoresList);
            $gasMedidoresLabel = $gasMedidoresList !== [] ? implode(' / ', $gasMedidoresList) : 'Medidores';
            $gasFactorLabel = ($gasFactorConsistente && $gasFactorUnit !== null) ? omEmailFmtNumber($gasFactorUnit, 2) : '-';
            $gasValorLitroLabel = ($gasValorLitroConsistente && $gasValorLitro !== null) ? omEmailFmtNumber($gasValorLitro, 2) : '-';

            if ($desglosePorMedidor) {
                $gasHtml .= '<tr>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;" colspan="3">Total m3</td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;text-align:right;">' . omEmailFmtNumber($gasConsumoTotal, 0) . '</td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;"></td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;"></td>'
                    . '</tr>'
                    . '<tr>'
                    . '<td style="border:3px solid #000;border-top:none;padding:8px;font-weight:800;" colspan="3">Monto servicio gas</td>'
                    . '<td style="border:3px solid #000;border-top:none;padding:8px;font-weight:800;text-align:right;">' . omEmailFmtMoneyPayable($gasMontoTotal) . '</td>'
                    . '<td style="border:3px solid #000;border-top:none;padding:8px;"></td>'
                    . '<td style="border:3px solid #000;border-top:none;padding:8px;"></td>'
                    . '</tr>';
            } else {
                $gasHtml .= '<tr>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;">' . htmlspecialchars($gasMedidoresLabel, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($gasLecturaAnteriorTotal, 0) . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($gasLecturaActualTotal, 0) . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($gasConsumoTotal, 0) . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;text-align:right;">' . $gasFactorLabel . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;text-align:right;">' . $gasValorLitroLabel . '</td>'
                    . '</tr>'
                    . '<tr>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;" colspan="3">Monto servicio gas</td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;text-align:right;">' . omEmailFmtMoneyPayable($gasMontoTotal) . '</td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;"></td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;"></td>'
                    . '</tr>';
            }

            $gasHtml .= '</table>';
        }

        $aguaHtml = '';
        if ($aguaItems !== []) {
            $aguaConsumoTotal = 0.0;
            $aguaMontoTotal = 0.0;
            $aguaLecturaAnteriorTotal = 0.0;
            $aguaLecturaActualTotal = 0.0;
            $aguaMedidores = [];
            [$aguaFechaAnteriorHeader, $aguaFechaActualHeader] = omEmailResolveHeaderDates($aguaItems);
            $aguaMarcAntHeader = 'Marc. ant' . ($aguaFechaAnteriorHeader !== '--' ? ' (' . $aguaFechaAnteriorHeader . ')' : '');
            $aguaMarcActHeader = 'Marc. act' . ($aguaFechaActualHeader !== '--' ? ' (' . $aguaFechaActualHeader . ')' : '');
            $aguaHtml = '<table style="width:100%;border-collapse:collapse;margin-top:14px;table-layout:fixed;">'
                . '<tr><td colspan="4" style="border:3px solid #000;border-bottom:none;padding:8px;font-weight:800;text-decoration:underline;">Consumo de agua</td></tr>'
                . '<tr>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;">Medidores</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">' . htmlspecialchars($aguaMarcAntHeader, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">' . htmlspecialchars($aguaMarcActHeader, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">Consumo (m3)</td>'
                . '</tr>';

            foreach ($aguaItems as $index => $aguaRow) {
                if (is_numeric((string) ($aguaRow['consumo_cobrado'] ?? ''))) {
                    $aguaConsumoTotal += (float) $aguaRow['consumo_cobrado'];
                }
                if (is_numeric((string) ($aguaRow['subtotal'] ?? ''))) {
                    $aguaMontoTotal += (float) $aguaRow['subtotal'];
                }
                if (is_numeric((string) ($aguaRow['lectura_anterior'] ?? ''))) {
                    $aguaLecturaAnteriorTotal += (float) $aguaRow['lectura_anterior'];
                }
                if (is_numeric((string) ($aguaRow['lectura_actual'] ?? ''))) {
                    $aguaLecturaActualTotal += (float) $aguaRow['lectura_actual'];
                }

                $medidorCodigo = trim((string) ($aguaRow['codigo_medidor'] ?? ''));
                if ($medidorCodigo === '') {
                    $medidorCodigo = trim((string) ($aguaRow['id_medidor'] ?? ''));
                }
                $medidorLabelSimple = omEmailSimplifyMedidorLabel($medidorCodigo, 'Medidor ' . ($index + 1));
                $aguaMedidores[$medidorLabelSimple] = true;
                $medidorLabel = $medidorLabelSimple;

                if ($desglosePorMedidor) {
                    $aguaHtml .= '<tr>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;">' . htmlspecialchars($medidorLabel, ENT_QUOTES, 'UTF-8') . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">'
                        . omEmailFmtNumber($aguaRow['lectura_anterior'] ?? null, 0)
                        . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">'
                        . omEmailFmtNumber($aguaRow['lectura_actual'] ?? null, 0)
                        . '</td>'
                        . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">'
                        . omEmailFmtNumber($aguaRow['consumo_cobrado'] ?? null, 0)
                        . '</td>'
                        . '</tr>';
                }
            }

            $aguaMedidoresList = array_keys($aguaMedidores);
            sort($aguaMedidoresList);
            $aguaMedidoresLabel = $aguaMedidoresList !== [] ? implode(' / ', $aguaMedidoresList) : 'Medidores';

            if ($desglosePorMedidor) {
                $aguaHtml .= '<tr>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;" colspan="3">Total m3</td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;text-align:right;">'
                    . omEmailFmtNumber($aguaConsumoTotal, 0)
                    . '</td>'
                    . '</tr>'
                    . '<tr>'
                    . '<td style="border:3px solid #000;border-top:none;padding:8px;font-weight:800;" colspan="3">Monto servicio agua</td>'
                    . '<td style="border:3px solid #000;border-top:none;padding:8px;font-weight:800;text-align:right;">' . omEmailFmtMoneyPayable($aguaMontoTotal) . '</td>'
                    . '</tr>';
            } else {
                $aguaHtml .= '<tr>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;">' . htmlspecialchars($aguaMedidoresLabel, ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($aguaLecturaAnteriorTotal, 0) . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($aguaLecturaActualTotal, 0) . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;font-weight:700;text-align:right;">' . omEmailFmtNumber($aguaConsumoTotal, 0) . '</td>'
                    . '</tr>'
                    . '<tr>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;" colspan="3">Monto servicio agua</td>'
                    . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;text-align:right;">' . omEmailFmtMoneyPayable($aguaMontoTotal) . '</td>'
                    . '</tr>';
            }

            $aguaHtml .= '</table>';
        }

        $otrosHtml = '';
        if ($otrosItems !== []) {
            $otrosHtml = '<table style="width:100%;border-collapse:collapse;margin-top:14px;table-layout:fixed;">'
                . '<tr><td colspan="2" style="border:3px solid #000;border-bottom:none;padding:8px;font-weight:800;text-decoration:underline;">Otros cargos</td></tr>'
                . '<tr>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;">Descripción</td>'
                . '<td style="border:3px solid #000;padding:6px 8px;font-weight:800;text-align:right;">Monto</td>'
                . '</tr>';

            foreach ($otrosItems as $otro) {
                $otrosHtml .= '<tr>'
                    . '<td style="border:3px solid #000;padding:6px 8px;">' . htmlspecialchars((string) ($otro['descripcion_item'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td style="border:3px solid #000;padding:6px 8px;text-align:right;font-weight:700;">' . omEmailFmtMoney($otro['subtotal'] ?? 0) . '</td>'
                    . '</tr>';
            }

            $otrosHtml .= '<tr>'
                . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;">Total otros cargos</td>'
                . '<td style="border:3px solid #000;border-top:4px solid #000;padding:8px;font-weight:800;text-align:right;">' . omEmailFmtMoney($totOtros) . '</td>'
                . '</tr>'
                . '</table>';
        }

        $resumenServiciosHtml = '';
        if ($totLuz > 0) {
            $resumenServiciosHtml .= '<tr><td class="sum-label">Consumo electricidad ' . htmlspecialchars($mesConsumoLuzGasLabel, ENT_QUOTES, 'UTF-8') . '</td><td class="sum-value">' . omEmailFmtMoney($totLuz) . '</td></tr>';
        }
        if ($totGas > 0) {
            $resumenServiciosHtml .= '<tr><td class="sum-label">Consumo gas ' . htmlspecialchars($mesConsumoLuzGasLabel, ENT_QUOTES, 'UTF-8') . '</td><td class="sum-value">' . omEmailFmtMoneyPayable($totGas) . '</td></tr>';
        }
        if ($totAgua > 0) {
            $resumenServiciosHtml .= '<tr><td class="sum-label">Consumo agua ' . htmlspecialchars($mesConsumoAguaLabel, ENT_QUOTES, 'UTF-8') . '</td><td class="sum-value">' . omEmailFmtMoneyPayable($totAgua) . '</td></tr>';
        }
        if ($totOtros > 0) {
            $resumenServiciosHtml .= '<tr><td class="sum-label">Otros cargos</td><td class="sum-value">' . omEmailFmtMoney($totOtros) . '</td></tr>';
        }
        if ($saldoFavorAplicadoDocumento > 0) {
            $resumenServiciosHtml .= '<tr><td class="sum-label sum-balance">Saldo a favor aplicado</td><td class="sum-value sum-balance">-' . omEmailFmtMoney($saldoFavorAplicadoDocumento) . '</td></tr>';
        }

        $detailBlockHtml = omEmailValeV2DetailsHtml($arriendoHtml . $luzHtml . $gasHtml . $aguaHtml . $otrosHtml);

        $cardsHtml .= '<div class="vale-v2-sheet">'
            . '<table class="vale-v2-head" role="presentation">'
            . '<tr>'
            . '<td class="vale-v2-head-left">'
            . '<div class="vale-v2-doc-title">VALE DE COBRO</div>'
            . '<div class="vale-v2-company-name">MERCADO SAN PEDRO</div>'
            . '<div class="vale-v2-company-meta">Comercial Patagual Limitada<br>Tucapel 155, San Pedro de la Paz</div>'
            . '</td>'
            . '<td class="vale-v2-head-right">'
            . '<div class="vale-v2-logo-wrap">' . $logoHtml . '</div>'
            . '</td>'
            . '</tr>'
            . '</table>'
            . '<table class="vale-v2-top" role="presentation">'
            . '<tr><td colspan="2" class="vale-v2-title">MES DE ARRIENDO ' . htmlspecialchars($periodoTitle, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td class="vale-v2-label">ARRENDATARIO</td><td class="vale-v2-value">' . htmlspecialchars($nombreArr, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td class="vale-v2-label">N° LOCAL</td><td class="vale-v2-value vale-v2-center">' . htmlspecialchars($localLabel, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td class="vale-v2-total-label">TOTAL A PAGAR</td><td class="vale-v2-total-value">' . omEmailFmtMoneyPayable($totalPagarConIvaArriendo) . '</td></tr>'
            . '</table>'
            . '<table class="vale-v2-summary" role="presentation">'
            . '<tr><td class="sum-label">Arriendo ' . htmlspecialchars($periodoTitle, ENT_QUOTES, 'UTF-8') . '</td><td class="sum-value">' . omEmailFmtMoneyPayable($totArriendoConIva) . '</td></tr>'
            . $resumenServiciosHtml
            . '</table>'
            . '<div class="vale-v2-detail-wrap">' . $detailBlockHtml . '</div>'
            . '</div>';

        $linesText[] = 'Documento #' . $docId
            . ' | Local: ' . $localLabel
            . ' | Total: ' . omEmailFmtMoneyPayable($totalPagarConIvaArriendo)
            . ' | Saldo favor aplicado: ' . omEmailFmtMoney($saldoFavorAplicadoDocumento)
            . ' | Arriendo : ' . omEmailFmtMoneyPayable($totArriendoConIva)
            . ' | Luz: ' . omEmailFmtMoney($totLuz)
            . ' | Gas: ' . omEmailFmtMoneyPayable($totGas)
            . ' | Agua: ' . omEmailFmtMoneyPayable($totAgua)
            . ' | Otros: ' . omEmailFmtMoney($totOtros);
    }

    $valeV2Css = '<style>'
        . '.vale-v2-wrap{font-family:Arial,DejaVu Sans,sans-serif;color:#111;max-width:760px;margin:0 auto;padding:0 10px;}'
        . '.vale-v2-sheet{border:1px solid #2c2c2c;background:#fff;margin:0 auto 18px auto;padding:0;}'
        . '.vale-v2-head{width:100%;border-collapse:collapse;table-layout:fixed;}'
        . '.vale-v2-head td{border:1px solid #5b5b5b;padding:8px 9px;vertical-align:top;}'
        . '.vale-v2-head-left{width:66%;}'
        . '.vale-v2-head-right{width:34%;text-align:right;}'
        . '.vale-v2-doc-title{font-size:17px;font-weight:900;letter-spacing:0.2px;}'
        . '.vale-v2-company-name{font-size:15px;font-weight:800;margin-top:10px;}'
        . '.vale-v2-company-meta{font-size:11px;line-height:1.3;margin-top:5px;}'
        . '.vale-v2-logo-wrap{min-height:56px;}'
        . '.vale-v2-logo-fallback{display:inline-block;border:1px solid #5b5b5b;padding:7px 12px;font-weight:800;font-size:12px;}'
        . '.vale-v2-top,.vale-v2-summary{width:100%;border-collapse:collapse;table-layout:fixed;}'
        . '.vale-v2-top td,.vale-v2-summary td{border:1px solid #5b5b5b;padding:7px 8px;}'
        . '.vale-v2-title{background:#8cc84b;text-align:center;font-style:italic;font-weight:900;font-size:18px;letter-spacing:0.2px;}'
        . '.vale-v2-label{width:38%;font-style:italic;font-weight:800;}'
        . '.vale-v2-value{font-weight:800;}'
        . '.vale-v2-center{text-align:center;}'
        . '.vale-v2-total-label{background:#8cc84b;font-style:italic;font-weight:900;font-size:24px;}'
        . '.vale-v2-total-value{background:#8cc84b;font-weight:900;font-size:32px;text-align:center;}'
        . '.vale-v2-summary{margin-top:8px;}'
        . '.vale-v2-summary .sum-label{font-weight:700;}'
        . '.vale-v2-summary .sum-value{text-align:right;font-weight:700;}'
        . '.vale-v2-summary .sum-balance{color:#0f5132;background:#d1e7dd;font-weight:800;}'
        . '.vale-v2-detail-wrap{padding:8px;}'
        . '.vale-v2-detail table{background:#fff;}'
        . '.vale-v2-detail td{font-size:11px;line-height:1.22;}'
        . '</style>';

    $subject = '[MSP] Cobro -- ' . $periodoTitle . ' -- ' . mb_strtoupper($nombreArr, 'UTF-8');
    $body = $valeV2Css . '<div class="vale-v2-wrap">'
        . $cardsHtml
        . '</div>';

    $altBody = 'Cobro MSP' . PHP_EOL
        . 'Arrendatario: ' . $nombreArr . PHP_EOL
        . 'Periodo: ' . $periodoYm . PHP_EOL
        . implode(PHP_EOL, $linesText);

    return [$subject, $body, $altBody];
}
