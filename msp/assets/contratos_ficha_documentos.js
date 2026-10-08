(function () {
    'use strict';

    const uploadModal = document.getElementById('modalDocumentoAdjunto');
    const uploadForm = uploadModal?.querySelector('[data-documento-adjunto-form]') ?? null;

    if (uploadModal && uploadForm) {
        const title = uploadForm.querySelector('[data-documento-modal-titulo]');
        const replacedId = uploadForm.querySelector('[data-documento-reemplazado]');
        const replacementNotice = uploadForm.querySelector('[data-documento-reemplazo-aviso]');
        const typeInput = uploadForm.querySelector('[name="tipo_documento"]');
        const dateInput = uploadForm.querySelector('[name="fecha_documento"]');
        const descriptionInput = uploadForm.querySelector('[name="descripcion"]');
        const fileInput = uploadForm.querySelector('[data-documento-archivo]');
        const submitButton = uploadForm.querySelector('[data-documento-submit]');
        const maxBytes = Number(uploadForm.dataset.maxBytes || 15728640);

        const validateFile = function () {
            if (!(fileInput instanceof HTMLInputElement)) {
                return true;
            }
            const file = fileInput.files?.[0] ?? null;
            let message = '';
            if (file) {
                const extensionIsPdf = file.name.toLowerCase().endsWith('.pdf');
                if (!extensionIsPdf) {
                    message = 'Selecciona un archivo con extensión .pdf.';
                } else if (file.size <= 0 || file.size > maxBytes) {
                    message = 'El PDF debe pesar como máximo 15 MB.';
                }
            }
            fileInput.setCustomValidity(message);
            fileInput.classList.toggle('is-invalid', message !== '');
            const feedback = uploadForm.querySelector('[data-documento-archivo-error]');
            if (feedback && message !== '') {
                feedback.textContent = message;
            }
            return message === '';
        };

        uploadModal.addEventListener('show.bs.modal', function (event) {
            uploadForm.reset();
            fileInput?.classList.remove('is-invalid');
            fileInput?.setCustomValidity('');

            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            const replacing = trigger?.dataset.documentoModo === 'reemplazar';
            const currentName = trigger?.dataset.documentoNombre || '';

            if (replacedId instanceof HTMLInputElement) {
                replacedId.value = replacing ? (trigger?.dataset.documentoId || '') : '';
            }
            if (typeInput instanceof HTMLSelectElement && replacing) {
                typeInput.value = trigger?.dataset.documentoTipo || 'OTRO';
            }
            if (dateInput instanceof HTMLInputElement && replacing) {
                dateInput.value = trigger?.dataset.documentoFecha || '';
            }
            if (descriptionInput instanceof HTMLTextAreaElement && replacing) {
                descriptionInput.value = trigger?.dataset.documentoDescripcion || '';
            }
            if (title) {
                title.textContent = replacing ? 'Reemplazar PDF' : 'Adjuntar PDF';
            }
            if (submitButton) {
                submitButton.textContent = replacing ? 'Reemplazar PDF' : 'Adjuntar PDF';
            }
            if (replacementNotice) {
                replacementNotice.classList.toggle('d-none', !replacing);
                replacementNotice.textContent = replacing
                    ? 'La versión activa de «' + currentName + '» será reemplazada y quedará conservada en la trazabilidad.'
                    : '';
            }
        });

        fileInput?.addEventListener('change', validateFile);
        uploadForm.addEventListener('submit', function (event) {
            if (!validateFile() || !uploadForm.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                uploadForm.classList.add('was-validated');
            }
        });
    }

    const annulModal = document.getElementById('modalAnularDocumentoAdjunto');
    if (annulModal) {
        annulModal.addEventListener('show.bs.modal', function (event) {
            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            const idInput = annulModal.querySelector('[data-documento-anular-id]');
            const nameTarget = annulModal.querySelector('[data-documento-anular-nombre]');
            const reasonInput = annulModal.querySelector('[name="motivo_anulacion"]');
            if (idInput instanceof HTMLInputElement) {
                idInput.value = trigger?.dataset.documentoId || '';
            }
            if (nameTarget) {
                nameTarget.textContent = trigger?.dataset.documentoNombre || 'seleccionado';
            }
            if (reasonInput instanceof HTMLTextAreaElement) {
                reasonInput.value = '';
            }
        });
    }
})();
