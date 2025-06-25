function dateToInt() {
    let myDate = $('#date_to_int').val();
    let referenceDate = new Date('1900-01-01');
    let inputDate = new Date(myDate);
    let daysDifference = Math.floor((inputDate - referenceDate) / (1000 * 60 * 60 * 24)) + 1;
    $('#resultInt').val(daysDifference);
}

function intToDate() {
    let daysDifference = parseInt($('#int_to_date').val());
    let referenceDate = new Date('1900-01-01');
    let inputDate = new Date(referenceDate.getTime() + (daysDifference * (1000 * 60 * 60 * 24)));

    let year = inputDate.getFullYear();
    let month = String(inputDate.getMonth() + 1).padStart(2, '0');
    let day = String(inputDate.getDate()).padStart(2, '0');

    $('#resultDate').val(year + '-' + month + '-' + day);
}


// Tabla de usuarios
let datatables_users = $('#datatables_users').DataTable({
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
                $('#datatables_users thead th').each(function () {
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
        url: '/it/datatables_users',
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
        {'data': 'ID'},
        {'data': 'USUARIO'},
        {'data': 'NOMBRE'},
        {'data': 'STATUS'},
        {'data': 'PERFIL'},
        {'data': 'CORREO'},
        {'data': 'ESTACION'},
        {'data': 'FECHA'},
        {'data': 'PERMISOS'},
        {'data': 'ACCIONES'}
    ],
    rowId: 'ID',
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Tabla de usuarios
let binnacle_table = $('#binnacle_table').DataTable({
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
                $('#datatables_users thead th').each(function () {
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
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_users input').on('keyup change clear', function () {
    datatables_users
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#USUARIO').val().trim())
        .column(2).search($('#NOMBRE').val().trim())
        .column(3).search($('#STATUS').val().trim())
        .column(4).search($('#PERFIL').val().trim())
        .column(5).search($('#CORREO').val().trim())
        .column(6).search($('#ESTACION').val().trim())
        .column(7).search($('#FECHA').val().trim())
        .column(8).search($('#PERMISOS').val().trim())
        .draw();
  });

// Agregar un evento clic de refresh
$('.refresh_datatables_users').on('click', function () {
    datatables_users.clear().draw();
    datatables_users.ajax.reload();
});


// Tabla de usuarios/permisos
let datatables_permissions_users = $('#datatables_permissions_users').DataTable({
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
                $('#datatables_permissions_users thead th').each(function () {
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
        data: {'user_id': $('#datatables_permissions_users').data('user')},
        url: '/it/datatables_permissions_users',
        error: function() {
            $('#datatables_permissions_users').waitMe('hide');
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
        {'data': 'CLASE'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'DESCRIPCION'},
        {'data': 'STATUS'},
        {'data': 'FECHA'},
        {'data': 'ACCIONES'}
    ],
    rowId: 'ID',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_permissions_users input').on('keyup change clear', function () {
    datatables_permissions_users
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#CLASE').val().trim())
        .column(2).search($('#DEPARTAMENTO').val().trim())
        .column(3).search($('#DESCRIPCION').val().trim())
        .column(4).search($('#STATUS').val().trim())
        .column(5).search($('#FECHA').val().trim())
        .draw();
  });

// Agregar un evento clic de refresh
$('.refresh_datatables_permissions_users').on('click', function () {
    datatables_permissions_users.clear().draw();
    datatables_permissions_users.ajax.reload();
    $('#datatables_permissions_users').waitMe('hide');
});




// Tabla de usuarios/permisos
let datatables_stations = $('#datatables_stations').DataTable({
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
                $('#datatables_stations thead th').each(function () {
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
        url: '/it/datatables_stations',
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
        {'data': 'ID'},
        {'data': 'NOMBRE'},
        {'data': 'DOMICILIO'},
        {'data': 'ESTACIÓN'},
        {'data': 'SERVIDOR'},
        {'data': 'BD'},
        {'data': 'CRE'},
        {'data': 'DENOMINACIÓN'},
        {'data': 'ZONA'},
        {'data': 'RFC'},
        {'data': 'STATUS'},
        {'data': 'CONEXION'},
    ],
    rowId: 'ID',
    createdRow: function (row, data, dataIndex) {
    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_stations input').on('keyup change clear', function () {
    datatables_stations
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#NOMBRE').val().trim())
        .column(2).search($('#DOMICILIO').val().trim())
        .column(3).search($('#ESTACIÓN').val().trim())
        .column(4).search($('#SERVIDOR').val().trim())
        .column(5).search($('#BD').val().trim())
        .column(6).search($('#CRE').val().trim())
        .column(7).search($('#DENOMINACIÓN').val().trim())
        .column(8).search($('#ZONA').val().trim())
        .column(9).search($('#RFC').val().trim())
        .column(10).search($('#STATUS').val().trim())
        .draw();
  });

// Agregar un evento clic de refresh
$('.refresh_datatables_stations').on('click', function () {
    datatables_stations.clear().draw();
    datatables_stations.ajax.reload();
});



// Tabla de usuarios/permisos
let datatables_permissions = $('#datatables_permissions').DataTable({
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
                $('#datatables_permissions thead th').each(function () {
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
        data: {'user_id': $('#datatables_permissions').data('user')},
        url: '/it/datatables_permissions',
        error: function() {
            $('#datatables_permissions').waitMe('hide');
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
        {'data': 'CLASE'},
        {'data': 'DEPARTAMENTO'},
        {'data': 'DESCRIPCION'},
        {'data': 'STATUS'},
        {'data': 'FECHA'},
        {'data': 'ACCIONES'}
    ],
    rowId: 'ID',
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_permissions input').on('keyup change clear', function () {
    datatables_permissions
        .column(0).search($('#ID').val().trim())
        .column(1).search($('#CLASE').val().trim())
        .column(2).search($('#DEPARTAMENTO').val().trim())
        .column(3).search($('#DESCRIPCION').val().trim())
        .column(4).search($('#STATUS').val().trim())
        .column(5).search($('#FECHA').val().trim())
        .draw();
  });

// Agregar un evento clic de refresh
$('.refresh_datatables_permissions').on('click', function () {
    datatables_permissions.clear().draw();
    datatables_permissions.ajax.reload();
    $('#datatables_permissions').waitMe('hide');
});



// Tabla de despachos a liberar
let datatables_release_dispatches = $('#datatables_release_dispatches').DataTable({
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
                $('#datatables_release_dispatches thead th').each(function () {
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
        data: {'nrotrn': $('#nrotrn').val(), 'codgas': $('#codgas').val()},
        url: '/it/datatables_release_dispatches',
        error: function() {
            $('#datatables_release_dispatches').waitMe('hide');
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
        {'data': 'FDESPACHO'},
        {'data': 'LITROS', 'render': $.fn.dataTable.render.number( ',', '.', 3, '' )},
        {'data': 'MONTO', 'render': $.fn.dataTable.render.number( ',', '.', 2, '$' )},
        {'data': 'FACTURA'},
        {'data': 'FACTEST'},
        {'data': 'UUID'},
        {'data': 'RFC'},
        {'data': 'LOGFECHA'},
        {'data': 'ACCIONES'},
    ],
    rowId: 'DESPACHO',
    createdRow: function (row, data, dataIndex) {

    },
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
        $('.table-responsive').removeClass('loading');
    }
});

// Evento para aplicar los filtros cuando cambien los valores en los inputs de filtrado
$('#filtro-datatables_release_dispatches input').on('keyup change clear', function () {
    datatables_release_dispatches
        .column(0).search($('#DESPACHO').val().trim())
        .column(1).search($('#FDESPACHO').val().trim())
        .column(2).search($('#LITROS').val().trim())
        .column(3).search($('#MONTO').val().trim())
        .column(4).search($('#FACTURA').val().trim())
        .column(5).search($('#FACTEST').val().trim())
        .column(6).search($('#UUID').val().trim())
        .column(7).search($('#RFC').val().trim())
        .column(8).search($('#LOGFECHA').val().trim())
        .draw();
  });

// Agregar un evento clic de refresh
$('.refresh_datatables_release_dispatches').on('click', function () {
    datatables_release_dispatches.clear().draw();
    datatables_release_dispatches.ajax.reload();
    $('#datatables_release_dispatches').waitMe('hide');
});



// Asignar o remover permiso a un usuario
function assignPermission(checkbox) {
    // Obtén el estado actual del checkbox
    let isChecked     = $(checkbox).prop('checked');
    let user_id       = $(checkbox).data('user');
    let permission_id = $(checkbox).data('permission');

    // Resto del código...
    $.ajax({
        url: '/it/assignPermission',
        method: 'GET',
        data: {
            'user_id' : user_id,
            'permission_id': permission_id,
            'check': (isChecked ? 1 : 0 ),
        },
        dataType: 'json',
        success: function() {
            if (isChecked) {
                toastr.success("Permiso agregado correctamente", "¡Éxito!", { timeOut: 2000 });
            } else {
                toastr.warning("Permiso removido correctamente", "¡Éxito!", { timeOut: 2000 });
            }
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
}


$('#userModal').on('show.bs.modal', function (event) {
    let modal = $(this);
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/it/userModal',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#userModalLabel').text(data.title);
            modal.find('#content').html(data.content);
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});

$('#editUserModal').on('show.bs.modal', function (event) {
    let modal = $(this);
    // Recuperamos el valor de data-id del botón que abre el modal
    let id = $(event.relatedTarget).data('id');
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/it/editUserModal/' + id,
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#editUserModalLabel').text(data.title);
            modal.find('#content').html(data.content);
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});



$('#changePasswordModal').on('show.bs.modal', function (event) {
    let modal = $(this);
    // Recuperamos el valor de data-id del botón que abre el modal
    let id = $(event.relatedTarget).data('id');
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/it/changePasswordModal/',
        method: 'GET',
        data: {'id': id},
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#changePasswordModalLabel').text(data.title);
            modal.find('#content').html(data.content);
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});

$('#permissionModal').on('show.bs.modal', function (event) {
    let modal = $(this);
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/it/permissionModal',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#permissionModalLabel').text(data.title);
            modal.find('#content').html(data.content);
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});


$('#stationModal').on('show.bs.modal', function (event) {
    let modal = $(this);
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/it/stationModal',
        method: 'GET',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#stationModalLabel').text(data.title);
            modal.find('#content').html(data.content);
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});

function release_dispatches(from, until, codgas) {
    $('.table-responsive').addClass('loading');
    $.ajax({
        url: '/it/release_dispatches',
        method: 'POST',
        data: {
            'from': from,
            'until': until,
            'codgas': codgas
        },
        dataType: 'json',
        success: function(data) {
            if (data.status === 'OK') {
                datatables_release_dispatches.clear().draw();
                datatables_release_dispatches.ajax.reload();
                $('.table-responsive').removeClass('loading');
                $('#datatables_release_dispatches').waitMe('hide');
                alertify.myAlert(
                    `<div class="container text-center text-success">
                        <h4 class="mt-2 text-success">¡Éxito!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">${data.message}</p>
                    </div>`
                );
            }
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
}

// Formulario para...
$(document).on("submit", "#userForm", function (event) {
    event.preventDefault(); // Evita el envío del formulario por defecto
    let formData = $("#userForm").serialize(); // Obtén los datos del formulario
    // Validar si las contraseñas coinciden
    if ($("#password").val() !== $("#password2").val()) {
        alert("Las contraseñas no coinciden");
        return; // Detiene el envío del formulario si las contraseñas no coinciden
    }

    $.ajax({
        type: "POST",
        url: "/it/userForm", // URL de destino
        data: formData,
        success: function (response) {
            if (response == 1) { // Usuario guardado con éxito
                alertify.myAlert(
                    `<div class="container text-center text-success">
                        <h4 class="mt-2 text-success">¡Éxito!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">Usuario creado correctamente</p>
                    </div>`
                );
                $("#userModal").modal("hide");
                $("#userForm")[0].reset();
                datatables_users.clear().draw();
                datatables_users.ajax.reload();
                $('.table-responsive').removeClass('loading');
            } else if (response == 2) {
                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">Este usuario ya existe.</p>
                    </div>`
                );
            } else {
                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">Error al crear al usuario. Favor de comunicarse on el departamento de Sistemas.</p>
                    </div>`
                );
                $("#userModal").modal("hide");
                $("#userForm")[0].reset();
            }
        },
        error: function (error) {
            console.error(error); // Manejar errores de la solicitud AJAX aquí
        }
    });
});

// Formulario para...
$(document).on("submit", "#editUserForm", function (event) {
    event.preventDefault(); // Evita el envío del formulario por defecto

    let formData = $("#editUserForm").serialize(); // Obtén los datos del formulario

    $.ajax({
        type: "POST",
        url: "/it/editUserForm", // URL de destino
        data: formData,
        success: function (response) {
            if (response == 1) {
                // Cerrar modal
                $("#editUserModal").modal("hide");
                // Mandar mensajito de exito
                toastr.success('Los datos del usuario se actualizaron correctamente', '¡Éxito!', { timeOut: 5000 });
                // Deberiamos actualizar la tabla
                datatables_users.clear().draw();
                datatables_users.ajax.reload();
                $('.table-responsive').removeClass('loading');
            } else {
                toastr.warning('No fue posible modificar los datos del usuario', '¡Atención!', { timeOut: 5000 });
            }
        },
        error: function (error) {
            console.error(error); // Manejar errores de la solicitud AJAX aquí
        }
    });
});


// Formulario para...
$(document).on("submit", "#changePasswordForm", function (event) {
    event.preventDefault(); // Evita el envío del formulario por defecto
    let password1 = $('#password1').val();
    let password2 = $('#password2').val();

    if (password1 !== password2) {
        $('#password2').addClass('is-invalid'); // Agregar una clase de Bootstrap para resaltar en rojo
        $('#password2-error').text('Las contraseñas no coinciden').show();
    }

    let formData = $("#changePasswordForm").serialize(); // Obtén los datos del formulario
    $.ajax({
        type: "POST",
        url: "/it/changePasswordModal", // URL de destino
        data: formData,
        success: function (response) {
            if (response == 1) {
                toastr.success('La contraseña se ha actualizado correctamente', '¡Éxito!', { timeOut: 5000 });
                // Cerramos el modal de la contraseña
                $("#changePasswordModal").modal("hide");
            } else {
                toastr.warning('No fue posible modificar la contraseña', '¡Atención!', { timeOut: 5000 });
            }
        },
        error: function (error) {
            console.error(error); // Manejar errores de la solicitud AJAX aquí
        }
    });
});

// Restablecer el estado del segundo campo cuando el usuario lo modifica
$('#password2').on('input', function() {
    $('#password2').removeClass('is-invalid');
    $('#password2-error').hide();
});

$('#change_password_form').submit(function(event) {
    event.preventDefault();

    // Obtener los valores de las contraseñas
    let password1 = $('#password1').val();
    let password2 = $('#password2').val();

    // Verificar que las contraseñas coincidan
    if (password1 !== password2) {
        // Manejar el caso en el que las contraseñas no coincidan
        toastr.warning('Las contraseñas no coinciden');
        return; // Detener el envío del formulario
    }

    // Aquí puedes enviar las contraseñas al servidor utilizando AJAX
    $.ajax({
        type: 'POST',
        url: '/it/change_password', // Reemplaza con tu URL
        data: {
            password1: password1,
            password2: password2
        },
        success: function(response) {
            // Manejar la respuesta del servidor
            if (response === 1) {
                toastr.success('Contraseña actualizada correctamente');
                // Ahora redirigimos a la página de inicio de sesión
                window.location.href = '/_assets/includes/logout.inc.php';
            } else {
                toastr.warning('No fue posible actualizar la contraseña');
            }
        },
        error: function(xhr, status, error) {
            // Manejar errores de la petición AJAX
            console.error('Error al cambiar la contraseña:', error);
        }
    });
});

$('#modalActivities').on('show.bs.modal', function (event) {
    let modal = $(this);
    let date = modal.find('#date_modal').val();
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/it/modalActivities',
        data: {'date': date},
        method: 'POST',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#modalActivitiesLabel').text(data.title);
            modal.find('.content').html(data.content);
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});

$('#activityModal').on('show.bs.modal', function (event) {
    let modal = $(this);
    let date = modal.find('#date_modal').val();
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/it/activityModal',
        data: {'activity_id': $('input#activity_id').val()},
        method: 'POST',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#activityModalLabel').text(data.title);
            modal.find('.content').html(data.content);
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
});

$('#modalEditActivities').on('show.bs.modal', function (event) {
    let modal = $(this);
    let date = modal.find('#date_modal').val();
    let id = $(event.relatedTarget).data('id');
    // Acciones que deseas realizar antes de que el modal se muestre
    $.ajax({
        url: '/it/modalEditActivities',
        data: {'activity_id': id},
        method: 'POST',
        dataType: 'json',
        success: function(data) {
            modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
            modal.find('#modalEditActivitiesLabel').text(data.title);
            modal.find('.content').html(data.content);
            $('.selectpicker').selectpicker();
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
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
                datatables_release_dispatches.clear().draw();
                datatables_release_dispatches.ajax.reload();
                $('.table-responsive').removeClass('loading');
                $('#datatables_release_dispatches').waitMe('hide');
                alertify.myAlert(
                    `<div class="container text-center text-success">
                        <h4 class="mt-2 text-success">¡Éxito!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">${data.message}</p>
                    </div>`
                );
            }
        },
        error: function(xhr, textStatus, errorThrown) {
            console.error('AJAX error:', errorThrown);
        }
    });
}

function out() {
    console.log("Entrabndo a la funcion");
    $.ajax({
        url: "/_assets/includes/logout2.inc.php",
        cache: false
    })
    .done(function( data ) {
        console.log(data);
    });
}