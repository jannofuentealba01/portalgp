(function () {
    'use strict';

    var menuRoot = document.querySelector('[data-tour="menu-root"]');
    var startButton = document.getElementById('mspStartMenuTour');

    if (!menuRoot || !startButton) {
        return;
    }

    var storageKey = 'msp_tour_menu_v2_seen';

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
                element: '[data-tour="menu-alta"]',
                popover: {
                    title: 'Gestión comercial y alta',
                    description: 'Registra al arrendatario, revisa locales y tiendas, crea el contrato y gestiona garantías.'
                }
            },
            {
                element: '[data-tour="menu-operacion"]',
                popover: {
                    title: 'Operación mensual',
                    description: 'Sigue el trabajo del período desde los pendientes y lecturas hasta el cierre mensual.'
                }
            },
            {
                element: '[data-tour="menu-cobranza"]',
                popover: {
                    title: 'Cobranza y tesorería',
                    description: 'Gestiona documentos, pagos, cargos adicionales, saldos a favor y respaldos.'
                }
            },
            {
                element: '[data-tour="menu-cierre"]',
                popover: {
                    title: 'Cierre y salida',
                    description: 'Consulta los casos que llegan al final del ciclo contractual y sus deudas posteriores.'
                }
            },
            {
                element: '[data-tour="menu-reportes"]',
                popover: {
                    title: 'Reportes y control',
                    description: 'Consulta el dashboard, libro diario, aging y trazabilidad sin modificar la operación.'
                }
            },
            {
                element: '[data-tour="menu-configuracion"]',
                popover: {
                    title: 'Configuración',
                    description: 'Administra catálogos maestros y la configuración de correos según tus permisos.'
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
