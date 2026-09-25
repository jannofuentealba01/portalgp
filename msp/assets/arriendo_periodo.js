(function () {
    'use strict';

    const form = document.querySelector('[data-arriendo-periodo-form]');
    if (!form) {
        return;
    }

    const confirmedInput = form.querySelector('[data-confirmar-arriendo-cero]');
    if (!confirmedInput) {
        return;
    }

    function isZero(value) {
        return /^0+(?:[.,]0+)?$/.test(String(value || '').replace(/\s/g, ''));
    }

    form.addEventListener('submit', function (event) {
        confirmedInput.value = '0';
        const period = form.querySelector('[name="periodo"]')?.value || '';
        const newZeroRows = [];

        form.querySelectorAll('[data-arriendo-periodo-row]').forEach(function (row) {
            if (row.dataset.originalZero === '1' || row.querySelector('[name$="[limpiar]"]')?.checked) {
                return;
            }

            const clp = row.querySelector('[name$="[valor_periodo_clp]"]')?.value.trim() || '';
            const uf = row.querySelector('[name$="[valor_periodo_uf]"]')?.value.trim() || '';
            const effectiveValue = clp !== '' ? clp : uf;
            if (effectiveValue !== '' && isZero(effectiveValue)) {
                newZeroRows.push(row.dataset.rentLabel || 'Contrato-local');
            }
        });

        if (newZeroRows.length === 0) {
            return;
        }

        const details = newZeroRows.map(function (label) { return '• ' + label; }).join('\n');
        const message = '¿Estás seguro de registrar arriendo 0 para ' + period + '?\n\n'
            + details + '\n\nEsto no recalcula automáticamente documentos ya emitidos.';
        if (!window.confirm(message)) {
            event.preventDefault();
            return;
        }

        confirmedInput.value = '1';
    });
}());
