$(document).ready(function() {
    console.log('accounting.js');
    let stimulus_table = $('#stimulus_table').DataTable({
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 100,
        ajax: {
            "url": "/accounting/stimulus_table",
            "type": "GET",
            "data": {
                "inicial":  $("input#from").val(),
                "final":    $("input#until").val(),
                "est87":    $("input#est87").val(),
                "est91":    $("input#est91").val(),
            },
            "beforeSend": function() {
                $('.table-responsive').addClass('loading');
            }
        },
        columns: [
            { "data": "cveest"},
            { "data": "station"},
            { "data": "tax_date"},
            { "data": "nropcc"}, // Permiso CRE
            { "data": "product"},
            { "data": "Cve_Producto"},
            { "data": "less150"},
            { "data": "more150"},
            { "data": "consumes"},
            { "data": "calibration"},
            { "data": "dues", 'render': $.fn.dataTable.render.number( ',', '.', 2, '$' )}, // Cuotas
            { "data": "volume", 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
            { "data": "volume_controlgas", 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
            { "data": "difference", 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
            { "data": "amount", 'render': $.fn.dataTable.render.number( ',', '.', 2, '$')},
        ],
        // vamos a poner botones en la tabla
        buttons: [
            {
                extend: 'excel',
                className: 'd-none',
                filename: 'Reporte de estímulo',
            }
        ],
        initComplete: function () {
            $('.dt-buttons').addClass('d-none');
            $('.table-responsive').removeClass('loading');
        }
    });

    // Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
    $('#filtro-stimulus_table input').on('keyup change clear', function () {
        stimulus_table
            .column(0).search($('#cveest').val().trim())
            .column(1).search($('#station').val().trim())
            .column(2).search($('#tax_date').val().trim())
            .column(3).search($('#nropcc').val().trim())
            .column(4).search($('#product').val().trim())
            .column(5).search($('#Cve_Producto').val().trim())
            .column(6).search($('#less150').val().trim())
            .column(7).search($('#more150').val().trim())
            .column(8).search($('#consumes').val().trim())
            .column(9).search($('#calibration').val().trim())
            .column(10).search($('#dues').val().trim())
            .column(11).search($('#volume').val().trim())
            .column(12).search($('#volume_controlgas').val().trim())
            .column(13).search($('#difference').val().trim())
            .column(14).search($('#amount').val().trim())
            .draw();
    });

    // Agregar un evento clic de refresh
    $('.refresh_stimulus_table').on('click', function () {
        stimulus_table.clear().draw();
        stimulus_table.ajax.reload();
        $('#stimulus_table').waitMe('hide');
    });
});



function actualizarDataTableInvoce() {
    var from = $('#from').val();
    var until = $('#until').val();
    var rfc = $('#rfc').val();
   
    var timestamp = new Date().getTime();

    if (!rfc || rfc === "") {
        alertify.myAlert(
            `<div class="container text-center text-danger">
                <h4 class="mt-2 text-danger">¡Error!</h4>
            </div>
            <div class="text-dark">
                <p class="text-center">Por favor, seleccione una Razon social antes de continuar.</p>
            </div>`
        );
        return; // Detiene la ejecución de la función si no se seleccionó una estación
    }
    if ($.fn.DataTable.isDataTable('#invoice_table')) {
        $('#invoice_table').DataTable().destroy();
    }
    $('#element_hidden').removeAttr('hidden');
    $('#no_selected').attr('hidden',true);

    let invoice_table = $('#invoice_table').DataTable({
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
               url: '/accounting/invoice_table',
               timeout: 300000, 
               data: {
                    'from': from,
                    'until': until,
                    'rfc': rfc
                },
               error: function() {
                //    $('#invoice_table').waitMe('hide');
                //    $('.invoice_table').removeClass('loading');
       
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
                   $('.invoice_table').addClass('loading');
               },
            
           },
           deferRender: true,
           columns: [
               {'data': 'Fecha'},
               {'data': 'Folio'},
               {'data': 'EmisorRfc'},
               {'data': 'ReceptorNombre'},
               {'data': 'ReceptorRfc'},
               {'data': 'SubTotal', 'render': $.fn.dataTable.render.number(',', '.', 2, '$')},
               {'data': 'TotalImpuestosTrasladados', 'render': $.fn.dataTable.render.number(',', '.', 2, '$')},
               {'data': 'Total', 'render': $.fn.dataTable.render.number(',', '.', 2, '$')},
               {'data': 'FechaTimbrado'},
               {'data': 'MetodoPago'},
               {'data': 'UUID'},
           ],
           rowId: 'Folio',
           createdRow: function (row, data, dataIndex) {

           },
           initComplete: function (settings, json) {
            //    $('.dt-buttons').addClass('d-none');
               $('.control_dispaches_table').removeClass('loading');
           }
       });
    // Actualizar los datos del DataTable
    $('#filtro-invoice_table input').on('keyup  change clear', function () {
        invoice_table
        .column(0).search($('#fecha').val().trim())                // Fecha
        .column(1).search($('#Folio').val().trim())      // Hora formateada
        .column(3).search($('#despacho').val().trim())             // Despacho
        .column(4).search($('#producto').val().trim())             // Producto
        .draw();
    });

    // Agregar un evento clic de refresh
    $('.refresh_invoice_table').on('click', function () {
        invoice_table.clear().draw();
        invoice_table.ajax.reload();
        $('#invoice_table').waitMe('hide');
    });
}


async function InvoiceConceptModal(uuid){
        console.log(uuid)
        try {
            $('#InvoiceConceptModal').modal('show'); // Abre el modal
            const response = await fetch('/accounting/InvoiceConceptModal', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json, text/javascript, */*',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                credentials: 'include',
                body: `uuid=${uuid}`
            });

            const content = await response.text();
            // Inserta el contenido en el modal
            $('#InvoiceConceptModal').find('#InvoiceConceptModalContent').html(content);

        } catch (error) {
            console.error(error);
        }

}


async function invoice_purchase_table(){
    if ($.fn.DataTable.isDataTable('#invoice_purchase_table')) {
        $('#invoice_purchase_table').DataTable().destroy();
        $('#invoice_purchase_table thead .filter').remove();
        // $('#invoice_purchase_table').DataTable().destroy();  // Destruye la tabla existente
        // $('#invoice_purchase_table thead').empty(); // Limpia el encabezado
        // $('#invoice_purchase_table tbody').empty(); // Limpia el cuerpo
        // $('#invoice_purchase_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;
    var product = document.getElementById('product').value;

    $('#invoice_purchase_table thead').prepend($('#invoice_purchase_table thead tr').clone().addClass('filter'));
    $('#invoice_purchase_table thead tr.filter th').each(function (index) {
        col = $('#invoice_purchase_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#invoice_purchase_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#invoice_purchase_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let invoice_purchase_table =$('#invoice_purchase_table').DataTable({
        order: [0, "asc"],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        scrollY: '700px',
        scrollX: true,
        scrollCollapse: true,
        paging: false,
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
                'product':product
            },
            url: '/accounting/invoice_purchase_table',
            timeout: 600000, 
            error: function() {
                $('#invoice_purchase_table').waitMe('hide');
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
            {'data': 'Fecha',className:'text-nowrap'},
            {'data': 'Fecha_vencimiento'},
            {'data': 'proveedor',className:'text-nowrap'},
            {'data': 'Factura'},
            {'data': 'txtref'},
            {'data': 'Estacion',className:'text-nowrap'},  // Falta en tu DataTable
            {'data': 'producto'},
            {'data': 'Empresa'},
            {'data': 'satuid',className:'text-nowrap'},

            {'data': 'can', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'pre', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'mto', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'mtoori', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'mtoiva', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'mtoiie', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'cantidad', render: $.fn.dataTable.render.number(',', '.', 2)},  // Falta en tu DataTable
            {'data': 'precio', render: $.fn.dataTable.render.number(',', '.', 2)},  // Falta en tu DataTable
            {'data': 'IvaImporte', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'IEPS', render: $.fn.dataTable.render.number(',', '.', 2)},  // Falta en tu DataTable
            {'data': 'Subtotal', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'Total', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'Numero_pago_OG'},
            {'data': 'num_factura_OG'},
            {'data': 'Ref_Numerica'},
            {'data': 'fecha_pago',className:'text-nowrap'},
            {'data': 'monto_pago', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'monto_pago_fac', render: $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'cuenta'},
            {'data': 'banco'},

        ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
            $('td:eq(15)', row).addClass('border_OG');
            if (data['mto'] !=  data['Subtotal']) {
                $('td:eq(19)', row).addClass('bg-danger');
                
            }
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
        footerCallback: function (row, data, start, end, display) {
            var api = this.api();

            // Función para sumar valores en una columna
            var intVal = function (i) {
                return typeof i === 'string' ?
                    i.replace(/[\$,]/g, '') * 1 :
                    typeof i === 'number' ? i : 0;
            };
        
            // Lista de columnas a sumar (desde 'can' en adelante)
            var columnIndexes = [9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

            api.columns().every(function (index) {
                if (index > 8 && index < 21 ) { // Desde la tercera columna en adelante
                    // Sumatoria de los datos filtrados (página actual)
                    var filteredSum = api
                        .column(index, { page: 'current' }) // Solo datos visibles (filtrados)
                        .data()
                        .reduce((a, b) => intVal(a) + intVal(b), 0);
            
                    // Sumatoria de todos los datos (incluyendo no visibles)
                    var totalSum = api
                        .column(index, { page: 'all' }) // Todos los datos
                        .data()
                        .reduce((a, b) => intVal(a) + intVal(b), 0);
            
                    // Actualizar el footer con ambas sumatorias
                    var footer = $(api.column(index).footer());
                    footer.html(`
                        <div>${filteredSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                        <div>Total: ${totalSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
                    `);
                }
            });
        
            // columnIndexes.forEach(function (colIdx, i) {
            //     // Calcula la suma de la columna
            //     var total = api.column(colIdx, { page: 'current' }).data()
            //         .reduce(function (a, b) {
            //             return intVal(a) + intVal(b);
            //         }, 0);
        
            //     // Inserta el total en el footer
            //     $(api.column(colIdx).footer()).html(total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
            // });
        }
    });
}
async function payments_table(){
    if ($.fn.DataTable.isDataTable('#payments_table')) {
        $('#payments_table').DataTable().destroy();
        $('#payments_table thead .filter').remove();
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    $('#payments_table thead').prepend($('#payments_table thead tr').clone().addClass('filter'));
    $('#payments_table thead tr.filter th').each(function (index) {
        col = $('#payments_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#payments_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#payments_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let payments_table =$('#payments_table').DataTable({
        order: [0, "asc"],
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
                'untilDate':untilDate
            },
            url: '/accounting/payments_table',
            timeout: 600000, 
            error: function() {
                $('#payments_table').waitMe('hide');
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
            { data: 'num_doc', className: 'text-nowrap' },              // Número de pago
            { data: 'clave' },
            // { data: 'id_prov' },
            { data: 'nom1' },                                            // Nombre del proveedor
            { data: 'cuenta' },                                          // t5.num
            { data: 'Ref_num' },                                         // t1.num_doc_cli
            { data: 'banco' },                                           // t6.nom
            { data: 'ref_ben' },
            { data: 'fecha', className: 'text-nowrap' },                 // Fecha del pago
            { data: 'monto', render: $.fn.dataTable.render.number(',', '.', 2) }, // Monto del pago
            { data: 'folio' },                                           // t8.id_doc
            { data: 'folio_dr' },                                           // t8.id_doc
            { data: 'fec_doc' },
            { data: 'cargo', render: $.fn.dataTable.render.number(',', '.', 2) }, // Monto del pago
            { data: 'importe', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'imptos', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'total', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'aplicado', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'ptg_apl', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'uuid_i', className: 'text-nowrap' },
            { data: 'control' },
            { data: 'Factura' },
            { data: 'Fecha_control' },
            { data: 'Fecha_vencimiento' },
            { data: 'can', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'pre', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'mtoori', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'mtoiva', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'mto', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'total_control', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'producto' },                                        // t9.den
            { data: 'estacion' },
            { data: 'documento' }// t9.abr
        ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
            var cls = data.control_estado === 'SI' ? 'bg-success' : 'bg-danger';
            $('td:eq(19)', row)
              .addClass(cls)
              .text(data.control); // muestra “12345 SI” o “12345 NO”
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
        footerCallback: function (row, data, start, end, display) {
            // var api = this.api();

            // // Función para sumar valores en una columna
            // var intVal = function (i) {
            //     return typeof i === 'string' ?
            //         i.replace(/[\$,]/g, '') * 1 :
            //         typeof i === 'number' ? i : 0;
            // };
        
            // // Lista de columnas a sumar (desde 'can' en adelante)
            // var columnIndexes = [9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20];

            // api.columns().every(function (index) {
            //     if (index > 8 && index < 21 ) { // Desde la tercera columna en adelante
            //         // Sumatoria de los datos filtrados (página actual)
            //         var filteredSum = api
            //             .column(index, { page: 'current' }) // Solo datos visibles (filtrados)
            //             .data()
            //             .reduce((a, b) => intVal(a) + intVal(b), 0);
            
            //         // Sumatoria de todos los datos (incluyendo no visibles)
            //         var totalSum = api
            //             .column(index, { page: 'all' }) // Todos los datos
            //             .data()
            //             .reduce((a, b) => intVal(a) + intVal(b), 0);
            
            //         // Actualizar el footer con ambas sumatorias
            //         var footer = $(api.column(index).footer());
            //         footer.html(`
            //             <div>${filteredSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
            //             <div>Total: ${totalSum.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</div>
            //         `);
            //     }
            // });
        }
    });
}

async function  SearchResults(){
    const year = document.getElementById('year').value;

    if ($.fn.DataTable.isDataTable('#income_statement_table')) {
        $('#income_statement_table').DataTable().destroy();
        $('#income_statement_table thead .filter').remove();
    }

    $('#income_statement_table thead').prepend($('#income_statement_table thead tr').clone().addClass('filter'));
    $('#income_statement_table thead tr.filter th').each(function (index) {
        col = $('#income_statement_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#income_statement_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#income_statement_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let income_statement_table =$('#income_statement_table').DataTable({
        order: [0, "asc"],
        colReorder: true,
        dom: '<"top"Bf>rt<"bottom"lip>',
        paging: true,
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
                'year':year
            },
            url: '/accounting/income_statement_table',
            timeout: 600000,
            error: function() {
                $('#income_statement_table').waitMe('hide');
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
            { data: 'Empresa',  title: 'Empresa' , className: 'text-nowrap' },
            { data: 'CentroCosto', title: 'Centro de Costo' , className: 'text-nowrap' },
            { data: 'CatCentroCosto', title: 'Estado de Resultados' , className: 'text-nowrap' },
            { data: 'NoCuenta', title: 'No. Cuenta' , className: 'text-nowrap' },
            { data: 'Rubro', title: 'Rubro' , className: 'text-nowrap' },
            { data: 'Concepto', title: 'Concepto' , className: 'text-nowrap' },
            { data: 'Enero', title: 'Enero',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Febrero', title: 'Febrero',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Marzo', title: 'Marzo',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Abril', title: 'Abril',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Mayo', title: 'Mayo',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Junio', title: 'Junio',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Julio', title: 'Julio',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Agosto', title: 'Agosto',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Septiembre', title: 'Septiembre',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Octubre', title: 'Octubre',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Noviembre', title: 'Noviembre',render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'Diciembre', title: 'Diciembre',render: $.fn.dataTable.render.number(',', '.', 2) },
        ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {

        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');

        },
        footerCallback: function (row, data, start, end, display) {
        }
    });
    
}
// console.log(localStorage);



async function drawAnnualTable() {
  const year = document.getElementById('year').value;
    try {
        const response = await fetch('/accounting/annual_table', {      
            method: 'POST',
            headers: {
                'Accept': 'text/html,application/json,*/*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
            body: `year=${year}`
        });
        console.log(response);

        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const html = await response.text();
        document.getElementById('annual_table_container').innerHTML = html;
    } catch (error) {
        console.error('Error cargando tabla:', error);
        alert('Ocurrió un error al cargar la tabla anual.');
    }
}