/* "Poner a todos" en la pantalla de cupos de guardias.
 *
 * Rellenar setenta casillas a mano es una sentada; lo normal es poner el mismo cupo a todo el mundo y
 * bajar después las excepciones. Esto solo escribe en las casillas: no envía nada, así que lo puesto se
 * revisa antes de guardar y un clic de más se deshace cambiando lo que haga falta.
 *
 * Mejora progresiva: el bloque nace con [hidden] en la plantilla y solo se muestra si este script
 * corre. Sin JS la tabla se rellena a mano, que es exactamente lo que se hacía antes. */
(function () {
    'use strict';

    var panel = document.querySelector('[data-quota-bulk]');
    if (!panel) {
        return;
    }

    var source = panel.querySelector('[data-quota-bulk-value]');
    if (!source) {
        return;
    }

    panel.hidden = false;

    panel.querySelectorAll('[data-quota-bulk-apply]').forEach(function (button) {
        button.addEventListener('click', function () {
            var field = button.getAttribute('data-quota-bulk-apply');
            var value = source.value;

            document.querySelectorAll('[data-quota-field="' + field + '"]').forEach(function (input) {
                input.value = value;
            });
        });
    });
})();
