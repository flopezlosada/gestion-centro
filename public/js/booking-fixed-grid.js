/**
 * Rejilla de una reserva fija (/reservas/fijas?recurso=…). Mejora progresiva: sin JavaScript las casillas se
 * envían con el formulario y es el servidor quien avisa de los choques. Con él:
 *  - el nombre de cada día marca o desmarca el día entero (menos lo que tiene clase);
 *  - los días que ya tienen reservas en las horas marcadas, dentro de las fechas pedidas, salen en un aviso
 *    ANTES de guardar, la casilla en rojo y el botón desactivado;
 *  - un resumen dice cuántas horas por semana se están pidiendo.
 * El servidor comprueba lo mismo al guardar: esto solo adelanta el aviso.
 */
(function () {
    'use strict';

    var SHOWN_CLASHES = 12;

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    /**
     * Los choques que declara una casilla ({@code data-clashes}): una lista de {d: fecha, t: texto}.
     * Un valor roto no tumba la pantalla: es una casilla sin choques.
     */
    function clashesOf(box) {
        var label = box.closest('[data-clashes]');
        if (!label) {
            return [];
        }
        try {
            var parsed = JSON.parse(label.getAttribute('data-clashes'));
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    ready(function () {
        var form = document.querySelector('[data-fixed-form]');
        if (!form) {
            return;
        }
        var boxes = Array.prototype.slice.call(form.querySelectorAll('input[name="franjas[]"]'));
        var from = form.querySelector('#desde');
        var to = form.querySelector('#hasta');
        var alertBox = form.querySelector('[data-fixed-clashes]');
        var summary = form.querySelector('[data-fixed-summary]');
        var submit = form.querySelector('[data-fixed-submit]');

        function ticked() {
            return boxes.filter(function (box) {
                return box.checked;
            });
        }

        /** Los choques de una casilla que caen entre «Desde» y «Hasta» (las fechas ISO se comparan como texto). */
        function clashesInRange(box) {
            var start = from ? from.value : '';
            var end = to ? to.value : '';
            return clashesOf(box).filter(function (c) {
                return (!start || c.d >= start) && (!end || c.d <= end);
            });
        }

        function refresh() {
            var chosen = ticked();
            var all = [];
            boxes.forEach(function (box) {
                var label = box.closest('.fcell');
                var inRange = box.checked ? clashesInRange(box) : [];
                if (label) {
                    label.classList.toggle('is-clash', inRange.length > 0);
                }
                all = all.concat(inRange);
            });

            if (alertBox) {
                alertBox.hidden = 0 === all.length;
                alertBox.textContent = '';
                if (all.length) {
                    var title = document.createElement('p');
                    title.textContent = 1 === all.length
                        ? 'No se puede crear: un día ya está reservado.'
                        : 'No se puede crear: ' + all.length + ' días ya están reservados.';
                    alertBox.appendChild(title);
                    var list = document.createElement('ul');
                    all.slice(0, SHOWN_CLASHES).forEach(function (c) {
                        var item = document.createElement('li');
                        item.textContent = c.t;
                        list.appendChild(item);
                    });
                    if (all.length > SHOWN_CLASHES) {
                        var more = document.createElement('li');
                        more.textContent = '… y ' + (all.length - SHOWN_CLASHES) + ' más.';
                        list.appendChild(more);
                    }
                    alertBox.appendChild(list);
                    var hint = document.createElement('p');
                    hint.textContent = 'Quita esas horas, cambia las fechas o pide a quien lo tiene que lo libere.';
                    alertBox.appendChild(hint);
                }
            }
            if (summary) {
                summary.textContent = chosen.length
                    ? chosen.length + (1 === chosen.length ? ' hora' : ' horas') + ' por semana, de ' + (from ? from.value : '') + ' a ' + (to ? to.value : '') + '.'
                    : 'Marca al menos una hora en la rejilla.';
            }
            if (submit) {
                submit.disabled = 0 === chosen.length || all.length > 0;
            }
        }

        // «Hasta»: «Fin de curso» y «Navidad» fijan su fecha (data-date) y esconden el campo; «Otra fecha» lo
        // enseña. Sin JavaScript el campo está siempre a la vista y es lo único que lee el servidor.
        var until = form.querySelector('[data-until]');
        if (until && to) {
            var custom = until.querySelector('[data-until-custom]');
            var kinds = Array.prototype.slice.call(until.querySelectorAll('input[name="hasta_tipo"]'));
            var applyUntil = function () {
                var chosen = kinds.filter(function (kind) {
                    return kind.checked;
                })[0];
                var date = chosen ? chosen.getAttribute('data-date') : null;
                if (custom) {
                    // Sin chip con fecha (p. ej. sin curso dado de alta) el campo se queda a la vista.
                    custom.hidden = !date;
                }
                if (date) {
                    to.value = date;
                }
                refresh();
            };
            kinds.forEach(function (kind) {
                kind.addEventListener('change', applyUntil);
            });
            applyUntil();
        }

        // El día entero: las casillas de su fila, sin las que tienen clase (esas no tienen casilla).
        form.querySelectorAll('[data-pick-day]').forEach(function (button) {
            button.hidden = false;
            button.addEventListener('click', function () {
                var rowBoxes = Array.prototype.slice.call(button.closest('tr').querySelectorAll('input[name="franjas[]"]'));
                var allOn = rowBoxes.length > 0 && rowBoxes.every(function (box) {
                    return box.checked;
                });
                rowBoxes.forEach(function (box) {
                    box.checked = !allOn;
                });
                button.textContent = allOn ? 'marcar el día' : 'quitar el día';
                refresh();
            });
        });

        boxes.forEach(function (box) {
            box.addEventListener('change', refresh);
        });
        [from, to].forEach(function (input) {
            if (input) {
                input.addEventListener('input', refresh);
                input.addEventListener('change', refresh);
            }
        });
        refresh();
    });
})();
