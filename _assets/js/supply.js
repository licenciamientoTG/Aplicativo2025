// Si el documento esta listo
$(document).ready(function() {


    // Table de Despachos de Crédito y Débito
    let inventory_mov_table = $('#inventory_mov_table').DataTable({
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 100,
        buttons: [
            {
                extend: 'excel',
                className: 'd-none',
            },
            {
                extend: 'print', // Agrega el botón de impresión
                className: 'd-none',
            }
        ],
        ajax: {

            url: '/supply/inventory_mov_table/' + $('input#from').val() + '/' + $('select#station_id').val(),
            error: function() {
                $('#inventory_mov_table').waitMe('hide');
                alertify.myAlert(
                    `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Error!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                </div>`
                );
            }
        },
        deferRender: true,
        columns: [
            {'data': 'ESTACION'},
            {'data': 'TURNO'},
            {'data': 'PRODUCTO'},
            {'data': 'CAP', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
            {'data': 'VOLUMEN', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
            {'data': 'PORCENTAJE', 'render': $.fn.dataTable.render.number( ',', '.', 0, '%')},
        ],
        createdRow: function (row, data, dataIndex) {

        },
        initComplete: function () {
            $('.dt-buttons').addClass('d-none');
            $('[data-toggle="tooltip"]').tooltip();
        }
    });

    // Agregar un evento clic de refresh
    $('.refresh_inventory_mov_table').on('click', function () {
        inventory_mov_table.clear().draw();
        inventory_mov_table.ajax.reload();
        $('#inventory_mov_table').waitMe('hide');
    });
});




let datatable_product_prices = $('#datatable_product_prices').DataTable({
    colReorder: true,
    order: [0, "asc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Precios de Combustibles',
        }
    ],
    ajax: {
        url: '/supply/datatable_product_prices',
        type: 'POST',
        error: function() {
            $('#datatable_product_prices').waitMe('hide');
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
    columns: [
        {'data': 'CODEST'},
        {'data': 'ESTACION'},
        {'data': 'PRECIOANTERIORMAXIMA'},
        {'data': 'PRECIONUEVOMAXIMA'},
        {'data': 'DIFERENCIAMAXIMA'},
        {'data': 'PRECIOANTERIORSUPER'},
        {'data': 'PRECIONUEVOSUPER'},
        {'data': 'DIFERENCIASUPER'},
        {'data': 'PRECIOANTERIORDIESEL'},
        {'data': 'PRECIONUEVODIESEL'},
        {'data': 'DIFERENCIADIESEL'},
    ],
    rowId: 'CODEST',
    createdRow: function (row, data, dataIndex) {
        // Vamos a agregar la clase .bg-success a las celdas de la columna 2,3 y 4
        // que tengan un valor mayor a 100
        $('td', row).eq(2).addClass('bg-success text-white text-center');
        $('td', row).eq(3).addClass('bg-success text-white text-center');
        $('td', row).eq(4).addClass('bg-success text-white text-center');

        $('td', row).eq(5).addClass('bg-primary text-white text-center');
        $('td', row).eq(6).addClass('bg-primary text-white text-center');
        $('td', row).eq(7).addClass('bg-primary text-white text-center');

        // Vamos a agregar la clase .bg-warning a las celdas de la columna 5,6 y 7 si el contenido de la celda es 'N/A'
        if ($('td', row).eq(6).text() === 'N/A') {
            $('td', row).eq(5).addClass('bg-black');
            $('td', row).eq(6).addClass('bg-black');
            $('td', row).eq(7).addClass('bg-black');
        }

        $('td', row).eq(8).addClass('table-warning text-center');
        $('td', row).eq(9).addClass('table-warning text-center');
        $('td', row).eq(10).addClass('table-warning text-center');

        // Vamos a agregar la clase .bg-warning a las celdas de la columna 5,6 y 7 si el contenido de la celda es 'N/A'
        if ($('td', row).eq(9).text() === 'N/A') {
            $('td', row).eq(8).addClass('bg-black text-white');
            $('td', row).eq(9).addClass('bg-black text-white');
            $('td', row).eq(10).addClass('bg-black text-white');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

datatable_product_prices.on('draw', function() {
    $('[data-toggle="tooltip"]').tooltip();
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatable_product_prices input').on('keyup change clear', function () {
    datatable_product_prices
        .column(0).search($('#CODEST').val().trim())
        .column(1).search($('#ESTACION').val().trim())
        .column(2).search($('#PRECIOANTERIORMAXIMA').val().trim())
        .column(3).search($('#PRECIONUEVOMAXIMA').val().trim())
        .column(4).search($('#DIFERENCIAMAXIMA').val().trim())
        .column(5).search($('#PRECIOANTERIORSUPER').val().trim())
        .column(6).search($('#PRECIONUEVOSUPER').val().trim())
        .column(7).search($('#DIFERENCIASUPER').val().trim())
        .column(8).search($('#PRECIOANTERIORDIESEL').val().trim())
        .column(9).search($('#PRECIONUEVODIESEL').val().trim())
        .column(10).search($('#DIFERENCIADIESEL').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatable_product_prices').on('click', function () {
    datatable_product_prices.clear().draw();
    datatable_product_prices.ajax.reload();
    $('#datatable_product_prices').waitMe('hide');
});

$(document).ready(function() {
    $('#ieps_value').text();
    $('#product').on('changed.bs.select', function(e, clickedIndex, isSelected, previousValue) {
        var selectedValue = $(this).val();
        $.getJSON( "/supply/get_ieps/" + selectedValue, function( json ) {
            // Vamos a actualizar el contenido de la eqtiqueta <small> con el valor del IEPS
            $('#ieps_value').text('IEPS: ' + json.abr);
        });

    });
});

function update_price(codprd, codgas, fch, hra, pre) {
    alertify.prompt('Actualizar precio', 'Por favor, ingrese el precio del producto: ', pre,
        function(evt, value) {
            // Convertir el valor ingresado a número decimal
            var price = parseFloat(value);

            // Validar si el valor es un número decimal válido
            if (!isNaN(price) && price >= 0) {
                // Comparar el precio ingresado con el precio actual
                if (price === parseFloat(pre)) {
                    toastr.warning('El precio ingresado es igual al precio actual.', '¡Atención!', { timeOut: 3000 });
                } else {
                    // Aqui vamos a ingresar un nuevo registro en la tabla de precios
                    $.ajax({
                        url: '/supply/update_price',
                        type: 'POST',
                        data: {
                            codprd: codprd,
                            codgas: codgas,
                            fch: fch,
                            hra: hra,
                            pre: price
                        },
                        success: function(data) {
                            if (data.status == 'Success') {
                                toastr.success(data.message, '¡Éxito!', { timeOut: 3000 });

                                // Vamos a actualizar la tabla
                                datatable_product_prices.clear().draw();
                                datatable_product_prices.ajax.reload();

                                // Vamos a remover la clase .loading de la tabla
                                toastr.success('Por favor, espere mientras la tabla recarga la información', '¡Éxito!', { timeOut: 3000 });
                                // Vamos a esperar 4 segundos y removemos la clase .loading
                                setTimeout(function() {
                                    $('.table-responsive').removeClass('loading');
                                }, 6000);
                            } else {
                                toastr.error(data.msg, '¡Error!', { timeOut: 3000 });
                            }
                        },
                        error: function() {
                            toastr.error('Ocurrió un error al intentar actualizar el precio.', '¡Error!', { timeOut: 3000 });
                        }
                    });
                }
            } else {
                toastr.error('El valor ingresado no es un número decimal válido.', '¡Atención!', { timeOut: 3000 });
            }
        },
        function() {
            toastr.info('Operación cancelada', '¡Atención!', { timeOut: 3000 });

        }
    );
}


function delete_price(codprd, codgas, fch, hra) {
    alertify.confirm('Eliminar precio actual', '¿Está segur@ de eliminar el precio actual? El cambio no podrá deshacerse pero se guardará en la bitácora electrónica.',
        function(){
            // Aqui vamos a redirigir a la ruta de eliminación
            window.location.href = '/supply/delete_price/' + codprd + '/' + codgas + '/' + fch + '/' + hra;
            toastr.success('El precio fue eliminado correctamente.', '¡Éxito!', { timeOut: 3000 });
        },
        function(){
            toastr.info('Operación cancelada', '¡Atención!', { timeOut: 3000 });
        }
    );
}


let datatable_creProducts = $('#datatable_creProducts').DataTable({
    colReorder: true,
    order: [0, "asc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Precios de Combustibles',
        }
    ],
    ajax: {
        url: '/supply/datatable_creProducts',
        type: 'POST',
        error: function() {
            alertify.myAlert(
                `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Error!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                </div>`
            );
        }
    },
    deferRender: true,
    columns: [
        {'data': 'ID'},
        {'data': 'ESTACIÓN'},
        {'data': 'CREPRODUCTO'},
        {'data': 'CRESUBPRODUCTO'},
        {'data': 'CREMARCA'},
        {'data': 'ALTA'},
        {'data': 'ACTIONS'},
    ],
    rowId: 'ID',
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
    }
});

datatable_creProducts.on('draw', function() {
    $('[data-toggle="tooltip"]').tooltip();
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatable_creProducts input').on('keyup change clear', function () {
    datatable_creProducts
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#ESTACIÓN').val().trim())
        .column(2).search($('#CREPRODUCTO').val().trim())
        .column(3).search($('#CRESUBPRODUCTO').val().trim())
        .column(4).search($('#CREMARCA').val().trim())
        .column(5).search($('#ALTA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatable_creProducts').on('click', function () {
    datatable_creProducts.clear().draw();
    datatable_creProducts.ajax.reload();
    $('#datatable_creProducts').waitMe('hide');
});


async function payment_control_table(){
    if ($.fn.DataTable.isDataTable('#payment_control_table')) {
        $('#payment_control_table').DataTable().destroy();
        $('#payment_control_table thead .filter').remove();
    }
    var fromDate = document.getElementById('from1').value;
    var untilDate = document.getElementById('until1').value;
    var codgas = document.getElementById('station_id1').value;
    if(!codgas){
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Debe seleccionar una estación para continuar.</p>
            </div>`
        );
        return;
    }

    $('#payment_control_table thead').prepend($('#payment_control_table thead tr').clone().addClass('filter'));
    $('#payment_control_table thead tr.filter th').each(function (index) {
        col = $('#payment_control_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#payment_control_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#payment_control_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let payment_control_table =$('#payment_control_table').DataTable({
        order: [[1, "asc"], [2, "desc"]],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        // scrollY: '700px',
        // scrollX: true,
        // scrollCollapse: true,
        paging: true,
        pageLength: 100,
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
                'codgas':codgas
            },
            url: '/supply/payment_control_table',
            timeout: 600000, 
            error: function() {
                $('#payment_control_table').waitMe('hide');
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
            { data: 'check_box' },                                // Folio del documento
            { data: 'gasolinera' },                                // Folio del documento
            { data: 'nro' },                                // Folio del documento
            { data: 'Factura' },                            // Texto extraído de @F:
            { data: 'Remision' },                           // Texto extraído de @R:
            { data: 'fecha' },                              // Fecha (fch - 1)
            { data: 'fechaVto' },                           // Vencimiento (vto - 1)
            { data: 'producto' },                           // Producto (t3.den)
            { data: 'proveedor' },                          // Proveedor (t4.den)
            { data: 'volrec', render: $.fn.dataTable.render.number(',', '.', 2) }, // Volumen recibido
            { data: 'can', render: $.fn.dataTable.render.number(',', '.', 2) },    // Cantidad
            { data: 'pre', render: $.fn.dataTable.render.number(',', '.', 4) },    // Precio unitario
            { data: 'mto', render: $.fn.dataTable.render.number(',', '.', 2) },    // Monto
            { data: 'mtoiie', render: $.fn.dataTable.render.number(',', '.', 2) }, // Monto IIE
            { data: 'iva8', render: $.fn.dataTable.render.number(',', '.', 2) },   // IVA 8%
            { data: 'iva', render: $.fn.dataTable.render.number(',', '.', 2) },    // IVA Extra
            { data: 'iva_total', render: $.fn.dataTable.render.number(',', '.', 2) }, // Total IVA
            { data: 'servicio', render: $.fn.dataTable.render.number(',', '.', 2) },  // Servicio
            { data: 'iva_servicio', render: $.fn.dataTable.render.number(',', '.', 2) }, // IVA Servicio
            { data: 'total_fac', render: $.fn.dataTable.render.number(',', '.', 2) },    // Total Factura
            { data: 'satuid', className: 'text-nowrap' }   // UID SAT
        ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
            var cls = data.control_estado === 'SI' ? 'bg-success' : 'bg-danger';
            // $('td:eq(19)', row)
            //   .addClass(cls)
            //   .text(data.control); // muestra “12345 SI” o “12345 NO”
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
        footerCallback: function (row, data, start, end, display) {
        }
    });
}

async function payment_list_table(){
    if ($.fn.DataTable.isDataTable('#payment_list_table')) {
        $('#payment_list_table').DataTable().destroy();
        $('#payment_list_table thead .filter').remove();
    }
    var fromDate = document.getElementById('from1').value;
    var untilDate = document.getElementById('until1').value;
    if(!codgas){
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Debe seleccionar una estación para continuar.</p>
            </div>`
        );
        return;
    }

    $('#payment_list_table thead').prepend($('#payment_list_table thead tr').clone().addClass('filter'));
    $('#payment_list_table thead tr.filter th').each(function (index) {
        col = $('#payment_list_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#payment_list_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#payment_list_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let payment_list_table =$('#payment_list_table').DataTable({
        order: [[1, "asc"], [2, "desc"]],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        // scrollY: '700px',
        // scrollX: true,
        // scrollCollapse: true,
        paging: true,
        pageLength: 100,
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
                'codgas':codgas
            },
            url: '/supply/payment_list_table',
            timeout: 600000, 
            error: function() {
                $('#payment_list_table').waitMe('hide');
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
            { data: 'check_box' },                                // Folio del documento
            { data: 'gasolinera' },                                // Folio del documento
            { data: 'nro' },                                // Folio del documento
            { data: 'Factura' },                            // Texto extraído de @F:
            { data: 'Remision' },                           // Texto extraído de @R:
        ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
            var cls = data.control_estado === 'SI' ? 'bg-success' : 'bg-danger';
            // $('td:eq(19)', row)
            //   .addClass(cls)
            //   .text(data.control); // muestra “12345 SI” o “12345 NO”
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
        footerCallback: function (row, data, start, end, display) {
        }
    });
}

function add_payment() {
    // Redirigir a la página de agregar pago
    window.location.href = '/supply/add_payment';
}

async function payment_create_table(){
    if ($.fn.DataTable.isDataTable('#payment_create_table')) {
        $('#payment_create_table').DataTable().destroy();
        $('#payment_create_table thead .filter').remove();
    }
    var fromDate = document.getElementById('from1').value;
    var untilDate = document.getElementById('until1').value;
    var codgas = document.getElementById('station_id1').value;
    var company = document.getElementById('company').value;
    var proveedor = document.getElementById('proveedor_id').value;

    if(!codgas || !company || !proveedor){
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Debe seleccionar una estación para continuar.</p>
            </div>`
        );
        return;
    }

    $('#payment_create_table thead').prepend($('#payment_create_table thead tr').clone().addClass('filter'));
    $('#payment_create_table thead tr.filter th').each(function (index) {
        col = $('#payment_create_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#payment_create_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#payment_create_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let payment_create_table =$('#payment_create_table').DataTable({
        order: [[0, "asc"], [1, "desc"]],
        colReorder: true,
        dom: '<"top"f>rt<"bottom"lip>',
        paging: true,
        pageLength: 100,
        ajax: {
            method: 'POST',
            data: {
                'fromDate':fromDate,
                'untilDate':untilDate,
                'codgas':codgas,
                'company':company,
                'proveedor':proveedor
            },
            url: '/supply/payment_control_table',
            timeout: 600000, 
            error: function() {
                $('#payment_create_table').waitMe('hide');
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
            
            // { data: 'check_box' },                                // Folio del documento
            { data: 'gasolinera' },                                // Folio del documento
            { data: 'nro' },                                // Folio del documento
            { data: 'Factura' },                            // Texto extraído de @F:
            { data: 'Remision' },                           // Texto extraído de @R:
            { data: 'fecha' },                              // Fecha (fch - 1)
            { data: 'fechaVto' },                           // Vencimiento (vto - 1)
            { data: 'producto' },                           // Producto (t3.den)
            { data: 'proveedor' },                          // Proveedor (t4.den)
            { data: 'volrec', render: $.fn.dataTable.render.number(',', '.', 2) }, // Volumen recibido
            { data: 'can', render: $.fn.dataTable.render.number(',', '.', 2) },    // Cantidad
            { data: 'total_fac', render: $.fn.dataTable.render.number(',', '.', 2) },    // Total Factura
        ],
         columnDefs: [
                    { orderable: false, targets: 0 }
                ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
            $(row).addClass('draggable-row');
            $(row).attr('draggable', 'true');
            $(row).data('rowData', data);
            $(row).find('td:first').prepend('<i class="fas fa-grip-vertical drag-handle me-2" style="color: #6c757d; cursor: move;"></i>');

        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            setupDragAndDrop();
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
        },
        footerCallback: function (row, data, start, end, display) {
        }
    });
}


async function providers_table(){
    if ($.fn.DataTable.isDataTable('#providers_table')) {
        $('#providers_table').DataTable().destroy();
        $('#providers_table thead .filter').remove();
    }


    $('#providers_table thead').prepend($('#providers_table thead tr').clone().addClass('filter'));
    $('#providers_table thead tr.filter th').each(function (index) {
        col = $('#providers_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#providers_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#providers_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let providers_table =$('#providers_table').DataTable({
        order: [[3, "desc"]],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        // scrollY: '700px',
        // scrollX: true,
        // scrollCollapse: true,
        paging: true,
        pageLength: 100,
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
            url: '/supply/providers_table',
            timeout: 600000, 
            error: function() {
                $('#providers_table').waitMe('hide');
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
            { data: 'id_control_gas', className: 'text-nowrap' },                                // Folio del documento
            { data: 'proveedor'},                          // Proveedor (t4.den)
            { data: 'dias_credito', className: 'text-nowrap' },                       // Días Crédito
            { data: 'total_facturado', render: $.fn.dataTable.render.number(',', '.', 2), className: 'text-nowrap text-end' },// Total total_facturado
            { data: 'limite_credito', render: $.fn.dataTable.render.number(',', '.', 2), className: 'text-nowrap text-end' },                     // Límite Crédito
            { data: 'condiciones_pago', className: 'text-nowrap' },                   // Condiciones Pago
            { data: 'observaciones', className: 'text-nowrap' },                      // Observaciones
            { data: 'activo', className: 'text-nowrap' },
        ],
        deferRender: true,
        createdRow: function (row, data, dataIndex) {
            if (parseFloat(data['total_facturado']) >= parseFloat(data['limite_credito'])) {
                // $(row).addClass('bg-warning');
            }

        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
        footerCallback: function (row, data, start, end, display) {
        }
    });
    
}

function filtrarEstacionesPorEmpresa() {
    const empresaSel = $('#company').val();
    const $station = $('#station_id1');

    // Si no se ha seleccionado empresa, mantener estaciones deshabilitadas
    if (empresaSel === null || empresaSel === '') {
        $station.prop('disabled', true);
        $station.selectpicker('refresh');
        return;
    }

    // Habilitar el select de estaciones
    $station.prop('disabled', false);

    // Destruir selectpicker para reconstruir opciones
    $station.selectpicker('destroy');

    // Limpiar todas las opciones
    $station.empty();

    // Agregar opción placeholder (NO seleccionada por defecto)
    $station.append('<option value="" disabled selected >Seleccione una estación</option>');

    // Agregar opción "Todas las estaciones"
    if (empresaSel === '0') {
        $station.append('<option value="0">Todas las estaciones</option>');
    } else {
        $station.append('<option value="0">Todas las estaciones de esta empresa</option>');
    }

    
    // Obtener y filtrar estaciones desde los datos originales
    if (window.originalStationOptions) {
        const $tempDiv = $('<div>').html(window.originalStationOptions);
        
        $tempDiv.find('option[data-emp]').each(function() {
            const emp = $(this).attr('data-emp');
            const stationValue = $(this).attr('value');
            const stationText = $(this).text();
            if (empresaSel === '0' || emp === empresaSel) {
                $station.append('<option value="' + stationValue + '" data-emp="' + emp + '">' + stationText + '</option>');
            }
        });
    } else {
        console.error('No se encontraron opciones originales');
    }

    // Seleccionar "Todas las estaciones" por defecto
    $station.val('0');

    // Reinicializar selectpicker
    $station.selectpicker({
        liveSearch: true,
        title: 'Seleccione una estación'
    });
    
    // $station.find('option').each(function() {
    //     console.log('Opción:', $(this).text(), 'Valor:', $(this).val());
    // });
}

// Función para guardar opciones originales (llamar después de cargar la página)
function saveOriginalStationOptions() {
    console.log('Guardando opciones originales de estaciones');
    if (!window.originalStationOptions) {
        window.originalStationOptions = $('#station_id').html();
    }
}


async function shop_fuel_table(){
    if ($.fn.DataTable.isDataTable('#shop_fuel_table')) {
        $('#shop_fuel_table').DataTable().destroy();
        $('#shop_fuel_table thead .filter').remove();
    }
    var fromDate = document.getElementById('from1').value;
    var untilDate = document.getElementById('until1').value;
    var codgas = document.getElementById('station_id1').value;
    if(!codgas){
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Debe seleccionar una estación para continuar.</p>
            </div>`
        );
        return;
    }

    $('#shop_fuel_table thead').prepend($('#shop_fuel_table thead tr').clone().addClass('filter'));
    $('#shop_fuel_table thead tr.filter th').each(function (index) {
        col = $('#shop_fuel_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#shop_fuel_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#shop_fuel_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let shop_fuel_table =$('#shop_fuel_table').DataTable({
        order: [[1, "asc"], [2, "desc"]],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        // scrollY: '700px',
        // scrollX: true,
        // scrollCollapse: true,
        paging: true,
        pageLength: 100,
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
                'codgas':codgas
            },
            url: '/supply/shop_fuel_table',
            timeout: 600000, 
            error: function() {
                $('#shop_fuel_table').waitMe('hide');
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
            { data: 'check_box' },                                // Folio del documento
            { data: 'gasolinera' },                                // Folio del documento
            { data: 'nro' },                                // Folio del documento
            { data: 'Factura' },                            // Texto extraído de @F:
            { data: 'Remision' },                           // Texto extraído de @R:
            { data: 'fecha' },                              // Fecha (fch - 1)
            { data: 'fechaVto' },                           // Vencimiento (vto - 1)
            { data: 'producto' },                           // Producto (t3.den)
            { data: 'proveedor' },                          // Proveedor (t4.den)
            { data: 'volrec', render: $.fn.dataTable.render.number(',', '.', 2) }, // Volumen recibido
            { data: 'can', render: $.fn.dataTable.render.number(',', '.', 2) },    // Cantidad
            { data: 'pre', render: $.fn.dataTable.render.number(',', '.', 4) },    // Precio unitario
            { data: 'mto', render: $.fn.dataTable.render.number(',', '.', 2) },    // Monto
            { data: 'mtoiie', render: $.fn.dataTable.render.number(',', '.', 2) }, // Monto IIE
            { data: 'iva8', render: $.fn.dataTable.render.number(',', '.', 2) },   // IVA 8%
            { data: 'iva', render: $.fn.dataTable.render.number(',', '.', 2) },    // IVA Extra
            { data: 'iva_total', render: $.fn.dataTable.render.number(',', '.', 2) }, // Total IVA
            { data: 'servicio', render: $.fn.dataTable.render.number(',', '.', 2) },  // Servicio
            { data: 'iva_servicio', render: $.fn.dataTable.render.number(',', '.', 2) }, // IVA Servicio
            { data: 'total_fac', render: $.fn.dataTable.render.number(',', '.', 2) },    // Total Factura
            { data: 'satuid', className: 'text-nowrap' }   // UID SAT
        ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
            var cls = data.control_estado === 'SI' ? 'bg-success' : 'bg-danger';
            // $('td:eq(19)', row)
            //   .addClass(cls)
            //   .text(data.control); // muestra “12345 SI” o “12345 NO”
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
        footerCallback: function (row, data, start, end, display) {
        }
    });
}




// ==========================================
// DESCARGAR FACTURAS POR UUID
// ==========================================
// ==========================================
// DESCARGAR FACTURAS POR UUID
// ==========================================

$(document).ready(function() {
    // Solo ejecutar si estamos en la página correcta
    if ($('#formImportarUUIDs').length > 0) {
        $('#formImportarUUIDs').on('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const btnProcesar = $('#btnProcesar');
            
            // Validar que se haya seleccionado un archivo
            if (!$('#archivo_excel')[0].files[0]) {
                alertify.error('Debe seleccionar un archivo Excel');
                return;
            }
            
            // Deshabilitar botón y mostrar progreso
            btnProcesar.prop('disabled', true);
            $('#areaProgreso').show();
            $('#areaResumen').hide();
            $('#barraProgreso').css('width', '10%').text('10%');
            $('#textoProgreso').text('Procesando archivo Excel...');
            
            // Enviar archivo para procesar
            $.ajax({
                url: '/supply/procesar_uuids_facturas',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $('#barraProgreso').css('width', '100%').text('100%');
                        $('#textoProgreso').text('UUIDs procesados correctamente');
                        
                        // Mostrar opciones de descarga
                        mostrarOpcionesDescarga(response.facturas, btnProcesar);
                    } else {
                        btnProcesar.prop('disabled', false);
                        $('#areaProgreso').hide();
                        alertify.error(response.message || 'Error al procesar el archivo');
                    }
                },
                error: function(xhr) {
                    btnProcesar.prop('disabled', false);
                    $('#areaProgreso').hide();
                    
                    let mensaje = 'Error al procesar el archivo';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        mensaje = xhr.responseJSON.message;
                    }
                    
                    alertify.error(mensaje);
                }
            });
        });
    }
});

function mostrarOpcionesDescarga(facturas, btnProcesar, facturasFallidas = []) {
    $('#areaProgreso').hide();
    $('#areaResumen').show();
    
    const totalExitosas = facturas.length;
    const totalFallidas = facturasFallidas.length;
    const totalGeneral = totalExitosas + totalFallidas;
    
    if (totalGeneral === 0) {
        $('#areaResumen').html(`
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                No se encontraron UUIDs válidos en el archivo Excel
            </div>
        `);
        btnProcesar.prop('disabled', false);
        return;
    }
    
    // Crear resumen con opciones de descarga
    let html = `
        <div class="row">
            <div class="col-12 mb-3">
                <div class="alert ${totalFallidas > 0 ? 'alert-warning' : 'alert-success'}">
                    <h5><i class="fas fa-info-circle"></i> Resumen de Búsqueda</h5>
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Total solicitado:</strong> <span class="badge bg-primary">${totalGeneral}</span>
                        </div>
                        <div class="col-md-4">
                            <strong class="text-success">Encontradas:</strong> <span class="badge bg-success">${totalExitosas}</span>
                        </div>
                        <div class="col-md-4">
                            <strong class="text-danger">Fallidas:</strong> <span class="badge bg-danger">${totalFallidas}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Tarjeta de facturas exitosas
    if (totalExitosas > 0) {
        html += `
            <div class="card border-success mb-3">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-check-circle"></i> 
                        Facturas Disponibles para Descarga (${totalExitosas})
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <button class="btn btn-primary btn-lg" id="btnDescargarZip">
                            <i class="fas fa-file-archive"></i> Descargar Todo en ZIP (Recomendado)
                        </button>
                        <button class="btn btn-secondary" id="btnDescargarIndividual">
                            <i class="fas fa-file-pdf"></i> Descargar Individual
                        </button>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-striped table-hover">
                            <thead class="table-success sticky-top">
                                <tr>
                                    <th width="40"><i class="fas fa-check"></i></th>
                                    <th>Folio</th>
                                    <th>UUID</th>
                                    <th>Emisor</th>
                                    <th class="text-end">Total</th>
                                    <th>Archivo</th>
                                </tr>
                            </thead>
                            <tbody>
        `;
        
        facturas.forEach(f => {
            html += `
                <tr>
                    <td><i class="fas fa-check-circle text-success"></i></td>
                    <td><strong>${f.folio || 'N/A'}</strong></td>
                    <td><small class="font-monospace text-muted">${f.uuid.substring(0, 8)}...${f.uuid.substring(28)}</small></td>
                    <td>${f.emisor || 'N/A'}</td>
                    <td class="text-end"><strong>$${parseFloat(f.total || 0).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                    <td><small>${f.nombre_archivo}</small></td>
                </tr>
            `;
        });
        
        html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Tarjeta de facturas fallidas
    if (totalFallidas > 0) {
        html += `
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">
                        <i class="fas fa-times-circle"></i> 
                        UUIDs No Disponibles (${totalFallidas})
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-3">
                        <i class="fas fa-exclamation-triangle"></i>
                        Los siguientes UUIDs no pudieron ser procesados. Revise el motivo de cada uno.
                    </div>
                    
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-bordered">
                            <thead class="table-danger sticky-top">
                                <tr>
                                    <th width="40"><i class="fas fa-times"></i></th>
                                    <th>UUID</th>
                                    <th>Folio</th>
                                    <th>Tipo de Error</th>
                                    <th>Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
        `;
        
        facturasFallidas.forEach(f => {
            let tipoError = '';
            let iconoError = '';
            let colorBadge = 'bg-danger';
            
            switch(f.estado) {
                case 'formato_invalido':
                    tipoError = 'Formato Inválido';
                    iconoError = '<i class="fas fa-exclamation-triangle text-warning"></i>';
                    colorBadge = 'bg-warning';
                    break;
                case 'no_encontrado_bd':
                    tipoError = 'No en BD';
                    iconoError = '<i class="fas fa-database text-danger"></i>';
                    colorBadge = 'bg-danger';
                    break;
                case 'archivo_no_existe':
                    tipoError = 'Archivo No Existe';
                    iconoError = '<i class="fas fa-file-excel text-orange"></i>';
                    colorBadge = 'bg-orange';
                    break;
                default:
                    tipoError = 'Error';
                    iconoError = '<i class="fas fa-times-circle text-danger"></i>';
            }
            
            const folioTexto = f.folio || 'N/A';
            const filaInfo = f.fila ? ` (Fila ${f.fila})` : '';
            
            html += `
                <tr>
                    <td class="text-center">${iconoError}</td>
                    <td><small class="font-monospace">${f.uuid}${filaInfo}</small></td>
                    <td><strong>${folioTexto}</strong></td>
                    <td><span class="badge ${colorBadge}">${tipoError}</span></td>
                    <td><small>${f.error || 'Error desconocido'}</small></td>
                </tr>
            `;
        });
        
        html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
    }
    
    $('#areaResumen').html(html);
    btnProcesar.prop('disabled', false);
    
    // Event listeners solo si hay facturas exitosas
    if (totalExitosas > 0) {
        $('#btnDescargarZip').on('click', function() {
            descargarFacturasZip(facturas);
        });
        
        $('#btnDescargarIndividual').on('click', function() {
            descargarFacturasIndividual(facturas);
        });
    }
}   

// Actualizar la llamada en el success del formulario
$('#formImportarUUIDs').on('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const btnProcesar = $('#btnProcesar');
    
    if (!$('#archivo_excel')[0].files[0]) {
        alertify.error('Debe seleccionar un archivo Excel');
        return;
    }
    
    btnProcesar.prop('disabled', true);
    $('#areaProgreso').show();
    $('#areaResumen').hide();
    $('#barraProgreso').css('width', '10%').text('10%');
    $('#textoProgreso').text('Procesando archivo Excel...');
    
    $.ajax({
        url: '/supply/procesar_uuids_facturas',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                $('#barraProgreso').css('width', '100%').text('100%');
                $('#textoProgreso').text('Procesamiento completado');
                
                // Pasar tanto exitosas como fallidas
                setTimeout(() => {
                    mostrarOpcionesDescarga(
                        response.facturas || [], 
                        btnProcesar,
                        response.facturas_fallidas || []
                    );
                }, 500);
            } else {
                btnProcesar.prop('disabled', false);
                $('#areaProgreso').hide();
                alertify.error(response.message || 'Error al procesar el archivo');
            }
        },
        error: function(xhr) {
            btnProcesar.prop('disabled', false);
            $('#areaProgreso').hide();
            
            let mensaje = 'Error al procesar el archivo';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                mensaje = xhr.responseJSON.message;
            }
            
            alertify.error(mensaje);
        }
    });
});

function descargarFacturasZip(facturas) {
    $('#btnDescargarZip').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Creando ZIP...');
    
    const ids = facturas.map(f => f.id);
    
    $.ajax({
        url: '/supply/descargar_facturas_zip',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({ ids: ids }),
        success: function(response) {
            if (response.success) {
                alertify.success(`ZIP creado con ${response.archivos_agregados} facturas`);
                
                // Descargar el ZIP
                window.location.href = response.download_url;
                
                // Mostrar advertencias si hubo archivos no encontrados
                if (response.archivos_no_encontrados.length > 0) {
                    setTimeout(() => {
                        alertify.warning(`${response.archivos_no_encontrados.length} archivos no se pudieron agregar al ZIP`);
                    }, 1000);
                }
            } else {
                alertify.error(response.message || 'Error al crear ZIP');
            }
            
            $('#btnDescargarZip').prop('disabled', false).html('<i class="fas fa-file-archive"></i> Descargar Todo en ZIP');
        },
        error: function() {
            alertify.error('Error al crear el archivo ZIP');
            $('#btnDescargarZip').prop('disabled', false).html('<i class="fas fa-file-archive"></i> Descargar Todo en ZIP');
        }
    });
}

function descargarFacturasIndividual(facturas) {
    alertify.confirm(
        'Descarga Individual',
        `¿Desea descargar ${facturas.length} archivos de forma individual? (Esto puede tardar más tiempo)`,
        function() {
            const exitosas = [];
            const fallidas = [];
            const total = facturas.length;
            let procesados = 0;
            
            $('#areaProgreso').show();
            $('#barraProgreso').css('width', '0%').text('0%').addClass('progress-bar-animated');
            $('#textoProgreso').text('Descargando archivos...');
            
            async function descargarArchivo(factura) {
                return new Promise((resolve) => {
                    fetch('/supply/descargar_factura/' + factura.id, {
                        method: 'GET'
                    })
                    .then(response => {
                        if (!response.ok) throw new Error('No se pudo descargar');
                        return response.blob();
                    })
                    .then(blob => {
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.style.display = 'none';
                        a.href = url;
                        a.download = factura.nombre_archivo;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        document.body.removeChild(a);
                        
                        exitosas.push(factura);
                        resolve(true);
                    })
                    .catch(error => {
                        fallidas.push({ uuid: factura.uuid, error: error.message });
                        resolve(false);
                    })
                    .finally(() => {
                        procesados++;
                        const progreso = (procesados / total * 100);
                        $('#barraProgreso').css('width', progreso + '%').text(Math.round(progreso) + '%');
                        $('#textoProgreso').text(`Descargando... ${procesados}/${total}`);
                    });
                });
            }
            
            async function procesarDescargas() {
                for (let i = 0; i < facturas.length; i++) {
                    await descargarArchivo(facturas[i]);
                    await new Promise(resolve => setTimeout(resolve, 500));
                }
                
                $('#areaProgreso').hide();
                alertify.success(`Descarga completada: ${exitosas.length} exitosas, ${fallidas.length} fallidas`);
            }
            
            procesarDescargas();
        },
        function() {
            alertify.message('Descarga cancelada');
        }
    );
}

function descargarFacturas(facturas, btnProcesar) {
    const exitosas = [];
    const fallidas = [];
    const total = facturas.length;
    let procesados = 0;
    
    if (total === 0) {
        alertify.warning('No se encontraron facturas con los UUIDs proporcionados');
        btnProcesar.prop('disabled', false);
        $('#areaProgreso').hide();
        return;
    }
    
    // Función para descargar un archivo individual
    function descargarArchivo(factura, index) {
        return new Promise((resolve) => {
            fetch('/supply/descargar_factura/' + factura.id, {
                method: 'GET'
            })
            .then(response => {
                if (!response.ok) throw new Error('No se pudo descargar');
                return response.blob();
            })
            .then(blob => {
                // Crear enlace de descarga
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = factura.nombre_archivo;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                exitosas.push(factura);
                resolve(true);
            })
            .catch(error => {
                fallidas.push({
                    uuid: factura.uuid,
                    error: error.message,
                    folio: factura.folio
                });
                resolve(false);
            })
            .finally(() => {
                procesados++;
                const progreso = 50 + (procesados / total * 50);
                $('#barraProgreso').css('width', progreso + '%')
                    .text(Math.round(progreso) + '%');
                $('#textoProgreso').text(`Descargando archivos... ${procesados}/${total}`);
            });
        });
    }
    
    // Procesar descargas con delay para no saturar el navegador
    async function procesarDescargas() {
        for (let i = 0; i < facturas.length; i++) {
            await descargarArchivo(facturas[i], i);
            // Delay de 500ms entre descargas para no saturar
            await new Promise(resolve => setTimeout(resolve, 500));
        }
        
        // Mostrar resumen
        mostrarResumen(exitosas, fallidas, btnProcesar);
    }
    
    procesarDescargas();
}

function mostrarResumen(exitosas, fallidas, btnProcesar) {
    $('#barraProgreso').css('width', '100%').text('100%')
        .removeClass('progress-bar-animated');
    $('#textoProgreso').text('Proceso completado');
    
    setTimeout(() => {
        $('#areaProgreso').hide();
        $('#areaResumen').show();
        
        // Mostrar exitosas
        if (exitosas.length > 0) {
            $('#cardExitosas').show();
            $('#countExitosas').text(exitosas.length);
            const listaHtml = exitosas.map(f => 
                `<li class="mb-1">
                    <i class="fas fa-check text-success"></i> 
                    <strong>Folio:</strong> ${f.folio || 'N/A'} | 
                    <strong>UUID:</strong> ${f.uuid}<br>
                    <small class="text-muted">${f.nombre_archivo}</small>
                </li>`
            ).join('');
            $('#listaExitosas').html(listaHtml);
        }
        
        // Mostrar fallidas
        if (fallidas.length > 0) {
            $('#cardFallidas').show();
            $('#countFallidas').text(fallidas.length);
            const listaHtml = fallidas.map(f => 
                `<li class="mb-1">
                    <i class="fas fa-times text-danger"></i> 
                    <strong>UUID:</strong> ${f.uuid}<br>
                    <small>${f.error || 'No encontrada'}</small>
                </li>`
            ).join('');
            $('#listaFallidas').html(listaHtml);
        }
        
        btnProcesar.prop('disabled', false);
        
        // Mensaje resumen
        alertify.success(`Proceso completado: ${exitosas.length} descargadas, ${fallidas.length} fallidas`);
    }, 500);
}