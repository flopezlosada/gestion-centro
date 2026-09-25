/* Explicación del cambio en "Modificar guardia". Todo como mejora progresiva (sin JS la pantalla sigue
 * funcionando, el bloque está siempre a la vista y quien manda es el servidor):
 *
 *  1. Aparece cuando hace falta: el "¿por qué?" explica un cambio de quién está en el aula, así que
 *     se esconde hasta que se toca el docente de guardia o la casilla de "no se cubrió", y vuelve a
 *     esconderse si se deshace el cambio (salvo que ya se haya escrito algo: eso no se tapa).
 *  2. Atajos: un clic rellena el textarea con el caso habitual, que sigue siendo editable. Los botones
 *     nacen ocultos en el HTML porque sin JS no harían nada.
 *  3. Obligatoriedad condicional: el motivo solo hace falta si se CAMBIA de docente de guardia. Se marca
 *     `required` y se enseña el asterisco en cuanto el desplegable deja de tener su valor inicial, y se
 *     retira si se vuelve atrás. Así el aviso llega antes de enviar, en vez de tras un viaje al servidor.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var field = document.getElementById('motivo');
        var block = document.querySelector('[data-change-note]');
        if (!field || !block) {
            return;
        }

        var presets = block.querySelector('[data-change-note-presets]');
        if (presets) {
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
        }

        var select = document.getElementById('guardia');
        var notCovered = document.getElementById('not_covered');
        var star = block.querySelector('[data-change-note-req]');
        // Los valores de partida se leen del DOM, no del `selected`/`checked` del HTML: si el navegador
        // restaura el formulario al volver atrás, lo que vale es lo que hay en pantalla ahora.
        var initialGuardia = select ? select.value : null;
        var initialNotCovered = notCovered ? notCovered.checked : null;

        function sync() {
            var guardiaChanged = !!select && select.value !== initialGuardia;
            var notCoveredChanged = !!notCovered && notCovered.checked !== initialNotCovered;
            block.hidden = !guardiaChanged && !notCoveredChanged && '' === field.value.trim();
            field.required = guardiaChanged;
            if (star) {
                star.hidden = !guardiaChanged;
            }
        }

        [select, notCovered].forEach(function (control) {
            if (control) {
                control.addEventListener('change', sync);
            }
        });
        sync();
    });
})();
