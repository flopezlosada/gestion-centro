/**
 * El acta en la ficha de una reunión: lo que la lista de asistencia y la subida de un acta hacen con JS.
 *
 *  - Recuento en vivo («X de N asistieron») y su barra, al marcar o desmarcar (también con «Marcar a todos»,
 *    de check-all.js, que cambia las casillas sin disparar `change`).
 *  - Buscador por nombre en listas largas: llega oculto ([data-roll-search-box]) y solo se enseña aquí.
 *  - El nombre del archivo elegido para subir, que el botón «Elegir archivo…» no enseña por sí solo.
 *  - Avisar, al publicar un borrador, de que hay cambios sin guardar que no irán en lo enviado.
 *
 * Mejora progresiva: sin JS la lista se marca igual (casillas reales; el aspecto sale de :has(:checked)) y el
 * recuento es el que pintó el servidor.
 */
(function () {
    'use strict';

    function setUpRoll(roll) {
        var boxes = Array.prototype.slice.call(roll.querySelectorAll('input[type="checkbox"]'));
        var count = roll.querySelector('[data-roll-count]');
        var meter = roll.querySelector('[data-roll-meter]');
        if (boxes.length === 0) {
            return;
        }

        var refresh = function () {
            var checked = boxes.filter(function (box) { return box.checked; }).length;
            if (count) {
                count.textContent = String(checked);
            }
            if (meter) {
                meter.style.width = Math.round(checked * 100 / boxes.length) + '%';
            }
        };
        roll.addEventListener('change', refresh);
        // «Marcar a todos» cambia las casillas desde su propio manejador, que corre antes que este.
        roll.addEventListener('click', function (event) {
            if (event.target.closest('[data-check-all]')) {
                refresh();
            }
        });

        var searchBox = roll.querySelector('[data-roll-search-box]');
        var search = roll.querySelector('[data-roll-search]');
        var none = roll.querySelector('[data-roll-none]');
        var query = roll.querySelector('[data-roll-query]');
        if (searchBox && search) {
            var people = Array.prototype.slice.call(roll.querySelectorAll('[data-roll-name]'));
            search.addEventListener('input', function () {
                var term = search.value.trim().toLowerCase();
                var shown = 0;
                people.forEach(function (person) {
                    var match = term === '' || person.getAttribute('data-roll-name').indexOf(term) !== -1;
                    person.hidden = !match;
                    shown += match ? 1 : 0;
                });
                if (none) {
                    none.hidden = shown > 0;
                    if (query) {
                        query.textContent = search.value.trim();
                    }
                }
            });
            // Enter en el buscador enviaría el formulario del acta entero.
            search.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
            });
            searchBox.hidden = false;
        }
    }

    function setUpFilePick(input) {
        var form = input.form;
        var label = form && form.querySelector('[data-file-name]');
        if (!label) {
            return;
        }
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            label.textContent = file ? file.name : '';
            label.hidden = !file;
        });
    }

    /**
     * Publicar un borrador con lo escrito sin guardar: lo que se envía es el PDF ya generado, y lo tecleado
     * se pierde. Si el formulario del acta ha cambiado, la pregunta de confirmación de publicar lo dice.
     *
     * La foto del formulario se toma al ENTRAR en él la primera vez, no al cargar: Trix reescribe el HTML de
     * los cuadros al montarse, y comparar con lo que vino del servidor daría cambios que nadie ha hecho.
     */
    function setUpUnsavedWarning(actaForm, publishForm) {
        var unsaved = publishForm.getAttribute('data-confirm-unsaved');
        var saved = publishForm.getAttribute('data-confirm');
        if (!unsaved) {
            return;
        }
        var snapshot = null;
        var serialize = function () {
            return new URLSearchParams(new FormData(actaForm)).toString();
        };
        actaForm.addEventListener('focusin', function () {
            if (snapshot === null) {
                snapshot = serialize();
            }
        });
        // En captura: corre antes que confirm-dialog.js, que lee data-confirm al burbujear.
        document.addEventListener('submit', function (event) {
            if (event.target === publishForm) {
                publishForm.setAttribute('data-confirm', snapshot !== null && serialize() !== snapshot ? unsaved : saved);
            }
        }, true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-roll]'), setUpRoll);
        Array.prototype.forEach.call(document.querySelectorAll('input[data-file-pick]'), setUpFilePick);
        var actaForm = document.getElementById('acta-form');
        var publishForm = document.getElementById('publish-form');
        if (actaForm && publishForm) {
            setUpUnsavedWarning(actaForm, publishForm);
        }
    });
})();
