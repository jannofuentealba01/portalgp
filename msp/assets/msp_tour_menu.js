(function () {
    'use strict';

    var menuRoot = document.querySelector('[data-tour="menu-root"]');
    var startButton = document.getElementById('mspStartMenuTour');

    if (!menuRoot || !startButton) {
        return;
    }

    var storageKey = 'msp_tour_menu_v1_seen';

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

    function markTourSeen() {
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
                element: '[data-tour="menu-header"]',
                popover: {
                    title: 'Menú principal MSP',
                    description: 'Desde aquí navegas a todos los módulos disponibles del sistema.'
                }
            },
            {
                element: '[data-tour="menu-admin"]',
                popover: {
                    title: 'Administración',
                    description: 'Configura contratos, arrendatarios, locales y catálogos base.'
                }
            },
            {
                element: '[data-tour="menu-facturacion"]',
                popover: {
                    title: 'Facturación',
                    description: 'Inicia la operación mensual y revisa control diario.'
                }
            },
            {
                element: '[data-tour="menu-cobranza"]',
                popover: {
                    title: 'Cobranza',
                    description: 'Gestiona documentos, pagos, cargos extra y saldos a favor.'
                }
            },
            {
                element: '[data-tour="menu-reportes"]',
                popover: {
                    title: 'Reportes',
                    description: 'Consulta reportes contables y de consumo para seguimiento.'
                }
            },
            {
                element: '[data-tour="menu-help"]',
                popover: {
                    title: 'Botón de Ayuda',
                    description: 'Usa el botón Ayuda del encabezado para abrir la guía y preguntas frecuentes.'
                }
            }
        ];
    }

    function runTour() {
        var driverFactory = getDriverFactory();
        if (!driverFactory) {
            return;
        }

        var driverObj = driverFactory({
            showProgress: true,
            animate: true,
            allowClose: true,
            overlayClickBehavior: 'close',
            nextBtnText: 'Siguiente',
            prevBtnText: 'Anterior',
            doneBtnText: 'Finalizar',
            steps: buildSteps(),
            onDestroyed: function () {
                markTourSeen();
            }
        });

        if (driverObj && typeof driverObj.drive === 'function') {
            driverObj.drive();
        }
    }

    startButton.addEventListener('click', function () {
        runTour();
    });

    if (shouldAutostart()) {
        window.setTimeout(runTour, 300);
    }
})();
