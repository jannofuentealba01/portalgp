<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpRenderConfirmActionModal')) {
    /**
     * @param array{
     *   id?: string,
     *   title?: string,
     *   message?: string,
     *   cancel_label?: string,
     *   accept_label?: string,
     *   accept_class?: string
     * } $options
     */
    function gpRenderConfirmActionModal(array $options = []): void
    {
        $id = trim((string) ($options['id'] ?? 'gp_confirm_action_modal'));
        $title = trim((string) ($options['title'] ?? 'Confirmar accion'));
        $message = trim((string) ($options['message'] ?? 'Deseas continuar?'));
        $cancelLabel = trim((string) ($options['cancel_label'] ?? 'Volver'));
        $acceptLabel = trim((string) ($options['accept_label'] ?? 'Confirmar'));
        $acceptClass = trim((string) ($options['accept_class'] ?? 'btn btn-danger'));
        ?>
        <div class="modal fade" id="<?php echo gpComponentEscape($id); ?>" tabindex="-1" aria-hidden="true" data-gp-confirm-modal>
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" data-gp-confirm-title><?php echo gpComponentEscape($title); ?></h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-0" data-gp-confirm-message><?php echo gpComponentEscape($message); ?></p>
                        <div class="mt-3 d-none" data-gp-confirm-pattern-wrap>
                            <label class="form-label" data-gp-confirm-pattern-label>Confirmación</label>
                            <input type="text" class="form-control" maxlength="120" placeholder="Escribe el patrón solicitado" data-gp-confirm-pattern-input>
                            <div class="small text-danger mt-1 d-none" data-gp-confirm-pattern-error>El texto no coincide con el patrón requerido.</div>
                        </div>
                        <div class="mt-3 d-none" data-gp-confirm-reason-wrap>
                            <label class="form-label" data-gp-confirm-reason-label>Motivo</label>
                            <textarea class="form-control" rows="2" maxlength="500" placeholder="Escribe el motivo" data-gp-confirm-reason-input></textarea>
                            <div class="small text-danger mt-1 d-none" data-gp-confirm-reason-error>Debes ingresar un motivo.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-gp-confirm-cancel><?php echo gpComponentEscape($cancelLabel); ?></button>
                        <button type="button" class="<?php echo gpComponentEscape($acceptClass); ?>" data-gp-confirm-accept><?php echo gpComponentEscape($acceptLabel); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php gpRenderConfirmActionAssets(); ?>
        <?php
    }
}

if (!function_exists('gpRenderConfirmActionAssets')) {
    function gpRenderConfirmActionAssets(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;
        ?>
        <script>
        (() => {
            const state = new WeakMap();

            const openConfirm = (trigger) => {
                const modalId = trigger.dataset.confirmModal || 'gp_confirm_action_modal';
                const modalEl = document.getElementById(modalId);
                if (!(modalEl instanceof HTMLElement) || !window.bootstrap) return;

                const titleEl = modalEl.querySelector('[data-gp-confirm-title]');
                const messageEl = modalEl.querySelector('[data-gp-confirm-message]');
                const acceptBtn = modalEl.querySelector('[data-gp-confirm-accept]');
                const reasonWrap = modalEl.querySelector('[data-gp-confirm-reason-wrap]');
                const reasonInput = modalEl.querySelector('[data-gp-confirm-reason-input]');
                const reasonError = modalEl.querySelector('[data-gp-confirm-reason-error]');
                const patternWrap = modalEl.querySelector('[data-gp-confirm-pattern-wrap]');
                const patternLabel = modalEl.querySelector('[data-gp-confirm-pattern-label]');
                const patternInput = modalEl.querySelector('[data-gp-confirm-pattern-input]');
                const patternError = modalEl.querySelector('[data-gp-confirm-pattern-error]');

                if (titleEl) titleEl.textContent = trigger.dataset.confirmTitle || 'Confirmar accion';
                if (messageEl) messageEl.textContent = trigger.dataset.confirmMessage || 'Deseas continuar?';
                if (acceptBtn) {
                    acceptBtn.textContent = trigger.dataset.confirmAcceptLabel || 'Confirmar';
                    acceptBtn.className = trigger.dataset.confirmAcceptClass || 'btn btn-danger';
                }

                const requiresPattern = trigger.dataset.confirmRequiresPattern === '1';
                const requiredPattern = String(trigger.dataset.confirmPattern || '').trim();
                const patternPrompt = String(trigger.dataset.confirmPatternPrompt || '').trim();
                if (patternWrap) patternWrap.classList.toggle('d-none', !requiresPattern);
                if (patternInput) patternInput.value = '';
                if (patternError) patternError.classList.add('d-none');
                if (patternLabel && requiresPattern) {
                    const labelPattern = patternPrompt !== '' ? patternPrompt : ('Escribe "' + requiredPattern + '" para confirmar');
                    patternLabel.textContent = labelPattern;
                }

                const requiresReason = trigger.dataset.confirmRequiresReason === '1';
                if (reasonWrap) reasonWrap.classList.toggle('d-none', !requiresReason);
                if (reasonInput) reasonInput.value = '';
                if (reasonError) reasonError.classList.add('d-none');

                state.set(modalEl, { trigger, requiresReason, requiresPattern, requiredPattern });
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            };

            document.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-gp-confirm]');
                if (!(trigger instanceof HTMLElement)) return;
                event.preventDefault();
                openConfirm(trigger);
            });

            document.addEventListener('click', (event) => {
                const acceptBtn = event.target.closest('[data-gp-confirm-accept]');
                if (!(acceptBtn instanceof HTMLElement)) return;
                const modalEl = acceptBtn.closest('[data-gp-confirm-modal]');
                if (!(modalEl instanceof HTMLElement)) return;
                const info = state.get(modalEl);
                if (!info) return;

                const reasonInput = modalEl.querySelector('[data-gp-confirm-reason-input]');
                const reasonError = modalEl.querySelector('[data-gp-confirm-reason-error]');
                const patternInput = modalEl.querySelector('[data-gp-confirm-pattern-input]');
                const patternError = modalEl.querySelector('[data-gp-confirm-pattern-error]');
                const reason = reasonInput instanceof HTMLTextAreaElement ? reasonInput.value.trim() : '';
                const patternValue = patternInput instanceof HTMLInputElement ? patternInput.value.trim() : '';

                if (info.requiresPattern) {
                    if (patternValue !== String(info.requiredPattern || '')) {
                        if (patternError) patternError.classList.remove('d-none');
                        if (patternInput) patternInput.focus();
                        return;
                    }
                    if (patternError) patternError.classList.add('d-none');
                }

                if (info.requiresReason && reason === '') {
                    if (reasonError) reasonError.classList.remove('d-none');
                    if (reasonInput) reasonInput.focus();
                    return;
                }

                const trigger = info.trigger;
                const formSelector = trigger.dataset.confirmForm || '';
                const form = formSelector !== '' ? document.querySelector(formSelector) : trigger.closest('form');
                if (form instanceof HTMLFormElement) {
                    if (reason !== '') {
                        let reasonField = form.querySelector('input[name="motivo_confirmacion"]');
                        if (!(reasonField instanceof HTMLInputElement)) {
                            reasonField = document.createElement('input');
                            reasonField.type = 'hidden';
                            reasonField.name = 'motivo_confirmacion';
                            form.appendChild(reasonField);
                        }
                        reasonField.value = reason;
                    }
                    form.submit();
                    return;
                }

                const href = trigger.getAttribute('href') || trigger.dataset.confirmHref || '';
                if (href !== '') window.location.href = href;
            });
        })();
        </script>
        <?php
    }
}
