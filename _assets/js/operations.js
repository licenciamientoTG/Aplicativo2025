const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]')
const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl))
// Tabla de Top Tabulators
// Table de Despachos de Crédito y Débito
let datatables_top_tabulators = $('#datatables_top_tabulators').DataTable({
    colReorder: true,
    order: [3, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Tabuladores',
            // Prevenimos la exportación de la columna de checkbox y de las acciones
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8]
            }
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

                $('#datatables_top_tabulators thead th').each(function () {
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
        url: '/operations/datatables_top_tabulators',
        error: function() {
            $('#datatables_top_tabulators').waitMe('hide');
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
        {'data': 'Id'},
        {'data': 'Nombre'},
        {'data': 'Turno'},
        {'data': 'Subcorte'},
        {'data': 'Fecha'},
        {'data': 'Usuario'},
        {'data': 'Estatus'},
        {'data': 'Productos', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$' )},
        {'data': 'Total', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$' )},
        {'data': 'Acciones'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
        if (data.Estatus === 'Cerrado') {
            $(row).find('td:eq(6)').addClass('fw-bold text-success');
        } else {
            $(row).find('td:eq(6)').addClass('fw-bold text-primary');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_top_tabulators input').on('keyup  change clear', function () {
    datatables_top_tabulators
        .column(0).search($('#FOLIO').val().trim())
        .column(1).search($('#NOMBRE').val().trim())
        .column(2).search($('#TURNO').val().trim())
        .column(3).search($('#SUBCORTE').val().trim())
        .column(4).search($('#FECHA').val().trim())
        .column(5).search($('#USUARIO').val().trim())
        .column(6).search($('#ESTATUS').val().trim())
        .column(7).search($('#PRODUCTOS').val().trim())
        .column(8).search($('#TOTAL').val().trim())
        .draw();
  });

// Agregar un evento clic de refresh
$('.refresh_datatables_top_tabulators').on('click', function () {
    datatables_top_tabulators.clear().draw();
    datatables_top_tabulators.ajax.reload();
    $('#datatables_top_tabulators').waitMe('hide');
});

// Manejar el evento de clic en los botones de píldora
$('.pill').on('click', function () {
    // Obtener el identificador de la píldora seleccionada
    let selectedTabId = $(this).attr('data-bs-target');
    let action        = $(this).attr('data-action');
    let tabid         = $(this).attr('data-tabid');
    let island        = $(this).attr('data-island');
    let estatus       = $('input#Estatus').val();
    let codigoestacion= $('input#CodigoEstacion').val();
    let fechatabular  = $('input#FechaTabular').val();
    let turno         = $('input#Turno').val();
    let islands       = $('input#Islands').val();
    let TotalProductos = $('input#TotalProductos').val();
    let Total         = $('input#Total').val();
    let DBString      = $('input#DBString').val();
    let LimiteFajilla = $('input#LimiteFajilla').val();
    let exchange_now   = $('input#exchange_now').val();
    let TotalPending   = $('input#TotalPending').val();


    $(selectedTabId).html('');

    // Realizar una solicitud AJAX para cargar el contenido de la píldora
    $.ajax({
        url: '/operations/' + action, // Ruta del archivo AJAX para cargar contenido
        method: 'GET', // Método de la solicitud (GET, POST, etc.)
        data: {
            'tabId': tabid,
            'action': action,
            'island': island,
            'estatus': estatus,
            'codigoestacion': codigoestacion,
            'fechatabular': fechatabular,
            'turno': turno,
            'islands': islands,
            'TotalProductos': TotalProductos,
            'Total': Total,
            'DBString': DBString,
            'LimiteFajilla': LimiteFajilla,
            'exchange_now': exchange_now,
            'TotalPending': TotalPending
        }, // Puedes enviar datos adicionales si es necesario
        success: function (data) {
            // Actualizar el contenido de la píldora activa actualmente
            $('.tab-pane.show.active').removeClass('show active');
            $(selectedTabId).addClass('show active');
            $(selectedTabId).html(data.html); // Actualizar el contenido con la respuesta del servidor

            // Actualizar los selectpickers
            $('.selectpicker').selectpicker();
        },
        error: function (error) {
            console.error('Error en la solicitud AJAX: ', error);
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    // Escuchar el evento shown.bs.tab para almacenar el último tab seleccionado
    document.querySelectorAll('.nav-link').forEach(function(tab) {
        tab.addEventListener('shown.bs.tab', function(event) {
            const tabId = event.target.getAttribute('id');
            localStorage.setItem('lastSelectedTab', tabId);
        });
    });

    // Recuperar y activar el último tab seleccionado al cargar la página
    const lastSelectedTab = localStorage.getItem('lastSelectedTab');
    if (lastSelectedTab) {
        const tabToActivate = $(`#${lastSelectedTab}`);
        if (tabToActivate.length) {
            tabToActivate.click();
        }
    }
});

function confirmDelete(id, secuencial) {


    // Vamos a ingresar un alertify.confirm
    alertify.confirm('Eliminar fajilla', '¿Estás segur@ de que deseas eliminar esta fajilla?',
        function(){
            $('.table-responsive').addClass('loading');
            // Si el usuario confirma, redirige al enlace de eliminación
            window.location.href = "/operations/delete_wad/" + id + "/" + secuencial + "/" + $('input#CodigoEstacion').val();
        },
        function(){
            toastr.info('Operación cancelada', '¡Atención!', { timeOut: 3000 });
        }
    );
}

$(document).on('click', '.denomination_input', function(e) {
    // Obtenermos el id del input
    let id = $(this).attr('id');
    // Obtenemos el valor del input
    let value = $(this).val();
    if (value === '0') {
        $(this).val('');
    }
});

$(document).on('blur', '.denomination_input', function(e) {
    // Obtenermos el id del input
    let id = $(this).attr('id');
    // Obtenemos el valor del input
    let value = $(this).val();
    // Si el valor es vacío, lo establecemos en 0
    if (value === '') {
        $(this).val(0);
    }
});

function calcTotal(element) {
    // Identificamos el input sobre el que se hace el cambio
    var inputId = $(element).attr('id');

    const billsTotal = calcBillsTotal();
    const coinsTotal = calcCoinsTotal();
    const totalCompare = $('#total').data('compare'); // Valor de comparación del total

    const total = billsTotal + coinsTotal;
    const total_diff = total - totalCompare;

    // Formatear los valores a float con dos decimales
    const billsTotalFormatted = billsTotal.toFixed(2);
    const coinsTotalFormatted = coinsTotal.toFixed(2);
    const total_diffFormatted = total_diff.toFixed(2);
    const totalFormatted = total.toFixed(2);

    // Actualizar los valores en la vista
    document.getElementById('subtotal_bills').innerHTML = `$${billsTotalFormatted}`;
    document.getElementById('subtotal_coins').innerHTML = `$${coinsTotalFormatted}`;
    document.getElementById('subtotal_diff').innerHTML = `$${total_diffFormatted}`;
    document.getElementById('total').innerHTML = `$${totalFormatted}`;

    // Agregar clases para indicar si el total es diferente al valor de comparación
    if (parseFloat(totalCompare) !== parseFloat(totalFormatted)) { // Si el total de las denominaciones es diferente al valor de comparación
        $('#total').addClass('text-danger');
    } else {
        $('#total').removeClass('text-danger');
        $('#total').addClass('text-success');
    }
}


function calcBillsTotal() {
    const bill_thousand     = parseInt(document.getElementById('bill_thousand').value) || 0;
    const bill_five_hundred = parseInt(document.getElementById('bill_five_hundred').value) || 0;
    const bill_two_hundred  = parseInt(document.getElementById('bill_two_hundred').value) || 0;
    const bill_hundred      = parseInt(document.getElementById('bill_hundred').value) || 0;
    const bill_fifty        = parseInt(document.getElementById('bill_fifty').value) || 0;
    const bill_twenty       = parseInt(document.getElementById('bill_twenty').value) || 0;
    return (bill_thousand * 1000) + (bill_five_hundred * 500) + (bill_two_hundred * 200) + (bill_hundred * 100) + (bill_fifty * 50) + (bill_twenty * 20);
}

function calcCoinsTotal() {
    const coin_twenty      = parseInt(document.getElementById('coin_twenty').value) || 0;
    const coin_ten         = parseInt(document.getElementById('coin_ten').value) || 0;
    const coin_five        = parseInt(document.getElementById('coin_five').value) || 0;
    const coin_two         = parseInt(document.getElementById('coin_two').value) || 0;
    const coin_one         = parseInt(document.getElementById('coin_one').value) || 0;
    const coin_point_fifty = parseInt(document.getElementById('coin_point_fifty').value) || 0;
    return (coin_twenty * 20) + (coin_ten * 10) + (coin_five * 5) + (coin_two * 2) + (coin_one) + (coin_point_fifty * .5);
}


function calcTotalUSD() {
    const billsTotal = calcBillsUSDTotal();
    const coinsTotal = calcCoinsUSDTotal();
    const totalCompare = $('#total').data('compare'); // Valor de comparación del total

    const total = billsTotal + coinsTotal;
    const total_diff = total - totalCompare;

    // Formatear los valores a float con dos decimales
    const billsTotalFormatted = billsTotal.toFixed(2);
    const coinsTotalFormatted = coinsTotal.toFixed(2);
    const total_diffFormatted = total_diff.toFixed(2);
    const totalFormatted = total.toFixed(2);

    // Actualizar los valores en la vista
    document.getElementById('subtotal_bills').innerHTML = `$${billsTotalFormatted}`;
    document.getElementById('subtotal_coins').innerHTML = `$${coinsTotalFormatted}`;
    document.getElementById('subtotal_diff').innerHTML = `$${total_diffFormatted}`;
    document.getElementById('total').innerHTML = `$${totalFormatted}`;

    // Agregar clases para indicar si el total es diferente al valor de comparación
    if (parseFloat(totalCompare) !== parseFloat(totalFormatted)) { // Si el total de las denominaciones es diferente al valor de comparación
        $('#total').addClass('text-danger');
    } else {
        $('#total').removeClass('text-danger');
        $('#total').addClass('text-success');
    }


}


function calcBillsUSDTotal() {
    const bill_hundred_usd = parseInt(document.getElementById('bill_hundred_usd').value) || 0;
    const bill_fifty_usd   = parseInt(document.getElementById('bill_fifty_usd').value)   || 0;
    const bill_twenty_usd  = parseInt(document.getElementById('bill_twenty_usd').value)  || 0;
    const bill_ten_usd     = parseInt(document.getElementById('bill_ten_usd').value)     || 0;
    const bill_five_usd    = parseInt(document.getElementById('bill_five_usd').value)    || 0;
    const bill_two_usd     = parseInt(document.getElementById('bill_two_usd').value)     || 0;
    const bill_one_usd     = parseInt(document.getElementById('bill_one_usd').value)     || 0;


    return (bill_hundred_usd * 100)
        + (bill_fifty_usd * 50)
        + (bill_twenty_usd * 20)
        + (bill_ten_usd * 10)
        + (bill_five_usd * 5)
        + (bill_two_usd * 2)
        + (bill_one_usd);
}

function calcCoinsUSDTotal() {
    const coin_one_usd     = parseInt(document.getElementById('coin_one_usd').value) || 0;
    const coin_half_usd    = parseInt(document.getElementById('coin_half_usd').value) || 0;
    const coin_quarter_usd = parseInt(document.getElementById('coin_quarter_usd').value) || 0;
    const coin_dime_usd    = parseInt(document.getElementById('coin_dime_usd').value) || 0;
    const coin_nickel_usd  = parseInt(document.getElementById('coin_nickel_usd').value) || 0;
    const coin_penny_usd   = parseInt(document.getElementById('coin_penny_usd').value) || 0;

    return (coin_one_usd)
        + (coin_half_usd * .5)
        + (coin_quarter_usd * .25)
        + (coin_dime_usd * .1)
        + (coin_nickel_usd * .05)
        + (coin_penny_usd * .01);
}

$(document).on('submit', '#save_denominations_form', function(e) {
    // Si una denominación con la clase denomination_input está vacía, establece su valor en 0
    $('.denomination_input').each(function() { if ($(this).val() === '') { $(this).val(0); } });
    // Primero deeshabilitamos el botón de submit
    $('#save_denominations').prop('disabled', true);
    e.preventDefault(); // Evita que el formulario se envíe normalmente

    // Obtiene los datos del formulario
    let formData = $(this).serialize();
    let currency = $('#currency_form').val();

    // Verificar que se hayan ingresado las denominaciones
    const billsTotal = (parseInt(currency) === 6) ? calcBillsTotal() : calcBillsUSDTotal();
    const coinsTotal = (parseInt(currency) === 6) ? calcCoinsTotal() : calcCoinsUSDTotal();

    const totalCompare = $('#total').data('compare'); // Valor de comparación del total

    const total = billsTotal + coinsTotal;

    // Verificar que el total de las denominaciones sea igual al valor de comparación
    if (parseFloat(totalCompare) !== parseFloat(total)) {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Los totales no coinciden. En deposito tenemos ` + parseFloat(total) + ` contra `+ parseFloat(total) + `. Por favor, verifica los valores ingresados.</p>
            </div>`
        );
        $('#save_denominations').prop('disabled', false);
        return; // Detiene la ejecución del formulario
    }


    // Realiza la solicitud AJAX
    $.ajax({
      type: 'POST', // O 'GET' según tus necesidades
      url: '/operations/save_denominations_form',
      data: formData,
      success: function() {
        // Maneja la respuesta del servidor aquí
        location.reload();
      },
      error: function(xhr) {
        // Maneja errores aquí
        console.error(xhr.responseText);
      },
      complete: function() {
        // Habilita el botón de submit
        $('#save_denominations').prop('disabled', true);
      }
    });
});

// Table de Jarreos
let sampling_table = $('#sampling_table').DataTable({
    colReorder: true,
    order: [0, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 25,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none sampling_table',
        }
    ],
});

// Agrega un evento "draw" a la tabla
sampling_table.on('draw', function() {
    // Inicializa DataTables FixedHeader en la tabla
    new $.fn.dataTable.FixedHeader(sampling_table);
});

// Tabla de Lecturas
let dispatches_table = $('#dispatches_table').DataTable({
    fixedHeader: {
        header: true,
        footer: true
    },
    scrollCollapse: true,
    colReorder: true,
    order: [3, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    columnDefs: [{
        'targets': 0,
        'searchable': false,
        'orderable': false,
        'className': 'dt-body-center',
        'render': function (data){
            return '<input type="checkbox" name="dispatches[]" value="' + $('<div/>').text(data).html() + '">';
        }
     }],
    buttons: [
        {
            extend: 'pdf',
            className: 'd-none dispatches_table',
            // Título del archivo de exportación
            title: 'Despachos',
            // Prevenimos la exportación de la columna de checkbox y de las acciones
            exportOptions: {
                columns: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
            }
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

                $('#dispatches_table thead th').each(function () {
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
        url: '/operations/dispatches_table/' + $('input#CodigoEstacion').val() + '/' + $('input#Turno').val() + '/' + $('input#FechaTabular').val(),
        error: function() {
            $('#dispatches_table').waitMe('hide');
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
        {'data': 'CHECK'},
        {'data': 'DESPACHO'},
        {'data': 'FECHA'},
        {'data': 'CLIENTE'},
        {'data': 'PRODUCTO'},
        {'data': 'LITROS', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'MONTO', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$' )},
        {'data': 'ISLA'},
        {'data': 'BOMBA'},
        {'data': 'FACTURA'},
        {'data': 'VALOR'},
        {'data': 'ACCIONES'}
    ],
    rowId: 'DESPACHO',
    createdRow: function (row, data) {
        // Agregamos la clase de css text-nowrap a la columna 1 para que no se rompa el texto
        $(row).find('td:eq(2)').addClass('text-nowrap');

        // Verificamos si la columna CLIENTE tiene un valor, y si no, agregamos la clase text-danger y el texto "Sin Cliente"
        $(row).find('td:eq(3)').addClass('text-center');
        if (data.CLIENTE === '' || data.CLIENTE === null) {
            $(row).find('td:eq(3)').text('Sin Cliente');
        }

        // Si el prducto es igual a T-Super Premium agregamos la clase text-primary, si el producto es T-Maxima Regular	agregamos la clase text-success
        if (data.PRODUCTO === 'T-Super Premium') {
            $(row).find('td:eq(4)').addClass('text-success');
        } else if (data.PRODUCTO === 'T-Maxima Regular') {
            $(row).find('td:eq(4)').addClass('text-primary');
        }

        // Agregamos el estilo bold a la columna PRODUCTO
        $(row).find('td:eq(4)').addClass('fw-bold');

        // Si la columna LITROS es igual a 0, agregamos la clase text-danger
        if (data.LITROS === 0) {
            $(row).find('td:eq(5)').addClass('text-danger');
        }

        // Si la columna MONTO es igual a 0, agregamos la clase text-danger
        if (data.MONTO === 0) {
            $(row).find('td:eq(6)').addClass('text-danger');
        }

        if(data.VALOR != 'Contado'){
            $(row).find('td:eq(0)').html('');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Tabla de Lecturas
let readings_table = $('#readings_table').DataTable({
    colReorder: true,
    order: [1, "asc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'pdf',
            className: 'd-none readings_table',
            // Título del archivo de exportación
            title: 'Lecturas'
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

                $('#readings_table thead th').each(function () {
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
        url: '/operations/readings_table/' + $('input#CodigoEstacion').val() + '/' + $('input#FechaTabular').val() + '/' + $('input#Turno').val() + '/' + $('input#tabId').val() + '/' + $('input#Estatus').val(),
        error: function() {
            $('#readings_table').waitMe('hide');
            toastr.warning('Tabla de lecturas no actualizada', '¡Atención!', { timeOut: 1000 });
        },
        beforeSend: function() {
            $('.table-responsive').addClass('loading');
        }
    },
    deferRender: true,
    columns: [
        {'data': 'ISLA'},
        {'data': 'BOMBA'},
        {'data': 'PRODUCTO'},
        {'data': 'L_INI_ELECT', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'L_FIN_ELECT', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'LTS_VEN_ELECT', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'L_INI_MEC', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'L_FIN_MEC', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'LTS_VEN_MEC', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'DIF_LITROS', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
    ],
    // rowId: 'Id',
    createdRow: function (row, data) {
        // Necesito agregar estos atributos a la celda 8 para poder editarla: class="text-nowrap editable shadow" data-Bomba="{{ read.Bomba }}" data-Turno="{{ tabulator.Turno }}" data-CodIsla="{{ read.CodIsla }}" data-CodProducto="{{ read.CodProducto }}" data-LecturaInicialMecanica="{{ read.LecturaInicialMecanica }}"

        if ($('input#Estatus').val() == 1) {
            $(row).find('td:eq(7)').attr('class', 'text-nowrap shadow');
            // Ahora agregarremos el atribut onclick="editReading(this)" a la celda 8
            $(row).find('td:eq(7)').attr('onclick', `editReading(${data.BOMBA}, ${$('input#Turno').val()}, ${data.CODISLA}, ${data.CODPRODUCTO}, ${data.L_INI_MEC}, ${data.tabId})`);
        }

        // Si el prducto es igual a T-Super Premium agregamos la clase text-primary, si el producto es T-Maxima Regular	agregamos la clase text-success
        if (data.PRODUCTO === 'T-Super Premium') {
            $(row).find('td:eq(2)').addClass('text-success');
        } else if (data.PRODUCTO === 'T-Maxima Regular') {
            $(row).find('td:eq(2)').addClass('text-primary');
        }

        // Agregamos el estilo bold a la columna PRODUCTO
        $(row).find('td:eq(2)').addClass('fw-bold');
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});


function editReading(bomba, turno, codisla, codproducto, lecturainicialmecanica, tabId) {
    // Lanzar un prompt para ingresar el nuevo valor
    alertify.prompt( 'Agregar Lectura Mecánica', 'Por favor, agregue la lectura mecánica', ''
        , function(evt, value) {
            // Validar si el valor ingresado es un número
            if (!isNaN(value.replace(/,/g, ''))) {
                $.ajax({
                    url: '/operations/save_reading',
                    method: 'post',
                    data: {
                        'Bomba'                  : bomba,
                        'Turno'                  : turno,
                        'CodIsla'                : codisla,
                        'CodProducto'            : codproducto,
                        'LecturaInicialMecanica' : lecturainicialmecanica,
                        'LecturaFinalMecanica'   : value.replace(/,/g, ''),
                        'tabId'                  : tabId,
                    },
                    dataType: 'json',
                    success: function() {
                        toastr.success('La lectura mecánica se ha ingresado correctamente', '¡Éxito!', { timeOut: 1000 });
                        // Ahora recargamos la tabla readings_table
                        readings_table.ajax.reload();
                        $('.table-responsive').removeClass('loading');
                    },
                    error: function(error) {
                        toastr.error('No fue posible ingresar la lectura mecánica', '¡Error!', { timeOut: 1000 })
                        console.log(error);
                    }
                });
            } else {
                toastr.error('El valor ingresado no es un número válido', 'Error', { timeOut: 1000 });
            }
        }
        , function() {
            toastr.warning('Operacion Cancelada', '¡Atención!', { timeOut: 1000 });
        }
    );
}


$('#markDispatchesModal').on('show.bs.modal', function () {

    let modal = $(this);
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/operations/markDispatchesModal/' + $('input#tabId').val(),
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#markDispatchesModalLabel').text(data.title);
            modal.find('#content').html(data.content);
            // Aqui vamos a recopilar los ids de los despachos seleccionados

            // Iterar sobre todas las filas del DataTable aunque no sean visibles por el filtro de busqueda
            // Filtrar las filas para obtener solo aquellas cuyos checkbox estén marcados
            var rowsWithCheckedCheckbox = dispatches_table.rows(function(index, data, node) {
                return $(node).find('input[name="dispatches[]"]:checked').length > 0;
            });

            // Iterar sobre las filas filtradas y obtener los valores de los checkbox marcados
            rowsWithCheckedCheckbox.every(function() {
                // Obtener el valor del checkbox marcado en cada fila
                var checkboxValue = $(this.node()).find('input[name="dispatches[]"]:checked').val();

                // Insertar un input:hidden con el valor del checkbox en el formulario
                $('#markDispatchesForm .modal-body').append(`<input type="hidden" name="dispatches[]" value="${checkboxValue}">`);
            });
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});

$('#historyModal').on('show.bs.modal', function () {
    let modal = $(this);
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/operations/historyModal/' + $('input#tabId').val(),
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#historyModalLabel').text(data.title);
            modal.find('#content').html(data.content);
            // Aqui vamos a recopilar los ids de los despachos seleccionados
            $.each($("input[name='dispatches[]']:checked"), function(){
                // Ingresamos un input:hidden con el id del despacho en el formulario
                $('#markDispatchesForm .modal-body').append(`<input type="hidden" name="dispatches[]" value="${$(this).val()}">`);
            });
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});

$('#debitModal').on('show.bs.modal', function () {
    let modal = $(this);
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/operations/debitModal/' + $('input#tabId').val(),
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#debitModalLabel').text(data.title);
            modal.find('.content').html(data.content);
            // Aqui vamos a recopilar los ids de los despachos seleccionados
            $('#debit').DataTable({
                buttons: [
                    {
                        extend: 'excel',
                        className: 'd-none debit_table',
                        filename: 'Despachos de débito'
                    },
                ],
                dom: '<"top"Bf>rt<"bottom"lip>',
            });

            $('#exportExcelDebit').on('click', function () {
                // Disparar el evento clic del botón de exportación de Excel del DataTable
                $('.debit_table').trigger('click');
            });
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});

$('#creditModal').on('show.bs.modal', function () {
    let modal = $(this);
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/operations/creditModal/' + $('input#tabId').val(),
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#creditModalLabel').text(data.title);
            modal.find('.content').html(data.content);
            // Aqui vamos a recopilar los ids de los despachos seleccionados

            $('#credit').DataTable({
                buttons: [
                    {
                        extend: 'excel',
                        className: 'd-none credit_table',
                        filename: 'Despachos de crédito'
                    },
                ],
                dom: '<"top"Bf>rt<"bottom"lip>',
            });

            $('#exportExcelCredit').on('click', function () {
                // Disparar el evento clic del botón de exportación de Excel del DataTable
                $('.credit_table').trigger('click');
            });
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});


function delete_deposit(IdTabulador, IdRecolecta) {
    alertify.confirm('Eliminar depósito', '¿Está seguro de que desea eliminar este depósito?',
        function(){
            $.ajax({
                url: '/operations/delete_deposit',
                method: 'POST',
                data: {
                    'IdTabulador': IdTabulador,
                    'IdRecolecta': IdRecolecta
                },
                dataType: 'json',
                success: function(data) {
                    if (data === 1) {
                        toastr.success('El depósito fue eliminado correctamente', '¡Éxito!', { timeOut: 1000 });
                        // Ahora refrescamos la página
                        location.reload();
                    } else {
                        toastr.error('No fue posible eliminar el depósito', '¡Error!', { timeOut: 1000 });
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    console.error('AJAX error:', errorThrown);
                }
            });
        },
        function(){
            toastr.error('Cancel')
        }
    );
}

function close_tabulator(tabId) {
    // Ejecuta las verificaciones
    executeVerifications(tabId);
}

// Vamos a procesar el formulario con el Id #open_tab_form
$(document).on('submit', '#open_tab_form', function(e) {
    e.preventDefault(); // Evita que el formulario se envíe normalmente

    // Obtiene los datos del formulario
    let formData = $(this).serialize();

    // Realiza la solicitud AJAX
    $.ajax({
        type: 'POST', // O 'GET' según tus necesidades
        url: '/operations/open_tabulator',
        data: formData,
        success: function(response) {
            // Maneja la respuesta del servidor aquí
            // location.reload();
            if (response.status === 'success') {
                toastr.success(response.message, '¡Éxito!', {timeOut: 10000});
                // Vamos a aghregar la clase loading a la card xcon la clase tabulator_window
                $('.tabulator_window').addClass('loading');
                // Ahora refrescamos la pagina actual
                location.reload();
            } else {
                toastr.error(response.message, '¡Error!', {timeOut: 10000});
            }
        },
        error: function(xhr) {
            // Maneja errores aquí
            console.error(xhr.responseText);
        }
    });
});

async function executeVerifications(tabId) {
    // Primera verificación: Si la suma de las fajillas ingresadas es igual a la suma de las denominaciones
    const response1 = await $.ajax({
        url: '/operations/compare_total_vs_denominations',
        method: 'POST',
        data: {
            'tabId': tabId,
        },
        dataType: 'json',
    });

    if (response1.success) {
        toastr.success(response1.message, '¡Éxito!', {timeOut: 10000});
        // Si la primera verificación es exitosa, entonces realizamos la segunda verificación
        const response2 = await $.ajax({
            url: '/operations/check_pending_deposits',
            method: 'POST',
            data: {
                'tabId': tabId,
            },
            dataType: 'json',
        });

        // Acción a realizar si la segunda verificación es exitosa
        if (response2.success) {
            toastr.success(response2.message, '¡Éxito!', {timeOut: 10000});

            // Aqui solicitamos la confirmación para cerrar el tabulador
            alertify.confirm('Cerrar tabulador', '<h4 class="text-center">¿Está seguro de cerrar el tabulador?</h4><p class="text-center">Los cambios ya no podran deshacerse.</p>',
                function(){
                    $.ajax({
                        method: "POST",
                        url: "/operations/close_tabulator",
                        data: { 'tabId': tabId }
                    })
                    .done(function( data ) {
                        if (data.success) {
                            toastr.success(data.message, '¡Éxito!')
                            // Vamos a aghregar la clase loading a la card xcon la clase tabulator_window
                            $('.tabulator_window').addClass('loading');
                            // Ahora refrescamos la pagina actual
                            location.reload();
                        }
                    });
                },
                function(){
                    toastr.error('Operacion cancelada', '¡Error!')
                }
            );
        } else {
            // Acción a realizar si la segunda verificación falla
            toastr.error('Existen depósitos pendientes', '¡Error!');
        }
    } else {
        // Acción a realizar si la primera verificación falla
        toastr.error(response1.message, '¡Error!', {timeOut: 10000});
    }
}

$('#datatables_assignations').DataTable({
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'pdf',
            className: 'd-none datatables_assignations',
            // Título del archivo de exportación
            title: 'Asignaciones',
            // Vamos a prevenir que la ultima columna se exporte
            exportOptions: {
                columns: ':visible:not(:last-child)'
            }
        }
    ],
    rowId: 'Id',
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

$('#exportExcel_datatables_assignations').on('click', function () {
    // Disparar el evento clic del botón de exportación de Excel del DataTable
    $('.datatables_assignations').trigger('click');
});

$('#exportExcel_dispatches_table').on('click', function () {
    // Disparar el evento clic del botón de exportación de Excel del DataTable
    $('.dispatches_table').trigger('click');
});

$('#exportExcel_sampling_table').on('click', function () {
    // Disparar el evento clic del botón de exportación de Excel del DataTable
    $('.sampling_table').trigger('click');
});

$('#exportExcel_readings_table').on('click', function () {
    // Disparar el evento clic del botón de exportación de Excel del DataTable
    $('.readings_table').trigger('click');
});

// Tabla de Ventas
$('#sales_table').DataTable({
    colReorder: true,
    order: [0, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    responsive: true,
    fixedColumns: {
        left: 1,
        right: 1
    },
    paging: false,
    scrollCollapse: true,
    scrollX: true,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            filename: 'Reporte de ventas',
            exportOptions: {
                format: {
                    body: function (data, row, column, node) {
                        if (column >= 2 && column <= 34) {
                            return parseFloat(data.replace(/[^0-9.-]/g, ''));
                        } else {
                            return data;
                        }
                    }
                }
            }
        }
    ],
    ajax: {
        data: {'from': $('#sales_table').data('from'), 'until': $('#sales_table').data('until')},
        // method: 'post',
        url: '/operations/sales_table',
        error: function() {
            $('#sales_table').waitMe('hide');
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
        {'data': 'Producto'},
        {'data': '02 LERDO', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '03 DELICIAS', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '04 PARRAL', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '05 LOPEZ MATEOS', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '06 GEMELA CHICA', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '07 GEMEL GRANDE', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '08 PLUTARCO', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '09 MPIO', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '10 AZTECAS', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '11 MISIONES', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '12 PTO DE PALOS', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '13 MIGUEL D MAD', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '14 PERMUTA', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '15 ELECTROLUX', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '16 AERONAUTICA', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '17 CUSTODIA', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '18 ANAPRA', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '19 INDEPENDENCI', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '20 TECNOLOGICO', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '21 EJERCITO NAL', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '22 SATELITE', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '23 LAS FUENTES', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '24 CLARA', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '25 SOLIS', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '26 SANTIAGO TRO', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '27 JARUDO', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '28 HERMANOS ESC', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '29 VILLA AHUMAD', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '30 EL CASTAÑO', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '31 Travel Center', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '32 Picachos', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '33 Ventanas', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '34 SAN RAFAEL', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '35 PUERTECITO', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '36 JESUS MARIA', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '37 GABRIELA MIS', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': '38 PRAXEDIS', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'Total', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
    ],
    createdRow: function (row, data, dataIndex) {
        let firstCell = $(row).find("td:first-child");
        let lastCell = $(row).find("td:last-child");
        firstCell.addClass("bg-primary text-light");
        lastCell.addClass("bg-primary text-light");
        // A la primera celda de cada fila le agregamos la clase text-nowrap
        $(row).find('td:eq(0)').addClass('text-nowrap');
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    },
    footerCallback: function (row, data, start, end, display) {
        var api = this.api();

        // Calcula los totales de las columnas
        api.columns([2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35])
            .every(function () {
                var columnData = this.data();
                var columnTotal = columnData.reduce(function (acc, val) {
                    return acc + parseFloat(val.replace(/[^0-9.-]/g, ''));
                }, 0);
                $(this.footer()).html('$' + columnTotal.toFixed(2));
            });
    }
});

$('#consultDispatchModal').on('show.bs.modal', function (e) {
    let modal = $(this);

    // Obtenemos el dato del codest del boton disparador
    let codest = $('input#CodigoEstacion').val();

    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/operations/consultDispatchModal/' + codest,
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#consultDispatchModalLabel').text(data.title);
            modal.find('.content').html(data.content);
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});


// Vamos a a gregar un evento para cuando se envie el formulario con el id assignation_form
$(document).on('submit', '#assignation_form', function(e) {
    $('.table-responsive').addClass('loading');
});



function details_deposit(IdTabulador, IdRecolecta) {

    let modal = $(this);
    // Aqui abrimos el modal de detalles de depósito con el #detailsDepositModal
    $('#detailsDepositModal').modal('show');
    // Aqui vamos a realizar la consulta de los detalles del depósito
    $.ajax({
        url: '/operations/details_deposit/' + IdTabulador + '/' + IdRecolecta,
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            $('#detailsDepositModal .modal-dialog').addClass(data.size + ' ' + data.position);
            $('#detailsDepositModal #detailsDepositModalLabel').text(data.title);
            $('#detailsDepositModal #content').html(data.content);
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
}

function dismark_dispatch(codest, nrotrn) {
    alertify.confirm('Desmarcar despacho', '¿Está segur@ de desmarcar este despacho?',
        function(){
            $('.table-responsive').addClass('loading');
            // Vamos a apuntar a la ruta /operations/dismark_dispatch
            $.ajax({
                url: '/operations/dismark_dispatch/' + codest + '/' + nrotrn,
                dataType: 'json',
                success: function(data) {
                    if (data === 1) {
                        toastr.success('El despacho fue desmarcado correctamente', '¡Éxito!', { timeOut: 1000 });
                        // Ahora recargamos la tabla readings_table
                        dispatches_table.ajax.reload();
                        $('.table-responsive').removeClass('loading');
                    } else {
                        toastr.error('No fue posible desmarcar el despacho', '¡Error!', { timeOut: 1000 });
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    console.error('AJAX error:', errorThrown);
                }
            });
        },
        function(){
            toastr.warning('Tabla de lecturas no actualizada', '¡Atención!', { timeOut: 1000 });
        }
    );
}

$('#registerDismissModal').on('show.bs.modal', function (e) {
    let modal = $(this);

    // Obtenemos el dato del codest del boton disparador
    let codest = $('input#CodigoEstacion').val();
    let mojo_access_key = $('input#mojo_access_key').val();



    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/operations/registerDismissModal/' + codest + '/' + mojo_access_key,
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#registerDismissModalLabel').text(data.title);
            modal.find('.content').html(data.content);
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});



$('#tabInfoModal').on('show.bs.modal', function (e) {
    let modal = $(this);

    // Obtenemos el dato del codest del boton disparador
    let codest = $('input#CodigoEstacion').val();
    let tabId = $('input#tabId').val();

    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/operations/tabInfoModal/' + codest + '/' + tabId,
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#tabInfoModalLabel').text(data.title);
            modal.find('.content').html(data.content);
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});


$('#islandInfoModal').on('show.bs.modal', function (e) {

    let modal = $(this);
    // Vamos a vaciar el contenido del modal
    modal.find('.content').html('');
    // Vamos a obtener el valor del atributo data-isalnd del modal disparador
    let island = $(e.relatedTarget).data('island');
    let tabId = $('input#tabId').val();
    let station_server = $('input#station_server').val();
    let fechaTabularInt = $('input#fechaTabularInt').val();
    let CodigoEstacion = $('input#CodigoEstacion').val();
    let Turno = $('input#Turno').val();

    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/operations/islandInfoModal/' + tabId + '/' + island,
        data: {'station_server': station_server, 'fechaTabularInt': fechaTabularInt, 'CodigoEstacion': CodigoEstacion, 'Turno': Turno},
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#islandInfoModalLabel').text(data.title);
            modal.find('.content').html(data.content);
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});

var responsables_table = $('#responsables_table').DataTable({
    colReorder: true,
    order: [0, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    responsive: true,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            filename: 'Reporte de ventas'
        }
    ],
    ajax: {
        // Enviamos por post
        data: {'from': $('#responsables_table').data('from'), 'until': $('#responsables_table').data('until')},
        method: 'post',
        url: '/operations/responsables',
        error: function() {
            $('#responsables_table').waitMe('hide');
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

        {'data': 'Codigo'},
        {'data': 'Nombre'},
        {'data': 'NoReloj'},
        {'data': 'Status'},
        {'data': 'Puesto'},
        {'data': 'Estacion'},
        {'data': 'Alta'},
        {'data': 'Responsable'},
        {'data': 'Acciones'}
    ],
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    },
    footerCallback: function (row, data, start, end, display) {

    }
});

// Agregar un evento clic de refresh
$('.refresh_responsables_table').on('click', function () {
    datatables_permissions_users.clear().draw();
    datatables_permissions_users.ajax.reload();
    $('#datatables_permissions_users').waitMe('hide');
});

$('#responsableModal').on('show.bs.modal', function (e) {
    // En el disparador del modal hay un atributo llamado data-id, el cual vamos a obtener
    let cod = $(e.relatedTarget).data('id');
    let codgas = $(e.relatedTarget).data('codgas');
    // Vamos a verificar que cod no este indefinido y si lo esta le vamos a asignar el valor cero
    if (cod === undefined) {
        cod = 0;
    }

    let modal = $(this);
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/operations/responsableModal/' + cod + '/' + codgas,
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#responsableModalLabel').text(data.title);
            modal.find('.content').html(data.content);
            // Aqui vamos a recopilar los ids de los despachos seleccionados
            $.each($("input[name='dispatches[]']:checked"), function(){
                // Ingresamos un input:hidden con el id del despacho en el formulario
                $('#markDispatchesForm .modal-body').append(`<input type="hidden" name="dispatches[]" value="${$(this).val()}">`);
            });
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});


function deactivate_responsable(cod, hab) {
    // VVamos a activar la clase .loading a la tabla de responsables
    $('.table-responsive').addClass('loading');
    // Vamos a enviar una paticion ajax por metodo post a la ruta /operations/deactivate_responsable
    $.ajax({
        url: '/operations/deactivate_responsable/' + cod + '/' + hab,
        method: 'POST',
        dataType: 'json',
        success: function(data) {
            if (data.status == 'success') {
                toastr.success(data.message, '¡Éxito!', { timeOut: 1000 });
                // Ahora recargamos la tabla de responsables
                responsables_table.clear().draw();
                responsables_table.ajax.reload();
                $('.table-responsive').removeClass('loading');
            } else {
                toastr.error(data.message, '¡Error!', { timeOut: 1000 });
            }
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
}

function delete_responsable(cod) {
    // Vamos a gregar la clase .loading a la tabla de responsables
    $('.table-responsive').addClass('loading');

    // Vamos a enviar una paticion ajax por metodo post a la ruta /operations/delete_responsable
    $.ajax({
        url: '/operations/delete_responsable/' + cod,
        method: 'POST',
        dataType: 'json',
        success: function(data) {
            if (data.status == 'success') {
                toastr.success(data.message, '¡Éxito!', { timeOut: 1000 });
                // Ahora recargamos la tabla de responsables
                responsables_table.clear().draw();
                responsables_table.ajax.reload();
                $('.table-responsive').removeClass('loading');
            } else {
                toastr.error(data.message, '¡Error!', { timeOut: 1000 });
            }
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        },
        complete: function() {
            $('.table-responsive').removeClass('loading');
        }
    });
}

var monitor_table = $('#monitor_table').DataTable({
    colReorder: true,
    order: [0, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    responsive: true,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            filename: 'Monitor de tabuladores - ' + $('input#from').val()
        }
    ],
    ajax: {
        // Enviamos por post
        data: {'from': $('input#from').val()},
        method: 'post',
        url: '/operations/monitor_table',
        error: function() {
            $('#monitor_table').waitMe('hide');
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
        {'data': 'Zona'},
        {'data': 'Estacion'},
        {'data': 'Turno_11'},
        {'data': 'Turno_21'},
        {'data': 'Turno_31'},
        {'data': 'Turno_41'},
    ],
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    },
    footerCallback: function (row, data, start, end, display) {

    }
});

// Agregar un evento clic de refresh
$('.refresh_monitor_table').on('click', function () {
    monitor_table.clear().draw();
    monitor_table.ajax.reload();
    $('#monitor_table').waitMe('hide');
});

var islands_table = $('#islands_table').DataTable({
    colReorder: true,
    order: [0, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    responsive: true,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            filename: 'Reporte de ventas'
        }
    ],
    ajax: {
        // Enviamos por post
        method: 'post',
        url: '/operations/islands_table',
        error: function() {
            $('#islands_table').waitMe('hide');
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

        {'data': 'Id'},
        {'data': 'Isla'},
        {'data': 'CodEst'},
        {'data': 'Estacion'},
        {'data': 'Producto'},
    ],
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    },
    footerCallback: function (row, data, start, end, display) {

    }
});

// Agregar un evento clic de refresh
$('.refresh_islands_table').on('click', function () {
    datatables_permissions_users.clear().draw();
    datatables_permissions_users.ajax.reload();
    $('#datatables_permissions_users').waitMe('hide');
});

$(document).on('submit', '#wadForm', function(e) {


    let amount = parseFloat($('#amount').val());
    let LimiteRecolecta = parseFloat($('#LimiteRecolecta').val());
    let TotalPending = parseFloat($('#TotalPending').val());
    let TotalPendingUSD = parseFloat($('#TotalPendingUSD').val());
    let TotalPendingMXN = parseFloat($('#TotalPendingMXN').val());
    let TotalPendingMRL = parseFloat($('#TotalPendingMRL').val());
    let currency = $('#currency').val();

    if (currency === 'USD') {
        amount = amount * parseFloat($('#exchange_now').val());
        if ((amount + TotalPendingUSD) > LimiteRecolecta) {
            toastr.error('El monto USD excede el límite de recolección', '¡Error!', { timeOut: 3000 });
            return false;
        }
    }

    if (currency === 'MXN') {
        if ((amount + TotalPendingMXN) > LimiteRecolecta) {
            toastr.error('El monto MXN excede el límite de recolección', '¡Error!', { timeOut: 3000 });
            return false;
        }
    }

    if (currency === 'MRL') {
        if ((amount + TotalPendingMRL) > LimiteRecolecta) {
            toastr.error('El monto en Morralla excede el límite de recolección', '¡Error!', { timeOut: 3000 });
            return false;
        }
    }

    e.preventDefault(); // Vamos a enviar el formulario por ajax
    $('#submitButton').prop('disabled', true);  // Deshabilitar el botón de envío del formulario
    $('.table-responsive').addClass('loading');
    let formData = new FormData(this);

    let island_id = formData.get('island_id');

    $.ajax({
        type: 'POST', // O 'GET' según tus necesidades
        url: '/operations/tab_process/' + $('input#tabId').val(),
        data: formData,
        contentType: false,
        processData: false,
        success: function(data) { // Maneja la respuesta del servidor aquí
            if (data.result == 1) {
                toastr.success(data.message, '¡Éxito!', { timeOut: 1000 });
                // Vamos a refrescar la tabla de fajillas con el Id datatables_wads_tab
                let datatables_wads_tab = $('#datatables_wads_tab').DataTable();
                datatables_wads_tab.clear().draw();
                datatables_wads_tab.ajax.reload();

                // Camos a refrescar la tabla wads_table_total
                let wads_table_total = $('#wads_table_total').DataTable();
                wads_table_total.clear().draw();
                wads_table_total.ajax.reload();

                // Vamos a formatear como moneda el dato de data.totalMXN, data.totalUSD, data.totalTab y data.TotalPending
                let totalMXN = new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'MXN'}).format(data.totalMXN);
                let totalUSD = new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'USD'}).format(data.totalUSD);
                let totalTab = new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'MXN'}).format(data.totalTab);
                let TotalPending = new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'MXN'}).format(data.TotalPending);

                // Vamos a actualizar el acumulado en pesos
                $('#acumulated_mxn').text(totalMXN);
                // Vamos a actualizar el acumulado en dólares
                $('#acumulated_usd').text(totalUSD);
                // Vamos a actualizar el acumulado en totalTab
                $('#totalTab').text('(Total: ' + totalTab + ')');
                // Vamos a actualizar el acumulado en $('#Total').val()
                $('#Total').val(totalTab);
                // Vamos a actualizar el acumulado en $('#TotalPending').val()
                $('#TotalPending').val(data.TotalPending);
                $('#TotalPendingUSD').val(parseFloat(data.totalUSD) * parseFloat($('#exchange_now').val()));
                $('#TotalPendingMXN').val(parseFloat(data.totalMXN));
                $('#TotalPendingMRL').val(parseFloat(data.totalMXN));

                // Vamos a vaciar el input llamado amount y a dejarle el focus
                $('#amount').val('');
                $('#amount').focus();

                // Ahora vamos a aghregar la clase loading al boton con el id island_id
                $('#' + island_id).html(`<div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div>`);

                // Ahora vamos a cargar los datos del boton
                $.get("/operations/getInitialReadingsByIslandsAjax/" + formData.get('CodigoEstacion') + '/' + formData.get('FechaTabular') + '/' + formData.get('Turno') + '/' + formData.get('island_id') + '/' + formData.get('tabId'))
                    .done(function( data ) {
                        // Vamos a convertir a json el dato data
                        data = JSON.parse(data);
                        // Ahora vamos a aghregar la clase loading al boton con el id island_id
                        $('#' + island_id).html(`${data.Isla}<br>$ ${data.Diferencia}`);
                    }
                );

                // Ahora vamos a obtener el contenido de #diff_tab_span
                var diff_tab_span = $('#diff_tab_span').html();

                // Extrae el número del texto, incluyendo decimales
                const numeroTexto = diff_tab_span.match(/-?\d[\d,]*(\.\d{2})?/)[0].replace(/,/g, '');
                const numero = parseFloat(numeroTexto);

                $('#diff_tab_span').html('Diferencia: $' + (numero + amount).toFixed(2));

            } else {
                toastr.error(data.message, '¡Error!', { timeOut: 1000 });
            }
        },
        error: function(xhr) {
            // Maneja errores aquí
            console.error(xhr.responseText);
        },
        complete: function() {
            // Habilitar nuevamente el botón de envío del formulario después de que la solicitud AJAX se complete
            $('#submitButton').prop('disabled', false);
            $('.table-responsive').removeClass('loading');
        }
    });
});

$(document).on('submit', '.formulario-ajax', function(e) {

    var form = $(this);
    e.preventDefault(); // Evita que el formulario se envíe normalmente

    // Agregamos la clase .loading al .table-responsive
    form.closest('.card-footer').prev('.card-body').addClass('loading');

    form.addClass('loading');

    let formData = new FormData(this);
    let action = $(this).attr('action');
    let codisl = formData.get('codisl');
    let CodigoEstacion = formData.get('CodigoEstacion');
    let tabId = formData.get('tabId');
    let turno = formData.get('Turno');
    let FechaTabular = formData.get('FechaTabular');
    let LimiteFajilla = formData.get('LimiteFajilla');
    let islands = formData.get('islands');

    // Ahora si vamos a enviar el formulario por ajax y si la respuesta es correcta solamente vamos a refrescar la tabla de ventas
    $.ajax({
        type: 'POST', // O 'GET' según tus necesidades
        url: action,
        data: formData,
        contentType: false,
        processData: false,
        // Antes de enviar la petición
        beforeSend: function() {
            // $('#row_' + codisl).addClass('loading');
        },
        success: function(data) { // Maneja la respuesta del servidor aquí
            if (data.result == 1) {
                // $('#row_' + codisl).html('');
                toastr.success(data.message, '¡Éxito!', { timeOut: 3000 });
                // Vamos a recargar la página

                // Aqui vamos a hacer oraetra peticion ajax para tr un html
                $.ajax({
                    url: '/operations/get_sales_in_isle/' + codisl + '/' + tabId + '/' + turno + '/' + FechaTabular + '/' + CodigoEstacion + '/' + LimiteFajilla + '/' + islands,
                    method: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        // Selecciona el elemento .card-body y cambia su contenido
                        form.closest('.card-footer').prev('.card-body').html(data.sales);

                        $('#diff_tab_span').html(data.diff_tab_span);
                        toastr.success(data.diff_tab_span, { timeOut: 3000 });

                        // Encuentra el .row contenedor del formulario
                        var pair_of_tables = form.closest('.pair-of-tables');
                        // Encuentra el .card-body.table-responsive en el otro col y cambia su contenido
                        pair_of_tables.find('.col-sm-7 .card-body.table-responsive').html(data.ventas);

                        // Vamos a modificar el atributo max del input amount
                        form.find('#amount').attr('max', data.limiteFajilla);

                        if (data.difference > 0) {
                            form.find('#amount').attr('disabled', 'disabled');
                            form.find('button#submitButton').attr('disabled', 'disabled');
                            form.find('#small_limit').text('Límite alcanzado');
                        } else if(Math.abs(data.difference) < data.limiteFajilla) {
                            form.find('#amount').attr('max', Math.abs(data.difference).toFixed(2));
                            form.find('#small_limit').text('Límite máximo: $' + Math.abs(data.difference).toFixed(2));
                        }

                        form.removeClass('loading');
                    },
                    error: function(xhr, textStatus, errorThrown) {
                        console.error('AJAX error:', errorThrown);
                    },
                    complete: function() {
                        form.closest('.card-footer').prev('.card-body').removeClass('loading');
                    }
                });
            } else {
                if (data.result == 0) {
                    // Vamos a mostrar showCustomPrompt(CodigoEstacion)
                    showCustomPrompt(CodigoEstacion, codisl);

                    form.closest('.card-footer').prev('.card-body').removeClass('loading');
                }
                form.removeClass('loading');
            }
        },
        error: function(xhr) {
            // Maneja errores aquí
            console.error(xhr.responseText);
        },
        complete: function() {
            // Habilitar nuevamente el botón de envío del formulario después de que la solicitud AJAX se complete
            $('#row_' + codisl).removeClass('loading');
            // Vamos a vaciar el input llamado amount y a dejarle el focus
            form.find('#amount').val('');
            form.find('#amount').focus();
            form.closest('.card-footer').prev('.card-body').removeClass('loading');
        }
    });

    async function getOptionsHTML(CodigoEstacion) {
        try {
            const response = await fetch('/operations/get_responsables/' + CodigoEstacion); // Reemplaza con tu URL de API
            const data = await response.json();
            return data.map(option => `<option value="${option['Codigo']}" class="selectpicker">${option['Nombre']}</option>`).join('');
        } catch (error) {
            console.error('Error al obtener las opciones:', error);
            return '<option>Error al cargar opciones</option>';
        }
    }

    async function showCustomPrompt(CodigoEstacion, codisl) {
        const optionsHTML = await getOptionsHTML(CodigoEstacion);
        const selectHTML = `<select class="selectpicker form-control w-100 mt-2" data-live-search="true" name="codgas" id="customSelect" placeholder="Responsable" style="display: block !important;">${optionsHTML}</select>`;

        alertify.prompt('Sin responsable asignado', `Por favor, asigne un responsable en esta isla para poder agregar efectivo: ${selectHTML}`, '',
            function(evt, value) {
                const selectedValue = document.getElementById('customSelect').value;
                // Ahora vamos a mandar mandar el formuaro inicial con el valor de selectedValue
                formData.append('responsable_id', selectedValue);
                $.ajax({
                    type: 'POST', // O 'GET' según tus necesidades
                    url: action,
                    data: formData,
                    contentType: false,
                    processData: false,
                    // Antes de enviar la petición
                    beforeSend: function() {
                        // $('#row_' + codisl).addClass('loading');
                    },
                    success: function(data) { // Maneja la respuesta del servidor aquí
                        if (data.result == 1) {
                            // $('#row_' + codisl).html('');
                            toastr.success(data.message, '¡Éxito!', { timeOut: 3000 });
                            // Vamos a recargar la página

                            // Aqui vamos a hacer oraetra peticion ajax para tr un html
                            $.ajax({
                                url: '/operations/get_sales_in_isle/' + codisl + '/' + tabId + '/' + turno + '/' + FechaTabular + '/' + CodigoEstacion + '/' + LimiteFajilla + '/' + islands,
                                method: 'GET',
                                dataType: 'json',
                                success: function(data) {
                                    // Selecciona el elemento .card-body y cambia su contenido
                                    form.closest('.card-footer').prev('.card-body').html(data.sales);

                                    $('#diff_tab_span').html(data.diff_tab_span);
                                    toastr.success(data.diff_tab_span, { timeOut: 3000 });

                                    // Encuentra el .row contenedor del formulario
                                    var pair_of_tables = form.closest('.pair-of-tables');
                                    // Encuentra el .card-body.table-responsive en el otro col y cambia su contenido
                                    pair_of_tables.find('.col-sm-7 .card-body.table-responsive').html(data.ventas);

                                    // Vamos a modificar el atributo max del input amount
                                    form.find('#amount').attr('max', data.limiteFajilla);

                                    if (data.difference > 0) {
                                        form.find('#amount').attr('disabled', 'disabled');
                                        form.find('button#submitButton').attr('disabled', 'disabled');
                                        form.find('#small_limit').text('Límite alcanzado');
                                    } else if(Math.abs(data.difference) < data.limiteFajilla) {
                                        form.find('#amount').attr('max', Math.abs(data.difference).toFixed(2));
                                        form.find('#small_limit').text('Límite máximo: $' + Math.abs(data.difference).toFixed(2));
                                    }

                                    form.removeClass('loading');
                                },
                                error: function(xhr, textStatus, errorThrown) {
                                    console.error('AJAX error:', errorThrown);
                                },
                                complete: function() {
                                    form.closest('.card-footer').prev('.card-body').removeClass('loading');
                                }
                            });
                        } else {
                            toastr.error(data.message, '¡Error!', { timeOut: 1000 });
                        }
                    },
                    error: function(xhr) {
                        // Maneja errores aquí
                        console.error(xhr.responseText);
                    },
                    complete: function() {
                        // Habilitar nuevamente el botón de envío del formulario después de que la solicitud AJAX se complete
                        $('#row_' + codisl).removeClass('loading');
                    }
                });
            },
            function() {
                toastr.info('Operación cancelada', '¡Atención!', { timeOut: 3000 });
            }
        ).set({onshow:function(){ console.log('prompt was shown.')}});
        $('.selectpicker').selectpicker();
        // Escondemos el input de texto
        $('input.ajs-input').hide();
    }


});



let inventories_table = $('#inventories_table').DataTable({
    colReorder: true,
    order: [0, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Inventarios/Mermas',
            // Prevenimos la exportación de la columna de checkbox y de las acciones
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7]
            }
        }
    ],
    ajax: {
        url: '/operations/inventories_table',
        data: {
            "from":  $("input#from").val(),
            "until":    $("input#until").val(),
        },
        error: function() {
            $('#inventories_table').waitMe('hide');
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
        {'data': 'ESTACION'},
        {'data': 'PRODUCTO'},
        {'data': 'SALDOINICIAL', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'COMPRAS', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'VENTAS', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'SALDOFINAL', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'SALDOREAL', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'MERMA', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'ACCIONES'},
    ],
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-inventories_table input').on('keyup  change clear', function () {
    inventories_table
        .column(0).search($('#ESTACION').val().trim())
        .column(1).search($('#PRODUCTO').val().trim())
        .column(2).search($('#SALDOINICIAL').val().trim())
        .column(3).search($('#COMPRAS').val().trim())
        .column(4).search($('#VENTAS').val().trim())
        .column(5).search($('#SALDOFINAL').val().trim())
        .column(6).search($('#SALDOREAL').val().trim())
        .column(7).search($('#MERMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_inventories_table').on('click', function () {
    inventories_table.clear().draw();
    inventories_table.ajax.reload();
    $('#inventories_table').waitMe('hide');
});




let inventories_details_table = $('#inventories_details_table').DataTable({
    colReorder: true,
    order: [0, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Inventarios por estación',
            // Prevenimos la exportación de la columna de checkbox y de las acciones
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6]
            }
        }
    ],
    ajax: {
        url: '/operations/inventories_details_table',
        data: {
            "from":   $("input#from").val(),
            "until":  $("input#until").val(),
            "codgas": $("input#codgas").val(),
            "codprd": $("input#codprd").val(),
        },
        error: function() {
            $('#inventories_details_table').waitMe('hide');
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
        {'data': 'PRODUCTO'},
        {'data': 'SALDOINICIAL', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'COMPRAS', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'VENTAS', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'SALDO', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'SALDOREAL', 'render': $.fn.dataTable.render.number( ',', '.', 3)},
        {'data': 'MERMA', 'render': $.fn.dataTable.render.number( ',', '.', 3)}
    ],
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-inventories_details_table input').on('keyup  change clear', function () {
    inventories_details_table
        .column(0).search($('#FECHA').val().trim())
        .column(1).search($('#SALDOINICIAL').val().trim())
        .column(2).search($('#COMPRAS').val().trim())
        .column(3).search($('#VENTAS').val().trim())
        .column(4).search($('#SALDO').val().trim())
        .column(5).search($('#SALDOREAL').val().trim())
        .column(6).search($('#MERMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_inventories_details_table').on('click', function () {
    inventories_details_table.clear().draw();
    inventories_details_table.ajax.reload();
    $('#inventories_details_table').waitMe('hide');
});

inventories_details_table.on('draw', function() {
    // Supongamos que la columna que quieres sumar es la columna con índice 2
    var total = inventories_details_table.column(8, { search: 'applied' }).data().reduce(function(a, b) {
        return parseFloat(a) + parseFloat(b);
    }, 0);

    // Actualiza el total en algún elemento de la página
    // $('#totalDisplay').html(total.toFixed(2)); // Asumiendo que #totalDisplay es el ID de un elemento donde quieres mostrar el total
});


var sales_stations_table = $('#sales_stations_table').DataTable({
    colReorder: true,
    order: [0, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    responsive: true,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            filename: 'Reporte de ventas por estación'
        }
    ],
    ajax: {
        // Enviamos por post
        data: {'from': $('input#from').val(), 'until': $('input#until').val(), 'codgas': $('#codgas').val(), 'codprd': $('input#codprd').val()},
        method: 'post',
        url: '/operations/sales_stations',
        error: function() {
            $('#sales_stations_table').waitMe('hide');
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
        {'data': 'Estación'},
        {'data': 'Producto'},
        {'data': 'Despachos', 'render': $.fn.dataTable.render.number( '', '.', 0)},
        {'data': 'Volumen', render: $.fn.dataTable.render.number( '', '.', 3 )},
        {'data': 'Precio'},
        {'data': 'Importe', render: $.fn.dataTable.render.number( '', '.', 2, '$' )},
        {'data': 'Crédito', render: $.fn.dataTable.render.number( '', '.', 3)},
        {'data': 'Débito', render: $.fn.dataTable.render.number( '', '.', 3 )},
        {'data': 'Acciones'}
    ],
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    },
    footerCallback: function (row, data, start, end, display) {

    }
});

// Agregar un evento clic de refresh
$('.refresh_sales_stations_table').on('click', function () {
    sales_stations_table.clear().draw();
    sales_stations_table.ajax.reload();
    $('#sales_stations_table').waitMe('hide');
});

async function sales_day_table(dynamicColumns){

    if ($.fn.DataTable.isDataTable('#sales_day_table')) {
        $('#sales_day_table').DataTable().destroy();  // Destruye la tabla existente
        $('#sales_day_table thead').empty(); // Limpia el encabezado
        $('#sales_day_table tbody').empty(); // Limpia el cuerpo
        $('#sales_day_table tfoot').empty(); // Limpia el pie de tabla si lo usas
        console.log('Tabla destruida');
    }
    const params = {
        fromDate: document.getElementById('from').value,
        untilDate: document.getElementById('until').value,
        shift: document.getElementById('shift').value,
        id_producto: document.getElementById('id_producto').value
    };
   let sales_day_table =$('#sales_day_table').DataTable({
        //  order:{[0, "desc"] [1, "asc"]},
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        ordering: false,
        colReorder: false,
        dom: '<"top"Bf>rt<"bottom"lip>',
        fixedColumns: {
            leftColumns: 5
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
            data: params,
            url: '/operations/sales_day_table',
            error: function(xhr, status, error) {
                console.error('Ajax error:', {xhr, status, error});

                $('#sales_day_table').waitMe('hide');
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
            complete: function (xhr, status) {

                $('.table-responsive').removeClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        destroy: true, 
        rowId: 'year',
        createdRow: function (row, data, dataIndex) {
            if (data['codprd']=='0') {
                $('td:eq(0)', row).addClass('total_bg');
                $('td:eq(1)', row).addClass('total_bg');

                // Aplicar la clase 'total_bg2' al resto de las columnas
                $('td:gt(1)', row).addClass('total_bg2');
            }

        },
        initComplete: function (settings, json) {
            console.log('DataTable initialization complete');
            // $('.dt-buttons').addClass('d-none');

            $('.table-responsive').removeClass('loading');
            
        },
        footerCallback: function (row, data, start, end, display) {

        },
      
    });
    
    $('.sales_day_table').on('click', function () {
        sales_day_table.clear().draw();
        sales_day_table.ajax.reload();
        $('#sales_day_table').waitMe('hide');
    });

}


 async function generateDateColumnsSalesDay(table) {
    estaciones = [];
    try{
        const response = await fetch('/operations/get_estations', {
            method: 'POST',
        });
        const data = await response.json();
        estaciones = data;

    }catch (error) {
        console.error('Error al obtener las opciones:', error);
        return '<option>Error al cargar opciones</option>';
    }
    const columns = [];
    columns.push(
        { data: 'year',title:'Año', className: 'text-left text-nowrap bg-info-subtle'},
        { data: 'mounth',title:'Mes', className: 'text-left text-nowrap bg-info-subtle'},
        { data: 'day',title:'Dia', className: 'text-left text-nowrap bg-info-subtle'},
        { data: 'turn',title:'Turno', className: 'text-left text-nowrap bg-info-subtle'},
    );
    if (table =='seach_sales_day') {
        columns.push(
            { data: 'product',title:'Producto', className: 'text-left text-nowrap bg-info-subtle'},        );
    }
    var  thead;
    if (table == 'seach_sales_day') {

         thead = $('#dynamicHeadersTable');
    }else if (table == 'seach_sales_day_shif') {

         thead = $('#dynamicHeadersTableShif');
    }
     thead.html('');
     thead.empty();
     let rowContent = '<tr>'; // Inicia una fila nueva
     rowContent += '<th>Año</th>';
     rowContent += '<th>Mes</th>';
     rowContent += '<th>Dia</th>';
     rowContent += '<th>Turno</th>';
     if (table == 'seach_sales_day') {

         rowContent += '<th>Producto</th>';
     }
    // Si tienes más columnas dinámicas, descomenta y agrega aquí:
    estaciones.forEach(station => {
        var cod = station.codigo;
        var station_name = station.estacion_nombre.slice(2);
        columns.push({
            data: `${cod}`,
            title: `${station_name}`,
            className: 'text-end text-nowrap'
        });
        rowContent += `<th class="header_small">${station_name}</th>`;
    });

        
    rowContent += '</tr>'; // Cierra la fila
    thead.append(rowContent); // Inserta la fila completa al thead


    return columns;
}


async function sales_day_table_shif(dynamicColumns){

    if ($.fn.DataTable.isDataTable('#sales_day_table_shif')) {
        $('#sales_day_table_shif').DataTable().destroy();  // Destruye la tabla existente
        $('#sales_day_table_shif thead').empty(); // Limpia el encabezado
        $('#sales_day_table_shif tbody').empty(); // Limpia el cuerpo
        $('#sales_day_table_shif tfoot').empty(); // Limpia el pie de tabla si lo usas
        console.log('Tabla destruida');
    }
    const params = {
        fromDate: document.getElementById('from2').value,
        untilDate: document.getElementById('until2').value,
        shift: document.getElementById('shift2').value,
        id_producto: document.getElementById('id_producto2').value
    };
   let sales_day_table_shif =$('#sales_day_table_shif').DataTable({
        //  order:{[0, "desc"] [1, "asc"]},
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
        ordering: false,
        colReorder: false,
        dom: '<"top"Bf>rt<"bottom"lip>',
        fixedColumns: {
            leftColumns: 4
        },
        rowGroup: {
            dataSrc: 'day' // Aquí defines la columna por la que se agruparán las filas (usa el nombre de tu data)
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
            data: params,
            url: '/operations/sales_day_table_shif',
            error: function(xhr, status, error) {
                console.error('Ajax error:', {xhr, status, error});

                $('#sales_day_table_shif').waitMe('hide');
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
                console.log('Cargando tabla');
                $('.table-responsive').addClass('loading');
            },
            complete: function (xhr, status) {
                console.log('Ajax request completed with status:', status);

                $('.table-responsive').removeClass('loading');
            }
        },
        deferRender: true,
        columns: dynamicColumns,
        destroy: true, 
        rowId: 'year',
        createdRow: function (row, data, dataIndex) {
            if (data['turn']=='Total') {
                $('td:eq(0)', row).addClass('total_bg');
                $('td:eq(1)', row).addClass('total_bg');

                // Aplicar la clase 'total_bg2' al resto de las columnas
                $('td:gt(1)', row).addClass('total_bg2');
            }
        },
        initComplete: function (settings, json) {
            console.log('DataTable initialization complete');

            // $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        },
        footerCallback: function (row, data, start, end, display) {

        },
        // drawCallback: function () {
        //     let api = this.api();
        //     let rows = api.rows({ page: 'current' }).nodes();
        //     let last = null;
    
        //     // Objeto para almacenar las sumas
        //     let daySums = {};
    
        //     api.rows({ page: 'current' }).data().each(function (data, index) {
        //         let day = data.day; // Ajusta según tu columna "día"
        //         if (!daySums[day]) {
        //             daySums[day] = {};
        //         }
    
        //         // Sumar valores de las columnas dinámicas (ajusta según tus columnas)
        //         Object.keys(data).forEach(key => {
        //             if (key !== 'day' && !isNaN(parseFloat(data[key]))) {
        //                 daySums[day][key] = (daySums[day][key] || 0) + parseFloat(data[key]);
        //             }
        //         });
        //     });
    
        //     // Agregar la fila con el total después de cada grupo
        //     $(rows).each(function () {
        //         let row = $(this);
        //         let day = api.row(row).data().day;
    
        //         if (day !== last) {
        //             let totals = daySums[day];
        //             let totalRow = '<tr class="group-sum" data-day='+day+'><td>Total</td><td></td><td></td><td></td><td></td>';
    
        //             Object.values(totals).forEach(value => {
        //                 totalRow += `<td>${value.toFixed(2)}</td>`;
        //             });
    
        //             totalRow += '</tr>';
        //             row.before(totalRow);
        //             last = day;
        //         }
        //     });
           
        // }
    });
    
    $('.sales_day_table_shif').on('click', function () {
        sales_day_table_shif.clear().draw();
        sales_day_table_shif.ajax.reload();
        $('#sales_day_table_shif').waitMe('hide');
    });

}
async function sale_day_base_table(){
    if ($.fn.DataTable.isDataTable('#sale_day_base_table')) {
        $('#sale_day_base_table').DataTable().destroy();
        $('#sale_day_base_table thead .filter').remove();
    }
    var fromDate = document.getElementById('from3').value;
    var untilDate = document.getElementById('until3').value;
    var zona = document.getElementById('zona3').value;

    $('#sale_day_base_table thead').prepend($('#sale_day_base_table thead tr').clone().addClass('filter'));
    $('#sale_day_base_table thead tr.filter th').each(function (index) {
        col = $('#sale_day_base_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#sale_day_base_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#sale_day_base_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let sale_day_base_table =$('#sale_day_base_table').DataTable({
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
            url: '/operations/sale_day_base_table',
            error: function() {
                $('#sale_day_base_table').waitMe('hide');
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
            {'data': 'Fecha'},
            {'data': 'year'},
            {'data': 'mounth'},
            {'data': 'day'},
            {'data': 'CodGasolinera'},
            {'data': 'turn'},
            {'data': 'VentasReales'},
            {'data': 'Producto'},
            {'data': 'Estacion'},
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










async function sales_cash_hour_table(){
    let chartAlreadyDrawn = false;

    if ($.fn.DataTable.isDataTable('#sales_cash_hour_table')) {
        $('#sales_cash_hour_table').DataTable().destroy();  // Destruye la tabla existente
        $('#sales_cash_hour_table thead').empty(); // Limpia el encabezado
        $('#sales_cash_hour_table tbody').empty(); // Limpia el cuerpo
        $('#sales_cash_hour_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;
    var codgas = document.getElementById('codgas').value;
    if(codgas == ''){
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Seleccione una estacion</p>
            </div>
            `);
            return;
    }

    var dynamicColumns = getHourDateColumns(fromDate, untilDate, 'sales_cash_hour_table');
    updateTableHeadersByDay(fromDate, untilDate, 'sales_cash_hour_table');


    let sales_cash_hour_table =$('#sales_cash_hour_table').DataTable({
        order: [0, "asc"],
        colReorder: false,
        // columnDefs: [{ visible: true, targets: groupColumn }],
        dom: '<"top"Bf>rt<"bottom"lip>',
        // pageLength: 150,
        scrollX: true,
        paging: false,
        fixedColumns: {
            leftColumns: 1,
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
                'codgas':codgas,
                'dinamicColumns': dynamicColumns
            },
            url: '/operations/sales_cash_hour_table',
            error: function() {
                $('#sales_cash_hour_table').waitMe('hide');
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
            // Calcular máximo por columna una sola vez
            if (!window.columnMaxValues) {
                window.columnMaxValues = {};
                this.api().columns().every(function (colIndex) {
                    if (colIndex === 0) return; // Saltar 'Hora'
                    let max = 0;
                    this.data().each(function (value) {
                        let numericVal = typeof value === 'string' ? parseFloat(value.replace(/[^0-9.-]+/g, '')) : value;
                        numericVal = isNaN(numericVal) ? 0 : numericVal;
                        if (numericVal > max) {
                            max = numericVal;
                        }
                    });
                    window.columnMaxValues[colIndex] = max;
                });
            }
            // Aplicar clase a la celda si es igual al valor máximo de su columna
            $('td', row).each(function (i) {
                if (i === 0) return; // Saltar columna 'Hora'
                const cellText = $(this).text().replace(/[^0-9.-]+/g, '');
                const cellValue = parseFloat(cellText);
                const isMax = !isNaN(cellValue) && cellValue === window.columnMaxValues[i];
        
                if (isMax) {
                    $(this).addClass('bg-success text-white fw-bold');
                }
            });
        },        
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            window.columnMaxValues = null;
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
        },
        footerCallback: function (row, data, start, end, display) {
        }
    }).on('xhr.dt', function (e, settings, json, xhr) {
        if (json && json.data && !chartAlreadyDrawn) {
            drawSalesHourChart(json.data);
            chartAlreadyDrawn = true;
        }
    });
    $('#filtro-sales_cash_hour_table input').on('keyup  change clear', function () {
        sales_cash_hour_table
            .column(0).search($('#Empresa4').val().trim())
            .column(1).search($('#Descripcion4').val().trim())
            .column(2).search($('#MedioPago4').val().trim())
            .draw();
      });
      $('.sales_cash_hour_table').on('click', function () {
        chartAlreadyDrawn = false;
        sales_cash_hour_table.clear().draw();
        sales_cash_hour_table.ajax.reload();
        $('#sales_cash_hour_table').waitMe('hide');
    });
}

function getHourDateColumns(fromDate, untilDate, tableId) {
    const startDate = new Date(fromDate + "T00:00:00");
    const endDate = new Date(untilDate + "T00:00:00");
    const columns = [];

    // Columna fija: hora
    columns.push({ data: 'Hora', title: 'Hora', className: 'text-center table-info' });

    const formatDate = (date) => {
        return date.toISOString().split('T')[0]; // formato yyyy-mm-dd
    };

    const formatTitle = (date) => {
        return date.toLocaleDateString('es-MX', { weekday: 'short', day: '2-digit' });
        // Ejemplo: 'lun. 03'
    };

    const dateIterator = new Date(startDate);
    while (dateIterator <= endDate) {
        const dateKey = formatDate(dateIterator);     // '2024-06-01'
        const dateTitle = formatTitle(dateIterator);  // 'lun. 01'

        columns.push({
            data: dateKey,
            title: dateTitle.charAt(0).toUpperCase() + dateTitle.slice(1), // Capitaliza la primera letra
            render: $.fn.dataTable.render.number(',', '.', 2, '$'),
            className: 'text-end text-nowrap'
        });

        dateIterator.setDate(dateIterator.getDate() + 1);
    }

    return columns;
}
function updateTableHeadersByDay(fromDate, untilDate, tableId) {
    const startDate = new Date(fromDate + "T00:00:00");
    const endDate = new Date(untilDate + "T00:00:00");

    let theadHTML = '<tr><th>Hora</th>';
    let tfootHTML = '<tr><th>Hora</th>';

    const formatDate = (date) => {
        return date.toISOString().split('T')[0];
    };

    const dateIterator = new Date(startDate);
    while (dateIterator <= endDate) {
        const dateKey = formatDate(dateIterator);
        theadHTML += `<th>${dateKey}</th>`;
        tfootHTML += `<th></th>`;
        dateIterator.setDate(dateIterator.getDate() + 1);
    }

    theadHTML += '</tr>';
    tfootHTML += '</tr>';

    const table = document.getElementById(tableId);
    table.getElementsByTagName('thead')[0].innerHTML = theadHTML;
    table.getElementsByTagName('tfoot')[0].innerHTML = tfootHTML;
}
function drawSalesHourChart(data) {
    // Obtener etiquetas (horas) desde el primer objeto
    const horas = data.map(row => row.Hora.toString().padStart(2, '0')); // ['00', '01', ... '23']

    // Obtener los días (claves distintas de 'Hora')
    const allKeys = Object.keys(data[0]);
    const dias = allKeys.filter(k => k !== 'Hora');
    const promedioData = data.map(row => {
        const valoresHora = dias.map(dia => {
            const val = parseFloat((row[dia] || 0).toString().replace(/[^0-9.-]+/g, ''));
            return isNaN(val) ? 0 : val;
        });
    
        const suma = valoresHora.reduce((a, b) => a + b, 0);
        return (suma / valoresHora.length).toFixed(2); // Promedio por hora
    });
    const datasets = [
        {
            label: 'Promedio horario',
            data: promedioData,
            borderColor: '#000000',
            backgroundColor: '#000000',
            borderDash: [5, 5],
            fill: false,
            tension: 0.2,
            borderWidth: 2,
            pointRadius: 0
        },
        // ...tus líneas por día vienen después
        ...dias.map((dia, index) => {
            const color = getRandomColor(index);
            const valores = data.map(row => parseFloat((row[dia] || 0).toString().replace(/[^0-9.-]+/g, '')));
    
            // Encontrar la posición del máximo
            const maxVal = Math.max(...valores);
            const highlightPoints = valores.map(v => (v === maxVal ? 6 : 0)); // punto grande si es el mayor
    
            return {
                label: dia,
                data: valores,
                borderColor: color,
                backgroundColor: color,
                fill: false,
                tension: 0.3,
                borderWidth: 2,
                pointRadius: highlightPoints,
                pointHoverRadius: highlightPoints.map(p => (p > 0 ? 8 : 4)),
            };
        })
    ];
    // Construir datasets por día
    
    // Destruir instancia anterior si existe
    if (window.salesHourChartInstance) {
        window.salesHourChartInstance.destroy();
    }

    const ctx = document.getElementById('salesHourLineChart').getContext('2d');
    window.salesHourChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: horas,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            stacked: false,
            plugins: {
                title: {
                    display: true,
                    text: 'Ingresos por Hora (Comparativa por Día)'
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                },
                legend: {
                    position: 'bottom'
                }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        text: 'Hora'
                    }
                },
                y: {
                    title: {
                        display: true,
                        text: 'Monto en Pesos'
                    },
                    beginAtZero: true
                }
            }
        }
    });
}

function getRandomColor(index) {
    const colors = [
        '#007bff', '#28a745', '#dc3545', '#ffc107', '#17a2b8',
        '#6f42c1', '#fd7e14', '#20c997', '#e83e8c', '#343a40'
    ];
    return colors[index % colors.length]; // Cicla si hay más días que colores
}