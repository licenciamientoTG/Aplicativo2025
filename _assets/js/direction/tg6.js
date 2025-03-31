console.log('tg6');

// $(document).ready(async function () {
//     const vta_vs_meta_canva= document.getElementById('vta_vs_meta_canva').getContext('2d');
//      vta_vs_meta_canvas(vta_vs_meta_canva);
// });

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
            url: '/direction/credit_debit_product_table',
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


async function dynamicColumns(months,target){
    if (target == '#CreditCount'){
        var table_class = 'table-info';
    }
    if (target == '#CreditClient'){
        var table_class = 'table-success';
    }
    if (target == '#DebitCount'){
        var table_class = 'table-orange';
    }
    var Columns = [
        { 'data': 'CodigoCliente', 'className': 'text-end '+table_class  },
        {
            'data': 'Cliente2',
            'className': 'text-nowrap '+table_class,
            'searchable': true,
            'render': function (data, type, row) {
                return `<span title="${row.Cliente}">${data}</span>`;
            }
        },
        {
            'data': 'Cliente',
            'visible': false,
            'searchable': true
        },
        { 'data': 'nombre_asesor', 'className': 'text-start text-nowrap '+table_class  },
        { 'data': 'lognew', 'className': 'text-start text-nowrap '+table_class  },
    ];
    months.forEach(function(process) {
        Columns.push({ 'data': process.formatted,
                            'className': 'text-end',
                            'render': $.fn.dataTable.render.number( ',', '.', 3 ),
                            searchable: false,
                        });
    });
        Columns.push( { 'data': 'MaxValue', 'className': ' text-nowrap text-end '  },);
        Columns.push( { 'data': 'prediction', 'className': ' text-nowrap text-end '  },);
        Columns.push( { 'data': 'pro_vs_max', 'className': '  text-end '  },);
        Columns.push( { 'data': 'nombre_zona', 'className': ' text-nowrap'  },);
        return Columns;

}
async function initConsumptionCreditClientTable(CreditClientColumns) {
    let comsumption_credit_client_table = $('#comsumption_credit_client_table').DataTable({
        order: [1, "asc"],
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        ordering: false,
        colReorder: false,
        dom: '<"top"f>rt<"bottom"lip>',
        fixedColumns: {
            leftColumns: 2
        },

        ajax: {
            method: 'POST',
            url: '/direction/comsumption_credit_client_table',
            data:{'type':'28'},
            dataSrc: 'data',
            error: function(data) {
                $('#comsumption_credit_client_table').waitMe('hide');
                $('.table-responsive').removeClass('loading');
 
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
                $('.table-responsive').addClass('loading');
            }
        },
        deferRender: true,
        columns: CreditClientColumns,
        rowId: 'CodigoCliente',
        createdRow: function (row, data, dataIndex) {
                $('td', row).eq(25).addClass('border_end table-success');
                $('td', row).eq(26).addClass('border_end table-primary');
                $('td', row).eq(27).addClass('border_end ');
                $('td', row).eq(28).addClass('border_end');
            if(data['pro_vs_max'] <  0){
                $('td', row).eq(27).addClass('table-danger');
            }else{
                $('td', row).eq(27).addClass('table-info');
            }

        },
        initComplete: function (settings) {
            $('.table-responsive').removeClass('loading');
            // window.scrollTo(0,180);
        },
        footerCallback: function (row, data, start, end, display) {
            const nf = new Intl.NumberFormat("es-MX");
            var api = this.api();
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ?
                    i : 0;
            };
            $(api.column(0).footer()).html("Suma");// Mostrar el texto "Suma" en el footer de la primera columna
            api.columns().every(function (index) {// Iterar sobre cada columna
                if (index > 4 && index < (api.columns().count()-5) ) {
                    var column = this;
                    var total = column.data().reduce(function (a, b) {// Sumar los valores de la columna
                        return intVal(a) + intVal(b);
                    }, 0);
                    total_final = nf.format(total.toFixed(2));
                    $(column.footer()).html(total_final );
                }
            });
        }
    });
    // $('.refresh_comsumption_credit_client_table').on('click', function () {
    //     comsumption_credit_client_table.clear().draw();
    //     comsumption_credit_client_table.ajax.reload();
    //     $('#comsumption_credit_client_table').waitMe('hide');
    // });

}
async function initConsumptionDebitTable(DebColumns) {
    let comsumption_debit_table = $('#comsumption_debit_table').DataTable({
        order: [1, "asc"],
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        ordering: false,
        colReorder: false,
        dom: '<"top"f>rt<"bottom"lip>',
        fixedColumns: {
            leftColumns: 2
        },

        ajax: {
            method: 'POST',
            url: '/direction/comsumption_credit_count_table',
            data:{'type':'127'},
            dataSrc: 'data',
            error: function(data) {
                $('#comsumption_debit_table').waitMe('hide');
                $('.table-responsive').removeClass('loading');
 
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
                $('.table-responsive').addClass('loading');
            }
        },
        deferRender: true,
        columns: DebColumns,
        rowId: 'CodigoCliente',
        createdRow: function (row, data, dataIndex) {
                $('td', row).eq(25).addClass('border_end table-success');
                $('td', row).eq(26).addClass('border_end table-primary');
                $('td', row).eq(27).addClass('border_end ');
                $('td', row).eq(28).addClass('border_end');
            if(data['pro_vs_max'] <  0){
                $('td', row).eq(27).addClass('table-danger');
            }else{
                $('td', row).eq(27).addClass('table-info');
            }

        },
        initComplete: function (settings) {
            $('.table-responsive').removeClass('loading');
            // window.scrollTo(0,180);
        },
        footerCallback: function (row, data, start, end, display) {
            const nf = new Intl.NumberFormat("es-MX");
            var api = this.api();
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ?
                    i : 0;
            };
            $(api.column(0).footer()).html("Suma");// Mostrar el texto "Suma" en el footer de la primera columna
            api.columns().every(function (index) {// Iterar sobre cada columna
                if (index > 4 && index < (api.columns().count()-5) ) {
                    var column = this;
                    var total = column.data().reduce(function (a, b) {// Sumar los valores de la columna
                        return intVal(a) + intVal(b);
                    }, 0);
                    total_final = nf.format(total.toFixed(2));
                    $(column.footer()).html(total_final );
                }
            });
        }
    });
    // $('.refresh_comsumption_debit_table').on('click', function () {
    //     comsumption_debit_table.clear().draw();
    //     comsumption_debit_table.ajax.reload();
    //     $('#comsumption_debit_table').waitMe('hide');
    // });
}
 function initConsumptionCreditCountTable(CreditColumns) {
     let comsumption_credit_count_table = $('#comsumption_credit_count_table').DataTable({
        order: [1, "asc"],
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        ordering: false,
        colReorder: false,
        dom: '<"top"f>rt<"bottom"lip>',
        fixedColumns: {
            leftColumns: 2
        },
        ajax: {
            method: 'POST',
            url: '/direction/comsumption_credit_count_table',
            data:{'type':'28'},
            dataSrc: 'data',
            error: function() {
                $('#comsumption_credit_count_table').waitMe('hide');
                $('.table-responsive').removeClass('loading');

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
                $('.table-responsive').addClass('loading');
            }
        },
        deferRender: true,
        columns: CreditColumns,
        rowId: 'CodigoCliente',
        createdRow: function (row, data, dataIndex) {
                $('td', row).eq(25).addClass('border_end table-success');
                $('td', row).eq(26).addClass('border_end table-primary');
                $('td', row).eq(27).addClass('border_end ');
                $('td', row).eq(28).addClass('border_end');
            if(data['pro_vs_max'] <  0){
                $('td', row).eq(27).addClass('table-danger');
            }else{
                $('td', row).eq(27).addClass('table-info');
            }

        },
        initComplete: function (settings) {
            $('.table-responsive').removeClass('loading');
            // window.scrollTo(0,180);
        },
        footerCallback: function (row, data, start, end, display) {
            const nf = new Intl.NumberFormat("es-MX");
            var api = this.api();
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ?
                    i : 0;
            };
            $(api.column(0).footer()).html("Suma");// Mostrar el texto "Suma" en el footer de la primera columna
            api.columns().every(function (index) {// Iterar sobre cada columna
                if (index > 4 && index < (api.columns().count()-5) ) {
                    var column = this;
                    var total = column.data().reduce(function (a, b) {// Sumar los valores de la columna
                        return intVal(a) + intVal(b);
                    }, 0);
                    total_final = nf.format(total.toFixed(2));
                    $(column.footer()).html(total_final );
                }
            });
        }
    });

}


async function vta_vs_meta_canvas(ctx) {
    try {
        const response = await fetch('/direction/Vta_vs_meta_canvas', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
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
        new Chart(ctx, {
            type: 'bar',
            data: barChartData,
            options: options,
            // plugins: [plugin],
        });
    } catch (error) {
        console.error('Error:', error);
    }
}


function downloadExcel() {
    // Mostrar el indicador de carga
    $('.tab-pane').addClass('loading');

    fetch('/direction/excel_tg6', {
        method: 'GET'
    })
    .then(response => response.blob()) // Obtener el archivo en formato blob
    .then(blob => {
        // Ocultar el indicador de carga
         $('.tab-pane').removeClass('loading');

        // Crear un enlace temporal para la descarga
        const url = window.URL.createObjectURL(new Blob([blob]));
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        a.download = 'reporte.xlsx'; // Nombre del archivo a descargar
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
    })
    .catch(() => {
        // Ocultar el indicador de carga y manejar errores
         $('.tab-pane').removeClass('loading');
        alert('Error al descargar el archivo');
    });
}


