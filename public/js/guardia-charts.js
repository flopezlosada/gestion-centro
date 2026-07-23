/* Gráficos del panel de estadísticas de guardias, con ApexCharts.
 *
 * Lee los datos de bloques <script type="application/json"> incrustados por la plantilla y los colores
 * de los tokens CSS del tema (para respetar claro/oscuro sin duplicar la paleta en JS). Monta:
 *   - #chart-evolution : líneas, evolución mensual. La plantilla manda un "spec" {kind, categories,
 *     series}: kind='status' (un periodo → cubiertas/sin asignar/incidencias, paleta de estado) o
 *     kind='periods' (varios → una línea por periodo, paleta categórica).
 *   - #chart-comparison: barras apiladas por estado, una por periodo comparado (paleta de estado).
 *
 * Los colores próximos se refuerzan con codificación redundante: cada serie de líneas lleva además
 * trazo y marcador distintos, y la leyenda/tooltip siempre las nombran. En las barras las separa el
 * orden apilado y un filete del color de fondo.
 *
 * Reacciona al cambio de tema (MutationObserver sobre data-theme en <html>) redibujando con los nuevos
 * tokens. Si ApexCharts no está cargado o no hay datos, no hace nada (degradación limpia). */
(function () {
    'use strict';

    if ('undefined' === typeof window.ApexCharts) {
        return;
    }

    /** Valor de un token CSS del tema activo (p. ej. '--success'). */
    function token(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    /** Datos JSON de un <script type="application/json"> por id, o null si falta / no parsea. */
    function readJson(id) {
        var el = document.getElementById(id);
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (error) {
            return null;
        }
    }

    /** La paleta del tema activo, leída de los tokens CSS. */
    function palette() {
        return {
            // Estados (cubiertas / sin asignar / incidencias): paleta semántica de la app.
            status: [token('--success'), token('--warning'), token('--danger')],
            // Categórica (una serie por periodo), en orden fijo y validada: teal, ámbar, azul, terracota.
            categorical: [token('--accent'), token('--warning'), token('--review'), token('--danger')],
            axis: token('--text-muted'),
            grid: token('--border'),
            surface: token('--surface'),
            ink: token('--text'),
        };
    }

    /** Opciones comunes a ambos gráficos para un tema dado. */
    function common(pal, isDark) {
        return {
            chart: {
                fontFamily: 'inherit',
                foreColor: pal.axis,
                background: 'transparent',
                toolbar: { show: false },
                animations: { enabled: true, speed: 320 },
                parentHeightOffset: 0,
            },
            theme: { mode: isDark ? 'dark' : 'light' },
            grid: { borderColor: pal.grid, strokeDashArray: 3, padding: { left: 12, right: 12 } },
            dataLabels: { enabled: false },
            legend: { position: 'top', horizontalAlign: 'left', fontSize: '13px', labels: { colors: pal.ink }, markers: { size: 6 } },
            tooltip: { theme: isDark ? 'dark' : 'light' },
            states: { active: { filter: { type: 'none' } } },
        };
    }

    /** Eje Y de la evolución, con el título del dato mostrado (Ausencias / Cubiertas / …). */
    function evolutionYAxis(title) {
        return { min: 0, forceNiceScale: true, title: { text: title }, labels: { formatter: function (value) { return Math.round(value); } } };
    }

    /** Base de un gráfico de líneas de evolución (trazo y marcador distintos por serie). */
    function evolutionBase(pal, isDark, colors) {
        var options = common(pal, isDark);
        options.chart.type = 'line';
        options.chart.height = 320;
        options.colors = colors;
        options.stroke = { width: 2, curve: 'smooth', dashArray: [0, 5, 2, 8] };
        options.markers = { size: 5, strokeWidth: 0, shape: ['circle', 'square', 'triangle', 'diamond'], hover: { size: 7 } };
        return options;
    }

    /** Evolución de un solo periodo: 3 líneas de estado (cubiertas / sin asignar / incidencias). */
    function statusEvolution(mount, spec, pal, isDark) {
        var options = evolutionBase(pal, isDark, pal.status);
        options.series = spec.series;
        options.xaxis = { categories: spec.categories, axisBorder: { color: pal.grid }, axisTicks: { color: pal.grid } };
        options.yaxis = evolutionYAxis('Ausencias');
        var chart = new window.ApexCharts(mount, options);
        chart.render();
        return chart;
    }

    /** Las series de un periodo para un dato concreto (una por periodo). */
    function periodSeries(spec, metric) {
        return spec.periods.map(function (p) { return { name: p.name, data: p.values[metric] }; });
    }

    /** Reconstruye la tabla de datos de la evolución comparada según el dato elegido. */
    function renderPeriodTable(spec, metric) {
        var el = document.getElementById('evolution-table');
        if (!el) {
            return;
        }
        var head = '<thead><tr><th>Mes</th>';
        spec.periods.forEach(function (p) { head += '<th class="mono">' + escapeHtml(p.name) + '</th>'; });
        head += '</tr></thead>';
        var body = '<tbody>';
        spec.categories.forEach(function (month, i) {
            body += '<tr><td>' + escapeHtml(month) + '</td>';
            spec.periods.forEach(function (p) {
                var v = p.values[metric][i];
                body += '<td class="mono">' + (null === v || undefined === v ? '—' : v) + '</td>';
            });
            body += '</tr>';
        });
        el.innerHTML = '<table>' + head + body + '</tbody></table>';
    }

    /** Texto seguro para insertar en HTML. */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /** Evolución comparada: una línea por periodo del dato elegido; un selector cambia el dato en vivo. */
    function periodsEvolution(mount, spec, pal, isDark) {
        var select = document.getElementById('evolution-metric');
        var metric = (select && select.value) || spec.defaultMetric;

        var options = evolutionBase(pal, isDark, pal.categorical);
        options.series = periodSeries(spec, metric);
        options.xaxis = { categories: spec.categories, axisBorder: { color: pal.grid }, axisTicks: { color: pal.grid } };
        options.yaxis = evolutionYAxis(spec.metrics[metric]);
        var chart = new window.ApexCharts(mount, options);
        chart.render();
        renderPeriodTable(spec, metric);

        // Cambiar el dato actualiza la serie, el título del eje y la tabla — sin recargar. Se engancha una
        // sola vez (el redibujado por tema reutiliza el <select> ya enganchado).
        if (select && '1' !== select.dataset.metricWired) {
            select.dataset.metricWired = '1';
            select.addEventListener('change', function () {
                var live = mounted['chart-evolution'];
                var current = readJson('guardia-evolution-data');
                if (!live || !current || 'periods' !== current.kind) {
                    return;
                }
                live.updateOptions({ series: periodSeries(current, select.value), yaxis: evolutionYAxis(current.metrics[select.value]) }, false, true);
                renderPeriodTable(current, select.value);
            });
        }
        return chart;
    }

    /** Gráfico de barras apiladas de la comparativa por periodo (estados apilados). */
    function comparison(mount, rows, pal, isDark) {
        var options = common(pal, isDark);
        options.chart.type = 'bar';
        options.chart.height = 320;
        options.chart.stacked = true;
        options.colors = pal.status;
        options.series = [
            { name: 'Cubiertas', data: rows.map(function (r) { return r.covered; }) },
            { name: 'Sin asignar', data: rows.map(function (r) { return r.unassigned; }) },
            { name: 'Incidencias', data: rows.map(function (r) { return r.incidents; }) },
        ];
        options.xaxis = { categories: rows.map(function (r) { return r.label; }), axisBorder: { color: pal.grid }, axisTicks: { color: pal.grid } };
        options.yaxis = { min: 0, labels: { formatter: function (value) { return Math.round(value); } } };
        options.plotOptions = { bar: { columnWidth: rows.length < 3 ? '30%' : '55%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } };
        options.stroke = { show: true, width: 2, colors: [pal.surface] };
        var chart = new window.ApexCharts(mount, options);
        chart.render();
        return chart;
    }

    // Gráficos ya montados, por id del contenedor. Sirve para no montarlos dos veces y para destruirlos
    // al cambiar de tema.
    var mounted = {};

    /** Monta los gráficos cuyo contenedor esté VISIBLE y aún no montado (los ocultos esperan a su pestaña). */
    function renderVisible() {
        var isDark = 'dark' === document.documentElement.getAttribute('data-theme');
        var pal = palette();

        var evoMount = document.getElementById('chart-evolution');
        var spec = readJson('guardia-evolution-data');
        if (evoMount && !mounted['chart-evolution'] && null !== evoMount.offsetParent && spec && spec.categories && spec.categories.length > 1) {
            if ('periods' === spec.kind && spec.periods && spec.periods.length) {
                mounted['chart-evolution'] = periodsEvolution(evoMount, spec, pal, isDark);
            } else if ('status' === spec.kind && spec.series && spec.series.length) {
                mounted['chart-evolution'] = statusEvolution(evoMount, spec, pal, isDark);
            }
        }

        var cmpMount = document.getElementById('chart-comparison');
        var kpis = readJson('guardia-kpis-data');
        if (cmpMount && !mounted['chart-comparison'] && null !== cmpMount.offsetParent && kpis && kpis.length) {
            mounted['chart-comparison'] = comparison(cmpMount, kpis, pal, isDark);
        }
    }

    /** Tras conmutar el tema: destruye lo montado y vuelve a montar lo que esté visible (el resto, al mostrarse). */
    function rebuildForTheme() {
        Object.keys(mounted).forEach(function (id) { mounted[id].destroy(); });
        mounted = {};
        renderVisible();
    }

    // Render PEREZOSO: cada gráfico se monta cuando su pestaña se muestra (contenedor con ancho real).
    // El evento lo emite tabs.js, también para la pestaña activa inicial. Se engancha en cuanto carga el
    // script (antes de que tabs.js dispare), no en DOMContentLoaded.
    document.addEventListener('tab:shown', renderVisible);

    function init() {
        renderVisible(); // gráficos ya visibles al cargar (pestaña activa inicial, o página sin pestañas)
        new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if ('data-theme' === mutations[i].attributeName) {
                    rebuildForTheme();
                    return;
                }
            }
        }).observe(document.documentElement, { attributes: true });
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
