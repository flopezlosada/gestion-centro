/* Formulario de incidencia TIC: el grupo solo se pregunta si el equipo lo estaba usando una clase.
 * Al marcar «es de uso individual» la fila del grupo desaparece y se vacía.
 *
 * Mejora progresiva: sin JS se ven los dos campos y el propio formulario, en el servidor, ignora el
 * grupo cuando la incidencia es de uso individual (App\Form\TicIncidentType), así que no se puede
 * guardar una contradicción por tener el JavaScript apagado.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var individual = document.querySelector('[name$="[individualUse]"]');
        var groupRow = document.querySelector('.form-row[data-group-step]');
        var groupInput = document.querySelector('[name$="[groupName]"]');
        if (!individual || !groupRow) {
            return;
        }

        function sync() {
            groupRow.hidden = individual.checked;
            if (individual.checked && groupInput) {
                groupInput.value = '';
            }
        }

        individual.addEventListener('change', sync);
        sync();
    });
})();
