/**
 * Bloque de documentos de una tarea del banco (edición): sube y quita por AJAX, sin recargar, para no
 * perder lo que se esté escribiendo en el formulario de arriba.
 *
 * El servidor no sabe nada de esto: cada acción responde con su cartel flash y una redirección a la
 * propia edición, que es también la respuesta entera sin JS. Aquí se sigue esa redirección y, de la
 * página que llega, se toman solo la lista de documentos y los carteles, que se pintan en el propio
 * bloque (arriba del todo nadie los vería: el bloque está al final de la página).
 *
 * Con JS se sube al elegir los archivos, así que el botón «Subir» se oculta. Quitar pregunta antes
 * (confirm-dialog.js): este script escucha en `document` y se carga después, así que solo ve el envío
 * ya confirmado; al cancelarlo él mismo, form-once.js tampoco bloquea el formulario.
 */
(function () {
    'use strict';

    var block = document.querySelector('[data-bank-docs]');
    if (!block || !window.fetch || !window.DOMParser) {
        return;
    }
    var list = block.querySelector('[data-bank-docs-list]');
    var addForm = block.querySelector('[data-bank-docs-add]');
    var input = addForm.querySelector('input[type="file"]');
    var notices = document.createElement('div');
    notices.className = 'bank-docs__notices';
    list.insertAdjacentElement('beforebegin', notices);
    var busy = false;

    addForm.querySelector('[data-bank-docs-submit]').hidden = true;

    input.addEventListener('change', function () {
        // En el turno siguiente: file-size-guard.js vacía el campo si algo pesa demasiado, y hay que
        // dejarle hacerlo antes de mirar si queda algo que subir.
        window.setTimeout(function () {
            if (input.files.length) {
                addForm.requestSubmit();
            }
        }, 0);
    });

    function show(flashes) {
        notices.innerHTML = '';
        flashes.forEach(function (flash) {
            notices.appendChild(flash);
            if (window.appNotices) {
                window.appNotices.flash(flash);
            }
        });
    }

    function failure() {
        var flash = document.createElement('div');
        flash.className = 'flash error';
        flash.textContent = 'No se pudo completar. Comprueba la conexión y vuelve a intentarlo.';

        return flash;
    }

    function send(form) {
        // Antes de marcar nada como ocupado: un campo deshabilitado no entra en el FormData.
        var data = new FormData(form);
        var hadFocus = list.contains(document.activeElement);
        busy = true;
        block.setAttribute('aria-busy', 'true');
        input.disabled = true;

        fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.text();
            })
            .then(function (html) {
                var page = new DOMParser().parseFromString(html, 'text/html');
                var fresh = page.querySelector('[data-bank-docs-list]');
                // Sin la lista no es la edición (la sesión caducó y llegó el login, por ejemplo).
                if (!fresh) {
                    throw new Error('respuesta inesperada');
                }
                list.innerHTML = fresh.innerHTML;
                show(Array.prototype.slice.call(page.querySelectorAll('.flash')));
            })
            .catch(function () {
                show([failure()]);
            })
            .finally(function () {
                // confirm-dialog.js marca el formulario como confirmado; si falla, el formulario sigue ahí
                // y el siguiente clic en la papelera tiene que volver a preguntar.
                delete form.dataset.confirmed;
                busy = false;
                block.removeAttribute('aria-busy');
                input.disabled = false;
                input.value = '';
                // El botón pulsado ya no existe: el foco vuelve al bloque, no al principio de la página.
                if (hadFocus) {
                    input.focus();
                }
            });
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !(form instanceof HTMLFormElement) || !block.contains(form)) {
            return;
        }
        e.preventDefault();
        if (!busy) {
            send(form);
        }
    });
}());
