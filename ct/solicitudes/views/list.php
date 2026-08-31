<?php
declare(strict_types=1);

if (!function_exists('ctSolicitudesViewDate')) {
    function ctSolicitudesViewDate(?string $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '-';
        }
        $date = substr($raw, 0, 10);
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dt instanceof DateTimeImmutable ? $dt->format('d-m-Y') : $raw;
    }
}

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

if (!function_exists('ctSolicitudesViewPillClass')) {
    function ctSolicitudesViewPillClass(string $codigo): string
    {
        return match (strtoupper(trim($codigo))) {
            'BORRADOR' => 'ct-crud-pill-muted',
            'EN_REVISION' => 'ct-crud-pill-primary',
            'CON_OBSERVACIONES' => 'ct-crud-pill-warning',
            'LISTA_PARA_APROBAR' => 'ct-crud-pill-info',
            'APROBADA' => 'ct-crud-pill-success',
            'ANULADA' => 'ct-crud-pill-danger',
            default => 'ct-crud-pill-muted',
        };
    }
}

if (!function_exists('ctSolicitudesViewStageKey')) {
    function ctSolicitudesViewStageKey(string $codigo): string
    {
        return match (strtoupper(trim($codigo))) {
            'BORRADOR', 'CON_OBSERVACIONES' => 'pendiente',
            'EN_REVISION', 'LISTA_PARA_APROBAR' => 'en_progreso',
            'APROBADA', 'ANULADA' => 'cerrada',
            default => 'pendiente',
        };
    }
}

if (!function_exists('ctSolicitudesViewTipoPillClass')) {
    function ctSolicitudesViewTipoPillClass(string $codigo): string
    {
        return match (strtoupper(trim($codigo))) {
            'ADQUISICION' => 'ct-crud-pill-primary',
            'SUBDIVISION' => 'ct-crud-pill-info',
            'FUSION' => 'ct-crud-pill-success',
            'SANEAMIENTO', 'REGULARIZACION' => 'ct-crud-pill-warning',
            default => 'ct-crud-pill-muted',
        };
    }
}

if (!function_exists('ctSolicitudesViewTipoIcon')) {
    function ctSolicitudesViewTipoIcon(string $codigo): string
    {
        return match (strtoupper(trim($codigo))) {
            'ADQUISICION' => 'bi-building-add',
            'SUBDIVISION' => 'bi-diagram-3',
            'FUSION' => 'bi-intersect',
            'SANEAMIENTO', 'REGULARIZACION' => 'bi-shield-check',
            default => 'bi-tag',
        };
    }
}

$tipoOptions = [['value' => '', 'label' => 'Todos', 'search' => 'todos']];
foreach ($tipos as $tipo) {
    $tipoOptions[] = [
        'value' => (string) (int) ($tipo['id_tipo_solicitud'] ?? 0),
        'label' => (string) ($tipo['nombre'] ?? ''),
        'search' => strtolower((string) ($tipo['nombre'] ?? '')),
    ];
}

$estadoOptions = [['value' => '', 'label' => 'Todos', 'search' => 'todos']];
foreach ($estados as $estado) {
    $estadoOptions[] = [
        'value' => (string) (int) ($estado['id_estado_solicitud'] ?? 0),
        'label' => (string) ($estado['nombre'] ?? ''),
        'search' => strtolower((string) ($estado['nombre'] ?? '')),
    ];
}

$solicitanteOptions = [['value' => '', 'label' => 'Todos', 'search' => 'todos']];
foreach ($solicitantesFiltro as $solicitanteItem) {
    $solicitanteOptions[] = [
        'value' => (string) (int) ($solicitanteItem['id_usuario'] ?? 0),
        'label' => (string) ($solicitanteItem['nombre'] ?? ''),
        'search' => strtolower((string) ($solicitanteItem['nombre'] ?? '')),
    ];
}

$lineasOptions = [];
foreach ($state['lineasPermitidas'] as $linea) {
    $lineasOptions[] = ['value' => (string) $linea, 'label' => (string) $linea, 'search' => (string) $linea];
}

$tiposCreateOptions = [];
$idTipoDefaultCreate = 0;
$tipoCodigoById = [];
foreach ($tipos as $tipoCreate) {
    $idTipoCreate = (int) ($tipoCreate['id_tipo_solicitud'] ?? 0);
    if ($idTipoCreate <= 0) {
        continue;
    }
    $codigoTipoCreate = strtoupper(trim((string) ($tipoCreate['codigo'] ?? '')));
    $tipoCodigoById[$idTipoCreate] = $codigoTipoCreate;
    $isEnabledForCreate = $codigoTipoCreate !== 'MODIFICACION';
    if ($isEnabledForCreate && ($idTipoDefaultCreate <= 0 || $codigoTipoCreate === 'ADQUISICION')) {
        $idTipoDefaultCreate = $idTipoCreate;
    }
    $labelTipoCreate = trim((string) ($tipoCreate['nombre'] ?? '')) !== ''
        ? (string) $tipoCreate['nombre']
        : ('Tipo #' . $idTipoCreate);
    if (!$isEnabledForCreate) {
        $labelTipoCreate .= ' (Próximamente)';
    }
    $tiposCreateOptions[] = [
        'id' => $idTipoCreate,
        'label' => $labelTipoCreate,
        'enabled' => $isEnabledForCreate,
    ];
}
$hasTiposCreateOptions = $tiposCreateOptions !== [];

$tipoAreaAllowedByTipo = [];
$tipoAreaDefaultByTipo = [];
$tipoAreaAdquisicionByTipo = [];
foreach (($tipoAreaConfig ?? []) as $tipoAreaRow) {
    $idTipoCfg = (int) ($tipoAreaRow['id_tipo_solicitud'] ?? 0);
    $idAreaCfg = (int) ($tipoAreaRow['id_area_solicitud'] ?? 0);
    if ($idTipoCfg <= 0 || $idAreaCfg <= 0) {
        continue;
    }
    if (!isset($tipoAreaAllowedByTipo[$idTipoCfg])) {
        $tipoAreaAllowedByTipo[$idTipoCfg] = [];
    }
    $tipoAreaAllowedByTipo[$idTipoCfg][$idAreaCfg] = true;
    $tipoCodigoCfg = strtoupper(trim((string) ($tipoAreaRow['tipo_codigo'] ?? '')));
    $areaCodigoCfg = strtoupper(trim((string) ($tipoAreaRow['area_codigo'] ?? '')));
    if ($tipoCodigoCfg === 'ADQUISICION' && in_array($areaCodigoCfg, ['LEGAL', 'ARQUITECTURA'], true)) {
        if (!isset($tipoAreaAdquisicionByTipo[$idTipoCfg])) {
            $tipoAreaAdquisicionByTipo[$idTipoCfg] = [];
        }
        $tipoAreaAdquisicionByTipo[$idTipoCfg][$idAreaCfg] = true;
    }
    if (!empty($tipoAreaRow['habilita_automaticamente']) || !empty($tipoAreaRow['es_requerida'])) {
        if (!isset($tipoAreaDefaultByTipo[$idTipoCfg])) {
            $tipoAreaDefaultByTipo[$idTipoCfg] = [];
        }
        $tipoAreaDefaultByTipo[$idTipoCfg][$idAreaCfg] = true;
    }
}
$tipoAreaUiConfig = [];
foreach ($tiposCreateOptions as $tipoCreateOption) {
    $idTipoCfg = (int) ($tipoCreateOption['id'] ?? 0);
    if ($idTipoCfg <= 0) {
        continue;
    }
    $allowedIds = isset($tipoAreaAllowedByTipo[$idTipoCfg]) ? array_map('intval', array_keys($tipoAreaAllowedByTipo[$idTipoCfg])) : [];
    $defaultIds = isset($tipoAreaDefaultByTipo[$idTipoCfg]) ? array_map('intval', array_keys($tipoAreaDefaultByTipo[$idTipoCfg])) : [];
    $strictAllowed = false;
    if (($tipoCodigoById[$idTipoCfg] ?? '') === 'ADQUISICION') {
        $strictAllowed = true;
        $adqIds = isset($tipoAreaAdquisicionByTipo[$idTipoCfg]) ? array_map('intval', array_keys($tipoAreaAdquisicionByTipo[$idTipoCfg])) : [];
        $allowedIds = $adqIds;
        $defaultIds = $adqIds;
    }
    $tipoAreaUiConfig[(string) $idTipoCfg] = [
        'allowed' => $allowedIds,
        'defaults' => $defaultIds,
        'strictAllowed' => $strictAllowed,
        'participantsDefaults' => [],
    ];
    $defaultsParticipantesTipo = $tipoAreaParticipanteDefaults[$idTipoCfg] ?? [];
    if (is_array($defaultsParticipantesTipo)) {
        foreach ($defaultsParticipantesTipo as $idAreaDefault => $defaultConfigArea) {
            $idAreaDefault = (int) $idAreaDefault;
            if ($idAreaDefault <= 0 || !is_array($defaultConfigArea)) {
                continue;
            }
            $tipoAreaUiConfig[(string) $idTipoCfg]['participantsDefaults'][(string) $idAreaDefault] = [
                'participants' => array_map('intval', (array) ($defaultConfigArea['participants'] ?? [])),
                'responsable' => (int) ($defaultConfigArea['responsable'] ?? 0),
            ];
        }
    }
}

$comunaOptions = [];
foreach ($comunas as $comuna) {
    $comunaOptions[] = [
        'value' => (string) (int) ($comuna['id_comuna'] ?? 0),
        'label' => (string) ($comuna['nombre'] ?? ''),
        'search' => strtolower((string) ($comuna['nombre'] ?? '')),
    ];
}

$tipoInmuebleOptions = [];
foreach ($tiposInmueble as $tipoInmueble) {
    $tipoInmuebleOptions[] = [
        'value' => (string) (int) ($tipoInmueble['id_tipo_inmueble'] ?? 0),
        'label' => (string) ($tipoInmueble['nombre'] ?? ''),
        'search' => strtolower((string) ($tipoInmueble['nombre'] ?? '')),
    ];
}

$participanteSelectOptions = [];
foreach ($participantesCatalog as $participanteCatalog) {
    $idParticipante = (int) ($participanteCatalog['id_participante_solicitud'] ?? 0);
    if ($idParticipante <= 0) {
        continue;
    }
    $label = (string) ($participanteCatalog['nombre'] ?? '');
    $email = trim((string) ($participanteCatalog['email'] ?? ''));
    if ($email !== '') {
        $label .= ' (' . $email . ')';
    }
    $participanteSelectOptions[] = [
        'value' => (string) $idParticipante,
        'label' => $label,
        'search' => strtolower($label),
    ];
}

$participanteMultiOptions = [];
foreach ($participanteSelectOptions as $opt) {
    $participanteMultiOptions[] = [
        'code' => (string) $opt['value'],
        'label' => (string) $opt['label'],
        'search' => (string) $opt['search'],
    ];
}

$solicitudesTableRows = [];
$fichaBaseHref = ctUrl('solicitudes/ficha.php');
foreach ($rows as $row) {
    $idSolicitudRow = (int) ($row['id_solicitud'] ?? 0);
    $idSolicitanteRow = (int) ($row['id_solicitante'] ?? 0);
    $areasCount = (int) ($row['areas_count'] ?? 0);
    $areasCompletas = (int) ($row['areas_completas_count'] ?? 0);
    $resumen = trim((string) ($row['resumen'] ?? ''));
    $rolAsignado = trim((string) ($row['rol_asignado'] ?? ''));
    $identificacion = trim((string) ($row['identificacion_propiedad'] ?? ''));

    $row['__id_label'] = '#' . $idSolicitudRow;
    $row['__is_active'] = false;
    $row['__solicitante_nombre'] = $solicitanteMap[$idSolicitanteRow] ?? ('Usuario #' . $idSolicitanteRow);
    $row['__resumen'] = $resumen;
    $row['__tipo_codigo'] = (string) ($row['tipo_codigo'] ?? '');
    $row['__tipo_nombre'] = (string) ($row['tipo_nombre'] ?? '');
    $row['__tipo_pill_class'] = ctSolicitudesViewTipoPillClass((string) ($row['tipo_codigo'] ?? ''));
    $row['__tipo_icon'] = ctSolicitudesViewTipoIcon((string) ($row['tipo_codigo'] ?? ''));
    $row['__estado_nombre'] = (string) ($row['estado_nombre'] ?? '');
    $row['__estado_pill_class'] = ctSolicitudesViewPillClass((string) ($row['estado_codigo'] ?? ''));
    $row['__rol_asignado'] = $rolAsignado;
    $row['__identificacion_propiedad'] = $identificacion;
    $row['__areas_count'] = $areasCount;
    $row['__areas_completas'] = $areasCompletas;
    $row['__fecha_actualizacion_label'] = ctSolicitudesViewDatetime((string) ($row['fecha_actualizacion'] ?? ''));
    $row['__href'] = $fichaBaseHref . ctSolicitudesBuildQuery($state['queryBase'], [
        'id' => $idSolicitudRow,
        'pagina' => $paginaActual,
    ]);

    $solicitudesTableRows[] = $row;
}

$solicitudesSummaryHtml = 'Total: <strong>' . number_format((int) $totalRegistros, 0, ',', '.') . '</strong>'
    . ' | Página <strong>' . (int) $paginaActual . '</strong> de <strong>' . (int) $totalPaginas . '</strong>';

$solicitudesPaginationHtml = '';
if ($totalPaginas > 1) {
    ob_start();
    ?>
    <nav aria-label="Paginación de solicitudes">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo ctEscape(ctSolicitudesBuildQuery($state['queryBase'], ['pagina' => max(1, $paginaActual - 1)])); ?>" aria-label="Anterior">&laquo;</a>
            </li>
            <?php foreach ($paginationItems as $item): ?>
                <?php if (($item['page'] ?? null) === null): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php else: ?>
                    <li class="page-item <?php echo !empty($item['active']) ? 'active' : ''; ?>">
                        <a class="page-link" href="<?php echo ctEscape(ctSolicitudesBuildQuery($state['queryBase'], ['pagina' => $item['page']])); ?>"><?php echo ctEscape((string) $item['label']); ?></a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
            <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo ctEscape(ctSolicitudesBuildQuery($state['queryBase'], ['pagina' => min($totalPaginas, $paginaActual + 1)])); ?>" aria-label="Siguiente">&raquo;</a>
            </li>
        </ul>
    </nav>
    <?php
    $solicitudesPaginationHtml = (string) ob_get_clean();
}

$solicitudesKanbanColumns = [
    'pendiente' => [
        'title' => 'Pendientes',
        'hint' => 'Borradores y solicitudes con observaciones por completar.',
        'items' => [],
    ],
    'en_progreso' => [
        'title' => 'En progreso',
        'hint' => 'Solicitudes en revisión o listas para aprobación.',
        'items' => [],
    ],
];
$solicitudesCompletedRows = [];
foreach ($solicitudesTableRows as $row) {
    $stageKey = ctSolicitudesViewStageKey((string) ($row['estado_codigo'] ?? ''));
    if ($stageKey === 'cerrada') {
        $solicitudesCompletedRows[] = $row;
        continue;
    }
    if (isset($solicitudesKanbanColumns[$stageKey])) {
        $solicitudesKanbanColumns[$stageKey]['items'][] = $row;
    }
}
$solicitudesKanbanTotal = 0;
foreach ($solicitudesKanbanColumns as $column) {
    $solicitudesKanbanTotal += count($column['items']);
}

$solicitudesSummaryHtml = 'Cerradas en esta página: <strong>' . number_format(count($solicitudesCompletedRows), 0, ',', '.') . '</strong>'
    . ' | Total filtrado: <strong>' . number_format((int) $totalRegistros, 0, ',', '.') . '</strong>'
    . ' | Página <strong>' . (int) $paginaActual . '</strong> de <strong>' . (int) $totalPaginas . '</strong>';

$solicitudesFiltersConfig = [
    'form_attrs' => [
        'class' => 'ct-solicitudes-filtros ct-crud-filters row g-2 align-items-end mb-3',
        'method' => 'get',
        'id' => 'ct-solicitudes-filtros-form',
    ],
    'hidden' => $state['idSolicitud'] > 0 ? ['id' => $state['idSolicitud']] : [],
    'fields' => [
        [
            'type' => 'input',
            'wrapper_class' => 'col-12 col-lg-4',
            'label' => 'Texto libre',
            'name' => 'filtroTexto',
            'id' => 'ct-solicitudes-filtro-texto',
            'value' => $state['filtroTexto'],
            'placeholder' => 'ID, resumen, rol, identificación...',
        ],
        [
            'type' => 'custom',
            'render' => static function () use ($estadoOptions, $state): void {
                ctRenderSearchableSelectField([
                    'wrapper_class' => 'col-12 col-sm-6 col-lg-2',
                    'label' => 'Estado',
                    'input_name' => 'filtroEstado',
                    'input_id' => 'ct-solicitudes-filtro-estado',
                    'picker_id' => 'ct-solicitudes-filtro-estado-picker',
                    'button_id' => 'ct-solicitudes-filtro-estado-btn',
                    'filter_id' => 'ct-solicitudes-filtro-estado-filter',
                    'list_id' => 'ct-solicitudes-filtro-estado-list',
                    'error_id' => 'ct-solicitudes-filtro-estado-error',
                    'error_message' => 'Debes seleccionar un estado.',
                    'button_placeholder' => 'Todos',
                    'filter_placeholder' => 'Buscar estado...',
                    'value' => $state['filtroEstado'],
                    'options' => $estadoOptions,
                ]);
            },
        ],
        [
            'type' => 'custom',
            'render' => static function () use ($tipoOptions, $state): void {
                ctRenderSearchableSelectField([
                    'wrapper_class' => 'col-12 col-sm-6 col-lg-2',
                    'label' => 'Tipo',
                    'input_name' => 'filtroTipo',
                    'input_id' => 'ct-solicitudes-filtro-tipo',
                    'picker_id' => 'ct-solicitudes-filtro-tipo-picker',
                    'button_id' => 'ct-solicitudes-filtro-tipo-btn',
                    'filter_id' => 'ct-solicitudes-filtro-tipo-filter',
                    'list_id' => 'ct-solicitudes-filtro-tipo-list',
                    'error_id' => 'ct-solicitudes-filtro-tipo-error',
                    'error_message' => 'Debes seleccionar un tipo.',
                    'button_placeholder' => 'Todos',
                    'filter_placeholder' => 'Buscar tipo...',
                    'value' => $state['filtroTipo'],
                    'options' => $tipoOptions,
                ]);
            },
        ],
        [
            'type' => 'custom',
            'render' => static function () use ($solicitanteOptions, $state): void {
                ctRenderSearchableSelectField([
                    'wrapper_class' => 'col-12 col-sm-6 col-lg-2',
                    'label' => 'Solicitante',
                    'input_name' => 'filtroSolicitante',
                    'input_id' => 'ct-solicitudes-filtro-solicitante',
                    'picker_id' => 'ct-solicitudes-filtro-solicitante-picker',
                    'button_id' => 'ct-solicitudes-filtro-solicitante-btn',
                    'filter_id' => 'ct-solicitudes-filtro-solicitante-filter',
                    'list_id' => 'ct-solicitudes-filtro-solicitante-list',
                    'error_id' => 'ct-solicitudes-filtro-solicitante-error',
                    'error_message' => 'Debes seleccionar un solicitante.',
                    'button_placeholder' => 'Todos',
                    'filter_placeholder' => 'Buscar solicitante...',
                    'value' => $state['filtroSolicitante'],
                    'options' => $solicitanteOptions,
                ]);
            },
        ],
        [
            'type' => 'custom',
            'render' => static function () use ($lineasOptions, $state): void {
                ctRenderSearchableSelectField([
                    'wrapper_class' => 'col-12 col-sm-6 col-lg-1',
                    'label' => 'Líneas',
                    'input_name' => 'lineas',
                    'input_id' => 'ct-solicitudes-lineas',
                    'picker_id' => 'ct-solicitudes-lineas-picker',
                    'button_id' => 'ct-solicitudes-lineas-btn',
                    'filter_id' => 'ct-solicitudes-lineas-filter',
                    'list_id' => 'ct-solicitudes-lineas-list',
                    'error_id' => 'ct-solicitudes-lineas-error',
                    'error_message' => 'Debes seleccionar líneas.',
                    'button_placeholder' => 'Líneas',
                    'filter_placeholder' => 'Buscar líneas...',
                    'value' => (string) $state['lineas'],
                    'options' => $lineasOptions,
                ]);
            },
        ],
    ],
    'actions' => [
        'wrapper_class' => 'col-12 col-lg-1',
        'inner_class' => 'd-grid gap-2',
        'items' => [
            [
                'type' => 'submit',
                'class' => 'btn btn-outline-primary ct-crud-filter-submit',
                'icon' => 'bi bi-funnel',
                'label' => '',
                'attrs' => [
                    'aria-label' => 'Filtrar solicitudes',
                    'title' => 'Filtrar solicitudes',
                ],
            ],
        ],
    ],
];
?>
<style>
#ct-solicitud-preview-offcanvas {
    --bs-offcanvas-width: min(92vw, 460px);
}

.ct-solicitudes-btn-create {
    white-space: nowrap;
}

[data-create-area-card] {
    cursor: pointer;
}
</style>
<section class="mt-3 ct-theme-enterprise">
    <?php
    gpRenderSectionHeader([
        'kicker' => 'CT / Solicitudes',
        'title' => 'Solicitudes',
        'description' => 'Borrador, coordinación por áreas y aprobación final con materialización en Terrenos.',
        'back_url' => ctUrl('ct_menu.php'),
        'back_label' => 'Volver al menú CT',
        'help_text' => 'Usa el kanban para solicitudes en trabajo y el histórico para cerradas.',
    ]);
    ?>

    <div class="row g-3">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <h2 class="h6 mb-0">Bandeja</h2>
                            <div class="small text-muted">Gestiona solicitudes en flujo y revisa el histórico cerrado.</div>
                        </div>
                        <?php if (!empty($canCreateSolicitud)): ?>
                            <button type="button" class="btn btn-primary ct-solicitudes-btn-create" data-bs-toggle="modal" data-bs-target="#ct-modal-crear-solicitud">
                                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Crear solicitud
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($canCreateSolicitud)): ?>
                        <div class="alert alert-secondary py-2 px-3 mb-3 small">
                            Solo usuarios de <strong>Gerencia General</strong> pueden crear solicitudes.
                        </div>
                    <?php endif; ?>
                    <?php
                    ctCrudRenderFilters($solicitudesFiltersConfig);
                    ?>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="small text-muted">
                            En flujo (kanban): <strong><?php echo number_format($solicitudesKanbanTotal, 0, ',', '.'); ?></strong>
                        </div>
                        <div class="small text-muted">
                            Cerradas: <strong><?php echo number_format(count($solicitudesCompletedRows), 0, ',', '.'); ?></strong>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <?php foreach ($solicitudesKanbanColumns as $kanban): ?>
                            <div class="col-12 col-lg-6">
                                <div class="ct-crud-table-shell h-100">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h3 class="h6 mb-1"><?php echo ctEscape((string) ($kanban['title'] ?? '')); ?></h3>
                                            <div class="small text-muted"><?php echo ctEscape((string) ($kanban['hint'] ?? '')); ?></div>
                                        </div>
                                        <span class="ct-crud-pill ct-crud-pill-info"><?php echo number_format(count($kanban['items'] ?? []), 0, ',', '.'); ?></span>
                                    </div>
                                    <?php if (($kanban['items'] ?? []) === []): ?>
                                        <div class="small text-muted py-2">Sin solicitudes en esta etapa.</div>
                                    <?php else: ?>
                                        <div class="d-grid gap-2">
                                            <?php foreach (($kanban['items'] ?? []) as $item): ?>
                                                <?php
                                                $isActive = !empty($item['__is_active']);
                                                $idSolicitudCard = (string) ($item['__id_label'] ?? '');
                                                $tipoNombreCard = trim((string) ($item['__tipo_nombre'] ?? ''));
                                                $tipoPillClassCard = (string) ($item['__tipo_pill_class'] ?? 'ct-crud-pill-muted');
                                                $tipoIconCard = (string) ($item['__tipo_icon'] ?? 'bi-tag');
                                                $estadoCodigoCard = (string) ($item['estado_codigo'] ?? '');
                                                $estadoNombreCard = (string) ($item['estado_nombre'] ?? '');
                                                $solicitanteCard = (string) ($item['__solicitante_nombre'] ?? '');
                                                $resumenCard = trim((string) ($item['__resumen'] ?? ''));
                                                $rolCard = trim((string) ($item['__rol_asignado'] ?? ''));
                                                $identificacionCard = trim((string) ($item['__identificacion_propiedad'] ?? ''));
                                                $areasCompletasCard = (int) ($item['__areas_completas'] ?? 0);
                                                $areasTotalCard = (int) ($item['__areas_count'] ?? 0);
                                                $areasClassCard = $areasTotalCard > 0 && $areasCompletasCard >= $areasTotalCard
                                                    ? 'ct-crud-pill-success'
                                                    : 'ct-crud-pill-warning';
                                                ?>
                                                <article class="border rounded p-2 <?php echo $isActive ? 'border-primary bg-primary-subtle' : 'bg-white'; ?>">
                                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                                            <div class="fw-semibold"><?php echo ctEscape($idSolicitudCard); ?></div>
                                                            <span class="ct-crud-pill <?php echo ctEscape($tipoPillClassCard); ?>">
                                                                <i class="bi <?php echo ctEscape($tipoIconCard); ?> me-1" aria-hidden="true"></i><?php echo ctEscape($tipoNombreCard !== '' ? $tipoNombreCard : 'Sin tipo'); ?>
                                                            </span>
                                                        </div>
                                                        <span class="ct-crud-pill <?php echo ctEscape(ctSolicitudesViewPillClass($estadoCodigoCard)); ?>"><?php echo ctEscape($estadoNombreCard); ?></span>
                                                    </div>
                                                    <div class="small fw-semibold mb-1"><?php echo ctEscape($solicitanteCard); ?></div>
                                                    <div class="small text-muted mb-2"><?php echo ctEscape($resumenCard !== '' ? $resumenCard : 'Sin resumen'); ?></div>
                                                    <div class="d-flex flex-wrap gap-2 mb-2">
                                                        <span class="ct-crud-pill ct-crud-pill-muted"><?php echo ctEscape($rolCard !== '' ? $rolCard : 'Sin rol'); ?></span>
                                                        <span class="ct-crud-pill ct-crud-pill-muted"><?php echo ctEscape($identificacionCard !== '' ? $identificacionCard : 'Sin identificación'); ?></span>
                                                        <span class="ct-crud-pill <?php echo $areasClassCard; ?>"><?php echo ctEscape((string) $areasCompletasCard); ?>/<?php echo ctEscape((string) $areasTotalCard); ?> áreas</span>
                                                    </div>
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div class="small text-muted"><?php echo ctEscape((string) ($item['__fecha_actualizacion_label'] ?? '-')); ?></div>
                                                        <a
                                                            class="btn btn-sm <?php echo $isActive ? 'btn-primary' : 'btn-outline-primary'; ?>"
                                                            href="<?php echo ctEscape((string) ($item['__href'] ?? '')); ?>"
                                                            data-ct-solicitud-preview="1"
                                                            data-ct-id="<?php echo ctEscape((string) ($item['id_solicitud'] ?? 0)); ?>"
                                                            data-ct-tipo="<?php echo ctEscape((string) ($item['__tipo_nombre'] ?? '')); ?>"
                                                            data-ct-tipo-code="<?php echo ctEscape((string) ($item['__tipo_codigo'] ?? '')); ?>"
                                                            data-ct-tipo-class="<?php echo ctEscape((string) ($item['__tipo_pill_class'] ?? 'ct-crud-pill-muted')); ?>"
                                                            data-ct-tipo-icon="<?php echo ctEscape((string) ($item['__tipo_icon'] ?? 'bi-tag')); ?>"
                                                            data-ct-estado="<?php echo ctEscape((string) ($item['__estado_nombre'] ?? '')); ?>"
                                                            data-ct-estado-class="<?php echo ctEscape((string) ($item['__estado_pill_class'] ?? 'ct-crud-pill-muted')); ?>"
                                                            data-ct-solicitante="<?php echo ctEscape((string) ($item['__solicitante_nombre'] ?? '')); ?>"
                                                            data-ct-resumen="<?php echo ctEscape((string) ($item['__resumen'] ?? '')); ?>"
                                                            data-ct-rol="<?php echo ctEscape((string) ($item['__rol_asignado'] ?? '')); ?>"
                                                            data-ct-identificacion="<?php echo ctEscape((string) ($item['__identificacion_propiedad'] ?? '')); ?>"
                                                            data-ct-areas-completas="<?php echo ctEscape((string) ($item['__areas_completas'] ?? 0)); ?>"
                                                            data-ct-areas-total="<?php echo ctEscape((string) ($item['__areas_count'] ?? 0)); ?>"
                                                            data-ct-actualizacion="<?php echo ctEscape((string) ($item['__fecha_actualizacion_label'] ?? '-')); ?>"
                                                            data-ct-ficha-url="<?php echo ctEscape((string) ($item['__href'] ?? '')); ?>">
                                                            Ficha
                                                        </a>
                                                    </div>
                                                </article>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <h3 class="h6 mb-2">Histórico cerradas</h3>
                    <?php
                    ctRenderCrudTable([
                        'table_wrap_class' => 'table-responsive ct-crud-table-wrap',
                        'table_class' => 'table table-sm align-middle mb-0 ct-solicitudes-table ct-crud-table',
                        'row_class' => static fn (array $row): string => !empty($row['__is_active']) ? 'table-primary' : '',
                        'columns' => [
                            [
                                'key' => '__id_label',
                                'label' => 'ID',
                                'cell_class' => 'text-nowrap',
                                'render' => static fn (array $row): string => '<span class="fw-semibold">' . ctEscape((string) $row['__id_label']) . '</span>',
                                'escape' => false,
                            ],
                            [
                                'key' => 'estado_nombre',
                                'label' => 'Estado',
                                'render' => static function (array $row): string {
                                    $codigo = (string) ($row['estado_codigo'] ?? '');
                                    $nombre = (string) ($row['estado_nombre'] ?? '');
                                    return '<span class="ct-crud-pill ' . ctEscape(ctSolicitudesViewPillClass($codigo)) . '">' . ctEscape($nombre) . '</span>';
                                },
                                'escape' => false,
                            ],
                            [
                                'key' => '__tipo_nombre',
                                'label' => 'Tipo',
                                'render' => static function (array $row): string {
                                    $tipoNombre = trim((string) ($row['__tipo_nombre'] ?? ''));
                                    $tipoClass = (string) ($row['__tipo_pill_class'] ?? 'ct-crud-pill-muted');
                                    $tipoIcon = (string) ($row['__tipo_icon'] ?? 'bi-tag');
                                    return '<span class="ct-crud-pill ' . ctEscape($tipoClass) . '"><i class="bi '
                                        . ctEscape($tipoIcon) . ' me-1" aria-hidden="true"></i>'
                                        . ctEscape($tipoNombre !== '' ? $tipoNombre : 'Sin tipo') . '</span>';
                                },
                                'escape' => false,
                            ],
                            [
                                'key' => '__solicitante_nombre',
                                'label' => 'Solicitante',
                                'render' => static fn (array $row): string => '<span class="fw-semibold">' . ctEscape((string) $row['__solicitante_nombre']) . '</span>',
                                'escape' => false,
                            ],
                            [
                                'key' => '__resumen',
                                'label' => 'Resumen',
                                'render' => static function (array $row): string {
                                    $resumen = trim((string) ($row['__resumen'] ?? ''));
                                    if ($resumen === '') {
                                        return '<span class="text-muted">Sin resumen</span>';
                                    }
                                    return ctEscape($resumen);
                                },
                                'escape' => false,
                            ],
                            [
                                'key' => '__fecha_actualizacion_label',
                                'label' => 'Actualización',
                                'cell_class' => 'text-nowrap',
                            ],
                            [
                                'key' => '__href',
                                'label' => 'Abrir',
                                'header_class' => 'text-end',
                                'cell_class' => 'text-end',
                                'render' => static function (array $row): string {
                                    $isActive = !empty($row['__is_active']);
                                    $class = $isActive ? 'btn-primary' : 'btn-outline-primary';
                                    return '<a class="btn btn-sm ' . $class . '"'
                                        . ' href="' . ctEscape((string) $row['__href']) . '"'
                                        . ' data-ct-solicitud-preview="1"'
                                        . ' data-ct-id="' . ctEscape((string) ($row['id_solicitud'] ?? 0)) . '"'
                                        . ' data-ct-tipo="' . ctEscape((string) ($row['__tipo_nombre'] ?? '')) . '"'
                                        . ' data-ct-tipo-code="' . ctEscape((string) ($row['__tipo_codigo'] ?? '')) . '"'
                                        . ' data-ct-tipo-class="' . ctEscape((string) ($row['__tipo_pill_class'] ?? 'ct-crud-pill-muted')) . '"'
                                        . ' data-ct-tipo-icon="' . ctEscape((string) ($row['__tipo_icon'] ?? 'bi-tag')) . '"'
                                        . ' data-ct-estado="' . ctEscape((string) ($row['__estado_nombre'] ?? '')) . '"'
                                        . ' data-ct-estado-class="' . ctEscape((string) ($row['__estado_pill_class'] ?? 'ct-crud-pill-muted')) . '"'
                                        . ' data-ct-solicitante="' . ctEscape((string) ($row['__solicitante_nombre'] ?? '')) . '"'
                                        . ' data-ct-resumen="' . ctEscape((string) ($row['__resumen'] ?? '')) . '"'
                                        . ' data-ct-rol="' . ctEscape((string) ($row['__rol_asignado'] ?? '')) . '"'
                                        . ' data-ct-identificacion="' . ctEscape((string) ($row['__identificacion_propiedad'] ?? '')) . '"'
                                        . ' data-ct-areas-completas="' . ctEscape((string) ($row['__areas_completas'] ?? 0)) . '"'
                                        . ' data-ct-areas-total="' . ctEscape((string) ($row['__areas_count'] ?? 0)) . '"'
                                        . ' data-ct-actualizacion="' . ctEscape((string) ($row['__fecha_actualizacion_label'] ?? '-')) . '"'
                                        . ' data-ct-ficha-url="' . ctEscape((string) $row['__href']) . '">'
                                        . '<i class="bi bi-layout-text-sidebar-reverse me-1" aria-hidden="true"></i>Ficha</a>';
                                },
                                'escape' => false,
                            ],
                        ],
                        'rows' => $solicitudesCompletedRows,
                        'empty_text' => 'Sin solicitudes cerradas en esta página.',
                        'meta' => [
                            'left_html' => $solicitudesSummaryHtml,
                            'right_html' => $solicitudesPaginationHtml,
                        ],
                    ]);
                    ?>
                </div>
            </div>
        </div>
    </div>

    <div class="offcanvas offcanvas-end" tabindex="-1" id="ct-solicitud-preview-offcanvas" aria-labelledby="ct-solicitud-preview-title">
        <div class="offcanvas-header border-bottom">
            <div>
                <div class="small text-muted">Solicitud</div>
                <h2 class="offcanvas-title h5 mb-0" id="ct-solicitud-preview-title">#-</h2>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column gap-3">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span id="ct-solicitud-preview-estado" class="ct-crud-pill ct-crud-pill-muted">Sin estado</span>
            </div>
            <div class="border rounded p-3">
                <div class="small text-muted mb-1">Tipo de transacción</div>
                <div>
                    <span id="ct-solicitud-preview-tipo" class="ct-crud-pill ct-crud-pill-info">
                        <i id="ct-solicitud-preview-tipo-icon" class="bi bi-tag me-1" aria-hidden="true"></i>
                        <span id="ct-solicitud-preview-tipo-text">Sin tipo</span>
                    </span>
                </div>
                <div class="small text-muted mt-2" id="ct-solicitud-preview-tipo-code">-</div>
            </div>
            <div>
                <div class="small text-muted">Solicitante</div>
                <div class="fw-semibold" id="ct-solicitud-preview-solicitante">-</div>
            </div>
            <div>
                <div class="small text-muted">Resumen</div>
                <div id="ct-solicitud-preview-resumen">Sin resumen</div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">Rol</div>
                        <div class="fw-semibold" id="ct-solicitud-preview-rol">Sin rol</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">Identificación</div>
                        <div class="fw-semibold" id="ct-solicitud-preview-identificacion">Sin identificación</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">Áreas completas</div>
                        <div class="fw-semibold" id="ct-solicitud-preview-areas">0/0</div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">Actualización</div>
                        <div class="fw-semibold" id="ct-solicitud-preview-actualizacion">-</div>
                    </div>
                </div>
            </div>
            <a href="#" id="ct-solicitud-preview-open-link" class="btn btn-primary mt-auto">
                <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Ir a ficha completa
            </a>
        </div>
    </div>

    <?php if (!empty($canCreateSolicitud)): ?>
    <div class="modal fade ct-crud-modal" id="ct-modal-crear-solicitud" tabindex="-1" aria-labelledby="ct-modal-crear-solicitud-title" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <form method="post" id="ct-form-crear-solicitud" class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title h5 mb-0" id="ct-modal-crear-solicitud-title">Nueva solicitud de transacción</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="crear_solicitud">
                    <div class="row g-3">
                        <div class="col-12">
                            <?php ctRenderFieldLabel('Tipo de transacción', true); ?>
                            <select class="form-select" name="id_tipo_solicitud" id="ct-create-tipo-solicitud" required>
                                <?php if (!$hasTiposCreateOptions): ?>
                                    <option value="">Sin tipos disponibles</option>
                                <?php else: ?>
                                    <?php foreach ($tiposCreateOptions as $tipoCreateOption): ?>
                                        <?php
                                        $tipoCreateEnabled = array_key_exists('enabled', $tipoCreateOption)
                                            ? !empty($tipoCreateOption['enabled'])
                                            : true;
                                        ?>
                                        <option
                                            value="<?php echo (int) ($tipoCreateOption['id'] ?? 0); ?>"
                                            <?php echo ((int) ($tipoCreateOption['id'] ?? 0) === $idTipoDefaultCreate) ? ' selected' : ''; ?>
                                            <?php echo $tipoCreateEnabled ? '' : ' disabled'; ?>
                                        >
                                            <?php echo ctEscape((string) ($tipoCreateOption['label'] ?? 'Tipo')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <?php ctRenderFieldLabel('Resumen', false); ?>
                            <textarea name="resumen" class="form-control" rows="3" maxlength="500" placeholder="Contexto breve, objetivo o restricción inicial..."></textarea>
                        </div>
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <div class="fw-semibold">Áreas participantes</div>
                                    <div class="small text-muted">Selecciona áreas y define al menos un participante por cada una.</div>
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <?php foreach ($areasCreate as $area): ?>
                                    <?php
                                    $idAreaModal = (int) ($area['id_area_solicitud'] ?? 0);
                                    if ($idAreaModal <= 0) {
                                        continue;
                                    }
                                    $areaNombreModal = trim((string) ($area['nombre'] ?? ''));
                                    $areaCodigoModal = trim((string) ($area['codigo'] ?? ''));
                                    $participantesAreaModal = $participantesByAreaCreate[$idAreaModal] ?? [];
                                    $areaHasParticipantes = is_array($participantesAreaModal) && $participantesAreaModal !== [];
                                    ?>
                                    <article class="border rounded p-3 bg-white" data-create-area-card="<?php echo $idAreaModal; ?>" data-create-area-has-participants="<?php echo $areaHasParticipantes ? '1' : '0'; ?>">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" value="1" name="area_enabled[<?php echo $idAreaModal; ?>]" id="ct-create-area-enable-<?php echo $idAreaModal; ?>" data-create-area-toggle="<?php echo $idAreaModal; ?>"<?php echo !$areaHasParticipantes ? ' disabled' : ''; ?>>
                                            <label class="form-check-label fw-semibold" for="ct-create-area-enable-<?php echo $idAreaModal; ?>">
                                                <?php echo ctEscape($areaNombreModal !== '' ? $areaNombreModal : ('Área #' . $idAreaModal)); ?>
                                            </label>
                                            <?php if ($areaCodigoModal !== ''): ?>
                                                <span class="small text-muted ms-2"><?php echo ctEscape($areaCodigoModal); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!$areaHasParticipantes): ?>
                                            <div class="small text-muted mt-2">Sin participantes asociados en este departamento.</div>
                                        <?php endif; ?>
                                        <div class="mt-3 ps-1 collapse" id="ct-create-area-details-<?php echo $idAreaModal; ?>" data-create-area-details="<?php echo $idAreaModal; ?>">
                                            <div class="small text-muted mb-2">Participantes posibles</div>
                                            <div class="row g-2">
                                                <?php foreach ($participantesAreaModal as $participanteModal): ?>
                                                    <?php
                                                    $idParticipanteModal = (int) ($participanteModal['id_participante_solicitud'] ?? 0);
                                                    if ($idParticipanteModal <= 0) {
                                                        continue;
                                                    }
                                                    $participanteNombreModal = trim((string) ($participanteModal['nombre'] ?? ''));
                                                    $participanteEmailModal = trim((string) ($participanteModal['email'] ?? ''));
                                                    $participanteLabelModal = $participanteNombreModal !== '' ? $participanteNombreModal : ('Usuario #' . $idParticipanteModal);
                                                    if ($participanteEmailModal !== '') {
                                                        $participanteLabelModal .= ' (' . $participanteEmailModal . ')';
                                                    }
                                                    ?>
                                                    <div class="col-12 col-md-6">
                                                        <div class="border rounded p-2 h-100">
                                                            <div class="form-check">
                                                                <input class="form-check-input" type="checkbox" value="<?php echo $idParticipanteModal; ?>" name="area_participantes[<?php echo $idAreaModal; ?>][]" id="ct-create-area-<?php echo $idAreaModal; ?>-p-<?php echo $idParticipanteModal; ?>" data-create-area-participant="<?php echo $idAreaModal; ?>" disabled>
                                                                <label class="form-check-label" for="ct-create-area-<?php echo $idAreaModal; ?>-p-<?php echo $idParticipanteModal; ?>">
                                                                    <?php echo ctEscape($participanteLabelModal); ?>
                                                                </label>
                                                            </div>
                                                            <div class="form-check mt-1 ms-4">
                                                                <input class="form-check-input" type="radio" value="<?php echo $idParticipanteModal; ?>" name="area_responsable[<?php echo $idAreaModal; ?>]" id="ct-create-area-<?php echo $idAreaModal; ?>-r-<?php echo $idParticipanteModal; ?>" data-create-area-responsable="<?php echo $idAreaModal; ?>" disabled>
                                                                <label class="form-check-label small text-muted" for="ct-create-area-<?php echo $idAreaModal; ?>-r-<?php echo $idParticipanteModal; ?>">
                                                                    Responsable
                                                                </label>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <div class="alert alert-danger d-none mt-2 mb-0" role="alert" id="ct-create-modal-error"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"<?php echo !$hasTiposCreateOptions ? ' disabled' : ''; ?>>
                        <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Crear solicitud
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <script>
    (function () {
        var form = document.getElementById('ct-form-crear-solicitud');
        if (!form) {
            return;
        }

        var tipoSelect = document.getElementById('ct-create-tipo-solicitud');
        var errorBox = document.getElementById('ct-create-modal-error');
        var toggles = form.querySelectorAll('[data-create-area-toggle]');
        var tipoAreaConfig = <?php echo json_encode($tipoAreaUiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function inSet(value, list) {
            for (var i = 0; i < list.length; i++) {
                if (String(list[i]) === String(value)) {
                    return true;
                }
            }
            return false;
        }

        function participantSelector(areaId) {
            return '[data-create-area-participant="' + areaId + '"]';
        }

        function responsableSelector(areaId) {
            return '[data-create-area-responsable="' + areaId + '"]';
        }

        function detailsSelector(areaId) {
            return '[data-create-area-details="' + areaId + '"]';
        }

        function setCollapseState(details, expanded) {
            if (!details) {
                return;
            }
            if (window.bootstrap && window.bootstrap.Collapse) {
                var instance = window.bootstrap.Collapse.getOrCreateInstance(details, {toggle: false});
                if (expanded) {
                    instance.show();
                } else {
                    instance.hide();
                }
                return;
            }
            details.classList.toggle('show', expanded);
        }

        function syncArea(areaId) {
            var toggle = form.querySelector('[data-create-area-toggle="' + areaId + '"]');
            if (!toggle) {
                return;
            }
            var enabled = !!toggle.checked;
            var wasEnabled = toggle.getAttribute('data-create-area-enabled') === '1';
            toggle.setAttribute('data-create-area-enabled', enabled ? '1' : '0');
            var details = form.querySelector(detailsSelector(areaId));
            if (details) {
                if (!enabled) {
                    setCollapseState(details, false);
                } else if (!wasEnabled) {
                    setCollapseState(details, true);
                }
            }

            form.querySelectorAll(participantSelector(areaId)).forEach(function (checkbox) {
                checkbox.disabled = !enabled;
                if (!enabled) {
                    checkbox.checked = false;
                }
            });

            form.querySelectorAll(responsableSelector(areaId)).forEach(function (radio) {
                var participantId = radio.value;
                var checkbox = form.querySelector('#ct-create-area-' + areaId + '-p-' + participantId);
                var participantChecked = !!(checkbox && checkbox.checked && enabled);
                radio.disabled = !participantChecked;
                if (!participantChecked) {
                    radio.checked = false;
                }
            });
        }

        function ensureOneParticipant(areaId) {
            var checked = Array.prototype.slice.call(form.querySelectorAll(participantSelector(areaId))).filter(function (checkbox) {
                return checkbox.checked;
            });
            if (checked.length === 0) {
                var firstEnabled = form.querySelector(participantSelector(areaId) + ':not([disabled])');
                if (firstEnabled) {
                    firstEnabled.checked = true;
                    syncArea(areaId);
                }
            }
            var responsableSelected = Array.prototype.slice.call(form.querySelectorAll(responsableSelector(areaId))).some(function (radio) {
                return radio.checked;
            });
            if (!responsableSelected) {
                var firstParticipantChecked = form.querySelector(participantSelector(areaId) + ':checked');
                if (firstParticipantChecked) {
                    var defaultRadio = form.querySelector('#ct-create-area-' + areaId + '-r-' + firstParticipantChecked.value);
                    if (defaultRadio && !defaultRadio.disabled) {
                        defaultRadio.checked = true;
                    }
                }
            }
        }

        function applyAreaParticipantsFromDefaults(areaId, areaDefaults) {
            if (!areaDefaults || !Array.isArray(areaDefaults.participants)) {
                ensureOneParticipant(areaId);
                return;
            }

            var wanted = {};
            areaDefaults.participants.forEach(function (id) {
                wanted[String(id)] = true;
            });

            form.querySelectorAll(participantSelector(areaId)).forEach(function (checkbox) {
                if (checkbox.disabled) {
                    checkbox.checked = false;
                    return;
                }
                checkbox.checked = !!wanted[String(checkbox.value)];
            });

            syncArea(areaId);

            var checked = Array.prototype.slice.call(form.querySelectorAll(participantSelector(areaId))).filter(function (checkbox) {
                return checkbox.checked;
            });
            if (checked.length === 0) {
                ensureOneParticipant(areaId);
                return;
            }

            var responsable = String(areaDefaults.responsable || '').trim();
            if (responsable !== '') {
                var responsableRadio = form.querySelector('#ct-create-area-' + areaId + '-r-' + responsable);
                if (responsableRadio && !responsableRadio.disabled) {
                    responsableRadio.checked = true;
                }
            }

            var responsableSelected = Array.prototype.slice.call(form.querySelectorAll(responsableSelector(areaId))).some(function (radio) {
                return radio.checked;
            });
            if (!responsableSelected) {
                ensureOneParticipant(areaId);
            }
        }

        function shouldSkipCardToggle(target) {
            return !!(
                target
                && (
                    target.closest('[data-create-area-details]')
                    || target.closest('input, label, textarea, select, button, a')
                )
            );
        }

        function applyTypeDefaults() {
            if (!tipoSelect) {
                return;
            }
            var tipoId = String(tipoSelect.value || '');
            var config = tipoAreaConfig[tipoId] || null;
            var allowedAreas = config && Array.isArray(config.allowed) ? config.allowed : [];
            var defaultAreas = config && Array.isArray(config.defaults) ? config.defaults : [];
            var strictAllowed = !!(config && config.strictAllowed);
            var participantsDefaultsByArea = config && config.participantsDefaults ? config.participantsDefaults : {};

            toggles.forEach(function (toggle) {
                var areaId = toggle.getAttribute('data-create-area-toggle');
                var card = form.querySelector('[data-create-area-card="' + areaId + '"]');
                var hasParticipants = card && card.getAttribute('data-create-area-has-participants') === '1';
                var isAllowed = strictAllowed ? inSet(areaId, allowedAreas) : (allowedAreas.length === 0 ? true : inSet(areaId, allowedAreas));
                var shouldDefault = inSet(areaId, defaultAreas);

                if (card) {
                    card.classList.toggle('d-none', !isAllowed);
                }

                if (!isAllowed || !hasParticipants) {
                    toggle.checked = false;
                    if (!hasParticipants) {
                        toggle.disabled = true;
                    }
                } else {
                    toggle.disabled = false;
                    toggle.checked = shouldDefault;
                }

                syncArea(areaId);
                if (toggle.checked) {
                    if (shouldDefault) {
                        applyAreaParticipantsFromDefaults(areaId, participantsDefaultsByArea[String(areaId)] || null);
                    } else {
                        ensureOneParticipant(areaId);
                    }
                }
            });
        }

        toggles.forEach(function (toggle) {
            var areaId = toggle.getAttribute('data-create-area-toggle');
            toggle.addEventListener('change', function () {
                syncArea(areaId);
                if (errorBox) {
                    errorBox.classList.add('d-none');
                    errorBox.textContent = '';
                }
            });
            syncArea(areaId);
        });

        form.querySelectorAll('[data-create-area-card]').forEach(function (card) {
            var areaId = card.getAttribute('data-create-area-card');
            var toggle = form.querySelector('[data-create-area-toggle="' + areaId + '"]');
            var details = form.querySelector(detailsSelector(areaId));
            if (!areaId || !toggle || !details) {
                return;
            }

            card.addEventListener('click', function (event) {
                if (shouldSkipCardToggle(event.target)) {
                    return;
                }
                if (toggle.disabled) {
                    return;
                }

                if (!toggle.checked) {
                    toggle.checked = true;
                    syncArea(areaId);
                    ensureOneParticipant(areaId);
                } else {
                    var expanded = details.classList.contains('show');
                    setCollapseState(details, !expanded);
                }

                if (errorBox) {
                    errorBox.classList.add('d-none');
                    errorBox.textContent = '';
                }
            });
        });

        if (tipoSelect) {
            tipoSelect.addEventListener('change', function () {
                applyTypeDefaults();
                if (errorBox) {
                    errorBox.classList.add('d-none');
                    errorBox.textContent = '';
                }
            });
            applyTypeDefaults();
        }

        form.addEventListener('change', function (event) {
            var target = event.target;
            if (!target || !target.matches('[data-create-area-participant]')) {
                return;
            }
            var areaId = target.getAttribute('data-create-area-participant');
            syncArea(areaId);
            if (target.checked) {
                var responsables = form.querySelectorAll(responsableSelector(areaId));
                var hasSelected = false;
                responsables.forEach(function (radio) {
                    if (radio.checked) {
                        hasSelected = true;
                    }
                });
                if (!hasSelected) {
                    var defaultRadio = form.querySelector('#ct-create-area-' + areaId + '-r-' + target.value);
                    if (defaultRadio && !defaultRadio.disabled) {
                        defaultRadio.checked = true;
                    }
                }
            }
        });

        form.addEventListener('submit', function (event) {
            if (!errorBox) {
                return;
            }

            var selectedAreas = [];
            toggles.forEach(function (toggle) {
                if (toggle.checked) {
                    selectedAreas.push(toggle.getAttribute('data-create-area-toggle'));
                }
            });

            if (selectedAreas.length === 0) {
                event.preventDefault();
                errorBox.textContent = 'Debes seleccionar al menos un área participante.';
                errorBox.classList.remove('d-none');
                return;
            }

            for (var i = 0; i < selectedAreas.length; i++) {
                var areaId = selectedAreas[i];
                var participants = Array.prototype.slice.call(form.querySelectorAll(participantSelector(areaId))).filter(function (checkbox) {
                    return checkbox.checked;
                });
                if (participants.length === 0) {
                    event.preventDefault();
                    errorBox.textContent = 'Cada área seleccionada debe tener al menos un participante.';
                    errorBox.classList.remove('d-none');
                    return;
                }

                var responsableSelected = Array.prototype.slice.call(form.querySelectorAll(responsableSelector(areaId))).some(function (radio) {
                    return radio.checked;
                });
                if (!responsableSelected) {
                    var firstParticipant = participants[0];
                    var defaultRadio = form.querySelector('#ct-create-area-' + areaId + '-r-' + firstParticipant.value);
                    if (defaultRadio && !defaultRadio.disabled) {
                        defaultRadio.checked = true;
                    }
                }
            }

            errorBox.classList.add('d-none');
            errorBox.textContent = '';
        });
    })();
    </script>
    <script>
    (function () {
        var offcanvasEl = document.getElementById('ct-solicitud-preview-offcanvas');
        if (!offcanvasEl) {
            return;
        }

        var titleNode = document.getElementById('ct-solicitud-preview-title');
        var estadoNode = document.getElementById('ct-solicitud-preview-estado');
        var tipoNode = document.getElementById('ct-solicitud-preview-tipo');
        var tipoIconNode = document.getElementById('ct-solicitud-preview-tipo-icon');
        var tipoTextNode = document.getElementById('ct-solicitud-preview-tipo-text');
        var tipoCodeNode = document.getElementById('ct-solicitud-preview-tipo-code');
        var solicitanteNode = document.getElementById('ct-solicitud-preview-solicitante');
        var resumenNode = document.getElementById('ct-solicitud-preview-resumen');
        var rolNode = document.getElementById('ct-solicitud-preview-rol');
        var identificacionNode = document.getElementById('ct-solicitud-preview-identificacion');
        var areasNode = document.getElementById('ct-solicitud-preview-areas');
        var actualizacionNode = document.getElementById('ct-solicitud-preview-actualizacion');
        var openLinkNode = document.getElementById('ct-solicitud-preview-open-link');

        function textValue(value, fallback) {
            var raw = String(value || '').trim();
            return raw !== '' ? raw : fallback;
        }

        function updateFromTrigger(trigger) {
            var id = textValue(trigger.getAttribute('data-ct-id'), '-');
            var estado = textValue(trigger.getAttribute('data-ct-estado'), 'Sin estado');
            var estadoClass = textValue(trigger.getAttribute('data-ct-estado-class'), 'ct-crud-pill-muted');
            var tipo = textValue(trigger.getAttribute('data-ct-tipo'), 'Sin tipo');
            var tipoCode = textValue(trigger.getAttribute('data-ct-tipo-code'), '');
            var tipoClass = textValue(trigger.getAttribute('data-ct-tipo-class'), 'ct-crud-pill-muted');
            var tipoIcon = textValue(trigger.getAttribute('data-ct-tipo-icon'), 'bi-tag');
            var solicitante = textValue(trigger.getAttribute('data-ct-solicitante'), '-');
            var resumen = textValue(trigger.getAttribute('data-ct-resumen'), 'Sin resumen');
            var rol = textValue(trigger.getAttribute('data-ct-rol'), 'Sin rol');
            var identificacion = textValue(trigger.getAttribute('data-ct-identificacion'), 'Sin identificación');
            var areasCompletas = textValue(trigger.getAttribute('data-ct-areas-completas'), '0');
            var areasTotal = textValue(trigger.getAttribute('data-ct-areas-total'), '0');
            var actualizacion = textValue(trigger.getAttribute('data-ct-actualizacion'), '-');
            var fichaUrl = textValue(trigger.getAttribute('data-ct-ficha-url'), '#');

            titleNode.textContent = '#' + id;
            estadoNode.className = 'ct-crud-pill ' + estadoClass;
            estadoNode.textContent = estado;
            tipoNode.className = 'ct-crud-pill ' + tipoClass;
            if (tipoIconNode) {
                tipoIconNode.className = 'bi ' + tipoIcon + ' me-1';
            }
            if (tipoTextNode) {
                tipoTextNode.textContent = tipo;
            } else {
                tipoNode.textContent = tipo;
            }
            if (tipoCodeNode) {
                tipoCodeNode.textContent = tipoCode !== '' ? ('Código: ' + tipoCode) : 'Código: N/D';
            }
            solicitanteNode.textContent = solicitante;
            resumenNode.textContent = resumen;
            rolNode.textContent = rol;
            identificacionNode.textContent = identificacion;
            areasNode.textContent = areasCompletas + '/' + areasTotal;
            actualizacionNode.textContent = actualizacion;
            openLinkNode.setAttribute('href', fichaUrl);
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target instanceof Element ? event.target.closest('[data-ct-solicitud-preview="1"]') : null;
            if (!trigger) {
                return;
            }

            event.preventDefault();
            updateFromTrigger(trigger);

            if (window.bootstrap && typeof window.bootstrap.Offcanvas === 'function') {
                var instance = window.bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl);
                instance.show();
                return;
            }

            var fallbackHref = trigger.getAttribute('href');
            if (fallbackHref) {
                window.location.href = fallbackHref;
            }
        });
    })();
    </script>

</section>
