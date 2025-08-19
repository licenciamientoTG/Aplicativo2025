
let datatables_duplicate_dispatches = $('#datatables_duplicate_dispatches').DataTable({
    order: [7, "desc"],
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
        },
        {
            extend: 'pdf', // Agrega el botón de exportación a PDF
            className: 'd-none',
            text: 'PDF',
            customize: function (doc) {
                // Establecer la orientación horizontal (apaisada)
                doc.pageOrientation = 'landscape';
                // Ajustar todas las columnas al ancho del PDF
                let colWidths = [];
                let tableWidth = doc.pageOrientation === 'landscape' ? 1060 : 500; // Ancho de página para orientación horizontal o vertical
                let totalColWidths = 0;

                console.log(tableWidth);
                $('#datatables_duplicate_dispatches thead th').each(function () {
                    let colWidth = $(this).outerWidth() / tableWidth;
                    totalColWidths += colWidth;
                    colWidths.push(colWidth * 100 + '%');
                });

                if (totalColWidths < 1) {
                    colWidths.push('*'); // Columna extra para completar el ancho restante
                }

                doc.content[1].table.widths = colWidths;
            }
        },
        {
            extend: 'print', // Agrega el botón de impresión
            className: 'd-none',
        }
    ],
    ajax: {
        data: {'from': $('#datatables_duplicate_dispatches').data('from'), 'until': $('#datatables_duplicate_dispatches').data('until'), 'interval': $('#datatables_duplicate_dispatches').data('interval'), 'codgas': $('#datatables_duplicate_dispatches').data('codgas'), 'client': $('#datatables_duplicate_dispatches').data('client')},
        url: '/income/datatables_duplicate_dispatches',
        error: function() {
            $('#datatables_duplicate_dispatches').waitMe('hide');
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
        {'data': 'Fecha'},
        {'data': 'Hora'},
        {'data': 'Despacho'},
        {'data': 'codcliente'},
        {'data': 'Cliente'},
        {'data': 'Tipo'},
        {'data': 'Placas'},
        {'data': 'Tarjeta'},
        {'data': 'Grupo'},
        {'data': 'Descripcion'},
        {'data': 'Cant despacho', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'Monto despacho', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$' )},
        {'data': 'Producto'},
        {'data': 'Estación'},
        {'data': 'Bomba'},
        {'data': 'Check'}
    ],
    rowId: 'Despacho',
    createdRow: function (row, data, dataIndex) {
        if (data['Placas'] === '') {
            $('td', row).eq(6).addClass('bg-danger text-dark text-center').html('S/P');
        }
        if (data['Check'] === '1') {
            $(row).addClass('table-danger text-dark');
        }
        if (data['Check'] === '2') {
            $('td', row).eq(15).html('0');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-container input').on('keyup  change clear', function () {
    datatables_duplicate_dispatches
        .column(0).search($('#FECHA').val().trim())
        .column(1).search($('#HORA').val().trim())
        .column(2).search($('#DESPACHO').val().trim())
        .column(3).search($('#CODCLIENTE').val().trim())
        .column(4).search($('#CLIENTE').val().trim())
        .column(5).search($('#TIPO').val().trim())
        .column(6).search($('#PLACAS').val().trim())
        .column(7).search($('#TARJETA').val().trim())
        .column(8).search($('#GRUPO').val().trim())
        .column(9).search($('#DESCRIPCION').val().trim())
        .column(10).search($('#LITROS').val().trim())
        .column(11).search($('#MONTO').val().trim())
        .column(12).search($('#PRODUCTO').val().trim())
        .column(13).search($('#ESTACIÓN').val().trim())
        .column(14).search($('#BOMBA').val().trim())
        .column(15).search($('#INCIDENCIA').val().trim())
        .draw();
  });

// Agregar un evento clic de refresh
$('.refresh_datatables_duplicate_dispatches').on('click', function () {
    datatables_duplicate_dispatches.clear().draw();
    datatables_duplicate_dispatches.ajax.reload();
    $('#datatables_duplicate_dispatches').waitMe('hide');
});



// Table de Despachos de Crédito y Débito
let datatables_credit_debit = $('#datatables_credit_debit').DataTable({
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
        },
        {
            extend: 'pdf', // Agrega el botón de exportación a PDF
            className: 'd-none',
            text: 'PDF',
            customize: function (doc) {
                // Establecer la orientación horizontal (apaisada)
                doc.pageOrientation = 'landscape';
                // Ajustar todas las columnas al ancho del PDF
                let colWidths = [];
                let tableWidth = doc.pageOrientation === 'landscape' ? 1060 : 500; // Ancho de página para orientación horizontal o vertical
                let totalColWidths = 0;

                console.log(tableWidth);
                $('#datatables_credit_debit thead th').each(function () {
                    let colWidth = $(this).outerWidth() / tableWidth;
                    totalColWidths += colWidth;
                    colWidths.push(colWidth * 100 + '%');
                });

                if (totalColWidths < 1) {
                    colWidths.push('*'); // Columna extra para completar el ancho restante
                }

                doc.content[1].table.widths = colWidths;
            }
        },
        {
            extend: 'print', // Agrega el botón de impresión
            className: 'd-none',
        }
    ],
    ajax: {
        data: {'from': $('#datatables_credit_debit').data('from'), 'until': $('#datatables_credit_debit').data('until'), 'codgas': $('#datatables_credit_debit').data('codgas'), 'client_type': $('#datatables_credit_debit').data('client_type')},
        url: '/income/datatables_credit_debit',
        error: function() {
            $('#datatables_credit_debit').waitMe('hide');
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
        {'data': 'Fecha'},
        {'data': 'Hora'},
        {'data': 'Despacho'},
        {'data': 'codcliente'},
        {'data': 'Cliente'},
        {'data': 'Tipo'},
        {'data': 'Placas'},
        {'data': 'Tarjeta'},
        {'data': 'Grupo'},
        {'data': 'Descripcion'},
        {'data': 'Cant despacho', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'Monto despacho', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$' )},
        {'data': 'Producto'},
        {'data': 'Estación'},
        {'data': 'Bomba'},
        {'data': 'Factura'},
        {'data': 'UUID'},
        {'data': 'RFC'},
    ],
    rowId: 'Despacho',
    createdRow: function (row, data, dataIndex) {
        if (data['Placas'] === '') {
            $('td', row).eq(6).addClass('bg-danger text-dark text-center').html('S/P');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_credit_debit input').on('keyup  change clear', function () {
    datatables_credit_debit
        .column(0).search($('#FECHA').val().trim())
        .column(1).search($('#HORA').val().trim())
        .column(2).search($('#DESPACHO').val().trim())
        .column(3).search($('#CODCLIENTE').val().trim())
        .column(4).search($('#CLIENTE').val().trim())
        .column(5).search($('#TIPO').val().trim())
        .column(6).search($('#PLACAS').val().trim())
        .column(7).search($('#TARJETA').val().trim())
        .column(8).search($('#GRUPO').val().trim())
        .column(9).search($('#TARJETA').val().trim())
        .column(10).search($('#LITROS').val().trim())
        .column(11).search($('#MONTO').val().trim())
        .column(12).search($('#PRODUCTO').val().trim())
        .column(13).search($('#ESTACIÓN').val().trim())
        .column(14).search($('#BOMBA').val().trim())
        .column(15).search($('#FACTURA').val().trim())
        .column(16).search($('#UUID').val().trim())
        .column(16).search($('#RFC').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_credit_debit').on('click', function () {
    datatables_credit_debit.clear().draw();
    datatables_credit_debit.ajax.reload();
    $('#datatables_credit_debit').waitMe('hide');
});



// Table de Despachos de Crédito y Débito
let datatables_vehicles = $('#datatables_vehicles').DataTable({
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
        },
        {
            extend: 'pdf', // Agrega el botón de exportación a PDF
            className: 'd-none',
            text: 'PDF',
            customize: function (doc) {
                // Establecer la orientación horizontal (apaisada)
                doc.pageOrientation = 'landscape';
                // Ajustar todas las columnas al ancho del PDF
                let colWidths = [];
                let tableWidth = doc.pageOrientation === 'landscape' ? 1060 : 500; // Ancho de página para orientación horizontal o vertical
                let totalColWidths = 0;

                console.log(tableWidth);
                $('#datatables_vehicles thead th').each(function () {
                    let colWidth = $(this).outerWidth() / tableWidth;
                    totalColWidths += colWidth;
                    colWidths.push(colWidth * 100 + '%');
                });

                if (totalColWidths < 1) {
                    colWidths.push('*'); // Columna extra para completar el ancho restante
                }

                doc.content[1].table.widths = colWidths;
            }
        },
        {
            extend: 'print', // Agrega el botón de impresión
            className: 'd-none',
        }
    ],
    ajax: {
        // data: {'from': $('#datatables_vehicles').data('from'), 'until': $('#datatables_vehicles').data('until'), 'codgas': $('#datatables_vehicles').data('codgas')},
        url: '/income/datatables_vehicles',
        error: function() {
            $('#datatables_vehicles').waitMe('hide');
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
        {'data': 'CodCliente'},
        {'data': 'Cliente'},
        {'data': 'Tarjeta'},
        {'data': 'Placas'},
        {'data': 'Económico'},
        {'data': 'Vehículo'},
        {'data': 'Grupo'},
        {'data': 'Descripcion'},
        {'data': 'Status'},
    ],
    // rowId: 'Despacho',
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_vehicles input').on('keyup  change clear', function () {
    datatables_vehicles
        .column(0).search($('#CODCLI').val().trim())
        .column(1).search($('#CLIENTE').val().trim())
        .column(2).search($('#TARJETA').val().trim())
        .column(3).search($('#PLACAS').val().trim())
        .column(4).search($('#ECONOMICO').val().trim())
        .column(5).search($('#VEHICULO').val().trim())
        .column(6).search($('#GRUPO').val().trim())
        .column(7).search($('#DESCRIPCION').val().trim())
        .column(8).search($('#STATUS').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_vehicles').on('click', function () {
    datatables_vehicles.clear().draw();
    datatables_vehicles.ajax.reload();
    $('#datatables_vehicles').waitMe('hide');
});



// Table de Despachos de Crédito y Débito
let datatables_kioskos = $('#datatables_kioskos').DataTable({
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
        }
    ],
    ajax: {
        method: 'POST',
        data: {'from': $('#from').val(), 'until': $('#until').val()},
        url: '/income/datatables_kioskos',
        error: function() {
            $('#datatables_kioskos').waitMe('hide');
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
        {'data': 'FECHA'},
        {'data': 'HORA'},
        {'data': 'NO_DESPACHO'},
        {'data': 'IMPORTE'},
        {'data': 'REF_BANCARIA'},
        {'data': 'NO_TARJETA'},
        {'data': 'AUTORIZACION'},
        {'data': 'AFI_BANCARIA'},
        {'data': 'ACCIONES'},
    ],
    // rowId: 'Despacho',
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_kioskos input').on('keyup  change clear', function () {
    datatables_kioskos
        .column(0).search($('#FECHA').val().trim())
        .column(1).search($('#HORA').val().trim())
        .column(2).search($('#NO_DESPACHO').val().trim())
        .column(3).search($('#IMPORTE').val().trim())
        .column(4).search($('#REF_BANCARIA').val().trim())
        .column(5).search($('#NO_TARJETA').val().trim())
        .column(6).search($('#AUTORIZACION').val().trim())
        .column(7).search($('#AFI_BANCARIA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_kioskos').on('click', function () {
    datatables_kioskos.clear().draw();
    datatables_kioskos.ajax.reload();
    $('#datatables_kioskos').waitMe('hide');
});



// Table de Despachos de Crédito y Débito
let datatables_diffs = $('#datatables_diffs').DataTable({
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
        },
        {
            extend: 'pdf', // Agrega el botón de exportación a PDF
            className: 'd-none',
            text: 'PDF',
            customize: function (doc) {
                // Establecer la orientación horizontal (apaisada)
                doc.pageOrientation = 'landscape';
                // Ajustar todas las columnas al ancho del PDF
                let colWidths = [];
                let tableWidth = doc.pageOrientation === 'landscape' ? 1060 : 500; // Ancho de página para orientación horizontal o vertical
                let totalColWidths = 0;

                console.log(tableWidth);
                $('#datatables_diffs thead th').each(function () {
                    let colWidth = $(this).outerWidth() / tableWidth;
                    totalColWidths += colWidth;
                    colWidths.push(colWidth * 100 + '%');
                });

                if (totalColWidths < 1) {
                    colWidths.push('*'); // Columna extra para completar el ancho restante
                }

                doc.content[1].table.widths = colWidths;
            }
        },
        {
            extend: 'print', // Agrega el botón de impresión
            className: 'd-none',
        }
    ],
    ajax: {
        url: '/income/datatables_diffs/' + $('#datatables_diffs').data('from') + '/' + $('#datatables_diffs').data('until') + '/' + $('#datatables_diffs').data('codgas'),
        error: function() {
            $('#datatables_diffs').waitMe('hide');
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
        {'data': 'FECHA'},
        {'data': 'ESTACION'},
        {'data': 'TOTALCORTE', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
        {'data': 'TOTALDESPACHOS', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
        {'data': 'TOTALCONSUMOS', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
        {'data': 'DIFERENCIA', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
        {'data': 'ACCIONES'},
    ],
    createdRow: function (row, data, dataIndex) {
        // Vamos a comprara los valores de TOTALCORTE, TOTALDESPACHOS y TOTALCONSUMOS para pintar la celda de color verde si son iguales
        if (data['TOTALCORTE'] === data['TOTALDESPACHOS'] && data['TOTALCORTE'] === data['TOTALCONSUMOS']) {
            // Si los valores son diferentes, se pintarán de color rojo
        } else {
            // Si los valores son diferentes, se pintarán de color rojo
            $('td', row).eq(2).addClass('bg-danger text-light text-center');
            $('td', row).eq(3).addClass('bg-danger text-light text-center');
            $('td', row).eq(4).addClass('bg-danger text-light text-center');
            $('td', row).eq(5).addClass('text-danger');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_diffs input').on('keyup  change clear', function () {
    datatables_diffs
        .column(0).search($('#FECHA').val().trim())
        .column(1).search($('#ESTACION').val().trim())
        .column(2).search($('#TOTALCORTE').val().trim())
        .column(3).search($('#TOTALDESPACHOS').val().trim())
        .column(4).search($('#TOTALCONSUMOS').val().trim())
        .column(5).search($('#DIFERENCIA').val().trim())
        .draw();
});

// Table de Despachos de Crédito y Débito
let datatables_diff_analysis = $('#datatables_diff_analysis').DataTable({
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
        url: '/income/datatables_diff_analysis/' + $('input#fch').val() + '/' + $('input#codgas').val(),
        error: function() {
            $('#datatables_diff_analysis').waitMe('hide');
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
        {'data': 'DESPACHO'},
        {'data': 'HORA'},
        {'data': 'CLIENTE'},
        {'data': 'TIPO'},
        {'data': 'TARJETA'},
        {'data': 'PRODUCTO'},
        {'data': 'FACTURA'},
        {'data': 'PRECIO', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
        {'data': 'MONTO', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
        {'data': 'DATOS'},
        {'data': 'TURNO'},
        {'data': 'ISLA'},
        {'data': 'FECHA'},
        {'data': 'ESTACIÓN'},
        {'data': 'COINCIDENCIA'}
    ],
    createdRow: function (row, data, dataIndex) {
        // Si el valor del campo Tipo es 'Crédito', se pinta la celda de color amarillo
        if (data['TIPO'] === 'Crédito') {
            $('td', row).eq(3).addClass('bg-warning text-dark text-center');
        }

        // Si el valor del campo Tipo es 'Débito', se pinta la celda de color verde
        if (data['TIPO'] === 'Débito') {
            $('td', row).eq(3).addClass('bg-success text-light text-center');
        }

        if (data['COINCIDENCIA'] == '-NO-') {
            // Vamos a pintar toda la fila de color rojo si la coincidencia es 0
            $(row).addClass('table-danger text-dark');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    },
    // Vamos a agregar un footer callback para sumar los valores de la columna Monto
    footerCallback: function ( row, data, start, end, display ) {
        var api = this.api(), data;
        // Remove the formatting to get integer data for summation
        var intVal = function (i) {
            return typeof i === 'string' ?
                i.replace(/[\$,]/g, '') * 1 :
                typeof i === 'number' ?
                    i : 0;
        };
        // Función para calcular el total
        var calculateTotal = function () {
            return api
                .column(8, { search: 'applied' }) // Solo tomará en cuenta los datos visibles después de aplicar filtros
                .data()
                .reduce(function (a, b) {
                    return intVal(a) + intVal(b);
                }, 0);
        };
        // Total inicial
        var total = calculateTotal();
        // Update footer
        $(api.column(8).footer()).html(
            // Formatear el total con formato de moneda a dos decimales
            total.toLocaleString('es-MX', {
                style: 'currency',
                currency: 'MXN'
            })
        );
        // Evento draw para recalcular el total cuando se redibuja la tabla
        api.on('draw', function () {
            var total = calculateTotal();
            $(api.column(8).footer()).html(
                total.toLocaleString('es-MX', {
                    style: 'currency',
                    currency: 'MXN'
                })
            );
        });
    }
});

// Table de Despachos de Crédito y Débito
let datatables_consumes = $('#datatables_consumes').DataTable({
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

        url: '/income/datatables_consumes/' + $('input#fch').val() + '/' + $('input#codgas').val(),
        error: function() {
            $('#datatables_consumes').waitMe('hide');
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
        {'data': 'DESPACHO'},
        {'data': 'TURNO'},
        {'data': 'CLIENTE'},
        {'data': 'TIPO'},
        {'data': 'PRODUCTO'},
        {'data': 'FACTURA'},
        {'data': 'PRECIO', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
        {'data': 'MONTO', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
        {'data': 'COINCIDENCIA'},
    ],
    createdRow: function (row, data, dataIndex) {
        // Si el valor del campo Tipo es 'Crédito', se pinta la celda de color amarillo
        if (data['TIPO'] === 'Crédito') {
            $('td', row).eq(3).addClass('bg-warning text-dark text-center');
        }

        // Si el valor del campo Tipo es 'Débito', se pinta la celda de color verde
        if (data['TIPO'] === 'Débito') {
            $('td', row).eq(3).addClass('bg-success text-light text-center');
        }

        if (data['COINCIDENCIA'] == '-NO-') {
            // Vamos a pintar toda la fila de color rojo si la coincidencia es 0
            $(row).addClass('table-danger text-dark');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    },
    // Vamos a agregar un footer callback para sumar los valores de la columna Monto
    footerCallback: function ( row, data, start, end, display ) {
        var api = this.api(), data;
        // Remove the formatting to get integer data for summation
        var intVal = function (i) {
            return typeof i === 'string' ?
                i.replace(/[\$,]/g, '') * 1 :
                typeof i === 'number' ?
                    i : 0;
        };
        // Función para calcular el total
        var calculateTotal = function () {
            return api
                .column(6, { search: 'applied' }) // Solo tomará en cuenta los datos visibles después de aplicar filtros
                .data()
                .reduce(function (a, b) {
                    return intVal(a) + intVal(b);
                }, 0);
        };
        // Total inicial
        var total = calculateTotal();
        // Update footer
        $(api.column(6).footer()).html(
            // Formatear el total con formato de moneda a dos decimales
            total.toLocaleString('es-MX', {
                style: 'currency',
                currency: 'MXN'
            })
        );
        // Evento draw para recalcular el total cuando se redibuja la tabla
        api.on('draw', function () {
            var total = calculateTotal();
            $(api.column(6).footer()).html(
                total.toLocaleString('es-MX', {
                    style: 'currency',
                    currency: 'MXN'
                })
            );
        });
    }
});


// Table de Despachos de Crédito y Débito
let datatables_pending_dispatches_for_invoice = $('#datatables_pending_dispatches_for_invoice').DataTable({
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'btn btn-success',
            text: 'Excel',
            filename: 'Despachos Pendientes de Facturar',
        },
        {
            extend: 'pdf', // Agrega el botón de exportación a PDF
            className: 'btn btn-danger',
            text: 'PDF',
            customize: function (doc) {
                // Establecer la orientación horizontal (apaisada)
                doc.pageOrientation = 'landscape';
                // Ajustar todas las columnas al ancho del PDF
                let colWidths = [];
                let tableWidth = doc.pageOrientation === 'landscape' ? 1060 : 500; // Ancho de página para orientación horizontal o vertical
                let totalColWidths = 0;

                console.log(tableWidth);
                $('#datatables_pending_dispatches_for_invoice thead th').each(function () {
                    let colWidth = $(this).outerWidth() / tableWidth;
                    totalColWidths += colWidth;
                    colWidths.push(colWidth * 100 + '%');
                });

                if (totalColWidths < 1) {
                    colWidths.push('*'); // Columna extra para completar el ancho restante
                }

                doc.content[1].table.widths = colWidths;
            }
        },
       
    ],
    ajax: {
        url: '/income/datatables_pending_dispatches_for_invoice/' + $('input#from').val() + '/' + $('input#until').val() + '/' + $('select#type').val() + '/' + $('select#status').val(),
        error: function() {
            $('#datatables_pending_dispatches_for_invoice').waitMe('hide');
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
        {'data': 'FECHA'},
        {'data': 'DESPACHO'},
        {'data': 'ESTACIÓN'},
        {'data': 'PRODUCTO'},
        {'data': 'CANTIDAD', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'MONTO', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
        {'data': 'CODCLIENTE'},
        {'data': 'CLIENTE'},
        {'data': 'TIPO'},
        {'data': 'FACTURA'},
        {'data': 'UUID'},
    ],
    createdRow: function (row, data, dataIndex) {
        // Si monto es menor o igual 0, se pinta la celda de color rojo

        // Si tipo es 'Crédito', se pinta la celda de color rojo
        if (data['TIPO'] === 'Crédito') {
            $('td', row).eq(8).addClass('bg-primary text-light text-center');
        }

        // Si tipo es 'Débito', se pinta la celda de color rojo
        if (data['TIPO'] === 'Débito') {
            $('td', row).eq(8).addClass('bg-success text-light text-center');
        }
    },
    initComplete: function () {
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_pending_dispatches_for_invoice input').on('keyup  change clear', function () {
    datatables_pending_dispatches_for_invoice
        .column(0).search($('#FECHA').val().trim())
        .column(1).search($('#DESPACHO').val().trim())
        .column(2).search($('#ESTACION').val().trim())
        .column(3).search($('#PRODUCTO').val().trim())
        .column(4).search($('#CANTIDAD').val().trim())
        .column(5).search($('#MONTO').val().trim())
        .column(6).search($('#CODCLIENTE').val().trim())
        .column(7).search($('#CLIENTE').val().trim())
        .column(8).search($('#TIPO').val().trim())
        .column(9).search($('#FACTURA').val().trim())
        .column(10).search($('#UUID').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_pending_dispatches_for_invoice').on('click', function () {
    datatables_pending_dispatches_for_invoice.clear().draw();
    datatables_pending_dispatches_for_invoice.ajax.reload();
    $('#datatables_pending_dispatches_for_invoice').waitMe('hide');
});

function release_dispatch(nrotrn, codgas) {
    // Primero validamos que las variables no esten vacias
    if (nrotrn === '' || codgas === '') {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Favor de ingresar un número de transacción y un código de gasolinera.</p>
            </div>`
        );
        return;
    }

    // Ahora vamos a enviar las variables por medio de ajax de jquery a php
    $.ajax({
        url: '/it/release_dispatch',
        method: 'POST',
        data: {
            'nrotrn': nrotrn,
            'codgas': codgas
        },
        dataType: 'json',
        success: function(data) {
            if (data.status === 'OK') {
                datatables_diff_analysis.clear().draw();
                datatables_diff_analysis.ajax.reload();
                $('.table-responsive').removeClass('loading');
                toastr.success("El despacho ha sido liberado correctamente", "¡Éxito!", { timeOut: 2000 });
            }
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
}

let all_dispatches_table = $('#all_dispatches_table').DataTable({
    colReorder: true,
    dom: '<"top"B>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Agregamos el título al documento de Excel
            title: 'Tickets de despacho',
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]
            },
            customize: function (xlsx) {
                var sheet = xlsx.xl.worksheets['sheet1.xml'];

                // Agregar nuevas columnas
                $('row', sheet).each(function (i, row) {
                    if (i === 1) {
                        $(row).append('<c t="inlineStr"><is><t></t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Total registros: '+ $('#totalRecords').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Marcados: '+ $('#totalCheckedRecords').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Pendientes: '+ $('#totalPendingRecords').text() +'</t></is></c>');
                    }

                    if (i === 2) {
                        $(row).append('<c t="inlineStr"><is><t></t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Monto total: '+ $('#formattedTotal').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Marcado: '+ $('#formattedCheckedTotal').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Pendiente: '+ $('#pendignAmount').text() +'</t></is></c>');
                    }
                });
            }
        }
    ],
    ajax: {
        url: '/income/all_dispatches_table/' + $('input#from').val() + '/' + $('#codgas').val() + '/' + $('#shift').val() + '/' + $('#dispatch_type').val(),
        error: function() {
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
        {'data': 'DESPACHO'},
        {'data': 'ESTACION'},
        {'data': 'ISLA'},
        {'data': 'CODCLIENTE'},
        {'data': 'CLIENTE'},
        {'data': 'VOLUMEN', render: $.fn.dataTable.render.number( ',', '.', 3, '', ' lts')},
        {'data': 'MONTO', render: $.fn.dataTable.render.number( ',', '.', 2, '$' )},
        {'data': 'TIPO'},
        {'data': 'TURNO'},
        {'data': 'FECHA'},
        {'data': 'PRODUCTO'},
        {'data': 'STATUS'},
        {'data': 'COMENTARIO'},
        {'data': 'ACCIONES'},
    ],
    createdRow: function (row, data, dataIndex) {
        // Vamos a verificar si la columna STATUS contine texto diferente a 'Sin verificar', y si es así pintamos la fila de verde
        if (data['STATUS'] !== 'Sin verificar') {
            $(row).addClass('table-success text-light');
        }

        // Vamos a verificar si la columna STATUS contine texto diferente a 'Sin verificar', y si es así pintamos la fila de verde
        if (data['INCIDENCIA'] == 1) {
            $('td', row).eq(9).addClass('bg-warning text-dark text-center');
        }

        if (data['CASOESPECIAL'] == 1) {
            $('td', row).eq(4).addClass('bg-danger text-light text-center');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-all_dispatches_table input').on('keyup change clear', function () {
    all_dispatches_table
        .column(0).search($('#DESPACHO').val().trim())
        .column(1).search($('#ESTACION').val().trim())
        .column(2).search($('#ISLA').val().trim())
        .column(3).search($('#CODCLIENTE').val().trim())
        .column(4).search($('#CLIENTE').val().trim())
        .column(5).search($('#VOLUMEN').val().trim())
        .column(6).search($('#MONTO').val().trim())
        .column(7).search($('#TIPO').val().trim())
        .column(8).search($('#TURNO').val().trim())
        .column(9).search($('#FECHA').val().trim())
        .column(10).search($('#PRODUCTO').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#COMENTARIO').val().trim())
        .draw();
});

let checked_dispatches_table = $('#checked_dispatches_table').DataTable({
    colReorder: true,
    dom: '<"top"B>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Agregamos el título al documento de Excel
            title: 'Tickets de despacho verificados',
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]
            },
            customize: function (xlsx) {
                var sheet = xlsx.xl.worksheets['sheet1.xml'];

                // Agregar nuevas columnas
                $('row', sheet).each(function (i, row) {
                    if (i === 1) {
                        $(row).append('<c t="inlineStr"><is><t></t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Total registros: '+ $('#totalRecords').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Marcados: '+ $('#totalCheckedRecords').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Pendientes: '+ $('#totalPendingRecords').text() +'</t></is></c>');
                    }

                    if (i === 2) {
                        $(row).append('<c t="inlineStr"><is><t></t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Monto total: '+ $('#formattedTotal').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Marcado: '+ $('#formattedCheckedTotal').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Pendiente: '+ $('#pendignAmount').text() +'</t></is></c>');
                    }
                });
            }
        }
    ],
    ajax: {
        url: '/income/checked_dispatches_table/' + $('input#from').val() + '/' + $('#codgas').val() + '/' + $('#shift').val(),
        data: {
            'dispatch_type': $('#dispatch_type').val(),
        },
        error: function() {
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
        {'data': 'DESPACHO'},
        {'data': 'ESTACION'},
        {'data': 'ISLA'},
        {'data': 'CODCLIENTE'},
        {'data': 'CLIENTE'},
        {'data': 'VOLUMEN', render: $.fn.dataTable.render.number( ',', '.', 3, '', ' lts')},
        {'data': 'MONTO', render: $.fn.dataTable.render.number( ',', '.', 2, '$' )},
        {'data': 'TIPO'},
        {'data': 'TURNO'},
        {'data': 'FECHA'},
        {'data': 'PRODUCTO'},
        {'data': 'STATUS'},
        {'data': 'ACCIONES'},
    ],
    createdRow: function (row, data, dataIndex) {
        // Vamos a verificar si la columna STATUS contine texto diferente a 'Sin verificar', y si es así pintamos la fila de verde
        if (data['STATUS'] !== 'Sin verificar') {
            $(row).addClass('table-success text-light');
        }
        // Vamos a verificar si la columna STATUS contine texto diferente a 'Sin verificar', y si es así pintamos la fila de verde
        if (data['INCIDENCIA'] == 1) {
            $('td', row).eq(9).addClass('bg-warning text-dark text-center');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-checked_dispatches_table input').on('keyup change clear', function () {
    checked_dispatches_table
        .column(0).search($('#DESPACHO').val().trim())
        .column(1).search($('#ESTACION').val().trim())
        .column(2).search($('#ISLA').val().trim())
        .column(3).search($('#CODCLIENTE').val().trim())
        .column(4).search($('#CLIENTE').val().trim())
        .column(5).search($('#VOLUMEN').val().trim())
        .column(6).search($('#MONTO').val().trim())
        .column(7).search($('#TIPO').val().trim())
        .column(8).search($('#TURNO').val().trim())
        .column(9).search($('#FECHA').val().trim())
        .column(10).search($('#PRODUCTO').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .draw();
});


let pending_dispatches_table = $('#pending_dispatches_table').DataTable({
    colReorder: true,
    dom: '<"top"B>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Agregamos el título al documento de Excel
            title: 'Tickets de despacho pendientes',
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]
            },
            customize: function (xlsx) {
                var sheet = xlsx.xl.worksheets['sheet1.xml'];

                // Agregar nuevas columnas
                $('row', sheet).each(function (i, row) {
                    if (i === 1) {
                        $(row).append('<c t="inlineStr"><is><t></t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Total registros: '+ $('#totalRecords').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Marcados: '+ $('#totalCheckedRecords').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Pendientes: '+ $('#totalPendingRecords').text() +'</t></is></c>');
                    }

                    if (i === 2) {
                        $(row).append('<c t="inlineStr"><is><t></t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Monto total: '+ $('#formattedTotal').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Marcado: '+ $('#formattedCheckedTotal').text() +'</t></is></c>');
                        $(row).append('<c t="inlineStr"><is><t>Pendiente: '+ $('#pendignAmount').text() +'</t></is></c>');
                    }
                });
            }
        }
    ],
    ajax: {
        url: '/income/pending_dispatches_table/' + $('input#from').val() + '/' + $('#codgas').val() + '/' + $('#shift').val() + '/' + $('#dispatch_type').val(),
        error: function() {
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
        {'data': 'DESPACHO'},
        {'data': 'ESTACION'},
        {'data': 'ISLA'},
        {'data': 'CODCLIENTE'},
        {'data': 'CLIENTE'},
        {'data': 'VOLUMEN', render: $.fn.dataTable.render.number( ',', '.', 3, '', ' lts')},
        {'data': 'MONTO', render: $.fn.dataTable.render.number( ',', '.', 2, '$' )},
        {'data': 'TIPO'},
        {'data': 'TURNO'},
        {'data': 'FECHA'},
        {'data': 'PRODUCTO'},
        {'data': 'STATUS'}
    ],
    createdRow: function (row, data, dataIndex) {
        // Vamos a verificar si la columna STATUS contine texto diferente a 'Sin verificar', y si es así pintamos la fila de verde
        if (data['STATUS'] !== 'Sin verificar') {
            $(row).addClass('table-success text-light');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-pending_dispatches_table input').on('keyup change clear', function () {
    checked_dispatches_table
        .column(0).search($('#DESPACHO').val().trim())
        .column(1).search($('#ESTACION').val().trim())
        .column(2).search($('#ISLA').val().trim())
        .column(3).search($('#CODCLIENTE').val().trim())
        .column(4).search($('#CLIENTE').val().trim())
        .column(5).search($('#VOLUMEN').val().trim())
        .column(6).search($('#MONTO').val().trim())
        .column(7).search($('#TIPO').val().trim())
        .column(8).search($('#TURNO').val().trim())
        .column(9).search($('#FECHA').val().trim())
        .column(10).search($('#PRODUCTO').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .draw();
});


// Agregar un evento clic de refresh
$('.refresh').on('click', function () {
    all_dispatches_table.clear().draw();
    all_dispatches_table.ajax.reload();
    $('#all_dispatches_table').waitMe('hide');

    checked_dispatches_table.clear().draw();
    checked_dispatches_table.ajax.reload();
    $('#checked_dispatches_table').waitMe('hide');

    pending_dispatches_table.clear().draw();
    pending_dispatches_table.ajax.reload();
    $('#pending_dispatches_table').waitMe('hide');
});

all_dispatches_table.on('draw', function() {
    $('.table-responsive').removeClass('loading');

    // Sumar los valores de la columna 11 (suponiendo que los valores son números)
    var totalAmount = all_dispatches_table
        .column(6)
        .data()
        .reduce(function (sum, value) {
            // Convertir el valor a número asegurando que no sea NaN y sumar
            return sum + parseFloat(value) || 0;
        }, 0);  // 0 es el valor inicial de la suma

    // Formatear el total como moneda en pesos mexicanos
    var formattedTotal = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(totalAmount);

    // Filtrar y sumar los valores de la columna 11 donde la columna 10 no sea 'Sin verificar'
    var totalCheckedAmount = all_dispatches_table
        .rows()
        .data()
        .reduce(function (sum, row) {
            // row[10] es la columna 10, row[11] es la columna 11
            if (row['STATUS'] !== 'Sin verificar') {
                return sum + parseFloat(row['MONTO']) || 0;
            }
            return sum;  // Si no cumple la condición, retorna la suma acumulada
        }, 0);  // 0 es el valor inicial de la suma

// Formatear el total como moneda en pesos mexicanos
    var formattedCheckedTotal = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(totalCheckedAmount);

    var pendignAmount = totalAmount - totalCheckedAmount;
    var formattedpendignAmount = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(pendignAmount);

    $('i#formattedTotal').text(formattedTotal);
    $('i#formattedCheckedTotal').text(formattedCheckedTotal);
    $('i#pendignAmount').text(formattedpendignAmount);


    // Ahora vamos a obtener la cantidad de registros
    var totalRecords = all_dispatches_table.rows().count();
    // Filtrar los registros donde el valor en la columna 10 es diferente de 'Sin verificar'
    var totalCheckedRecords = all_dispatches_table
        .column(11)
        .data()
        .filter(function (value, index) {
            return value !== 'Sin verificar';
        }).length;  // Contar el número de registros filtrados
    var totalPendingRecords = totalRecords - totalCheckedRecords;
    $('i#totalRecords').text(totalRecords);
    $('i#totalCheckedRecords').text(totalCheckedRecords);
    $('i#totalPendingRecords').text(totalPendingRecords);

    console.log(totalRecords, totalCheckedRecords, totalPendingRecords);
});


// Vamos a agregar un evento para cuando se abra el modal #notesModal
$('#notesModal').on('show.bs.modal', function (event) {
    let button = $(event.relatedTarget); // Botón que abre el modal
    let dispatch_id = button.data('id');
    let despacho = button.data('despacho');
    let estacion = button.data('estacion');
    let comentario = button.data('comentario');
    let modal = $(this);
    modal.find('.modal-body #input_dispatch').val(despacho);
    modal.find('.modal-body #input_codgas').val(estacion);
    modal.find('.modal-body #input_notes').val(comentario);
    modal.find('.modal-body #dispatch_id').val(dispatch_id);
});

// Cuando el formulario #notes_form sea enviado ponemos la clase.loading a el modal
$('#notes_form').on('submit', function () {
    $('.modal-content').addClass('loading');
});

// Cuando hagamos clic en el boton #exportExcel, vamos a exportar la tabla a un archivo de Excel
$('#all_dispatches_table_to_excel').on('click', function () {
    // Vamos a hacer un trigger click en el botón de exportar a Excel pero solo el de la tabla de despachos
    all_dispatches_table.button('.buttons-excel').trigger();
});

$('#checked_dispatches_table_to_excel').on('click', function () {
    // Vamos a hacer un trigger click en el botón de exportar a Excel pero solo el de la tabla de despachos
    checked_dispatches_table.button('.buttons-excel').trigger();
});

$('#pending_dispatches_table_to_excel').on('click', function () {
    // Vamos a hacer un trigger click en el botón de exportar a Excel pero solo el de la tabla de despachos
    pending_dispatches_table.button('.buttons-excel').trigger();
});

// Cuando el modal mailModal se abra, vamos a hacer una peticion ajax
$('#mailModal').on('show.bs.modal', function (event) {
    let modal = $(this);

    // Vamos a hacer una peticion ajax para obtener los correos de los usuarios
    $.ajax({
        url: '/income/get_users_emails',
        method: 'GET',
        data: {
            'dispatch_type': $('#dispatch_type').val(),
            'codgas': $('#codgas').val(),
            'nrotur': $('#nrotur').val(),
            'shift': $('#shift').val(),
            'from': $('#from').val(),
        },
        success: function(data) {
            // Vamos a recorrer el objeto data y vamos a agregar los correos a un select
            modal.find('.modal-body #sentTo').val(`${data.user_mail}; ${data.station_mail}`);
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});

// Vamos a enviar el formulario #mail_form por medio de AJAX
$('#mail_form').on('submit', function (e) {
    e.preventDefault();
    // Primero vamos a agregar la clase .loading a .content
    $('.modal').addClass('loading');

    // Primero vamos a borrar los espacion en blanco que pueda contener el campo #sentTo
    $('#sentTo').val($('#sentTo').val().replace(/\s/g, ''));
    // Ahora vamos a verificar cada uno de los correo que se ingresaron y que estan separados por ;
    let emails = $('#sentTo').val().split(';');
    // Tenemos que verificar que cada correo termine con el dominio @totalgas.com
    let valid_emails = [];
    emails.forEach(email => {
        if (email.includes('@totalgas.com')) {
            valid_emails.push(email);
        }
    });

    // Vamos a arrojar una alerta si un correo no cumple con la condicion y a detener el envio hasta que se corrija
    if (valid_emails.length !== emails.length) {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Favor de ingresar correos válidos.</p>
            </div>`
        );
        $('.modal').removeClass('loading');
        return;
    }

    // Llamada AJAX para enviar el correo
    var xhr = new XMLHttpRequest();
    xhr.open("POST", '/income/send_mail/' + $('input#from').val() + '/' + $('#codgas').val() + '/' + $('#shift').val() + '/' + $('#dispatch_type').val() + '/' + $('#sentTo').val(), true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            console.log(xhr); // Muestra el mensaje de éxito o error
        }
        // Vamos a quitar la clase .loading a la clase .content
        $('.modal').removeClass('loading');
        // Vamos a cerrar el modal
        $('#mailModal').modal('hide');
    };
    xhr.send(new FormData(this));
});


// Vamos a agregar un evento para cuando se abra el modal #ticketModal
$('#ticketModal').on('show.bs.modal', function (event) {
    let button = $(event.relatedTarget); // Botón que abre el modal
    let id = button.data('id');
    // Vamos a obtener un ticket por medio de AJAX
    $.ajax({
        url: '/income/get_voucher/' + id,
        method: 'GET',
        success: function(data) {

            // Vamos a agregar el atributo onclick="print()" al botón de imprimir
            $('#print_button').attr('onclick', `print(${data.despacho})`);

            let modal = $('#ticketModal');
            modal.find('.modal-body #ticket').html(data.voucher + `
                <div style="text-align: center; max-width: 246px">
                    <canvas id="barcode"></canvas>
                </div>
            `);
            // Generar código de barras usando JsBarcode
            JsBarcode("#barcode", data.despacho + '0', {
                format: "CODE128",
                displayValue: true,
                lineColor: "#222",
                width: 1,
                height: 20,
            });
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});


function print(despacho) {

    var ticketContent = document.getElementById('ticket').innerHTML;
    var printWindow = window.open('', '', 'width=1000,height=1000');

    printWindow.document.write('<html><head><title>Imprimir Ticket</title></head><body>');
    printWindow.document.write(ticketContent);
    printWindow.document.write('</body></html>');
    printWindow.document.close(); // Necesario para que se cargue correctamente el contenido

    // Esperar a que la nueva ventana cargue completamente antes de llamar a print
    printWindow.onload = function () {
        // Generar código de barras en la ventana de impresión
        var barcodeCanvas = printWindow.document.getElementById('barcode');
        if (barcodeCanvas) {
            JsBarcode(barcodeCanvas, despacho + '0', {
                format: "CODE128",
                displayValue: true,
                lineColor: "#222",
                width: 1,
                height: 20,
            });
        }

        // Ahora imprimimos una vez que el código de barras ha sido generado
        printWindow.print();
    };

}

async function dispatches_credit_client_table(){
    if ($.fn.DataTable.isDataTable('#dispatches_credit_client_table')) {
        $('#dispatches_credit_client_table').DataTable().destroy();  // Destruye la tabla existente
        // $('#dispatches_credit_client_table thead').empty(); // Limpia el encabezado
        // $('#dispatches_credit_client_table tbody').empty(); // Limpia el cuerpo
        // $('#dispatches_credit_client_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;




   $('#dispatches_credit_client_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 150,
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-success',
                text: ' Excel'
            }
        ],
        ajax: {
            method: 'POST',
            data: {
                'from':fromDate,
                'until':untilDate
            },
            url: '/income/dispatches_credit_client_table',
            error: function() {
                $('#dispatches_credit_client_table').waitMe('hide');
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
        columns: [
            {'data': 'date'},
            {'data': 'station'},
            {'data': 'cod_client'},
            {'data': 'client'},
            {'data': 'product'},
            {'data': 'dispatch'},
            {'data': 'import', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
            {'data': 'can', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
            {'data': 'series'},
            {'data': 'nrofac'},
        ],
        destroy: true, 
        rowId: 'dispatch',
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            // $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {

        }
    });
    $('.dispatches_credit_client_table').on('click', function () {
        dispatches_credit_client_table.clear().draw();
        dispatches_credit_client_table.ajax.reload();
        $('#dispatches_credit_client_table').waitMe('hide');
    });
}

async function relation_invoice_advance_table(){
    if ($.fn.DataTable.isDataTable('#relation_invoice_advance_table')) {
        $('#relation_invoice_advance_table').DataTable().destroy();  // Destruye la tabla existente
         $('#relation_invoice_advance_table thead .filter').remove(); // Limpia el encabezado
        // $('#relation_invoice_advance_table tbody').empty(); // Limpia el cuerpo
        // $('#relation_invoice_advance_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    $('#relation_invoice_advance_table thead').prepend($('#relation_invoice_advance_table thead tr').clone().addClass('filter'));
    $('#relation_invoice_advance_table thead tr.filter th').each(function (index) {
        col = $('#relation_invoice_advance_table thead th').length/2;
        if (index < col - 1) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#relation_invoice_advance_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#relation_invoice_advance_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });

   $('#relation_invoice_advance_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 150,
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-success',
                text: ' Excel'
            }
        ],
        ajax: {
            method: 'POST',
            data: {
                'from':fromDate,
                'until':untilDate
            },
            url: '/income/relation_invoice_advance_table',
            error: function() {
                $('#relation_invoice_advance_table').waitMe('hide');
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
        columns: [
            {'data': 'fecha', className: 'text-nowrap'},
            {'data': 'factura' , className: 'text-nowrap'},
            {'data': 'factura_anticipo', className: 'text-nowrap'},
            {'data': 'client' },
            {'data': 'mto_fact_e', className: 'text-end text-nowrap', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'mto_iva_e', className: 'text-end text-nowrap', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'mto_total_e', className: 'text-end text-nowrap', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'monto_aplicado', className: 'text-end text-nowrap', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'monto', className: 'text-end text-nowrap', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'mtoiva', className: 'text-end text-nowrap', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'monto_original', className: 'text-end text-nowrap', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'UUID'},
            {'data': 'uid_anticipo'},
            {'data': 'txt_anticipo'}
        ],
        destroy: true, 
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            // $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {

        }
    });
    $('.relation_invoice_advance_table').on('click', function () {
        relation_invoice_advance_table.clear().draw();
        relation_invoice_advance_table.ajax.reload();
        $('#relation_invoice_advance_table').waitMe('hide');
    });
}


async function relation_credit_table(){
    if ($.fn.DataTable.isDataTable('#relation_credit_table')) {
        $('#relation_credit_table').DataTable().destroy();  // Destruye la tabla existente
         $('#relation_credit_table thead .filter').remove(); // Limpia el encabezado
        // $('#relation_credit_table tbody').empty(); // Limpia el cuerpo
        // $('#relation_credit_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from2').value;
    var untilDate = document.getElementById('until2').value;

    $('#relation_credit_table thead').prepend($('#relation_credit_table thead tr').clone().addClass('filter'));
    $('#relation_credit_table thead tr.filter th').each(function (index) {
        col = $('#relation_credit_table thead th').length/2;
        if (index < col - 1) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#relation_credit_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#relation_credit_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });

   $('#relation_credit_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 150,
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-success',
                text: ' Excel'
            }
        ],
        ajax: {
            method: 'POST',
            data: {
                'from':fromDate,
                'until':untilDate
            },
            url: '/income/relation_credit_table',
            error: function() {
                $('#relation_credit_table').waitMe('hide');
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
        columns: [
            {'data': 'fecha', className: 'word-wrap'},
            {'data': 'factura'},
            {'data': 'factura_anticipo'},
            {'data': 'client'},
            {'data': 'monto_sub', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'monto_iva', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'monto_aplicado', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'monto_original', render: $.fn.dataTable.render.number( ',', '.', 2, '$') },
            {'data': 'UUID'},
            {'data': 'uid_anticipo'},
            {'data': 'txt_anticipo'}
        ],
        destroy: true, 
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            // $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {

        }
    });
    $('.relation_credit_table').on('click', function () {
        relation_credit_table.clear().draw();
        relation_credit_table.ajax.reload();
        $('#relation_credit_table').waitMe('hide');
    });
}



async function cash_sales_table(){
    if ($.fn.DataTable.isDataTable('#cash_sales_table')) {
        $('#cash_sales_table').DataTable().destroy();
        $('#cash_sales_table thead .filter').remove();
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;
    var codgas = document.getElementById('codgas').value;

    if (codgas == '') {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Favor de seleccionar una estación.</p>
            </div>`
        );
        return;
    }

    $('#cash_sales_table thead').prepend($('#cash_sales_table thead tr').clone().addClass('filter'));
    $('#cash_sales_table thead tr.filter th').each(function (index) {
        col = $('#cash_sales_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#cash_sales_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#cash_sales_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let cash_sales_table =$('#cash_sales_table').DataTable({
        order: [0, "asc"],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        paging: true,
        pageLength: 100,
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
            url: '/income/cash_sales_table',
            timeout: 600000, 
            error: function() {
                $('#cash_sales_table').waitMe('hide');
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
            { data: 'Fecha' },
            { data: 'Gasolinera' },
            { data: 'Turno' },
            { data: 'Dolares' },
            { data: 'Dolares2' },
            { data: 'Mn' },
            { data: 'Morralla' },
            { data: 'Cheques' },
            { data: 'INTERL - Efectivo' },
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

async function clients_debit_table(){
    if ($.fn.DataTable.isDataTable('#clients_debit_table')) {
        $('#clients_debit_table').DataTable().destroy();
        $('#clients_debit_table thead .filter').remove();

    }
    var status = document.getElementById('status').value;

    $('#clients_debit_table thead').prepend($('#clients_debit_table thead tr').clone().addClass('filter'));
    $('#clients_debit_table thead tr.filter th').each(function (index) {
        col = $('#clients_debit_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#clients_debit_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#clients_debit_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let clients_debit_table =$('#clients_debit_table').DataTable({
        order: [0, "asc"],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
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
                'status':status
            },
            url: '/income/clients_debit_table',
            timeout: 600000, 
            error: function() {
                $('#clients_debit_table').waitMe('hide');
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
            {'data': 'cod'},
            {'data': 'den',className:'text-nowrap'},
            {'data': 'debsdo', render: $.fn.dataTable.render.number( ',', '.', 2, '$'), className:'text-nowrap text-end'},
            {'data': 'status', className:'text-nowrap text-center'},
            {'data': 'dom'},
            {'data': 'rfc'},


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

async function cargarGraficaDesdeController() {
  const response = await fetch('chartcontroller.php');
  const datos = await response.json();

  const estaciones = [...new Set(datos.map(d => d.estacion))].sort();
  const tipos = [...new Set(datos.map(d => d.tipo_combustible))];

  const colores = {
    Magna: 'rgba(75, 192, 192, 0.7)',
    Premium: 'rgba(255, 99, 132, 0.7)',
    Diesel: 'rgba(255, 206, 86, 0.7)'
  };

  const datasets = tipos.map(tipo => ({
    label: tipo,
    backgroundColor: colores[tipo] || 'rgba(150,150,150,0.7)',
    data: estaciones.map(est => {
      const entrada = datos.find(d => d.estacion === est && d.tipo_combustible === tipo);
      return entrada ? parseFloat(entrada.total_venta) : 0;
    })
  }));

  new Chart(document.getElementById('ventasChart'), {
    type: 'bar',
    data: {
      labels: estaciones.map(e => 'Estación ' + e),
      datasets: datasets
    },
    options: {
      responsive: true,
      plugins: {
        title: {
          display: true,
          text: 'Ventas por tipo de combustible por estación'
        },
        legend: {
          position: 'top'
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          title: { display: true, text: 'Monto en MXN' }
        },
        x: {
          title: { display: true, text: 'Estación' }
        }
      }
    }
  });
}

cargarGraficaDesdeController();
