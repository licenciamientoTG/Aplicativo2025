let tank_volume_table;
let consolidated_table;
let volumeChart;

$(document).ready(function() {
    // Inicializar DataTable individual
    tank_volume_table = $('#tank_volume_table').DataTable({
        colReorder: true,
        order: [[0, "desc"], [1, "desc"]],
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 50,
        data: [],
        buttons: [
            {
                extend: 'excel',
                className: 'd-none',
                title: 'Volumen_Tanques',
                exportOptions: {
                    columns: ':visible'
                }
            }
        ],
        columns: [
            {'data': 'FECHA'},
            {'data': 'HORA'},
            {'data': 'PRODUCTO'},
            {'data': 'VOLUMEN', 'render': $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'VOLUMEN_CXT', 'render': $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'AGUA', 'render': $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'CAP_MAXIMA', 'render': $.fn.dataTable.render.number(',', '.', 0)},
            {'data': 'CAP_OPERATIVA', 'render': $.fn.dataTable.render.number(',', '.', 0)},
            {'data': 'UTIL', 'render': $.fn.dataTable.render.number(',', '.', 0)},
            {'data': 'FONDAJE', 'render': $.fn.dataTable.render.number(',', '.', 0)},
            {'data': 'VOL_MINIMO', 'render': $.fn.dataTable.render.number(',', '.', 0)}
        ],
        initComplete: function () {
            $('.dt-buttons').addClass('d-none');
        }
    });

    // Inicializar DataTable consolidada con columna de estado
    consolidated_table = $('#consolidated_table').DataTable({
        colReorder: true,
        order: [[0, "asc"], [1, "asc"]],
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 100,
        data: [],
        buttons: [
            {
                extend: 'excel',
                className: 'd-none',
                title: 'Reporte_Consolidado_Tanques',
                exportOptions: {
                    columns: [0, 1, 2, 3, 4, 5, 6, 7, 8]
                }
            }
        ],
        columns: [
            {'data': 'ESTACION'},
            {'data': 'TANQUE'},
            {'data': 'PRODUCTO'},
            {'data': 'VOL_MAXIMO', 'render': $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'VOL_MINIMO', 'render': $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'VOL_PROMEDIO', 'render': $.fn.dataTable.render.number(',', '.', 2)},
            {'data': 'CAP_MAXIMA', 'render': $.fn.dataTable.render.number(',', '.', 0)},
            {'data': 'CAP_MINIMA', 'render': $.fn.dataTable.render.number(',', '.', 0)},
            {
                'data': 'ESTADO',
                'render': function(data, type, row) {
                    if (data === 'SOBRE_MAXIMO') {
                        return '<span class="badge bg-danger">Sobre Máximo</span>';
                    } else if (data === 'BAJO_MINIMO') {
                        return '<span class="badge bg-warning">Bajo Mínimo</span>';
                    } else {
                        return '<span class="badge bg-success">Normal</span>';
                    }
                }
            }
        ],
        initComplete: function () {
            $('.dt-buttons').addClass('d-none');
        }
    });

    // Inicializar gráfico
    initChart();
});

// =================== TAB 1: CONSULTA INDIVIDUAL ===================
// (Tu código del tab 1 aquí - cargar tanques, consultar individual, etc.)

$('#station_select').on('change', function() {
    const codgas = $(this).val();
    const servidor = $(this).find(':selected').data('servidor');
    const base = $(this).find(':selected').data('base');
    
    if (!codgas) {
        $('#tank_select').prop('disabled', true).html('<option value="">Primero seleccione una estación</option>');
        return;
    }
    
    tank_volume_table.clear().draw();
    clearChart();
    $('#tank_info').text('');
    
    $('#tank_select').prop('disabled', true).html('<option value="">Cargando tanques...</option>');
    
    $.ajax({
        url: '/operations/get_tanks',
        method: 'POST',
        data: {
            codgas: codgas,
            servidor: servidor,
            base: base
        },
        success: function(response) {
            if (response.tanques && response.tanques.length > 0) {
                let options = '<option value="">Seleccione un tanque</option>';
                response.tanques.forEach(tank => {
                    options += `<option value="${tank.cod}" data-producto="${tank.producto}">Tanque ${tank.numero_tan} - ${tank.producto}</option>`;
                });
                $('#tank_select').prop('disabled', false).html(options);
            } else {
                $('#tank_select').html('<option value="">No se encontraron tanques</option>');
                alertify.warning('No se encontraron tanques para esta estación');
            }
        },
        error: function(xhr) {
            $('#tank_select').prop('disabled', false).html('<option value="">Error al cargar tanques</option>');
            console.error('Error response:', xhr.responseText);
            alertify.error('Error al cargar los tanques');
        }
    });
});

$('#btn_consultar_volumen').on('click', function() {
    const codgas = $('#station_select').val();
    const codtan = $('#tank_select').val();
    const from_date = $('#from_date').val();
    const until_date = $('#until_date').val();
    const stationName = $('#station_select option:selected').text();
    const tankName = $('#tank_select option:selected').text();
    const producto = $('#tank_select option:selected').data('producto');
    
    if (!codgas || !codtan) {
        alertify.error('Por favor seleccione una estación y un tanque');
        return;
    }
    if (!from_date || !until_date) {
        alertify.error('Por favor seleccione ambas fechas');
        return;
    }
    
    if (new Date(from_date) > new Date(until_date)) {
        alertify.error('La fecha inicial debe ser menor a la fecha final');
        return;
    }
    
    
    
    $('#progress_container_volume').removeClass('d-none');
    $('#progress_bar_volume').css('width', '30%');
    $('#progress_text_volume').text('Consultando volumen del tanque...');
    
    $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Consultando...');
    
    tank_volume_table.clear().draw();
    clearChart();
    
    $.ajax({
        url: '/operations/tank_volume_table',
        method: 'POST',
        data: {
            codgas: codgas,
            codtan: codtan,
            from_date: from_date,
            until_date: until_date
        },
        beforeSend: function() {
            $('#progress_bar_volume').css('width', '50%');
            $('.table-responsive').addClass('loading');
        },
        success: function(response) {
            $('#progress_bar_volume').css('width', '100%').removeClass('progress-bar-animated');
            $('#progress_text_volume').text('¡Consulta completada!');
            
            if (response.data && response.data.length > 0) {
                $('#tank_info').text(`${stationName} - ${tankName}`);
                tank_volume_table.rows.add(response.data).draw();
                updateChart(response.data, producto);
                alertify.success(`Se cargaron ${response.data.length} registros`);
            } else {
                alertify.warning('No se encontraron registros para los parámetros especificados');
            }
            
            setTimeout(() => {
                $('#progress_container_volume').addClass('d-none');
            }, 2000);
        },
        error: function(xhr, status, error) {
            $('#progress_container_volume').addClass('d-none');
            alertify.error('Error al consultar: ' + error);
            console.error('Error:', xhr.responseText);
        },
        complete: function() {
            $('.table-responsive').removeClass('loading');
            $('#btn_consultar_volumen').prop('disabled', false).html('<i data-feather="search"></i> Consultar');
            feather.replace();
        }
    });
});

function initChart() {
    const ctx = document.getElementById('volumeChart').getContext('2d');
    volumeChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: [],
            datasets: [
                {
                    label: 'Volumen',
                    data: [],
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.1
                },
                {
                    label: 'Capacidad Operativa',
                    data: [],
                    borderColor: 'rgb(255, 159, 64)',
                    backgroundColor: 'rgba(255, 159, 64, 0.2)',
                    borderDash: [5, 5],
                    tension: 0.1
                },
                {
                    label: 'Volumen Mínimo',
                    data: [],
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    borderDash: [5, 5],
                    tension: 0.1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: true,
                    text: 'Historial de Volumen del Tanque'
                }
            },
            scales: {
                y: {
                    title: {
                        display: true,
                        text: 'Litros'
                    },
                    ticks: {
                        callback: function(value) {
                            return new Intl.NumberFormat('es-MX').format(value);
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Fecha y Hora'
                    }
                }
            }
        }
    });
}

function updateChart(data, producto) {
    const reversedData = [...data].reverse();
    
    const labels = reversedData.map(row => `${row.FECHA} ${row.HORA}`);
    const volumen = reversedData.map(row => parseFloat(row.VOLUMEN));
    const capOperativa = reversedData.map(row => parseFloat(row.CAP_OPERATIVA));
    const volMin = reversedData.map(row => parseFloat(row.VOL_MINIMO));
    
    volumeChart.data.labels = labels;
    volumeChart.data.datasets[0].data = volumen;
    volumeChart.data.datasets[0].label = `Volumen - ${producto}`;
    volumeChart.data.datasets[1].data = capOperativa;
    volumeChart.data.datasets[2].data = volMin;
    
    volumeChart.update();
}

function clearChart() {
    volumeChart.data.labels = [];
    volumeChart.data.datasets[0].data = [];
    volumeChart.data.datasets[1].data = [];
    volumeChart.data.datasets[2].data = [];
    volumeChart.update();
}

// =================== TAB 2: REPORTE CONSOLIDADO ===================

$('#btn_consultar_consolidado').on('click', function() {
    const fromDate = $('#from_date_cons').val();
    const untilDate = $('#until_date_cons').val();
    
    if (!fromDate || !untilDate) {
        alertify.error('Por favor seleccione ambas fechas');
        return;
    }
    
    if (new Date(fromDate) > new Date(untilDate)) {
        alertify.error('La fecha inicial debe ser menor a la fecha final');
        return;
    }
    
    $('#progress_container_cons').removeClass('d-none');
    $('#progress_bar_cons').css('width', '30%');
    $('#progress_text_cons').text('Consultando todas las estaciones en paralelo...');
    
    $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Consultando...');
    
    consolidated_table.clear().draw();
    clearAlerts();
    
    $.ajax({
        url: '/operations/tank_consolidated_report',
        method: 'POST',
        data: {
            from: fromDate,
            until: untilDate
        },
        beforeSend: function() {
            $('#progress_bar_cons').css('width', '50%');
        },
        success: function(response) {
            $('#progress_bar_cons').css('width', '100%').removeClass('progress-bar-animated');
            $('#progress_text_cons').text('¡Consulta completada!');
            
            if (response.data && response.data.length > 0) {
                processAlertsAndDisplay(response.data);
                alertify.success(`Se cargaron ${response.data.length} registros de tanques`);
            } else {
                alertify.warning('No se encontraron registros para el rango de fechas especificado');
            }
            
            setTimeout(() => {
                $('#progress_container_cons').addClass('d-none');
            }, 2000);
        },
        error: function(xhr, status, error) {
            $('#progress_container_cons').addClass('d-none');
            alertify.error('Error al consultar: ' + error);
            console.error('Error:', xhr.responseText);
        },
        complete: function() {
            $('#btn_consultar_consolidado').prop('disabled', false).html('<i data-feather="download-cloud"></i> Consultar Todas las Estaciones');
            feather.replace();
        }
    });
});

function processAlertsAndDisplay(data) {
    let bajoMinimo = [];
    let sobreMaximo = [];
    
    // Procesar cada tanque y agregar estado
    const processedData = data.map(row => {
        let estado = 'NORMAL';
        
        if (parseFloat(row.VOL_MINIMO) < parseFloat(row.CAP_MINIMA)) {
            estado = 'BAJO_MINIMO';
            bajoMinimo.push(row);
        }
        
        if (parseFloat(row.VOL_MAXIMO) > parseFloat(row.CAP_MAXIMA)) {
            estado = 'SOBRE_MAXIMO';
            sobreMaximo.push(row);
        }
        
        return {
            ...row,
            ESTADO: estado
        };
    });
    
    // Actualizar cards
    $('#total_tanques').text(data.length);
    $('#tanques_bajo_minimo').text(bajoMinimo.length);
    $('#tanques_sobre_maximo').text(sobreMaximo.length);
    
    // Mostrar tabla consolidada
    consolidated_table.rows.add(processedData).draw();
    
    // Mostrar alertas de tanques bajo mínimo
    if (bajoMinimo.length > 0) {
        $('#alertas_bajo_minimo_container').show();
        let html = '';
        bajoMinimo.forEach(row => {
            const diferencia = parseFloat(row.CAP_MINIMA) - parseFloat(row.VOL_MINIMO);
            html += `
                <tr>
                    <td>${row.ESTACION}</td>
                    <td>${row.TANQUE}</td>
                    <td>${row.PRODUCTO}</td>
                    <td>${new Intl.NumberFormat('es-MX', {minimumFractionDigits: 2}).format(row.VOL_MINIMO)}</td>
                    <td>${new Intl.NumberFormat('es-MX', {minimumFractionDigits: 0}).format(row.CAP_MINIMA)}</td>
                    <td class="text-warning fw-bold">-${new Intl.NumberFormat('es-MX', {minimumFractionDigits: 2}).format(diferencia)}</td>
                </tr>
            `;
        });
        $('#alertas_bajo_minimo_body').html(html);
    } else {
        $('#alertas_bajo_minimo_container').hide();
    }
    
    // Mostrar alertas de tanques sobre máximo
    if (sobreMaximo.length > 0) {
        $('#alertas_sobre_maximo_container').show();
        let html = '';
        sobreMaximo.forEach(row => {
            const diferencia = parseFloat(row.VOL_MAXIMO) - parseFloat(row.CAP_MAXIMA);
            html += `
                <tr>
                    <td>${row.ESTACION}</td>
                    <td>${row.TANQUE}</td>
                    <td>${row.PRODUCTO}</td>
                    <td>${new Intl.NumberFormat('es-MX', {minimumFractionDigits: 2}).format(row.VOL_MAXIMO)}</td>
                    <td>${new Intl.NumberFormat('es-MX', {minimumFractionDigits: 0}).format(row.CAP_MAXIMA)}</td>
                    <td class="text-danger fw-bold">+${new Intl.NumberFormat('es-MX', {minimumFractionDigits: 2}).format(diferencia)}</td>
                </tr>
            `;
        });
        $('#alertas_sobre_maximo_body').html(html);
    } else {
        $('#alertas_sobre_maximo_container').hide();
    }
    
    // Actualizar iconos de feather
    feather.replace();
}

function clearAlerts() {
    $('#total_tanques').text('0');
    $('#tanques_bajo_minimo').text('0');
    $('#tanques_sobre_maximo').text('0');
    $('#alertas_bajo_minimo_container').hide();
    $('#alertas_sobre_maximo_container').hide();
    $('#alertas_bajo_minimo_body').html('');
    $('#alertas_sobre_maximo_body').html('');
}

// Exportar Excel consolidado
$('#exportExcelCons').on('click', function(e) {
    e.preventDefault();
    consolidated_table.button('.buttons-excel').trigger();
});

// Limpiar tabla consolidada
$('#refresh_cons_table').on('click', function(e) {
    e.preventDefault();
    consolidated_table.clear().draw();
    clearAlerts();
    $('#from_date_cons').val('');
    $('#until_date_cons').val('');
    alertify.success('Datos limpiados');
});

// Exportar Excel individual
$('#exportExcelVolume').on('click', function(e) {
    e.preventDefault();
    tank_volume_table.button('.buttons-excel').trigger();
});

// Limpiar tabla individual
$('#refresh_volume_table').on('click', function(e) {
    e.preventDefault();
    tank_volume_table.clear().draw();
    clearChart();
    $('#tank_info').text('');
    $('#station_select').val('');
    $('#tank_select').prop('disabled', true).html('<option value="">Primero seleccione una estación</option>');
    $('#limit_records').val('100');
    alertify.success('Datos limpiados');
});

function prepararDatosGrafica(datos) {
    // Si hay más de 1000 puntos, agregar por día
    if (datos.length > 1000) {
        return agregarPorDia(datos);
    }
    // Si hay más de 200 puntos, agregar por hora
    if (datos.length > 200) {
        return agregarPorHora(datos);
    }
    return datos;
}

