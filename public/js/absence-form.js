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
            // "Falta N" cuenta ausencias: rojo/danger (coherente con el estado de cada tarjeta), NO
            // el verde de éxito. Ninguna hora marcada => neutro (muted): no genera guardias.
            out.classList.toggle('badge--danger', marcadas > 0);
            out.classList.toggle('badge--muted', 0 === marcadas);
            // El diálogo de confirmación (confirm-dialog.js) refleja el número real de guardias a generar.
            form.setAttribute('data-confirm', 'Se generarán ' + marcadas + ' guardia' + (1 === marcadas ? '' : 's') + ' y se avisará a los profesores asignados. ¿Continuar?');
        }

        boxes.forEach(function (b) { b.addEventListener('change', render); });
        render();
    });
})();
