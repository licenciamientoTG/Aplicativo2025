
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
            messageTop: function () {
                return 'Total: ' + $('#totalRecords').text() + '   Marcados: ' + $('#totalCheckedRecords').text() + '   Pendientes: ' + $('#totalPendingRecords').text() + '\n' +
                       'Monto total: ' + $('#formattedTotal').text() + '   Marcado: ' + $('#formattedCheckedTotal').text() + '   Pendiente: ' + $('#pendignAmount').text();
            },
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]
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
            messageTop: function () {
                return 'Total: ' + $('#totalRecords').text() + '   Marcados: ' + $('#totalCheckedRecords').text() + '   Pendientes: ' + $('#totalPendingRecords').text() + '\n' +
                       'Monto total: ' + $('#formattedTotal').text() + '   Marcado: ' + $('#formattedCheckedTotal').text() + '   Pendiente: ' + $('#pendignAmount').text();
            },
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]
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
            messageTop: function () {
                return 'Total: ' + $('#totalRecords').text() + '   Marcados: ' + $('#totalCheckedRecords').text() + '   Pendientes: ' + $('#totalPendingRecords').text() + '\n' +
                       'Monto total: ' + $('#formattedTotal').text() + '   Marcado: ' + $('#formattedCheckedTotal').text() + '   Pendiente: ' + $('#pendignAmount').text();
            },
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]
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

async function values_report_table(){
    if ($.fn.DataTable.isDataTable('#values_report_table')) {
        $('#values_report_table').DataTable().destroy();
        $('#values_report_table thead .filter').remove();
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;
    var codgas = document.getElementById('codgas').value;
    var codVal = $('#codVal').val();
    codVal = codVal ? codVal.join(',') : '';

    $('#values_report_table thead').prepend($('#values_report_table thead tr').clone().addClass('filter'));
    $('#values_report_table thead tr.filter th').each(function (index) {
        col = $('#values_report_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#values_report_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#values_report_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let values_report_table = $('#values_report_table').DataTable({
        order: [1, "asc"],
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
                'fromDate': fromDate,
                'untilDate': untilDate,
                'codgas': codgas,
                'codVal': codVal
            },
            url: '/income/values_report_table',
            timeout: 600000,
            error: function() {
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
            { data: 'Estacion' },
            { data: 'Fecha' },
            { data: 'Isla' },
            { data: 'Turno' },
            { data: 'Valor' },
            { data: 'Cantidad' },
            { data: 'Monto' },
        ],
        deferRender: true,
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
        }
    });
}

async function clients_debit_table(){
    if ($.fn.DataTable.isDataTable('#clients_debit_table')) {
        $('#clients_debit_table').DataTable().destroy();
        $('#clients_debit_table thead .filter').remove();

    }
    var status = document.getElementById('status_debit')
        ? document.getElementById('status_debit').value
        : document.getElementById('status').value;

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

////////////////////////

// let cashTable = null;

// $(function () {
//   cashTable = $('#cash_invoices_table').DataTable({
//     responsive: true,
//     destroy: true,
//     columns: [
//       { data: 'codcli' },
//       { data: 'cliente' },
//       {
//         data: 'monto',
//         render: d => Number(d).toLocaleString(undefined, {
//           minimumFractionDigits: 2,
//           maximumFractionDigits: 2
//         })
//       }
//     ]
//   });
// });

// function setLoading(isLoading) {
//   const $btn = $('#search_cash_invoices_table');
//   $btn.prop('disabled', isLoading);
//   $btn.text(isLoading ? 'Cargando…' : 'Generar Reporte');
// }

// window.cash_invoices_table = async function () {
//   const from  = $('#from').val();
//   const until = $('#until').val();

//   setLoading(true);
//   try {
//     const res = await $.ajax({
//       url: '/income/cash_invoices_table',
//       method: 'POST',
//       dataType: 'json',
//       data: {
//         from,
//         until,
//       }
//     });


//     if (!res || typeof res !== 'object' || !Array.isArray(res.data)) {
//       console.error('Respuesta inesperada:', res);
//       alert('Respuesta inesperada del servidor. Revisa la consola.');
//       return;
//     }

//     cashTable.clear().rows.add(res.data).draw();
//   } catch (jqXHR) {
//     console.group('cash_invoices_table AJAX error');
//     console.error('status:', jqXHR.status);
//     console.error('statusText:', jqXHR.statusText);
//     console.error('responseText:', jqXHR.responseText);
//     console.error('responseJSON:', jqXHR.responseJSON);
//     console.groupEnd();

//     let msg = 'No se pudo generar el reporte.';
//     if (jqXHR.responseJSON?.error) msg += ` ${jqXHR.responseJSON.error}`;
//     alert(msg);
//   } finally {
//     setLoading(false);
//   }
// };

async function cash_invoices_table(){
    if ($.fn.DataTable.isDataTable('#cash_invoices_table')) {
        $('#cash_invoices_table').DataTable().destroy();
        $('#cash_invoices_table thead .filter').remove();
    }
    const from  = $('#from').val();
    const until = $('#until').val();

    $('#cash_invoices_table thead').prepend($('#cash_invoices_table thead tr').clone().addClass('filter'));
    $('#cash_invoices_table thead tr.filter th').each(function (index) {
        const col = $('#cash_invoices_table thead th').length/2;
        if (index < col ) {
            const title = $(this).text();
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" '+ title +'" />');
        }
    });
    $('#cash_invoices_table thead tr.filter th input').on('keyup change', function () {
        const index = $(this).parent().index();
        const table = $('#cash_invoices_table').DataTable();
        table.column(index).search(this.value).draw();
    });

    let dt = $('#cash_invoices_table').DataTable({
        order: [3, "desc"], // ahora Monto es la columna 3
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        buttons: [
            { extend: 'excel', className: 'btn btn-success', text: ' Excel' }
        ],
        ajax: {
            method: 'POST',
            data: { from, until },
            url: '/income/cash_invoices_table',
            timeout: 600000,
            error: function() {
                $('#cash_invoices_table').waitMe('hide');
                $('.table-responsive').removeClass('loading');
                alertify.myAlert(`
                    <div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                    </div>
                `);
            },
            beforeSend: function() { $('.table-responsive').addClass('loading'); }
        },
        columns: [
            { data: 'codcli',      className:'text-nowrap' },
            { data: 'cliente' },
            { data: 'n_despachos', render: $.fn.dataTable.render.number(',','.',0), className: 'text-center' },
            { data: 'monto',       render: $.fn.dataTable.render.number(',','.',2) }
        ],
        deferRender: true,
        createdRow: function (row, data, dataIndex) {},
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {}
    });
}

$('#dispatches_table_2').DataTable({
    order: [2, "desc"],  // Ordenar por Fecha de Transacción de manera descendente
    scrollY: '400px',
    scrollX: true,
    scrollCollapse: true,
    paging: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    buttons: [
        { extend: 'excel', className: 'btn btn-success', text: 'Exportar a Excel' }
    ],
    ajax: {
        method: 'POST',
        data: { from: $('#from').val(), until: $('#until').val() },
        url: 'income/datatables_dispatches_invoiced',
        error: function() {
            alert('Error al cargar los datos');
        }
    },
    columns: [
        { data: 'nrofac' },
        { data: 'nrotrn' },
        { data: 'fchtrn' },
        { data: 'cliente' },
        { data: 'islaDen' },
        { data: 'Estacion' },
        { data: 'serie' },
        { data: 'conceptofac' },
        { data: 'monto', render: $.fn.dataTable.render.number(',', '.', 2) }
    ]
});


async function invoice_client_desp(){
    if ($.fn.DataTable.isDataTable('#invoice_client_desp')) {
        $('#invoice_client_desp').DataTable().destroy();
        $('#invoice_client_desp thead .filter').remove();
    }
    const from  = $('#from2').val();
    const until = $('#until2').val();

    $('#invoice_client_desp thead').prepend($('#invoice_client_desp thead tr').clone().addClass('filter'));
    $('#invoice_client_desp thead tr.filter th').each(function (index) {
        const col = $('#invoice_client_desp thead th').length/2;
        if (index < col ) {
            const title = $(this).text();
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" '+ title +'" />');
        }
    });
    $('#invoice_client_desp thead tr.filter th input').on('keyup change', function () {
        const index = $(this).parent().index();
        const table = $('#invoice_client_desp').DataTable();
        table.column(index).search(this.value).draw();
    });

    let dt = $('#invoice_client_desp').DataTable({
        order: [2, "desc"], // sigue ordenando por Cliente
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 100,
        buttons: [
            { extend: 'excel', className: 'btn btn-success', text: ' Excel' }
        ],
        ajax: {
            method: 'POST',
            data: { from, until },
            url: '/income/invoice_client_desp',
            timeout: 600000,
            dataSrc: function(json) {
                if (json.data && json.data.length > 0) {
                    generateClientStatsCards(json.data);
                }
                return json.data;
            },
            error: function() {
                $('#invoice_client_desp').waitMe('hide');
                $('.table-responsive').removeClass('loading');
                $('#client-stats-cards').html(`
                    <div class="col-12 text-center text-muted">
                        <p>No hay datos para mostrar estadísticas</p>
                    </div>
                `);
                alertify.myAlert(`
                    <div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                    </div>
                `);
            },
            beforeSend: function() {
                $('.table-responsive').addClass('loading');
                $('#client-stats-cards').html(`
                    <div class="col-12 text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <p class="mt-2">Procesando estadísticas...</p>
                    </div>
                `);
            }
        },
        columns: [
            { data: 'fecha',       className:'text-nowrap' },
            { data: 'codcli',      className:'text-nowrap' },
            { data: 'cliente' },
            { data: 'monto',       render: $.fn.dataTable.render.number(',','.',2) },
            { data: 'metodo_pago' },
            { data: 'estacion' },
            { data: 'factura' }
        ],
        deferRender: true,
        createdRow: function (row, data, dataIndex) {},
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {}
    });
}




///////////////////

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

// ── Clientes Crédito ────────────────────────────────────────────────────────

async function clients_credit_table() {
    if ($.fn.DataTable.isDataTable('#clients_credit_table')) {
        $('#clients_credit_table').DataTable().destroy();
        $('#clients_credit_table thead .filter').remove();
    }
    var status = document.getElementById('status_credit').value;

    $('#clients_credit_table thead').prepend($('#clients_credit_table thead tr').clone().addClass('filter'));
    $('#clients_credit_table thead tr.filter th').each(function (index) {
        var col = $('#clients_credit_table thead th').length / 2;
        if (index < col) {
            var title = $(this).text();
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#clients_credit_table thead tr.filter th input').on('keyup change', function () {
        $('#clients_credit_table').DataTable()
            .column($(this).parent().index())
            .search(this.value).draw();
    });

    $('#clients_credit_table').DataTable({
        order: [[1, 'asc']],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        paging: true,
        pageLength: 100,
        buttons: [{ extend: 'excel', className: 'btn btn-success', text: ' Excel' }],
        ajax: {
            method: 'POST',
            data: { 'status': status },
            url: '/income/clients_credit_table',
            timeout: 600000,
            error: function () {
                $('.table-responsive').removeClass('loading');
                alertify.myAlert('<div class="text-center text-danger"><h4>¡Error!</h4><p>No existen registros.</p></div>');
            },
            beforeSend: function () { $('.table-responsive').addClass('loading'); }
        },
        columns: [
            { data: 'cod' },
            { data: 'den', className: 'text-nowrap' },
            { data: 'cresdo', render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
            { data: 'mtoasg', render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
            { data: 'cndpag', className: 'text-center' },
            { data: 'status', className: 'text-center' },
            { data: 'dom' },
            { data: 'rfc' },
        ],
        deferRender: true,
        initComplete: function () { $('.table-responsive').removeClass('loading'); }
    });
}

// ── Consumos por Cliente (Débito tipval=4 / Crédito tipval=3) ───────────────

async function clients_dispatches_table(tipo) {
    var tableId  = tipo === 'debit' ? 'debit_consumos_table' : 'credit_consumos_table';
    var from     = tipo === 'debit' ? $('#from_debit').val()     : $('#from_credit').val();
    var until    = tipo === 'debit' ? $('#until_debit').val()    : $('#until_credit').val();
    var codcli   = tipo === 'debit' ? $('#cliente_debit').val()  : $('#cliente_credit').val();
    var tipval   = tipo === 'debit' ? 4 : 3;

    if (!codcli) {
        alertify.myAlert('<div class="text-center text-warning"><h5>Selecciona un cliente</h5><p>Debes elegir un cliente antes de consultar.</p></div>');
        return;
    }

    if ($.fn.DataTable.isDataTable('#' + tableId)) {
        $('#' + tableId).DataTable().destroy();
        $('#' + tableId + ' thead .filter').remove();
    }

    $('#' + tableId + ' thead').prepend($('#' + tableId + ' thead tr').clone().addClass('filter'));
    $('#' + tableId + ' thead tr.filter th').each(function (index) {
        var col = $('#' + tableId + ' thead th').length / 2;
        if (index < col) {
            var title = $(this).text();
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#' + tableId + ' thead tr.filter th input').on('keyup change', function () {
        $('#' + tableId).DataTable()
            .column($(this).parent().index())
            .search(this.value).draw();
    });

    $('#' + tableId).DataTable({
        order: [[0, 'asc'], [2, 'asc']],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        paging: true,
        pageLength: 100,
        buttons: [{ extend: 'excel', className: 'btn btn-success', text: ' Excel' }],
        ajax: {
            method: 'POST',
            data: { 'from': from, 'until': until, 'codcli': codcli, 'tipval': tipval },
            url: '/income/clients_dispatches_table',
            timeout: 600000,
            error: function () {
                $('.table-responsive').removeClass('loading');
                alertify.myAlert('<div class="text-center text-danger"><h4>¡Error!</h4><p>No existen registros con los parámetros dados.</p></div>');
            },
            beforeSend: function () { $('.table-responsive').addClass('loading'); }
        },
        columns: [
            { data: 'Cliente',  className: 'text-nowrap' },
            { data: 'codcli' },
            { data: 'Fecha',    className: 'text-center' },
            { data: 'hratrn',   className: 'text-center', render: function(d) {
                if (!d) return '';
                var n = parseInt(d, 10);
                if (isNaN(n)) return d;
                var h = Math.floor(n / 100), m = n % 100, ap = h >= 12 ? 'PM' : 'AM';
                h = h % 12 || 12;
                return h.toString().padStart(2,'0') + ':' + m.toString().padStart(2,'0') + ' ' + ap;
            }},
            { data: 'nrotrn',   className: 'text-center' },
            { data: 'Estacion', className: 'text-nowrap' },
            { data: 'Litros',   render: $.fn.dataTable.render.number(',', '.', 3, ''), className: 'text-end' },
            { data: 'Monto',    render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-end' },
            { data: 'producto' },
        ],
        deferRender: true,
        initComplete: function () { $('.table-responsive').removeClass('loading'); },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();
            var litrosTotal = api.column(6, { page: 'current' }).data().reduce(function (a, b) { return (parseFloat(a)||0) + (parseFloat(b)||0); }, 0);
            var montoTotal  = api.column(7, { page: 'current' }).data().reduce(function (a, b) { return (parseFloat(a)||0) + (parseFloat(b)||0); }, 0);
            $(api.column(6).footer()).html(litrosTotal.toLocaleString('es-MX', {minimumFractionDigits:3}));
            $(api.column(7).footer()).html('$' + montoTotal.toLocaleString('es-MX', {minimumFractionDigits:2}));
        }
    });
}

// ── Estado de Cuenta por Cliente (Débito / Crédito) ─────────────────────────

async function account_statement_table(tipo) {
    var sfx     = tipo === 'debit' ? 'debit' : 'credit';
    var from    = $('#from_edo_' + sfx).val();
    var until   = $('#until_edo_' + sfx).val();
    var codcli  = $('#cliente_edo_' + sfx).val();

    if (codcli === null || codcli === undefined || codcli === '') {
        alertify.myAlert('<div class="text-center text-warning"><h5>Selecciona un cliente</h5><p>Debes elegir un cliente (o "Todos") antes de consultar.</p></div>');
        return;
    }

    var allMode = codcli === '0';
    var tableId = 'edo_' + sfx + (allMode ? '_summary_table' : '_table');

    // Alternar entre la tabla de detalle y la de resumen
    $('#edo_' + sfx + '_detail_card').toggle(!allMode);
    $('#edo_' + sfx + '_summary_card').toggle(allMode);
    if (allMode) { $('#summary_edo_' + sfx).hide(); }

    if ($.fn.DataTable.isDataTable('#' + tableId)) {
        $('#' + tableId).DataTable().destroy();
        $('#' + tableId + ' thead .filter').remove();
    }

    $('#' + tableId + ' thead').prepend($('#' + tableId + ' thead tr').clone().addClass('filter'));
    $('#' + tableId + ' thead tr.filter th').each(function (index) {
        var col = $('#' + tableId + ' thead th').length / 2;
        if (index < col) {
            var title = $(this).text();
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#' + tableId + ' thead tr.filter th input').on('keyup change', function () {
        $('#' + tableId).DataTable()
            .column($(this).parent().index())
            .search(this.value).draw();
    });

    var fmtMoney = function (v) {
        return '$' + (parseFloat(v) || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    var columnsCredit = [
        { data: 'Fecha',        className: 'text-center' },
        { data: 'Vencimiento',  className: 'text-center' },
        { data: 'Movimiento',   className: 'text-nowrap' },
        { data: 'Documento',    className: 'text-nowrap' },
        { data: 'Estacion',     className: 'text-nowrap' },
        { data: 'Importe',      render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
        { data: 'Pendiente',    render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
        { data: 'Estatus',      className: 'text-center' },
        { data: 'SaldoCorrido', render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
    ];
    var columnsDebit = [
        { data: 'Fecha',        className: 'text-center' },
        { data: 'Movimiento',   className: 'text-nowrap' },
        { data: 'Detalle',      className: 'text-nowrap' },
        { data: 'Estacion',     className: 'text-nowrap' },
        { data: 'Monto',        render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
        { data: 'SaldoCorrido', render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
    ];
    // Monto clicable que abre el drill-down (solo si hay importe)
    var drillRender = function (drill) {
        var numFmt = $.fn.dataTable.render.number(',', '.', 2, '$');
        return function (d, type) {
            if (type !== 'display') return d;
            var f = numFmt.display(d);
            if (!parseFloat(d)) return f;
            return '<a href="#" class="edo-drill" data-drill="' + drill + '" title="Ver detalle">' + f + ' <i class="fas fa-list-ul"></i></a>';
        };
    };
    var columnsSummaryCredit = [
        { data: 'CodCliente' },
        { data: 'Cliente',      className: 'text-nowrap' },
        { data: 'SaldoInicial', render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
        { data: 'Cargos',       render: drillRender('cargos'),   className: 'text-nowrap text-end' },
        { data: 'Consumos',     render: drillRender('consumos'), className: 'text-nowrap text-end' },
        { data: 'Abonos',       render: drillRender('abonos'),   className: 'text-nowrap text-end' },
        { data: 'Diferencia',   render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
        { data: 'SaldoFinal',   render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
        { data: 'SaldoSistema', render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
        { data: 'PorVencer',    render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
        { data: 'Vencido',      render: drillRender('vencido'), className: 'text-nowrap text-end' },
    ];
    var columnsSummaryDebit = [
        { data: 'CodCliente' },
        { data: 'Cliente',      className: 'text-nowrap' },
        { data: 'Anticipos',    render: drillRender('anticipos'), className: 'text-nowrap text-end' },
        { data: 'Consumos',     render: drillRender('consumos'),  className: 'text-nowrap text-end' },
        { data: 'Diferencia',   render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
        { data: 'SaldoSistema', render: $.fn.dataTable.render.number(',', '.', 2, '$'), className: 'text-nowrap text-end' },
    ];

    var columns;
    if (allMode) {
        columns = tipo === 'debit' ? columnsSummaryDebit : columnsSummaryCredit;
    } else {
        columns = tipo === 'debit' ? columnsDebit : columnsCredit;
    }
    // Columnas de dinero a totalizar en el pie (solo modo resumen)
    var moneyCols = tipo === 'debit' ? [2, 3, 4, 5] : [2, 3, 4, 5, 6, 7, 8, 9, 10];

    $('#' + tableId).DataTable({
        ordering: allMode,      // en detalle, el orden cronológico es lo que da sentido al saldo corrido
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        paging: true,
        pageLength: 100,
        buttons: [{ extend: 'excel', className: 'btn btn-success', text: ' Excel' }],
        ajax: {
            method: 'POST',
            data: { 'tipo': tipo, 'codcli': codcli, 'from': from, 'until': until },
            url: '/income/account_statement_table',
            timeout: 600000,
            dataSrc: function (json) {
                var s = json.summary;
                if (s) {
                    $('#sum_ini_' + sfx).text(fmtMoney(s.saldo_inicial));
                    $('#sum_cargos_' + sfx).text(fmtMoney(s.cargos));
                    $('#sum_abonos_' + sfx).text(fmtMoney(s.abonos));
                    $('#sum_final_' + sfx).text(fmtMoney(s.saldo_final));
                    $('#sum_sistema_' + sfx).text(fmtMoney(s.saldo_sistema));
                    $('#summary_edo_' + sfx).show();
                }
                return json.data || [];
            },
            error: function () {
                $('.table-responsive').removeClass('loading');
                alertify.myAlert('<div class="text-center text-danger"><h4>¡Error!</h4><p>No existen registros con los parámetros dados.</p></div>');
            },
            beforeSend: function () { $('.table-responsive').addClass('loading'); }
        },
        columns: columns,
        deferRender: true,
        initComplete: function () { $('.table-responsive').removeClass('loading'); },
        footerCallback: function () {
            if (!allMode) return;
            var api = this.api();
            moneyCols.forEach(function (idx) {
                var total = api.column(idx, { search: 'applied' }).data()
                    .reduce(function (a, b) { return (parseFloat(a) || 0) + (parseFloat(b) || 0); }, 0);
                $(api.column(idx).footer()).html('$' + total.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            });
        }
    });
}

// ── Drill-down del resumen débito: anticipos / consumos de un cliente ───────

$(document).on('click', '#edo_debit_summary_table .edo-drill', function (e) {
    e.preventDefault();
    var row = $('#edo_debit_summary_table').DataTable().row($(this).closest('tr')).data();
    if (row) edo_debit_drill($(this).data('drill'), row.CodCliente, row.Cliente);
});

// Modal de consumos (despachos) compartido: débito tipval=4 / crédito tipval=3
function edo_consumos_modal(codcli, cliente, from, until, tipval) {
    var money = function (v) {
        return '$' + (parseFloat(v) || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };
    var headers = ['Fecha', 'Hora', 'Despacho', 'Estación', 'Producto', 'Litros', 'Monto'];

    $('#modal_edo_drill_title').html('Consumos (Despachos) — ' + cliente + ' <small class="text-muted">(' + from + ' → ' + until + ')</small>');
    $('#modal_edo_drill_table thead').html('<tr><th>' + headers.join('</th><th>') + '</th></tr>');
    $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + headers.length + '" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Cargando…</td></tr>');
    $('#modal_edo_drill_table tfoot').empty();
    $('#modal_edo_drill').modal('show');

    $.post('/income/clients_dispatches_table', { codcli: codcli, from: from, until: until, tipval: tipval }, function (json) {
        var rows = (json && json.data) || [];
        if (!rows.length) {
            $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + headers.length + '" class="text-center py-3">Sin registros en el periodo.</td></tr>');
            return;
        }
        $('#modal_edo_drill_table tbody').html(rows.map(function (r) {
            var h = parseInt(r.hratrn, 10), hora = '';
            if (!isNaN(h)) {
                var hh = Math.floor(h / 100), mm = h % 100, ap = hh >= 12 ? 'PM' : 'AM';
                hh = hh % 12 || 12;
                hora = String(hh).padStart(2, '0') + ':' + String(mm).padStart(2, '0') + ' ' + ap;
            }
            return '<tr><td>' + r.Fecha + '</td><td>' + hora + '</td><td>' + r.nrotrn + '</td>' +
                   '<td>' + (r.Estacion || '') + '</td><td>' + (r.producto || '') + '</td>' +
                   '<td class="text-end">' + (parseFloat(r.Litros) || 0).toLocaleString('es-MX', { minimumFractionDigits: 3 }) + '</td>' +
                   '<td class="text-end">' + money(r.Monto) + '</td></tr>';
        }).join(''));
        var lt = rows.reduce(function (a, r) { return a + (parseFloat(r.Litros) || 0); }, 0);
        var mt = rows.reduce(function (a, r) { return a + (parseFloat(r.Monto) || 0); }, 0);
        $('#modal_edo_drill_table tfoot').html(
            '<tr><th colspan="5" class="text-end">Total</th>' +
            '<th class="text-end">' + lt.toLocaleString('es-MX', { minimumFractionDigits: 3 }) + '</th>' +
            '<th class="text-end">' + money(mt) + '</th></tr>'
        );
    }, 'json').fail(function () {
        $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + headers.length + '" class="text-center py-3 text-danger">Error al consultar.</td></tr>');
    });
}

// ── Drill-down del resumen crédito: cargos / consumos / abonos de un cliente ─

$(document).on('click', '#edo_credit_summary_table .edo-drill', function (e) {
    e.preventDefault();
    var row = $('#edo_credit_summary_table').DataTable().row($(this).closest('tr')).data();
    if (row) edo_credit_drill($(this).data('drill'), row.CodCliente, row.Cliente);
});

function edo_credit_drill(drill, codcli, cliente) {
    var from  = $('#from_edo_credit').val();
    var until = $('#until_edo_credit').val();

    if (drill === 'consumos') {
        edo_consumos_modal(codcli, cliente, from, until, 3);
        return;
    }

    var money = function (v) {
        return '$' + (parseFloat(v) || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    if (drill === 'vencido') {
        var hdrs = ['Fecha', 'Vencimiento', 'Documento', 'Estación', 'Importe', 'Pendiente', 'Días Vencido'];
        $('#modal_edo_drill_title').html('Facturas vencidas — ' + cliente + ' <small class="text-muted">(al día de hoy)</small>');
        $('#modal_edo_drill_table thead').html('<tr><th>' + hdrs.join('</th><th>') + '</th></tr>');
        $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + hdrs.length + '" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Cargando…</td></tr>');
        $('#modal_edo_drill_table tfoot').empty();
        $('#modal_edo_drill').modal('show');

        $.post('/income/client_overdue_table', { codcli: codcli }, function (json) {
            var rows = (json && json.data) || [];
            if (!rows.length) {
                $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + hdrs.length + '" class="text-center py-3">Sin facturas vencidas.</td></tr>');
                return;
            }
            $('#modal_edo_drill_table tbody').html(rows.map(function (r) {
                return '<tr><td>' + r.Fecha + '</td><td>' + r.Vencimiento + '</td><td>' + r.Documento + '</td>' +
                       '<td>' + (r.Estacion || '') + '</td>' +
                       '<td class="text-end">' + money(r.Importe) + '</td>' +
                       '<td class="text-end">' + money(r.Pendiente) + '</td>' +
                       '<td class="text-center">' + r.DiasVencido + '</td></tr>';
            }).join(''));
            var tp = rows.reduce(function (a, r) { return a + (parseFloat(r.Pendiente) || 0); }, 0);
            $('#modal_edo_drill_table tfoot').html(
                '<tr><th colspan="5" class="text-end">Total vencido</th>' +
                '<th class="text-end">' + money(tp) + '</th><th></th></tr>'
            );
        }, 'json').fail(function () {
            $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + hdrs.length + '" class="text-center py-3 text-danger">Error al consultar.</td></tr>');
        });
        return;
    }
    var esCargos = drill === 'cargos';
    var titulo   = (esCargos ? 'Cargos (facturas) — ' : 'Abonos (pagos / notas de crédito) — ') + cliente;
    var headers  = esCargos
        ? ['Fecha', 'Vencimiento', 'Documento', 'Estación', 'Importe', 'Pendiente', 'Estatus']
        : ['Fecha', 'Vencimiento', 'Documento', 'Aplicada a Factura', 'Estación', 'Importe', 'Pendiente', 'Estatus'];

    $('#modal_edo_drill_title').html(titulo + ' <small class="text-muted">(' + from + ' → ' + until + ')</small>');
    $('#modal_edo_drill_table thead').html('<tr><th>' + headers.join('</th><th>') + '</th></tr>');
    $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + headers.length + '" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Cargando…</td></tr>');
    $('#modal_edo_drill_table tfoot').empty();
    $('#modal_edo_drill').modal('show');

    // Reutiliza el endpoint del estado de cuenta y filtra la clase de movimiento aquí
    $.post('/income/account_statement_table', { tipo: 'credit', codcli: codcli, from: from, until: until }, function (json) {
        var rows = ((json && json.data) || []).filter(function (r) {
            return (r.Movimiento || '').indexOf(esCargos ? 'CARGO' : 'ABONO') === 0;
        });
        if (!rows.length) {
            $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + headers.length + '" class="text-center py-3">Sin registros en el periodo.</td></tr>');
            return;
        }
        $('#modal_edo_drill_table tbody').html(rows.map(function (r) {
            return '<tr><td>' + r.Fecha + '</td><td>' + r.Vencimiento + '</td><td>' + r.Documento + '</td>' +
                   (esCargos ? '' : '<td>' + (r.Referencia || '') + '</td>') +
                   '<td>' + (r.Estacion || '') + '</td>' +
                   '<td class="text-end">' + money(r.Importe) + '</td>' +
                   '<td class="text-end">' + money(r.Pendiente) + '</td>' +
                   '<td class="text-center">' + r.Estatus + '</td></tr>';
        }).join(''));
        var ti = rows.reduce(function (a, r) { return a + (parseFloat(r.Importe)   || 0); }, 0);
        var tp = rows.reduce(function (a, r) { return a + (parseFloat(r.Pendiente) || 0); }, 0);
        $('#modal_edo_drill_table tfoot').html(
            '<tr><th colspan="' + (esCargos ? 4 : 5) + '" class="text-end">Total</th>' +
            '<th class="text-end">' + money(ti) + '</th>' +
            '<th class="text-end">' + money(tp) + '</th><th></th></tr>'
        );
    }, 'json').fail(function () {
        $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + headers.length + '" class="text-center py-3 text-danger">Error al consultar.</td></tr>');
    });
}

function edo_debit_drill(drill, codcli, cliente) {
    var from  = $('#from_edo_debit').val();
    var until = $('#until_edo_debit').val();
    var money = function (v) {
        return '$' + (parseFloat(v) || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    if (drill === 'consumos') {
        edo_consumos_modal(codcli, cliente, from, until, 4);
        return;
    }

    var cfg = {
        titulo : 'Anticipos — ' + cliente,
        url    : '/income/client_anticipos_table',
        data   : { codcli: codcli, from: from, until: until },
        headers: ['Fecha', 'Factura', 'Estación', 'Subtotal', 'IVA', 'Total'],
        row    : function (r) {
            return '<tr><td>' + r.Fecha + '</td><td>' + r.Factura + '</td><td>' + (r.Estacion || '') + '</td>' +
                   '<td class="text-end">' + money(r.Subtotal) + '</td>' +
                   '<td class="text-end">' + money(r.IVA) + '</td>' +
                   '<td class="text-end">' + money(r.Total) + '</td></tr>';
        },
        total  : function (rows) {
            var t = rows.reduce(function (a, r) { return a + (parseFloat(r.Total) || 0); }, 0);
            return '<tr><th colspan="5" class="text-end">Total</th><th class="text-end">' + money(t) + '</th></tr>';
        }
    };

    $('#modal_edo_drill_title').html(cfg.titulo + ' <small class="text-muted">(' + from + ' → ' + until + ')</small>');
    $('#modal_edo_drill_table thead').html('<tr><th>' + cfg.headers.join('</th><th>') + '</th></tr>');
    $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + cfg.headers.length + '" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Cargando…</td></tr>');
    $('#modal_edo_drill_table tfoot').empty();
    $('#modal_edo_drill').modal('show');

    $.post(cfg.url, cfg.data, function (json) {
        var rows = (json && json.data) || [];
        if (!rows.length) {
            $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + cfg.headers.length + '" class="text-center py-3">Sin registros en el periodo.</td></tr>');
            return;
        }
        $('#modal_edo_drill_table tbody').html(rows.map(cfg.row).join(''));
        $('#modal_edo_drill_table tfoot').html(cfg.total(rows));
    }, 'json').fail(function () {
        $('#modal_edo_drill_table tbody').html('<tr><td colspan="' + cfg.headers.length + '" class="text-center py-3 text-danger">Error al consultar.</td></tr>');
    });
}

// cargarGraficaDesdeController();

/* =====================================================================
   Expediente de facturas  (/income/expediente_facturas)
   ---------------------------------------------------------------------
   Patrón A de este archivo: función invocada por el botón, que destruye
   y reconstruye la tabla. NO declarar la DataTable a nivel superior:
   income.js se carga en todas las páginas de Ingresos y esos bloques
   correrían aunque la tabla no exista en el DOM.

   Las columnas NO se listan aquí: vienen de window.EXPEDIENTE_COLS, que
   la plantilla emite desde Income::EXPEDIENTE_COLUMNS (una sola fuente
   de verdad para PHP, Twig y JS).
   ===================================================================== */
async function expediente_facturas_table() {
    var codgas = $('#codgas').val();
    var codopr = $('#codopr').val();
    var from   = $('#from').val();
    var until  = $('#until').val();

    if (!codgas || !codopr) {
        alertify.myAlert(
            `<div class="container text-center text-warning">
                <h4 class="mt-2 text-warning">Faltan datos</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Selecciona la estación y el cliente antes de consultar.</p>
            </div>`
        );
        return;
    }

    if (from && until && from > until) {
        alertify.myAlert(
            `<div class="container text-center text-warning">
                <h4 class="mt-2 text-warning">Rango inválido</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">La fecha "Desde" no puede ser posterior a la fecha "Hasta".</p>
            </div>`
        );
        return;
    }

    if ($.fn.DataTable.isDataTable('#expediente_facturas_table')) {
        $('#expediente_facturas_table').DataTable().destroy();
    }

    var cols = (window.EXPEDIENTE_COLS || []).map(function (c) {
        // defaultContent: el SP devuelve NULL en varias columnas (satext,
        // satnro, Vehiculos...). Sin esto DataTables lanza warning y pinta
        // "undefined" en la celda.
        var col = { data: c.key, defaultContent: '' };

        if (c.type === 'money') {
            col.render = $.fn.dataTable.render.number(',', '.', 2, '$');
            col.className = 'text-end text-nowrap';
        } else if (c.type === 'dec3') {
            col.render = $.fn.dataTable.render.number(',', '.', 3, '');
            col.className = 'text-end text-nowrap';
        } else if (c.type === 'dec2') {
            col.render = $.fn.dataTable.render.number(',', '.', 2, '');
            col.className = 'text-end text-nowrap';
        } else if (c.type === 'int' || c.type === 'date') {
            col.className = 'text-center text-nowrap';
        } else {
            // Columnas de texto: varias son listas separadas por comas y muy
            // largas, se dejan envolver en lugar de estirar la tabla.
            col.className = 'cell-list';
        }
        return col;
    });

    $('#expediente_facturas_table').DataTable({
        columns: cols,
        order: [[0, 'asc']],
        scrollX: true,
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        paging: true,
        pageLength: 50,
        deferRender: true,
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-success',
                text: ' Excel',
                title: 'Expediente de facturas',
                // Respeta lo que el usuario dejó visible con colvis: con 48
                // columnas, exportarlas todas produce un Excel ilegible.
                exportOptions: { columns: ':visible' }
            },
            {
                extend: 'colvis',
                className: 'btn btn-secondary',
                text: ' Columnas'
            }
        ],
        ajax: {
            method: 'POST',
            url: '/income/expediente_facturas_table',
            data: {
                'codgas': codgas,
                'codopr': codopr,
                'from': from,
                'until': until
            },
            timeout: 600000,
            beforeSend: function () {
                $('.table-responsive').addClass('loading');
            },
            error: function () {
                $('.table-responsive').removeClass('loading');
                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No se pudo obtener el expediente de facturas. Intentelo nuevamente.</p>
                    </div>`
                );
            }
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // scrollX calcula anchos antes de que la pestaña termine de pintar;
            // este ajuste alinea encabezados con el cuerpo.
            this.api().columns.adjust();
        }
    });
}
