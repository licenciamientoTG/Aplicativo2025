console.log('controldispaches.js');



function actualizarDataTable() {
    var from = $('#from').val();
    var until = $('#until').val();
    var codgas = $('#codgas').val();
    var uuid = $('#uuid').val();
    var billed = $('#billed').val();
    console.log(billed);
    var timestamp = new Date().getTime();

    if (!codgas || codgas === "") {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una estación antes de continuar.</p>
            </div>`
        );
        return; // Detiene la ejecución de la función si no se seleccionó una estación
    }
    if ($.fn.DataTable.isDataTable('#datatables_dispatches')) {
        $('#datatables_dispatches').DataTable().destroy();
    }
    $('#element_hidden').removeAttr('hidden');
    $('#no_selected').attr('hidden',true);

    let datatables_dispatches =   $('#datatables_dispatches').DataTable({
            // scrollY: '700px',
            // scrollX: true,
            // scrollCollapse: true,
            // paging: false,
            // ordering: true,
            // colReorder: false,
            pageLength: 100,

           dom: '<"top"Bf>rt<"bottom"lip>',
           // order: [3, 'asc'],
           buttons: [
               {
                   extend: 'excel',
                   className: 'btn btn-outline-success',
                   text: '<i data-feather="download"> Excel',
               },
               {
                extend: 'pdf',
                className: 'btn btn-outline-info',
                text: ' PDF'
            },
           ],
           ajax: {
               method: 'POST',
               url: '/income/datatables_dispatches',
               data: {
                    'from': from,
                    'until': until,
                    'codgas': codgas,
                    'uuid': uuid,
                    'billed': billed
                },
               error: function() {
                   $('#historic_shortage_table').waitMe('hide');
                   $('.control_dispaches_table').removeClass('loading');
       
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
                   $('.control_dispaches_table').addClass('loading');
               },
            
           },
           deferRender: true,
           columns: [
               {'data': 'fecha'},
               {'data': 'hora_formateada'},
               {'data': 'turno'},
               {'data': 'despacho'},
               {'data': 'producto'},
               {'data': 'estacion'},
               {'data': 'empresa'},
               {'data': 'cliente_fac'},
               {'data': 'cantidad','render': $.fn.dataTable.render.number(',', '.', 3, '')},
               {'data': 'importe', 'render': $.fn.dataTable.render.number(',', '.', 2, '$')},
               {'data': 'precio', 'render': $.fn.dataTable.render.number(',', '.', 2, '$') },
               {'data': 'despachador'},
               {'data': 'tipo_pago'},
               {'data': 'factura'},
               {'data': 'FechaFactura'},

               {'data': 'UUID'},
               {'data': 'txtref'},
               {'data': 'rut'},
               {'data': 'denominacion'},
               {'data': 'codigo_cliente'},
               {'data': 'tipo_cliente'},
               {'data': 'tipo_cliente_aplicativo'},
               {'data': 'vehiculo'},
               {'data': 'placas'},
           ],
           rowId: 'despacho',
           createdRow: function (row, data, dataIndex) {
       
           },
           initComplete: function (settings, json) {
            //    $('.dt-buttons').addClass('d-none');
               $('.control_dispaches_table').removeClass('loading');
               agruparPorFactura(json.data);

           }
       });
    
    // Actualizar los datos del DataTable
    
    $('#filtro-datatables_dispatches input').on('keyup  change clear', function () {
        datatables_dispatches
        .column(0).search($('#fecha').val().trim())                // Fecha
        .column(1).search($('#hora_formateada').val().trim())      // Hora formateada
        .column(2).search($('#turno').val().trim())                // Turno
        .column(3).search($('#despacho').val().trim())             // Despacho
        .column(4).search($('#producto').val().trim())             // Producto
        .column(5).search($('#estacion').val().trim())             // Estación
        .column(6).search($('#empresa').val().trim())              // Empresa
        .column(7).search($('#cliente_fac').val().trim())          // Cliente facturación
        .column(8).search($('#cantidad').val().trim())             // Cantidad despachada
        .column(9).search($('#importe').val().trim())             // Importe
        .column(10).search($('#precio').val().trim())              // Precio
        .column(11).search($('#despachador').val().trim())         // Despachador
        .column(12).search($('#tipo_pago').val().trim())             // Factura
        .column(13).search($('#factura').val().trim())             // Factura
        .column(14).search($('#UUID').val().trim())                // UUID
        .column(15).search($('#txtref').val().trim())                // UUID
        .column(16).search($('#rut').val().trim())                 // RUT
        .column(17).search($('#denominacion').val().trim())                 // RUT
        .column(18).search($('#codigo_cliente').val().trim())      // Código cliente
        .column(19).search($('#tipo_cliente').val().trim())        // Tipo cliente
        .column(19).search($('#tipo_cliente_aplicativo').val().trim())        // Tipo cliente
        .column(20).search($('#vehiculo').val().trim())            // Vehículo
        .column(21).search($('#placas').val().trim())  
            .draw();
    });
    $('#limpiar').on('click', function () {
        $('#tipo_cliente').val(''); // Limpiar el campo de tipo de cliente
        $('.btn-outline-primary').removeClass('btn-selected'); // Eliminar la clase de todos los botones
    
        // Limpiar el filtro de la columna de tipo cliente en el DataTable y actualizarlo
        datatables_dispatches
            .column(19).search('') // Limpiar el filtro de tipo cliente
            .draw();
    });
    $('.btn-outline-primary').not('#limpiar').on('click', function () {
        var tipoCliente = $(this).text(); // Capturar el texto del botón seleccionado
    
        // Asignar el valor al campo de tipo cliente
        $('#tipo_cliente').val(tipoCliente);
    
        // Agregar la clase "btn-selected" al botón presionado y quitarla de los demás
        $('.btn-outline-primary').removeClass('btn-selected'); // Quitar clase de todos los botones
        $(this).addClass('btn-selected'); // Agregar la clase solo al botón presionado
    
        // Actualizar el filtro en el DataTable
        datatables_dispatches
            .column(19).search(tipoCliente) // Filtrar por el tipo cliente seleccionado
            .draw();
    });
    $('#limpiar_uuid').on('click', function () {
        $('.btn-outline-success, .btn-outline-danger').removeClass('btn-selected'); // Quitar la clase de todos los botones
        datatables_dispatches
            .column(14) // Cambia a la columna correcta donde se encuentra el UUID
            .search('') // Limpiar el filtro de timbrado
            .draw();
    });
    
    // Filtro para cuando el UUID está presente (timbrado)
    $('#uuid_true').on('click', function () {
        $('.btn-outline-success, .btn-outline-danger').removeClass('btn-selected'); // Quitar clase de todos los botones
        $(this).addClass('btn-selected'); // Agregar clase solo al botón presionado
    
        datatables_dispatches
            .column(14) // Cambia a la columna correcta donde se encuentra el UUID
            .search('^[a-zA-Z0-9-]+$', true, false) // Expresión regular para buscar un UUID (alfanumérico)
            .draw();
    });
    
    // Filtro para cuando no está timbrado (solo punto)
    $('#uuid_false').on('click', function () {
        console.log('false');
        $('.btn-outline-success, .btn-outline-danger').removeClass('btn-selected'); // Quitar clase de todos los botones
        $(this).addClass('btn-selected'); // Agregar clase solo al botón presionado
    
        datatables_dispatches
            .column(14) // Cambia a la columna correcta donde se encuentra el UUID
            .search('^\\.$', true, false) // Buscar solo un punto
            .draw();
    });
    
    // Agregar un evento clic de refresh
    $('.refresh_datatables_dispatches').on('click', function () {
        datatables_dispatches.clear().draw();
        datatables_dispatches.ajax.reload();
        $('#datatables_dispatches').waitMe('hide');
    });
}
function actualizarDataTableEst() {
    var from = $('#from1').val();
    var until = $('#until1').val();
    var codgas = $('#codgas1').val();
    var uuid = $('#uuid1').val();
    var billed = $('#billed1').val();
    var timestamp = new Date().getTime();
    console.log(billed);

    if (!codgas || codgas === "") {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una estación antes de continuar.</p>
            </div>`
        );
        return; // Detiene la ejecución de la función si no se seleccionó una estación
    }
    if ($.fn.DataTable.isDataTable('#datatables_dispatches_est')) {
        $('#datatables_dispatches_est').DataTable().destroy();
    }
    $('#element_hidden2').removeAttr('hidden');
    $('#no_selected2').attr('hidden',true);

    let datatables_dispatches_est =   $('#datatables_dispatches_est').DataTable({
        pageLength: 100,
        dom: '<"top"Bf>rt<"bottom"lip>',
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-outline-success',
                text: '<i data-feather="download"> Excel',
            },
            {
                extend: 'pdf',
                className: 'btn btn-outline-info',
                text: ' PDF'
            },
        ],
        ajax: {
            method: 'POST',
            url: '/income/datatables_dispatches_est',
            data: {
                'from': from,
                'until': until,
                'codgas': codgas,
                'uuid': uuid,
                'billed': billed
            },
            error: function() {
                $('#historic_shortage_table').waitMe('hide');
                $('.datatables_dispatches_est').removeClass('loading');
    
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
                $('.datatables_dispatches_est').addClass('loading');
            },
            
           },
           deferRender: true,
           columns: [
               {'data': 'fecha'},
               {'data': 'hora_formateada'},
               {'data': 'turno'},
               {'data': 'despacho'},
               {'data': 'producto'},
               {'data': 'estacion'},
               {'data': 'empresa'},
               {'data': 'cliente_fac'},
               {'data': 'cantidad','render': $.fn.dataTable.render.number(',', '.', 3, '')},
               {'data': 'importe', 'render': $.fn.dataTable.render.number(',', '.', 2, '$')},
               {'data': 'precio', 'render': $.fn.dataTable.render.number(',', '.', 2, '$') },
               {'data': 'despachador'},
               {'data': 'tipo_pago'},
               {'data': 'factura'},
               {'data': 'FechaFactura'},
               {'data': 'UUID'},
               {'data': 'txtref'},
               {'data': 'rut'},
               {'data': 'denominacion'},
               {'data': 'codigo_cliente'},
               {'data': 'tipo_cliente'},
               {'data': 'tipo_cliente_aplicativo'},
               {'data': 'vehiculo'},
               {'data': 'placas'},
           ],
           rowId: 'despacho',
           createdRow: function (row, data, dataIndex) {
       
           },
           initComplete: function (settings, json) {
               $('.datatables_dispatches_est').removeClass('loading');
           }
       });
    
    // Actualizar los datos del DataTable
    
    $('#filtro-datatables_dispatches_est input').on('keyup  change clear', function () {
        datatables_dispatches_est
        .column(0).search($('#1fecha').val().trim())                // Fecha
        .column(1).search($('#1hora_formateada').val().trim())      // Hora formateada
        .column(2).search($('#1turno').val().trim())                // Turno
        .column(3).search($('#1despacho').val().trim())             // Despacho
        .column(4).search($('#1producto').val().trim())             // Producto
        .column(5).search($('#1estacion').val().trim())             // Estación
        .column(6).search($('#1empresa').val().trim())              // Empresa
        .column(7).search($('#1cliente_fac').val().trim())          // Cliente facturación
        .column(8).search($('#1cantidad').val().trim())             // Cantidad despachada
        .column(9).search($('#1importe').val().trim())             // Importe
        .column(10).search($('#1precio').val().trim())              // Precio
        .column(11).search($('#1despachador').val().trim())         // Despachador
        .column(12).search($('#1tipo_pago').val().trim())             // Factura
        .column(13).search($('#1factura').val().trim())             // Factura
        .column(14).search($('#1UUID').val().trim())                // UUID
        .column(15).search($('#1txtref').val().trim())                // UUID
        .column(16).search($('#1rut').val().trim())                 // RUT
        .column(17).search($('#1denominacion').val().trim())                 // RUT
        .column(18).search($('#1codigo_cliente').val().trim())      // Código cliente
        .column(19).search($('#1tipo_cliente').val().trim())        // Tipo cliente
        .column(19).search($('#1tipo_cliente_aplicativo').val().trim())        // Tipo cliente
        .column(20).search($('#1vehiculo').val().trim())            // Vehículo
        .column(21).search($('#1placas').val().trim())  
            .draw();
    });

    
    // Agregar un evento clic de refresh
    $('.refresh_datatables_dispatches_est').on('click', function () {
        datatables_dispatches_est.clear().draw();
        datatables_dispatches_est.ajax.reload();
        $('#datatables_dispatches_est').waitMe('hide');
    });
}
function agruparPorFactura(data) {
    let facturas_GlobalDebito = {};
    let facturas_GlobalCredito = {};
    let facturas_GlobalEfectivo = {};

    // Usar un bucle for para recorrer los datos
    for (let i = 0; i < data.length; i++) {
        let row = data[i]; // Accede a la fila actual

        // Filtrar por código cliente
        if (row.codigo_cliente === "21701354") {
            let numeroFactura = row.factura; // Número de factura
            let tipo_pago_factura = row.tipo_pago; // Número de factura
            if(tipo_pago_factura == 'Efectivo'){
                if (!facturas_GlobalEfectivo[numeroFactura]) {
                    facturas_GlobalEfectivo[numeroFactura] = { tipo_pago: row.tipo_pago, rows: [] }; // Inicializa un objeto para la factura
                }
                facturas_GlobalEfectivo[numeroFactura].rows.push(row); // Agrega la fila a la factura correspondiente
            }

            if(tipo_pago_factura == 'Tarjeta Debito'){
                if (!facturas_GlobalDebito[numeroFactura]) {
                    facturas_GlobalDebito[numeroFactura] = { tipo_pago: row.tipo_pago, rows: [] }; // Inicializa un objeto para la factura
                }
                facturas_GlobalDebito[numeroFactura].rows.push(row); // Agrega la fila a la factura correspondiente
            }
            if(tipo_pago_factura == 'Tarjeta Credito'){
                if (!facturas_GlobalCredito[numeroFactura]) {
                    facturas_GlobalCredito[numeroFactura] = { tipo_pago: row.tipo_pago, rows: [] }; // Inicializa un objeto para la factura
                }
                facturas_GlobalCredito[numeroFactura].rows.push(row); // Agrega la fila a la factura correspondiente
            }


            // Inicializar el objeto de la factura si no existe
           
        }
    }

    // Crear tarjetas para cada grupo de facturas
    let collapsesContainer  = $('#collapses-container'); // Asegúrate de que este contenedor exista
    collapsesContainer.empty(); // Limpiar el contenedor antes de agregar nuevas tarjetas
   
    function crearLinksCollapse(collapseId, tipo) {
        return `<a class="btn btn-primary" data-bs-toggle="collapse" href="#${collapseId}" role="button" aria-expanded="false" aria-controls="${collapseId}">Facturas - ${tipo}</a>`;
    }

    // Crear los divs del contenido de cada collapse
    function crearCollapseDivs(facturas, collapseId, title) {
        let collapseHtml = `
            <div class="collapse" id="${collapseId}">
                <div class="card card-body">
                    <span>${title}</span>
                    <ul class="list-group list-group-flush">
        `;

        // Agregar cada factura a la lista dentro de la tarjeta
        for (let numero in facturas) {
            let facturaData = facturas[numero];
            let nota = facturaData.rows[0].txtref;
            let numero_f  = facturaData.rows.length;
            collapseHtml += `
               <li class="list-group-item d-flex justify-content-between align-items-start">
                <div class="ms-2 me-auto">
                    <div class="fw-bold">${numero}</div>
                    ${nota}
                    </div>
                    <span class="text-danger">${numero_f}</span>

                </li>
                `;
        }

        collapseHtml += `
                    </ul>
                </div>
            </div>
        `;
        return collapseHtml;
    }
    var links = `<p>`;
    var collapsedivsHtml = '<div class="div_collapse">';
    if (Object.keys(facturas_GlobalEfectivo).length > 0) {
        links += crearLinksCollapse('collapseEfectivo', 'Efectivo');
        collapsedivsHtml += crearCollapseDivs(facturas_GlobalEfectivo, 'collapseEfectivo','Efectivo');

    }
    if (Object.keys(facturas_GlobalDebito).length > 0) {
        links += crearLinksCollapse('collapseDebito', 'Tarjeta Débito');
        collapsedivsHtml += crearCollapseDivs(facturas_GlobalDebito, 'collapseDebito','Tarjeta Débito');

    }
    if (Object.keys(facturas_GlobalCredito).length > 0) {
        links += crearLinksCollapse('collapseCredito', 'Tarjeta Crédito');
        collapsedivsHtml += crearCollapseDivs(facturas_GlobalCredito, 'collapseCredito','Tarjeta Crédito');

    }
    collapsesContainer.append(links + collapsedivsHtml );
}


function actualizarDataTablePivot() {
    var from2 = $('#from2').val();
    var until2 = $('#until2').val();
    var codgas2 = $('#codgas2').val();
    if (!codgas2 || codgas2 === "") {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una estación antes de continuar.</p>
            </div>`
        );
        return; // Detiene la ejecución de la función si no se seleccionó una estación
    }
    if ($.fn.DataTable.isDataTable('#pivot_dispatches_table')) {
        $('#pivot_dispatches_table').DataTable().destroy();
    }
    $('#element_hidden6').removeAttr('hidden');
    $('#no_selected6').attr('hidden',true);

    let pivot_dispatches_table =   $('#pivot_dispatches_table').DataTable({
           dom: '<"top"Bf>rt<"bottom"lip>',
           paging: false,
           ordering: true,
           colReorder: false,
           buttons: [
                    {
                        extend: 'excel',
                        className: 'btn btn-outline-success',
                        text: '<i data-feather="download"> Excel',
                    },
                    {
                    extend: 'pdf',
                    className: 'btn btn-outline-info',
                    text: ' PDF'
                },
           ],
           ajax: {
               method: 'POST',
               url: '/income/pivot_dispatches_table',
               data: {'from': from2, 'until': until2, 'codgas': codgas2},
               error: function() {
                   $('#historic_shortage_table').waitMe('hide');
                   $('.table_pivot').removeClass('loading');
       
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
                   $('.table_pivot').addClass('loading');
               }
           },
           deferRender: true,
           columns: [
                    {'data': 'estacion'},
                    {'data': 'contado', 'className': 'text-end', 'render': $.fn.dataTable.render.number(',', '.', 3, '')},
                    {'data': 'cliente_credito', 'className': 'text-end', 'render': $.fn.dataTable.render.number(',', '.', 3, '')},
                    {'data': 'cliente_debito', 'className': 'text-end', 'render': $.fn.dataTable.render.number(',', '.', 3, '')},
                    {'data': 'monedero', 'className': 'text-end', 'render': $.fn.dataTable.render.number(',', '.', 3, '')},
                    {'data': 'factura_global', 'className': 'text-end', 'render': $.fn.dataTable.render.number(',', '.', 3, '')},
                    {'data': 'N_A',  'className': 'text-end','render': $.fn.dataTable.render.number(',', '.', 3, '')},
                    {'data': 'total',  'className': 'text-end','render': $.fn.dataTable.render.number(',', '.', 3, '')},
                ],
           rowId: 'estacion',
           createdRow: function (row, data, dataIndex) {
       
           },
           initComplete: function () {
               $('.table_pivot').removeClass('loading');
           }
       });
    // Agregar un evento clic de refresh
    $('.pivot_dispatches_table').on('click', function () {
        pivot_dispatches_table.clear().draw();
        pivot_dispatches_table.ajax.reload();
        $('#pivot_dispatches_table').waitMe('hide');
    });
}



function actualizarDailyPivot() {
    // Obtener los valores seleccionados del formulario
    var from3 = $('#from3').val();
    var until3 = $('#until3').val();
    var codgas3 = $('#codgas3').val();
    if (!codgas3 || codgas3 === "") {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una estación antes de continuar.</p>
            </div>`
        );
        return; // Detiene la eecución de la función si no se seleccionó una estación
    }
    if ($.fn.DataTable.isDataTable('#pivot_daily_dispatches_table')) {
        $('#pivot_daily_dispatches_table').DataTable().clear().destroy(); // Destruir DataTable completamente
        $('#pivot_daily_dispatches_table_head').empty(); // Limpiar los encabezados generados dinámicamente
    }
    $('#element_hidden3').removeAttr('hidden');
    $('#no_selected3').attr('hidden',true);

    let startDate = new Date(from3);
    let endDate = new Date(until3);
    let dates = [];
    while (startDate <= endDate) {
        dates.push(new Date(startDate).toISOString().split('T')[0]); // Convertimos la fecha a formato YYYY-MM-DD
        startDate.setDate(startDate.getDate() + 1);
    }
    generateTableHeaders(dates);/////generar cabeceras
    toggleLoading(true);

    $('#pivot_daily_dispatches_table').DataTable({
        dom: '<"top"Bf>rt<"bottom"lip>',
        paging: false,
        ordering: true,
        colReorder: false,
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-outline-success',
                text: 'Excel',
            },
            {
            extend: 'pdf',
            className: 'btn btn-outline-info',
            text: ' PDF'
        },
        ],
        ajax: {
            method: 'POST',
            url: '/income/pivot_daily_dispatches_table',
            data: {'from': from3, 'until': until3, 'codgas': codgas3},
            success: function(response) {
                generateDataTable(response.data);
                toggleLoading(false);
            },
            error: function() {
                toggleLoading(false);
                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                    </div>`
                );
            },
        },
        deferRender: true,
        columns: generateColumnsConfig(dates)

    });

}

function generateTableHeaders(dates) {
    let thead = $('#pivot_daily_dispatches_table_head');
    thead.html('');
    thead.empty();
    let row1 = $('<tr>');
    row1.append('<th rowspan="2">Estación</th>'); // Columna fija
    dates.forEach(date => {
        row1.append('<th colspan="6" class="text-center">' + date + '</th>'); // Columna de cada día
    });
    thead.append(row1);
    let row2 = $('<tr>');
    dates.forEach(() => {
        row2.append('<th>Cliente Crédito</th>');
        row2.append('<th>Cliente Débito</th>');
        row2.append('<th>Monedero</th>');
        row2.append('<th>Contado</th>');
        row2.append('<th>Factura Global</th>');
        row2.append('<th>N/A</th>');
    });
    thead.append(row2);
}
function generateColumnsConfig(dates) {
    let columns = [{ 'data': 'estacion','className':'text-nowrap'}]; // Primera columna fija de la estación
    dates.forEach(date => {
        columns.push({ 'data': date + '_cliente_credito', 'className': 'text-end' });
        columns.push({ 'data': date + '_cliente_debito', 'className': 'text-end' });
        columns.push({ 'data': date + '_monedero', 'className': 'text-end' });
        columns.push({ 'data': date + '_contado', 'className': 'text-end' });
        columns.push({ 'data': date + '_factura_global', 'className': 'text-end' });
        columns.push({ 'data': date + '_NA', className :'text-end'});
    });
    return columns;
}

function generateDataTable(data) {
    $('#pivot_daily_dispatches_table').DataTable().clear().rows.add(data).draw();
}

function toggleLoading(isLoading) {
    if (isLoading) {
        $('.table_pivot_daily').addClass('loading');
    } else {
        $('.table_pivot_daily').removeClass('loading');
        $('#pivot_daily_dispatches_table').waitMe('hide');
    }
}
async function DispachesTypeModal(fecha, codgas, tipo_client) {
    console.log(fecha)
    console.log(codgas)
    console.log(tipo_client)
    try {
        $('#DispachesTypeModal').modal('show'); // Abre el modal

        const response = await fetch('/income/DispachesTypeModal', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
            body: `fecha=${fecha}&codgas=${codgas}&tipo_client=${tipo_client}`
        });

        const content = await response.text();

        // Inserta el contenido en el modal
        $('#DispachesTypeModal').find('#DispachesTypeModalContent').html(content);

        // Inicializa la tabla DataTable de manera asincrónica
        drawDatatableDispachesModal();

    } catch (error) {
        console.error(error);
    }
}

// Cuando se cierre el modal, destruye la tabla para evitar problemas al reabrirla
$('#DispachesTypeModal').on('hidden.bs.modal', function () {
    if ($.fn.DataTable.isDataTable('.datatables_dispatches_modal')) {
        $('.datatables_dispatches_modal').DataTable().destroy();
    }
});

// Inicializa la DataTable de manera asincrónica después de cargar los datos
function drawDatatableDispachesModal(){
    if ($.fn.DataTable.isDataTable('.datatables_dispatches_modal')) {
        var table = $('.datatables_dispatches_modal').DataTable();
        table.clear(); // Limpia las filas
    } else {
        // Si la tabla no está inicializada, inicialízala
        $('.datatables_dispatches_modal').DataTable({
            paging: true,
            searching: true,
            ordering: true,
            responsive: true,
            pageLength: 100,
            dom: '<"top"Bf>rt<"bottom"lip>',

        });
    }
}

function actualizarFacturaGlobal() {
    console.log('actualizarFacturaGlobal');
    var from4 = $('#from4').val();
    var until4 = $('#until4').val();
    var codgas4 = $('#codgas4').val();
    if (!codgas4 || codgas4 === "") {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una estación antes de continuar.</p>
            </div>`
        );
        return;
    }
    // $('#pivot_facturacion_diaria_table').addClass('loading');

    if ($.fn.DataTable.isDataTable('#pivot_facturacion_diaria_table')) {
        $('#pivot_facturacion_diaria_table').DataTable().clear().destroy(); // Destruir DataTable completamente
        $('#pivot_facturacion_diaria_table_head').empty(); // Limpiar los encabezados generados dinámicamente
    }
    $('#element_hidden4').removeAttr('hidden');
    $('#no_selected4').attr('hidden',true);
    let estaciones = [
        { num: 5, nombre: 'lerdo' },
        { num: 19, nombre: 'delicias' },
        { num: 18, nombre: 'parral' },
        { num: 6, nombre: 'lopez_mateos' },
        { num: 7, nombre: 'gemela_chica' },
        { num: 2, nombre: 'gemel_grande' },
        { num: 21, nombre: 'plutarco' },
        { num: 8, nombre: 'mpio_libre' },
        { num: 9, nombre: 'aztecas' },
        { num: 10, nombre: 'misiones' },
        { num: 11, nombre: 'pto_de_palos' },
        { num: 12, nombre: 'miguel_d_mad' },
        { num: 13, nombre: 'permuta' },
        { num: 14, nombre: 'electrolux' },
        { num: 15, nombre: 'aeronautica' },
        { num: 16, nombre: 'custodia' },
        { num: 17, nombre: 'anapra' },
        { num: 3, nombre: 'independenci' },
        { num: 22, nombre: 'tecnologico' },
        { num: 23, nombre: 'ejercito_nal' },
        { num: 24, nombre: 'satellite' },
        { num: 25, nombre: 'las_fuentes' },
        { num: 26, nombre: 'clara' },
        { num: 27, nombre: 'solis' },
        { num: 28, nombre: 'santiago_tro' },
        { num: 29, nombre: 'jarudo' },
        { num: 30, nombre: 'hermanos_esc' },
        { num: 31, nombre: 'villa_ahumad' },
        { num: 32, nombre: 'el_castano' },
        { num: 33, nombre: 'travel_cente' },
        { num: 34, nombre: 'picachos' },
        { num: 35, nombre: 'ventanas' },
        { num: 36, nombre: 'san_rafael' },
        { num: 37, nombre: 'puertcito' }
    ];
    let columns = [
        {'data': 'fecha', 'className': 'text-nowrap colum_date' }
    ];
    let thead = $('#pivot_facturacion_diaria_table_head');
    thead.empty();
    let headers = '<tr><th>Fecha</th>';

    if (codgas4 == 0) {
        $('#element_hidden4').addClass('col-6');
        estaciones.forEach(function(estacion) {
            columns.push({'data': estacion.nombre,className:'text-end'});
            headers += `<th>${estacion.nombre.charAt(0).toUpperCase() + estacion.nombre.slice(1).replace(/_/g, ' ')}</th>`;
        });
    } else {
        $('#element_hidden4').addClass('col-6');
        let estacionSeleccionada = estaciones.find(est => est.num == codgas4);
        let estacionColumna = {
            'data': estacionSeleccionada.nombre, 
            'className': 'text-end'};
        columns.push(estacionColumna);
        headers += `<th>${estacionSeleccionada.nombre.charAt(0).toUpperCase() + estacionSeleccionada.nombre.slice(1).replace(/_/g, ' ')}</th>`
    }

    headers += '</tr>';
    thead.append(headers);

    let pivot_facturacion_diaria_table =   $('#pivot_facturacion_diaria_table').DataTable({
           dom: '<"top"Bf>rt<"bottom"lip>',
           paging: false,
           ordering: true,
           colReorder: false,
           fixedColumns: {
            leftColumns: 1
        },
           buttons: [
                    {
                        extend: 'excel',
                        className: 'btn btn-outline-success',
                        text: '<i data-feather="download"> Excel',
                    },
                    {
                    extend: 'pdf',
                    className: 'btn btn-outline-info',
                    text: ' PDF'
                },
           ],
           ajax: {
               method: 'POST',
               url: '/income/pivot_facturacion_diaria_table',
               data: {'from': from4, 'until': until4, 'codgas': codgas4},
               error: function() {
                   $('#pivot_facturacion_diaria_table').waitMe('hide');
                   $('#pivot_facturacion_diaria_table').removeClass('loading');
       
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
                   $('#pivot_facturacion_diaria_table').addClass('loading');
               }
           },
           deferRender: true,
           columns: columns,
           rowId: 'estacion',
           createdRow: function (row, data, dataIndex) {
            $('td', row).eq(0).addClass('text-nowrap');
           },
           initComplete: function () {
               $('#pivot_facturacion_diaria_table').removeClass('loading');
           }
       });
    // Agregar un evento clic de refresh
    $('.pivot_facturacion_diaria_table').on('click', function () {
        pivot_facturacion_diaria_table.clear().draw();
        pivot_facturacion_diaria_table.ajax.reload();
        $('#pivot_facturacion_diaria_table').waitMe('hide');
    });
}

function actualizarDataTableInvoceUnstamped() {
    var from = $('#from').val();
    var until = $('#until').val();
    var codgas = $('#codgas').val();
    var uuid = 1;
    var billed = 2;
    var timestamp = new Date().getTime();

    if (!codgas || codgas === "") {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una estación antes de continuar.</p>
            </div>`
        );
        return; // Detiene la ejecución de la función si no se seleccionó una estación
    }
    if ($.fn.DataTable.isDataTable('#datatables_dispatches')) {
        $('#datatables_dispatches').DataTable().destroy();
    }
    $('#element_hidden').removeAttr('hidden');
    $('#no_selected').attr('hidden',true);

    let datatables_dispatches =   $('#datatables_dispatches').DataTable({
            // scrollY: '700px',
            // scrollX: true,
            // scrollCollapse: true,
            // paging: false,
            // ordering: true,
            // colReorder: false,
            pageLength: 100,

           dom: '<"top"Bf>rt<"bottom"lip>',
           // order: [3, 'asc'],
           buttons: [
               {
                   extend: 'excel',
                   className: 'btn btn-outline-success',
                   text: '<i data-feather="download"> Excel',
               },
               {
                extend: 'pdf',
                className: 'btn btn-outline-info',
                text: ' PDF'
            },
           ],
           ajax: {
               method: 'POST',
               url: '/income/datatables_dispatches',
               data: {
                    'from': from,
                    'until': until,
                    'codgas': codgas,
                    'billed': billed,
                    'uuid': uuid
                },
               error: function() {
                   $('#historic_shortage_table').waitMe('hide');
                   $('.control_dispaches_table').removeClass('loading');
       
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
                   $('.control_dispaches_table').addClass('loading');
               },
            
           },
           deferRender: true,
           columns: [
               {'data': 'fecha'},
               {'data': 'hora_formateada'},
               {'data': 'despacho'},
               {'data': 'producto'},
               {'data': 'estacion'},
               {'data': 'empresa'},
               {'data': 'cliente_fac'},
               {'data': 'cantidad','render': $.fn.dataTable.render.number(',', '.', 3, '')},
               {'data': 'importe', 'render': $.fn.dataTable.render.number(',', '.', 2, '$')},
               {'data': 'precio', 'render': $.fn.dataTable.render.number(',', '.', 2, '$') },
               {'data': 'tipo_pago'},
               {'data': 'factura'},
               {'data': 'UUID'},
               {'data': 'txtref'},
               {'data': 'rut'},
               {'data': 'codigo_cliente'},
               {'data': 'tipo_cliente'},
           ],
           rowId: 'despacho',
           createdRow: function (row, data, dataIndex) {
       
           },
           initComplete: function (settings, json) {
            //    $('.dt-buttons').addClass('d-none');
               $('.control_dispaches_table').removeClass('loading');
               agruparPorFactura(json.data);

           }
       });
    
    // Actualizar los datos del DataTable
    
    $('#filtro-datatables_dispatches input').on('keyup  change clear', function () {
        datatables_dispatches
        .column(0).search($('#fecha').val().trim())                // Fecha
        .column(1).search($('#hora_formateada').val().trim())      // Hora formateada
        .column(2).search($('#despacho').val().trim())             // Despacho
        .column(3).search($('#producto').val().trim())             // Producto
        .column(4).search($('#estacion').val().trim())             // Estación
        .column(5).search($('#empresa').val().trim())              // Empresa
        .column(6).search($('#cliente_fac').val().trim())          // Cliente facturación
        .column(7).search($('#cantidad').val().trim())             // Cantidad despachada
        .column(8).search($('#importe').val().trim())             // Importe
        .column(9).search($('#precio').val().trim())              // Precio
        .column(10).search($('#tipo_pago').val().trim())             // Factura
        .column(11).search($('#factura').val().trim())             // Factura
        .column(12).search($('#UUID').val().trim())                // UUID
        .column(13).search($('#txtref').val().trim())                // UUID
        .column(14).search($('#rut').val().trim())                 // RUT
        .column(15).search($('#codigo_cliente').val().trim())      // Código cliente
        .column(16).search($('#tipo_cliente').val().trim())        // Tipo cliente
        .draw();
    });
    $('#limpiar').on('click', function () {
        $('#tipo_cliente').val(''); // Limpiar el campo de tipo de cliente
        $('.btn-outline-primary').removeClass('btn-selected'); // Eliminar la clase de todos los botones
    
        // Limpiar el filtro de la columna de tipo cliente en el DataTable y actualizarlo
        datatables_dispatches
            .column(19).search('') // Limpiar el filtro de tipo cliente
            .draw();
    });
    $('.btn-outline-primary').not('#limpiar').on('click', function () {
        var tipoCliente = $(this).text(); // Capturar el texto del botón seleccionado
    
        // Asignar el valor al campo de tipo cliente
        $('#tipo_cliente').val(tipoCliente);
    
        // Agregar la clase "btn-selected" al botón presionado y quitarla de los demás
        $('.btn-outline-primary').removeClass('btn-selected'); // Quitar clase de todos los botones
        $(this).addClass('btn-selected'); // Agregar la clase solo al botón presionado
    
        // Actualizar el filtro en el DataTable
        datatables_dispatches
            .column(19).search(tipoCliente) // Filtrar por el tipo cliente seleccionado
            .draw();
    });
    $('#limpiar_uuid').on('click', function () {
        $('.btn-outline-success, .btn-outline-danger').removeClass('btn-selected'); // Quitar la clase de todos los botones
        datatables_dispatches
            .column(14) // Cambia a la columna correcta donde se encuentra el UUID
            .search('') // Limpiar el filtro de timbrado
            .draw();
    });
    
    // Filtro para cuando el UUID está presente (timbrado)
    $('#uuid_true').on('click', function () {
        $('.btn-outline-success, .btn-outline-danger').removeClass('btn-selected'); // Quitar clase de todos los botones
        $(this).addClass('btn-selected'); // Agregar clase solo al botón presionado
    
        datatables_dispatches
            .column(14) // Cambia a la columna correcta donde se encuentra el UUID
            .search('^[a-zA-Z0-9-]+$', true, false) // Expresión regular para buscar un UUID (alfanumérico)
            .draw();
    });
    
    // Filtro para cuando no está timbrado (solo punto)
    $('#uuid_false').on('click', function () {
        console.log('false');
        $('.btn-outline-success, .btn-outline-danger').removeClass('btn-selected'); // Quitar clase de todos los botones
        $(this).addClass('btn-selected'); // Agregar clase solo al botón presionado
    
        datatables_dispatches
            .column(14) // Cambia a la columna correcta donde se encuentra el UUID
            .search('^\\.$', true, false) // Buscar solo un punto
            .draw();
    });
    
    // Agregar un evento clic de refresh
    $('.refresh_datatables_dispatches').on('click', function () {
        datatables_dispatches.clear().draw();
        datatables_dispatches.ajax.reload();
        $('#datatables_dispatches').waitMe('hide');
    });
}

function actualizarDataTableInvoceDispatched() {
    var from = $('#from').val();
    var until = $('#until').val();
    var codgas = $('#codgas').val();
    var uuid = 0;
    var billed = 0;
    var timestamp = new Date().getTime();

    if (!codgas || codgas === "") {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una estación antes de continuar.</p>
            </div>`
        );
        return; // Detiene la ejecución de la función si no se seleccionó una estación
    }
    if ($.fn.DataTable.isDataTable('#datatables_dispatches')) {
        $('#datatables_dispatches').DataTable().destroy();
    }
    $('#element_hidden').removeAttr('hidden');
    $('#no_selected').attr('hidden',true);

    let datatables_dispatches =   $('#datatables_dispatches').DataTable({
            pageLength: 100,
           dom: '<"top"Bf>rt<"bottom"lip>',
           // order: [3, 'asc'],
           buttons: [
               {
                   extend: 'excel',
                   className: 'btn btn-outline-success',
                   text: '<i data-feather="download"> Excel',
               },
               {
                extend: 'pdf',
                className: 'btn btn-outline-info',
                text: ' PDF'
            },
           ],
           ajax: {
               method: 'POST',
               url: '/income/datatables_dispatches_invoiced',
               data: {
                    'from': from,
                    'until': until,
                    'codgas': codgas,
                    'billed': billed,
                    'uuid': uuid
                },
               error: function() {
                   $('#historic_shortage_table').waitMe('hide');
                   $('.control_dispaches_table').removeClass('loading');
       
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
                   $('.control_dispaches_table').addClass('loading');
               },
            
           },
           deferRender: true,
           columns: [
               {'data': 'fecha'},
               {'data': 'hora_formateada'},
               {'data': 'despacho'},
               {'data': 'producto'},
               {'data': 'estacion'},
               {'data': 'empresa'},
               {'data': 'cliente_fac'},
               {'data': 'cantidad','render': $.fn.dataTable.render.number(',', '.', 3, '')},
               {'data': 'importe', 'render': $.fn.dataTable.render.number(',', '.', 2, '$')},
               {'data': 'precio', 'render': $.fn.dataTable.render.number(',', '.', 2, '$') },
               {'data': 'tipo_pago'},
               {'data': 'factura'},
               {'data': 'UUID'},
               {'data': 'UUID_sat'},
               {'data': 'FechaTimbrado'},
               {'data': 'txtref'},
               {'data': 'rut'},
               {'data': 'denominacion'},
               {'data': 'codigo_cliente'},
               {'data': 'tipo_cliente'},
           ],
           rowId: 'despacho',
           createdRow: function (row, data, dataIndex) {
            if (data['UUID_sat'] == null ) {
                $(row).addClass('table-warning');
            }
           },
           initComplete: function (settings, json) {
            //    $('.dt-buttons').addClass('d-none');
               $('.control_dispaches_table').removeClass('loading');
               
           }
       });
    // Actualizar los datos del DataTable
    $('#filtro-datatables_dispatches input').on('keyup  change clear', function () {
        datatables_dispatches
        .column(0).search($('#fecha').val().trim())                // Fecha
        .column(1).search($('#hora_formateada').val().trim())      // Hora formateada
        .column(3).search($('#despacho').val().trim())             // Despacho
        .column(4).search($('#producto').val().trim())             // Producto
        .column(5).search($('#estacion').val().trim())             // Estación
        .column(6).search($('#empresa').val().trim())              // Empresa
        .column(7).search($('#cliente_fac').val().trim())          // Cliente facturación
        .column(8).search($('#cantidad').val().trim())             // Cantidad despachada
        .column(9).search($('#importe').val().trim())             // Importe
        .column(10).search($('#precio').val().trim())              // Precio
        .column(12).search($('#tipo_pago').val().trim())             // Factura
        .column(13).search($('#factura').val().trim())             // Factura
        .column(14).search($('#UUID').val().trim())                // UUID
        .column(15).search($('#txtref').val().trim())                // UUID
        .column(16).search($('#rut').val().trim())                 // RUT
        .column(17).search($('#denominacion').val().trim())                 // RUT
        .column(18).search($('#codigo_cliente').val().trim())      // Código cliente
        .column(19).search($('#tipo_cliente').val().trim())        // Tipo cliente
            .draw();
    });

    // Agregar un evento clic de refresh
    $('.refresh_datatables_dispatches').on('click', function () {
        datatables_dispatches.clear().draw();
        datatables_dispatches.ajax.reload();
        $('#datatables_dispatches').waitMe('hide');
    });
}

function actualizarOveralInvoiceTable() {


    if ($.fn.DataTable.isDataTable('#overal_invoice_table')) {
        $('#overal_invoice_table').DataTable().destroy();
        $('#overal_invoice_table thead .filter').remove();
    }
    $('#overal_invoice_table thead').prepend($('#overal_invoice_table thead tr').clone().addClass('filter'));
    $('#overal_invoice_table thead tr.filter th').each(function (index) {
        col = $('#overal_invoice_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#overal_invoice_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#overal_invoice_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    var from = $('#from').val();
    var until = $('#until').val();
    var codgas = $('#codgas').val();
    var timestamp = new Date().getTime();

    if (!codgas || codgas === "") {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una estación antes de continuar.</p>
            </div>`
        );
        return; // Detiene la ejecución de la función si no se seleccionó una estación
    }
    if ($.fn.DataTable.isDataTable('#overal_invoice_table')) {
        $('#overal_invoice_table').DataTable().destroy();
    }
    $('#element_hidden').removeAttr('hidden');
    $('#no_selected').attr('hidden',true);

    let overal_invoice_table =   $('#overal_invoice_table').DataTable({
            // scrollY: '700px',
            // scrollX: true,
            // scrollCollapse: true,
            // paging: false,
            // ordering: true,
            // colReorder: false,
            pageLength: 100,

           dom: '<"top"Bf>rt<"bottom"lip>',
           // order: [3, 'asc'],
           buttons: [
               {
                   extend: 'excel',
                   className: 'btn btn-outline-success',
                   text: '<i data-feather="download"> Excel',
               },
               {
                extend: 'pdf',
                className: 'btn btn-outline-info',
                text: ' PDF'
            },
           ],
           ajax: {
               method: 'POST',
               url: '/income/overal_invoice_out_table',
               data: {
                    'from': from,
                    'until': until,
                    'codgas': codgas,
                    'status': '1',
                },
               error: function() {
                   $('#historic_shortage_table').waitMe('hide');
                   $('.control_dispaches_table').removeClass('loading');
       
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
                   $('.control_dispaches_table').addClass('loading');
               },
            
           },
           deferRender: true,
           columns: [
                // {'data': 'nro'}, // Número
                {'data': 'estacion','className': 'text-nowrap '}, // Factura (cálculo basado en nro)
                {'data': 'factura','className': 'text-nowrap '}, // Factura (cálculo basado en nro)
                {'data': 'satuid'}, // UUID SAT
                {'data': 'fecha', 'className': 'text-nowrap '}, // Fecha
                {'data': 'vigencia','className': 'text-nowrap '}, // Vigencia
                {'data': 'FechasConcatenadas'}, // Fechas Concatenadas
                {'data': 'txtref'}, // Referencia
                {'data': 'TipoPago'}, // Tipo de Pago
                {'data': 'NrotrnConcatenados'}, // Transacciones Concatenadas
                {'data': 'estado'}
            ],
           rowId: 'despacho',
           createdRow: function (row, data, dataIndex) {

           },
           initComplete: function (settings, json) {
            //    $('.dt-buttons').addClass('d-none');
               $('.control_dispaches_table').removeClass('loading');
           }
       });
    
    // Actualizar los datos del DataTable
    
   
    // Agregar un evento clic de refresh
    $('.refresh_overal_invoice_table').on('click', function () {
        overal_invoice_table.clear().draw();
        overal_invoice_table.ajax.reload();
        $('#overal_invoice_table').waitMe('hide');
    });
}


function actualizarOveralInvoiceOutTable() {

    if ($.fn.DataTable.isDataTable('#overal_invoice_out_table')) {
        $('#overal_invoice_out_table').DataTable().destroy();
        $('#overal_invoice_out_table thead .filter').remove();
    }
    $('#overal_invoice_out_table thead').prepend($('#overal_invoice_out_table thead tr').clone().addClass('filter'));
    $('#overal_invoice_out_table thead tr.filter th').each(function (index) {
        col = $('#overal_invoice_out_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#overal_invoice_out_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#overal_invoice_out_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    var from2 = $('#from2').val();
    var until2 = $('#until2').val();
    var codgas2 = $('#codgas2').val();
    var timestamp = new Date().getTime();

    if (!codgas || codgas === "") {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una estación antes de continuar.</p>
            </div>`
        );
        return;
    }
   
    $('#element_hidden2').removeAttr('hidden');
    $('#no_selected2').attr('hidden',true);

    let overal_invoice_out_table =   $('#overal_invoice_out_table').DataTable({
            pageLength: 100,
           dom: '<"top"Bf>rt<"bottom"lip>',
           buttons: [
               {
                   extend: 'excel',
                   className: 'btn btn-outline-success',
                   text: '<i data-feather="download"> Excel',
               },
               {
                extend: 'pdf',
                className: 'btn btn-outline-info',
                text: ' PDF'
            },
           ],
           ajax: {
               method: 'POST',
               url: '/income/overal_invoice_out_table',
               data: {
                    'from': from2,
                    'until': until2,
                    'codgas': codgas2,
                    'status': '0',
                },
               error: function() {
                   $('.control_dispaches_table2').removeClass('loading');
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
                   $('.control_dispaches_table2').addClass('loading');
               },
           },
           deferRender: true,
           columns: [
                // {'data': 'nro'}, // Número
                {'data': 'factura','className': 'text-nowrap '}, // Factura (cálculo basado en nro)
                {'data': 'satuid'}, // UUID SAT
                {'data': 'fecha', 'className': 'text-nowrap '}, // Fecha
                {'data': 'vigencia','className': 'text-nowrap '}, // Vigencia
                {'data': 'FechasConcatenadas'}, // Fechas Concatenadas
                {'data': 'txtref'}, // Referencia
                {'data': 'TipoPago'}, // Tipo de Pago
                {'data': 'NrotrnConcatenados'}, // Transacciones Concatenadas
                {'data': 'estado'},
                {'data': 'estacion'}
            ],
           rowId: 'despacho',
           createdRow: function (row, data, dataIndex) {

           },
           initComplete: function (settings, json) {
            //    $('.dt-buttons').addClass('d-none');
               $('.control_dispaches_table2').removeClass('loading');
           }
       });
    // Actualizar los datos del DataTable
    $('#filtro-overal_invoice_out_table input').on('keyup  change clear', function () {
        overal_invoice_out_table
        .column(0).search($('#factura').val().trim())                // Fecha
        .column(1).search($('#satuid').val().trim())      // Hora formateada
        .column(2).search($('#fecha').val().trim())             // Despacho
        .column(3).search($('#vigencia').val().trim())             // Producto
        .column(4).search($('#FechasConcatenadas').val().trim())             // Estación
        .column(5).search($('#txtref').val().trim())              // Empresa
        .column(6).search($('#TipoPago').val().trim())          // Cliente facturación
        .column(7).search($('#NrotrnConcatenados').val().trim())          // Cliente facturación
        .column(8).search($('#estado').val().trim())          // Cliente facturación
        .draw();
    });
    // Agregar un evento clic de refresh
    $('.refresh_overal_invoice_out_table').on('click', function () {
        overal_invoice_out_table.clear().draw();
        overal_invoice_out_table.ajax.reload();
        $('#overal_invoice_out_table').waitMe('hide');
    });
}