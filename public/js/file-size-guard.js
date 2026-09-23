/* Aviso de tamaño antes de enviar: sin esto, un fichero grande hace el viaje completo al servidor y,
 * pasados los 15 MB de ModSecurity, ni siquiera llega a Symfony — el usuario solo ve un error crudo.
 * El límite real sigue siendo el del servidor (cada input trae el suyo en data-max-bytes, citando la
 * misma constante que valida allí); esto es solo para no hacer esperar al usuario en balde.
 *
 * Mejora progresiva: sin JS el formulario funciona igual y es el servidor quien rechaza el fichero.
 */
(function () {
    'use strict';

    function formatMb(bytes) {
        return (bytes / (1024 * 1024)).toFixed(1).replace(/\.0$/, '');
    }

    function guard(input) {
        var maxBytes = parseInt(input.dataset.maxBytes, 10);
        if (!maxBytes) {
            return;
        }

        var message = document.createElement('p');
        message.className = 'field-help field-help--error';
        message.hidden = true;
        // Si el input vive dentro de un <label> (p. ej. .classcard__file), insertar el mensaje ahí
        // dentro lo mete bajo el paraguas del label: clicar el texto de error reabriría el selector de
        // fichero, y su nombre accesible se leería pegado al del campo. Se cuelga fuera del label.
        (input.closest('label') || input).insertAdjacentElement('afterend', message);

        input.addEventListener('change', function () {
            var file = input.files[0];
            message.hidden = true;
            if (!file || file.size <= maxBytes) {
                return;
            }
            message.textContent = '«' + file.name + '» pesa ' + formatMb(file.size) + ' MB, el máximo es ' + formatMb(maxBytes) + ' MB.';
            message.hidden = false;
            input.value = '';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[type="file"][data-max-bytes]').forEach(guard);
    });
})();
