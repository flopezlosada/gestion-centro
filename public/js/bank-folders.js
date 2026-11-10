/* Carpetas del banco de tareas (<details data-fold-key>): conserva abierta o cerrada cada carpeta al
 * cambiar de filtro. Cada filtro recarga la página y el servidor pinta abiertas las carpetas según lo que
 * haya en pantalla; sin esto, un filtro que añade una materia (ver retiradas) plegaba la que se estaba
 * leyendo y parecía que la pantalla había escondido esas fichas.
 *
 * - La clave es el nivel, o nivel|materia: la misma carpeta en otro listado guarda su estado.
 * - Lo que pinta el servidor vale solo la primera vez que aparece una carpeta; desde entonces manda lo
 *   que tenía en pantalla.
 * - La ficha recién tocada (#ficha-N tras editar o retirar) y la sugerida para una guardia siempre se
 *   ven: sus carpetas se abren aunque estuvieran cerradas.
 * - sessionStorage: dura lo que la pestaña. Si no está disponible, queda el comportamiento del servidor. */
(function () {
    'use strict';

    var STORE = 'bank-folders';
    var folders = Array.prototype.slice.call(document.querySelectorAll('details[data-fold-key]'));
    if (folders.length === 0) {
        return;
    }

    function load() {
        try {
            return JSON.parse(sessionStorage.getItem(STORE) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    var state = load();

    function save() {
        try {
            sessionStorage.setItem(STORE, JSON.stringify(state));
        } catch (e) {
            // Sin almacenamiento la carpeta se ve igual; solo se pierde el recuerdo.
        }
    }

    function remember(folder) {
        state[folder.dataset.foldKey] = folder.open;
    }

    function shown() {
        var touched = location.hash.indexOf('#ficha-') === 0 ? document.getElementById(location.hash.slice(1)) : null;

        return [touched, document.querySelector('.bank-item.is-suggested')].filter(Boolean);
    }

    folders.forEach(function (folder) {
        var key = folder.dataset.foldKey;
        if (Object.prototype.hasOwnProperty.call(state, key)) {
            folder.open = state[key] === true;
        }
    });
    shown().forEach(function (item) {
        folders.forEach(function (folder) {
            if (folder.contains(item)) {
                folder.open = true;
            }
        });
    });
    folders.forEach(remember);
    save();

    folders.forEach(function (folder) {
        folder.addEventListener('toggle', function () {
            remember(folder);
            save();
        });
    });
})();
