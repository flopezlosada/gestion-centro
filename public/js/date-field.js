/* Calendario propio para <input type="date">: sustituye el selector nativo del sistema operativo
 * por uno con la estética de "Registro" (meses/días en español, semana empezando en lunes).
 *
 * Mejora progresiva con red de seguridad: sin JS (o si esto falla), el input nativo type="date"
 * queda plenamente usable. Con JS, el input real se convierte en type="hidden" CONSERVANDO su name
 * y su valor ISO (AAAA-MM-DD) — la fuente de verdad que se envía —, y encima se muestra un campo
 * legible dd/mm/aaaa más un botón que abre el calendario. Así el formulario se envía igual y los
 * tests que fijan el valor por nombre de campo (task_form[dueDate]) siguen funcionando.
 *
 * Cada campo se realza en un try/catch: un fallo puntual no rompe la página ni deja el input roto. */
(function () {
    'use strict';

    var MONTHS = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    var WEEKDAYS = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

    /* AAAA-MM-DD -> {y, m (1-12), d}, o null si no es una fecha válida. */
    function parseIso(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!m) {
            return null;
        }
        var y = +m[1], mo = +m[2], d = +m[3];
        var date = new Date(y, mo - 1, d);
        if (date.getFullYear() !== y || date.getMonth() !== mo - 1 || date.getDate() !== d) {
            return null; // rechaza desbordamientos tipo 2026-02-30
        }
        return { y: y, m: mo, d: d };
    }

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function toIso(y, m, d) { return y + '-' + pad(m) + '-' + pad(d); }
    function toHuman(y, m, d) { return pad(d) + '/' + pad(m) + '/' + y; }

    /* Índice 0-6 (Lun=0 … Dom=6) del primer día del mes: base para alinear la rejilla. */
    function mondayFirstOffset(y, m) {
        var jsDay = new Date(y, m - 1, 1).getDay(); // 0=Dom … 6=Sáb
        return (jsDay + 6) % 7;
    }

    function daysInMonth(y, m) { return new Date(y, m, 0).getDate(); }

    var counter = 0;

    function enhance(input) {
        if (input.dataset.cdateDone === '1') {
            return;
        }
        input.dataset.cdateDone = '1';

        var id = input.id || 'cdate-' + (++counter);
        var initial = parseIso(input.value);

        var wrap = document.createElement('div');
        wrap.className = 'cdate';

        // Campo legible (dd/mm/aaaa). Hereda el id original para que el <label for> lo enfoque.
        var display = document.createElement('input');
        display.type = 'text';
        display.className = 'cdate__display';
        display.readOnly = true;
        display.placeholder = 'dd/mm/aaaa';
        display.autocomplete = 'off';
        display.id = id;
        display.value = initial ? toHuman(initial.y, initial.m, initial.d) : '';
        display.setAttribute('aria-haspopup', 'dialog');
        display.setAttribute('aria-expanded', 'false');
        // El input real pasa a hidden (excluido de la validación HTML5). Se conserva la señal de
        // obligatoriedad en el campo visible para lectores de pantalla; la valida el servidor.
        if (input.required) {
            display.setAttribute('aria-required', 'true');
        }

        var pop = document.createElement('div');
        pop.className = 'cdate__pop';
        pop.setAttribute('role', 'dialog');
        pop.setAttribute('aria-label', 'Elegir fecha');
        pop.hidden = true;

        // El input real se vuelve oculto pero conserva name/valor: es lo que se envía.
        input.type = 'hidden';
        input.removeAttribute('id');

        // Mes que se está mostrando (arranca en el valor actual o en hoy).
        var view = initial ? { y: initial.y, m: initial.m } : (function () {
            var now = new Date();
            return { y: now.getFullYear(), m: now.getMonth() + 1 };
        })();
        var active = initial ? { y: initial.y, m: initial.m, d: initial.d } : null;

        function selectedIso() { return input.value; }

        function render() {
            var today = new Date();
            var todayIso = toIso(today.getFullYear(), today.getMonth() + 1, today.getDate());
            var html = '<div class="cdate__head">' +
                '<button type="button" class="cdate__nav" data-step="-1" aria-label="Mes anterior">‹</button>' +
                '<span class="cdate__title">' + MONTHS[view.m - 1] + ' ' + view.y + '</span>' +
                '<button type="button" class="cdate__nav" data-step="1" aria-label="Mes siguiente">›</button>' +
                '</div><div class="cdate__grid" role="grid">';
            WEEKDAYS.forEach(function (w) { html += '<span class="cdate__wd" aria-hidden="true">' + w + '</span>'; });
            var offset = mondayFirstOffset(view.y, view.m);
            for (var i = 0; i < offset; i++) { html += '<span class="cdate__pad"></span>'; }
            var total = daysInMonth(view.y, view.m);
            for (var d = 1; d <= total; d++) {
                var iso = toIso(view.y, view.m, d);
                var cls = 'cdate__day';
                if (iso === selectedIso()) { cls += ' is-selected'; }
                if (iso === todayIso) { cls += ' is-today'; }
                var isActive = active && active.y === view.y && active.m === view.m && active.d === d;
                html += '<button type="button" class="' + cls + '" role="gridcell" data-iso="' + iso + '"' +
                    (isActive ? ' data-active="1"' : '') +
                    ' aria-selected="' + (iso === selectedIso() ? 'true' : 'false') + '">' + d + '</button>';
            }
            html += '</div>';
            pop.innerHTML = html;
        }

        function focusActive() {
            var el = pop.querySelector('.cdate__day[data-active="1"]') || pop.querySelector('.cdate__day');
            if (el) { el.focus(); }
        }

        function open() {
            render();
            pop.hidden = false;
            wrap.classList.add('is-open');
            display.setAttribute('aria-expanded', 'true');
            if (!active) {
                // Sin fecha previa, el foco de teclado arranca en HOY (no en el día 1), que es lo que
                // el usuario espera al abrir el calendario para poner una fecha cercana.
                var now = new Date();
                active = { y: now.getFullYear(), m: now.getMonth() + 1, d: now.getDate() };
                view = { y: active.y, m: active.m };
                render();
            }
            focusActive();
        }

        function close() {
            pop.hidden = true;
            wrap.classList.remove('is-open');
            display.setAttribute('aria-expanded', 'false');
        }
        wrap.cdateClose = close;

        function choose(iso) {
            var parts = parseIso(iso);
            if (!parts) {
                return;
            }
            input.value = iso;
            display.value = toHuman(parts.y, parts.m, parts.d);
            active = { y: parts.y, m: parts.m, d: parts.d };
            input.dispatchEvent(new Event('change', { bubbles: true }));
            close();
            display.focus();
        }

        function step(months) {
            var base = new Date(view.y, view.m - 1 + months, 1);
            view = { y: base.getFullYear(), m: base.getMonth() + 1 };
            render();
        }

        function moveActive(days) {
            var base = active ? new Date(active.y, active.m - 1, active.d) : new Date(view.y, view.m - 1, 1);
            base.setDate(base.getDate() + days);
            active = { y: base.getFullYear(), m: base.getMonth() + 1, d: base.getDate() };
            view = { y: active.y, m: active.m };
            render();
            focusActive();
        }

        display.addEventListener('click', function () { wrap.classList.contains('is-open') ? close() : open(); });
        display.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') { e.preventDefault(); open(); }
        });

        pop.addEventListener('click', function (e) {
            var nav = e.target.closest('.cdate__nav');
            if (nav) { step(+nav.dataset.step); focusActive(); return; }
            var day = e.target.closest('.cdate__day');
            if (day) { choose(day.dataset.iso); }
        });

        pop.addEventListener('keydown', function (e) {
            switch (e.key) {
                case 'ArrowLeft': e.preventDefault(); moveActive(-1); break;
                case 'ArrowRight': e.preventDefault(); moveActive(1); break;
                case 'ArrowUp': e.preventDefault(); moveActive(-7); break;
                case 'ArrowDown': e.preventDefault(); moveActive(7); break;
                case 'PageUp': e.preventDefault(); step(-1); focusActive(); break;
                case 'PageDown': e.preventDefault(); step(1); focusActive(); break;
                case 'Enter': case ' ':
                    e.preventDefault();
                    if (active) { choose(toIso(active.y, active.m, active.d)); }
                    break;
                case 'Escape': e.preventDefault(); close(); display.focus(); break;
            }
        });

        // Monta el UI ahora que todo está construido.
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(display);
        wrap.appendChild(input);
        wrap.appendChild(pop);
    }

    // Un ÚNICO listener delegado para "clic fuera": si el clic no cae dentro de un .cdate, se cierran
    // todos los calendarios abiertos. Evita acumular un listener en document por cada campo realzado.
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.cdate')) {
            document.querySelectorAll('.cdate.is-open').forEach(function (el) { el.cdateClose(); });
        }
    });

    function init() {
        document.querySelectorAll('input[type="date"]').forEach(function (input) {
            try {
                enhance(input);
            } catch (err) {
                if (window.console) {
                    window.console.warn('cdate: no se pudo realzar un input de fecha', err);
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
