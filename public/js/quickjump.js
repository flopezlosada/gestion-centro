/* Quick-jump — salto rápido a módulos desde la topbar.
 *
 * El servidor ya pinta la lista de módulos como <a href> reales y filtrados por permisos
 * (templates/app_shell.html.twig, vía nav_pillars()). Este script solo añade el comportamiento
 * de cliente: abrir el panel al enfocar, filtrar la lista al teclear (sin distinguir acentos ni
 * mayúsculas) y navegar con teclado. Cero red, cero backend.
 *
 * Como los resultados son enlaces de verdad, Ctrl/⌘+clic abre en pestaña nueva sin código extra
 * (útil para consultar un registro sin perder el formulario que se está rellenando). JS mínimo,
 * sin dependencias, mismo patrón que el resto de public/js. */
(function () {
    'use strict';

    // "Consumos" debe casar al teclear "consumo", "Residuos" con "residuos", etc.: quitamos
    // los diacríticos y pasamos a minúsculas para que la búsqueda sea tolerante en español.
    function normalize(text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }

    function setup(root) {
        var input = root.querySelector('.quickjump__input');
        var panel = root.querySelector('.quickjump__panel');
        var list = root.querySelector('.quickjump__list');
        var empty = root.querySelector('.quickjump__empty');
        if (!input || !panel || !list) {
            return;
        }

        // Cada opción: el <a role="option"> y su texto normalizado (nombre + pista) para filtrar.
        // Asignamos un id a cada enlace para poder referenciarlo desde aria-activedescendant.
        // Indexamos nombre y pista con un espacio entre medias para no casar a través del límite
        // entre ambos (p. ej. evitar que "soshac" case "Consumos" + "Hacer").
        var options = Array.prototype.map.call(list.querySelectorAll('.quickjump__item'), function (link, i) {
            if (!link.id) {
                link.id = 'quickjump-opt-' + i;
            }
            var name = link.querySelector('.quickjump__name');
            var hint = link.querySelector('.quickjump__hint');
            var label = (name ? name.textContent : link.textContent) + ' ' + (hint ? hint.textContent : '');
            return { link: link, text: normalize(label) };
        });
        var visible = options.slice();
        var activeIndex = -1;

        function setActive(i) {
            if (activeIndex >= 0 && visible[activeIndex]) {
                visible[activeIndex].link.classList.remove('is-active');
                visible[activeIndex].link.removeAttribute('aria-selected');
            }
            activeIndex = i;
            if (i >= 0 && visible[i]) {
                visible[i].link.classList.add('is-active');
                visible[i].link.setAttribute('aria-selected', 'true');
                visible[i].link.scrollIntoView({ block: 'nearest' });
                input.setAttribute('aria-activedescendant', visible[i].link.id || '');
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function open() {
            if (panel.hidden) {
                panel.hidden = false;
                input.setAttribute('aria-expanded', 'true');
                document.addEventListener('click', onDocClick, true);
            }
        }

        function close() {
            if (!panel.hidden) {
                panel.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                setActive(-1);
                document.removeEventListener('click', onDocClick, true);
            }
        }

        function onDocClick(e) {
            if (!root.contains(e.target)) {
                close();
            }
        }

        function filter() {
            var q = normalize(input.value);
            visible = [];
            options.forEach(function (opt) {
                var match = '' === q || opt.text.indexOf(q) !== -1;
                opt.link.hidden = !match;
                if (match) {
                    visible.push(opt);
                }
            });
            if (empty) {
                empty.hidden = visible.length > 0;
            }
            // Tras filtrar, la opción activa de antes ya no vale: preseleccionamos la primera
            // visible para que un Enter directo navegue al resultado más probable.
            activeIndex = -1;
            setActive(visible.length > 0 ? 0 : -1);
        }

        function move(delta) {
            if (0 === visible.length) {
                return;
            }
            // Sin opción activa, ArrowDown va a la primera y ArrowUp a la última (envolvente).
            var start = activeIndex < 0 ? (delta > 0 ? -1 : 0) : activeIndex;
            var i = (start + delta + visible.length) % visible.length;
            setActive(i);
        }

        input.addEventListener('focus', open);

        input.addEventListener('input', function () {
            open();
            filter();
        });

        input.addEventListener('keydown', function (e) {
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    open();
                    move(1);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    open();
                    move(-1);
                    break;
                case 'Enter':
                    // Navegación normal (misma pestaña) al resultado activo. Ctrl/⌘+clic con ratón
                    // sigue abriendo pestaña nueva porque son <a href> reales; aquí no lo forzamos.
                    if (activeIndex >= 0 && visible[activeIndex]) {
                        e.preventDefault();
                        visible[activeIndex].link.click();
                    }
                    break;
                case 'Escape':
                    if (!panel.hidden) {
                        e.preventDefault();
                        input.value = '';
                        filter();
                        close();
                    }
                    break;
                case 'Tab':
                    // Salir del buscador cierra el panel (consistente con selectmenu.js).
                    close();
                    break;
            }
        });
    }

    function init() {
        document.querySelectorAll('[data-quickjump]').forEach(setup);
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
