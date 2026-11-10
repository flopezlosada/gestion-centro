/**
 * One submit per click: a form that is already on its way is not sent again.
 *
 * With a slow connection people press the button again because nothing seems to happen, and every press is
 * a whole new request: an acta published four times, four e-mails to the copy room. This holds back every
 * POST submit of the app from the moment one really leaves until the next page arrives.
 *
 * Listens on `window` on purpose, in both phases: the capture listener runs before any other script, so a
 * second press stops there (no confirmation dialog opening again for a form already sent); the bubbling
 * one runs after them all, so a submit another script cancelled (confirm-dialog.js asking first, a form
 * sent by fetch) does not lock anything.
 *
 * Whether a submit is a POST is the BUTTON's call as much as the form's: the login form posts the e-mail
 * link but its SSO button goes by GET (`formmethod`), and that one must not lock the form.
 *
 * The button is NOT disabled: a disabled submitter is left out of the form data, and some forms tell their
 * buttons apart by name and value. It is marked busy — also when it sits outside the form (`form=`, the
 * mobile dock of a meeting) — and a button with `data-busy-label` shows that text meanwhile: a faded
 * button alone went unnoticed, the page looked as if nothing had happened and people pressed again. It is
 * opt-in because many submit buttons are an icon or a shape (a task's round checkbox, a switch), which a
 * word would break; the form macro's main button carries it. Only on a `<button>`: in an
 * `<input type=submit>` the text IS the value sent.
 *
 * Releases the lock by itself when the page does not change: a response that is a download, a form opening
 * a new tab, or a server slower than the wait — by then a press again is a decision, not impatience. The
 * wait outlasts the slowest thing a request does: an e-mail to a mail server that stops answering. The app
 * waits 15 s for it (BoundedSmtpTimeoutTransportFactory), and Symfony then spends up to 15 s more on a RSET
 * over the same dead connection: 30 s, plus the request's own work. Letting go before that showed the
 * button as ready while the server was still working. A form carrying a file waits longer: on a slow
 * connection the upload alone takes a while. And on returning with the back button, when the browser
 * restores the page as it was left.
 *
 * Opt out with `data-allow-resubmit` on the form.
 */
(function () {
    'use strict';

    var RELEASE_MS = 60000;
    var UPLOAD_RELEASE_MS = 120000;
    var NEW_TAB_RELEASE_MS = 3000;
    var BUSY_CLASS = 'is-submitting';
    var busyButtons = new WeakMap();
    var idleLabels = new WeakMap();
    var releaseTimers = new WeakMap();

    function isPost(form, button) {
        var method = (button && button.hasAttribute('formmethod') ? button.formMethod : form.method) || '';

        return 'post' === method.toLowerCase();
    }

    function carriesFile(form) {
        return Array.prototype.some.call(form.querySelectorAll('input[type="file"]'), function (input) {
            return input.files && input.files.length > 0;
        });
    }

    function release(form) {
        var button = busyButtons.get(form);
        // A timer left from a submit released by the back button would cut a later one short.
        window.clearTimeout(releaseTimers.get(form));
        releaseTimers.delete(form);
        delete form.dataset.submitting;
        // Released, the next press is a new decision: confirm-dialog.js has to ask again.
        delete form.dataset.confirmed;
        form.removeAttribute('aria-busy');
        if (button) {
            button.classList.remove(BUSY_CLASS);
            if (idleLabels.has(button)) {
                button.innerHTML = idleLabels.get(button);
                idleLabels.delete(button);
            }
            busyButtons.delete(form);
        }
    }

    function markBusy(form, button) {
        button.classList.add(BUSY_CLASS);
        busyButtons.set(form, button);
        if (button instanceof HTMLButtonElement && button.dataset.busyLabel) {
            idleLabels.set(button, button.innerHTML);
            button.textContent = button.dataset.busyLabel;
        }
    }

    window.addEventListener('submit', function (event) {
        var form = event.target;
        if (form instanceof HTMLFormElement && form.dataset.submitting === 'yes') {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);

    window.addEventListener('submit', function (event) {
        var form = event.target;
        var button = event.submitter || null;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented
            || form.hasAttribute('data-allow-resubmit') || !isPost(form, button)) {
            return;
        }
        var target = (button && button.getAttribute('formtarget')) || form.getAttribute('target') || '_self';
        var wait = '_self' !== target ? NEW_TAB_RELEASE_MS : (carriesFile(form) ? UPLOAD_RELEASE_MS : RELEASE_MS);

        form.dataset.submitting = 'yes';
        form.setAttribute('aria-busy', 'true');
        if (button) {
            markBusy(form, button);
        }
        releaseTimers.set(form, window.setTimeout(function () {
            release(form);
        }, wait));
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            Array.prototype.forEach.call(document.querySelectorAll('form[data-submitting]'), release);
        }
    });
})();
