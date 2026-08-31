<?php
declare(strict_types=1);

function rpValePagoPdfFmtMoney(mixed $value): string
{
    $num = (float) $value;
    $decimals = abs($num - round($num)) < 0.005 ? 0 : 2;
    return '$ ' . number_format($num, $decimals, ',', '.');
}

function rpValePagoPdfFmtFecha(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return '--';
    }

    $date = DateTime::createFromFormat('Y-m-d', substr($raw, 0, 10));
    return $date ? $date->format('d-m-Y') : (string) $raw;
}

function rpValePagoPdfFmtRut(?string $raw): string
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

function rpValePagoPdfConceptLabel(?string $nombreRaw, ?string $codigoRaw = null): string
{
    $nombre = trim((string) $nombreRaw);
    $codigo = strtoupper(trim((string) $codigoRaw));

    $mapByCode = [
        'ARRIENDO' => 'Arriendo',
        'SERVICIO_LUZ' => 'Servicio de luz',
        'SERVICIO_GAS' => 'Servicio de gas',
        'SERVICIO_AGUA' => 'Servicio de agua',
        'MULTA' => 'Multa',
        'DANO' => 'Daño/Reparación',
        'AJUSTE' => 'Otros cargos',
    ];
    if ($codigo !== '' && isset($mapByCode[$codigo])) {
        return $mapByCode[$codigo];
    }

    if ($nombre === '') {
        return 'Concepto';
    }

    return $nombre;
}

function rpValePagoPdfConceptPriority(?string $codigoRaw): int
{
    $codigo = strtoupper(trim((string) $codigoRaw));
    return function_exists('msp2PagoPrioridadImputacion')
        ? msp2PagoPrioridadImputacion($codigo)
        : match ($codigo) {
            'ARRIENDO' => 10, 'SERVICIO_LUZ' => 20, 'SERVICIO_GAS' => 30,
            'SERVICIO_AGUA' => 40, 'MULTA' => 50, 'DANO' => 60, 'AJUSTE' => 70, default => 80,
        };
}

function rpValePagoPdfConceptDetail(?string $detalleRaw): string
{
    $detalle = trim((string) $detalleRaw);
    if ($detalle === '') {
        return '';
    }

    $partes = preg_split('/\s*\|\s*/u', $detalle) ?: [];
    $partesLimpias = [];
    foreach ($partes as $parte) {
        $txt = trim((string) $parte);
        if ($txt === '') {
            continue;
        }
        $partesLimpias[] = $txt;
    }
    if ($partesLimpias === []) {
        return '';
    }

    return implode(' · ', $partesLimpias);
}

function rpValePagoPdfPeriodoLabel(?string $periodoYm): string
{
    $months = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];

    if ($periodoYm === null || trim($periodoYm) === '') {
        return '--';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($date === false || $date->format('Y-m') !== $periodoYm) {
        return $periodoYm;
    }

    return mb_strtoupper($months[(int) $date->format('n')] ?? $periodoYm, 'UTF-8') . ' ' . $date->format('Y');
}

function rpValePagoPdfPeriodoFilenamePart(?string $periodoYm): string
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
        return rpValePagoPdfSafePart($periodoYm, 'sin_periodo');
    }

    return ($months[(int) $date->format('n')] ?? $date->format('m')) . '-' . $date->format('Y');
}

function rpValePagoPdfSafePart(string $value, string $fallback): string
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

function rpValePagoPdfLogoDataUri(): string
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

function rpBuildValePagoPdfFilename(array $pagoData, array $arrData, array $docData): string
{
    $periodo = rpValePagoPdfPeriodoFilenamePart((string) ($docData['periodo_ym'] ?? ''));
    $arrendatario = rpValePagoPdfSafePart((string) ($arrData['nombre_arrendatario'] ?? ''), 'arrendatario');
    $locales = rpValePagoPdfSafePart((string) ($docData['locales_contrato'] ?? ''), 'sin_local');
    $idPago = (int) ($pagoData['id_pago'] ?? 0);

    return 'Vale_Pago_'
        . $periodo
        . '_' . $arrendatario
        . '_(' . $locales . ')'
        . ($idPago > 0 ? ('_P' . $idPago) : '')
        . '.pdf';
}

/**
 * @return array{0:string,1:string} [filename, html]
 */
function rpBuildValePagoPdfPayload(array $pagoData, array $arrData, array $docData): array
{
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $nombreArr = trim((string) ($arrData['nombre_arrendatario'] ?? ''));
    if ($nombreArr === '') {
        $nombreArr = 'Arrendatario';
    }

    $rutArr = rpValePagoPdfFmtRut((string) ($arrData['rut'] ?? ''));
    $fechaPago = rpValePagoPdfFmtFecha((string) ($pagoData['fecha_pago'] ?? ''));
    $medioPago = trim((string) ($pagoData['medio_pago'] ?? ''));
    $referencia = trim((string) ($pagoData['referencia_pago'] ?? ''));
    $banco = trim((string) ($pagoData['banco'] ?? ''));

    $montoPagado = (float) ($pagoData['monto_pagado'] ?? 0);
    $montoAplicado = (float) ($pagoData['monto_aplicado'] ?? $montoPagado);
    $excedente = max(0.0, round($montoPagado - $montoAplicado, 2));
    $saldoNuevo = (float) ($docData['saldo_pendiente_nuevo'] ?? 0);

    $numDoc = trim((string) ($docData['numero_documento'] ?? ''));
    $periodo = rpValePagoPdfPeriodoLabel((string) ($docData['periodo_ym'] ?? ''));
    $locales = trim((string) ($docData['locales_contrato'] ?? ''));

    $detalleConceptos = is_array($pagoData['detalle_conceptos'] ?? null) ? $pagoData['detalle_conceptos'] : [];
    usort($detalleConceptos, static function (array $a, array $b): int {
        $pa = rpValePagoPdfConceptPriority((string) ($a['codigo_item'] ?? ''));
        $pb = rpValePagoPdfConceptPriority((string) ($b['codigo_item'] ?? ''));
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        $na = trim((string) ($a['nombre_item'] ?? ($a['codigo_item'] ?? '')));
        $nb = trim((string) ($b['nombre_item'] ?? ($b['codigo_item'] ?? '')));
        return strcasecmp($na, $nb);
    });
    $rowsHtml = '';
    foreach ($detalleConceptos as $concepto) {
        if (!is_array($concepto)) {
            continue;
        }
        $codigo = trim((string) ($concepto['codigo_item'] ?? ''));
        $nombre = rpValePagoPdfConceptLabel(
            (string) ($concepto['nombre_item'] ?? $codigo),
            $codigo
        );
        $detalle = rpValePagoPdfConceptDetail((string) ($concepto['detalle_items'] ?? ''));
        $monto = (float) ($concepto['monto'] ?? 0);
        if ($monto <= 0.005) {
            continue;
        }
        $rowsHtml .= '<tr>'
            . '<td>'
            . $e($nombre !== '' ? $nombre : 'Concepto')
            . ($detalle !== '' ? '<div class="concept-sub">' . $e($detalle) . '</div>' : '')
            . '</td>'
            . '<td class="text-right">' . $e(rpValePagoPdfFmtMoney($monto)) . '</td>'
            . '</tr>';
    }
    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="2" class="muted">Sin detalle de conceptos.</td></tr>';
    }

    $filename = rpBuildValePagoPdfFilename($pagoData, $arrData, $docData);
    $estadoLabel = $saldoNuevo <= 0.005
        ? 'Documento pagado en su totalidad'
        : ('Saldo pendiente: ' . rpValePagoPdfFmtMoney($saldoNuevo));
    $logoSrc = rpValePagoPdfLogoDataUri();
    $logoHtml = $logoSrc !== ''
        ? '<img src="' . $e($logoSrc) . '" alt="Logo MSP" style="width:118px;height:auto;display:block;margin:0 0 0 auto;">'
        : '<div style="font-size:11px;font-weight:700;text-align:right;">MERCADO SAN PEDRO</div>';

    $html = '<!DOCTYPE html>'
        . '<html lang="es"><head><meta charset="UTF-8"><style>'
        . '@page{margin:14mm;}'
        . 'body{font-family:Arial,DejaVu Sans,sans-serif;font-size:12px;color:#000;margin:0;padding:0;}'
        . '.sheet{border:1px solid #000;padding:14px 14px 20px 14px;}'
        . '.head{width:100%;border-collapse:collapse;}'
        . '.head td{vertical-align:top;}'
        . '.title{font-size:16px;font-weight:700;line-height:1.15;}'
        . '.line{display:block;border-bottom:1px solid #000;min-height:18px;line-height:14px;padding-bottom:3px;}'
        . '.money{display:inline-block;border:1px solid #000;min-width:118px;height:24px;line-height:18px;padding:2px 8px 0 8px;text-align:right;font-weight:700;vertical-align:middle;}'
        . '.grid{width:100%;border-collapse:collapse;margin-top:18px;font-size:12px;font-weight:700;}'
        . '.grid td{padding:5px 0;vertical-align:top;}'
        . '.label{white-space:nowrap;width:112px;}'
        . '.value{padding-left:8px;}'
        . '.muted{color:#6b7280;font-weight:400;}'
        . '.section{font-size:12px;text-transform:uppercase;font-weight:700;margin-top:18px;}'
        . '.items{width:100%;border-collapse:collapse;table-layout:fixed;margin-top:6px;}'
        . '.items th,.items td{border:1px solid #000;padding:7px 8px;font-size:12px;}'
        . '.items th{background:#f4f4f4;text-align:left;}'
        . '.concept-sub{margin-top:3px;color:#555;font-size:11px;line-height:1.25;font-weight:400;}'
        . '.text-right{text-align:right;white-space:nowrap;}'
        . '.total td{font-weight:700;}'
        . '.status{margin-top:12px;border:1px solid #000;padding:8px 10px;font-weight:700;background:#f7f7f7;}'
        . '</style></head><body>'
        . '<div class="sheet">'
        . '<table class="head"><tr>'
        . '<td style="width:62%;">'
        . '<div class="title">VALE DE PAGO</div>'
        . '<div style="margin-top:18px;font-size:16px;font-weight:700;">MERCADO SAN PEDRO</div>'
        . '<div style="margin-top:5px;font-size:12px;line-height:1.25;">Comercial Patagual Limitada<br>Tucapel 155<br>San Pedro de la Paz</div>'
        . '</td>'
        . '<td style="width:38%;">'
        . '<div style="margin-top:14px;text-align:right;">' . $logoHtml . '</div>'
        . '<table style="margin-top:12px;margin-left:auto;border-collapse:collapse;"><tr>'
        . '<td style="padding-right:8px;vertical-align:middle;">Monto pagado</td>'
        . '<td style="vertical-align:middle;"><span class="money">' . $e(rpValePagoPdfFmtMoney($montoPagado)) . '</span></td>'
        . '</tr></table>'
        . '</td>'
        . '</tr></table>'
        . '<table class="grid">'
        . '<tr><td class="label">Fecha pago</td><td class="value"><span class="line" style="width:100%;">' . $e($fechaPago) . '</span></td></tr>'
        . '<tr><td class="label">Arrendatario</td><td class="value"><span class="line" style="width:100%;">' . $e($nombreArr) . ($rutArr !== '' ? ' <span class="muted">(' . $e($rutArr) . ')</span>' : '') . '</span></td></tr>'
        . '<tr><td class="label">Periodo</td><td class="value"><span class="line" style="width:100%;">' . $e($periodo) . '</span></td></tr>'
        . '<tr><td class="label">Locales</td><td class="value"><span class="line" style="width:100%;">' . $e($locales !== '' ? $locales : '-') . '</span></td></tr>'
        . '<tr><td class="label">Medio pago</td><td class="value"><span class="line" style="width:100%;">' . $e($medioPago !== '' ? $medioPago : '-') . '</span></td></tr>'
        . '<tr><td class="label">Referencia</td><td class="value"><span class="line" style="width:100%;">' . $e($referencia !== '' ? $referencia : '-') . '</span></td></tr>'
        . ($banco !== '' ? '<tr><td class="label">Banco</td><td class="value"><span class="line" style="width:100%;">' . $e($banco) . '</span></td></tr>' : '')
        . '</table>'
        . '<div class="section">Detalle del pago</div>'
        . '<table class="items"><thead><tr><th>Concepto</th><th class="text-right">Monto</th></tr></thead><tbody>'
        . $rowsHtml
        . '<tr class="total"><td>Aplicado al documento</td><td class="text-right">' . $e(rpValePagoPdfFmtMoney($montoAplicado)) . '</td></tr>'
        . ($excedente > 0.005 ? '<tr><td>Excedente / saldo a favor</td><td class="text-right">' . $e(rpValePagoPdfFmtMoney($excedente)) . '</td></tr>' : '')
        . '</tbody></table>'
        . '<div class="status">' . $e($estadoLabel) . '</div>'
        . '</div>'
        . '</body></html>';

    return [$filename, $html];
}
