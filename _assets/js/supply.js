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
            url: '/supply/  ',
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



// ... [CÓDIGO ANTERIOR DE SUPPLY.JS SE MANTIENE IGUAL HASTA LA LÍNEA ~1440] ...

// ==========================================
// DESCARGAR FACTURAS POR UUID - VERSIÓN ÚNICA Y DEFINITIVA
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

// ==========================================
// FIN DE CÓDIGO DE FACTURAS UUID
// ==========================================
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
async function resumen_payment_table() {
    // Destruir tabla existente si existe
    if ($.fn.DataTable.isDataTable('#resumen_payment_table')) {
        $('#resumen_payment_table').DataTable().clear().destroy();
        $('#resumen_payment_table thead .filter').remove();
        $('#resumen_payment_table tbody').empty();
    }

    // Obtener valores de los filtros
    var fromDate = document.getElementById('from_resumen').value;
    var untilDate = document.getElementById('until_resumen').value;
    var codgas = document.getElementById('station_id_resumen').value || '0';
    var proveedor = document.getElementById('proveedor_resumen').value || '0';
    var company = document.getElementById('company_resumen').value || '0';

    // Validación de fechas
    if (!fromDate || !untilDate) {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Debe seleccionar un rango de fechas para continuar.</p>
            </div>`
        );
        return;
    }
   $('#resumen_payment_table thead').prepend($('#resumen_payment_table thead tr').clone().addClass('filter'));
    $('#resumen_payment_table thead tr.filter th').each(function (index) {
        col = $('#resumen_payment_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#resumen_payment_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#resumen_payment_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let movimientoActual = {};

    // Inicializar DataTable
    let resumen_payment_table = $('#resumen_payment_table').DataTable({
        order: [[0, "asc"]],
        scrollY: '700px',
        colReorder: false, // ← Desactivar para evitar problemas de alineación
        fixedHeader: false, // ← Desactivar, usaremos CSS sticky
        dom: '<"top"Bf>rt<"bottom"lip>',
        scrollX: true,
        scrollCollapse: true,
        // responsive: false,
        paging: false,
        autoWidth: false, // ← IMPORTANTE: Desactivar auto width
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-success',
                text: ' Excel'
            },
            {
                extend: 'colvis',
                className: 'btn btn-sm btn-secondary',
                text: '<i class="bi bi-eye"></i> Columnas'
            }
        ],
        ajax: {
            method: 'POST',
            data: {
                'fromDate': fromDate,
                'untilDate': untilDate,
                'codgas': codgas,
                'proveedor': proveedor,
                'company': company
            },
            url: '/supply/resumen_payment_table',
            timeout: 600000,
            error: function(xhr, error, thrown) {
                $('.datatable-wrapper').removeClass('loading');
                alertify.error('Error al cargar datos: ' + thrown);
            },
            beforeSend: function() {
                $('.datatable-wrapper').addClass('loading');
            },
            dataSrc: function(json) {
                if (json.data && json.data.length > 0) {
                    $('#table-info').html(
                        `<i class="bi bi-info-circle"></i> ${json.data.length} registro(s)`
                    );
                }
                return json.data;
            }
        },
        columns: [
            { 
                data: null,
                className: 'text-center',
                orderable: false,   
                width: '120px',
                render: function(data, type, row) {
                    let icono = '';
                    let tooltipTexto = '';
                    
                    if (row.tiene_facturas_asignadas) {
                        if (row.tipo_operacion == 2) {
                            // Operación con Petrotal
                            icono = '<i class="fas fa-layer-group text-warning" title="Vía Petrotal"></i>';
                            tooltipTexto = `Proveedor → Petrotal → TotalGas\nUsuario: ${row.usuario_asignacion}\nFecha: ${row.fecha_asignacion}`;
                        } else {
                            // Operación directa
                            icono = '<i class="fas fa-check-circle text-success" title="Compra Directa"></i>';
                            tooltipTexto = `Compra Directa\nUsuario: ${row.usuario_asignacion}\nFecha: ${row.fecha_asignacion}`;
                        }
                    } else {
                        icono = '<i class="fas fa-exclamation-circle text-danger" title="Sin asignar"></i>';
                        tooltipTexto = 'Sin factura asignada';
                    }
                    
                    const btnAsignar = row.tiene_facturas_asignadas
                        ? `<button class="btn btn-sm btn-info" 
                                onclick='abrirModalAsignacion(${JSON.stringify(row).replace(/'/g, "&apos;")})' 
                                title="Editar asignación">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" 
                                onclick='eliminarAsignacion(${JSON.stringify(row).replace(/'/g, "&apos;")})' 
                                title="Eliminar">
                            <i class="fas fa-trash"></i>
                        </button>`
                        : `<button class="btn btn-sm btn-primary" 
                                onclick='abrirModalAsignacion(${JSON.stringify(row).replace(/'/g, "&apos;")})' 
                                title="Asignar facturas">
                            <i class="fas fa-link"></i>
                        </button>`;
                    
                    return `<div class="btn-group btn-group-sm" title="${tooltipTexto}">${icono} ${btnAsignar}</div>`;
                }
            },
            { data: 'fecha', className: 'text-center text-nowrap' },
            { data: 'estacion', className: 'text-start text-nowrap' },
            { data: 'numero_estacion', className: 'text-center  text-nowrap' },
            { data: 'proveedor_original', className: 'text-start text-nowrap' },
            { data: 'combustible', className: 'text-start text-nowrap' },
            { 
                data: 'num_fac_proveedor', 
                className: 'text-start text-nowrap',
                render: function(data, type, row) {
                    if (row.tiene_facturas_asignadas && row.tipo_operacion == 2) {
                        // Mostrar ambos folios si es operación Petrotal
                        return `<small>Prov: ${row.folio_proveedor || 'N/A'}<br>Petrotal: ${row.folio_petrotal || 'N/A'}</small>`;
                    }
                    return data || 'N/A';
                }
        },            
        { data: 'proveedor_final', className: 'text-start text-nowrap' },
            {data: 'cantidad_factura_controlgas',className: 'text-end',render: $.fn.dataTable.render.number(',', '.', 2)},
            {data: 'monto_factura_controlgas',className: 'text-end',render: $.fn.dataTable.render.number( ',', '.', 2, '$' )},
            {data: 'precio_factura_controlgas',className: 'text-end',render: $.fn.dataTable.render.number(',', '.', 4)},
            { 
                data: 'uuid',
                className: 'text-start text-nowrap',
                render: function(data) {
                    if (!data || data === '') {
                        return '<span class="badge bg-warning text-dark">Sin UUID</span>';
                    }
                    return '<small>' + data + '</small>';
                }
            },
            { data: 'proveedor_controlgas', className: 'text-start text-nowrap' },
            { 
                data: 'monto_factura_controlgas',
                className: 'text-end',
                render: function(data) {
                    return data ? '$' + parseFloat(data).toLocaleString('es-MX', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) : '$0.00';
                }
            },
            { 
                data: 'cantidad_factura_controlgas',
                className: 'text-end',
                render: function(data) {
                    return data ? parseFloat(data).toLocaleString('es-MX', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) : '0.00';
                }
            },
            
            { data: 'graprd', className: 'text-center' },
            { data: 'nrotrn', className: 'text-center text-nowrap' },

        ],
        columnDefs: [
            { targets: '_all', orderable: true }
        ],
        deferRender: true,
        createdRow: function (row, data, dataIndex) {
           if (!data.tiene_factura) {
                $(row).addClass('table-warning');
            }
            
            // Agregar atributos de datos para fácil acceso
            $(row).attr('data-nrotrn', data.nrotrn);
            $(row).attr('data-codgas', data.numero_estacion);
        },
        initComplete: function () {
            $('.datatable-wrapper').removeClass('loading');
            alertify.success('Tabla cargada exitosamente');
        },
        drawCallback: function(settings) {
            this.api().columns.adjust();

        }
    });
}

// ==========================================
// SISTEMA DE ASIGNACIÓN DE FACTURAS (DIRECTO Y PETROTAL)
// ==========================================

let movimientoActual = {};
let facturaProveedorSeleccionada = null;
let facturaPetrotalSeleccionada = null;

// Evento para cambio de tipo de operación
$(document).ready(function() {
    $('input[name="tipoOperacion"]').on('change', function() {
        const tipoPetrotal = $(this).val() === '2';
        
        // Mostrar/ocultar elementos según el tipo
        if (tipoPetrotal) {
            $('#paso2').fadeIn();
            $('#flecha2').fadeIn();
            $('#petrotal-tab-item').fadeIn();
            $('#paso3_directo').removeClass('col-md-3').addClass('col-md-3');
        } else {
            $('#paso2').fadeOut();
            $('#flecha2').fadeOut();
            $('#petrotal-tab-item').fadeOut();
            $('#paso3_directo').removeClass('col-md-3').addClass('col-md-3');
            
            // Limpiar selección de Petrotal
            facturaPetrotalSeleccionada = null;
            $('#info_petrotal').html('<span class="text-muted">Sin asignar</span>');
            $('#badge-petrotal').removeClass('bg-success').addClass('bg-danger').text('Requerida');
        }
        
        validarAsignacionCompleta();
    });
});

function abrirModalAsignacion(movimiento) {
    movimientoActual = movimiento;
    facturaProveedorSeleccionada = null;
    facturaPetrotalSeleccionada = null;
    
    // Llenar información del movimiento
    $('#modal_nrotrn').text(movimiento.nrotrn);
    $('#modal_estacion').text(movimiento.estacion);
    $('#modal_fecha').text(movimiento.fecha);
    $('#modal_combustible').text(movimiento.combustible);
    $('#modal_litros').text(parseFloat(movimiento.recaudado || 0).toLocaleString('es-MX', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }));
    
    // Determinar tipo de operación si ya está asignada
    if (movimiento.tiene_facturas_asignadas) {
        const tipoOp = movimiento.tipo_operacion || 1;
        $(`input[name="tipoOperacion"][value="${tipoOp}"]`).prop('checked', true).trigger('change');
        
        // Pre-cargar facturas asignadas
        if (movimiento.factura_proveedor_id) {
            facturaProveedorSeleccionada = {
                id: movimiento.factura_proveedor_id,
                uuid: movimiento.uuid_proveedor,
                folio: movimiento.folio_proveedor,
                total: movimiento.total_factura_proveedor,
                emisor: movimiento.emisor_factura_proveedor,
                litros: movimiento.litros_proveedor,
                precio: movimiento.precio_proveedor
            };
            actualizarInfoProveedor();
        }
        
        if (movimiento.factura_petrotal_id) {
            facturaPetrotalSeleccionada = {
                id: movimiento.factura_petrotal_id,
                uuid: movimiento.uuid_petrotal,
                folio: movimiento.folio_petrotal,
                total: movimiento.total_factura_petrotal,
                litros: movimiento.litros_petrotal,
                precio: movimiento.precio_petrotal
            };
            actualizarInfoPetrotal();
        }
    } else {
        // Nueva asignación - por defecto directo
        $('#tipoDirecto').prop('checked', true).trigger('change');
    }
    
    // Limpiar formularios
    $('#search_factura_proveedor, #fecha_inicio_proveedor, #fecha_fin_proveedor').val('');
    $('#search_factura_petrotal, #fecha_inicio_petrotal, #fecha_fin_petrotal').val('');
    $('#observaciones_asignacion').val(movimiento.observaciones || '');
    
    // Limpiar tablas
    $('#tbody_facturas_proveedor').html(`
        <tr><td colspan="8" class="text-center text-muted">
            <i class="fas fa-search"></i> Utilice los filtros para buscar facturas
        </td></tr>
    `);
    $('#tbody_facturas_petrotal').html(`
        <tr><td colspan="7" class="text-center text-muted">
            <i class="fas fa-search"></i> Utilice los filtros para buscar facturas
        </td></tr>
    `);
    
    $('#resumenAsignacion').hide();
    $('#btnGuardarAsignacion').prop('disabled', true);
    
    // Abrir modal
    $('#modalAsignarFactura').modal('show');
}

// ==========================================
// BÚSQUEDA DE FACTURAS PROVEEDOR
// ==========================================
function buscarFacturasProveedor() {
    const searchTerm = $('#search_factura_proveedor').val();
    const fechaInicio = $('#fecha_inicio_proveedor').val();
    const fechaFin = $('#fecha_fin_proveedor').val();
    
    if (!searchTerm && (!fechaInicio || !fechaFin)) {
        alertify.warning('Debe ingresar al menos un criterio de búsqueda');
        return;
    }
    
    $('#tbody_facturas_proveedor').html(`
        <tr><td colspan="8" class="text-center">
            <i class="fas fa-spinner fa-spin"></i> Buscando facturas...
        </td></tr>
    `);
    
    $.ajax({
        url: '/supply/buscar_facturas_proveedor',
        type: 'POST',
        data: {
            search: searchTerm,
            fecha_inicio: fechaInicio,
            fecha_fin: fechaFin,
            tipo: 'proveedor' // Excluir facturas de Petrotal
        },
        success: function(response) {
            if (response.success && response.data.length > 0) {
                let html = '';
                
                response.data.forEach(factura => {
                    // Calcular litros y precio por litro desde conceptos si existen
                    const litros = factura.litros || 0;
                    const precioLitro = litros > 0 ? (factura.total / litros) : 0;
                    
                    const btnSeleccionar = `
                        <button class="btn btn-sm btn-success" 
                                onclick='seleccionarFacturaProveedor(${JSON.stringify(factura)})' 
                                title="Seleccionar esta factura">
                            <i class="fas fa-check"></i>
                        </button>
                    `;
                    
                    html += `
                        <tr>
                            <td>${btnSeleccionar}</td>
                            <td><strong>${factura.folio || 'N/A'}</strong></td>
                            <td>${factura.fecha}</td>
                            <td><small>${factura.emisor_nombre}</small></td>
                            <td class="text-end"><strong>$${parseFloat(factura.total).toLocaleString('es-MX', {minimumFractionDigits: 2})}</strong></td>
                            <td class="text-end">${litros > 0 ? litros.toLocaleString('es-MX', {minimumFractionDigits: 2}) : 'N/A'}</td>
                            <td class="text-end">${precioLitro > 0 ? '$' + precioLitro.toFixed(4) : 'N/A'}</td>
                            <td><small class="font-monospace">${factura.uuid.substring(0,8)}...</small></td>
                        </tr>
                    `;
                });
                
                $('#tbody_facturas_proveedor').html(html);
            } else {
                $('#tbody_facturas_proveedor').html(`
                    <tr><td colspan="8" class="text-center text-muted">
                        <i class="fas fa-inbox"></i> No se encontraron facturas
                    </td></tr>
                `);
            }
        },
        error: function() {
            alertify.error('Error al buscar facturas');
        }
    });
}

function seleccionarFacturaProveedor(factura) {
    facturaProveedorSeleccionada = factura;
    actualizarInfoProveedor();
    validarAsignacionCompleta();
    
    // Cambiar al tab de Petrotal si es operación con intermediario
    if ($('input[name="tipoOperacion"]:checked').val() === '2') {
        $('#petrotal-tab').click();
    }
}

function actualizarInfoProveedor() {
    if (facturaProveedorSeleccionada) {
        const litros = facturaProveedorSeleccionada.litros || 0;
        const precio = facturaProveedorSeleccionada.precio || 
                       (litros > 0 ? (facturaProveedorSeleccionada.total / litros) : 0);
        
        $('#info_proveedor').html(`
            <strong class="text-success">${facturaProveedorSeleccionada.emisor_nombre || facturaProveedorSeleccionada.emisor}</strong><br>
            <small>Folio: ${facturaProveedorSeleccionada.folio}</small><br>
            <small>Total: $${parseFloat(facturaProveedorSeleccionada.total).toLocaleString('es-MX', {minimumFractionDigits: 2})}</small><br>
            <small>Precio/L: $${precio.toFixed(4)}</small>
        `);
        
        $('#badge-proveedor').removeClass('bg-danger').addClass('bg-success').text('Asignada');
        
        $('#resumen_proveedor').html(`
            <strong>Folio:</strong> ${facturaProveedorSeleccionada.folio}<br>
            <strong>UUID:</strong> ${facturaProveedorSeleccionada.uuid}<br>
            <strong>Total:</strong> $${parseFloat(facturaProveedorSeleccionada.total).toLocaleString('es-MX', {minimumFractionDigits: 2})}<br>
            <strong>Litros:</strong> ${litros.toLocaleString('es-MX', {minimumFractionDigits: 2})}<br>
            <strong>Precio/L:</strong> $${precio.toFixed(4)}
        `);
        
        $('#resumenAsignacion').show();
    }
}

// ==========================================
// BÚSQUEDA DE FACTURAS PETROTAL
// ==========================================
function buscarFacturasPetrotal() {
    const searchTerm = $('#search_factura_petrotal').val();
    const fechaInicio = $('#fecha_inicio_petrotal').val();
    const fechaFin = $('#fecha_fin_petrotal').val();
    
    if (!searchTerm && (!fechaInicio || !fechaFin)) {
        alertify.warning('Debe ingresar al menos un criterio de búsqueda');
        return;
    }
    
    $('#tbody_facturas_petrotal').html(`
        <tr><td colspan="7" class="text-center">
            <i class="fas fa-spinner fa-spin"></i> Buscando facturas...
        </td></tr>
    `);
    
    $.ajax({
        url: '/supply/buscar_facturas_petrotal',
        type: 'POST',
        data: {
            search: searchTerm,
            fecha_inicio: fechaInicio,
            fecha_fin: fechaFin
        },
        success: function(response) {
            if (response.success && response.data.length > 0) {
                let html = '';
                
                response.data.forEach(factura => {
                    const litros = factura.litros || 0;
                    const precioLitro = litros > 0 ? (factura.total / litros) : 0;
                    
                    const btnSeleccionar = `
                        <button class="btn btn-sm btn-warning" 
                                onclick='seleccionarFacturaPetrotal(${JSON.stringify(factura)})' 
                                title="Seleccionar esta factura">
                            <i class="fas fa-check"></i>
                        </button>
                    `;
                    
                    html += `
                        <tr>
                            <td>${btnSeleccionar}</td>
                            <td><strong>${factura.folio || 'N/A'}</strong></td>
                            <td>${factura.fecha}</td>
                            <td class="text-end"><strong>$${parseFloat(factura.total).toLocaleString('es-MX', {minimumFractionDigits: 2})}</strong></td>
                            <td class="text-end">${litros > 0 ? litros.toLocaleString('es-MX', {minimumFractionDigits: 2}) : 'N/A'}</td>
                            <td class="text-end">${precioLitro > 0 ? '$' + precioLitro.toFixed(4) : 'N/A'}</td>
                            <td><small class="font-monospace">${factura.uuid.substring(0,8)}...</small></td>
                        </tr>
                    `;
                });
                
                $('#tbody_facturas_petrotal').html(html);
            } else {
                $('#tbody_facturas_petrotal').html(`
                    <tr><td colspan="7" class="text-center text-muted">
                        <i class="fas fa-inbox"></i> No se encontraron facturas de Petrotal
                    </td></tr>
                `);
            }
        },
        error: function() {
            alertify.error('Error al buscar facturas');
        }
    });
}

function seleccionarFacturaPetrotal(factura) {
    facturaPetrotalSeleccionada = factura;
    actualizarInfoPetrotal();
    validarAsignacionCompleta();
    calcularMargen();
}

function actualizarInfoPetrotal() {
    if (facturaPetrotalSeleccionada) {
        const litros = facturaPetrotalSeleccionada.litros || 0;
        const precio = facturaPetrotalSeleccionada.precio || 
                       (litros > 0 ? (facturaPetrotalSeleccionada.total / litros) : 0);
        
        $('#info_petrotal').html(`
            <strong class="text-warning">PETROTAL</strong><br>
            <small>Folio: ${facturaPetrotalSeleccionada.folio}</small><br>
            <small>Total: $${parseFloat(facturaPetrotalSeleccionada.total).toLocaleString('es-MX', {minimumFractionDigits: 2})}</small><br>
            <small>Precio/L: $${precio.toFixed(4)}</small>
        `);
        
        $('#badge-petrotal').removeClass('bg-danger').addClass('bg-success').text('Asignada');
        
        $('#resumen_petrotal_container').show();
        $('#resumen_petrotal').html(`
            <strong>Folio:</strong> ${facturaPetrotalSeleccionada.folio}<br>
            <strong>UUID:</strong> ${facturaPetrotalSeleccionada.uuid}<br>
            <strong>Total:</strong> $${parseFloat(facturaPetrotalSeleccionada.total).toLocaleString('es-MX', {minimumFractionDigits: 2})}<br>
            <strong>Litros:</strong> ${litros.toLocaleString('es-MX', {minimumFractionDigits: 2})}<br>
            <strong>Precio/L:</strong> $${precio.toFixed(4)}
        `);
    }
}

// ==========================================
// VALIDACIÓN Y CÁLCULOS
// ==========================================
function validarAsignacionCompleta() {
    const tipoOperacion = $('input[name="tipoOperacion"]:checked').val();
    let esValido = false;
    
    if (tipoOperacion === '1') {
        // Operación directa: solo necesita factura proveedor
        esValido = facturaProveedorSeleccionada !== null;
    } else {
        // Operación con Petrotal: necesita ambas facturas
        esValido = facturaProveedorSeleccionada !== null && 
                   facturaPetrotalSeleccionada !== null;
    }
    
    $('#btnGuardarAsignacion').prop('disabled', !esValido);
    
    return esValido;
}

function calcularMargen() {
    if (facturaProveedorSeleccionada && facturaPetrotalSeleccionada) {
        const litrosProv = facturaProveedorSeleccionada.litros || 0;
        const litrosPetro = facturaPetrotalSeleccionada.litros || 0;
        
        if (litrosProv > 0 && litrosPetro > 0) {
            const precioProveedor = facturaProveedorSeleccionada.total / litrosProv;
            const precioPetrotal = facturaPetrotalSeleccionada.total / litrosPetro;
            
            const diferencia = precioPetrotal - precioProveedor;
            const margen = (diferencia / precioProveedor) * 100;
            
            $('#diferencia_precio').text(diferencia.toFixed(4));
            $('#margen_porcentual').text(margen.toFixed(2));
            
            $('#analisis_margen').show();
        }
    }
}

// ==========================================
// GUARDAR ASIGNACIÓN
// ==========================================
function guardarAsignacionCompleta() {
    if (!validarAsignacionCompleta()) {
        alertify.error('Debe seleccionar todas las facturas requeridas');
        return;
    }
    
    const tipoOperacion = $('input[name="tipoOperacion"]:checked').val();
    const observaciones = $('#observaciones_asignacion').val();
    
    // Preparar datos para enviar
    const datosAsignacion = {
        nrotrn: movimientoActual.nrotrn,
        codgas: movimientoActual.numero_estacion,
        tipo_operacion: tipoOperacion,
        observaciones: observaciones,
        
        // Factura Proveedor (siempre presente)
        factura_proveedor_id: facturaProveedorSeleccionada.id,
        uuid_proveedor: facturaProveedorSeleccionada.uuid,
        folio_proveedor: facturaProveedorSeleccionada.folio,
        monto_proveedor: facturaProveedorSeleccionada.total,
        litros_proveedor: facturaProveedorSeleccionada.litros || 0,
        precio_proveedor: facturaProveedorSeleccionada.litros > 0 
            ? (facturaProveedorSeleccionada.total / facturaProveedorSeleccionada.litros) 
            : 0
    };
    
    // Agregar factura Petrotal si aplica
    if (tipoOperacion === '2' && facturaPetrotalSeleccionada) {
        datosAsignacion.factura_petrotal_id = facturaPetrotalSeleccionada.id;
        datosAsignacion.uuid_petrotal = facturaPetrotalSeleccionada.uuid;
        datosAsignacion.folio_petrotal = facturaPetrotalSeleccionada.folio;
        datosAsignacion.monto_petrotal = facturaPetrotalSeleccionada.total;
        datosAsignacion.litros_petrotal = facturaPetrotalSeleccionada.litros || 0;
        datosAsignacion.precio_petrotal = facturaPetrotalSeleccionada.litros > 0 
            ? (facturaPetrotalSeleccionada.total / facturaPetrotalSeleccionada.litros) 
            : 0;
    }
    
    // Deshabilitar botón mientras guarda
    $('#btnGuardarAsignacion').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Guardando...');
    
    $.ajax({
        url: '/supply/guardar_asignacion_completa',
        type: 'POST',
        data: datosAsignacion,
        success: function(response) {
            if (response.success) {
                alertify.success(response.message);
                $('#modalAsignarFactura').modal('hide');
                
                // Recargar tabla
                $('#resumen_payment_table').DataTable().ajax.reload(null, false);
            } else {
                alertify.error(response.message);
                $('#btnGuardarAsignacion').prop('disabled', false).html('<i class="fas fa-save"></i> Guardar Asignación');
            }
        },
        error: function() {
            alertify.error('Error al guardar la asignación');
            $('#btnGuardarAsignacion').prop('disabled', false).html('<i class="fas fa-save"></i> Guardar Asignación');
        }
    });
}

// ==========================================
// ELIMINAR ASIGNACIÓN
// ==========================================
function eliminarAsignacion(movimiento) {
    alertify.confirm(
        'Eliminar Asignación',
        '¿Está seguro de eliminar la asignación de facturas de este movimiento? Esta acción no se puede deshacer.',
        function() {
            $.ajax({
                url: '/supply/eliminar_asignacion_factura',
                type: 'POST',
                data: {
                    nrotrn: movimiento.nrotrn,
                    codgas: movimiento.numero_estacion
                },
                success: function(response) {
                    if (response.success) {
                        alertify.success(response.message);
                        $('#resumen_payment_table').DataTable().ajax.reload(null, false);
                    } else {
                        alertify.error(response.message);
                    }
                },
                error: function() {
                    alertify.error('Error al eliminar asignación');
                }
            });
        },
        function() {
            alertify.message('Operación cancelada');
        }
    );
}

async function compras_facturas_table() {
    if ($.fn.DataTable.isDataTable('#payment_control_table')) {
        $('#payment_control_table').DataTable().destroy();
        $('#payment_control_table thead .filter').remove();
    }
    // OBTENER TODOS LOS FILTROS
    var fromDate = document.getElementById('from_compras').value;
    var untilDate = document.getElementById('until_compras').value;
    var codgas = document.getElementById('codgas_compras').value;
    var proveedor = document.getElementById('proveedor_compras').value;
    var company = document.getElementById('company_compras').value;

    if (!fromDate || !untilDate) {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Debe seleccionar las fechas para continuar.</p>
            </div>`
        );
        return;
    }

    // Agregar fila de filtros en el thead
    $('#payment_control_table thead').prepend($('#payment_control_table thead tr').clone().addClass('filter'));
    $('#payment_control_table thead tr.filter th').each(function (index) {
        var col = $('#payment_control_table thead th').length / 2;
        if (index < col) {
            var title = $(this).text();
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="' + title + '" />');
        }
    });
    
    $('#payment_control_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index();
        var table = $('#payment_control_table').DataTable();
        table.column(index).search(this.value).draw();
    });

    let payment_control_table = $('#payment_control_table').DataTable({
        order: [[0, "desc"]],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        scrollX: true,
        paging: true,
        pageLength: 50,
        buttons: [
            {
                className: 'btn btn-warning',
                text: '<i class="fas fa-exchange-alt"></i> Reconciliar',
                action: function () {
                    abrirVistaReconciliacion()
                }
            },
            {
                extend: 'excel',
                className: 'btn btn-success',
                text: '<i class="fas fa-file-excel"></i> Excel',
                title: 'Compras_Facturas_' + fromDate + '_' + untilDate,
                exportOptions: {
                    columns: ':visible:not(:last-child)'
                }
            },
            {
                extend: 'print',
                className: 'btn btn-secondary',
                text: '<i class="fas fa-print"></i> Imprimir',
                exportOptions: {
                    columns: ':visible:not(:last-child)'
                }
            },
            {
                extend: 'colvis',
                className: 'btn btn-info',
                text: '<i class="fas fa-columns"></i> Columnas'
            },
            {
                className: 'btn btn-secondary',
                text: '<i class="fas fa-list-ol"></i> Registros',
                action: function () {
                    verResumenCompras()
                }
            }
        ],
        ajax: {
            method: 'POST',
            data: {
                'fromDate': fromDate,
                'untilDate': untilDate,
                'codgas': codgas,
                'proveedor': proveedor,
                'company': company
            },
            url: '/supply/compras_facturas_table',
            timeout: 600000,
            error: function(xhr, error, thrown) {
                $('.table-responsive').removeClass('loading');
                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No se pudieron cargar las facturas.</p>
                        <small>${thrown}</small>
                    </div>`
                );
            },
            beforeSend: function() {
                $('.table-responsive').addClass('loading');
            },
            dataSrc: function(json) {
                if (json.error) {
                    alertify.error(json.message);
                    return [];
                }
                // Actualizar contadores
                $('#contador_facturas').text(json.data.length + ' facturas');
                // Calcular total
                var totalMonto = json.data.reduce((sum, item) => sum + parseFloat(item.MontoFactura || 0), 0);
                $('#total_monto_facturas').text('$' + totalMonto.toLocaleString('es-MX', {minimumFractionDigits: 2}));
                return json.data;
            }
        },
        columns: [
            { data: 'FechaRecepcion', className: 'text-center text-nowrap' },
            { 
                data: 'NumeroEstacion',
                className: 'text-center',
                render: function(data) {
                    if (data === '00' || data === 'PENDIENTE') {
                        return '<span class="badge bg-warning text-dark">PENDIENTE</span>';
                    }
                    return '<span class="bg-controlgas">' + data + '</span>';
                }
            },
            { 
                data: 'NombreEstacion',
                className: 'text-start text-nowrap',
                render: function(data) {
                    return data ? '<span class="bg-controlgas">' + data + '</span>' : '<span class="text-muted">N/A</span>';
                }
            },
            { data: 'ProveedorNormalizado', className: 'text-start text-nowrap' },
            { data: 'ProductoNormalizado', className: 'text-start' },
            { 
                data: 'NumeroFacturaProveedorOriginal',
                className: 'text-start text-nowrap',
                // render: function(data, type, row) {
                //     // Hacer el folio clickeable para abrir el PDF en modal
                //     if (row.RutaArchivo) {
                //         return `<a href="javascript:void(0);" 
                //                 onclick='abrirFacturaPDF(${row.FacturaId}, ${JSON.stringify(row).replace(/'/g, "&apos;")})' 
                //                 class="text-primary fw-bold" 
                //                 title="Click para ver la factura PDF">
                //                     <i class="fas fa-file-pdf text-danger"></i> ${data}
                //                 </a>`;
                //     }
                //     return data;
                // }
                 render: function(data, type, row) {
                    // Hacer el folio clickeable para abrir el PDF en modal
                    if (row.RutaArchivo) {
                        return `<a href="javascript:void(0);" 
                                onclick='ModalinvoicePdf(${row.FacturaId}, ${JSON.stringify(row).replace(/'/g, "&apos;")})' 
                                class="text-primary fw-bold" 
                                title="Click para ver la factura PDF">
                                    <i class="fas fa-file-pdf text-danger"></i> ${data}
                                </a>`;
                    }
                    return data;
                }
            },
            { 
                data: 'LitrosDocumentoSoporte',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 2)
            },
            { 
                data: 'MontoFactura',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 2, '$')
            },
            { 
                data: 'SaldoFactura',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 2, '$')
            },
            { 
                data: 'PrecioPorLitro',
                className: 'text-end',
                render: function(data) {
                    return '$' + parseFloat(data).toFixed(4);
                }
            },
            { 
                data: 'PrecioCotizado',
                className: 'text-center',
                render: function(data) {
                    return data === 'PENDIENTE' 
                        ? '<span class="badge bg-secondary">PENDIENTE</span>'
                        : '$' + parseFloat(data).toFixed(4);
                }
            },
            { 
                data: 'Diferencia',
                className: 'text-end',
                render: function(data) {
                    var val = parseFloat(data);
                    var color = val > 0 ? 'text-success' : val < 0 ? 'text-danger' : '';
                    return '<span class="' + color + '">' + val.toFixed(4) + '</span>';
                }
            },
            { 
                data: 'PrecioFacturaCotizadoPetrotal',
                className: 'text-end',
                render: function(data) {
                    return data > 0 ? '$' + parseFloat(data).toFixed(4) : '-';
                }
            },
            { 
                data: 'NumeroFacturaPetrotal',
                className: 'text-start',
                render: function(data) {
                    return data || '<span class="text-muted">N/A</span>';
                }
            },
            { 
                data: 'EstadoAsignacion',
                className: 'text-center',
                render: function(data, type, row) {
                    if (data === 'ASIGNADA') {
                        if (row.TipoOperacion === 2) {
                            return '<span class="badge bg-info"><i class="fas fa-layer-group"></i> PETROTAL</span>';
                        }
                        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> ASIGNADA</span>';
                    } else {
                        return '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-circle"></i> PENDIENTE</span>';
                    }
                }
            },
            { 
                data: 'UUID',
                className: 'text-start',
                render: function(data) {
                    if (!data) return '<span class="text-muted">N/A</span>';
                    return '<small class="font-monospace" style="cursor:pointer;" title="' + data + '" onclick="copiarUUID(\'' + data + '\')">' + 
                           data.substring(0, 8) + '...' + 
                           '</small>';
                }
            },
           {
    data: null,
    orderable: false,
    className: 'text-center',
    render: function(data, type, row) {
        const btnRelacionar = row.EstadoAsignacion === 'PENDIENTE'
            ? `<button class="btn btn-sm btn-primary" 
                      onclick='abrirRelacionarFactura(${row.FacturaId})' 
                      title="Relacionar con recepción">
                   <i class="fas fa-link"></i>
               </button>`
            : `<button class="btn btn-sm btn-secondary" 
                      onclick='verRelacionFactura(${row.FacturaId})' 
                      title="Ver relación">
                   <i class="fas fa-eye"></i>
               </button>`;
        
        return `<div class="btn-group btn-group-sm">${btnRelacionar}</div>`;
    }
}
        ],
        createdRow: function (row, data, dataIndex) {
            // // Resaltar filas según estado
            // if (data.NombreEstacion != '' && data.EstadoAsignacion === 'PENDIENTE') {
            //     $(row).addClass('table-warning');
            // } else if (data.TipoOperacion === 2) {
            //     $(row).addClass('table-info');
            // }
            if (data.EstadoAsignacion === 'PENDIENTE' && data.NumeroEstacion !== '00') {
                $(row).addClass('table-warning');
            }
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            alertify.success('Tabla cargada exitosamente');
        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();
            
            // Calcular totales
            var totalLitros = api.column(6, {page: 'current'}).data().reduce(function (a, b) {
                return parseFloat(a) + parseFloat(b || 0);
            }, 0);
            
            var totalFacturas = api.column(7, {page: 'current'}).data().reduce(function (a, b) {
                return parseFloat(a) + parseFloat(b || 0);
            }, 0);
            
            // Actualizar footer
            $('#footer_litros').html('<strong>' + totalLitros.toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</strong>');
            $('#footer_monto').html('<strong>$' + totalFacturas.toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</strong>');
        }
    });
}
function abrirRelacionarFactura(facturaId) {
    window.open('/supply/relacionar_factura/' + facturaId, '_blank');
}
// FUNCIONES AUXILIARES

function limpiarFiltrosCompras() {
    document.getElementById('from_compras').value = new Date().toISOString().split('T')[0];
    document.getElementById('until_compras').value = new Date().toISOString().split('T')[0];
    $('#codgas_compras').selectpicker('val', '0');
    $('#proveedor_compras').selectpicker('val', '0');
    $('#company_compras').selectpicker('val', '0');
    
    if ($.fn.DataTable.isDataTable('#payment_control_table')) {
        $('#payment_control_table').DataTable().clear().draw();
    }
    
    alertify.message('Filtros limpiados');
}

function exportarComprasExcel() {
    if ($.fn.DataTable.isDataTable('#payment_control_table')) {
        $('#payment_control_table').DataTable().button('.buttons-excel').trigger();
    }
}

function verResumenCompras() {
    if (!$.fn.DataTable.isDataTable('#payment_control_table')) {
        alertify.warning('Debe generar el reporte primero');
        return;
    }
    
    var api = $('#payment_control_table').DataTable();
    var data = api.rows().data();
    
    var totalFacturas = data.length;
    var totalLitros = 0;
    var totalMonto = 0;
    var porProveedor = {};
    var porProducto = {};
    
    data.each(function(row) {
        totalLitros += parseFloat(row.LitrosDocumentoSoporte || 0);
        totalMonto += parseFloat(row.MontoFactura || 0);
        
        // Por proveedor
        var prov = row.ProveedorNormalizado;
        if (!porProveedor[prov]) {
            porProveedor[prov] = { cantidad: 0, litros: 0, monto: 0 };
        }
        porProveedor[prov].cantidad++;
        porProveedor[prov].litros += parseFloat(row.LitrosDocumentoSoporte || 0);
        porProveedor[prov].monto += parseFloat(row.MontoFactura || 0);
        
        // Por producto
        var prod = row.ProductoNormalizado;
        if (!porProducto[prod]) {
            porProducto[prod] = { cantidad: 0, litros: 0, monto: 0 };
        }
        porProducto[prod].cantidad++;
        porProducto[prod].litros += parseFloat(row.LitrosDocumentoSoporte || 0);
        porProducto[prod].monto += parseFloat(row.MontoFactura || 0);
    });
    
    var htmlProveedor = '<table class="table table-sm table-bordered"><thead><tr><th>Proveedor</th><th>Facturas</th><th>Litros</th><th>Monto</th></tr></thead><tbody>';
    for (var prov in porProveedor) {
        htmlProveedor += '<tr>' +
            '<td>' + prov + '</td>' +
            '<td class="text-center">' + porProveedor[prov].cantidad + '</td>' +
            '<td class="text-end">' + porProveedor[prov].litros.toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>' +
            '<td class="text-end">$' + porProveedor[prov].monto.toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>' +
            '</tr>';
    }
    htmlProveedor += '</tbody></table>';
    
    var htmlProducto = '<table class="table table-sm table-bordered"><thead><tr><th>Producto</th><th>Facturas</th><th>Litros</th><th>Monto</th></tr></thead><tbody>';
    for (var prod in porProducto) {
        htmlProducto += '<tr>' +
            '<td>' + prod + '</td>' +
            '<td class="text-center">' + porProducto[prod].cantidad + '</td>' +
            '<td class="text-end">' + porProducto[prod].litros.toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>' +
            '<td class="text-end">$' + porProducto[prod].monto.toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</td>' +
            '</tr>';
    }
    htmlProducto += '</tbody></table>';
    
    alertify.alert(
        'Resumen de Compras',
        `<div class="container-fluid">
            <div class="row">
                <div class="col-12 mb-3">
                    <h5>Totales Generales</h5>
                    <ul class="list-group">
                        <li class="list-group-item d-flex justify-content-between">
                            <strong>Total Facturas:</strong>
                            <span class="badge bg-primary">${totalFacturas}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <strong>Total Litros:</strong>
                            <span>${totalLitros.toLocaleString('es-MX', {minimumFractionDigits: 2})}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <strong>Monto Total:</strong>
                            <span class="text-success fw-bold">$${totalMonto.toLocaleString('es-MX', {minimumFractionDigits: 2})}</span>
                        </li>
                    </ul>
                </div>
                <div class="col-12 mb-3">
                    <h5>Por Proveedor</h5>
                    ${htmlProveedor}
                </div>
                <div class="col-12">
                    <h5>Por Producto</h5>
                    ${htmlProducto}
                </div>
            </div>
        </div>`
    ).set('maximizable', true);
}

function copiarUUID(uuid) {
    navigator.clipboard.writeText(uuid).then(function() {
        alertify.success('UUID copiado al portapapeles');
    }, function() {
        alertify.error('No se pudo copiar el UUID');
    });
}

// Funciones auxiliares
function verDetalleFactura(factura) {
    alertify.alert(
        'Detalle de Factura',
        `<div class="row">
            <div class="col-6"><strong>Folio:</strong> ${factura.NumeroFacturaProveedorOriginal}</div>
            <div class="col-6"><strong>Fecha:</strong> ${factura.FechaRecepcion}</div>
            <div class="col-6"><strong>Proveedor:</strong> ${factura.ProveedorOriginalizado}</div>
            <div class="col-6"><strong>RFC:</strong> ${factura.RfcProveedorOriginal}</div>
            <div class="col-6"><strong>Producto:</strong> ${factura.ProductoNormalizado}</div>
            <div class="col-6"><strong>Litros:</strong> ${factura.LitrosDocumentoSoporte}</div>
            <div class="col-6"><strong>Total:</strong> $${parseFloat(factura.MontoFactura).toLocaleString('es-MX', {minimumFractionDigits: 2})}</div>
            <div class="col-6"><strong>Precio/L:</strong> $${parseFloat(factura.PrecioPorLitro).toFixed(4)}</div>
            <div class="col-12 mt-2"><strong>UUID:</strong><br><small class="font-monospace">${factura.UUID}</small></div>
        </div>`
    );
}

function asignarFacturaAMovimiento(factura) {
    // Esta función abrirá un modal para buscar el movimiento correspondiente
    alertify.prompt(
        'Asignar a Movimiento',
        'Ingrese el número de transacción (nrotrn) al que desea asignar esta factura:',
        '',
        function(evt, nrotrn) {
            if (nrotrn) {
                // Aquí implementarás la lógica de asignación
                alertify.success('Función en desarrollo: asignar factura ' + factura.NumeroFacturaProveedorOriginal + ' a transacción ' + nrotrn);
            }
        },
        function() {
            alertify.message('Operación cancelada');
        }
    );
}

function editarAsignacionFactura(factura) {
    alertify.message('Función en desarrollo: editar asignación de factura ' + factura.NumeroFacturaProveedorOriginal);
}
let facturaActualPDF = null;
let pdfBlobUrl = null; // URL temporal del blob

/**
 * Abre el modal con el visor de PDF usando POST
 * @param {number} facturaId - ID de la factura
 * @param {object} facturaData - Datos adicionales de la factura (opcional)
 */
function abrirFacturaPDF(facturaId, facturaData = null) {
    // Guardar información de la factura
    facturaActualPDF = {
        id: facturaId,
        data: facturaData
    };
    console.log('Abriendo PDF para factura ID:', facturaId, facturaData);
    // Limpiar estado anterior
    $('#iframe_pdf').hide();
    $('#pdf-error').hide();
    $('#pdf-loading').show();
    
    // Limpiar blob URL anterior si existe
    if (pdfBlobUrl) {
        URL.revokeObjectURL(pdfBlobUrl);
        pdfBlobUrl = null;
    }
    
    // Actualizar título si hay datos
    if (facturaData) {
        $('#info_factura_pdf').text(
            `Folio: ${facturaData.NumeroFacturaProveedorOriginal || 'N/A'} | ` +
            `Proveedor: ${facturaData.ProveedorNormalizado || 'N/A'} | ` +
            `Monto: $${parseFloat(facturaData.MontoFactura || 0).toLocaleString('es-MX', {minimumFractionDigits: 2})}`
        );
    } else {
        $('#info_factura_pdf').text(`ID Factura: ${facturaId}`);
    }
    
    // Abrir modal
    $('#modalVisorPDF').modal('show');
    
    // Cargar PDF mediante POST
    cargarPDFPorPost(facturaId);
}

/**
 * Carga el PDF mediante petición POST y lo muestra en el iframe
 */
function cargarPDFPorPost(facturaId) {
    $.ajax({
        url: '/supply/ver_factura_pdf',
        method: 'POST',
        data: { id: facturaId },
        dataType: 'json',
        timeout: 30000, // 30 segundos
        success: function(response) {
            if (response.success && response.pdf) {
                try {
                    // Convertir base64 a blob
                    const binaryString = atob(response.pdf);
                    const bytes = new Uint8Array(binaryString.length);
                    
                    for (let i = 0; i < binaryString.length; i++) {
                        bytes[i] = binaryString.charCodeAt(i);
                    }
                    
                    const blob = new Blob([bytes], { type: 'application/pdf' });
                    
                    // Crear URL temporal del blob
                    pdfBlobUrl = URL.createObjectURL(blob);
                    
                    // Asignar al iframe
                    const iframe = document.getElementById('iframe_pdf');
                    iframe.src = pdfBlobUrl;
                    
                    // Mostrar iframe
                    $('#pdf-loading').hide();
                    $('#iframe_pdf').fadeIn();
                    
                    // Mostrar información del archivo
                    const sizeKB = (response.size / 1024).toFixed(2);
                    console.log(`PDF cargado: ${response.nombre} (${sizeKB} KB)`);
                    
                } catch (error) {
                    console.error('Error al procesar el PDF:', error);
                    mostrarErrorPDF('Error al procesar el archivo PDF. El archivo podría estar corrupto.');
                }
            } else {
                mostrarErrorPDF(response.error || 'No se pudo obtener el PDF del servidor');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error AJAX:', status, error);
            
            let mensaje = 'Error al cargar el PDF del servidor.';
            
            if (xhr.status === 404) {
                mensaje = 'Archivo PDF no encontrado.';
            } else if (xhr.status === 500) {
                mensaje = 'Error en el servidor al procesar el PDF.';
            } else if (status === 'timeout') {
                mensaje = 'El servidor tardó demasiado en responder.';
            }
            
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.error) {
                    mensaje = response.error;
                }
            } catch (e) {
                // No se pudo parsear la respuesta
            }
            
            mostrarErrorPDF(mensaje);
        }
    });
}

function mostrarErrorPDF(mensaje) {
    $('#pdf-loading').hide();
    $('#pdf-error-message').text(mensaje);
    $('#pdf-error').show();
}
/**
 * Descarga el PDF directamente
 */
function descargarPDFDirecto() {
    if (!facturaActualPDF) {
        alertify.error('No hay factura seleccionada');
        return;
    }
    
    // Crear un formulario temporal para hacer POST y descargar
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/supply/descargar_factura_pdf';
    form.target = '_blank'; // Abrir en nueva pestaña
    
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'id';
    input.value = facturaActualPDF.id;
    
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
    
    alertify.success('Descargando PDF...');
}

/**
 * Abre el PDF en una nueva ventana/pestaña
 */
function abrirPDFNuevaVentana() {
    if (!pdfBlobUrl) {
        alertify.error('No hay PDF cargado');
        return;
    }
    
    // Abrir el blob URL en nueva ventana
    window.open(pdfBlobUrl, '_blank');
}
/**
 * Imprime el PDF
 */
function imprimirPDF() {
    const iframe = document.getElementById('iframe_pdf');
    
    if (iframe && iframe.contentWindow) {
        try {
            iframe.contentWindow.print();
        } catch (e) {
            alertify.warning('No se pudo imprimir directamente. Intente desde el botón de nueva pestaña.');
            abrirPDFNuevaVentana();
        }
    } else {
        alertify.error('No hay PDF cargado para imprimir');
    }
}

/**
 * Cierra el modal y limpia el iframe
 */
$('#modalVisorPDF').on('hidden.bs.modal', function () {
    // Limpiar iframe
    document.getElementById('iframe_pdf').src = 'about:blank';
    
    // Revocar blob URL para liberar memoria
    if (pdfBlobUrl) {
        URL.revokeObjectURL(pdfBlobUrl);
        pdfBlobUrl = null;
    }
    
    facturaActualPDF = null;
});
$('#modalVisorPDF').on('shown.bs.modal', function () {
    $(document).on('keydown.pdfmodal', function(e) {
        // ESC para cerrar
        if (e.key === 'Escape') {
            $('#modalVisorPDF').modal('hide');
        }
        // Ctrl+P para imprimir
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            imprimirPDF();
        }
        // Ctrl+S para descargar
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            descargarPDFDirecto();
        }
    });
});

$('#modalVisorPDF').on('hidden.bs.modal', function () {
    // Remover event listener de teclas
    $(document).off('keydown.pdfmodal');
});

function abrirModalAsignarFactura(factura) {
    // Si ya está asignada, mostrar la info del movimiento
    if (factura.EstadoAsignacion === 'ASIGNADA' && factura.NumeroTransaccion) {
        // Cargar datos del movimiento desde la asignación existente
        $('#modal_nrotrn').text(factura.NumeroTransaccion);
        $('#modal_estacion').text(factura.NombreEstacion || 'N/A');
        $('#modal_fecha').text(factura.FechaRecepcion);
        $('#modal_combustible').text(factura.ProductoNormalizado);
        $('#modal_litros').text(parseFloat(factura.LitrosDocumentoSoporte).toLocaleString('es-MX', {minimumFractionDigits: 2}));
        
        // Determinar tipo de operación
        if (factura.TipoOperacion === 2) {
            $('#tipoPetrotal').prop('checked', true);
            $('#tipoDirecto').prop('checked', false);
            mostrarSeccionPetrotal();
        } else {
            $('#tipoDirecto').prop('checked', true);
            $('#tipoPetrotal').prop('checked', false);
            ocultarSeccionPetrotal();
        }
        
        // Pre-cargar las facturas seleccionadas
        // ... (código para mostrar facturas ya asignadas)
        
    } else {
        // Nueva asignación - mostrar modal para buscar movimiento
        alertify.prompt(
            'Asignar a Movimiento',
            'Ingrese el número de transacción (nrotrn) y código de estación (codgas) separados por coma:',
            '',
            function(evt, value) {
                if (value) {
                    const [nrotrn, codgas] = value.split(',').map(v => v.trim());
                    if (nrotrn && codgas) {
                        // Buscar datos del movimiento
                        buscarMovimientoParaAsignar(nrotrn, codgas, factura);
                    } else {
                        alertify.error('Formato incorrecto. Use: nrotrn,codgas');
                    }
                }
            },
            function() {
                alertify.message('Operación cancelada');
            }
        ).set('labels', {ok: 'Buscar', cancel: 'Cancelar'});
    }
}

// FUNCIÓN AUXILIAR PARA BUSCAR MOVIMIENTO
function buscarMovimientoParaAsignar(nrotrn, codgas, factura) {
    $.ajax({
        url: '/supply/buscar_movimiento_por_nrotrn',
        method: 'POST',
        data: { nrotrn: nrotrn, codgas: codgas },
        success: function(response) {
            if (response.success && response.data) {
                const movimiento = response.data;
                
                // Llenar modal con datos del movimiento
                $('#modal_nrotrn').text(movimiento.nrotrn);
                $('#modal_estacion').text(movimiento.nombre_estacion);
                $('#modal_fecha').text(movimiento.fecha);
                $('#modal_combustible').text(movimiento.producto);
                $('#modal_litros').text(parseFloat(movimiento.litros).toLocaleString('es-MX', {minimumFractionDigits: 2}));
                
                // Guardar movimiento actual y factura pre-seleccionada
                movimientoActual = movimiento;
                facturaProveedorSeleccionada = factura;
                
                // Actualizar diagrama
                actualizarDiagramaProveedor(factura);
                
                // Abrir modal
                $('#modalAsignarFactura').modal('show');
            } else {
                alertify.error('Movimiento no encontrado');
            }
        },
        error: function() {
            alertify.error('Error al buscar movimiento');
        }
    });
}

// Función para verificar si el navegador soporta PDFs en iframe
function navegadorSoportaPDF() {
    const userAgent = navigator.userAgent.toLowerCase();
    
    // Navegadores que típicamente soportan PDF en iframe
    if (userAgent.includes('chrome') || 
        userAgent.includes('firefox') || 
        userAgent.includes('safari') && !userAgent.includes('android')) {
        return true;
    }
    
    return false;
}

// Verificar soporte al abrir el modal
// function abrirFacturaPDF(facturaId, facturaData = null) {
//     facturaActualPDF = {
//         id: facturaId,
//         data: facturaData
//     };
    
//     // Si el navegador no soporta bien los PDFs, ofrecer alternativa
//     if (!navegadorSoportaPDF()) {
//         alertify.confirm(
//             'Visualización de PDF',
//             'Su navegador podría no mostrar PDFs correctamente en esta vista. ¿Desea abrir en nueva pestaña?',
//             function() {
//                 window.open(`/supply/ver_factura_pdf?id=${facturaId}`, '_blank');
//             },
//             function() {
//                 // Continuar con el modal de todas formas
//                 cargarPDFEnModal(facturaId, facturaData);
//             }
//         ).set('labels', {ok: 'Nueva pestaña', cancel: 'Ver aquí'});
//     } else {
//         cargarPDFEnModal(facturaId, facturaData);
//     }
// }

function cargarPDFEnModal(facturaId, facturaData) {
    $('#iframe_pdf').hide();
    $('#pdf-error').hide();
    $('#pdf-loading').show();
    
    if (facturaData) {
        $('#info_factura_pdf').text(
            `Folio: ${facturaData.NumeroFacturaProveedorOriginal || 'N/A'} | ` +
            `Proveedor: ${facturaData.ProveedorNormalizado || 'N/A'} | ` +
            `Monto: $${parseFloat(facturaData.MontoFactura || 0).toLocaleString('es-MX', {minimumFractionDigits: 2})}`
        );
    }
    
    $('#modalVisorPDF').modal('show');
    
    const pdfUrl = `/supply/ver_factura_pdf?id=${facturaId}&t=${Date.now()}`;
    const iframe = document.getElementById('iframe_pdf');
    
    iframe.onload = function() {
        $('#pdf-loading').hide();
        $('#iframe_pdf').fadeIn();
    };
    
    iframe.onerror = function() {
        $('#pdf-loading').hide();
        $('#pdf-error-message').text('No se pudo cargar el archivo PDF.');
        $('#pdf-error').show();
    };
    
    iframe.src = pdfUrl;
    
    setTimeout(function() {
        if ($('#pdf-loading').is(':visible')) {
            $('#pdf-loading').hide();
            $('#pdf-error-message').text('El PDF está tardando mucho en cargar.');
            $('#pdf-error').show();
        }
    }, 10000);
}

// ==================== FUNCIONES PARA VISTA DE RECONCILIACIÓN ====================

function regresarACompras() {
    // Redirigir a la vista de compras/facturas recibidas
    window.location.href = '/supply/fuel_payments';
}

function abrirVistaReconciliacion() {
    // Guardar los filtros actuales en localStorage para pasarlos a la nueva vista
    var filtros = {
        fromDate: document.getElementById('from_compras').value,
        untilDate: document.getElementById('until_compras').value,
        codgas: document.getElementById('codgas_compras').value,
        proveedor: document.getElementById('proveedor_compras').value,
        company: document.getElementById('company_compras').value
    };

    localStorage.setItem('reconciliation_filters', JSON.stringify(filtros));

    // Redirigir a la nueva vista
    window.location.href = '/supply/fuel_reconciliation';
}

async function loadReconciliationData() {
    var fromDate = document.getElementById('from_reconciliation').value;
    var untilDate = document.getElementById('until_reconciliation').value;
    var codgas = document.getElementById('codgas_reconciliation').value;
    var proveedor = document.getElementById('proveedor_reconciliation').value;
    var company = document.getElementById('company_reconciliation').value;

    if (!fromDate || !untilDate) {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Debe seleccionar las fechas para continuar.</p>
            </div>`
        );
        return;
    }

    // Cargar ambas tablas en paralelo
    await Promise.all([
        loadFacturasReconciliationTable(fromDate, untilDate, codgas, proveedor, company),
        loadRecepcionesReconciliationTable(fromDate, untilDate, codgas, proveedor, company)
    ]);
}

async function loadFacturasReconciliationTable(fromDate, untilDate, codgas, proveedor, company) {
    if ($.fn.DataTable.isDataTable('#facturas_reconciliation_table')) {
        $('#facturas_reconciliation_table').DataTable().destroy();
    }

    let facturas_reconciliation_table = $('#facturas_reconciliation_table').DataTable({
        order: [[0, "desc"]],
        dom: '<"top"f>rt<"bottom"ip>',
        scrollY: 'calc(100vh - 350px)',
        scrollCollapse: true,
        paging: false,
        pageLength: 100,
        ajax: {
            method: 'POST',
            data: {
                'fromDate': fromDate,
                'untilDate': untilDate,
                'codgas': codgas,
                'proveedor': proveedor,
                'company': company
            },
            url: '/supply/compras_facturas_table',
            timeout: 600000,
            error: function(xhr, error, thrown) {
                $('.table-responsive').removeClass('loading');
                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No se pudieron cargar las facturas.</p>
                        <small>${thrown}</small>
                    </div>`
                );
            },
            beforeSend: function() {
                $('.table-responsive').addClass('loading');
            },
            dataSrc: function(json) {
                if (json.error) {
                    alertify.error(json.message);
                    return [];
                }
                // Actualizar contadores
                $('#contador_facturas_reconciliation').text(json.data.length + ' facturas');
                // Calcular total
                var totalMonto = json.data.reduce((sum, item) => sum + parseFloat(item.MontoFactura || 0), 0);
                $('#total_monto_facturas_reconciliation').text('$' + totalMonto.toLocaleString('es-MX', {minimumFractionDigits: 2}));
                $('#footer_monto_facturas').text('$' + totalMonto.toLocaleString('es-MX', {minimumFractionDigits: 2}));
                return json.data;
            }
        },
        columns: [
            {
                data: 'FechaRecepcion',
                className: 'text-center text-nowrap'
            },
            {
                data: 'ProveedorNormalizado',
                className: 'text-start text-nowrap',
                

            },

            {
                data: 'NumeroFacturaProveedorOriginal',
                className: 'text-start text-nowrap',
                render: function(data, type, row) {
                    // Hacer el folio clickeable para abrir el PDF en modal
                    if (row.RutaArchivo) {
                        return `<a href="javascript:void(0);" 
                                onclick='ModalinvoicePdf(${row.FacturaId}, ${JSON.stringify(row).replace(/'/g, "&apos;")})' 
                                class="text-primary fw-bold" 
                                title="Click para ver la factura PDF">
                                    <i class="fas fa-file-pdf text-danger"></i> ${data}
                                </a>`;
                    }
                    return data;
                }
            },
            {
                data: 'MontoFactura',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 2, '$')
            }
        ],
        deferRender: true,
        initComplete: function() {
            $('.table-responsive').removeClass('loading');
        },
        createdRow: function(row, data, dataIndex) {
            // Agregar atributos de datos para facilitar la selección
            $(row).attr('data-factura-id', data.FacturaId);
            $(row).attr('data-uuid', data.UUID);
        }
    });

    // Agregar evento de clic para seleccionar factura
    $('#facturas_reconciliation_table tbody').on('click', 'tr', function() {
        if ($(this).hasClass('selected-row')) {
            $(this).removeClass('selected-row');
            facturaSeleccionada = null;
        } else {
            $('#facturas_reconciliation_table tbody tr').removeClass('selected-row');
            $(this).addClass('selected-row');
            facturaSeleccionada = facturas_reconciliation_table.row(this).data();
        }
        actualizarBotonRelacionar();
    });
}

async function loadRecepcionesReconciliationTable(fromDate, untilDate, codgas, proveedor, company) {
    if ($.fn.DataTable.isDataTable('#recepciones_reconciliation_table')) {
        $('#recepciones_reconciliation_table').DataTable().clear().destroy();
        $('#recepciones_reconciliation_table thead .filter').remove();
        $('#recepciones_reconciliation_table tbody').empty();
    }

    let movimientoActual = {};

    let recepciones_reconciliation_table = $('#recepciones_reconciliation_table').DataTable({
        order: [[1, "asc"]],
        scrollY: 'calc(100vh - 350px)',
        colReorder: false,
        fixedHeader: false,
        dom: '<"top"f>rt<"bottom"ip>',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        autoWidth: false,
        ajax: {
            method: 'POST',
            data: {
                'fromDate': fromDate,
                'untilDate': untilDate,
                'codgas': codgas,
                'proveedor': proveedor,
                'company': company
            },
            url: '/supply/resumen_payment_table',
            timeout: 600000,
            error: function(xhr, error, thrown) {
                $('.datatable-wrapper').removeClass('loading');
                alertify.error('Error al cargar datos: ' + thrown);
            },
            beforeSend: function() {
                $('.datatable-wrapper').addClass('loading');
            },
            dataSrc: function(json) {
                if (json.data && json.data.length > 0) {
                    $('#table-info-reconciliation').html(
                        `<i class="bi bi-info-circle"></i> ${json.data.length} registro(s)`
                    );
                }
                return json.data;
            }
        },
        columns: [
            { data: 'fecha', className: 'text-center text-nowrap' },
            { data: 'estacion', className: 'text-start text-nowrap' },
            { data: 'numero_estacion', className: 'text-center text-nowrap' },
            { data: 'proveedor_original', className: 'text-start text-nowrap' },
            { data: 'combustible', className: 'text-start text-nowrap' },
            {
                data: 'num_fac_proveedor',
                className: 'text-start text-nowrap',
                render: function(data) {
                    return data || '<span class="text-muted">Sin asignar</span>';
                }
            },
            { data: 'proveedor_final', className: 'text-start text-nowrap' },
            {
                data: 'fac_rec',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 2)
            },
            {
                data: 'monto_factura_controlgas',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 2, '$')
            },
            {
                data: 'precio_factura_controlgas',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 4, '$')
            },
            { data: 'uuid', className: 'text-start text-nowrap' },
            { data: 'proveedor_controlgas', className: 'text-start text-nowrap' },
            {
                data: 'monto_factura_controlgas',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 2, '$')
            },
            {
                data: 'cantidad_factura_controlgas',
                className: 'text-end',
                render: $.fn.dataTable.render.number(',', '.', 2)
            },
            { data: 'graprd', className: 'text-start text-nowrap' },
            { data: 'nrotrn', className: 'text-center' }
        ],
        deferRender: true,
        initComplete: function() {
            $('.datatable-wrapper').removeClass('loading');
        },
        createdRow: function(row, data, dataIndex) {
            // Agregar atributos de datos para facilitar la selección
            $(row).attr('data-nrotrn', data.nrotrn);
            $(row).attr('data-codgas', data.codgas);
        }
    });

    // Agregar evento de clic para seleccionar recepción
    $('#recepciones_reconciliation_table tbody').on('click', 'tr', function() {
        if ($(this).hasClass('selected-row')) {
            $(this).removeClass('selected-row');
            recepcionSeleccionada = null;
        } else {
            $('#recepciones_reconciliation_table tbody tr').removeClass('selected-row');
            $(this).addClass('selected-row');
            recepcionSeleccionada = recepciones_reconciliation_table.row(this).data();
        }
        actualizarBotonRelacionar();
    });
}

// Variables globales para almacenar las selecciones
let facturaSeleccionada = null;
let recepcionSeleccionada = null;

// Función para actualizar la visibilidad del botón de relacionar
function actualizarBotonRelacionar() {
    if (facturaSeleccionada && recepcionSeleccionada) {
        $('#btnRelacionar').fadeIn();
    } else {
        $('#btnRelacionar').fadeOut();
    }
}

// Función para abrir el modal de confirmación
function relacionarFacturaRecepcion() {
    if (!facturaSeleccionada || !recepcionSeleccionada) {
        alertify.error('Debe seleccionar una factura y una recepción');
        return;
    }

    // Mostrar información de la factura seleccionada
    $('#infoFacturaSeleccionada').html(`
        <strong>Fecha:</strong> ${facturaSeleccionada.FechaRecepcion}<br>
        <strong>Proveedor:</strong> ${facturaSeleccionada.ProveedorNormalizado}<br>
        <strong>Número de Factura:</strong> ${facturaSeleccionada.NumeroFacturaProveedorOriginal}<br>
        <strong>Monto:</strong> $${parseFloat(facturaSeleccionada.MontoFactura).toLocaleString('es-MX', {minimumFractionDigits: 2})}<br>
        <strong>UUID:</strong> ${facturaSeleccionada.UUID}
    `);

    // Mostrar información de la recepción seleccionada
    $('#infoRecepcionSeleccionada').html(`
        <strong>Fecha:</strong> ${recepcionSeleccionada.fecha}<br>
        <strong>Estación:</strong> ${recepcionSeleccionada.estacion} (${recepcionSeleccionada.numero_estacion})<br>
        <strong>Nro. Transacción:</strong> ${recepcionSeleccionada.nrotrn}<br>
        <strong>Combustible:</strong> ${recepcionSeleccionada.combustible}<br>
        <strong>Litros:</strong> ${parseFloat(recepcionSeleccionada.fac_rec).toLocaleString('es-MX', {minimumFractionDigits: 2})}
    `);

    // Limpiar observaciones y checkbox
    $('#observacionesRelacion').val('');
    $('#checkPetrotal').prop('checked', false);
    actualizarTipoOperacion();

    // Mostrar modal
    $('#modalConfirmRelacion').modal('show');
}

// Función para actualizar el diagrama según el tipo de operación
function actualizarTipoOperacion() {
    var conPetrotal = $('#checkPetrotal').is(':checked');

    if (conPetrotal) {
        $('#flujo-texto').html('Proveedor → <span class="text-warning fw-bold">PETROTAL</span> → TotalGas (Con Intermediario)');
        $('#diagrama-operacion').removeClass('bg-light').addClass('bg-warning bg-opacity-10');
    } else {
        $('#flujo-texto').html('Proveedor → TotalGas (Compra Directa)');
        $('#diagrama-operacion').removeClass('bg-warning bg-opacity-10').addClass('bg-light');
    }
}

// Función para confirmar la relación
function confirmarRelacion() {
    if (!facturaSeleccionada || !recepcionSeleccionada) {
        alertify.error('Debe seleccionar una factura y una recepción');
        return;
    }

    var observaciones = $('#observacionesRelacion').val();
    var conPetrotal = $('#checkPetrotal').is(':checked');

    // Preparar datos para enviar
    var datos = {
        nrotrn: recepcionSeleccionada.nrotrn,
        codgas: recepcionSeleccionada.codgas,
        factura_proveedor_id: facturaSeleccionada.FacturaId,
        uuid_proveedor: facturaSeleccionada.UUID,
        folio_proveedor: facturaSeleccionada.NumeroFacturaProveedorOriginal,
        monto_proveedor: facturaSeleccionada.MontoFactura,
        litros_proveedor: facturaSeleccionada.LitrosDocumentoSoporte,
        precio_proveedor: facturaSeleccionada.PrecioPorLitro,
        observaciones: observaciones,
        tipo_operacion: conPetrotal ? 2 : 1, // 1 = Compra directa, 2 = Con Petrotal
        petrotal: conPetrotal ? 1 : 0 // Campo BIT: 1 = lleva Petrotal, 0 = no lleva
    };

    // Enviar al servidor
    $.ajax({
        url: '/supply/relacionar_factura_movimiento',
        type: 'POST',
        data: datos,
        beforeSend: function() {
            $('.modal-footer button').prop('disabled', true);
        },
        success: function(response) {
            if (response.success) {
                toastr.success('Factura relacionada exitosamente', '¡Éxito!');
                $('#modalConfirmRelacion').modal('hide');

                // Limpiar selecciones
                $('#facturas_reconciliation_table tbody tr').removeClass('selected-row');
                $('#recepciones_reconciliation_table tbody tr').removeClass('selected-row');
                facturaSeleccionada = null;
                recepcionSeleccionada = null;
                actualizarBotonRelacionar();

                // Recargar las tablas
                loadReconciliationData();
            } else {
                toastr.error(response.message || 'Error al relacionar factura', '¡Error!');
            }
        },
        error: function(xhr, status, error) {
            toastr.error('Error al comunicarse con el servidor', '¡Error!');
        },
        complete: function() {
            $('.modal-footer button').prop('disabled', false);
        }
    });
}

// Cargar filtros guardados al cargar la página de reconciliación
$(document).ready(function() {
    if (window.location.pathname.includes('fuel_reconciliation')) {
        var filtrosGuardados = localStorage.getItem('reconciliation_filters');
        if (filtrosGuardados) {
            filtrosGuardados = JSON.parse(filtrosGuardados);
            document.getElementById('from_reconciliation').value = filtrosGuardados.fromDate || '';
            document.getElementById('until_reconciliation').value = filtrosGuardados.untilDate || '';
            $('#codgas_reconciliation').val(filtrosGuardados.codgas || '0').selectpicker('refresh');
            $('#proveedor_reconciliation').val(filtrosGuardados.proveedor || '0').selectpicker('refresh');
            $('#company_reconciliation').val(filtrosGuardados.company || '0').selectpicker('refresh');

            // Cargar datos automáticamente si hay filtros
            if (filtrosGuardados.fromDate && filtrosGuardados.untilDate) {
                loadReconciliationData();
            }
        }
    }
});


async function ModalinvoicePdf(id, data){
    try {
        $('#ModalinvoicePdf').modal('show'); // Abre el modal
        const response = await fetch('/supply/ModalinvoicePdf', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
            body: `FacturaId=${id}&data=${encodeURIComponent(JSON.stringify(data))}`
        });

        const content = await response.text();
        // Inserta el contenido en el modal
        $('#ModalinvoicePdf').find('#ModalinvoicePdfContent').html(content);

    } catch (error) {
        console.error(error);
    }

}