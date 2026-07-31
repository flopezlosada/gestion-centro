/* Explicación del cambio en "Modificar guardia". Dos cosas, las dos como mejora progresiva (sin JS la
 * pantalla sigue funcionando y quien manda es el servidor):
 *
 *  1. Atajos: un clic rellena el textarea con el caso habitual, que sigue siendo editable. Los botones
 *     nacen ocultos en el HTML porque sin JS no harían nada.
 *  2. Obligatoriedad condicional: el motivo solo hace falta si se CAMBIA de profesor de guardia. El
 *     script marca `required` y enseña el asterisco en cuanto el desplegable deja de tener su valor
 *     inicial, y lo retira si se vuelve atrás. Así el aviso llega antes de enviar, en vez de tras un
 *     viaje al servidor.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var field = document.getElementById('motivo');
        if (!field) {
            return;
        }

        var presets = document.querySelector('[data-change-note-presets]');
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
        var star = document.querySelector('[data-change-note-req]');
        if (!select) {
            return;
        }
        // El valor de partida se lee del DOM, no del `selected` del HTML: si el navegador restaura el
        // formulario al volver atrás, lo que vale es lo que hay en pantalla ahora.
        var initial = select.value;
        select.addEventListener('change', function () {
            var changed = select.value !== initial;
            field.required = changed;
            if (star) {
                star.hidden = !changed;
            }
        });
    });
})();
