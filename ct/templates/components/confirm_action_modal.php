<?php
declare(strict_types=1);
?>
<div class="modal fade" id="ct_confirm_action_modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="ct_confirm_action_title">Confirmar acción</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="ct_confirm_action_message">¿Deseas continuar?</p>
                <div class="mt-3 d-none" id="ct_confirm_action_reason_wrap">
                    <label for="ct_confirm_action_reason_input" class="form-label" id="ct_confirm_action_reason_label">Motivo</label>
                    <textarea class="form-control" id="ct_confirm_action_reason_input" rows="2" maxlength="500" placeholder="Escribe el motivo"></textarea>
                    <div class="small text-danger mt-1 d-none" id="ct_confirm_action_reason_error">Debes ingresar un motivo.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="ct_confirm_action_cancel" data-bs-dismiss="modal">Volver</button>
                <button type="button" class="btn btn-danger" id="ct_confirm_action_accept">Confirmar</button>
            </div>
        </div>
    </div>
</div>
<script src="<?php echo ctEscape(ctUrl('assets/ct_confirm_action.js')); ?>"></script>
