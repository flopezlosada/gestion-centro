/* Pestañas accesibles (patrón ARIA tablist) con mejora progresiva. Realza cualquier contenedor
 * [data-tabs] que lleve dentro botones role="tab" (con aria-controls al id de su panel) y paneles
 * role="tabpanel". Sin JS todos los paneles quedan visibles y los botones no estorban; con JS se
 * muestra solo el panel activo. Teclado: flechas izquierda/derecha entre pestañas.
 *
 * Al mostrar un panel dispara un evento window 'resize': los gráficos SVG que se renderizaron dentro
 * de un panel oculto midieron 0px de ancho, y ese resize hace que recalculen su tamaño al aparecer. */
(function () {
    'use strict';

    function enhance(container) {
        var tabs = Array.prototype.slice.call(container.querySelectorAll('[role="tab"]'));
        var panels = Array.prototype.slice.call(container.querySelectorAll('[role="tabpanel"]'));
        if (tabs.length === 0) {
            return;
        }

        function activate(tab, focus) {
            tabs.forEach(function (t) {
                var selected = t === tab;
                t.setAttribute('aria-selected', String(selected));
                t.classList.toggle('is-active', selected);
                t.tabIndex = selected ? 0 : -1;
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.id !== tab.getAttribute('aria-controls');
            });
            if (focus) {
                tab.focus();
            }
            // Avisa del panel que acaba de mostrarse: quien monte gráficos dentro puede hacerlo AHORA,
            // con el contenedor visible y su ancho real (un SVG creado en un panel oculto sale a 0px).
            var shown = document.getElementById(tab.getAttribute('aria-controls'));
            if (shown) {
                shown.dispatchEvent(new CustomEvent('tab:shown', { bubbles: true, detail: { panel: shown } }));
            }
            // Y por si algún gráfico ya montado necesita recalcular su ancho al reaparecer.
            window.dispatchEvent(new Event('resize'));
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () {
                activate(tab);
            });
            tab.addEventListener('keydown', function (event) {
                var dir = 'ArrowRight' === event.key ? 1 : ('ArrowLeft' === event.key ? -1 : 0);
                if (0 === dir) {
                    return;
                }
                event.preventDefault();
                activate(tabs[(index + dir + tabs.length) % tabs.length], true);
            });
        });

        // Solo ahora ocultamos los paneles inactivos: hasta aquí el HTML sin realzar mostraba todos.
        container.classList.add('is-enhanced');
        activate(container.querySelector('[role="tab"][aria-selected="true"]') || tabs[0]);
    }

    function init() {
        document.querySelectorAll('[data-tabs]').forEach(enhance);
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
