<?php
declare(strict_types=1);

if (!function_exists('ctSolicitudesViewDatetime')) {
    function ctSolicitudesViewDatetime(?string $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '-';
        }
        $dt = new DateTimeImmutable($raw);
        return $dt->format('d-m-Y H:i');
    }
}

if (!function_exists('ctSolicitudesViewBadgeClass')) {
    function ctSolicitudesViewBadgeClass(string $codigo): string
    {
        return match (strtoupper(trim($codigo))) {
            'BORRADOR' => 'bg-secondary-subtle text-secondary-emphasis',
            'EN_REVISION' => 'bg-primary-subtle text-primary-emphasis',
            'CON_OBSERVACIONES' => 'bg-warning-subtle text-warning-emphasis',
            'LISTA_PARA_APROBAR' => 'bg-info-subtle text-info-emphasis',
            'APROBADA' => 'bg-success-subtle text-success-emphasis',
            'ANULADA' => 'bg-danger-subtle text-danger-emphasis',
            default => 'bg-light text-dark',
        };
    }
}

if (!function_exists('ctSolicitudesViewAreaBadgeClass')) {
    function ctSolicitudesViewAreaBadgeClass(string $codigo): string
    {
        return match (strtoupper(trim($codigo))) {
            'PENDIENTE' => 'bg-secondary-subtle text-secondary-emphasis',
            'HABILITADA' => 'bg-info-subtle text-info-emphasis',
            'EN_PROCESO', 'EN_CURSO' => 'bg-primary-subtle text-primary-emphasis',
            'CON_OBSERVACIONES' => 'bg-warning-subtle text-warning-emphasis',
            'COMPLETA', 'CERRADA' => 'bg-success-subtle text-success-emphasis',
            default => 'bg-light text-dark',
        };
    }
}

if (!function_exists('ctSolicitudesComentarioEstadoMeta')) {
    function ctSolicitudesComentarioEstadoMeta(?string $estado): array
    {
        $codigo = strtoupper(trim((string) $estado));
        if ($codigo === 'RESUELTO') {
            return [
                'codigo' => 'RESUELTO',
                'label' => 'Resuelto',
                'badge' => 'bg-success-subtle text-success-emphasis',
            ];
        }
        return [
            'codigo' => 'PENDIENTE',
            'label' => 'Pendiente revisión',
            'badge' => 'bg-warning-subtle text-warning-emphasis',
        ];
    }
}

if (!function_exists('ctSolicitudesAvatarInitial')) {
    function ctSolicitudesAvatarInitial(string $name, int $fallbackId = 0): string
    {
        $clean = trim($name);
        if ($clean === '') {
            return $fallbackId > 0 ? '#' : '?';
        }
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($clean, 0, 1, 'UTF-8'), 'UTF-8');
        }
        return strtoupper(substr($clean, 0, 1));
    }
}

require_once __DIR__ . '/partials/comentarios.php';
require_once __DIR__ . '/partials/tercero_modal.php';
?>
<?php
        $solicitud = $detailData['solicitud'];
        $draft = $detailData['draft'];
        $titulares = $detailData['titulares'];
        $snapshot = $detailData['snapshot'];
        $usuariosMap = $detailData['usuariosMap'];
        $panelesArea = $detailData['panelesArea'];
        $comentarios = $detailData['comentarios'];
        $adjuntos = $detailData['adjuntos'];
        $canEditSolicitante = ctSolicitudesCanEditSolicitud($solicitud, $currentUserId);
        $canApproveMaterialization = ctSolicitudesTipoSoportaAprobacionMaterializacion($solicitud);
        $isSolicitante = (int) ($solicitud['id_solicitante'] ?? 0) === $currentUserId;
        $fichaPostUrl = ctUrl('solicitudes/ficha.php') . ctSolicitudesBuildQuery($state['queryBase'], [
            'pagina' => $state['pagina'],
            'id' => (int) ($solicitud['id_solicitud'] ?? 0),
        ]);
        $titularesByIndex = array_values($titulares);
        if ($titularesByIndex === []) {
            $titularesByIndex[] = [
                'id_tercero' => 0,
                'porcentaje_derecho' => '100',
                'vigente_desde' => (string) ($draft['fecha_adquisicion'] ?? ''),
                'vigente_hasta' => '',
            ];
        }
        $comunaLabels = [];
        if (!isset($comunaOptions) || !is_array($comunaOptions)) {
            $comunaOptions = [];
            foreach (($comunas ?? []) as $comunaItem) {
                $idComunaItem = (int) ($comunaItem['id_comuna'] ?? 0);
                if ($idComunaItem <= 0) {
                    continue;
                }
                $nombreComunaItem = trim((string) (
                    $comunaItem['nombre']
                    ?? $comunaItem['comuna']
                    ?? $comunaItem['comuna_nombre']
                    ?? ''
                ));
                $nombreRegionItem = trim((string) (
                    $comunaItem['region']
                    ?? $comunaItem['region_nombre']
                    ?? ''
                ));
                $labelComunaItem = $nombreComunaItem;
                if ($nombreRegionItem !== '') {
                    $labelComunaItem .= ' (' . $nombreRegionItem . ')';
                }
                if ($labelComunaItem === '') {
                    $labelComunaItem = 'Comuna #' . $idComunaItem;
                }
                $comunaOptions[] = [
                    'value' => (string) $idComunaItem,
                    'label' => $labelComunaItem,
                    'search' => strtolower($labelComunaItem),
                ];
            }
        }
        foreach ($comunaOptions as $option) {
            $value = trim((string) ($option['value'] ?? ''));
            if ($value !== '') {
                $comunaLabels[$value] = (string) ($option['label'] ?? '');
            }
        }
        $tipoInmuebleLabels = [];
        if (!isset($tipoInmuebleOptions) || !is_array($tipoInmuebleOptions)) {
            $tipoInmuebleOptions = [];
            foreach (($tiposInmueble ?? []) as $tipoItem) {
                $idTipoItem = (int) ($tipoItem['id_tipo_inmueble'] ?? 0);
                if ($idTipoItem <= 0) {
                    continue;
                }
                $codigoTipoItem = trim((string) ($tipoItem['codigo'] ?? ''));
                $nombreTipoItem = trim((string) ($tipoItem['nombre'] ?? ''));
                $labelTipoItem = $nombreTipoItem !== '' ? $nombreTipoItem : ('Tipo #' . $idTipoItem);
                if ($codigoTipoItem !== '') {
                    $labelTipoItem .= ' (' . $codigoTipoItem . ')';
                }
                $tipoInmuebleOptions[] = [
                    'value' => (string) $idTipoItem,
                    'label' => $labelTipoItem,
                    'search' => strtolower($labelTipoItem . ' ' . $codigoTipoItem . ' ' . $nombreTipoItem),
                ];
            }
        }
        foreach ($tipoInmuebleOptions as $option) {
            $value = trim((string) ($option['value'] ?? ''));
            if ($value !== '') {
                $tipoInmuebleLabels[$value] = (string) ($option['label'] ?? '');
            }
        }
        $comentariosByArea = [];
        $comentariosGenerales = [];
        foreach ($comentarios as $comentarioRow) {
            $idAreaComentario = 0;
            if (isset($comentarioRow['id_area_solicitud']) && is_numeric((string) $comentarioRow['id_area_solicitud'])) {
                $idAreaComentario = (int) $comentarioRow['id_area_solicitud'];
            } elseif (isset($comentarioRow['id_area_instancia']) && is_numeric((string) $comentarioRow['id_area_instancia'])) {
                foreach ($panelesArea as $panelTmp) {
                    $respuestaTmp = $panelTmp['respuesta'] ?? null;
                    if (is_array($respuestaTmp) && (int) ($respuestaTmp['id_area_instancia'] ?? 0) === (int) ($comentarioRow['id_area_instancia'] ?? 0)) {
                        $idAreaComentario = (int) (($panelTmp['area']['id_area_solicitud'] ?? 0));
                        break;
                    }
                }
            }
            if ($idAreaComentario > 0) {
                if (!isset($comentariosByArea[$idAreaComentario])) {
                    $comentariosByArea[$idAreaComentario] = [];
                }
                $comentariosByArea[$idAreaComentario][] = $comentarioRow;
            } else {
                $comentariosGenerales[] = $comentarioRow;
            }
        }
        $draftMissingFieldsApproval = isset($snapshot['draft_missing_fields']) && is_array($snapshot['draft_missing_fields'])
            ? array_values(array_filter(array_map(static fn($item): string => trim((string) $item), $snapshot['draft_missing_fields']), static fn(string $item): bool => $item !== ''))
            : [];
        $selectedAreaIdsApproval = isset($snapshot['selected_area_ids']) && is_array($snapshot['selected_area_ids'])
            ? array_values(array_filter(array_map(static fn($id): int => (int) $id, $snapshot['selected_area_ids']), static fn(int $id): bool => $id > 0))
            : [];
        $selectedAreaIdLookupApproval = [];
        foreach ($selectedAreaIdsApproval as $idTmpApproval) {
            $selectedAreaIdLookupApproval[$idTmpApproval] = true;
        }
        $selectedAreaNamesApproval = [];
        foreach ($panelesArea as $panelApproval) {
            $idAreaApproval = (int) ($panelApproval['area']['id_area_solicitud'] ?? 0);
            if ($idAreaApproval <= 0 || !isset($selectedAreaIdLookupApproval[$idAreaApproval])) {
                continue;
            }
            $nombreAreaApproval = trim((string) ($panelApproval['area']['nombre'] ?? ''));
            if ($nombreAreaApproval !== '') {
                $selectedAreaNamesApproval[] = $nombreAreaApproval;
            }
        }
        $approvalModalId = 'ct-sol-approve-modal';
?>
<section class="mt-3 ct-theme-enterprise">
    <?php
    gpRenderSectionHeader([
        'kicker' => 'CT / Solicitudes',
        'title' => 'Ficha de solicitud #' . (string) ((int) ($solicitud['id_solicitud'] ?? 0)),
        'back_url' => $volverHref,
        'back_label' => 'Volver a bandeja',
        'help_text' => 'Trabaja por bloques de área y usa comentarios contextualizados por departamento.',
    ]);
    ?>
    <section class="mt-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                        <div>
                            <div class="small text-muted">Solicitud #<?php echo ctEscape((string) ($solicitud['id_solicitud'] ?? 0)); ?></div>
                            <h2 class="h4 mb-1"><?php echo ctEscape((string) ($solicitud['tipo_nombre'] ?? 'Solicitud')); ?></h2>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="badge rounded-pill <?php echo ctEscape(ctSolicitudesViewBadgeClass((string) ($solicitud['estado_codigo'] ?? ''))); ?>"><?php echo ctEscape((string) ($solicitud['estado_nombre'] ?? '')); ?></span>
                                <span class="small text-muted">Solicitante: <?php echo ctEscape($usuariosMap[(int) ($solicitud['id_solicitante'] ?? 0)] ?? ('Usuario #' . (int) ($solicitud['id_solicitante'] ?? 0))); ?></span>
                                <span class="small text-muted">Creada: <?php echo ctEscape(ctSolicitudesViewDatetime((string) ($solicitud['fecha_creacion'] ?? ''))); ?></span>
                            </div>
                            <?php if ((int) ($solicitud['id_terreno_generado'] ?? 0) > 0 || (int) ($solicitud['id_operacion_generada'] ?? 0) > 0): ?>
                                <div class="small text-muted mt-2">
                                    Terreno generado: <strong>#<?php echo ctEscape((string) ($solicitud['id_terreno_generado'] ?? 0)); ?></strong>
                                    | Operación: <strong>#<?php echo ctEscape((string) ($solicitud['id_operacion_generada'] ?? 0)); ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($canEditSolicitante): ?>
                                <form method="post">
                                    <?php ctCsrfField(); ?>
                                    <input type="hidden" name="accion" value="anular_solicitud">
                                    <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) ($solicitud['id_solicitud'] ?? 0)); ?>">
                                    <button type="submit" class="btn btn-outline-danger">Anular</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canEditSolicitante && $canApproveMaterialization): ?>
                                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#<?php echo ctEscape($approvalModalId); ?>">
                                    Aprobar y materializar
                                </button>
                            <?php elseif ($canEditSolicitante): ?>
                                <button type="button" class="btn btn-outline-secondary" disabled>Aprobación de este tipo: Próximamente</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php if ($canEditSolicitante && $canApproveMaterialization): ?>
                <div class="modal fade" id="<?php echo ctEscape($approvalModalId); ?>" tabindex="-1" aria-labelledby="<?php echo ctEscape($approvalModalId); ?>-label" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h3 class="modal-title fs-5" id="<?php echo ctEscape($approvalModalId); ?>-label">Confirmar aprobación y materialización</h3>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                            </div>
                            <div class="modal-body">
                                <div class="border rounded p-3 bg-light-subtle mb-3">
                                    <div class="small text-muted">Solicitud #<?php echo ctEscape((string) ($solicitud['id_solicitud'] ?? 0)); ?></div>
                                    <div class="fw-semibold"><?php echo ctEscape((string) ($solicitud['tipo_nombre'] ?? 'Solicitud')); ?></div>
                                    <div class="small text-muted mt-1">La aprobación creará/mutará registros operativos en Terrenos.</div>
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-12 col-md-6">
                                        <div class="border rounded p-2 h-100">
                                            <div class="small text-muted">Draft completo</div>
                                            <div class="fw-semibold"><?php echo !empty($snapshot['draft_completo']) ? 'Sí' : 'No'; ?></div>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="border rounded p-2 h-100">
                                            <div class="small text-muted">Titulares válidos</div>
                                            <div class="fw-semibold"><?php echo !empty($snapshot['titulares_validos']) ? 'Sí' : 'No'; ?></div>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="border rounded p-2 h-100">
                                            <div class="small text-muted">Áreas completas</div>
                                            <div class="fw-semibold"><?php echo ctEscape((string) ((int) ($snapshot['areas_completas_count'] ?? 0))); ?>/<?php echo ctEscape((string) count($selectedAreaIdsApproval)); ?></div>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="border rounded p-2 h-100">
                                            <div class="small text-muted">Lista para aprobar</div>
                                            <div class="fw-semibold"><?php echo !empty($snapshot['ready_to_approve']) ? 'Sí' : 'No'; ?></div>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($selectedAreaNamesApproval !== []): ?>
                                    <div class="small mb-2"><span class="text-muted">Áreas incluidas:</span> <?php echo ctEscape(implode(', ', $selectedAreaNamesApproval)); ?></div>
                                <?php endif; ?>
                                <?php if ($draftMissingFieldsApproval !== []): ?>
                                    <div class="alert alert-warning py-2 mb-2">
                                        <div class="small fw-semibold">Faltan campos para aprobar</div>
                                        <div class="small"><?php echo ctEscape(implode(', ', $draftMissingFieldsApproval)); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (empty($snapshot['ready_to_approve'])): ?>
                                    <div class="alert alert-warning py-2 mb-0">
                                        <div class="small">Hay validaciones pendientes. Si confirmas igual, el backend rechazará la aprobación con el detalle.</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <form method="post">
                                    <?php ctCsrfField(); ?>
                                    <input type="hidden" name="accion" value="aprobar_solicitud">
                                    <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) ($solicitud['id_solicitud'] ?? 0)); ?>">
                                    <button type="submit" class="btn btn-success">Confirmar aprobación</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-12 order-1">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h3 class="h6 mb-1">Participantes</h3>
                            <div class="row g-3">
                                <?php if ($panelesArea === []): ?>
                                    <div class="col-12">
                                        <div class="small text-muted">Sin áreas seleccionadas todavía.</div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($panelesArea as $panelResumen): ?>
                                        <?php
                                        $areaResumen = $panelResumen['area'] ?? [];
                                        $participantesResumen = $panelResumen['participantes'] ?? [];
                                        $responsablesResumen = [];
                                        $colaboradoresResumen = [];
                                        foreach ($participantesResumen as $personaResumen) {
                                            $idPersonaResumen = (int) ($personaResumen['id_usuario_corporativo'] ?? 0);
                                            $nombrePersonaResumen = trim((string) ($personaResumen['participante_nombre'] ?? ''));
                                            if ($nombrePersonaResumen === '') {
                                                $nombrePersonaResumen = $idPersonaResumen > 0 ? ('Usuario #' . $idPersonaResumen) : 'Usuario';
                                            }
                                            $personaRow = [
                                                'nombre' => $nombrePersonaResumen,
                                                'url_logo' => trim((string) ($personaResumen['participante_url_logo'] ?? '')),
                                                'initial' => ctSolicitudesAvatarInitial($nombrePersonaResumen, $idPersonaResumen),
                                            ];
                                            if (!empty($personaResumen['es_responsable_area'])) {
                                                $responsablesResumen[] = $personaRow;
                                            } else {
                                                $colaboradoresResumen[] = $personaRow;
                                            }
                                        }
                                        ?>
                                        <div class="col-12 col-lg-6">
                                            <div class="border rounded p-3 h-100 bg-light-subtle">
                                                <div class="fw-semibold mb-2"><?php echo ctEscape((string) ($areaResumen['nombre'] ?? 'Área')); ?></div>
                                                <div class="small mb-2">
                                                    <div class="text-muted mb-1">Responsable</div>
                                                    <?php if ($responsablesResumen === []): ?>
                                                        <div class="small text-muted">Sin definir</div>
                                                    <?php else: ?>
                                                        <div class="ct-participants-stack">
                                                            <?php foreach ($responsablesResumen as $personaTag): ?>
                                                                <div class="ct-participant-pill">
                                                                    <span class="ct-participant-avatar<?php echo $personaTag['url_logo'] !== '' ? ' has-image' : ''; ?>">
                                                                        <?php if ($personaTag['url_logo'] !== ''): ?>
                                                                            <img
                                                                                src="<?php echo ctEscape((string) $personaTag['url_logo']); ?>"
                                                                                alt="<?php echo ctEscape((string) $personaTag['nombre']); ?>"
                                                                                loading="lazy"
                                                                                onerror="this.style.display='none';var p=this.parentNode;if(p&&p.classList){p.classList.remove('has-image');}">
                                                                        <?php endif; ?>
                                                                        <span class="ct-participant-avatar-fallback"><?php echo ctEscape((string) $personaTag['initial']); ?></span>
                                                                    </span>
                                                                    <span class="ct-participant-name"><?php echo ctEscape((string) $personaTag['nombre']); ?></span>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small">
                                                    <div class="text-muted mb-1">Colaboradores</div>
                                                    <?php if ($colaboradoresResumen === []): ?>
                                                        <div class="small text-muted">Sin colaboradores adicionales</div>
                                                    <?php else: ?>
                                                        <div class="ct-participants-stack">
                                                            <?php foreach ($colaboradoresResumen as $personaTag): ?>
                                                                <div class="ct-participant-pill">
                                                                    <span class="ct-participant-avatar<?php echo $personaTag['url_logo'] !== '' ? ' has-image' : ''; ?>">
                                                                        <?php if ($personaTag['url_logo'] !== ''): ?>
                                                                            <img
                                                                                src="<?php echo ctEscape((string) $personaTag['url_logo']); ?>"
                                                                                alt="<?php echo ctEscape((string) $personaTag['nombre']); ?>"
                                                                                loading="lazy"
                                                                                onerror="this.style.display='none';var p=this.parentNode;if(p&&p.classList){p.classList.remove('has-image');}">
                                                                        <?php endif; ?>
                                                                        <span class="ct-participant-avatar-fallback"><?php echo ctEscape((string) $personaTag['initial']); ?></span>
                                                                    </span>
                                                                    <span class="ct-participant-name"><?php echo ctEscape((string) $personaTag['nombre']); ?></span>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 order-2">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <?php if ($panelesArea === []): ?>
                                <div class="text-muted">Todavía no hay áreas seleccionadas en la solicitud.</div>
                            <?php else: ?>
                                <div class="row g-3">
                                    <?php foreach ($panelesArea as $panel): ?>
                                        <?php
                                        $area = $panel['area'];
                                        $respuesta = $panel['respuesta'];
                                        $idArea = (int) ($area['id_area_solicitud'] ?? 0);
                                        $areaCodigoPanel = strtoupper(trim((string) ($area['codigo'] ?? '')));
                                        $estadoAreaCodigo = strtoupper(trim((string) ($respuesta['estado'] ?? '')));
                                        $isAreaCompleta = in_array($estadoAreaCodigo, ['COMPLETA', 'CERRADA'], true);
                                        $requiredFieldDefs = [];
                                        $canEditArea = false;
                                        $canManageAreaActions = false;
                                        if (!in_array(strtoupper(trim((string) ($solicitud['estado_codigo'] ?? ''))), ['APROBADA', 'ANULADA'], true)) {
                                            foreach ($panel['participantes'] as $participanteArea) {
                                                if ((int) ($participanteArea['id_usuario_corporativo'] ?? 0) === $currentUserId) {
                                                    $canManageAreaActions = true;
                                                    $canEditArea = true;
                                                    break;
                                                }
                                            }
                                        }
                                        if ($isAreaCompleta) {
                                            $canEditArea = false;
                                        }
                                        $canEditAdquisicionSupport = $canApproveMaterialization && !$isAreaCompleta && $canEditArea;
                                        $areaComentarios = $comentariosByArea[$idArea] ?? [];
                                        $collapseId = 'ct-sol-area-collapse-' . (string) $idArea;
                                        $areaPanelAnchorId = 'ct-sol-area-panel-' . (string) $idArea;
                                        $areaThreadAnchorId = 'ct-sol-area-thread-' . (string) $idArea;
                                        $canCommentArea = (bool) ($canCommentByArea[$idArea] ?? false);
                                        $collapsedByDefault = in_array($estadoAreaCodigo, ['COMPLETA', 'CERRADA'], true);
                                        ?>
                                        <div class="col-12" id="<?php echo ctEscape($areaPanelAnchorId); ?>">
                                            <div class="row g-3 ct-sol-area-layout">
                                                <div class="col-12 col-xl-9 ct-sol-area-main-col">
                                                    <div class="border rounded p-3 h-100 <?php echo $isAreaCompleta ? 'ct-sol-area-card-complete' : ''; ?>" data-area-card="<?php echo ctEscape((string) $idArea); ?>">
                                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                                    <div>
                                                        <div class="fw-semibold">Formulario <?php echo ctEscape((string) ($area['nombre'] ?? '')); ?></div>
                                                        <div class="small text-muted">
                                                            <?php
                                                            $labels = [];
                                                            foreach ($panel['participantes'] as $participanteLabel) {
                                                                $labels[] = (string) ($participanteLabel['participante_nombre'] ?? '');
                                                            }
                                                            echo ctEscape(implode(', ', $labels));
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <span class="badge rounded-pill <?php echo ctEscape(ctSolicitudesViewAreaBadgeClass((string) ($respuesta['estado'] ?? 'PENDIENTE'))); ?>" data-area-status-badge="<?php echo ctEscape((string) $idArea); ?>">
                                                        <?php echo ctEscape((string) ($respuesta['estado'] ?? 'PENDIENTE')); ?>
                                                    </span>
                                                </div>
                                                <div class="ct-sol-area-status mb-2">
                                                    <span class="ct-sol-area-status-dot <?php echo $isAreaCompleta ? 'is-complete' : 'is-pending'; ?>" data-area-status-dot="<?php echo ctEscape((string) $idArea); ?>" aria-hidden="true"></span>
                                                    <span class="small <?php echo $isAreaCompleta ? 'text-success-emphasis' : 'text-muted'; ?>" data-area-status-text="<?php echo ctEscape((string) $idArea); ?>">
                                                        <?php echo $isAreaCompleta ? 'Bloque completado' : 'Bloque pendiente de cierre'; ?>
                                                    </span>
                                                </div>
                                                <?php if (!$canCommentArea): ?>
                                                    <div class="small mt-1">
                                                        <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis">Sin permiso para comentar en esta área</span>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="d-flex justify-content-end mb-2">
                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-secondary btn-sm"
                                                        data-bs-toggle="collapse"
                                                        data-bs-target="#<?php echo ctEscape($collapseId); ?>"
                                                        aria-expanded="<?php echo $collapsedByDefault ? 'false' : 'true'; ?>"
                                                        aria-controls="<?php echo ctEscape($collapseId); ?>">
                                                        <i class="bi bi-arrows-expand-vertical me-1" aria-hidden="true"></i>Ver / Ocultar
                                                    </button>
                                                </div>
                                                <div id="<?php echo ctEscape($collapseId); ?>" class="collapse<?php echo $collapsedByDefault ? '' : ' show'; ?>">
                                                <?php if ($canApproveMaterialization && $areaCodigoPanel === 'LEGAL'): ?>
                                                    <?php $modalCrearTerceroId = 'ct-sol-crear-tercero-' . (string) $idArea; ?>
                                                    <div class="border rounded p-3 mb-3">
                                                        <h4 class="h6 mb-1">Datos de adquisición</h4>
                                                        <form method="post" class="row g-2" data-area-support-form="<?php echo ctEscape((string) $idArea); ?>" data-area-support-sync="1">
                                                            <?php ctCsrfField(); ?>
                                                            <input type="hidden" name="accion" value="guardar_draft_adquisicion_area">
                                                            <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) ($solicitud['id_solicitud'] ?? 0)); ?>">
                                                            <input type="hidden" name="id_area_solicitud" value="<?php echo ctEscape((string) $idArea); ?>">
                                                            <div class="col-12">
                                                                <?php ctRenderFieldLabel('Resumen', false); ?>
                                                                <textarea name="resumen" class="form-control form-control-sm" rows="2" maxlength="500" <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>><?php echo ctEscape((string) ($solicitud['resumen'] ?? '')); ?></textarea>
                                                            </div>
                                                            <div class="col-12 col-md-6">
                                                                <?php ctRenderFieldLabel('Rol asignado', true); ?>
                                                                <input name="rol_asignado" class="form-control form-control-sm" maxlength="30" value="<?php echo ctEscape((string) ($draft['rol_asignado'] ?? '')); ?>" <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>>
                                                            </div>
                                                            <div class="col-12 col-md-6">
                                                                <?php ctRenderFieldLabel('Rol matriz', false); ?>
                                                                <input name="rol_matriz" class="form-control form-control-sm" maxlength="30" value="<?php echo ctEscape((string) ($draft['rol_matriz'] ?? '')); ?>" <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>>
                                                            </div>
                                                            <div class="col-12 col-md-6">
                                                                <?php ctRenderFieldLabel('Fecha adquisición', true); ?>
                                                                <input type="date" name="fecha_adquisicion" class="form-control form-control-sm" value="<?php echo ctEscape((string) ($draft['fecha_adquisicion'] ?? '')); ?>" <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>>
                                                            </div>
                                                            <div class="col-12 col-md-6">
                                                                <?php ctRenderFieldLabel('Documento fuente', false); ?>
                                                                <input name="documento_fuente" class="form-control form-control-sm" maxlength="255" value="<?php echo ctEscape((string) ($draft['documento_fuente'] ?? '')); ?>" <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>>
                                                            </div>
                                                            <?php if ($canEditAdquisicionSupport && !$canEditArea): ?>
                                                                <div class="col-12">
                                                                    <button type="submit" class="btn btn-outline-primary btn-sm">Guardar datos legales</button>
                                                                </div>
                                                            <?php endif; ?>
                                                        </form>
                                                    </div>
                                                    <div class="border rounded p-3 mb-3">
                                                        <h4 class="h6 mb-1">Titulares</h4>
                                                        <div class="small text-muted mb-2">La suma debe cerrar exactamente en 100.00.</div>
                                                        <form
                                                            method="post"
                                                            data-titulares-form="1"
                                                            data-area-support-form="<?php echo ctEscape((string) $idArea); ?>"
                                                            data-titulares-default-date="<?php echo ctEscape((string) ($draft['fecha_adquisicion'] ?? '')); ?>">
                                                            <?php ctCsrfField(); ?>
                                                            <input type="hidden" name="accion" value="guardar_titulares_draft">
                                                            <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) ($solicitud['id_solicitud'] ?? 0)); ?>">
                                                            <input type="hidden" name="id_area_solicitud" value="<?php echo ctEscape((string) $idArea); ?>">
                                                            <input type="hidden" name="fecha_adquisicion" value="<?php echo ctEscape((string) ($draft['fecha_adquisicion'] ?? '')); ?>">
                                                            <div class="table-responsive">
                                                                <table class="table table-sm align-middle mb-2">
                                                                    <thead>
                                                                    <tr>
                                                                        <th>Tercero</th>
                                                                        <th>%</th>
                                                                        <th>Vigente desde</th>
                                                                        <th class="text-end">Acción</th>
                                                                    </tr>
                                                                    </thead>
                                                                    <tbody data-titulares-body>
                                                                    <?php foreach ($titularesByIndex as $idxTitular => $rowTitular): ?>
                                                                        <tr data-titulares-row>
                                                                            <td>
                                                                                <select name="titulares_id_tercero[]" class="form-select form-select-sm" <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>>
                                                                                    <option value="">Selecciona titular...</option>
                                                                                    <?php foreach ($terceros as $tercero): ?>
                                                                                        <?php $selected = (int) ($rowTitular['id_tercero'] ?? 0) === (int) ($tercero['id_tercero'] ?? 0); ?>
                                                                                        <option value="<?php echo ctEscape((string) ($tercero['id_tercero'] ?? 0)); ?>" <?php echo $selected ? 'selected' : ''; ?>>
                                                                                            <?php echo ctEscape((string) ($tercero['nombre_razon_social'] ?? '')); ?><?php echo trim((string) ($tercero['rut'] ?? '')) !== '' ? ' (' . ctEscape((string) $tercero['rut']) . ')' : ''; ?>
                                                                                        </option>
                                                                                    <?php endforeach; ?>
                                                                                </select>
                                                                            </td>
                                                                            <td><input name="titulares_porcentaje_derecho[]" class="form-control form-control-sm" value="<?php echo ctEscape((string) ($rowTitular['porcentaje_derecho'] ?? ($idxTitular === 0 ? '100' : ''))); ?>" <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>></td>
                                                                            <td><input type="date" name="titulares_vigente_desde[]" class="form-control form-control-sm" value="<?php echo ctEscape((string) ($rowTitular['vigente_desde'] ?? ($draft['fecha_adquisicion'] ?? ''))); ?>" <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>></td>
                                                                            <td class="text-end">
                                                                                <button
                                                                                    type="button"
                                                                                    class="btn btn-outline-danger btn-sm ct-titular-remove-btn"
                                                                                    data-titulares-remove
                                                                                    <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>>
                                                                                    Quitar
                                                                                </button>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                            <template data-titulares-template>
                                                                <tr data-titulares-row>
                                                                    <td>
                                                                        <select name="titulares_id_tercero[]" class="form-select form-select-sm">
                                                                            <option value="">Selecciona titular...</option>
                                                                            <?php foreach ($terceros as $tercero): ?>
                                                                                <option value="<?php echo ctEscape((string) ($tercero['id_tercero'] ?? 0)); ?>">
                                                                                    <?php echo ctEscape((string) ($tercero['nombre_razon_social'] ?? '')); ?><?php echo trim((string) ($tercero['rut'] ?? '')) !== '' ? ' (' . ctEscape((string) $tercero['rut']) . ')' : ''; ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </td>
                                                                    <td><input name="titulares_porcentaje_derecho[]" class="form-control form-control-sm" value=""></td>
                                                                    <td><input type="date" name="titulares_vigente_desde[]" class="form-control form-control-sm" value=""></td>
                                                                    <td class="text-end">
                                                                        <button type="button" class="btn btn-outline-danger btn-sm ct-titular-remove-btn" data-titulares-remove>Quitar</button>
                                                                    </td>
                                                                </tr>
                                                            </template>
                                                            <?php if ($canEditAdquisicionSupport): ?>
                                                                <div class="d-flex flex-wrap gap-2">
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-titulares-add>Agregar titular</button>
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#<?php echo ctEscape($modalCrearTerceroId); ?>">
                                                                        Registrar nuevo titular
                                                                    </button>
                                                                    <button type="submit" class="btn btn-outline-primary btn-sm">Guardar titulares</button>
                                                                </div>
                                                            <?php endif; ?>
                                                        </form>
                                                        <?php
                                                        ctSolicitudesRenderCrearTerceroModal([
                                                            'modal_id' => $modalCrearTerceroId,
                                                            'id_solicitud' => (int) ($solicitud['id_solicitud'] ?? 0),
                                                            'id_area_solicitud' => $idArea,
                                                            'can_edit' => $canEditAdquisicionSupport,
                                                        ]);
                                                        ?>
                                                    </div>
                                                <?php elseif ($canApproveMaterialization && $areaCodigoPanel === 'ARQUITECTURA'): ?>
                                                    <div class="border rounded p-3 mb-3">
                                                        <h4 class="h6 mb-1">Datos técnicos de adquisición (soporte)</h4>
                                                        <div class="small mb-2">
                                                            <span class="badge rounded-pill bg-info-subtle text-info-emphasis">Responsable: Arquitectura</span>
                                                        </div>
                                                        <div class="small text-muted mb-2">Campos técnicos asociados a Arquitectura.</div>
                                                        <form method="post" class="row g-2" data-area-support-form="<?php echo ctEscape((string) $idArea); ?>" data-area-support-sync="1">
                                                            <?php ctCsrfField(); ?>
                                                            <input type="hidden" name="accion" value="guardar_draft_adquisicion_area">
                                                            <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) ($solicitud['id_solicitud'] ?? 0)); ?>">
                                                            <input type="hidden" name="id_area_solicitud" value="<?php echo ctEscape((string) $idArea); ?>">
                                                            <div class="col-12 col-md-6">
                                                                <?php ctRenderFieldLabel('Identificación', false); ?>
                                                                <input name="identificacion_propiedad" class="form-control form-control-sm" maxlength="120" value="<?php echo ctEscape((string) ($draft['identificacion_propiedad'] ?? '')); ?>" <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>>
                                                            </div>
                                                            <div class="col-12 col-md-6">
                                                                <?php ctRenderFieldLabel('Superficie m2', true); ?>
                                                                <input name="superficie_m2" class="form-control form-control-sm" inputmode="decimal" value="<?php echo ctEscape((string) ($draft['superficie_m2'] ?? '')); ?>" <?php echo $canEditAdquisicionSupport ? '' : 'disabled'; ?>>
                                                            </div>
                                                            <div class="col-12 col-md-6">
                                                                <?php
                                                                ctRenderSearchableSelectField([
                                                                    'wrapper_class' => '',
                                                                    'label' => 'Comuna',
                                                                    'input_name' => 'id_comuna',
                                                                    'input_id' => 'ct-sol-area-draft-comuna-' . $idArea,
                                                                    'picker_id' => 'ct-sol-area-draft-comuna-picker-' . $idArea,
                                                                    'button_id' => 'ct-sol-area-draft-comuna-btn-' . $idArea,
                                                                    'filter_id' => 'ct-sol-area-draft-comuna-filter-' . $idArea,
                                                                    'list_id' => 'ct-sol-area-draft-comuna-list-' . $idArea,
                                                                    'error_id' => 'ct-sol-area-draft-comuna-error-' . $idArea,
                                                                    'error_message' => 'Debes seleccionar una comuna.',
                                                                    'button_placeholder' => 'Selecciona comuna...',
                                                                    'filter_placeholder' => 'Buscar comuna...',
                                                                    'required' => false,
                                                                    'show_requirement' => true,
                                                                    'disabled' => !$canEditAdquisicionSupport,
                                                                    'value' => (string) ($draft['id_comuna'] ?? ''),
                                                                    'options' => $comunaOptions,
                                                                ]);
                                                                ?>
                                                            </div>
                                                            <div class="col-12 col-md-6">
                                                                <?php
                                                                ctRenderSearchableSelectField([
                                                                    'wrapper_class' => '',
                                                                    'label' => 'Tipo inmueble',
                                                                    'input_name' => 'id_tipo_inmueble',
                                                                    'input_id' => 'ct-sol-area-draft-tipo-' . $idArea,
                                                                    'picker_id' => 'ct-sol-area-draft-tipo-picker-' . $idArea,
                                                                    'button_id' => 'ct-sol-area-draft-tipo-btn-' . $idArea,
                                                                    'filter_id' => 'ct-sol-area-draft-tipo-filter-' . $idArea,
                                                                    'list_id' => 'ct-sol-area-draft-tipo-list-' . $idArea,
                                                                    'error_id' => 'ct-sol-area-draft-tipo-error-' . $idArea,
                                                                    'error_message' => 'Debes seleccionar un tipo.',
                                                                    'button_placeholder' => 'Selecciona tipo...',
                                                                    'filter_placeholder' => 'Buscar tipo...',
                                                                    'required' => false,
                                                                    'show_requirement' => true,
                                                                    'disabled' => !$canEditAdquisicionSupport,
                                                                    'value' => (string) ($draft['id_tipo_inmueble'] ?? ''),
                                                                    'options' => $tipoInmuebleOptions,
                                                                ]);
                                                                ?>
                                                            </div>
                                                            <?php if ($canEditAdquisicionSupport && !$canEditArea): ?>
                                                                <div class="col-12">
                                                                    <button type="submit" class="btn btn-outline-primary btn-sm">Guardar datos técnicos</button>
                                                                </div>
                                                            <?php endif; ?>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                                <form method="post" class="mb-3" data-area-main-form="<?php echo ctEscape((string) $idArea); ?>">
                                                    <?php ctCsrfField(); ?>
                                                    <input type="hidden" name="accion" value="guardar_respuesta_area">
                                                    <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) ($solicitud['id_solicitud'] ?? 0)); ?>">
                                                    <input type="hidden" name="id_area_solicitud" value="<?php echo ctEscape((string) $idArea); ?>">
                                                    <?php if ($requiredFieldDefs !== []): ?>
                                                        <div class="border rounded p-2 bg-light-subtle mb-2" data-area-checklist="<?php echo ctEscape((string) $idArea); ?>">
                                                            <div class="small fw-semibold mb-1">Checklist mínimo</div>
                                                            <div class="small text-muted d-grid gap-1">
                                                                <?php foreach ($requiredFieldDefs as $requiredField): ?>
                                                                    <?php $requiredFieldName = (string) ($requiredField['name'] ?? ''); ?>
                                                                    <span data-area-check-item="<?php echo ctEscape((string) $idArea); ?>" data-field-name="<?php echo ctEscape($requiredFieldName); ?>">
                                                                        <i class="bi bi-x-circle me-1 text-danger" aria-hidden="true"></i><?php echo ctEscape((string) ($requiredField['label'] ?? $requiredFieldName)); ?>
                                                                    </span>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($areaCodigoPanel === 'LEGAL'): ?>
                                                        <div class="row g-2">
                                                            <div class="col-12">
                                                                <?php ctRenderFieldLabel('Observaciones legales', false); ?>
                                                                <textarea name="legal_observaciones" class="form-control form-control-sm" rows="4" maxlength="2000" <?php echo $canEditArea ? '' : 'disabled'; ?>><?php echo ctEscape((string) ($respuesta['observaciones_legal'] ?? '')); ?></textarea>
                                                            </div>
                                                        </div>
                                                    <?php elseif ($areaCodigoPanel === 'ARQUITECTURA'): ?>
                                                        <div class="row g-2">
                                                            <div class="col-12">
                                                                <?php ctRenderFieldLabel('Observaciones arquitectura', false); ?>
                                                                <textarea name="arq_observaciones" class="form-control form-control-sm" rows="4" maxlength="2000" <?php echo $canEditArea ? '' : 'disabled'; ?>><?php echo ctEscape((string) ($respuesta['observaciones_arquitectura'] ?? '')); ?></textarea>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="small text-muted">Área sin formulario tipado implementado en esta fase.</div>
                                                    <?php endif; ?>
                                                    <?php if ($canManageAreaActions): ?>
                                                        <div data-area-save-wrap="<?php echo ctEscape((string) $idArea); ?>" class="<?php echo $canEditArea ? '' : 'd-none'; ?>">
                                                            <button
                                                                type="submit"
                                                                class="btn btn-outline-primary btn-sm mt-2"
                                                                data-area-save-btn="<?php echo ctEscape((string) $idArea); ?>"
                                                                <?php echo $canEditArea ? '' : 'disabled'; ?>>
                                                                Guardar bloque
                                                            </button>
                                                        </div>
                                                    <?php endif; ?>
                                                </form>
                                                <div class="d-flex flex-wrap gap-2 mb-3">
                                                    <?php if ($canManageAreaActions): ?>
                                                        <form method="post" data-area-complete-form="<?php echo ctEscape((string) $idArea); ?>" class="<?php echo $canEditArea ? '' : 'd-none'; ?>">
                                                            <?php ctCsrfField(); ?>
                                                            <input type="hidden" name="accion" value="marcar_area_completa">
                                                            <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) ($solicitud['id_solicitud'] ?? 0)); ?>">
                                                            <input type="hidden" name="id_area_solicitud" value="<?php echo ctEscape((string) $idArea); ?>">
                                                            <button
                                                                type="submit"
                                                                class="btn btn-sm <?php echo $isAreaCompleta ? 'btn-success' : 'btn-outline-success'; ?>"
                                                                data-area-complete-btn="<?php echo ctEscape((string) $idArea); ?>"
                                                                data-area-complete-locked="<?php echo $isAreaCompleta ? '1' : '0'; ?>"
                                                                <?php echo ($isAreaCompleta || !$canEditArea) ? 'disabled' : ''; ?>>
                                                                <i class="bi <?php echo $isAreaCompleta ? 'bi-check-circle-fill' : 'bi-check-circle'; ?> me-1" aria-hidden="true"></i>
                                                                <?php echo $isAreaCompleta ? 'Área completada' : 'Marcar completa'; ?>
                                                            </button>
                                                            <div class="small text-muted mt-1" data-area-complete-hint="<?php echo ctEscape((string) $idArea); ?>"></div>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                                    </div>
                                                </div>
                                                </div>
                                                <div class="col-12 col-xl-3 ct-sol-area-comments-col">
                                                    <div class="ct-sol-area-comments-shell">
                                                        <?php
                                                        ctSolicitudesRenderAreaCommentsThread([
                                                            'idArea' => $idArea,
                                                            'areaName' => (string) ($area['nombre'] ?? ('Área #' . $idArea)),
                                                            'areaThreadAnchorId' => $areaThreadAnchorId,
                                                            'areaComentarios' => $areaComentarios,
                                                            'canComment' => $canCommentArea,
                                                            'currentUserId' => $currentUserId,
                                                            'usuariosMap' => $usuariosMap,
                                                            'idSolicitud' => (int) ($solicitud['id_solicitud'] ?? 0),
                                                            'postUrl' => $fichaPostUrl,
                                                        ]);
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
            <style>
                .ct-sol-area-thread {
                    background: #f8fafc;
                    height: 100%;
                    display: flex;
                    flex-direction: column;
                    min-height: 0;
                    overflow: hidden;
                }
                .ct-sol-area-thread-list {
                    display: flex;
                    flex-direction: column;
                    gap: 0.5rem;
                    align-content: flex-start;
                    overflow: auto;
                    padding-right: 0.25rem;
                    flex: 1 1 auto;
                    min-height: 0;
                }
                .ct-sol-area-comments-shell {
                    height: 100%;
                    min-height: 0;
                }
                .ct-sol-comment-item {
                    background: #ffffff;
                    border: 1px solid #d4dde9;
                    border-radius: 0.75rem;
                    padding: 0.6rem 0.7rem;
                    height: fit-content;
                }
                .ct-sol-comment-item.is-own {
                    background: #eaf2ff;
                    border-color: #b8cdf6;
                }
                .ct-sol-comment-meta {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 0.75rem;
                    font-size: 0.74rem;
                    color: #5b6778;
                }
                .ct-sol-area-card-complete {
                    border-color: #b9e5c7 !important;
                    background: #f6fbf7;
                }
                .ct-sol-area-status {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.45rem;
                }
                .ct-sol-area-status-dot {
                    width: 0.55rem;
                    height: 0.55rem;
                    border-radius: 50%;
                    display: inline-block;
                }
                .ct-sol-area-status-dot.is-complete {
                    background: #198754;
                }
                .ct-sol-area-status-dot.is-pending {
                    background: #adb5bd;
                }
                .ct-participants-stack {
                    display: grid;
                    gap: 0.55rem;
                }
                .ct-participant-pill {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.65rem;
                    width: 100%;
                    max-width: 26rem;
                    padding: 0.45rem 0.75rem 0.45rem 0.45rem;
                    border: 1px solid #d5dde7;
                    border-radius: 0.75rem;
                    background: #fff;
                    font-size: 0.94rem;
                    color: #2f3a4a;
                    min-height: 3rem;
                }
                .ct-participant-avatar {
                    width: 2.1rem;
                    height: 2.1rem;
                    min-width: 2.1rem;
                    border-radius: 50%;
                    overflow: hidden;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    background: #d9e7fb;
                    color: #254a73;
                    font-weight: 600;
                    font-size: 0.9rem;
                    line-height: 1;
                }
                .ct-participant-name {
                    font-weight: 600;
                    line-height: 1.2;
                }
                .ct-participant-avatar img {
                    width: 100%;
                    height: 100%;
                    object-fit: cover;
                    object-position: center center;
                    transform: scale(1.34);
                    display: none;
                }
                .ct-participant-avatar.has-image img {
                    display: block;
                }
                .ct-participant-avatar.has-image .ct-participant-avatar-fallback {
                    display: none;
                }
                .ct-titular-remove-btn:disabled,
                .ct-titular-remove-btn.is-disabled {
                    opacity: 1;
                    color: #7f8894 !important;
                    border-color: #d2d9e1 !important;
                    background-color: #edf1f5 !important;
                    box-shadow: none !important;
                    cursor: not-allowed !important;
                    pointer-events: none;
                }
                .ct-titular-remove-btn:disabled:hover,
                .ct-titular-remove-btn.is-disabled:hover {
                    color: #7f8894 !important;
                    border-color: #d2d9e1 !important;
                    background-color: #edf1f5 !important;
                }
                [data-ct-searchable-select] [data-searchable-btn]:disabled {
                    background-color: #edf1f5 !important;
                    border-color: #d2d9e1 !important;
                    color: #7f8894 !important;
                    box-shadow: none !important;
                    cursor: not-allowed !important;
                    opacity: 1;
                }
                @media (min-width: 992px) {
                    .ct-sol-area-layout {
                        display: grid !important;
                        grid-template-columns: minmax(0, 3fr) minmax(17rem, 1fr);
                        gap: 1rem;
                        align-items: start !important;
                    }
                    .ct-sol-area-main-col,
                    .ct-sol-area-comments-col {
                        width: auto !important;
                        max-width: none !important;
                        flex: initial !important;
                        padding-left: 0;
                        padding-right: 0;
                    }
                    .ct-sol-area-comments-col,
                    .ct-sol-area-comments-shell {
                        display: flex;
                        flex-direction: column;
                        min-height: 0;
                    }
                    .ct-sol-area-comments-col {
                        height: auto;
                    }
                    .ct-sol-area-thread {
                        max-height: none;
                    }
                }
                @media (max-width: 991.98px) {
                    .ct-sol-area-thread-list {
                        max-height: none;
                    }
                }
            </style>
            <script src="https://unpkg.com/htmx.org@1.9.12"></script>
            <script>
            (function () {
                function syncAreaCommentsHeight() {
                    var desktopMode = window.matchMedia('(min-width: 992px)').matches;
                    var layouts = Array.prototype.slice.call(document.querySelectorAll('.ct-sol-area-layout'));

                    layouts.forEach(function (layout) {
                        if (!(layout instanceof HTMLElement)) {
                            return;
                        }
                        var mainCol = layout.querySelector('.ct-sol-area-main-col');
                        var commentsShell = layout.querySelector('.ct-sol-area-comments-shell');
                        if (!(mainCol instanceof HTMLElement) || !(commentsShell instanceof HTMLElement)) {
                            return;
                        }

                        commentsShell.style.height = '';
                        commentsShell.style.maxHeight = '';

                        if (!desktopMode) {
                            return;
                        }

                        var mainHeight = Math.ceil(mainCol.getBoundingClientRect().height);
                        if (!(mainHeight > 0)) {
                            return;
                        }
                        commentsShell.style.height = String(mainHeight) + 'px';
                    });
                }

                function hasValue(field) {
                    if (!field) {
                        return false;
                    }
                    if (field.type === 'checkbox' || field.type === 'radio') {
                        return !!field.checked;
                    }
                    return String(field.value || '').trim() !== '';
                }

                function updateArea(areaId) {
                    var requiredFields = Array.prototype.slice.call(document.querySelectorAll('[data-area-required-field="' + areaId + '"]'));
                    var completeButton = document.querySelector('[data-area-complete-btn="' + areaId + '"]');
                    var hintNode = document.querySelector('[data-area-complete-hint="' + areaId + '"]');
                    var allOk = true;

                    requiredFields.forEach(function (field) {
                        var ok = hasValue(field);
                        allOk = allOk && ok;
                        var item = document.querySelector('[data-area-check-item="' + areaId + '"][data-field-name="' + field.name + '"]');
                        if (!item) {
                            return;
                        }
                        if (ok) {
                            item.innerHTML = '<i class="bi bi-check-circle me-1 text-success" aria-hidden="true"></i>' + item.textContent.replace(/^\s+|\s+$/g, '');
                        } else {
                            item.innerHTML = '<i class="bi bi-x-circle me-1 text-danger" aria-hidden="true"></i>' + item.textContent.replace(/^\s+|\s+$/g, '');
                        }
                    });

                    if (completeButton) {
                        var locked = completeButton.getAttribute('data-area-complete-locked') === '1';
                        completeButton.disabled = locked || !allOk;
                    }
                    if (hintNode) {
                        hintNode.textContent = allOk ? 'Checklist mínimo completo.' : 'Completa el checklist mínimo para habilitar el cierre del área.';
                    }
                }

                var forms = Array.prototype.slice.call(document.querySelectorAll('[data-area-complete-form]'));
                forms.forEach(function (form) {
                    var areaId = form.getAttribute('data-area-complete-form');
                    if (!areaId) {
                        return;
                    }

                    var fields = Array.prototype.slice.call(document.querySelectorAll('[data-area-required-field="' + areaId + '"]'));
                    fields.forEach(function (field) {
                        field.addEventListener('change', function () {
                            updateArea(areaId);
                        });
                        field.addEventListener('input', function () {
                            updateArea(areaId);
                        });
                    });

                    form.addEventListener('submit', function (event) {
                        var button = form.querySelector('[data-area-complete-btn="' + areaId + '"]');
                        if (button && button.disabled) {
                            event.preventDefault();
                        }
                    });

                    updateArea(areaId);
                });

                var areaMainForms = Array.prototype.slice.call(document.querySelectorAll('[data-area-main-form]'));
                areaMainForms.forEach(function (mainForm) {
                    mainForm.addEventListener('submit', function () {
                        var areaId = String(mainForm.getAttribute('data-area-main-form') || '').trim();
                        if (areaId === '') {
                            return;
                        }

                        mainForm.querySelectorAll('input[data-area-support-shadow="1"]').forEach(function (node) {
                            node.remove();
                        });

                        var supportForms = Array.prototype.slice.call(document.querySelectorAll('form[data-area-support-form="' + areaId + '"][data-area-support-sync="1"]'));
                        supportForms.forEach(function (supportForm) {
                            var controls = Array.prototype.slice.call(supportForm.querySelectorAll('input, select, textarea'));
                            controls.forEach(function (control) {
                                if (!(control instanceof HTMLElement)) {
                                    return;
                                }
                                var fieldName = String(control.getAttribute('name') || '').trim();
                                if (fieldName === '') {
                                    return;
                                }
                                if (control.hasAttribute('disabled')) {
                                    return;
                                }
                                if (fieldName === 'accion' || fieldName === 'id_solicitud' || fieldName === 'id_area_solicitud') {
                                    return;
                                }
                                if (control instanceof HTMLInputElement) {
                                    var inputType = String(control.type || '').toLowerCase();
                                    if (inputType === 'submit' || inputType === 'button' || inputType === 'reset' || inputType === 'file') {
                                        return;
                                    }
                                    if ((inputType === 'checkbox' || inputType === 'radio') && !control.checked) {
                                        return;
                                    }
                                }
                                if (control instanceof HTMLSelectElement && control.multiple) {
                                    Array.from(control.selectedOptions).forEach(function (option) {
                                        var hiddenMulti = document.createElement('input');
                                        hiddenMulti.type = 'hidden';
                                        hiddenMulti.name = fieldName;
                                        hiddenMulti.value = option.value;
                                        hiddenMulti.setAttribute('data-area-support-shadow', '1');
                                        mainForm.appendChild(hiddenMulti);
                                    });
                                    return;
                                }

                                var hidden = document.createElement('input');
                                hidden.type = 'hidden';
                                hidden.name = fieldName;
                                hidden.value = (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement)
                                    ? String(control.value || '')
                                    : '';
                                hidden.setAttribute('data-area-support-shadow', '1');
                                mainForm.appendChild(hidden);
                            });
                        });
                    });
                });

                var titularesForms = Array.prototype.slice.call(document.querySelectorAll('[data-titulares-form]'));
                var titularesSyncFns = [];

                function syncTitularesButtonsGlobal() {
                    titularesSyncFns.forEach(function (syncFn) {
                        if (typeof syncFn === 'function') {
                            syncFn();
                        }
                    });
                }

                titularesForms.forEach(function (titForm) {
                    var tbody = titForm.querySelector('[data-titulares-body]');
                    var template = titForm.querySelector('template[data-titulares-template]');
                    var addBtn = titForm.querySelector('[data-titulares-add]');
                    if (!tbody || !template) {
                        return;
                    }

                    function syncRemoveButtons() {
                        var rows = Array.prototype.slice.call(tbody.querySelectorAll('[data-titulares-row]'));
                        rows.forEach(function (row) {
                            var removeBtn = row.querySelector('[data-titulares-remove]');
                            if (!removeBtn) {
                                return;
                            }
                            var shouldDisable = rows.length <= 1 || !!addBtn && addBtn.disabled;
                            removeBtn.disabled = shouldDisable;
                            removeBtn.classList.toggle('is-disabled', shouldDisable);
                            removeBtn.classList.toggle('btn-outline-secondary', shouldDisable);
                            removeBtn.classList.toggle('btn-outline-danger', !shouldDisable);
                        });
                    }

                    titularesSyncFns.push(syncRemoveButtons);

                    function appendRow() {
                        var rowTemplate = template.content.firstElementChild;
                        if (!rowTemplate) {
                            return;
                        }
                        var cloned = rowTemplate.cloneNode(true);
                        var defaultDate = String(titForm.getAttribute('data-titulares-default-date') || '').trim();
                        if (defaultDate !== '') {
                            var fromInput = cloned.querySelector('input[name="titulares_vigente_desde[]"]');
                            if (fromInput && String(fromInput.value || '').trim() === '') {
                                fromInput.value = defaultDate;
                            }
                        }
                        tbody.appendChild(cloned);
                        syncRemoveButtons();
                    }

                    if (addBtn) {
                        addBtn.addEventListener('click', function () {
                            appendRow();
                        });
                    }

                    tbody.addEventListener('click', function (event) {
                        var target = event.target;
                        if (!(target instanceof HTMLElement)) {
                            return;
                        }
                        var removeBtn = target.closest('[data-titulares-remove]');
                        if (!removeBtn) {
                            return;
                        }
                        var row = removeBtn.closest('[data-titulares-row]');
                        if (!row) {
                            return;
                        }
                        var rows = Array.prototype.slice.call(tbody.querySelectorAll('[data-titulares-row]'));
                        if (rows.length <= 1) {
                            return;
                        }
                        row.remove();
                        syncRemoveButtons();
                    });

                    if (tbody.querySelectorAll('[data-titulares-row]').length === 0) {
                        appendRow();
                    } else {
                        syncRemoveButtons();
                    }
                });

                function areaBadgeClass(statusCode) {
                    var code = String(statusCode || '').toUpperCase();
                    if (code === 'PENDIENTE') {
                        return 'bg-secondary-subtle text-secondary-emphasis';
                    }
                    if (code === 'HABILITADA') {
                        return 'bg-info-subtle text-info-emphasis';
                    }
                    if (code === 'EN_PROCESO' || code === 'EN_CURSO') {
                        return 'bg-primary-subtle text-primary-emphasis';
                    }
                    if (code === 'CON_OBSERVACIONES') {
                        return 'bg-warning-subtle text-warning-emphasis';
                    }
                    if (code === 'COMPLETA' || code === 'CERRADA') {
                        return 'bg-success-subtle text-success-emphasis';
                    }
                    return 'bg-light text-dark';
                }

                function setFormControlsDisabled(formNode, disabled) {
                    if (!formNode) {
                        return;
                    }
                    var controls = formNode.querySelectorAll('input, select, textarea, button');
                    controls.forEach(function (control) {
                        if (!(control instanceof HTMLElement)) {
                            return;
                        }
                        if (control.tagName === 'INPUT' && control.getAttribute('type') === 'hidden') {
                            return;
                        }
                        control.disabled = !!disabled;
                    });
                }

                function applyAreaStatusUpdate(detail) {
                    var areaId = parseInt(String((detail && detail.areaId) || ''), 10);
                    if (!areaId) {
                        return;
                    }
                    var estado = String((detail && detail.estado) || '').toUpperCase();
                    var isComplete = estado === 'COMPLETA' || estado === 'CERRADA';
                    var canEditAreaForm = !!(detail && detail.canEditAreaForm) && !isComplete;
                    var canEditSupportForm = !!(detail && detail.canEditSupportForm) && !isComplete;

                    var card = document.querySelector('[data-area-card="' + areaId + '"]');
                    if (card) {
                        card.classList.toggle('ct-sol-area-card-complete', isComplete);
                    }

                    var badge = document.querySelector('[data-area-status-badge="' + areaId + '"]');
                    if (badge) {
                        badge.className = 'badge rounded-pill ' + areaBadgeClass(estado);
                        badge.textContent = estado || 'PENDIENTE';
                    }

                    var dot = document.querySelector('[data-area-status-dot="' + areaId + '"]');
                    if (dot) {
                        dot.classList.remove('is-complete', 'is-pending');
                        dot.classList.add(isComplete ? 'is-complete' : 'is-pending');
                    }

                    var statusText = document.querySelector('[data-area-status-text="' + areaId + '"]');
                    if (statusText) {
                        statusText.textContent = isComplete ? 'Bloque completado' : 'Bloque pendiente de cierre';
                        statusText.classList.remove('text-muted', 'text-success-emphasis');
                        statusText.classList.add(isComplete ? 'text-success-emphasis' : 'text-muted');
                    }

                    document.querySelectorAll('[data-area-main-form="' + areaId + '"]').forEach(function (formNode) {
                        setFormControlsDisabled(formNode, !canEditAreaForm);
                    });
                    document.querySelectorAll('[data-area-support-form="' + areaId + '"]').forEach(function (formNode) {
                        setFormControlsDisabled(formNode, !canEditSupportForm);
                    });
                    syncTitularesButtonsGlobal();

                    document.querySelectorAll('[data-area-save-wrap="' + areaId + '"]').forEach(function (wrapNode) {
                        if (!(wrapNode instanceof HTMLElement)) {
                            return;
                        }
                        wrapNode.classList.toggle('d-none', !canEditAreaForm);
                    });
                    document.querySelectorAll('[data-area-save-btn="' + areaId + '"]').forEach(function (saveBtn) {
                        if (!(saveBtn instanceof HTMLButtonElement)) {
                            return;
                        }
                        saveBtn.disabled = !canEditAreaForm;
                    });
                    document.querySelectorAll('[data-area-complete-form="' + areaId + '"]').forEach(function (completeForm) {
                        if (!(completeForm instanceof HTMLElement)) {
                            return;
                        }
                        completeForm.classList.toggle('d-none', !canEditAreaForm);
                    });

                    var completeBtn = document.querySelector('[data-area-complete-btn="' + areaId + '"]');
                    if (completeBtn) {
                        completeBtn.setAttribute('data-area-complete-locked', isComplete ? '1' : '0');
                        completeBtn.classList.remove('btn-success', 'btn-outline-success');
                        completeBtn.classList.add(isComplete ? 'btn-success' : 'btn-outline-success');
                        completeBtn.disabled = isComplete || !canEditAreaForm;
                        completeBtn.innerHTML = (isComplete
                            ? '<i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Área completada'
                            : '<i class="bi bi-check-circle me-1" aria-hidden="true"></i>Marcar completa');
                    }

                    updateArea(String(areaId));
                }

                document.body.addEventListener('ct-solicitudes-area-status', function (event) {
                    applyAreaStatusUpdate(event && event.detail ? event.detail : {});
                    syncAreaCommentsHeight();
                });

                document.body.addEventListener('htmx:afterSettle', function () {
                    syncAreaCommentsHeight();
                });
                document.body.addEventListener('shown.bs.collapse', function () {
                    syncAreaCommentsHeight();
                });
                document.body.addEventListener('hidden.bs.collapse', function () {
                    syncAreaCommentsHeight();
                });
                window.addEventListener('resize', function () {
                    syncAreaCommentsHeight();
                });
                syncAreaCommentsHeight();
            })();
            </script>
    </section>
</section>
