/**
 * Gráficas de la sección Rentabilidad del RESUMEN DIRECCIÓN (/merma/ventas).
 * La sección llega por AJAX (merma_ventas.js); al inyectarla se llama
 * initRentabilidad(). Los datos vienen en atributos data-* del parcial
 * views/merma/ventas_rentabilidad.html.
 */
var VR_COLOR_PROD = { maxima: '#009559', super: '#0095DA', diesel: '#7c2d12' };
var VR_NOMBRE_PROD = { maxima: 'Regular', super: 'Premium', diesel: 'Diesel' };
var VR_COLOR_ZONA = { 'MARCA Y PROTS': '#0095DA', 'TSA AGS': '#009559', 'ZONA 3': '#f59e0b' };
var vrCharts = [];

var VR_DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

/** 'YYYY-MM-DD' → nombre del día de la semana (fecha local, sin desfase de zona). */
function vrDiaSemana(fecha) {
    var p = String(fecha).split('-');
    return VR_DIAS_SEMANA[new Date(+p[0], +p[1] - 1, +p[2]).getDay()];
}

function vrDatos(el, attr) {
    try { return JSON.parse(el.getAttribute(attr) || 'null'); } catch (e) { return null; }
}
function vrPesos(v) {
    if (v == null) return '—';
    return (v < 0 ? '−$' : '$') + Math.round(Math.abs(v)).toLocaleString('es-MX');
}
function vrMillones(v) {
    return (v < 0 ? '−' : '') + '$' + (Math.abs(v) / 1e6).toLocaleString('es-MX', { maximumFractionDigits: 1 }) + ' M';
}
function vrInit(id) {
    var el = document.getElementById(id);
    if (!el || typeof echarts === 'undefined') return null;
    var chart = echarts.init(el);
    vrCharts.push(chart);
    return { el: el, chart: chart };
}

function initRentabilidad() {
    vrCharts.forEach(function (c) { c.dispose(); });
    vrCharts = [];
    vrMargenColumna();
    vrProvPrecio();
    vrProvLitros();
    vrEstacionesInit();
    vrDispersion();
}

/**
 * Una sola columna apilada con las piezas del margen ajustado (antes de
 * estímulo, estímulo, descuentos, diferencia de inventario). Las piezas
 * negativas se apilan por debajo de cero; arriba, el total.
 */
function vrMargenColumna() {
    var c = vrInit('vr-chart-margen');
    if (!c) return;
    var piezas = vrDatos(c.el, 'data-piezas') || [];
    var total = parseFloat(c.el.getAttribute('data-total'));
    var maxPos = piezas.reduce(function (s, p) { return s + Math.max(p.v, 0); }, 0);
    c.chart.setOption({
        grid: { left: 70, right: 150, top: 34, bottom: 12 },
        tooltip: { trigger: 'item', formatter: function (p) {
            return p.marker + '<strong>' + p.seriesName + '</strong><br>' + vrPesos(p.value);
        } },
        xAxis: { type: 'category', data: ['Margen ajustado'], axisTick: { show: false },
                 axisLabel: { show: false }, axisLine: { show: false } },
        yAxis: { type: 'value', axisLabel: { formatter: vrMillones }, max: function (v) { return Math.max(v.max, maxPos) * 1.12; } },
        series: piezas.map(function (p, i) {
            return {
                name: p.l, type: 'bar', stack: 'margen', barWidth: '46%',
                itemStyle: { color: p.c },
                data: [p.v],
                label: { show: Math.abs(p.v) >= maxPos * 0.06, formatter: function (x) { return vrMillones(x.value); },
                         color: '#fff', fontSize: 11, fontWeight: 600 }
            };
        }).concat([{
            // Serie vacía solo para rotular el total sobre la columna
            name: 'Total', type: 'bar', stack: 'margen', data: [0], barWidth: '46%', legendHoverLink: false,
            tooltip: { show: false },
            label: { show: true, position: 'top', fontSize: 13, fontWeight: 700, color: '#0f4c75',
                     formatter: function () { return 'Total ' + vrMillones(total); } }
        }]),
        // La serie "Total" no aparece en la leyenda
        legend: { data: piezas.map(function (p) { return p.l; }), orient: 'vertical', right: 0, top: 'middle',
                  itemWidth: 12, itemHeight: 12, textStyle: { fontSize: 11 } }
    });
}

/** $/L sin IVA de cada proveedor, agrupado por producto. */
function vrProvPrecio() {
    var c = vrInit('vr-chart-prov-precio');
    if (!c) return;
    var prov = vrDatos(c.el, 'data-prov') || [];
    var series = ['maxima', 'super', 'diesel'].map(function (fam) {
        return {
            name: VR_NOMBRE_PROD[fam], type: 'bar', barMaxWidth: 22,
            itemStyle: { color: VR_COLOR_PROD[fam], borderRadius: [3, 3, 0, 0] },
            data: prov.map(function (p) { return p[fam] == null ? null : +p[fam].toFixed(3); })
        };
    });
    var valores = [].concat.apply([], series.map(function (s) { return s.data; }))
                    .filter(function (v) { return v != null; });
    c.chart.setOption({
        grid: { left: 50, right: 16, top: 36, bottom: 50 },
        legend: { top: 0 },
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' },
                   valueFormatter: function (v) { return v == null ? '—' : '$' + v.toFixed(2) + '/L'; } },
        xAxis: { type: 'category', data: prov.map(function (p) { return p.n; }),
                 axisLabel: { interval: 0, fontSize: 10, width: 110, overflow: 'break' } },
        // El eje no arranca en cero: la diferencia entre proveedores son
        // centavos sobre ~$20, y desde cero las barras se verían iguales.
        yAxis: { type: 'value', min: valores.length ? Math.floor(Math.min.apply(null, valores) - 1) : null,
                 axisLabel: { formatter: '${value}' } },
        series: series
    });
}

/** Participación de cada proveedor en los litros comprados. */
function vrProvLitros() {
    var c = vrInit('vr-chart-prov-litros');
    if (!c) return;
    var prov = vrDatos(c.el, 'data-prov') || [];
    c.chart.setOption({
        tooltip: { trigger: 'item', formatter: function (p) {
            return '<strong>' + p.name + '</strong><br>' + Math.round(p.value).toLocaleString('es-MX') + ' L (' + p.percent + '%)';
        } },
        color: ['#0095DA', '#009559', '#f59e0b', '#7c2d12', '#64748b', '#a855f7'],
        series: [{
            type: 'pie', radius: ['45%', '72%'], center: ['50%', '52%'],
            label: { formatter: '{d}%', fontSize: 11 },
            data: prov.map(function (p) { return { name: p.n, value: p.v }; })
        }]
    });
}

/**
 * Precio de compra y venta por estación: arriba los precios (venta continua,
 * compra punteada con un punto por descarga), abajo el margen por litro.
 * Un color por estación; las tres series de una estación comparten nombre
 * para que la leyenda las prenda/apague juntas.
 */
var VR_COLORES_EST = ['#0095DA', '#009559', '#f59e0b', '#a855f7', '#dc2626', '#0f4c75'];
var vrPeProd = 'maxima';

function vrEstacionesInit() {
    var el = document.getElementById('vr-chart-estacion');
    if (!el || typeof echarts === 'undefined') return;
    var datos = vrDatos(el, 'data-serie');
    if (!datos || !datos.estaciones.length) return;
    var chart = echarts.init(el);
    vrCharts.push(chart);

    var $sel = $('#vr-pe-estaciones');
    // Por defecto, la estación de mayor venta del mes
    var mayor = datos.estaciones.slice().sort(function (a, b) { return b.litros - a.litros; })[0];
    $sel.val([String(mayor.cod)]);
    if ($.fn.selectpicker) $sel.selectpicker();

    var pintar = function () { vrEstacionesPintar(chart, datos, ($sel.val() || []).map(Number), vrPeProd); };
    // Con bootstrap-select el aviso es changed.bs.select; sin él, el change nativo
    $sel.on($.fn.selectpicker ? 'changed.bs.select' : 'change', pintar);
    $('#vr-pe-prod').on('click', 'button', function () {
        $(this).addClass('active').siblings().removeClass('active');
        vrPeProd = $(this).data('prod');
        pintar();
    });
    pintar();
}

function vrEstacionesPintar(chart, datos, cods, prod) {
    var dias = datos.fechas.map(function (f) {
        var d = vrDiaSemana(f).substring(0, 3);
        return +f.substring(8, 10) + '\n' + d.charAt(0).toUpperCase() + d.slice(1);
    });
    var sel = datos.estaciones.filter(function (e) { return cods.indexOf(e.cod) !== -1 && e.familias[prod]; });
    var fmt = function (v) { return v == null ? '—' : (v < 0 ? '−$' : '$') + Math.abs(v).toFixed(2); };

    var series = [];
    sel.forEach(function (e, i) {
        var color = VR_COLORES_EST[i % VR_COLORES_EST.length];
        var s = e.familias[prod];
        series.push(
            { name: e.nombre, type: 'line', xAxisIndex: 0, yAxisIndex: 0, data: s.v, showSymbol: false,
              lineStyle: { width: 2, color: color }, itemStyle: { color: color }, connectNulls: false, vrTipo: 'venta' },
            { name: e.nombre, type: 'line', xAxisIndex: 0, yAxisIndex: 0, data: s.c, symbol: 'circle', symbolSize: 7,
              connectNulls: true, lineStyle: { width: 1.5, type: 'dashed', color: color }, itemStyle: { color: color }, vrTipo: 'compra' },
            { name: e.nombre, type: 'line', xAxisIndex: 1, yAxisIndex: 1, data: s.m, showSymbol: false,
              lineStyle: { width: 2, color: color }, itemStyle: { color: color },
              areaStyle: sel.length === 1 ? { color: color, opacity: .12 } : undefined, vrTipo: 'margen' }
        );
    });

    chart.setOption({
        color: VR_COLORES_EST,
        legend: { top: 0, data: sel.map(function (e) { return e.nombre; }) },
        axisPointer: { link: [{ xAxisIndex: 'all' }] },
        tooltip: {
            trigger: 'axis',
            formatter: function (p) {
                if (!p.length) return '';
                var i = p[0].dataIndex;
                var html = '<strong>' + datos.fechas[i] + ' (' + vrDiaSemana(datos.fechas[i]) + ')</strong>';
                sel.forEach(function (e, k) {
                    var s = e.familias[prod];
                    html += '<br><span style="display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:5px;background:' +
                            VR_COLORES_EST[k % VR_COLORES_EST.length] + '"></span><strong>' + e.nombre + '</strong>' +
                            '<br>&nbsp;&nbsp;Venta ' + fmt(s.v[i]) + ' · Compra ' + (s.c[i] == null ? 'sin descarga' : fmt(s.c[i])) +
                            ' · <strong>Margen ' + fmt(s.m[i]) + '/L</strong>';
                });
                return html;
            }
        },
        grid: [
            { left: 60, right: 24, top: 40, height: '48%' },
            { left: 60, right: 24, top: '67%', bottom: 46 }
        ],
        xAxis: [
            { type: 'category', gridIndex: 0, data: dias, axisLabel: { show: false }, axisTick: { show: false } },
            { type: 'category', gridIndex: 1, data: dias, axisLabel: { interval: 0, fontSize: 10, lineHeight: 13 } }
        ],
        yAxis: [
            { type: 'value', gridIndex: 0, scale: true, name: 'Precio $/L sin IVA', nameTextStyle: { align: 'left' },
              axisLabel: { formatter: function (v) { return '$' + v.toFixed(2); } } },
            { type: 'value', gridIndex: 1, name: 'Margen $/L', nameTextStyle: { align: 'left' },
              axisLabel: { formatter: function (v) { return '$' + v.toFixed(2); } } }
        ],
        series: series.concat([{
            // Línea de margen cero en la gráfica de abajo
            type: 'line', xAxisIndex: 1, yAxisIndex: 1, data: [], silent: true,
            markLine: { silent: true, symbol: 'none', label: { show: false },
                        lineStyle: { color: '#94a3b8', type: 'dashed' }, data: [{ yAxis: 0 }] }
        }])
    }, true);
}

/** Litros vendidos vs margen por litro, un punto por estación. */
function vrDispersion() {
    var c = vrInit('vr-chart-dispersion');
    if (!c) return;
    var est = vrDatos(c.el, 'data-estaciones') || [];
    var zonas = {};
    est.forEach(function (e) { (zonas[e.z] = zonas[e.z] || []).push(e); });
    var nombres = Object.keys(zonas);
    c.chart.setOption({
        grid: { left: 60, right: 24, top: 36, bottom: 40 },
        legend: { top: 0 },
        tooltip: { formatter: function (p) {
            var e = p.data.e;
            return '<strong>' + e.n + '</strong> (' + e.z + ')<br>' + Math.round(e.l).toLocaleString('es-MX') +
                   ' L · $' + e.m.toFixed(2) + '/L<br>Margen: ' + vrPesos(e.t);
        } },
        xAxis: { type: 'value', name: 'Litros', nameLocation: 'middle', nameGap: 26,
                 axisLabel: { formatter: function (v) { return (v / 1000).toLocaleString('es-MX') + ' mil'; } } },
        yAxis: { type: 'value', name: '$/L', axisLabel: { formatter: '${value}' } },
        series: nombres.map(function (z, i) {
            var s = {
                name: z, type: 'scatter', symbolSize: 12,
                itemStyle: { color: VR_COLOR_ZONA[z] || '#64748b', opacity: .85 },
                data: zonas[z].map(function (e) { return { value: [e.l, e.m], e: e }; })
            };
            // Línea de margen cero, una sola vez
            if (i === 0) {
                s.markLine = { silent: true, symbol: 'none', label: { show: false },
                               lineStyle: { color: '#94a3b8', type: 'dashed' }, data: [{ yAxis: 0 }] };
            }
            return s;
        })
    });
}

// Las gráficas dibujadas mientras su tab estaba oculto quedan en tamaño 0:
// se reajustan al volver a mostrarlo y al cambiar el tamaño de la ventana.
$(window).on('resize', function () { vrCharts.forEach(function (c) { c.resize(); }); });
$(document).on('shown.bs.tab', '#tab-resumen-link', function () {
    vrCharts.forEach(function (c) { c.resize(); });
    var principal = document.getElementById('vd-chart-diario');
    if (principal && typeof echarts !== 'undefined' && echarts.getInstanceByDom(principal)) {
        echarts.getInstanceByDom(principal).resize();
    }
});
