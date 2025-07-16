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




async function openAdjustmentModal(){
    try {
        $('#adjustmentModal').modal('show'); // Abre el modal
        const response = await fetch('/accounting/adjustmentModal', {
            method: 'POST',
            headers: {
                'Accept': 'application/json, text/javascript, */*',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            credentials: 'include',
        });

        const content = await response.text();
        // Inserta el contenido en el modal
        $('#adjustmentModal').find('#adjustmentModalContent').html(content);

    } catch (error) {
        console.error(error);
    }

}
function download_format_sales_petrotal(){
    fetch('/accounting/download_format_sales_petrotal')
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
        a.download = 'FormatoVentasPetrotal.xlsx'; // Nombre del archivo a descargar
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
    })
    .catch(error => console.error('Error:', error));
}


    async function upload_file_sales_petrotal() {
        const fileInput = document.getElementById('file_to_upload');
        const file = fileInput.files[0]; // Obtiene el primer archivo seleccionado
        if (!file) {
            toastr.error('Por favor, selecciona un archivo.', '¡Error!', { timeOut: 3000 });
            return;
        }
        $('.mistery_heather').addClass('loading');
        const formData = new FormData();
        formData.append('file_to_upload', file);
        try {
            const response = await fetch('/accounting/import_file_sales_petrotal', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            console.log(data);
    
            if (data['success'] == false) {
                toastr.error(data['message'], '¡Error!', { timeOut: 3000 });
                $('.mistery_heather').removeClass('loading');
                fileInput.value = '';
                return;
            }
    
            if (data['success'] == true) {
                toastr.success('Archivo subido exitosamente ', '¡Éxito!', { timeOut: 3000 });
                $('.mistery_heather').removeClass('loading');
                fileInput.value = '';
                // mistery_shopper_table();
                // setTimeout(() => {
                //     window.location.reload();
                // }, 2000);
            } 
        } catch (error) {
            console.error('Error al subir el archivo:', error);
            // $('.mistery_heather').removeClass('loading');
            // $('.mistery_heather').removeClass('loading');
    
            toastr.error('Hubo un problema al subir el archivo.', '¡Error!', { timeOut: 3000 });
        }
        $('.mistery_heather').removeClass('loading');
    
    }


async function upload_file_concept_petrotal() {
    const fileInput = document.getElementById('file_to_upload2');
    date = $('#month_to_upload').val();
    const file = fileInput.files[0]; // Obtiene el primer archivo seleccionado
    if (!file) {
        toastr.error('Por favor, selecciona un archivo.', '¡Error!', { timeOut: 3000 });
        return;
    }
    // $('.er_petrotal_heather').addClass('loading');
    const formData = new FormData();
    formData.append('file_to_upload', file);
    formData.append('date', date); // Agrega la fecha al FormData
    try {
        $('.er_petrotal_heather').addClass('loading');
        const response = await fetch('/accounting/import_file_concept_petrotal', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        console.log(data);

        if (data['success'] == false) {
            toastr.error(data['message'], '¡Error!', { timeOut: 3000 });
            $('.er_petrotal_heather').removeClass('loading');
            fileInput.value = '';
            return;
        }

        if (data['success'] == true) {
            toastr.success('Archivo subido exitosamente ', '¡Éxito!', { timeOut: 3000 });
            $('.er_petrotal_heather').removeClass('loading');
            fileInput.value = '';
            // mistery_shopper_table();
            // setTimeout(() => {
            //     window.location.reload();
            // }, 2000);
        } 
    } catch (error) {
        console.error('Error al subir el archivo:', error);
        // $('.mistery_heather').removeClass('loading');
        // $('.mistery_heather').removeClass('loading');

        toastr.error('Hubo un problema al subir el archivo.', '¡Error!', { timeOut: 3000 });
    }
    $('.er_petrotal_heather').removeClass('loading');

}

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

// Formatea números con separador de miles (sin decimales)
function formatea(valor) {
  if (typeof valor === 'number') {
    return valor.toLocaleString('es-MX', { minimumFractionDigits: 0 });
  }
  return valor || '-';
}





function toggleSection(div) {

  // alterna el icono
  const icon = div.querySelector('i');
  icon.classList.toggle('fa-chevron-down');
  icon.classList.toggle('fa-chevron-right');

  // oculta/muestra hasta el siguiente .divider
  let next = div.nextElementSibling;
  while (next && !next.classList.contains('divider')) {
    next.style.display = next.style.display === 'none' ? '' : 'none';
    next = next.nextElementSibling;
  }
}

function toggleGroup(tr) {
  const icon = tr.querySelector('i');
  icon.classList.toggle('fa-chevron-down');
  icon.classList.toggle('fa-chevron-right');

  // oculta/enseña hasta el siguiente header (.fw-bold)
  let next = tr.nextElementSibling;
  while (next && !next.classList.contains('fw-bold')) {
    next.style.display = next.style.display === 'none' ? '' : 'none';
    next = next.nextElementSibling;
  }
}


async function annual_budgetTable(){
  const container = document.getElementById('annual_budget');
  container.classList.add('loading');

  try {
    // 1) Fetch y parseo
    const year = parseInt(document.getElementById('input_year').value, 10);
    const response = await fetch(`/accounting/get_er_budget?year=${year}`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json, text/javascript, */*',
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      credentials: 'include',
      body: `year=${year}`
    });
    const api = await response.json();

    // 2) Defino todas las secciones con su categoría
    const allSections = [
      // → Estaciones
      { label: 'A - INGRESOS',             dataKey: 'ingresos_estaciones',         category: 'estaciones' },
      { label: 'B - COSTO DE VENTA',       dataKey: 'costoventa_estaciones',       category: 'estaciones' },
      { label: 'E - GASTOS DE OPERACION',  dataKey: 'gastos_operacion_estaciones', category: 'estaciones' },
      { label: 'C - NOMINA',               dataKey: 'nomina_estaciones',           category: 'estaciones' },
      { label: 'D - COSTO SOCIAL',         dataKey: 'costo_social_estaciones',     category: 'estaciones' },
      { label: 'F - MANTENIMIENTO',        dataKey: 'mantenimiento_estaciones',    category: 'estaciones' },
      { label: 'H - GASTOS FIJOS',         dataKey: 'gastos_fijos_estaciones',     category: 'estaciones' },

      // → Staff
      { label: 'E - GASTOS DE OPERACION',  dataKey: 'gastos_operacion_staff',      category: 'staff' },
      { label: 'C - NOMINA',               dataKey: 'nomina_staff',                category: 'staff' },
      { label: 'D - COSTO SOCIAL',         dataKey: 'costo_social_staff',          category: 'staff' },
      { label: 'F - MANTENIMIENTO',        dataKey: 'mantenimiento_staff',         category: 'staff' },
      { label: 'H - GASTOS FIJOS',         dataKey: 'gastos_fijos_staff',          category: 'staff' },
    ];

    // 3) Preparo los mapas de resumen para cada categoría
    const summaryMaps = {
      estaciones: (api.rubro_estaciones || []).reduce((acc, x) => {
        acc[x.Rubro] = x; return acc;
      }, {}),
      staff:      (api.rubro_staff      || []).reduce((acc, x) => {
        acc[x.Rubro] = x; return acc;
      }, {}),
    };

    // 4) Meses y referencias DOM
    const meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                   'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    const table = document.getElementById('annual_budgetTable');
    const thead = table.querySelector('thead');
    const tbody = document.getElementById('body_annual_budget');
    thead.innerHTML = '';
    tbody.innerHTML = '';

    // 5) Encabezado de la tabla
    const headerRow = document.createElement('tr');
    headerRow.innerHTML = `<th>CONCEPTO</th>` +
      meses.map(m => `<th>${m.toUpperCase()}</th>`).join('');
    thead.appendChild(headerRow);

    // 6) Helpers
    function createSummaryRow(label, sums) {
      const tr = document.createElement('tr');
      tr.classList.add('table-light', 'fw-bold');
      tr.setAttribute('onclick', 'toggleGroup(this)');
      let inner = `<td><i class="fas fa-chevron-down pe-2"></i>${label}</td>`;
      inner += meses.map(m => `<td> ${formatea(sums[m] || 0)}</td>`).join('');
      tr.innerHTML = inner;
      return tr;
    }
    function createDetailRows(list) {
      return list.map(item => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${item.Concepto}</td>` +
          meses.map(m => `<td> ${formatea(item[m] ?? 0)}</td>`).join('');
        return tr;
      });
    }

    // 7) Recorro todas las secciones, eligiendo correctamente summaryMap según category
    // Y entre medio agrego el separador “Gastos Staff”
    let insertedStaffSeparator = false;
    for (const sec of allSections) {
      // Cuando empiece la primera sección de staff, inserto el separador
      if (!insertedStaffSeparator && sec.category === 'staff') {
        const sep = document.createElement('tr');
        sep.classList.add('table-secondary', 'fw-bold');
        sep.innerHTML = `<td colspan="${1 + meses.length}" class="text-start">Gastos Staff</td>`;
        tbody.appendChild(sep);
        insertedStaffSeparator = true;
      }

         // A) fila resumen
        const sums    = summaryMaps[sec.category][sec.label] || {};
        const summary = createSummaryRow(sec.label, sums);
        tbody.appendChild(summary);


      // B) filas detalle
      const details = api[sec.dataKey] || [];
      createDetailRows(details).forEach(r => tbody.appendChild(r));
    }

    container.classList.remove('loading');
    const summaryRows = document.querySelectorAll('#body_annual_budget tr.table-light');
    setTimeout(() => {
        summaryRows.forEach(row => {
            toggleGroup(row);
        });
    }, 0);
}
  catch (err) {
    console.error('Error al dibujar tabla presupuesto:', err);
    container.classList.remove('loading');
  }
}



async function drawAnnualTable() {
  const container = document.getElementById('Edo_anual');
  container.classList.add('loading');

  const year = parseInt(document.getElementById('input_year').value, 10);
  const prevYear = year - 1;

  const meses = [
    'Enero', 'Febrero', 'Marzo', 'Abril','Mayo', 'Junio', 'Julio', 'Agosto',
    'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
  ];

  try {
    const api = await fetchData(year);

    const table = document.getElementById('estadoResultadosTable');
    const thead = table.querySelector('thead');
    var tbody = document.getElementById('bodyEstadoResultados');
    thead.innerHTML = '';
    tbody.innerHTML = '';
    console.log('API response:', api);

    buildTableHeader(thead, year, prevYear, meses);
    renderTableBody(api, meses);

    container.classList.remove('loading');

     const summaryRows = document.querySelectorAll('#estadoResultadosTable tr.table-light');
    setTimeout(() => {
        summaryRows.forEach(row => {
            toggleGroup(row);
        });
    }, 0);

  } catch (error) {
    console.error('Error al dibujar tabla anual:', error);
  }
}

///////////contrulle el header de la tabla
function buildTableHeader(thead, year, prevYear, meses) {
    const colors = ['#92D050', '#0070C0'];
    const trMeses = document.createElement('tr');
    trMeses.innerHTML = `<th class="" rowspan="2">CONCEPTO</th>`;
    meses.forEach((m, i) => {
        const color = colors[i % 2];
        trMeses.innerHTML += `<th colspan="8" style="background:${color}; color: #fff;">${m.toUpperCase()}</th>`;
    });
    thead.appendChild(trMeses);

    const trSub = document.createElement('tr');
    meses.forEach(() => {
        trSub.innerHTML += `
        <th class="sub_header_blue">${prevYear}</th>
        <th class="sub_header_grey">% Part</th>
        <th class="sub_header_blue">${year}</th>
        <th class="sub_header_grey">% Part</th>
        <th class="sub_header_blue">Ptto ${year}</th>
        <th class="sub_header_grey">% Part</th>
        <th class="sub_header_grey">Var AA%</th>
        <th class="sub_header_grey">Var Ppto%</th>
        `;
    });
    thead.appendChild(trSub);
}

/////////////////////contrulle el cuerpo de la tabla
function renderTableBody( api, months) {

    const {
        budget = {},
        secciones_estaciones: estaciones = {},
        secciones_estaciones_last_year: estacionesLastYear = {},
        secciones_staff: staff = {},
        secciones_staff_last_year: staffLastYear = {},
        sumas_por_rubro_mes: sumasPorRubroMes = {},
        sumas_por_rubro_mes_last_year: sumasPorRubroMesLastYear = {},
        porcentajes_vs_ingresos: porcentajesVsIngresos = {},
        porcentajes_vs_ingresos_last_year: porcentajesVsIngresosLastYear = {},
        porcentajes_vs_ingresos_staff: porcentajesVsIngresosStaff = {}
    } = api;
    BudgetTotalIngresos = api.budget.rubro_estaciones.find(r => r.Rubro === 'A - INGRESOS') || {};
    const getSumas = (rubro, type) => sumasPorRubroMes[rubro]?.[type] || {};
    const getPorcentajes = (rubro, type) => (type === 'ESTACIONES' ? porcentajesVsIngresos : porcentajesVsIngresosStaff)[rubro] || {};
    const getBudgetRubro = (rubro) => budget.rubro_estaciones?.find(r => r.Rubro === rubro) || {};
    const getBudgetRubroStaff = (rubro) => budget.rubro_staff?.find(r => r.Rubro === rubro) || {};
    const getBudgetConceptos = (sectionType) => budget[sectionType] || [];
    //////lastyear
    const getSumasLastYear = (rubro, type) => sumasPorRubroMesLastYear[rubro]?.[type] || {};
    const getPorcentajesLastYear = (rubro, type) => (type === 'ESTACIONES' ? porcentajesVsIngresosLastYear : porcentajesVsIngresosStaff)[rubro] || {};

    renderDivider('INGRESOS', months.length);
    renderSection(
        'A - INGRESOS', estaciones.ingresos_estaciones || [],estacionesLastYear.ingresos_estaciones || [],
        getSumas('A - INGRESOS', 'ESTACIONES'), 
        getSumasLastYear('A - INGRESOS', 'ESTACIONES'),
        getPorcentajes('A - INGRESOS', 'ESTACIONES'),
        getPorcentajesLastYear('A - INGRESOS', 'ESTACIONES'),
        getBudgetRubro('A - INGRESOS'), getBudgetConceptos('ingresos_estaciones'), months, BudgetTotalIngresos
    );
    renderSection(
        'B - COSTO DE VENTA', estaciones.costo_venta_estaciones || [],estacionesLastYear.costo_venta_estaciones || [],
        getSumas('B - COSTO DE VENTA', 'ESTACIONES'),
        getSumasLastYear('B - COSTO DE VENTA', 'ESTACIONES'),
        getPorcentajes('B - COSTO DE VENTA', 'ESTACIONES'),
        getPorcentajesLastYear('B - COSTO DE VENTA', 'ESTACIONES'),
        getBudgetRubro('B - COSTO DE VENTA'), getBudgetConceptos('costoventa_estaciones'), months,BudgetTotalIngresos
    );
    renderDivider( 'GASTOS ESTACIONES', months.length);
    renderSection(
        'E - GASTOS DE OPERACION', estaciones.gastos_operacion_estaciones || [],estacionesLastYear.gastos_operacion_estaciones || [],
        getSumas('E - GASTOS DE OPERACION', 'ESTACIONES'),
        getSumasLastYear('E - GASTOS DE OPERACION', 'ESTACIONES'),
        getPorcentajes('E - GASTOS DE OPERACION', 'ESTACIONES'),
        getPorcentajesLastYear('E - GASTOS DE OPERACION', 'ESTACIONES'),
        getBudgetRubro('E - GASTOS DE OPERACION'), getBudgetConceptos('gastos_operacion_estaciones'), months,BudgetTotalIngresos
    );
    renderSection(
         'C - NOMINA', estaciones.nomina_estaciones || [],estacionesLastYear.nomina_estaciones || [],
        getSumas('C - NOMINA', 'ESTACIONES'),
        getSumasLastYear('C - NOMINA', 'ESTACIONES'),
        getPorcentajes('C - NOMINA', 'ESTACIONES'),
        getPorcentajesLastYear('C - NOMINA', 'ESTACIONES'),
        getBudgetRubro('C - NOMINA'), getBudgetConceptos('nomina_estaciones'), months,BudgetTotalIngresos
    );
    renderSection(
         'D - COSTO SOCIAL', estaciones.costo_social_estaciones || [],estacionesLastYear.costo_social_estaciones || [],
        getSumas('D - COSTO SOCIAL', 'ESTACIONES'),
        getSumasLastYear('D - COSTO SOCIAL', 'ESTACIONES'),
        getPorcentajes('D - COSTO SOCIAL', 'ESTACIONES'),
        getPorcentajesLastYear('D - COSTO SOCIAL', 'ESTACIONES'),
        getBudgetRubro('D - COSTO SOCIAL'), getBudgetConceptos('costo_social_estaciones'), months,BudgetTotalIngresos
    );
    renderSection(
         'F - MANTENIMIENTO', estaciones.mantenimiento_estaciones || [],estacionesLastYear.mantenimiento_estaciones || [],
        getSumas('F - MANTENIMIENTO', 'ESTACIONES'), 
        getSumasLastYear('F - MANTENIMIENTO', 'ESTACIONES'),
        getPorcentajes('F - MANTENIMIENTO', 'ESTACIONES'),
        getPorcentajesLastYear('F - MANTENIMIENTO', 'ESTACIONES'),
        getBudgetRubro('F - MANTENIMIENTO'), getBudgetConceptos('mantenimiento_estaciones'), months,BudgetTotalIngresos
    );
    renderSection(
         'H - GASTOS FIJOS', estaciones.gastos_fijos_estaciones || [],estacionesLastYear.gastos_fijos_estaciones || [],
        getSumas('H - GASTOS FIJOS', 'ESTACIONES'), 
        getSumasLastYear('H - GASTOS FIJOS', 'ESTACIONES'),
        getPorcentajes('H - GASTOS FIJOS', 'ESTACIONES'), 
        getPorcentajesLastYear('H - GASTOS FIJOS', 'ESTACIONES'),
        getBudgetRubro('H - GASTOS FIJOS'), getBudgetConceptos('gastos_fijos_estaciones'), months,BudgetTotalIngresos
    );

    renderDivider( 'GASTOS STAFF', months.length);
    renderSection(
         'E - GASTOS DE OPERACION', staff.gastos_operacion_staff || [],staffLastYear.gastos_operacion_staff || [],
        getSumas('E - GASTOS DE OPERACION', 'STAFF'), 
        getSumasLastYear('E - GASTOS DE OPERACION', 'STAFF'),
        getPorcentajes('E - GASTOS DE OPERACION', 'STAFF'),
        getPorcentajesLastYear('E - GASTOS DE OPERACION', 'STAFF'),
        getBudgetRubroStaff('E - GASTOS DE OPERACION'), getBudgetConceptos('gastos_operacion_staff'), months,BudgetTotalIngresos
    );
    renderSection(
         'C - NOMINA', staff.nomina_staff || [],staffLastYear.nomina_staff || [],
        getSumas('C - NOMINA', 'STAFF'), 
        getSumasLastYear('C - NOMINA', 'STAFF'),
        getPorcentajes('C - NOMINA', 'STAFF'),
        getPorcentajesLastYear('C - NOMINA', 'STAFF'),
        getBudgetRubroStaff('C - NOMINA'), getBudgetConceptos('nomina_staff'), months,BudgetTotalIngresos
    );
    renderSection(
         'D - COSTO SOCIAL', staff.costo_social_staff || [],staffLastYear.costo_social_staff || [],
        getSumas('D - COSTO SOCIAL', 'STAFF'), 
        getSumasLastYear('D - COSTO SOCIAL', 'STAFF'),
        getPorcentajes('D - COSTO SOCIAL', 'STAFF'),
        getPorcentajesLastYear('D - COSTO SOCIAL', 'STAFF'),
        getBudgetRubroStaff('D - COSTO SOCIAL'), getBudgetConceptos('costo_social_staff'), months,BudgetTotalIngresos
    );
    renderSection(
         'F - MANTENIMIENTO', staff.mantenimiento_staff || [],staffLastYear.mantenimiento_staff || [],
        getSumas('F - MANTENIMIENTO', 'STAFF'),
        getSumasLastYear('F - MANTENIMIENTO', 'STAFF'),
        getPorcentajes('F - MANTENIMIENTO', 'STAFF'),
        getPorcentajesLastYear('F - MANTENIMIENTO', 'STAFF'),
        getBudgetRubroStaff('F - MANTENIMIENTO'), getBudgetConceptos('mantenimiento_staff'), months,BudgetTotalIngresos
    );
    renderSection(
         'H - GASTOS FIJOS', staff.gastos_fijos_staff || [],staffLastYear.gastos_fijos_staff || [],
        getSumas('H - GASTOS FIJOS', 'STAFF'),
        getSumasLastYear('H - GASTOS FIJOS', 'STAFF'),
        getPorcentajes('H - GASTOS FIJOS', 'STAFF'),
        getPorcentajesLastYear('H - GASTOS FIJOS', 'STAFF'),
        getBudgetRubroStaff('H - GASTOS FIJOS'), getBudgetConceptos('gastos_fijos_staff'), months,BudgetTotalIngresos
    );

}



function renderDivider(label, numMeses) {
    var tbody = document.getElementById('bodyEstadoResultados');
    const tr = document.createElement('tr');
    tr.classList.add('table-primary', 'text-white', 'fw-bold', 'text-start', 'divider');
    //   tr.setAttribute('onclick', 'toggleSection(this)');
    tr.innerHTML = `
        <td colspan="${1 + numMeses * 8}">
        <i class="fas fa-chevron-down pe-2"></i> ${label}
        </td>
    `;
    tbody.appendChild(tr);
}

function renderSection( titulo, data,data_last_year, sumas, sumas_last_year, porcentajes, porcentajes_last_year, budget_rubro, budget_conceptos, meses,BudgetTotalIngresos, soloMostrarTotal = false) {
    var tbody = document.getElementById('bodyEstadoResultados');
    const trTitulo = document.createElement('tr');
    trTitulo.classList.add('table-light', 'fw-bold');
    if (!soloMostrarTotal) trTitulo.setAttribute('onclick', 'toggleGroup(this)');

    trTitulo.innerHTML = `<td><i class="fas fa-chevron-down pe-2"></i> ${titulo}</td>`;
    meses.forEach(mes => {
        const total = sumas?.[mes] ?? '-';
        const total_last_year = sumas_last_year?.[mes] ?? '-';
        const porcentaje = porcentajes?.[mes] ?? '-';
        const porcentaje_last_year = porcentajes_last_year?.[mes] ?? '-';
        const presupuesto = formatea(budget_rubro?.[mes]) ?? '-';
        trTitulo.innerHTML += `
        <td>${formatea(total_last_year)}</td><td>${porcentaje_last_year} %</td>
        <td>${formatea(total)}</td>
        <td>${porcentaje} %</td><td>${presupuesto}</td><td>-</td><td>-</td><td>-</td>
        `;
    });
    tbody.appendChild(trTitulo);

    data.forEach(fila => {
        const fila_last_year = data_last_year.find(f => f.concepto === fila.concepto) || {};
        const budget_concepto = budget_conceptos.find(b => b.Concepto === fila.concepto) || {};
        const tr = document.createElement('tr');
        tr.innerHTML = `<td class='concept_td' >${fila.concepto}</td>`;
        meses.forEach(mes => {

        const val = fila[mes]?.total ?? 0;
        const val_last_year = fila_last_year[mes]?.total ?? 0;
        const pct_last_year = fila_last_year[mes]?.porcentaje ?? '-';
        const pct = fila[mes]?.porcentaje ?? '-';
        const presupuesto = formatea(budget_concepto[mes]) ?? '-';
        ingreso_presupuesto = BudgetTotalIngresos[mes] || 0;
        var porcen_presupuesto =  (budget_concepto[mes]/ingreso_presupuesto) * 100 || 0;
            porcen_presupuesto = porcen_presupuesto.toFixed(1);
        let variacion = budget_concepto[mes] ? ((val / budget_concepto[mes]) - 1) *100: 0;
            variacion = Number(variacion.toFixed(1)); // Para comparación numérica
            const variacionClass = variacion < 0 ? 'variacion-negativa' : '';


        tr.innerHTML += `
            <td class="year_value">${formatea(val_last_year)}</td>
            <td class="porcent_value">${pct_last_year}</td>
            <td class="year_value" >${formatea(val)}</td>
            <td class="porcent_value">${pct}</td>
            <td class="year_value">${presupuesto}</td>
            <td class="porcent_value">${(porcen_presupuesto)} %</td>
            <td>-</td>
            <td class="${variacionClass} year_value">${variacion} %</td>
        `;
        });
        tbody.appendChild(tr);
    });
}

function formatea(valor) {
  if (typeof valor === 'number') {
    return valor.toLocaleString('es-MX', { minimumFractionDigits: 0 });
  }
  return valor || '-';
}
async function fetchData(year) {
  const response = await fetch('/accounting/drawAnnualTable', {
    method: 'POST',
    headers: {
      'Accept': 'application/json, text/javascript, */*',
      'Content-Type': 'application/x-www-form-urlencoded'
    },
    credentials: 'include',
    body: `year=${year}`
  });

  if (!response.ok) {
    throw new Error(`HTTP error! status: ${response.status}`);
  }

  return response.json();
}


async function sales_petrotal_table(){
    if ($.fn.DataTable.isDataTable('#sales_petrotal_table')) {
        $('#sales_petrotal_table').DataTable().destroy();
        $('#sales_petrotal_table thead .filter').remove();
        // $('#sales_petrotal_table').DataTable().destroy();  // Destruye la tabla existente
        // $('#sales_petrotal_table thead').empty(); // Limpia el encabezado
        // $('#sales_petrotal_table tbody').empty(); // Limpia el cuerpo
        // $('#sales_petrotal_table tfoot').empty(); // Limpia el pie de tabla si lo usas
    }
    var fromDate = document.getElementById('from').value;
    var untilDate = document.getElementById('until').value;

    $('#sales_petrotal_table thead').prepend($('#sales_petrotal_table thead tr').clone().addClass('filter'));
    $('#sales_petrotal_table thead tr.filter th').each(function (index) {
        col = $('#sales_petrotal_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#sales_petrotal_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#sales_petrotal_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let sales_petrotal_table =$('#sales_petrotal_table').DataTable({
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
                'untilDate':untilDate
            },
            url: '/accounting/sales_petrotal_table',
            timeout: 600000, 
            error: function() {
                $('#sales_petrotal_table').waitMe('hide');
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
            { data: 'anio' },
            { data: 'mes_deuda' },
            { data: 'fecha', className: 'text-nowrap' },
            { data: 'factura' },
            { data: 'num_estacion' },
            { data: 'razon_social' },
            { data: 'estacion' },
            { data: 'cre_estacion' },
            { data: 'fecha_descarga', className: 'text-nowrap' },
            { data: 'proveedor' },
            { data: 'codigo_proveedor' },
            { data: 'cre_proveedor' },
            { data: 'combustible' },
            { data: 'factor_ieps', render: $.fn.dataTable.render.number(',', '.', 6) },
            { data: 'litros', render: $.fn.dataTable.render.number(',', '.', 3) },
            { data: 'precio', render: $.fn.dataTable.render.number(',', '.', 8) },
            { data: 'precio_litro', render: $.fn.dataTable.render.number(',', '.', 8) },
            { data: 'subtotal_con_ieps', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'ieps', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'subtotal_sin_ieps', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'iva', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'total', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'costo', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'factura_compra' },
            { data: 'utilidad_perdida', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'monto_pagado', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'iva_pagado', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'fecha_pago', className: 'text-nowrap' },
            { data: 'uuid' },
            { data: 'tasa_iva' },
            { data: 'indicador_1' }
        ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
    });
}
async function save_spend_petrotal(){
    var fecha =  document.getElementById('date_spent');
    var gasto =  document.getElementById('gasto');
    const formData = new FormData();
    formData.append('fecha', fecha.value); // Agrega la fecha al FormData
    formData.append('gasto', gasto.value); // Agrega el gasto al FormData
    try {
        $('.er_petrotal_heather').addClass('loading');
        const response = await fetch('/accounting/save_spend_petrotal', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data['success'] == false) {
            toastr.error(data['message'], '¡Error!', { timeOut: 3000 });
            $('.er_petrotal_heather').removeClass('loading');
            gasto.value = '';
            fecha.value = '';
            return;
        }

        if (data['success'] == true) {
            toastr.success('Gasto guardado exitosamente ', '¡Éxito!', { timeOut: 3000 });
            $('.er_petrotal_heather').removeClass('loading');
            gasto.value = '';
            spend_real();
        }
    } catch (error) {
        console.error('Error al subir el archivo:', error);
        $('.er_petrotal_heather').removeClass('loading');
        toastr.error('Hubo un problema al subir el archivo.', '¡Error!', { timeOut: 3000 });
    }
    $('.er_petrotal_heather').removeClass('loading');

}
async function generateReport() {
    er_petrotal_table();
    console.log('Generando reporte de Petrotal...');
    er_petrotal_concept();

}

async function er_petrotal_concept() {
    var fromDate = document.getElementById('from2').value;
    fromDate = fromDate + '-01';

    // Llamada AJAX clásica, puedes usar fetch
    const response = await fetch('/accounting/er_petrotal_concept', {
        method: 'POST',
        headers: {
        'Accept': 'application/json, text/javascript, */*',
        'Content-Type': 'application/x-www-form-urlencoded'
        },
        credentials: 'include',
            body: `date=${fromDate}`
        });
    const data = await response.json();

    // Llena la tabla manualmente
    const tbody = document.querySelector('#er_petrotal_concept_table tbody');
    tbody.innerHTML = ''; // Limpia tabla
    if(data.error){
        alertify.error(
            `<div class="text-light text-center ">
                <h4 class=" text-danger">¡Error!</h4>
            </div>
            <div class="text-light">
                <p class="text-center">${data.error}</p>
            </div>`
        );
        return;
    }

    data.forEach(row => {
        const tr = document.createElement('tr');
        // Ajusta los nombres de columna según tu JSON
        tr.innerHTML = `
            <td>${row.rubro}</td>
            <td>${row.cuenta}</td>
            <td>${row.valor}</td>
        `;
        tbody.appendChild(tr);
    });
}



async function er_petrotal_table(){
    if ($.fn.DataTable.isDataTable('#er_petrotal_table')) {
        $('#er_petrotal_table').DataTable().destroy();
        $('#er_petrotal_table thead .filter').remove();

    }
    var fromDate = document.getElementById('from2').value;
    fromDate = fromDate + '-01';
    $('#er_petrotal_table thead').prepend($('#er_petrotal_table thead tr').clone().addClass('filter'));
    $('#er_petrotal_table thead tr.filter th').each(function (index) {
        col = $('#er_petrotal_table thead th').length/2;
        if (index < col ) {
            var title = $(this).text(); // Obtiene el nombre de la columna
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder=" ' + title + '" />');
        }
    });
    $('#er_petrotal_table thead tr.filter th input').on('keyup change', function () {
        var index = $(this).parent().index(); // Obtiene el índice de la columna
        var table = $('#er_petrotal_table').DataTable(); // Obtiene la instancia de DataTable
        table
            .column(index)
            .search(this.value) // Busca el valor del input
            .draw(); // Redibuja la tabla
    });
    let er_petrotal_table =$('#er_petrotal_table').DataTable({
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
                'fromDate':fromDate
            },
            url: '/accounting/er_petrotal_table',
            timeout: 600000, 
            error: function() {
                $('#er_petrotal_table').waitMe('hide');
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
            { data: 'estacion', className: 'text-nowrap' },
            { data: 'etiqueta' },
            { data: 'diesel', className:'text-end', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'premium', className:'text-end', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'regular', className:'text-end', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'diesel_porcent', className:'text-end'  },  // si llega como decimal
            { data: 'premium_porcent', className:'text-end'  }, // si llega como decimal
            { data: 'regular_porcent', className:'text-end'  },   // si llega como decimal
            { data: 'diesel_utilidad', className:'text-end', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'premium_utilidad', className:'text-end', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'regular_utilidad', className:'text-end', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'total', className:'text-end', render: $.fn.dataTable.render.number(',', '.', 2) }
        ],
        deferRender: true,
        // destroy: true, 
        createdRow: function (row, data, dataIndex) {
        },
        initComplete: function () {
            $('.table-responsive').removeClass('loading');
            // addStationSummaryRow(dynamicColumns);  // Agregar fila de sumatoria por estación

        },
    });
}

function download_format_concept_petrotal(){
    fetch('/accounting/download_format_concept_petrotal')
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
        a.download = 'FormatoConceptosPetrotal.xlsx'; // Nombre del archivo a descargar
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
    })
    .catch(error => console.error('Error:', error));
}
async function form_save_adjustments(){
    console.log("Guardar ajustes"); 
    var form_save_adjustments = document.querySelector('.form_save_adjustments');
    var formData = new FormData(form_save_adjustments);
    fetch('/accounting/form_save_adjustments', {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            console.log(data);
            
            // if (data == 0) {
            //     $('#EditAlertModal').modal('hide');
            //     alert_active_table.clear().draw();
            //     alert_active_table.ajax.reload();
            //     $('div#loader').addClass('d-none');

            //     alert_Inactive_table.clear().draw();
            //     alert_Inactive_table.ajax.reload();
            //     $('div#loader').addClass('d-none');
            //   }
            //   if (data == 1) {
            //     alertify.error('No se Reactivo');
            //   }
        })
        .catch(error => {
            console.error(error);
        });

}
async function spend_real(){
    const spend_real = document.getElementById('spend_real');
    var fecha =  document.getElementById('date_spent').value;
    const formData = new FormData();
    formData.append('fecha', fecha); // Agrega la fecha al FormData
    try {
        const response = await fetch('/accounting/spend_real', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data['success'] == true) {
           spend_real.value = data['spend'];
        }

        // if (data['success'] == true) {
        //     toastr.success('Gasto guardado exitosamente ', '¡Éxito!', { timeOut: 3000 });
        //     $('.er_petrotal_heather').removeClass('loading');
        //     gasto.value = '';
        //     fecha.value = '';
        // }
    } catch (error) {
        console.error('eror al consultar gasto real:', error);
    }
}