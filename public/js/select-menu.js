/* Desplegable propio para <select>: sustituye la lista nativa del sistema operativo (que el CSS
 * no puede tocar y en macOS oscuro sale oscura) por un listbox con la estética de "Registro".
 *
 * Mejora progresiva con red de seguridad: el <select> nativo SIGUE en el DOM como fuente de verdad
 * (solo oculto visualmente), así que el formulario se envía igual, los tests que fijan el valor por
 * nombre de campo siguen funcionando y, si este script falla o no carga, el control nativo queda
 * plenamente usable. Cada <select> se realza dentro de un try/catch: un fallo puntual no tumba la
 * página ni deja el campo roto (se revierte al nativo).
 *
 * Expone window.enhanceSelectMenus(root) — el gancho que table-tools.js ya invoca para que su
 * filtro de columnas herede el mismo estilo. Sin argumento, realza todo el documento.
 *
 * Patrón ARIA: botón (aria-haspopup="listbox") + <ul role="listbox"> con <li role="option">. El
 * foco vive en las opciones cuando está abierto; aria-selected marca la elegida. Teclado: flechas,
 * Inicio/Fin, Enter/Espacio para elegir, Esc para cerrar, y typeahead (teclear para saltar). */
(function () {
    'use strict';

    var counter = 0;

    /* Cierra cualquier desplegable abierto salvo el que se pase a conservar. */
    function closeOthers(keep) {
        document.querySelectorAll('.cselect.is-open').forEach(function (el) {
            if (el !== keep) {
                el.cselectClose();
            }
        });
    }

    function enhance(select) {
        // No realzamos selects nativos que no aportan (multiple, sin opciones) ni los ya realzados.
        if (select.multiple || select.dataset.cselectDone === '1' || select.options.length === 0) {
            return;
        }
        select.dataset.cselectDone = '1';

        var id = 'cselect-' + (++counter);
        var wrap = document.createElement('div');
        wrap.className = 'cselect';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'cselect__button';
        button.setAttribute('aria-haspopup', 'listbox');
        button.setAttribute('aria-expanded', 'false');
        if (select.disabled) {
            button.disabled = true;
        }
        // La validación de restricciones HTML5 del <select> nativo (required) anclaría su globo a un
        // elemento invisible de 1px. Se traslada al botón como aria-required (para lectores de pantalla)
        // y se quita del nativo: la obligatoriedad la valida el servidor (Assert\NotNull), como en el
        // campo de fecha realzado. Coherente entre ambos componentes.
        if (select.required) {
            button.setAttribute('aria-required', 'true');
            select.required = false;
        }
        // El botón hereda la etiqueta accesible del <select> (su <label> asociado por id).
        var labelledby = labelIdFor(select);
        if (labelledby) {
            button.setAttribute('aria-labelledby', labelledby + ' ' + id + '-value');
        }

        var valueSpan = document.createElement('span');
        valueSpan.className = 'cselect__value';
        valueSpan.id = id + '-value';
        button.appendChild(valueSpan);
        button.insertAdjacentHTML('beforeend',
            '<svg class="cselect__chevron" width="12" height="8" viewBox="0 0 12 8" fill="none" ' +
            'aria-hidden="true"><polyline points="1 1.5 6 6.5 11 1.5" stroke="currentColor" ' +
            'stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>');

        var list = document.createElement('ul');
        list.className = 'cselect__list';
        list.id = id + '-list';
        list.setAttribute('role', 'listbox');
        list.tabIndex = -1;
        list.hidden = true;
        if (labelledby) {
            list.setAttribute('aria-labelledby', labelledby);
        }

        var optionEls = [];

        function syncButtonText() {
            var selected = select.options[select.selectedIndex];
            valueSpan.textContent = selected ? selected.textContent : '';
        }

        // (Re)builds the listbox from the native <select>'s current options. Exposed as
        // select.cselectRefresh so callers that add/remove options at runtime (e.g. task-form.js
        // narrowing the person list) can keep this enhanced menu in sync — otherwise it would keep
        // showing the snapshot taken when it was first enhanced.
        function rebuild() {
            list.textContent = '';
            optionEls.length = 0;
            Array.prototype.forEach.call(select.options, function (opt, index) {
                var li = document.createElement('li');
                li.className = 'cselect__option';
                li.id = id + '-opt-' + index;
                li.setAttribute('role', 'option');
                li.dataset.value = opt.value;
                li.textContent = opt.textContent;
                li.setAttribute('aria-selected', opt.selected ? 'true' : 'false');
                list.appendChild(li);
                optionEls.push(li);
            });
            syncButtonText();
        }
        rebuild();
        select.cselectRefresh = rebuild;

        var activeIndex = select.selectedIndex < 0 ? 0 : select.selectedIndex;

        function setActive(index) {
            if (index < 0 || index >= optionEls.length) {
                return;
            }
            activeIndex = index;
            optionEls.forEach(function (li, i) { li.classList.toggle('is-active', i === index); });
            list.setAttribute('aria-activedescendant', optionEls[index].id);
            optionEls[index].scrollIntoView({ block: 'nearest' });
        }

        function open() {
            closeOthers(wrap);
            list.hidden = false;
            wrap.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
            setActive(select.selectedIndex < 0 ? 0 : select.selectedIndex);
            list.focus();
        }

        function close() {
            list.hidden = true;
            wrap.classList.remove('is-open');
            button.setAttribute('aria-expanded', 'false');
        }
        wrap.cselectClose = close;

        function choose(index) {
            select.selectedIndex = index;
            optionEls.forEach(function (li, i) { li.setAttribute('aria-selected', i === index ? 'true' : 'false'); });
            syncButtonText();
            // Notifica al <select> nativo como si el usuario hubiera elegido, para los listeners
            // que dependen de 'change' (p. ej. el filtro de table-tools).
            select.dispatchEvent(new Event('change', { bubbles: true }));
            close();
            button.focus();
        }

        button.addEventListener('click', function () {
            if (wrap.classList.contains('is-open')) {
                close();
            } else {
                open();
            }
        });

        list.addEventListener('click', function (e) {
            var li = e.target.closest('.cselect__option');
            if (li) {
                choose(optionEls.indexOf(li));
            }
        });

        var typeahead = '';
        var typeaheadTimer = null;
        list.addEventListener('keydown', function (e) {
            switch (e.key) {
                case 'ArrowDown': e.preventDefault(); setActive(Math.min(activeIndex + 1, optionEls.length - 1)); break;
                case 'ArrowUp': e.preventDefault(); setActive(Math.max(activeIndex - 1, 0)); break;
                case 'Home': e.preventDefault(); setActive(0); break;
                case 'End': e.preventDefault(); setActive(optionEls.length - 1); break;
                case 'Enter': case ' ': e.preventDefault(); choose(activeIndex); break;
                case 'Escape': e.preventDefault(); close(); button.focus(); break;
                case 'Tab': close(); break;
                default:
                    if (e.key.length === 1) {
                        typeahead += e.key.toLowerCase();
                        clearTimeout(typeaheadTimer);
                        typeaheadTimer = setTimeout(function () { typeahead = ''; }, 600);
                        for (var i = 0; i < optionEls.length; i++) {
                            if (optionEls[i].textContent.toLowerCase().indexOf(typeahead) === 0) {
                                setActive(i);
                                break;
                            }
                        }
                    }
            }
        });

        // Monta el UI y oculta el nativo SOLO ahora que todo se ha construido bien. El <select> se
        // saca del orden de tabulación y del árbol de accesibilidad: sin esto, al ir con Tab, el foco
        // caería en el select fantasma de 1px (opacity:0 NO lo excluye del foco) sin indicador visible.
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(button);
        wrap.appendChild(list);
        wrap.appendChild(select);
        select.classList.add('cselect__native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
    }

    /* El id del <label> que rotula al select (para heredar su nombre accesible), o null. */
    function labelIdFor(select) {
        if (select.id) {
            var label = document.querySelector('label[for="' + select.id + '"]');
            if (label) {
                if (!label.id) {
                    label.id = select.id + '-label';
                }
                return label.id;
            }
        }
        return null;
    }

    function enhanceSelectMenus(root) {
        (root || document).querySelectorAll('select').forEach(function (select) {
            try {
                enhance(select);
            } catch (err) {
                // Red de seguridad: si algo falla, se deja el <select> nativo intacto y usable.
                if (window.console) {
                    window.console.warn('cselect: no se pudo realzar un select', err);
                }
            }
        });
    }

    window.enhanceSelectMenus = enhanceSelectMenus;

    // Un ÚNICO listener delegado para "clic fuera" (no uno por cada select realzado): si el clic no
    // cae dentro de un .cselect, se cierran todos los abiertos. Evita acumular listeners en document
    // cada vez que se realza un select (p. ej. cuando table-tools reconstruye su filtro).
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.cselect')) {
            closeOthers(null);
        }
    });

    function init() { enhanceSelectMenus(document); }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
