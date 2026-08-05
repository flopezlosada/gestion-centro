/* "Poner a todos" en la pantalla de clasificar espacios.
 *
 * Treinta de los cuarenta espacios del centro son aulas ordinarias de un grupo: a mano son treinta
 * selectores iguales antes de llegar a los diez que de verdad hay que pensar. Esto solo escribe en los
 * selectores de una columna —no envía nada—, así que lo puesto se revisa antes de guardar y un clic de
 * más se corrige cambiando lo que haga falta.
 *
 * Mejora progresiva: el bloque nace con [hidden] en la plantilla y solo se muestra si este script corre.
 * Sin JS la tabla se rellena a mano, que es lo que se hacía antes de que la pantalla existiera.
 *
 * Mismo patrón que quota-bulk.js, con dos diferencias: son varias columnas (tipo y tamaño), cada una con
 * su propio origen, y el valor sale de un <select> en vez de un <input number>. */
(function () {
    'use strict';

    var panel = document.querySelector('[data-catalogue-bulk]');
    if (!panel) {
        return;
    }

    panel.hidden = false;

    panel.querySelectorAll('[data-catalogue-bulk-apply]').forEach(function (button) {
        button.addEventListener('click', function () {
            var field = button.getAttribute('data-catalogue-bulk-apply');
            var source = panel.querySelector('[data-catalogue-bulk-value="' + field + '"]');
            if (!source) {
                return;
            }

            document.querySelectorAll('[data-catalogue-field="' + field + '"]').forEach(function (select) {
                select.value = source.value;
            });
        });
    });
})();
