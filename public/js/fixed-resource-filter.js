/**
 * Buscador de la lista «1 · Qué» de las reservas fijas (.fixed-resources[data-resource-filter]). Con medio
 * centenar de aulas y carros la lista entera no se abarca: al escribir, quedan solo las cosas cuyo nombre,
 * ubicación o equipos casan, y los títulos de grupo que se quedan sin nada se esconden. La cosa elegida
 * siempre se queda a la vista. Ignora mayúsculas y tildes.
 *
 * Mejora progresiva: sin JavaScript el buscador está oculto y la lista se ve entera, agrupada.
 */
(function () {
    'use strict';

    /* Minúsculas y sin tildes, para que «informatica» encuentre «Informática». */
    function fold(text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    }

    document.querySelectorAll('[data-resource-filter]').forEach(function (nav) {
        var input = nav.querySelector('.fixed-resources__search');
        if (!input) {
            return;
        }
        var items = Array.prototype.slice.call(nav.querySelectorAll('[data-resource]'));
        var groups = Array.prototype.slice.call(nav.querySelectorAll('[data-resource-group]'));
        var none = nav.querySelector('[data-resource-none]');
        var haystacks = items.map(function (item) {
            return fold(item.textContent);
        });
        input.hidden = false;

        input.addEventListener('input', function () {
            var query = fold(input.value.trim());
            var shown = 0;
            items.forEach(function (item, i) {
                var visible = '' === query || haystacks[i].indexOf(query) !== -1 || item.classList.contains('is-on');
                item.hidden = !visible;
                shown += visible ? 1 : 0;
            });
            groups.forEach(function (group) {
                group.hidden = !group.querySelector('[data-resource]:not([hidden])');
            });
            if (none) {
                none.hidden = shown > 0;
            }
        });
    });
})();
