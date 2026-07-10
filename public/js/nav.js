/* Navegación móvil: el botón hamburguesa abre un cajón lateral (off-canvas) desde la
 * izquierda, superpuesto sobre el contenido con un fondo oscurecido. En escritorio el
 * botón está oculto por CSS, así que esto no hace nada. JS mínimo, sin dependencias. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.querySelector('.nav-toggle');
        var sidebar = document.querySelector('.sidebar');
        if (!btn || !sidebar) {
            return;
        }
        var backdrop = sidebar.querySelector('.nav-backdrop');

        function setOpen(open) {
            sidebar.classList.toggle('is-open', open);
            btn.setAttribute('aria-expanded', String(open));
            btn.setAttribute('aria-label', open ? 'Cerrar menú' : 'Abrir menú');
            // Evita que el fondo haga scroll mientras el cajón está abierto.
            document.body.style.overflow = open ? 'hidden' : '';
        }

        btn.addEventListener('click', function () {
            setOpen(!sidebar.classList.contains('is-open'));
        });

        if (backdrop) {
            backdrop.addEventListener('click', function () { setOpen(false); });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) {
                setOpen(false);
                btn.focus();
            }
        });

        // Al navegar, cerramos el cajón.
        document.querySelectorAll('#main-nav a').forEach(function (a) {
            a.addEventListener('click', function () { setOpen(false); });
        });
    });
})();
