/**
 * Avisos que se van: el toast de "hecho" (con su deshacer) y los carteles flash de una acción.
 *
 * Los dos los sirve el SERVIDOR, no este script: el "Deshacer" del toast es un <form> normal y el flash
 * es un div en el flujo de la página, así que sin JS se siguen viendo y funcionando. Esto solo añade lo
 * que un aviso servido no puede tener por sí mismo — que se pueda cerrar a mano y que se vaya solo — y
 * por eso la × de los flash la crea el script: un botón de cerrar que no cierra nada es peor que no
 * tenerlo.
 *
 * Quién se va solo y quién no:
 * - el toast, siempre: es una ventana de tiempo para rectificar, y se agota;
 * - un flash de ÉXITO, también: la acción salió adelante y el cartel solo estorba a la pantalla que ya
 *   la confirma;
 * - un flash de error o de aviso, NUNCA solo: dice algo que hay que leer y decidir, así que se queda
 *   hasta que se cierre a mano o se navegue.
 *
 * Todo lo que se va solo se RETIENE mientras el ratón está encima o el foco está dentro: si se
 * desvaneciera justo cuando vas a pulsar "Deshacer", la red de seguridad no serviría de nada. Autónomo:
 * no hace nada si no hay avisos en la página.
 */
(function () {
    'use strict';

    /** Cuánto se queda lo que se va solo. Hay que leerlo y decidir, así que no puede ser un parpadeo. */
    var VISIBLE_MS = 7000;
    /** Debe coincidir con la transición de .toast.is-leaving / .flash.is-leaving en app.css. */
    var FADE_MS = 250;

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var toasts = Array.prototype.slice.call(document.querySelectorAll('[data-toast]'));
        var flashes = Array.prototype.slice.call(document.querySelectorAll('.flash'));
        if (!toasts.length && !flashes.length) {
            return;
        }

        // Sin animación para quien pide menos movimiento: se quita de golpe.
        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        /** Lo que Escape puede cerrar, en orden de aparición: { el, dismiss }. */
        var open = [];

        /**
         * Lee un aviso en voz alta a través de una región viva CREADA AHORA.
         *
         * Por qué no basta con poner aria-live en el propio aviso: una región viva solo anuncia lo que
         * CAMBIA una vez está registrada, y el aviso llega ya escrito en el HTML de la página, así que su
         * texto cuenta como contenido de partida y no se anuncia. Se copia a un nodo oculto que se
         * inserta después de la carga, con lo que sí es un cambio. No se toca el aviso visible: vaciarlo
         * y volver a escribirlo lo haría parpadear.
         *
         * Sin JS el aviso se sigue viendo y su botón se alcanza con el tabulador; solo se pierde el aviso
         * hablado, que es una degradación aceptable.
         */
        function announce(el) {
            var text = (el.textContent || '').replace(/\s+/g, ' ').trim();
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

        /**
         * Hace descartable un aviso: le pone la × si se pide y, si se pide, el temporizador que lo retira.
         *
         * @param el      {Element}                        el aviso
         * @param options {{auto: boolean, close: boolean}} auto = se va solo pasado VISIBLE_MS;
         *                                                 close = añadirle un botón de cerrar
         */
        function dismissible(el, options) {
            var timer = null;
            // Cuenta de "razones para quedarse" (ratón encima, foco dentro): mientras haya alguna, no
            // se va. Un contador y no un booleano porque ratón y foco pueden solaparse.
            var holds = 0;

            function dismiss() {
                if (timer !== null) {
                    window.clearTimeout(timer);
                    timer = null;
                }
                var i = 0;
                for (; i < open.length; i += 1) {
                    if (open[i].el === el) {
                        open.splice(i, 1);
                        break;
                    }
                }
                if (reduced) {
                    el.remove();
                    return;
                }
                el.classList.add('is-leaving');
                window.setTimeout(function () {
                    el.remove();
                }, FADE_MS);
            }

            function schedule() {
                if (!options.auto || timer !== null || holds > 0) {
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

            el.addEventListener('mouseenter', hold);
            el.addEventListener('mouseleave', release);
            el.addEventListener('focusin', hold);
            el.addEventListener('focusout', release);

            if (options.close) {
                var close = document.createElement('button');
                close.type = 'button';
                close.className = 'flash__close';
                close.setAttribute('aria-label', 'Cerrar el aviso');
                close.innerHTML = '<span aria-hidden="true">×</span>';
                close.addEventListener('click', dismiss);
                el.classList.add('is-dismissible');
                el.appendChild(close);
            }

            open.push({ el: el, dismiss: dismiss });
            schedule();
        }

        toasts.forEach(function (toast) {
            announce(toast);
            // El toast no lleva ×: su acción es "Deshacer", y una segunda al lado competiría con ella.
            dismissible(toast, { auto: true, close: false });
        });

        flashes.forEach(function (flash) {
            dismissible(flash, { auto: flash.classList.contains('success'), close: true });
        });

        // Escape cierra el último aviso: son avisos, no diálogos, así que no atrapan el foco ni piden
        // confirmar. Un solo listener para todos, en vez de uno por aviso.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && open.length) {
                open[open.length - 1].dismiss();
            }
        });
    });
})();
