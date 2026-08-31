<?php
declare(strict_types=1);

/**
 * Template de correo: Comprobante de pago al arrendatario.
 *
 * @return array{0: string, 1: string, 2: string} [subject, bodyHtml, altText]
 */

function rpEmailFmtMoney(mixed $value): string
{
    $num = (float) $value;
    $decimals = abs($num - round($num)) < 0.005 ? 0 : 2;
    return '$' . number_format($num, $decimals, ',', '.');
}

function rpEmailFmtMoneyPayable(mixed $value): string
{
    $num = max(0.0, (float) $value);
    $roundedUp = ceil($num);
    return '$' . number_format($roundedUp, 0, ',', '.');
}

function rpEmailFmtFecha(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return '--';
    }
    $d = DateTime::createFromFormat('Y-m-d', substr($raw, 0, 10));
    return $d ? $d->format('d-m-Y') : $raw;
}

function rpEmailFmtRut(?string $raw): string
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

function rpEmailPeriodoLabel(?string $periodoYm): string
{
    $months = [
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    if ($periodoYm === null || trim($periodoYm) === '') {
        return '--';
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($d === false || $d->format('Y-m') !== $periodoYm) {
        return $periodoYm;
    }

    return mb_strtoupper($months[(int) $d->format('n')] ?? $periodoYm, 'UTF-8') . ' ' . $d->format('Y');
}

function rpEmailPrioridadConcepto(string $codigo): int
{
    return function_exists('msp2PagoPrioridadImputacion')
        ? msp2PagoPrioridadImputacion($codigo)
        : match ($codigo) {
            'ARRIENDO' => 10, 'SERVICIO_LUZ' => 20, 'SERVICIO_GAS' => 30,
            'SERVICIO_AGUA' => 40, 'MULTA' => 50, 'DANO' => 60, 'AJUSTE' => 70, default => 80,
        };
}

function rpEmailConceptLabel(?string $nombreRaw, ?string $codigoRaw = null): string
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

function rpEmailConceptDetail(?string $detalleRaw): string
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

function rpBuildValeEmailContent(array $pagoData, array $arrData, array $docData): array
{
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $nombreArr = trim((string) ($arrData['nombre_arrendatario'] ?? ''));
    if ($nombreArr === '') {
        $nombreArr = 'Arrendatario';
    }
    $rutArr        = trim((string) ($arrData['rut'] ?? ''));
    $rutArrFmt     = rpEmailFmtRut($rutArr);
    $fechaPagoRaw  = (string) ($pagoData['fecha_pago'] ?? '');
    $fechaPago     = rpEmailFmtFecha($fechaPagoRaw);
    $montoPagado   = (float) ($pagoData['monto_pagado'] ?? 0);
    $montoAplicado = (float) ($pagoData['monto_aplicado'] ?? $montoPagado);
    $excedente     = max(0.0, round($montoPagado - $montoAplicado, 2));

    $numDoc      = trim((string) ($docData['numero_documento'] ?? ''));
    $periodoYm   = trim((string) ($docData['periodo_ym'] ?? ''));
    $saldoNuevo  = (float) ($docData['saldo_pendiente_nuevo'] ?? 0);
    $periodoLabel = rpEmailPeriodoLabel($periodoYm !== '' ? $periodoYm : null);

    $detalleConceptos = is_array($pagoData['detalle_conceptos'] ?? null) ? $pagoData['detalle_conceptos'] : [];

    // Ordenar por prioridad: Arriendo → Luz → Gas → Agua → Otros
    usort($detalleConceptos, static function (array $a, array $b): int {
        $ca = strtoupper(trim((string) ($a['codigo_item'] ?? '')));
        $cb = strtoupper(trim((string) ($b['codigo_item'] ?? '')));
        return rpEmailPrioridadConcepto($ca) <=> rpEmailPrioridadConcepto($cb);
    });

    // Construir filas de conceptos dinámicamente (solo con monto > 0)
    $conceptoRowsHtml = '';
    $conceptosAlt     = [];
    foreach ($detalleConceptos as $dc) {
        $codigo = strtoupper(trim((string) ($dc['codigo_item'] ?? '')));
        $nombre = rpEmailConceptLabel(
            (string) ($dc['nombre_item'] ?? $codigo),
            $codigo
        );
        $detalle = rpEmailConceptDetail((string) ($dc['detalle_items'] ?? ''));
        $monto  = (float) ($dc['monto'] ?? 0);
        if ($monto <= 0.005) {
            continue;
        }
        $conceptoRowsHtml .= '<tr>'
            . '<td style="padding:7px 12px;border-bottom:1px solid #f1f5f9;font-size:14px;">'
            . $e($nombre)
            . ($detalle !== '' ? '<div style="margin-top:3px;color:#64748b;font-size:12px;line-height:1.3;">' . $e($detalle) . '</div>' : '')
            . '</td>'
            . '<td align="right" style="padding:7px 12px;border-bottom:1px solid #f1f5f9;font-size:14px;white-space:nowrap;">' . $e(rpEmailFmtMoney($monto)) . '</td>'
            . '</tr>';
        $conceptosAlt[] = $nombre . ': ' . rpEmailFmtMoney($monto);
    }
    if ($conceptoRowsHtml === '') {
        $conceptoRowsHtml = '<tr><td colspan="2" style="padding:7px 12px;color:#94a3b8;font-style:italic;font-size:14px;">Sin detalle de conceptos.</td></tr>';
    }

    $excedenteRowHtml = '';
    if ($excedente > 0.005) {
        $excedenteRowHtml = '<tr>'
            . '<td style="padding:7px 12px;font-size:13px;color:#6b7280;">Excedente (saldo a favor)</td>'
            . '<td align="right" style="padding:7px 12px;font-size:13px;color:#6b7280;white-space:nowrap;">' . $e(rpEmailFmtMoney($excedente)) . '</td>'
            . '</tr>';
    }

    $statusLabel  = $saldoNuevo <= 0.005
        ? 'Documento pagado en su totalidad ✓'
        : 'Saldo pendiente: ' . rpEmailFmtMoneyPayable($saldoNuevo);
    $statusBg     = $saldoNuevo <= 0.005 ? '#dcfce7' : '#fef9c3';
    $statusColor  = $saldoNuevo <= 0.005 ? '#15803d' : '#854d0e';
    $statusBorder = $saldoNuevo <= 0.005 ? '#86efac' : '#fde68a';

    $arrendatarioCell = $e($nombreArr)
        . ($rutArrFmt !== '' ? ' <span style="color:#94a3b8;font-weight:400;">(' . $e($rutArrFmt) . ')</span>' : '');

    $body = '<!DOCTYPE html>'
        . '<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">'
        . '<title>Comprobante de Pago — MSP</title></head>'
        . '<body style="margin:0;padding:24px 16px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"'
        . ' style="max-width:600px;width:100%;background:#ffffff;border:1px solid #cbd5e1;">'

        // ── Encabezado verde ──────────────────────────────────────────────────
        . '<tr><td style="background:#16a34a;padding:20px 24px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
        . '<tr>'
        . '<td style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#bbf7d0;">Mercado San Pedro</td>'
        . '<td align="right" style="font-size:12px;color:#86efac;">' . $e($fechaPago) . '</td>'
        . '</tr>'
        . '<tr><td colspan="2" style="padding-top:6px;">'
        . '<span style="display:inline-block;background:rgba(0,0,0,.18);color:#fff;font-size:10px;'
        . 'padding:2px 10px;border-radius:10px;letter-spacing:.08em;text-transform:uppercase;">Comprobante de Pago</span>'
        . '</td></tr>'
        . '<tr><td colspan="2" style="padding-top:8px;font-size:34px;font-weight:800;color:#ffffff;line-height:1.1;">'
        . $e(rpEmailFmtMoney($montoPagado))
        . '</td></tr>'
        . '</table>'
        . '</td></tr>'

        // ── Datos del arrendatario y documento ────────────────────────────────
        . '<tr><td style="padding:20px 24px 0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
        . ' style="font-size:14px;border-collapse:collapse;">'
        . '<tr>'
        . '<td style="padding:7px 0;border-bottom:1px solid #f1f5f9;color:#64748b;width:38%;vertical-align:top;">Arrendatario</td>'
        . '<td style="padding:7px 0;border-bottom:1px solid #f1f5f9;font-weight:600;">' . $arrendatarioCell . '</td>'
        . '</tr>'
        . '<tr>'
        . '<td style="padding:7px 0;border-bottom:1px solid #f1f5f9;color:#64748b;">Período</td>'
        . '<td style="padding:7px 0;border-bottom:1px solid #f1f5f9;">' . $e($periodoLabel) . '</td>'
        . '</tr>'
        . '<tr>'
        . '<td style="padding:7px 0;color:#64748b;">Documento</td>'
        . '<td style="padding:7px 0;">' . $e($numDoc !== '' ? $numDoc : '—') . '</td>'
        . '</tr>'
        . '</table>'
        . '</td></tr>'

        // ── Detalle del pago ──────────────────────────────────────────────────
        . '<tr><td style="padding:20px 24px 0;">'
        . '<div style="font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;font-weight:700;margin-bottom:8px;">Detalle del pago</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
        . ' style="font-size:14px;border-collapse:collapse;border:1px solid #e2e8f0;">'
        . '<tr style="background:#f8fafc;">'
        . '<th align="left" style="padding:8px 12px;font-weight:600;font-size:12px;color:#64748b;'
        . 'text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e2e8f0;">Concepto</th>'
        . '<th align="right" style="padding:8px 12px;font-weight:600;font-size:12px;color:#64748b;'
        . 'text-transform:uppercase;letter-spacing:.05em;border-bottom:1px solid #e2e8f0;">Monto</th>'
        . '</tr>'
        . $conceptoRowsHtml
        . '<tr style="background:#f8fafc;">'
        . '<td style="padding:9px 12px;border-top:2px solid #e2e8f0;font-weight:700;font-size:14px;">Aplicado al documento</td>'
        . '<td align="right" style="padding:9px 12px;border-top:2px solid #e2e8f0;font-weight:700;font-size:14px;white-space:nowrap;">'
        . $e(rpEmailFmtMoney($montoAplicado)) . '</td>'
        . '</tr>'
        . $excedenteRowHtml
        . '</table>'
        . '</td></tr>'

        // ── Banner de estado ──────────────────────────────────────────────────
        . '<tr><td style="padding:16px 24px;">'
        . '<div style="background:' . $statusBg . ';border:1px solid ' . $statusBorder . ';color:' . $statusColor . ';'
        . 'padding:11px 14px;border-radius:6px;font-weight:700;font-size:14px;">'
        . $e($statusLabel)
        . '</div>'
        . '</td></tr>'

        // ── Pie ───────────────────────────────────────────────────────────────
        . '<tr><td style="padding:0 24px 16px;border-top:1px solid #f1f5f9;">'
        . '<p style="margin:0;font-size:11px;color:#94a3b8;line-height:1.5;">'
        . 'Este comprobante fue generado automáticamente por el sistema MSP. Para consultas, comuníquese con administración.'
        . '</p>'
        . '</td></tr>'

        . '</table>'
        . '</td></tr></table>'
        . '</body></html>';

    $subject = '[MSP] Comprobante de pago -- '
        . ($periodoLabel !== '--' ? $periodoLabel : '--')
        . ' -- '
        . mb_strtoupper($nombreArr, 'UTF-8');

    $altText = 'COMPROBANTE DE PAGO — MERCADO SAN PEDRO' . PHP_EOL
        . str_repeat('-', 44) . PHP_EOL
        . 'Fecha            : ' . $fechaPago . PHP_EOL
        . 'Arrendatario     : ' . $nombreArr . ($rutArr !== '' ? ' (' . $rutArr . ')' : '') . PHP_EOL
        . 'Documento        : ' . ($numDoc !== '' ? $numDoc : '-') . PHP_EOL
        . 'Periodo          : ' . $periodoLabel . PHP_EOL
        . 'Monto pagado     : ' . rpEmailFmtMoney($montoPagado) . PHP_EOL
        . 'Aplicado doc     : ' . rpEmailFmtMoney($montoAplicado) . PHP_EOL
        . ($excedente > 0.005 ? 'Excedente        : ' . rpEmailFmtMoney($excedente) . PHP_EOL : '')
        . 'Estado           : ' . $statusLabel . PHP_EOL
        . ($conceptosAlt !== [] ? 'Detalle          : ' . implode(' | ', $conceptosAlt) . PHP_EOL : '');

    return [$subject, $body, $altText];
}
