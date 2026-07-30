/* Atajos de la explicación del cambio en "Modificar guardia": un clic rellena el textarea con el caso
 * habitual, que sigue siendo editable. Mejora progresiva: los botones nacen ocultos en el HTML y solo
 * este script los muestra, porque sin JS no harían nada; el campo se escribe a mano igual. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var presets = document.querySelector('[data-change-note-presets]');
        var field = document.getElementById('motivo');
        if (!presets || !field) {
            return;
        }
        presets.hidden = false;

        presets.addEventListener('click', function (event) {
            var button = event.target.closest('button');
            if (!button || !presets.contains(button)) {
                return;
            }
            // Rellena y deja el cursor al final: el atajo es un punto de partida, no una respuesta cerrada.
            field.value = button.textContent.trim();
            field.focus();
            field.setSelectionRange(field.value.length, field.value.length);
        });
    });
})();
