<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/mail_helper.php';
require_once __DIR__ . '/mail_templates/solicitud_notificacion_email.php';

function ctSolicitudesNotifEmailDomain(string $email): string
{
    $email = trim($email);
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }
    $parts = explode('@', $email);
    $domain = strtolower(trim((string) end($parts)));
    return $domain;
}

function ctSolicitudesNotifDomainBlocked(string $email, array $blockedDomains): bool
{
    $domain = ctSolicitudesNotifEmailDomain($email);
    if ($domain === '') {
        return false;
    }
    foreach ($blockedDomains as $blockedRaw) {
        $blocked = strtolower(trim((string) $blockedRaw));
        $blocked = ltrim($blocked, '@');
        if ($blocked === '') {
            continue;
        }
        if ($domain === $blocked) {
            return true;
        }
    }
    return false;
}

function ctSolicitudesNotifBaseSubject(array $solicitud): string
{
    $idSolicitud = (int) ($solicitud['id_solicitud'] ?? 0);
    $tipoNombre = trim((string) ($solicitud['tipo_nombre'] ?? 'Solicitud'));
    if ($tipoNombre === '') {
        $tipoNombre = 'Solicitud';
    }
    return '[CT Solicitud #' . $idSolicitud . '] ' . $tipoNombre;
}

function ctSolicitudesNotifBuildFichaUrl(int $idSolicitud): string
{
    $path = ctUrl('solicitudes/ficha.php') . '?id=' . max(0, $idSolicitud);
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return $path;
    }
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
    );
    $scheme = $isHttps ? 'https' : 'http';
    return $scheme . '://' . $host . $path;
}

function ctSolicitudesNotifResolveUsersCatalog(PDO $conn): array
{
    static $cacheByDsn = [];
    $key = spl_object_hash($conn);
    if (isset($cacheByDsn[$key]) && is_array($cacheByDsn[$key])) {
        return $cacheByDsn[$key];
    }

    $rows = ctSolicitudesRepoListUsuariosCorporativos($conn);
    $catalog = [];
    foreach ($rows as $row) {
        $idUsuario = (int) ($row['id_usuario'] ?? 0);
        if ($idUsuario <= 0) {
            continue;
        }
        $nombre = trim((string) ($row['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = 'Usuario #' . $idUsuario;
        }
        $email = trim((string) ($row['email'] ?? ''));
        $catalog[$idUsuario] = [
            'id_usuario' => $idUsuario,
            'nombre' => $nombre,
            'email' => $email,
        ];
    }
    $cacheByDsn[$key] = $catalog;
    return $catalog;
}

function ctSolicitudesNotifResolveContactsByUserIds(PDO $conn, array $idsUsuario): array
{
    $catalog = ctSolicitudesNotifResolveUsersCatalog($conn);
    $contacts = [];
    foreach ($idsUsuario as $idUsuarioRaw) {
        $idUsuario = (int) $idUsuarioRaw;
        if ($idUsuario <= 0) {
            continue;
        }
        if (isset($contacts[$idUsuario])) {
            continue;
        }
        $base = $catalog[$idUsuario] ?? null;
        $contacts[$idUsuario] = [
            'id_usuario' => $idUsuario,
            'nombre' => trim((string) ($base['nombre'] ?? '')) !== '' ? (string) $base['nombre'] : ('Usuario #' . $idUsuario),
            'email' => trim((string) ($base['email'] ?? '')),
        ];
    }
    return $contacts;
}

function ctSolicitudesNotifDispatchByUserIds(
    PDO $conn,
    array $solicitud,
    ?int $idAreaSolicitud,
    string $tipoEvento,
    array $recipientUserIds,
    int $idUsuarioAccion,
    string $title,
    string $message,
    array $extraPayload = []
): void {
    $idSolicitud = (int) ($solicitud['id_solicitud'] ?? 0);
    if ($idSolicitud <= 0 || $recipientUserIds === []) {
        return;
    }

    $cfg = ctMailConfig();
    if (!(bool) ($cfg['enabled'] ?? true)) {
        return;
    }

    $contacts = ctSolicitudesNotifResolveContactsByUserIds($conn, $recipientUserIds);
    if ($contacts === []) {
        return;
    }

    $idArea = $idAreaSolicitud !== null && $idAreaSolicitud > 0 ? $idAreaSolicitud : null;
    $subject = ctSolicitudesNotifBaseSubject($solicitud);
    $actorNameMap = ctTerrenosRepoResolveUsuariosDisplayMap($conn, [$idUsuarioAccion]);
    $actorNombre = trim((string) ($actorNameMap[$idUsuarioAccion] ?? '')) !== ''
        ? (string) $actorNameMap[$idUsuarioAccion]
        : ('Usuario #' . $idUsuarioAccion);
    $fechaEvento = gmdate('Y-m-d H:i:s');
    $urlFicha = ctSolicitudesNotifBuildFichaUrl($idSolicitud);
    $demoTo = trim((string) ($cfg['demo']['to'] ?? ''));
    $demoToIsValid = $demoTo !== '' && filter_var($demoTo, FILTER_VALIDATE_EMAIL) !== false;
    $blockedDomains = is_array($cfg['blocked_domains'] ?? null) ? $cfg['blocked_domains'] : [];
    $mail = null;

    foreach ($contacts as $contact) {
        $idUsuarioDest = (int) ($contact['id_usuario'] ?? 0);
        if ($idUsuarioDest <= 0 || $idUsuarioDest === $idUsuarioAccion) {
            continue;
        }

        $emailDestinoReal = trim((string) ($contact['email'] ?? ''));
        if (ctSolicitudesNotifDomainBlocked($emailDestinoReal, $blockedDomains)) {
            continue;
        }
        $emailDestino = $demoToIsValid ? $demoTo : $emailDestinoReal;
        $destinatarioNombre = trim((string) ($contact['nombre'] ?? ''));
        if ($destinatarioNombre === '') {
            $destinatarioNombre = 'Usuario #' . $idUsuarioDest;
        }

        $payloadRaw = array_merge(
            [
                'id_solicitud' => $idSolicitud,
                'id_area_solicitud' => $idArea,
                'tipo_evento' => $tipoEvento,
                'id_usuario_destinatario' => $idUsuarioDest,
                'email_destino' => $emailDestino,
                'email_destino_real' => $emailDestinoReal,
                'modo_demo' => $demoToIsValid,
            ],
            $extraPayload
        );
        $payloadJson = json_encode($payloadRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $idNotificacion = ctSolicitudesRepoQueueNotificacion(
            $conn,
            $idSolicitud,
            $idArea,
            $tipoEvento,
            $idUsuarioDest,
            $destinatarioNombre,
            $subject,
            $payloadJson === false ? null : $payloadJson
        );

        if (filter_var($emailDestino, FILTER_VALIDATE_EMAIL) === false) {
            ctSolicitudesRepoMarkNotificacionError($conn, $idNotificacion, 'Usuario destinatario sin correo válido.');
            continue;
        }

        try {
            if (!$mail instanceof \PHPMailer\PHPMailer\PHPMailer) {
                $mail = ctMailBuildSmtp();
                $mail->isHTML(true);
            }
            $mail->clearAddresses();
            $mail->clearAttachments();

            [$mailSubject, $mailHtml, $mailText] = ctSolicitudesBuildNotificationEmail([
                'subject' => $subject,
                'title' => $title,
                'message' => $message,
                'id_solicitud' => $idSolicitud,
                'tipo_nombre' => (string) ($solicitud['tipo_nombre'] ?? 'Solicitud'),
                'estado_nombre' => (string) ($solicitud['estado_nombre'] ?? ''),
                'area_nombre' => (string) ($extraPayload['area_nombre'] ?? ''),
                'actor_nombre' => $actorNombre,
                'fecha_evento' => $fechaEvento,
                'url_ficha' => $urlFicha,
            ]);

            $mail->Subject = $mailSubject;
            $mail->Body = $mailHtml;
            $mail->AltBody = $mailText;
            $mail->addAddress($emailDestino, $destinatarioNombre);
            $mail->send();

            ctSolicitudesRepoMarkNotificacionEnviada($conn, $idNotificacion);
        } catch (Throwable $exception) {
            ctSolicitudesRepoMarkNotificacionError($conn, $idNotificacion, $exception->getMessage());
        }
    }
}

function ctSolicitudesNotifyParticipantesByDepto(
    PDO $conn,
    array $solicitud,
    array $participantesRows,
    int $idUsuarioAccion
): void {
    $recipientIds = [];
    $areas = [];
    foreach ($participantesRows as $row) {
        $idUsuario = (int) ($row['id_participante_solicitud'] ?? ($row['id_usuario_corporativo'] ?? 0));
        if ($idUsuario > 0) {
            $recipientIds[$idUsuario] = true;
        }
        $idArea = (int) ($row['id_area_solicitud'] ?? 0);
        if ($idArea > 0) {
            $area = ctSolicitudesRepoFindAreaById($conn, $idArea);
            $nombreArea = trim((string) ($area['nombre'] ?? ''));
            if ($nombreArea !== '') {
                $areas[$nombreArea] = true;
            }
        }
        $areaName = trim((string) ($row['area_nombre'] ?? ''));
        if ($areaName !== '') {
            $areas[$areaName] = true;
        }
    }
    if ($recipientIds === []) {
        return;
    }

    $areaList = $areas === [] ? 'Áreas asignadas' : implode(', ', array_keys($areas));
    ctSolicitudesNotifDispatchByUserIds(
        $conn,
        $solicitud,
        null,
        'SOLICITUD_ASIGNADA_PARTICIPANTES',
        array_keys($recipientIds),
        $idUsuarioAccion,
        'Nueva solicitud asignada',
        'Se te asignó una solicitud en: ' . $areaList . '.',
        ['area_nombre' => $areaList]
    );
}

function ctSolicitudesNotifyAreaCompleta(
    PDO $conn,
    array $solicitud,
    int $idAreaSolicitud,
    int $idUsuarioAccion
): void {
    $idSolicitud = (int) ($solicitud['id_solicitud'] ?? 0);
    if ($idSolicitud <= 0 || $idAreaSolicitud <= 0) {
        return;
    }

    $recipients = [];
    $idSolicitante = (int) ($solicitud['id_solicitante'] ?? 0);
    if ($idSolicitante > 0) {
        $recipients[$idSolicitante] = true;
    }
    $participantes = ctSolicitudesRepoListParticipantesBySolicitudId($conn, $idSolicitud);
    foreach ($participantes as $participante) {
        if ((int) ($participante['id_area_solicitud'] ?? 0) !== $idAreaSolicitud) {
            continue;
        }
        $idUsuario = (int) ($participante['id_usuario_corporativo'] ?? 0);
        if ($idUsuario > 0) {
            $recipients[$idUsuario] = true;
        }
    }
    if ($recipients === []) {
        return;
    }

    $area = ctSolicitudesRepoFindAreaById($conn, $idAreaSolicitud);
    $areaNombre = trim((string) ($area['nombre'] ?? '')) !== '' ? (string) $area['nombre'] : ('Área #' . $idAreaSolicitud);
    ctSolicitudesNotifDispatchByUserIds(
        $conn,
        $solicitud,
        $idAreaSolicitud,
        'FORMULARIO_AREA_COMPLETADO',
        array_keys($recipients),
        $idUsuarioAccion,
        'Formulario de área marcado como completo',
        'El formulario del área ' . $areaNombre . ' fue marcado como completo.',
        ['area_nombre' => $areaNombre]
    );
}

function ctSolicitudesNotifyComentario(
    PDO $conn,
    array $solicitud,
    int $idAreaSolicitud,
    int $idUsuarioAccion,
    string $comentario
): void {
    $idSolicitud = (int) ($solicitud['id_solicitud'] ?? 0);
    if ($idSolicitud <= 0) {
        return;
    }

    $recipients = [];
    $idSolicitante = (int) ($solicitud['id_solicitante'] ?? 0);
    if ($idSolicitante > 0) {
        $recipients[$idSolicitante] = true;
    }
    $participantes = ctSolicitudesRepoListParticipantesBySolicitudId($conn, $idSolicitud);
    foreach ($participantes as $participante) {
        $idUsuario = (int) ($participante['id_usuario_corporativo'] ?? 0);
        if ($idUsuario <= 0) {
            continue;
        }
        if ($idAreaSolicitud > 0 && (int) ($participante['id_area_solicitud'] ?? 0) !== $idAreaSolicitud) {
            continue;
        }
        $recipients[$idUsuario] = true;
    }
    if ($recipients === []) {
        return;
    }

    $areaNombre = 'General';
    if ($idAreaSolicitud > 0) {
        $area = ctSolicitudesRepoFindAreaById($conn, $idAreaSolicitud);
        $areaNombre = trim((string) ($area['nombre'] ?? '')) !== '' ? (string) $area['nombre'] : ('Área #' . $idAreaSolicitud);
    }

    $texto = trim($comentario);
    if ((function_exists('mb_strlen') ? mb_strlen($texto) : strlen($texto)) > 260) {
        $texto = function_exists('mb_substr') ? (string) mb_substr($texto, 0, 260) . '...' : substr($texto, 0, 260) . '...';
    }

    ctSolicitudesNotifDispatchByUserIds(
        $conn,
        $solicitud,
        $idAreaSolicitud > 0 ? $idAreaSolicitud : null,
        'SOLICITUD_COMENTARIO',
        array_keys($recipients),
        $idUsuarioAccion,
        'Nuevo comentario en solicitud',
        'Se registró un comentario en ' . $areaNombre . ':' . PHP_EOL . $texto,
        ['area_nombre' => $areaNombre, 'comentario' => $texto]
    );
}

function ctSolicitudesNotifyDecisionFinal(
    PDO $conn,
    array $solicitud,
    string $decisionCodigo,
    int $idUsuarioAccion
): void {
    $idSolicitud = (int) ($solicitud['id_solicitud'] ?? 0);
    if ($idSolicitud <= 0) {
        return;
    }

    $recipients = [];
    $idSolicitante = (int) ($solicitud['id_solicitante'] ?? 0);
    if ($idSolicitante > 0) {
        $recipients[$idSolicitante] = true;
    }
    $participantes = ctSolicitudesRepoListParticipantesBySolicitudId($conn, $idSolicitud);
    foreach ($participantes as $participante) {
        $idUsuario = (int) ($participante['id_usuario_corporativo'] ?? 0);
        if ($idUsuario > 0) {
            $recipients[$idUsuario] = true;
        }
    }
    if ($recipients === []) {
        return;
    }

    $decision = strtoupper(trim($decisionCodigo));
    if ($decision === 'APROBADA') {
        $title = 'Solicitud aprobada';
        $message = 'La Gerencia General aprobó la solicitud.';
        $tipoEvento = 'SOLICITUD_APROBADA_FINAL';
    } else {
        $title = 'Solicitud rechazada/anulada';
        $message = 'La Gerencia General rechazó o anuló la solicitud.';
        $tipoEvento = 'SOLICITUD_RECHAZADA_FINAL';
    }

    ctSolicitudesNotifDispatchByUserIds(
        $conn,
        $solicitud,
        null,
        $tipoEvento,
        array_keys($recipients),
        $idUsuarioAccion,
        $title,
        $message
    );
}
