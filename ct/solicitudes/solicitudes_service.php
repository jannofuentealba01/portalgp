<?php
declare(strict_types=1);

require_once __DIR__ . '/solicitudes_repository.php';
require_once __DIR__ . '/solicitudes_notifier.php';
require_once dirname(__DIR__) . '/predial/terrenos/terrenos_service.php';

function ctSolicitudesAllowedLines(): array
{
    return [10, 25, 50, 100];
}

function ctSolicitudesCurrentUserId(): int
{
    $idUsuario = (int) ($_SESSION['usuario']['id'] ?? 0);
    if ($idUsuario <= 0) {
        throw new RuntimeException('No fue posible identificar al usuario actual.');
    }
    return $idUsuario;
}

function ctSolicitudesCanCreateSolicitud(PDO $conn, int $idUsuario): bool
{
    return ctSolicitudesRepoUserBelongsToGerenciaGeneral($conn, $idUsuario);
}

function ctSolicitudesEnsureCanCreateSolicitud(PDO $conn, int $idUsuario): void
{
    if (ctSolicitudesCanCreateSolicitud($conn, $idUsuario)) {
        return;
    }
    throw new RuntimeException('Solo usuarios del departamento Gerencia General pueden crear solicitudes.');
}

function ctSolicitudesTipoSoportaAprobacionMaterializacion(array $solicitud): bool
{
    $tipoCodigo = strtoupper(trim((string) ($solicitud['tipo_codigo'] ?? '')));
    return $tipoCodigo === 'ADQUISICION';
}

function ctSolicitudesCanCommentSolicitud(PDO $conn, array $solicitud, int $idUsuario, ?int $idAreaSolicitud = null): bool
{
    $estado = strtoupper(trim((string) ($solicitud['estado_codigo'] ?? '')));
    if (in_array($estado, ['APROBADA', 'ANULADA'], true)) {
        return false;
    }
    if ($idUsuario <= 0) {
        return false;
    }

    if (ctSolicitudesRepoUserBelongsToGerenciaGeneral($conn, $idUsuario)) {
        return true;
    }

    $idSolicitud = (int) ($solicitud['id_solicitud'] ?? 0);
    if ($idSolicitud <= 0) {
        return false;
    }

    $idArea = $idAreaSolicitud !== null ? (int) $idAreaSolicitud : 0;
    if ($idArea > 0) {
        return is_array(ctSolicitudesRepoFindAreaAssignmentForUser($conn, $idSolicitud, $idArea, $idUsuario));
    }

    if ((int) ($solicitud['id_solicitante'] ?? 0) === $idUsuario) {
        return true;
    }

    $participantes = ctSolicitudesRepoListParticipantesBySolicitudId($conn, $idSolicitud);
    foreach ($participantes as $participante) {
        if ((int) ($participante['id_usuario_corporativo'] ?? 0) === $idUsuario) {
            return true;
        }
    }

    return false;
}

function ctSolicitudesEnsureCanCommentSolicitud(PDO $conn, array $solicitud, int $idUsuario, ?int $idAreaSolicitud = null): void
{
    if (ctSolicitudesCanCommentSolicitud($conn, $solicitud, $idUsuario, $idAreaSolicitud)) {
        return;
    }
    $idArea = $idAreaSolicitud !== null ? (int) $idAreaSolicitud : 0;
    if ($idArea > 0) {
        throw new RuntimeException('Solo Gerencia General y usuarios asignados al área pueden registrar comentarios en este bloque.');
    }
    throw new RuntimeException('No tienes permisos para registrar comentarios en esta solicitud.');
}

function ctSolicitudesBuildQuery(array $base, array $override = []): string
{
    $merged = array_merge($base, $override);
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null) {
            unset($merged[$key]);
        }
    }
    $qs = http_build_query($merged);
    return $qs === '' ? '' : ('?' . $qs);
}

function ctSolicitudesNormalizeFragment(?string $value): ?string
{
    $fragment = trim((string) $value);
    if ($fragment === '') {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9\-_:.]+$/', $fragment)) {
        return null;
    }
    return $fragment;
}

function ctSolicitudesRedirect(array $queryBase = [], ?string $fragment = null): never
{
    $clean = array_filter($queryBase, static fn($value) => $value !== '' && $value !== null);
    $qs = http_build_query($clean);
    $url = $qs === '' ? '' : ('?' . $qs);
    $fragmentSafe = ctSolicitudesNormalizeFragment($fragment);
    if ($fragmentSafe !== null) {
        $url .= '#' . $fragmentSafe;
    }
    header('Location: ' . $url);
    exit();
}

function ctSolicitudesIsHtmxRequest(): bool
{
    $headerValue = strtolower(trim((string) ($_SERVER['HTTP_HX_REQUEST'] ?? '')));
    return $headerValue === 'true' || $headerValue === '1' || $headerValue === 'yes';
}

function ctSolicitudesRenderHtmxCommentsFragment(
    PDO $conn,
    int $idSolicitud,
    array $state,
    int $currentUserId,
    int $idAreaSolicitud = 0,
    ?string $errorMessage = null,
    int $statusCode = 200
): never {
    $detailData = ctSolicitudesFetchDetail($conn, $idSolicitud);
    if (!is_array($detailData)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div class="alert alert-danger py-2 px-2 small mb-0">La solicitud ya no está disponible.</div>';
        exit();
    }

    $solicitud = $detailData['solicitud'];
    $panelesArea = $detailData['panelesArea'];
    $comentarios = $detailData['comentarios'];
    $usuariosMap = $detailData['usuariosMap'];
    $canCommentGeneral = ctSolicitudesCanCommentSolicitud($conn, $solicitud, $currentUserId, null);
    $postUrl = ctUrl('solicitudes/ficha.php') . ctSolicitudesBuildQuery($state['queryBase'], [
        'pagina' => $state['pagina'],
        'id' => $idSolicitud,
    ]);

    $comentariosByArea = [];
    $comentariosGenerales = [];
    foreach ($comentarios as $comentarioRow) {
        $areaId = 0;
        if (isset($comentarioRow['id_area_solicitud']) && is_numeric((string) $comentarioRow['id_area_solicitud'])) {
            $areaId = (int) $comentarioRow['id_area_solicitud'];
        } elseif (isset($comentarioRow['id_area_instancia']) && is_numeric((string) $comentarioRow['id_area_instancia'])) {
            foreach ($panelesArea as $panelTmp) {
                $respuestaTmp = $panelTmp['respuesta'] ?? null;
                if (is_array($respuestaTmp) && (int) ($respuestaTmp['id_area_instancia'] ?? 0) === (int) ($comentarioRow['id_area_instancia'] ?? 0)) {
                    $areaId = (int) ($panelTmp['area']['id_area_solicitud'] ?? 0);
                    break;
                }
            }
        }

        if ($areaId > 0) {
            if (!isset($comentariosByArea[$areaId])) {
                $comentariosByArea[$areaId] = [];
            }
            $comentariosByArea[$areaId][] = $comentarioRow;
            continue;
        }
        $comentariosGenerales[] = $comentarioRow;
    }

    require_once __DIR__ . '/views/partials/comentarios.php';

    http_response_code($statusCode);
    header('Content-Type: text/html; charset=UTF-8');

    if ($idAreaSolicitud > 0) {
        $areaName = 'Área #' . $idAreaSolicitud;
        $panelArea = null;
        foreach ($panelesArea as $panel) {
            $idAreaPanel = (int) ($panel['area']['id_area_solicitud'] ?? 0);
            if ($idAreaPanel === $idAreaSolicitud) {
                $panelArea = $panel;
                $areaName = (string) ($panel['area']['nombre'] ?? $areaName);
                break;
            }
        }

        $estadoAreaCodigo = 'PENDIENTE';
        $isAreaCompleta = false;
        $canEditAreaForm = false;
        if (is_array($panelArea)) {
            $estadoAreaCodigo = strtoupper(trim((string) (($panelArea['respuesta']['estado'] ?? 'PENDIENTE'))));
            $isAreaCompleta = in_array($estadoAreaCodigo, ['COMPLETA', 'CERRADA'], true);
            $estadoSolicitud = strtoupper(trim((string) ($solicitud['estado_codigo'] ?? '')));
            if (!in_array($estadoSolicitud, ['APROBADA', 'ANULADA'], true) && !$isAreaCompleta) {
                foreach ((array) ($panelArea['participantes'] ?? []) as $participanteArea) {
                    if ((int) ($participanteArea['id_usuario_corporativo'] ?? 0) === $currentUserId) {
                        $canEditAreaForm = true;
                        break;
                    }
                }
            }
        }
        $canEditSupportForm = ctSolicitudesTipoSoportaAprobacionMaterializacion($solicitud)
            && !$isAreaCompleta
            && $canEditAreaForm;
        if ($statusCode < 400) {
            header(
                'HX-Trigger: ' . json_encode(
                    [
                        'ct-solicitudes-area-status' => [
                            'areaId' => $idAreaSolicitud,
                            'estado' => $estadoAreaCodigo,
                            'canEditAreaForm' => $canEditAreaForm,
                            'canEditSupportForm' => $canEditSupportForm,
                        ],
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
            );
        }

        ctSolicitudesRenderAreaCommentsThread([
            'idArea' => $idAreaSolicitud,
            'areaName' => $areaName,
            'areaThreadAnchorId' => 'ct-sol-area-thread-' . $idAreaSolicitud,
            'areaComentarios' => $comentariosByArea[$idAreaSolicitud] ?? [],
            'canComment' => (bool) ctSolicitudesCanCommentSolicitud($conn, $solicitud, $currentUserId, $idAreaSolicitud),
            'currentUserId' => $currentUserId,
            'usuariosMap' => $usuariosMap,
            'idSolicitud' => $idSolicitud,
            'postUrl' => $postUrl,
            'errorMessage' => $errorMessage,
        ]);
        exit();
    }

    ctSolicitudesRenderGeneralCommentsCard([
        'generalComentarios' => $comentariosGenerales,
        'canComment' => (bool) $canCommentGeneral,
        'usuariosMap' => $usuariosMap,
        'idSolicitud' => $idSolicitud,
        'postUrl' => $postUrl,
        'errorMessage' => $errorMessage,
    ]);
    exit();
}

function ctSolicitudesNormalizeIdFilter(string $value): string
{
    if (!is_numeric($value)) {
        return '';
    }
    $id = (int) $value;
    return $id > 0 ? (string) $id : '';
}

function ctSolicitudesParseQuery(array $query): array
{
    $lineasPermitidas = ctSolicitudesAllowedLines();
    $lineas = isset($query['lineas']) && is_numeric((string) $query['lineas']) ? (int) $query['lineas'] : 25;
    if (!in_array($lineas, $lineasPermitidas, true)) {
        $lineas = 25;
    }

    $pagina = isset($query['pagina']) && is_numeric((string) $query['pagina']) ? max(1, (int) $query['pagina']) : 1;
    $idSolicitud = isset($query['id']) && is_numeric((string) $query['id']) ? max(0, (int) $query['id']) : 0;
    $filtroTexto = ctNormalizeText((string) ($query['filtroTexto'] ?? ''));
    $filtroEstado = ctSolicitudesNormalizeIdFilter((string) ($query['filtroEstado'] ?? ''));
    $filtroTipo = ctSolicitudesNormalizeIdFilter((string) ($query['filtroTipo'] ?? ''));
    $filtroSolicitante = ctSolicitudesNormalizeIdFilter((string) ($query['filtroSolicitante'] ?? ''));

    $queryBase = [
        'filtroTexto' => $filtroTexto,
        'filtroEstado' => $filtroEstado,
        'filtroTipo' => $filtroTipo,
        'filtroSolicitante' => $filtroSolicitante,
        'lineas' => $lineas,
    ];

    return [
        'lineasPermitidas' => $lineasPermitidas,
        'lineas' => $lineas,
        'pagina' => $pagina,
        'idSolicitud' => $idSolicitud,
        'filtroTexto' => $filtroTexto,
        'filtroEstado' => $filtroEstado,
        'filtroTipo' => $filtroTipo,
        'filtroSolicitante' => $filtroSolicitante,
        'queryBase' => $queryBase,
    ];
}

function ctSolicitudesBuildPaginationItems(int $paginaActual, int $totalPaginas): array
{
    if ($totalPaginas <= 1) {
        return [];
    }

    $pages = [1, $totalPaginas];
    for ($i = max(1, $paginaActual - 2); $i <= min($totalPaginas, $paginaActual + 2); $i++) {
        $pages[] = $i;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    $items = [];
    $last = null;
    foreach ($pages as $page) {
        if ($last !== null && $page > $last + 1) {
            $items[] = ['page' => null, 'label' => '...'];
        }
        $items[] = ['page' => $page, 'label' => (string) $page, 'active' => $page === $paginaActual];
        $last = $page;
    }
    return $items;
}

function ctSolicitudesNormalizeSummary(?string $value): ?string
{
    $summary = ctNormalizeText($value);
    if ($summary === '') {
        return null;
    }
    if ((function_exists('mb_strlen') ? mb_strlen($summary) : strlen($summary)) > 500) {
        throw new RuntimeException('El resumen excede el máximo de 500 caracteres.');
    }
    return $summary;
}

function ctSolicitudesNormalizeComentario(?string $value): string
{
    $comentario = ctNormalizeText($value);
    if ($comentario === '') {
        throw new RuntimeException('Debes ingresar un comentario.');
    }
    return $comentario;
}

function ctSolicitudesNormalizeAdjuntoField(?string $value, string $label, int $maxLen): string
{
    $normalized = ctNormalizeText($value);
    if ($normalized === '') {
        throw new RuntimeException('Debes ingresar ' . $label . '.');
    }
    if ((function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized)) > $maxLen) {
        throw new RuntimeException('El campo ' . $label . ' excede el máximo permitido.');
    }
    return $normalized;
}

function ctSolicitudesNormalizeOptionalField(?string $value, int $maxLen): ?string
{
    $normalized = ctNormalizeText($value);
    if ($normalized === '') {
        return null;
    }
    if ((function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized)) > $maxLen) {
        throw new RuntimeException('Uno de los campos excede el máximo permitido.');
    }
    return $normalized;
}

function ctSolicitudesNormalizeRut(?string $rawRut): ?string
{
    $normalized = strtoupper(ctNormalizeText((string) $rawRut));
    if ($normalized === '') {
        return null;
    }
    $clean = preg_replace('/[^0-9K]/', '', $normalized);
    if (!is_string($clean) || strlen($clean) < 2) {
        throw new RuntimeException('RUT inválido. Debe incluir cuerpo y dígito verificador.');
    }
    $dv = substr($clean, -1);
    $body = substr($clean, 0, -1);
    if ($body === '' || !preg_match('/^\d+$/', $body)) {
        throw new RuntimeException('RUT inválido. Formato esperado: XXXXXXXX-X');
    }
    return $body . '-' . $dv;
}

function ctSolicitudesNormalizeNuevoTerceroPayload(array $post): array
{
    $tipo = strtoupper(trim((string) ($post['tipo_persona'] ?? 'N')));
    if (!in_array($tipo, ['N', 'J'], true)) {
        throw new RuntimeException('Debes seleccionar un tipo de persona válido.');
    }
    $nombre = ctNormalizeText((string) ($post['nombre_razon_social'] ?? ''));
    if ($nombre === '') {
        throw new RuntimeException('Debes ingresar el nombre o razón social del tercero.');
    }
    if ((function_exists('mb_strlen') ? mb_strlen($nombre) : strlen($nombre)) > 200) {
        throw new RuntimeException('El nombre o razón social excede el máximo permitido.');
    }
    $rut = ctSolicitudesNormalizeRut((string) ($post['rut'] ?? ''));

    return [
        'tipo' => $tipo,
        'rut' => $rut,
        'nombre' => $nombre,
    ];
}

function ctSolicitudesNormalizeOptionalBit(?string $value): ?int
{
    $raw = strtolower(trim((string) $value));
    if ($raw === '') {
        return null;
    }
    if (in_array($raw, ['1', 'true', 'si', 'sí', 'yes'], true)) {
        return 1;
    }
    if (in_array($raw, ['0', 'false', 'no'], true)) {
        return 0;
    }
    throw new RuntimeException('Valor booleano inválido en formulario de área.');
}

function ctSolicitudesNormalizeOptionalPositiveDecimal(?string $value): ?float
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return null;
    }
    $normalized = str_replace(',', '.', $raw);
    if (!is_numeric($normalized)) {
        throw new RuntimeException('Superficie validada inválida.');
    }
    $result = (float) $normalized;
    if ($result <= 0) {
        throw new RuntimeException('La superficie validada debe ser mayor a 0.');
    }
    return $result;
}

function ctSolicitudesNormalizeAreaFormPayload(array $post, string $areaCodigo): array
{
    if ($areaCodigo === 'LEGAL') {
        return [
            'observaciones_legal' => ctSolicitudesNormalizeOptionalField((string) ($post['legal_observaciones'] ?? ''), 2000),
        ];
    }

    if ($areaCodigo === 'ARQUITECTURA') {
        return [
            'observaciones_arquitectura' => ctSolicitudesNormalizeOptionalField((string) ($post['arq_observaciones'] ?? ''), 2000),
        ];
    }

    return [];
}

function ctSolicitudesParseParticipantesFromPost(
    array $post,
    array $areasCatalog,
    array $participantesCatalog,
    array $participantesPermitidosPorArea = []
): array
{
    $areasById = [];
    foreach ($areasCatalog as $area) {
        $idArea = (int) ($area['id_area_solicitud'] ?? 0);
        if ($idArea > 0) {
            $areasById[$idArea] = $area;
        }
    }
    $participantesById = [];
    foreach ($participantesCatalog as $participante) {
        $idParticipante = (int) ($participante['id_participante_solicitud'] ?? 0);
        if ($idParticipante > 0) {
            $participantesById[$idParticipante] = $participante;
        }
    }

    $rows = [];
    $areaInputs = isset($post['area_participantes']) && is_array($post['area_participantes']) ? $post['area_participantes'] : [];
    $areaEnabledRaw = isset($post['area_enabled']) && is_array($post['area_enabled']) ? $post['area_enabled'] : [];
    $areasHabilitadas = [];
    foreach ($areaEnabledRaw as $idAreaRaw => $enabledRaw) {
        if (!is_numeric((string) $idAreaRaw)) {
            continue;
        }
        $idArea = (int) $idAreaRaw;
        if ($idArea <= 0 || !isset($areasById[$idArea])) {
            continue;
        }
        $isEnabled = !in_array(strtolower(trim((string) $enabledRaw)), ['', '0', 'false', 'off', 'no'], true);
        if ($isEnabled) {
            $areasHabilitadas[$idArea] = true;
        }
    }
    $responsables = isset($post['area_responsable']) && is_array($post['area_responsable']) ? $post['area_responsable'] : [];

    $areaIdsProcesar = [];
    if ($areasHabilitadas !== []) {
        foreach (array_keys($areasHabilitadas) as $idArea) {
            $areaIdsProcesar[(int) $idArea] = true;
        }
    } else {
        foreach ($areaInputs as $idAreaRaw => $selectedRaw) {
            if (!is_numeric((string) $idAreaRaw)) {
                continue;
            }
            $idArea = (int) $idAreaRaw;
            if ($idArea > 0 && isset($areasById[$idArea])) {
                $areaIdsProcesar[$idArea] = true;
            }
        }
    }

    foreach (array_keys($areaIdsProcesar) as $idArea) {
        $selectedRaw = $areaInputs[$idArea] ?? [];
        $selectedIds = [];
        if (is_array($selectedRaw)) {
            foreach ($selectedRaw as $itemRaw) {
                if (!is_scalar($itemRaw)) {
                    continue;
                }
                $item = trim((string) $itemRaw);
                if ($item === '' || !ctype_digit($item)) {
                    continue;
                }
                $idParticipante = (int) $item;
                if ($idParticipante > 0 && isset($participantesById[$idParticipante])) {
                    $selectedIds[$idParticipante] = true;
                }
            }
        } else {
            $parts = preg_split('/[;,\s]+/', trim((string) $selectedRaw)) ?: [];
            foreach ($parts as $part) {
                $part = trim((string) $part);
                if ($part === '' || !ctype_digit($part)) {
                    continue;
                }
                $idParticipante = (int) $part;
                if ($idParticipante > 0 && isset($participantesById[$idParticipante])) {
                    $selectedIds[$idParticipante] = true;
                }
            }
        }

        if ($selectedIds === []) {
            if ($areasHabilitadas !== []) {
                throw new RuntimeException('Cada área seleccionada debe tener al menos un participante.');
            }
            continue;
        }

        $permitidosEnArea = $participantesPermitidosPorArea[$idArea] ?? null;
        if (is_array($permitidosEnArea) && $permitidosEnArea !== []) {
            foreach (array_keys($selectedIds) as $idParticipanteCheck) {
                if (!isset($permitidosEnArea[$idParticipanteCheck])) {
                    throw new RuntimeException('Hay participantes no válidos para una de las áreas seleccionadas.');
                }
            }
        }

        $idResponsableRaw = $responsables[(string) $idArea] ?? ($responsables[$idArea] ?? null);
        $idResponsable = is_scalar($idResponsableRaw) && ctype_digit((string) $idResponsableRaw)
            ? (int) $idResponsableRaw
            : 0;
        if ($idResponsable > 0 && !isset($selectedIds[$idResponsable])) {
            throw new RuntimeException('El responsable del área debe estar incluido entre los participantes seleccionados.');
        }

        if ($idResponsable <= 0) {
            $idResponsable = (int) array_key_first($selectedIds);
        }

        foreach (array_keys($selectedIds) as $idParticipante) {
            $rows[] = [
                'id_area_solicitud' => $idArea,
                'id_participante_solicitud' => $idParticipante,
                'estado_trabajo' => 'PENDIENTE',
                'es_requerido' => true,
                'es_responsable_area' => $idParticipante === $idResponsable,
            ];
        }
    }

    if ($rows === []) {
        throw new RuntimeException('Debes seleccionar al menos un área con participantes.');
    }

    return $rows;
}

function ctSolicitudesBuildParticipantesPermitidosPorArea(array $participantesByAreaCreate): array
{
    $permitidos = [];
    foreach ($participantesByAreaCreate as $idAreaRaw => $participantesArea) {
        $idArea = (int) $idAreaRaw;
        if ($idArea <= 0 || !is_array($participantesArea)) {
            continue;
        }
        foreach ($participantesArea as $participante) {
            $idParticipante = (int) ($participante['id_participante_solicitud'] ?? 0);
            if ($idParticipante > 0) {
                if (!isset($permitidos[$idArea])) {
                    $permitidos[$idArea] = [];
                }
                $permitidos[$idArea][$idParticipante] = true;
            }
        }
    }
    return $permitidos;
}

function ctSolicitudesBuildDefaultParticipantesByTipoArea(array $defaultsRows, array $participantesCatalog): array
{
    $participantesValidos = [];
    foreach ($participantesCatalog as $participante) {
        $idParticipante = (int) ($participante['id_participante_solicitud'] ?? 0);
        if ($idParticipante > 0) {
            $participantesValidos[$idParticipante] = true;
        }
    }

    $result = [];
    foreach ($defaultsRows as $row) {
        $idTipo = (int) ($row['id_tipo_solicitud'] ?? 0);
        $idArea = (int) ($row['id_area_solicitud'] ?? 0);
        $idUsuario = (int) ($row['id_usuario_resuelto'] ?? 0);
        if ($idTipo <= 0 || $idArea <= 0 || $idUsuario <= 0 || !isset($participantesValidos[$idUsuario])) {
            continue;
        }

        if (!isset($result[$idTipo])) {
            $result[$idTipo] = [];
        }
        if (!isset($result[$idTipo][$idArea])) {
            $result[$idTipo][$idArea] = [
                'participants' => [],
                'responsable' => 0,
            ];
        }

        $result[$idTipo][$idArea]['participants'][$idUsuario] = true;
        if ((int) ($row['es_responsable'] ?? 0) === 1 && $result[$idTipo][$idArea]['responsable'] <= 0) {
            $result[$idTipo][$idArea]['responsable'] = $idUsuario;
        }
    }

    foreach ($result as $idTipo => $areasDefaults) {
        foreach ($areasDefaults as $idArea => $config) {
            $participants = array_map('intval', array_keys((array) ($config['participants'] ?? [])));
            $responsable = (int) ($config['responsable'] ?? 0);
            if ($participants === []) {
                unset($result[$idTipo][$idArea]);
                continue;
            }
            if ($responsable <= 0 || !in_array($responsable, $participants, true)) {
                $responsable = $participants[0];
            }
            $result[$idTipo][$idArea] = [
                'participants' => $participants,
                'responsable' => $responsable,
            ];
        }
        if ($result[$idTipo] === []) {
            unset($result[$idTipo]);
        }
    }

    return $result;
}

function ctSolicitudesApplyCreateParticipantesDefaults(
    array $post,
    int $idTipoSolicitud,
    array $defaultAreaIds,
    array $defaultsByTipoArea
): array {
    if ($idTipoSolicitud <= 0) {
        return $post;
    }

    $areaEnabled = isset($post['area_enabled']) && is_array($post['area_enabled']) ? $post['area_enabled'] : [];
    if ($areaEnabled === [] && $defaultAreaIds !== []) {
        $areaEnabled = [];
        foreach ($defaultAreaIds as $idAreaDefault) {
            $idAreaDefault = (int) $idAreaDefault;
            if ($idAreaDefault > 0) {
                $areaEnabled[(string) $idAreaDefault] = '1';
            }
        }
    }
    $post['area_enabled'] = $areaEnabled;

    $enabledAreaIds = [];
    foreach ($areaEnabled as $idAreaRaw => $enabledRaw) {
        if (!is_numeric((string) $idAreaRaw)) {
            continue;
        }
        $idArea = (int) $idAreaRaw;
        if ($idArea <= 0) {
            continue;
        }
        $isEnabled = !in_array(strtolower(trim((string) $enabledRaw)), ['', '0', 'false', 'off', 'no'], true);
        if ($isEnabled) {
            $enabledAreaIds[$idArea] = true;
        }
    }

    $areaParticipantes = isset($post['area_participantes']) && is_array($post['area_participantes']) ? $post['area_participantes'] : [];
    $areaResponsable = isset($post['area_responsable']) && is_array($post['area_responsable']) ? $post['area_responsable'] : [];
    $defaultsTipo = $defaultsByTipoArea[$idTipoSolicitud] ?? [];

    foreach (array_keys($enabledAreaIds) as $idArea) {
        $defaultsArea = $defaultsTipo[$idArea] ?? null;
        if (!is_array($defaultsArea)) {
            continue;
        }

        $selected = $areaParticipantes[(string) $idArea] ?? ($areaParticipantes[$idArea] ?? null);
        $hasSelected = false;
        if (is_array($selected)) {
            foreach ($selected as $value) {
                if (is_scalar($value) && ctype_digit(trim((string) $value))) {
                    $hasSelected = true;
                    break;
                }
            }
        } elseif (is_scalar($selected)) {
            $parts = preg_split('/[;,\s]+/', trim((string) $selected)) ?: [];
            foreach ($parts as $part) {
                if (ctype_digit(trim((string) $part))) {
                    $hasSelected = true;
                    break;
                }
            }
        }

        if (!$hasSelected) {
            $participantsDefaults = array_map('intval', (array) ($defaultsArea['participants'] ?? []));
            if ($participantsDefaults !== []) {
                $areaParticipantes[(string) $idArea] = array_map(static fn(int $id): string => (string) $id, $participantsDefaults);
            }
        }

        $responsableRaw = $areaResponsable[(string) $idArea] ?? ($areaResponsable[$idArea] ?? null);
        $hasResponsable = is_scalar($responsableRaw) && ctype_digit(trim((string) $responsableRaw)) && (int) $responsableRaw > 0;
        if (!$hasResponsable) {
            $idResponsableDefault = (int) ($defaultsArea['responsable'] ?? 0);
            if ($idResponsableDefault > 0) {
                $areaResponsable[(string) $idArea] = (string) $idResponsableDefault;
            }
        }
    }

    $post['area_participantes'] = $areaParticipantes;
    $post['area_responsable'] = $areaResponsable;
    return $post;
}

function ctSolicitudesResolveDefaultAreaIdsForTipo(array $tipoAreaConfig, int $idTipoSolicitud): array
{
    $result = [];
    $resultAdquisicion = [];
    $hasAdquisicionRows = false;
    if ($idTipoSolicitud <= 0) {
        return [];
    }
    foreach ($tipoAreaConfig as $row) {
        if ((int) ($row['id_tipo_solicitud'] ?? 0) !== $idTipoSolicitud) {
            continue;
        }
        $idArea = (int) ($row['id_area_solicitud'] ?? 0);
        if ($idArea <= 0) {
            continue;
        }
        $habilitaAuto = !empty($row['habilita_automaticamente']);
        $esRequerida = !empty($row['es_requerida']);
        $tipoCodigo = strtoupper(trim((string) ($row['tipo_codigo'] ?? '')));
        $areaCodigo = strtoupper(trim((string) ($row['area_codigo'] ?? '')));
        if ($tipoCodigo === 'ADQUISICION') {
            $hasAdquisicionRows = true;
            if (in_array($areaCodigo, ['LEGAL', 'ARQUITECTURA'], true)) {
                $resultAdquisicion[$idArea] = true;
            }
            continue;
        }
        if ($habilitaAuto || $esRequerida) {
            $result[$idArea] = true;
        }
    }

    if ($hasAdquisicionRows) {
        return array_map('intval', array_keys($resultAdquisicion));
    }
    return array_map('intval', array_keys($result));
}

function ctSolicitudesParseTitularesFromPost(array $post, string $fechaPorDefecto): array
{
    return ctTerrenosParseTitularesFromTable($post, $fechaPorDefecto);
}

function ctSolicitudesNormalizeDraftPayload(array $post): array
{
    $payload = ctTerrenosNormalizeWritePayload($post);
    $payload['fecha_adquisicion'] = ctTerrenosNormalizeDate(
        (string) ($post['fecha_adquisicion'] ?? ''),
        'la fecha de adquisición'
    );
    $payload['documento_fuente'] = ctTerrenosNormalizeOperacionDocumento((string) ($post['documento_fuente'] ?? ''));
    return $payload;
}

function ctSolicitudesCanEditSolicitud(array $solicitud, int $idUsuario): bool
{
    $estado = strtoupper(trim((string) ($solicitud['estado_codigo'] ?? '')));
    if (in_array($estado, ['APROBADA', 'ANULADA'], true)) {
        return false;
    }
    return (int) ($solicitud['id_solicitante'] ?? 0) === $idUsuario;
}

function ctSolicitudesEnsureEditableBySolicitante(array $solicitud, int $idUsuario): void
{
    if (!ctSolicitudesCanEditSolicitud($solicitud, $idUsuario)) {
        throw new RuntimeException('La solicitud no está disponible para edición por el solicitante.');
    }
}

function ctSolicitudesEnsureReadable(array $solicitud, int $idUsuario, array $participantes): void
{
    if ((int) ($solicitud['id_solicitante'] ?? 0) === $idUsuario) {
        return;
    }
    foreach ($participantes as $participante) {
        if ((int) ($participante['id_usuario_corporativo'] ?? 0) === $idUsuario) {
            return;
        }
    }
}

function ctSolicitudesEnsureAreaEditable(PDO $conn, array $solicitud, int $idSolicitud, int $idAreaSolicitud, int $idUsuario): array
{
    $estado = strtoupper(trim((string) ($solicitud['estado_codigo'] ?? '')));
    if (in_array($estado, ['APROBADA', 'ANULADA'], true)) {
        throw new RuntimeException('La solicitud está cerrada y ya no admite cambios.');
    }

    $assignment = ctSolicitudesRepoFindAreaAssignmentForUser($conn, $idSolicitud, $idAreaSolicitud, $idUsuario);
    if (!is_array($assignment)) {
        throw new RuntimeException('No tienes permiso para editar esta área.');
    }

    $respuestaArea = ctSolicitudesRepoFindAreaRespuesta($conn, $idSolicitud, $idAreaSolicitud);
    if (!is_array($respuestaArea)) {
        throw new RuntimeException('No existe instancia de área para la solicitud.');
    }
    $estadoArea = strtoupper(trim((string) ($respuestaArea['estado'] ?? '')));
    if (in_array($estadoArea, ['COMPLETA', 'CERRADA'], true)) {
        throw new RuntimeException('El área está cerrada por revisión. Agrega un comentario para reabrir edición.');
    }
    return $assignment;
}

function ctSolicitudesEnsureAreaCodigoEnSolicitud(PDO $conn, int $idSolicitud, int $idAreaSolicitud, string $expectedAreaCode): void
{
    $expected = strtoupper(trim($expectedAreaCode));
    if ($idAreaSolicitud <= 0 || $expected === '') {
        throw new RuntimeException('Área inválida para editar datos de adquisición.');
    }
    $respuesta = ctSolicitudesRepoFindAreaRespuesta($conn, $idSolicitud, $idAreaSolicitud);
    if (!is_array($respuesta)) {
        throw new RuntimeException('El área indicada no existe en la solicitud.');
    }
    $actual = strtoupper(trim((string) ($respuesta['area_codigo'] ?? '')));
    if ($actual !== $expected) {
        throw new RuntimeException('El bloque no corresponde al área esperada para esta operación.');
    }
}

function ctSolicitudesEnsureCanEditAdquisicionSupportByArea(
    PDO $conn,
    array $solicitud,
    int $idSolicitud,
    int $idUsuario,
    int $idAreaSolicitud,
    string $expectedAreaCode
): void {
    ctSolicitudesEnsureAreaEditable($conn, $solicitud, $idSolicitud, $idAreaSolicitud, $idUsuario);
    ctSolicitudesEnsureAreaCodigoEnSolicitud($conn, $idSolicitud, $idAreaSolicitud, $expectedAreaCode);
}

function ctSolicitudesBuildDraftPayloadFromExisting(?array $draft): array
{
    $existing = is_array($draft) ? $draft : [];
    $rolAsignado = strtoupper(ctNormalizeText((string) ($existing['rol_asignado'] ?? '')));
    $rolMatriz = strtoupper(ctNormalizeText((string) ($existing['rol_matriz'] ?? '')));
    $identificacion = ctNormalizeText((string) ($existing['identificacion_propiedad'] ?? ''));
    $documento = ctNormalizeText((string) ($existing['documento_fuente'] ?? ''));
    $fecha = trim((string) ($existing['fecha_adquisicion'] ?? ''));
    $idComuna = isset($existing['id_comuna']) && is_numeric((string) $existing['id_comuna']) ? (int) $existing['id_comuna'] : 0;
    $idTipoInmueble = isset($existing['id_tipo_inmueble']) && is_numeric((string) $existing['id_tipo_inmueble']) ? (int) $existing['id_tipo_inmueble'] : 0;
    $superficie = isset($existing['superficie_m2']) && is_numeric((string) $existing['superficie_m2']) ? (float) $existing['superficie_m2'] : null;

    return [
        'rol_asignado' => $rolAsignado !== '' ? $rolAsignado : null,
        'rol_matriz' => $rolMatriz !== '' ? $rolMatriz : null,
        'identificacion_propiedad' => $identificacion !== '' ? $identificacion : null,
        'superficie_m2' => $superficie !== null && $superficie > 0 ? round($superficie, 2) : null,
        'id_comuna' => $idComuna > 0 ? $idComuna : null,
        'id_tipo_inmueble' => $idTipoInmueble > 0 ? $idTipoInmueble : null,
        'fecha_adquisicion' => $fecha !== '' ? $fecha : null,
        'documento_fuente' => $documento !== '' ? $documento : null,
    ];
}

function ctSolicitudesDraftHasAnyData(?array $draft): bool
{
    if (!is_array($draft)) {
        return false;
    }
    foreach (['rol_asignado', 'rol_matriz', 'identificacion_propiedad', 'fecha_adquisicion', 'documento_fuente'] as $field) {
        if (trim((string) ($draft[$field] ?? '')) !== '') {
            return true;
        }
    }
    if ((float) ($draft['superficie_m2'] ?? 0) > 0) {
        return true;
    }
    if ((int) ($draft['id_comuna'] ?? 0) > 0 || (int) ($draft['id_tipo_inmueble'] ?? 0) > 0) {
        return true;
    }
    return false;
}

function ctSolicitudesDraftMissingRequiredFields(?array $draft): array
{
    $missing = [];
    if (!is_array($draft)) {
        return [
            'Rol asignado',
            'Superficie (m2)',
            'Comuna',
            'Tipo inmueble',
            'Fecha de adquisición',
        ];
    }

    if (trim((string) ($draft['rol_asignado'] ?? '')) === '') {
        $missing[] = 'Rol asignado';
    }
    if ((float) ($draft['superficie_m2'] ?? 0) <= 0) {
        $missing[] = 'Superficie (m2)';
    }
    if ((int) ($draft['id_comuna'] ?? 0) <= 0) {
        $missing[] = 'Comuna';
    }
    if ((int) ($draft['id_tipo_inmueble'] ?? 0) <= 0) {
        $missing[] = 'Tipo inmueble';
    }
    if (trim((string) ($draft['fecha_adquisicion'] ?? '')) === '') {
        $missing[] = 'Fecha de adquisición';
    }

    return $missing;
}

function ctSolicitudesBuildSnapshot(PDO $conn, int $idSolicitud): array
{
    $draft = ctSolicitudesRepoFindDraftBySolicitudId($conn, $idSolicitud);
    $titulares = ctSolicitudesRepoListTitularesBySolicitudId($conn, $idSolicitud);
    $participantes = ctSolicitudesRepoListParticipantesBySolicitudId($conn, $idSolicitud);
    $respuestas = ctSolicitudesRepoListAreaRespuestasBySolicitudId($conn, $idSolicitud);

    $areaIds = [];
    foreach ($participantes as $participante) {
        $areaIds[(int) ($participante['id_area_solicitud'] ?? 0)] = true;
    }
    unset($areaIds[0]);
    $selectedAreaIds = array_map('intval', array_keys($areaIds));

    $respuestasByArea = [];
    foreach ($respuestas as $respuesta) {
        $respuestasByArea[(int) ($respuesta['id_area_solicitud'] ?? 0)] = $respuesta;
    }

    $areasCompletas = 0;
    foreach ($selectedAreaIds as $idArea) {
        $estado = strtoupper(trim((string) ($respuestasByArea[$idArea]['estado'] ?? '')));
        if ($estado === 'COMPLETA') {
            $areasCompletas++;
        }
    }

    $titularesTotal = 0.0;
    foreach ($titulares as $titular) {
        $titularesTotal += (float) ($titular['porcentaje_derecho'] ?? 0);
    }

    $draftMissingFields = ctSolicitudesDraftMissingRequiredFields($draft);
    $draftCompleto = $draftMissingFields === [];

    $titularesValidos = $titulares !== [] && round($titularesTotal, 2) === 100.00;
    $areasCompletasTodas = $selectedAreaIds !== [] && $areasCompletas === count($selectedAreaIds);
    $hasParticipantsOrDraft = $participantes !== [] || ctSolicitudesDraftHasAnyData($draft);

    return [
        'draft' => $draft,
        'titulares' => $titulares,
        'participantes' => $participantes,
        'respuestas' => $respuestas,
        'selected_area_ids' => $selectedAreaIds,
        'areas_completas_count' => $areasCompletas,
        'draft_completo' => $draftCompleto,
        'draft_missing_fields' => $draftMissingFields,
        'titulares_validos' => $titularesValidos,
        'has_participants_or_draft' => $hasParticipantsOrDraft,
        'ready_to_approve' => $draftCompleto && $titularesValidos && $areasCompletasTodas,
    ];
}

function ctSolicitudesResolveEstadoObjetivo(PDO $conn, array $solicitud, array $snapshot, bool $forzarObservaciones = false): int
{
    $estadoActual = strtoupper(trim((string) ($solicitud['estado_codigo'] ?? '')));
    if (in_array($estadoActual, ['APROBADA', 'ANULADA'], true)) {
        return (int) ($solicitud['id_estado_solicitud'] ?? 0);
    }

    if ($snapshot['ready_to_approve']) {
        return ctSolicitudesRepoFindEstadoIdByCode($conn, 'LISTA_PARA_APROBAR');
    }
    if ($forzarObservaciones) {
        return ctSolicitudesRepoFindEstadoIdByCode($conn, 'CON_OBSERVACIONES');
    }
    if ($snapshot['has_participants_or_draft']) {
        return ctSolicitudesRepoFindEstadoIdByCode($conn, 'EN_REVISION');
    }
    return ctSolicitudesRepoFindEstadoIdByCode($conn, 'BORRADOR');
}

function ctSolicitudesRefreshEstado(PDO $conn, array $solicitud, bool $forzarObservaciones = false): void
{
    $snapshot = ctSolicitudesBuildSnapshot($conn, (int) $solicitud['id_solicitud']);
    $target = ctSolicitudesResolveEstadoObjetivo($conn, $solicitud, $snapshot, $forzarObservaciones);
    if ($target > 0 && $target !== (int) ($solicitud['id_estado_solicitud'] ?? 0)) {
        ctSolicitudesRepoUpdateEstado($conn, (int) $solicitud['id_solicitud'], $target);
    } else {
        ctSolicitudesRepoTouchSolicitud($conn, (int) $solicitud['id_solicitud']);
    }
}

function ctSolicitudesQueueNotificacionesObservacionGerencia(
    PDO $conn,
    array $solicitud,
    int $idSolicitud,
    int $idAreaSolicitud,
    int $idUsuarioAccion,
    string $comentario
): void {
    if ($idSolicitud <= 0 || $idAreaSolicitud <= 0) {
        return;
    }

    $area = ctSolicitudesRepoFindAreaById($conn, $idAreaSolicitud);
    $areaNombre = trim((string) ($area['nombre'] ?? '')) !== '' ? (string) ($area['nombre'] ?? '') : ('Área #' . $idAreaSolicitud);
    $participantes = ctSolicitudesRepoListParticipantesBySolicitudId($conn, $idSolicitud);
    $destinatariosIds = [];
    foreach ($participantes as $participante) {
        if ((int) ($participante['id_area_solicitud'] ?? 0) !== $idAreaSolicitud) {
            continue;
        }
        $idUsuario = (int) ($participante['id_usuario_corporativo'] ?? 0);
        if ($idUsuario <= 0 || $idUsuario === $idUsuarioAccion) {
            continue;
        }
        $destinatariosIds[$idUsuario] = true;
    }
    if ($destinatariosIds === []) {
        return;
    }

    $ids = array_map('intval', array_keys($destinatariosIds));
    $usuariosMap = ctTerrenosRepoResolveUsuariosDisplayMap($conn, $ids);
    $idSolicitudLabel = (int) ($solicitud['id_solicitud'] ?? $idSolicitud);
    $tipoNombre = trim((string) ($solicitud['tipo_nombre'] ?? '')) !== '' ? (string) ($solicitud['tipo_nombre'] ?? '') : 'Solicitud';

    $payloadJson = json_encode([
        'id_solicitud' => $idSolicitudLabel,
        'id_area_solicitud' => $idAreaSolicitud,
        'area' => $areaNombre,
        'tipo' => $tipoNombre,
        'comentario' => $comentario,
        'accion' => 'OBSERVACION_GERENCIA',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    foreach ($ids as $idUsuarioDest) {
        $destinatario = $usuariosMap[$idUsuarioDest] ?? ('Usuario #' . $idUsuarioDest);
        $asunto = 'Solicitud #' . $idSolicitudLabel . ' | Observación de Gerencia en ' . $areaNombre;
        ctSolicitudesRepoQueueNotificacion(
            $conn,
            $idSolicitud,
            $idAreaSolicitud,
            'OBSERVACION_GERENCIA_AREA',
            $idUsuarioDest,
            $destinatario,
            $asunto,
            $payloadJson === false ? null : $payloadJson
        );
    }
}

function ctSolicitudesLoadSolicitudOrFail(PDO $conn, int $idSolicitud): array
{
    $solicitud = ctSolicitudesRepoFindById($conn, $idSolicitud);
    if (!is_array($solicitud)) {
        throw new RuntimeException('La solicitud indicada no existe.');
    }
    return $solicitud;
}

function ctSolicitudesHandlePost(PDO $conn, array $post, array $state): never
{
    $accion = trim((string) ($post['accion'] ?? ''));
    $queryBase = $state['queryBase'];
    $idUsuario = ctSolicitudesCurrentUserId();
    $isHtmx = ctSolicitudesIsHtmxRequest();

    try {
        if ($accion === 'crear_solicitud_adquisicion' || $accion === 'crear_solicitud') {
            ctSolicitudesEnsureCanCreateSolicitud($conn, $idUsuario);
            $idTipo = 0;
            if ($accion === 'crear_solicitud') {
                $idTipoPost = isset($post['id_tipo_solicitud']) && is_numeric((string) $post['id_tipo_solicitud'])
                    ? (int) $post['id_tipo_solicitud']
                    : 0;
                $tipoSolicitud = ctSolicitudesRepoFindTipoById($conn, $idTipoPost);
                if (!is_array($tipoSolicitud) || empty($tipoSolicitud['activo'])) {
                    throw new RuntimeException('Debes seleccionar un tipo de solicitud válido.');
                }
                $idTipo = (int) ($tipoSolicitud['id_tipo_solicitud'] ?? 0);
            } else {
                $idTipo = ctSolicitudesRepoFindTipoIdByCode($conn, 'ADQUISICION');
            }

            $idEstado = ctSolicitudesRepoFindEstadoIdByCode($conn, 'BORRADOR');
            if ($idTipo <= 0 || $idEstado <= 0) {
                throw new RuntimeException('No fue posible resolver los catálogos base de solicitudes.');
            }
            $areasCatalogCreate = ctSolicitudesRepoListAreasForCreate($conn);
            $participantesCatalog = ctSolicitudesRepoListParticipantesCatalog($conn);
            $participantesByAreaCreate = ctSolicitudesRepoListParticipantesCatalogByAreaForCreate($conn, $areasCatalogCreate);
            $tipoAreaConfig = ctSolicitudesRepoListTipoAreaConfig($conn);
            $tipoAreaParticipanteDefaultsRows = ctSolicitudesRepoListTipoAreaParticipanteDefaults($conn);
            $tipoAreaParticipanteDefaults = ctSolicitudesBuildDefaultParticipantesByTipoArea(
                $tipoAreaParticipanteDefaultsRows,
                $participantesCatalog
            );
            $participantesPermitidosPorArea = ctSolicitudesBuildParticipantesPermitidosPorArea($participantesByAreaCreate);
            $defaultAreaIds = ctSolicitudesResolveDefaultAreaIdsForTipo($tipoAreaConfig, $idTipo);
            $post = ctSolicitudesApplyCreateParticipantesDefaults(
                $post,
                $idTipo,
                $defaultAreaIds,
                $tipoAreaParticipanteDefaults
            );
            $resumen = ctSolicitudesNormalizeSummary((string) ($post['resumen'] ?? ''));
            $participantesIniciales = ctSolicitudesParseParticipantesFromPost(
                $post,
                $areasCatalogCreate,
                $participantesCatalog,
                $participantesPermitidosPorArea
            );
            $conn->beginTransaction();
            try {
                $idSolicitud = ctSolicitudesRepoCreate($conn, $idTipo, $idEstado, $idUsuario, $resumen);
                ctSolicitudesRepoEnsureAreaInstancesForSolicitud($conn, $idSolicitud);
                ctSolicitudesRepoReplaceParticipantes($conn, $idSolicitud, $participantesIniciales);
                $solicitudCreada = ctSolicitudesLoadSolicitudOrFail($conn, $idSolicitud);
                ctSolicitudesRefreshEstado($conn, $solicitudCreada);
                $conn->commit();
            } catch (Throwable $exception) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $exception;
            }
            ctSetFlash('success', 'Solicitud de adquisición creada correctamente.');
            try {
                ctSolicitudesNotifyParticipantesByDepto($conn, $solicitudCreada, $participantesIniciales, $idUsuario);
            } catch (Throwable $ignore) {
            }
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }

        $idSolicitud = isset($post['id_solicitud']) && is_numeric((string) $post['id_solicitud']) ? (int) $post['id_solicitud'] : 0;
        $solicitud = ctSolicitudesLoadSolicitudOrFail($conn, $idSolicitud);

        if ($accion === 'guardar_draft_adquisicion') {
            ctSolicitudesEnsureEditableBySolicitante($solicitud, $idUsuario);
            $payload = ctSolicitudesNormalizeDraftPayload($post);
            ctTerrenosValidateAdquisicionPayload($conn, $payload);
            ctSolicitudesRepoUpsertDraft($conn, $idSolicitud, $payload);
            ctSolicitudesRepoUpdateResumen($conn, $idSolicitud, ctSolicitudesNormalizeSummary((string) ($post['resumen'] ?? '')));
            ctSolicitudesRefreshEstado($conn, $solicitud);
            ctSetFlash('success', 'Draft de adquisición guardado.');
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }

        if ($accion === 'guardar_draft_adquisicion_area') {
            if (!ctSolicitudesTipoSoportaAprobacionMaterializacion($solicitud)) {
                throw new RuntimeException('Este tipo de solicitud no utiliza draft de adquisición por área.');
            }
            $idArea = isset($post['id_area_solicitud']) && is_numeric((string) $post['id_area_solicitud']) ? (int) $post['id_area_solicitud'] : 0;
            if ($idArea <= 0) {
                throw new RuntimeException('Debes indicar el área del bloque para guardar datos de adquisición.');
            }
            $respuestaArea = ctSolicitudesRepoFindAreaRespuesta($conn, $idSolicitud, $idArea);
            if (!is_array($respuestaArea)) {
                throw new RuntimeException('No existe instancia de área para la solicitud.');
            }
            $areaCodigo = strtoupper(trim((string) ($respuestaArea['area_codigo'] ?? '')));
            if (!in_array($areaCodigo, ['LEGAL', 'ARQUITECTURA'], true)) {
                throw new RuntimeException('Este bloque de área no administra datos de adquisición.');
            }
            ctSolicitudesEnsureCanEditAdquisicionSupportByArea($conn, $solicitud, $idSolicitud, $idUsuario, $idArea, $areaCodigo);

            $payload = ctSolicitudesBuildDraftPayloadFromExisting(ctSolicitudesRepoFindDraftBySolicitudId($conn, $idSolicitud));
            if ($areaCodigo === 'LEGAL') {
                $payload['rol_asignado'] = ctSolicitudesNormalizeOptionalField((string) ($post['rol_asignado'] ?? ''), 30);
                $payload['rol_asignado'] = $payload['rol_asignado'] !== null ? strtoupper($payload['rol_asignado']) : null;
                $payload['rol_matriz'] = ctSolicitudesNormalizeOptionalField((string) ($post['rol_matriz'] ?? ''), 30);
                $payload['rol_matriz'] = $payload['rol_matriz'] !== null ? strtoupper($payload['rol_matriz']) : null;
                $payload['fecha_adquisicion'] = ctTerrenosNormalizeOptionalDate((string) ($post['fecha_adquisicion'] ?? ''), 'la fecha de adquisición');
                $payload['documento_fuente'] = ctTerrenosNormalizeOperacionDocumento((string) ($post['documento_fuente'] ?? ''));
                ctSolicitudesRepoUpdateResumen($conn, $idSolicitud, ctSolicitudesNormalizeSummary((string) ($post['resumen'] ?? '')));
            } else {
                $payload['identificacion_propiedad'] = ctSolicitudesNormalizeOptionalField((string) ($post['identificacion_propiedad'] ?? ''), 120);
                $superficieRaw = trim((string) ($post['superficie_m2'] ?? ''));
                $payload['superficie_m2'] = $superficieRaw === '' ? null : ctTerrenosNormalizeSuperficie($superficieRaw);
                $idComuna = isset($post['id_comuna']) && is_numeric((string) $post['id_comuna']) ? (int) $post['id_comuna'] : 0;
                $idTipoInmueble = isset($post['id_tipo_inmueble']) && is_numeric((string) $post['id_tipo_inmueble']) ? (int) $post['id_tipo_inmueble'] : 0;
                $payload['id_comuna'] = $idComuna > 0 ? $idComuna : null;
                $payload['id_tipo_inmueble'] = $idTipoInmueble > 0 ? $idTipoInmueble : null;
            }

            ctSolicitudesRepoUpsertDraft($conn, $idSolicitud, $payload);
            ctSolicitudesRefreshEstado($conn, $solicitud);
            ctSetFlash('success', 'Datos de adquisición del bloque guardados.');
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }

        if ($accion === 'guardar_titulares_draft') {
            if (!ctSolicitudesTipoSoportaAprobacionMaterializacion($solicitud)) {
                throw new RuntimeException('Este tipo de solicitud no utiliza titulares de adquisición.');
            }
            $idAreaTitulares = isset($post['id_area_solicitud']) && is_numeric((string) $post['id_area_solicitud']) ? (int) $post['id_area_solicitud'] : 0;
            ctSolicitudesEnsureCanEditAdquisicionSupportByArea($conn, $solicitud, $idSolicitud, $idUsuario, $idAreaTitulares, 'LEGAL');
            $fechaBase = trim((string) ($post['fecha_adquisicion'] ?? ''));
            if ($fechaBase === '') {
                $draft = ctSolicitudesRepoFindDraftBySolicitudId($conn, $idSolicitud);
                $fechaBase = trim((string) ($draft['fecha_adquisicion'] ?? ''));
            }
            if ($fechaBase === '') {
                throw new RuntimeException('Debes guardar primero la fecha de adquisición del draft.');
            }
            $titulares = ctSolicitudesParseTitularesFromPost($post, $fechaBase);
            ctTerrenosValidateTitularesPayload($conn, $titulares);
            ctSolicitudesRepoReplaceTitulares($conn, $idSolicitud, $titulares);
            ctSolicitudesRefreshEstado($conn, $solicitud);
            ctSetFlash('success', 'Titulares del draft guardados.');
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }

        if ($accion === 'crear_tercero_desde_ficha') {
            if (!ctSolicitudesTipoSoportaAprobacionMaterializacion($solicitud)) {
                throw new RuntimeException('Este tipo de solicitud no permite registrar terceros desde esta ficha.');
            }
            $idAreaTercero = isset($post['id_area_solicitud']) && is_numeric((string) $post['id_area_solicitud']) ? (int) $post['id_area_solicitud'] : 0;
            if ($idAreaTercero <= 0) {
                throw new RuntimeException('Debes indicar el área para registrar el tercero.');
            }
            ctSolicitudesEnsureCanEditAdquisicionSupportByArea($conn, $solicitud, $idSolicitud, $idUsuario, $idAreaTercero, 'LEGAL');

            $payloadTercero = ctSolicitudesNormalizeNuevoTerceroPayload($post);
            if ($payloadTercero['tipo'] === 'J' && ctSolicitudesRepoExistsRazonSocialJuridica($conn, $payloadTercero['nombre'])) {
                throw new RuntimeException('Ya existe una persona jurídica con esa razón social.');
            }

            $idTerceroNuevo = ctSolicitudesRepoInsertTercero(
                $conn,
                $payloadTercero['tipo'],
                $payloadTercero['rut'],
                $payloadTercero['nombre']
            );
            ctSetFlash('success', 'Tercero creado correctamente (#' . $idTerceroNuevo . ').');
            ctSolicitudesRedirect(
                array_merge($queryBase, ['id' => $idSolicitud]),
                'ct-sol-area-panel-' . $idAreaTercero
            );
        }

        if ($accion === 'guardar_participantes') {
            ctSolicitudesEnsureEditableBySolicitante($solicitud, $idUsuario);
            $rows = ctSolicitudesParseParticipantesFromPost(
                $post,
                ctSolicitudesRepoListAreas($conn),
                ctSolicitudesRepoListParticipantesCatalog($conn)
            );
            $conn->beginTransaction();
            try {
                ctSolicitudesRepoReplaceParticipantes($conn, $idSolicitud, $rows);
                ctSolicitudesRefreshEstado($conn, $solicitud);
                $conn->commit();
            } catch (Throwable $exception) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $exception;
            }
            ctSetFlash('success', 'Participantes guardados.');
            try {
                $solicitudActualizada = ctSolicitudesLoadSolicitudOrFail($conn, $idSolicitud);
                ctSolicitudesNotifyParticipantesByDepto($conn, $solicitudActualizada, $rows, $idUsuario);
            } catch (Throwable $ignore) {
            }
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }

        if ($accion === 'guardar_respuesta_area') {
            $idArea = isset($post['id_area_solicitud']) && is_numeric((string) $post['id_area_solicitud']) ? (int) $post['id_area_solicitud'] : 0;
            $assignment = ctSolicitudesEnsureAreaEditable($conn, $solicitud, $idSolicitud, $idArea, $idUsuario);
            $respuestaActual = ctSolicitudesRepoFindAreaRespuesta($conn, $idSolicitud, $idArea);
            if (!is_array($respuestaActual)) {
                throw new RuntimeException('No existe instancia de área para la solicitud.');
            }
            $areaCodigo = strtoupper(trim((string) ($respuestaActual['area_codigo'] ?? '')));
            $payloadArea = ctSolicitudesNormalizeAreaFormPayload($post, $areaCodigo);
            $idParticipante = isset($assignment['id_participante_solicitud']) ? (int) $assignment['id_participante_solicitud'] : null;
            ctSolicitudesRepoUpsertAreaRespuesta($conn, $idSolicitud, $idArea, $idParticipante, $payloadArea);

            $supportSaved = false;
            if (ctSolicitudesTipoSoportaAprobacionMaterializacion($solicitud) && in_array($areaCodigo, ['LEGAL', 'ARQUITECTURA'], true)) {
                $supportFields = $areaCodigo === 'LEGAL'
                    ? ['resumen', 'rol_asignado', 'rol_matriz', 'fecha_adquisicion', 'documento_fuente']
                    : ['identificacion_propiedad', 'superficie_m2', 'id_comuna', 'id_tipo_inmueble'];
                $hasSupportPayload = false;
                foreach ($supportFields as $supportFieldName) {
                    if (array_key_exists($supportFieldName, $post)) {
                        $hasSupportPayload = true;
                        break;
                    }
                }
                if ($hasSupportPayload) {
                    ctSolicitudesEnsureCanEditAdquisicionSupportByArea($conn, $solicitud, $idSolicitud, $idUsuario, $idArea, $areaCodigo);
                    $payloadDraft = ctSolicitudesBuildDraftPayloadFromExisting(ctSolicitudesRepoFindDraftBySolicitudId($conn, $idSolicitud));
                    if ($areaCodigo === 'LEGAL') {
                        $payloadDraft['rol_asignado'] = ctSolicitudesNormalizeOptionalField((string) ($post['rol_asignado'] ?? ''), 30);
                        $payloadDraft['rol_asignado'] = $payloadDraft['rol_asignado'] !== null ? strtoupper($payloadDraft['rol_asignado']) : null;
                        $payloadDraft['rol_matriz'] = ctSolicitudesNormalizeOptionalField((string) ($post['rol_matriz'] ?? ''), 30);
                        $payloadDraft['rol_matriz'] = $payloadDraft['rol_matriz'] !== null ? strtoupper($payloadDraft['rol_matriz']) : null;
                        $payloadDraft['fecha_adquisicion'] = ctTerrenosNormalizeOptionalDate((string) ($post['fecha_adquisicion'] ?? ''), 'la fecha de adquisición');
                        $payloadDraft['documento_fuente'] = ctTerrenosNormalizeOperacionDocumento((string) ($post['documento_fuente'] ?? ''));
                        ctSolicitudesRepoUpdateResumen($conn, $idSolicitud, ctSolicitudesNormalizeSummary((string) ($post['resumen'] ?? '')));
                    } else {
                        $payloadDraft['identificacion_propiedad'] = ctSolicitudesNormalizeOptionalField((string) ($post['identificacion_propiedad'] ?? ''), 120);
                        $superficieRaw = trim((string) ($post['superficie_m2'] ?? ''));
                        $payloadDraft['superficie_m2'] = $superficieRaw === '' ? null : ctTerrenosNormalizeSuperficie($superficieRaw);
                        $idComuna = isset($post['id_comuna']) && is_numeric((string) $post['id_comuna']) ? (int) $post['id_comuna'] : 0;
                        $idTipoInmueble = isset($post['id_tipo_inmueble']) && is_numeric((string) $post['id_tipo_inmueble']) ? (int) $post['id_tipo_inmueble'] : 0;
                        $payloadDraft['id_comuna'] = $idComuna > 0 ? $idComuna : null;
                        $payloadDraft['id_tipo_inmueble'] = $idTipoInmueble > 0 ? $idTipoInmueble : null;
                    }
                    ctSolicitudesRepoUpsertDraft($conn, $idSolicitud, $payloadDraft);
                    $supportSaved = true;
                }
            }

            ctSolicitudesRepoUpdateAreaParticipantesEstado($conn, $idSolicitud, $idArea, 'EN_CURSO');
            ctSolicitudesRefreshEstado($conn, $solicitud);
            ctSetFlash('success', $supportSaved ? 'Bloque guardado.' : 'Respuesta de área guardada.');
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }

        if ($accion === 'marcar_area_completa') {
            $idArea = isset($post['id_area_solicitud']) && is_numeric((string) $post['id_area_solicitud']) ? (int) $post['id_area_solicitud'] : 0;
            $assignment = ctSolicitudesEnsureAreaEditable($conn, $solicitud, $idSolicitud, $idArea, $idUsuario);
            $existing = ctSolicitudesRepoFindAreaRespuesta($conn, $idSolicitud, $idArea);
            if (!is_array($existing)) {
                throw new RuntimeException('Debes guardar primero la respuesta del área.');
            }
            $areaCodigo = strtoupper(trim((string) ($existing['area_codigo'] ?? '')));
            $idParticipante = isset($assignment['id_participante_solicitud']) ? (int) $assignment['id_participante_solicitud'] : null;
            ctSolicitudesRepoMarkAreaCompleta($conn, $idSolicitud, $idArea, $idParticipante);
            ctSolicitudesRepoUpdateAreaParticipantesEstado($conn, $idSolicitud, $idArea, 'COMPLETADA');
            ctSolicitudesRefreshEstado($conn, $solicitud);
            ctSetFlash('success', 'Área marcada como completa.');
            try {
                $solicitudActualizada = ctSolicitudesLoadSolicitudOrFail($conn, $idSolicitud);
                ctSolicitudesNotifyAreaCompleta($conn, $solicitudActualizada, $idArea, $idUsuario);
            } catch (Throwable $ignore) {
            }
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }

        if ($accion === 'agregar_comentario') {
            $idArea = isset($post['id_area_solicitud']) && is_numeric((string) $post['id_area_solicitud']) ? (int) $post['id_area_solicitud'] : 0;
            $comentario = ctSolicitudesNormalizeComentario((string) ($post['comentario'] ?? ''));
            $returnFragment = ctSolicitudesNormalizeFragment((string) ($post['return_fragment'] ?? ''));
            ctSolicitudesEnsureCanCommentSolicitud($conn, $solicitud, $idUsuario, $idArea > 0 ? $idArea : null);
            $forzarObservaciones = false;
            if ($idArea > 0) {
                $areaExists = ctSolicitudesRepoFindAreaRespuesta($conn, $idSolicitud, $idArea);
                if (!is_array($areaExists)) {
                    throw new RuntimeException('El área indicada no existe en la solicitud.');
                }
                ctSolicitudesRepoMarcarAreaConObservaciones($conn, $idSolicitud, $idArea, $comentario);
                $forzarObservaciones = true;
            }
            ctSolicitudesRepoAddComentario($conn, $idSolicitud, $idUsuario, $idArea > 0 ? $idArea : null, $comentario);
            try {
                $solicitudActualizada = ctSolicitudesLoadSolicitudOrFail($conn, $idSolicitud);
                ctSolicitudesNotifyComentario($conn, $solicitudActualizada, $idArea, $idUsuario, $comentario);
            } catch (Throwable $ignore) {
            }
            ctSolicitudesRefreshEstado($conn, $solicitud, $forzarObservaciones);
            if ($isHtmx) {
                ctSolicitudesRenderHtmxCommentsFragment($conn, $idSolicitud, $state, $idUsuario, $idArea);
            }
            if ($idArea > 0) {
                ctSetFlash('success', 'Observación registrada y área reabierta en estado CON_OBSERVACIONES.');
            } else {
                ctSetFlash('success', 'Comentario registrado.');
            }
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]), $returnFragment);
        }

        if ($accion === 'resolver_comentario') {
            $idComentario = isset($post['id_solicitud_comentario']) && is_numeric((string) $post['id_solicitud_comentario'])
                ? (int) $post['id_solicitud_comentario']
                : 0;
            $idArea = isset($post['id_area_solicitud']) && is_numeric((string) $post['id_area_solicitud'])
                ? (int) $post['id_area_solicitud']
                : 0;
            $returnFragment = ctSolicitudesNormalizeFragment((string) ($post['return_fragment'] ?? ''));
            if ($idComentario <= 0) {
                throw new RuntimeException('Comentario inválido.');
            }
            $comentario = ctSolicitudesRepoFindComentarioById($conn, $idSolicitud, $idComentario);
            if (!is_array($comentario)) {
                throw new RuntimeException('El comentario indicado no existe en la solicitud.');
            }
            $idAreaComentario = isset($comentario['id_area_solicitud']) && is_numeric((string) $comentario['id_area_solicitud'])
                ? (int) $comentario['id_area_solicitud']
                : 0;
            ctSolicitudesEnsureCanCommentSolicitud($conn, $solicitud, $idUsuario, $idAreaComentario > 0 ? $idAreaComentario : null);
            $estadoActualComentario = strtoupper(trim((string) ($comentario['estado_revision'] ?? 'PENDIENTE')));
            if ($estadoActualComentario === 'RESUELTO') {
                if ($isHtmx) {
                    ctSolicitudesRenderHtmxCommentsFragment($conn, $idSolicitud, $state, $idUsuario, $idArea);
                }
                ctSetFlash('info', 'El comentario ya estaba marcado como resuelto.');
                ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]), $returnFragment);
            }
            ctSolicitudesRepoMarcarComentarioResuelto($conn, $idComentario, $idUsuario);
            if ($isHtmx) {
                ctSolicitudesRenderHtmxCommentsFragment($conn, $idSolicitud, $state, $idUsuario, $idArea);
            }
            ctSetFlash('success', 'Comentario marcado como resuelto.');
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]), $returnFragment);
        }

        if ($accion === 'registrar_adjunto') {
            if ((int) ($solicitud['id_solicitante'] ?? 0) === $idUsuario) {
                ctSolicitudesEnsureEditableBySolicitante($solicitud, $idUsuario);
            } else {
                $idAreaAdj = isset($post['id_area_solicitud']) && is_numeric((string) $post['id_area_solicitud']) ? (int) $post['id_area_solicitud'] : 0;
                if ($idAreaAdj <= 0) {
                    throw new RuntimeException('Los participantes deben registrar adjuntos dentro de un área asignada.');
                }
                ctSolicitudesEnsureAreaEditable($conn, $solicitud, $idSolicitud, $idAreaAdj, $idUsuario);
            }
            $idArea = isset($post['id_area_solicitud']) && is_numeric((string) $post['id_area_solicitud']) ? (int) $post['id_area_solicitud'] : 0;
            $nombre = ctSolicitudesNormalizeAdjuntoField((string) ($post['nombre'] ?? ''), 'el nombre del adjunto', 255);
            $tipo = ctSolicitudesNormalizeOptionalField((string) ($post['tipo'] ?? ''), 120);
            $referencia = ctSolicitudesNormalizeAdjuntoField((string) ($post['referencia'] ?? ''), 'la referencia del adjunto', 500);
            $nota = ctSolicitudesNormalizeOptionalField((string) ($post['nota'] ?? ''), 500);
            ctSolicitudesRepoAddAdjunto($conn, $idSolicitud, $idArea > 0 ? $idArea : null, $nombre, $tipo, $referencia, $nota);
            ctSolicitudesRefreshEstado($conn, $solicitud);
            ctSetFlash('success', 'Adjunto referencial registrado.');
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }

        if ($accion === 'anular_solicitud') {
            ctSolicitudesEnsureEditableBySolicitante($solicitud, $idUsuario);
            $idEstadoAnulada = ctSolicitudesRepoFindEstadoIdByCode($conn, 'ANULADA');
            if ($idEstadoAnulada <= 0) {
                throw new RuntimeException('No fue posible resolver el estado ANULADA.');
            }
            ctSolicitudesRepoUpdateEstado($conn, $idSolicitud, $idEstadoAnulada);
            ctSetFlash('success', 'Solicitud anulada.');
            try {
                $solicitudAnulada = ctSolicitudesLoadSolicitudOrFail($conn, $idSolicitud);
                ctSolicitudesNotifyDecisionFinal($conn, $solicitudAnulada, 'ANULADA', $idUsuario);
            } catch (Throwable $ignore) {
            }
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }

        if ($accion === 'aprobar_solicitud') {
            ctSolicitudesEnsureEditableBySolicitante($solicitud, $idUsuario);
            if (!ctSolicitudesTipoSoportaAprobacionMaterializacion($solicitud)) {
                $tipoNombre = trim((string) ($solicitud['tipo_nombre'] ?? 'Solicitud'));
                throw new RuntimeException('La aprobación con materialización aún no está habilitada para "' . $tipoNombre . '".');
            }
            $snapshot = ctSolicitudesBuildSnapshot($conn, $idSolicitud);
            if (!$snapshot['draft_completo']) {
                $faltantes = isset($snapshot['draft_missing_fields']) && is_array($snapshot['draft_missing_fields'])
                    ? array_values(array_filter(array_map(static fn($item): string => trim((string) $item), $snapshot['draft_missing_fields']), static fn(string $item): bool => $item !== ''))
                    : [];
                if ($faltantes !== []) {
                    throw new RuntimeException('El draft de adquisición está incompleto. Faltan: ' . implode(', ', $faltantes) . '.');
                }
                throw new RuntimeException('El draft de adquisición está incompleto.');
            }
            if ($snapshot['titulares'] === []) {
                throw new RuntimeException('Debes registrar titulares antes de aprobar.');
            }
            if (!$snapshot['titulares_validos']) {
                throw new RuntimeException('La suma de porcentajes de titulares debe ser exactamente 100.00.');
            }
            if ($snapshot['selected_area_ids'] === []) {
                throw new RuntimeException('Debes seleccionar áreas participantes antes de aprobar.');
            }
            if (!$snapshot['ready_to_approve']) {
                throw new RuntimeException('Todas las áreas seleccionadas deben estar marcadas como completas antes de aprobar.');
            }

            $draft = $snapshot['draft'];
            if (!is_array($draft)) {
                throw new RuntimeException('No existe draft de adquisición para aprobar.');
            }
            $rolAsignado = trim((string) ($draft['rol_asignado'] ?? ''));
            if ($rolAsignado === '') {
                throw new RuntimeException('El draft no tiene rol asignado.');
            }
            if (ctTerrenosRepoRolAsignadoExists($conn, $rolAsignado)) {
                throw new RuntimeException('Ya existe un terreno con el rol asignado "' . $rolAsignado . '".');
            }

            $idEstadoAprobada = ctSolicitudesRepoFindEstadoIdByCode($conn, 'APROBADA');
            if ($idEstadoAprobada <= 0) {
                throw new RuntimeException('No fue posible resolver el estado APROBADA.');
            }

            $payload = [
                'rol_asignado' => $rolAsignado,
                'rol_matriz' => trim((string) ($draft['rol_matriz'] ?? '')) !== '' ? (string) $draft['rol_matriz'] : null,
                'identificacion_propiedad' => trim((string) ($draft['identificacion_propiedad'] ?? '')) !== '' ? (string) $draft['identificacion_propiedad'] : null,
                'superficie_m2' => (float) ($draft['superficie_m2'] ?? 0),
                'id_comuna' => (int) ($draft['id_comuna'] ?? 0),
                'id_tipo_inmueble' => (int) ($draft['id_tipo_inmueble'] ?? 0),
                'fecha_adquisicion' => (string) $draft['fecha_adquisicion'],
                'documento_fuente' => trim((string) ($draft['documento_fuente'] ?? '')) !== '' ? (string) $draft['documento_fuente'] : null,
                'id_estado_predial' => ctTerrenosRepoEnsureEstadoPredialDisponible($conn),
                'id_estado_comercial' => ctTerrenosRepoEnsureEstadoComercialDefault($conn),
            ];

            $conn->beginTransaction();
            try {
                $result = ctTerrenosRepoMaterializarAdquisicion($conn, $payload, $snapshot['titulares'], $idUsuario);
                ctSolicitudesRepoUpdateGeneratedLinks(
                    $conn,
                    $idSolicitud,
                    (int) ($result['id_terreno'] ?? 0),
                    (int) ($result['id_operacion'] ?? 0)
                );
                ctSolicitudesRepoUpdateEstado($conn, $idSolicitud, $idEstadoAprobada);
                $conn->commit();
            } catch (Throwable $exception) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                throw $exception;
            }

            ctSetFlash('success', 'Solicitud aprobada y adquisición materializada correctamente.');
            try {
                $solicitudAprobada = ctSolicitudesLoadSolicitudOrFail($conn, $idSolicitud);
                ctSolicitudesNotifyDecisionFinal($conn, $solicitudAprobada, 'APROBADA', $idUsuario);
            } catch (Throwable $ignore) {
            }
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }

        ctSetFlash('warning', 'Acción no reconocida.');
        ctSolicitudesRedirect($queryBase);
    } catch (Throwable $exception) {
        $errorMessage = trim((string) $exception->getMessage()) !== '' ? trim((string) $exception->getMessage()) : 'No fue posible procesar la solicitud.';
        if (stripos($errorMessage, "No se puede insertar el valor NULL en la columna 'rut'") !== false) {
            $errorMessage = 'La base aún tiene ct_tercero.rut como NOT NULL. Ejecuta db/50_ct_integridad.sql (o db/core_ct.sql) para dejar RUT opcional.';
        }
        if (stripos($errorMessage, 'UX_ct_tercero_razon_social_juridica') !== false) {
            $errorMessage = 'Ya existe una persona jurídica con esa razón social.';
        }
        if (
            $isHtmx
            && in_array($accion, ['agregar_comentario', 'resolver_comentario'], true)
            && isset($idSolicitud)
            && $idSolicitud > 0
        ) {
            $idArea = isset($post['id_area_solicitud']) && is_numeric((string) $post['id_area_solicitud'])
                ? (int) $post['id_area_solicitud']
                : 0;
            ctSolicitudesRenderHtmxCommentsFragment($conn, $idSolicitud, $state, $idUsuario, $idArea, $errorMessage, 422);
        }
        ctSetFlash('danger', $errorMessage);
        if (isset($idSolicitud) && $idSolicitud > 0) {
            ctSolicitudesRedirect(array_merge($queryBase, ['id' => $idSolicitud]));
        }
        ctSolicitudesRedirect($queryBase);
    }
}

function ctSolicitudesFetchCatalogs(PDO $conn): array
{
    $areas = ctSolicitudesRepoListAreas($conn);
    $areasCreate = ctSolicitudesRepoListAreasForCreate($conn);
    $participantesCatalog = ctSolicitudesRepoListParticipantesCatalog($conn);
    $tipoAreaConfig = ctSolicitudesRepoListTipoAreaConfig($conn);
    $tipoAreaParticipanteDefaults = ctSolicitudesBuildDefaultParticipantesByTipoArea(
        ctSolicitudesRepoListTipoAreaParticipanteDefaults($conn),
        $participantesCatalog
    );

    return [
        'tipos' => ctSolicitudesRepoListTipos($conn),
        'estados' => ctSolicitudesRepoListEstados($conn),
        'areas' => $areas,
        'areasCreate' => $areasCreate,
        'tipoAreaConfig' => $tipoAreaConfig,
        'tipoAreaParticipanteDefaults' => $tipoAreaParticipanteDefaults,
        'participantesCatalog' => $participantesCatalog,
        'participantesByAreaCreate' => ctSolicitudesRepoListParticipantesCatalogByAreaForCreate($conn, $areasCreate),
        'comunas' => ctSolicitudesRepoListComunas($conn),
        'tiposInmueble' => ctSolicitudesRepoListTiposInmueble($conn),
        'terceros' => ctSolicitudesRepoListTerceros($conn),
        'solicitantesFiltro' => ctSolicitudesRepoListSolicitantesFilter($conn),
    ];
}

function ctSolicitudesFetchPage(PDO $conn, array $state): array
{
    $filters = [
        'filtro_texto' => $state['filtroTexto'],
        'id_estado_solicitud' => (int) $state['filtroEstado'],
        'id_tipo_solicitud' => (int) $state['filtroTipo'],
        'id_solicitante' => (int) $state['filtroSolicitante'],
    ];
    $lineas = (int) $state['lineas'];
    $pagina = (int) $state['pagina'];
    $offset = max(0, ($pagina - 1) * $lineas);

    $totalRegistros = ctSolicitudesRepoCount($conn, $filters);
    $totalPaginas = max(1, (int) ceil($totalRegistros / max(1, $lineas)));
    $pagina = min($pagina, $totalPaginas);
    $offset = max(0, ($pagina - 1) * $lineas);
    $rows = ctSolicitudesRepoList($conn, $filters, $offset, $lineas);

    $solicitanteIds = [];
    foreach ($rows as $row) {
        $idSolicitante = (int) ($row['id_solicitante'] ?? 0);
        if ($idSolicitante > 0) {
            $solicitanteIds[] = $idSolicitante;
        }
    }
    $solicitanteMap = ctTerrenosRepoResolveUsuariosDisplayMap($conn, $solicitanteIds);

    return [
        'rows' => $rows,
        'solicitanteMap' => $solicitanteMap,
        'totalRegistros' => $totalRegistros,
        'totalPaginas' => $totalPaginas,
        'paginaActual' => $pagina,
        'paginationItems' => ctSolicitudesBuildPaginationItems($pagina, $totalPaginas),
    ];
}

function ctSolicitudesFetchDetail(PDO $conn, int $idSolicitud): ?array
{
    if ($idSolicitud <= 0) {
        return null;
    }

    $solicitud = ctSolicitudesRepoFindById($conn, $idSolicitud);
    if (!is_array($solicitud)) {
        return null;
    }
    ctSolicitudesRepoEnsureAreaInstancesForSolicitud($conn, $idSolicitud);

    $participantes = ctSolicitudesRepoListParticipantesBySolicitudId($conn, $idSolicitud);
    $areas = ctSolicitudesRepoListAreas($conn);
    $areasMap = [];
    foreach ($areas as $area) {
        $areasMap[(int) ($area['id_area_solicitud'] ?? 0)] = $area;
    }

    $respuestas = ctSolicitudesRepoListAreaRespuestasBySolicitudId($conn, $idSolicitud);
    $respuestasMap = [];
    foreach ($respuestas as $respuesta) {
        $respuestasMap[(int) ($respuesta['id_area_solicitud'] ?? 0)] = $respuesta;
    }

    $participantesPorArea = [];
    foreach ($participantes as $participante) {
        $idArea = (int) ($participante['id_area_solicitud'] ?? 0);
        if ($idArea <= 0) {
            continue;
        }
        if (!isset($participantesPorArea[$idArea])) {
            $participantesPorArea[$idArea] = [];
        }
        $participantesPorArea[$idArea][] = $participante;
    }
    $areasSeleccionadas = [];
    foreach (array_keys($participantesPorArea) as $idAreaSeleccionada) {
        $idAreaSeleccionada = (int) $idAreaSeleccionada;
        if ($idAreaSeleccionada > 0) {
            $areasSeleccionadas[$idAreaSeleccionada] = true;
        }
    }

    $panelesArea = [];
    foreach ($respuestas as $respuestaArea) {
        $idArea = (int) ($respuestaArea['id_area_solicitud'] ?? 0);
        if ($idArea <= 0) {
            continue;
        }
        if ($areasSeleccionadas !== [] && !isset($areasSeleccionadas[$idArea])) {
            continue;
        }
        $areaBase = $areasMap[$idArea] ?? [
            'id_area_solicitud' => $idArea,
            'nombre' => (string) ($respuestaArea['area_nombre'] ?? ('Área #' . $idArea)),
            'codigo' => (string) ($respuestaArea['area_codigo'] ?? ('AREA_' . $idArea)),
            'orden_visual' => 9999,
        ];
        $panelesArea[$idArea] = [
            'area' => $areaBase,
            'participantes' => $participantesPorArea[$idArea] ?? [],
            'respuesta' => $respuestaArea,
        ];
    }
    foreach ($participantesPorArea as $idArea => $rowsArea) {
        if (isset($panelesArea[$idArea])) {
            continue;
        }
        $panelesArea[$idArea] = [
            'area' => $areasMap[$idArea] ?? ['id_area_solicitud' => $idArea, 'nombre' => 'Área #' . $idArea, 'codigo' => 'AREA_' . $idArea, 'orden_visual' => 9999],
            'participantes' => $rowsArea,
            'respuesta' => $respuestasMap[$idArea] ?? null,
        ];
    }
    $panelesArea = array_values($panelesArea);
    usort(
        $panelesArea,
        static function (array $a, array $b): int {
            $ordenA = (int) (($a['area']['orden_visual'] ?? 9999));
            $ordenB = (int) (($b['area']['orden_visual'] ?? 9999));
            if ($ordenA !== $ordenB) {
                return $ordenA <=> $ordenB;
            }
            return strcasecmp((string) (($a['area']['nombre'] ?? '')), (string) (($b['area']['nombre'] ?? '')));
        }
    );

    $comentarios = ctSolicitudesRepoListComentariosBySolicitudId($conn, $idSolicitud);
    $adjuntos = ctSolicitudesRepoListAdjuntosBySolicitudId($conn, $idSolicitud);
    $draft = ctSolicitudesRepoFindDraftBySolicitudId($conn, $idSolicitud);
    $titulares = ctSolicitudesRepoListTitularesBySolicitudId($conn, $idSolicitud);
    $snapshot = ctSolicitudesBuildSnapshot($conn, $idSolicitud);

    $userIds = [(int) ($solicitud['id_solicitante'] ?? 0)];
    foreach ($participantes as $participante) {
        $idUsuario = (int) ($participante['id_usuario_corporativo'] ?? 0);
        if ($idUsuario > 0) {
            $userIds[] = $idUsuario;
        }
    }
    foreach ($comentarios as $comentario) {
        $idUsuario = (int) ($comentario['id_usuario'] ?? 0);
        if ($idUsuario > 0) {
            $userIds[] = $idUsuario;
        }
        $idUsuarioResolucion = (int) ($comentario['id_usuario_resolucion'] ?? 0);
        if ($idUsuarioResolucion > 0) {
            $userIds[] = $idUsuarioResolucion;
        }
    }
    $usuariosMap = ctTerrenosRepoResolveUsuariosDisplayMap($conn, $userIds);
    $usuariosLogoMap = ctTerrenosRepoResolveUsuariosLogoMap($conn, $userIds);
    foreach ($participantes as &$participante) {
        $idUsuario = (int) ($participante['id_usuario_corporativo'] ?? 0);
        if ($idUsuario > 0 && isset($usuariosMap[$idUsuario])) {
            $participante['participante_nombre'] = $usuariosMap[$idUsuario];
        }
        $participante['participante_url_logo'] = $idUsuario > 0 ? (string) ($usuariosLogoMap[$idUsuario] ?? '') : '';
    }
    unset($participante);
    foreach ($panelesArea as &$panel) {
        foreach ($panel['participantes'] as &$participantePanel) {
            $idUsuario = (int) ($participantePanel['id_usuario_corporativo'] ?? 0);
            if ($idUsuario > 0 && isset($usuariosMap[$idUsuario])) {
                $participantePanel['participante_nombre'] = $usuariosMap[$idUsuario];
            }
            $participantePanel['participante_url_logo'] = $idUsuario > 0 ? (string) ($usuariosLogoMap[$idUsuario] ?? '') : '';
        }
        unset($participantePanel);
    }
    unset($panel);

    return [
        'solicitud' => $solicitud,
        'draft' => $draft,
        'titulares' => $titulares,
        'participantes' => $participantes,
        'panelesArea' => $panelesArea,
        'comentarios' => $comentarios,
        'adjuntos' => $adjuntos,
        'snapshot' => $snapshot,
        'usuariosMap' => $usuariosMap,
    ];
}
