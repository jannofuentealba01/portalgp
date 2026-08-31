(function () {
    'use strict';

    var startBtn = document.getElementById('rpc_start_demo_btn');
    if (!startBtn) {
        return;
    }

    var stateKey = 'msp_rpc_demo_v1';
    var guardKey = 'msp_rpc_demo_guard_v1';
    var stepAppliedKey = 'msp_rpc_demo_step_applied_v1';

    function getDriverFactory() {
        if (window.driver && window.driver.js && typeof window.driver.js.driver === 'function') {
            return window.driver.js.driver;
        }
        if (window.Driver && typeof window.Driver === 'function') {
            return function (config) {
                return new window.Driver(config);
            };
        }
        return null;
    }

    function setState(state) {
        try {
            sessionStorage.setItem(stateKey, JSON.stringify(state));
        } catch (error) {
            // noop
        }
    }

    function getState() {
        try {
            var raw = sessionStorage.getItem(stateKey);
            return raw ? JSON.parse(raw) : null;
        } catch (error) {
            return null;
        }
    }

    function clearState() {
        try {
            sessionStorage.removeItem(stateKey);
            sessionStorage.removeItem(guardKey);
            sessionStorage.removeItem(stepAppliedKey);
        } catch (error) {
            // noop
        }
    }

    function setDemoGuard(enabled) {
        try {
            if (enabled) {
                sessionStorage.setItem(guardKey, '1');
            } else {
                sessionStorage.removeItem(guardKey);
            }
        } catch (error) {
            // noop
        }
    }

    function isDemoGuardEnabled() {
        try {
            return sessionStorage.getItem(guardKey) === '1';
        } catch (error) {
            return false;
        }
    }

    function setAppliedStep(step) {
        try {
            if (typeof step === 'string' && step !== '') {
                sessionStorage.setItem(stepAppliedKey, step);
            } else {
                sessionStorage.removeItem(stepAppliedKey);
            }
        } catch (error) {
            // noop
        }
    }

    function getAppliedStep() {
        try {
            return sessionStorage.getItem(stepAppliedKey) || '';
        } catch (error) {
            return '';
        }
    }

    function markStepApplied(step) {
        setAppliedStep(step);
    }

    function isStepApplied(step) {
        return getAppliedStep() === step;
    }

    function selectFirstOption(inputId, buttonId, listId) {
        var hiddenInput = document.getElementById(inputId);
        var triggerBtn = document.getElementById(buttonId);
        var list = document.getElementById(listId);
        if (!(hiddenInput instanceof HTMLInputElement) || !(list instanceof HTMLElement)) {
            return false;
        }

        if (triggerBtn instanceof HTMLButtonElement) {
            triggerBtn.click();
        }

        var option = list.querySelector('[data-value]:not(.d-none)');
        if (!(option instanceof HTMLElement)) {
            return false;
        }

        var value = String(option.dataset.value || '').trim();
        if (value === '') {
            return false;
        }

        hiddenInput.value = value;
        hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
    }

    function fillMontoDemo() {
        var montoView = document.getElementById('pc_monto_pagado_view');
        if (montoView instanceof HTMLInputElement) {
            montoView.value = '120000';
            montoView.dispatchEvent(new Event('input', { bubbles: true }));
            montoView.dispatchEvent(new Event('blur', { bubbles: true }));
        }
    }

    function fillFechaDemo() {
        var fechaPago = document.getElementById('pc_fecha_pago');
        if (fechaPago instanceof HTMLInputElement) {
            if (fechaPago.value.trim() === '') {
                fechaPago.value = new Date().toISOString().slice(0, 10);
            }
            fechaPago.dispatchEvent(new Event('input', { bubbles: true }));
            fechaPago.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function fillMedioPagoDemo() {
        var medioPago = document.getElementById('pc_medio_pago');
        if (medioPago instanceof HTMLSelectElement) {
            medioPago.value = 'Transferencia';
            medioPago.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function fillReferenciaDemo() {
        var referencia = document.getElementById('pc_referencia_pago');
        if (referencia instanceof HTMLInputElement) {
            referencia.value = 'DEMO-TRX-001';
            referencia.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    function applyDemoStep(step) {
        if (isStepApplied(step)) {
            return;
        }

        if (step === 'monto') {
            fillMontoDemo();
        } else if (step === 'fecha') {
            fillFechaDemo();
        } else if (step === 'medio_pago') {
            fillMedioPagoDemo();
        } else if (step === 'referencia') {
            fillReferenciaDemo();
        } else {
            return;
        }

        markStepApplied(step);
    }

    function runModalTour() {
        var driverFactory = getDriverFactory();
        if (!driverFactory) {
            return;
        }

        var state = getState();
        var modalSteps = ['modal', 'monto', 'fecha', 'medio_pago', 'referencia', 'preview', 'submit'];
        var startIndex = Math.max(0, modalSteps.indexOf(state && state.step ? state.step : 'modal'));

        var demoDriver = driverFactory({
            showProgress: true,
            animate: true,
            allowClose: true,
            overlayClickBehavior: 'close',
            nextBtnText: 'Siguiente',
            prevBtnText: 'Anterior',
            doneBtnText: 'Finalizar',
            steps: [
                {
                    element: '[data-tour="rpc-modal"]',
                    popover: {
                        title: 'Paso 1: Modal de registro',
                        description: 'Aquí registras el pago del contrato y revisas su distribución antes de guardar.'
                    },
                    onHighlighted: function () {
                        setState({ active: true, step: 'modal' });
                        setAppliedStep('');
                    }
                },
                {
                    element: '[data-tour="rpc-monto"]',
                    popover: {
                        title: 'Paso 2: Monto pagado',
                        description: 'Cargamos un monto demo para simular la distribución.'
                    },
                    onHighlighted: function () {
                        setState({ active: true, step: 'monto' });
                        applyDemoStep('monto');
                    }
                },
                {
                    element: '[data-tour="rpc-fecha-pago"]',
                    popover: {
                        title: 'Paso 3: Fecha de pago',
                        description: 'Marcamos la fecha del pago en su propio paso para que el recorrido no se salte este campo.'
                    },
                    onHighlighted: function () {
                        setState({ active: true, step: 'fecha' });
                        applyDemoStep('fecha');
                    }
                },
                {
                    element: '[data-tour="rpc-medio-pago"]',
                    popover: {
                        title: 'Paso 4: Medio de pago',
                        description: 'Seleccionamos el medio de pago sin tocar todavía la referencia.'
                    },
                    onHighlighted: function () {
                        setState({ active: true, step: 'medio_pago' });
                        applyDemoStep('medio_pago');
                    }
                },
                {
                    element: '[data-tour="rpc-referencia"]',
                    popover: {
                        title: 'Paso 5: Referencia',
                        description: 'Completamos una referencia demo para dejar trazabilidad de la operación.'
                    },
                    onHighlighted: function () {
                        setState({ active: true, step: 'referencia' });
                        applyDemoStep('referencia');
                    }
                },
                {
                    element: '[data-tour="rpc-preview"]',
                    popover: {
                        title: 'Paso 6: Vista previa',
                        description: 'Aquí ves cómo se reparte el pago entre documentos pendientes.'
                    },
                    onHighlighted: function () {
                        setState({ active: true, step: 'preview' });
                        setAppliedStep('');
                    }
                },
                {
                    element: '[data-tour="rpc-submit-btn"]',
                    popover: {
                        title: 'Paso 7: Confirmación',
                        description: 'En modo demo bloqueamos el envío. Este botón es para operación real.'
                    },
                    onHighlighted: function () {
                        setState({ active: true, step: 'submit' });
                        setAppliedStep('');
                    }
                }
            ],
            onDestroyed: function () {
                clearState();
            }
        });

        demoDriver.drive(startIndex);
    }

    function runDemo() {
        var state = getState();
        if (!state || state.active !== true) {
            return;
        }

        var arr = document.getElementById('id_arrendatario');
        var contrato = document.getElementById('id_contrato_arriendo');
        var arrValue = arr instanceof HTMLInputElement ? arr.value.trim() : '';
        var contratoValue = contrato instanceof HTMLInputElement ? contrato.value.trim() : '';
        var currentStep = typeof state.step === 'string' ? state.step : 'arrendatario';

        if (currentStep === 'arrendatario' && arrValue === '') {
            selectFirstOption(
                'id_arrendatario',
                'arrendatario_dropdown_btn_pago_contrato',
                'arrendatario_dropdown_list_pago_contrato'
            );
            return;
        }

        if (currentStep === 'arrendatario' && arrValue !== '') {
            setState({ active: true, step: 'contrato' });
            currentStep = 'contrato';
        }

        if (currentStep === 'contrato' && contratoValue === '') {
            selectFirstOption(
                'id_contrato_arriendo',
                'contrato_dropdown_btn_pago_contrato',
                'contrato_dropdown_list_pago_contrato'
            );
            return;
        }

        if (currentStep === 'contrato' && contratoValue !== '') {
            setState({ active: true, step: 'abrir_modal' });
            currentStep = 'abrir_modal';
        }

        if (currentStep !== 'abrir_modal' && currentStep !== 'modal' && currentStep !== 'monto'
            && currentStep !== 'fecha' && currentStep !== 'medio_pago' && currentStep !== 'referencia'
            && currentStep !== 'preview' && currentStep !== 'submit'
        ) {
            setState({ active: true, step: 'abrir_modal' });
            currentStep = 'abrir_modal';
        }

        var openBtn = document.getElementById('rpc_open_modal_btn');
        var modal = document.getElementById('modalPagoContrato');
        if (!(openBtn instanceof HTMLButtonElement)) {
            setState({ active: true, step: 'modal' });
            runModalTour();
            return;
        }
        if (!(modal instanceof HTMLElement)) {
            setState({ active: true, step: 'modal' });
            openBtn.click();
            return;
        }

        if (modal.classList.contains('show')) {
            if (currentStep === 'abrir_modal') {
                setState({ active: true, step: 'modal' });
            }
            runModalTour();
            return;
        }

        modal.addEventListener('shown.bs.modal', function () {
            setState({ active: true, step: 'modal' });
            runModalTour();
        }, { once: true });
        openBtn.click();
    }

    var formPago = document.getElementById('form_pago_contrato');
    if (formPago instanceof HTMLFormElement) {
        formPago.addEventListener('submit', function (event) {
            if (!isDemoGuardEnabled()) {
                return;
            }
            event.preventDefault();
            window.alert('Demo guiada activa: no se enviará ningún pago real.');
        });
    }

    startBtn.addEventListener('click', function () {
        setDemoGuard(true);
        setAppliedStep('');
        setState({ active: true, step: 'arrendatario' });
        runDemo();
    });

    if (isDemoGuardEnabled()) {
        runDemo();
    }
})();
