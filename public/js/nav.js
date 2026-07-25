/* Navegación móvil: el botón "Más" de la barra inferior abre un cajón lateral (off-canvas) desde
 * la izquierda, superpuesto sobre el contenido con un fondo oscurecido. En escritorio la sidebar
 * es permanente y no hay disparador, así que esto no hace nada. JS mínimo, sin dependencias. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var triggers = document.querySelectorAll('[data-nav-toggle]');
        var sidebar = document.querySelector('.sidebar');
        if (!triggers.length || !sidebar) {
            return;
        }
        var backdrop = sidebar.querySelector('.nav-backdrop');
        // Al cerrar con Escape hay que devolver el foco al botón que abrió el cajón, no a uno
        // cualquiera: si algún día hay más de un disparador, seguirá siendo el correcto.
        var opener = null;

        function setOpen(open) {
            sidebar.classList.toggle('is-open', open);
            triggers.forEach(function (btn) {
                btn.setAttribute('aria-expanded', String(open));
            });
            // Evita que el fondo haga scroll mientras el cajón está abierto.
            document.body.style.overflow = open ? 'hidden' : '';
        }

        triggers.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var open = !sidebar.classList.contains('is-open');
                opener = open ? btn : null;
                setOpen(open);
            });
        });

        if (backdrop) {
            backdrop.addEventListener('click', function () { setOpen(false); });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
                setOpen(false);
                if (opener) {
                    opener.focus();
                    opener = null;
                }
            }
        });

        // Al navegar, cerramos el cajón.
        document.querySelectorAll('#main-nav a').forEach(function (a) {
            a.addEventListener('click', function () { setOpen(false); });
        });
    });
})();
