/* Campo de texto con su lista a la vista (input[data-combobox] con un <datalist>): el tema de una clase.
 * Se elige de la lista de temas de la materia y el nivel, o se escribe uno nuevo sin más —al guardar se
 * añade a la lista—, sin botón de «añadir».
 *
 * El <datalist> nativo no sirve para esto: en Chrome solo se abre al escribir o con un indicador diminuto,
 * en Firefox no tiene flecha y en el iPhone sale encima del teclado, así que la lista de temas no se veía.
 * Este script lo sustituye por una lista propia, numerada en el orden del temario, que se abre al entrar
 * en el campo, se filtra al escribir (sin mayúsculas ni tildes) y avisa cuando lo escrito será un tema nuevo.
 *
 * Mejora progresiva: el <input> sigue siendo el campo real que se envía. Sin JS queda el datalist nativo.
 *
 * Patrón ARIA 1.2 de combobox con lista: el foco se queda en el campo, aria-activedescendant señala la
 * opción activa. Teclado: flechas para moverse (abren la lista), Enter elige la activa, Escape cierra. */
(function () {
    'use strict';

    var counter = 0;

    /* Minúsculas y sin tildes, para que «romantico» encuentre «Romántico». */
    function fold(text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }

    /* El texto con la parte buscada resaltada, construido con nodos (nunca HTML: el nombre lo escribe el profesorado). */
    function highlighted(name, query) {
        var span = document.createElement('span');
        span.className = 'ccombo__name';
        var at = query === '' ? -1 : fold(name).indexOf(query);
        if (at < 0) {
            span.textContent = name;
            return span;
        }
        // fold() no cambia la longitud de las letras españolas (quita el acento combinante tras NFD, que
        // para á/é/í/ó/ú/ü/ñ precompuestas vuelve a una sola letra), así que las posiciones valen en el original.
        var mark = document.createElement('mark');
        mark.textContent = name.slice(at, at + query.length);
        span.append(name.slice(0, at), mark, name.slice(at + query.length));
        return span;
    }

    function enhance(input) {
        var datalist = input.list;
        if (!datalist || input.dataset.comboboxDone === '1') {
            return;
        }
        input.dataset.comboboxDone = '1';
        var names = Array.prototype.map.call(datalist.options, function (o) { return o.value; });
        var id = 'ccombo-' + (++counter);

        var wrap = document.createElement('div');
        wrap.className = 'ccombo';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        // Sin «list», el navegador ya no pinta su flecha ni abre su propia lista encima de la nuestra.
        input.removeAttribute('list');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-haspopup', 'listbox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', id);

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'ccombo__toggle';
        toggle.tabIndex = -1;
        toggle.setAttribute('aria-label', 'Ver los temas');
        toggle.innerHTML = '<svg width="12" height="8" viewBox="0 0 12 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 1.5 6 6.5 11 1.5"/></svg>';
        wrap.appendChild(toggle);

        var list = document.createElement('ul');
        list.className = 'ccombo__list';
        list.id = id;
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        wrap.appendChild(list);

        var active = -1;

        function options() {
            return list.querySelectorAll('[role="option"]');
        }

        /* Desplaza la lista (no la página) hasta dejar a la vista una opción. */
        function reveal(li) {
            if (li.offsetTop < list.scrollTop) {
                list.scrollTop = li.offsetTop - 4;
            } else if (li.offsetTop + li.offsetHeight > list.scrollTop + list.clientHeight) {
                list.scrollTop = li.offsetTop + li.offsetHeight - list.clientHeight + 4;
            }
        }

        function setActive(index) {
            var all = options();
            if (all.length === 0) {
                active = -1;
                input.removeAttribute('aria-activedescendant');
                return;
            }
            active = (index + all.length) % all.length;
            all.forEach(function (li, i) { li.classList.toggle('is-active', i === active); });
            input.setAttribute('aria-activedescendant', all[active].id);
            reveal(all[active]);
        }

        /* Pinta la lista. Con showAll (al abrirla sin haber escrito, o con la flecha) salen todos los
           temas aunque el campo traiga uno: es lo que hay que poder ver para cambiarlo. */
        function render(showAll) {
            var typed = input.value.trim();
            var query = fold(typed);
            var exact = names.some(function (n) { return fold(n) === query; });
            var filter = showAll || exact ? '' : query;
            list.replaceChildren();
            active = -1;
            input.removeAttribute('aria-activedescendant');

            names.forEach(function (name, i) {
                if (filter !== '' && fold(name).indexOf(filter) < 0) {
                    return;
                }
                var li = document.createElement('li');
                li.id = id + '-' + i;
                li.className = 'ccombo__option';
                li.setAttribute('role', 'option');
                li.dataset.value = name;
                li.setAttribute('aria-selected', fold(name) === query ? 'true' : 'false');
                var num = document.createElement('span');
                num.className = 'ccombo__num';
                num.textContent = String(i + 1);
                li.append(num, highlighted(name, filter));
                list.appendChild(li);
            });

            if (typed !== '' && !exact) {
                var hint = document.createElement('li');
                hint.className = 'ccombo__hint';
                hint.setAttribute('role', 'presentation');
                hint.append('Al guardar, «', typed, '» se añade como tema nuevo.');
                list.appendChild(hint);
            } else if (names.length === 0) {
                var empty = document.createElement('li');
                empty.className = 'ccombo__hint';
                empty.setAttribute('role', 'presentation');
                empty.textContent = 'Aún no hay temas de esta materia y curso: escribe el primero.';
                list.appendChild(empty);
            }

            var selected = list.querySelector('[aria-selected="true"]');
            if (selected) {
                setActive(Array.prototype.indexOf.call(options(), selected));
            }
        }

        function open(showAll) {
            list.hidden = false;
            wrap.classList.add('is-open');
            input.setAttribute('aria-expanded', 'true');
            // Pintada ya visible: con la lista oculta no hay medidas y no se puede bajar hasta el tema elegido.
            render(showAll);
        }

        function close() {
            list.hidden = true;
            wrap.classList.remove('is-open');
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        // Al elegir se avisa a quien escuche el campo, pero ese aviso no debe reabrir la lista.
        var choosing = false;

        function choose(li) {
            input.value = li.dataset.value;
            choosing = true;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            choosing = false;
            close();
        }

        input.addEventListener('focus', function () { open(true); });
        input.addEventListener('click', function () {
            if (list.hidden) {
                open(true);
            }
        });
        input.addEventListener('input', function () {
            if (!choosing) {
                open(false);
            }
        });
        input.addEventListener('blur', close);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (list.hidden) {
                    open(true);
                }
                setActive(active + (e.key === 'ArrowDown' ? 1 : -1));
            } else if (e.key === 'Enter' && !list.hidden && active >= 0) {
                // Enter elige la opción activa solo si cambia el tema: si ya es el escrito (el campo viene
                // relleno y la lista lo marca), Enter es guardar, como en cualquier campo del formulario.
                var li = options()[active];
                if (li.dataset.value !== input.value) {
                    e.preventDefault();
                    choose(li);
                } else {
                    close();
                }
            } else if (e.key === 'Escape' && !list.hidden) {
                e.preventDefault();
                close();
            }
        });

        // pointerdown y no click: si no, el campo pierde el foco (y la lista se cierra) antes del click.
        toggle.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            if (list.hidden) {
                input.focus();
                open(true);
            } else {
                close();
            }
        });
        list.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            var li = e.target.closest('[role="option"]');
            if (li) {
                choose(li);
            }
        });
    }

    document.querySelectorAll('input[data-combobox]').forEach(function (input) {
        try {
            enhance(input);
        } catch (err) {
            // Un fallo aquí deja el campo con su datalist nativo, usable.
        }
    });
})();
