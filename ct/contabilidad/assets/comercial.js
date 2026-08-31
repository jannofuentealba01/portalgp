(() => {
    const round = (value, decimals) => {
        const factor = 10 ** decimals;
        return Math.round(value * factor) / factor;
    };

    const parsePositive = (value) => {
        const parsed = Number(String(value || '').replace(',', '.'));
        return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
    };

    const formatSuperficie = (value) => {
        return new Intl.NumberFormat('es-CL', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
    };

    const findSearchableOptionByValue = (pickerRoot, value) => {
        if (!(pickerRoot instanceof HTMLElement)) return null;
        const safeValue = String(value || '').trim();
        if (safeValue === '') return null;
        const options = Array.from(pickerRoot.querySelectorAll('.js-searchable-option'));
        return options.find((option) => (
            option instanceof HTMLElement
            && String(option.dataset.value || '').trim() === safeValue
        )) || null;
    };

    const setFieldValue = (field, pickerId, value) => {
        const target = String(value || '').trim();
        if (target === '') return;

        if (window.CtSearchableSelect && typeof window.CtSearchableSelect.get === 'function' && pickerId) {
            const instance = window.CtSearchableSelect.get(pickerId);
            if (instance && typeof instance.setValue === 'function') {
                instance.setValue(target);
                return;
            }
        }

        if (field instanceof HTMLSelectElement) {
            const option = Array.from(field.options).find((opt) => String(opt.value) === target);
            if (option) {
                field.value = target;
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return;
        }

        if (field instanceof HTMLInputElement) {
            field.value = target;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    document.querySelectorAll('.js-open-modal-tasacion').forEach((btn) => {
        btn.addEventListener('click', () => {
            const idTerreno = btn.getAttribute('data-id-terreno') || '';
            setFieldValue(document.querySelector('#ct-tasacion-id-terreno'), 'ct-tasacion-id-terreno-picker', idTerreno);
        });
    });

    const tasacionTerrenoField = document.querySelector('#ct-tasacion-id-terreno');
    const tasacionTerrenoPicker = document.querySelector('#ct-tasacion-id-terreno-picker');
    const tasacionValorTotalInput = document.querySelector('#ct-tasacion-valor-total');
    const tasacionValorM2Input = document.querySelector('#ct-tasacion-valor-m2');
    const tasacionSuperficieInfo = document.querySelector('#ct-tasacion-superficie-info');

    if (
        (tasacionTerrenoField instanceof HTMLSelectElement || tasacionTerrenoField instanceof HTMLInputElement)
        && tasacionValorTotalInput instanceof HTMLInputElement
        && tasacionValorM2Input instanceof HTMLInputElement
    ) {
        const getSuperficie = () => {
            if (tasacionTerrenoField instanceof HTMLSelectElement) {
                const option = tasacionTerrenoField.selectedOptions[0];
                if (!(option instanceof HTMLOptionElement)) return null;
                return parsePositive(option.getAttribute('data-superficie-m2'));
            }
            const option = findSearchableOptionByValue(
                tasacionTerrenoPicker instanceof HTMLElement ? tasacionTerrenoPicker : null,
                tasacionTerrenoField.value,
            );
            if (!(option instanceof HTMLElement)) return null;
            return parsePositive(option.dataset.superficieM2 || '');
        };

        const refreshSuperficieInfo = () => {
            if (!(tasacionSuperficieInfo instanceof HTMLElement)) return;
            const superficie = getSuperficie();
            if (superficie === null) {
                tasacionSuperficieInfo.textContent = 'Superficie: selecciona un terreno.';
                return;
            }
            tasacionSuperficieInfo.textContent = `Superficie: ${formatSuperficie(superficie)} m²`;
        };

        let lockCalculation = false;

        const recalcFromTotal = () => {
            if (lockCalculation) return;
            const superficie = getSuperficie();
            const total = parsePositive(tasacionValorTotalInput.value);
            if (superficie === null || total === null) return;
            lockCalculation = true;
            tasacionValorM2Input.value = String(round(total / superficie, 4));
            lockCalculation = false;
        };

        const recalcFromM2 = () => {
            if (lockCalculation) return;
            const superficie = getSuperficie();
            const valorM2 = parsePositive(tasacionValorM2Input.value);
            if (superficie === null || valorM2 === null) return;
            lockCalculation = true;
            tasacionValorTotalInput.value = String(round(valorM2 * superficie, 2));
            lockCalculation = false;
        };

        tasacionTerrenoField.addEventListener('change', () => {
            refreshSuperficieInfo();
            if (parsePositive(tasacionValorTotalInput.value) !== null) {
                recalcFromTotal();
            } else if (parsePositive(tasacionValorM2Input.value) !== null) {
                recalcFromM2();
            }
        });

        tasacionValorTotalInput.addEventListener('input', recalcFromTotal);
        tasacionValorM2Input.addEventListener('input', recalcFromM2);
        refreshSuperficieInfo();
    }

    document.querySelectorAll('.js-open-modal-venta').forEach((btn) => {
        btn.addEventListener('click', () => {
            const idTerreno = btn.getAttribute('data-id-terreno') || '';
            setFieldValue(document.querySelector('#ct-venta-id-terreno'), 'ct-venta-id-terreno-picker', idTerreno);
        });
    });

    const ventaTerrenoField = document.querySelector('#ct-venta-id-terreno');
    const ventaTerrenoPicker = document.querySelector('#ct-venta-id-terreno-picker');
    const ventaValorTotalInput = document.querySelector('#ct-venta-valor-total');
    const ventaValorM2Input = document.querySelector('#ct-venta-valor-m2');
    const ventaSuperficieInfo = document.querySelector('#ct-venta-superficie-info');

    if (
        (ventaTerrenoField instanceof HTMLSelectElement || ventaTerrenoField instanceof HTMLInputElement)
        && ventaValorTotalInput instanceof HTMLInputElement
        && ventaValorM2Input instanceof HTMLInputElement
    ) {
        const getSuperficie = () => {
            if (ventaTerrenoField instanceof HTMLSelectElement) {
                const option = ventaTerrenoField.selectedOptions[0];
                if (!(option instanceof HTMLOptionElement)) return null;
                return parsePositive(option.getAttribute('data-superficie-m2'));
            }
            const option = findSearchableOptionByValue(
                ventaTerrenoPicker instanceof HTMLElement ? ventaTerrenoPicker : null,
                ventaTerrenoField.value,
            );
            if (!(option instanceof HTMLElement)) return null;
            return parsePositive(option.dataset.superficieM2 || '');
        };

        const refreshSuperficieInfo = () => {
            if (!(ventaSuperficieInfo instanceof HTMLElement)) return;
            const superficie = getSuperficie();
            if (superficie === null) {
                ventaSuperficieInfo.textContent = 'Superficie: selecciona un terreno.';
                return;
            }
            ventaSuperficieInfo.textContent = `Superficie: ${formatSuperficie(superficie)} m²`;
        };

        let lockCalculation = false;

        const recalcFromTotal = () => {
            if (lockCalculation) return;
            const superficie = getSuperficie();
            const total = parsePositive(ventaValorTotalInput.value);
            if (superficie === null || total === null) return;
            lockCalculation = true;
            ventaValorM2Input.value = String(round(total / superficie, 4));
            lockCalculation = false;
        };

        const recalcFromM2 = () => {
            if (lockCalculation) return;
            const superficie = getSuperficie();
            const valorM2 = parsePositive(ventaValorM2Input.value);
            if (superficie === null || valorM2 === null) return;
            lockCalculation = true;
            ventaValorTotalInput.value = String(round(valorM2 * superficie, 2));
            lockCalculation = false;
        };

        ventaTerrenoField.addEventListener('change', () => {
            refreshSuperficieInfo();
            if (parsePositive(ventaValorTotalInput.value) !== null) {
                recalcFromTotal();
            } else if (parsePositive(ventaValorM2Input.value) !== null) {
                recalcFromM2();
            }
        });

        ventaValorTotalInput.addEventListener('input', recalcFromTotal);
        ventaValorM2Input.addEventListener('input', recalcFromM2);
        refreshSuperficieInfo();
    }

    const compradoresBody = document.querySelector('#ct-venta-compradores-body');
    const addCompradorBtn = document.querySelector('#ct-venta-add-comprador');

    if (compradoresBody instanceof HTMLElement && addCompradorBtn instanceof HTMLElement) {
        const createCompradorRow = () => {
            const templateRow = compradoresBody.querySelector('.ct-venta-comprador-row');
            if (!(templateRow instanceof HTMLTableRowElement)) {
                return null;
            }
            const clone = templateRow.cloneNode(true);
            clone.querySelectorAll('input').forEach((input) => {
                input.value = '';
            });
            clone.querySelectorAll('select').forEach((select) => {
                select.selectedIndex = 0;
            });
            return clone;
        };

        addCompradorBtn.addEventListener('click', () => {
            const row = createCompradorRow();
            if (row) compradoresBody.appendChild(row);
        });

        compradoresBody.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) return;

            const removeBtn = target.closest('.js-remove-comprador');
            if (!(removeBtn instanceof HTMLButtonElement)) return;

            const row = removeBtn.closest('.ct-venta-comprador-row');
            if (!(row instanceof HTMLTableRowElement)) return;

            const rows = compradoresBody.querySelectorAll('.ct-venta-comprador-row');
            if (rows.length <= 1) {
                row.querySelectorAll('input').forEach((input) => {
                    input.value = '';
                });
                row.querySelectorAll('select').forEach((select) => {
                    select.selectedIndex = 0;
                });
                return;
            }

            row.remove();
        });
    }
})();
