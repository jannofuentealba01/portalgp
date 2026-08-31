(() => {
    const boot = () => {
        const filtrosForm = document.getElementById('ct-terceros-filtros-form');
        const filtroNombre = document.getElementById('ct-filtro-nombre');
        const filtroRut = document.getElementById('ct-filtro-rut');
        const filtroTipo = document.getElementById('ct-filtro-tipo');
        const filtroRelacion = document.getElementById('ct-filtro-relacion');
        const lineas = document.getElementById('ct-lineas');

        const editButtons = document.querySelectorAll('.ct-btn-editar');
        const deleteButtons = document.querySelectorAll('.ct-btn-eliminar');

        const editId = document.getElementById('ct-edit-id');
        const editTipo = document.getElementById('ct-edit-tipo');
        const editRut = document.getElementById('ct-edit-rut');
        const createRut = document.getElementById('ct-crear-rut');
        const createRutEnabled = document.getElementById('ct-crear-rut-enabled');
        const editRutEnabled = document.getElementById('ct-edit-rut-enabled');
        const editNombre = document.getElementById('ct-edit-nombre');
        const previewModal = document.getElementById('ct-modal-preview-importacion-terceros');
        const previewForm = document.getElementById('ct-form-preview-importacion-terceros');
        const previewRutInputs = Array.from(document.querySelectorAll('.ct-import-rut'));
        const previewSelectAll = document.getElementById('ct-import-select-all');
        const previewRowChecks = Array.from(document.querySelectorAll('.ct-import-row-check'));
        const previewRowTipos = Array.from(document.querySelectorAll('.ct-import-row-tipo'));
        const previewDefaultTipo = document.getElementById('ct-import-default-tipo');
        const previewApplyDefaultTipo = document.getElementById('ct-import-apply-default-tipo');
        const importDropzone = document.getElementById('ct-import-dropzone');
        const importFileInput = document.getElementById('ct-import-file-input');
        const importDropTitle = document.getElementById('ct-import-dropzone-title');
        const importDropFilename = document.getElementById('ct-import-dropzone-filename');
        const importDropIconUpload = importDropzone ? importDropzone.querySelector('.ct-import-dropzone-icon') : null;
        const importDropIconCheck = importDropzone ? importDropzone.querySelector('.ct-import-dropzone-icon-check') : null;

        const deleteId = document.getElementById('ct-delete-id');
        const deleteNombre = document.getElementById('ct-delete-nombre');

        editButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                if (editId) editId.value = btn.getAttribute('data-id') || '';
                if (editTipo) editTipo.value = btn.getAttribute('data-tipo') || 'N';
                if (window.CtSearchableSelect && typeof window.CtSearchableSelect.get === 'function') {
                    const instance = window.CtSearchableSelect.get('ct-edit-tipo-picker');
                    if (instance && typeof instance.setValue === 'function') {
                        instance.setValue(btn.getAttribute('data-tipo') || 'N');
                    }
                }
                if (editRut) editRut.value = formatRutForDisplay(btn.getAttribute('data-rut') || '');
                if (editNombre) editNombre.value = btn.getAttribute('data-nombre') || '';
                if (editRutEnabled instanceof HTMLInputElement && editRut instanceof HTMLInputElement) {
                    editRutEnabled.checked = editRut.value.trim() !== '';
                    syncRutInputState(editRut, editRutEnabled);
                }
            });
        });

        deleteButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                if (deleteId) deleteId.value = btn.getAttribute('data-id') || '';
                if (deleteNombre) deleteNombre.textContent = btn.getAttribute('data-nombre') || '';
            });
        });

        const normalizeRutForSubmit = (rawValue) => {
            const clean = String(rawValue || '').replace(/[^0-9kK]/g, '').toUpperCase();
            if (clean === '') return '';
            if (clean.length === 1) return clean;

            const dv = clean.slice(-1);
            const body = clean.slice(0, -1);
            return `${body}-${dv}`;
        };

        const formatRutForDisplay = (rawValue) => {
            const normalized = normalizeRutForSubmit(rawValue);
            if (normalized === '' || !normalized.includes('-')) {
                return normalized;
            }
            const parts = normalized.split('-');
            const body = parts[0] || '';
            const dv = parts[1] || '';
            if (body === '' || dv === '') {
                return normalized;
            }
            const reversed = body.split('').reverse();
            const grouped = [];
            for (let i = 0; i < reversed.length; i += 3) {
                grouped.push(reversed.slice(i, i + 3).reverse().join(''));
            }
            return `${grouped.reverse().join('.')}-${dv}`;
        };

        const syncRutInputState = (inputEl, switchEl) => {
            if (!(inputEl instanceof HTMLInputElement)) return;
            const enabled = !(switchEl instanceof HTMLInputElement) || switchEl.checked;
            inputEl.disabled = !enabled;
            if (!enabled) {
                inputEl.value = '';
            }
        };

        const applyRutFormat = (inputEl, switchEl) => {
            if (!(inputEl instanceof HTMLInputElement)) return;
            if (switchEl instanceof HTMLInputElement && !switchEl.checked) {
                inputEl.value = '';
                return;
            }
            inputEl.value = formatRutForDisplay(inputEl.value);
        };

        const normalizeRutOnSubmit = (inputEl, switchEl) => {
            if (!(inputEl instanceof HTMLInputElement)) return;
            if (switchEl instanceof HTMLInputElement && !switchEl.checked) {
                inputEl.value = '';
                return;
            }
            inputEl.value = normalizeRutForSubmit(inputEl.value);
        };

        if (createRut instanceof HTMLInputElement) {
            createRut.addEventListener('blur', () => applyRutFormat(createRut, createRutEnabled));
            if (createRutEnabled instanceof HTMLInputElement) {
                createRutEnabled.addEventListener('change', () => syncRutInputState(createRut, createRutEnabled));
                syncRutInputState(createRut, createRutEnabled);
            }
            const createForm = createRut.closest('form');
            if (createForm instanceof HTMLFormElement) {
                createForm.addEventListener('submit', () => normalizeRutOnSubmit(createRut, createRutEnabled));
            }
        }

        if (editRut instanceof HTMLInputElement) {
            editRut.addEventListener('blur', () => applyRutFormat(editRut, editRutEnabled));
            if (editRutEnabled instanceof HTMLInputElement) {
                editRutEnabled.addEventListener('change', () => syncRutInputState(editRut, editRutEnabled));
                syncRutInputState(editRut, editRutEnabled);
            }
            const editForm = editRut.closest('form');
            if (editForm instanceof HTMLFormElement) {
                editForm.addEventListener('submit', () => normalizeRutOnSubmit(editRut, editRutEnabled));
            }
        }

        previewRutInputs.forEach((inputEl) => {
            if (!(inputEl instanceof HTMLInputElement)) {
                return;
            }
            inputEl.addEventListener('blur', () => {
                inputEl.value = formatRutForDisplay(inputEl.value);
            });
        });

        const applyDefaultTipoToEmptyRows = () => {
            if (!(previewDefaultTipo instanceof HTMLInputElement)) {
                return;
            }
            const defaultValue = (previewDefaultTipo.value || '').toUpperCase();
            if (defaultValue !== 'N' && defaultValue !== 'J') {
                return;
            }

            previewRowTipos.forEach((selectEl, index) => {
                if (!(selectEl instanceof HTMLSelectElement)) {
                    return;
                }
                const checkEl = previewRowChecks[index];
                if (checkEl instanceof HTMLInputElement && !checkEl.checked) {
                    return;
                }

                const current = (selectEl.value || '').toUpperCase();
                if (current === '') {
                    selectEl.value = defaultValue;
                }
            });
        };

        const updateDropzoneLabel = () => {
            if (
                !(importFileInput instanceof HTMLInputElement)
                || !(importDropFilename instanceof HTMLElement)
                || !(importDropzone instanceof HTMLElement)
            ) {
                return;
            }
            const file = importFileInput.files && importFileInput.files.length > 0
                ? importFileInput.files[0]
                : null;
            if (!file) {
                importDropzone.classList.remove('has-file');
                importDropFilename.classList.add('d-none');
                importDropFilename.textContent = '';
                if (importDropTitle instanceof HTMLElement) {
                    importDropTitle.textContent = 'Arrastra tu archivo aquí o haz clic para seleccionar';
                }
                if (importDropIconUpload instanceof HTMLElement) {
                    importDropIconUpload.classList.remove('d-none');
                }
                if (importDropIconCheck instanceof HTMLElement) {
                    importDropIconCheck.classList.add('d-none');
                }
                return;
            }
            importDropzone.classList.add('has-file');
            importDropFilename.classList.remove('d-none');
            importDropFilename.textContent = `Archivo: ${file.name}`;
            if (importDropTitle instanceof HTMLElement) {
                importDropTitle.textContent = 'Archivo listo para importar';
            }
            if (importDropIconUpload instanceof HTMLElement) {
                importDropIconUpload.classList.add('d-none');
            }
            if (importDropIconCheck instanceof HTMLElement) {
                importDropIconCheck.classList.remove('d-none');
            }
        };

        const setDroppedFiles = (files) => {
            if (!(importFileInput instanceof HTMLInputElement) || !files || files.length === 0) {
                return;
            }
            importFileInput.files = files;
            updateDropzoneLabel();
        };

        if (importDropzone instanceof HTMLElement && importFileInput instanceof HTMLInputElement) {
            importDropzone.addEventListener('click', () => importFileInput.click());
            importDropzone.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    importFileInput.click();
                }
            });
            importDropzone.addEventListener('dragover', (event) => {
                event.preventDefault();
                importDropzone.classList.add('is-dragover');
            });
            importDropzone.addEventListener('dragleave', () => {
                importDropzone.classList.remove('is-dragover');
            });
            importDropzone.addEventListener('drop', (event) => {
                event.preventDefault();
                importDropzone.classList.remove('is-dragover');
                if (event.dataTransfer && event.dataTransfer.files) {
                    setDroppedFiles(event.dataTransfer.files);
                }
            });
            importFileInput.addEventListener('change', updateDropzoneLabel);
            updateDropzoneLabel();
        }

        if (previewForm instanceof HTMLFormElement) {
            previewForm.addEventListener('submit', (event) => {
                const submitter = event.submitter;
                const action = submitter instanceof HTMLButtonElement ? (submitter.value || '') : '';
                if (action === 'confirmar_importacion_terceros') {
                    applyDefaultTipoToEmptyRows();
                }
                previewRutInputs.forEach((inputEl) => {
                    if (inputEl instanceof HTMLInputElement) {
                        inputEl.value = normalizeRutForSubmit(inputEl.value);
                    }
                });
            });
        }

        if (previewApplyDefaultTipo instanceof HTMLButtonElement) {
            previewApplyDefaultTipo.addEventListener('click', applyDefaultTipoToEmptyRows);
        }

        const syncPreviewSelectAll = () => {
            if (!(previewSelectAll instanceof HTMLInputElement)) {
                return;
            }
            if (previewRowChecks.length === 0) {
                previewSelectAll.checked = false;
                previewSelectAll.indeterminate = false;
                return;
            }

            const checkedCount = previewRowChecks.filter((checkEl) => (
                checkEl instanceof HTMLInputElement && checkEl.checked
            )).length;
            previewSelectAll.checked = checkedCount === previewRowChecks.length;
            previewSelectAll.indeterminate = checkedCount > 0 && checkedCount < previewRowChecks.length;
        };

        if (previewSelectAll instanceof HTMLInputElement) {
            previewSelectAll.addEventListener('change', () => {
                previewRowChecks.forEach((checkEl) => {
                    if (checkEl instanceof HTMLInputElement) {
                        checkEl.checked = previewSelectAll.checked;
                    }
                });
                syncPreviewSelectAll();
            });
        }

        previewRowChecks.forEach((checkEl) => {
            if (!(checkEl instanceof HTMLInputElement)) {
                return;
            }
            checkEl.addEventListener('change', syncPreviewSelectAll);
        });
        syncPreviewSelectAll();

        if (
            previewModal instanceof HTMLElement
            && previewModal.getAttribute('data-open-on-load') === '1'
            && window.bootstrap
            && window.bootstrap.Modal
        ) {
            const previewInstance = window.bootstrap.Modal.getOrCreateInstance(previewModal);
            window.setTimeout(() => previewInstance.show(), 80);
        }

        const submitFiltros = () => {
            if (filtrosForm instanceof HTMLFormElement) {
                if (filtroRut instanceof HTMLInputElement) {
                    filtroRut.value = normalizeRutForSubmit(filtroRut.value);
                }
                filtrosForm.submit();
            }
        };

        if (filtrosForm instanceof HTMLFormElement) {
            filtrosForm.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }

                if (target === filtroTipo || target === filtroRelacion || target === lineas) {
                    submitFiltros();
                }
            });
        }

        const submitOnEnter = (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitFiltros();
            }
        };

        if (filtroNombre instanceof HTMLInputElement) {
            filtroNombre.addEventListener('keydown', submitOnEnter);
        }

        if (filtroRut instanceof HTMLInputElement) {
            filtroRut.addEventListener('keydown', submitOnEnter);
            filtroRut.addEventListener('blur', () => {
                filtroRut.value = formatRutForDisplay(filtroRut.value);
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
