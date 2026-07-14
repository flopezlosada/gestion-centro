/* Herramientas de listado sin backend: para cada <table data-list-tools> dentro de .card
 * añade, en el cliente y sobre las filas ya renderizadas:
 *   - un cajón de búsqueda (filtra filas por texto),
 *   - ordenación al pulsar la cabecera de columna (asc/desc, con tipo numérico/fecha/texto),
 *   - desplegables de filtro para las columnas cuya cabecera lleve data-filter.
 *
 * Progressive enhancement: sin JS la tabla se ve completa y normal. No toca el servidor,
 * así que el volumen debe caber en la página (correcto para un SGA de centro).
 *
 * Convenciones de marcado:
 *   - Una columna NO se ordena si su cabecera está vacía (caso de la columna de acciones)
 *     o lleva data-nosort.
 *   - data-sort en una celda fija su clave de orden cuando el texto visible no ordena bien
 *     (p. ej. "Enero" → nº de mes). DEBE ser un valor NUMÉRICO comparable (entero/flotante),
 *     no texto libre: la detección de tipo de columna lo trata como número. */
(function () {
    'use strict';

    var COLLATOR = new Intl.Collator('es', { numeric: true, sensitivity: 'base' });
    var PLACEHOLDERS = ['', '—', '-'];

    function cellText(row, index) {
        var cell = row.children[index];
        return cell ? cell.textContent.trim().replace(/\s+/g, ' ') : '';
    }

    /* Texto de una cabecera para etiquetas (filtro/aria), excluyendo el botón de ayuda "?" que
     * pueda llevar al lado: sin esto el filtro mostraría "Tipo ?: todos". */
    function headerLabel(th) {
        var clone = th.cloneNode(true);
        var help = clone.querySelector('.help-btn');
        if (help) {
            help.remove();
        }
        return clone.textContent.trim().replace(/\s+/g, ' ');
    }

    /* Valor para ORDENAR: respeta un data-sort explícito en la celda (p. ej. el número de
     * mes para una columna que muestra "Enero"); si no, usa el texto visible. */
    function sortText(row, index) {
        var cell = row.children[index];
        if (cell && cell.dataset.sort !== undefined) {
            return cell.dataset.sort.trim();
        }
        return cellText(row, index);
    }

    /* dd/mm/aaaa -> número comparable (aaaammdd), o null si no es una fecha. */
    function asDate(text) {
        var m = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        return m ? Number(m[3] + m[2].padStart(2, '0') + m[1].padStart(2, '0')) : null;
    }

    /* Extrae el primer número de un texto ("1.234,5 kg", "12 €"...) en formato es-ES, o null. */
    function asNumber(text) {
        var m = text.match(/-?\d[\d.]*(?:,\d+)?/);
        if (!m) {
            return null;
        }
        var n = parseFloat(m[0].replace(/\./g, '').replace(',', '.'));
        return isNaN(n) ? null : n;
    }

    /* Detecta el tipo de una columna muestreando sus celdas con valor. */
    function columnType(rows, index) {
        var allDate = true, allNumber = true, seen = false;
        for (var i = 0; i < rows.length; i++) {
            var text = sortText(rows[i], index);
            if (PLACEHOLDERS.indexOf(text) !== -1) {
                continue;
            }
            seen = true;
            if (asDate(text) === null) { allDate = false; }
            if (asNumber(text) === null) { allNumber = false; }
            if (!allDate && !allNumber) { break; }
        }
        if (!seen) { return 'text'; }
        if (allDate) { return 'date'; }
        if (allNumber) { return 'number'; }
        return 'text';
    }

    function sortKey(text, type) {
        if (PLACEHOLDERS.indexOf(text) !== -1) {
            return null; // los huecos van siempre al final
        }
        if (type === 'date') { return asDate(text); }
        if (type === 'number') { return asNumber(text); }
        return text;
    }

    function compare(a, b, type, dir) {
        if (a === null && b === null) { return 0; }
        if (a === null) { return 1; }  // huecos al final, sea cual sea la dirección
        if (b === null) { return -1; }
        var res = type === 'text' ? COLLATOR.compare(a, b) : (a - b);
        return dir === 'desc' ? -res : res;
    }

    function build(table) {
        var thead = table.tHead;
        var tbody = table.tBodies[0];
        if (!thead || !tbody) {
            return;
        }
        var headers = Array.prototype.slice.call(thead.rows[0].cells);
        var rows = Array.prototype.slice.call(tbody.rows);
        if (rows.length < 2) {
            return; // con 0-1 filas no hay nada que ordenar/filtrar/buscar
        }
        // Orden original, para mantener estabilidad en empates y poder "des-ordenar".
        rows.forEach(function (row, i) { row.dataset.ttOrder = i; });

        var toolbar = document.createElement('div');
        toolbar.className = 'table-tools';

        // ---- Cajón de búsqueda ----
        var search = document.createElement('div');
        search.className = 'table-tools__search';
        var input = document.createElement('input');
        input.type = 'search';
        input.placeholder = 'Buscar en la tabla…';
        input.setAttribute('aria-label', 'Buscar en la tabla');
        input.autocomplete = 'off';
        search.appendChild(input);
        toolbar.appendChild(search);

        // ---- Filtros por columna (chip + popover; cabeceras con data-filter) ----
        var filters = [];

        function closePops() {
            filters.forEach(function (f) {
                f.pop.hidden = true;
                f.button.setAttribute('aria-expanded', 'false');
            });
        }

        // Refresca el chip y las marcas del popover según los valores elegidos (multi-selección).
        function refreshFilter(filter) {
            var n = filter.values.length;
            filter.button.classList.toggle('is-active', n > 0);
            filter.caption.textContent = n === 0
                ? filter.label
                : (n === 1 ? filter.label + ': ' + filter.values[0] : filter.label + ' · ' + n);
            Array.prototype.forEach.call(filter.pop.children, function (opt) {
                var v = opt.dataset.value;
                var selected = v === '' ? n === 0 : filter.values.indexOf(v) !== -1;
                opt.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
        }

        // Alterna un valor en el filtro; "Todos" (valor vacío) limpia la selección. No cierra el
        // popover: así se pueden marcar varios de una vez.
        function toggleValue(filter, value) {
            if (value === '') {
                filter.values = [];
            } else {
                var i = filter.values.indexOf(value);
                if (i === -1) { filter.values.push(value); } else { filter.values.splice(i, 1); }
            }
            refreshFilter(filter);
            apply();
        }

        // Crea el chip (botón + popover vacío) y lo engancha a la barra; devuelve sus piezas.
        // Compartido por los filtros de conjunto y el de rango de fechas (DRY).
        function makeChip(label) {
            var wrap = document.createElement('div');
            wrap.className = 'tt-filter';
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'tt-chip';
            button.setAttribute('aria-haspopup', 'true');
            button.setAttribute('aria-expanded', 'false');
            var caption = document.createElement('span');
            caption.className = 'tt-chip__text';
            caption.textContent = label;
            var chevron = document.createElement('span');
            chevron.className = 'tt-chip__chevron';
            chevron.setAttribute('aria-hidden', 'true');
            chevron.textContent = '▾';
            button.appendChild(caption);
            button.appendChild(chevron);
            var pop = document.createElement('div');
            pop.className = 'tt-pop';
            pop.hidden = true;
            button.addEventListener('click', function (ev) {
                ev.stopPropagation();
                var willOpen = pop.hidden;
                closePops();
                if (willOpen) {
                    pop.hidden = false;
                    button.setAttribute('aria-expanded', 'true');
                }
            });
            wrap.appendChild(button);
            wrap.appendChild(pop);
            toolbar.appendChild(wrap);
            return { button: button, caption: caption, pop: pop };
        }

        // dd/mm corto de un aaaammdd (o '…' si vacío), para el texto del chip de rango.
        function ymdShort(n) {
            if (n === '') { return '…'; }
            var s = String(n);
            return s.slice(6, 8) + '/' + s.slice(4, 6);
        }

        function refreshRange(f) {
            var has = f.from !== '' || f.to !== '';
            f.button.classList.toggle('is-active', has);
            f.caption.textContent = has ? f.label + ': ' + ymdShort(f.from) + '–' + ymdShort(f.to) : f.label;
        }

        // Filtro por rango de fechas (columna con data-filter-range): dos campos Desde/Hasta que acotan
        // la columna comparando aaaammdd. Reutiliza asDate() para leer la fecha visible dd/mm/aaaa.
        function buildRange(index, label) {
            var chip = makeChip(label);
            chip.pop.classList.add('tt-pop--range');
            var filter = { index: index, type: 'range', from: '', to: '', label: label, button: chip.button, caption: chip.caption, pop: chip.pop, fromInput: null, toInput: null };

            function field(text) {
                var row = document.createElement('label');
                row.className = 'tt-range__field';
                var span = document.createElement('span');
                span.textContent = text;
                var input = document.createElement('input');
                input.type = 'date';
                row.appendChild(span);
                row.appendChild(input);
                input.addEventListener('change', function () {
                    filter.from = filter.fromInput.value ? Number(filter.fromInput.value.replace(/-/g, '')) : '';
                    filter.to = filter.toInput.value ? Number(filter.toInput.value.replace(/-/g, '')) : '';
                    refreshRange(filter);
                    apply();
                });
                chip.pop.appendChild(row);
                return input;
            }
            filter.fromInput = field('Desde');
            filter.toInput = field('Hasta');
            filters.push(filter);
        }

        headers.forEach(function (th, index) {
            if (th.hasAttribute('data-filter-range')) {
                buildRange(index, headerLabel(th));
                return;
            }
            if (!th.hasAttribute('data-filter')) {
                return;
            }
            // Valor visible -> tono (data-tone del chip de la celda, si lo hay): así el popover
            // muestra el mismo color de estado que la tabla, sin conocer la semántica de la columna.
            var values = {};
            rows.forEach(function (row) {
                var text = cellText(row, index);
                if (PLACEHOLDERS.indexOf(text) === -1 && !(text in values)) {
                    var cell = row.children[index];
                    var toned = cell ? cell.querySelector('[data-tone]') : null;
                    values[text] = toned ? toned.getAttribute('data-tone') : '';
                }
            });
            var distinct = Object.keys(values).sort(COLLATOR.compare);
            if (distinct.length < 2) {
                return; // un solo valor: el filtro no aporta
            }
            var label = headerLabel(th);

            var wrap = document.createElement('div');
            wrap.className = 'tt-filter';
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'tt-chip';
            button.setAttribute('aria-haspopup', 'true');
            button.setAttribute('aria-expanded', 'false');
            var caption = document.createElement('span');
            caption.className = 'tt-chip__text';
            caption.textContent = label;
            var chevron = document.createElement('span');
            chevron.className = 'tt-chip__chevron';
            chevron.setAttribute('aria-hidden', 'true');
            chevron.textContent = '▾';
            button.appendChild(caption);
            button.appendChild(chevron);

            var pop = document.createElement('div');
            pop.className = 'tt-pop';
            pop.hidden = true;
            pop.setAttribute('role', 'listbox');
            pop.setAttribute('aria-label', 'Filtrar por ' + label);

            var filter = { index: index, key: th.getAttribute('data-filter'), values: [], label: label, button: button, caption: caption, pop: pop };

            function addOption(value, text, tone) {
                var opt = document.createElement('button');
                opt.type = 'button';
                opt.className = 'tt-pop__opt';
                opt.setAttribute('role', 'option');
                opt.setAttribute('aria-selected', value === '' ? 'true' : 'false');
                opt.dataset.value = value;
                if (tone) {
                    var dot = document.createElement('span');
                    dot.className = 'tt-pop__dot';
                    dot.setAttribute('data-tone', tone);
                    dot.setAttribute('aria-hidden', 'true');
                    opt.appendChild(dot);
                }
                var lbl = document.createElement('span');
                lbl.textContent = text;
                opt.appendChild(lbl);
                opt.addEventListener('click', function (ev) {
                    ev.stopPropagation();
                    toggleValue(filter, value);
                });
                pop.appendChild(opt);
            }
            addOption('', 'Todos', '');
            distinct.forEach(function (value) { addOption(value, value, values[value]); });

            button.addEventListener('click', function (ev) {
                ev.stopPropagation();
                var willOpen = pop.hidden;
                closePops();
                if (willOpen) {
                    pop.hidden = false;
                    button.setAttribute('aria-expanded', 'true');
                }
            });

            wrap.appendChild(button);
            wrap.appendChild(pop);
            toolbar.appendChild(wrap);
            filters.push(filter);
        });

        // Un clic fuera de cualquier popover abierto lo cierra.
        document.addEventListener('click', function (ev) {
            filters.forEach(function (f) {
                if (!f.pop.hidden && !f.pop.contains(ev.target) && !f.button.contains(ev.target)) {
                    f.pop.hidden = true;
                    f.button.setAttribute('aria-expanded', 'false');
                }
            });
        });

        // ---- Botón "Limpiar" (aparece sólo con filtros o búsqueda activos) ----
        var clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'tt-clear';
        clearBtn.textContent = 'Limpiar';
        clearBtn.hidden = true;
        clearBtn.addEventListener('click', function () {
            filters.forEach(function (f) {
                if (f.type === 'range') {
                    f.from = ''; f.to = '';
                    f.fromInput.value = ''; f.toInput.value = '';
                    refreshRange(f);
                } else {
                    f.values = [];
                    refreshFilter(f);
                }
            });
            input.value = '';
            apply();
        });
        toolbar.appendChild(clearBtn);

        // ---- Contador de resultados (a la derecha de la barra) ----
        var counter = document.createElement('span');
        counter.className = 'tt-count';
        toolbar.appendChild(counter);

        // ---- Mensaje "sin resultados" ----
        var emptyRow = document.createElement('tr');
        emptyRow.className = 'table-tools__empty';
        emptyRow.hidden = true;
        var emptyCell = document.createElement('td');
        emptyCell.colSpan = headers.length;
        emptyCell.className = 'muted';
        emptyCell.textContent = 'No hay resultados para los filtros aplicados.';
        emptyRow.appendChild(emptyCell);
        tbody.appendChild(emptyRow);

        function apply() {
            var query = input.value.trim().toLowerCase();
            var active = filters.filter(function (f) {
                return f.type === 'range' ? (f.from !== '' || f.to !== '') : f.values.length > 0;
            });
            var visible = 0;
            rows.forEach(function (row) {
                var ok = (query === '' || row.textContent.toLowerCase().indexOf(query) !== -1)
                    && active.every(function (f) {
                        if (f.type === 'range') {
                            var d = asDate(cellText(row, f.index));
                            return d !== null && (f.from === '' || d >= f.from) && (f.to === '' || d <= f.to);
                        }
                        return f.values.indexOf(cellText(row, f.index)) !== -1;
                    });
                row.hidden = !ok;
                if (ok) { visible++; }
            });
            emptyRow.hidden = visible !== 0;
            counter.textContent = visible === rows.length
                ? rows.length + (rows.length === 1 ? ' resultado' : ' resultados')
                : visible + ' de ' + rows.length;
            clearBtn.hidden = active.length === 0 && query === '';
        }

        var debounce = null;
        input.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(apply, 200);
        });

        // Preselección de filtro vía URL (?filtro=clave:valor), para enlazar a un listado ya filtrado
        // (p. ej. desde una guía de "qué falta"). La clave es el valor de data-filter de la columna.
        // Aditivo: sin el parámetro, o si el valor no existe como opción, no cambia nada.
        var requested = new URLSearchParams(window.location.search).get('filtro');
        if (requested) {
            var sep = requested.indexOf(':');
            var key = sep === -1 ? requested : requested.slice(0, sep);
            var value = sep === -1 ? '' : requested.slice(sep + 1);
            filters.forEach(function (f) {
                if (f.key !== key) {
                    return;
                }
                Array.prototype.forEach.call(f.pop.children, function (opt) {
                    if (opt.dataset.value === value) {
                        toggleValue(f, value);
                    }
                });
            });
        }

        // Estado inicial: pinta el contador (y aplica cualquier preselección de URL ya hecha arriba).
        apply();

        // ---- Ordenación por columna ----
        headers.forEach(function (th, index) {
            if (!headerLabel(th) || th.hasAttribute('data-nosort')) {
                return; // columnas de acciones / explícitamente no ordenables
            }
            th.classList.add('is-sortable');
            th.setAttribute('aria-sort', 'none'); // basta para que el lector lo anuncie ordenable
            th.tabIndex = 0;                       // sin role="button": rompería el rol implícito columnheader
            var type = columnType(rows, index);

            function sort(event) {
                // Pulsar el "?" de ayuda de la cabecera no debe ordenar la columna.
                if (event && event.target && event.target.closest && event.target.closest('.help-btn')) {
                    return;
                }
                var dir = th.getAttribute('aria-sort') === 'ascending' ? 'desc' : 'asc';
                headers.forEach(function (other) { other.setAttribute('aria-sort', 'none'); });
                th.setAttribute('aria-sort', dir === 'asc' ? 'ascending' : 'descending');
                var ordered = rows.slice().sort(function (a, b) {
                    var res = compare(sortKey(sortText(a, index), type), sortKey(sortText(b, index), type), type, dir);
                    return res !== 0 ? res : (a.dataset.ttOrder - b.dataset.ttOrder);
                });
                ordered.forEach(function (row) { tbody.insertBefore(row, emptyRow); });
            }

            th.addEventListener('click', sort);
            th.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    sort();
                }
            });
        });

        table.parentNode.insertBefore(toolbar, table);
        // Da a los campos de fecha del filtro de rango el calendario propio del proyecto (date-field.js).
        if (typeof window.enhanceDateFields === 'function') {
            window.enhanceDateFields(toolbar);
        }
    }

    function init() {
        document.querySelectorAll('.card table[data-list-tools]').forEach(build);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
