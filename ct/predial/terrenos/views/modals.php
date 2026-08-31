<?php
declare(strict_types=1);

$comunaOptions = [];
foreach ($comunas as $comuna) {
    $id = (string) ((int) ($comuna['id_comuna'] ?? 0));
    $nombre = trim((string) ($comuna['nombre'] ?? ''));
    if ($id === '0' || $nombre === '') {
        continue;
    }
    $search = function_exists('mb_strtolower') ? mb_strtolower($nombre) : strtolower($nombre);
    $comunaOptions[] = ['value' => $id, 'label' => $nombre, 'search' => $search];
}

$estadoPredialOptions = [];
foreach ($estadosPrediales as $estado) {
    $id = (string) ((int) ($estado['id_estado_predial'] ?? 0));
    $nombre = trim((string) ($estado['nombre'] ?? ''));
    if ($id === '0' || $nombre === '') {
        continue;
    }
    $search = function_exists('mb_strtolower') ? mb_strtolower($nombre) : strtolower($nombre);
    $estadoPredialOptions[] = ['value' => $id, 'label' => $nombre, 'search' => $search];
}

$estadoComercialOptions = [
    ['value' => '', 'label' => 'Sin definir (auto)', 'search' => 'sin definir auto por defecto'],
];
foreach ($estadosComerciales as $estado) {
    $id = (string) ((int) ($estado['id_estado_comercial'] ?? 0));
    $nombre = trim((string) ($estado['nombre'] ?? ''));
    if ($id === '0' || $nombre === '') {
        continue;
    }
    $search = function_exists('mb_strtolower') ? mb_strtolower($nombre) : strtolower($nombre);
    $estadoComercialOptions[] = ['value' => $id, 'label' => $nombre, 'search' => $search];
}

$tipoInmuebleOptions = [];
foreach ($tiposInmueble as $tipo) {
    $id = (string) ((int) ($tipo['id_tipo_inmueble'] ?? 0));
    $nombre = trim((string) ($tipo['nombre'] ?? ''));
    if ($id === '0' || $nombre === '') {
        continue;
    }
    $search = function_exists('mb_strtolower') ? mb_strtolower($nombre) : strtolower($nombre);
    $tipoInmuebleOptions[] = ['value' => $id, 'label' => $nombre, 'search' => $search];
}

$terrenosSelectOptions = [];
$terrenosSubdivisionSelectOptions = [];
$terrenosFusionOrigenSelectOptions = [];
$terrenosFusionResultadoExistenteSelectOptions = [];
foreach ($terrenosSelector as $terrenoBase) {
    $id = (string) ((int) ($terrenoBase['id_terreno'] ?? 0));
    $rol = trim((string) ($terrenoBase['rol_asignado'] ?? ''));
    $comuna = trim((string) ($terrenoBase['comuna_nombre'] ?? ''));
    $estadoPredialNombre = trim((string) ($terrenoBase['estado_predial_nombre'] ?? ''));
    $estadoPredialNormalizado = function_exists('mb_strtoupper')
        ? mb_strtoupper($estadoPredialNombre)
        : strtoupper($estadoPredialNombre);
    if ($id === '0' || $rol === '') {
        continue;
    }

    $propietarioPrincipal = trim((string) ($terrenoBase['propietario_principal'] ?? ''));
    $propietariosCount = (int) ($terrenoBase['propietarios_vigentes_count'] ?? 0);
    $propietarioLabel = $propietarioPrincipal !== '' ? $propietarioPrincipal : 'Sin titular vigente';
    if ($propietarioPrincipal !== '' && $propietariosCount > 1) {
        $propietarioLabel .= ' +' . ($propietariosCount - 1);
    }

    $label = '#' . $id . ' - ' . $rol . ($comuna !== '' ? ' (' . $comuna . ')' : '');
    $search = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
    $terrenosSelectOptions[] = ['value' => $id, 'label' => $label, 'search' => $search];

    if ($estadoPredialNormalizado === 'DISPONIBLE') {
        $subdivisionLabel = $rol . ' - ' . $propietarioLabel
            . ($comuna !== '' ? ' | ' . $comuna : '')
            . ' | ID: ' . $id;
        $subdivisionSearchText = $rol . ' ' . $propietarioLabel . ' ' . $id . ' ' . $comuna . ' ' . $subdivisionLabel;
        $subdivisionSearch = function_exists('mb_strtolower') ? mb_strtolower($subdivisionSearchText) : strtolower($subdivisionSearchText);
        $terrenosSubdivisionSelectOptions[] = [
            'value' => $id,
            'label' => $subdivisionLabel,
            'search' => $subdivisionSearch,
            'attrs' => [
                'superficie' => number_format((float) ($terrenoBase['superficie_m2'] ?? 0), 2, '.', ''),
                'rol' => $rol,
                'comuna' => $comuna,
                'propietario' => $propietarioLabel,
            ],
        ];
    }

    $superficieFusion = number_format((float) ($terrenoBase['superficie_m2'] ?? 0), 2, ',', '.');
    $fusionLabel = $rol . ' - ' . $propietarioLabel
        . ($comuna !== '' ? ' | ' . $comuna : '')
        . ' | Sup: ' . $superficieFusion . ' m²';
    $fusionSearchText = $fusionLabel . ' ' . $id . ' ' . $rol . ' ' . $propietarioLabel . ' ' . $comuna;
    $fusionSearch = function_exists('mb_strtolower') ? mb_strtolower($fusionSearchText) : strtolower($fusionSearchText);
    $fusionOption = [
        'value' => $id,
        'label' => $fusionLabel,
        'search' => $fusionSearch,
        'attrs' => [
            'id-terreno' => $id,
            'superficie' => number_format((float) ($terrenoBase['superficie_m2'] ?? 0), 2, '.', ''),
            'rol' => $rol,
            'comuna' => $comuna,
            'propietario' => $propietarioLabel,
        ],
    ];
    if ($estadoPredialNormalizado === 'DISPONIBLE') {
        $terrenosFusionOrigenSelectOptions[] = $fusionOption;
    }
    if ($estadoPredialNormalizado === 'SUBDIVIDIDO') {
        $terrenosFusionResultadoExistenteSelectOptions[] = $fusionOption;
    }
}

$tercerosSelectOptions = [];
foreach ($tercerosSelector as $terceroBase) {
    $id = (string) ((int) ($terceroBase['id_tercero'] ?? 0));
    $nombre = trim((string) ($terceroBase['nombre_razon_social'] ?? ''));
    $rut = trim((string) ($terceroBase['rut'] ?? ''));
    if ($id === '0' || $nombre === '') {
        continue;
    }
    $label = '#' . $id . ' - ' . $nombre . ($rut !== '' ? ' (' . $rut . ')' : '');
    $search = function_exists('mb_strtolower') ? mb_strtolower($label) : strtolower($label);
    $tercerosSelectOptions[] = ['value' => $id, 'label' => $label, 'search' => $search];
}

$terrenosTasacionSelectOptions = [];
$terrenosVentaSelectOptions = [];
foreach ($terrenosSelector as $terrenoOpt) {
    $idOpt = (string) ((int) ($terrenoOpt['id_terreno'] ?? 0));
    $rolOpt = trim((string) ($terrenoOpt['rol_asignado'] ?? ''));
    $estadoPredialOpt = strtoupper(trim((string) ($terrenoOpt['estado_predial_nombre'] ?? '')));
    $superficieOpt = is_numeric((string) ($terrenoOpt['superficie_m2'] ?? null))
        ? (float) $terrenoOpt['superficie_m2']
        : 0.0;
    if ($idOpt === '0') {
        continue;
    }

    $label = ($rolOpt !== '' ? $rolOpt : 'Sin rol') . ' (#' . $idOpt . ')';
    $searchText = $label . ' ' . (string) ($terrenoOpt['comuna_nombre'] ?? '');
    $search = function_exists('mb_strtolower') ? mb_strtolower($searchText) : strtolower($searchText);
    $option = [
        'value' => $idOpt,
        'label' => $label,
        'search' => $search,
        'attrs' => [
            'superficie-m2' => (string) $superficieOpt,
        ],
    ];

    if ($estadoPredialOpt !== 'NO DISPONIBLE') {
        $terrenosTasacionSelectOptions[] = $option;
    }
    $terrenosVentaSelectOptions[] = $option;
}

$tiposTasacionSelectOptions = [];
foreach ($tiposTasacion as $tipo) {
    $idTipo = (string) ((int) ($tipo['id_tipo_tasacion'] ?? 0));
    $nombreTipo = trim((string) ($tipo['nombre'] ?? ''));
    if ($idTipo === '0' || $nombreTipo === '') {
        continue;
    }
    $search = function_exists('mb_strtolower') ? mb_strtolower($nombreTipo) : strtolower($nombreTipo);
    $tiposTasacionSelectOptions[] = ['value' => $idTipo, 'label' => $nombreTipo, 'search' => $search];
}

$entidadesFinancierasSelectOptions = [];
foreach ($entidadesFinancieras as $entidad) {
    $idEntidad = (string) ((int) ($entidad['id_entidad_financiera'] ?? 0));
    $nombreEntidad = trim((string) ($entidad['nombre'] ?? ''));
    if ($idEntidad === '0' || $nombreEntidad === '') {
        continue;
    }
    $search = function_exists('mb_strtolower') ? mb_strtolower($nombreEntidad) : strtolower($nombreEntidad);
    $entidadesFinancierasSelectOptions[] = ['value' => $idEntidad, 'label' => $nombreEntidad, 'search' => $search];
}

$tasacionesReferencialesSelectOptions = [];
foreach ($tasacionesSelector as $tasacion) {
    $idTas = (string) ((int) ($tasacion['id_tasacion'] ?? 0));
    $rolTas = trim((string) ($tasacion['rol_asignado'] ?? ''));
    if ($idTas === '0') {
        continue;
    }
    $labelTas = '#'.$idTas.' | '.($rolTas !== '' ? $rolTas : 'Sin rol').' | '.ctComercialFormatDate((string) ($tasacion['fecha_tasacion'] ?? '')).' | UF '.ctComercialFormatUf($tasacion['valor_total_uf'] ?? 0);
    $search = function_exists('mb_strtolower') ? mb_strtolower($labelTas) : strtolower($labelTas);
    $tasacionesReferencialesSelectOptions[] = ['value' => $idTas, 'label' => $labelTas, 'search' => $search];
}

$todayDate = date('Y-m-d');
$estadoPredialOptionalOptions = array_merge(
    [['value' => '', 'label' => 'Sin cambio', 'search' => 'sin cambio']],
    $estadoPredialOptions
);
?>
<div class="modal fade ct-terrenos-modal ct-crud-modal" id="ct-modal-crear-terreno" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="ct-form-crear-terreno" data-ct-disable-submit="1">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Crear terreno base (técnico)</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="crear_terreno">
                    <div class="ct-modal-hint">Uso técnico: crea solo el maestro del terreno. No registra adquisición, titularidad ni operación predial. Para flujo operativo usa “Registrar adquisición”.</div>

                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Rol asignado', true); ?>
                        <input id="ct-crear-rol-asignado" name="rol_asignado" class="form-control" maxlength="30" required>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Rol matriz', false); ?>
                        <input id="ct-crear-rol-matriz" name="rol_matriz" class="form-control" maxlength="30">
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Identificación de propiedad', false); ?>
                        <input id="ct-crear-identificacion" name="identificacion_propiedad" class="form-control" maxlength="120">
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Superficie m2', true); ?>
                        <input id="ct-crear-superficie" name="superficie_m2" class="form-control ct-superficie-input" inputmode="decimal" required>
                    </div>
                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Comuna',
                            'input_name' => 'id_comuna',
                            'input_id' => 'ct-crear-comuna',
                            'picker_id' => 'ct-crear-comuna-picker',
                            'button_id' => 'ct-crear-comuna-btn',
                            'filter_id' => 'ct-crear-comuna-filter',
                            'list_id' => 'ct-crear-comuna-list',
                            'error_id' => 'ct-crear-comuna-error',
                            'error_message' => 'Debes seleccionar una comuna.',
                            'button_placeholder' => 'Selecciona comuna...',
                            'filter_placeholder' => 'Buscar comuna...',
                            'required' => true,
                            'show_requirement' => true,
                            'options' => $comunaOptions,
                        ]);
                        ?>
                    </div>
                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Estado',
                            'input_name' => 'id_estado_predial',
                            'input_id' => 'ct-crear-estado-predial',
                            'picker_id' => 'ct-crear-estado-predial-picker',
                            'button_id' => 'ct-crear-estado-predial-btn',
                            'filter_id' => 'ct-crear-estado-predial-filter',
                            'list_id' => 'ct-crear-estado-predial-list',
                            'error_id' => 'ct-crear-estado-predial-error',
                            'error_message' => 'Debes seleccionar un estado.',
                            'button_placeholder' => 'Selecciona estado...',
                            'filter_placeholder' => 'Buscar estado...',
                            'required' => true,
                            'show_requirement' => true,
                            'options' => $estadoPredialOptions,
                        ]);
                        ?>
                    </div>
                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Estado comercial',
                            'input_name' => 'id_estado_comercial',
                            'input_id' => 'ct-crear-estado-comercial',
                            'picker_id' => 'ct-crear-estado-comercial-picker',
                            'button_id' => 'ct-crear-estado-comercial-btn',
                            'filter_id' => 'ct-crear-estado-comercial-filter',
                            'list_id' => 'ct-crear-estado-comercial-list',
                            'error_id' => 'ct-crear-estado-comercial-error',
                            'error_message' => 'Estado comercial inválido.',
                            'button_placeholder' => 'Sin definir (auto)',
                            'filter_placeholder' => 'Buscar estado...',
                            'required' => false,
                            'show_requirement' => true,
                            'optional_text' => 'opcional',
                            'options' => $estadoComercialOptions,
                        ]);
                        ?>
                    </div>
                    <div class="mb-0">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Tipo',
                            'input_name' => 'id_tipo_inmueble',
                            'input_id' => 'ct-crear-tipo-inmueble',
                            'picker_id' => 'ct-crear-tipo-inmueble-picker',
                            'button_id' => 'ct-crear-tipo-inmueble-btn',
                            'filter_id' => 'ct-crear-tipo-inmueble-filter',
                            'list_id' => 'ct-crear-tipo-inmueble-list',
                            'error_id' => 'ct-crear-tipo-inmueble-error',
                            'error_message' => 'Debes seleccionar un tipo.',
                            'button_placeholder' => 'Selecciona tipo...',
                            'filter_placeholder' => 'Buscar tipo...',
                            'required' => true,
                            'show_requirement' => true,
                            'options' => $tipoInmuebleOptions,
                        ]);
                        ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-outline-primary">Guardar base</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade ct-terrenos-modal ct-crud-modal" id="ct-modal-editar-terreno" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="ct-form-editar-terreno" data-ct-disable-submit="1">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Editar terreno</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="editar_terreno">
                    <input type="hidden" name="id_terreno" id="ct-edit-id">

                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Rol asignado', true); ?>
                        <input id="ct-edit-rol-asignado" name="rol_asignado" class="form-control" maxlength="30" required>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Rol matriz', false); ?>
                        <input id="ct-edit-rol-matriz" name="rol_matriz" class="form-control" maxlength="30">
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Identificación de propiedad', false); ?>
                        <input id="ct-edit-identificacion" name="identificacion_propiedad" class="form-control" maxlength="120">
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Superficie m2', true); ?>
                        <input id="ct-edit-superficie" name="superficie_m2" class="form-control ct-superficie-input" inputmode="decimal" required>
                    </div>
                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Comuna',
                            'input_name' => 'id_comuna',
                            'input_id' => 'ct-edit-comuna',
                            'picker_id' => 'ct-edit-comuna-picker',
                            'button_id' => 'ct-edit-comuna-btn',
                            'filter_id' => 'ct-edit-comuna-filter',
                            'list_id' => 'ct-edit-comuna-list',
                            'error_id' => 'ct-edit-comuna-error',
                            'error_message' => 'Debes seleccionar una comuna.',
                            'button_placeholder' => 'Selecciona comuna...',
                            'filter_placeholder' => 'Buscar comuna...',
                            'required' => true,
                            'show_requirement' => true,
                            'options' => $comunaOptions,
                        ]);
                        ?>
                    </div>
                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Estado',
                            'input_name' => 'id_estado_predial',
                            'input_id' => 'ct-edit-estado-predial',
                            'picker_id' => 'ct-edit-estado-predial-picker',
                            'button_id' => 'ct-edit-estado-predial-btn',
                            'filter_id' => 'ct-edit-estado-predial-filter',
                            'list_id' => 'ct-edit-estado-predial-list',
                            'error_id' => 'ct-edit-estado-predial-error',
                            'error_message' => 'Debes seleccionar un estado.',
                            'button_placeholder' => 'Selecciona estado...',
                            'filter_placeholder' => 'Buscar estado...',
                            'required' => true,
                            'show_requirement' => true,
                            'options' => $estadoPredialOptions,
                        ]);
                        ?>
                    </div>
                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Estado comercial',
                            'input_name' => 'id_estado_comercial',
                            'input_id' => 'ct-edit-estado-comercial',
                            'picker_id' => 'ct-edit-estado-comercial-picker',
                            'button_id' => 'ct-edit-estado-comercial-btn',
                            'filter_id' => 'ct-edit-estado-comercial-filter',
                            'list_id' => 'ct-edit-estado-comercial-list',
                            'error_id' => 'ct-edit-estado-comercial-error',
                            'error_message' => 'Estado comercial inválido.',
                            'button_placeholder' => 'Sin definir (auto)',
                            'filter_placeholder' => 'Buscar estado...',
                            'required' => false,
                            'show_requirement' => true,
                            'optional_text' => 'opcional',
                            'options' => $estadoComercialOptions,
                        ]);
                        ?>
                    </div>
                    <div class="mb-0">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Tipo',
                            'input_name' => 'id_tipo_inmueble',
                            'input_id' => 'ct-edit-tipo-inmueble',
                            'picker_id' => 'ct-edit-tipo-inmueble-picker',
                            'button_id' => 'ct-edit-tipo-inmueble-btn',
                            'filter_id' => 'ct-edit-tipo-inmueble-filter',
                            'list_id' => 'ct-edit-tipo-inmueble-list',
                            'error_id' => 'ct-edit-tipo-inmueble-error',
                            'error_message' => 'Debes seleccionar un tipo.',
                            'button_placeholder' => 'Selecciona tipo...',
                            'filter_placeholder' => 'Buscar tipo...',
                            'required' => true,
                            'show_requirement' => true,
                            'options' => $tipoInmuebleOptions,
                        ]);
                        ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade ct-terrenos-modal ct-crud-modal" id="ct-modal-registrar-adquisicion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post" id="ct-form-registrar-adquisicion" data-ct-disable-submit="1">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Registrar adquisición</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="registrar_adquisicion">
                    <div class="ct-modal-hint">Este flujo crea el terreno, registra la adquisición y deja la titularidad inicial en una sola operación.</div>

                    <div class="accordion" id="ct-adq-accordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="ct-adq-step-1-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#ct-adq-step-1" aria-expanded="true" aria-controls="ct-adq-step-1">
                                    Paso 1. Datos base del terreno
                                </button>
                            </h2>
                            <div id="ct-adq-step-1" class="accordion-collapse collapse show" aria-labelledby="ct-adq-step-1-header" data-bs-parent="#ct-adq-accordion">
                                <div class="accordion-body">
                                    <div class="row g-2">
                                        <div class="col-12 col-md-6">
                                            <?php ctRenderFieldLabel('Rol asignado', true); ?>
                                            <input id="ct-adq-rol-asignado" name="rol_asignado" class="form-control" maxlength="30" required>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <?php ctRenderFieldLabel('Rol matriz', false); ?>
                                            <input id="ct-adq-rol-matriz" name="rol_matriz" class="form-control" maxlength="30">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <?php ctRenderFieldLabel('Identificación de propiedad', false); ?>
                                            <input id="ct-adq-identificacion" name="identificacion_propiedad" class="form-control" maxlength="120">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <?php ctRenderFieldLabel('Superficie m2', true); ?>
                                            <input id="ct-adq-superficie" name="superficie_m2" class="form-control ct-superficie-input" inputmode="decimal" required>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <?php
                                            ctRenderSearchableSelectField([
                                                'wrapper_class' => '',
                                                'label' => 'Comuna',
                                                'input_name' => 'id_comuna',
                                                'input_id' => 'ct-adq-comuna',
                                                'picker_id' => 'ct-adq-comuna-picker',
                                                'button_id' => 'ct-adq-comuna-btn',
                                                'filter_id' => 'ct-adq-comuna-filter',
                                                'list_id' => 'ct-adq-comuna-list',
                                                'error_id' => 'ct-adq-comuna-error',
                                                'error_message' => 'Debes seleccionar una comuna.',
                                                'button_placeholder' => 'Selecciona comuna...',
                                                'filter_placeholder' => 'Buscar comuna...',
                                                'required' => true,
                                                'show_requirement' => true,
                                                'options' => $comunaOptions,
                                            ]);
                                            ?>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <?php
                                            ctRenderSearchableSelectField([
                                                'wrapper_class' => '',
                                                'label' => 'Tipo',
                                                'input_name' => 'id_tipo_inmueble',
                                                'input_id' => 'ct-adq-tipo-inmueble',
                                                'picker_id' => 'ct-adq-tipo-inmueble-picker',
                                                'button_id' => 'ct-adq-tipo-inmueble-btn',
                                                'filter_id' => 'ct-adq-tipo-inmueble-filter',
                                                'list_id' => 'ct-adq-tipo-inmueble-list',
                                                'error_id' => 'ct-adq-tipo-inmueble-error',
                                                'error_message' => 'Debes seleccionar un tipo.',
                                                'button_placeholder' => 'Selecciona tipo...',
                                                'filter_placeholder' => 'Buscar tipo...',
                                                'required' => true,
                                                'show_requirement' => true,
                                                'options' => $tipoInmuebleOptions,
                                            ]);
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="ct-adq-step-2-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#ct-adq-step-2" aria-expanded="false" aria-controls="ct-adq-step-2">
                                    Paso 2. Titularidad inicial
                                </button>
                            </h2>
                            <div id="ct-adq-step-2" class="accordion-collapse collapse" aria-labelledby="ct-adq-step-2-header" data-bs-parent="#ct-adq-accordion">
                                <div class="accordion-body">
                                    <div class="ct-adq-titulares-shell">
                                        <div class="ct-adq-titulares-head">
                                            <div>
                                                <div class="ct-adq-titulares-title">Titulares iniciales</div>
                                                <div class="ct-adq-titulares-subtitle">Define titulares y participaciones para dejar la adquisición lista.</div>
                                            </div>
                                            <div class="ct-adq-titulares-kpis">
                                                <span class="ct-adq-kpi">Filas: <strong id="ct-adq-titulares-count">1</strong></span>
                                                <span class="ct-adq-kpi">Total: <strong id="ct-adq-titulares-total" class="ct-adq-total-value">100.00%</strong></span>
                                                <span class="ct-adq-kpi">Control: <strong>100% requerido</strong></span>
                                            </div>
                                        </div>

                                        <div class="table-responsive ct-adq-titulares-wrap">
                                            <table class="table table-sm align-middle mb-0 ct-adq-titulares-table" id="ct-adq-titulares-table">
                                                <thead>
                                                <tr>
                                                    <th style="width: 56px;" class="text-center">#</th>
                                                    <th style="min-width: 300px;">Titular</th>
                                                    <th style="min-width: 140px;" class="text-end">Participación</th>
                                                    <th style="min-width: 170px;">Vigente desde</th>
                                                    <th style="min-width: 170px;">Vigente hasta</th>
                                                    <th class="text-center" style="width: 78px;">Quitar</th>
                                                </tr>
                                                </thead>
                                                <tbody id="ct-adq-titulares-body">
                                                <tr class="ct-adq-titular-row">
                                                    <td class="text-center">
                                                        <span class="ct-adq-row-index">1</span>
                                                    </td>
                                                    <td>
                                                        <select name="titulares_id_tercero[]" class="form-select form-select-sm">
                                                            <option value="">Selecciona titular...</option>
                                                            <?php foreach ($tercerosSelectOptions as $terceroOpt): ?>
                                                                <option value="<?php echo ctEscape((string) ($terceroOpt['value'] ?? '')); ?>">
                                                                    <?php echo ctEscape((string) ($terceroOpt['label'] ?? '')); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            <input type="text" name="titulares_porcentaje_derecho[]" class="form-control text-end ct-adq-titular-pct" value="100" inputmode="decimal">
                                                            <span class="input-group-text">%</span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <input type="date" name="titulares_vigente_desde[]" class="form-control form-control-sm" value="<?php echo ctEscape($todayDate); ?>">
                                                    </td>
                                                    <td>
                                                        <input type="date" name="titulares_vigente_hasta[]" class="form-control form-control-sm">
                                                    </td>
                                                    <td class="text-center">
                                                        <button type="button" class="btn btn-sm btn-outline-danger ct-adq-remove-titular-row" title="Quitar fila" aria-label="Quitar titular">
                                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-2 gap-2">
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="ct-adq-add-titular-row">
                                                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar titular
                                            </button>
                                            <div class="small text-muted ct-adq-titulares-note">La suma debe cerrar en 100.00% y al menos un titular valido.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header" id="ct-adq-step-3-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#ct-adq-step-3" aria-expanded="false" aria-controls="ct-adq-step-3">
                                    Paso 3. Formalizacion
                                </button>
                            </h2>
                            <div id="ct-adq-step-3" class="accordion-collapse collapse" aria-labelledby="ct-adq-step-3-header" data-bs-parent="#ct-adq-accordion">
                                <div class="accordion-body">
                                    <div class="small text-muted mb-2">Registra el respaldo documental de la adquisición.</div>
                                    <div class="row g-2">
                                        <div class="col-12 col-md-6">
                                            <?php ctRenderFieldLabel('Fecha adquisición', true); ?>
                                            <input type="date" id="ct-adq-fecha" name="fecha_adquisicion" class="form-control" value="<?php echo ctEscape($todayDate); ?>" required>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <?php ctRenderFieldLabel('Documento fuente', false); ?>
                                            <input id="ct-adq-documento" name="documento_fuente" class="form-control" maxlength="255" placeholder="Escritura, repertorio, folio...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar adquisición</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade ct-terrenos-modal ct-crud-modal" id="ct-modal-registrar-titularidad" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="ct-form-registrar-titularidad" data-ct-disable-submit="1">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Registrar titularidad</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="registrar_titularidad">

                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Terreno',
                            'input_name' => 'id_terreno',
                            'input_id' => 'ct-titularidad-id-terreno',
                            'picker_id' => 'ct-titularidad-id-terreno-picker',
                            'button_id' => 'ct-titularidad-id-terreno-btn',
                            'filter_id' => 'ct-titularidad-id-terreno-filter',
                            'list_id' => 'ct-titularidad-id-terreno-list',
                            'error_id' => 'ct-titularidad-id-terreno-error',
                            'error_message' => 'Debes seleccionar un terreno.',
                            'button_placeholder' => 'Selecciona terreno...',
                            'filter_placeholder' => 'Buscar terreno...',
                            'required' => true,
                            'show_requirement' => true,
                            'options' => $terrenosSelectOptions,
                        ]);
                        ?>
                    </div>
                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Tercero titular',
                            'input_name' => 'id_tercero',
                            'input_id' => 'ct-titularidad-id-tercero',
                            'picker_id' => 'ct-titularidad-id-tercero-picker',
                            'button_id' => 'ct-titularidad-id-tercero-btn',
                            'filter_id' => 'ct-titularidad-id-tercero-filter',
                            'list_id' => 'ct-titularidad-id-tercero-list',
                            'error_id' => 'ct-titularidad-id-tercero-error',
                            'error_message' => 'Debes seleccionar un tercero.',
                            'button_placeholder' => 'Selecciona tercero...',
                            'filter_placeholder' => 'Buscar tercero...',
                            'required' => true,
                            'show_requirement' => true,
                            'options' => $tercerosSelectOptions,
                        ]);
                        ?>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Porcentaje de derecho', true); ?>
                        <input id="ct-titularidad-porcentaje" name="porcentaje_derecho" class="form-control" inputmode="decimal" value="100" required>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Vigente desde', true); ?>
                        <input type="date" id="ct-titularidad-vigente-desde" name="vigente_desde" class="form-control" value="<?php echo ctEscape($todayDate); ?>" required>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Vigente hasta', false); ?>
                        <input type="date" id="ct-titularidad-vigente-hasta" name="vigente_hasta" class="form-control">
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="ct-titularidad-cerrar-vigente" name="cerrar_vigente_actual">
                        <label class="form-check-label" for="ct-titularidad-cerrar-vigente">
                            Cerrar vigencia actual del mismo tercero en este terreno
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar titularidad</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade ct-terrenos-modal ct-crud-modal" id="ct-modal-registrar-subdivision" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl ct-subdivision-modal-dialog">
        <div class="modal-content">
            <form method="post" id="ct-form-registrar-subdivision" data-ct-disable-submit="1">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Registrar subdivisión</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="registrar_subdivision">

                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Terreno origen',
                            'input_name' => 'id_terreno_origen',
                            'input_id' => 'ct-subdivision-id-origen',
                            'picker_id' => 'ct-subdivision-id-origen-picker',
                            'button_id' => 'ct-subdivision-id-origen-btn',
                            'filter_id' => 'ct-subdivision-id-origen-filter',
                            'list_id' => 'ct-subdivision-id-origen-list',
                            'error_id' => 'ct-subdivision-id-origen-error',
                            'error_message' => 'Debes seleccionar un terreno origen.',
                            'button_placeholder' => 'Selecciona terreno disponible...',
                            'filter_placeholder' => 'Buscar disponible por rol o propietario...',
                            'required' => true,
                            'show_requirement' => true,
                            'options' => $terrenosSubdivisionSelectOptions,
                        ]);
                        ?>
                    </div>
                    <div class="small text-muted mb-2">Solo se muestran terrenos con estado <strong>Disponible</strong>.</div>

                    <div class="ct-subdivision-origen-resumen mb-2" id="ct-subdivision-origen-resumen">
                        <input type="hidden" id="ct-subdivision-superficie-origen" value="">
                        <div class="ct-subdivision-origen-title">Terreno origen seleccionado</div>
                        <div class="ct-subdivision-origen-grid">
                            <div class="ct-subdivision-origen-item">
                                <span class="ct-subdivision-origen-key">Rol origen</span>
                                <strong id="ct-subdivision-origen-rol">-</strong>
                            </div>
                            <div class="ct-subdivision-origen-item">
                                <span class="ct-subdivision-origen-key">Superficie origen</span>
                                <strong id="ct-subdivision-origen-superficie">-</strong>
                            </div>
                            <div class="ct-subdivision-origen-item">
                                <span class="ct-subdivision-origen-key">Comuna</span>
                                <strong id="ct-subdivision-origen-comuna">-</strong>
                            </div>
                        </div>
                    </div>

                    <div class="ct-subdivision-resultados-shell mb-2">
                        <div class="ct-subdivision-resultados-head">
                            <div>
                                <div class="ct-subdivision-resultados-title">Terrenos resultado</div>
                                <div class="ct-subdivision-resultados-subtitle">Define rol y superficie de cada nuevo terreno. El rol matriz se asigna automático con el rol del origen.</div>
                            </div>
                            <div class="ct-subdivision-resultados-kpis">
                                <span class="ct-subdivision-kpi">Filas: <strong id="ct-subdivision-result-count">2</strong></span>
                                <span class="ct-subdivision-kpi">Suma: <strong id="ct-subdivision-result-total" class="ct-subdivision-total-value">0.00 m²</strong></span>
                            </div>
                        </div>

                        <div class="table-responsive ct-subdivision-resultados-wrap">
                            <table class="table table-sm align-middle mb-0 ct-subdivision-resultados-table">
                                <thead>
                                <tr>
                                    <th style="width: 56px;" class="text-center">#</th>
                                    <th style="min-width: 220px;">Rol asignado</th>
                                    <th style="min-width: 160px;" class="text-end">Superficie (m²)</th>
                                    <th style="width: 78px;" class="text-center">Quitar</th>
                                </tr>
                                </thead>
                                <tbody id="ct-subdivision-result-body">
                                <tr class="ct-subdivision-result-row">
                                    <td class="text-center"><span class="ct-subdivision-row-index">1</span></td>
                                    <td><input type="text" name="subdivision_result_rol_asignado[]" class="form-control form-control-sm" maxlength="30" placeholder="Ej: 123-45"></td>
                                    <td><input type="text" name="subdivision_result_superficie_m2[]" class="form-control form-control-sm text-end ct-subdivision-result-superficie" inputmode="decimal" placeholder="0,00"></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger ct-subdivision-remove-row" aria-label="Quitar resultado">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr class="ct-subdivision-result-row">
                                    <td class="text-center"><span class="ct-subdivision-row-index">2</span></td>
                                    <td><input type="text" name="subdivision_result_rol_asignado[]" class="form-control form-control-sm" maxlength="30" placeholder="Ej: 123-46"></td>
                                    <td><input type="text" name="subdivision_result_superficie_m2[]" class="form-control form-control-sm text-end ct-subdivision-result-superficie" inputmode="decimal" placeholder="0,00"></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger ct-subdivision-remove-row" aria-label="Quitar resultado">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-2 gap-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="ct-subdivision-add-row">
                                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar resultado
                            </button>
                            <div class="small text-muted">La suma de superficie debe coincidir exactamente con la superficie del origen.</div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Fecha operación', true); ?>
                        <input type="date" id="ct-subdivision-fecha-operacion" name="fecha_operacion" class="form-control" value="<?php echo ctEscape($todayDate); ?>" required>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Documento fuente', false); ?>
                        <input id="ct-subdivision-documento" name="documento_fuente" class="form-control" maxlength="255">
                    </div>
                    <div class="small text-muted mb-0">
                        Al registrar: el terreno origen cambia automáticamente a <strong>Subdividido</strong> y cada resultado queda en <strong>Disponible</strong>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar subdivisión</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade ct-terrenos-modal ct-crud-modal" id="ct-modal-registrar-fusion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl ct-fusion-modal-dialog">
        <div class="modal-content">
            <form method="post" id="ct-form-registrar-fusion" data-ct-disable-submit="1">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Registrar fusión</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="registrar_fusion">
                    <input type="hidden" id="ct-fusion-ids-origen" name="ids_terrenos_origen" value="" required>

                    <div class="ct-fusion-origen-shell mb-2">
                        <div class="ct-fusion-origen-head">
                            <div>
                                <div class="ct-fusion-origen-title">Terrenos origen</div>
                                <div class="ct-fusion-origen-subtitle">Selecciona los terrenos que se fusionarán. Mínimo 2 orígenes.</div>
                            </div>
                            <div class="ct-fusion-origen-kpis">
                                <span class="ct-fusion-kpi">Orígenes: <strong id="ct-fusion-origen-count">0</strong></span>
                                <span class="ct-fusion-kpi">Superficie: <strong id="ct-fusion-origen-total">0.00 m²</strong></span>
                            </div>
                        </div>

                        <div class="row g-2 align-items-end mb-2">
                            <div class="col-12 col-lg-9">
                                <?php
                                ctRenderSearchableSelectField([
                                    'wrapper_class' => 'col-12',
                                    'label' => 'Agregar terreno origen',
                                    'input_name' => 'fusion_origen_selector',
                                    'input_id' => 'ct-fusion-origen-selector',
                                    'picker_id' => 'ct-fusion-origen-selector-picker',
                                    'button_id' => 'ct-fusion-origen-selector-btn',
                                    'filter_id' => 'ct-fusion-origen-selector-filter',
                                    'list_id' => 'ct-fusion-origen-selector-list',
                                    'error_id' => 'ct-fusion-origen-selector-error',
                                    'error_message' => 'Selecciona un terreno origen.',
                                    'button_placeholder' => 'Selecciona terreno disponible...',
                                    'filter_placeholder' => 'Buscar disponible por rol o propietario...',
                                    'required' => false,
                                    'show_requirement' => true,
                                    'optional_text' => 'opcional',
                                    'options' => $terrenosFusionOrigenSelectOptions,
                                ]);
                                ?>
                                <div class="small text-muted mt-1">Solo se muestran terrenos con estado <strong>Disponible</strong>.</div>
                            </div>
                            <div class="col-12 col-lg-3 d-grid">
                                <button type="button" class="btn btn-outline-primary" id="ct-fusion-add-origen">
                                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar origen
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive ct-fusion-origen-wrap">
                            <table class="table table-sm align-middle mb-0 ct-fusion-origen-table">
                                <thead>
                                <tr>
                                    <th style="width: 56px;" class="text-center">#</th>
                                    <th>Rol</th>
                                    <th>Propietario</th>
                                    <th>Comuna</th>
                                    <th class="text-end">Superficie (m²)</th>
                                    <th style="width: 78px;" class="text-center">Quitar</th>
                                </tr>
                                </thead>
                                <tbody id="ct-fusion-origen-body">
                                <tr class="ct-fusion-origen-empty">
                                    <td colspan="6" class="text-muted text-center py-2">Aún no agregas terrenos origen.</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="ct-fusion-resultado-shell mb-2">
                        <div class="ct-fusion-resultado-mode mb-2">
                            <input class="btn-check" type="radio" name="fusion_resultado_modo" id="ct-fusion-resultado-modo-nuevo" value="nuevo" checked>
                            <label class="btn btn-outline-primary btn-sm" for="ct-fusion-resultado-modo-nuevo">Crear nuevo resultado</label>
                            <input class="btn-check" type="radio" name="fusion_resultado_modo" id="ct-fusion-resultado-modo-existente" value="existente">
                            <label class="btn btn-outline-secondary btn-sm" for="ct-fusion-resultado-modo-existente">Usar terreno existente</label>
                        </div>

                        <div id="ct-fusion-resultado-nuevo-block">
                            <div class="row g-2">
                                <div class="col-12 col-md-8">
                                    <?php ctRenderFieldLabel('Rol asignado resultado', true); ?>
                                    <input type="text" id="ct-fusion-resultado-nuevo-rol" name="fusion_resultado_nuevo_rol_asignado" class="form-control" maxlength="30" placeholder="Ej: E-50">
                                </div>
                                <div class="col-12 col-md-4">
                                    <?php ctRenderFieldLabel('Superficie estimada', false); ?>
                                    <input type="text" id="ct-fusion-resultado-nuevo-superficie" class="form-control text-end" readonly value="0,00 m²">
                                </div>
                            </div>
                            <div class="small text-muted mt-1">La superficie del nuevo resultado se calcula automáticamente con la suma de orígenes.</div>
                        </div>

                        <div id="ct-fusion-resultado-existente-block" class="d-none">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Terreno resultado',
                            'input_name' => 'id_terreno_resultado',
                            'input_id' => 'ct-fusion-id-resultado',
                            'picker_id' => 'ct-fusion-id-resultado-picker',
                            'button_id' => 'ct-fusion-id-resultado-btn',
                            'filter_id' => 'ct-fusion-id-resultado-filter',
                            'list_id' => 'ct-fusion-id-resultado-list',
                            'error_id' => 'ct-fusion-id-resultado-error',
                            'error_message' => 'Debes seleccionar un terreno resultado.',
                            'button_placeholder' => 'Selecciona terreno subdividido...',
                            'filter_placeholder' => 'Buscar subdividido por rol o propietario...',
                            'required' => false,
                            'show_requirement' => true,
                            'options' => $terrenosFusionResultadoExistenteSelectOptions,
                        ]);
                        ?>
                        <div class="small text-muted mt-1">Solo se muestran terrenos con estado <strong>Subdividido</strong>.</div>
                        <div class="ct-fusion-resultado-resumen mt-2">
                            <div class="ct-fusion-resultado-item">
                                <span class="ct-fusion-resultado-key">Rol resultado</span>
                                <strong id="ct-fusion-resultado-rol">-</strong>
                            </div>
                            <div class="ct-fusion-resultado-item">
                                <span class="ct-fusion-resultado-key">Superficie</span>
                                <strong id="ct-fusion-resultado-superficie">-</strong>
                            </div>
                            <div class="ct-fusion-resultado-item">
                                <span class="ct-fusion-resultado-key">Comuna</span>
                                <strong id="ct-fusion-resultado-comuna">-</strong>
                            </div>
                        </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Fecha operación', true); ?>
                        <input type="date" id="ct-fusion-fecha-operacion" name="fecha_operacion" class="form-control" value="<?php echo ctEscape($todayDate); ?>" required>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Documento fuente', false); ?>
                        <input id="ct-fusion-documento" name="documento_fuente" class="form-control" maxlength="255">
                    </div>
                    <div class="small text-muted mb-0">
                        Al registrar: los orígenes cambian automáticamente a <strong>Fusionado</strong> y el terreno resultado queda en <strong>Disponible</strong>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Registrar fusión</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade ct-terrenos-modal ct-crud-modal" id="ct-modal-registrar-tasacion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" data-ct-disable-submit="1">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Registrar tasación</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="registrar_tasacion">
                    <div class="ct-modal-hint">Ingresa valor total UF o UF/m²; el sistema completa automáticamente el campo faltante según la superficie del terreno.</div>
                    <div class="row g-2">
                        <div class="col-12 col-md-6">
                            <?php
                            ctRenderSearchableSelectField([
                                'wrapper_class' => '',
                                'label' => 'Terreno',
                                'input_name' => 'id_terreno',
                                'input_id' => 'ct-tasacion-id-terreno',
                                'picker_id' => 'ct-tasacion-id-terreno-picker',
                                'button_id' => 'ct-tasacion-id-terreno-btn',
                                'filter_id' => 'ct-tasacion-id-terreno-filter',
                                'list_id' => 'ct-tasacion-id-terreno-list',
                                'error_id' => 'ct-tasacion-id-terreno-error',
                                'error_message' => 'Debes seleccionar un terreno.',
                                'button_placeholder' => 'Selecciona terreno...',
                                'filter_placeholder' => 'Buscar terreno...',
                                'required' => true,
                                'show_requirement' => true,
                                'options' => $terrenosTasacionSelectOptions,
                            ]);
                            ?>
                            <div class="form-text" id="ct-tasacion-superficie-info">Superficie: selecciona un terreno.</div>
                        </div>
                        <div class="col-12 col-md-6">
                            <?php
                            ctRenderSearchableSelectField([
                                'wrapper_class' => '',
                                'label' => 'Tipo tasación',
                                'input_name' => 'id_tipo_tasacion',
                                'input_id' => 'ct-tasacion-tipo',
                                'picker_id' => 'ct-tasacion-tipo-picker',
                                'button_id' => 'ct-tasacion-tipo-btn',
                                'filter_id' => 'ct-tasacion-tipo-filter',
                                'list_id' => 'ct-tasacion-tipo-list',
                                'error_id' => 'ct-tasacion-tipo-error',
                                'error_message' => 'Debes seleccionar un tipo de tasación.',
                                'button_placeholder' => 'Selecciona tipo...',
                                'filter_placeholder' => 'Buscar tipo...',
                                'required' => true,
                                'show_requirement' => true,
                                'options' => $tiposTasacionSelectOptions,
                            ]);
                            ?>
                        </div>
                        <div class="col-12 col-md-4">
                            <?php
                            ctRenderSearchableSelectField([
                                'wrapper_class' => '',
                                'label' => 'Entidad financiera',
                                'input_name' => 'id_entidad_financiera',
                                'input_id' => 'ct-tasacion-id-entidad-financiera',
                                'picker_id' => 'ct-tasacion-id-entidad-financiera-picker',
                                'button_id' => 'ct-tasacion-id-entidad-financiera-btn',
                                'filter_id' => 'ct-tasacion-id-entidad-financiera-filter',
                                'list_id' => 'ct-tasacion-id-entidad-financiera-list',
                                'error_id' => 'ct-tasacion-id-entidad-financiera-error',
                                'error_message' => 'Entidad financiera inválida.',
                                'button_placeholder' => 'Sin entidad',
                                'filter_placeholder' => 'Buscar banco...',
                                'required' => false,
                                'show_requirement' => true,
                                'optional_text' => 'opcional',
                                'options' => $entidadesFinancierasSelectOptions,
                            ]);
                            ?>
                        </div>
                        <div class="col-12 col-md-4">
                            <?php ctRenderFieldLabel('Fecha tasación', true); ?>
                            <input type="date" class="form-control ct-control-input" id="ct-tasacion-fecha" name="fecha_tasacion" value="<?php echo ctEscape($todayDate); ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <?php ctRenderFieldLabel('Valor total UF', false); ?>
                            <input type="number" step="0.01" min="0.01" class="form-control ct-control-input" id="ct-tasacion-valor-total" name="valor_total_uf">
                        </div>
                        <div class="col-12 col-md-4">
                            <?php ctRenderFieldLabel('Valor UF/m²', false); ?>
                            <input type="number" step="0.0001" min="0.0001" class="form-control ct-control-input" id="ct-tasacion-valor-m2" name="valor_uf_m2">
                        </div>
                        <div class="col-12 col-md-4">
                            <?php ctRenderFieldLabel('Vigente desde', false); ?>
                            <input type="date" class="form-control ct-control-input" id="ct-tasacion-vigente-desde" name="vigente_desde">
                        </div>
                        <div class="col-12 col-md-4">
                            <?php ctRenderFieldLabel('Vigente hasta', false); ?>
                            <input type="date" class="form-control ct-control-input" id="ct-tasacion-vigente-hasta" name="vigente_hasta">
                        </div>
                        <div class="col-12 col-md-4 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" value="1" id="ct-tasacion-referencial" name="es_referencial">
                                <label class="form-check-label" for="ct-tasacion-referencial">Marcar como referencial</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar tasación</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade ct-terrenos-modal ct-crud-modal" id="ct-modal-registrar-venta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" data-ct-disable-submit="1">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Registrar venta</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="registrar_venta">
                    <div class="ct-modal-hint">Ingresa valor total UF o UF/m² y completa compradores. La suma de porcentajes debe ser exactamente 100.00%.</div>
                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-4">
                            <?php
                            ctRenderSearchableSelectField([
                                'wrapper_class' => '',
                                'label' => 'Terreno',
                                'input_name' => 'id_terreno',
                                'input_id' => 'ct-venta-id-terreno',
                                'picker_id' => 'ct-venta-id-terreno-picker',
                                'button_id' => 'ct-venta-id-terreno-btn',
                                'filter_id' => 'ct-venta-id-terreno-filter',
                                'list_id' => 'ct-venta-id-terreno-list',
                                'error_id' => 'ct-venta-id-terreno-error',
                                'error_message' => 'Debes seleccionar un terreno.',
                                'button_placeholder' => 'Selecciona terreno...',
                                'filter_placeholder' => 'Buscar terreno...',
                                'required' => true,
                                'show_requirement' => true,
                                'options' => $terrenosVentaSelectOptions,
                            ]);
                            ?>
                            <div class="form-text" id="ct-venta-superficie-info">Superficie: selecciona un terreno.</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <?php ctRenderFieldLabel('Fecha venta', true); ?>
                            <input type="date" class="form-control ct-control-input" id="ct-venta-fecha" name="fecha_venta" value="<?php echo ctEscape($todayDate); ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <?php
                            ctRenderSearchableSelectField([
                                'wrapper_class' => '',
                                'label' => 'Tasación referencial',
                                'input_name' => 'id_tasacion_referencial',
                                'input_id' => 'ct-venta-tasacion-ref',
                                'picker_id' => 'ct-venta-tasacion-ref-picker',
                                'button_id' => 'ct-venta-tasacion-ref-btn',
                                'filter_id' => 'ct-venta-tasacion-ref-filter',
                                'list_id' => 'ct-venta-tasacion-ref-list',
                                'error_id' => 'ct-venta-tasacion-ref-error',
                                'error_message' => 'Tasación referencial inválida.',
                                'button_placeholder' => 'Sin referencia',
                                'filter_placeholder' => 'Buscar tasación...',
                                'required' => false,
                                'show_requirement' => true,
                                'optional_text' => 'opcional',
                                'options' => $tasacionesReferencialesSelectOptions,
                            ]);
                            ?>
                        </div>
                        <div class="col-12 col-md-6">
                            <?php ctRenderFieldLabel('Valor total UF', false); ?>
                            <input type="number" step="0.01" min="0.01" class="form-control ct-control-input" id="ct-venta-valor-total" name="valor_total_uf">
                        </div>
                        <div class="col-12 col-md-6">
                            <?php ctRenderFieldLabel('Valor venta UF/m²', false); ?>
                            <input type="number" step="0.0001" min="0.0001" class="form-control ct-control-input" id="ct-venta-valor-m2" name="valor_venta_uf_m2">
                        </div>
                    </div>

                    <div class="ct-venta-compradores-shell">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h4 class="h6 mb-0">Compradores y porcentajes</h4>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="ct-venta-add-comprador">
                                <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Agregar comprador
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0 ct-venta-compradores-table">
                                <thead>
                                <tr>
                                    <th>Tercero</th>
                                    <th>%</th>
                                    <th>Rol en venta</th>
                                    <th class="text-center">Quitar</th>
                                </tr>
                                </thead>
                                <tbody id="ct-venta-compradores-body">
                                <tr class="ct-venta-comprador-row">
                                    <td>
                                        <select class="form-select form-select-sm" name="venta_id_tercero[]" required>
                                            <option value="">Seleccionar...</option>
                                            <?php foreach ($tercerosSelector as $tercero): ?>
                                                <?php
                                                $idTercero = (int) ($tercero['id_tercero'] ?? 0);
                                                $nombreTercero = trim((string) ($tercero['nombre_razon_social'] ?? ''));
                                                if ($idTercero <= 0 || $nombreTercero === '') {
                                                    continue;
                                                }
                                                ?>
                                                <option value="<?php echo $idTercero; ?>"><?php echo ctEscape($nombreTercero); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="number" step="0.01" min="0.01" max="100" class="form-control form-control-sm" name="venta_porcentaje[]" required></td>
                                    <td><input type="text" maxlength="30" class="form-control form-control-sm" name="venta_rol[]" placeholder="Comprador / Cesionario"></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger js-remove-comprador" title="Quitar fila">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
                                        </button>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar venta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade ct-terrenos-modal ct-crud-modal" id="ct-modal-eliminar-terreno" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" data-ct-disable-submit="1">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Eliminar terreno</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="eliminar_terreno">
                    <input type="hidden" name="id_terreno" id="ct-delete-id">

                    <p class="mb-0">Se eliminará el terreno <strong id="ct-delete-nombre"></strong>.</p>
                    <div class="small text-muted mt-2">Si el terreno tiene titularidad, ventas u otras relaciones, la base bloqueará la eliminación.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Eliminar</button>
                </div>
            </form>
        </div>
    </div>
</div>
