/**
 * Rejilla de reservas (/reservas y /reservas/semana). Mejora progresiva: sin JavaScript las casillas de las
 * celdas libres ya se envían con el formulario «Vas a reservar», que está siempre a la vista. Con él:
 *  - marcar una celda de OTRA cosa u otro día desmarca las demás (una reserva es una cosa, un día, varias
 *    horas: el servidor lo exige igual, esto solo evita el aviso);
 *  - la barra se esconde hasta que hay algo marcado y dice qué se ha marcado;
 *  - un cuadro ocupado de la semana enseña qué es y de quién encima de la rejilla.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    /** Cosa y día de una celda: su valor sin la hora final (tipo:id:fecha). */
    function groupOf(box) {
        return box.value.slice(0, box.value.lastIndexOf(':'));
    }

    ready(function () {
        var bar = document.querySelector('[data-booking-bar]');
        var boxes = Array.prototype.slice.call(document.querySelectorAll('input[name="celdas[]"]'));
        var info = document.querySelector('[data-booking-info-box]');

        function ticked() {
            return boxes.filter(function (box) {
                return box.checked;
            });
        }

        function refresh() {
            if (!bar) {
                return;
            }
            var chosen = ticked();
            bar.hidden = 0 === chosen.length;
            var clear = bar.querySelector('[data-booking-clear]');
            if (clear) {
                clear.hidden = 0 === chosen.length;
            }
            var summary = bar.querySelector('[data-booking-summary]');
            if (summary && chosen.length) {
                var hours = chosen.map(function (box) {
                    return box.getAttribute('data-hour');
                }).filter(Boolean);
                summary.textContent = chosen[0].getAttribute('data-what')
                    + (hours.length ? ': ' + hours.join(', ') : ' (' + chosen.length + (1 === chosen.length ? ' hora' : ' horas') + ')');
            }
        }

        boxes.forEach(function (box) {
            box.addEventListener('change', function () {
                if (box.checked) {
                    boxes.forEach(function (other) {
                        if (other !== box && other.checked && groupOf(other) !== groupOf(box)) {
                            other.checked = false;
                        }
                    });
                }
                refresh();
            });
        });

        if (bar) {
            var clear = bar.querySelector('[data-booking-clear]');
            if (clear) {
                clear.addEventListener('click', function () {
                    boxes.forEach(function (box) {
                        box.checked = false;
                    });
                    refresh();
                });
            }
            refresh();
        }

        if (info) {
            document.addEventListener('click', function (event) {
                var cell = event.target.closest ? event.target.closest('[data-booking-info]') : null;
                if (!cell) {
                    return;
                }
                info.textContent = cell.getAttribute('data-booking-info');
                info.hidden = false;
                document.querySelectorAll('.wcell--on').forEach(function (on) {
                    on.classList.remove('wcell--on');
                });
                cell.classList.add('wcell--on');
            });
        }
    });
})();
