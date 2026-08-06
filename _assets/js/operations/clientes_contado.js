let clientes_contado_table = $('#clientes_contado_table').DataTable({
    colReorder: true,
    order: [2, "asc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 25,
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            title: 'Clientes_Contado',
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9]
            }
        }
    ],
    columns: [
        {'data': 'Estacion'},
        {'data': 'Codigo'},
        {'data': 'Nombre'},
        {'data': 'Domicilio'},
        {'data': 'RFC'},
        {'data': 'CodigoPostal'},
        {'data': 'Telefono'},
        {'data': 'Correo'},
        {'data': 'Estatus'},
        {'data': 'Registrado'},
        {
            'data': null,
            'orderable': false,
            'render': function () {
                return '<button type="button" class="btn btn-sm btn-primary btn-edit-contado">' +
                       '<i data-feather="edit-2"></i></button>';
            }
        },
    ],
    initComplete: function () {
        $('.dt-buttons').addClass('d-none');
    },
    drawCallback: function () {
        feather.replace();
    }
});

// ── Buscar clientes de contado en la estación seleccionada ──
$('#btn_buscar_contado').on('click', function () {
    const codgas = $('#contado_estacion').val();
    const termino = $('#contado_termino').val().trim();

    if (!codgas) {
        alertify.error('Selecciona una estación');
        return;
    }
    if (termino.length > 0 && termino.length < 3) {
        alertify.error('Escribe al menos 3 caracteres para buscar, o déjalo vacío para ver los últimos registrados');
        return;
    }

    const $btn = $(this);
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Buscando...');
    $('.table-responsive').addClass('loading');

    $.ajax({
        url: '/operations/clientes_contado_search',
        method: 'POST',
        data: { codgas: codgas, termino: termino },
        success: function (response) {
            clientes_contado_table.clear();
            if (response.data && response.data.length > 0) {
                clientes_contado_table.rows.add(response.data).draw();
                alertify.success(`Se encontraron ${response.data.length} registros`);
            } else {
                clientes_contado_table.draw();
                alertify.warning(response.error || 'No se encontraron clientes con ese criterio');
            }
        },
        error: function (xhr, status, error) {
            alertify.error('Error al buscar clientes de contado: ' + error);
            console.error('Error:', xhr.responseText);
        },
        complete: function () {
            $('.table-responsive').removeClass('loading');
            $btn.prop('disabled', false).html('<i data-feather="search"></i> Buscar');
            feather.replace();
        }
    });
});

$('#contado_termino').on('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        $('#btn_buscar_contado').trigger('click');
    }
});

// ── Exportar a Excel ──
$('#exportExcelContado').on('click', function (e) {
    e.preventDefault();
    clientes_contado_table.button('.buttons-excel').trigger();
});

// ── Abrir modal de edición con los datos de la fila ──
$('#clientes_contado_table tbody').on('click', '.btn-edit-contado', function () {
    const row = clientes_contado_table.row($(this).closest('tr')).data();
    if (!row) return;

    $('#edit_codgas').val(row.CodigoEstacion);
    $('#edit_cod').val(row.Codigo);
    $('#edit_estacion_nombre').val(row.Estacion);
    $('#edit_den').val(row.Nombre);
    $('#edit_rfc').val(row.RFC);
    $('#edit_codpos').val(row.CodigoPostal);

    new bootstrap.Modal(document.getElementById('modal_edit_cliente_contado')).show();
});

// ── Validaciones (espejo de las del servidor, para feedback inmediato) ──
const RFC_PATTERN = /^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/;
const DEN_PATTERN = /^[\p{L}0-9 &.,\-/()']+$/u;

function validarClienteContado(den, rfc, codpos) {
    den = den.replace(/\s+/g, ' ').trim();
    if (!den) return 'La razón social no puede estar vacía';
    if (den.length < 3 || den.length > 255) return 'La razón social debe tener entre 3 y 255 caracteres';
    if (!DEN_PATTERN.test(den)) return "La razón social solo puede tener letras, números, espacios y & . , - / ( ) '";

    rfc = rfc.trim().toUpperCase();
    if (!RFC_PATTERN.test(rfc)) return 'El RFC no es válido. Debe tener 12 o 13 caracteres con el formato del SAT';

    codpos = codpos.trim();
    if (!/^[0-9]{2,5}$/.test(codpos)) return 'El código postal debe ser numérico, de 2 a 5 dígitos';

    return null;
}

// ── Guardar edición (afecta estación + corporativo, todo o nada) ──
$('#btn_guardar_cliente_contado').on('click', function () {
    const $btn = $(this);
    const payload = {
        codgas: $('#edit_codgas').val(),
        cod: $('#edit_cod').val(),
        den: $('#edit_den').val().replace(/\s+/g, ' ').trim(),
        rfc: $('#edit_rfc').val().trim().toUpperCase(),
        codpos: $('#edit_codpos').val().trim(),
    };

    const error = validarClienteContado(payload.den, payload.rfc, payload.codpos);
    if (error) {
        alertify.error(error);
        return;
    }

    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Guardando...');

    $.ajax({
        url: '/operations/edit_cliente_contado',
        method: 'POST',
        data: payload,
        success: function (response) {
            if (response.success) {
                alertify.success(response.message || 'Cliente actualizado correctamente');

                // Refresca la fila en la tabla sin volver a consultar la estación
                clientes_contado_table.rows().every(function () {
                    const d = this.data();
                    if (String(d.CodigoEstacion) === String(payload.codgas) && String(d.Codigo) === String(payload.cod)) {
                        d.Nombre = payload.den;
                        d.RFC = payload.rfc;
                        d.CodigoPostal = payload.codpos;
                        this.data(d);
                    }
                });
                clientes_contado_table.draw(false);

                bootstrap.Modal.getInstance(document.getElementById('modal_edit_cliente_contado')).hide();
            } else {
                alertify.error(response.message || 'No se pudo actualizar el cliente');
            }
        },
        error: function (xhr, status, error) {
            alertify.error('Error al guardar: ' + error);
            console.error('Error:', xhr.responseText);
        },
        complete: function () {
            $btn.prop('disabled', false).text('Guardar');
        }
    });
});
