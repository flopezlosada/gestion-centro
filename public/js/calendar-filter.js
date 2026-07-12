/**
 * Client-side filtering of the calendar by task status and (in the year view) by term.
 *
 * Two independent, composable filters driven by the legends already on the page:
 *   - Status (additive): every status chip in the legend is a toggle. All are shown by default;
 *     clicking one hides the tasks/dots of that status. Several can be off at once.
 *   - Term (exclusive, year view): clicking a term shows only that term's months; clicking it again
 *     (or another term) resets. Only one term is active at a time.
 *
 * Pure hide/show over the server-rendered markup ([data-status] on tasks/dots, .cal-term--N on the
 * month cards) — no reload, no server round-trip. Progressive enhancement: with JS off, everything
 * is simply shown (the legend degrades to a static key). Self-contained; no-ops off the calendar.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        var statusButtons = document.querySelectorAll('[data-filter-status]');
        var termButtons = document.querySelectorAll('[data-filter-term]');
        if (!statusButtons.length && !termButtons.length) {
            return;
        }

        // ---- Status filter (additive) ----------------------------------------------------------
        var hiddenStatuses = Object.create(null);

        function applyStatus() {
            document.querySelectorAll('[data-status]').forEach(function (el) {
                el.classList.toggle('is-filtered-out', hiddenStatuses[el.getAttribute('data-status')] === true);
            });
        }

        statusButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var status = btn.getAttribute('data-filter-status');
                var nowHidden = !hiddenStatuses[status];
                hiddenStatuses[status] = nowHidden;
                btn.setAttribute('aria-pressed', String(!nowHidden));
                btn.classList.toggle('is-off', nowHidden);
                applyStatus();
            });
        });

        // ---- Term filter (exclusive, year view) ------------------------------------------------
        var activeTerm = null;

        function applyTerm() {
            document.querySelectorAll('.cal-mini').forEach(function (el) {
                var show = activeTerm === null || el.classList.contains('cal-term--' + activeTerm);
                el.classList.toggle('is-filtered-out', !show);
            });
        }

        termButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var term = btn.getAttribute('data-filter-term');
                activeTerm = activeTerm === term ? null : term;
                termButtons.forEach(function (other) {
                    other.setAttribute('aria-pressed', String(other.getAttribute('data-filter-term') === activeTerm));
                });
                applyTerm();
            });
        });
    });
})();
