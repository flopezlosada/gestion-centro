/**
 * Confirmation dialog for destructive/irreversible actions (delete, approve, cancel…).
 *
 * Replaces the native window.confirm() with a consistent in-page modal: any <form data-confirm="…">
 * is intercepted on submit and only sent once the user accepts. Self-contained on purpose — it
 * injects its own styles so it does not depend on (nor collide with) the design system's app.css.
 *
 * Progressive enhancement: with JavaScript disabled the form submits normally (same as the previous
 * confirm(), which was also JS). The server still enforces permissions and CSRF, so the dialog is a
 * usability guard, not a security control.
 */
(function () {
    'use strict';

    function injectStyles() {
        if (document.getElementById('confirm-dialog-styles')) {
            return;
        }
        var style = document.createElement('style');
        style.id = 'confirm-dialog-styles';
        style.textContent = [
            '.confirm-overlay{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;',
            'justify-content:center;background:rgba(0,0,0,.45);padding:1rem;}',
            '.confirm-dialog{background:var(--surface,#fff);color:var(--text,#1a1a1a);border-radius:8px;',
            'max-width:30rem;width:100%;padding:1.5rem;box-shadow:0 10px 40px rgba(0,0,0,.25);}',
            '.confirm-dialog .confirm-message{margin:0 0 1.25rem;line-height:1.5;white-space:pre-line;}',
            '.confirm-dialog .confirm-actions{display:flex;gap:.5rem;justify-content:flex-end;}',
        ].join('');
        document.head.appendChild(style);
    }

    function openDialog(message, onAccept) {
        injectStyles();

        var overlay = document.createElement('div');
        overlay.className = 'confirm-overlay';

        var dialog = document.createElement('div');
        dialog.className = 'confirm-dialog';
        dialog.setAttribute('role', 'alertdialog');
        dialog.setAttribute('aria-modal', 'true');

        var text = document.createElement('p');
        text.className = 'confirm-message';
        text.textContent = message; // textContent, never innerHTML: the message is data, not markup.

        var actions = document.createElement('div');
        actions.className = 'confirm-actions';

        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn secondary';
        cancel.textContent = 'Cancelar';

        var accept = document.createElement('button');
        accept.type = 'button';
        accept.className = 'btn';
        accept.textContent = 'Confirmar';

        actions.appendChild(cancel);
        actions.appendChild(accept);
        dialog.appendChild(text);
        dialog.appendChild(actions);
        overlay.appendChild(dialog);

        function close() {
            overlay.remove();
            document.removeEventListener('keydown', onKey);
        }
        function onKey(event) {
            if (event.key === 'Escape') {
                close();
            }
        }

        cancel.addEventListener('click', close);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                close();
            }
        });
        accept.addEventListener('click', function () {
            close();
            onAccept();
        });
        document.addEventListener('keydown', onKey);

        document.body.appendChild(overlay);
        accept.focus();
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        var message = form.getAttribute('data-confirm');
        if (!message || form.dataset.confirmed === 'yes') {
            return;
        }

        event.preventDefault();
        openDialog(message, function () {
            // Mark as confirmed and re-submit; requestSubmit keeps native validation and the button.
            form.dataset.confirmed = 'yes';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    });
})();
