/* Navegación móvil: el botón hamburguesa abre un cajón lateral (off-canvas) desde la
 * izquierda, superpuesto sobre el contenido con un fondo oscurecido. En escritorio el
 * botón está oculto por CSS, así que esto no hace nada. JS mínimo, sin dependencias. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // Disparadores del cajón: la hamburguesa del topbar y el botón "Más" de la barra inferior.
        var triggers = document.querySelectorAll('.nav-toggle, [data-nav-toggle]');
        var sidebar = document.querySelector('.sidebar');
        if (!triggers.length || !sidebar) {
            return;
        }
        var backdrop = sidebar.querySelector('.nav-backdrop');

        function setOpen(open) {
            sidebar.classList.toggle('is-open', open);
            triggers.forEach(function (btn) {
                btn.setAttribute('aria-expanded', String(open));
                if (btn.classList.contains('nav-toggle')) {
                    btn.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
                }
            });
            // Evita que el fondo haga scroll mientras el cajón está abierto.
            document.body.style.overflow = open ? 'hidden' : '';
        }

        triggers.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setOpen(!sidebar.classList.contains('is-open'));
            });
        });

        if (backdrop) {
            backdrop.addEventListener('click', function () { setOpen(false); });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
                setOpen(false);
                triggers[0].focus();
            }
        });

        // Al navegar, cerramos el cajón.
        document.querySelectorAll('#main-nav a').forEach(function (a) {
            a.addEventListener('click', function () { setOpen(false); });
        });
    });
})();
