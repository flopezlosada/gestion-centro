/* Pilares plegables del menú lateral. Para cada <div data-nav-pillar> con su <button class="nav-label">
 * y su <div class="nav-group">, colapsa todos salvo el del módulo actual, de modo que quien ve muchas
 * áreas no se encuentre una pared de enlaces. El estado abierto/cerrado se recuerda entre páginas en
 * localStorage (por el id del grupo).
 *
 * Progressive enhancement: sin JS no se añade nunca .is-collapsed, así que todos los pilares quedan
 * abiertos y el menú funciona igual. */
(function () {
    'use strict';

    var STORAGE_KEY = 'sga.navOpenPillars';

    /* Conjunto de ids de grupo abiertos, persistido. Si localStorage no está disponible
     * (modo privado, etc.) se degrada a un estado solo en memoria. */
    function loadOpen() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function saveOpen(ids) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
        } catch (e) {
            /* sin persistencia: el plegado sigue funcionando en la sesión actual */
        }
    }

    function init() {
        var pillars = Array.prototype.slice.call(document.querySelectorAll('[data-nav-pillar]'));
        if (!pillars.length) {
            return;
        }

        var stored = loadOpen();
        var open = stored ? Object.create(null) : null;
        if (stored) {
            stored.forEach(function (id) { open[id] = true; });
        }

        pillars.forEach(function (pillar) {
            var button = pillar.querySelector('.nav-label');
            var group = pillar.querySelector('.nav-group');
            if (!button || !group) {
                return;
            }
            var id = button.getAttribute('aria-controls');
            var hasActive = !!group.querySelector('a.is-active');
            // Primera visita (sin estado guardado): se abre solo el pilar activo. Con estado: se respeta,
            // pero el pilar activo siempre se abre para que la página actual quede a la vista.
            var shouldOpen = hasActive || (open ? !!open[id] : false);

            setExpanded(pillar, button, shouldOpen);

            button.addEventListener('click', function () {
                setExpanded(pillar, button, pillar.classList.contains('is-collapsed'));
                persist(pillars);
            });
        });

        // Asegura que el pilar activo quede registrado como abierto desde el primer render.
        persist(pillars);
    }

    function setExpanded(pillar, button, expanded) {
        pillar.classList.toggle('is-collapsed', !expanded);
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    /* Relee el estado actual del DOM y lo guarda, para no depender de un objeto paralelo. */
    function persist(pillars) {
        var ids = [];
        pillars.forEach(function (pillar) {
            if (!pillar.classList.contains('is-collapsed')) {
                var button = pillar.querySelector('.nav-label');
                if (button) {
                    ids.push(button.getAttribute('aria-controls'));
                }
            }
        });
        saveOpen(ids);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
