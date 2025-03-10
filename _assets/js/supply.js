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