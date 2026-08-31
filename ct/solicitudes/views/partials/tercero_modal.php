<?php
declare(strict_types=1);

if (!function_exists('ctSolicitudesRenderCrearTerceroModal')) {
    function ctSolicitudesRenderCrearTerceroModal(array $context): void
    {
        $modalId = trim((string) ($context['modal_id'] ?? 'ct-solicitudes-modal-crear-tercero'));
        $idSolicitud = (int) ($context['id_solicitud'] ?? 0);
        $idArea = (int) ($context['id_area_solicitud'] ?? 0);
        $canEdit = !empty($context['can_edit']);
        if ($idSolicitud <= 0 || $idArea <= 0) {
            return;
        }
        ?>
        <div class="modal fade" id="<?php echo ctEscape($modalId); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post">
                        <div class="modal-header">
                            <h3 class="modal-title fs-5">Registrar tercero</h3>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <?php ctCsrfField(); ?>
                            <input type="hidden" name="accion" value="crear_tercero_desde_ficha">
                            <input type="hidden" name="id_solicitud" value="<?php echo ctEscape((string) $idSolicitud); ?>">
                            <input type="hidden" name="id_area_solicitud" value="<?php echo ctEscape((string) $idArea); ?>">
                            <div class="small text-muted mb-2">Crea un nuevo tercero y luego selecciónalo como titular en la tabla.</div>
                            <div class="mb-2">
                                <?php
                                ctRenderSearchableSelectField([
                                    'wrapper_class' => '',
                                    'label' => 'Tipo persona',
                                    'input_name' => 'tipo_persona',
                                    'input_id' => $modalId . '-tipo',
                                    'picker_id' => $modalId . '-tipo-picker',
                                    'button_id' => $modalId . '-tipo-btn',
                                    'filter_id' => $modalId . '-tipo-filter',
                                    'list_id' => $modalId . '-tipo-list',
                                    'error_id' => $modalId . '-tipo-error',
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
                                <input name="rut" class="form-control" maxlength="20" <?php echo $canEdit ? '' : 'disabled'; ?>>
                            </div>
                            <div class="mb-2">
                                <?php ctRenderFieldLabel('Nombre / Razón social', true); ?>
                                <input name="nombre_razon_social" class="form-control" maxlength="200" required <?php echo $canEdit ? '' : 'disabled'; ?>>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-primary" <?php echo $canEdit ? '' : 'disabled'; ?>>Guardar tercero</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
}
