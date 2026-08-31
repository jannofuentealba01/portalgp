<?php
declare(strict_types=1);
?>
<div class="modal fade ct-terceros-modal ct-crud-modal" id="ct-modal-crear-tercero" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Registrar tercero</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="crear_tercero">
                    <div class="ct-modal-hint">Crea un tercero para asociarlo luego a titularidad, ventas y operaciones.</div>
                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Tipo persona',
                            'input_name' => 'tipo_persona',
                            'input_id' => 'ct-crear-tipo',
                            'picker_id' => 'ct-crear-tipo-picker',
                            'button_id' => 'ct-crear-tipo-btn',
                            'filter_id' => 'ct-crear-tipo-filter',
                            'list_id' => 'ct-crear-tipo-list',
                            'error_id' => 'ct-crear-tipo-error',
                            'error_message' => 'Debes seleccionar el tipo de persona.',
                            'button_placeholder' => 'Selecciona tipo...',
                            'filter_placeholder' => 'Buscar tipo...',
                            'required' => true,
                            'show_requirement' => true,
                            'value' => 'N',
                            'options' => [
                                ['value' => 'N', 'label' => 'Persona Natural', 'search' => 'persona natural n'],
                                ['value' => 'J', 'label' => 'Persona Jurídica', 'search' => 'persona juridica j'],
                            ],
                        ]);
                        ?>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('RUT', false); ?>
                        <input id="ct-crear-rut" name="rut" class="form-control" maxlength="20">
                        <?php
                        ctRenderFormSwitch([
                            'id' => 'ct-crear-rut-enabled',
                            'label' => 'Ingresar RUT',
                            'checked' => true,
                            'help_text' => 'Si lo desactivas, se guardará sin RUT.',
                        ]);
                        ?>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Nombre / Razón social', true); ?>
                        <input name="nombre_razon_social" class="form-control" maxlength="200" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$previewRows = [];
$previewSummary = [
    'total' => 0,
    'selected' => 0,
    'ready' => 0,
    'errors' => 0,
    'warnings' => 0,
    'omitted' => 0,
    'create' => 0,
    'update' => 0,
    'unchanged' => 0,
];
$previewImportId = '';
$previewDefaultTipo = '';
if (is_array($importPreview)) {
    $previewRows = isset($importPreview['rows']) && is_array($importPreview['rows']) ? $importPreview['rows'] : [];
    $previewSummary = isset($importPreview['summary']) && is_array($importPreview['summary']) ? $importPreview['summary'] : $previewSummary;
    $previewImportId = trim((string) ($importPreview['id'] ?? ''));
    $previewDefaultTipoRaw = trim((string) ($importPreview['default_tipo_persona'] ?? ''));
    $previewDefaultTipo = ($previewDefaultTipoRaw === 'N' || $previewDefaultTipoRaw === 'J') ? $previewDefaultTipoRaw : '';
}
$templateDownloadUrl = ctTercerosBuildQuery($queryBase, ['descargar_plantilla' => '1']);
?>

<div class="modal fade ct-terceros-modal ct-crud-modal" id="ct-modal-importar-terceros" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Importar terceros desde Excel</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="preview_importacion_terceros">
                    <div class="ct-modal-hint">
                        Sube un archivo <strong>.xlsx</strong> (o <strong>.csv</strong>).
                        La columna <code>nombre_razon_social</code> es obligatoria; <code>tipo_persona</code> y <code>rut</code> son opcionales.
                    </div>
                    <div class="mb-2">
                        <a class="btn ct-template-btn" href="<?php echo ctEscape($templateDownloadUrl); ?>">
                            <i class="bi bi-file-earmark-arrow-down me-2" aria-hidden="true"></i>
                            <span>Descargar plantilla Excel</span>
                        </a>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Tipo por defecto para filas sin tipo', false); ?>
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => '',
                            'input_name' => 'tipo_persona_default',
                            'input_id' => 'ct-import-upload-default-tipo',
                            'picker_id' => 'ct-import-upload-default-tipo-picker',
                            'button_id' => 'ct-import-upload-default-tipo-btn',
                            'filter_id' => 'ct-import-upload-default-tipo-filter',
                            'list_id' => 'ct-import-upload-default-tipo-list',
                            'error_id' => 'ct-import-upload-default-tipo-error',
                            'error_message' => 'Debes seleccionar un tipo valido.',
                            'button_placeholder' => 'Sin tipo por defecto',
                            'filter_placeholder' => 'Buscar tipo...',
                            'value' => $previewDefaultTipo,
                            'options' => [
                                ['value' => '', 'label' => 'Sin tipo por defecto', 'search' => 'sin tipo por defecto'],
                                ['value' => 'N', 'label' => 'N (Persona Natural)', 'search' => 'n persona natural'],
                                ['value' => 'J', 'label' => 'J (Persona Jurídica)', 'search' => 'j persona juridica'],
                            ],
                        ]);
                        ?>
                        <div class="small text-muted mt-1">Si el Excel viene sin <code>tipo_persona</code>, puedes precompletarlo automáticamente.</div>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Archivo Excel', true); ?>
                        <div id="ct-import-dropzone" class="ct-import-dropzone" tabindex="0">
                            <div class="ct-import-dropzone-icon"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i></div>
                            <div class="ct-import-dropzone-icon-check d-none"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></div>
                            <div id="ct-import-dropzone-title" class="ct-import-dropzone-title">Arrastra tu archivo aquí o haz clic para seleccionar</div>
                            <div class="ct-import-dropzone-meta">Formatos permitidos: <strong>.xlsx</strong>, <strong>.csv</strong></div>
                            <div id="ct-import-dropzone-filename" class="ct-import-dropzone-filename d-none"></div>
                        </div>
                        <input type="file" id="ct-import-file-input" name="archivo_importacion" class="form-control d-none" accept=".xlsx,.csv" required>
                    </div>
                    <div class="small text-muted">
                        El sistema no importa directo: primero genera una preview editable para corregir datos problematicos.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Generar preview</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade ct-terceros-modal ct-crud-modal" id="ct-modal-preview-importacion-terceros" tabindex="-1" aria-hidden="true" data-open-on-load="<?php echo $openImportPreviewModal ? '1' : '0'; ?>">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" id="ct-form-preview-importacion-terceros">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Preview de importación</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="import_id" value="<?php echo ctEscape($previewImportId); ?>">

                    <?php if ($previewRows === []): ?>
                        <div class="alert alert-secondary mb-0">
                            Aún no hay preview cargada. Primero sube un archivo desde "Importar Excel".
                        </div>
                    <?php else: ?>
                        <div class="ct-import-default-tipo-row mb-2">
                            <div class="ct-import-default-tipo-group">
                                <label class="form-label small mb-0" for="ct-import-default-tipo">Tipo por defecto para filas sin tipo</label>
                                <?php
                                ctRenderSearchableSelectField([
                                    'wrapper_class' => '',
                                    'label' => '',
                                    'input_name' => 'tipo_persona_default',
                                    'input_id' => 'ct-import-default-tipo',
                                    'picker_id' => 'ct-import-default-tipo-picker',
                                    'button_id' => 'ct-import-default-tipo-btn',
                                    'filter_id' => 'ct-import-default-tipo-filter',
                                    'list_id' => 'ct-import-default-tipo-list',
                                    'error_id' => 'ct-import-default-tipo-error',
                                    'error_message' => 'Debes seleccionar un tipo valido.',
                                    'button_placeholder' => 'Sin tipo por defecto',
                                    'filter_placeholder' => 'Buscar tipo...',
                                    'value' => $previewDefaultTipo,
                                    'input_class' => 'ct-import-default-tipo-input',
                                    'options' => [
                                        ['value' => '', 'label' => 'Sin tipo por defecto', 'search' => 'sin tipo por defecto'],
                                        ['value' => 'N', 'label' => 'N (Persona Natural)', 'search' => 'n persona natural'],
                                        ['value' => 'J', 'label' => 'J (Persona Jurídica)', 'search' => 'j persona juridica'],
                                    ],
                                ]);
                                ?>
                            </div>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="ct-import-apply-default-tipo">Aplicar a filas vacías</button>
                        </div>

                        <div class="ct-import-summary mb-3">
                            <span class="ct-crud-pill ct-crud-pill-muted">Total: <?php echo (int) ($previewSummary['total'] ?? 0); ?></span>
                            <span class="ct-crud-pill ct-crud-pill-primary">Seleccionadas: <?php echo (int) ($previewSummary['selected'] ?? 0); ?></span>
                            <span class="ct-crud-pill ct-crud-pill-success">Listas: <?php echo (int) ($previewSummary['ready'] ?? 0); ?></span>
                            <span class="ct-crud-pill ct-crud-pill-info">Crear: <?php echo (int) ($previewSummary['create'] ?? 0); ?></span>
                            <span class="ct-crud-pill ct-crud-pill-dark">Actualizar: <?php echo (int) ($previewSummary['update'] ?? 0); ?></span>
                            <span class="ct-crud-pill ct-crud-pill-warning">Advertencias: <?php echo (int) ($previewSummary['warnings'] ?? 0); ?></span>
                            <span class="ct-crud-pill ct-crud-pill-muted">Sin cambios: <?php echo (int) ($previewSummary['unchanged'] ?? 0); ?></span>
                            <span class="ct-crud-pill ct-crud-pill-danger">Con error: <?php echo (int) ($previewSummary['errors'] ?? 0); ?></span>
                            <span class="ct-crud-pill ct-crud-pill-warning">Omitidas: <?php echo (int) ($previewSummary['omitted'] ?? 0); ?></span>
                        </div>

                        <div class="table-responsive ct-import-preview-table-wrap">
                            <table class="table table-sm align-middle ct-import-preview-table">
                                <thead>
                                <tr>
                                    <th class="text-center" style="width: 78px;">
                                        <label class="form-check-label small" for="ct-import-select-all">Importar</label>
                                        <input class="form-check-input ms-1" type="checkbox" id="ct-import-select-all" checked>
                                    </th>
                                    <th style="width: 70px;">Fila</th>
                                    <th style="width: 150px;">Tipo</th>
                                    <th style="width: 190px;">RUT</th>
                                    <th>Nombre / Razón social</th>
                                    <th style="width: 160px;">Operación</th>
                                    <th style="width: 130px;">Estado</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($previewRows as $index => $row): ?>
                                    <?php
                                    $rowSelected = (($row['selected'] ?? false) === true);
                                    $rowStatus = trim((string) ($row['status'] ?? 'error'));
                                    $rowErrors = isset($row['errors']) && is_array($row['errors']) ? $row['errors'] : [];
                                    $rowWarnings = isset($row['warnings']) && is_array($row['warnings']) ? $row['warnings'] : [];
                                    $rowClass = $rowStatus === 'ok'
                                        ? 'ct-import-row-ok'
                                        : ($rowStatus === 'warning'
                                            ? 'ct-import-row-warning'
                                            : ($rowStatus === 'omitido' ? 'ct-import-row-omitido' : 'ct-import-row-error'));
                                    $statusLabel = $rowStatus === 'ok'
                                        ? 'OK'
                                        : ($rowStatus === 'warning'
                                            ? 'Advertencia'
                                            : ($rowStatus === 'omitido' ? 'Omitido' : 'Error'));
                                    $operationRaw = strtolower(trim((string) ($row['operation'] ?? 'create')));
                                    $operationLabel = 'Crear';
                                    $operationClass = 'ct-crud-pill ct-crud-pill-info';
                                    if ($operationRaw === 'update') {
                                        $operationLabel = 'Actualizar';
                                        $operationClass = 'ct-crud-pill ct-crud-pill-dark';
                                    } elseif ($operationRaw === 'omit') {
                                        $operationLabel = 'Omitir';
                                        $operationClass = 'ct-crud-pill ct-crud-pill-muted';
                                    }
                                    ?>
                                    <tr class="<?php echo ctEscape($rowClass); ?>">
                                        <td class="text-center">
                                            <input type="hidden" name="preview_rows[<?php echo (int) $index; ?>][line]" value="<?php echo (int) ($row['line'] ?? 0); ?>">
                                            <input class="form-check-input ct-import-row-check" type="checkbox" name="preview_rows[<?php echo (int) $index; ?>][selected]" value="1" <?php echo $rowSelected ? 'checked' : ''; ?>>
                                        </td>
                                        <td><?php echo (int) ($row['line'] ?? 0); ?></td>
                                        <td>
                                            <?php $tipoValue = (string) ($row['tipo_persona'] ?? ''); ?>
                                            <select class="form-select form-select-sm ct-import-row-tipo" name="preview_rows[<?php echo (int) $index; ?>][tipo_persona]">
                                                <option value="" <?php echo ($tipoValue !== 'N' && $tipoValue !== 'J') ? 'selected' : ''; ?>>Seleccionar...</option>
                                                <option value="N" <?php echo ($tipoValue === 'N') ? 'selected' : ''; ?>>N</option>
                                                <option value="J" <?php echo ($tipoValue === 'J') ? 'selected' : ''; ?>>J</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text"
                                                   class="form-control form-control-sm ct-import-rut"
                                                   name="preview_rows[<?php echo (int) $index; ?>][rut]"
                                                   maxlength="20"
                                                   value="<?php echo ctEscape((string) ($row['rut'] ?? '')); ?>">
                                        </td>
                                        <td>
                                            <input type="text"
                                                   class="form-control form-control-sm"
                                                   name="preview_rows[<?php echo (int) $index; ?>][nombre_razon_social]"
                                                   maxlength="200"
                                                   value="<?php echo ctEscape((string) ($row['nombre_razon_social'] ?? '')); ?>">
                                            <?php if ($rowErrors !== []): ?>
                                                <div class="ct-import-errors mt-1">
                                                    <?php foreach ($rowErrors as $error): ?>
                                                        <div><?php echo ctEscape((string) $error); ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($rowWarnings !== []): ?>
                                                <div class="ct-import-warnings mt-1">
                                                    <?php foreach ($rowWarnings as $warning): ?>
                                                        <div><?php echo ctEscape((string) $warning); ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo ctEscape($operationClass); ?>">
                                                <?php echo ctEscape($operationLabel); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="<?php echo ctEscape(
                                                $rowStatus === 'ok'
                                                    ? 'ct-crud-pill ct-crud-pill-success'
                                                    : ($rowStatus === 'warning'
                                                        ? 'ct-crud-pill ct-crud-pill-warning'
                                                        : ($rowStatus === 'omitido' ? 'ct-crud-pill ct-crud-pill-muted' : 'ct-crud-pill ct-crud-pill-danger'))
                                            ); ?>">
                                                <?php echo ctEscape($statusLabel); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <div>
                        <?php if ($previewRows !== []): ?>
                            <button type="submit" class="btn btn-outline-danger" name="accion" value="descartar_importacion_terceros" formnovalidate>Descartar preview</button>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-primary" name="accion" value="confirmar_importacion_terceros" <?php echo $previewRows === [] ? 'disabled' : ''; ?>>Validar e importar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade ct-terceros-modal ct-crud-modal" id="ct-modal-editar-tercero" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="ct-form-editar-tercero">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Editar tercero</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="editar_tercero">
                    <input type="hidden" name="id_tercero" id="ct-edit-id" value="">
                    <div class="ct-modal-hint">Actualiza tipo, RUT y nombre para mantener consistencia de los registros.</div>
                    <div class="mb-2">
                        <?php
                        ctRenderSearchableSelectField([
                            'wrapper_class' => '',
                            'label' => 'Tipo persona',
                            'input_name' => 'tipo_persona',
                            'input_id' => 'ct-edit-tipo',
                            'picker_id' => 'ct-edit-tipo-picker',
                            'button_id' => 'ct-edit-tipo-btn',
                            'filter_id' => 'ct-edit-tipo-filter',
                            'list_id' => 'ct-edit-tipo-list',
                            'error_id' => 'ct-edit-tipo-error',
                            'error_message' => 'Debes seleccionar el tipo de persona.',
                            'button_placeholder' => 'Selecciona tipo...',
                            'filter_placeholder' => 'Buscar tipo...',
                            'required' => true,
                            'show_requirement' => true,
                            'value' => 'N',
                            'options' => [
                                ['value' => 'N', 'label' => 'Persona Natural', 'search' => 'persona natural n'],
                                ['value' => 'J', 'label' => 'Persona Jurídica', 'search' => 'persona juridica j'],
                            ],
                        ]);
                        ?>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('RUT', false); ?>
                        <input name="rut" id="ct-edit-rut" class="form-control" maxlength="20">
                        <?php
                        ctRenderFormSwitch([
                            'id' => 'ct-edit-rut-enabled',
                            'label' => 'Ingresar RUT',
                            'checked' => true,
                            'help_text' => 'Si lo desactivas, se guardará sin RUT.',
                        ]);
                        ?>
                    </div>
                    <div class="mb-2">
                        <?php ctRenderFieldLabel('Nombre / Razón social', true); ?>
                        <input name="nombre_razon_social" id="ct-edit-nombre" class="form-control" maxlength="200" required>
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

<div class="modal fade ct-terceros-modal ct-crud-modal" id="ct-modal-eliminar-tercero" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="ct-form-eliminar-tercero">
                <div class="modal-header">
                    <h3 class="modal-title fs-5">Eliminar tercero</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php ctCsrfField(); ?>
                    <input type="hidden" name="accion" value="eliminar_tercero">
                    <input type="hidden" name="id_tercero" id="ct-delete-id" value="">
                    <p class="mb-0">Vas a eliminar a <strong id="ct-delete-nombre"></strong>. Esta acción no se puede deshacer.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Eliminar</button>
                </div>
            </form>
        </div>
    </div>
</div>
