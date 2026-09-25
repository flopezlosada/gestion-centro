/* Buscador de materias del índice de temas (/admin/temas): filtra las filas por el nombre de la materia,
 * sin tener en cuenta mayúsculas ni tildes. Sin JS se ven todas. */
(function () {
    'use strict';

    var input = document.querySelector('[data-topic-filter]');
    var list = document.querySelector('[data-topic-subjects]');
    var none = document.querySelector('[data-topic-none]');
    if (!input || !list) {
        return;
    }

    /* Minúsculas y sin tildes, para que «matematicas» encuentre «Matemáticas». */
    function fold(text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    }

    var rows = Array.prototype.map.call(list.children, function (li) {
        return { li: li, name: fold(li.dataset.name || '') };
    });

    input.addEventListener('input', function () {
        var query = fold(input.value);
        var shown = 0;
        rows.forEach(function (row) {
            row.li.hidden = query !== '' && row.name.indexOf(query) < 0;
            shown += row.li.hidden ? 0 : 1;
        });
        if (none) {
            none.hidden = shown > 0;
        }
    });
})();
