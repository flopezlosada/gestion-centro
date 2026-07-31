/* Contador en el ICONO de la aplicación instalada (App Badging API): el numerito que se ve en la
 * pantalla de inicio del móvil sin llegar a abrir nada. El centro lo pidió con estas palabras: "que
 * aparezca en el móvil un icono de llamada antes de abrirlo para saber que tenemos que mirar".
 *
 * Aquí se fija el número REAL de avisos sin leer, que solo conoce el servidor y que llega en el
 * data-unread del <body>. El service worker también lo sube al recibir un aviso con la aplicación
 * cerrada (public/sw.js), pero es este quien lo cuadra: al abrir la aplicación el número vuelve a ser
 * el de verdad, y al quedarse a cero la marca desaparece.
 *
 * Donde la API no existe (Firefox, iOS con la web sin instalar) no pasa nada: el punto de la campana y
 * el aviso push siguen estando. Mejora progresiva.
 */
(function () {
    'use strict';

    if (!('setAppBadge' in navigator)) {
        return;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var unread = parseInt(document.body.getAttribute('data-unread') || '0', 10);
        // Una promesa rechazada aquí no es un error de la aplicación: en algunos navegadores la API
        // existe pero solo funciona con la web instalada. Se traga a propósito.
        var done = unread > 0 ? navigator.setAppBadge(unread) : navigator.clearAppBadge();
        if (done && typeof done.catch === 'function') {
            done.catch(function () {});
        }
    });
})();
