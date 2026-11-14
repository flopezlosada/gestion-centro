/* «Volver» de la app instalada (ver app_shell.html.twig).
 *
 * Lleva SIEMPRE al padre de la ruta de la cabecera, que está en el DOM aunque en móvil no se pinte; si la
 * pantalla no tiene ruta, se queda en Inicio. No usa history.back(): tras guardar un formulario (POST y
 * redirección) la página anterior del historial es ese mismo formulario, y «Volver» lo reabriría.
 *
 * La clase .is-standalone de iOS no se pone aquí sino en el <head> (base.html.twig), antes de pintar. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var back = document.querySelector('[data-app-back]');
        var trail = document.querySelectorAll('.breadcrumb a[href]');
        if (back && trail.length) {
            back.href = trail[trail.length - 1].href;
        }
    });
})();
