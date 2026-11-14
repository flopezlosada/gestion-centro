/**
 * «Marcar a todos» para una lista de casillas: un <button data-check-all="nombre[]" hidden> marca todas las
 * casillas con ese name dentro de su mismo formulario. Si ya estaban todas marcadas, las desmarca.
 * Con data-check-all-only="data-algo" solo cuenta las casillas que lleven ese atributo; el resto no se toca.
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
            var only = button.getAttribute('data-check-all-only');
            var boxes = Array.prototype.filter.call(form.elements, function (el) {
                return el.type === 'checkbox' && el.name === button.getAttribute('data-check-all')
                    && (!only || el.hasAttribute(only));
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
                boxes.forEach(function (box) {
                    if (box.checked !== check) {
                        box.checked = check;
                        // Marcar por código no dispara «change»: sin él, quien escucha la lista (el buscador
                        // de personas, que solo enseña las marcadas; el contador de asistencia) no se entera.
                        box.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
                sync();
            });
            boxes.forEach(function (box) { box.addEventListener('change', sync); });
            sync();
            button.hidden = false;
        });
    });
})();
