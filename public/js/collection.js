/**
 * Minimal add/remove for Symfony CollectionType forms (allow_add / allow_delete), without a build
 * step — same vanilla style as the rest of /js. A collection is a container with:
 *   - data-prototype: the HTML for one entry, with __name__ as the index placeholder
 *   - data-index:     the next index to use (rendered server-side as the current entry count)
 * An "add" trigger is any element with data-collection-add="<container id>"; a "remove" trigger is
 * any element with data-collection-remove inside an entry. Both are delegated, so entries added
 * dynamically work too. Each entry is numbered ("Acción 1, 2…" via its title element with
 * data-collection-label) and renumbered on every add/remove, so it is always clear which entry a
 * Quitar button affects.
 */
(function () {
    'use strict';

    /** Re-labels every entry in the container as "<base> 1, 2, 3…" after the set changes. */
    function renumber(container) {
        var titles = container.querySelectorAll('[data-collection-label]');
        Array.prototype.forEach.call(titles, function (title, i) {
            title.textContent = title.getAttribute('data-collection-label') + ' ' + (i + 1);
        });
    }

    function addEntry(container) {
        var index = parseInt(container.getAttribute('data-index') || '0', 10);
        var html = (container.getAttribute('data-prototype') || '').replace(/__name__/g, String(index));
        var label = container.getAttribute('data-collection-item-label') || 'Elemento';

        var entry = document.createElement('div');
        entry.className = 'collection-item';

        var bar = document.createElement('div');
        bar.className = 'collection-item__bar';
        var title = document.createElement('span');
        title.className = 'collection-item__title';
        title.setAttribute('data-collection-label', label);
        bar.appendChild(title);
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn secondary btn-sm collection-remove';
        remove.setAttribute('data-collection-remove', '');
        remove.textContent = 'Quitar';
        bar.appendChild(remove);
        entry.appendChild(bar);

        var fields = document.createElement('div');
        fields.innerHTML = html;
        while (fields.firstChild) {
            entry.appendChild(fields.firstChild);
        }

        container.appendChild(entry);
        container.setAttribute('data-index', String(index + 1));
        renumber(container);

        var firstField = entry.querySelector('input, select, textarea');
        if (firstField) {
            firstField.focus();
        }
    }

    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-collection-add]');
        if (add) {
            event.preventDefault();
            var container = document.getElementById(add.getAttribute('data-collection-add'));
            if (container) {
                addEntry(container);
            }
            return;
        }

        var remove = event.target.closest('[data-collection-remove]');
        if (remove) {
            event.preventDefault();
            var item = remove.closest('.collection-item');
            if (item) {
                var parent = item.parentElement;
                item.remove();
                if (parent) {
                    renumber(parent);
                }
            }
        }
    });
})();
