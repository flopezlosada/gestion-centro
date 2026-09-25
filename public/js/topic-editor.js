/* Reordenar la lista de temas (/admin/temas/…/editar) con flechas en vez de escribiendo números.
 *
 * Mejora progresiva: el número de cada fila (tema_orden) sigue siendo lo que se envía y lo que decide el
 * orden en el servidor. Sin JS se escribe a mano; con JS se oculta y lo renumeran las flechas ↑ ↓, que
 * mueven la fila. Marcar «Retirado» apaga la fila al momento. */
(function () {
    'use strict';

    var list = document.querySelector('[data-topic-rows]');
    if (!list) {
        return;
    }
    list.classList.add('is-enhanced');
    list.querySelectorAll('.topic-row__move').forEach(function (el) { el.hidden = false; });

    /* El número visible y el que se envía, según el sitio de cada fila. */
    function renumber() {
        Array.prototype.forEach.call(list.children, function (li, i) {
            li.querySelector('.topic-row__num').textContent = String(i + 1);
            li.querySelector('input[name="tema_orden[]"]').value = String(i + 1);
        });
    }

    list.addEventListener('click', function (e) {
        var button = e.target.closest('[data-move]');
        if (!button) {
            return;
        }
        var li = button.closest('.topic-row');
        var up = button.dataset.move === '-1';
        var other = up ? li.previousElementSibling : li.nextElementSibling;
        if (!other) {
            return;
        }
        list.insertBefore(li, up ? other : other.nextElementSibling);
        renumber();
        button.focus();
    });

    list.addEventListener('change', function (e) {
        if (e.target.name === 'tema_retirado[]') {
            e.target.closest('.topic-row').classList.toggle('is-retired', e.target.checked);
        }
    });
})();
