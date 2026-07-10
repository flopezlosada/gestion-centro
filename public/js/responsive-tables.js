/* Tablas responsive sin tocar plantillas: por cada tabla de datos, copia el texto de
 * cada cabecera (thead th) a un atributo data-label en la celda correspondiente. En móvil
 * el CSS apila cada fila como "Etiqueta: valor" usando ese data-label. Progressive
 * enhancement: si no hay JS, la tabla se ve como tabla normal. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.card table').forEach(function (table) {
            var heads = Array.prototype.map.call(
                table.querySelectorAll('thead th'),
                function (th) { return th.textContent.trim(); }
            );
            if (0 === heads.length) {
                return;
            }
            table.querySelectorAll('tbody tr').forEach(function (row) {
                Array.prototype.forEach.call(row.children, function (cell, i) {
                    // Solo celdas de datos con una cabecera con texto (la columna de acciones
                    // suele tener cabecera vacía y se queda sin etiqueta, que es lo deseado).
                    if ('TD' === cell.tagName && heads[i]) {
                        cell.setAttribute('data-label', heads[i]);
                    }
                });
            });
        });
    });
})();
