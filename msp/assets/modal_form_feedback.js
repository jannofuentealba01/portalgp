(() => {
    'use strict';

    if (!window.location.pathname.includes('/msp/')) {
        return;
    }

    const STORAGE_PREFIX = 'msp_modal_form_state_v1:';
    const MAX_AGE_MS = 30 * 60 * 1000;

    const storageKey = STORAGE_PREFIX + window.location.pathname;

    const clearStoredState = () => {
        try {
            window.sessionStorage.removeItem(storageKey);
        } catch (_error) {
            // Ignore storage failures.
        }
    };

    const readStoredState = () => {
        try {
            const raw = window.sessionStorage.getItem(storageKey);
            if (!raw) {
                return null;
            }

            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') {
                clearStoredState();
                return null;
            }

            if (!parsed.modalId || !Array.isArray(parsed.fields) || typeof parsed.ts !== 'number') {
                clearStoredState();
                return null;
            }

            if ((Date.now() - parsed.ts) > MAX_AGE_MS) {
                clearStoredState();
                return null;
            }

            return parsed;
        } catch (_error) {
            clearStoredState();
            return null;
        }
    };

    const findFlashAlert = () => document.querySelector('.flash-stack .alert');

    const resolveAlertType = (alertEl) => {
        if (!alertEl) {
            return 'info';
        }

        const supported = ['success', 'danger', 'warning', 'info', 'secondary'];
        for (const type of supported) {
            if (alertEl.classList.contains(`alert-${type}`)) {
                return type;
            }
        }

        return 'info';
    };

    const serializeForm = (form) => {
        const fields = [];
        const controls = form.querySelectorAll('input[name], select[name], textarea[name]');

        controls.forEach((control) => {
            if (!(control instanceof HTMLElement) || control.hasAttribute('disabled')) {
                return;
            }

            if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) {
                return;
            }

            const type = (control instanceof HTMLInputElement ? control.type : '').toLowerCase();
            if (type === 'password' || type === 'file') {
                return;
            }

            if (control instanceof HTMLInputElement && (type === 'checkbox' || type === 'radio')) {
                fields.push({
                    name: control.name,
                    kind: type,
                    value: control.value,
                    checked: control.checked,
                });
                return;
            }

            if (control instanceof HTMLSelectElement && control.multiple) {
                fields.push({
                    name: control.name,
                    kind: 'select-multiple',
                    values: Array.from(control.selectedOptions).map((option) => option.value),
                });
                return;
            }

            fields.push({
                name: control.name,
                kind: 'value',
                value: control.value,
            });
        });

        return fields;
    };

    const restoreForm = (form, fields) => {
        if (!Array.isArray(fields)) {
            return;
        }

        const counters = new Map();

        fields.forEach((field) => {
            if (!field || typeof field.name !== 'string' || field.name === '') {
                return;
            }

            const escapedName = window.CSS && typeof window.CSS.escape === 'function'
                ? window.CSS.escape(field.name)
                : field.name.replace(/["\\]/g, '\\$&');
            const elements = Array.from(form.querySelectorAll(`[name="${escapedName}"]`));
            if (elements.length === 0) {
                return;
            }

            const elementIndex = counters.get(field.name) ?? 0;

            if (field.kind === 'checkbox' || field.kind === 'radio') {
                const expectedValue = typeof field.value === 'string' ? field.value : '';
                const matching = elements.find((element) => {
                    return element instanceof HTMLInputElement && element.value === expectedValue;
                });

                const target = matching ?? elements[Math.min(elementIndex, elements.length - 1)];
                if (target instanceof HTMLInputElement) {
                    target.checked = Boolean(field.checked);
                }

                return;
            }

            const target = elements[Math.min(elementIndex, elements.length - 1)];
            counters.set(field.name, elementIndex + 1);

            if (field.kind === 'select-multiple' && target instanceof HTMLSelectElement) {
                const values = Array.isArray(field.values) ? field.values.map((value) => String(value)) : [];
                const selectedSet = new Set(values);
                Array.from(target.options).forEach((option) => {
                    option.selected = selectedSet.has(option.value);
                });
                return;
            }

            if (target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement) {
                target.value = typeof field.value === 'string' ? field.value : '';
                target.dispatchEvent(new Event('change', { bubbles: true }));
                target.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    };

    const injectModalAlert = (modalEl, alertType, alertMessage) => {
        const body = modalEl.querySelector('.modal-body');
        if (!body || !(body instanceof HTMLElement)) {
            return;
        }

        body.querySelectorAll('.js-msp2-modal-feedback').forEach((node) => node.remove());

        const alert = document.createElement('div');
        alert.className = `alert alert-${alertType} js-msp2-modal-feedback`;
        alert.setAttribute('role', 'alert');
        alert.textContent = alertMessage || 'Revisa los datos e intenta nuevamente.';
        body.prepend(alert);
    };

    const persistModalStateOnSubmit = () => {
        document.addEventListener('submit', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLFormElement)) {
                return;
            }

            if (target.dataset.msp2Preserve === 'off') {
                return;
            }

            if (target.method.toLowerCase() !== 'post') {
                return;
            }

            const modal = target.closest('.modal');
            if (!modal || !(modal instanceof HTMLElement) || modal.id === '') {
                return;
            }

            const payload = {
                modalId: modal.id,
                ts: Date.now(),
                fields: serializeForm(target),
            };

            try {
                window.sessionStorage.setItem(storageKey, JSON.stringify(payload));
            } catch (_error) {
                // Ignore storage failures.
            }
        }, true);
    };

    const tryRestoreModalAfterRedirect = () => {
        const state = readStoredState();
        if (!state) {
            return;
        }

        const flashAlert = findFlashAlert();
        if (!flashAlert) {
            return;
        }

        const alertType = resolveAlertType(flashAlert);
        const isError = alertType === 'danger' || alertType === 'warning';
        const isSuccess = alertType === 'success' || alertType === 'info';

        if (isSuccess) {
            clearStoredState();
            return;
        }

        if (!isError) {
            return;
        }

        const modalEl = document.getElementById(String(state.modalId));
        if (!modalEl) {
            return;
        }

        const modalForm = modalEl.querySelector('form');
        if (!(modalForm instanceof HTMLFormElement)) {
            return;
        }

        const message = flashAlert.textContent ? flashAlert.textContent.trim() : '';
        const restore = () => {
            restoreForm(modalForm, state.fields);
            injectModalAlert(modalEl, alertType, message);
        };

        const flashStack = flashAlert.closest('.flash-stack');
        if (flashStack instanceof HTMLElement) {
            flashStack.classList.add('d-none');
        }

        if (window.bootstrap && typeof window.bootstrap.Modal === 'function') {
            const instance = window.bootstrap.Modal.getOrCreateInstance(modalEl);
            modalEl.addEventListener('shown.bs.modal', function onShown() {
                restore();
                modalEl.removeEventListener('shown.bs.modal', onShown);
            });
            instance.show();
        } else {
            restore();
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        persistModalStateOnSubmit();
        tryRestoreModalAfterRedirect();
    });
})();
