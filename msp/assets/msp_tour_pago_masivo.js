(function () {
    'use strict';

    var root = document.querySelector('[data-tour="pm-root"]');
    var startButton = document.getElementById('mspStartPagoMasivoTour');
    if (!root || !startButton) {
        return;
    }

    var storageKey = 'msp_tour_pago_masivo_v1_seen';

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

    function hasForceParam() {
        return new URLSearchParams(window.location.search).get('tour') === '1';
    }

    function markSeen() {
        try {
            localStorage.setItem(storageKey, '1');
        } catch (error) {
            // noop
        }
    }

    function shouldAutostart() {
        if (hasForceParam()) {
            return true;
        }
        try {
            return localStorage.getItem(storageKey) !== '1';
        } catch (error) {
            return false;
        }
    }

    function buildSteps() {
        return [
            {
                element: '[data-tour="pm-hero"]',
                popover: {
                    title: 'Pago Masivo Mensual',
                    description: 'Esta pantalla te permite registrar pagos históricos en lote para un mes específico.'
                }
            },
            {
                element: '[data-tour="pm-periodo"]',
                popover: {
                    title: '1) Selecciona el mes',
                    description: 'Define el mes de trabajo. La grilla se recarga automáticamente con documentos pendientes.'
                }
            },
            {
                element: '[data-tour="pm-datos-lote"]',
                popover: {
                    title: '2) Completa datos comunes',
                    description: 'Ingresa fecha, medio, referencia y observaciones que aplicarán a todas las filas seleccionadas.'
                }
            },
            {
                element: '[data-tour="pm-tabla-lote"]',
                popover: {
                    title: '3) Marca filas y ajusta montos',
                    description: 'Selecciona documentos, opcionalmente usa ajuste +/- y valida el monto final antes de ejecutar.'
                }
            },
            {
                element: '#btn_ejecutar_lote',
                popover: {
                    title: '4) Ejecuta el lote',
                    description: 'Procesa solo filas marcadas. Si pagas por sobre saldo, el excedente queda como saldo a favor.'
                }
            },
            {
                element: '[data-tour="pm-tour-button"]',
                popover: {
                    title: 'Repetir tutorial',
                    description: 'Puedes volver a iniciar este tour cuando quieras desde este botón o desde el botón Ayuda.'
                }
            }
        ];
    }

    function runTour() {
        var driverFactory = getDriverFactory();
        if (!driverFactory) {
            return;
        }

        var tour = driverFactory({
            showProgress: true,
            animate: true,
            allowClose: true,
            overlayClickBehavior: 'close',
            nextBtnText: 'Siguiente',
            prevBtnText: 'Anterior',
            doneBtnText: 'Finalizar',
            steps: buildSteps(),
            onDestroyed: function () {
                markSeen();
            }
        });

        if (tour && typeof tour.drive === 'function') {
            tour.drive();
        }
    }

    startButton.addEventListener('click', runTour);

    if (shouldAutostart()) {
        window.setTimeout(runTour, 300);
    }
})();
