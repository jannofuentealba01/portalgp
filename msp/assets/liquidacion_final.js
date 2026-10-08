(function () {
    'use strict';

    function configureModal(modal) {
        if (!modal) {
            return;
        }

        modal.addEventListener('show.bs.modal', function (event) {
            var trigger = event.relatedTarget;
            if (!trigger) {
                return;
            }

            var id = trigger.getAttribute('data-id') || '';
            var servicio = trigger.getAttribute('data-servicio') || 'Servicio';
            var local = trigger.getAttribute('data-local') || '-';
            var medidor = trigger.getAttribute('data-medidor') || '';
            var periodoSugerido = trigger.getAttribute('data-periodo-sugerido') || '';
            var fechaTermino = trigger.getAttribute('data-fecha-termino') || '';
            var hidden = modal.querySelector('input[name="id_liquidacion_servicio"]');
            if (hidden) {
                hidden.value = id;
            }

            var text = servicio + ' · Local ' + local;
            if (medidor !== '') {
                text += ' · Medidor ' + medidor;
            }
            modal.querySelectorAll('.js-servicio-contexto').forEach(function (element) {
                element.textContent = text;
            });

            var periodoInput = modal.querySelector('input[name="periodo_emision_mes"]');
            if (periodoInput && periodoSugerido !== '') {
                periodoInput.value = periodoSugerido;
            }
            var fechaHastaInput = modal.querySelector('input[name="fecha_hasta_consumo"]');
            if (fechaHastaInput && fechaTermino !== '') {
                fechaHastaInput.value = fechaTermino.substring(0, 10);
            }
        });
    }

    function parseDecimal(value) {
        var normalized = String(value || '').trim().replace(/\s+/g, '');
        if (normalized === '') {
            return null;
        }
        if (normalized.indexOf(',') >= 0) {
            normalized = normalized.replace(/\./g, '').replace(',', '.');
        }
        var number = Number(normalized);
        return Number.isFinite(number) ? number : null;
    }

    function configureConsumptionCalculation(modal) {
        if (!modal) {
            return;
        }
        var previous = modal.querySelector('input[name="lectura_anterior"]');
        var current = modal.querySelector('input[name="lectura_actual"]');
        var consumption = modal.querySelector('input[name="consumo_asignado"]');
        if (!previous || !current || !consumption) {
            return;
        }

        var update = function () {
            var previousValue = parseDecimal(previous.value);
            var currentValue = parseDecimal(current.value);
            if (previousValue === null || currentValue === null || currentValue < previousValue) {
                return;
            }
            consumption.value = String(Math.round((currentValue - previousValue) * 10000) / 10000).replace('.', ',');
        };
        previous.addEventListener('change', update);
        current.addEventListener('change', update);
    }

    document.addEventListener('DOMContentLoaded', function () {
        configureModal(document.getElementById('modalServicioTardio'));
        configureModal(document.getElementById('modalServicioNoAplica'));
        configureModal(document.getElementById('modalReabrirServicio'));
        configureModal(document.getElementById('modalConciliarServicio'));
        configureConsumptionCalculation(document.getElementById('modalServicioTardio'));
    });
})();
