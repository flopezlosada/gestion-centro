/* Conmutador de tema claro/oscuro.
 * El valor inicial se fija en un script inline en el <head> (anti-FOUC) leyendo
 * localStorage y, en su defecto, prefers-color-scheme. Aquí solo enganchamos los
 * botones del conmutador y persistimos la elección. JS mínimo y sin dependencias. */
(function () {
    'use strict';

    function current() {
        return document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    function apply(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem('gc-theme', theme); } catch (e) { /* almacenamiento no disponible */ }
        document.querySelectorAll('.theme-toggle button[data-theme-value]').forEach(function (btn) {
            btn.setAttribute('aria-pressed', String(btn.getAttribute('data-theme-value') === theme));
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        apply(current());
        document.querySelectorAll('.theme-toggle button[data-theme-value]').forEach(function (btn) {
            btn.addEventListener('click', function () { apply(btn.getAttribute('data-theme-value')); });
        });
    });
})();
