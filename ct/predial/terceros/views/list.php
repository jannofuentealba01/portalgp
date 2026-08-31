<?php
declare(strict_types=1);
?>
<section id="terceros" class="mt-3 ct-crud-fade-in">
    <div class="ct-terceros-toolbar ct-crud-toolbar d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <div class="ct-terceros-toolbar-title ct-crud-toolbar-title small text-muted">Administración de terceros prediales</div>
            <div class="ct-terceros-toolbar-hint ct-crud-toolbar-hint small text-muted">Un tercero puede ser propietario (titularidad de terreno), cliente (compra registrada) o ambos.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if (is_array($importPreview)): ?>
                <button class="btn btn-outline-success ct-crud-btn-main" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-preview-importacion-terceros">
                    <i class="bi bi-table me-1" aria-hidden="true"></i>Continuar importación
                </button>
            <?php endif; ?>
            <button class="btn btn-outline-primary ct-crud-btn-main" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-importar-terceros">
                <i class="bi bi-file-earmark-arrow-up me-1" aria-hidden="true"></i>Importar Excel
            </button>
            <button class="btn btn-primary ct-crud-btn-main" type="button" data-bs-toggle="modal" data-bs-target="#ct-modal-crear-tercero">
                <i class="bi bi-person-plus me-1" aria-hidden="true"></i>Registrar tercero
            </button>
        </div>
    </div>

    <?php if ($tercerosError !== null): ?>
        <div class="alert alert-warning mb-3"><?php echo ctEscape($tercerosError); ?></div>
    <?php endif; ?>

    <form class="ct-terceros-filtros ct-crud-filters row g-2 align-items-end mb-3" method="get" id="ct-terceros-filtros-form">
        <div class="col-12 col-md-3">
            <label class="form-label small text-muted" for="ct-filtro-nombre">Nombre / Razón social</label>
            <input class="form-control ct-control-input" id="ct-filtro-nombre" name="filtroNombre" value="<?php echo ctEscape($filtroNombre); ?>" placeholder="Buscar por nombre">
        </div>
        <div class="col-12 col-md-2">
            <label class="form-label small text-muted" for="ct-filtro-rut">RUT</label>
            <input class="form-control ct-control-input" id="ct-filtro-rut" name="filtroRut" value="<?php echo ctEscape($filtroRutDisplay); ?>" placeholder="Buscar por RUT">
        </div>
        <div class="col-12 col-md-2">
            <?php
            ctRenderSearchableSelectField([
                'wrapper_class' => '',
                'label' => 'Tipo persona',
                'input_name' => 'filtroTipo',
                'input_id' => 'ct-filtro-tipo',
                'picker_id' => 'ct-filtro-tipo-picker',
                'button_id' => 'ct-filtro-tipo-btn',
                'filter_id' => 'ct-filtro-tipo-filter',
                'list_id' => 'ct-filtro-tipo-list',
                'error_id' => 'ct-filtro-tipo-error',
                'error_message' => 'Debes seleccionar un tipo de persona.',
                'button_placeholder' => 'Todos',
                'filter_placeholder' => 'Buscar tipo de persona...',
                'value' => $filtroTipo,
                'options' => [
                    ['value' => '', 'label' => 'Todos', 'search' => 'todos'],
                    ['value' => 'N', 'label' => 'Persona Natural', 'search' => 'persona natural n'],
                    ['value' => 'J', 'label' => 'Persona Jurídica', 'search' => 'persona juridica j'],
                ],
            ]);
            ?>
        </div>
        <div class="col-12 col-md-2">
            <?php
            ctRenderSearchableSelectField([
                'wrapper_class' => '',
                'label' => 'Tipo',
                'input_name' => 'filtroRelacion',
                'input_id' => 'ct-filtro-relacion',
                'picker_id' => 'ct-filtro-relacion-picker',
                'button_id' => 'ct-filtro-relacion-btn',
                'filter_id' => 'ct-filtro-relacion-filter',
                'list_id' => 'ct-filtro-relacion-list',
                'error_id' => 'ct-filtro-relacion-error',
                'error_message' => 'Debes seleccionar un tipo.',
                'button_placeholder' => 'Todos',
                'filter_placeholder' => 'Buscar tipo...',
                'value' => $filtroRelacion,
                'options' => [
                    ['value' => '', 'label' => 'Todos', 'search' => 'todos'],
                    ['value' => 'P', 'label' => 'Propietario', 'search' => 'propietario p'],
                    ['value' => 'C', 'label' => 'Cliente', 'search' => 'cliente c'],
                ],
            ]);
            ?>
        </div>
        <div class="col-12 col-md-2">
            <?php
            $lineasOptions = [];
            foreach ($lineasPermitidas as $lineas) {
                $lineasOptions[] = [
                    'value' => (string) (int) $lineas,
                    'label' => (string) (int) $lineas,
                    'search' => (string) (int) $lineas,
                ];
            }
            ctRenderSearchableSelectField([
                'wrapper_class' => '',
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
            ?>
        </div>
        <div class="col-12 col-md-1 d-grid">
            <button type="submit" class="btn btn-outline-primary ct-filter-submit ct-crud-filter-submit">
                <i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar
            </button>
        </div>
    </form>

    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo $vista === 'tabla' ? 'active' : ''; ?>" href="<?php echo ctEscape(ctTercerosBuildQuery($queryBase, ['vista' => 'tabla'])); ?>">Tabla</a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?php echo $vista === 'cards' ? 'active' : ''; ?>" href="<?php echo ctEscape(ctTercerosBuildQuery($queryBase, ['vista' => 'cards'])); ?>">Cards</a>
        </li>
    </ul>

    <div class="tab-content border border-top-0 rounded-bottom p-3 bg-white">
        <?php if ($vista === 'cards'): ?>
            <?php if ($terceros === []): ?>
                <div class="text-muted text-center py-4">Sin terceros registrados.</div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($terceros as $tercero): ?>
                        <div class="col-12 col-md-6 col-xl-4">
                            <div class="card h-100 border">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="small text-muted">ID #<?php echo ctEscape((string) $tercero['id_tercero']); ?></div>
                                            <h3 class="h6 mb-1"><?php echo ctEscape((string) $tercero['nombre_razon_social']); ?></h3>
                                            <div class="text-muted small"><?php echo ctEscape(ctTercerosFormatRutDisplay((string) $tercero['rut'])); ?></div>
                                        </div>
                                        <span class="badge bg-light text-dark border"><?php echo ctEscape(ctTercerosTipoPersonaLabel((string) $tercero['tipo_persona'])); ?></span>
                                    </div>
                                </div>
                                <div class="card-footer bg-white border-0 pt-0">
                                    <div class="ct-crud-actions">
                                        <button class="btn btn-outline-secondary btn-sm ct-btn-editar" type="button" title="Editar tercero"
                                                data-id="<?php echo ctEscape((string) $tercero['id_tercero']); ?>"
                                                data-tipo="<?php echo ctEscape((string) $tercero['tipo_persona']); ?>"
                                                data-rut="<?php echo ctEscape((string) $tercero['rut']); ?>"
                                                data-nombre="<?php echo ctEscape((string) $tercero['nombre_razon_social']); ?>"
                                                data-bs-toggle="modal" data-bs-target="#ct-modal-editar-tercero">
                                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                            <span class="visually-hidden">Editar</span>
                                        </button>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Más acciones">
                                                <i class="bi bi-three-dots" aria-hidden="true"></i>
                                                <span class="visually-hidden">Más acciones</span>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <button class="dropdown-item text-danger ct-btn-eliminar" type="button"
                                                            data-id="<?php echo ctEscape((string) $tercero['id_tercero']); ?>"
                                                            data-nombre="<?php echo ctEscape((string) $tercero['nombre_razon_social']); ?>"
                                                            data-bs-toggle="modal" data-bs-target="#ct-modal-eliminar-tercero">
                                                        <i class="bi bi-trash me-2" aria-hidden="true"></i>Eliminar
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 ct-terceros-table ct-crud-table">
                    <thead>
                    <tr>
                        <th><a class="link-dark text-decoration-none" href="<?php echo ctEscape(ctTercerosSortLink('id_tercero', $queryBase, $orden, $direccion)); ?>">ID <i class="bi <?php echo ctEscape(ctTercerosSortIcon('id_tercero', $orden, $direccion)); ?>"></i></a></th>
                        <th><a class="link-dark text-decoration-none" href="<?php echo ctEscape(ctTercerosSortLink('tipo_persona', $queryBase, $orden, $direccion)); ?>">Tipo <i class="bi <?php echo ctEscape(ctTercerosSortIcon('tipo_persona', $orden, $direccion)); ?>"></i></a></th>
                        <th><a class="link-dark text-decoration-none" href="<?php echo ctEscape(ctTercerosSortLink('rut', $queryBase, $orden, $direccion)); ?>">RUT <i class="bi <?php echo ctEscape(ctTercerosSortIcon('rut', $orden, $direccion)); ?>"></i></a></th>
                        <th><a class="link-dark text-decoration-none" href="<?php echo ctEscape(ctTercerosSortLink('nombre_razon_social', $queryBase, $orden, $direccion)); ?>">Nombre / Razón social <i class="bi <?php echo ctEscape(ctTercerosSortIcon('nombre_razon_social', $orden, $direccion)); ?>"></i></a></th>
                        <th class="text-center">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($terceros === []): ?>
                        <tr><td colspan="5" class="text-muted text-center py-4">Sin terceros registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($terceros as $tercero): ?>
                            <tr>
                                <td><?php echo ctEscape((string) $tercero['id_tercero']); ?></td>
                                <td><?php echo ctEscape(ctTercerosTipoPersonaLabel((string) $tercero['tipo_persona'])); ?></td>
                                <td><?php echo ctEscape(ctTercerosFormatRutDisplay((string) $tercero['rut'])); ?></td>
                                <td><?php echo ctEscape((string) $tercero['nombre_razon_social']); ?></td>
                                <td class="text-center">
                                    <div class="ct-crud-actions">
                                        <button type="button" class="btn btn-outline-secondary btn-sm ct-btn-editar" title="Editar tercero"
                                                data-id="<?php echo ctEscape((string) $tercero['id_tercero']); ?>"
                                                data-tipo="<?php echo ctEscape((string) $tercero['tipo_persona']); ?>"
                                                data-rut="<?php echo ctEscape((string) $tercero['rut']); ?>"
                                                data-nombre="<?php echo ctEscape((string) $tercero['nombre_razon_social']); ?>"
                                                data-bs-toggle="modal" data-bs-target="#ct-modal-editar-tercero">
                                            <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                            <span class="visually-hidden">Editar</span>
                                        </button>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Más acciones">
                                                <i class="bi bi-three-dots" aria-hidden="true"></i>
                                                <span class="visually-hidden">Más acciones</span>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <button type="button" class="dropdown-item text-danger ct-btn-eliminar"
                                                            data-id="<?php echo ctEscape((string) $tercero['id_tercero']); ?>"
                                                            data-nombre="<?php echo ctEscape((string) $tercero['nombre_razon_social']); ?>"
                                                            data-bs-toggle="modal" data-bs-target="#ct-modal-eliminar-tercero">
                                                        <i class="bi bi-trash me-2" aria-hidden="true"></i>Eliminar
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
            <div class="small text-muted">
                Total: <strong><?php echo number_format((int) $totalRegistros, 0, ',', '.'); ?></strong>
                | Página <strong><?php echo (int) $paginaActual; ?></strong> de <strong><?php echo (int) $totalPaginas; ?></strong>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Paginación de terceros">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo ctEscape(ctTercerosBuildQuery($queryBase, ['pagina' => max(1, ((int) $paginaActual) - 1)])); ?>" aria-label="Anterior">&laquo;</a>
                        </li>
                        <?php foreach ($paginationItems as $item): ?>
                            <?php if ($item['page'] === null): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php else: ?>
                                <li class="page-item <?php echo $item['active'] ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo ctEscape(ctTercerosBuildQuery($queryBase, ['pagina' => $item['page']])); ?>"><?php echo ctEscape($item['label']); ?></a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo ctEscape(ctTercerosBuildQuery($queryBase, ['pagina' => min((int) $totalPaginas, ((int) $paginaActual) + 1)])); ?>" aria-label="Siguiente">&raquo;</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</section>
