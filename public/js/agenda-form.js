/* Divulgación progresiva en el alta/edición de eventos de agenda: oculta los campos que dependen de
 * otro hasta que su condición se cumple, para no abrumar con selects que aún no aplican.
 *   - "Hasta" y "Avisarme" (data-qa-when="hasstart") solo cuando ya hay una hora de inicio: sin hora
 *     es un recordatorio suelto, y ni una hora de fin ni un aviso "10 minutos antes" significan nada.
 *   - "Repetir hasta" (data-qa-when="repeating") solo cuando hay una repetición elegida.
 *
 * Mejora progresiva: sin JS se muestran todos los campos y el formulario funciona igual; el servidor
 * es quien valida. Escucha el 'change' de los controles nativos (que siguen en el DOM aunque los
 * realce el combobox). La hora es un <input type="time">, no un <select>: de ahí que su selector no
 * fije etiqueta y que además de 'change' escuche 'input' (al teclear la hora, Chrome solo dispara
 * 'change' al completarla, pero al BORRARLA conviene ocultar los dependientes en el acto). */
(function () {
    'use strict';

    function apply() {
        var startTime = document.querySelector('[name$="[startTime]"]');
        var repeat = document.querySelector('select[name$="[repeat]"]');
        var form = (startTime || repeat) ? (startTime || repeat).closest('form') : null;
        if (!form) {
            return;
        }

        function show(when, visible) {
            form.querySelectorAll('[data-qa-when="' + when + '"]').forEach(function (el) {
                el.hidden = !visible;
            });
        }

        if (startTime) {
            var syncHasStart = function () { show('hasstart', startTime.value !== ''); };
            startTime.addEventListener('change', syncHasStart);
            startTime.addEventListener('input', syncHasStart);
            syncHasStart();
        }
        if (repeat) {
            var syncRepeat = function () { show('repeating', repeat.value !== '' && repeat.value !== 'none'); };
            repeat.addEventListener('change', syncRepeat);
            syncRepeat();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
})();
