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
                'codgas':codgas
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
            { data: 'gasolinera', className: 'text-nowrap' },                                // Folio del documento
            { data: 'nro', className: 'text-nowrap' },                                // Folio del documento
            { data: 'Factura', className: 'text-nowrap' },                            // Texto extraído de @F:
            { data: 'Remision', className: 'text-nowrap' },                           // Texto extraído de @R:
            { data: 'fecha', className: 'text-nowrap' },                              // Fecha (fch - 1)
            { data: 'fechaVto', className: 'text-nowrap' },                           // Vencimiento (vto - 1)
            { data: 'producto', className: 'text-nowrap' },                           // Producto (t3.den)
            { data: 'proveedor', className: 'text-nowrap' },                          // Proveedor (t4.den)
            { data: 'volrec', render: $.fn.dataTable.render.number(',', '.', 2) }, // Volumen recibido
            { data: 'can', render: $.fn.dataTable.render.number(',', '.', 2) },    // Cantidad
            // { data: 'pre', render: $.fn.dataTable.render.number(',', '.', 4) },    // Precio unitario
            // { data: 'mto', render: $.fn.dataTable.render.number(',', '.', 2) },    // Monto
            // { data: 'mtoiie', render: $.fn.dataTable.render.number(',', '.', 2) }, // Monto IIE
            // { data: 'iva8', render: $.fn.dataTable.render.number(',', '.', 2) },   // IVA 8%
            // { data: 'iva', render: $.fn.dataTable.render.number(',', '.', 2) },    // IVA Extra
            // { data: 'iva_total', render: $.fn.dataTable.render.number(',', '.', 2) }, // Total IVA
            // { data: 'servicio', render: $.fn.dataTable.render.number(',', '.', 2) },  // Servicio
            // { data: 'iva_servicio', render: $.fn.dataTable.render.number(',', '.', 2) }, // IVA Servicio
            { data: 'total_fac', render: $.fn.dataTable.render.number(',', '.', 2) },    // Total Factura
            // { data: 'satuid', className: 'text-nowrap' }   // UID SAT
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