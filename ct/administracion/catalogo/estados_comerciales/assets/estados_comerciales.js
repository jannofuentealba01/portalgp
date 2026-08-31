(() => {
    const boot = () => {
        const editButtons = document.querySelectorAll('.ct-btn-editar');
        const deleteButtons = document.querySelectorAll('.ct-btn-eliminar');
        const editId = document.getElementById('ct-edit-id');
        const editNombre = document.getElementById('ct-edit-nombre');
        const deleteId = document.getElementById('ct-delete-id');
        const deleteNombre = document.getElementById('ct-delete-nombre');
        const deleteWarning = document.getElementById('ct-delete-warning');
        const deleteSubmit = document.getElementById('ct-delete-submit');

        editButtons.forEach((btn) => btn.addEventListener('click', () => {
            if (editId instanceof HTMLInputElement) editId.value = btn.getAttribute('data-id') || '';
            if (editNombre instanceof HTMLInputElement) editNombre.value = btn.getAttribute('data-nombre') || '';
        }));

        deleteButtons.forEach((btn) => btn.addEventListener('click', () => {
            const count = Number(btn.getAttribute('data-count') || '0');
            if (deleteId instanceof HTMLInputElement) deleteId.value = btn.getAttribute('data-id') || '';
            if (deleteNombre instanceof HTMLElement) deleteNombre.textContent = btn.getAttribute('data-nombre') || '';
            if (deleteWarning instanceof HTMLElement) {
                deleteWarning.textContent = Number.isFinite(count) && count > 0
                    ? `Tiene ${count} terreno(s) asociado(s). El sistema bloqueará la eliminación.`
                    : 'No hay terrenos asociados según la carga actual.';
            }
            if (deleteSubmit instanceof HTMLButtonElement) deleteSubmit.disabled = Number.isFinite(count) && count > 0;
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
