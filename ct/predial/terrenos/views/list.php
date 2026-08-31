<?php
declare(strict_types=1);
?>
<section id="terrenos" class="mt-3 ct-crud-fade-in ct-theme-enterprise" data-open-modal="<?php echo ctEscape($modal ?? ''); ?>">
    <div class="ct-terrenos-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h4 mb-0">Terrenos</h2>
        <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
            <div class="dropdown">
                <button class="btn btn-primary ct-crud-btn-main dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Operaciones
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-registrar-adquisicion">
                            <i class="bi bi-house-add me-2" aria-hidden="true"></i>Registrar adquisición
                        </button>
                    </li>
                    <li>
                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-registrar-subdivision">
                            <i class="bi bi-bezier me-2" aria-hidden="true"></i>Registrar subdivisión
                        </button>
                    </li>
                    <li>
                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-registrar-fusion">
                            <i class="bi bi-intersect me-2" aria-hidden="true"></i>Registrar fusión
                        </button>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-registrar-titularidad">
                            <i class="bi bi-person-vcard me-2" aria-hidden="true"></i>Registrar titularidad
                        </button>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-crear-terreno">
                            <i class="bi bi-plus-square me-2" aria-hidden="true"></i>Crear terreno base (técnico)
                        </button>
                    </li>
                </ul>
            </div>
            <div class="dropdown">
                <button class="btn btn-outline-primary ct-crud-btn-main dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Comercial
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-registrar-tasacion">
                            <i class="bi bi-clipboard-data me-2" aria-hidden="true"></i>Registrar tasación
                        </button>
                    </li>
                    <li>
                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-registrar-venta">
                            <i class="bi bi-receipt-cutoff me-2" aria-hidden="true"></i>Registrar venta
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <?php if ($catalogosError !== null): ?>
        <div class="alert alert-warning mb-3"><?php echo ctEscape($catalogosError); ?></div>
    <?php endif; ?>

    <?php if ($terrenosError !== null): ?>
        <div class="alert alert-warning mb-3"><?php echo ctEscape($terrenosError); ?></div>
    <?php endif; ?>

    <?php
    $toSearch = static function (string $text): string {
        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    };

    $comunaOptions = [['value' => '', 'label' => 'Todas', 'search' => 'todas']];
    foreach ($comunas as $comuna) {
        $id = (string) ((int) ($comuna['id_comuna'] ?? 0));
        $nombre = trim((string) ($comuna['nombre'] ?? ''));
        if ($id === '0' || $nombre === '') {
            continue;
        }
        $comunaOptions[] = ['value' => $id, 'label' => $nombre, 'search' => $toSearch($nombre)];
    }

    $estadoPredialOptions = [['value' => '', 'label' => 'Todos', 'search' => 'todos']];
    foreach ($estadosPrediales as $estado) {
        $id = (string) ((int) ($estado['id_estado_predial'] ?? 0));
        $nombre = trim((string) ($estado['nombre'] ?? ''));
        if ($id === '0' || $nombre === '') {
            continue;
        }
        $estadoPredialOptions[] = ['value' => $id, 'label' => $nombre, 'search' => $toSearch($nombre)];
    }

    $estadoComercialOptions = [['value' => '', 'label' => 'Todos', 'search' => 'todos']];
    foreach ($estadosComerciales as $estado) {
        $id = (string) ((int) ($estado['id_estado_comercial'] ?? 0));
        $nombre = trim((string) ($estado['nombre'] ?? ''));
        if ($id === '0' || $nombre === '') {
            continue;
        }
        $estadoComercialOptions[] = ['value' => $id, 'label' => $nombre, 'search' => $toSearch($nombre)];
    }

    $tipoInmuebleOptions = [['value' => '', 'label' => 'Todos', 'search' => 'todos']];
    foreach ($tiposInmueble as $tipo) {
        $id = (string) ((int) ($tipo['id_tipo_inmueble'] ?? 0));
        $nombre = trim((string) ($tipo['nombre'] ?? ''));
        if ($id === '0' || $nombre === '') {
            continue;
        }
        $tipoInmuebleOptions[] = ['value' => $id, 'label' => $nombre, 'search' => $toSearch($nombre)];
    }

    $lineasOptions = [];
    foreach ($lineasPermitidas as $lineas) {
        $lineasOptions[] = [
            'value' => (string) (int) $lineas,
            'label' => (string) (int) $lineas,
            'search' => (string) (int) $lineas,
        ];
    }

    $tableRows = [];
    foreach ($terrenos as $terreno) {
        if (!is_array($terreno)) {
            continue;
        }

        $idTerreno = (int) ($terreno['id_terreno'] ?? 0);
        $rolAsignado = trim((string) ($terreno['rol_asignado'] ?? ''));
        $rolMatriz = trim((string) ($terreno['rol_matriz'] ?? ''));
        $identificacionPropiedad = trim((string) ($terreno['identificacion_propiedad'] ?? ''));
        $comunaNombre = trim((string) ($terreno['comuna_nombre'] ?? ''));
        $propietarioPrincipal = trim((string) ($terreno['propietario_principal'] ?? ''));
        $propietariosVigentes = (int) ($terreno['propietarios_vigentes_count'] ?? 0);
        $copropietariosExtra = max(0, $propietariosVigentes - 1);

        $historialQuery = http_build_query(array_filter([
            'id_terreno' => $idTerreno > 0 ? (string) $idTerreno : '',
            'rol' => $rolAsignado,
        ], static fn($value) => $value !== '' && $value !== null));
        $historialHref = ctUrl('predial/terrenos/historial.php')
            . ($historialQuery !== '' ? ('?' . $historialQuery) : '');

        $volverQuery = http_build_query(array_filter(array_merge($queryBase, [
            'pagina' => (int) $paginaActual,
        ]), static fn($value) => $value !== '' && $value !== null));

        $fichaQuery = http_build_query(array_filter([
            'id_terreno' => $idTerreno > 0 ? (string) $idTerreno : '',
            'volver' => $volverQuery,
        ], static fn($value) => $value !== '' && $value !== null));
        $fichaHref = ctUrl('predial/terrenos/terreno.php')
            . ($fichaQuery !== '' ? ('?' . $fichaQuery) : '');

        $estadoPredialNombre = trim((string) ($terreno['estado_predial_nombre'] ?? ''));
        $estadoPredialUpper = strtoupper($estadoPredialNombre);

        $row = $terreno;
        $row['__ficha_href'] = $fichaHref;
        $row['__historial_href'] = $historialHref;
        $row['__rol_asignado'] = $rolAsignado;
        $row['__rol_matriz'] = $rolMatriz;
        $row['__identificacion_propiedad'] = $identificacionPropiedad;
        $row['__comuna_nombre'] = $comunaNombre;
        $row['__propietario_principal'] = $propietarioPrincipal;
        $row['__copropietarios_extra'] = $copropietariosExtra;
        $row['__permite_tasacion'] = $estadoPredialUpper !== 'NO DISPONIBLE';
        $row['__permite_subdivision'] = $estadoPredialUpper === 'DISPONIBLE';
        $row['__permite_fusion_origen'] = $estadoPredialUpper === 'DISPONIBLE';
        $row['__permite_fusion_resultado'] = $estadoPredialUpper === 'SUBDIVIDIDO';
        $row['__has_ultima_operacion'] = (int) ($terreno['ultima_operacion_id'] ?? 0) > 0;
        $tableRows[] = $row;
    }

    $summaryHtml = '<div class="small text-muted">'
        . 'Total: <strong>' . number_format((int) $totalRegistros, 0, ',', '.') . '</strong>'
        . ' | Página <strong>' . (int) $paginaActual . '</strong>'
        . ' de <strong>' . (int) $totalPaginas . '</strong>'
        . '</div>';

    $paginationHtml = '';
    if ($totalPaginas > 1) {
        ob_start();
        ?>
        <nav aria-label="Paginación de terrenos">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo ctEscape(ctTerrenosBuildQuery($queryBase, ['pagina' => max(1, ((int) $paginaActual) - 1)])); ?>" aria-label="Anterior">&laquo;</a>
                </li>
                <?php foreach ($paginationItems as $item): ?>
                    <?php if ($item['page'] === null): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                    <?php else: ?>
                        <li class="page-item <?php echo $item['active'] ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo ctEscape(ctTerrenosBuildQuery($queryBase, ['pagina' => $item['page']])); ?>"><?php echo ctEscape($item['label']); ?></a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                    <a class="page-link" href="<?php echo ctEscape(ctTerrenosBuildQuery($queryBase, ['pagina' => min((int) $totalPaginas, ((int) $paginaActual) + 1)])); ?>" aria-label="Siguiente">&raquo;</a>
                </li>
            </ul>
        </nav>
        <?php
        $paginationHtml = (string) ob_get_clean();
    }

    ctRenderCrudTable([
        'filters' => [
            'form_attrs' => [
                'class' => 'ct-terrenos-filtros ct-crud-filters row g-2 align-items-end mb-3',
                'method' => 'get',
                'id' => 'ct-terrenos-filtros-form',
            ],
            'fields' => [
                [
                    'type' => 'custom',
                    'render' => static function () use ($filtroCampo, $filtroTexto): void {
                        ?>
                        <div class="col-12 col-md-4">
                            <label class="form-label small text-muted" for="ct-filtro-texto">Buscar</label>
                            <div class="input-group">
                                <select class="form-select ct-control-input" id="ct-filtro-campo" name="filtroCampo" aria-label="Buscar en">
                                    <option value="todos"<?php echo ($filtroCampo ?? 'todos') === 'todos' ? ' selected' : ''; ?>>Todos</option>
                                    <option value="rol"<?php echo ($filtroCampo ?? '') === 'rol' ? ' selected' : ''; ?>>Rol / Matriz</option>
                                    <option value="identificacion"<?php echo ($filtroCampo ?? '') === 'identificacion' ? ' selected' : ''; ?>>Identificación</option>
                                    <option value="propietario"<?php echo ($filtroCampo ?? '') === 'propietario' ? ' selected' : ''; ?>>Propietario</option>
                                </select>
                                <input class="form-control ct-control-input" id="ct-filtro-texto" name="filtroTexto" value="<?php echo ctEscape($filtroTexto); ?>" placeholder="Texto a buscar">
                            </div>
                        </div>
                        <?php
                    },
                ],
                [
                    'type' => 'custom',
                    'render' => static function () use ($comunaOptions, $filtroComuna): void {
                        ctRenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-md-2',
                            'label' => 'Comuna',
                            'input_name' => 'filtroComuna',
                            'input_id' => 'ct-filtro-comuna',
                            'picker_id' => 'ct-filtro-comuna-picker',
                            'button_id' => 'ct-filtro-comuna-btn',
                            'filter_id' => 'ct-filtro-comuna-filter',
                            'list_id' => 'ct-filtro-comuna-list',
                            'error_id' => 'ct-filtro-comuna-error',
                            'error_message' => 'Debes seleccionar una comuna.',
                            'button_placeholder' => 'Todas',
                            'filter_placeholder' => 'Buscar comuna...',
                            'value' => $filtroComuna,
                            'options' => $comunaOptions,
                        ]);
                    },
                ],
                [
                    'type' => 'custom',
                    'render' => static function () use ($estadoPredialOptions, $filtroEstadoPredial): void {
                        ctRenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-md-2',
                            'label' => 'Estado',
                            'input_name' => 'filtroEstadoPredial',
                            'input_id' => 'ct-filtro-estado-predial',
                            'picker_id' => 'ct-filtro-estado-predial-picker',
                            'button_id' => 'ct-filtro-estado-predial-btn',
                            'filter_id' => 'ct-filtro-estado-predial-filter',
                            'list_id' => 'ct-filtro-estado-predial-list',
                            'error_id' => 'ct-filtro-estado-predial-error',
                            'error_message' => 'Debes seleccionar un estado.',
                            'button_placeholder' => 'Todos',
                            'filter_placeholder' => 'Buscar estado...',
                            'value' => $filtroEstadoPredial,
                            'options' => $estadoPredialOptions,
                        ]);
                    },
                ],
                [
                    'type' => 'custom',
                    'render' => static function () use ($estadoComercialOptions, $filtroEstadoComercial): void {
                        ctRenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-md-2',
                            'label' => 'Estado comercial',
                            'input_name' => 'filtroEstadoComercial',
                            'input_id' => 'ct-filtro-estado-comercial',
                            'picker_id' => 'ct-filtro-estado-comercial-picker',
                            'button_id' => 'ct-filtro-estado-comercial-btn',
                            'filter_id' => 'ct-filtro-estado-comercial-filter',
                            'list_id' => 'ct-filtro-estado-comercial-list',
                            'error_id' => 'ct-filtro-estado-comercial-error',
                            'error_message' => 'Debes seleccionar un estado comercial.',
                            'button_placeholder' => 'Todos',
                            'filter_placeholder' => 'Buscar estado...',
                            'value' => $filtroEstadoComercial,
                            'options' => $estadoComercialOptions,
                        ]);
                    },
                ],
                [
                    'type' => 'custom',
                    'render' => static function () use ($tipoInmuebleOptions, $filtroTipoInmueble): void {
                        ctRenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-md-1',
                            'label' => 'Tipo',
                            'input_name' => 'filtroTipoInmueble',
                            'input_id' => 'ct-filtro-tipo-inmueble',
                            'picker_id' => 'ct-filtro-tipo-inmueble-picker',
                            'button_id' => 'ct-filtro-tipo-inmueble-btn',
                            'filter_id' => 'ct-filtro-tipo-inmueble-filter',
                            'list_id' => 'ct-filtro-tipo-inmueble-list',
                            'error_id' => 'ct-filtro-tipo-inmueble-error',
                            'error_message' => 'Debes seleccionar un tipo.',
                            'button_placeholder' => 'Todos',
                            'filter_placeholder' => 'Buscar tipo...',
                            'value' => $filtroTipoInmueble,
                            'options' => $tipoInmuebleOptions,
                        ]);
                    },
                ],
                [
                    'type' => 'custom',
                    'render' => static function () use ($lineasOptions, $lineasPorPagina): void {
                        ctRenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-md-1',
                            'label' => 'Líneas',
                            'input_name' => 'lineas',
                            'input_id' => 'ct-lineas',
                            'picker_id' => 'ct-lineas-picker',
                            'button_id' => 'ct-lineas-btn',
                            'filter_id' => 'ct-lineas-filter',
                            'list_id' => 'ct-lineas-list',
                            'error_id' => 'ct-lineas-error',
                            'error_message' => 'Debes seleccionar una cantidad.',
                            'button_placeholder' => 'Líneas',
                            'filter_placeholder' => 'Buscar cantidad...',
                            'value' => (string) $lineasPorPagina,
                            'options' => $lineasOptions,
                        ]);
                    },
                ],
            ],
            'actions' => [
                'wrapper_class' => 'col-12',
                'inner_class' => 'ct-terrenos-filtros-actions d-flex justify-content-end gap-2',
                'items' => [
                    [
                        'type' => 'submit',
                        'class' => 'btn btn-outline-primary ct-crud-filter-submit',
                        'icon' => 'bi bi-funnel',
                        'label' => 'Filtrar',
                    ],
                    [
                        'type' => 'link',
                        'href' => '?',
                        'class' => 'btn btn-outline-secondary ct-crud-filter-submit',
                        'icon' => 'bi bi-eraser',
                        'label' => 'Limpiar',
                    ],
                ],
            ],
        ],
        'shell_class' => 'ct-crud-table-shell',
        'table_wrap_class' => 'table-responsive',
        'table_class' => 'table table-sm align-middle mb-0 ct-terrenos-table ct-crud-table',
        'tbody_id' => 'ct-terrenos-table-body',
        'columns' => [
            [
                'key' => 'id_terreno',
                'label' => 'ID',
                'header_class' => 'ct-col-head-id',
                'cell_class' => 'ct-col-cell-id text-center',
                'sort_url' => ctTerrenosSortLink('id_terreno', $queryBase, $orden, $direccion),
                'sort_icon' => ctTerrenosSortIcon('id_terreno', $orden, $direccion),
            ],
            [
                'key' => 'ficha',
                'label' => 'Ficha',
                'header_class' => 'ct-col-head-ficha',
                'sort_url' => ctTerrenosSortLink('rol_asignado', $queryBase, $orden, $direccion),
                'sort_icon' => ctTerrenosSortIcon('rol_asignado', $orden, $direccion),
                'escape' => false,
                'cell_class' => 'ct-terreno-ficha-cell ct-col-cell-ficha',
                'render' => static function (array $row): string {
                    ob_start();
                    ?>
                    <div class="ct-terreno-ficha-card">
                        <div class="ct-terreno-ficha-topline">
                            <span class="ct-terreno-ficha-key">Rol:</span>
                            <span class="ct-terreno-ficha-value"><?php echo ctEscape((string) ($row['__rol_asignado'] !== '' ? $row['__rol_asignado'] : '-')); ?></span>
                            <span class="ct-terreno-ficha-divider">|</span>
                            <span class="ct-terreno-ficha-key">Rol matriz:</span>
                            <span class="ct-terreno-ficha-value"><?php echo ctEscape((string) ($row['__rol_matriz'] !== '' ? $row['__rol_matriz'] : '-')); ?></span>
                        </div>
                        <div class="ct-terreno-ficha-row">
                            <span class="ct-terreno-ficha-key">Dirección</span>
                            <span class="ct-terreno-ficha-value"><?php echo ctEscape((string) ($row['__comuna_nombre'] !== '' ? $row['__comuna_nombre'] : '-')); ?></span>
                        </div>
                        <div class="ct-terreno-ficha-row">
                            <span class="ct-terreno-ficha-key">Superficie</span>
                            <span class="ct-terreno-ficha-value"><?php echo ctEscape(ctTerrenosFormatSuperficie($row['superficie_m2'] ?? 0)); ?> m²</span>
                        </div>
                        <div class="ct-terreno-ficha-row">
                            <span class="ct-terreno-ficha-key">Propietario</span>
                            <span class="ct-terreno-ficha-value"><?php echo ctEscape((string) ($row['__propietario_principal'] !== '' ? $row['__propietario_principal'] : '-')); ?><?php echo ((int) ($row['__copropietarios_extra'] ?? 0)) > 0 ? ctEscape(' (+' . (int) ($row['__copropietarios_extra'] ?? 0) . ' coprop.)') : ''; ?></span>
                        </div>
                        <div class="ct-terreno-ficha-row">
                            <span class="ct-terreno-ficha-key">Identificación</span>
                            <span class="ct-terreno-ficha-value"><?php echo ctEscape((string) ($row['__identificacion_propiedad'] !== '' ? $row['__identificacion_propiedad'] : '-')); ?></span>
                        </div>
                    </div>
                    <?php
                    return (string) ob_get_clean();
                },
            ],
            [
                'key' => 'estado_predial_nombre',
                'label' => 'Estado',
                'header_class' => 'ct-col-head-estado',
                'cell_class' => 'ct-col-cell-estado',
                'sort_url' => ctTerrenosSortLink('estado_predial_nombre', $queryBase, $orden, $direccion),
                'sort_icon' => ctTerrenosSortIcon('estado_predial_nombre', $orden, $direccion),
            ],
            [
                'key' => 'estado_comercial_nombre',
                'label' => 'Estado comercial',
                'header_class' => 'ct-col-head-estado-comercial',
                'cell_class' => 'ct-col-cell-estado-comercial',
                'sort_url' => ctTerrenosSortLink('estado_comercial_nombre', $queryBase, $orden, $direccion),
                'sort_icon' => ctTerrenosSortIcon('estado_comercial_nombre', $orden, $direccion),
            ],
            [
                'key' => 'tipo_inmueble_nombre',
                'label' => 'Tipo',
                'header_class' => 'ct-col-head-tipo',
                'cell_class' => 'ct-col-cell-tipo',
                'sort_url' => ctTerrenosSortLink('tipo_inmueble_nombre', $queryBase, $orden, $direccion),
                'sort_icon' => ctTerrenosSortIcon('tipo_inmueble_nombre', $orden, $direccion),
            ],
            [
                'key' => 'ultima_operacion',
                'label' => 'Última operación',
                'header_class' => 'ct-col-head-ultima',
                'escape' => false,
                'cell_class' => 'small ct-col-cell-ultima',
                'render' => static function (array $row): string {
                    ob_start();
                    ?>
                    <?php if ((bool) ($row['__has_ultima_operacion'] ?? false)): ?>
                        <div><?php echo ctEscape(ctTerrenosFormatOperacionLabel((string) ($row['ultima_operacion_tipo'] ?? ''))); ?></div>
                        <div class="text-muted"><?php echo ctEscape(ctTerrenosFormatDate((string) ($row['ultima_operacion_fecha'] ?? ''))); ?></div>
                    <?php else: ?>
                        <span class="text-muted">Sin operaciones</span>
                    <?php endif; ?>
                    <?php
                    return (string) ob_get_clean();
                },
            ],
        ],
        'rows' => $tableRows,
        'empty_text' => 'Sin terrenos registrados.',
        'actions' => [
            'header_label' => 'Acciones',
            'header_class' => 'text-center ct-col-head-acciones',
            'cell_class' => 'text-center ct-col-cell-acciones',
            'primary' => static function (array $row): array {
                return [
                    'type' => 'link',
                    'href' => (string) ($row['__ficha_href'] ?? '#'),
                    'class' => 'btn btn-outline-secondary btn-sm',
                    'icon' => 'bi bi-eye',
                    'label' => 'Ver terreno',
                    'show_label' => false,
                    'attrs' => [
                        'title' => 'Ver terreno',
                    ],
                ];
            },
            'secondary' => static function (array $row): array {
                $tasacionAttrs = [
                    'data-id-terreno' => (string) ($row['id_terreno'] ?? ''),
                    'data-bs-toggle' => 'modal',
                    'data-bs-target' => '#ct-modal-registrar-tasacion',
                ];
                if (!(bool) ($row['__permite_tasacion'] ?? false)) {
                    $tasacionAttrs['title'] = 'No puedes tasar un terreno con estado predial No disponible';
                }

                $subdivisionAttrs = [
                    'data-id-terreno' => (string) ($row['id_terreno'] ?? ''),
                    'data-bs-toggle' => 'modal',
                    'data-bs-target' => '#ct-modal-registrar-subdivision',
                ];
                if (!(bool) ($row['__permite_subdivision'] ?? false)) {
                    $subdivisionAttrs['title'] = 'Solo disponible para estado Disponible';
                }

                $fusionOrigenAttrs = [
                    'data-id-terreno' => (string) ($row['id_terreno'] ?? ''),
                    'data-bs-toggle' => 'modal',
                    'data-bs-target' => '#ct-modal-registrar-fusion',
                ];
                if (!(bool) ($row['__permite_fusion_origen'] ?? false)) {
                    $fusionOrigenAttrs['title'] = 'Solo disponible para estado Disponible';
                }

                $fusionResultadoAttrs = [
                    'data-id-terreno' => (string) ($row['id_terreno'] ?? ''),
                    'data-bs-toggle' => 'modal',
                    'data-bs-target' => '#ct-modal-registrar-fusion',
                ];
                if (!(bool) ($row['__permite_fusion_resultado'] ?? false)) {
                    $fusionResultadoAttrs['title'] = 'Solo disponible para estado Subdividido';
                }

                return [
                    [
                        'type' => 'link',
                        'href' => (string) ($row['__ficha_href'] ?? '#'),
                        'icon' => 'bi bi-eye',
                        'label' => 'Ver terreno',
                    ],
                    ['type' => 'divider'],
                    [
                        'type' => 'link',
                        'href' => (string) ($row['__historial_href'] ?? '#'),
                        'icon' => 'bi bi-clock-history',
                        'label' => 'Ir al historial',
                    ],
                    ['type' => 'divider'],
                    [
                        'type' => 'button',
                        'icon' => 'bi bi-clipboard-data',
                        'label' => 'Registrar tasación',
                        'class' => ((bool) ($row['__permite_tasacion'] ?? false)) ? 'js-open-modal-tasacion' : '',
                        'disabled' => !((bool) ($row['__permite_tasacion'] ?? false)),
                        'attrs' => $tasacionAttrs,
                    ],
                    [
                        'type' => 'button',
                        'icon' => 'bi bi-receipt-cutoff',
                        'label' => 'Registrar venta',
                        'class' => 'js-open-modal-venta',
                        'attrs' => [
                            'data-id-terreno' => (string) ($row['id_terreno'] ?? ''),
                            'data-bs-toggle' => 'modal',
                            'data-bs-target' => '#ct-modal-registrar-venta',
                        ],
                    ],
                    ['type' => 'divider'],
                    [
                        'type' => 'button',
                        'icon' => 'bi bi-pencil-square',
                        'label' => 'Editar terreno',
                        'class' => 'ct-btn-editar',
                        'attrs' => [
                            'data-id' => (string) ($row['id_terreno'] ?? ''),
                            'data-rol-asignado' => (string) ($row['rol_asignado'] ?? ''),
                            'data-rol-matriz' => (string) ($row['rol_matriz'] ?? ''),
                            'data-identificacion' => (string) ($row['identificacion_propiedad'] ?? ''),
                            'data-superficie' => (string) ($row['superficie_m2'] ?? ''),
                            'data-comuna' => (string) ($row['id_comuna'] ?? ''),
                            'data-estado-predial' => (string) ($row['id_estado_predial'] ?? ''),
                            'data-estado-comercial' => (string) ($row['id_estado_comercial'] ?? ''),
                            'data-tipo-inmueble' => (string) ($row['id_tipo_inmueble'] ?? ''),
                            'data-bs-toggle' => 'modal',
                            'data-bs-target' => '#ct-modal-editar-terreno',
                        ],
                    ],
                    ['type' => 'divider'],
                    [
                        'type' => 'button',
                        'icon' => 'bi bi-person-vcard',
                        'label' => 'Titularidad',
                        'class' => 'ct-btn-abrir-titularidad',
                        'attrs' => [
                            'data-id-terreno' => (string) ($row['id_terreno'] ?? ''),
                            'data-bs-toggle' => 'modal',
                            'data-bs-target' => '#ct-modal-registrar-titularidad',
                        ],
                    ],
                    [
                        'type' => 'button',
                        'icon' => 'bi bi-bezier',
                        'label' => 'Usar como origen (subdivisión)',
                        'class' => ((bool) ($row['__permite_subdivision'] ?? false)) ? 'ct-btn-abrir-subdivision' : '',
                        'disabled' => !((bool) ($row['__permite_subdivision'] ?? false)),
                        'attrs' => $subdivisionAttrs,
                    ],
                    [
                        'type' => 'button',
                        'icon' => 'bi bi-plus-circle',
                        'label' => 'Agregar como origen (fusión)',
                        'class' => ((bool) ($row['__permite_fusion_origen'] ?? false)) ? 'ct-btn-agregar-fusion-origen' : '',
                        'disabled' => !((bool) ($row['__permite_fusion_origen'] ?? false)),
                        'attrs' => $fusionOrigenAttrs,
                    ],
                    [
                        'type' => 'button',
                        'icon' => 'bi bi-intersect',
                        'label' => 'Usar como resultado (fusión)',
                        'class' => ((bool) ($row['__permite_fusion_resultado'] ?? false)) ? 'ct-btn-abrir-fusion-resultado' : '',
                        'disabled' => !((bool) ($row['__permite_fusion_resultado'] ?? false)),
                        'attrs' => $fusionResultadoAttrs,
                    ],
                    ['type' => 'divider'],
                    [
                        'type' => 'button',
                        'icon' => 'bi bi-trash',
                        'label' => 'Eliminar',
                        'class' => 'ct-btn-eliminar text-danger',
                        'attrs' => [
                            'data-id' => (string) ($row['id_terreno'] ?? ''),
                            'data-nombre' => (string) ($row['rol_asignado'] ?? ''),
                            'data-bs-toggle' => 'modal',
                            'data-bs-target' => '#ct-modal-eliminar-terreno',
                        ],
                    ],
                ];
            },
            'dropdown_toggle' => [
                'title' => 'Más acciones',
                'icon' => 'bi bi-three-dots',
                'class' => '',
            ],
        ],
        'meta' => [
            'wrapper_class' => 'd-flex flex-wrap justify-content-between align-items-center mt-3 gap-2',
            'left_html' => $summaryHtml,
            'right_html' => $paginationHtml,
        ],
    ]);
    ?>
</section>
