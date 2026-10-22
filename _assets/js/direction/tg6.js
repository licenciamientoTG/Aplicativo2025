console.log('tg6');

// Gráfica Vta vs Meta del histórico: sigue el año elegido en #historic_year
let tg6VtaChart = null;
function tg6LoadVtaChart() {
    const canvas = document.getElementById('vta_vs_meta_canva');
    if (canvas && typeof Chart !== 'undefined') {
        vta_vs_meta_canvas(canvas.getContext('2d'));
    }
}
$(document).ready(tg6LoadVtaChart);

async function credit_debit_product_table(){
    if ($.fn.DataTable.isDataTable('#credit_debit_product_table')) {
        $('#credit_debit_product_table').DataTable().destroy();
        $('#credit_debit_product_table thead .filter').remove();
       
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;
    var tipo = document.getElementById('tipo').value;

    if (tipo == 0) {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una fecha y un tipo de producto.</p>
            </div>`
        );
        return;
        
    }

    $('#credit_debit_product_table thead').prepend($('#credit_debit_product_table thead tr').clone().addClass('filter'));
    $('#credit_debit_product_table thead tr.filter th').each(function (index) {
        col = $('#credit_debit_product_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#credit_debit_product_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#credit_debit_product_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let credit_debit_product_table =$('#credit_debit_product_table').DataTable({
        order: [0, "asc"],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        // processing: true,  // Agregar esta línea
        // serverSide: true,  // Agregar esta línea
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-success',
                text: ' Excel'
            },
        ],
        ajax: {
            method: 'POST',
            data: {
                'fromDate':fromDate,
                'untilDate':untilDate,
                'tipo':tipo,
            },
            url: routeUrl('direction.credit_debit_product_table'),
            timeout: 600000, 
            error: function() {
                $('#credit_debit_product_table').waitMe('hide');
                $('#card_table').removeClass('loading');

                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                    </div>`
                );

            },
            beforeSend: function() {
                $('.table-responsive').removeClass('d-none');
                $('#alert_credit_debit_product_table').addClass('d-none');
                $('#card_table').addClass('loading');
            }
        },
        columns: [
            { data: 'CodigoCliente', className: 'text-nowrap' },
            { data: 'Cliente', className: 'text-nowrap' },
            { data: 'Tipo' },
            { data: 'Diesel Automotriz', render: $.fn.dataTable.render.number(',', '.', 2), className: 'text-end' },
            { data: 'T-Maxima Regular', render: $.fn.dataTable.render.number(',', '.', 2), className: 'text-end' },
            { data: 'T-Super Premium', render: $.fn.dataTable.render.number(',', '.', 2), className: 'text-end' },
            { data: 'Total Litros', render: $.fn.dataTable.render.number(',', '.', 2), className: 'text-end' }
        ],
        rowId: 'CodigoCliente',
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            $('#card_table').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();

            // Función para sumar valores en una columna
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ? i : 0;
            };
        
            // Lista de columnas a sumar (desde 'can' en adelante)
            var columnIndexes = [9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

            api.columns().every(function (index) {
                if (index > 2  ) { // Desde la tercera columna en adelante
                    // Sumatoria de los datos filtrados (página actual)
                    var filteredSum = api
                        .column(index, { page: 'current' }) // Solo datos visibles (filtrados)
                        .data()
                        .reduce((a, b) => intVal(a) + intVal(b), 0);
            
                    // Sumatoria de todos los datos (incluyendo no visibles)
                    var totalSum = api
                        .column(index, { page: 'all' }) // Todos los datos
                        .data()
                        .reduce((a, b) => intVal(a) + intVal(b), 0);
            
                    // Actualizar el footer con ambas sumatorias
                    var footer = $(api.column(index).footer());
                    footer.html(`
                        <div>${filteredSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                        <div>Total: ${totalSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                    `);
                }
            });
        
          
        }
    });
}


// Meses visibles en las tablas de consumo: por defecto el año en curso (ene -> mes actual)
function tg6MonthKey(key) {
    const m = /^(\d{4})_(\d{1,2})$/.exec(key);
    return m ? (parseInt(m[1], 10) * 12 + parseInt(m[2], 10)) : null;
}
function tg6MonthInRange(key, from, to) {
    const k = tg6MonthKey(key);
    return k !== null && k >= tg6MonthKey(from) && k <= tg6MonthKey(to);
}
function tg6DefaultRange(months) {
    const keys = months.map(m => m.formatted).sort((a, b) => tg6MonthKey(a) - tg6MonthKey(b));
    const last = keys[keys.length - 1];
    return { from: last.split('_')[0] + '_1', to: last };
}
function tg6CurrentRange() {
    const from = $('.tg6-month-from').first().val();
    const to = $('.tg6-month-to').first().val();
    return (from && to) ? { from: from, to: to } : tg6DefaultRange(months);
}
// Muestra/oculta las columnas de meses de las tablas ya creadas según el rango elegido
function tg6ApplyMonthRange() {
    const range = tg6CurrentRange();
    TG6_TABLES.forEach(function (sel) {
        if (!$.fn.DataTable.isDataTable(sel)) return;
        const table = $(sel).DataTable();
        table.columns().every(function () {
            const key = this.dataSrc();
            if (tg6MonthKey(key) !== null) {
                this.visible(tg6MonthInRange(key, range.from, range.to), false);
            }
        });
        table.columns.adjust();
        try { table.fixedColumns().relayout(); } catch (e) {}
        table.draw(false);
    });
}
$(document).on('change', '.tg6-month-from, .tg6-month-to', function () {
    const value = $(this).val();
    if ($(this).hasClass('tg6-month-from')) {
        $('.tg6-month-from').val(value);
        if (tg6MonthKey(value) > tg6MonthKey($('.tg6-month-to').first().val())) { $('.tg6-month-to').val(value); }
    } else {
        $('.tg6-month-to').val(value);
        if (tg6MonthKey(value) < tg6MonthKey($('.tg6-month-from').first().val())) { $('.tg6-month-from').val(value); }
    }
    tg6ApplyMonthRange();
});

// Atajos de rango (año en curso / últimos 12 / últimos 6), aplican a las 3 tablas
$(document).on('click', '[data-tg6-range]', function () {
    const keys = months.map(m => m.formatted).sort((a, b) => tg6MonthKey(a) - tg6MonthKey(b));
    const last = keys[keys.length - 1];
    const n = { '12m': 12, '6m': 6 }[$(this).data('tg6-range')];
    const from = n ? keys[Math.max(keys.length - n, 0)] : last.split('_')[0] + '_1';
    $('.tg6-month-from').val(from);
    $('.tg6-month-to').val(last);
    tg6ApplyMonthRange();
});

const TG6_TABLES = ['#comsumption_credit_count_table', '#comsumption_credit_client_table', '#comsumption_debit_table'];
const tg6Num = $.fn.dataTable.render.number(',', '.', 0);
const tg6Nf = new Intl.NumberFormat('es-MX', { maximumFractionDigits: 0 });
const tg6StatusFilter = {};   // tabla -> 'Verde' | 'Naranja' | 'Rojo' | null
const tg6RiskFirst = {};      // tabla -> true si se ordena por riesgo

// Variación roja si es muy negativa; sin color de fondo para el resto
function tg6StyleRow(row, data) {
    if (!data['max_label'] && data['pro_vs_max'] <= -30) {
        $('td.col-provs', row).addClass('table-danger');
    }
}

// Filtro por estatus del resumen Verde / Naranja / Rojo
$.fn.dataTable.ext.search.push(function (settings, searchData, index, rowData) {
    const active = tg6StatusFilter['#' + (settings.nTable && settings.nTable.id)];
    return !active || (rowData && rowData.estatus === active);
});

// Resumen arriba de la tabla: botones que filtran, % en verde y barra contra el objetivo de 95%
const tg6Meta = {};           // tabla -> meta del mes en curso { year, month, cantidad }
function tg6RenderStatus(tableSel, rows) {
    const total = rows.length;
    const count = (e) => rows.filter(r => r.estatus === e).length;
    const active = tg6StatusFilter[tableSel] || null;
    const btn = (est, cls, icon) =>
        `<button type="button" class="btn btn-outline-${cls} ${active === est ? 'active' : ''}" data-status="${est}" data-table="${tableSel}" aria-pressed="${active === est}">` +
        `<i class="fas ${icon}" aria-hidden="true"></i> ${est} ${count(est)}</button>`;
    // Avance contra la meta del mes en curso: consumo acumulado del mes y proyección al cierre
    const meta = tg6Meta[tableSel];
    let goal = '<span class="tg6-muted">Sin meta cargada para este mes</span>';
    if (meta && meta.cantidad > 0) {
        const key = meta.year + '_' + meta.month;
        const consumed = rows.reduce((a, r) => a + (parseFloat(r[key]) || 0), 0);
        const projected = rows.reduce((a, r) => a + (parseFloat(r.prediction) || 0), 0);
        const pct = consumed * 100 / meta.cantidad;
        const pctProj = projected * 100 / meta.cantidad;
        goal = `<span class="tg6-muted">Meta del mes: <strong>${tg6Nf.format(meta.cantidad)}</strong> lts · Consumo <strong>${tg6Nf.format(consumed)}</strong> ` +
            `(<strong>${pct.toFixed(1)}%</strong>) · Proyección al cierre <strong>${pctProj.toFixed(1)}%</strong></span>` +
            `<div class="progress" style="height:10px;width:220px" title="Consumo ${pct.toFixed(1)}% de la meta; proyección ${pctProj.toFixed(1)}%">` +
            `<div class="progress-bar" role="progressbar" style="width:${Math.min(pct, 100)}%;background:var(--tg6-accent,#0d6efd)" aria-valuenow="${pct.toFixed(1)}" aria-valuemin="0" aria-valuemax="100"></div></div>`;
    }
    $('.tg6-status[data-for="' + tableSel + '"]').html(
        `<div class="d-flex flex-wrap align-items-center gap-2">` +
        `<div class="btn-group btn-group-sm" role="group" aria-label="Filtrar por estatus">` +
        btn('Verde', 'success', 'fa-check-circle') + btn('Naranja', 'warning', 'fa-exclamation-triangle') + btn('Rojo', 'danger', 'fa-times-circle') +
        `</div>` +
        `<button type="button" class="btn btn-sm btn-outline-secondary ${tg6RiskFirst[tableSel] ? 'active' : ''}" data-risk="${tableSel}" aria-pressed="${!!tg6RiskFirst[tableSel]}"><i class="fas fa-sort-amount-up" aria-hidden="true"></i> Riesgo primero</button>` +
        `<span class="tg6-muted">${total} clientes</span>` + goal +
        `</div>`
    );
}
$(document).on('xhr.dt', function (e, settings, json) {
    const id = settings.nTable && settings.nTable.id;
    if (json && json.data && TG6_TABLES.indexOf('#' + id) !== -1) {
        tg6Meta['#' + id] = json.meta || null;
        tg6RenderStatus('#' + id, json.data);
    }
});
$(document).on('click', '.tg6-status [data-status]', function () {
    const sel = $(this).data('table'), est = $(this).data('status');
    tg6StatusFilter[sel] = (tg6StatusFilter[sel] === est) ? null : est;
    const table = $(sel).DataTable();
    tg6RenderStatus(sel, table.ajax.json().data);
    table.draw();
});
$(document).on('click', '.tg6-status [data-risk]', function () {
    const sel = $(this).data('risk');
    const table = $(sel).DataTable();
    const n = table.columns().count();
    tg6RiskFirst[sel] = !tg6RiskFirst[sel];
    // riesgo: menor cumplimiento primero y, a igualdad, el de mayor volumen; normal: mayor consumo
    table.order(tg6RiskFirst[sel] ? [[n - 2, 'asc'], [n - 5, 'desc']] : [[n - 5, 'desc']]).draw();
    tg6RenderStatus(sel, table.ajax.json().data);
});
$(document).on('click', '.tg6-retry', function () {
    $($(this).data('table')).DataTable().ajax.reload();
});

// Suma del mes (solo filas visibles tras buscar/filtrar) arriba y el nombre del mes abajo
function tg6SetHeaderSum(column, total, label) {
    const $h = $(column.header());
    if ($h.data('base') === undefined) { $h.data('base', $h.html()); }
    const title = label || 'Total del mes (filas visibles)';
    $h.html('<div class="tg6-month-sum" title="' + title + '">' + total + '</div><div class="fw-bold">' + $h.data('base') + '</div>');
}

async function dynamicColumns(months,target){
    // Cre Consumo Cliente agrupa por RFC: no existe un código de cliente ni un asesor únicos
    const byRfc = (target == '#CreditClient');
    const nowKey = new Date().getFullYear() * 12 + new Date().getMonth() + 1;
    var Columns = [
        { 'data': 'CodigoCliente', 'className': 'text-end', 'visible': !byRfc  },
        {
            'data': 'Cliente2',
            'className': 'text-nowrap',
            'searchable': true,
            'render': function (data, type, row) {
                return `<span class="tg6-name" title="${row.Cliente}">${data}</span>`;
            }
        },
        {
            'data': 'Cliente',
            'visible': false,
            'searchable': true
        },
        { 'data': 'nombre_asesor', 'className': 'text-start text-nowrap', 'visible': !byRfc  },
        { 'data': 'lognew', 'className': 'text-start text-nowrap tg6-group-end',
            'render': function (data, type) {
                // clientes de menos de 6 meses: se entiende por qué aparecen como "Nuevo" o "Sin Consumo"
                const m = /^(\d{4})-(\d{1,2})$/.exec(data || '');
                if (type === 'display' && m && nowKey - (parseInt(m[1], 10) * 12 + parseInt(m[2], 10)) < 6) {
                    return data + ' <span class="badge bg-light text-dark border">reciente</span>';
                }
                return data;
            }
        },
    ];
    const range = tg6DefaultRange(months);
    months.forEach(function(process) {
        Columns.push({ 'data': process.formatted,
                            'className': 'text-end',
                            'render': function (d, type) {
                                if (type !== 'display') return d;
                                return (+d === 0) ? '<span class="tg6-zero">–</span>' : tg6Num.display(d);
                            },
                            searchable: false,
                            'visible': tg6MonthInRange(process.formatted, range.from, range.to),
                        });
    });
    Columns.push( { 'data': 'MaxValue', 'className': 'text-nowrap text-end tg6-group-start',
        'render': function (data, type, row) {
            // Texto "Sin Consumo" / "Nuevo" solo al mostrar; para ordenar se usa el número
            if (type !== 'display') return data;
            return row.max_label ? '<span class="tg6-muted">' + row.max_label + '</span>' : tg6Num.display(data);
        }
    });
    Columns.push( { 'data': 'prediction', 'className': 'text-nowrap text-end',
        'render': function (data, type) { return type === 'display' ? tg6Num.display(data) : data; }
    });
    Columns.push( { 'data': 'pro_vs_max', 'className': 'text-end col-provs',
        'render': function (data, type, row) {
            if (type !== 'display') return data;
            if (row.max_label) return '<span class="tg6-muted">—</span>';      // sin base de comparación: no es una alarma
            return data < 0 ? `<span class="text-danger fw-bold">▼ ${data}%</span>` : `${data > 0 ? '▲ ' : ''}${data}%`;
        }
    });
    Columns.push( { 'data': 'cumplimiento', 'className': 'text-end text-nowrap',
        'render': function (data, type, row) {
            // Semáforo: forma y color distintos por estado + texto para lectores de pantalla; para ordenar se usa el número
            if (type !== 'display') return data;
            const map = { Verde: ['fa-check-circle', 'text-success'], Naranja: ['fa-exclamation-triangle', 'tg6-amber'], Rojo: ['fa-times-circle', 'text-danger'] };
            const [icon, color] = map[row.estatus] || ['fa-circle', 'tg6-muted'];
            return `<i class="fas ${icon} ${color} me-1" aria-hidden="true"></i><span class="visually-hidden">${row.estatus || ''}: </span>${data}%`;
        }
    });
    Columns.push( { 'data': 'nombre_zona', 'className': 'text-nowrap' });
    return Columns;
}

// Las 3 tablas de consumo comparten la misma configuración; solo cambian columnas, origen y columnas fijas
function tg6InitConsumptionTable(selector, columns, type, url, leftColumns, rowId) {
    const totalCols = columns.length; // las 5 últimas: Consumo Mayor, Proyección, Proy vs Cons, % Cumpl. y Zona
    const alta = columns.findIndex(c => c.data === 'lognew');
    return $(selector).DataTable({
        order: [[totalCols - 5, "desc"]],
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        ordering: true,
        colReorder: false,
        processing: true,
        dom: '<"top"f>rt<"bottom"lip>',
        language: {
            processing: '<div class="spinner-border spinner-border-sm text-primary me-2"></div>Cargando consumos…',
            search: '',
            searchPlaceholder: 'Buscar cliente, nombre o asesor…',
            emptyTable: 'Sin consumos en el rango elegido',
            zeroRecords: 'Ningún cliente coincide con la búsqueda',
        },
        fixedColumns: { leftColumns: leftColumns },
        ajax: {
            method: 'POST',
            url: url,
            data: { 'type': type },
            dataSrc: 'data',
            error: function (xhr) {
                $(selector).DataTable().processing(false);
                $('.tg6-status[data-for="' + selector + '"]').html(
                    `<div class="alert alert-danger tg6-error py-2 mb-0" role="alert"><span>No se pudo cargar la tabla (error ${xhr.status || 'de red'}).</span>` +
                    `<button type="button" class="btn btn-sm btn-outline-danger tg6-retry" data-table="${selector}">Reintentar</button></div>`
                );
            }
        },
        deferRender: true,
        columns: columns,
        rowId: rowId,
        createdRow: function (row, data) {
            tg6StyleRow(row, data);
        },
        initComplete: function () {
            // etiqueta de la fila de sumas, en el encabezado de la columna "Alta"
            tg6SetHeaderSum(this.api().column(alta), 'Total', 'Suma de cada mes (filas visibles)');
        },
        footerCallback: function () {
            const api = this.api();
            api.columns().every(function () {
                if (tg6MonthKey(this.dataSrc()) !== null) {
                    const values = api.column(this.index(), { search: 'applied' }).data().toArray();
                    const total = values.reduce((a, b) => a + (parseFloat(b) || 0), 0);
                    tg6SetHeaderSum(this, tg6Nf.format(total));
                }
            });
        }
    });
}
async function initConsumptionCreditClientTable(columns) {
    // el código de cliente va oculto: fija solo "Nombre"
    tg6InitConsumptionTable('#comsumption_credit_client_table', columns, '28', routeUrl('direction.comsumption_credit_client_table'), 1, 'Cliente');
}
async function initConsumptionDebitTable(columns) {
    tg6InitConsumptionTable('#comsumption_debit_table', columns, '127', routeUrl('direction.comsumption_credit_count_table'), 2, 'CodigoCliente');
}
function initConsumptionCreditCountTable(columns) {
    tg6InitConsumptionTable('#comsumption_credit_count_table', columns, '28', routeUrl('direction.comsumption_credit_count_table'), 2, 'CodigoCliente');
}


async function vta_vs_meta_canvas(ctx) {
    try {
        const response = await fetch(routeUrl('direction.Vta_vs_meta_canvas'), {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
            body: new URLSearchParams({ year: $('#historic_year').val() || '' }),
        });

        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        const data = await response.json();
        var barChartData = {
            labels: data.months, // Usamos los meses como etiquetas del eje X
            datasets: [
                {
                    label: 'Venta Cre/Deb',
                    data: data.sum_mouth_mun,
                    backgroundColor: 'rgba(54, 162, 235, 1)', // Color de barra
                    borderColor: 'rgba(54, 162, 235)', // Borde de barra
                    borderWidth: 2,
                },
                {
                    label: 'Meta',
                    data: data.sum_meta_mouth,
                    backgroundColor: 'rgba(54, 162, 235 , 0.2)',
                    borderColor: 'rgba(54, 162, 235)',
                    borderWidth: 3,
                },
                {
                    label: 'Credito',
                    data: data.sum_cre_mun,
                    backgroundColor: 'rgba(255, 184, 8, 1)',
                    borderColor: 'rgba(255, 184, 8)',
                    borderWidth: 2,
                },
                {
                    label: 'Meta Credito',
                    data: data.sum_meta_cre,
                    backgroundColor: 'rgba(255, 184, 8, 0.2)',
                    borderColor: 'rgba(255, 184, 8, 1)',
                    borderWidth: 3,
                },
                {
                    label: 'Debito',
                    data: data.sum_deb,
                    backgroundColor: 'rgba(0, 204, 4, 1)',
                    borderColor: 'rgba(0, 204, 4)',
                    borderWidth: 2,
                },
                
               
                {
                    label: 'Meta Debito',
                    data: data.sum_meta_deb,
                    backgroundColor: 'rgba(0, 204, 4, 0.2)',
                    borderColor: 'rgba(0, 204, 4, 1)',
                    borderWidth: 2,
                },
                {
                    label: '% Logro',
                    data: data.percentage_achieved,
                    backgroundColor: '#0066cc',
                    borderColor: '#0066cc',
                    borderWidth: 2,
                    type: 'line',
                    yAxisID: 'right-y-axis',
                    fill: false,
                    order: 0,
                    tension: 0.1
                }
            ],
        }
        var options = {
            layout: {
                padding: {
                    left: 20,
                    right:20,
                    top:0,
                    bottom:0
                }
            },
            title: {
                display: true,
                text: 'VTA vs META - Empresarial',
                fontColor: 'rgb(255, 255, 255)',
                fontSize: 20,
                padding: 30,
            },
            scales: {
                yAxes: [
                    {
                        id: 'left-y-axis', // Escala Y para las barras
                        position: 'left',
                        ticks: {
                            fontColor: 'rgb(255, 255, 255)',
                        }
                    },
                    {
                        id: 'right-y-axis', // Escala Y para la línea de % Logro
                        position: 'right',
                        ticks: {
                            fontColor: 'rgb(255, 255, 255)',
                            beginAtZero: true, // Aseguramos que empiece en 0
                        },
                        gridLines: {
                            drawOnChartArea: false, // No mostramos las líneas de la segunda escala en el área del gráfico
                        }
                    }
                ],
                xAxes: [{
                    ticks: {
                        beginAtZero: true,
                        fontColor: 'rgb(255, 255, 255)',
                    }
                }]
            },
            responsive: true,
            legend: {
                labels: {
                    fontColor: 'rgb(255, 255, 255)',
                },
                position: 'bottom',
                padding: 20,
            },
            tooltips: {
                callbacks: {
                    label: function(tooltipItem, data) {
                        var label = data.datasets[tooltipItem.datasetIndex].label || '';
                        if (label) {
                            label += ': ';
                        }
                        label += tooltipItem.yLabel.toLocaleString('en-US');
                        return label;
                    }
                }
            }
        };
        // var plugin = { datalabels: {
        //         display: function(context) {
        //             // Solo muestra etiquetas para el dataset de tipo "line"
        //             return context.dataset.type === 'line'; 
        //         },
        //         align: 'top', // Coloca las etiquetas arriba de los puntos de la línea
        //         color: 'white', // Cambia el color de las etiquetas
        //         formatter: function(value) {
        //             // Formato con separadores de miles
        //             return value.toLocaleString('en-US');
        //         }
        //     }};
        if (tg6VtaChart) { tg6VtaChart.destroy(); }
        tg6VtaChart = new Chart(ctx, {
            type: 'bar',
            data: barChartData,
            options: options,
            // plugins: [plugin],
        });
    } catch (error) {
        console.error('Error:', error);
    }
}


function valeras_valid_range(fromDate, untilDate) {
    if (!fromDate || !untilDate || fromDate > untilDate) {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione un rango de fechas válido.</p>
            </div>`
        );
        return false;
    }
    return true;
}

async function valeras_table() {
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;
    var codgas = document.getElementById('codgas').value;

    if (!valeras_valid_range(fromDate, untilDate)) return;

    if ($.fn.DataTable.isDataTable('#valeras_table')) {
        $('#valeras_table').DataTable().destroy();
    }
    $('#valeras_table thead tr').empty();
    $('#valeras_table tfoot tr').empty();
    $('#valeras_table tbody').empty();

    $('#card_table .table-responsive').removeClass('d-none');
    $('#alert_valeras_table').addClass('d-none');
    $('#card_table').addClass('loading');
    $('#search_valeras_table').prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Consultando...');

    $.ajax({
        method: 'POST',
        url: routeUrl('direction.valeras_table'),
        data: { 'fromDate': fromDate, 'untilDate': untilDate, 'codgas': codgas },
        dataType: 'json',
        timeout: 600000
    })
    .done(function (response) {
        var columns = [
            { data: 'estacion', title: 'Estación', className: 'text-nowrap' },
            { data: 'denominacion', title: 'Denominación', className: 'text-nowrap' }
        ];
        response.columns.forEach(function (col) {
            columns.push({
                data: col.key,
                title: col.label,
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 2)
            });
        });
        columns.push({
            data: 'total',
            title: 'Total',
            className: 'text-end fw-bold',
            render: $.fn.dataTable.render.number(',', '.', 2)
        });

        columns.forEach(function (col) {
            $('#valeras_table thead tr').append('<th>' + col.title + '</th>');
            $('#valeras_table tfoot tr').append('<th></th>');
        });

        $('#valeras_table').DataTable({
            data: response.data,
            columns: columns,
            order: [],
            scrollY: '700px',
            scrollX: true,
            scrollCollapse: true,
            paging: false,
            dom: '<"top"Bf>rt<"bottom"lip>',
            buttons: [
                {
                    extend: 'excel',
                    className: 'btn btn-success',
                    text: ' Excel',
                    title: 'Valeras_' + fromDate + '_' + untilDate
                },
            ],
            deferRender: true,
            initComplete: function () {
                $('#card_table').removeClass('loading');
            },
            footerCallback: function (row, data, start, end, display) {
                const nf = new Intl.NumberFormat("es-MX", { minimumFractionDigits: 2 });
                var api = this.api();
                var intVal = function (i) {
                    return typeof i === 'string' ?
                        i.replace(/[\$,]/g, '') * 1 :
                        typeof i === 'number' ? i : 0;
                };
                $(api.column(1).footer()).html('Suma');
                api.columns().every(function (index) {
                    if (index > 1) {
                        var total = api
                            .column(index, { page: 'current' })
                            .data()
                            .reduce(function (a, b) { return intVal(a) + intVal(b); }, 0);
                        $(api.column(index).footer()).html(nf.format(total.toFixed(2)));
                    }
                });
            }
        });

        if (!response.data.length) {
            alertify.myAlert(
                `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Atención!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                </div>`
            );
        }
    })
    .fail(function () {
        $('#card_table').removeClass('loading');
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
            </div>`
        );
    })
    .always(function () {
        $('#search_valeras_table').prop('disabled', false).html('Generar Reporte');
    });
}

async function valeras_base_table() {
    var fromDate = document.getElementById('from_base').value;
    var untilDate = document.getElementById('until_base').value;
    var codgas = document.getElementById('codgas_base').value;

    if (!valeras_valid_range(fromDate, untilDate)) return;

    if ($.fn.DataTable.isDataTable('#valeras_base_table')) {
        $('#valeras_base_table').DataTable().destroy();
    }
    $('#valeras_base_table tbody').empty();

    $('#card_table_base .table-responsive').removeClass('d-none');
    $('#alert_valeras_base_table').addClass('d-none');
    $('#card_table_base').addClass('loading');
    $('#search_valeras_base_table').prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Consultando...');

    $.ajax({
        method: 'POST',
        url: routeUrl('direction.valeras_table'),
        data: { 'fromDate': fromDate, 'untilDate': untilDate, 'codgas': codgas },
        dataType: 'json',
        timeout: 600000
    })
    .done(function (response) {
        $('#valeras_base_table').DataTable({
            data: response.base,
            columns: [
                { data: 'estacion', className: 'text-nowrap' },
                { data: 'denominacion', className: 'text-nowrap' },
                { data: 'anio', className: 'text-end' },
                { data: 'mes', className: 'text-end' },
                { data: 'total', className: 'text-end', render: $.fn.dataTable.render.number(',', '.', 2) }
            ],
            order: [],
            scrollY: '700px',
            scrollX: true,
            scrollCollapse: true,
            paging: false,
            dom: '<"top"Bf>rt<"bottom"lip>',
            buttons: [
                {
                    extend: 'excel',
                    className: 'btn btn-success',
                    text: ' Excel',
                    title: 'Valeras_Base_' + fromDate + '_' + untilDate
                },
            ],
            deferRender: true,
            initComplete: function () {
                $('#card_table_base').removeClass('loading');
            },
            footerCallback: function (row, data, start, end, display) {
                const nf = new Intl.NumberFormat("es-MX", { minimumFractionDigits: 2 });
                var api = this.api();
                var intVal = function (i) {
                    return typeof i === 'string' ?
                        i.replace(/[\$,]/g, '') * 1 :
                        typeof i === 'number' ? i : 0;
                };
                $(api.column(1).footer()).html('Suma');
                var total = api
                    .column(4, { page: 'current' })
                    .data()
                    .reduce(function (a, b) { return intVal(a) + intVal(b); }, 0);
                $(api.column(4).footer()).html(nf.format(total.toFixed(2)));
            }
        });

        if (!response.base.length) {
            alertify.myAlert(
                `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Atención!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                </div>`
            );
        }
    })
    .fail(function () {
        $('#card_table_base').removeClass('loading');
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
            </div>`
        );
    })
    .always(function () {
        $('#search_valeras_base_table').prop('disabled', false).html('Generar Reporte');
    });
}

async function valeras_conc_table() {
    var fromDate = document.getElementById('from_conc').value;
    var untilDate = document.getElementById('until_conc').value;
    var codgas = document.getElementById('codgas_conc').value;

    if (!valeras_valid_range(fromDate, untilDate)) return;

    if ($.fn.DataTable.isDataTable('#valeras_conc_table')) {
        $('#valeras_conc_table').DataTable().destroy();
    }
    $('#valeras_conc_table thead tr').empty();
    $('#valeras_conc_table tfoot tr').empty();
    $('#valeras_conc_table tbody').empty();

    $('#card_table_conc .table-responsive').removeClass('d-none');
    $('#alert_valeras_conc_table').addClass('d-none');
    $('#card_table_conc').addClass('loading');
    $('#search_valeras_conc_table').prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Consultando...');

    $.ajax({
        method: 'POST',
        url: routeUrl('direction.valeras_table'),
        data: { 'fromDate': fromDate, 'untilDate': untilDate, 'codgas': codgas },
        dataType: 'json',
        timeout: 600000
    })
    .done(function (response) {
        // Concentrar por valera: sumar todas las estaciones en cada mes y en el total
        var round2 = function (n) { return Math.round(n * 100) / 100; };
        var conc = {};
        var order = []; // conserva el orden de aparición (orden custom del query)
        response.data.forEach(function (row) {
            if (!conc[row.denominacion]) {
                order.push(row.denominacion);
                conc[row.denominacion] = { denominacion: row.denominacion, total: 0 };
                response.columns.forEach(function (col) {
                    conc[row.denominacion][col.key] = 0;
                });
            }
            response.columns.forEach(function (col) {
                conc[row.denominacion][col.key] = round2(conc[row.denominacion][col.key] + (row[col.key] || 0));
            });
            conc[row.denominacion].total = round2(conc[row.denominacion].total + row.total);
        });
        var data = order.map(function (den) { return conc[den]; });

        var columns = [
            { data: 'denominacion', title: 'Denominación', className: 'text-nowrap' }
        ];
        response.columns.forEach(function (col) {
            columns.push({
                data: col.key,
                title: col.label,
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 2)
            });
        });
        columns.push({
            data: 'total',
            title: 'Total',
            className: 'text-end fw-bold',
            render: $.fn.dataTable.render.number(',', '.', 2)
        });

        columns.forEach(function (col) {
            $('#valeras_conc_table thead tr').append('<th>' + col.title + '</th>');
            $('#valeras_conc_table tfoot tr').append('<th></th>');
        });

        $('#valeras_conc_table').DataTable({
            data: data,
            columns: columns,
            order: [],
            scrollY: '700px',
            scrollX: true,
            scrollCollapse: true,
            paging: false,
            dom: '<"top"Bf>rt<"bottom"lip>',
            buttons: [
                {
                    extend: 'excel',
                    className: 'btn btn-success',
                    text: ' Excel',
                    title: 'Valeras_Concentrado_' + fromDate + '_' + untilDate
                },
            ],
            deferRender: true,
            initComplete: function () {
                $('#card_table_conc').removeClass('loading');
            },
            footerCallback: function (row, data, start, end, display) {
                const nf = new Intl.NumberFormat("es-MX", { minimumFractionDigits: 2 });
                var api = this.api();
                var intVal = function (i) {
                    return typeof i === 'string' ?
                        i.replace(/[\$,]/g, '') * 1 :
                        typeof i === 'number' ? i : 0;
                };
                $(api.column(0).footer()).html('Suma');
                api.columns().every(function (index) {
                    if (index > 0) {
                        var total = api
                            .column(index, { page: 'current' })
                            .data()
                            .reduce(function (a, b) { return intVal(a) + intVal(b); }, 0);
                        $(api.column(index).footer()).html(nf.format(total.toFixed(2)));
                    }
                });
            }
        });

        if (!data.length) {
            alertify.myAlert(
                `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Atención!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                </div>`
            );
        }
    })
    .fail(function () {
        $('#card_table_conc').removeClass('loading');
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
            </div>`
        );
    })
    .always(function () {
        $('#search_valeras_conc_table').prop('disabled', false).html('Generar Reporte');
    });
}


// Instancias de las gráficas de valeras (para destruirlas al re-consultar)
var valeras_chart_stations = null;
var valeras_chart_totals = null;
// Datos agregados de la última consulta, para redibujar al filtrar sin volver a consultar
var valeras_graph_cache = null;

var VALERAS_CHART_COLORS = [
    '#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd', '#8c564b',
    '#e377c2', '#7f7f7f', '#bcbd22', '#17becf', '#3959a8', '#cc6600'
];

async function valeras_graph() {
    var fromDate = document.getElementById('from_graph').value;
    var untilDate = document.getElementById('until_graph').value;
    var codgas = document.getElementById('codgas_graph').value;

    if (!valeras_valid_range(fromDate, untilDate)) return;

    $('#alert_valeras_graph').addClass('d-none');
    $('#card_graph').addClass('loading');
    $('#search_valeras_graph').prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Consultando...');

    $.ajax({
        method: 'POST',
        url: routeUrl('direction.valeras_table'),
        data: { 'fromDate': fromDate, 'untilDate': untilDate, 'codgas': codgas },
        dataType: 'json',
        timeout: 600000
    })
    .done(function (response) {
        $('#card_graph').removeClass('loading');

        if (!response.data.length) {
            $('#charts_valeras').addClass('d-none');
            alertify.myAlert(
                `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Atención!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                </div>`
            );
            return;
        }

        // Cada fila de response.data ya trae el total del rango por estación + valera
        var stations = [];
        var valeras = [];
        var byValeraStation = {}; // denominación -> { estación: litros }
        response.data.forEach(function (row) {
            if (stations.indexOf(row.estacion) === -1) stations.push(row.estacion);
            if (valeras.indexOf(row.denominacion) === -1) valeras.push(row.denominacion);
            if (!byValeraStation[row.denominacion]) byValeraStation[row.denominacion] = {};
            byValeraStation[row.denominacion][row.estacion] =
                (byValeraStation[row.denominacion][row.estacion] || 0) + row.total;
        });

        valeras_graph_cache = {
            stations: stations,
            valeras: valeras,
            byValeraStation: byValeraStation,
            fromDate: fromDate,
            untilDate: untilDate
        };

        // Poblar los filtros de la gráfica con todo seleccionado
        var $stationsSelect = $('#graph_filter_stations').empty();
        stations.forEach(function (est) {
            $stationsSelect.append(new Option(est, est, true, true));
        });
        var $valerasSelect = $('#graph_filter_valeras').empty();
        valeras.forEach(function (den) {
            $valerasSelect.append(new Option(den, den, true, true));
        });
        $stationsSelect.selectpicker('refresh');
        $valerasSelect.selectpicker('refresh');

        // Mostrar el contenedor ANTES de crear las gráficas para que el canvas tenga dimensiones
        $('#charts_valeras').removeClass('d-none');

        render_valeras_charts();
    })
    .fail(function () {
        $('#card_graph').removeClass('loading');
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
            </div>`
        );
    })
    .always(function () {
        $('#search_valeras_graph').prop('disabled', false).html('Generar Gráfica');
    });
}

// Dibuja ambas gráficas respetando las estaciones y valeras seleccionadas en los filtros
function render_valeras_charts() {
    if (!valeras_graph_cache) return;

    var cache = valeras_graph_cache;
    var selStations = $('#graph_filter_stations').val() || [];
    var selValeras = $('#graph_filter_valeras').val() || [];

    // Conservar el orden original de la consulta
    var stations = cache.stations.filter(function (est) { return selStations.indexOf(est) !== -1; });
    var valeras = cache.valeras.filter(function (den) { return selValeras.indexOf(den) !== -1; });

    var round2 = function (n) { return Math.round(n * 100) / 100; };
    var fmt = function (n) {
        return Number(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    // Mismo color por valera en ambas gráficas, estable aunque se filtren
    var colorFor = function (den) {
        return VALERAS_CHART_COLORS[cache.valeras.indexOf(den) % VALERAS_CHART_COLORS.length];
    };

    // Gráfica 1: barras agrupadas — litros por estación, un dataset por valera
    var datasets = valeras.map(function (den) {
        var color = colorFor(den);
        return {
            label: den,
            data: stations.map(function (est) {
                return round2(cache.byValeraStation[den][est] || 0);
            }),
            backgroundColor: color,
            borderColor: color,
            borderWidth: 1
        };
    });

    if (valeras_chart_stations) valeras_chart_stations.destroy();
    valeras_chart_stations = new Chart(document.getElementById('valeras_stations_canvas'), {
        type: 'bar',
        data: { labels: stations, datasets: datasets },
        options: {
            responsive: true,
            title: {
                display: true,
                fontSize: 16,
                text: 'Litros por estación y valera (' + cache.fromDate + ' a ' + cache.untilDate + ')'
            },
            legend: { position: 'bottom' },
            scales: {
                xAxes: [{ ticks: { autoSkip: false } }],
                yAxes: [{
                    ticks: {
                        beginAtZero: true,
                        callback: function (value) { return value.toLocaleString('es-MX'); }
                    }
                }]
            },
            tooltips: {
                callbacks: {
                    label: function (tooltipItem, data) {
                        var label = data.datasets[tooltipItem.datasetIndex].label || '';
                        return label + ': ' + fmt(tooltipItem.yLabel) + ' lts';
                    }
                }
            }
        }
    });

    // Gráfica 2: total de litros por valera, sumando solo las estaciones seleccionadas
    var byValera = {};
    valeras.forEach(function (den) {
        byValera[den] = stations.reduce(function (sum, est) {
            return sum + (cache.byValeraStation[den][est] || 0);
        }, 0);
    });
    var valerasSorted = valeras.slice().sort(function (a, b) { return byValera[b] - byValera[a]; });

    if (valeras_chart_totals) valeras_chart_totals.destroy();
    valeras_chart_totals = new Chart(document.getElementById('valeras_totals_canvas'), {
        type: 'horizontalBar',
        data: {
            labels: valerasSorted,
            datasets: [{
                label: 'Litros',
                data: valerasSorted.map(function (den) { return round2(byValera[den]); }),
                backgroundColor: valerasSorted.map(colorFor)
            }]
        },
        options: {
            responsive: true,
            title: { display: true, fontSize: 16, text: 'Total de litros por valera' },
            legend: { display: false },
            scales: {
                xAxes: [{
                    ticks: {
                        beginAtZero: true,
                        callback: function (value) { return value.toLocaleString('es-MX'); }
                    }
                }]
            },
            tooltips: {
                callbacks: {
                    label: function (tooltipItem) {
                        return fmt(tooltipItem.xLabel) + ' lts';
                    }
                }
            }
        }
    });
}


// Descarga un Excel con las 4 pestañas (una por hoja): el año elegido en el histórico y el rango de meses elegido en las tablas
function downloadExcel() {
    $('.tab-pane').addClass('loading');
    const range = tg6CurrentRange();
    const params = new URLSearchParams({ year: $('#historic_year').val() || '', from: range.from, to: range.to });

    fetch(routeUrl('direction.excel_tg6') + '?' + params.toString(), { method: 'GET', credentials: 'include' })
    .then(response => {
        if (!response.ok) throw new Error('Error ' + response.status);
        return response.blob();
    })
    .then(blob => {
        $('.tab-pane').removeClass('loading');
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        const t = new Date();
        const pad = (n) => String(n).padStart(2, '0');
        a.download = `TG6 Clientes Credito-Debito ${pad(t.getDate())}-${pad(t.getMonth() + 1)}-${t.getFullYear()}.xlsx`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        window.URL.revokeObjectURL(url);
    })
    .catch(() => {
        $('.tab-pane').removeClass('loading');
        toastr.error('Error al descargar el archivo');
    });
}




// Sincronizar ConsumoCreditoDebitoMensual (crédito y débito): modal con selector de mes y resumen de cambios
function syncResetModal() {
    $('#sync_step_select').removeClass('d-none');
    $('#sync_step_loading, #sync_step_result').addClass('d-none').empty();
    $('#btn_sync_run').removeClass('d-none').prop('disabled', false);
}

function syncRenderResult(res) {
    const nf = (n) => Number(n).toLocaleString('es-MX', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    const esc = (s) => $('<div>').text(s ?? '').html();
    let html = `<p class="mb-2">Mes <strong>${esc(res.mes)}</strong> sincronizado (${esc(res.desde)} a ${esc(res.hasta)}).</p>`;
    const huboCambios = res.cambios.some(t => t.nuevos || t.eliminados || t.modificados);
    html += huboCambios
        ? '<div class="alert alert-warning py-2">Hubo cambios respecto a lo que estaba guardado.</div>'
        : '<div class="alert alert-success py-2">Sin cambios: la información ya estaba actualizada.</div>';
    res.cambios.forEach(function (t) {
        const delta = t.litros_despues - t.litros_antes;
        html += `<h6 class="mt-3">${esc(t.tipo)}</h6>
            <table class="table table-sm mb-2">
                <tr><td>Clientes</td><td class="text-end">${t.clientes_antes} &rarr; ${t.clientes_despues}</td></tr>
                <tr><td>Litros</td><td class="text-end">${nf(t.litros_antes)} &rarr; ${nf(t.litros_despues)} (${delta >= 0 ? '+' : ''}${nf(delta)})</td></tr>
                <tr><td>Nuevos / Modificados / Eliminados</td><td class="text-end">${t.nuevos} / ${t.modificados} / ${t.eliminados}</td></tr>
            </table>`;
        if (t.detalle.length) {
            html += `<div class="small text-muted">Mayores cambios${t.detalle_total > t.detalle.length ? ` (${t.detalle.length} de ${t.detalle_total})` : ''}:</div>
                <table class="table table-sm table-striped"><thead><tr><th>Cliente</th><th>Nombre</th><th>Cambio</th><th class="text-end">Antes</th><th class="text-end">Después</th></tr></thead><tbody>`;
            t.detalle.forEach(function (d) {
                html += `<tr><td>${esc(d.cliente)}</td><td>${esc(d.nombre)}</td><td>${esc(d.cambio)}</td><td class="text-end">${nf(d.antes)}</td><td class="text-end">${nf(d.despues)}</td></tr>`;
            });
            html += '</tbody></table>';
        }
    });
    $('#sync_step_result').html(html).removeClass('d-none');
}

$(document).on('click', '#btn_sync_consumption', function () {
    syncResetModal();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('sync_consumption_modal')).show();
});

$(document).on('click', '#btn_sync_run', function () {
    const month = $('#sync_month').val();
    if (!month) {
        toastr.error('Selecciona el mes a sincronizar.');
        return;
    }
    $('#sync_step_select').addClass('d-none');
    $('#sync_step_loading').removeClass('d-none');
    $('#btn_sync_run').prop('disabled', true);
    $.ajax({
        method: 'POST',
        url: routeUrl('direction.sync_consumption'),
        data: { month: month },
        dataType: 'json'
    }).done(function (res) {
        $('#sync_step_loading').addClass('d-none');
        if (res.success) {
            $('#btn_sync_run').addClass('d-none');
            syncRenderResult(res);
            ['#comsumption_credit_count_table', '#comsumption_credit_client_table', '#comsumption_debit_table'].forEach(function (sel) {
                if ($.fn.DataTable.isDataTable(sel)) {
                    $(sel).DataTable().ajax.reload(null, false);
                }
            });
        } else {
            toastr.error(res.message || 'No se pudo sincronizar.');
            syncResetModal();
        }
    }).fail(function () {
        toastr.error('Error al sincronizar. Intenta nuevamente.');
        syncResetModal();
    });
});


// Asignar asesor a clientes de crédito o débito: búsqueda + selección múltiple (arranca en crédito)
let advisorSearchTimer = null;

function advisorUpdateSelection() {
    const n = $('#advisor_results .advisor-check:checked').length;
    $('#advisor_selected_count').text(n);
    $('#btn_assign_run').prop('disabled', n === 0);
    const total = $('#advisor_results .advisor-check').length;
    $('#advisor_check_all').prop('checked', total > 0 && n === total);
}

function advisorSearch() {
    const q = $('#advisor_search').val().trim();
    $('#advisor_search_info').text('Buscando...');
    $.ajax({
        method: 'POST',
        url: routeUrl('direction.search_clients'),
        data: { q: q, tipo: $('#advisor_tipo').val() },
        dataType: 'json'
    }).done(function (res) {
        const esc = (s) => $('<div>').text(s ?? '').html();
        const rows = res.data || [];
        $('#advisor_results').html(rows.map(r =>
            `<tr>
                <td><input type="checkbox" class="advisor-check" value="${esc(r.cod)}"></td>
                <td>${esc(r.cod)}</td>
                <td>${esc(r.den)}</td>
                <td>${esc(r.rfc)}</td>
                <td>${esc(r.asesor) || '<span class="text-muted">Sin asesor</span>'}</td>
            </tr>`).join(''));
        $('#advisor_search_info').text(rows.length >= 100 ? 'Mostrando los primeros 100; afina la búsqueda.' : `${rows.length} cliente(s).`);
        advisorUpdateSelection();
    }).fail(function () {
        $('#advisor_search_info').text('');
        toastr.error('No se pudo buscar. Intenta nuevamente.');
    });
}

$(document).on('click', '#btn_assign_advisor', function () {
    $('#advisor_search').val('');
    $('#advisor_tipo').val('28');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('assign_advisor_modal')).show();
    advisorSearch();
});

$(document).on('change', '#advisor_tipo', function () {
    $('#advisor_search').val('');
    advisorSearch();
});

$(document).on('input', '#advisor_search', function () {
    clearTimeout(advisorSearchTimer);
    advisorSearchTimer = setTimeout(advisorSearch, 300);
});

$(document).on('change', '#advisor_check_all', function () {
    $('#advisor_results .advisor-check').prop('checked', $(this).prop('checked'));
    advisorUpdateSelection();
});

$(document).on('change', '#advisor_results .advisor-check', advisorUpdateSelection);

$(document).on('click', '#btn_assign_run', function () {
    const ids = $('#advisor_results .advisor-check:checked').map(function () { return this.value; }).get();
    const advisor = $('#advisor_select').val();
    if (!ids.length) return;
    const $btn = $(this).prop('disabled', true);
    $.ajax({
        method: 'POST',
        url: routeUrl('direction.assign_advisor'),
        data: { client_ids: ids, advisor: advisor, tipo: $('#advisor_tipo').val() },
        dataType: 'json'
    }).done(function (res) {
        if (res.success) {
            toastr.success(`${res.updated} cliente(s) asignados a ${advisor}.`);
            advisorSearch();
        } else {
            toastr.error(res.message || 'No se pudo asignar el asesor.');
            $btn.prop('disabled', false);
        }
    }).fail(function () {
        toastr.error('Error al asignar. Intenta nuevamente.');
        $btn.prop('disabled', false);
    });
});

// Histórico: muestra solo las columnas del año elegido (por defecto el año en curso)
function tg6ApplyHistoricYear() {
    const year = String($('#historic_year').val());
    $('#VtaVsVenHistoric [data-year]').each(function () {
        $(this).toggleClass('d-none', String($(this).data('year')) !== year);
    });
}
$(document).on('change', '#historic_year', function () {
    tg6ApplyHistoricYear();
    tg6LoadVtaChart();
});
$(function () { if ($('#historic_year').length) tg6ApplyHistoricYear(); });
