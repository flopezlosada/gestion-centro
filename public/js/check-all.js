/**
 * «Marcar a todos» para una lista de casillas: un <button data-check-all="nombre[]" hidden> marca todas las
 * casillas con ese name dentro de su mismo formulario. Si ya estaban todas marcadas, las desmarca.
 *
 * Mejora progresiva: el botón llega oculto y solo se enseña aquí, así que sin JavaScript no aparece un
 * botón que no hace nada; las casillas se marcan igual una a una.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('button[data-check-all]'), function (button) {
            var form = button.form;
            if (!form) {
                return;
            }
            var boxes = Array.prototype.filter.call(form.elements, function (el) {
                return el.type === 'checkbox' && el.name === button.getAttribute('data-check-all');
            });
            if (boxes.length < 2) {
                return;
            }
            var label = button.textContent;
            var sync = function () {
                var all = boxes.every(function (box) { return box.checked; });
                button.textContent = all ? 'Desmarcar a todos' : label;
            };
            button.addEventListener('click', function () {
                var check = !boxes.every(function (box) { return box.checked; });
                boxes.forEach(function (box) { box.checked = check; });
                sync();
            });
            boxes.forEach(function (box) { box.addEventListener('change', sync); });
            sync();
            button.hidden = false;
        });
    });
})();
