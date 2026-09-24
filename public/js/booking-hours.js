/**
 * Filtro de horas de /reservas: pide por fetch solo el fragmento de "qué está libre" al marcar o
 * desmarcar una casilla, en vez de dejar que el checkbox recargue la página entera (nav, lo reservado hoy,
 * "lo que tienes reservado"...) por un simple cambio de filtro.
 *
 * Progresivo: sin JS, cada casilla sigue enviando su <form method="get"> normal (el atributo `onchange`
 * inline del twig), que recarga la página entera y llega al mismo sitio por BookingController::index().
 * Con JS, este script intercepta ese mismo evento `change` antes de que el navegador llegue a navegar de
 * verdad — `submit` se dispara síncronamente antes de la navegación, así que basta con cancelarlo aquí.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var form = document.querySelector('[data-booking-hours]');
        var result = document.querySelector('[data-booking-availability]');
        if (!form || !result) {
            return;
        }

        var availabilityUrl = form.getAttribute('data-availability-url');
        var pageUrl = form.getAttribute('data-page-url');

        function paramsFromForm() {
            return new URLSearchParams(new FormData(form));
        }

        function refresh(params) {
            fetch(availabilityUrl + '?' + params.toString())
                .then(function (response) {
                    return response.ok ? response.text() : Promise.reject(response.status);
                })
                .then(function (html) {
                    result.innerHTML = html;
                    // El <select> que acaba de llegar es nativo: sin esto se queda sin el listbox propio
                    // (select-menu.js), que solo realza lo que ya estaba en el documento al cargar.
                    if (window.enhanceSelectMenus) {
                        window.enhanceSelectMenus(result);
                    }
                })
                .catch(function () {
                    // El fragmento no llegó: se deja el último resultado visible y el usuario puede
                    // reintentar marcando otra vez. Recargar la página entera aquí sería reintroducir el
                    // problema que este script existe para evitar.
                });
            // La URL sí se actualiza aunque el fetch tarde o falle: recargar la página a mano (F5, volver
            // atrás) tiene que ver el mismo filtro, y esto es gratis.
            history.replaceState(null, '', pageUrl + '?' + params.toString());
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
        });

        form.addEventListener('change', function (event) {
            if ('checkbox' !== event.target.type) {
                return;
            }
            refresh(paramsFromForm());
        });

        // «Todo el día»: en vez de navegar (perdería el fragmento ya cargado sin necesidad), marca todas
        // las casillas y dispara el mismo camino que marcarlas una a una.
        var allDay = form.querySelector('[data-booking-all-day]');
        if (allDay) {
            allDay.addEventListener('click', function (event) {
                event.preventDefault();
                form.querySelectorAll('input[type=checkbox]').forEach(function (box) {
                    box.checked = true;
                });
                refresh(paramsFromForm());
            });
        }
    });
})();
