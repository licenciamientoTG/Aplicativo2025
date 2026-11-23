const EMPTY_SIN_ESTACION = 'Selecciona una estación para ver sus clientes de contado';
const EMPTY_SIN_RESULTADOS = 'No hay clientes de contado que coincidan con la búsqueda';

let clientes_contado_table = $('#clientes_contado_table').DataTable({
    colReorder: true,
    order: [2, "asc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 25,
    language: {
        emptyTable: EMPTY_SIN_ESTACION
    },
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
                return '<button type="button" class="btn btn-sm btn-primary btn-edit-contado" ' +
                       'aria-label="Editar cliente" title="Editar cliente">' +
                       '<i data-feather="edit-2" aria-hidden="true"></i></button>';
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

function setMensajeTablaVacia(texto) {
    clientes_contado_table.settings()[0].oLanguage.sEmptyTable = texto;
}

// Mensaje entendible para el usuario a partir de un error de $.ajax
function mensajeErrorAjax(xhr, accion) {
    if (xhr.status === 0) {
        return `No se pudo conectar con el servidor al ${accion}. Revisa tu conexión e intenta de nuevo.`;
    }
    const msgServidor = xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error);
    if (msgServidor) return msgServidor;
    return `Ocurrió un error al ${accion}. Intenta de nuevo; si persiste, avisa a sistemas.`;
}

// ── Estado de la búsqueda ──
const $estacion = $('#contado_estacion');
const $termino = $('#contado_termino');
const $btnBuscar = $('#btn_buscar_contado');
let busquedaXhr = null;

function limpiarErrorTermino() {
    $termino.removeClass('is-invalid');
    $('#contado_termino_feedback').text('');
}

// Al elegir estación: habilita la búsqueda, limpia resultados de la estación anterior
$estacion.on('change', function () {
    const hayEstacion = !!$estacion.val();

    if (busquedaXhr) {
        busquedaXhr.abort();
    }

    clientes_contado_table.clear();
    setMensajeTablaVacia(EMPTY_SIN_ESTACION);
    clientes_contado_table.draw();

    limpiarErrorTermino();
    $termino.prop('disabled', !hayEstacion)
            .attr('placeholder', hayEstacion ? 'Vacío = todos los clientes' : 'Primero selecciona una estación');
    $btnBuscar.prop('disabled', !hayEstacion);

    if (hayEstacion) {
        $termino.trigger('focus');
    }
});

$termino.on('input', limpiarErrorTermino);

// ── Buscar clientes de contado en la estación seleccionada ──
function buscarClientesContado() {
    if (busquedaXhr) return; // ya hay una búsqueda en curso

    const codgas = $estacion.val();
    const termino = $termino.val().trim();

    if (!codgas) {
        $estacion.selectpicker('toggle');
        return;
    }
    if (termino.length > 0 && termino.length < 3) {
        $termino.addClass('is-invalid').trigger('focus');
        $('#contado_termino_feedback').text('Escribe al menos 3 caracteres, o déjalo vacío para ver todos los clientes.');
        return;
    }

    $btnBuscar.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Buscando...');
    $('#clientes_contado_wrapper').addClass('loading').attr('aria-busy', 'true');

    busquedaXhr = $.ajax({
        url: '/operations/clientes_contado_search',
        method: 'POST',
        data: { codgas: codgas, termino: termino },
        success: function (response) {
            clientes_contado_table.clear();
            setMensajeTablaVacia(EMPTY_SIN_RESULTADOS);
            if (response.data && response.data.length > 0) {
                clientes_contado_table.rows.add(response.data).draw();
                if (response.truncated) {
                    alertify.warning(`Se encontraron ${response.data.length} registros (puede haber más; afina la búsqueda para ver el resto)`);
                } else {
                    alertify.success(`Se encontraron ${response.data.length} registros`);
                }
            } else {
                clientes_contado_table.draw();
                alertify.warning(response.error || 'No se encontraron clientes con ese criterio');
            }
        },
        error: function (xhr, status) {
            if (status === 'abort') return; // se cambió de estación a media búsqueda
            alertify.error(mensajeErrorAjax(xhr, 'buscar clientes de contado'));
            console.error('Error:', xhr.responseText);
        },
        complete: function () {
            busquedaXhr = null;
            $('#clientes_contado_wrapper').removeClass('loading').removeAttr('aria-busy');
            $btnBuscar.prop('disabled', !$estacion.val()).html('<i data-feather="search"></i> Buscar');
            feather.replace();
        }
    });
}

$btnBuscar.on('click', buscarClientesContado);

$termino.on('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        buscarClientesContado();
    }
});

// ── Exportar a Excel ──
$('#exportExcelContado').on('click', function (e) {
    e.preventDefault();
    clientes_contado_table.button('.buttons-excel').trigger();
});

// ── Modal de edición ──
const modalEditEl = document.getElementById('modal_edit_cliente_contado');
const $formEdit = $('#form_edit_cliente_contado');
const $btnGuardar = $('#btn_guardar_cliente_contado');
let guardando = false;

function limpiarErroresEdicion() {
    $formEdit.find('.is-invalid').removeClass('is-invalid');
    $formEdit.find('.invalid-feedback').text('');
}

function marcarErrorCampo(campo, mensaje) {
    $(`#edit_${campo}`).addClass('is-invalid').trigger('focus');
    $(`#edit_${campo}_feedback`).text(mensaje);
}

$('#clientes_contado_table tbody').on('click', '.btn-edit-contado', function () {
    const row = clientes_contado_table.row($(this).closest('tr')).data();
    if (!row) return;

    limpiarErroresEdicion();
    $('#edit_codgas').val(row.CodigoEstacion);
    $('#edit_cod').val(row.Codigo);
    $('#edit_estacion_nombre').val(row.Estacion);
    $('#edit_den').val(row.Nombre);
    $('#edit_rfc').val((row.RFC || '').toUpperCase());
    $('#edit_codpos').val(row.CodigoPostal);

    bootstrap.Modal.getOrCreateInstance(modalEditEl).show();
});

modalEditEl.addEventListener('shown.bs.modal', function () {
    $('#edit_den').trigger('focus');
});

// Quita el error de un campo en cuanto el usuario lo corrige
$formEdit.on('input', '.is-invalid', function () {
    $(this).removeClass('is-invalid');
    $(`#${this.id}_feedback`).text('');
});

// RFC siempre en mayúsculas mientras se escribe (conserva la posición del cursor)
$('#edit_rfc').on('input', function () {
    const start = this.selectionStart;
    const end = this.selectionEnd;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(start, end);
});

// Código postal: solo dígitos
$('#edit_codpos').on('input', function () {
    const limpio = this.value.replace(/\D/g, '');
    if (limpio !== this.value) this.value = limpio;
});

// ── Validaciones (espejo de las del servidor, para feedback inmediato) ──
const RFC_PATTERN = /^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/;
const DEN_PATTERN = /^[\p{L}0-9 &.,\-/()']+$/u;

// Devuelve { campo, mensaje } del primer error, o null si todo es válido
function validarClienteContado(den, rfc, codpos) {
    den = den.replace(/\s+/g, ' ').trim();
    if (!den) return { campo: 'den', mensaje: 'La razón social no puede estar vacía' };
    if (den.length < 3 || den.length > 255) return { campo: 'den', mensaje: 'La razón social debe tener entre 3 y 255 caracteres' };
    if (!DEN_PATTERN.test(den)) return { campo: 'den', mensaje: "La razón social solo puede tener letras, números, espacios y & . , - / ( ) '" };

    rfc = rfc.trim().toUpperCase();
    if (!RFC_PATTERN.test(rfc)) return { campo: 'rfc', mensaje: 'El RFC no es válido. Debe tener 12 o 13 caracteres con el formato del SAT' };

    codpos = codpos.trim();
    if (!/^[0-9]{2,5}$/.test(codpos)) return { campo: 'codpos', mensaje: 'El código postal debe ser numérico, de 2 a 5 dígitos' };

    return null;
}

// ── Guardar edición (afecta estación + corporativo, todo o nada) ──
$formEdit.on('submit', function (e) {
    e.preventDefault();
    if (guardando) return;

    const payload = {
        codgas: $('#edit_codgas').val(),
        cod: $('#edit_cod').val(),
        den: $('#edit_den').val().replace(/\s+/g, ' ').trim(),
        rfc: $('#edit_rfc').val().trim().toUpperCase(),
        codpos: $('#edit_codpos').val().trim(),
    };

    limpiarErroresEdicion();
    const error = validarClienteContado(payload.den, payload.rfc, payload.codpos);
    if (error) {
        marcarErrorCampo(error.campo, error.mensaje);
        return;
    }

    guardando = true;
    $btnGuardar.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Guardando...');

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

                bootstrap.Modal.getOrCreateInstance(modalEditEl).hide();
            } else {
                alertify.error(response.message || 'No se pudo actualizar el cliente');
            }
        },
        error: function (xhr) {
            alertify.error(mensajeErrorAjax(xhr, 'guardar el cliente') + ' No se guardó ningún cambio.');
            console.error('Error:', xhr.responseText);
        },
        complete: function () {
            guardando = false;
            $btnGuardar.prop('disabled', false).text('Guardar');
        }
    });
});
