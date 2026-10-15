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

        // Con `multiple`, además del límite de cada fichero cuenta la suma: todos viajan en el mismo envío.
        var maxTotal = parseInt(input.dataset.maxTotalBytes, 10) || 0;

        input.addEventListener('change', function () {
            var files = Array.prototype.slice.call(input.files);
            var tooBig = files.filter(function (file) { return file.size > maxBytes; })[0];
            var total = files.reduce(function (sum, file) { return sum + file.size; }, 0);
            message.hidden = true;
            if (tooBig) {
                message.textContent = '«' + tooBig.name + '» pesa ' + formatMb(tooBig.size) + ' MB, el máximo es ' + formatMb(maxBytes) + ' MB.';
            } else if (maxTotal && total > maxTotal) {
                message.textContent = 'Entre todos pesan ' + formatMb(total) + ' MB y de una vez se pueden subir ' + formatMb(maxTotal) + ' MB: sube unos ahora y el resto después.';
            } else {
                return;
            }
            message.hidden = false;
            input.value = '';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[type="file"][data-max-bytes]').forEach(guard);
    });
})();
