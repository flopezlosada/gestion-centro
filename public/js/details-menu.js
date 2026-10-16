/* Menús desplegables hechos con <details data-menu> (acciones de fila, filtros del banco, el «+» del
 * calendario…): un <details> ya abre y cierra solo, pero no se cierra al pinchar fuera ni al abrir otro, y
 * un panel flotante se queda tapando la página. Esto solo cierra: al abrir uno se cierran los demás, y
 * pinchar fuera o pulsar Escape cierra el abierto. Los <details> que despliegan contenido en línea (un
 * «ver más») no llevan la marca: esos se quedan abiertos mientras se lee lo de dentro. */
(function () {
    'use strict';

    var OPEN = 'details[data-menu][open]';

    function closeAll(except) {
        document.querySelectorAll(OPEN).forEach(function (menu) {
            if (menu !== except) {
                menu.open = false;
            }
        });
    }

    document.addEventListener('click', function (e) {
        closeAll(e.target.closest ? e.target.closest('details[data-menu]') : null);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') {
            return;
        }
        var open = document.querySelector(OPEN);
        if (open) {
            open.open = false;
            // El foco vuelve al botón que lo abrió: quien va con teclado no pierde su sitio en la página.
            open.querySelector('summary').focus();
        }
    });
}());
