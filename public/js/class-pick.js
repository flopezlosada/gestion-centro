/**
 * Rejilla de clases de una actividad semanal con familias: al marcar una clase, las de otros grupos se
 * apagan, porque una actividad es siempre con un mismo grupo. Al desmarcarlas todas, vuelven.
 *
 * Mejora progresiva: sin JavaScript se pueden marcar todas y el servidor rechaza la mezcla con su
 * mensaje (WeeklyFamilyActivity::problemWith()); esto solo evita llegar ahí.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.class-pick'), function (pick) {
            var boxes = Array.prototype.slice.call(pick.querySelectorAll('input[type="checkbox"][data-groups]'));
            var sync = function () {
                var picked = boxes.filter(function (box) { return box.checked; });
                var group = picked.length > 0 ? picked[0].getAttribute('data-groups') : null;
                boxes.forEach(function (box) {
                    box.disabled = !box.checked && group !== null && box.getAttribute('data-groups') !== group;
                });
            };
            boxes.forEach(function (box) { box.addEventListener('change', sync); });
            sync();
        });
    });
})();
