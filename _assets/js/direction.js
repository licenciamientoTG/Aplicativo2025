$(document).ready(function() {


});


let monthly_dollar_sales_report_table = $('#monthly_dollar_sales_report_table').DataTable({
    // order: [0, "desc"],
    scrollY: '700px',
    scrollX: true,
    scrollCollapse: true,
    paging: false,
    ordering: false,
    colReorder: false,
    dom: '<"top"Bf>rt<"bottom"lip>',
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
        },
        {
            extend: 'pdf', // Agrega el botón de exportación a PDF
            className: 'd-none',
            text: 'PDF',
        },
        {
            extend: 'print', // Agrega el botón de impresión
            className: 'd-none',
        }
    ],
    ajax: {
        method: 'POST',
        url: '/direction/monthly_dollar_sales_report_table',
        error: function() {
            $('#monthly_dollar_sales_report_table').waitMe('hide');
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
    columns: [
        { "data": "estacion", "title": "Estación" },           // Columna de la estación
        { "data": "año", "title": "Año" },                     // Columna del año
        { "data": "EneroDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },  // Enero - Dólares
        { "data": "EneroMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },    // Enero - Monto
        { "data": "FebreroDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) }, // Febrero - Dólares
        { "data": "FebreroMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },   // Febrero - Monto
        { "data": "MarzoDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },   // Marzo - Dólares
        { "data": "MarzoMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },     // Marzo - Monto
        { "data": "AbrilDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },   // Abril - Dólares
        { "data": "AbrilMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },     // Abril - Monto
        { "data": "MayoDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },     // Mayo - Dólares
        { "data": "MayoMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },       // Mayo - Monto
        { "data": "JunioDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },   // Junio - Dólares
        { "data": "JunioMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },     // Junio - Monto
        { "data": "JulioDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },   // Julio - Dólares
        { "data": "JulioMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },     // Julio - Monto
        { "data": "AgostoDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) }, // Agosto - Dólares
        { "data": "AgostoMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },   // Agosto - Monto
        { "data": "SeptiembreDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) }, // Septiembre - Dólares
        { "data": "SeptiembreMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },   // Septiembre - Monto
        { "data": "OctubreDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },   // Octubre - Dólares
        { "data": "OctubreMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },     // Octubre - Monto
        { "data": "NoviembreDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' )},   // Noviembre - Dólares
        { "data": "NoviembreMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },     // Noviembre - Monto
        { "data": "DiciembreDolares",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) },   // Diciembre - Dólares
        { "data": "DiciembreMontos",'render': $.fn.dataTable.render.number( ',', '.', 0, '$' ) }      // Diciembre - Monto
    ],
    columnDefs: [
        {
           targets: [4,5,8,9,12,13,16,17],
           className: 'month_group',
        },

    ],
    rowId: 'estacion',
    createdRow: function (row, data, dataIndex) {
        $('td', row).eq(0).addClass('table-secondary text-nowrap');
        $('td', row).eq(1).addClass('table-secondary');
        $('monthly_dollar_sales_report_table').addClass('text-end');
    },
    drawCallback: function (settings) {
        var api = this.api();
        var rows = api.rows({ page: 'current' }).nodes();
        var last = null;
        var groupTotals = {};
        var lastEstacion = null;
        api.column(0, { page: 'current' }).data().each(function (estacion, i) {
            if (lastEstacion === estacion) {
                $(rows).eq(i).find('td').eq(0).html(''); // Si es la misma estación, deja la celda vacía
            } else {
                lastEstacion = estacion; // Si es una nueva estación, actualiza `lastEstacion`
            }
        });

        // Inicializa los totales por columna
        api.columns().every(function () {
            groupTotals[this.index()] = 0;
        });

        api.column(0, { page: 'current' }).data().each(function (group, i) {
            // Sumar valores para el grupo actual
            if (last === null) {
                last = group;
            }

            if (last !== group) {
                // Insertar fila de grupo con totales
                $(rows)
                    .eq(i - 1)
                    .after(
                        '<tr class="group bg-warning_1">'+
                            '<td>Total</td>'+
                            '<td></td>'+
                            '<td>'+ number_format(groupTotals[2] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[3] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[4] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[5] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[6] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[7] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[8] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[9] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[10] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[11] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[12] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[13] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[14] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[15] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[16] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[17] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[18] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[19] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[20] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[21] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[22] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[23] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[24] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[25] || 0, 0, '.', ',') +'</td>'+
                        '</tr>'
                    );

                // Reinicia los totales para el siguiente grupo
                last = group;
                groupTotals = {};
            }

            // Acumula los totales por columna
            api.columns().every(function () {
                var columnIndex = this.index();
                var cellValue = parseFloat(api.cell(i, columnIndex).data()) || 0;
                groupTotals[columnIndex] = (groupTotals[columnIndex] || 0) + cellValue;
            });
        });

        // Agregar la fila de grupo al final del último grupo
        if (last !== null) {
            $(rows)
                .eq(rows.length - 1)
                .after(
                    '<tr class="group bg-warning_1">'+
                            '<td>' +last  +'</td>'+
                            '<td></td>'+
                            '<td>'+ number_format(groupTotals[2] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[3] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[4] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[5] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[6] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[7] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[8] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[9] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[10] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[11] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[12] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[13] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[14] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[15] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[16] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[17] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[18] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[19] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[20] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[21] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[22] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[23] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[24] || 0, 0, '.', ',') +'</td>'+
                            '<td>'+ number_format(groupTotals[25] || 0, 0, '.', ',') +'</td>'+
                        '</tr>'
                );
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
        window.scrollTo(0,180);

    },
    
});




// $('#filtro-monthly_dollar_sales_report_table input').on('keyup  change clear', function () {
//     monthly_dollar_sales_report_table
//         .column(0).search($('#col_id_desabasto').val().trim())
//         .column(1).search($('#col_fecha_desabasto').val().trim())
//         .column(2).search($('#col_codigo').val().trim())
//         .column(3).search($('#col_nombre').val().trim())
//         .column(4).search($('#col_producto').val().trim())
//         .column(5).search($('#col_horas').val().trim())
//         .column(6).search($('#col_razon_social').val().trim())
//         .draw();
//   });

$('.refresh_monthly_dollar_sales_report_table').on('click', function () {
    monthly_dollar_sales_report_table.clear().draw();
    monthly_dollar_sales_report_table.ajax.reload();
    $('#monthly_dollar_sales_report_table').waitMe('hide');
});

function number_format(number, decimals, dec_point, thousands_sep) {
    number = (number + '').replace(/[^0-9+\-Ee.]/g, '');
    var n = !isFinite(+number) ? 0 : +number,
        prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
        sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
        dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
        s = '',
        toFixedFix = function (n, prec) {
            var k = Math.pow(10, prec);
            return '' + Math.round(n * k) / k;
        };

    s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
    if (s[0].length > 3) {
        s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
    }
    if ((s[1] || '').length < prec) {
        s[1] = s[1] || '';
        s[1] += new Array(prec - s[1].length + 1).join('0');
    }
    return s.join(dec);
}


let cumsumption_credit_table = $('#cumsumption_credit_table').DataTable({
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
        },
        {
            extend: 'print', // Agrega el botón de impresión
            className: 'd-none',
        }
    ],
    ajax: {
        method: 'POST',
        data: {'year': $('#cumsumption_credit_table').data('year')},
        url: '/direction/cumsumption_credit_table',
        error: function() {
            $('#cumsumption_credit_table').waitMe('hide');
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
        {'data': 'cliente'},
        {'data': 'enero'},
        {'data': 'febrero'},
        {'data': 'marzo'},
        {'data': 'abril'},
        {'data': 'mayo'},
        {'data': 'junio'},
        {'data': 'julio'},
        {'data': 'agosto'},
        {'data': 'septiembre'},
        {'data': 'octubre'},
        {'data': 'noviembre'},
        {'data': 'diciembre'}
    ],
    rowId: 'Despacho',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
// $('#filtro-container input').on('keyup  change clear', function () {
//     cumsumption_credit_table
//         .column(0).search($('#FECHA').val().trim())
//         .column(1).search($('#HORA').val().trim())
//         .column(2).search($('#DESPACHO').val().trim())
//         .column(3).search($('#CODCLIENTE').val().trim())
//         .column(4).search($('#CLIENTE').val().trim())
//         .column(5).search($('#TIPO').val().trim())
//         .column(6).search($('#PLACAS').val().trim())
//         .column(7).search($('#TARJETA').val().trim())
//         .column(8).search($('#GRUPO').val().trim())
//         .column(9).search($('#DESCRIPCION').val().trim())
//         .column(10).search($('#LITROS').val().trim())
//         .column(11).search($('#MONTO').val().trim())
//         .column(12).search($('#PRODUCTO').val().trim())
//         .column(13).search($('#ESTACIÓN').val().trim())
//         .column(14).search($('#BOMBA').val().trim())
//         .column(15).search($('#INCIDENCIA').val().trim())
//         .draw();
//   });

// Agregar un evento clic de refresh
$('.refresh_cumsumption_credit_table').on('click', function () {
    cumsumption_credit_table.clear().draw();
    cumsumption_credit_table.ajax.reload();
    $('#cumsumption_credit_table').waitMe('hide');
});


//////////////////////////////////////////////////////////////////////////////////////////////////
let historic_price_table = $('#historic_price_table').DataTable({
    order: [0, "desc"],
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 25,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
        },
        {
            extend: 'pdf', // Agrega el botón de exportación a PDF
            className: 'd-none',
            text: 'PDF',
        },
        {
            extend: 'print', // Agrega el botón de impresión
            className: 'd-none',
        }
    ],
    ajax: {
        method: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),

        },
        url: '/direction/historic_price_table',
        error: function() {
            $('#historic_price_table').waitMe('hide');
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
    columns: [
        {'data': 'fecha_precio'},
        {'data': 'grupo'},
        {'data': 'precios'},
        {'data': 'producto'},
        {'data': 'plaza'}
    ],
    rowId: 'id_historico',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

$('#filtro-historic_price_table input').on('keyup  change clear', function () {
    historic_price_table
        .column(0).search($('#Fecha').val().trim())
        .column(1).search($('#Grupo').val().trim())
        .column(2).search($('#Precio').val().trim())
        .column(3).search($('#Producto').val().trim())
        .column(4).search($('#Plaza').val().trim())

        .draw();
  });

// Agregar un evento clic de refresh
$('.refresh_historic_price_table').on('click', function () {
    historic_price_table.clear().draw();
    historic_price_table.ajax.reload();
    $('#historic_price_table').waitMe('hide');
});
function download_format(){
    fetch('/direction/download_format')
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
        a.download = 'FormatoPrecio.xlsx'; // Nombre del archivo a descargar
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
    })
    .catch(error => console.error('Error:', error));

 }


 async function upload_file() {
    const fileInput = document.getElementById('file_to_upload');
    const file = fileInput.files[0]; // Obtiene el primer archivo seleccionado

    if (!file) {
        toastr.error('Por favor, selecciona un archivo.', '¡Error!', { timeOut: 3000 });
        return;
    }

    $('.heather_historic').addClass('loading');
    const formData = new FormData();
    formData.append('file_to_upload', file);

    try {
        const response = await fetch('/direction/import_file_historic_price', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        console.log('Respuesta del servidor:', data);

        if (data == 1) {
            toastr.success('Archivo subido exitosamente ', '¡Éxito!', { timeOut: 3000 });
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        } else if (data == 2) {
            toastr.error('Documento sin Fecha.', '¡Error!', { timeOut: 3000 });
            $('.heather_historic').removeClass('loading');
            fileInput.value = '';
        }
    } catch (error) {
        console.error('Error al subir el archivo:', error);
        $('.heather_historic').removeClass('loading');
        toastr.error('Hubo un problema al subir el archivo.', '¡Error!', { timeOut: 3000 });
    }
}


//////////////////////////////////////////////////////////////////////////
let historic_shortage_table = $('#historic_shortage_table').DataTable({
    order: [0, "desc"],
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 25,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
        },
        {
            extend: 'pdf', // Agrega el botón de exportación a PDF
            className: 'd-none',
            text: 'PDF',
        },
        {
            extend: 'print', // Agrega el botón de impresión
            className: 'd-none',
        }
    ],
    ajax: {
        method: 'POST',
        url: '/direction/historic_shortage_table',
        error: function() {
            $('#historic_shortage_table').waitMe('hide');
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
    columns: [
        {'data': 'id_desabasto'},
        {'data': 'fecha_desabasto'},
        {'data': 'codigo_estacion'},
        {'data': 'estacion'},
        {'data': 'producto'},
        {'data': 'horas'},
        {'data': 'razon_social'},
        {'data': 'options'}
    ],
    rowId: 'id_desabasto',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

$('#filtro-historic_shortage_table input').on('keyup  change clear', function () {
    historic_shortage_table
        .column(0).search($('#col_id_desabasto').val().trim())
        .column(1).search($('#col_fecha_desabasto').val().trim())
        .column(2).search($('#col_codigo').val().trim())
        .column(3).search($('#col_nombre').val().trim())
        .column(4).search($('#col_producto').val().trim())
        .column(5).search($('#col_horas').val().trim())
        .column(6).search($('#col_razon_social').val().trim())
        .draw();
  });

$('.refresh_historic_shortage_table').on('click', function () {
    historic_shortage_table.clear().draw();
    historic_shortage_table.ajax.reload();
    $('#historic_shortage_table').waitMe('hide');
});



async function SaveHoursShortage(){
    var fecha_desabasto = document.getElementById('fecha_desabasto').value;
    var id_estacion = document.getElementById('id_estacion').value;
    var horas = document.getElementById('horas').value;
    var id_producto = document.getElementById('id_producto').value;
    if( !fecha_desabasto || !id_estacion || !horas || !id_producto){
        alertify.myAlert(
                `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Error!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">Llene todos los campos.</p>
                </div>`
            );
            return;
    }
    try{
        $('#form_add_hours').addClass('loading');
        form_add_hours = document.getElementById('form_add_hours');
        var formData = new FormData(form_add_hours);
        let response = await fetch('/direction/SaveHoursShortage', {
            method: 'POST',
            body: formData
        });
        let data = await response.json();
        if (data === 1 ) {
            $('#form_add_hours').removeClass('loading');
            historic_shortage_table.clear().draw();
            historic_shortage_table.ajax.reload();
            $('.table-responsive').removeClass('loading');
            form_add_hours.reset();
        }else{
            alertify.myAlert(
                `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Error!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">Contacte a Soporte.</p>
                </div>`
            );
        }

    }catch (error) {
        console.error('Error:', error);
    }

}

async function delete_shortage(id_desabasto) {
    Swal.fire({
        title: "¿Desea eliminar el desabasto?",
        showCancelButton: true,
        confirmButtonText: "Eliminar",
        customClass: {
            confirmButton: 'btn btn-danger', // Clase para el botón de confirmación
            cancelButton: 'btn btn-secondary', // Clase para el botón de cancelación
        }
    }).then(async (result) => { // Usar async aquí
        if (result.isConfirmed) {
            try {
                // Realiza la petición fetch con POST
                const response = await fetch('/direction/delete_shortage', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json, text/javascript, */*',
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    credentials: 'include',
                    body: `id_desabasto=${id_desabasto}`
                });

                if (!response.ok) {
                    throw new Error('Error en la red o en el servidor');
                }

                const data = await response.json();
                console.log(data);
                if (data === 1) {
                    Swal.fire("¡Eliminado!", "El desabasto ha sido eliminado.", "success");
                    historic_shortage_table.clear().draw();
                    historic_shortage_table.ajax.reload();
                    $('.table-responsive').removeClass('loading');
                } else {
                    Swal.fire("Error", "Hubo un problema al eliminar el desabasto.", "error");
                }
            } catch (error) {
                console.error("Error:", error);
                Swal.fire("Error", "Hubo un problema con la conexión o el servidor.", "error");
            }
        } else if (result.isDismissed) {
            Swal.fire("Operación cancelada", "", "info");
        }
    });
}