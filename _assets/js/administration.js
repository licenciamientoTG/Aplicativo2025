// Setup - add a text input to each footer cell
$('#datatables_monthly_dispatches tfoot th').each(function (index) {
    let name = $('#datatables_monthly_dispatches thead').find('th:eq(' + index + ')').html();
    $("div#datatables_monthly_dispatches_tools").append('<a class="toggle-vis" data-column="' + index + '">' + name + '</a> / ');
    if ($(this)[0] != $('#datatables_monthly_dispatches thead th').last()[0]) {
        $(this).html('<input class="form-control form-control-sm shadow" type="text" placeholder="' + name + '" />');
    }
});
$('a.toggle-vis').on('click', function (e) {
    e.preventDefault();
    let column = datatables_monthly_dispatches.column($(this).attr('data-column'));
    column.visible(!column.visible());
});
let datatables_monthly_dispatches = $('#datatables_monthly_dispatches').DataTable({
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'btn btn-sm btn-success',
            text: 'Excel'
        },
        {
            extend: 'copy',
            className: 'btn btn-sm btn-primary',
            text: 'Portapapelese'
        }
    ],
    deferRender: true,
    columns: [
        {'data': 'Fecha'},
        {'data': 'Estacion'},
        {'data': 'Despacho'},
        {'data': 'Posicion'},
        {'data': 'Producto'},
        {'data': 'Cantidad'},
        {'data': 'Precio'},
        {'data': 'Importe'},
        {'data': 'Nota'},
        {'data': 'Factura'},
        {'data': 'UUID'},
        {'data': 'Cliente'},
        {'data': 'Codigo'},
        {'data': 'Vehiculo'},
        {'data': 'Placas'},
        {'data': 'tiptrn'},
        {'data': 'nrotur'}
    ],
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('#datatables_monthly_dispatches').waitMe('hide');
        this.api().columns().every(function () {
            let that = this;
            $('input', this.footer()).on('keyup change clear', function () {
                if (that.search() !== this.value) {
                    that
                        .search(this.value)
                        .draw();
                }
            });
        });
    }
});

$("#download_info").on("click", function() {
    // Realiza la petición AJAX para obtener los datos

    let from = $('#from').val();
    let until = $('#until').val();

    datatables_monthly_dispatches.clear().draw();


    $.ajax({
        method: 'POST',
        url: '/administration/datatables_monthly_dispatches/2/' + from + '/' + until,
        success: function(data) {
            // Actualiza los datos del DataTable con los nuevos datos
            datatables_monthly_dispatches.rows.add(data.data).draw();
        },
        error: function() {
            toastr.error('Error en la primera petición AJAX');
        },
        beforeSend: function() {
            toastr.success('Datos de GEMELA GRANDE descargandose');
            $('#datatables_monthly_dispatches').waitMe();
        },
        complete: function() {
            // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
            toastr.success('Datos de GEMELA GRANDE descargados con exito');
        }
    });

    $.ajax({
        method: 'POST',
        url: '/administration/datatables_monthly_dispatches/3/' + from + '/' + until,
        success: function(data) {
            // Actualiza los datos del DataTable con los nuevos datos
            datatables_monthly_dispatches.rows.add(data.data).draw();
        },
        error: function() {
            toastr.error('Error en la primera petición AJAX');
        },
        beforeSend: function() {
            toastr.success('Datos de INDEPENDENCIA descargandose');
            $('#datatables_monthly_dispatches').waitMe();
        },
        complete: function() {
            // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
            toastr.success('Datos de INDEPENDENCIA descargados con exito');
        }
    });

    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/5/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de LERDO descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de LERDO descargados con exito');
    //     }
    // });

    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/6/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de LOPEZ MATEOS descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de LOPEZ MATEOS descargados con exito');
    //     }
    // });

    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/7/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de GEMELA CHICA descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de GEMELA CHICA descargados con exito');
    //     }
    // });

    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/8/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de MPIO. LIBRE descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de MPIO. LIBRE descargados con exito');
    //     }
    // });

    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/9/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de AZTECAS descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de AZTECAS descargados con exito');
    //     }
    // });

    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/10/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de MISIONES descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de MISIONES descargados con exito');
    //     }
    // });

    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/11/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de PTO DE PALOS descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de PTO DE PALOS descargados con exito');
    //     }
    // });

    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/12/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de MIGUEL DE LA MADRID descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de MIGUEL DE LA MADRID descargados con exito');
    //     }
    // });

    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/13/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de PERMUTA descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de PERMUTA descargados con exito');
    //     }
    // });

    //  // 14 - 15 ELECTROLUX
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/14/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de ELECTROLUX descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de ELECTROLUX descargados con exito');
    //     }
    // });
    // // 15 - 16 AERONAUTICA
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/15/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de AERONAUTICA descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de AERONAUTICA descargados con exito');
    //     }
    // });
    // // 16 - 17 CUSTODIA
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/16/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de CUSTODIA descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de CUSTODIA descargados con exito');
    //     }
    // });
    // // 17 - 18 ANAPRA
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/17/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de ANAPRA descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de ANAPRA descargados con exito');
    //     }
    // });
    // // 18 - 04 PARRAL
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/18/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de PARRAL descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de PARRAL descargados con exito');
    //     }
    // });
    // // 19 - 03 DELICIAS
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/19/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de DELICIAS descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de DELICIAS descargados con exito');
    //     }
    // });
    // // 21 - 08 PLUTARCO
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/21/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de PLUTARCO descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de PLUTARCO descargados con exito');
    //     }
    // });
    // // 22 - 20 TECNOLOGICO
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/22/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de TECNOLOGICO descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de TECNOLOGICO descargados con exito');
    //     }
    // });
    // // 23 - 21 EJERCITO NAL
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/23/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de EJERCITO NACIONAL descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de EJERCITO NACIONAL descargados con exito');
    //     }
    // });
    // // 24 - 22 SATELITE
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/24/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de SATELITE descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de SATELITE descargados con exito');
    //     }
    // });
    // // 25 - 23 LAS FUENTES
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/25/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de LAS FUENTES descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de LAS FUENTES descargados con exito');
    //     }
    // });
    // // 26 - 24 CLARA
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/26/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de CLARA descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de CLARA descargados con exito');
    //     }
    // });
    // // 27 - 25 SOLIS
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/27/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de SOLIS descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de SOLIS descargados con exito');
    //     }
    // });
    // // 28 - 26 SANTIAGO TRO
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/28/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de SANTIAGO TRONCOSO descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de SANTIAGO TRONCOSO descargados con exito');
    //     }
    // });
    // // 29 - 27 JARUDO
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/29/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de JARUDO descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de JARUDO descargados con exito');
    //     }
    // });
    // // 30 - 28 HERMANOS ESC
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/30/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de HERMANOS ESCOBAR descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de HERMANOS ESCOBAR descargados con exito');
    //     }
    // });
    // // 31 - 29 VILLA AHUMAD
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/31/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de VILLA AHUMADA descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de VILLA AHUMADA descargados con exito');
    //     }
    // });
    // // 32 - 30  EL CASTAÑO
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/32/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de EL CASTAÑO descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de EL CASTAÑO descargados con exito');
    //     }
    // });
    // // 33 - 31 - KM30
    // $.ajax({
    //     method: 'POST',
    //     url: '/administration/datatables_monthly_dispatches/33/' + from + '/' + until,
    //     success: function(data) {
    //         // Actualiza los datos del DataTable con los nuevos datos
    //         datatables_monthly_dispatches.rows.add(data.data).draw();
    //     },
    //     error: function() {
    //         toastr.error('Error en la primera petición AJAX');
    //     },
    //     beforeSend: function() {
    //         toastr.success('Datos de KM30 descargandose');
    //         $('#datatables_monthly_dispatches').waitMe();
    //     },
    //     complete: function() {
    //         // Oculta el mensaje de espera cuando la petición se completa (ya sea éxito o error)
    //         toastr.success('Datos de KM30 descargados con exito');
    //     }
    // });
});

//////////////////////////////////////////////////////////////////////////////
// DATATABLES TICKETS
//////////////////////////////////////////////////////////////////////////////
let datatables_tickets = $('#datatables_tickets').DataTable({
    colReorder: true,
    order: [3, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Tickets',
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

                $('#datatables_tickets thead th').each(function () {
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
        url: '/administration/datatables_tickets',
        type: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),
        },
        error: function() {
            $('#datatables_tickets').waitMe('hide');
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
        {'data': 'ID'},
        {'data': 'TIPO'},
        {'data': 'FORMULARIO'},
        {'data': 'GRUPO'},
        {'data': 'TÍTULO'},
        {'data': 'DESCRIPCIÓN'},
        {'data': 'CREADO'},
        {'data': 'RESUELTO'},
        {'data': 'TIEMPO_RESPUESTA'},
        {'data': 'USUARIO'},
        {'data': 'AGENTE'},
        {'data': 'STATUS'},
        {'data': 'PRIORIDAD'},
        {'data': 'COLA'},
        {'data': 'ASIGNADO'},
        {'data': 'ACTUALIZADO'},
        {'data': 'CALIFICACIÓN'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'SOLICITANTE'},
        {'data': 'PROBLEMA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_tickets input').on('keyup  change clear', function () {
    datatables_tickets
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#FORMULARIO').val().trim())
        .column(3).search($('#GRUPO').val().trim())
        .column(4).search($('#TITULO').val().trim())
        .column(5).search($('#DESCRIPCION').val().trim())
        .column(6).search($('#CREADO').val().trim())
        .column(7).search($('#RESUELTO').val().trim())
        .column(8).search($('#TRESOLUCION').val().trim())
        .column(9).search($('#USUARIO').val().trim())
        .column(10).search($('#AGENTE').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#PRIORIDAD').val().trim())
        .column(13).search($('#COLA').val().trim())
        .column(14).search($('#ASIGNADO').val().trim())
        .column(15).search($('#ACTUALIZADO').val().trim())
        .column(16).search($('#CALIFICACION').val().trim())
        .column(17).search($('#DEPARTAMENTO').val().trim())
        .column(18).search($('#SOLICITANTE').val().trim())
        .column(19).search($('#PROBLEMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_tickets').on('click', function () {
    datatables_tickets.clear().draw();
    datatables_tickets.ajax.reload();
    $('#datatables_tickets').waitMe('hide');
});



function update_mojo() {
    // Ahora vamos a insertar una funcion ajax para actualizar el ticket en mojo
    $.ajax({
        method: 'POST',
        url: '/administration/update_mojo',
        success: function(response) {

            if (response.success) {
                toastr.success('Tickets actualizado con éxito');

            } else {
                toastr.error('Error al actualizar el ticket');
            }
        },
        error: function() {
            toastr.error('Error en la petición AJAX');
        },
        beforeSend: function() {
            toastr.success('Actualizando tickets...');
            $('#datatables_tickets').addClass('loading');
        },
        complete: function() {
            $('#datatables_tickets').removeClass('loading');
        }
    });
}

function delete_ticket(ticket_id) {
    $.ajax({
        url: 'https://app.mojohelpdesk.com/api/v2/tickets/'+ ticket_id +'?access_key=f68cddda794b0bf9582c23b7b3099011d95c60ce',
        type: 'DELETE',
        success: function(response) {
            // Realizar una segunda petición AJAX
            $.ajax({
                url: '/administration/delete_ticket/' + ticket_id,
                success: function(response) {
                    if (response.success) {
                        toastr.success(response.message, '¡Éxito!', {timeOut: 5000});
                        // Vamos a hacer un reload a la página
                        datatables_tickets.clear().draw();
                        datatables_tickets.ajax.reload();

                    } else {
                        toastr.error(response.message, '¡Error!', {timeOut: 5000});
                    }
                },
                error: function(xhr, status, error) {
                    toastr.error(response.message, '¡Error!', {timeOut: 5000});
                },
                complete: function() {
                    $('.table-responsive').removeClass('loading');
                }
            });
        },
        error: function(xhr, status, error) {
            alert('Hubo un error al eliminar el ticket');
        }
    });
}


let datatables_tickets_2 = $('#datatables_tickets_2').DataTable({
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

                $('#datatables_tickets_2 thead th').each(function () {
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
        url: '/administration/datatables_tickets_2',
        type: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),
            'date_range': $('#date_range').val(),
            'ticket_form': $('#ticket_form').val(),
        },
        error: function() {
            $('#datatables_tickets_2').waitMe('hide');
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
        {'data': 'ID'},
        {'data': 'TIPO'},
        {'data': 'FORMULARIO'},
        {'data': 'GRUPO'},
        {'data': 'TÍTULO'},
        {'data': 'DESCRIPCIÓN'},
        {'data': 'CREADO'},
        {'data': 'RESUELTO'},
        {'data': 'TIEMPO_RESPUESTA'},
        {'data': 'USUARIO'},
        {'data': 'AGENTE'},
        {'data': 'STATUS'},
        {'data': 'PRIORIDAD'},
        {'data': 'COLA'},
        {'data': 'ASIGNADO'},
        {'data': 'ACTUALIZADO'},
        {'data': 'CALIFICACIÓN'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'SOLICITANTE'},
        {'data': 'PROBLEMA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_tickets_2 input').on('keyup  change clear', function () {
    datatables_tickets_2
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#FORMULARIO').val().trim())
        .column(3).search($('#GRUPO').val().trim())
        .column(4).search($('#TITULO').val().trim())
        .column(5).search($('#DESCRIPCION').val().trim())
        .column(6).search($('#CREADO').val().trim())
        .column(7).search($('#RESUELTO').val().trim())
        .column(8).search($('#TRESOLUCION').val().trim())
        .column(9).search($('#USUARIO').val().trim())
        .column(10).search($('#AGENTE').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#PRIORIDAD').val().trim())
        .column(13).search($('#COLA').val().trim())
        .column(14).search($('#ASIGNADO').val().trim())
        .column(15).search($('#ACTUALIZADO').val().trim())
        .column(16).search($('#CALIFICACION').val().trim())
        .column(17).search($('#DEPARTAMENTO').val().trim())
        .column(18).search($('#SOLICITANTE').val().trim())
        .column(19).search($('#PROBLEMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_tickets_2').on('click', function () {
    datatables_tickets_2.clear().draw();
    datatables_tickets_2.ajax.reload();
    $('#datatables_tickets_2').waitMe('hide');
});

//////////////////////////////////////////////////////////////////////////////

let datatables_urgentes_tickets = $('#datatables_urgentes_tickets').DataTable({
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

                $('#datatables_urgentes_tickets thead th').each(function () {
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
        url: '/administration/datatables_urgentes_tickets',
        type: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),
            'ticket_form': $('#ticket_form').val(),
        },
        error: function() {
            $('#datatables_urgentes_tickets').waitMe('hide');
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
        {'data': 'ID'},
        {'data': 'TIPO'},
        {'data': 'FORMULARIO'},
        {'data': 'GRUPO'},
        {'data': 'TÍTULO'},
        {'data': 'DESCRIPCIÓN'},
        {'data': 'CREADO'},
        {'data': 'RESUELTO'},
        {'data': 'TIEMPO_RESPUESTA'},
        {'data': 'USUARIO'},
        {'data': 'AGENTE'},
        {'data': 'STATUS'},
        {'data': 'PRIORIDAD'},
        {'data': 'COLA'},
        {'data': 'ASIGNADO'},
        {'data': 'ACTUALIZADO'},
        {'data': 'CALIFICACIÓN'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'SOLICITANTE'},
        {'data': 'PROBLEMA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_urgentes_tickets input').on('keyup  change clear', function () {
    datatables_urgentes_tickets
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#FORMULARIO').val().trim())
        .column(3).search($('#GRUPO').val().trim())
        .column(4).search($('#TITULO').val().trim())
        .column(5).search($('#DESCRIPCION').val().trim())
        .column(6).search($('#CREADO').val().trim())
        .column(7).search($('#RESUELTO').val().trim())
        .column(8).search($('#TRESOLUCION').val().trim())
        .column(9).search($('#USUARIO').val().trim())
        .column(10).search($('#AGENTE').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#PRIORIDAD').val().trim())
        .column(13).search($('#COLA').val().trim())
        .column(14).search($('#ASIGNADO').val().trim())
        .column(15).search($('#ACTUALIZADO').val().trim())
        .column(16).search($('#CALIFICACION').val().trim())
        .column(17).search($('#DEPARTAMENTO').val().trim())
        .column(18).search($('#SOLICITANTE').val().trim())
        .column(19).search($('#PROBLEMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_urgentes_tickets').on('click', function () {
    datatables_urgentes_tickets.clear().draw();
    datatables_urgentes_tickets.ajax.reload();
    $('#datatables_urgentes_tickets').waitMe('hide');
});



let datatables_normales_tickets = $('#datatables_normales_tickets').DataTable({
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

                $('#datatables_normales_tickets thead th').each(function () {
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
        url: '/administration/datatables_normales_tickets',
        type: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),
            'date_range': $('#date_range').val(),
            'ticket_form': $('#ticket_form').val(),
        },
        error: function() {
            $('#datatables_normales_tickets').waitMe('hide');
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
        {'data': 'ID'},
        {'data': 'TIPO'},
        {'data': 'FORMULARIO'},
        {'data': 'GRUPO'},
        {'data': 'TÍTULO'},
        {'data': 'DESCRIPCIÓN'},
        {'data': 'CREADO'},
        {'data': 'RESUELTO'},
        {'data': 'TIEMPO_RESPUESTA'},
        {'data': 'USUARIO'},
        {'data': 'AGENTE'},
        {'data': 'STATUS'},
        {'data': 'PRIORIDAD'},
        {'data': 'COLA'},
        {'data': 'ASIGNADO'},
        {'data': 'ACTUALIZADO'},
        {'data': 'CALIFICACIÓN'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'SOLICITANTE'},
        {'data': 'PROBLEMA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_normales_tickets input').on('keyup  change clear', function () {
    datatables_normales_tickets
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#FORMULARIO').val().trim())
        .column(3).search($('#GRUPO').val().trim())
        .column(4).search($('#TITULO').val().trim())
        .column(5).search($('#DESCRIPCION').val().trim())
        .column(6).search($('#CREADO').val().trim())
        .column(7).search($('#RESUELTO').val().trim())
        .column(8).search($('#TRESOLUCION').val().trim())
        .column(9).search($('#USUARIO').val().trim())
        .column(10).search($('#AGENTE').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#PRIORIDAD').val().trim())
        .column(13).search($('#COLA').val().trim())
        .column(14).search($('#ASIGNADO').val().trim())
        .column(15).search($('#ACTUALIZADO').val().trim())
        .column(16).search($('#CALIFICACION').val().trim())
        .column(17).search($('#DEPARTAMENTO').val().trim())
        .column(18).search($('#SOLICITANTE').val().trim())
        .column(19).search($('#PROBLEMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_normales_tickets').on('click', function () {
    datatables_normales_tickets.clear().draw();
    datatables_normales_tickets.ajax.reload();
    $('#datatables_normales_tickets').waitMe('hide');
});

let datatable_exchange_rate = $('#datatable_exchange_rate').DataTable({
    colReorder: true,
    order: [1, "asc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'buttons-excel',
            // Título del archivo de exportación
            title: 'Tipo de cambio actual',
            // Prevenimos la exportación de la columna de checkbox y de las acciones
            exportOptions: {
                columns: [1, 2, 3, 4, 5, 6]
            }
        }
    ],
    ajax: {
        url: '/administration/datatable_exchange_rate',
        type: 'POST',
        error: function() {
            $('#datatable_exchange_rate').waitMe('hide');
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
        {'data': 'DESCRIPCION'},
        {'data': 'NO_ESTACION'},
        {'data': 'ESTACION'},
        {'data': 'FECHA'},
        {'data': 'HORA'},
        {'data': 'CAMBIO', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
        // Ahora vamos a pintar las filas dependiendo del valor de la columna DESCRIPTION
        if (data.NO_ESTACION === '1149' || data.NO_ESTACION === '4179' || data.NO_ESTACION === '4188' || data.NO_ESTACION === '7167' || data.NO_ESTACION === '9191' || data.NO_ESTACION === '1163' || data.NO_ESTACION === '2526' || data.NO_ESTACION === '9235' || data.NO_ESTACION === '9885' || data.NO_ESTACION === '1156') {
            $(row).css('background-color', '#ECD0DC');
        }
        // Ahora vamos a pintar las filas dependiendo del valor de la columna DESCRIPTION
        if (data.NO_ESTACION === '23214' || data.NO_ESTACION === '6947' || data.NO_ESTACION === '6410' || data.NO_ESTACION === '5317' || data.NO_ESTACION === '8244' || data.NO_ESTACION === '9893' || data.NO_ESTACION === '24938' || data.NO_ESTACION === '1148' || data.NO_ESTACION === '1159' || data.NO_ESTACION === '4457' || data.NO_ESTACION === '9733' || data.NO_ESTACION === '12097' || data.NO_ESTACION === '10141') {
            $(row).css('background-color', '#CDE2F5');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

function update_exchange(station_name, fecha_y_hora, exchange, unique_id) {
    alertify.prompt( 'Actualizar', '<p><label class="col-form-label col-form-label-sm">Estación: </label><input class="form-control form-control-sm" type="text" readonly value="' + station_name + '"></p> <p><label class="col-form-label col-form-label-sm">Fecha y hora: </label><input class="form-control form-control-sm" type="text" readonly value="'+ fecha_y_hora+'"></p><p class="mb-3"><label class="col-form-label col-form-label-sm">Tipo de cambio actual: </label><input class="form-control form-control-sm" type="number" readonly value="'+ exchange +'"></p><hr> <p>Por favor, agregue el tipo de cambio:</p>', ''
        , function(evt, value) {
            // Vamos averificar que el valor no venga vacio, ni nullo ni este en ceros y mayor a cero
            if (value === '' || value === 0 || value === '0' || value === null) {
                toastr.error('Debe ingresar un valor válido', '¡Error!', { timeOut: 3000 })
                return false;
            }

            // Ahora vamos a enviar el unique_id y el valor a la base de datos por medio de ajax
            $.ajax({
                url: '/administration/update_exchange',
                type: 'POST',
                data: {
                    'unique_id': unique_id,
                    'exchange': value
                },
                success: function(response) {
                    alertify.myAlert(
                        `<div class="container text-center text-success">
                            <h4 class="mt-2 text-success">¡Éxito!</h4>
                        </div>
                        <div class="text-dark">
                            <p class="text-center">El registro fue actualizado correctamente.</p>
                        </div>`
                    );

                    // Vamos a recargar la pagina completa despues de 2 segundos
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                },
                error: function(xhr, status, error) {
                    toastr.error('Error al actualizar el tipo de cambio', '¡Error!', { timeOut: 3000 });
                },
                beforeSend: function() {
                    $('#datatable_exchange_rate').addClass('loading');
                    toastr.success('Actualizando tipo de cambio...');
                },
                complete: function() {
                    $('#datatable_exchange_rate').removeClass('loading');


                }
            });
            toastr.success('Valor ingresado: ' + value, '¡Éxito!', { timeOut: 3000 })
        }
        , function() {
            toastr.error('Operacion cancelada', '¡Error!', { timeOut: 3000 })
        }
    );
}

function delete_exchange(unique_id) {
    // Primero validamos que el unique_id no venga vacio
    if (unique_id === '' || unique_id === null) {
        toastr.error('El unique_id no puede estar vacio', '¡Error!', { timeOut: 3000 });
        return false;
    }

    // Ahora vamos a enviar el unique_id a la base de datos por medio de ajax
    $.ajax({
        url: '/administration/delete_exchange',
        type: 'POST',
        data: {
            'unique_id': unique_id
        },
        success: function(response) {
            alertify.myAlert(
                `<div class="container text-center text-success">
                    <h4 class="mt-2 text-success">¡Éxito!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">El registro fue eliminado correctamente.</p>
                </div>`
            );

            // Vamos a recargar la pagina completa despues de 2 segundos
            setTimeout(function() {
                location.reload();
            }, 2000);
        },
        error: function(xhr, status, error) {
            toastr.error('Error al eliminar el tipo de cambio', '¡Error!', { timeOut: 3000 });
        },
        beforeSend: function() {
            $('#datatable_exchange_rate').addClass('loading');
            toastr.success('Eliminando tipo de cambio...');
        },
        complete: function() {
            $('#datatable_exchange_rate').removeClass('loading');
        }
    });
}



let datatables_abiertos_tickets = $('#datatables_abiertos_tickets').DataTable({
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

                $('#datatables_abiertos_tickets thead th').each(function () {
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
        url: '/administration/datatables_abiertos_tickets',
        type: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),
            'date_range': $('#date_range').val(),
            'ticket_form': $('#ticket_form').val(),
        },
        error: function() {
            $('#datatables_abiertos_tickets').waitMe('hide');
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
        {'data': 'ID'},
        {'data': 'TIPO'},
        {'data': 'FORMULARIO'},
        {'data': 'GRUPO'},
        {'data': 'TÍTULO'},
        {'data': 'DESCRIPCIÓN'},
        {'data': 'CREADO'},
        {'data': 'RESUELTO'},
        {'data': 'TIEMPO_RESPUESTA'},
        {'data': 'USUARIO'},
        {'data': 'AGENTE'},
        {'data': 'STATUS'},
        {'data': 'PRIORIDAD'},
        {'data': 'COLA'},
        {'data': 'ASIGNADO'},
        {'data': 'ACTUALIZADO'},
        {'data': 'CALIFICACIÓN'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'SOLICITANTE'},
        {'data': 'PROBLEMA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_abiertos_tickets input').on('keyup  change clear', function () {
    datatables_abiertos_tickets
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#FORMULARIO').val().trim())
        .column(3).search($('#GRUPO').val().trim())
        .column(4).search($('#TITULO').val().trim())
        .column(5).search($('#DESCRIPCION').val().trim())
        .column(6).search($('#CREADO').val().trim())
        .column(7).search($('#RESUELTO').val().trim())
        .column(8).search($('#TRESOLUCION').val().trim())
        .column(9).search($('#USUARIO').val().trim())
        .column(10).search($('#AGENTE').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#PRIORIDAD').val().trim())
        .column(13).search($('#COLA').val().trim())
        .column(14).search($('#ASIGNADO').val().trim())
        .column(15).search($('#ACTUALIZADO').val().trim())
        .column(16).search($('#CALIFICACION').val().trim())
        .column(17).search($('#DEPARTAMENTO').val().trim())
        .column(18).search($('#SOLICITANTE').val().trim())
        .column(19).search($('#PROBLEMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_abiertos_tickets').on('click', function () {
    datatables_abiertos_tickets.clear().draw();
    datatables_abiertos_tickets.ajax.reload();
    $('#datatables_abiertos_tickets').waitMe('hide');
});



let datatables_resueltos_tickets = $('#datatables_resueltos_tickets').DataTable({
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

                $('#datatables_resueltos_tickets thead th').each(function () {
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
        url: '/administration/datatables_resueltos_tickets',
        type: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),
            'date_range': $('#date_range').val(),
            'ticket_form': $('#ticket_form').val(),
        },
        error: function() {
            $('#datatables_resueltos_tickets').waitMe('hide');
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
        {'data': 'ID'},
        {'data': 'TIPO'},
        {'data': 'FORMULARIO'},
        {'data': 'GRUPO'},
        {'data': 'TÍTULO'},
        {'data': 'DESCRIPCIÓN'},
        {'data': 'CREADO'},
        {'data': 'RESUELTO'},
        {'data': 'TIEMPO_RESPUESTA'},
        {'data': 'USUARIO'},
        {'data': 'AGENTE'},
        {'data': 'STATUS'},
        {'data': 'PRIORIDAD'},
        {'data': 'COLA'},
        {'data': 'ASIGNADO'},
        {'data': 'ACTUALIZADO'},
        {'data': 'CALIFICACIÓN'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'SOLICITANTE'},
        {'data': 'PROBLEMA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_resueltos_tickets input').on('keyup  change clear', function () {
    datatables_resueltos_tickets
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#FORMULARIO').val().trim())
        .column(3).search($('#GRUPO').val().trim())
        .column(4).search($('#TITULO').val().trim())
        .column(5).search($('#DESCRIPCION').val().trim())
        .column(6).search($('#CREADO').val().trim())
        .column(7).search($('#RESUELTO').val().trim())
        .column(8).search($('#TRESOLUCION').val().trim())
        .column(9).search($('#USUARIO').val().trim())
        .column(10).search($('#AGENTE').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#PRIORIDAD').val().trim())
        .column(13).search($('#COLA').val().trim())
        .column(14).search($('#ASIGNADO').val().trim())
        .column(15).search($('#ACTUALIZADO').val().trim())
        .column(16).search($('#CALIFICACION').val().trim())
        .column(17).search($('#DEPARTAMENTO').val().trim())
        .column(18).search($('#SOLICITANTE').val().trim())
        .column(19).search($('#PROBLEMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_resueltos_tickets').on('click', function () {
    datatables_resueltos_tickets.clear().draw();
    datatables_resueltos_tickets.ajax.reload();
    $('#datatables_resueltos_tickets').waitMe('hide');
});



let datatables_usuarios_tickets = $('#datatables_usuarios_tickets').DataTable({
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

                $('#datatables_usuarios_tickets thead th').each(function () {
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
        url: '/administration/datatables_usuarios_tickets/',
        type: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),
            'date_range': $('#date_range').val(),
            'ticket_form': $('#ticket_form').val(),
            'user_id': $('#user_id').val(),
        },
        error: function() {
            $('#datatables_usuarios_tickets').waitMe('hide');
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
        {'data': 'ID'},
        {'data': 'TIPO'},
        {'data': 'FORMULARIO'},
        {'data': 'GRUPO'},
        {'data': 'TÍTULO'},
        {'data': 'DESCRIPCIÓN'},
        {'data': 'CREADO'},
        {'data': 'RESUELTO'},
        {'data': 'TIEMPO_RESPUESTA'},
        {'data': 'USUARIO'},
        {'data': 'AGENTE'},
        {'data': 'STATUS'},
        {'data': 'PRIORIDAD'},
        {'data': 'COLA'},
        {'data': 'ASIGNADO'},
        {'data': 'ACTUALIZADO'},
        {'data': 'CALIFICACIÓN'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'SOLICITANTE'},
        {'data': 'PROBLEMA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_usuarios_tickets input').on('keyup  change clear', function () {
    datatables_usuarios_tickets
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#FORMULARIO').val().trim())
        .column(3).search($('#GRUPO').val().trim())
        .column(4).search($('#TITULO').val().trim())
        .column(5).search($('#DESCRIPCION').val().trim())
        .column(6).search($('#CREADO').val().trim())
        .column(7).search($('#RESUELTO').val().trim())
        .column(8).search($('#TRESOLUCION').val().trim())
        .column(9).search($('#USUARIO').val().trim())
        .column(10).search($('#AGENTE').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#PRIORIDAD').val().trim())
        .column(13).search($('#COLA').val().trim())
        .column(14).search($('#ASIGNADO').val().trim())
        .column(15).search($('#ACTUALIZADO').val().trim())
        .column(16).search($('#CALIFICACION').val().trim())
        .column(17).search($('#DEPARTAMENTO').val().trim())
        .column(18).search($('#SOLICITANTE').val().trim())
        .column(19).search($('#PROBLEMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_usuarios_tickets').on('click', function () {
    datatables_usuarios_tickets.clear().draw();
    datatables_usuarios_tickets.ajax.reload();
    $('#datatables_usuarios_tickets').waitMe('hide');
});

let datatables_grupos_tickets = $('#datatables_grupos_tickets').DataTable({
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

                $('#datatables_grupos_tickets thead th').each(function () {
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
        url: '/administration/datatables_grupos_tickets/',
        type: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),
            'date_range': $('#date_range').val(),
            'ticket_form': $('#ticket_form').val(),
            'user_id': $('#user_id').val(),
        },
        error: function() {
            $('#datatables_grupos_tickets').waitMe('hide');
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
        {'data': 'ID'},
        {'data': 'TIPO'},
        {'data': 'FORMULARIO'},
        {'data': 'GRUPO'},
        {'data': 'TÍTULO'},
        {'data': 'DESCRIPCIÓN'},
        {'data': 'CREADO'},
        {'data': 'RESUELTO'},
        {'data': 'TIEMPO_RESPUESTA'},
        {'data': 'USUARIO'},
        {'data': 'AGENTE'},
        {'data': 'STATUS'},
        {'data': 'PRIORIDAD'},
        {'data': 'COLA'},
        {'data': 'ASIGNADO'},
        {'data': 'ACTUALIZADO'},
        {'data': 'CALIFICACIÓN'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'SOLICITANTE'},
        {'data': 'PROBLEMA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_grupos_tickets input').on('keyup  change clear', function () {
    datatables_grupos_tickets
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#FORMULARIO').val().trim())
        .column(3).search($('#GRUPO').val().trim())
        .column(4).search($('#TITULO').val().trim())
        .column(5).search($('#DESCRIPCION').val().trim())
        .column(6).search($('#CREADO').val().trim())
        .column(7).search($('#RESUELTO').val().trim())
        .column(8).search($('#TRESOLUCION').val().trim())
        .column(9).search($('#USUARIO').val().trim())
        .column(10).search($('#AGENTE').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#PRIORIDAD').val().trim())
        .column(13).search($('#COLA').val().trim())
        .column(14).search($('#ASIGNADO').val().trim())
        .column(15).search($('#ACTUALIZADO').val().trim())
        .column(16).search($('#CALIFICACION').val().trim())
        .column(17).search($('#DEPARTAMENTO').val().trim())
        .column(18).search($('#SOLICITANTE').val().trim())
        .column(19).search($('#PROBLEMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_grupos_tickets').on('click', function () {
    datatables_grupos_tickets.clear().draw();
    datatables_grupos_tickets.ajax.reload();
    $('#datatables_grupos_tickets').waitMe('hide');
});

function updateMojoTicket(id) {
    let accessKey = 'f68cddda794b0bf9582c23b7b3099011d95c60ce';
    var url = `https://totalgas.mojohelpdesk.com/api/v2/tickets/${id}?access_key=${accessKey}`;

    $.ajax({
        url: url,
        type: 'PUT',
        contentType: 'application/json',
        data: JSON.stringify({ 'assigned_to_id': 4351669 }),
        success: function(response) {
            toastr.success('El ticket fue asignado correctamente', '¡Éxito!', { timeOut: 3000 });
            // Ahora vamos
            $.get( "/administration/update_ticket_db/" + id );
        },
        error: function(xhr, status, error) {
            toastr.error('Error al asignar el ticket', '¡Error!', { timeOut: 3000 });
        }
    });
}



let datatables_departments_tickets = $('#datatables_departments_tickets').DataTable({
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

                $('#datatables_departments_tickets thead th').each(function () {
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
        url: '/administration/datatables_departments_tickets/',
        type: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),
            'date_range': $('#date_range').val(),
            'ticket_form': $('#ticket_form').val(),
            'user_id': $('#user_id').val(),
        },
        error: function() {
            $('#datatables_departments_tickets').waitMe('hide');
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
        {'data': 'ID'},
        {'data': 'TIPO'},
        {'data': 'FORMULARIO'},
        {'data': 'GRUPO'},
        {'data': 'TÍTULO'},
        {'data': 'DESCRIPCIÓN'},
        {'data': 'CREADO'},
        {'data': 'RESUELTO'},
        {'data': 'TIEMPO_RESPUESTA'},
        {'data': 'USUARIO'},
        {'data': 'AGENTE'},
        {'data': 'STATUS'},
        {'data': 'PRIORIDAD'},
        {'data': 'COLA'},
        {'data': 'ASIGNADO'},
        {'data': 'ACTUALIZADO'},
        {'data': 'CALIFICACIÓN'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'SOLICITANTE'},
        {'data': 'PROBLEMA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_departments_tickets input').on('keyup  change clear', function () {
    datatables_departments_tickets
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#FORMULARIO').val().trim())
        .column(3).search($('#GRUPO').val().trim())
        .column(4).search($('#TITULO').val().trim())
        .column(5).search($('#DESCRIPCION').val().trim())
        .column(6).search($('#CREADO').val().trim())
        .column(7).search($('#RESUELTO').val().trim())
        .column(8).search($('#TRESOLUCION').val().trim())
        .column(9).search($('#USUARIO').val().trim())
        .column(10).search($('#AGENTE').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#PRIORIDAD').val().trim())
        .column(13).search($('#COLA').val().trim())
        .column(14).search($('#ASIGNADO').val().trim())
        .column(15).search($('#ACTUALIZADO').val().trim())
        .column(16).search($('#CALIFICACION').val().trim())
        .column(17).search($('#DEPARTAMENTO').val().trim())
        .column(18).search($('#SOLICITANTE').val().trim())
        .column(19).search($('#PROBLEMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_departments_tickets').on('click', function () {
    datatables_departments_tickets.clear().draw();
    datatables_departments_tickets.ajax.reload();
    $('#datatables_departments_tickets').waitMe('hide');
});

let datatables_supports_tickets = $('#datatables_supports_tickets').DataTable({
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
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15]
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

                $('#datatables_supports_tickets thead th').each(function () {
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
        url: '/administration/datatables_supports_tickets/',
        type: 'POST',
        data: {
            'from': $('input#from').val(),
            'until': $('input#until').val(),
            'date_range': $('#date_range').val(),
            'ticket_form': $('#ticket_form').val(),
            'user_id': $('#user_id').val(),
        },
        error: function() {
            $('#datatables_supports_tickets').waitMe('hide');
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
        {'data': 'ID'},
        {'data': 'TIPO'},
        {'data': 'FORMULARIO'},
        {'data': 'GRUPO'},
        {'data': 'TÍTULO'},
        {'data': 'DESCRIPCIÓN'},
        {'data': 'CREADO'},
        {'data': 'RESUELTO'},
        {'data': 'TIEMPO_RESPUESTA'},
        {'data': 'USUARIO'},
        {'data': 'AGENTE'},
        {'data': 'STATUS'},
        {'data': 'PRIORIDAD'},
        {'data': 'COLA'},
        {'data': 'ASIGNADO'},
        {'data': 'ACTUALIZADO'},
        {'data': 'CALIFICACIÓN'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'SOLICITANTE'},
        {'data': 'PROBLEMA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_supports_tickets input').on('keyup  change clear', function () {
    datatables_supports_tickets
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#FORMULARIO').val().trim())
        .column(3).search($('#GRUPO').val().trim())
        .column(4).search($('#TITULO').val().trim())
        .column(5).search($('#DESCRIPCION').val().trim())
        .column(6).search($('#CREADO').val().trim())
        .column(7).search($('#RESUELTO').val().trim())
        .column(8).search($('#TRESOLUCION').val().trim())
        .column(9).search($('#USUARIO').val().trim())
        .column(10).search($('#AGENTE').val().trim())
        .column(11).search($('#STATUS').val().trim())
        .column(12).search($('#PRIORIDAD').val().trim())
        .column(13).search($('#COLA').val().trim())
        .column(14).search($('#ASIGNADO').val().trim())
        .column(15).search($('#ACTUALIZADO').val().trim())
        .column(16).search($('#CALIFICACION').val().trim())
        .column(17).search($('#DEPARTAMENTO').val().trim())
        .column(18).search($('#SOLICITANTE').val().trim())
        .column(19).search($('#PROBLEMA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_supports_tickets').on('click', function () {
    datatables_supports_tickets.clear().draw();
    datatables_supports_tickets.ajax.reload();
    $('#datatables_supports_tickets').waitMe('hide');
});

let datatables_anticipos = $('#datatables_anticipos').DataTable({
    colReorder: true,
    order: [3, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Anticipos',
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

                $('#datatables_anticipos thead th').each(function () {
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
        url: '/administration/datatables_anticipos/' + $('input#from').val() + '/' + $('input#until').val(),
        type: 'POST',
        error: function() {
            $('#datatables_anticipos').waitMe('hide');
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
        {'data': 'FACTURA'},
        {'data': 'TIPO'},
        {'data': 'PRODUCTO'},
        {'data': 'CUENTA'},
        {'data': 'MONTO', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'IVA', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'TOTAL', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'CFDI'},
        {'data': 'PAGO'},
        {'data': 'CLIENTE'},
        {'data': 'ESTACION'},
        {'data': 'REGISTRO'},
        {'data': 'FECHA'}
    ],
    rowId: 'FACTURA',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_anticipos input').on('keyup  change clear', function () {
    datatables_anticipos
        .column(0).search($('#FACTURA').val().trim())
        .column(1).search($('#TIPO').val().trim())
        .column(2).search($('#PRODUCTO').val().trim())
        .column(3).search($('#CUENTA').val().trim())
        .column(4).search($('#MONTO').val().trim())
        .column(5).search($('#IVA').val().trim())
        .column(6).search($('#TOTAL').val().trim())
        .column(7).search($('#CFDI').val().trim())
        .column(8).search($('#PAGO').val().trim())
        .column(9).search($('#CLIENTE').val().trim())
        .column(10).search($('#ESTACION').val().trim())
        .column(11).search($('#REGISTRO').val().trim())
        .column(12).search($('#FECHA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_anticipos').on('click', function () {
    datatables_anticipos.clear().draw();
    datatables_anticipos.ajax.reload();
    $('#datatables_anticipos').waitMe('hide');
});


let datatables_customer_anticipos = $('#datatables_customer_anticipos').DataTable({
    colReorder: true,
    order: [3, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Anticipos',
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

                $('#datatables_customer_anticipos thead th').each(function () {
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
        url: '/administration/datatables_customer_anticipos/' + $('input#from').val() + '/' + $('input#until').val(),
        type: 'POST',
        error: function() {
            $('#datatables_customer_anticipos').waitMe('hide');
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
        {'data': 'CODCLIENTE'},
        {'data': 'CLIENTE'},
        {'data': 'STATUS'},
        {'data': 'RFC'},
        {'data': 'TIPO'},
        {'data': 'PRODUCTO'},
        {'data': 'TOTAL', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'CONSUMOS', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'SALDOCALCULADO', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'SALDOINGRESADO', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'DIFERENCIA', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'ULTIMODEPOSITO'},
        {'data': 'ULTIMOCONSUMO'},
        {'data': 'ACCIONES'}
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_customer_anticipos input').on('keyup  change clear', function () {
    datatables_customer_anticipos
        .column(0).search($('#CODCLIENTE').val().trim())
        .column(1).search($('#CLIENTE1').val().trim())
        .column(2).search($('#STATUS').val().trim())
        .column(3).search($('#RFC').val().trim())
        .column(4).search($('#TIPO1').val().trim())
        .column(5).search($('#PRODUCTO1').val().trim())
        .column(6).search($('#TOTAL1').val().trim())
        .column(7).search($('#CONSUMOS').val().trim())
        .column(8).search($('#SALDOCALCULADO').val().trim())
        .column(9).search($('#SALDOINGRESADO').val().trim())
        .column(10).search($('#DIFERENCIA').val().trim())
        .column(11).search($('#ULTIMODEPOSITO').val().trim())
        .column(12).search($('#ULTIMOCONSUMO').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_customer_anticipos').on('click', function () {
    datatables_customer_anticipos.clear().draw();
    datatables_customer_anticipos.ajax.reload();
    $('#datatables_customer_anticipos').waitMe('hide');
});

let datatables_customer_advances = $('#datatables_customer_advances').DataTable({
    colReorder: true,
    order: [3, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Anticipos',
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

                $('#datatables_customer_advances thead th').each(function () {
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
        url: '/administration/datatables_customer_advances/' + $('input#from').val() + '/' + $('input#until').val(),
        type: 'POST',
        error: function() {
            $('#datatables_customer_advances').waitMe('hide');
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
        {'data': 'CODCLIENTE'},
        {'data': 'CLIENTE'},
        {'data': 'RFC'},
        {'data': 'TIPO'},
        {'data': 'PRODUCTO'},
        {'data': 'MONTO', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'IVA', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'TOTAL', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'CONSUMOS', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'DIFERENCIA', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'ACCIONES'}
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_customer_advances input').on('keyup  change clear', function () {
    datatables_customer_advances
        .column(0).search($('#CODCLIENTE').val().trim())
        .column(1).search($('#CLIENTE').val().trim())
        .column(2).search($('#RFC').val().trim())
        .column(3).search($('#TIPO').val().trim())
        .column(4).search($('#PRODUCTO').val().trim())
        .column(5).search($('#MONTO').val().trim())
        .column(6).search($('#IVA').val().trim())
        .column(7).search($('#TOTAL').val().trim())
        .column(8).search($('#CONSUMOS').val().trim())
        .column(9).search($('#DIFERENCIA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_customer_advances').on('click', function () {
    datatables_customer_advances.clear().draw();
    datatables_customer_advances.ajax.reload();
    $('#datatables_customer_advances').waitMe('hide');
});




let datatables_customer_anticipos2 = $('#datatables_customer_anticipos2').DataTable({
    colReorder: true,
    order: [3, "desc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Anticipos',
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

                $('#datatables_customer_anticipos2 thead th').each(function () {
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
        url: '/administration/datatables_customer_anticipos2/' + $('input#percent').val() + '/' + $('input#from').val() + '/' + $('input#until').val(),
        type: 'POST',
        error: function() {
            $('#datatables_customer_anticipos2').waitMe('hide');
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
        {'data': 'CODCLIENTE'},
        {'data': 'CLIENTE'},
        {'data': 'RFC'},
        {'data': 'TIPO'},
        {'data': 'PRODUCTO'},
        {'data': 'MONTO', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'IVA', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'TOTAL', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'CONSUMOS', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'DIFERENCIA', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'ACCIONES'}
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_customer_anticipos2 input').on('keyup  change clear', function () {
    datatables_customer_anticipos2
        .column(0).search($('#CODCLIENTE').val().trim())
        .column(1).search($('#CLIENTE').val().trim())
        .column(2).search($('#RFC').val().trim())
        .column(3).search($('#TIPO').val().trim())
        .column(4).search($('#PRODUCTO').val().trim())
        .column(5).search($('#MONTO').val().trim())
        .column(6).search($('#IVA').val().trim())
        .column(7).search($('#TOTAL').val().trim())
        .column(8).search($('#CONSUMOS').val().trim())
        .column(9).search($('#DIFERENCIA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_customer_anticipos2').on('click', function () {
    datatables_customer_anticipos2.clear().draw();
    datatables_customer_anticipos2.ajax.reload();
    $('#datatables_customer_anticipos2').waitMe('hide');
});




let datatables_customer_advances_details = $('#datatables_customer_advances_details').DataTable({
    colReorder: true,
    order: [0, "asc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            // Título del archivo de exportación
            title: 'Anticipos',
        }
    ],
    ajax: {
        url: '/administration/datatables_customer_advances_details/' + $('input#codcli').val() + '/' + $('input#from').val() + '/' + $('input#until').val(),
        type: 'POST',
        error: function() {
            $('#datatables_customer_advances_details').waitMe('hide');
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
        {'data': 'CODCLIENTE'},
        {'data': 'CLIENTE'},
        {'data': 'RFC'},
        {'data': 'TIPO'},
        {'data': 'FACDESP'},
        {'data': 'PRODUCTO'},
        {'data': 'MONTOANTICIPO', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'MONTOCONSUMO', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'SALDO', render: $.fn.dataTable.render.number(',', '.', 2, '$')},
        {'data': 'ESTACION'},
        {'data': 'FECHA'},
    ],
    rowId: 'Id',
    createdRow: function (row, data, dataIndex) {
        if (data.TIPO === 'Anticipo') {
            $(row).addClass('table-success');
            // Vamos a pintar la celda correspondiente a la columna de tipo
            $('td', row).eq(3).addClass('text-white bg-success');
        } else {
            // Vamos a pintar la celda correspondiente a la columna de tipo
            $('td', row).eq(3).addClass('bg-warning');
        }

        // Ahora vamos a pintar la celda producto
        if (data.PRODUCTO === 'T-Super Premium') {
            // Vamos a pintar la celda correspondiente a la columna de tipo
            $('td', row).eq(5).addClass('text-success fw-bold');
        }
        if (data.PRODUCTO === 'T-Maxima Regular') {
            // Vamos a pintar la celda correspondiente a la columna de tipo
            $('td', row).eq(5).addClass('text-primary fw-bold');
        }

        // Si el saldo es negativo lo pintamos de rojo
        if (parseFloat(data.SALDO) < 0) {
            $('td', row).eq(8).addClass('text-danger fw-bold');
        } else {
            $('td', row).eq(8).addClass('text-success fw-bold');
        }
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_customer_advances_details input').on('keyup  change clear', function () {
    datatables_customer_advances_details
        .column(0).search($('#CODCLIENTE').val().trim())
        .column(1).search($('#CLIENTE').val().trim())
        .column(2).search($('#RFC').val().trim())
        .column(3).search($('#TIPO').val().trim())
        .column(4).search($('#FACDESP').val().trim())
        .column(5).search($('#PRODUCTO').val().trim())
        .column(6).search($('#MONTOANTICIPO').val().trim())
        .column(7).search($('#MONTOCONSUMO').val().trim())
        .column(8).search($('#SALDO').val().trim())
        .column(8).search($('#ESTACION').val().trim())
        .column(10).search($('#FECHA').val().trim())
        .draw();
});

// Agregar un evento clic de refresh
$('.refresh_datatables_customer_advances_details').on('click', function () {
    datatables_customer_advances_details.clear().draw();
    datatables_customer_advances_details.ajax.reload();
    $('#datatables_customer_advances_details').waitMe('hide');
});

datatables_customer_advances_details.on('draw', function() {
    // Contamos solo los registros visibles después del filtrado
    let records = datatables_customer_advances_details.rows({ search: 'applied' }).count();

    // Sumar los montos de los anticipos visibles después del filtrado
    let anticipos = 0;
    datatables_customer_advances_details.rows({ search: 'applied' }).every(function() {
        let data = this.data();
        anticipos += parseFloat(data.MONTOANTICIPO);
    });

    // Sumar los montos de los consumos visibles después del filtrado
    let consumos = 0;
    datatables_customer_advances_details.rows({ search: 'applied' }).every(function() {
        let data = this.data();
        consumos += parseFloat(data.MONTOCONSUMO);
    });

    // Ahora vamos a poner una deferencia entre los anticipos y los consumos
    let diferencia = anticipos - consumos;

    // Formatear los montos como moneda
    anticipos = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(anticipos);
    consumos = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(consumos);
    diferencia_formateada = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(diferencia);

    // Actualizar los textos de los badges
    $('#badge-registers').text('Registros: ' + records);
    $('#badge-anticipos').text('Anticipos: ' + anticipos);
    $('#badge-consumos').text('Consumos: ' + consumos);
    $('#badge-diferencia').text('Diferencia: ' + diferencia_formateada);

    // Si la diferencia es negativa, vamos a pintar el badge de rojo
    if (diferencia < 0) {
        $('#badge-diferencia').removeClass('text-bg-success').addClass('text-bg-danger');
    } else {
        $('#badge-diferencia').removeClass('text-bg-danger').addClass('text-bg-success');
    }

});
