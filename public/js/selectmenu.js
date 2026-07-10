/* selectmenu — "enhancer" de <select> nativos (portado de csa-vega).
 *
 * El desplegable nativo del <select> lo pinta el navegador/SO y no se puede estilar
 * (en macOS con el SO en oscuro sale oscuro aunque la app esté en claro). Este enhancer
 * reemplaza VISUALMENTE el select por un menú propio con nuestra estética, manteniendo el
 * <select> real en el DOM, oculto pero funcional: el form de Symfony postea igual y el
 * evento 'change' sigue disparándose. Accesible (combobox/listbox + teclado).
 *
 * Se aplica a los <select> simples dentro de .form-row. Los múltiples se dejan nativos. */
(function () {
    'use strict';

    function enhance(select) {
        if (select.dataset.smEnhanced || select.multiple || select.size > 1) {
            return;
        }
        select.dataset.smEnhanced = '1';

        var wrap = document.createElement('div');
        wrap.className = 'selectmenu';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'selectmenu__trigger';
        trigger.setAttribute('role', 'combobox');
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        if (select.disabled) {
            trigger.disabled = true;
        }

        var value = document.createElement('span');
        value.className = 'selectmenu__value';
        trigger.appendChild(value);

        var menu = document.createElement('ul');
        menu.className = 'selectmenu__menu';
        menu.setAttribute('role', 'listbox');
        menu.hidden = true;

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(trigger);
        wrap.appendChild(menu);
        select.classList.add('selectmenu__native');

        var activeIndex = -1;

        function syncValueLabel() {
            var opt = select.options[select.selectedIndex];
            value.textContent = opt ? opt.textContent.trim() : '';
            value.classList.toggle('selectmenu__value--placeholder', !opt || '' === opt.value);
        }

        function buildMenu() {
            menu.innerHTML = '';
            Array.prototype.forEach.call(select.options, function (opt, i) {
                var li = document.createElement('li');
                li.className = 'selectmenu__option';
                li.setAttribute('role', 'option');
                li.textContent = opt.textContent.trim();
                li.setAttribute('aria-selected', i === select.selectedIndex ? 'true' : 'false');
                if (opt.disabled) {
                    li.classList.add('selectmenu__option--disabled');
                } else {
                    li.addEventListener('click', function () {
                        commit(i);
                        close();
                        trigger.focus();
                    });
                }
                menu.appendChild(li);
            });
        }

        function commit(i) {
            select.selectedIndex = i;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            syncValueLabel();
            markSelected();
        }

        function markSelected() {
            Array.prototype.forEach.call(menu.children, function (li, i) {
                li.setAttribute('aria-selected', i === select.selectedIndex ? 'true' : 'false');
            });
        }

        function setActive(i) {
            Array.prototype.forEach.call(menu.children, function (li) {
                li.classList.remove('selectmenu__option--active');
            });
            if (i >= 0 && menu.children[i]) {
                menu.children[i].classList.add('selectmenu__option--active');
                menu.children[i].scrollIntoView({ block: 'nearest' });
                activeIndex = i;
            }
        }

        function moveActive(delta) {
            var n = select.options.length;
            var i = activeIndex < 0 ? select.selectedIndex : activeIndex;
            for (var step = 0; step < n; step++) {
                i = (i + delta + n) % n;
                if (!select.options[i].disabled) {
                    break;
                }
            }
            setActive(i);
        }

        function onDocClick(e) {
            if (!wrap.contains(e.target)) {
                close();
            }
        }

        function open() {
            if (trigger.disabled) {
                return;
            }
            buildMenu();
            menu.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            wrap.classList.add('selectmenu--open');
            setActive(select.selectedIndex);
            document.addEventListener('click', onDocClick, true);
        }

        function close() {
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
            wrap.classList.remove('selectmenu--open');
            activeIndex = -1;
            document.removeEventListener('click', onDocClick, true);
        }

        trigger.addEventListener('click', function () {
            menu.hidden ? open() : close();
        });

        trigger.addEventListener('keydown', function (e) {
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    menu.hidden ? open() : moveActive(1);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    menu.hidden ? open() : moveActive(-1);
                    break;
                case 'Enter':
                case ' ':
                    e.preventDefault();
                    if (menu.hidden) {
                        open();
                    } else if (activeIndex >= 0) {
                        commit(activeIndex);
                        close();
                    }
                    break;
                case 'Escape':
                    if (!menu.hidden) {
                        e.preventDefault();
                        close();
                    }
                    break;
                case 'Tab':
                    close();
                    break;
            }
        });

        // El <select> cambió por código (p. ej. un reset): reflejarlo en el menú.
        select.addEventListener('change', function () {
            if (menu.hidden) {
                syncValueLabel();
                markSelected();
            }
        });

        syncValueLabel();
    }

    function init(root) {
        (root || document).querySelectorAll('.form-row select').forEach(enhance);
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

    // Por si una pantalla inyecta selects más tarde.
    window.enhanceSelectMenus = init;
})();
