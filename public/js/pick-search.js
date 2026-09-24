/* Buscador para las listas largas de casillas de personas (.pick-list[data-pick-search]): convocados de
 * una reunión, miembros de un grupo de convocatoria o de un proyecto. Con ochenta docentes, la lista
 * entera de tarjetas obligaba a recorrerla para encontrar a tres.
 *
 * Mejora progresiva: las casillas siguen siendo el control real que se envía. El script solo deja a la
 * vista las marcadas y pone encima un buscador que ofrece las demás; elegir una la marca y la muestra,
 * desmarcarla la vuelve a esconder (y a ofrecer en el buscador). Sin JS se ve la lista entera, como antes.
 *
 * La búsqueda ignora mayúsculas y tildes y mira el nombre y el departamento, que es la descripción de
 * cada tarjeta. Enter añade la primera coincidencia; Escape vacía el buscador. */
(function () {
    'use strict';

    var MAX_RESULTS = 8;

    /* Minúsculas y sin tildes, para que «maria» encuentre a «María». */
    function fold(text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    function enhance(list) {
        var options = Array.prototype.slice.call(list.querySelectorAll('.pick-option'));
        if (options.length === 0) {
            return;
        }
        var entries = options.map(function (option) {
            var name = option.querySelector('.pick-option__name');
            var desc = option.querySelector('.pick-option__desc');
            return {
                option: option,
                box: option.querySelector('.pick-option__input'),
                name: name ? name.textContent.trim() : '',
                desc: desc ? desc.textContent.trim() : '',
                haystack: fold(option.textContent)
            };
        });

        var search = document.createElement('div');
        search.className = 'pick-search';
        var input = document.createElement('input');
        input.type = 'search';
        input.className = 'pick-search__input';
        input.autocomplete = 'off';
        input.placeholder = list.dataset.pickSearch || 'Busca por nombre o departamento para añadir…';
        input.setAttribute('aria-label', input.placeholder);
        var results = document.createElement('ul');
        results.className = 'pick-search__results';
        results.hidden = true;
        search.appendChild(input);
        search.appendChild(results);
        list.parentNode.insertBefore(search, list);

        var empty = document.createElement('p');
        empty.className = 'muted pick-search__empty';
        empty.textContent = 'Nadie todavía: búscalo arriba para añadirlo.';
        list.parentNode.insertBefore(empty, list.nextSibling);

        function sync() {
            var chosen = 0;
            entries.forEach(function (entry) {
                entry.option.hidden = !entry.box.checked;
                chosen += entry.box.checked ? 1 : 0;
            });
            empty.hidden = chosen > 0;
        }

        function add(entry) {
            entry.box.checked = true;
            entry.box.dispatchEvent(new Event('change', { bubbles: true }));
            input.value = '';
            render();
            input.focus();
        }

        function matches() {
            var q = fold(input.value.trim());
            if (q === '') {
                return [];
            }
            return entries.filter(function (entry) {
                return !entry.box.checked && entry.haystack.indexOf(q) !== -1;
            }).slice(0, MAX_RESULTS);
        }

        function render() {
            var found = matches();
            results.innerHTML = '';
            results.hidden = input.value.trim() === '';
            if (found.length === 0) {
                var none = document.createElement('li');
                none.className = 'pick-search__none';
                none.textContent = 'No hay nadie más con ese nombre.';
                results.appendChild(none);
                return;
            }
            found.forEach(function (entry) {
                var li = document.createElement('li');
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'pick-search__result';
                var name = document.createElement('span');
                name.className = 'pick-option__name';
                name.textContent = entry.name;
                button.appendChild(name);
                if (entry.desc) {
                    var desc = document.createElement('span');
                    desc.className = 'pick-option__desc';
                    desc.textContent = entry.desc;
                    button.appendChild(desc);
                }
                button.addEventListener('click', function () { add(entry); });
                li.appendChild(button);
                results.appendChild(li);
            });
        }

        input.addEventListener('input', render);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                // Sin esto, Enter en el buscador enviaría el formulario entero.
                e.preventDefault();
                var found = matches();
                if (found.length > 0) {
                    add(found[0]);
                }
            } else if (e.key === 'Escape' && input.value !== '') {
                e.preventDefault();
                input.value = '';
                render();
            }
        });
        list.addEventListener('change', sync);
        sync();
    }

    function init() {
        document.querySelectorAll('.pick-list[data-pick-search]').forEach(function (list) {
            try {
                enhance(list);
            } catch (err) {
                // Un fallo aquí deja la lista entera, que sigue funcionando: mejor que un campo roto.
                if (window.console) { console.error(err); }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
