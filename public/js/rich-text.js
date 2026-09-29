/* Texto con formato en los cuadros de una reunión (orden del día, desarrollo, acuerdos): negrita, cursiva,
 * subrayado, listas y enlaces, con el editor Trix (vendor/trix) montado sobre cada textarea[data-rich-text].
 *
 * Mejora progresiva: el textarea sigue siendo el campo que se envía. Trix escribe en él el HTML y el
 * textarea se esconde; sin JS queda a la vista y se puede escribir igual. Lo que llegue, venga de donde
 * venga, lo limpia el servidor (MeetingProse): esto solo da los botones.
 *
 * Tres ajustes sobre el Trix de serie:
 *  - Subrayado: lo pidió el centro y Trix no lo trae; se añade como atributo de texto (<u>) con su botón.
 *  - Sin adjuntos: un fichero arrastrado al cuadro se quedaría a medio subir (no hay dónde guardarlo), así
 *    que se rechaza y el grupo de botones de adjuntar no se enseña (CSS).
 *  - Textos de la barra en español.
 */
(function () {
    'use strict';

    if (!window.Trix) {
        return;
    }

    Trix.config.textAttributes.underline = {
        tagName: 'u',
        inheritable: true,
        parser: function (element) {
            return window.getComputedStyle(element).textDecorationLine.indexOf('underline') !== -1;
        }
    };

    Object.assign(Trix.config.lang, {
        bold: 'Negrita',
        italic: 'Cursiva',
        strike: 'Tachado',
        link: 'Enlace',
        unlink: 'Quitar enlace',
        url: 'Enlace',
        urlPlaceholder: 'Pega el enlace (https://…)',
        heading1: 'Título',
        quote: 'Cita',
        code: 'Código',
        bullets: 'Lista',
        numbers: 'Lista numerada',
        outdent: 'Menos sangría',
        indent: 'Más sangría',
        undo: 'Deshacer',
        redo: 'Rehacer',
        remove: 'Quitar',
        attachFiles: 'Adjuntar'
    });

    // El botón de subrayado, junto a los de negrita y cursiva, en cada barra que Trix pinte.
    document.addEventListener('trix-initialize', function (event) {
        var toolbar = event.target.toolbarElement;
        var group = toolbar && toolbar.querySelector('.trix-button-group--text-tools');
        if (!group || group.querySelector('[data-trix-attribute="underline"]')) {
            return;
        }
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'trix-button trix-button--icon-underline';
        button.setAttribute('data-trix-attribute', 'underline');
        button.setAttribute('data-trix-key', 'u');
        button.title = 'Subrayado';
        button.tabIndex = -1;
        button.textContent = 'U';
        var italic = group.querySelector('[data-trix-attribute="italic"]');
        group.insertBefore(button, italic ? italic.nextSibling : null);
    });

    document.addEventListener('trix-file-accept', function (event) {
        event.preventDefault();
    });

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('textarea[data-rich-text]'), function (textarea) {
            if (!textarea.id) {
                return; // Trix se ata al campo por id: sin él no hay a dónde escribir.
            }
            var editor = document.createElement('trix-editor');
            editor.setAttribute('input', textarea.id);
            editor.className = 'trix-content rich-text-editor';
            if (textarea.placeholder) {
                editor.setAttribute('placeholder', textarea.placeholder);
            }
            textarea.insertAdjacentElement('afterend', editor);
            textarea.hidden = true;

            // Un enlace que apuntaba al cuadro (#meeting_form_agenda) llega ahora a un campo escondido:
            // se lleva el foco al editor, que es donde se escribe.
            if (window.location.hash === '#' + textarea.id) {
                editor.addEventListener('trix-initialize', function () {
                    editor.focus();
                    editor.scrollIntoView({ block: 'center' });
                }, { once: true });
            }
        });
    });
})();
