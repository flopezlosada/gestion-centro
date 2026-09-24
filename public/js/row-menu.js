/* Menús de acciones de fila (details.row-menu): un <details> ya abre y cierra solo, pero no se cierra al
 * pinchar fuera ni al abrir el de otra fila, y con varios abiertos a la vez la tabla se llena de paneles.
 * Esto solo cierra: al abrir uno se cierran los demás, y pinchar fuera o pulsar Escape cierra el abierto. */
(function () {
    'use strict';

    function closeAll(except) {
        document.querySelectorAll('details.row-menu[open]').forEach(function (menu) {
            if (menu !== except) {
                menu.open = false;
            }
        });
    }

    document.addEventListener('click', function (e) {
        closeAll(e.target.closest ? e.target.closest('details.row-menu') : null);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        var open = document.querySelector('details.row-menu[open]');
        if (open) {
            open.open = false;
            open.querySelector('summary').focus();
        }
    });
}());
