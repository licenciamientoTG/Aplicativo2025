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

let movimientoActual = {};
let facturaProveedorSeleccionada = null;
let facturaPetrotalSeleccionada = null;


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



function add_payment() {
    // Redirigir a la página de agregar pago
    window.location.href = '/supply/add_payment';
}
let selectedInvoices = new Set();


let paymentTable = null;

async function payment_create_table(){
    var fromDate = document.getElementById('from1').value;
    var untilDate = document.getElementById('until1').value;
    var codgas = document.getElementById('station_id1').value;
    var company = document.getElementById('company').value;
    var proveedor = document.getElementById('proveedor_id').value;
    if(!codgas || !company || !proveedor || codgas == 'Seleccione' || company == 'Seleccione' || proveedor == 'Seleccione'){
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
     if (paymentTable) {

        // Actualizar parámetros AJAX
        paymentTable.settings()[0].ajax.data = {
            'fromDate': fromDate,
            'untilDate': untilDate,
            'codgas': codgas,
            'company': company,
            'proveedor': proveedor
        };

        // Recargar datos
        paymentTable.ajax.reload(function() {
            // Este callback se ejecuta DESPUÉS de cargar los datos
            $('.table-responsive').removeClass('loading');
            updateSelectedCount();
            console.log('✅ Datos recargados correctamente');
        }, false); // false = mantener página actual

        return;
    }

    console.log('🆕 Creando tabla por primera vez...');

    paymentTable = $('#payment_create_table').DataTable({
        order: [[0, "desc"], [1, "desc"]],
        colReorder: false,
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
            {
                data: null,
                orderable: false,
                className: 'text-center text-nowrap',
                render: function (data, type, row) {
                    if (row.en_orden_pago != 0) {
                        return ''; // o un ícono si quieres
                    }
                    return `
                        <input type="checkbox"
                            class="invoice-checkbox"
                            data-nro="${row.nro}"
                            data-factura="${row.Factura}" data-codigo_empresa="${row.codigo_empresa}"
                            data-codgas="${row.codgas}" data-proveedor_codigo="${row.proveedor_codigo}" 
                            onchange="updateSelectedCount()">
                    `;
                }
            },
            { data: 'nro' },
            { data: 'Factura' },
            { data: 'gasolinera', className: 'text-center text-nowrap' },
            { data: 'proveedor', className: 'text-center text-nowrap' },
            { data: 'fecha', className: 'text-center text-nowrap' },
            { data: 'fechaVto', className: 'text-center text-nowrap' },
            { data: 'total_fac', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'producto', className: 'text-center text-nowrap' },
            { data :'statusLabel'},
            { data: 'satuid',visible: false,searchable: false    }
        ],
         columnDefs: [
                    { orderable: false, targets: 0 }
                ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
            if (data.en_orden_pago != 0) {
                $(row).addClass('bg_send');
            }else{

                $(row).addClass('draggable-row');
                $(row).attr('draggable', 'true');
                $(row).data('rowData', data);
                $(row).find('td:first').prepend('<i class="fas fa-grip-vertical drag-handle me-2" style="color: #6c757d; cursor: move;"></i>');

                // data.fechaVto = 'YYYY-MM-DD'
                const partes = data.fechaVto.split('-');
                const fechaVto = new Date(
                    parseInt(partes[0]),
                    parseInt(partes[1]) - 1,
                    parseInt(partes[2])
                );

                const hoy = new Date();
                hoy.setHours(0, 0, 0, 0);

                if (fechaVto < hoy) {
                    $('td', row).eq(6).addClass('bg-danger text-white text-center');
                }
            }
        },
        initComplete: function () {
            const api = this.api();

            if ($('#payment_create_table thead tr.filter').length === 0) {
                const filterRow = $('<tr class="filter"></tr>');

                $('#payment_create_table thead tr:first th').each(function (index) {
                    if (index === 0) {
                        filterRow.append('<th></th>');
                    } else {
                        // ✅ RESTO DE COLUMNAS: Con filtro
                        filterRow.append(
                            `<th>
                                <input type="text"
                                    class="form-control form-control-sm"
                                    placeholder="${$(this).text().trim()}">
                            </th>`
                        );
                    }
                });

                $('#payment_create_table thead').prepend(filterRow);

                // Eventos de búsqueda
                $('#payment_create_table thead tr.filter input')
                    .on('keyup change', function () {
                        const colIndex = $(this).parent().index();
                        api.column(colIndex).search(this.value).draw();
                    });
            }
            $('.table-responsive').removeClass('loading');
            setupDragAndDrop();
            updateSelectedCount();
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación
            console.log('🎯 Filtros agregados:', $('#payment_create_table thead tr.filter').length);

        },
         drawCallback: function(settings) {
            // ✅ ACTUALIZAR CONTADOR DESPUÉS DE CADA REDIBUJADO
            updateSelectedCount();
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
    // let movimientoActual = {};

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
        colReorder: false,
        dom: '<"top"Bf>rt<"bottom"lip>',
        scrollX: true,
        scrollY: 'calc(100vh - 350px)',
        scrollCollapse: true,
        paging: false,
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
            { data: 'FechaRecepcion', className: 'text-left text-nowrap' },
            {data: 'NumeroEstacion',className: 'text-left text-nowrap'},
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
            // if (data.EstadoAsignacion === 'PENDIENTE' && data.NumeroEstacion !== '00') {
            //     $(row).addClass('table-warning');
            // }
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

    // let movimientoActual = {};

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

// ==========================================
// FUNCIONES PARA GESTIÓN DE PAGOS
// ==========================================
function showPaymentLoader(text = 'Procesando pago...', subtext = 'Por favor espere') {
    const loader = document.getElementById('paymentLoader');
    if (loader) {
        loader.querySelector('.payment-loader-text').textContent = text;
        loader.querySelector('.payment-loader-subtext').textContent = subtext;
        loader.classList.add('active');
        
        // Deshabilitar scroll del body
        document.body.style.overflow = 'hidden';
    }
}

/**
 * Oculta el loader
 */
function hidePaymentLoader() {
    const loader = document.getElementById('paymentLoader');
    if (loader) {
        loader.classList.remove('active');
        
        // Restaurar scroll del body
        document.body.style.overflow = '';
    }
}
// Mejorar generate_payment() existente
async function generatePayment() {
   if (paymentItems.length === 0) {
        alertify.myAlert(
            `<div class="container text-center text-warning">
                <h4 class="mt-2 text-warning">¡Advertencia!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">No hay documentos en el pago.</p>
            </div>`
        );
        return;
    }

    const primerItem = paymentItems[0];
    const proveedorCodigo = primerItem.proveedor_codigo || primerItem.id_control_gas || null;
    var codigo_empresa = primerItem.codigo_empresa || null;
    
    if (!proveedorCodigo) {
        alertify.error('Error: No se pudo obtener el código del proveedor');
        return;
    }
    
    // Solicitar comentario
    alertify.prompt(
        'Comentario del Pago',
        'Ingrese un comentario o descripción para este pago:',
        '',
        async function(evt, comment) {
            showPaymentLoader('Creando pago...', 'Procesando documentos');

            const paymentData = {
                documentos: paymentItems,
                total_documentos: paymentItems.length,
                total_amount: paymentItems.reduce((sum, item) => sum + (parseFloat(item.total_fac) || 0), 0),
                fecha_pago: new Date().toISOString().split('T')[0],
                comment: comment || 'Pago programado',
                provider_cod: proveedorCodigo,  // ✅ AGREGADO
                provider_name: currentProvider,  // ✅ OPCIONAL
                empresa_cod: codigo_empresa  // ✅ AGREGADO
            };

            console.log('📤 Datos enviados:', paymentData); // Debug


            try {
                const response = await fetch('/supply/generate_payment', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(paymentData)
                });

                const data = await response.json();
                hidePaymentLoader();
                if (data.success) {
                    alertify.success('Pago creado exitosamente: ID #' + data.payment_id);

                    // Limpiar carrito
                    paymentItems = [];
                    currentProvider = null; // ✅ Resetear proveedor
                    renderPaymentItems();
                    updatePaymentSummary();

                    // Preguntar si desea ver el detalle
                    alertify.confirm(
                        '¿Ver detalle del pago?',
                        '¿Desea ver el detalle del pago creado?',
                        function() {
                            window.location.href = '/supply/payment_detail/' + data.payment_id;
                        },
                        function() {
                            // Recargar tabla
                            if ($.fn.DataTable.isDataTable('#payment_create_table')) {
                                $('#payment_create_table').DataTable().ajax.reload();
                            }
                        }
                    );
                } else {
                    alertify.error('Error: ' + data.detail);
                }
            } catch (error) {
                hidePaymentLoader();
                alertify.error('Error de conexión');

                console.error(error);
            }
        },
        function() {
            alertify.message('Operación cancelada');
        }
    );
}


function loadPaymentList() {
    if ($.fn.DataTable.isDataTable('#payment_list_table')) {
        $('#payment_list_table').DataTable().destroy();
    }
    const status = $('#status_filter').val();
    
    paymentListTable = $('#payment_list_table').DataTable({
        ajax: {
            url: '/supply/payment_list_table',
            type: 'POST',
            data: {

                status: status,
                type: 'payment'
            },
            error: function(xhr, error, thrown) {
                alertify.error('Error al cargar datos: ' + thrown);
            }
        },
        columns: [
            { data: 'id' },
            { data: 'request_date' },
            { data: 'emp_name' },
            { data: 'provider_name' },
            { data: 'usuario' },
            { data: 'total_invoices', className: 'text-center' },
            { data: 'total_amount', className: 'text-end' },
            { data: 'total_paid', className: 'text-end' },
                        { 
                // ✅ NUEVA COLUMNA: Facturas Autorizadas
                data: null,
                className: 'text-center',
                render: function(data, type, row) {
                    var count = parseInt(row.authorized_invoices_count) || 0;
                    var rawAmount = row.authorized_amount_total || '0';
                    rawAmount = rawAmount.replace(/[$,]/g, '');
                    var authorized_amount_total = parseFloat(rawAmount) || 0;
                    if (count === 0) {
                        return '<span class="badge bg-secondary">Sin autorizar</span>';
                    }

                    // Calcular porcentaje
                    var totalInvoices = parseInt(row.total_invoices) || 0;
                    var percentage = totalInvoices > 0 
                        ? Math.round((count / totalInvoices) * 100) 
                        : 0;

                    let badgeColor = 'bg-warning';
                    if (percentage === 100) {
                        badgeColor = 'bg-success';
                    } else if (percentage >= 50) {
                        badgeColor = 'bg-info';
                    }
                    return `
                        <div class="text-center">
                            <span class="badge ${badgeColor}" style="font-size: 0.85rem;">
                                <i class="fas fa-check-circle"></i> ${count} de ${totalInvoices}
                            </span>
                            <br>
                            <small class="text-success fw-bold">
                                $${authorized_amount_total.toLocaleString('es-MX', {minimumFractionDigits: 2})}
                            </small>
                        </div>
                    `;
                }
            },
            { data: 'status', className: 'text-center' },
            { data: 'authorizations', className: 'text-center' },
            { data: 'comment' },
            { data: 'actions', orderable: false, className: 'text-center' }
        ],
        order: [[0, 'desc']],
        // language: {
        //     url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json'
        // }
    });
}

function loadAnticiposList() {
    console.log('Cargando lista de anticipos...');
    if ($.fn.DataTable.isDataTable('#tabla_anticipos')) {
        $('#tabla_anticipos').DataTable().destroy();
    }

    const status = $('#status_filter').val();
    paymentListTable = $('#tabla_anticipos').DataTable({
        dom: '<"top"f>rt<"bottom"lip>',
        ajax: {
            url: '/supply/loadAnticiposList',
            type: 'POST',
            data: {
                status: status,
                type: 'anticipos'
            },
            error: function(xhr, error, thrown) {
                alertify.error('Error al cargar datos: ' + thrown);
            }
        },
        columns: [
            { data: 'id' },
            { data: 'request_date' },
            { data: 'emp_name' },
            { data: 'provider_name' },
            { data: 'usuario' },
            { 
                data: 'total_invoices', 
                className: 'text-center',
                render: function(data) {
                    return data > 0 
                        ? '<span class="badge bg-info">' + data + '</span>'
                        : '<span class="badge bg-secondary">0</span>';
                }
            },
            { data: 'total_amount', className: 'text-end' },
            { data: 'total_aplicado', className: 'text-end' }, // ✅ CAMBIO
            { 
                data: 'saldo_disponible', 
                className: 'text-end',
                render: function(data) {
                    const saldo = parseFloat(data.replace(/[$,]/g, ''));
                    const color = saldo > 0 ? 'success' : 'secondary';
                    return '<strong class="text-' + color + '">' + data + '</strong>';
                }
            }, // ✅ NUEVA
            { data: 'status', className: 'text-center' },
            { data: 'authorizations', className: 'text-center' },
            { data: 'comment' },
            { data: 'actions', orderable: false, className: 'text-center' }
        ],
        order: [[0, 'desc']],
    });
}
function aplicarAnticipo(anticipoId) {
    // Redirigir a la página de detalle del anticipo
    window.location.href = '/supply/anticipo_detail/' + anticipoId;
    
    // O abrir modal (implementación futura)
    // abrirModalAplicarAnticipo(anticipoId);
}
async function abrirModalCrearAnticipo() {
    try {
        // Abrir modal inmediatamente
        $('#modalCrearAnticipo').modal('show');
        
        // Mostrar loader
        $('#modalCrearAnticipoContent').html(`
            <div class="modal-body text-center py-5">
                <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
                <p class="mt-3">Cargando formulario...</p>
            </div>
        `);
        
        // Cargar contenido del modal
        const response = await fetch('/supply/modalCrearAnticipo', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include'
        });
        
        if (!response.ok) {
            throw new Error('Error al cargar el formulario');
        }
        
        const content = await response.text();
        $('#modalCrearAnticipoContent').html(content);
        
        // Reinicializar selectpicker si existe
        if ($.fn.selectpicker) {
            $('.selectpicker').selectpicker();
        }
        
    } catch (error) {
        console.error('Error:', error);
        $('#modalCrearAnticipoContent').html(`
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    Error al cargar el formulario. Por favor intente nuevamente.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        `);
    }
}
/**
 * Actualizar resumen del anticipo en tiempo real
 */
$(document).on('change input', '#anticipo_proveedor, #anticipo_empresa, #anticipo_monto', function() {
    actualizarResumenAnticipo();
});

/**
 * Contador de caracteres del comentario
 */
$(document).on('input', '#anticipo_comentario', function() {
    const length = $(this).val().length;
    $('#comentario_chars').text(length);
});


function actualizarResumenAnticipo() {
    const proveedor = $('#anticipo_proveedor option:selected').text();
    const empresa = $('#anticipo_empresa option:selected').text();
    const monto = parseFloat($('#anticipo_monto').val()) || 0;
    
    if (proveedor && proveedor !== 'Seleccione un proveedor' && monto > 0) {
        $('#resumen_proveedor').text(proveedor);
        $('#resumen_empresa').text(empresa || 'No seleccionada');
        $('#resumen_monto').text('$' + monto.toLocaleString('es-MX', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }));
        $('#resumen_anticipo').slideDown();
    } else {
        $('#resumen_anticipo').slideUp();
    }
}

/**
 * Confirmar creación del anticipo
 */
function confirmarCreacionAnticipo() {
    // Validar formulario
    const proveedor_cod = $('#anticipo_proveedor').val();
    const empresa_cod = $('#anticipo_empresa').val();
    const monto = parseFloat($('#anticipo_monto').val());
    const comentario = $('#anticipo_comentario').val().trim();
    
    // Validaciones
    if (!proveedor_cod) {
        alertify.error('Debe seleccionar un proveedor');
        return;
    }
    
    if (!empresa_cod) {
        alertify.error('Debe seleccionar una empresa');
        return;
    }
    
    if (!monto || monto <= 0) {
        alertify.error('El monto debe ser mayor a cero');
        return;
    }
    
    if (!comentario || comentario.length < 10) {
        alertify.error('La justificación debe tener al menos 10 caracteres');
        return;
    }
    
    // Obtener nombres para confirmación
    const proveedor_nombre = $('#anticipo_proveedor option:selected').text();
    const empresa_nombre = $('#anticipo_empresa option:selected').text();
    
    // Confirmar con el usuario
    alertify.confirm(
        'Confirmar Anticipo',
        `<div class="text-center">
            <p class="mb-3">¿Crear anticipo con los siguientes datos?</p>
            <div class="alert alert-info mb-3" style="display: flex;    flex-direction: column;">
                <strong>Proveedor:</strong> ${proveedor_nombre}<br>
                <strong>Empresa:</strong> ${empresa_nombre}<br>
                <strong>Monto:</strong> <span class="h5 text-primary">$${monto.toLocaleString('es-MX', {minimumFractionDigits: 2})}</span>
            </div>
            <small class="text-muted">Este anticipo requerirá autorización de 3 niveles antes de poder aplicarse.</small>
        </div>`,
        function() {
            ejecutarCreacionAnticipo(proveedor_cod, empresa_cod, monto, comentario);
        },
        function() {
            alertify.message('Operación cancelada');
        }
    ).set('labels', {ok: 'Crear Anticipo', cancel: 'Cancelar'});
}

/**
 * Ejecutar creación del anticipo (AJAX)
 */
async function ejecutarCreacionAnticipo(proveedor_cod, empresa_cod, monto, comentario) {
    try {
        // Deshabilitar botón
        $('#btnConfirmarAnticipo').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin"></i> Creando...');
        
        const response = await fetch('/supply/create_anticipo', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                provider_cod: proveedor_cod,
                empresa_cod: empresa_cod,
                monto: monto,
                comentario: comentario
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Cerrar modal
            $('#modalCrearAnticipo').modal('hide');
            
            // Mensaje de éxito
            alertify.success('✓ Anticipo creado exitosamente: ID #' + data.anticipo_id);
            
            // Recargar tabla de anticipos si existe
            if ($.fn.DataTable.isDataTable('#tabla_anticipos')) {
                $('#tabla_anticipos').DataTable().ajax.reload(null, false);
            }
            
            // Preguntar si desea ver el detalle
            setTimeout(() => {
                alertify.confirm(
                    '¿Ver detalle?',
                    '¿Desea ver el detalle del anticipo creado?',
                    function() {
                        window.location.href = '/supply/payment_detail/' + data.anticipo_id;
                    },
                    function() {
                        // No hacer nada
                    }
                ).set('labels', {ok: 'Ver Detalle', cancel: 'Cerrar'});
            }, 500);
            
        } else {
            alertify.error(data.message || 'Error al crear anticipo');
            $('#btnConfirmarAnticipo').prop('disabled', false)
                .html('<i class="fas fa-save"></i> Crear Anticipo');
        }
        
    } catch (error) {
        console.error('Error:', error);
        alertify.error('Error de conexión al crear anticipo');
        $('#btnConfirmarAnticipo').prop('disabled', false)
            .html('<i class="fas fa-save"></i> Crear Anticipo');
    }
}


function deletePayment(paymentId) {
    alertify.confirm(
        'Eliminar Pago',
        '¿Está seguro de eliminar este pago programado? Esta acción no se puede deshacer.',
        function() {
            $.ajax({
                url: '/supply/delete_payment',
                type: 'POST',
                data: { payment_id: paymentId },
                success: function(response) {
                    if (response.success) {
                        alertify.success(response.message);
                        loadPaymentList();
                    } else {
                        alertify.error(response.message);
                    }
                },
                error: function() {
                    alertify.error('Error al eliminar el pago');
                }
            });
        },
        function() {
            alertify.message('Operación cancelada');
        }
    );
}

function toggleSelectAll() {
    const selectAllCheckbox = $('#selectAllCheckbox');
    const isChecked = selectAllCheckbox.prop('checked');
    
    $('.invoice-checkbox:visible').prop('checked', isChecked);
    updateSelectedCount();
}

// Actualizar contador de facturas seleccionadas
function updateSelectedCount() {
    const count = $('.invoice-checkbox:checked').length;
    $('#selected-count').text(count + ' seleccionada' + (count !== 1 ? 's' : ''));
    
    const totalVisible = $('.invoice-checkbox:visible').length;
    const totalChecked = $('.invoice-checkbox:checked').length;
    $('#selectAllCheckbox').prop('checked', totalVisible > 0 && totalVisible === totalChecked);
}

function addSelectedInvoices() {
    const table = $('#payment_create_table').DataTable();
    const selectedRows = [];

    // Obtener todas las filas con checkbox marcado
    table.$('input[type="checkbox"].invoice-checkbox:checked').each(function() {
        const row = table.row($(this).closest('tr'));
        selectedRows.push(row.data());
    });

    if (selectedRows.length === 0) {
        alertify.warning('No has seleccionado ninguna factura.');
        return;
    }

    // ✅ Si ya hay un proveedor establecido, filtrar solo ese proveedor
    let dataToAdd = selectedRows;
    if (currentProvider) {
        dataToAdd = selectedRows.filter(row => row.proveedor === currentProvider);
        
        const otherProviders = selectedRows.filter(row => row.proveedor !== currentProvider);
        if (otherProviders.length > 0) {
            alertify.warning(
                `Solo se agregarán ${dataToAdd.length} facturas del proveedor actual: ${currentProvider}<br>` +
                `<small>Se omitieron ${otherProviders.length} facturas de otros proveedores.</small>`
            );
        }
    } else if (selectedRows.length > 0) {
        // ✅ Si no hay proveedor, validar múltiples proveedores en la selección
        const firstProvider = selectedRows[0].proveedor;
        dataToAdd = selectedRows.filter(row => row.proveedor === firstProvider);
        
        if (dataToAdd.length < selectedRows.length) {
            alertify.confirm(
                '¿Múltiples Proveedores Detectados?',
                `Se detectaron facturas de diferentes proveedores en tu selección.<br><br>` +
                `<strong>¿Quieres agregar solo las ${dataToAdd.length} facturas de "${firstProvider}"?</strong><br><br>` +
                `<small class="text-muted">Las ${selectedRows.length - dataToAdd.length} facturas de otros proveedores se omitirán.</small>`,
                function() {
                    // Agregar solo del primer proveedor
                    addFilteredDataToPayment(dataToAdd);
                    
                    // Desmarcar checkboxes
                    table.$('input[type="checkbox"].invoice-checkbox:checked').prop('checked', false);
                    $('#selectAllCheckbox').prop('checked', false);
                    updateSelectedCount();
                },
                function() {
                    // Cancelado - no hacer nada
                    alertify.message('Operación cancelada');
                }
            );
            return; // ✅ Salir para esperar confirmación
        }
    }

    // Agregar facturas filtradas
    addFilteredDataToPayment(dataToAdd);

    // Desmarcar checkboxes
    table.$('input[type="checkbox"].invoice-checkbox:checked').prop('checked', false);
    $('#selectAllCheckbox').prop('checked', false);
    updateSelectedCount();
}
function addFilteredDataToPayment(dataToAdd) {
    let addedCount = 0;
    let skippedCount = 0;

    dataToAdd.forEach(rowData => {
        // Verificar UUID
        if (!rowData.satuid) {
            skippedCount++;
            return;
        }

        // Verificar si ya existe
        const exists = paymentItems.some(item => item.nro === rowData.nro);
        if (!exists) {
            // Establecer proveedor si es el primero
            if (paymentItems.length === 0) {
                currentProvider = rowData.proveedor;
            }
            paymentItems.push(rowData);
            addedCount++;
        } else {
            skippedCount++;
        }
    });

    renderPaymentItems();
    updatePaymentSummary();

    if (addedCount > 0) {
        let message = `✓ Se agregaron ${addedCount} documento(s) al pago.`;
        if (skippedCount > 0) {
            message += ` (${skippedCount} omitidos)`;
        }
        alertify.success(message);
    } else if (skippedCount > 0) {
        alertify.warning('No se agregaron documentos nuevos.');
    }
}

function clearAllPayments() {
    if (paymentItems.length === 0) return;

    alertify.confirm(
        '¿Estás seguro?',
        '¿Quieres limpiar todos los documentos del pago?',
        function() {
            paymentItems = [];
            currentProvider = null; // ✅ Resetear proveedor
            renderPaymentItems();
            updatePaymentSummary();
            alertify.success('Pago limpiado correctamente');
        },
        function() {
            // Cancelado
        }
    );
}
function setupDragAndDrop() {
    // Eventos de drag para las filas de la tabla
    $('#payment_create_table tbody').off('dragstart dragend'); // Limpiar eventos previos
    
    $('#payment_create_table tbody').on('dragstart', 'tr', function(e) {
        $(this).addClass('dragging');
        const rowData = $('#payment_create_table').DataTable().row(this).data();
        e.originalEvent.dataTransfer.setData('text/plain', JSON.stringify(rowData));
    });

    $('#payment_create_table tbody').on('dragend', 'tr', function(e) {
        $(this).removeClass('dragging');
    });

    // Configurar área de drop si no está configurada
    const basket = document.getElementById('payment-basket');
    if (basket && !basket.hasAttribute('data-drop-configured')) {
        basket.setAttribute('data-drop-configured', 'true');

        basket.addEventListener('dragover', function(e) {
            e.preventDefault();
            $(this).addClass('dragover');
        });

        basket.addEventListener('dragleave', function(e) {
            if (!basket.contains(e.relatedTarget)) {
                $(this).removeClass('dragover');
            }
        });

        basket.addEventListener('drop', function(e) {
            e.preventDefault();
            $(this).removeClass('dragover');

            try {
                const rowData = JSON.parse(e.dataTransfer.getData('text/plain'));
                addToPayment(rowData);
            } catch (error) {
                console.error('Error al procesar el elemento:', error);
            }
        });
    }
}

// Añadir elemento al pago
function addToPayment(rowData) {
    provider_name = rowData.proveedor;
    if (!rowData.satuid) {
        alertify.error('⚠️ Esta factura no tiene UUID válido. No se puede agregar.');
        return;
    }
    // ✅ VALIDACIÓN: Solo un proveedor por pago
    if (currentProvider && currentProvider !== rowData.proveedor) {
        alertify.alert(
            '<i class="fas fa-exclamation-triangle text-warning"></i> Proveedor Diferente',
            `<div class="text-center">
                <p class="mb-3">No puedes agregar facturas de diferentes proveedores en el mismo pago.</p>
                <div class="alert alert-info mb-0">
                    <strong>Proveedor actual del pago:</strong><br>
                    <span class="text-primary">${currentProvider}</span>
                </div>
                <div class="alert alert-warning mb-0 mt-2">
                    <strong>Proveedor que intentas agregar:</strong><br>
                    <span class="text-danger">${rowData.proveedor}</span>
                </div>
                <hr>
                <small class="text-muted">Debes crear un pago separado para este proveedor.</small>
            </div>`
        ).set({
            maximizable: false,
            closable: true
        });
        return;
    }
    // Verificar si ya existe (usando nro en lugar de folio)
    const exists = paymentItems.some(item => 
        item.nro === rowData.nro && 
        item.Factura === rowData.Factura && 
        item.codgas === rowData.codgas
    );
    
    if (exists) {
        alertify.myAlert(
            `<div class="container text-center text-warning">
                <h4 class="mt-2 text-warning">¡Advertencia!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Este documento ya está en el pago.</p>
            </div>`
        );
        return;
    }
    if (paymentItems.length === 0) {
        currentProvider = rowData.proveedor;
        console.log('✅ Proveedor establecido:', currentProvider);
    }

    paymentItems.push(rowData);
    renderPaymentItems();
    updatePaymentSummary();
}

// Renderizar elementos del pago
function renderPaymentItems() {
    const basket = $('#payment-basket');
    
    if (paymentItems.length === 0) {
        basket.html(`
            <li class="list-group-item text-center text-muted" style="border: none; background: transparent;">
                <i class="fas fa-hand-point-right fa-2x mb-3"></i>
                <p>Arrastra aquí los documentos desde la tabla</p>
            </li>
        `);
        return;
    }

    let html = '';
    paymentItems.forEach((item, index) => {
        const totalFac = parseFloat(item.total_fac) || 0;
        html += `
            <li class="list-group-item payment-item d-flex justify-content-between align-items-center">
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <strong>Folio: ${item.nro}</strong>
                        <span class="badge bg-light text-dark">$${totalFac.toLocaleString('es-MX', {minimumFractionDigits: 2})}</span>
                    </div>
                    <small class="d-block">Factura: ${item.Factura || 'N/A'} | Remisión: ${item.Remision || 'N/A'}</small>
                    <small class="d-block">Proveedor: ${item.proveedor}</small>
                    <small class="d-block text-light">Fecha: ${item.fecha}</small>
                </div>
                <button type="button" class="btn btn-sm ms-2" style="background: rgba(255, 255, 255, 0.2); border: none; color: white; border-radius: 50%; width: 30px; height: 30px;" onclick="removeFromPayment(${index})">
                    <i class="fas fa-times"></i>
                </button>
            </li>
        `;
    });

    basket.html(html);
}

// Remover elemento del pago
function removeFromPayment(index) {
    paymentItems.splice(index, 1);
    if (paymentItems.length === 0) {
        currentProvider = null;
        console.log('✅ Proveedor reseteado');

    }
    renderPaymentItems();
    updatePaymentSummary();
}

// Actualizar resumen del pago
function updatePaymentSummary() {
    const totalDocs = paymentItems.length;
    let totalAmount = 0;

    paymentItems.forEach(item => {
        const amount = parseFloat(item.total_fac) || 0;
        totalAmount += amount;
    });

    // Actualizar contador en el header
    $('#item-count').text(`${totalDocs} documento${totalDocs !== 1 ? 's' : ''}`);
    
    // Actualizar resumen
    $('#total-docs').text(totalDocs);
    $('#total-amount').text(`$${totalAmount.toLocaleString('es-MX', {minimumFractionDigits: 2})}`);
    // ✅ Mostrar proveedor actual
    if (currentProvider && totalDocs > 0) {
        // Agregar badge de proveedor si no existe
        if ($('#current-provider-badge').length === 0) {
            $('#payment-summary').prepend(`
                <div id="current-provider-badge" class="alert alert-light mb-3 py-2">
                    <small class="d-block text-muted mb-1">Proveedor del pago:</small>
                    <strong class="text-primary">${currentProvider}</strong>
                </div>
            `);
        }
    } else {
        $('#current-provider-badge').remove();
    }
    // Mostrar/ocultar elementos según si hay documentos
    if (totalDocs > 0) {
        $('#payment-summary').show();
        $('#generar-pago').prop('disabled', false).removeClass('btn-secondary').addClass('btn-success');
        $('#clear-basket').show();
    } else {
        $('#payment-summary').hide();
        $('#generar-pago').prop('disabled', true).removeClass('btn-success').addClass('btn-secondary');
        $('#clear-basket').hide();
    }
}


function addAllToPayment() {
    const table = $('#payment_create_table').DataTable();

    if (!table || table.rows().count() === 0) {
        alertify.warning('No hay documentos en la tabla para agregar.');
        return;
    }

    const allData = table.rows({ search: 'applied' }).data().toArray();
    
    // ✅ Si ya hay un proveedor establecido, filtrar solo ese proveedor
    let dataToAdd = allData;
    if (currentProvider) {
        dataToAdd = allData.filter(row => row.proveedor === currentProvider);
        
        const otherProviders = allData.filter(row => row.proveedor !== currentProvider);
        if (otherProviders.length > 0) {
            alertify.warning(
                `Solo se agregarán ${dataToAdd.length} facturas del proveedor actual: ${currentProvider}<br>` +
                `<small>Se omitieron ${otherProviders.length} facturas de otros proveedores.</small>`
            );
        }
    } else if (allData.length > 0) {
        // ✅ Si no hay proveedor, tomar el del primer registro
        const firstProvider = allData[0].proveedor;
        dataToAdd = allData.filter(row => row.proveedor === firstProvider);
        
        if (dataToAdd.length < allData.length) {
            alertify.confirm(
                '¿Múltiples Proveedores Detectados?',
                `Se detectaron facturas de diferentes proveedores.<br><br>` +
                `<strong>¿Quieres agregar solo las ${dataToAdd.length} facturas de "${firstProvider}"?</strong><br><br>` +
                `<small class="text-muted">Las ${allData.length - dataToAdd.length} facturas de otros proveedores se omitirán.</small>`,
                function() {
                    // Agregar solo del primer proveedor
                    addFilteredDataToPayment(dataToAdd);
                },
                function() {
                    // Cancelado
                }
            );
            return;
        }
    }

    addFilteredDataToPayment(dataToAdd);
}

// Función auxiliar para agregar factura al carrito
async function addInvoiceToPayment(nro, factura, codgas) {
    try {
        console.log(nro, factura, codgas);
        const response = await fetch('/supply/get_invoice_detail', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ folio: nro, factura: factura, codgas: codgas })
        });
        
        const data = await response.json();
        
        if (data.success && data.invoice) {
            // Verificar si ya existe
            const exists = paymentItems.some(item => 
                item.nro === nro && item.Factura === factura && item.codgas === codgas
            );
            
            if (!exists) {
                paymentItems.push(data.invoice);
                renderPaymentItems();
                updatePaymentSummary();
                alertify.success('Factura agregada');
            } else {
                alertify.warning('Esta factura ya está en el pago');
            }
        } else {
            alertify.error(data.message || 'Error al obtener factura');
        }
    } catch (error) {
        console.error('Error agregando factura:', error);
        alertify.error('Error de conexión al agregar factura');
    }
}

$(document).ready(function() {
    $('#payment_create_table').on('draw.dt', function() {
        updateSelectedCount();
    });
});
function ReturnListPayment(){
    window.location.href = '/supply/payment_list';
}


let currentPaymentId = null;
let availableInvoices = [];
let selectedCuentaBancaria = null;

async function generarTransferenciasBancarias(paymentId) {
    try {
        currentPaymentId = paymentId;
        
        // Abrir modal inmediatamente
        $('#modalConfigLayout').modal('show');
        
        // Mostrar loader
        $('#modalConfigLayoutContent').html(`
            <div class="text-center py-5">
                <i class="fas fa-spinner fa-spin fa-3x text-primary"></i>
                <p class="mt-3">Cargando configuración...</p>
            </div>
        `);
        
        // Cargar contenido del modal
        const response = await fetch('/supply/configLayoutModal', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
            body: `payment_id=${paymentId}`
        });
        
        if (!response.ok) {
            throw new Error('Error al cargar el modal');
        }
        
        const content = await response.text();
        
        // Insertar contenido en el modal
        $('#modalConfigLayoutContent').html(content);
        
    } catch (error) {
        console.error('Error:', error);
        alertify.error('Error al cargar la configuración del layout');
        $('#modalConfigLayout').modal('hide');
    }
}

// function generarArchivoTransferencias(paymentId) {
//     alertify.message('<i class="fas fa-spinner fa-spin"></i> Generando archivo...');
    
//     const btnTransfer = $('#btnGenerarTransferencias');
//     const btnOriginalText = btnTransfer.html();
//     btnTransfer.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Generando...');
    
//     $.ajax({
//         url: '/supply/generate_payment_layout',
//         type: 'POST',
//         data: { payment_id: paymentId },
//         timeout: 300000,
//         success: function(response) {
            
//             if (response.success) {
//                 // Descargar archivo
//                 fetch(response.file_url)
//                     .then(res => {
//                         if (!res.ok) throw new Error('Error en la descarga');
//                         return res.blob();
//                     })
//                     .then(blob => {
//                         const url = window.URL.createObjectURL(new Blob([blob]));
//                         const a = document.createElement('a');
//                         a.style.display = 'none';
//                         a.href = url;
//                         a.download = response.file_name;
//                         document.body.appendChild(a);
//                         a.click();
//                         window.URL.revokeObjectURL(url);
//                         document.body.removeChild(a);
                        
//                         // ✅ Mostrar modal y eliminar archivo AL CERRARLO
//                         alertify.alert(
//                             'Layout Generado',
//                             `<div class="text-center">
//                                 <i class="fas fa-check-circle text-success" style="font-size: 48px;"></i>
//                                 <h5 class="mt-3">Archivo Descargado</h5>
//                                 <p class="mb-2"><strong>${response.file_name}</strong></p>
//                                 <p class="text-muted">
//                                     ${response.registros_procesados} transferencias<br>
//                                     Total: $${parseFloat(response.total_importe).toLocaleString('es-MX', {minimumFractionDigits: 2})}
//                                 </p>
//                             </div>`,
//                             function() {
//                                 // ✅ Eliminar archivo CUANDO EL USUARIO CIERRE EL MODAL
//                                 $.post('/supply/delete_layout', {
//                                     filename: response.file_name
//                                 }, function(deleteResponse) {
//                                     console.log('Archivo eliminado:', deleteResponse);
//                                 }).fail(function(error) {
//                                     console.error('Error al eliminar:', error);
//                                 });
//                             }
//                         ).set({
//                             maximizable: false,
//                             closable: true
//                         });
//                     })
//                     .catch(error => {
//                         console.error('Error:', error);
//                         alertify.error('Error al descargar el archivo');
//                     });
                
//             } else {
//                 alertify.error(response.message);
//             }
//         },
//         error: function(xhr, status) {
//             console.error('Error AJAX:', { status, xhr });
//             alertify.error(status === 'timeout' ? 'Timeout' : 'Error al generar');
//         },
//         complete: function() {
//             btnTransfer.prop('disabled', false).html(btnOriginalText);
//         }
//     });
// }

function cargarDatosParaLayout(paymentId) {
    $.ajax({
        url: '/supply/get_payment_layout_data',
        type: 'POST',
        data: { payment_id: paymentId },
        success: function(response) {
            if (response.success) {
                // Mostrar información del proveedor
                $('#layout_proveedor_nombre').text(response.proveedor.nombre);
                $('#layout_proveedor_codigo').text(response.proveedor.codigo);
                
                // Cargar cuentas bancarias en el select
                cargarCuentasBancarias(response.cuentas_bancarias);
                
                // Guardar y mostrar facturas
                availableInvoices = response.facturas;
                renderizarFacturasLayout(response.facturas);
                
            } else {
                alertify.error(response.message || 'Error al cargar datos');
                $('#modalConfigLayout').modal('hide');
            }
        },
        error: function() {
            alertify.error('Error de conexión al cargar datos');
            $('#modalConfigLayout').modal('hide');
        }
    });
}

// ==========================================
// CARGAR CUENTAS BANCARIAS EN SELECT
// ==========================================
function cargarCuentasBancarias(cuentas) {
    const $select = $('#select_cuenta_bancaria');
    
    // Limpiar opciones anteriores
    $select.find('option:not(:first)').remove();
    
    if (!cuentas || cuentas.length === 0) {
        alertify.warning('No se encontraron cuentas bancarias para este proveedor');
        return;
    }
    
    // Agregar opciones
    cuentas.forEach(cuenta => {
        const opcionTexto = `${cuenta.CuentaLocal} - ${cuenta.Banco} (${cuenta.TipoCuenta || 'N/A'})`;
        $select.append(new Option(opcionTexto, cuenta.id));
    });
    
    // Reinicializar selectpicker
    $select.selectpicker('refresh');
    
    // Evento de cambio
    $select.off('change').on('change', function() {
        const cuentaId = $(this).val();
        if (cuentaId) {
            const cuenta = cuentas.find(c => c.id == cuentaId);
            if (cuenta) {
                selectedCuentaBancaria = cuenta;
                mostrarInfoCuentaSeleccionada(cuenta);
                validarGeneracionLayout();
            }
        } else {
            selectedCuentaBancaria = null;
            $('#info_cuenta_seleccionada').hide();
            validarGeneracionLayout();
        }
    });
}

// ==========================================
// MOSTRAR INFO DE CUENTA SELECCIONADA
// ==========================================
function mostrarInfoCuentaSeleccionada(cuenta) {
    $('#cuenta_numero').text(cuenta.CuentaLocal);
    $('#cuenta_banco').text(cuenta.Banco);
    $('#cuenta_email').text(cuenta.EmailCuenta || 'No especificado');
    $('#info_cuenta_seleccionada').slideDown();
}

// ==========================================
// RENDERIZAR TABLA DE FACTURAS
// ==========================================
function renderizarFacturasLayout(facturas) {
    const $tbody = $('#tabla_facturas_layout tbody');
    $tbody.empty();
    
    if (!facturas || facturas.length === 0) {
        $tbody.html(`
            <tr>
                <td colspan="7" class="text-center text-muted">
                    <i class="fas fa-inbox"></i> No hay facturas disponibles
                </td>
            </tr>
        `);
        return;
    }
    
    facturas.forEach(factura => {
        const monto = parseFloat(factura.amount || 0);
        const uuidShort = factura.uuid ? factura.uuid.substring(0, 8) + '...' : 'N/A';
        
        const estadoBadge = factura.paid_status == 1 
            ? '<span class="badge bg-success">Pagada</span>'
            : '<span class="badge bg-warning text-dark">Pendiente</span>';
        
        const row = `
            <tr>
                <td class="text-center">
                    <input type="checkbox" 
                           class="invoice-layout-checkbox" 
                           data-factura-id="${factura.id}"
                           onchange="updateCountSelectedLayout()">
                </td>
                <td>${factura.folio}</td>
                <td>${factura.invoice_number || 'N/A'}</td>
                <td>${factura.station_name || 'N/A'}</td>
                <td class="text-end"><strong>$${monto.toLocaleString('es-MX', {minimumFractionDigits: 2})}</strong></td>
                <td><small class="font-monospace">${uuidShort}</small></td>
                <td>${estadoBadge}</td>
            </tr>
        `;
        
        $tbody.append(row);
    });
    
    updateCountSelectedLayout();
}

// ==========================================
// TOGGLE TODAS LAS FACTURAS
// ==========================================
function toggleAllInvoicesLayout() {
    const isChecked = $('#selectAllLayoutCheckbox').prop('checked');
    $('.invoice-layout-checkbox').prop('checked', isChecked);
    updateCountSelectedLayout();
}

// ==========================================
// ACTUALIZAR CONTADOR DE SELECCIONADAS
// ==========================================
function updateCountSelectedLayout() {
    const count = $('.invoice-layout-checkbox:checked').length;
    $('#count_selected_layout').text(count + ' seleccionada' + (count !== 1 ? 's' : ''));
    
    // Actualizar checkbox "Seleccionar Todas"
    const total = $('.invoice-layout-checkbox').length;
    $('#selectAllLayoutCheckbox').prop('checked', count === total && total > 0);
    
    validarGeneracionLayout();
}

// ==========================================
// VALIDAR SI SE PUEDE GENERAR LAYOUT
// ==========================================
function validarGeneracionLayout() {
    const tieneCuenta = selectedCuentaBancaria !== null;
    const tieneFacturas = $('.invoice-layout-checkbox:checked').length > 0;
    
    const esValido = tieneCuenta && tieneFacturas;
    
    $('#btnConfirmarLayout').prop('disabled', !esValido);
    
    if (!tieneCuenta && tieneFacturas) {
        alertify.warning('Debes seleccionar una cuenta bancaria');
    } else if (tieneCuenta && !tieneFacturas) {
        alertify.warning('Debes seleccionar al menos una factura');
    }
}

// ==========================================
// CONFIRMAR Y GENERAR LAYOUT
// ==========================================
function confirmarGeneracionLayout() {
    if (!selectedCuentaBancaria) {
        alertify.error('Debe seleccionar una cuenta bancaria');
        return;
    }
    
    const facturasSeleccionadas = [];
    $('.invoice-layout-checkbox:checked').each(function() {
        facturasSeleccionadas.push($(this).data('factura-id'));
    });
    
    if (facturasSeleccionadas.length === 0) {
        alertify.error('Debe seleccionar al menos una factura');
        return;
    }
    
    // Confirmar con el usuario
    alertify.confirm(
        'Confirmar Generación',
        `¿Generar layout de Santander con:<br><br>` +
        `<strong>Cuenta:</strong> ${selectedCuentaBancaria.CuentaLocal}<br>` +
        `<strong>Banco:</strong> ${selectedCuentaBancaria.Banco}<br>` +
        `<strong>Facturas:</strong> ${facturasSeleccionadas.length}<br><br>` +
        `<small class="text-muted">Esta operación no se puede deshacer.</small>`,
        function() {
            // Cerrar modal y generar
            $('#modalConfigLayout').modal('hide');
            ejecutarGeneracionLayout(facturasSeleccionadas);
        },
        function() {
            alertify.message('Operación cancelada');
        }
    ).set('labels', {ok: 'Generar', cancel: 'Cancelar'});
}

// ==========================================
// EJECUTAR GENERACIÓN DEL LAYOUT
// ==========================================
function ejecutarGeneracionLayout(facturasIds) {
    alertify.message('<i class="fas fa-spinner fa-spin"></i> Generando archivo...');
    
    const btnTransfer = $('#btnGenerarTransferencias');
    const btnOriginalText = btnTransfer.html();
    btnTransfer.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Generando...');
    
    $.ajax({
        url: '/supply/generate_payment_layout',
        type: 'POST',
        data: { 
            payment_id: currentPaymentId,
            cuenta_bancaria_id: selectedCuentaBancaria.id,
            facturas_ids: JSON.stringify(facturasIds)
        },
        timeout: 300000,
        success: function(response) {
            if (response.success) {
                // Descargar archivo
                fetch(response.file_url)
                    .then(res => {
                        if (!res.ok) throw new Error('Error en la descarga');
                        return res.blob();
                    })
                    .then(blob => {
                        const url = window.URL.createObjectURL(new Blob([blob]));
                        const a = document.createElement('a');
                        a.style.display = 'none';
                        a.href = url;
                        a.download = response.file_name;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                        document.body.removeChild(a);
                        
                        // Mostrar resumen
                        alertify.alert(
                            'Layout Generado',
                            `<div class="text-center">
                                <i class="fas fa-check-circle text-success" style="font-size: 48px;"></i>
                                <h5 class="mt-3">Archivo Descargado</h5>
                                <p class="mb-2"><strong>${response.file_name}</strong></p>
                                <div class="alert alert-info">
                                    <strong>Transferencias:</strong> ${response.registros_procesados}<br>
                                    <strong>Total:</strong> $${parseFloat(response.total_importe).toLocaleString('es-MX', {minimumFractionDigits: 2})}<br>
                                    <strong>Cuenta:</strong> ${selectedCuentaBancaria.CuentaLocal}
                                </div>
                            </div>`,
                            function() {
                                // Eliminar archivo temporal
                                $.post('/supply/delete_layout', {
                                    filename: response.file_name
                                });
                            }
                        );
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alertify.error('Error al descargar el archivo');
                    });
            } else {
                alertify.error(response.message || 'Error al generar layout');
            }
        },
        error: function(xhr, status) {
            console.error('Error AJAX:', { status, xhr });
            alertify.error(status === 'timeout' ? 'Timeout' : 'Error al generar');
        },
        complete: function() {
            btnTransfer.prop('disabled', false).html(btnOriginalText);
        }
    });
}

async function crearAnticipo() {
    const proveedor_cod = $('#anticipo_proveedor').val();
    const monto = parseFloat($('#anticipo_monto').val());
    const comentario = $('#anticipo_comentario').val().trim();
    
    // Validaciones
    if (!proveedor_cod) {
        alertify.error('Debe seleccionar un proveedor');
        return;
    }
    
    if (!monto || monto <= 0) {
        alertify.error('El monto debe ser mayor a cero');
        return;
    }
    
    if (!comentario) {
        alertify.error('Debe proporcionar una justificación');
        return;
    }
    
    alertify.confirm(
        'Confirmar Anticipo',
        `¿Crear anticipo de <strong>$${monto.toLocaleString('es-MX', {minimumFractionDigits: 2})}</strong>?<br><br>` +
        `<small>${comentario}</small>`,
        async function() {
            try {
                const response = await fetch('/supply/create_anticipo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        provider_cod: proveedor_cod,
                        monto: monto,
                        comentario: comentario
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alertify.success('Anticipo creado: ID #' + data.anticipo_id);
                    
                    // Limpiar formulario
                    $('#anticipo_proveedor').val('').selectpicker('refresh');
                    $('#anticipo_monto').val('');
                    $('#anticipo_comentario').val('');
                    
                    // Cambiar a tab de anticipos
                    $('a[href="#tab_anticipos"]').tab('show');
                    $('#tabla_anticipos').DataTable().ajax.reload();
                } else {
                    alertify.error(data.message || 'Error al crear anticipo');
                }
            } catch (error) {
                console.error('Error:', error);
                alertify.error('Error de conexión');
            }
        }
    ).set('labels', {ok: 'Crear', cancel: 'Cancelar'});
}

function cargarProveedoresAnticipo() {
    $.ajax({
        url: '/supply/get_proveedores_combustible',
        type: 'GET',
        success: function(response) {
            const $select = $('#anticipo_proveedor');
            $select.find('option:not(:first)').remove();
            
            if (response.success && response.proveedores) {
                response.proveedores.forEach(prov => {
                    $select.append(new Option(prov.nombre, prov.codigo));
                });
                $select.selectpicker('refresh');
            }
        }
    });
}

async function cargarAnticiposDisponibles(proveedor_cod) {
    try {
        const response = await fetch('/supply/get_anticipos_disponibles', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ provider_cod: proveedor_cod })
        });
        
        const data = await response.json();
        
        if (data.success && data.anticipos.length > 0) {
            mostrarAnticiposDisponibles(data.anticipos);
        } else {
            $('#anticipos_disponibles_section').hide();
        }
    } catch (error) {
        console.error('Error:', error);
    }
}

function mostrarAnticiposDisponibles(anticipos) {
    const $tbody = $('#tabla_anticipos_aplicables tbody');
    $tbody.empty();
    
    anticipos.forEach(ant => {
        const row = `
            <tr>
                <td class="text-center">
                    <input type="checkbox" 
                           class="anticipo-checkbox" 
                           data-anticipo-id="${ant.id}"
                           data-saldo="${ant.saldo_disponible}">
                </td>
                <td>${ant.id}</td>
                <td>${ant.request_date}</td>
                <td class="text-end"><strong>$${parseFloat(ant.saldo_disponible).toLocaleString('es-MX', {minimumFractionDigits: 2})}</strong></td>
                <td>
                    <input type="number" 
                           class="form-control form-control-sm anticipo-monto"
                           data-anticipo-id="${ant.id}"
                           step="0.01"
                           min="0.01"
                           max="${ant.saldo_disponible}"
                           placeholder="0.00"
                           disabled>
                </td>
            </tr>
        `;
        $tbody.append(row);
    });
    
    $('#anticipos_disponibles_section').show();
    
    // Eventos
    $('.anticipo-checkbox').on('change', function() {
        const $input = $(`.anticipo-monto[data-anticipo-id="${$(this).data('anticipo-id')}"]`);
        $input.prop('disabled', !$(this).is(':checked'));
        if (!$(this).is(':checked')) {
            $input.val('');
        }
        calcularTotalConAnticipos();
    });
    
    $('.anticipo-monto').on('input', calcularTotalConAnticipos);
}

function calcularTotalConAnticipos() {
    const totalFacturas = paymentItems.reduce((sum, item) => 
        sum + (parseFloat(item.total_fac) || 0), 0);
    
    let totalAnticipos = 0;
    $('.anticipo-checkbox:checked').each(function() {
        const anticipoId = $(this).data('anticipo-id');
        const monto = parseFloat($(`.anticipo-monto[data-anticipo-id="${anticipoId}"]`).val()) || 0;
        totalAnticipos += monto;
    });
    
    const totalAPagar = Math.max(0, totalFacturas - totalAnticipos);
    
    $('#total_factura_original').text('$' + totalFacturas.toLocaleString('es-MX', {minimumFractionDigits: 2}));
    $('#total_anticipos_aplicados').text('$' + totalAnticipos.toLocaleString('es-MX', {minimumFractionDigits: 2}));
    $('#total_con_anticipos').text('$' + totalAPagar.toLocaleString('es-MX', {minimumFractionDigits: 2}));
}


function openAuthModal(permission, departamento) {
    currentPermission = permission;
    $('#modalDepartamento').text(departamento);
    $('#authModal').modal('show');
}
function confirmAuthorization() {
    if (!currentPermission) {
        alertify.error('Error: No se seleccionó un nivel de autorización');
        return;
    }
    $('#btnConfirmAuth').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Autorizando...');
    $.ajax({
        url: '/supply/authorize_payment',
        type: 'POST',
        data: {
            payment_id: paymentId,
            permission: currentPermission
        },
        success: function(response) {
            if (response.success) {
                alertify.success(response.message);
                $('#authModal').modal('hide');
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                alertify.error(response.message);
                $('#btnConfirmAuth').prop('disabled', false).html('<i class="fas fa-check"></i> Confirmar Autorización');
            }
        },
        error: function() {
            alertify.error('Error de conexión al autorizar');
            $('#btnConfirmAuth').prop('disabled', false).html('<i class="fas fa-check"></i> Confirmar Autorización');
        }
    });
}

// function procesarPago() {
//     // Abrir modal de selección de facturas
//     $('#modalProcesarPago').modal('show');
//     updatePagoSummary();
// }
function autorizarPago() {
    $('#modalAutorizarPago').modal('show');
    updateAutorizacionSummary();
}

function updateAutorizacionSummary() {
    let totalAAutorizar = 0;
    let facturasCount = 0;
    
    // Solo contar facturas NO autorizadas que están seleccionadas
    $('.factura-checkbox:checked:not(:disabled)').each(function() {
        const row = $(this).closest('tr');
        const montoInput = row.find('.monto-autorizar');
        const monto = parseFloat(montoInput.val()) || 0;
        const saldo = parseFloat(row.data('saldo'));
        
        // Habilitar input cuando se selecciona
        montoInput.prop('disabled', !$(this).prop('checked'));
        
        // Si se acaba de seleccionar y no tiene valor, poner el saldo completo
        if ($(this).prop('checked') && montoInput.val() === '') {
            montoInput.val(saldo.toFixed(2));
        }
        
        // Validar que no exceda el saldo
        if (monto > saldo) {
            montoInput.val(saldo.toFixed(2));
            alertify.warning('El monto no puede exceder el saldo de la factura');
        }
        
        totalAAutorizar += parseFloat(montoInput.val()) || 0;
        facturasCount++;
    });
    
    // Actualizar resumen
    $('#totalAAutorizar').text('$' + totalAAutorizar.toLocaleString('es-MX', {
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2
    }));
    $('#facturasSeleccionadas').text(facturasCount);
    $('#totalNuevasAutorizaciones').html('<strong>$' + totalAAutorizar.toLocaleString('es-MX', {
        minimumFractionDigits: 2, 
        maximumFractionDigits: 2
    }) + '</strong>');
    
    // Habilitar/deshabilitar botón de confirmar
    $('#btnConfirmarAutorizacion').prop('disabled', facturasCount === 0 || totalAAutorizar === 0);
    
    // Actualizar el checkbox "Seleccionar Todas" solo para las no autorizadas
    const totalCheckboxes = $('.factura-checkbox:not(:disabled)').length;
    const totalChecked = $('.factura-checkbox:checked:not(:disabled)').length;
    $('#selectAllFacturas').prop('checked', totalCheckboxes > 0 && totalCheckboxes === totalChecked);
}


function toggleSelectAllFacturas() {
    const selectAll = $('#selectAllFacturas').prop('checked');  
    // Solo seleccionar checkboxes que NO están deshabilitados (facturas no autorizadas)
    $('.factura-checkbox:not(:disabled)').each(function() {
        $(this).prop('checked', selectAll);
        const row = $(this).closest('tr');
        const montoInput = row.find('.monto-autorizar');

        if (selectAll) {
            const saldo = parseFloat(row.data('saldo'));
            montoInput.prop('disabled', false).val(saldo.toFixed(2));
        } else {
            montoInput.prop('disabled', true).val('');
        }
    });
    
    updateAutorizacionSummary();
}
function confirmarAutorizarPago() {
    const facturasAutorizar = [];
    let totalAAutorizar = 0;
    
    // Recopilar facturas seleccionadas con sus montos
    $('.factura-checkbox:checked').each(function() {
        const row = $(this).closest('tr');
        const facturaId = $(this).val();
        const monto = parseFloat(row.find('.monto-autorizar').val()) || 0;
        const saldo = parseFloat(row.data('saldo'));
        const folio = row.data('folio');
        
        if (monto > 0 && monto <= saldo) {
            facturasAutorizar.push({
                invoice_id: facturaId,
                folio: folio,
                monto_autorizado: monto,
                saldo_anterior: saldo
            });
            totalAAutorizar += monto;
        }
    });
    
    if (facturasAutorizar.length === 0) {
        alertify.error('Debe seleccionar al menos una factura con monto válido');
        return;
    }
    
    // Confirmar con el usuario
    alertify.confirm(
        'Confirmar Autorización de Pago',
        `<div class="text-center">
            <i class="fas fa-check-circle text-info fa-3x mb-3"></i>
            <p class="mb-3">¿Está seguro de autorizar el pago de <strong>${facturasAutorizar.length} factura(s)</strong>?</p>
            <div class="alert alert-info">
                <strong>Total a Autorizar:</strong><br>
                <h4 class="text-info mb-0">$${totalAAutorizar.toLocaleString('es-MX', {minimumFractionDigits: 2})}</h4>
            </div>
            <small class="text-muted">La ejecución del pago será realizada posteriormente por el área correspondiente.</small>
        </div>`,
        function() {
            ejecutarAutorizacionPago(facturasAutorizar);
        },
        function() {
            alertify.message('Operación cancelada');
        }
    ).set('labels', {ok: 'Autorizar', cancel: 'Cancelar'});
}

function ejecutarAutorizacionPago(facturasAutorizar) {
    $('#btnConfirmarAutorizacion')
        .prop('disabled', true)
        .html('<i class="fas fa-spinner fa-spin"></i> Autorizando...');

    $.ajax({
        // url: '/supply/process_payment',
        url: '/supply/authorize_payment_execution',
        type: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            payment_id: paymentId,
            facturas: facturasAutorizar
        }),
        success: function(response) {
            if (response.success) {
                alertify.success('✓ ' + response.message);
                $('#modalAutorizarPago').modal('hide');
                
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                alertify.error(response.message);
                $('#btnConfirmarAutorizacion')
                    .prop('disabled', false)
                    .html('<i class="fas fa-check-circle"></i> Autorizar Pago');
            }
        },
        error: function(xhr) {
            const errorMsg = xhr.responseJSON?.message || 'Error al autorizar el pago';
            alertify.error(errorMsg);
            $('#btnConfirmarAutorizacion')
                .prop('disabled', false)
                .html('<i class="fas fa-check-circle"></i> Autorizar Pago');
        }
    });
}
// Limpiar cuando se cierra el modal
$('#modalAutorizarPago').on('hidden.bs.modal', function() {
    $('.factura-checkbox:not(:disabled)').prop('checked', false);
    $('.monto-autorizar:not(:disabled)').val('').prop('disabled', true);
    $('#selectAllFacturas').prop('checked', false);
    updateAutorizacionSummary();
});

function verHistorialPagos(invoiceId, folio) {
    $('#folioHistorial').text(folio);
    $('#modalHistorialPagos').modal('show');
    
    // Cargar historial
    $.ajax({
        url: '/supply/get_payment_history',
        type: 'GET',
        data: { invoice_id: invoiceId },
        success: function(response) {
            if (response.success) {
                renderHistorial(response.data, invoiceId);
            } else {
                $('#historialContent').html(`
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle"></i> No hay pagos registrados para esta factura
                    </div>
                `);
            }
        },
        error: function() {
            $('#historialContent').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> Error al cargar el historial
                </div>
            `);
        }
    });
}

function renderHistorial(transactions, invoiceId) {
    if (!transactions || transactions.length === 0) {
        $('#historialContent').html(`
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> No hay pagos registrados para esta factura
            </div>
        `);
        return;
    }
    
    let totalPagado = 0;
    let html = `
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Fecha Pago</th>
                        <th class="text-end">Monto</th>
                        <th>Método</th>
                        <th>Referencia</th>
                        <th>Pagado Por</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    transactions.forEach((tx, index) => {
        totalPagado += parseFloat(tx.payment_amount);
        
        let statusBadge = '';
        switch(parseInt(tx.status)) {
            case 0: statusBadge = '<span class="badge bg-warning text-dark">Pendiente</span>'; break;
            case 1: statusBadge = '<span class="badge bg-info">Procesado</span>'; break;
            case 2: statusBadge = '<span class="badge bg-success">Confirmado</span>'; break;
            case 3: statusBadge = '<span class="badge bg-danger">Rechazado</span>'; break;
        }
        
        html += `
            <tr>
                <td>${index + 1}</td>
                <td>${formatDate(tx.payment_date)}</td>
                <td class="text-end"><strong>$${parseFloat(tx.payment_amount).toLocaleString('es-MX', {minimumFractionDigits: 2})}</strong></td>
                <td>${tx.payment_method || 'N/A'}</td>
                <td>${tx.payment_reference || '-'}</td>
                <td><small>${tx.created_by_name || 'N/A'}</small></td>
                <td>${statusBadge}</td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="2" class="text-end">TOTAL PAGADO:</th>
                        <th class="text-end text-success">$${totalPagado.toLocaleString('es-MX', {minimumFractionDigits: 2})}</th>
                        <th colspan="4"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    `;
    
    $('#historialContent').html(html);
}

function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('es-MX', { 
        year: 'numeric', 
        month: '2-digit', 
        day: '2-digit' 
    });
}

function verNotasPago(transactionId, notes) {
    $('#notasPagoContent').text(notes);
    $('#modalNotasPago').modal('show');
}
let tablaFacturasAutorizadas;

/**
 * Cargar tabla de facturas autorizadas pendientes AGRUPADAS
 */
function loadAuthorizedPendingInvoices() {
    console.log('loadAuthorizedPendingInvoices called (GROUPED)');
    if ($.fn.DataTable.isDataTable('#tabla_facturas_autorizadas')) {
        $('#tabla_facturas_autorizadas').DataTable().destroy();
    }
    tablaFacturasAutorizadas = $('#tabla_facturas_autorizadas').DataTable({
        ajax: {
            url: '/supply/authorized_pending_invoices_grouped_table', // ✅ Nuevo endpoint
            type: 'POST',
            dataSrc: 'data',
            error: function(xhr, error, thrown) {
                console.error('Error en AJAX:', xhr, error, thrown);
                alertify.error('Error al cargar facturas: ' + thrown);
            }
        },
        columns: [
            {
                // Checkbox
                data: null,
                orderable: false,
                className: 'text-center',
                render: function(data, type, row) {
                    return `
                        <input type="checkbox" 
                               class="invoice-group-checkbox" 
                               data-invoice-ids="${row.invoice_ids}"
                               data-banco="${row.banco_asignado}"
                               data-monto="${row.total_autorizado}"
                               data-empresa="${row.empresa_nombre}"
                               data-proveedor="${row.proveedor_nombre}"
                               onchange="updateSelectedSummary()">
                    `;
                }
            },
            {
                // Banco
                data: 'banco_asignado',
                render: function(data, type, row) {
                    let icon = data === 'Banorte' ? 'university' : 'landmark';
                    return `
                        <span class="badge" style="background-color: ${row.banco_color}; color: white; font-size: 0.9rem;">
                            <i class="fas fa-${icon}"></i> ${data}
                        </span>
                    `;
                }
            },
            {
                // Empresa
                data: 'empresa_nombre',
                render: function(data, type, row) {
                    return `
                        <strong>${data}</strong><br>
                        <small class="text-muted">RFC: ${row.empresa_rfc}</small>
                    `;
                }
            },
            {
                // Proveedor
                data: 'proveedor_nombre',
                render: function(data, type, row) {
                    return `
                        <strong>${data}</strong><br>
                        <small class="text-muted">RFC: ${row.proveedor_rfc}</small>
                    `;
                }
            },
            {
                // Total Facturas
                data: 'total_facturas',
                className: 'text-center',
                render: function(data, type, row) {
                    return `
                        <span class="badge bg-primary" style="font-size: 0.9rem;">
                            <i class="fas fa-file-invoice"></i> ${data} factura(s)
                        </span>
                    `;
                }
            },
            {
                // Monto Total Autorizado
                data: 'total_autorizado',
                className: 'text-end',
                render: function(data) {
                    return '<strong style="font-size: 1.1rem;">$' + parseFloat(data).toLocaleString('es-MX', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) + '</strong>';
                }
            },
            {
                // Saldo Total
                data: 'total_saldo',
                className: 'text-end',
                render: function(data) {
                    return '<strong class="text-danger">$' + parseFloat(data).toLocaleString('es-MX', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) + '</strong>';
                }
            },
            {
                // Autorizado Por
                data: 'authorized_by_name',
                render: function(data) {
                    return `<small><i class="fas fa-user"></i> ${data}</small>`;
                }
            },
            {
                // Fecha Última Autorización
                data: 'ultima_autorizacion',
                render: function(data) {
                    if (!data) return '-';
                    return new Date(data).toLocaleDateString('es-MX', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                }
            },
            {
                // Vencimiento Más Próximo
                data: 'vencimiento_mas_proximo',
                render: function(data) {
                    if (!data) return '-';
                    const vencimiento = new Date(data);
                    const hoy = new Date();
                    const diasDiff = Math.ceil((vencimiento - hoy) / (1000 * 60 * 60 * 24));
                    let badge = 'secondary';
                    let icon = 'calendar';
                    if (diasDiff < 0) {
                        badge = 'danger';
                        icon = 'exclamation-triangle';
                    } else if (diasDiff <= 7) {
                        badge = 'warning';
                        icon = 'clock';
                    } else {
                        badge = 'success';
                        icon = 'check';
                    }
                    return `
                        <span class="badge bg-${badge}">
                            <i class="fas fa-${icon}"></i> 
                            ${vencimiento.toLocaleDateString('es-MX')}
                        </span>
                        ${diasDiff >= 0 ? '<br><small class="text-muted">' + diasDiff + ' días</small>' : '<br><small class="text-danger">Vencido</small>'}
                    `;
                }
            },
            {
                // Acciones
                data: null,
                orderable: false,
                className: 'text-center',
                render: function(data, type, row) {
                    return `
                        <button class="btn btn-sm btn-outline-info" 
                                onclick="verDetalleFacturasAgrupadas('${row.invoice_ids}', '${row.empresa_nombre}', '${row.proveedor_nombre}')"
                                title="Ver facturas individuales">
                            <i class="fas fa-eye"></i> Ver Desglose
                        </button>
                    `;
                }
            }
        ],
        order: [[1, 'asc'], [2, 'asc'], [3, 'asc']], // Ordenar por banco, empresa, proveedor
        pageLength: 50,
        rowGroup: {
            // Agrupar solo por banco
            dataSrc: 'banco_asignado',
            startRender: function(rows, group) {
                const total = rows.data().pluck('total_autorizado').reduce((a, b) => {
                    return parseFloat(a) + parseFloat(b);
                }, 0);
                const totalFacturas = rows.data().pluck('total_facturas').reduce((a, b) => {
                    return parseInt(a) + parseInt(b);
                }, 0);
                return $('<tr/>')
                    .addClass('group-header bg-light')
                    .append(`<td colspan="11">
                        <strong><i class="fas fa-university"></i> ${group}</strong> - 
                        Total: <strong>$${total.toLocaleString('es-MX', {minimumFractionDigits: 2})}</strong> - 
                        ${totalFacturas} factura(s) en ${rows.count()} grupo(s)
                    </td>`);
            }
        },
        drawCallback: function() {
            const table = this.api();
            updateBankSummaryFromTable(table);
            updateSelectedSummary();
            
            // Poblar filtro de empresas
            const empresas = new Set();
            table.rows().data().each(function(row) {
                empresas.add(row.empresa_nombre);
            });
            
            $('#filtroEmpresa').html('<option value="all">Todas las Empresas</option>');
            empresas.forEach(empresa => {
                $('#filtroEmpresa').append(`<option value="${empresa}">${empresa}</option>`);
            });
        }
    });
}



/**
 * Actualizar resumen de seleccionadas (AGRUPADAS)
 */
function updateSelectedSummary() {
    let total = 0;
    let countGroups = 0;
    let countFacturas = 0;
    
    $('.invoice-group-checkbox:checked').each(function() {
        const monto = parseFloat($(this).data('monto')) || 0;
        const facturas = $(this).data('invoice-ids').split(',').length;
        
        total += monto;
        countGroups++;
        countFacturas += facturas;
    });
    
    $('#totalSeleccionadas').text('$' + total.toLocaleString('es-MX', {minimumFractionDigits: 2}));
    $('#facturasSeleccionadas').text(countFacturas + ' facturas (' + countGroups + ' grupos)');
    $('#footerTotalSeleccionado').text('$' + total.toLocaleString('es-MX', {minimumFractionDigits: 2}));
    
    // Habilitar/deshabilitar botón de generar layout
    $('#btnGenerarLayout').prop('disabled', countGroups === 0);
}


function updateBankSummaryFromTable(table) {
    let banorte = {count: 0, total: 0};
    let santander = {count: 0, total: 0};
    
    table.rows({search: 'applied'}).data().each(function(row) {
        if (row.banco_asignado === 'Banorte') {
            banorte.count++;
            banorte.total += parseFloat(row.authorized_amount);
        } else if (row.banco_asignado === 'Santander') {
            santander.count++;
            santander.total += parseFloat(row.authorized_amount);
        }
    });
    console.log(banorte);
    console.log(santander);
    
    $('#totalBanorte').text('$' + banorte.total.toLocaleString('es-MX', {minimumFractionDigits: 2}));
    $('#facturasBanorte').text(banorte.count + ' facturas');
    
    $('#totalSantander').text('$' + santander.total.toLocaleString('es-MX', {minimumFractionDigits: 2}));
    $('#facturasSantander').text(santander.count + ' facturas');
}


/**
 * Aplicar filtros
 */
function aplicarFiltros() {
    const banco = $('#filtroBanco').val();
    const empresa = $('#filtroEmpresa').val();
    
    $.fn.dataTable.ext.search.push(
        function(settings, data, dataIndex) {
            if (settings.nTable.id !== 'tabla_facturas_autorizadas') {
                return true;
            }
            
            const rowData = tablaFacturasAutorizadas.row(dataIndex).data();
            
            let bancoMatch = banco === 'all' || rowData.banco_asignado === banco;
            let empresaMatch = empresa === 'all' || rowData.empresa_nombre === empresa;
            
            return bancoMatch && empresaMatch;
        }
    );
    
    tablaFacturasAutorizadas.draw();
    $.fn.dataTable.ext.search.pop();
}

/**
 * Generar layout de pago
 */
function generarLayoutPago() {
    const seleccionadas = [];
    
    $('.invoice-checkbox:checked').each(function() {
        const row = tablaFacturasAutorizadas.row($(this).closest('tr')).data();
        seleccionadas.push({
            id: row.id,
            payment_request_id: row.payment_request_id,
            folio: row.folio,
            monto: row.authorized_amount,
            banco: row.banco_asignado,
            empresa: row.empresa_nombre,
            proveedor: row.proveedor_nombre
        });
    });
    
    if (seleccionadas.length === 0) {
        alertify.warning('Seleccione al menos una factura');
        return;
    }
    
    // Verificar que todas sean del mismo banco
    const bancos = [...new Set(seleccionadas.map(f => f.banco))];
    if (bancos.length > 1) {
        alertify.confirm(
            'Múltiples Bancos',
            'Has seleccionado facturas de diferentes bancos: ' + bancos.join(', ') + '. ¿Deseas continuar generando layouts separados?',
            function() {
                generarLayoutsPorBanco(seleccionadas);
            },
            function() {
                alertify.error('Operación cancelada');
            }
        );
        return;
    }
    
    // TODO: Implementar lógica de generación de layout
    console.log('Facturas seleccionadas:', seleccionadas);
    alertify.success('Generando layout para ' + seleccionadas.length + ' facturas del banco ' + bancos[0]);
}

/**
 * Ver detalle del pago
 */
function verDetallePago(paymentId) {
    window.location.href = '/supply/payment_detail/' + paymentId;
}


let tablaDesgloseFacturas;

/**
 * Ver desglose de facturas agrupadas
 */
function verDetalleFacturasAgrupadas(invoiceIds, empresa, proveedor) {
    // Mostrar información del grupo
    $('#desgloseEmpresa').text(empresa);
    $('#desgloseProveedor').text(proveedor);
    
    // Abrir modal
    $('#modalDesgloseFacturas').modal('show');
    
    // Cargar datos
    cargarDesgloseFacturas(invoiceIds);
}

/**
 * Cargar desglose de facturas
 */
function cargarDesgloseFacturas(invoiceIds) {
    // Destruir tabla anterior si existe
    if ($.fn.DataTable.isDataTable('#tablaDesgloseFacturas')) {
        $('#tablaDesgloseFacturas').DataTable().destroy();
    }
    
    // Mostrar loading
    $('#tablaDesgloseFacturas tbody').html(`
        <tr>
            <td colspan="11" class="text-center py-4">
                <i class="fas fa-spinner fa-spin fa-2x"></i>
                <p class="mt-2">Cargando facturas...</p>
            </td>
        </tr>
    `);
    
    // Hacer petición AJAX
    $.ajax({
        url: '/supply/get_invoices_detail',
        type: 'POST',
        data: { invoice_ids: invoiceIds },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                inicializarTablaDesglose(response.data);
            } else {
                alertify.error(response.message || 'Error al cargar las facturas');
                $('#tablaDesgloseFacturas tbody').html(`
                    <tr>
                        <td colspan="11" class="text-center text-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            No se pudieron cargar las facturas
                        </td>
                    </tr>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('Error AJAX:', error);
            alertify.error('Error al cargar las facturas: ' + error);
            $('#tablaDesgloseFacturas tbody').html(`
                <tr>
                    <td colspan="11" class="text-center text-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        Error de conexión
                    </td>
                </tr>
            `);
        }
    });
}

/**
 * Inicializar tabla de desglose con datos
 */
function inicializarTablaDesglose(data) {
    tablaDesgloseFacturas = $('#tablaDesgloseFacturas').DataTable({
        data: data,
        columns: [
            { 
                data: 'folio',
                render: function(data) {
                    return '<strong>' + data + '</strong>';
                }
            },
            { data: 'invoice_number' },
            { data: 'estacion_nombre' },
            {
                data: 'amount',
                className: 'text-end',
                render: function(data) {
                    return '$' + parseFloat(data).toLocaleString('es-MX', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }
            },
            {
                data: 'paid_amount',
                className: 'text-end',
                render: function(data) {
                    return '$' + parseFloat(data).toLocaleString('es-MX', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    });
                }
            },
            {
                data: 'authorized_amount',
                className: 'text-end',
                render: function(data) {
                    return '<strong class="text-success">$' + parseFloat(data).toLocaleString('es-MX', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) + '</strong>';
                }
            },
            {
                data: 'saldo',
                className: 'text-end',
                render: function(data) {
                    return '<strong class="text-danger">$' + parseFloat(data).toLocaleString('es-MX', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }) + '</strong>';
                }
            },
            {
                data: 'expiration_date',
                render: function(data) {
                    if (!data) return '-';
                    const vencimiento = new Date(data);
                    const hoy = new Date();
                    const diasDiff = Math.ceil((vencimiento - hoy) / (1000 * 60 * 60 * 24));
                    let badge = 'secondary';
                    if (diasDiff < 0) badge = 'danger';
                    else if (diasDiff <= 7) badge = 'warning';
                    else badge = 'success';
                    return `<span class="badge bg-${badge}">${vencimiento.toLocaleDateString('es-MX')}</span>`;
                }
            },
            {
                data: 'authorized_by_name',
                render: function(data) {
                    return `<small><i class="fas fa-user"></i> ${data}</small>`;
                }
            },
            {
                data: 'authorized_at',
                render: function(data) {
                    if (!data) return '-';
                    return new Date(data).toLocaleDateString('es-MX', {
                        year: 'numeric',
                        month: '2-digit',
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                }
            },
            // {
            //     data: null,
            //     orderable: false,
            //     className: 'text-center',
            //     render: function(data, type, row) {
            //         return `
            //             <button class="btn btn-sm btn-outline-info" 
            //                     onclick="verDetallePago(${row.payment_request_id})"
            //                     title="Ver solicitud de pago">
            //                 <i class="fas fa-eye"></i>
            //             </button>
            //         `;
            //     }
            // }
        ],
        order: [[7, 'asc']], // Ordenar por vencimiento
        pageLength: 25,
        dom: 'frtip',
        // buttons: [
        //     {
        //         extend: 'excel',
        //         text: '<i class="fas fa-file-excel"></i> Excel',
        //         className: 'btn btn-success btn-sm',
        //         exportOptions: {
        //             columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9]
        //         }
        //     },
        //     {
        //         extend: 'pdf',
        //         text: '<i class="fas fa-file-pdf"></i> PDF',
        //         className: 'btn btn-danger btn-sm',
        //         exportOptions: {
        //             columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9]
        //         }
        //     }
        // ],
        drawCallback: function() {
            actualizarTotalesDesglose(data);
        }
    });
    
    actualizarTotalesDesglose(data);
}

/**
 * Actualizar totales del desglose
 */
function actualizarTotalesDesglose(data) {
    let totalFacturas = data.length;
    let totalAutorizado = 0;
    let totalSaldo = 0;
    
    data.forEach(function(factura) {
        totalAutorizado += parseFloat(factura.authorized_amount);
        totalSaldo += parseFloat(factura.saldo);
    });
    
    // Actualizar cards superiores
    $('#desgloseTotalFacturas').text(totalFacturas);
    $('#desgloseMontoTotal').text('$' + totalAutorizado.toLocaleString('es-MX', {minimumFractionDigits: 2}));
    $('#desgloseSaldoTotal').text('$' + totalSaldo.toLocaleString('es-MX', {minimumFractionDigits: 2}));
    
    // Actualizar footer de la tabla
    $('#footerDesgloseAutorizado').html('<strong>$' + totalAutorizado.toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</strong>');
    $('#footerDesgloseSaldo').html('<strong>$' + totalSaldo.toLocaleString('es-MX', {minimumFractionDigits: 2}) + '</strong>');
}

/**
 * Exportar desglose a Excel
 */
function exportarDesgloseExcel() {
    if (tablaDesgloseFacturas) {
        tablaDesgloseFacturas.button('.buttons-excel').trigger();
    } else {
        alertify.warning('No hay datos para exportar');
    }
}

/**
 * Limpiar modal al cerrar
 */
$('#modalDesgloseFacturas').on('hidden.bs.modal', function() {
    if ($.fn.DataTable.isDataTable('#tablaDesgloseFacturas')) {
        $('#tablaDesgloseFacturas').DataTable().destroy();
    }
    $('#tablaDesgloseFacturas tbody').empty();
});