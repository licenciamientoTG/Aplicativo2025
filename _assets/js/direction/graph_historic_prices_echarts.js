// Para revertir: cambia USE_ECHARTS = false en la vista

function destroyEChart(domId) {
    var instance = echarts.getInstanceByDom(document.getElementById(domId));
    if (instance) instance.dispose();
}

function buildEChartOption(data, isMes) {
    var allFechas = data.length > 0 ? data[0].fechas : [];

    var series = data.map(function(grupo) {
        var values = grupo.precios.map(function(p) {
            return (p === 0 || p === null) ? null : p;
        });
        return {
            name: grupo.label,
            type: 'line',
            data: values,
            lineStyle: { width: grupo.label === 'TOTALGAS' ? 3 : 1.5 },
            symbolSize: grupo.label === 'TOTALGAS' ? 6 : 4,
            emphasis: { focus: 'series', lineStyle: { width: 3 } },
            smooth: 0.1,
            connectNulls: true,
        };
    });

    var colors = data.map(function(g) { return getColorByLabel(g.label); });

    return {
        color: colors,
        tooltip: {
            trigger: 'axis',
            axisPointer: { type: 'cross' },
            formatter: function(params) {
                var lines = '<b>' + params[0].axisValueLabel + '</b><br/>';
                var byPrice = {};
                params.forEach(function(p) {
                    if (p.value === null || p.value === undefined) return;
                    var key = parseFloat(p.value).toFixed(2);
                    if (!byPrice[key]) byPrice[key] = [];
                    byPrice[key].push(p.marker + ' ' + p.seriesName);
                });
                Object.keys(byPrice).sort(function(a, b) { return b - a; }).forEach(function(precio) {
                    lines += '<b>$' + precio + '</b>: ' + byPrice[precio].join(', ') + '<br/>';
                });
                return lines;
            }
        },
        legend: {
            type: 'scroll',
            orient: 'horizontal',
            bottom: 40,
        },
        grid: { left: 60, right: 20, top: 30, bottom: 100 },
        xAxis: {
            type: 'category',
            data: allFechas,
            axisLabel: { rotate: 45, fontSize: 11 },
        },
        yAxis: {
            type: 'value',
            scale: true,
            axisLabel: { formatter: '${value}' },
            splitLine: { lineStyle: { type: 'dashed' } },
        },
        dataZoom: [
            { type: 'inside', xAxisIndex: 0 },
            { type: 'slider', xAxisIndex: 0, bottom: 10, height: 20 },
        ],
        series: series,
    };
}

async function graph_week_echarts(domId, product) {
    var Id_plaza  = document.getElementById('plaza_id').value;
    var fromDate  = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    try {
        var response = await fetch('/direction/week_graph', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'include',
            body: 'product=' + product + '&Id_plaza=' + Id_plaza + '&fromDate=' + fromDate + '&untilDate=' + untilDate
        });
        if (!response.ok) throw new Error('Network error ' + response.status);
        var data = await response.json();
        if (!data || data.length === 0) return;

        destroyEChart(domId);
        var chart = echarts.init(document.getElementById(domId));
        chart.setOption(buildEChartOption(data, false));
        window.addEventListener('resize', function() { chart.resize(); });
    } catch (e) {
        console.error('graph_week_echarts [' + domId + ']:', e);
    }
}

async function graph_month_echarts(domId, product) {
    var Id_plaza  = document.getElementById('plaza_id').value;
    var fromDate  = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    try {
        var response = await fetch('/direction/graph_month', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'include',
            body: 'product=' + product + '&Id_plaza=' + Id_plaza + '&fromDate=' + fromDate + '&untilDate=' + untilDate
        });
        if (!response.ok) throw new Error('Network error ' + response.status);
        var rawData = await response.json();
        if (!rawData || rawData.length === 0) return;

        // normalizar: construir fechas desde year_num + month_num
        var data = rawData.map(function(grupo) {
            var fechas = grupo.year_num.map(function(year, i) {
                return year + '-' + String(grupo.month_num[i]).padStart(2, '0');
            });
            return { label: grupo.label, fechas: fechas, precios: grupo.precios };
        });

        destroyEChart(domId);
        var chart = echarts.init(document.getElementById(domId));
        chart.setOption(buildEChartOption(data, true));
        window.addEventListener('resize', function() { chart.resize(); });
    } catch (e) {
        console.error('graph_month_echarts [' + domId + ']:', e);
    }
}
