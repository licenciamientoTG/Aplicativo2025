let inventories_distributed_table = $('#inventories_distributed_table').DataTable({
    colReorder: true,
    order: [0, "asc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            title: 'Inventarios_Mermas_Distribuido',
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7]
            }
        }
    ],
    columns: [
        {'data': 'ESTACION'},
        {'data': 'PRODUCTO'},
        {'data': 'SALDOINICIAL', 'render': $.fn.dataTable.render.number(',', '.', 3)},
        {'data': 'COMPRAS', 'render': $.fn.dataTable.render.number(',', '.', 3)},
        {'data': 'VENTAS', 'render': $.fn.dataTable.render.number(',', '.', 3)},
        {'data': 'SALDOFINAL', 'render': $.fn.dataTable.render.number(',', '.', 3)},
        {'data': 'SALDOREAL', 'render': $.fn.dataTable.render.number(',', '.', 3)},
        {'data': 'MERMA', 'render': $.fn.dataTable.render.number(',', '.', 3)},
        {'data': 'ACCIONES'},
    ],
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
    }
});

// Evento del botón de consulta distribuida
$('#btn_consultar_distribuido').on('click', function() {
    const fromDate = $('#from_dist').val();
    const untilDate = $('#until_dist').val();
    
    if (!fromDate || !untilDate) {
        alertify.error('Por favor seleccione ambas fechas');
        return;
    }

    // Mostrar progress bar
    $('#progress_container').removeClass('d-none');
    $('#progress_bar').css('width', '30%');
    $('#progress_text').text('Consultando estaciones distribuidas...');
    
    // Deshabilitar botón
    $('#btn_consultar_distribuido').prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Consultando...');
    
    // Mostrar loading en la tabla
    $('.table-responsive').addClass('loading');
    
    // Limpiar tabla
    inventories_distributed_table.clear().draw();

    // Llamar al backend PHP
    $.ajax({
        url: '/operations/inventories_distributed_table',
        method: 'POST',
        data: {
            from: fromDate,
            until: untilDate
        },
        beforeSend: function() {
            $('#progress_bar').css('width', '50%');
        },
        success: function(response) {
            $('#progress_bar').css('width', '100%').removeClass('progress-bar-animated');
            $('#progress_text').text('¡Consulta completada!');
            
            if (response.data && response.data.length > 0) {
                inventories_distributed_table.rows.add(response.data).draw();
                alertify.success(`Se cargaron ${response.data.length} registros`);
            } else {
                alertify.warning('No se encontraron registros para el rango de fechas especificado');
            }
            
            setTimeout(() => {
                $('#progress_container').addClass('d-none');
            }, 2000);
        },
        error: function(xhr, status, error) {
            $('#progress_container').addClass('d-none');
            alertify.myAlert(
                `<div class="container text-center text-danger">
                    <h4 class="mt-2 text-danger">¡Error!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">Error al consultar inventarios distribuidos. ${error}</p>
                </div>`
            );
            console.error('Error:', xhr.responseText);
        },
        complete: function() {
            $('.table-responsive').removeClass('loading');
            $('#btn_consultar_distribuido').prop('disabled', false).html('<i data-feather="download-cloud"></i> Consultar');
            feather.replace();
        }
    });
});

// Botón de exportar Excel distribuido
$('#exportExcelDist').on('click', function(e) {
    e.preventDefault();
    inventories_distributed_table.button('.buttons-excel').trigger();
});

// Botón de limpiar tabla distribuida
$('#refresh_distributed_table').on('click', function(e) {
    e.preventDefault();
    inventories_distributed_table.clear().draw();
    $('#from_dist').val('');
    $('#until_dist').val('');
    alertify.success('Tabla limpiada');
});