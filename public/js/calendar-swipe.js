/**
 * Deslizar el dedo sobre el calendario cambia de periodo, como en Google Calendar: hacia la izquierda
 * el siguiente, hacia la derecha el anterior. No sabe de fechas: sigue los enlaces «‹ Anterior» y
 * «Siguiente ›» de la barra (rel="prev" / rel="next"), que son los que calcula el servidor, así que
 * vale para día, semana, mes y año sin repetir su lógica.
 *
 * Solo cuenta un gesto claramente horizontal y largo: uno en diagonal es alguien bajando por las horas,
 * y un toque corto es abrir un bloque. Autónomo: no hace nada fuera de la página del calendario.
 */
(function () {
    'use strict';

    /** Recorrido horizontal mínimo, en px, para que cuente como deslizar. */
    var MIN_DISTANCE = 60;
    /** Cuántas veces mayor que el vertical tiene que ser el recorrido horizontal. */
    var MIN_RATIO = 1.5;

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var prev = document.querySelector('.calendar-toolbar a[rel="prev"]');
        var next = document.querySelector('.calendar-toolbar a[rel="next"]');
        // La tarjeta entera y no solo la rejilla: las vistas de día y de año no comparten contenedor.
        var calendar = prev && prev.closest('.card');
        if (!calendar || !next) {
            return;
        }

        var startX = null;
        var startY = null;

        calendar.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) {
                startX = null;
                return;
            }
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }, { passive: true });

        calendar.addEventListener('touchend', function (e) {
            if (startX === null) {
                return;
            }
            var dx = e.changedTouches[0].clientX - startX;
            var dy = e.changedTouches[0].clientY - startY;
            startX = null;
            if (Math.abs(dx) < MIN_DISTANCE || Math.abs(dx) < MIN_RATIO * Math.abs(dy)) {
                return;
            }
            window.location.href = (dx < 0 ? next : prev).href;
        }, { passive: true });
    });
})();
