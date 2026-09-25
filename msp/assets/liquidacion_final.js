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
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        configureModal(document.getElementById('modalServicioTardio'));
        configureModal(document.getElementById('modalServicioNoAplica'));
        configureModal(document.getElementById('modalReabrirServicio'));
        configureModal(document.getElementById('modalConciliarServicio'));
    });
})();
