/**
 * "Añadir en este día": cada celda del calendario lleva un "+" que abre un mini-menú (Nueva tarea /
 * Nuevo evento) enlazando a los formularios de alta con el día prerrellenado (?fecha=YYYY-MM-DD).
 *
 * El menú es un <details> nativo, así que funciona con JS desactivado (posición absoluta de reserva
 * en CSS). Este script solo lo mejora: reposiciona el menú abierto como position:fixed anclado al
 * botón, de modo que el `overflow:hidden` de .calendar no pueda recortarlo; mantiene un único menú
 * abierto a la vez; y lo cierra al pinchar fuera o con Escape. Autónomo: no hace nada si no hay
 * celdas de calendario.
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

    ready(function () {
        var items = document.querySelectorAll('.day-add');
        if (!items.length) {
            return;
        }

        function closeAll(except) {
            items.forEach(function (d) {
                if (d !== except) {
                    d.removeAttribute('open');
                }
            });
        }

        // Ancla el menú al botón como position:fixed, alineado a su derecha y volcado hacia arriba si
        // no cabe por abajo; siempre dentro del viewport. Así escapa del overflow:hidden del calendario.
        function place(details) {
            var btn = details.querySelector('.day-add__btn');
            var menu = details.querySelector('.day-add__menu');
            if (!btn || !menu) {
                return;
            }
            var rect = btn.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.right = 'auto';
            var width = menu.offsetWidth;
            var left = Math.min(rect.right - width, window.innerWidth - width - 8);
            menu.style.left = Math.max(8, left) + 'px';
            var top = rect.bottom + 4;
            if (top + menu.offsetHeight > window.innerHeight - 8) {
                top = Math.max(8, rect.top - 4 - menu.offsetHeight);
            }
            menu.style.top = top + 'px';
        }

        function reset(menu) {
            menu.style.position = '';
            menu.style.top = '';
            menu.style.left = '';
            menu.style.right = '';
        }

        items.forEach(function (details) {
            details.addEventListener('toggle', function () {
                var menu = details.querySelector('.day-add__menu');
                if (details.open) {
                    closeAll(details);
                    place(details);
                } else if (menu) {
                    reset(menu);
                }
            });
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.day-add')) {
                closeAll(null);
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            // Devuelve el foco al botón que abrió el menú (si hay alguno abierto) antes de cerrarlo,
            // para que el usuario de teclado no pierda su posición en la página.
            var open = document.querySelector('.day-add[open]');
            if (open) {
                var btn = open.querySelector('.day-add__btn');
                if (btn) {
                    btn.focus();
                }
            }
            closeAll(null);
        });
    });
})();
