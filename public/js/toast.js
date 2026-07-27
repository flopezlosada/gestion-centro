/**
 * Toast de "hecho": el aviso flotante que confirma que acabas de marcar algo y ofrece deshacerlo.
 *
 * El toast lo sirve el servidor (ver el bloque del final de app_shell.html.twig), no este script: el
 * "Deshacer" es un <form> normal, así que sigue funcionando con JS desactivado. Esto solo lo hace
 * desaparecer solo pasados unos segundos; sin JS el aviso se queda hasta la siguiente navegación, que
 * es un fallo inocuo.
 *
 * Se RETIENE mientras el ratón está encima o el foco está dentro: si se desvaneciera justo cuando vas
 * a pulsar "Deshacer", la red de seguridad no serviría de nada. Autónomo: no hace nada si no hay toast.
 */
(function () {
    'use strict';

    /** Cuánto se queda en pantalla. Hay que leer el título y decidir, así que no puede ser un parpadeo. */
    var VISIBLE_MS = 7000;
    /** Debe coincidir con la transición de .toast.is-leaving en app.css. */
    var FADE_MS = 250;

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var toasts = document.querySelectorAll('[data-toast]');
        if (!toasts.length) {
            return;
        }

        // Sin animación para quien pide menos movimiento: se quita de golpe.
        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        /**
         * Lee el toast en voz alta a través de una región viva CREADA AHORA.
         *
         * Por qué no basta con poner aria-live en el propio toast: una región viva solo anuncia lo que
         * CAMBIA una vez está registrada, y el toast llega ya escrito en el HTML de la página, así que su
         * texto cuenta como contenido de partida y no se anuncia. Se copia a un nodo oculto que se
         * inserta después de la carga, con lo que sí es un cambio. No se toca el toast visible: vaciarlo
         * y volver a escribirlo lo haría parpadear.
         *
         * Sin JS el toast se sigue viendo y su botón se alcanza con el tabulador; solo se pierde el aviso
         * hablado, que es una degradación aceptable.
         */
        function announce(toast) {
            var text = (toast.textContent || '').replace(/\s+/g, ' ').trim();
            if (!text) {
                return;
            }
            var region = document.createElement('div');
            region.className = 'visually-hidden';
            region.setAttribute('aria-live', 'polite');
            document.body.appendChild(region);
            // El texto va en un ciclo posterior a la inserción: si se pusiera de una vez, volvería a ser
            // contenido inicial de la región y tampoco se anunciaría.
            window.setTimeout(function () {
                region.textContent = text;
            }, 120);
        }

        toasts.forEach(function (toast) {
            announce(toast);

            var timer = null;
            // Cuenta de "razones para quedarse" (ratón encima, foco dentro): mientras haya alguna, no
            // se va. Un contador y no un booleano porque ratón y foco pueden solaparse.
            var holds = 0;

            function dismiss() {
                if (reduced) {
                    toast.remove();
                    return;
                }
                toast.classList.add('is-leaving');
                window.setTimeout(function () {
                    toast.remove();
                }, FADE_MS);
            }

            function schedule() {
                if (timer !== null || holds > 0) {
                    return;
                }
                timer = window.setTimeout(dismiss, VISIBLE_MS);
            }

            function hold() {
                holds += 1;
                if (timer !== null) {
                    window.clearTimeout(timer);
                    timer = null;
                }
            }

            function release() {
                holds = Math.max(0, holds - 1);
                schedule();
            }

            toast.addEventListener('mouseenter', hold);
            toast.addEventListener('mouseleave', release);
            toast.addEventListener('focusin', hold);
            toast.addEventListener('focusout', release);
            // Escape lo cierra: es un aviso, no un diálogo, así que no atrapa el foco ni pide confirmar.
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && toast.isConnected) {
                    dismiss();
                }
            });

            schedule();
        });
    });
})();
