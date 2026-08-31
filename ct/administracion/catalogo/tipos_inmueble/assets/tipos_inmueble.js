(() => {
    const boot = () => {
        const editButtons = document.querySelectorAll('.ct-btn-editar');
        const editId = document.getElementById('ct-edit-id');
        const editNombre = document.getElementById('ct-edit-nombre');

        editButtons.forEach((btn) => btn.addEventListener('click', () => {
            if (editId instanceof HTMLInputElement) editId.value = btn.getAttribute('data-id') || '';
            if (editNombre instanceof HTMLInputElement) editNombre.value = btn.getAttribute('data-nombre') || '';
        }));

        const forms = Array.from(document.querySelectorAll('form[data-ct-disable-submit="1"]'));
        forms.forEach((formEl) => {
            if (!(formEl instanceof HTMLFormElement)) return;
            formEl.addEventListener('submit', (event) => {
                const submitter = event.submitter;
                if (submitter instanceof HTMLButtonElement) {
                    submitter.disabled = true;
                    submitter.innerHTML = 'Procesando...';
                }
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
