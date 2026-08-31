<?php
declare(strict_types=1);

function rpCmpFmtMoney(mixed $value): string
{
    $num = (float) $value;
    $decimals = abs($num - round($num)) < 0.005 ? 0 : 2;
    return '$ ' . number_format($num, $decimals, ',', '.');
}

function rpCmpFmtFechaDmY(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return '--';
    }
    $d = DateTime::createFromFormat('Y-m-d', substr($raw, 0, 10));
    return $d ? $d->format('d-m-Y') : (string) $raw;
}

function rpCmpFmtRut(?string $raw): string
{
    $rut = trim((string) $raw);
    if ($rut === '') {
        return '';
    }

    $compact = strtoupper(preg_replace('/[^0-9kK]/', '', $rut) ?? '');
    if (strlen($compact) < 2) {
        return $rut;
    }

    $dv = substr($compact, -1);
    $body = substr($compact, 0, -1);
    if ($body === '' || preg_match('/^[0-9]+$/', $body) !== 1) {
        return $rut;
    }

    return number_format((int) $body, 0, '', '.') . '-' . $dv;
}

function rpCmpPeriodoLabel(?string $periodoYm): string
{
    $months = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    if ($periodoYm === null || trim($periodoYm) === '') {
        return '';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($d === false || $d->format('Y-m') !== $periodoYm) {
        return $periodoYm;
    }

    return mb_strtoupper($months[(int) $d->format('n')] ?? $periodoYm, 'UTF-8') . ' ' . $d->format('Y');
}

function rpCmpPeriodoPrevioLabel(?string $periodoYm): string
{
    if ($periodoYm === null || trim($periodoYm) === '') {
        return '';
    }

    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($d === false || $d->format('Y-m') !== $periodoYm) {
        return rpCmpPeriodoLabel($periodoYm);
    }

    return rpCmpPeriodoLabel($d->modify('-1 month')->format('Y-m'));
}

function rpCmpPeriodoRelativoLabel(?string $periodoYm, int $monthsBack): string
{
    if ($periodoYm === null || trim($periodoYm) === '') {
        return '';
    }

    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($d === false || $d->format('Y-m') !== $periodoYm) {
        return rpCmpPeriodoLabel($periodoYm);
    }

    $months = max(0, $monthsBack);
    return rpCmpPeriodoLabel($d->modify('-' . $months . ' month')->format('Y-m'));
}

function rpCmpConceptUiLabel(?string $nombreRaw, ?string $codigoRaw = null): string
{
    $nombre = trim((string) $nombreRaw);
    $codigo = strtoupper(trim((string) $codigoRaw));

    $mapByCode = [
        'MULTA' => 'Multa',
        'DANO' => 'Daño/Reparación',
        'AJUSTE' => 'Otros cargos',
        'CARGO_EXTRA' => 'Cargo extra',
        'COMISION' => 'Comisión',
    ];
    if ($codigo !== '' && isset($mapByCode[$codigo])) {
        return $mapByCode[$codigo];
    }

    if ($nombre === '') {
        return 'Otro concepto';
    }

    $upper = strtoupper($nombre);
    if (isset($mapByCode[$upper])) {
        return $mapByCode[$upper];
    }

    $normalized = str_replace(['_', '-'], ' ', $nombre);
    $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
    $normalized = trim($normalized);
    if ($normalized === '') {
        return 'Otro concepto';
    }

    return mb_convert_case($normalized, MB_CASE_TITLE, 'UTF-8');
}

function rpCmpLogoDataUri(): string
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

function rpCmpTimbreDataUri(): string
{
    $timbrePath = dirname(__DIR__, 2) . '/assets/timbre.png';
    if (!is_file($timbrePath)) {
        return '';
    }

    $bin = @file_get_contents($timbrePath);
    if (!is_string($bin) || $bin === '') {
        return '';
    }

    return 'data:image/png;base64,' . base64_encode($bin);
}

function rpCmpFilenameSafePart(string $value, string $fallback): string
{
    $normalized = trim($value);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (is_string($converted) && $converted !== '') {
            $normalized = $converted;
        }
    }

    $safe = preg_replace('/[^A-Za-z0-9\-_]+/', '_', $normalized);
    $safe = is_string($safe) ? trim($safe, '_') : '';
    if (strlen($safe) > 54) {
        $safe = substr($safe, 0, 54);
        $safe = trim($safe, '_');
    }

    return $safe !== '' ? $safe : $fallback;
}

function rpCmpPeriodoFilenamePart(?string $periodoYm): string
{
    $months = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    if ($periodoYm === null || trim($periodoYm) === '') {
        return 'sin_periodo';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($date === false || $date->format('Y-m') !== $periodoYm) {
        return rpCmpFilenameSafePart($periodoYm, 'sin_periodo');
    }

    return ($months[(int) $date->format('n')] ?? $date->format('m')) . '-' . $date->format('Y');
}

function rpCmpBuildComprobanteFilename(string $numeroDocumento, array $arrData = [], array $docData = [], array $pagoData = []): string
{
    $periodo = rpCmpPeriodoFilenamePart((string) ($docData['periodo_ym'] ?? ''));
    $arrendatario = rpCmpFilenameSafePart((string) ($arrData['nombre_arrendatario'] ?? ''), 'arrendatario');
    $locales = rpCmpFilenameSafePart((string) ($docData['locales_contrato'] ?? ''), 'sin_local');
    $idPago = (int) ($pagoData['id_pago'] ?? 0);

    return 'Comprobante_Gastos_'
        . $periodo
        . '_' . $arrendatario
        . '_(' . $locales . ')'
        . ($idPago > 0 ? ('_P' . $idPago) : '')
        . '.pdf';
}

/**
 * @return array{0:string,1:string} [filename, html]
 */
function rpBuildComprobanteGastosPdfPayload(array $pagoData, array $arrData, array $docData): array
{
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $nombreArr = trim((string) ($arrData['nombre_arrendatario'] ?? ''));
    if ($nombreArr === '') {
        $nombreArr = 'Arrendatario';
    }
    $rutArr = trim((string) ($arrData['rut'] ?? ''));
    $rutArrFmt = rpCmpFmtRut($rutArr);
    $fechaPagoRaw = (string) ($pagoData['fecha_pago'] ?? '');
    $medioPago = trim((string) ($pagoData['medio_pago'] ?? ''));
    $referencia = trim((string) ($pagoData['referencia_pago'] ?? ''));
    $banco = trim((string) ($pagoData['banco'] ?? ''));

    $numDoc = trim((string) ($docData['numero_documento'] ?? ''));
    $periodoYm = trim((string) ($docData['periodo_ym'] ?? ''));
    $local = trim((string) ($docData['locales_contrato'] ?? ($docData['codigo_local'] ?? ($docData['local'] ?? ''))));

    $periodoConsumoLuzGasLabel = rpCmpPeriodoPrevioLabel($periodoYm !== '' ? $periodoYm : null);
    $periodoConsumoAguaLabel = rpCmpPeriodoRelativoLabel($periodoYm !== '' ? $periodoYm : null, 2);
    $logoSrc = rpCmpLogoDataUri();
    $timbreSrc = rpCmpTimbreDataUri();

    $detalleConceptos = is_array($pagoData['detalle_conceptos'] ?? null) ? $pagoData['detalle_conceptos'] : [];
    $montoLuz = 0.0;
    $montoGas = 0.0;
    $montoAgua = 0.0;
    $montoOtros = 0.0;
    $otrosConceptosMap = [];
    $otrosConceptosDetalleMap = [];

    foreach ($detalleConceptos as $dc) {
        $nombreRaw = trim((string) ($dc['nombre_item'] ?? ($dc['codigo_item'] ?? '')));
        $nombre = mb_strtolower($nombreRaw, 'UTF-8');
        $monto = (float) ($dc['monto'] ?? 0);
        if ($monto <= 0) {
            continue;
        }

        if (str_contains($nombre, 'arriendo')) {
            continue;
        }
        if (str_contains($nombre, 'luz') || str_contains($nombre, 'electr') || str_contains($nombre, 'energia')) {
            $montoLuz += $monto;
        } elseif (str_contains($nombre, 'agua') || str_contains($nombre, 'alcantarill') || str_contains($nombre, 'sanitar')) {
            $montoAgua += $monto;
        } elseif (str_contains($nombre, 'gas')) {
            $montoGas += $monto;
        } else {
            $montoOtros += $monto;
            $label = rpCmpConceptUiLabel(
                $nombreRaw,
                (string) ($dc['codigo_item'] ?? '')
            );
            if (!isset($otrosConceptosMap[$label])) {
                $otrosConceptosMap[$label] = 0.0;
            }
            $otrosConceptosMap[$label] += $monto;
            $detalleItemsRaw = trim((string) ($dc['detalle_items'] ?? ''));
            if ($detalleItemsRaw !== '') {
                if (!isset($otrosConceptosDetalleMap[$label])) {
                    $otrosConceptosDetalleMap[$label] = [];
                }
                $detPartes = preg_split('/\s*\|\s*/u', $detalleItemsRaw) ?: [];
                foreach ($detPartes as $detParte) {
                    $detLimpio = trim((string) $detParte);
                    if ($detLimpio === '') {
                        continue;
                    }
                    $otrosConceptosDetalleMap[$label][$detLimpio] = $detLimpio;
                }
            }
        }
    }

    $otrosRowsHtml = '';
    if ($otrosConceptosMap !== []) {
        foreach ($otrosConceptosMap as $label => $montoConcepto) {
            if ((float) $montoConcepto <= 0.005) {
                continue;
            }
            $labelSafe = mb_strlen((string) $label, 'UTF-8') > 90
                ? (mb_substr((string) $label, 0, 87, 'UTF-8') . '...')
                : (string) $label;
            $detalleTexto = '';
            if (isset($otrosConceptosDetalleMap[$label]) && is_array($otrosConceptosDetalleMap[$label])) {
                $detalleLista = array_values($otrosConceptosDetalleMap[$label]);
                if ($detalleLista !== []) {
                    $detalleTexto = implode(' · ', $detalleLista);
                    if (mb_strlen($detalleTexto, 'UTF-8') > 160) {
                        $detalleTexto = mb_substr($detalleTexto, 0, 157, 'UTF-8') . '...';
                    }
                }
            }
            $otrosRowsHtml .= '<tr><td style="padding:10px 0;">'
                . '<table style="width:100%;border-collapse:collapse;table-layout:fixed;"><tr>'
                . '<td style="white-space:nowrap;width:52px;">Otros</td>'
                . '<td style="padding-left:8px;"><span class="line" style="display:block;width:100%;">' . $e($labelSafe) . '</span>'
                . ($detalleTexto !== '' ? '<div style="font-size:10px;color:#666;margin-top:2px;line-height:1.25;">' . $e($detalleTexto) . '</div>' : '')
                . '</td>'
                . '</tr></table>'
                . '</td>'
                . '<td style="text-align:right;white-space:nowrap;"><span class="money">' . $e(rpCmpFmtMoney($montoConcepto)) . '</span></td></tr>';
        }
    }
    if ($otrosRowsHtml === '') {
        $otrosRowsHtml = '<tr><td style="padding:10px 0;">'
            . '<table style="width:100%;border-collapse:collapse;table-layout:fixed;"><tr>'
            . '<td style="white-space:nowrap;width:52px;">Otros</td>'
            . '<td style="padding-left:8px;"><span class="line" style="display:block;width:100%;">-</span></td>'
            . '</tr></table>'
            . '</td>'
            . '<td style="text-align:right;white-space:nowrap;"><span class="money">' . $e(rpCmpFmtMoney(0)) . '</span></td></tr>';
    }

    $montoTotal = round($montoLuz + $montoGas + $montoAgua + $montoOtros, 2);

    $fechaDia = '';
    $fechaMes = '';
    $fechaAnio = '';
    $date = DateTime::createFromFormat('Y-m-d', substr($fechaPagoRaw, 0, 10));
    if ($date) {
        $fechaDia = $date->format('d');
        $fechaMes = $date->format('m');
        $fechaAnio = $date->format('Y');
    }

    $medioLower = mb_strtolower($medioPago, 'UTF-8');
    $isEfectivo = str_contains($medioLower, 'efectivo');
    $isTransferencia = str_contains($medioLower, 'transfer');
    $isCheque = str_contains($medioLower, 'cheque');

    $logoHtml = $logoSrc !== ''
        ? '<img src="' . $e($logoSrc) . '" alt="Logo" style="width:126px;height:auto;display:block;margin:0 auto;">'
        : '<div style="font-size:11px;font-weight:700;text-align:right;">MERCADO SAN PEDRO</div>';
    $timbreHtml = $timbreSrc !== ''
        ? '<img src="' . $e($timbreSrc) . '" alt="Timbre" style="max-width:165px;max-height:82px;display:block;">'
        : '';

    $filename = rpCmpBuildComprobanteFilename($numDoc, $arrData, $docData, $pagoData);

    $html = '<!DOCTYPE html>'
        . '<html lang="es"><head><meta charset="UTF-8"><style>'
        . '@page { margin: 14mm; }'
        . 'body{font-family:Arial,sans-serif;font-size:12px;color:#000;margin:0;padding:0;}'
        . '.sheet{border:1px solid #000;padding:14px 14px 22px 14px;}'
        . '.head{width:100%;border-collapse:collapse;}'
        . '.head td{vertical-align:top;}'
        . '.title{font-size:16px;font-weight:700;line-height:1.1;}'
        . '.nro{font-size:16px;font-weight:700;text-align:center;}'
        . '.line{display:inline-block;border-bottom:1px solid #000;height:16px;line-height:16px;vertical-align:bottom;}'
        . '.money{display:inline-block;border:1px solid #000;min-width:96px;height:20px;line-height:20px;padding:0 7px;text-align:left;}'
        . '.box{display:inline-block;border:1px solid #000;min-width:34px;height:18px;line-height:18px;text-align:center;}'
        . '.chk{display:inline-block;border:1px solid #000;width:24px;height:18px;line-height:18px;text-align:center;font-weight:700;}'
        . '</style></head><body>'
        . '<div class="sheet">'
        . '<table class="head"><tr>'
        . '<td style="width:62%;">'
        . '<div class="title">COMPROBANTE DE GASTOS</div>'
        . '<div style="margin-top:20px;font-size:18px;font-weight:700;">MERCADO SAN PEDRO</div>'
        . '<div style="margin-top:6px;font-size:12px;line-height:1.25;">Comercial Patagual Limitada<br>Tucapel 155<br>San Pedro de la Paz</div>'
        . '</td>'
        . '<td style="width:38%;">'
        . '<div class="nro" style="text-align:right;">N°' . $e($numDoc !== '' ? $numDoc : '000000001') . '</div>'
        . '<div style="margin-top:22px;width:100%;text-align:center;">' . $logoHtml . '</div>'
        . '<div style="margin-top:14px;font-size:12px;font-weight:700;text-align:right;">Fecha '
        . '<span class="box">' . $e($fechaDia) . '</span>'
        . '<span class="box">' . $e($fechaMes) . '</span>'
        . '<span class="box" style="min-width:52px;">' . $e($fechaAnio) . '</span>'
        . '</div>'
        . '</td>'
        . '</tr></table>'

        . '<table style="width:100%;border-collapse:collapse;margin-top:24px;font-size:12px;font-weight:700;">'
        . '<tr>'
        . '<td style="white-space:nowrap;width:56px;">Nombre</td>'
        . '<td><span class="line" style="display:block;width:100%;">' . $e($nombreArr . ($rutArrFmt !== '' ? ' (' . $rutArrFmt . ')' : '')) . '</span></td>'
        . '</tr>'
        . '<tr>'
        . '<td style="white-space:nowrap;padding-top:10px;">Local</td>'
        . '<td style="padding-top:10px;"><span class="line" style="display:block;width:100%;">' . $e($local) . '</span></td>'
        . '</tr>'
        . '</table>'

        . '<table style="width:100%;border-collapse:collapse;table-layout:fixed;margin-top:22px;font-size:12px;">'
        . '<tr><td style="width:82%;padding:10px 0;">'
        . '<table style="width:100%;border-collapse:collapse;table-layout:fixed;"><tr>'
        . '<td style="white-space:nowrap;width:180px;">Consumo eléctrico mes de</td>'
        . '<td style="padding-left:8px;"><span class="line" style="display:block;width:100%;">' . $e($periodoConsumoLuzGasLabel) . '</span></td>'
        . '</tr></table>'
        . '</td>'
        . '<td style="width:18%;text-align:right;white-space:nowrap;"><span class="money">' . $e(rpCmpFmtMoney($montoLuz)) . '</span></td></tr>'
        . '<tr><td style="padding:10px 0;">'
        . '<table style="width:100%;border-collapse:collapse;table-layout:fixed;"><tr>'
        . '<td style="white-space:nowrap;width:180px;">Consumo gas mes de</td>'
        . '<td style="padding-left:8px;"><span class="line" style="display:block;width:100%;">' . $e($periodoConsumoLuzGasLabel) . '</span></td>'
        . '</tr></table>'
        . '</td>'
        . '<td style="text-align:right;white-space:nowrap;"><span class="money">' . $e(rpCmpFmtMoney($montoGas)) . '</span></td></tr>'
        . '<tr><td style="padding:10px 0;">'
        . '<table style="width:100%;border-collapse:collapse;table-layout:fixed;"><tr>'
        . '<td style="white-space:nowrap;width:180px;">Consumo agua mes de</td>'
        . '<td style="padding-left:8px;"><span class="line" style="display:block;width:100%;">' . $e($periodoConsumoAguaLabel) . '</span></td>'
        . '</tr></table>'
        . '</td>'
        . '<td style="text-align:right;white-space:nowrap;"><span class="money">' . $e(rpCmpFmtMoney($montoAgua)) . '</span></td></tr>'
        . $otrosRowsHtml
        . '<tr><td style="padding-top:12px;padding-right:12px;text-align:right;font-size:18px;font-weight:700;">TOTAL</td>'
        . '<td style="text-align:right;padding-top:12px;white-space:nowrap;"><span class="money" style="font-weight:700;">' . $e(rpCmpFmtMoney($montoTotal)) . '</span></td></tr>'
        . '</table>'

        . '<div style="margin-top:22px;font-size:13px;font-weight:700;">Forma de pago</div>'
        . '<table style="width:100%;border-collapse:collapse;margin-top:6px;font-size:12px;">'
        . '<tr><td style="width:35%;padding:6px 0 6px 60px;">Efectivo</td><td style="width:10%;"><span class="chk">' . ($isEfectivo ? 'X' : '&nbsp;') . '</span></td><td></td></tr>'
        . '<tr><td style="padding:6px 0 6px 60px;">Transferencia</td><td><span class="chk">' . ($isTransferencia ? 'X' : '&nbsp;') . '</span></td><td></td></tr>'
        . '<tr><td style="padding:6px 0 6px 60px;">Cheque</td><td><span class="chk">' . ($isCheque ? 'X' : '&nbsp;') . '</span></td>'
        . '<td style="padding-left:28px;">'
        . '<table style="width:100%;border-collapse:collapse;table-layout:fixed;">'
        . '<tr>'
        . '<td style="white-space:nowrap;width:74px;">N° Cheque</td>'
        . '<td><span class="line" style="display:block;width:100%;">' . $e($isCheque ? $referencia : '') . '</span></td>'
        . '</tr>'
        . '<tr>'
        . '<td style="white-space:nowrap;padding-top:4px;">Banco</td>'
        . '<td style="padding-top:4px;"><span class="line" style="display:block;width:100%;">' . $e($isCheque ? $banco : '') . '</span></td>'
        . '</tr>'
        . '</table>'
        . '</td></tr>'
        . '</table>'

        . '<table style="width:100%;border-collapse:collapse;margin-top:52px;font-size:12px;font-weight:700;">'
        . '<tr>'
        . '<td style="width:100%;text-align:center;">'
        . '<div style="position:relative;display:inline-block;width:150px;height:80px;text-align:center;">'
        . '<span class="line" style="width:110px;position:absolute;bottom:18px;left:20px;"></span>'
        . '<span style="position:absolute;bottom:0;left:0;width:150px;text-align:center;">Timbre</span>'
        . ($timbreHtml !== '' ? '<div style="position:absolute;right:-2px;bottom:12px;z-index:3;transform:rotate(-14deg);">' . $timbreHtml . '</div>' : '')
        . '</div>'
        . '</td>'
        . '</tr>'
        . '</table>'

        . '</div></body></html>';

    return [$filename, $html];
}
