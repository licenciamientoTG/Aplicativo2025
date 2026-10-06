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
    vrCascada();
    vrProvPrecio();
    vrProvLitros();
    vrDiario('total');
    vrDispersion();

    $('#vr-diario-prod').on('click', 'button', function () {
        $(this).addClass('active').siblings().removeClass('active');
        vrDiario($(this).data('prod'));
    });
}

/** Cascada: barras flotantes de la venta al margen ajustado. */
function vrCascada() {
    var c = vrInit('vr-chart-cascada');
    if (!c) return;
    var pasos = vrDatos(c.el, 'data-cascada') || [];
    var base = [], valor = [], acumulado = 0;
    pasos.forEach(function (p) {
        var color;
        if (p.t === 'total') {
            acumulado = p.v;
            base.push(Math.min(0, p.v));
            color = p.v < 0 ? '#dc2626' : '#334155';
        } else {
            var desde = acumulado, hasta = acumulado + p.v;
            acumulado = hasta;
            base.push(Math.min(desde, hasta));
            color = p.v < 0 ? '#dc2626' : '#009559';
        }
        valor.push({ value: Math.abs(p.v), itemStyle: { color: color }, real: p.v });
    });
    c.chart.setOption({
        grid: { left: 80, right: 16, top: 24, bottom: 30 },
        tooltip: {
            trigger: 'axis', axisPointer: { type: 'shadow' },
            formatter: function (p) { var s = p[1]; return '<strong>' + s.name + '</strong><br>' + vrPesos(s.data.real); }
        },
        xAxis: { type: 'category', data: pasos.map(function (p) { return p.l; }), axisLabel: { interval: 0, fontSize: 11 } },
        yAxis: { type: 'value', axisLabel: { formatter: vrMillones } },
        series: [
            { type: 'bar', stack: 'c', data: base, itemStyle: { color: 'transparent' }, emphasis: { disabled: true }, tooltip: { show: false } },
            { type: 'bar', stack: 'c', data: valor, barMaxWidth: 56,
              label: { show: true, position: 'top', fontSize: 11, formatter: function (p) { return vrMillones(p.data.real); } } }
        ]
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

/** Precio de venta vs precio de compra por día, con el margen diario en barras. */
function vrDiario(prod) {
    var el = document.getElementById('vr-chart-diario');
    if (!el || typeof echarts === 'undefined') return;
    var chart = echarts.getInstanceByDom(el) || vrInit('vr-chart-diario').chart;
    var dias = vrDatos(el, 'data-diario') || [];
    var punto = function (d) { return d[prod] || {}; };
    chart.setOption({
        grid: { left: 60, right: 80, top: 36, bottom: 30 },
        legend: { top: 0 },
        tooltip: {
            trigger: 'axis',
            formatter: function (p) {
                var d = dias[p[0].dataIndex], q = punto(d);
                return '<strong>' + d.fecha + '</strong>' +
                    '<br>Venta s/IVA: ' + (q.precio_venta == null ? '—' : '$' + q.precio_venta.toFixed(2) + '/L') +
                    '<br>Compra s/IVA: ' + (q.precio_compra == null ? 'sin descargas con precio' : '$' + q.precio_compra.toFixed(2) + '/L') +
                    '<br>Margen del día: ' + vrPesos(q.margen) +
                    '<br>Litros vendidos: ' + Math.round(q.litros || 0).toLocaleString('es-MX');
            }
        },
        xAxis: { type: 'category', data: dias.map(function (d) { return d.dia; }) },
        yAxis: [
            { type: 'value', scale: true, name: '$/L', axisLabel: { formatter: '${value}' } },
            { type: 'value', name: 'Margen', axisLabel: { formatter: vrMillones }, splitLine: { show: false } }
        ],
        series: [
            { name: 'Margen del día', type: 'bar', yAxisIndex: 1, barMaxWidth: 14,
              data: dias.map(function (d) {
                  var m = punto(d).margen;
                  return m == null ? null : { value: m, itemStyle: { color: m < 0 ? 'rgba(220,38,38,.35)' : 'rgba(0,149,89,.3)' } };
              }) },
            { name: 'Precio de venta s/IVA', type: 'line', symbolSize: 5, connectNulls: true,
              itemStyle: { color: '#009559' }, data: dias.map(function (d) { return punto(d).precio_venta; }) },
            { name: 'Precio de compra s/IVA', type: 'line', symbolSize: 5, connectNulls: true,
              itemStyle: { color: '#0095DA' }, lineStyle: { type: 'dashed' },
              data: dias.map(function (d) { return punto(d).precio_compra; }) }
        ]
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
