console.log('employees.js loaded');


var datatables_employees = $('#datatables_employees').DataTable({
    colReorder: true,
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 100,
    buttons: [
        {
            extend: 'excel',
            className: 'btn btn-sm btn-success',
            text: '<i class="fas fa-file-excel"></i> Excel',
            footer: true,
            header:true
        },
        {
            extend: 'pdf',
            className: 'btn btn-sm btn-info',
            text: '<i class="fas fa-file-pdf"></i> PDF'
        },
        {
            extend: 'print',
            className: 'btn btn-sm btn-secondary',
            text: '<i class="fas fa-print"></i> Print'
        },
        {
            extend: 'copy',
            className: 'btn btn-sm btn-warning',
            text: '<i class="fas fa-print"></i> Copiar'
        },
        {
            text: '<i class="fas fa-sync"></i>',
            className: 'btn-sm btn-success',
            action: function (e, dt, node, config) {
                datatables_employees.clear().draw();
                datatables_employees.ajax.reload();
                $('div#loader').addClass('d-none');
            }
        }
    ],
    ajax: {
        method: 'POST',
        url: '/humancapital/datatables_employees',
        error: function() {
            $('div#loader').addClass('d-none');
            alertify.myAlert(
                `<div class="container text-center text-danger">
                    <p><i class="fa-3x fas fa-search ball"></i></p>
                    <h4 class="mt-2">¡Error!</h4>
                </div>
                <div class="text-dark">
                    <p class="text-center">No existen registros con los parametros dados. Intentelo nuevamente.</p>
                </div>`
            );
        },
        beforeSend: function() {
            $('div#loader').removeClass('d-none');
        }
    },
    rowId: 'id',
    columns: [
        {'data': 'id'},
        {'data': 'Numero'},
        {'data': 'Nombre'},
        {'data': 'Departamento'},
        {'data': 'FechaIngreso'},
        {'data': 'Activo'},
        {'data': 'FechaBaja'},
        {'data': 'Recontratar'},
        {'data': 'MotivoBaja'},
        {'data': 'Equipo'},
        {'data': 'Antiguedad'},
        {'data': 'BajaCorrecta'},
        {'data': 'puesto_id'},
        {'data': 'empresa_id'}
    ],
    createdRow: function (row, data, dataIndex) {


    },

    initComplete: function () {
        $('div#loader').addClass('d-none');
        this.api().columns().every(function () {
            var that = this;
            $('input', this.header()).on('keyup change clear', function () {
                if (that.search() !== this.value) {
                    that
                        .search(this.value)
                        .draw();
                }
            });
        });
    }
});



async function upload_file_rh() {
    const fileInput = document.getElementById('file_to_upload');
    const file = fileInput.files[0]; // Obtiene el primer archivo seleccionado

    if (!file) {
        toastr.error('Por favor, selecciona un archivo.', '¡Error!', { timeOut: 3000 });
        return;
    }

    $('.heather_employees').addClass('loading');
    const formData = new FormData();
    formData.append('file_to_upload', file);

    try {
        const response = await fetch('/humancapital/import_file_reporte_empleados', {
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
            $('.heather_employees').removeClass('loading');
            fileInput.value = '';
        }
    } catch (error) {
        console.error('Error al subir el archivo:', error);
        $('.heather_employees').removeClass('loading');
        toastr.error('Hubo un problema al subir el archivo.', '¡Error!', { timeOut: 3000 });
    }
}