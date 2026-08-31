(() => {
    const modalEl = document.getElementById('ct_confirm_action_modal');
    const titleEl = document.getElementById('ct_confirm_action_title');
    const messageEl = document.getElementById('ct_confirm_action_message');
    const cancelBtn = document.getElementById('ct_confirm_action_cancel');
    const acceptBtn = document.getElementById('ct_confirm_action_accept');
    const reasonWrap = document.getElementById('ct_confirm_action_reason_wrap');
    const reasonLabel = document.getElementById('ct_confirm_action_reason_label');
    const reasonInput = document.getElementById('ct_confirm_action_reason_input');
    const reasonError = document.getElementById('ct_confirm_action_reason_error');

    let pendingForm = null;
    let modal = null;

    const normalizeVariantClass = (variantRaw) => {
        const variant = String(variantRaw || 'danger').trim().toLowerCase();
        if (variant === 'warning') return 'btn-warning';
        if (variant === 'primary') return 'btn-primary';
        if (variant === 'success') return 'btn-success';
        return 'btn-danger';
    };

    const isReasonRequired = (form) => String(form.dataset.confirmReasonRequired || '').trim() === '1';

    const resetReasonUi = () => {
        if (reasonWrap) reasonWrap.classList.add('d-none');
        if (reasonLabel) reasonLabel.textContent = 'Motivo';
        if (reasonInput) {
            reasonInput.value = '';
            reasonInput.classList.remove('is-invalid');
        }
        if (reasonError) reasonError.classList.add('d-none');
    };

    const setReasonOnForm = (form) => {
        if (!(form instanceof HTMLFormElement) || !(reasonInput instanceof HTMLTextAreaElement)) {
            return true;
        }

        const required = isReasonRequired(form);
        const reasonValue = reasonInput.value.trim();

        if (required && reasonValue === '') {
            reasonInput.classList.add('is-invalid');
            if (reasonError) reasonError.classList.remove('d-none');
            reasonInput.focus();
            return false;
        }

        reasonInput.classList.remove('is-invalid');
        if (reasonError) reasonError.classList.add('d-none');

        const reasonFieldName = (form.dataset.confirmReasonName || 'confirm_reason').trim();
        if (reasonFieldName === '') {
            return true;
        }

        let hiddenInput = form.querySelector(`input[name="${reasonFieldName}"]`);
        if (!(hiddenInput instanceof HTMLInputElement)) {
            hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = reasonFieldName;
            form.appendChild(hiddenInput);
        }

        hiddenInput.value = reasonValue;
        return true;
    };

    const onSubmitIntercept = (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const confirmMessage = form.dataset.confirmMessage;
        if (!confirmMessage) {
            return;
        }

        if (form.dataset.confirmed === '1') {
            if (String(form.dataset.confirmPersist || '') !== '1') {
                form.dataset.confirmed = '0';
            }
            return;
        }

        event.preventDefault();

        const canUseBootstrapModal = !!(
            window.bootstrap
            && modalEl
            && titleEl
            && messageEl
            && cancelBtn
            && acceptBtn
        );

        if (canUseBootstrapModal && modal === null) {
            modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        }

        if (!canUseBootstrapModal || !modal) {
            if (window.confirm(String(confirmMessage))) {
                form.dataset.confirmed = '1';
                form.requestSubmit();
            }
            return;
        }

        pendingForm = form;
        titleEl.textContent = form.dataset.confirmTitle || 'Confirmar accion';
        messageEl.textContent = confirmMessage;
        cancelBtn.textContent = form.dataset.confirmCancel || 'Volver';
        acceptBtn.textContent = form.dataset.confirmAccept || 'Confirmar';
        acceptBtn.className = 'btn ' + normalizeVariantClass(form.dataset.confirmVariant || 'danger');

        if (isReasonRequired(form) && reasonWrap && reasonInput) {
            reasonWrap.classList.remove('d-none');
            reasonLabel.textContent = form.dataset.confirmReasonLabel || 'Motivo';
            reasonInput.placeholder = form.dataset.confirmReasonPlaceholder || 'Escribe el motivo';
            reasonInput.classList.remove('is-invalid');
            if (reasonError) reasonError.classList.add('d-none');
            window.setTimeout(() => reasonInput.focus(), 120);
        } else {
            resetReasonUi();
        }

        modal.show();
    };

    document.addEventListener('submit', onSubmitIntercept, true);

    if (acceptBtn instanceof HTMLButtonElement) {
        acceptBtn.addEventListener('click', () => {
            if (!(pendingForm instanceof HTMLFormElement)) {
                if (modal) modal.hide();
                return;
            }

            if (!setReasonOnForm(pendingForm)) {
                return;
            }

            pendingForm.dataset.confirmed = '1';
            const formToSubmit = pendingForm;
            pendingForm = null;
            if (modal) modal.hide();
            formToSubmit.requestSubmit();
        });
    }

    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', () => {
            pendingForm = null;
            resetReasonUi();
        });
    }
})();

