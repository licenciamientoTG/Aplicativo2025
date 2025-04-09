// comentarios
async function mistery_shopper_table(){
    if ($.fn.DataTable.isDataTable('#mistery_shopper_table')) {
        $('#mistery_shopper_table').DataTable().destroy();  // Destruye la tabla existente
        $('#mistery_shopper_table thead').empty(); // Limpia el encabezado
        $('#mistery_shopper_table tbody').empty(); // Limpia el cuerpo
        $('#mistery_shopper_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    var dynamicColumns = generateDateColumnsMystery(fromDate, untilDate);

   $('#mistery_shopper_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 150,
        fixedColumns: {
            leftColumns: 2
        },
        buttons: [
            { extend: 'excel', className: 'd-none' },
            { extend: 'pdf', className: 'd-none', text: 'PDF' },
            { extend: 'print', className: 'd-none' }
        ],
        ajax: {
            method: 'POST',
            data: {
                'fromDate':fromDate,
                'untilDate':untilDate,
                'dinamicColumns': dynamicColumns
            },
            url: '/commercial/mistery_shopper_table',
            error: function() {
                $('#mistery_shopper_table').waitMe('hide');
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
            },
            complete: function () {
                $('.table-responsive').removeClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        destroy: true, 
        rowId: 'id_grupo',
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {

        }
    });
    $('.mistery_shopper_table').on('click', function () {
        mistery_shopper_table.clear().draw();
        mistery_shopper_table.ajax.reload();
        $('#mistery_shopper_table').waitMe('hide');
    });
}
function generateDateColumnsMystery(fromDate, untilDate) {
    const startDate = new Date(fromDate + "T00:00:00"); // Asegurarte de usar el inicio del día
    const endDate = new Date(untilDate + "T00:00:00");
    const columns = [];
    let currentWeekStart = new Date(startDate);
    columns.push(
        { data: 'codgas',title:'Código', className: 'text-left text-nowrap bg-info-subtle'},
        { data: 'abr', title: 'Estación', className: 'text-left text-nowrap bg-info-subtle' },
    );
    let thead = $('#pivot_daily_dispatches_table_head');
    thead.html('');
    thead.empty();
    thead.append('<tr>'); // Inicia una fila nueva
    thead.append('<th>Código</th><th>Estación</th>'); // Columnas fijas
    // Iterar por semanas dentro del rango
    while (currentWeekStart <= endDate) {
        let year = currentWeekStart.getFullYear();
        // let weekNumber = getISOWeekNumber(currentWeekStart);
        const { weekYear, weekNumber } = getISOWeekYearAndNumber(currentWeekStart);

        // weekNumber= weekNumber.toString().padStart(2, '0');

        let weekStart = new Date(currentWeekStart);
        let weekEnd = new Date(currentWeekStart);
        weekEnd.setDate(weekEnd.getDate() + 6);

        // Formatear fechas al formato deseado (dd/mm/yyyy)
        let formattedWeekStart = formatDate(weekStart);
        let formattedWeekEnd = formatDate(weekEnd);

        let data = `${weekYear }_${weekNumber}`;

        columns.push(
            {
                data: data,
                title: `Sem ${weekNumber} <p> ${formattedWeekStart} <p> ${formattedWeekEnd}`,
                className: 'text-end text-nowrap '
            },
        );
        thead.append(`<th class="header_small">Sem ${weekNumber} </th>`);
        currentWeekStart.setDate(currentWeekStart.getDate() + ((8 - currentWeekStart.getDay()) % 7 || 7));
    }
    thead.append('</tr>'); // Cierra la fila

    return columns;
}

function formatDate(date) {
    const options = { day: '2-digit', month: 'short', year: '2-digit' };
    return date.toLocaleDateString('es-ES', options)
               .replace('.', ''); // Eliminar punto después del mes (si existe)
}
async function lubricants_table(){
    if ($.fn.DataTable.isDataTable('#lubricants_table')) {
        $('#lubricants_table').DataTable().destroy();  // Destruye la tabla existente
        $('#lubricants_table thead').empty(); // Limpia el encabezado
        $('#lubricants_table tbody').empty(); // Limpia el cuerpo
        $('#lubricants_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    var dynamicColumns = generateDateColumns(fromDate, untilDate);

   $('#lubricants_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 150,
         fixedColumns: {
            leftColumns: 3
         },
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-sm btn-success',
                text: '<i class="fa fa-file-excel"></i> Excel',
            }
        ],
        ajax: {
            method: 'POST',
            data: {
                'fromDate':fromDate,
                'untilDate':untilDate,
                'dinamicColumns': dynamicColumns
            },
            url: '/commercial/lubricants_table',
            error: function() {
                $('#lubricants_table').waitMe('hide');
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
            },
            complete: function () {
                $('.table-responsive').removeClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        destroy: true, 
        rowId: 'id_grupo',
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            // $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {

        }
    });
    $('.lubricants_table').on('click', function () {
        lubricants_table.clear().draw();
        lubricants_table.ajax.reload();
        $('#lubricants_table').waitMe('hide');
    });
}


function generateDateColumns(fromDate, untilDate) {
    const startDate = new Date(fromDate + "T00:00:00"); // Asegurarte de usar el inicio del día
    const endDate = new Date(untilDate + "T00:00:00");
    const columns = [];
    let currentWeekStart = new Date(startDate);
    columns.push(
        { data: 'codigo',title:'Código' },
        { data: 'Estacion', title: 'Estación', className: 'text-left text-nowrap' },
        { data: 'producto', title: 'Producto', className: 'text-left text-nowrap'},
    );
    let thead = $('#pivot_daily_dispatches_table_head');
    thead.html('');
    thead.empty();
    thead.append('<tr>'); // Inicia una fila nueva
    thead.append('<th>Código</th><th>Estación</th><th>Producto</th>'); // Columnas fijas
    // Iterar por semanas dentro del rango
    while (currentWeekStart <= endDate) {
        const { weekYear, weekNumber } = getISOWeekYearAndNumber(currentWeekStart);
        let data_monto = `${weekYear }_${weekNumber}_monto`;
        let data_cantidad = `${weekYear }_${weekNumber}_cantidad`;

        columns.push(
            {
                data: data_monto,
                title: `Sem ${weekNumber} Monto`,
                render: $.fn.dataTable.render.number(',', '.', 2, '$'), // Render como formato monetario
                className: 'text-end text-nowrap table-info'
            },
            {
                data: data_cantidad,
                title: `Sem ${weekNumber} Cantidad`,
                className: 'text-end text-nowrap'
            }
        );

        thead.append(`<th>Sem ${weekNumber} Monto</th>`);
        thead.append(`<th>Sem ${weekNumber} Cantidad</th>`);
        currentWeekStart.setDate(currentWeekStart.getDate() + ((8 - currentWeekStart.getDay()) % 7 || 7));
    }
    thead.append('</tr>'); // Cierra la fila

    return columns;
}
function getISOWeekNumber(date) {
    const tempDate = new Date(date.getTime());
    const dayNum = (date.getDay() + 6) % 7;
    tempDate.setDate(tempDate.getDate() - dayNum + 3);
    const firstThursday = tempDate.getTime();
    tempDate.setMonth(0, 1);
    if (tempDate.getDay() !== 4) {
        tempDate.setMonth(0, 1 + ((4 - tempDate.getDay()) + 7) % 7);
    }
    return 1 + Math.ceil((firstThursday - tempDate) / 604800000);
}

function getISOWeekYearAndNumber(date) {
    const tempDate = new Date(date);
    tempDate.setHours(0, 0, 0, 0);
    tempDate.setDate(tempDate.getDate() + 3 - (tempDate.getDay() + 6) % 7);
    const week1 = new Date(tempDate.getFullYear(), 0, 4);
    const weekNumber = 1 + Math.round(((tempDate - week1) / 86400000 - 3 + (week1.getDay() + 6) % 7) / 7);
    const weekYear = tempDate.getFullYear();
    return { weekYear, weekNumber };
}


async function sales_type_payment_table(){
    if ($.fn.DataTable.isDataTable('#sales_type_payment_table')) {
        $('#sales_type_payment_table').DataTable().destroy();  // Destruye la tabla existente
        $('#sales_type_payment_table thead').empty(); // Limpia el encabezado
        $('#sales_type_payment_table tbody').empty(); // Limpia el cuerpo
        $('#sales_type_payment_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;
    var zona = document.getElementById('zona').value;

    var dynamicColumns = generateSalesPaymentColumns(fromDate, untilDate,'sales_type_payment_table');

    var groupColumn = 0;
    let sales_type_payment_table =$('#sales_type_payment_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        // columnDefs: [{ visible: true, targets: groupColumn }],

        dom: '<"top"Bf>rt<"bottom"lip>',
        // pageLength: 150,
        ordering: true,
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
         fixedColumns: {
            leftColumns: 2,
         },
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
                'zona':zona,
                'total':0,
                'dinamicColumns': dynamicColumns
            },
            url: '/commercial/sales_type_payment_table',
            error: function() {
                $('#sales_type_payment_table').waitMe('hide');
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
            },
            complete: function () {
                $('.table-responsive').removeClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        destroy: true, 
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();

            // Función para obtener sumatoria de una columna
            var intVal = function (i) {
                return typeof i === 'string' 
                    ? i.replace(/[\$,]/g, '') * 1 
                    : typeof i === 'number' 
                        ? i 
                        : 0;
            };

            // Recorremos las columnas desde la tercera en adelante
            api.columns().every(function (index) {
                if (index > 2) { // Desde la tercera columna en adelante
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
                        <div>Filtrado: ${filteredSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                        <div>Total: ${totalSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                    `);
                }
            });
        }
    });


    $('#filtro-sales_type_payment_table input').on('keyup  change clear', function () {
        sales_type_payment_table
            .column(0).search($('#Empresa').val().trim())
            .column(1).search($('#Zona').val().trim())
            .column(2).search($('#Estacion').val().trim())
            .column(3).search($('#Descripcion').val().trim())
            .draw();
      });
    $('.sales_type_payment_table').on('click', function () {
        sales_type_payment_table.clear().draw();
        sales_type_payment_table.ajax.reload();
        $('#sales_type_payment_table').waitMe('hide');
    });
}

async function sales_type_payment_totals_table(){
    if ($.fn.DataTable.isDataTable('#sales_type_payment_totals_table')) {
        $('#sales_type_payment_totals_table').DataTable().destroy();  // Destruye la tabla existente
        $('#sales_type_payment_totals_table thead').empty(); // Limpia el encabezado
        $('#sales_type_payment_totals_table tbody').empty(); // Limpia el cuerpo
        $('#sales_type_payment_totals_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from2').value;
    var untilDate = document.getElementById('until2').value;
    var zona = document.getElementById('zona2').value;

    var dynamicColumns = generateSalesPaymentColumns(fromDate, untilDate,'sales_type_payment_totals_table');

    var groupColumn = 0;
    let sales_type_payment_totals_table =$('#sales_type_payment_totals_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        // columnDefs: [{ visible: true, targets: groupColumn }],

        dom: '<"top"Bf>rt<"bottom"lip>',
        // pageLength: 150,
        ordering: true,
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
         fixedColumns: {
            leftColumns: 2,
         },
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
                'zona':zona,
                'total':1,
                'dinamicColumns': dynamicColumns
            },
            url: '/commercial/sales_type_payment_table',
            error: function() {
                $('#sales_type_payment_totals_table').waitMe('hide');
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
            },
            complete: function () {
                $('.table-responsive').removeClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        destroy: true, 
        createdRow: function (row, data, dataIndex) {
            if (data['Descripcion']=='Total Estación') {
                $('td:eq(0)', row).addClass('total_bg');
                $('td:eq(1)', row).addClass('total_bg');
        
                // Aplicar la clase 'total_bg2' al resto de las columnas
                $('td:gt(1)', row).addClass('total_bg2');
            }
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');

           
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
        footerCallback: function (row, data, start, end, display) {
           
        }
    });


    $('#filtro-sales_type_payment_totals_table input').on('keyup  change clear', function () {
        sales_type_payment_totals_table
            .column(0).search($('#Empresa2').val().trim())
            .column(1).search($('#Zona2').val().trim())
            .column(2).search($('#Estacion2').val().trim())
            .column(3).search($('#Descripcion2').val().trim())
            .draw();
      });
    $('.sales_type_payment_totals_table').on('click', function () {
        sales_type_payment_totals_table.clear().draw();
        sales_type_payment_totals_table.ajax.reload();
        $('#sales_type_payment_totals_table').waitMe('hide');
    });

}


async function mounth_group_table(){
    if ($.fn.DataTable.isDataTable('#mounth_group_table')) {
        $('#mounth_group_table').DataTable().destroy();  // Destruye la tabla existente
        $('#mounth_group_table thead').empty(); // Limpia el encabezado
        $('#mounth_group_table tbody').empty(); // Limpia el cuerpo
        $('#mounth_group_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from3').value;
    var untilDate = document.getElementById('until3').value;
    var grupo = document.getElementById('grupo3').value;

    var dynamicColumns = generateMounthGroupColumns(fromDate, untilDate,'mounth_group_table');
    let mounth_group_table =$('#mounth_group_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        // columnDefs: [{ visible: true, targets: groupColumn }],

        dom: '<"top"Bf>rt<"bottom"lip>',
        // pageLength: 150,
        ordering: true,
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
         fixedColumns: {
            leftColumns: 3,
         },
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
                'grupo':grupo,
                'total':0,
                'dinamicColumns': dynamicColumns
            },
            url: '/commercial/mounth_group_table',
            error: function() {
                $('#mounth_group_table').waitMe('hide');
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
            },
            complete: function () {
                $('.table-responsive').removeClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        destroy: true, 
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();

            // Función para obtener sumatoria de una columna
            var intVal = function (i) {
                return typeof i === 'string' 
                    ? i.replace(/[\$,]/g, '') * 1 
                    : typeof i === 'number' 
                        ? i 
                        : 0;
            };

            // Recorremos las columnas desde la tercera en adelante
            api.columns().every(function (index) {
                if (index > 3) { // Desde la tercera columna en adelante
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
                        <div>Filtrado: ${filteredSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                        <div>Total: ${totalSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                    `);
                }
            });
        }
    }).on('xhr.dt', function(e, settings, json, xhr) {
        if (json && json.data) {
            generateCards(json.data); // Llamar a la función con los datos obtenidos
        }
    });


    $('#filtro-mounth_group_table input').on('keyup  change clear', function () {
        mounth_group_table
            .column(0).search($('#Grupo3').val().trim())
            .column(1).search($('#Empresa3').val().trim())
            .column(2).search($('#Descripcion3').val().trim())
            .column(3).search($('#MedioPago3').val().trim())
            .draw();
      });
    $('.mounth_group_table').on('click', function () {
        mounth_group_table.clear().draw();
        mounth_group_table.ajax.reload();
        $('#mounth_group_table').waitMe('hide');
    });
}
function generateCards(data) {
    var group_data = Object.values(groupAndSum(data));

    let container = document.getElementById('cardContainer');
    container.innerHTML = '';

    group_data.forEach(row => {
        let card = document.createElement('div');
        // Convertir el objeto MediosPago a un array
        var medios_pago = Object.values(row.MediosPago);
        
        // Crear una tabla para los medios de pago
        let table = document.createElement('table');
        table.className = 'table table_card table-sm'; // Añade clases Bootstrap si estás usando Bootstrap
        
        // Crear encabezado de la tabla
        let thead = document.createElement('thead');
        thead.innerHTML = `
            <tr>
                <th>Medio de Pago</th>
                <th>Monto</th>
                <th>Porcentaje</th>
            </tr>
        `;
        table.appendChild(thead);
        
        // Crer cuerpo de la tabla
        let tbody = document.createElement('tbody');
        
        // Ahora podemos usar forEach porque medios_pago es un array
        medios_pago.forEach(med => {
            // Calcular el porcentaje del total
            let porcentaje = (med.TotalSum / row.TotalSum) * 100;
            
            let tr = document.createElement('tr');
            let total_sum = Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(med.TotalSum);
            tr.innerHTML = `
                <td>${med.MedioPago}</td>
                <td class="text-end">${total_sum}</td>
                <td class="text-end">${porcentaje.toFixed(2)}%</td>
            `;
            tbody.appendChild(tr);
        });
        
        table.appendChild(tbody);
        let total_sum = Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(row.TotalSum);
        card.className = 'card card_group';
        card.innerHTML = `
            <div class="card-body body_card">
                <h5 class="card-title">${row.Grupo}</h5>
                <p class="card-text"><strong>Total: ${total_sum}</strong></p>
            </div>
        `;
        
        // Agregar la tabla después de establecer el innerHTML
        card.querySelector('.card-body').appendChild(table);
        
        container.appendChild(card);
    });
}
function groupAndSum(data) {
    return data.reduce((acc, item) => {
        let group = item.Grupo;
        let paymentMethod = item.MedioPago;

        // Si el grupo no existe en el acumulador, lo inicializamos con su total general
        if (!acc[group]) {
            acc[group] = { 
                Grupo: group, 
                TotalSum: 0,  // Total general del grupo
                MediosPago: {} // Detalle de medios de pago
            };
        }

        // Si el medio de pago no existe dentro del grupo, lo inicializamos
        if (!acc[group].MediosPago[paymentMethod]) {
            acc[group].MediosPago[paymentMethod] = { 
                MedioPago: paymentMethod, 
                TotalSum: 0 // Total por medio de pago
            };
        }

        // Recorremos las claves del objeto y sumamos solo las numéricas (excluyendo las especificadas)
        Object.keys(item).forEach(key => {
            if (!["Grupo", "Empresa", "Descripcion", "MedioPago", "Total"].includes(key)) {
                let value = parseFloat(item[key]) || 0;
                
                // Sumamos al total del grupo
                acc[group].TotalSum += value;
                
                // Sumamos al total del medio de pago dentro del grupo
                acc[group].MediosPago[paymentMethod].TotalSum += value;
            }
        });

        return acc;
    }, {});
}


function updateCards() {
    let data = $('#mounth_group_table').DataTable().rows().data().toArray();
    generateCards(data);

}
function generateSalesPaymentColumns(fromDate, untilDate, table) {
    const startDate = new Date(fromDate + "T00:00:00"); // Asegurarte de usar el inicio del día
    const endDate = new Date(untilDate + "T00:00:00");
    const columns = [];
    const monthNames = [
        "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio",
        "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"
    ];

    columns.push(
        { data: 'Empresa',title:'Empresa', className: 'text-left text-nowrap table-info' },
        { data: 'Zona',title:'Zona', className: 'text-left text-nowrap table-info' },
        { data: 'Estacion',title:'Estacion', className: 'text-left text-nowrap table-info' },
        { data: 'Descripcion', title: 'Descripcion', className: 'text-left text-nowrap table-info' },
    );

    let theadHTML = '<tr>';
    let tfootHTML = '<tr>';
     // Agregar las columnas fijas al thead y tfoot
     theadHTML += '<th>Empresa</th><th>Zona</th><th>Estación</th><th>Producto</th>';
     tfootHTML += '<th>Empresa</th><th>Zona</th><th>Estación</th><th>Producto</th>'; // Espacios en blanco para las columnas fijas
   
    let currentMonth = new Date(startDate.getFullYear(), startDate.getMonth(), 1);

    while (currentMonth  <= endDate) {
        var YearName = currentMonth.getFullYear();
        const monthYear = currentMonth.getFullYear() + "_" + (currentMonth.getMonth() + 1);
        let data_monto = `${monthYear}`;
        let monthName = monthNames[currentMonth.getMonth()]; // Obtener el nombre del mes


        columns.push(
            {
                data: data_monto,
                title: `${monthName} ${YearName}`,
                render: $.fn.dataTable.render.number(',', '.', 2, '$'),
                className: 'text-end text-nowrap '
            },
        );
        theadHTML += `<th>${monthName}</th>`;
        tfootHTML += `<th></th>`;
        currentMonth.setMonth(currentMonth.getMonth() + 1);
    }
    columns.push(
        { data: 'Total',
            title:'Total', 
            className: 'text-left text-nowrap table-info' ,
            render: $.fn.dataTable.render.number(',', '.', 2, '$'),

        },
    );
    theadHTML += `<th>Total</th>`;
    tfootHTML += `<th></th>`;

    // Cerrar las filas correctamente
    theadHTML += '</tr>';
    tfootHTML += '</tr>';
    tablename = document.getElementById(table);
    thead = tablename.getElementsByTagName('thead')[0];
    tfoot = tablename.getElementsByTagName('tfoot')[0];
    thead.innerHTML = theadHTML;
    tfoot.innerHTML = tfootHTML;
    // $('#{table} thead').html(theadHTML);
    // $('#{table} tfoot').html(tfootHTML);

    return columns;
}




function generateMounthGroupColumns(fromDate, untilDate, table) {
    const startDate = new Date(fromDate + "T00:00:00"); // Asegurarte de usar el inicio del día
    const endDate = new Date(untilDate + "T00:00:00");
    const columns = [];
    const monthNames = [
        "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio",
        "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"
    ];

    columns.push(
        { data: 'Grupo',title:'Grupo', className: 'text-left text-nowrap table-info' },
        { data: 'Empresa',title:'Empresa', className: 'text-left text-nowrap table-info' },
        { data: 'Descripcion', title: 'Descripcion', className: 'text-left text-nowrap table-info' },
        { data: 'MedioPago', title: 'MedioPago', className: 'text-left text-nowrap table-info' },
    );

    let theadHTML = '<tr>';
    let tfootHTML = '<tr>';
     // Agregar las columnas fijas al thead y tfoot
     theadHTML += '<th>Grupo</th><th>Empresa</th><th>Descripcion</th><th>MedioPago</th>';
     tfootHTML += '<th>Grupo</th><th>Empresa</th><th>Descripcion</th><th>MedioPago</th>'; // Espacios en blanco para las columnas fijas
   
    let currentMonth = new Date(startDate.getFullYear(), startDate.getMonth(), 1);

    while (currentMonth  <= endDate) {
        var YearName = currentMonth.getFullYear();
        const monthYear = currentMonth.getFullYear() + "_" + (currentMonth.getMonth() + 1);
        let data_monto = `${monthYear}`;
        let monthName = monthNames[currentMonth.getMonth()]; // Obtener el nombre del mes


        columns.push(
            {
                data: data_monto,
                title: `${monthName} ${YearName}`,
                render: $.fn.dataTable.render.number(',', '.', 2, '$'),
                className: 'text-end text-nowrap '
            },
        );
        theadHTML += `<th>${monthName}</th>`;
        tfootHTML += `<th></th>`;
        currentMonth.setMonth(currentMonth.getMonth() + 1);
    }
    columns.push(
        { data: 'Total',
            title:'Total', 
            className: 'text-left text-nowrap table-info' ,
            render: $.fn.dataTable.render.number(',', '.', 2, '$'),

        },
    );
    theadHTML += `<th>Total</th>`;
    tfootHTML += `<th></th>`;

    // Cerrar las filas correctamente
    theadHTML += '</tr>';
    tfootHTML += '</tr>';
    tablename = document.getElementById(table);
    thead = tablename.getElementsByTagName('thead')[0];
    tfoot = tablename.getElementsByTagName('tfoot')[0];
    thead.innerHTML = theadHTML;
    tfoot.innerHTML = tfootHTML;
    // $('#{table} thead').html(theadHTML);
    // $('#{table} tfoot').html(tfootHTML);

    return columns;
}


async function upload_file_mystery() {
    const fileInput = document.getElementById('file_to_upload');
    const date_mystery = document.getElementById('date_mystery').value;

    if (!date_mystery) {
        toastr.error('Por favor, selecciona una fecha.', '¡Error!', { timeOut: 3000 });
        return;
    }

    const file = fileInput.files[0]; // Obtiene el primer archivo seleccionado

    if (!file) {
        toastr.error('Por favor, selecciona un archivo.', '¡Error!', { timeOut: 3000 });
        return;
    }

    $('.mistery_heather').addClass('loading');
    const formData = new FormData();
    formData.append('file_to_upload', file);
    formData.append('date_mystery', date_mystery);

    try {
        const response = await fetch('/commercial/import_file_mystery_shopper', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data['success'] == false) {
            toastr.error(data['message'], '¡Error!', { timeOut: 3000 });
            $('.mistery_heather').removeClass('loading');
            fileInput.value = '';
            return;
        }

        if (data['success'] == true) {
            toastr.success('Archivo subido exitosamente ', '¡Éxito!', { timeOut: 3000 });
            $('.mistery_heather').removeClass('loading');
            fileInput.value = '';
            mistery_shopper_table();
            // setTimeout(() => {
            //     window.location.reload();
            // }, 2000);
        } 
    } catch (error) {
        console.error('Error al subir el archivo:', error);
        $('.mistery_heather').removeClass('loading');
        $('.mistery_heather').removeClass('loading');

        toastr.error('Hubo un problema al subir el archivo.', '¡Error!', { timeOut: 3000 });
    }
    $('.mistery_heather').removeClass('loading');

}


async function sale_month_turn_table(){
    if ($.fn.DataTable.isDataTable('#sale_month_turn_table')) {
        $('#sale_month_turn_table').DataTable().destroy();  // Destruye la tabla existente
        $('#sale_month_turn_table thead').empty(); // Limpia el encabezado
        $('#sale_month_turn_table tbody').empty(); // Limpia el cuerpo
        $('#sale_month_turn_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;
    var zona = document.getElementById('zona').value;
    var turn = document.getElementById('turn').value;
    var total = 1;

    var dynamicColumns = generateSalesMonthColumns(fromDate, untilDate,turn,'sale_month_turn_table');

    var groupColumn = 0;
    let sale_month_turn_table =$('#sale_month_turn_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        // columnDefs: [{ visible: true, targets: groupColumn }],

        dom: '<"top"Bf>rt<"bottom"lip>',
        // pageLength: 150,
        ordering: false,
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
         fixedColumns: {
            leftColumns: 2,
         },
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-success',
                text: ' Excel',
                exportOptions: {
                    rows: function (idx, data, node) {
                        return data.Producto !== 'Total Estación'; // 👈 EXCLUYE esta fila
                    }
                }
            },
        ],
        ajax: {
            method: 'POST',
            data: {
                'fromDate':fromDate,
                'untilDate':untilDate,
                'zona':zona,
                'turn':turn,
                'total':total,
                'dinamicColumns': dynamicColumns
            },
            url: '/commercial/sale_month_turn_table',
            error: function() {
                $('#sale_month_turn_table').waitMe('hide');
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
            },
            complete: function () {
                $('.table-responsive').removeClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        destroy: true, 
        createdRow: function (row, data, dataIndex) {
            $('td:eq(0)', row).addClass('table-info');
            $('td:eq(1)', row).addClass('table-info');

            if (data['Producto']=='Total Estación') {
                $('td:eq(0)', row).addClass('total_bg');
                $('td:eq(1)', row).addClass('total_bg');

                // Aplicar la clase 'total_bg2' al resto de las columnas
                $('td:gt(1)', row).addClass('total_bg2');
            }

        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');

        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();

            // Función para sumar valores en una columna
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ? i : 0;
            };
            api.columns().every(function (index) {
                if (index > 1) { // Desde la tercera columna en adelante
        
                    // 🔍 Obtener nombre del campo (key del objeto de datos)
                    var columnName = api.column(index).dataSrc();
        
                    // Sumatoria de los datos de la página actual, excluyendo 'Total Estación'
                    let filteredSum = data.reduce((sum, row) => {
                        if (row.Producto !== 'Total Estación') {
                            sum += intVal(row[columnName]);
                        }
                        return sum;
                    }, 0);
        
                    // Sumatoria total (todos los datos filtrados), excluyendo 'Total Estación'
                    let totalSum = 0;
                    api.rows({ search: 'applied' }).every(function () {
                        const rowData = this.data();
                        if (rowData.Producto !== 'Total Estación') {
                            totalSum += intVal(rowData[columnName]);
                        }
                    });
        
                    // Mostrar en footer
                    $(api.column(index).footer()).html(`
                        <div>Total: ${filteredSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                        <div>Filtrado: ${totalSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                    `);
                }
            });
        }
    });
    $('#filtroProducto').on('change', function () {
        const value = $(this).val();
        // Asumiendo que la columna "Producto" está en el índice 1
        $('#sale_month_turn_table').DataTable()
            .column(1)
            .search(value)
            .draw();
    });

    $('#filtro-sale_month_turn_table input').on('keyup  change clear', function () {
        sale_month_turn_table
            .column(0).search($('#Estacion').val().trim())
            .column(1).search($('#Producto').val().trim())
            .draw();
      });
    $('.sale_month_turn_table').on('click', function () {
        sale_month_turn_table.clear().draw();
        sale_month_turn_table.ajax.reload();
        $('#sale_month_turn_table').waitMe('hide');
    });
}


function generateSalesMonthColumns(fromDate, untilDate,turn, table) {
    const startDate = new Date(fromDate + "T00:00:00"); // Asegurarte de usar el inicio del día
    const endDate = new Date(untilDate + "T00:00:00");
    const columns = [];
    const monthNames = [
        "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio",
        "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"
    ];
    var turns  = [11,21,31,41];

    if(turn != 0){
        turns = [turn];
    }

    columns.push(
        { data: 'Estacion',title:'Estacion',name:'Estacion', className: 'text-left text-nowrap border_center',rowspam: 2 },
        { data: 'Producto', title: 'Producto', className: 'text-left text-nowrap border_center', rowspam: 2},
    );

    let theadHTML = '<tr >';
    let tfootHTML = '<tr>';
     // Agregar las columnas fijas al thead y tfoot
    theadHTML += '<th >Estación</th><th >Producto</th>';
    tfootHTML += '<th>Estación</th><th>Producto</th>'; // Espacios en blanco para las columnas fijas

    let currentMonth = new Date(startDate.getFullYear(), startDate.getMonth(), 1);

    while (currentMonth  <= endDate) {
        const year = currentMonth.getFullYear();
        const month = currentMonth.getMonth() + 1; // Los meses en JavaScript son base 0
        const monthName = monthNames[currentMonth.getMonth()]; // Obtener el nombre del mes

        // Iterar sobre los turnos para cada mes
        turns.forEach(turn => {
            const dataKey = `${year}_${month}_${turn}`; // Crear la clave única para cada turno y mes
            const title = `${monthName} ${year} (Turno ${Math.floor(turn / 10)})`; // Título de la columna

            columns.push({
                data: dataKey,
                title: title,
                render: $.fn.dataTable.render.number(',', '.', 2, ),
                className: 'text-end text-nowrap border_center',
            });

            theadHTML += `<th >${monthName} (Turno ${turn % 10})</th>`;
            tfootHTML += `<th></th>`;
        });

        currentMonth.setMonth(currentMonth.getMonth() + 1);
    }
    columns.push(
        { data: 'total',
            title:'Total', 
            className: 'text-left text-nowrap table-info' ,
            render: $.fn.dataTable.render.number(',', '.', 2, )

        },
    );
    theadHTML += `<th>Total</th>`;
     tfootHTML += `<th></th>`;

    // Cerrar las filas correctamente
    theadHTML += '</tr>';
    tfootHTML += '</tr>';
    // $('#sale_month_turn_table thead').html(theadHTML);
    var tablename = document.getElementById(table);
    tablename.querySelector('tfoot').innerHTML = tfootHTML;

    // $('#sale_month_turn_table tfoot').html(tfootHTML);

    return columns;
}



async function sale_month_turn_table_no_total(){
    if ($.fn.DataTable.isDataTable('#sale_month_turn_table_no_total')) {
        $('#sale_month_turn_table_no_total').DataTable().destroy();  // Destruye la tabla existente
        $('#sale_month_turn_table_no_total thead').empty(); // Limpia el encabezado
        $('#sale_month_turn_table_no_total tbody').empty(); // Limpia el cuerpo
        $('#sale_month_turn_table_no_total tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from2').value;
    var untilDate = document.getElementById('until2').value;
    var zona = document.getElementById('zona2').value;
    var turn = document.getElementById('turn2').value;
    var total = 0;

    var dynamicColumns = generateSalesMonthColumns(fromDate, untilDate,turn,'sale_month_turn_table_no_total');

    var groupColumn = 0;
    let sale_month_turn_table_no_total =$('#sale_month_turn_table_no_total').DataTable({
        order: [0, "asc"],
        colReorder: false,
        // columnDefs: [{ visible: true, targets: groupColumn }],

        dom: '<"top"Bf>rt<"bottom"lip>',
        // pageLength: 150,
        ordering: false,
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
         fixedColumns: {
            leftColumns: 2,
         },
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
                'zona':zona,
                'turn':turn,
                'total':total,
                'dinamicColumns': dynamicColumns
            },
            url: '/commercial/sale_month_turn_table',
                error: function() {
                $('#sale_month_turn_table_no_total').waitMe('hide');
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
            },
            complete: function () {
                $('.table-responsive').removeClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        destroy: true, 
        createdRow: function (row, data, dataIndex) {
            $('td:eq(0)', row).addClass('table-info');
            $('td:eq(1)', row).addClass('table-info');

            if (data['Producto']=='Total Estación') {
                $('td:eq(0)', row).addClass('total_bg');
                $('td:eq(1)', row).addClass('total_bg');
        
                // Aplicar la clase 'total_bg2' al resto de las columnas
                $('td:gt(1)', row).addClass('total_bg2');
            }

        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
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
            api.columns().every(function (index) {
                if (index > 1) { // Desde la tercera columna en adelante
        
                    // 🔍 Obtener nombre del campo (key del objeto de datos)
                    var columnName = api.column(index).dataSrc();
        
                    // Sumatoria de los datos de la página actual, excluyendo 'Total Estación'
                    let filteredSum = data.reduce((sum, row) => {
                        if (row.Producto !== 'Total Estación') {
                            sum += intVal(row[columnName]);
                        }
                        return sum;
                    }, 0);
        
                    // Sumatoria total (todos los datos filtrados), excluyendo 'Total Estación'
                    let totalSum = 0;
                    api.rows({ search: 'applied' }).every(function () {
                        const rowData = this.data();
                        if (rowData.Producto !== 'Total Estación') {
                            totalSum += intVal(rowData[columnName]);
                        }
                    });
        
                    // Mostrar en footer
                    $(api.column(index).footer()).html(`
                        <div>Total: ${filteredSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                        <div>Filtrado: ${totalSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                    `);
                }
            });
        }
    });

    $('#filtroProducto2').on('change', function () {
        const value = $(this).val();
        // Asumiendo que la columna "Producto" está en el índice 1
        $('#sale_month_turn_table_no_total').DataTable()
            .column(1)
            .search(value)
            .draw();
    });
    $('#filtro-sale_month_turn_table_no_total input').on('keyup  change clear', function () {
        sale_month_turn_table_no_total
            .column(0).search($('#Estacion').val().trim())
            .draw();
      });
    $('.sale_month_turn_table_no_total').on('click', function () {
        sale_month_turn_table_no_total.clear().draw();
        sale_month_turn_table_no_total.ajax.reload();
        $('#sale_month_turn_table_no_total').waitMe('hide');
    });
}

async function sale_month_turn_base_table(){
    if ($.fn.DataTable.isDataTable('#sale_month_turn_base_table')) {
        $('#sale_month_turn_base_table').DataTable().destroy();
        $('#sale_month_turn_base_table thead .filter').remove();
        // $('#sale_month_turn_base_table').DataTable().destroy();  // Destruye la tabla existente
        // $('#sale_month_turn_base_table thead').empty(); // Limpia el encabezado
        // $('#sale_month_turn_base_table tbody').empty(); // Limpia el cuerpo
        // $('#sale_month_turn_base_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from3').value;
    var untilDate = document.getElementById('until3').value;
    var zona = document.getElementById('zona3').value;

    $('#sale_month_turn_base_table thead').prepend($('#sale_month_turn_base_table thead tr').clone().addClass('filter'));
    $('#sale_month_turn_base_table thead tr.filter th').each(function (index) {
        col = $('#sale_month_turn_base_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#sale_month_turn_base_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#sale_month_turn_base_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let sale_month_turn_base_table =$('#sale_month_turn_base_table').DataTable({
        order: [0, "asc"],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 50,
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
                'zona':zona
            },
            url: '/commercial/sale_month_turn_base_table',
            error: function() {
                $('#sale_month_turn_base_table').waitMe('hide');
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
        columns: [
            {'data': 'Año'},
            {'data': 'Mes'},
            {'data': 'Turno'},
            {'data': 'Producto'},
            {'data': 'CodGasolinera'},
            {'data': 'Estacion'},
            {'data': 'CodProducto'},
            {'data': 'VentasReales'},
            {'data': 'MontoVendido'},
            {'data': 'CodEmp'},
            {'data': 'den'},
            {'data': 'estructura'}
        ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
           
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
        footerCallback: function (row, data, start, end, display) {
        }
    });
}

async function sale_week_zone_table(){
    if ($.fn.DataTable.isDataTable('#sale_week_zone_table')) {
        $('#sale_week_zone_table').DataTable().destroy();  // Destruye la tabla existente
        $('#sale_week_zone_table thead').empty(); // Limpia el encabezado
        $('#sale_week_zone_table tbody').empty(); // Limpia el cuerpo
        $('#sale_week_zone_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    var dynamicColumns = generateSaleWeekZoneColumns(fromDate, untilDate);


   var sale_week_zone_table =$('#sale_week_zone_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 150,
         fixedColumns: {
            leftColumns: 3
         },
        buttons: [
            { extend: 'excel', className: 'd-none' },
            { extend: 'pdf', className: 'd-none', text: 'PDF' },
            { extend: 'print', className: 'd-none' }
        ],
        ajax: {
            method: 'POST',
            data: {
                'fromDate':fromDate,
                'untilDate':untilDate,
                'dinamicColumns': dynamicColumns
            },
            url: '/commercial/sale_week_zone_table',
            error: function() {
                $('#sale_week_zone_table').waitMe('hide');
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
            },
            complete: function () {
                $('.table-responsive').removeClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        destroy: true, 
        // rowId: 'id_grupo',
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            // $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {

        }
    });
    $('.table-responsive').removeClass('loading');

    $('.sale_week_zone_table').on('click', function () {
        sale_week_zone_table.clear().draw();
        sale_week_zone_table.ajax.reload();
        $('#sale_week_zone_table').waitMe('hide');
    });
}

function generateSaleWeekZoneColumns(fromDate, untilDate) {
    const startDate = new Date(fromDate + "T00:00:00");
    const endDate = new Date(untilDate + "T00:00:00");
    const columns = [];
    let currentWeekStart = new Date(startDate);
    
    // Definir columnas para DataTables
    columns.push(
        { data: 'Zona', title: 'Zona',className: 'text-left text-nowrap table-info' },
        { data: 'Estacion', title: 'Estacion', className: 'text-left text-nowrap table-info' },
    );

    // Crear la estructura del thead
    let thead = $('#sale_week_zoneHeadersTable');
    thead.empty(); // Limpiar el contenido existente
    // Crear el elemento tr usando jQuery
    let headerRow = $('<tr>');

    // Añadir las columnas fijas
    headerRow.append('<th>Zona</th>');
    headerRow.append('<th>Estación</th>');

    // Iterar por semanas dentro del rango
    while (currentWeekStart <= endDate) {
        const { weekYear, weekNumber } = getISOWeekYearAndNumber(currentWeekStart);
        let data_monto = `${weekYear}_${weekNumber}`;

        columns.push({
            data: data_monto,
            title: `Sem ${weekNumber} Monto`,
            render: $.fn.dataTable.render.number(',', '.', 2, '$'),
            className: 'text-end text-nowrap '
        });

        // Añadir el th a la fila
        headerRow.append(`<th>Sem ${weekNumber}</th>`);
        // Avanzar a la siguiente semana
        currentWeekStart.setDate(currentWeekStart.getDate() + ((8 - currentWeekStart.getDay()) % 7 || 7));
    }

    // Añadir la fila completa al thead
    thead.append(headerRow);

    return columns;
}

    async function sales_indicators_table(){
        if ($.fn.DataTable.isDataTable('#sales_indicators_table')) {
            $('#sales_indicators_table').DataTable().destroy();  // Destruye la tabla existente
            $('#sales_indicators_table thead').empty(); // Limpia el encabezado
            $('#sales_indicators_table tbody').empty(); // Limpia el cuerpo
            $('#sales_indicators_table tfoot').empty(); // Limpia el pie de tabla si lo usas
        }
        var fromDate = document.getElementById('from').value;
        var untilDate = document.getElementById('until').value;
        var zona = document.getElementById('zona').value;

        var dynamicColumns = GenerateSalesIndicatorsColumns(fromDate, untilDate,'sales_indicators_table');
        let sales_indicators_table =$('#sales_indicators_table').DataTable({
            order: [0, "asc"],
            colReorder: false,
            // columnDefs: [{ visible: true, targets: groupColumn }],
            dom: '<"top"Bf>rt<"bottom"lip>',
            // pageLength: 150,
            ordering: true,
            scrollY: '700px',
            scrollX: true,
            scrollCollapse: true,
            paging: false,
            fixedColumns: {
                leftColumns: 2,
            },
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
                    'total':false,
                    'fromDate':fromDate,
                    'untilDate':untilDate,
                    'zona':zona,
                    'dinamicColumns': dynamicColumns
                },
                url: '/commercial/sales_indicators_table',
                error: function() {
                    $('#sales_indicators_table').waitMe('hide');
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
                },
                complete: function () {
                    $('.table-responsive').removeClass('loading');
                }
            },
            deferRender: true,
            columns: dynamicColumns,
            destroy: true, 
            createdRow: function (row, data, dataIndex) {
                $('td', row).each(function (index) {
                    if (index > 1) { // A partir de la tercera columna (índice 2)
                        $(this).addClass('text-end');
                    }

                });
            },
            initComplete: function () {
                $('.table-responsive').removeClass('loading');
                // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
                highlightNegativeNumbers('sales_indicators_table'); // Llamar una vez al inicializar

            },
            footerCallback: function (row, data, start, end, display) {
                var api = this.api();
            
                // Función para convertir los valores a números (eliminando $, %, , etc.)
                var intVal = function (i) {
                    return typeof i === 'string'
                        ? i.replace(/[\$,]/g, '').replace('%', '') * 1
                        : typeof i === 'number'
                        ? i
                        : 0;
                };
            
                // Iteramos sobre todas las columnas dinámicas (después de la segunda columna)
                api.columns().every(function (index) {
                    if (index > 1) { 
                        var columnTitle = $(api.column(index).header()).text().trim(); // Obtiene el título de la columna
                        var dataColumn = api.column(index, { page: 'all' }).data(); // Obtiene los datos de la columna
                        if (columnTitle.includes("Alcance  Presupuesto %")) {
                            // Si es una columna de "Cumplimiento", calcular el promedio
                            var validValues = dataColumn.map(intVal).filter(val => !isNaN(val)); // Filtrar solo valores numéricos
                            var avg = validValues.length ? validValues.reduce((a, b) => a + b, 0) / validValues.length : 0;
                            avg  = avg.toFixed(2);
                            avg = avg + '%';
                            $(api.column(index).footer()).html( avg);
                        } else {
                            // Si no es "Cumplimiento", calcular la suma
                            var sum = dataColumn.reduce((a, b) => intVal(a) + intVal(b), 0);
                            $(api.column(index).footer()).html($.fn.dataTable.render.number(',', '.', 2, '$').display(sum));
                        }
                    }
                });
            }

        });
        $('#filtroProducto').on('change', function () {
            const value = $(this).val();
            // Asumiendo que la columna "Producto" está en el índice 1
            $('#sales_indicators_table').DataTable()
                .column(1)
                .search(value)
                .draw();
        });


        $('#filtro-sales_indicators_table input').on('keyup  change clear', function () {
            sales_indicators_table
                .column(0).search($('#Estacion').val().trim())
                .draw();
        });
        $('.sales_indicators_table').on('click', function () {
            sales_indicators_table.clear().draw();
            sales_indicators_table.ajax.reload();
            $('#sales_indicators_table').waitMe('hide');
        });
    }
    async function sales_indicators_totals_table(){
        if ($.fn.DataTable.isDataTable('#sales_indicators_totals_table')) {
            $('#sales_indicators_totals_table').DataTable().destroy();  // Destruye la tabla existente
            $('#sales_indicators_totals_table thead').empty(); // Limpia el encabezado
            $('#sales_indicators_totals_table tbody').empty(); // Limpia el cuerpo
            $('#sales_indicators_totals_table tfoot').empty(); // Limpia el pie de tabla si lo usas
        }
        var fromDate = document.getElementById('from2').value;
        var untilDate = document.getElementById('until2').value;
        var zona = document.getElementById('zona2').value;
    
        var dynamicColumns = GenerateSalesIndicatorsColumns(fromDate, untilDate,'sales_indicators_totals_table');
        let sales_indicators_totals_table =$('#sales_indicators_totals_table').DataTable({
            order: [0, "asc"],
            colReorder: false,
            // columnDefs: [{ visible: true, targets: groupColumn }],
            dom: '<"top"Bf>rt<"bottom"lip>',
            // pageLength: 150,
            ordering: true,
            scrollY: '700px',
            scrollX: true,
            scrollCollapse: true,
            paging: false,
             fixedColumns: {
                leftColumns: 2,
             },
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
                    'total':true,
                    'fromDate':fromDate,
                    'untilDate':untilDate,
                    'zona':zona,
                    'dinamicColumns': dynamicColumns
                },
                url: '/commercial/sales_indicators_table',
                error: function() {
                    $('#sales_indicators_totals_table').waitMe('hide');
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
                },
                complete: function () {
                    $('.table-responsive').removeClass('loading');
                }
            },
            deferRender: true,
            columns: dynamicColumns,
            destroy: true, 
            createdRow: function (row, data, dataIndex) {
                $('td', row).each(function (index) {
                    if (index > 1) { // A partir de la tercera columna (índice 2)
                        $(this).addClass('text-end');
                    }
    
                });
                if (data['producto']=='TOTAL') {
                    $('td:eq(0)', row).addClass('total_bg');
                    $('td:eq(1)', row).addClass('total_bg');
    
                    // Aplicar la clase 'total_bg2' al resto de las columnas
                    $('td:gt(1)', row).addClass('total_bg2');
                }
            },
            initComplete: function () {
                $('.table-responsive').removeClass('loading');
                // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
                highlightNegativeNumbers('sales_indicators_totals_table'); // Llamar una vez al inicializar
    
            },
            footerCallback: function (row, data, start, end, display) {
                var api = this.api();
            
                // Función para convertir los valores a números (eliminando $, %, , etc.)
                var intVal = function (i) {
                    return typeof i === 'string'
                        ? i.replace(/[\$,]/g, '').replace('%', '') * 1
                        : typeof i === 'number'
                        ? i
                        : 0;
                };
            
                // Iteramos sobre todas las columnas dinámicas (después de la segunda columna)
                api.columns().every(function (index) {
                    if (index > 1) { 
                        var columnTitle = $(api.column(index).header()).text().trim(); // Obtiene el título de la columna
                        var dataColumn = api.column(index, { page: 'all' }).data(); // Obtiene los datos de la columna
                        if (columnTitle.includes("Alcance  Presupuesto %")) {
                            // Si es una columna de "Cumplimiento", calcular el promedio
                            var validValues = dataColumn.map(intVal).filter(val => !isNaN(val)); // Filtrar solo valores numéricos
                            var avg = validValues.length ? validValues.reduce((a, b) => a + b, 0) / validValues.length : 0;
                            avg  = avg.toFixed(2);
                            avg = avg + '%';
                            $(api.column(index).footer()).html( avg);
                        } else {
                            // Si no es "Cumplimiento", calcular la suma
                            var sum = dataColumn.reduce((a, b) => intVal(a) + intVal(b), 0);
            
                            $(api.column(index).footer()).html($.fn.dataTable.render.number(',', '.', 2, '$').display(sum));
                        }
                    }
                });
            }
            
            
        });
    
        $('#filtroProducto2').on('change', function () {
            const value = $(this).val();
            // Asumiendo que la columna "Producto" está en el índice 1
            $('#sales_indicators_totals_table').DataTable()
                .column(1)
                .search(value)
                .draw();
        });
        $('#filtro-sales_indicators_totals_table input').on('keyup  change clear', function () {
            sales_indicators_totals_table
                .column(0).search($('#Estacion2').val().trim())
                .draw();
          });
        $('.sales_indicators_totals_table').on('click', function () {
            sales_indicators_totals_table.clear().draw();
            sales_indicators_totals_table.ajax.reload();
            $('#sales_indicators_totals_table').waitMe('hide');
        });
    }
    function GenerateSalesIndicatorsColumns(fromDate, untilDate, table) {
        const startDate = new Date(fromDate + "T00:00:00"); // Asegurarte de usar el inicio del día
        const endDate = new Date(untilDate + "T00:00:00");
        const columns = [];
        const monthNames = [
            "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio",
            "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"
        ];

        columns.push(
            { data: 'Estacion',title:'Venta <br> Mensual.Estacion	', className: 'text-left text-nowrap column_estation border_table' },
            { data: 'producto', title: 'Venta <br> Mensual.Producto', className: 'text-left text-nowrap column_estation border_table' },
        );

        let theadHTML = '<tr><th rowspan="2" class="style_heather">Estación</th><th rowspan="2" class="style_heather">Producto</th>';
        let subHeaderHTML = '<tr>';
        let tfootHTML = '<tr><th>Estación</th><th>Producto</th>';
    
        let currentMonth = new Date(startDate.getFullYear(), startDate.getMonth(), 1);

        while (currentMonth <= endDate) {
            let YearName = currentMonth.getFullYear();
            let MonthNumber = currentMonth.getMonth() + 1; // Asegura formato 2 dígitos
            let monthYear = `${YearName}_${MonthNumber}`;
            let monthName = monthNames[currentMonth.getMonth()];

            // Encabezado del mes (ocupará 5 columnas)
            theadHTML += `<th colspan="5" class="text-center style_heather border_table">${monthName} ${YearName}</th>`;

            // Subencabezados para las métricas
            subHeaderHTML += `
                <th class="style_heather border_table" >Ventas</th >
                <th class="style_heather border_table" >Proyección</th >
                <th class="style_heather border_table" >Presupuesto Mensual</th >
                <th class="style_heather border_table" >Alcance Presupuesto	</th >
                <th class="style_heather border_table" >Dif Presupuesto VS Proyeccion</th>
            `;

            // Agregar columnas a DataTables
            columns.push(
                { 
                    data: `Ventas_${monthYear}`, 
                    title: `Venta Mensual`, 
                    render: $.fn.dataTable.render.number(',', '.', 2), 
                    className: 'border_left' },
                { 
                    data: `Proyeccion_${monthYear}`, 
                    title: `Proyección <br> Mensual`, 
                    render: $.fn.dataTable.render.number(',', '.', 2), 
                    className: 'border_center ' },
                { 
                    data: `Presupuesto_${monthYear}`, 
                    title: `Presupuesto <br> Mensual`, 
                    render: $.fn.dataTable.render.number(',', '.', 2), 
                    className: ' border_center' },
                { 
                    data: `Cumplimiento_${monthYear}`,
                    title: `Alcance <br> Presupuesto %`,
                    render: $.fn.dataTable.render.number(',', '.', 2),
                    className: ' border_center' },
                { 
                    data: `Diferencia_${monthYear}`, 
                    title: `Dif Presupuesto <br> VS Proyección`, 
                    render: $.fn.dataTable.render.number(',', '.', 2), 
                    className: 'border_right' 
                }
            );

            // Agregar espacios vacíos al pie de tabla
            tfootHTML += `<th></th><th></th><th></th><th></th><th></th>`;

            // Avanzar al siguiente mes
            currentMonth.setMonth(currentMonth.getMonth() + 1);
        }
        // Cerrar las filas correctamente
        theadHTML += '</tr>' + subHeaderHTML + '</tr>';
        tfootHTML += '</tr>';

        // Insertar encabezados y pie en la tabla
        let tablename = document.getElementById(table);
        let thead = tablename.getElementsByTagName('thead')[0];
        let tfoot = tablename.getElementsByTagName('tfoot')[0];

        thead.innerHTML = theadHTML;
         tfoot.innerHTML = tfootHTML;

        return columns;
        // $('#{table} thead').html(theadHTML);
        // $('#{table} tfoot').html(tfootHTML);

    }



    function highlightNegativeNumbers(tableId) {
        $('#' + tableId + ' tbody tr').each(function () {
            $(this).find('td').each(function () {
                var cellValue = $(this).text().replace(/[\$,]/g, '').trim(); // Limpiar el valor de la celda
                var numValue = parseFloat(cellValue); // Convertir a número
                $(this).css('color', ''); 
                if (!isNaN(numValue) && numValue < 0) {
                    // $(this).css({ 'color': '' });
                    $(this).addClass('text_negative'); // Aplicar estilos
                }
            });
        }); 
    }


   
    async function lubricants_table_month(){                                                        //Lubricantes por mes
        if ($.fn.DataTable.isDataTable('#lubricants_table_month')) {
            $('#lubricants_table_month').DataTable().destroy();//destruye la tabla existente
            $('#lubricants_table_month thead').empty();//limpia el encabezado
            $('#lubricants_table_month tbody').empty();//limpia el cuerpo
            $('#lubricants_table_month tfoot').empty();//limpia el pie de tabla si lo usas
        }
        var fromDate = document.getElementById('from_month').value;
        var untilDate = document.getElementById('until_month').value;
    
        var dynamicColumns = generateMonthColumns(fromDate, untilDate);
        $('#lubricants_table_month').DataTable({
            order: [0, "asc"],
            colReorder: false,
            dom: '<"top"Bf>rt<"bottom"lip>',
            pageLength: 150,
            fixedColumns: {
                leftColumns: 3
            },
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
                    'dinamicColumns': dynamicColumns
                },
                url: '/commercial/lubricants_table_month',
                error: function() {
                    alert("Error al obtener datos");
                }
            },
            deferRender: true,
            columns: dynamicColumns,
            destroy: true,
            rowId: 'id_grupo',
            createdRow: function (row, data, dataIndex) {
            },
            initComplete: function () {
                $('.table-responsive').removeClass('loading');
            },
            footerCallback: function (row, data, start, end, display) {
    
            }
        });
        $('.lubricants_table_month').on('click', function () {
            lubricants_table_month.clear().draw();
            lubricants_table_month.ajax.reload();
            $('#lubricants_table_month').waitMe('hide');
        });
    }
    function generateMonthColumns(fromDate, untilDate) {
        const startDate = new Date(fromDate + "T00:00:00");
        const endDate = new Date(untilDate + "T00:00:00");
        const columns = [];
        let currentMonthStart = new Date(startDate.getFullYear(), startDate.getMonth(), 1);
        
        columns.push(
            { data: 'codigo', title: 'Código' },
            { data: 'Estacion', title: 'Estación', className: 'text-left text-nowrap' },
            { data: 'producto', title: 'Producto', className: 'text-left text-nowrap' }
        );
        
        let thead = $('#pivot_monthly_dispatches_table_head');
        thead.html('');
        thead.empty();
        thead.append('<tr>');
        thead.append('<th>Código</th><th>Estación</th><th>Producto</th>');
        
        while (currentMonthStart <= endDate) {
            let year = currentMonthStart.getFullYear();
            let month = (currentMonthStart.getMonth() + 1).toString();
            let data_monto = `${year}_${month}_monto`;
            let data_cantidad = `${year}_${month}_cantidad`;
    
            columns.push(
                {
                    data: data_monto,
                    title: `${year}-${month} Monto`,
                    render: $.fn.dataTable.render.number(',', '.', 2, '$'),
                    className: 'text-end text-nowrap table-info'
                },
                {
                    data: data_cantidad,
                    title: `${year}-${month} Cantidad`,
                    className: 'text-end text-nowrap'
                }
            );
    
            thead.append(`<th>Mes ${month} Monto</th>`);
            thead.append(`<th>Mes ${month} Cantidad</th>`);
    
            currentMonthStart.setMonth(currentMonthStart.getMonth() + 1);
        }
        thead.append('</tr>');
        
        return columns;
    }
    async function mounth_company_table(){
        if ($.fn.DataTable.isDataTable('#mounth_company_table')) {
            $('#mounth_company_table').DataTable().destroy();  // Destruye la tabla existente
            $('#mounth_company_table thead').empty(); // Limpia el encabezado
            $('#mounth_company_table tbody').empty(); // Limpia el cuerpo
            $('#mounth_company_table tfoot').empty(); // Limpia el pie de tabla si lo usas
        }
        var fromDate = document.getElementById('from4').value;
        var untilDate = document.getElementById('until4').value;
        var company = document.getElementById('company4').value;

        var dynamicColumns = getMounthCompanyColumns(fromDate, untilDate,'mounth_company_table');
        updateTableHeaders(fromDate, untilDate,'mounth_company_table');
        let mounth_company_table =$('#mounth_company_table').DataTable({
            order: [0, "asc"],
            colReorder: false,
            // columnDefs: [{ visible: true, targets: groupColumn }],
            dom: '<"top"Bf>rt<"bottom"lip>',
            // pageLength: 150,
            ordering: true,
            scrollY: '700px',
            scrollX: true,
            scrollCollapse: true,
            paging: false,
            fixedColumns: {
                leftColumns: 3,
            },
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
                    'company':company,
                    'total':0,
                    'dinamicColumns': dynamicColumns
                },
                url: '/commercial/mounth_company_table',
                error: function() {
                    $('#mounth_company_table').waitMe('hide');
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
                },
                complete: function () {
                    $('.table-responsive').removeClass('loading');
                }
            },
            deferRender: true,
            columns: dynamicColumns,
            destroy: true, 
            createdRow: function (row, data, dataIndex) {
            },
            initComplete: function () {
                $('.table-responsive').removeClass('loading');
                // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
            },
            footerCallback: function (row, data, start, end, display) {
                var api = this.api();
                // Función para obtener sumatoria de una columna
                var intVal = function (i) {
                    return typeof i === 'string' 
                        ? i.replace(/[\$,]/g, '') * 1 
                        : typeof i === 'number' 
                            ? i 
                            : 0;
                };
                // Recorremos las columnas desde la tercera en adelante
                api.columns().every(function (index) {
                    if (index > 2) { // Desde la tercera columna en adelante
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
                            <div>Filtrado: ${filteredSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                            <div>Total: ${totalSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                        `);
                    }
                });
            }
        }).off('xhr.dt').on('xhr.dt', function(e, settings, json, xhr) {
            if (json && json.data) {
                generateCardsCompany(json.data, fromDate, untilDate, company);
            }
        });
        $('#filtro-mounth_company_table input').on('keyup  change clear', function () {
            mounth_company_table
                .column(0).search($('#Empresa4').val().trim())
                .column(1).search($('#Descripcion4').val().trim())
                .column(2).search($('#MedioPago4').val().trim())
                .draw();
          });
        $('.mounth_company_table').on('click', function () {
            mounth_company_table.clear().draw();
            mounth_company_table.ajax.reload();
            $('#mounth_company_table').waitMe('hide');
        });
    }
    async function generateCardsCompany(data,fromDate,untilDate,company) {
        const currentData = data; // Los datos que ya usas para renderCards

        var lastYearFrom = subtracYear(fromDate);
        var lastYearUntil = subtracYear(untilDate);
        var dynamicColumns = getMounthCompanyColumns(lastYearFrom, lastYearUntil,'mounth_company_table');
        try {
            const response = await fetch('/commercial/mounth_company_table2', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json, text/javascript, */*',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                credentials: 'include',
                body: `fromDate=${lastYearFrom}&untilDate=${lastYearUntil}&company=${company}&total=${0}&dinamicColumns=${encodeURIComponent(JSON.stringify(dynamicColumns))}`
            });
            const jsonData = await response.json();
            if (jsonData && jsonData.data) {
                const previousData = jsonData.data;
                renderComparativeCards(currentData, previousData, 'comparativeContainer');

            }
        } catch (error) {
            console.error("Error al obtener los datos del año anterior:", error);
        }
        
    }
    function groupAndSumCompay(data) {
        return data.reduce((acc, item) => {
            let Empresa = item.Empresa;
            let paymentMethod = item.MedioPago;
            if (!acc[Empresa]) {// Si el grupo no existe en el acumulador, lo inicializamos con su total general
                acc[Empresa] = { 
                    Grupo: Empresa, 
                    TotalSum: 0,  // Total general del grupo
                    MediosPago: {} // Detalle de medios de pago
                };
            }
            if (!acc[Empresa].MediosPago[paymentMethod]) {// Si el medio de pago no existe dentro del grupo, lo inicializamos
                acc[Empresa].MediosPago[paymentMethod] = { 
                    MedioPago: paymentMethod, 
                    TotalSum: 0 // Total por medio de pago
                };
            }
            Object.keys(item).forEach(key => {// Recorremos las claves del objeto y sumamos solo las numéricas (excluyendo las especificadas)
                if (![ "Empresa", "Descripcion", "MedioPago", "Total"].includes(key)) {
                    let value = parseFloat(item[key]) || 0;

                    // Sumamos al total del grupo
                    acc[Empresa].TotalSum += value;
                    // Sumamos al total del medio de pago dentro del grupo
                    acc[Empresa].MediosPago[paymentMethod].TotalSum += value;
                }
            });
            return acc;
        }, {});
    }
    function groupAndSumEstation(data) {
        return data.reduce((acc, item) => {
            let Estacion = item.Estacion;
            let paymentMethod = item.MedioPago;
            if (!acc[Estacion]) {// Si el grupo no existe en el acumulador, lo inicializamos con su total general
                acc[Estacion] = { 
                    Grupo: Estacion, 
                    TotalSum: 0,  // Total general del grupo
                    MediosPago: {} // Detalle de medios de pago
                };
            }
            if (!acc[Estacion].MediosPago[paymentMethod]) {// Si el medio de pago no existe dentro del grupo, lo inicializamos
                acc[Estacion].MediosPago[paymentMethod] = { 
                    MedioPago: paymentMethod, 
                    TotalSum: 0 // Total por medio de pago
                };
            }
            Object.keys(item).forEach(key => {// Recorremos las claves del objeto y sumamos solo las numéricas (excluyendo las especificadas)
                if (![ "Estacion", "Descripcion", "MedioPago", "Total"].includes(key)) {
                    let value = parseFloat(item[key]) || 0;

                    // Sumamos al total del grupo
                    acc[Estacion].TotalSum += value;
                    // Sumamos al total del medio de pago dentro del grupo
                    acc[Estacion].MediosPago[paymentMethod].TotalSum += value;
                }
            });
            return acc;
        }, {});
    }
    function subtracYear(dateStr){
        const date = new Date(dateStr);
        date.setFullYear(date.getFullYear() - 1);
        return date.toISOString().split('T')[0];
    }
    function buildPaymentTable(mediosPago, totalSum) {
        let table = document.createElement('table');
        table.className = 'table table_card table-sm';
        let thead = document.createElement('thead');
        thead.innerHTML = `
            <tr>
                <th>Medio de Pago</th>
                <th>Monto</th>
                <th>Porcentaje</th>
            </tr>
        `;
        table.appendChild(thead);
        let tbody = document.createElement('tbody');
        Object.values(mediosPago).forEach(med => {
            let tr = document.createElement('tr');
            let porcentaje = (med.TotalSum / totalSum) * 100;
            tr.innerHTML = `
                <td>${med.MedioPago}</td>
                <td class="text-end">${Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN' }).format(med.TotalSum)}</td>
                <td class="text-end">${porcentaje.toFixed(2)}%</td>
            `;
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        return table;
    }
    // Función que renderiza las cards comparativas
    function renderComparativeCards(currentData, previousData, containerId) {
        console.log(containerId);
        if(containerId == 'comparativeEstationContainer'){
            var currentGroups = groupAndSumEstation(currentData);
            var previousGroups = groupAndSumEstation(previousData);
        }else{
            var currentGroups = groupAndSumCompay(currentData);
            var previousGroups = groupAndSumCompay(previousData);
        }
        const allGroups = new Set([...Object.keys(currentGroups), ...Object.keys(previousGroups)]);
    
        let container = document.getElementById(containerId);
        container.innerHTML = '';
        allGroups.forEach(group => {
            // Título para el grupo
            let groupTitle = document.createElement('h4');
            groupTitle.textContent = group;
            groupTitle.style.marginLeft = '15px';
            container.appendChild(groupTitle);

            // Contenedor para la comparación (fila en display flex)
            let rowDiv = document.createElement('div');
            rowDiv.style.display = 'flex';
            rowDiv.style.alignItems = 'flex-start';
            rowDiv.style.gap = '10px';
            rowDiv.style.marginBottom = '20px';

            // Definimos totales para el grupo
            const previousTotal = previousGroups[group] ? previousGroups[group].TotalSum : 0;
            const currentTotal  = currentGroups[group]  ? currentGroups[group].TotalSum  : 0;

            // Card para el Año Pasado
            let previousCard = CreatePreviosCard(group, previousGroups, previousTotal);
            let currentCard = CreateCurrentCard(group, currentGroups, currentTotal);
            let DiffCard = CreateDiffCard(group, previousGroups, currentGroups, previousTotal, currentTotal);


            rowDiv.appendChild(previousCard);
            rowDiv.appendChild(currentCard);
            rowDiv.appendChild(DiffCard);
            container.appendChild(rowDiv);
        });
    }

    function CreateDiffCard(group, previousGroups, currentGroups, previousTotal, currentTotal) {
        let diffCard = document.createElement('div');
        diffCard.style.flex = '1 0 0%'; // Ancho fijo para la card de diferencia
        diffCard.className = 'card card_group';
        let diffCardBody = document.createElement('div');
        diffCardBody.className = 'card-body body_card';
        let diffTitle = document.createElement('h5');
        diffTitle.className = 'card-title';
        diffTitle.textContent = 'Diferencia';
        diffCardBody.appendChild(diffTitle);

        // Diferencia total
        let diff = currentTotal - previousTotal;
        let diffPercentage = previousTotal !== 0 ? (diff / previousTotal) * 100 : 0;
        let diffColor = diff > 0 ? 'green' : (diff < 0 ? 'red' : 'gray');

        let diffP = document.createElement('p');
        diffP.className = 'card-text';
        diffP.innerHTML = `<strong>Total: ${Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(diff)}</strong>`;
        diffP.style.color = diffColor;
        diffCardBody.appendChild(diffP);

        // Generamos la tabla de diferencias por medio de pago
        let previousMedios = previousGroups[group] ? previousGroups[group].MediosPago : {};
        let currentMedios  = currentGroups[group] ? currentGroups[group].MediosPago : {};
        let diffTable = buildDiffPaymentTable(previousMedios, currentMedios,previousTotal, currentTotal);
        diffCardBody.appendChild(diffTable);

        diffCard.appendChild(diffCardBody);
        return diffCard;
    }
    function  CreateCurrentCard(group, currentGroups, currentTotal) {
        let currentCard = document.createElement('div');
            currentCard.style.flex = '1';
            currentCard.className = 'card card_group';
            let currentCardBody = document.createElement('div');
            currentCardBody.className = 'card-body body_card';
            let currentTitle = document.createElement('h5');
            currentTitle.className = 'card-title';
            currentTitle.textContent = 'Año Actual';
            currentCardBody.appendChild(currentTitle);
            if (currentGroups[group]) {
                let currentTotalP = document.createElement('p');
                currentTotalP.className = 'card-text';
                currentTotalP.innerHTML = `<strong>Total: ${Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN' }).format(currentTotal)}</strong>`;
                currentCardBody.appendChild(currentTotalP);
                let currentTable = buildPaymentTable(currentGroups[group].MediosPago, currentTotal);
                currentCardBody.appendChild(currentTable);
            } else {
                let noData = document.createElement('p');
                noData.textContent = 'Sin datos';
                currentCardBody.appendChild(noData);
            }
            currentCard.appendChild(currentCardBody);
            return currentCard;
    }

    function CreatePreviosCard(group, previousGroups, previousTotal) {
        let previousCard = document.createElement('div');
        previousCard.style.flex = '1';
        previousCard.className = 'card card_group';
        let previousCardBody = document.createElement('div');
        previousCardBody.className = 'card-body body_card';
        let previousTitle = document.createElement('h5');
        previousTitle.className = 'card-title';
        previousTitle.textContent = 'Año Anterior';
        previousCardBody.appendChild(previousTitle);
        if (previousGroups[group]) {
            let previousTotalP = document.createElement('p');
            previousTotalP.className = 'card-text';
            previousTotalP.innerHTML = `<strong>Total: ${Intl.NumberFormat('es-MX', { style:'currency', currency:'MXN' }).format(previousTotal)}</strong>`;
            previousCardBody.appendChild(previousTotalP);
            let previousTable = buildPaymentTable(previousGroups[group].MediosPago, previousTotal);
            previousCardBody.appendChild(previousTable);
        } else {
            let noData = document.createElement('p');
            noData.textContent = 'Sin datos';
            previousCardBody.appendChild(noData);
        }
        previousCard.appendChild(previousCardBody);

        return previousCard;
    }
    
    function buildDiffPaymentTable(previousMedios = {}, currentMedios = {}, previousTotalGroup = 0, currentTotalGroup = 0) {
        // Unión de los nombres de medios de pago
        const allMedios = new Set([
            ...Object.keys(previousMedios),
            ...Object.keys(currentMedios)
        ]);
        
        let table = document.createElement('table');
        table.className = 'table table_card table-sm';
        
        // Encabezado: se muestran "Medio de Pago", "Diferencia Monto" y "Diferencia %"
        let thead = document.createElement('thead');
        thead.innerHTML = `
            <tr>
                <th>Medio de Pago</th>
                <th>Diferencia Monto</th>
                <th>Diferencia %</th>
            </tr>
        `;
        table.appendChild(thead);
        
        // Cuerpo de la tabla
        let tbody = document.createElement('tbody');
        allMedios.forEach(medio => {
            const previousValue = previousMedios[medio] ? previousMedios[medio].TotalSum : 0;
            const currentValue  = currentMedios[medio]  ? currentMedios[medio].TotalSum  : 0;
            const diffAmount = currentValue - previousValue;
            
            // Calcular el porcentaje que representa cada medio en el total del grupo
            const previousPercent = previousTotalGroup !== 0 ? (previousValue / previousTotalGroup) * 100 : 0;
            const currentPercent  = currentTotalGroup !== 0 ? (currentValue / currentTotalGroup) * 100 : 0;
            const diffPercentage = currentPercent - previousPercent;
            
            // Se asigna color en función de la diferencia de porcentajes
            const diffColor = diffAmount > 0 ? 'green' : (diffAmount < 0 ? 'red' : 'gray');
            
            let tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${medio}</td>
                <td class="text-end" style="color: ${diffColor};">${Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(diffAmount)}</td>
                <td class="text-end" style="color: ${diffColor};">
                    <strong>${diffPercentage.toFixed(2)}%</strong>
                </td>
            `;
            tbody.appendChild(tr);
        });
        
        table.appendChild(tbody);
        return table;
    }
    function getMounthCompanyColumns(fromDate, untilDate, tableId) {
        const startDate = new Date(fromDate + "T00:00:00");
        const endDate = new Date(untilDate + "T00:00:00");
        const columns = [];
        const monthNames = [
            "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio",
            "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"
        ];
        if (tableId == "mounth_estation_table") {
            columns.push({ data: 'Estacion', title: 'Estacion', className: 'text-left text-nowrap table-info' });
        }else{
            columns.push({ data: 'Empresa', title: 'Empresa', className: 'text-left text-nowrap table-info' });

        }

        columns.push(
            { data: 'Descripcion', title: 'Descripcion', className: 'text-left text-nowrap table-info' },
            { data: 'MedioPago', title: 'MedioPago', className: 'text-left text-nowrap table-info' },
        );

        let currentMonth = new Date(startDate.getFullYear(), startDate.getMonth(), 1);
        while (currentMonth <= endDate) {
            const YearName = currentMonth.getFullYear();
            const monthYear = `${YearName}_${currentMonth.getMonth() + 1}`;
            const monthName = monthNames[currentMonth.getMonth()];
            columns.push({
                data: monthYear,
                title: `${monthName} ${YearName}`,
                render: $.fn.dataTable.render.number(',', '.', 2, '$'),
                className: 'text-end text-nowrap'
            });
            currentMonth.setMonth(currentMonth.getMonth() + 1);
        }
        columns.push({
            data: 'Total',
            title: 'Total',
            className: 'text-left text-nowrap table-info',
            render: $.fn.dataTable.render.number(',', '.', 2, '$'),
        });
        return columns;
    }

    function updateTableHeaders(fromDate, untilDate, tableId) {
        console.log(tableId);
        const startDate = new Date(fromDate + "T00:00:00");
        const endDate = new Date(untilDate + "T00:00:00");
        const monthNames = [
            "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio",
            "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"
        ];
        if (tableId == "mounth_estation_table") {
            var theadHTML = '<tr><th>Estacion</th><th>Descripcion</th><th>MedioPago</th>';
            var tfootHTML = '<tr><th>Estacion</th><th>Descripcion</th><th>MedioPago</th>';
        }else{

            var theadHTML = '<tr><th>Empresa</th><th>Descripcion</th><th>MedioPago</th>';
            var tfootHTML = '<tr><th>Empresa</th><th>Descripcion</th><th>MedioPago</th>';
        }

        let currentMonth = new Date(startDate.getFullYear(), startDate.getMonth(), 1);
        while (currentMonth <= endDate) {
            let monthName = monthNames[currentMonth.getMonth()];
            theadHTML += `<th>${monthName}</th>`;
            tfootHTML += `<th></th>`;
            currentMonth.setMonth(currentMonth.getMonth() + 1);
        }
    
        theadHTML += '<th>Total</th></tr>';
        tfootHTML += '<th></th></tr>';
    
        const table = document.getElementById(tableId);
        table.getElementsByTagName('thead')[0].innerHTML = theadHTML;
        table.getElementsByTagName('tfoot')[0].innerHTML = tfootHTML;
    }

    async function mounth_estation_table(){
        if ($.fn.DataTable.isDataTable('#mounth_estation_table')) {
            $('#mounth_estation_table').DataTable().destroy();  // Destruye la tabla existente
            $('#mounth_estation_table thead').empty(); // Limpia el encabezado
            $('#mounth_estation_table tbody').empty(); // Limpia el cuerpo
            $('#mounth_estation_table tfoot').empty(); // Limpia el pie de tabla si lo usas
        }
        var fromDate = document.getElementById('from5').value;
        var untilDate = document.getElementById('until5').value;
        var estation = document.getElementById('estation5').value;

        var dynamicColumns = getMounthCompanyColumns(fromDate, untilDate,'mounth_estation_table');
        updateTableHeaders(fromDate, untilDate,'mounth_estation_table');
        let mounth_estation_table =$('#mounth_estation_table').DataTable({
            order: [0, "asc"],
            colReorder: false,
            dom: '<"top"Bf>rt<"bottom"lip>',
            // pageLength: 150,
            ordering: true,
            scrollY: '700px',
            scrollX: true,
            scrollCollapse: true,
            paging: false,
             fixedColumns: {
                leftColumns: 3,
             },
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
                    'estation':estation,
                    'json':0,
                    'total':0,
                    'dinamicColumns': dynamicColumns
                },
                url: '/commercial/mounth_estation_table',
                error: function() {
                    $('#mounth_estation_table').waitMe('hide');
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
                },
                complete: function () {
                    $('.table-responsive').removeClass('loading');
                }
            },
            deferRender: true,
            columns: dynamicColumns,
            destroy: true, 
            createdRow: function (row, data, dataIndex) {
            },
            initComplete: function () {
                $('.table-responsive').removeClass('loading');
                // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
            },
            footerCallback: function (row, data, start, end, display) {
                var api = this.api();
                // Función para obtener sumatoria de una columna
                var intVal = function (i) {
                    return typeof i === 'string' 
                        ? i.replace(/[\$,]/g, '') * 1 
                        : typeof i === 'number' 
                            ? i 
                            : 0;
                };
                // Recorremos las columnas desde la tercera en adelante
                api.columns().every(function (index) {
                    if (index > 2) { // Desde la tercera columna en adelante
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
                            <div>Filtrado: ${filteredSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                            <div>Total: ${totalSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                        `);
                    }
                });
            }
        }).off('xhr.dt').on('xhr.dt', function(e, settings, json, xhr) {
            if (json && json.data) {
                 generateCardsEstation(json.data, fromDate, untilDate, estation);
            }
        });
        $('#filtro-mounth_estation_table input').on('keyup  change clear', function () {
            mounth_estation_table
                .column(0).search($('#Empresa4').val().trim())
                .column(1).search($('#Descripcion4').val().trim())
                .column(2).search($('#MedioPago4').val().trim())
                .draw();
          });
        $('.mounth_estation_table').on('click', function () {
            mounth_estation_table.clear().draw();
            mounth_estation_table.ajax.reload();
            $('#mounth_estation_table').waitMe('hide');
        });
    }

    async function generateCardsEstation(data,fromDate,untilDate,estation) {
        const currentData = data; // Los datos que ya usas para renderCards

        var lastYearFrom = subtracYear(fromDate);
        var lastYearUntil = subtracYear(untilDate);
        var dynamicColumns = getMounthCompanyColumns(lastYearFrom, lastYearUntil,'mounth_estation_table');
        try {
            const response = await fetch('/commercial/mounth_estation_table', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json, text/javascript, */*',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                credentials: 'include',
                body: `fromDate=${lastYearFrom}&untilDate=${lastYearUntil}&estation=${estation}&json=1&total=${0}&dinamicColumns=${encodeURIComponent(JSON.stringify(dynamicColumns))}`
            });
            const jsonData = await response.json();
            if (jsonData && jsonData.data) {

                const previousData = jsonData.data;
                renderComparativeCards(currentData, previousData, 'comparativeEstationContainer');

            }
        } catch (error) {
            console.error("Error al obtener los datos del año anterior:", error);
        }
        
    }

    async function upload_file_budget() {
        const fileInput = document.getElementById('file_to_upload');
        const date_budget = document.getElementById('date_budget').value;
    
        if (!date_budget) {
            toastr.error('Por favor, selecciona una fecha.', '¡Error!', { timeOut: 3000 });
            return;
        }
    
        const file = fileInput.files[0]; // Obtiene el primer archivo seleccionado
    
        if (!file) {
            toastr.error('Por favor, selecciona un archivo.', '¡Error!', { timeOut: 3000 });
            return;
        }
    
        $('.mistery_heather').addClass('loading');
        const formData = new FormData();
        formData.append('file_to_upload', file);
        formData.append('date_budget', date_budget);
    
        try {
            const response = await fetch('/commercial/import_file_budget', {
                method: 'POST',
                body: formData
            });
    
            const data = await response.json();
    
            if (data['success'] == false) {
                toastr.error(data['message'], '¡Error!', { timeOut: 3000 });
                $('.mistery_heather').removeClass('loading');
                fileInput.value = '';
                return;
            }
    
            if (data['success'] == true) {
                toastr.success('Archivo subido exitosamente ', '¡Éxito!', { timeOut: 3000 });
                $('.mistery_heather').removeClass('loading');
                fileInput.value = '';
                mistery_shopper_table();
                // setTimeout(() => {
                //     window.location.reload();
                // }, 2000);
            } 
        } catch (error) {
            console.error('Error al subir el archivo:', error);
            $('.mistery_heather').removeClass('loading');
            $('.mistery_heather').removeClass('loading');
    
            toastr.error('Hubo un problema al subir el archivo.', '¡Error!', { timeOut: 3000 });
        }
        $('.mistery_heather').removeClass('loading');
    
    }
    function download_format_budget(){
        fetch('/commercial/download_format_budget')
        .then(response => {
            if (!response.ok) {
                throw new Error('Error en la descarga del archivo');
            }
            return response.blob();
        })
        .then(blob => {
            const url = window.URL.createObjectURL(new Blob([blob]));
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = 'BudgetDocument.xlsx'; // Nombre del archivo a descargar
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
        })
        .catch(error => console.error('Error:', error));
    
     }
    