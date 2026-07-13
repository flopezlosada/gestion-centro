/* Divulgación progresiva en el alta/edición de eventos de agenda: oculta los campos que dependen de
 * otro hasta que su condición se cumple, para no abrumar con selects que aún no aplican.
 *   - "Hasta" (data-qa-when="hasstart") solo cuando ya hay una hora de inicio (si no hay hora, es un
 *     recordatorio y no tiene sentido una hora de fin).
 *   - "Repetir hasta" (data-qa-when="repeating") solo cuando hay una repetición elegida.
 *
 * Mejora progresiva: sin JS se muestran todos los campos y el formulario funciona igual; el servidor
 * es quien valida. Escucha el 'change' de los controles nativos (que siguen en el DOM aunque los
 * realce el combobox). */
(function () {
    'use strict';

    function apply() {
        var startTime = document.querySelector('select[name$="[startTime]"]');
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
