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
 * buttons apart by name and value. It is only marked busy — also when it sits outside the form (`form=`,
 * the mobile dock of a meeting).
 *
 * Releases the lock by itself when the page does not change: a response that is a download, a form opening
 * a new tab, or a server slower than the wait — by then a press again is a decision, not impatience. A form
 * carrying a file waits longer: on a slow connection the upload alone takes a while. And on returning with
 * the back button, when the browser restores the page as it was left.
 *
 * Opt out with `data-allow-resubmit` on the form.
 */
(function () {
    'use strict';

    var RELEASE_MS = 15000;
    var UPLOAD_RELEASE_MS = 120000;
    var NEW_TAB_RELEASE_MS = 3000;
    var BUSY_CLASS = 'is-submitting';
    var busyButtons = new WeakMap();

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
        delete form.dataset.submitting;
        // Released, the next press is a new decision: confirm-dialog.js has to ask again.
        delete form.dataset.confirmed;
        form.removeAttribute('aria-busy');
        if (button) {
            button.classList.remove(BUSY_CLASS);
            busyButtons.delete(form);
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
            button.classList.add(BUSY_CLASS);
            busyButtons.set(form, button);
        }
        window.setTimeout(function () {
            release(form);
        }, wait);
    });

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            Array.prototype.forEach.call(document.querySelectorAll('form[data-submitting]'), release);
        }
    });
})();
