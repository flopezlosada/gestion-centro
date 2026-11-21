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

        /** «Cosa · día» de una casilla, partido en la cosa y el día. */
        function partsOf(box) {
            var pieces = box.getAttribute('data-what').split(' · ');
            return { title: pieces[0], day: pieces.slice(1).join(' · ') };
        }

        function refresh() {
            if (!bar) {
                return;
            }
            var chosen = ticked();
            bar.hidden = 0 === chosen.length;
            bar.classList.add('is-floating');
            var clear = bar.querySelector('[data-booking-clear]');
            if (clear) {
                clear.hidden = 0 === chosen.length;
            }
            var submit = bar.querySelector('[data-booking-submit]');
            if (submit) {
                submit.textContent = chosen.length > 1 ? 'Reservar ' + chosen.length + ' horas' : 'Reservar';
            }
            if (!chosen.length) {
                return;
            }
            var parts = partsOf(chosen[0]);
            var hours = chosen.map(function (box) {
                return box.getAttribute('data-hour').replace(/\s*hora$/, '');
            });
            bar.querySelector('[data-booking-title]').textContent = parts.title;
            bar.querySelector('[data-booking-summary]').textContent = parts.day + ' · ' + hours.join(', ');
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
            var fields = {
                title: info.querySelector('[data-info-title]'),
                when: info.querySelector('[data-info-when]'),
                what: info.querySelector('[data-info-what]'),
                more: info.querySelector('[data-info-more]'),
                dot: info.querySelector('[data-info-dot]')
            };

            function closeInfo() {
                info.hidden = true;
                document.querySelectorAll('.wcell--on').forEach(function (on) {
                    on.classList.remove('wcell--on');
                });
            }

            document.addEventListener('click', function (event) {
                var cell = event.target.closest ? event.target.closest('[data-booking-info]') : null;
                if (!cell) {
                    return;
                }
                closeInfo();
                fields.title.textContent = cell.getAttribute('data-title');
                fields.when.textContent = cell.getAttribute('data-when');
                fields.what.textContent = cell.getAttribute('data-what');
                fields.more.textContent = cell.getAttribute('data-more');
                fields.dot.className = 'booking-info__dot booking-info__dot--' + cell.getAttribute('data-state');
                info.hidden = false;
                cell.classList.add('wcell--on');
            });
            info.querySelector('[data-info-close]').addEventListener('click', closeInfo);
            // Marcar un cuadro libre cierra el recuadro: lo que se está haciendo ya es otra cosa.
            boxes.forEach(function (box) {
                box.addEventListener('change', closeInfo);
            });
        }
    });
})();
