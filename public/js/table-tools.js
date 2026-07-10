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

        // ---- Filtros por columna (cabeceras con data-filter) ----
        var filters = [];
        headers.forEach(function (th, index) {
            if (!th.hasAttribute('data-filter')) {
                return;
            }
            var values = {};
            rows.forEach(function (row) {
                var text = cellText(row, index);
                if (PLACEHOLDERS.indexOf(text) === -1) {
                    values[text] = true;
                }
            });
            var distinct = Object.keys(values).sort(COLLATOR.compare);
            if (distinct.length < 2) {
                return; // un solo valor: el filtro no aporta
            }
            var wrap = document.createElement('div');
            wrap.className = 'form-row table-tools__filter';
            var select = document.createElement('select');
            var label = headerLabel(th);
            select.setAttribute('aria-label', 'Filtrar por ' + label);
            var all = document.createElement('option');
            all.value = '';
            all.textContent = label + ': todos';
            select.appendChild(all);
            distinct.forEach(function (value) {
                var opt = document.createElement('option');
                opt.value = value;
                opt.textContent = value;
                select.appendChild(opt);
            });
            wrap.appendChild(select);
            toolbar.appendChild(wrap);
            filters.push({ index: index, select: select, key: th.getAttribute('data-filter') });
        });

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
            var active = filters.filter(function (f) { return f.select.value !== ''; });
            var visible = 0;
            rows.forEach(function (row) {
                var ok = (query === '' || row.textContent.toLowerCase().indexOf(query) !== -1)
                    && active.every(function (f) { return cellText(row, f.index) === f.select.value; });
                row.hidden = !ok;
                if (ok) { visible++; }
            });
            emptyRow.hidden = visible !== 0;
        }

        var debounce = null;
        input.addEventListener('input', function () {
            clearTimeout(debounce);
            debounce = setTimeout(apply, 200);
        });
        filters.forEach(function (f) { f.select.addEventListener('change', apply); });

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
                for (var i = 0; i < f.select.options.length; i++) {
                    if (f.select.options[i].value === value) {
                        f.select.value = value;
                        apply();
                        break;
                    }
                }
            });
        }

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
        // Reutiliza el "enhancer" de selects para que el filtro tenga la estética del tema.
        if (typeof window.enhanceSelectMenus === 'function') {
            window.enhanceSelectMenus(toolbar);
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
