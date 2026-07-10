/**
 * Contextual help popover.
 *
 * Any <a class="help-btn" data-help="slug"> (rendered by the help_button() Twig function) is a real
 * link to the full help page /ayuda/{slug}. This script progressively enhances it: on click it
 * fetches the topic's popover fragment (/ayuda/{slug}/panel) and shows it in an in-page modal, so
 * the user gets the summary without leaving the screen. With JavaScript disabled — or if the fetch
 * fails — the link navigates to the full page as usual, so help is never unreachable.
 *
 * Self-contained on purpose (like confirm-dialog.js): it injects its own styles built on the design
 * system's tokens, so it neither depends on nor collides with app.css.
 */
(function () {
    'use strict';

    function injectStyles() {
        if (document.getElementById('help-styles')) {
            return;
        }
        var style = document.createElement('style');
        style.id = 'help-styles';
        // NOTE: the "?" button and field-label styles live in app.css (loaded in <head>), NOT here,
        // so the buttons already present in the HTML are styled on first paint (no flash). This block
        // only styles the modal/panel, which is created on demand and never in the initial HTML.
        style.textContent = [
            '.help-overlay{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;',
            'justify-content:center;background:rgba(0,0,0,.45);padding:1rem;}',
            '.help-modal{background:var(--surface,#fff);color:var(--text,#1a1a1a);border-radius:8px;',
            'max-width:34rem;width:100%;max-height:85vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.25);}',
            '.help-modal__head{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;',
            'padding:1.25rem 1.5rem;border-bottom:1px solid var(--surface-sunken,#eee);}',
            '.help-modal__head h2{margin:0;font-size:1.1rem;}',
            '.help-modal__close{border:0;background:transparent;color:var(--text-muted,#666);font-size:1.4rem;',
            'line-height:1;cursor:pointer;padding:0;}',
            '.help-modal__content{padding:1.25rem 1.5rem;line-height:1.55;}',
            '.help-panel__summary{margin-top:0;}',
            '.help-panel__legal h3{font-size:.95rem;margin:1rem 0 .35rem;}',
            '.help-panel__legal ul,.help-panel__more{margin:.25rem 0 0;}',
            '.help-modal__loading{padding:1.5rem;color:var(--text-muted,#666);}',
        ].join('');
        document.head.appendChild(style);
    }

    function openModal(title, opener) {
        injectStyles();

        var overlay = document.createElement('div');
        overlay.className = 'help-overlay';

        var modal = document.createElement('div');
        modal.className = 'help-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'help-modal-title');

        var head = document.createElement('div');
        head.className = 'help-modal__head';

        var heading = document.createElement('h2');
        heading.id = 'help-modal-title';
        heading.textContent = title || 'Ayuda';

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'help-modal__close';
        close.setAttribute('aria-label', 'Cerrar');
        close.innerHTML = '&times;';

        head.appendChild(heading);
        head.appendChild(close);

        var content = document.createElement('div');
        content.className = 'help-modal__content';
        var loading = document.createElement('p');
        loading.className = 'help-modal__loading';
        loading.textContent = 'Cargando…';
        content.appendChild(loading);

        modal.appendChild(head);
        modal.appendChild(content);
        overlay.appendChild(modal);

        function destroy() {
            overlay.remove();
            document.removeEventListener('keydown', onKey);
            if (opener && typeof opener.focus === 'function') {
                opener.focus(); // return focus to the button that opened the popover
            }
        }
        function onKey(event) {
            if (event.key === 'Escape') {
                destroy();
            }
        }

        close.addEventListener('click', destroy);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                destroy();
            }
        });
        document.addEventListener('keydown', onKey);

        document.body.appendChild(overlay);
        close.focus();

        return { content: content };
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a.help-btn[data-help]') : null;
        if (!link) {
            return;
        }
        // Let the user open the full page in a new tab / with a modifier, as with any real link.
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        event.preventDefault();
        var title = link.getAttribute('data-help-title') || 'Ayuda';
        var panel = openModal(title, link);

        // The panel URL is the topic's own href (/ayuda/{slug}) plus /panel: derive it from the link
        // so the /ayuda prefix is never duplicated here and in the PHP route.
        fetch(link.href + '/panel', { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            })
            .then(function (html) {
                panel.content.innerHTML = html; // trusted server-rendered fragment
            })
            .catch(function () {
                // Fetch failed: fall back to the full help page so the user is never stuck.
                window.location = link.href;
            });
    });

    // Inject the styles on load (not just when a modal opens) so the "?" buttons are styled from the
    // start. injectStyles() is idempotent, so opening a modal later is a no-op.
    injectStyles();
})();
