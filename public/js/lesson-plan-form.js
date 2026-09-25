/* «Quitar el segundo tema» en la programación de una clase (lesson_plan/show): vacía su nombre, deja su
 * actividad en «Sin decir» y su resultado en «Aún no», y pliega el bloque. Quitar solo el nombre no
 * bastaba: con una actividad marcada, el segundo tema se guardaba igual, como «Sin tema». Se guarda con
 * el botón «Guardar» del formulario, como cualquier otro cambio. Sin JS el botón no aparece. */
(function () {
    'use strict';

    var button = document.querySelector('[data-remove-second-topic]');
    if (!button) {
        return;
    }
    var block = button.closest('details');
    var form = button.closest('form');
    button.hidden = false;

    button.addEventListener('click', function () {
        form.querySelector('input[name="tema2"]').value = '';
        ['actividad2', 'resultado2'].forEach(function (name) {
            var none = form.querySelector('input[name="' + name + '"][value=""]');
            if (none) {
                none.checked = true;
            }
        });
        block.open = false;
        block.querySelector('summary').focus();
    });
})();
