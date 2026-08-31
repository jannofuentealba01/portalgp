(() => {
    const boot = () => {
        const filtrosForm = document.getElementById('ct-terrenos-filtros-form');
        const terrenosSection = document.getElementById('terrenos');
        const filtroTexto = document.getElementById('ct-filtro-texto');
        const filtroComuna = document.getElementById('ct-filtro-comuna');
        const filtroEstadoPredial = document.getElementById('ct-filtro-estado-predial');
        const filtroEstadoComercial = document.getElementById('ct-filtro-estado-comercial');
        const filtroTipoInmueble = document.getElementById('ct-filtro-tipo-inmueble');
        const lineas = document.getElementById('ct-lineas');

        const editButtons = document.querySelectorAll('.ct-btn-editar');
        const deleteButtons = document.querySelectorAll('.ct-btn-eliminar');
        const abrirTitularidadButtons = document.querySelectorAll('.ct-btn-abrir-titularidad');
        const abrirSubdivisionButtons = document.querySelectorAll('.ct-btn-abrir-subdivision');
        const fusionOrigenButtons = document.querySelectorAll('.ct-btn-agregar-fusion-origen');
        const fusionResultadoButtons = document.querySelectorAll('.ct-btn-abrir-fusion-resultado');

        const editId = document.getElementById('ct-edit-id');
        const editRolAsignado = document.getElementById('ct-edit-rol-asignado');
        const editRolMatriz = document.getElementById('ct-edit-rol-matriz');
        const editIdentificacion = document.getElementById('ct-edit-identificacion');
        const editSuperficie = document.getElementById('ct-edit-superficie');
        const editComuna = document.getElementById('ct-edit-comuna');
        const editEstadoPredial = document.getElementById('ct-edit-estado-predial');
        const editEstadoComercial = document.getElementById('ct-edit-estado-comercial');
        const editTipoInmueble = document.getElementById('ct-edit-tipo-inmueble');

        const deleteId = document.getElementById('ct-delete-id');
        const deleteNombre = document.getElementById('ct-delete-nombre');
        const titularidadTerrenoInput = document.getElementById('ct-titularidad-id-terreno');
        const subdivisionOrigenInput = document.getElementById('ct-subdivision-id-origen');
        const fusionResultadoInput = document.getElementById('ct-fusion-id-resultado');
        const fusionForm = document.getElementById('ct-form-registrar-fusion');
        const fusionOrigenIdsInput = document.getElementById('ct-fusion-ids-origen');
        const fusionOrigenSelectorInput = document.getElementById('ct-fusion-origen-selector');
        const fusionOrigenAddBtn = document.getElementById('ct-fusion-add-origen');
        const fusionOrigenBody = document.getElementById('ct-fusion-origen-body');
        const fusionOrigenCount = document.getElementById('ct-fusion-origen-count');
        const fusionOrigenTotal = document.getElementById('ct-fusion-origen-total');
        const fusionResultadoRol = document.getElementById('ct-fusion-resultado-rol');
        const fusionResultadoSuperficie = document.getElementById('ct-fusion-resultado-superficie');
        const fusionResultadoComuna = document.getElementById('ct-fusion-resultado-comuna');
        const subdivisionForm = document.getElementById('ct-form-registrar-subdivision');
        const subdivisionOrigenRol = document.getElementById('ct-subdivision-origen-rol');
        const subdivisionOrigenSuperficie = document.getElementById('ct-subdivision-origen-superficie');
        const subdivisionOrigenComuna = document.getElementById('ct-subdivision-origen-comuna');
        const subdivisionOrigenSuperficieHidden = document.getElementById('ct-subdivision-superficie-origen');
        const subdivisionResultBody = document.getElementById('ct-subdivision-result-body');
        const subdivisionAddRowBtn = document.getElementById('ct-subdivision-add-row');
        const subdivisionResultCount = document.getElementById('ct-subdivision-result-count');
        const subdivisionResultTotal = document.getElementById('ct-subdivision-result-total');

        const createForm = document.getElementById('ct-form-crear-terreno');
        const adquisicionForm = document.getElementById('ct-form-registrar-adquisicion');
        const tasacionForm = document.querySelector('#ct-modal-registrar-tasacion form');
        const ventaForm = document.querySelector('#ct-modal-registrar-venta form');
        const ventaAddCompradorBtn = document.getElementById('ct-venta-add-comprador');
        const adqTitularesBody = document.getElementById('ct-adq-titulares-body');
        const adqTitularAddBtn = document.getElementById('ct-adq-add-titular-row');
        const adqTitularesCount = document.getElementById('ct-adq-titulares-count');
        const adqTitularesTotal = document.getElementById('ct-adq-titulares-total');
        const editForm = document.getElementById('ct-form-editar-terreno');
        const superficieInputs = Array.from(document.querySelectorAll('.ct-superficie-input'));
        const formsDisableSubmit = Array.from(document.querySelectorAll('form[data-ct-disable-submit="1"]'));
        let fusionAddOrigenById = null;
        let fusionSetResultadoModo = null;

        const normalizeNumberForSubmit = (rawValue) => {
            let value = String(rawValue || '').trim();
            if (value === '') return '';

            value = value.replace(/\s+/g, '').replace(/[^0-9,.\-]/g, '');
            if (value === '' || value === '-') return '';

            const hasComma = value.includes(',');
            const hasDot = value.includes('.');
            if (hasComma && hasDot) {
                if (value.lastIndexOf(',') > value.lastIndexOf('.')) {
                    value = value.replace(/\./g, '').replace(',', '.');
                } else {
                    value = value.replace(/,/g, '');
                }
            } else if (hasComma) {
                value = value.replace(',', '.');
            }

            const number = Number(value);
            if (!Number.isFinite(number) || number <= 0) return '';
            return number.toFixed(2);
        };

        const formatNumberForDisplay = (rawValue) => {
            const normalized = normalizeNumberForSubmit(rawValue);
            if (normalized === '') return '';
            const number = Number(normalized);
            return new Intl.NumberFormat('es-CL', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            }).format(number);
        };

        const setSearchableValue = (pickerId, hiddenInput, value) => {
            if (hiddenInput instanceof HTMLInputElement) {
                hiddenInput.value = value || '';
            }
            if (window.CtSearchableSelect && typeof window.CtSearchableSelect.get === 'function') {
                const instance = window.CtSearchableSelect.get(pickerId);
                if (instance && typeof instance.setValue === 'function') {
                    instance.setValue(value || '');
                }
            }
        };

        const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => {
            switch (char) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case '\'': return '&#39;';
                default: return char;
            }
        });

        const restoreStateRaw = (
            window.ctTerrenosFormRestore
            && typeof window.ctTerrenosFormRestore === 'object'
            && !Array.isArray(window.ctTerrenosFormRestore)
        ) ? window.ctTerrenosFormRestore : null;

        const restoreGetPayload = () => {
            if (!restoreStateRaw || typeof restoreStateRaw !== 'object') return null;
            const action = String(restoreStateRaw.accion || '').trim();
            const payload = restoreStateRaw.payload;
            if (action === '' || !payload || typeof payload !== 'object' || Array.isArray(payload)) {
                return null;
            }
            return { action, payload };
        };

        const restoreGetControlList = (form, name) => {
            if (!(form instanceof HTMLFormElement) || String(name || '').trim() === '') return [];
            const controls = Array.from(form.elements || []);
            return controls.filter((control) => (
                (control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)
                && control.name === name
            ));
        };

        const restoreSetControlValue = (control, value) => {
            if (control instanceof HTMLInputElement) {
                if (control.type === 'checkbox') {
                    const normalized = String(value || '').trim().toLowerCase();
                    control.checked = ['1', 'true', 'on', 'yes'].includes(normalized);
                    return;
                }
                if (control.type === 'radio') {
                    control.checked = String(control.value || '') === String(value || '');
                    return;
                }
                control.value = String(value ?? '');
                return;
            }
            if (control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement) {
                control.value = String(value ?? '');
            }
        };

        const restoreSetScalar = (form, name, value) => {
            const controls = restoreGetControlList(form, name);
            if (controls.length < 1) return;
            if (controls.length === 1) {
                restoreSetControlValue(controls[0], value);
                return;
            }
            controls.forEach((control) => restoreSetControlValue(control, value));
        };

        const restoreSetArray = (form, name, values) => {
            if (!(values instanceof Array)) return;
            const controls = restoreGetControlList(form, name);
            if (controls.length < 1) return;
            controls.forEach((control, index) => {
                const value = index < values.length ? values[index] : '';
                restoreSetControlValue(control, value);
            });
        };

        const restoreEnsureRows = (selector, addBtn, targetRows, minRows = 1) => {
            const goal = Math.max(minRows, Number(targetRows || 0));
            if (!(addBtn instanceof HTMLButtonElement) || goal <= 0) return;
            for (let i = 0; i < 120; i += 1) {
                const current = document.querySelectorAll(selector).length;
                if (current >= goal) break;
                addBtn.click();
            }
        };


        editButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (editId) editId.value = button.getAttribute('data-id') || '';
                if (editRolAsignado) editRolAsignado.value = button.getAttribute('data-rol-asignado') || '';
                if (editRolMatriz) editRolMatriz.value = button.getAttribute('data-rol-matriz') || '';
                if (editIdentificacion) editIdentificacion.value = button.getAttribute('data-identificacion') || '';
                if (editSuperficie instanceof HTMLInputElement) {
                    editSuperficie.value = formatNumberForDisplay(button.getAttribute('data-superficie') || '');
                }

                setSearchableValue('ct-edit-comuna-picker', editComuna, button.getAttribute('data-comuna') || '');
                setSearchableValue('ct-edit-estado-predial-picker', editEstadoPredial, button.getAttribute('data-estado-predial') || '');
                setSearchableValue('ct-edit-estado-comercial-picker', editEstadoComercial, button.getAttribute('data-estado-comercial') || '');
                setSearchableValue('ct-edit-tipo-inmueble-picker', editTipoInmueble, button.getAttribute('data-tipo-inmueble') || '');
            });
        });

        deleteButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (deleteId) deleteId.value = button.getAttribute('data-id') || '';
                if (deleteNombre) deleteNombre.textContent = button.getAttribute('data-nombre') || '';
            });
        });

        abrirTitularidadButtons.forEach((button) => {
            button.addEventListener('click', () => {
                setSearchableValue(
                    'ct-titularidad-id-terreno-picker',
                    titularidadTerrenoInput,
                    button.getAttribute('data-id-terreno') || '',
                );
            });
        });

        abrirSubdivisionButtons.forEach((button) => {
            button.addEventListener('click', () => {
                setSearchableValue(
                    'ct-subdivision-id-origen-picker',
                    subdivisionOrigenInput,
                    button.getAttribute('data-id-terreno') || '',
                );
            });
        });

        fusionOrigenButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (typeof fusionAddOrigenById === 'function') {
                    fusionAddOrigenById(button.getAttribute('data-id-terreno') || '');
                }
            });
        });

        fusionResultadoButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (typeof fusionSetResultadoModo === 'function') {
                    fusionSetResultadoModo('existente');
                }
                setSearchableValue(
                    'ct-fusion-id-resultado-picker',
                    fusionResultadoInput,
                    button.getAttribute('data-id-terreno') || '',
                );
            });
        });


        superficieInputs.forEach((input) => {
            if (!(input instanceof HTMLInputElement)) return;
            input.addEventListener('blur', () => {
                input.value = formatNumberForDisplay(input.value);
            });
        });

        if (createForm instanceof HTMLFormElement) {
            createForm.addEventListener('submit', () => {
                const input = createForm.querySelector('#ct-crear-superficie');
                if (input instanceof HTMLInputElement) {
                    input.value = normalizeNumberForSubmit(input.value);
                }
            });
        }

        if (adquisicionForm instanceof HTMLFormElement) {
            const syncAdqTitularesTotal = () => {
                if (!(adqTitularesBody instanceof HTMLElement)) {
                    return;
                }

                const rows = adqTitularesBody.querySelectorAll('.ct-adq-titular-row');
                if (adqTitularesCount instanceof HTMLElement) {
                    adqTitularesCount.textContent = String(rows.length);
                }
                rows.forEach((row, index) => {
                    if (!(row instanceof HTMLTableRowElement)) return;
                    const indexBadge = row.querySelector('.ct-adq-row-index');
                    if (indexBadge instanceof HTMLElement) {
                        indexBadge.textContent = String(index + 1);
                    }
                });

                const pctInputs = Array.from(adqTitularesBody.querySelectorAll('.ct-adq-titular-pct'));
                let total = 0;
                pctInputs.forEach((input) => {
                    if (!(input instanceof HTMLInputElement)) return;
                    const normalized = normalizeNumberForSubmit(input.value);
                    if (normalized !== '') {
                        total += Number(normalized);
                    }
                });

                if (adqTitularesTotal instanceof HTMLElement) {
                    adqTitularesTotal.textContent = `${total.toFixed(2)}%`;
                    const isExact = Math.round(total * 100) === 10000;
                    adqTitularesTotal.classList.toggle('is-invalid', !isExact);
                    adqTitularesTotal.classList.toggle('is-valid', isExact);
                }
            };

            if (adqTitularesBody instanceof HTMLElement) {
                const firstRow = adqTitularesBody.querySelector('.ct-adq-titular-row');
                let rowTemplate = null;
                if (firstRow instanceof HTMLTableRowElement) {
                    rowTemplate = firstRow.cloneNode(true);
                }

                const resetRowValues = (row) => {
                    if (!(row instanceof HTMLTableRowElement)) return;
                    const select = row.querySelector('select[name="titulares_id_tercero[]"]');
                    const pct = row.querySelector('.ct-adq-titular-pct');
                    const desde = row.querySelector('input[name="titulares_vigente_desde[]"]');
                    const hasta = row.querySelector('input[name="titulares_vigente_hasta[]"]');
                    if (select instanceof HTMLSelectElement) select.value = '';
                    if (pct instanceof HTMLInputElement) pct.value = '';
                    if (hasta instanceof HTMLInputElement) hasta.value = '';
                    if (desde instanceof HTMLInputElement) {
                        const existingDefault = (adquisicionForm.querySelector('#ct-adq-fecha') || {}).value || '';
                        if (typeof existingDefault === 'string' && existingDefault.trim() !== '') {
                            desde.value = existingDefault;
                        }
                    }
                };

                if (adqTitularAddBtn instanceof HTMLButtonElement && rowTemplate instanceof HTMLTableRowElement) {
                    adqTitularAddBtn.addEventListener('click', () => {
                        const newRow = rowTemplate.cloneNode(true);
                        resetRowValues(newRow);
                        adqTitularesBody.appendChild(newRow);
                        syncAdqTitularesTotal();
                    });
                }

                adqTitularesBody.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;
                    const btn = target.closest('.ct-adq-remove-titular-row');
                    if (!(btn instanceof HTMLButtonElement)) return;

                    const row = btn.closest('.ct-adq-titular-row');
                    if (!(row instanceof HTMLTableRowElement)) return;
                    const rows = adqTitularesBody.querySelectorAll('.ct-adq-titular-row');
                    if (rows.length <= 1) {
                        resetRowValues(row);
                        syncAdqTitularesTotal();
                        return;
                    }
                    row.remove();
                    syncAdqTitularesTotal();
                });

                adqTitularesBody.addEventListener('input', (event) => {
                    const target = event.target;
                    if (target instanceof HTMLInputElement && target.classList.contains('ct-adq-titular-pct')) {
                        syncAdqTitularesTotal();
                    }
                });

                adqTitularesBody.addEventListener('blur', (event) => {
                    const target = event.target;
                    if (target instanceof HTMLInputElement && target.classList.contains('ct-adq-titular-pct')) {
                        const normalized = normalizeNumberForSubmit(target.value);
                        target.value = normalized === '' ? '' : Number(normalized).toFixed(2);
                        syncAdqTitularesTotal();
                    }
                }, true);

                syncAdqTitularesTotal();
            }

            adquisicionForm.addEventListener('submit', (event) => {
                const input = adquisicionForm.querySelector('#ct-adq-superficie');
                if (input instanceof HTMLInputElement) {
                    input.value = normalizeNumberForSubmit(input.value);
                }

                if (adqTitularesBody instanceof HTMLElement) {
                    const rows = Array.from(adqTitularesBody.querySelectorAll('.ct-adq-titular-row'));
                    let total = 0;
                    let filledRows = 0;

                    rows.forEach((row) => {
                        if (!(row instanceof HTMLTableRowElement)) return;

                        const select = row.querySelector('select[name="titulares_id_tercero[]"]');
                        const pct = row.querySelector('.ct-adq-titular-pct');
                        if (!(select instanceof HTMLSelectElement) || !(pct instanceof HTMLInputElement)) return;

                        const hasAnyValue = (
                            select.value.trim() !== ''
                            || String(pct.value || '').trim() !== ''
                            || String((row.querySelector('input[name="titulares_vigente_desde[]"]') || {}).value || '').trim() !== ''
                            || String((row.querySelector('input[name="titulares_vigente_hasta[]"]') || {}).value || '').trim() !== ''
                        );
                        if (!hasAnyValue) return;

                        const normalized = normalizeNumberForSubmit(pct.value);
                        pct.value = normalized;
                        if (select.value.trim() !== '' && normalized !== '') {
                            total += Number(normalized);
                            filledRows++;
                        }
                    });

                    if (filledRows < 1 || Math.round(total * 100) !== 10000) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        window.alert('Los titulares iniciales deben sumar exactamente 100.00% y contener al menos una fila válida.');
                        syncAdqTitularesTotal();
                    }
                }
            });
        }

        if (subdivisionForm instanceof HTMLFormElement) {
            const subdivisionPickerRoot = document.getElementById('ct-subdivision-id-origen-picker');

            const findSearchableOptionByValue = (root, value) => {
                if (!(root instanceof HTMLElement)) return null;
                const safeValue = String(value || '').trim();
                if (safeValue === '') return null;
                const options = Array.from(root.querySelectorAll('.js-searchable-option'));
                for (const option of options) {
                    if (option instanceof HTMLElement && String(option.dataset.value || '').trim() === safeValue) {
                        return option;
                    }
                }
                return null;
            };

            const readSubdivisionOrigenSurface = () => {
                if (!(subdivisionOrigenSuperficieHidden instanceof HTMLInputElement)) return 0;
                const normalized = normalizeNumberForSubmit(subdivisionOrigenSuperficieHidden.value);
                return normalized === '' ? 0 : Number(normalized);
            };

            const renderSubdivisionOrigenInfo = () => {
                const selectedValue = subdivisionOrigenInput instanceof HTMLInputElement
                    ? String(subdivisionOrigenInput.value || '').trim()
                    : '';
                const option = findSearchableOptionByValue(subdivisionPickerRoot, selectedValue);

                const rol = option ? String(option.dataset.rol || '').trim() : '';
                const comuna = option ? String(option.dataset.comuna || '').trim() : '';
                const superficieRaw = option ? String(option.dataset.superficie || '').trim() : '';
                const superficieNormalized = normalizeNumberForSubmit(superficieRaw);
                const superficieFormatted = superficieNormalized === ''
                    ? '-'
                    : `${new Intl.NumberFormat('es-CL', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2,
                    }).format(Number(superficieNormalized))} m²`;

                if (subdivisionOrigenRol instanceof HTMLElement) {
                    subdivisionOrigenRol.textContent = rol !== '' ? rol : '-';
                }
                if (subdivisionOrigenComuna instanceof HTMLElement) {
                    subdivisionOrigenComuna.textContent = comuna !== '' ? comuna : '-';
                }
                if (subdivisionOrigenSuperficie instanceof HTMLElement) {
                    subdivisionOrigenSuperficie.textContent = superficieFormatted;
                }
                if (subdivisionOrigenSuperficieHidden instanceof HTMLInputElement) {
                    subdivisionOrigenSuperficieHidden.value = superficieNormalized === '' ? '' : Number(superficieNormalized).toFixed(2);
                }
            };

            const syncSubdivisionResultTotal = () => {
                if (!(subdivisionResultBody instanceof HTMLElement)) return;

                const rows = Array.from(subdivisionResultBody.querySelectorAll('.ct-subdivision-result-row'));
                if (subdivisionResultCount instanceof HTMLElement) {
                    subdivisionResultCount.textContent = String(rows.length);
                }
                rows.forEach((row, index) => {
                    if (!(row instanceof HTMLTableRowElement)) return;
                    const badge = row.querySelector('.ct-subdivision-row-index');
                    if (badge instanceof HTMLElement) {
                        badge.textContent = String(index + 1);
                    }
                });

                let total = 0;
                const inputs = Array.from(subdivisionResultBody.querySelectorAll('.ct-subdivision-result-superficie'));
                inputs.forEach((input) => {
                    if (!(input instanceof HTMLInputElement)) return;
                    const normalized = normalizeNumberForSubmit(input.value);
                    if (normalized !== '') {
                        total += Number(normalized);
                    }
                });

                const originSurface = readSubdivisionOrigenSurface();
                const exact = originSurface > 0 && Math.round(total * 100) === Math.round(originSurface * 100);
                if (subdivisionResultTotal instanceof HTMLElement) {
                    subdivisionResultTotal.textContent = `${total.toFixed(2)} m²`;
                    subdivisionResultTotal.classList.toggle('is-invalid', !exact && total > 0);
                }
            };

            if (subdivisionResultBody instanceof HTMLElement) {
                const firstRow = subdivisionResultBody.querySelector('.ct-subdivision-result-row');
                let rowTemplate = null;
                if (firstRow instanceof HTMLTableRowElement) {
                    rowTemplate = firstRow.cloneNode(true);
                }

                const clearRow = (row) => {
                    if (!(row instanceof HTMLTableRowElement)) return;
                    const rolInput = row.querySelector('input[name="subdivision_result_rol_asignado[]"]');
                    const superficieInput = row.querySelector('input[name="subdivision_result_superficie_m2[]"]');
                    if (rolInput instanceof HTMLInputElement) rolInput.value = '';
                    if (superficieInput instanceof HTMLInputElement) superficieInput.value = '';
                };

                if (subdivisionAddRowBtn instanceof HTMLButtonElement && rowTemplate instanceof HTMLTableRowElement) {
                    subdivisionAddRowBtn.addEventListener('click', () => {
                        const row = rowTemplate.cloneNode(true);
                        clearRow(row);
                        subdivisionResultBody.appendChild(row);
                        syncSubdivisionResultTotal();
                    });
                }

                subdivisionResultBody.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;
                    const removeBtn = target.closest('.ct-subdivision-remove-row');
                    if (!(removeBtn instanceof HTMLButtonElement)) return;

                    const row = removeBtn.closest('.ct-subdivision-result-row');
                    if (!(row instanceof HTMLTableRowElement)) return;

                    const rows = subdivisionResultBody.querySelectorAll('.ct-subdivision-result-row');
                    if (rows.length <= 2) {
                        clearRow(row);
                        syncSubdivisionResultTotal();
                        return;
                    }

                    row.remove();
                    syncSubdivisionResultTotal();
                });

                subdivisionResultBody.addEventListener('input', (event) => {
                    const target = event.target;
                    if (target instanceof HTMLInputElement && target.classList.contains('ct-subdivision-result-superficie')) {
                        syncSubdivisionResultTotal();
                    }
                });

                subdivisionResultBody.addEventListener('blur', (event) => {
                    const target = event.target;
                    if (target instanceof HTMLInputElement && target.classList.contains('ct-subdivision-result-superficie')) {
                        const normalized = normalizeNumberForSubmit(target.value);
                        target.value = normalized === '' ? '' : formatNumberForDisplay(normalized);
                        syncSubdivisionResultTotal();
                    }
                }, true);
            }

            if (subdivisionOrigenInput instanceof HTMLInputElement) {
                subdivisionOrigenInput.addEventListener('change', () => {
                    renderSubdivisionOrigenInfo();
                    syncSubdivisionResultTotal();
                });
            }

            renderSubdivisionOrigenInfo();
            syncSubdivisionResultTotal();

            subdivisionForm.addEventListener('submit', (event) => {
                const originSurface = readSubdivisionOrigenSurface();
                if (originSurface <= 0) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    window.alert('Debes seleccionar un terreno origen válido.');
                    return;
                }

                if (subdivisionResultBody instanceof HTMLElement) {
                    const rows = Array.from(subdivisionResultBody.querySelectorAll('.ct-subdivision-result-row'));
                    let total = 0;
                    let validRows = 0;

                    rows.forEach((row) => {
                        if (!(row instanceof HTMLTableRowElement)) return;
                        const rolInput = row.querySelector('input[name="subdivision_result_rol_asignado[]"]');
                        const superficieInput = row.querySelector('input[name="subdivision_result_superficie_m2[]"]');
                        if (!(rolInput instanceof HTMLInputElement) || !(superficieInput instanceof HTMLInputElement)) return;

                        const rol = String(rolInput.value || '').trim();
                        const supRaw = String(superficieInput.value || '').trim();
                        if (rol === '' && supRaw === '') {
                            return;
                        }

                        if (rol !== '') {
                            rolInput.value = rol.toUpperCase();
                        }

                        const normalized = normalizeNumberForSubmit(supRaw);
                        superficieInput.value = normalized;
                        if (rolInput.value.trim() !== '' && normalized !== '') {
                            validRows++;
                            total += Number(normalized);
                        }
                    });

                    const exact = Math.round(total * 100) === Math.round(originSurface * 100);
                    if (validRows < 2 || !exact) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        window.alert('Debes ingresar al menos 2 resultados y la suma de superficies debe coincidir exactamente con la superficie del origen.');
                        syncSubdivisionResultTotal();
                    }
                }
            });
        }

        if (fusionForm instanceof HTMLFormElement) {
            const fusionOrigenPickerRoot = document.getElementById('ct-fusion-origen-selector-picker');
            const fusionResultadoPickerRoot = document.getElementById('ct-fusion-id-resultado-picker');
            const fusionResultadoModoNuevo = document.getElementById('ct-fusion-resultado-modo-nuevo');
            const fusionResultadoModoExistente = document.getElementById('ct-fusion-resultado-modo-existente');
            const fusionResultadoNuevoBlock = document.getElementById('ct-fusion-resultado-nuevo-block');
            const fusionResultadoExistenteBlock = document.getElementById('ct-fusion-resultado-existente-block');
            const fusionResultadoNuevoRolInput = document.getElementById('ct-fusion-resultado-nuevo-rol');
            const fusionResultadoNuevoSuperficieInput = document.getElementById('ct-fusion-resultado-nuevo-superficie');

            const findSearchableOptionByValue = (root, value) => {
                if (!(root instanceof HTMLElement)) return null;
                const safeValue = String(value || '').trim();
                if (safeValue === '') return null;
                const options = Array.from(root.querySelectorAll('.js-searchable-option'));
                for (const option of options) {
                    if (option instanceof HTMLElement && String(option.dataset.value || '').trim() === safeValue) {
                        return option;
                    }
                }
                return null;
            };

            const getFusionResultMode = () => {
                if (fusionResultadoModoExistente instanceof HTMLInputElement && fusionResultadoModoExistente.checked) {
                    return 'existente';
                }
                return 'nuevo';
            };

            const formatSurfaceM2 = (rawValue) => {
                const normalized = normalizeNumberForSubmit(rawValue);
                if (normalized === '') return '-';
                return `${new Intl.NumberFormat('es-CL', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                }).format(Number(normalized))} m²`;
            };

            const collectFusionOrigenRows = () => (
                fusionOrigenBody instanceof HTMLElement
                    ? Array.from(fusionOrigenBody.querySelectorAll('.ct-fusion-origen-row'))
                    : []
            );

            const collectFusionOrigenIds = () => (
                collectFusionOrigenRows()
                    .map((row) => String((row instanceof HTMLTableRowElement ? row.dataset.idTerreno : '') || '').trim())
                    .filter((id) => /^\d+$/.test(id))
            );

            const refreshSearchableOptions = (root, blockedIds) => {
                if (!(root instanceof HTMLElement)) return;
                const blocked = blockedIds instanceof Set ? blockedIds : new Set();
                const options = Array.from(root.querySelectorAll('.js-searchable-option'));
                options.forEach((option) => {
                    if (!(option instanceof HTMLElement)) return;
                    const id = String(option.dataset.value || '').trim();
                    option.hidden = blocked.has(id);
                });
                const filterInput = root.querySelector('[data-searchable-filter]');
                if (filterInput instanceof HTMLInputElement) {
                    filterInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
            };

            const renderFusionResultadoInfo = () => {
                const selectedValue = fusionResultadoInput instanceof HTMLInputElement
                    ? String(fusionResultadoInput.value || '').trim()
                    : '';
                const option = findSearchableOptionByValue(fusionResultadoPickerRoot, selectedValue);

                const rol = option ? String(option.dataset.rol || '').trim() : '';
                const comuna = option ? String(option.dataset.comuna || '').trim() : '';
                const superficieRaw = option ? String(option.dataset.superficie || '').trim() : '';

                if (fusionResultadoRol instanceof HTMLElement) {
                    fusionResultadoRol.textContent = rol !== '' ? rol : '-';
                }
                if (fusionResultadoComuna instanceof HTMLElement) {
                    fusionResultadoComuna.textContent = comuna !== '' ? comuna : '-';
                }
                if (fusionResultadoSuperficie instanceof HTMLElement) {
                    fusionResultadoSuperficie.textContent = formatSurfaceM2(superficieRaw);
                }
            };

            const refreshFusionSelectorAvailability = () => {
                const origenIds = collectFusionOrigenIds();
                const resultadoId = fusionResultadoInput instanceof HTMLInputElement
                    ? String(fusionResultadoInput.value || '').trim()
                    : '';
                const mode = getFusionResultMode();

                const blockedForOrigen = new Set(origenIds);
                if (mode === 'existente' && /^\d+$/.test(resultadoId)) {
                    blockedForOrigen.add(resultadoId);
                }
                refreshSearchableOptions(fusionOrigenPickerRoot, blockedForOrigen);

                const blockedForResultado = new Set(origenIds);
                refreshSearchableOptions(fusionResultadoPickerRoot, blockedForResultado);

                if (mode === 'existente' && /^\d+$/.test(resultadoId) && blockedForResultado.has(resultadoId)) {
                    setSearchableValue('ct-fusion-id-resultado-picker', fusionResultadoInput, '');
                }
            };

            const syncFusionOrigenMetrics = () => {
                if (!(fusionOrigenBody instanceof HTMLElement)) return;

                const rows = collectFusionOrigenRows();
                if (rows.length < 1) {
                    fusionOrigenBody.innerHTML = '<tr class="ct-fusion-origen-empty"><td colspan="6" class="text-muted text-center py-2">Aún no agregas terrenos origen.</td></tr>';
                } else {
                    const emptyRow = fusionOrigenBody.querySelector('.ct-fusion-origen-empty');
                    if (emptyRow instanceof HTMLElement) {
                        emptyRow.remove();
                    }
                }

                const validRows = collectFusionOrigenRows();
                let total = 0;
                validRows.forEach((row, index) => {
                    if (!(row instanceof HTMLTableRowElement)) return;
                    const badge = row.querySelector('.ct-fusion-row-index');
                    if (badge instanceof HTMLElement) {
                        badge.textContent = String(index + 1);
                    }
                    const superficie = normalizeNumberForSubmit(row.dataset.superficie || '');
                    if (superficie !== '') {
                        total += Number(superficie);
                    }
                });

                if (fusionOrigenCount instanceof HTMLElement) {
                    fusionOrigenCount.textContent = String(validRows.length);
                }
                if (fusionOrigenTotal instanceof HTMLElement) {
                    fusionOrigenTotal.textContent = `${total.toFixed(2)} m²`;
                }
                if (fusionResultadoNuevoSuperficieInput instanceof HTMLInputElement) {
                    fusionResultadoNuevoSuperficieInput.value = formatSurfaceM2(total.toFixed(2));
                }
                if (fusionOrigenIdsInput instanceof HTMLInputElement) {
                    const ids = validRows
                        .map((row) => String((row instanceof HTMLTableRowElement ? row.dataset.idTerreno : '') || '').trim())
                        .filter((id) => /^\d+$/.test(id));
                    fusionOrigenIdsInput.value = ids.join(', ');
                }
                refreshFusionSelectorAvailability();
            };

            const applyFusionResultMode = () => {
                const mode = getFusionResultMode();
                const isNuevo = mode === 'nuevo';

                if (fusionResultadoNuevoBlock instanceof HTMLElement) {
                    fusionResultadoNuevoBlock.classList.toggle('d-none', !isNuevo);
                }
                if (fusionResultadoExistenteBlock instanceof HTMLElement) {
                    fusionResultadoExistenteBlock.classList.toggle('d-none', isNuevo);
                }

                if (fusionResultadoNuevoRolInput instanceof HTMLInputElement) {
                    fusionResultadoNuevoRolInput.required = isNuevo;
                }
                if (fusionResultadoInput instanceof HTMLInputElement) {
                    fusionResultadoInput.required = !isNuevo;
                }

                if (isNuevo) {
                    setSearchableValue('ct-fusion-id-resultado-picker', fusionResultadoInput, '');
                } else if (fusionResultadoNuevoRolInput instanceof HTMLInputElement) {
                    fusionResultadoNuevoRolInput.value = String(fusionResultadoNuevoRolInput.value || '').trim().toUpperCase();
                }

                renderFusionResultadoInfo();
                refreshFusionSelectorAvailability();
            };

            const addFusionOrigenById = (idValue) => {
                if (!(fusionOrigenBody instanceof HTMLElement)) return false;
                const id = String(idValue || '').trim();
                if (!/^\d+$/.test(id)) return false;

                const mode = getFusionResultMode();
                const resultadoId = fusionResultadoInput instanceof HTMLInputElement
                    ? String(fusionResultadoInput.value || '').trim()
                    : '';
                if (mode === 'existente' && resultadoId !== '' && resultadoId === id) {
                    window.alert('El terreno resultado no puede ser parte de los origenes.');
                    return false;
                }

                const exists = collectFusionOrigenRows().some((row) => (
                    row instanceof HTMLTableRowElement
                    && String(row.dataset.idTerreno || '').trim() === id
                ));
                if (exists) {
                    return false;
                }

                const option = findSearchableOptionByValue(fusionOrigenPickerRoot, id);
                if (!(option instanceof HTMLElement)) {
                    return false;
                }

                const row = document.createElement('tr');
                row.className = 'ct-fusion-origen-row';
                row.dataset.idTerreno = id;
                row.dataset.superficie = String(option.dataset.superficie || '').trim();

                const rol = String(option.dataset.rol || '').trim() || '-';
                const propietario = String(option.dataset.propietario || '').trim() || '-';
                const comuna = String(option.dataset.comuna || '').trim() || '-';
                const superficieLabel = formatSurfaceM2(option.dataset.superficie || '');

                row.innerHTML = `
                    <td class="text-center"><span class="ct-fusion-row-index"></span></td>
                    <td>${escapeHtml(rol)}</td>
                    <td>${escapeHtml(propietario)}</td>
                    <td>${escapeHtml(comuna)}</td>
                    <td class="text-end">${escapeHtml(superficieLabel)}</td>
                    <td class="text-center">
                        <button type="button" class="btn btn-sm btn-outline-danger ct-fusion-remove-origen" aria-label="Quitar origen">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                        </button>
                    </td>
                `;

                const emptyRow = fusionOrigenBody.querySelector('.ct-fusion-origen-empty');
                if (emptyRow instanceof HTMLElement) {
                    emptyRow.remove();
                }
                fusionOrigenBody.appendChild(row);
                syncFusionOrigenMetrics();
                return true;
            };

            fusionAddOrigenById = addFusionOrigenById;
            fusionSetResultadoModo = (mode) => {
                if (mode === 'existente' && fusionResultadoModoExistente instanceof HTMLInputElement) {
                    fusionResultadoModoExistente.checked = true;
                } else if (fusionResultadoModoNuevo instanceof HTMLInputElement) {
                    fusionResultadoModoNuevo.checked = true;
                }
                applyFusionResultMode();
            };

            if (fusionResultadoModoNuevo instanceof HTMLInputElement) {
                fusionResultadoModoNuevo.addEventListener('change', applyFusionResultMode);
            }
            if (fusionResultadoModoExistente instanceof HTMLInputElement) {
                fusionResultadoModoExistente.addEventListener('change', applyFusionResultMode);
            }

            if (fusionOrigenAddBtn instanceof HTMLButtonElement) {
                fusionOrigenAddBtn.addEventListener('click', () => {
                    const value = fusionOrigenSelectorInput instanceof HTMLInputElement
                        ? fusionOrigenSelectorInput.value
                        : '';
                    const added = addFusionOrigenById(value);
                    if (added) {
                        setSearchableValue('ct-fusion-origen-selector-picker', fusionOrigenSelectorInput, '');
                    }
                });
            }

            if (fusionOrigenBody instanceof HTMLElement) {
                fusionOrigenBody.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof HTMLElement)) return;
                    const btn = target.closest('.ct-fusion-remove-origen');
                    if (!(btn instanceof HTMLButtonElement)) return;
                    const row = btn.closest('.ct-fusion-origen-row');
                    if (!(row instanceof HTMLTableRowElement)) return;
                    row.remove();
                    syncFusionOrigenMetrics();
                });
            }

            if (fusionResultadoInput instanceof HTMLInputElement) {
                fusionResultadoInput.addEventListener('change', () => {
                    renderFusionResultadoInfo();
                    refreshFusionSelectorAvailability();
                });
            }

            applyFusionResultMode();
            renderFusionResultadoInfo();
            syncFusionOrigenMetrics();

            fusionForm.addEventListener('submit', (event) => {
                const origenIds = collectFusionOrigenIds();
                const resultMode = getFusionResultMode();
                const resultadoId = fusionResultadoInput instanceof HTMLInputElement
                    ? String(fusionResultadoInput.value || '').trim()
                    : '';
                const rolNuevoRaw = fusionResultadoNuevoRolInput instanceof HTMLInputElement
                    ? String(fusionResultadoNuevoRolInput.value || '').trim()
                    : '';

                if (origenIds.length < 2) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    window.alert('Debes seleccionar al menos dos terrenos origen para la fusión.');
                    syncFusionOrigenMetrics();
                    return;
                }

                if (resultMode === 'existente') {
                    if (!/^\d+$/.test(resultadoId)) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        window.alert('Debes seleccionar un terreno resultado válido.');
                        return;
                    }
                    if (origenIds.includes(resultadoId)) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        window.alert('El terreno resultado no puede ser parte de los orígenes.');
                        return;
                    }
                } else {
                    const rolNuevo = rolNuevoRaw.toUpperCase();
                    if (fusionResultadoNuevoRolInput instanceof HTMLInputElement) {
                        fusionResultadoNuevoRolInput.value = rolNuevo;
                    }
                    if (rolNuevo === '') {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        window.alert('Debes ingresar el rol asignado del nuevo terreno resultado.');
                        return;
                    }
                }

                if (fusionOrigenIdsInput instanceof HTMLInputElement) {
                    fusionOrigenIdsInput.value = origenIds.join(', ');
                }
            });
        }

        const restoreTerrenosFormState = () => {
            const state = restoreGetPayload();
            if (!state) return;

            const action = state.action;
            const payload = state.payload;
            const getArray = (key) => {
                if (payload[key] instanceof Array) return payload[key];
                const altKey = key.endsWith('[]') ? key.slice(0, -2) : `${key}[]`;
                return payload[altKey] instanceof Array ? payload[altKey] : [];
            };

            if (action === 'registrar_adquisicion' && adquisicionForm instanceof HTMLFormElement) {
                restoreSetScalar(adquisicionForm, 'rol_asignado', payload.rol_asignado ?? '');
                restoreSetScalar(adquisicionForm, 'rol_matriz', payload.rol_matriz ?? '');
                restoreSetScalar(adquisicionForm, 'identificacion_propiedad', payload.identificacion_propiedad ?? '');
                restoreSetScalar(adquisicionForm, 'superficie_m2', payload.superficie_m2 ?? '');
                restoreSetScalar(adquisicionForm, 'fecha_adquisicion', payload.fecha_adquisicion ?? '');
                restoreSetScalar(adquisicionForm, 'documento_fuente', payload.documento_fuente ?? '');

                if (document.getElementById('ct-adq-comuna') instanceof HTMLInputElement) {
                    setSearchableValue('ct-adq-comuna-picker', document.getElementById('ct-adq-comuna'), payload.id_comuna ?? '');
                }
                if (document.getElementById('ct-adq-tipo-inmueble') instanceof HTMLInputElement) {
                    setSearchableValue('ct-adq-tipo-inmueble-picker', document.getElementById('ct-adq-tipo-inmueble'), payload.id_tipo_inmueble ?? '');
                }

                const rowsTarget = Math.max(
                    1,
                    getArray('titulares_id_tercero[]').length,
                    getArray('titulares_porcentaje_derecho[]').length,
                    getArray('titulares_vigente_desde[]').length,
                    getArray('titulares_vigente_hasta[]').length,
                );
                restoreEnsureRows('.ct-adq-titular-row', adqTitularAddBtn, rowsTarget, 1);

                restoreSetArray(adquisicionForm, 'titulares_id_tercero[]', getArray('titulares_id_tercero[]'));
                restoreSetArray(adquisicionForm, 'titulares_porcentaje_derecho[]', getArray('titulares_porcentaje_derecho[]'));
                restoreSetArray(adquisicionForm, 'titulares_vigente_desde[]', getArray('titulares_vigente_desde[]'));
                restoreSetArray(adquisicionForm, 'titulares_vigente_hasta[]', getArray('titulares_vigente_hasta[]'));

                Array.from(adquisicionForm.querySelectorAll('.ct-adq-titular-pct')).forEach((input) => {
                    if (input instanceof HTMLInputElement) {
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            }

            if (action === 'registrar_titularidad') {
                const form = document.getElementById('ct-form-registrar-titularidad');
                if (!(form instanceof HTMLFormElement)) return;
                restoreSetScalar(form, 'porcentaje_derecho', payload.porcentaje_derecho ?? '');
                restoreSetScalar(form, 'vigente_desde', payload.vigente_desde ?? '');
                restoreSetScalar(form, 'vigente_hasta', payload.vigente_hasta ?? '');
                restoreSetScalar(form, 'cerrar_vigente_actual', payload.cerrar_vigente_actual ?? '');

                if (document.getElementById('ct-titularidad-id-terreno') instanceof HTMLInputElement) {
                    setSearchableValue('ct-titularidad-id-terreno-picker', document.getElementById('ct-titularidad-id-terreno'), payload.id_terreno ?? '');
                }
                if (document.getElementById('ct-titularidad-id-tercero') instanceof HTMLInputElement) {
                    setSearchableValue('ct-titularidad-id-tercero-picker', document.getElementById('ct-titularidad-id-tercero'), payload.id_tercero ?? '');
                }
            }

            if (action === 'registrar_subdivision' && subdivisionForm instanceof HTMLFormElement) {
                restoreSetScalar(subdivisionForm, 'fecha_operacion', payload.fecha_operacion ?? '');
                restoreSetScalar(subdivisionForm, 'documento_fuente', payload.documento_fuente ?? '');
                if (subdivisionOrigenInput instanceof HTMLInputElement) {
                    setSearchableValue('ct-subdivision-id-origen-picker', subdivisionOrigenInput, payload.id_terreno_origen ?? '');
                    subdivisionOrigenInput.dispatchEvent(new Event('change', { bubbles: true }));
                }

                const rowsTarget = Math.max(
                    2,
                    getArray('subdivision_result_rol_asignado[]').length,
                    getArray('subdivision_result_superficie_m2[]').length,
                );
                restoreEnsureRows('.ct-subdivision-result-row', subdivisionAddRowBtn, rowsTarget, 2);
                restoreSetArray(subdivisionForm, 'subdivision_result_rol_asignado[]', getArray('subdivision_result_rol_asignado[]'));
                restoreSetArray(subdivisionForm, 'subdivision_result_superficie_m2[]', getArray('subdivision_result_superficie_m2[]'));

                Array.from(subdivisionForm.querySelectorAll('.ct-subdivision-result-superficie')).forEach((input) => {
                    if (input instanceof HTMLInputElement) {
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                });
            }

            if (action === 'registrar_fusion' && fusionForm instanceof HTMLFormElement) {
                restoreSetScalar(fusionForm, 'fecha_operacion', payload.fecha_operacion ?? '');
                restoreSetScalar(fusionForm, 'documento_fuente', payload.documento_fuente ?? '');
                restoreSetScalar(fusionForm, 'fusion_resultado_nuevo_rol_asignado', payload.fusion_resultado_nuevo_rol_asignado ?? '');

                if (typeof fusionSetResultadoModo === 'function') {
                    fusionSetResultadoModo(String(payload.fusion_resultado_modo || '').trim() === 'existente' ? 'existente' : 'nuevo');
                }

                const idsRaw = String(payload.ids_terrenos_origen || '');
                const ids = idsRaw.match(/\d+/g) || [];
                if (typeof fusionAddOrigenById === 'function') {
                    ids.forEach((id) => {
                        fusionAddOrigenById(id);
                    });
                }

                if (fusionResultadoInput instanceof HTMLInputElement) {
                    setSearchableValue('ct-fusion-id-resultado-picker', fusionResultadoInput, payload.id_terreno_resultado ?? '');
                    fusionResultadoInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            if (action === 'registrar_tasacion' && tasacionForm instanceof HTMLFormElement) {
                if (document.getElementById('ct-tasacion-id-terreno') instanceof HTMLInputElement) {
                    setSearchableValue('ct-tasacion-id-terreno-picker', document.getElementById('ct-tasacion-id-terreno'), payload.id_terreno ?? '');
                }
                if (document.getElementById('ct-tasacion-tipo') instanceof HTMLInputElement) {
                    setSearchableValue('ct-tasacion-tipo-picker', document.getElementById('ct-tasacion-tipo'), payload.id_tipo_tasacion ?? '');
                }
                if (document.getElementById('ct-tasacion-id-entidad-financiera') instanceof HTMLInputElement) {
                    setSearchableValue(
                        'ct-tasacion-id-entidad-financiera-picker',
                        document.getElementById('ct-tasacion-id-entidad-financiera'),
                        payload.id_entidad_financiera ?? '',
                    );
                }
                restoreSetScalar(tasacionForm, 'fecha_tasacion', payload.fecha_tasacion ?? '');
                restoreSetScalar(tasacionForm, 'valor_total_uf', payload.valor_total_uf ?? '');
                restoreSetScalar(tasacionForm, 'valor_uf_m2', payload.valor_uf_m2 ?? '');
                restoreSetScalar(tasacionForm, 'vigente_desde', payload.vigente_desde ?? '');
                restoreSetScalar(tasacionForm, 'vigente_hasta', payload.vigente_hasta ?? '');
                restoreSetScalar(tasacionForm, 'es_referencial', payload.es_referencial ?? '');
            }

            if (action === 'registrar_venta' && ventaForm instanceof HTMLFormElement) {
                if (document.getElementById('ct-venta-id-terreno') instanceof HTMLInputElement) {
                    setSearchableValue('ct-venta-id-terreno-picker', document.getElementById('ct-venta-id-terreno'), payload.id_terreno ?? '');
                }
                restoreSetScalar(ventaForm, 'fecha_venta', payload.fecha_venta ?? '');
                if (document.getElementById('ct-venta-tasacion-ref') instanceof HTMLInputElement) {
                    setSearchableValue('ct-venta-tasacion-ref-picker', document.getElementById('ct-venta-tasacion-ref'), payload.id_tasacion_referencial ?? '');
                }
                restoreSetScalar(ventaForm, 'valor_total_uf', payload.valor_total_uf ?? '');
                restoreSetScalar(ventaForm, 'valor_venta_uf_m2', payload.valor_venta_uf_m2 ?? '');

                const rowsTarget = Math.max(
                    1,
                    getArray('venta_id_tercero[]').length,
                    getArray('venta_porcentaje[]').length,
                    getArray('venta_rol[]').length,
                );
                restoreEnsureRows('.ct-venta-comprador-row', ventaAddCompradorBtn, rowsTarget, 1);
                restoreSetArray(ventaForm, 'venta_id_tercero[]', getArray('venta_id_tercero[]'));
                restoreSetArray(ventaForm, 'venta_porcentaje[]', getArray('venta_porcentaje[]'));
                restoreSetArray(ventaForm, 'venta_rol[]', getArray('venta_rol[]'));
            }
        };
        restoreTerrenosFormState();

        if (editForm instanceof HTMLFormElement) {
            editForm.addEventListener('submit', () => {
                const input = editForm.querySelector('#ct-edit-superficie');
                if (input instanceof HTMLInputElement) {
                    input.value = normalizeNumberForSubmit(input.value);
                }
            });
        }

        const submitFiltros = () => {
            if (filtrosForm instanceof HTMLFormElement) {
                filtrosForm.submit();
            }
        };

        if (filtrosForm instanceof HTMLFormElement) {
            filtrosForm.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) return;

                if (
                    target === filtroComuna
                    || target === filtroEstadoPredial
                    || target === filtroEstadoComercial
                    || target === filtroTipoInmueble
                    || target === lineas
                ) {
                    submitFiltros();
                }
            });
        }

        if (filtroTexto instanceof HTMLInputElement) {
            filtroTexto.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    submitFiltros();
                }
            });
        }

        const initTableActionDropdowns = () => {
            if (!window.bootstrap || typeof window.bootstrap.Dropdown !== 'function') return;

            const toggles = Array.from(
                document.querySelectorAll('.ct-terrenos-table .ct-crud-actions .dropdown-toggle[data-bs-toggle="dropdown"]'),
            );

            toggles.forEach((toggle) => {
                if (!(toggle instanceof HTMLElement)) return;

                window.bootstrap.Dropdown.getOrCreateInstance(toggle, {
                    popperConfig(defaultConfig) {
                        const baseConfig = (defaultConfig && typeof defaultConfig === 'object') ? defaultConfig : {};
                        const baseModifiers = Array.isArray(baseConfig.modifiers) ? baseConfig.modifiers : [];
                        const modifiers = baseModifiers.map((modifier) => {
                            if (!modifier || typeof modifier !== 'object') return modifier;
                            if (modifier.name !== 'preventOverflow') return modifier;
                            return {
                                ...modifier,
                                options: {
                                    ...(modifier.options || {}),
                                    boundary: 'viewport',
                                },
                            };
                        });

                        return {
                            ...baseConfig,
                            strategy: 'fixed',
                            modifiers,
                        };
                    },
                });

                const menu = toggle.parentElement?.querySelector('.dropdown-menu');
                if (!(menu instanceof HTMLElement)) return;

                toggle.addEventListener('shown.bs.dropdown', () => {
                    menu.classList.add('ct-terrenos-dropdown-portal');
                });
                toggle.addEventListener('hidden.bs.dropdown', () => {
                    menu.classList.remove('ct-terrenos-dropdown-portal');
                });
            });
        };
        initTableActionDropdowns();

        const autoModal = terrenosSection?.getAttribute('data-open-modal') || '';
        const autoModalMap = {
            adquisicion: 'ct-modal-registrar-adquisicion',
            titularidad: 'ct-modal-registrar-titularidad',
            subdivision: 'ct-modal-registrar-subdivision',
            fusion: 'ct-modal-registrar-fusion',
            tasacion: 'ct-modal-registrar-tasacion',
            venta: 'ct-modal-registrar-venta',
        };
        const autoModalId = autoModalMap[autoModal];
        if (autoModalId) {
            const modalEl = document.getElementById(autoModalId);
            if (modalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function') {
                window.setTimeout(() => {
                    const instance = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                    instance.show();
                }, 120);
            }
        }

        formsDisableSubmit.forEach((formEl) => {
            if (!(formEl instanceof HTMLFormElement)) return;
            formEl.addEventListener('submit', (event) => {
                const submitter = event.submitter;
                if (submitter instanceof HTMLButtonElement) {
                    submitter.disabled = true;
                    submitter.dataset.originalLabel = submitter.innerHTML;
                    submitter.innerHTML = 'Procesando...';
                }
                const submitButtons = Array.from(formEl.querySelectorAll('button[type="submit"]'));
                submitButtons.forEach((btn) => {
                    if (btn instanceof HTMLButtonElement) {
                        btn.disabled = true;
                    }
                });
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
