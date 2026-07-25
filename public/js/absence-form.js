/* Contador de horas marcadas en "apuntar ausencia" ("falta 3 de 4"). Progressive enhancement:
 * sin JS el contador queda con el total, que es cierto de partida porque todas nacen marcadas.
 * El apagado visual de una hora desmarcada lo hace el CSS con :has, no esto. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-absence-form]');
        if (!form) {
            return;
        }
        var out = form.querySelector('[data-absence-count]');
        var boxes = form.querySelectorAll('input[name="slots[]"]');
        if (!out || !boxes.length) {
            return;
        }

        function render() {
            var marcadas = 0;
            boxes.forEach(function (b) { marcadas += b.checked ? 1 : 0; });
            out.textContent = 'falta ' + marcadas + ' de ' + boxes.length;
            // Ninguna hora marcada no genera guardias: se avisa con el tono, no con un bloqueo.
            out.classList.toggle('badge--ok', marcadas > 0);
            out.classList.toggle('badge--muted', 0 === marcadas);
        }

        boxes.forEach(function (b) { b.addEventListener('change', render); });
        render();
    });
})();
