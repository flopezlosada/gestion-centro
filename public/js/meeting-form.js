/* Convocatoria de reunión: lo que solo tienen las reuniones con equipo docente —el tipo, el orden del
 * día y todo lo del acta— se esconde al elegir "con alumnado" o "con familias", porque esas se registran
 * en RAICES y aquí solo son una cita.
 *
 * Mejora progresiva: sin JS los bloques siguen a la vista y no pasa nada, porque quien manda es el
 * servidor — Meeting::setScope() limpia el tipo y los tres cuadros del acta en cuanto la reunión deja de
 * ser con equipo docente. Esto solo evita rellenar cosas que se van a tirar.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var radios = Array.prototype.slice.call(document.querySelectorAll('[name$="[scope]"]'));
        var blocks = Array.prototype.slice.call(document.querySelectorAll('[data-staff-only]'));
        if (radios.length === 0 || blocks.length === 0) {
            return;
        }

        // El aviso de "esto se registra en RAICES" se pinta una vez y se reutiliza.
        var notice = document.createElement('p');
        notice.className = 'help';
        notice.hidden = true;
        var lastRadio = radios[radios.length - 1];
        var anchor = lastRadio.closest('.form-row') || lastRadio.parentNode;
        anchor.appendChild(notice);

        function sync() {
            var chosen = radios.filter(function (r) { return r.checked; })[0];
            // Sin nada marcado se enseña todo: no esconder es siempre la opción segura.
            var keeps = !chosen || chosen.getAttribute('data-keeps-minutes') !== '0';

            blocks.forEach(function (block) { block.hidden = !keeps; });
            notice.hidden = keeps;
            if (!keeps) {
                notice.textContent = 'Estas reuniones se registran en RAICES: aquí queda la cita y a quién se convoca.';
            }
        }

        radios.forEach(function (radio) { radio.addEventListener('change', sync); });
        sync();
    });
})();
