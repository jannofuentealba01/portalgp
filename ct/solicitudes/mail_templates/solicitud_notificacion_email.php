<?php
declare(strict_types=1);

function ctSolicitudesEmailEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ctSolicitudesEmailFmtUtc(?string $raw): string
{
    $value = trim((string) $raw);
    if ($value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->format('d-m-Y H:i');
    } catch (Throwable $exception) {
        return $value;
    }
}

function ctSolicitudesBuildNotificationEmail(array $payload): array
{
    $subject = trim((string) ($payload['subject'] ?? 'Notificación CT'));
    $titulo = trim((string) ($payload['title'] ?? 'Actualización de solicitud'));
    $mensaje = trim((string) ($payload['message'] ?? 'Se registró una actualización.'));
    $solicitudId = (int) ($payload['id_solicitud'] ?? 0);
    $tipoNombre = trim((string) ($payload['tipo_nombre'] ?? 'Solicitud'));
    $estadoNombre = trim((string) ($payload['estado_nombre'] ?? ''));
    $areaNombre = trim((string) ($payload['area_nombre'] ?? ''));
    $actorNombre = trim((string) ($payload['actor_nombre'] ?? ''));
    $fechaEvento = ctSolicitudesEmailFmtUtc((string) ($payload['fecha_evento'] ?? ''));
    $urlFicha = trim((string) ($payload['url_ficha'] ?? ''));

    $headerMeta = [];
    if ($estadoNombre !== '') {
        $headerMeta[] = 'Estado: ' . $estadoNombre;
    }
    if ($areaNombre !== '') {
        $headerMeta[] = 'Área: ' . $areaNombre;
    }
    if ($actorNombre !== '') {
        $headerMeta[] = 'Usuario: ' . $actorNombre;
    }
    if ($fechaEvento !== '') {
        $headerMeta[] = 'Fecha: ' . $fechaEvento;
    }

    $html = '<!doctype html><html lang="es"><head><meta charset="UTF-8"><title>'
        . ctSolicitudesEmailEscape($subject)
        . '</title></head><body style="margin:0;padding:20px;background:#f5f7fb;color:#0f172a;font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:720px;margin:0 auto;background:#ffffff;border:1px solid #dbe2f0;border-radius:8px;">'
        . '<tr><td style="padding:18px 20px;border-bottom:1px solid #e6ebf5;">'
        . '<div style="font-size:12px;color:#4f5d75;letter-spacing:.05em;text-transform:uppercase;">CT · Solicitudes</div>'
        . '<h2 style="margin:8px 0 0 0;font-size:24px;line-height:1.2;color:#0b1f4a;">' . ctSolicitudesEmailEscape($titulo) . '</h2>'
        . '<div style="margin-top:6px;font-size:14px;color:#334155;">Solicitud #' . $solicitudId . ' · ' . ctSolicitudesEmailEscape($tipoNombre) . '</div>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 20px;">'
        . '<p style="margin:0 0 12px 0;font-size:15px;line-height:1.5;">' . nl2br(ctSolicitudesEmailEscape($mensaje)) . '</p>';

    if ($headerMeta !== []) {
        $html .= '<ul style="margin:0 0 12px 16px;padding:0;color:#334155;font-size:14px;">';
        foreach ($headerMeta as $line) {
            $html .= '<li style="margin:4px 0;">' . ctSolicitudesEmailEscape($line) . '</li>';
        }
        $html .= '</ul>';
    }

    if ($urlFicha !== '') {
        $html .= '<p style="margin:16px 0 0 0;">'
            . '<a href="' . ctSolicitudesEmailEscape($urlFicha) . '" style="display:inline-block;background:#1d4ed8;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:6px;font-size:14px;">Abrir solicitud</a>'
            . '</p>';
    }

    $html .= '</td></tr></table></body></html>';

    $textLines = [
        $titulo,
        'Solicitud #' . $solicitudId . ' - ' . $tipoNombre,
        '',
        $mensaje,
    ];
    foreach ($headerMeta as $line) {
        $textLines[] = $line;
    }
    if ($urlFicha !== '') {
        $textLines[] = '';
        $textLines[] = 'Abrir solicitud: ' . $urlFicha;
    }

    return [$subject, $html, implode(PHP_EOL, $textLines)];
}
