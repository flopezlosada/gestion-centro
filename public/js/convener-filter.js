/* «Quién convoca» de un grupo de convocatoria (select[data-convener-filter]): solo ofrece a quien está
 * marcado como miembro del grupo en ese mismo formulario y al equipo directivo (opciones con
 * data-leadership). Se recalcula al marcar o desmarcar miembros, sin guardar antes.
 *
 * Mismo patrón que task-form.js: las <option> se quitan y se vuelven a poner (un <option> oculto sigue
 * saliendo en algunos navegadores) y se avisa al desplegable propio con cselectRefresh. Lo que ya estaba
 * elegido al abrir se conserva aunque no cumpla, para no borrarlo en silencio: el servidor lo rechaza al
 * guardar con su mensaje. Sin JS se ofrece todo y manda la misma validación del servidor. */
(function () {
    'use strict';

    function enhance(select) {
        var form = select.form;
        var boxes = Array.prototype.slice.call(form.querySelectorAll('input[type="checkbox"][name$="[members][]"]'));
        var options = Array.prototype.slice.call(select.options);
        var placeholder = options.filter(function (option) { return option.value === ''; })[0] || null;
        var candidates = options.filter(function (option) { return option.value !== ''; });
        var initial = select.value;

        function filter() {
            var members = {};
            boxes.forEach(function (box) {
                if (box.checked) {
                    members[box.value] = true;
                }
            });
            var previous = select.value;
            while (select.firstChild) {
                select.removeChild(select.firstChild);
            }
            if (placeholder) {
                select.appendChild(placeholder);
            }
            var stillValid = false;
            candidates.forEach(function (option) {
                var eligible = members[option.value] || option.hasAttribute('data-leadership') || option.value === initial;
                if (eligible) {
                    select.appendChild(option);
                    stillValid = stillValid || option.value === previous;
                }
            });
            select.value = stillValid ? previous : '';
            if (typeof select.cselectRefresh === 'function') {
                select.cselectRefresh();
            }
        }

        boxes.forEach(function (box) { box.addEventListener('change', filter); });
        filter();
    }

    function init() {
        document.querySelectorAll('select[data-convener-filter]').forEach(function (select) {
            try {
                enhance(select);
            } catch (err) {
                // Si falla, queda el desplegable completo y el servidor sigue validando.
                if (window.console) { console.error(err); }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
