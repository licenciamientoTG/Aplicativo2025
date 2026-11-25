const EMPTY_SIN_ESTACION = 'Selecciona una estación para ver sus clientes de contado';
const EMPTY_SIN_BUSCAR = 'Presiona Buscar (o Enter) para ver los clientes de esta estación';
const EMPTY_SIN_RESULTADOS = 'No hay clientes de contado que coincidan con la búsqueda';
const EMPTY_SIN_REVISAR = 'Ningún cliente de esta búsqueda tiene RFC o correo por revisar';
const ZERO_RECORDS = 'No hay clientes que coincidan con el filtro';
let ultimoTruncado = false;

const VF = window.ValidacionFiscal;

function esc(valor) {
    return String(valor == null ? '' : valor)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Render de DataTables: escapa solo al mostrar; búsqueda, orden y Excel usan el texto tal cual
function textoSeguro(data, type) {
    return type === 'display' ? esc(data) : (data == null ? '' : data);
}

// Calcula (una vez por fila) el resultado de las reglas de RFC y correo
function validarFila(d) {
    d._vRfc = VF.rfc(d.RFC);
    d._vCorreo = VF.correos(d.Correo);
    return d;
}

// "Por revisar" = el RFC no está bien (error o aviso) o algún correo no es válido.
// Los avisos de correo (coma, espacios, repetidos) se corrigen solos al guardar.
function rfcPorRevisar(d) { return d._vRfc.nivel === 'error' || d._vRfc.nivel === 'aviso'; }
function correoPorRevisar(d) { return d._vCorreo.nivel === 'error'; }

const ICONO_NIVEL = { error: 'alert-circle', aviso: 'alert-triangle', generico: 'info' };

function notaValidacion(v) {
    if (!v || v.nivel === 'ok' || !v.mensaje) return '';
    return `<span class="vf-nota vf-${v.nivel}"><i data-feather="${ICONO_NIVEL[v.nivel]}" aria-hidden="true"></i>` +
           `<span>${esc(v.mensaje)}</span></span>`;
}

let clientes_contado_table = $('#clientes_contado_table').DataTable({
    colReorder: true,
    order: [2, "asc"],
    dom: '<"top"Bf>rt<"bottom"lip>',
    pageLength: 25,
    language: {
        emptyTable: EMPTY_SIN_ESTACION,
        zeroRecords: ZERO_RECORDS
    },
    buttons: [
        {
            extend: 'excel',
            className: 'd-none',
            title: 'Clientes_Contado',
            exportOptions: {
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 11],
                orthogonal: 'export'
            }
        }
    ],
    columns: [
        {'data': 'Estacion', 'render': textoSeguro},
        {'data': 'Codigo'},
        {'data': 'Nombre', 'render': textoSeguro},
        {'data': 'Domicilio', 'render': textoSeguro},
        {
            'data': 'RFC',
            'render': function (data, type, row) {
                if (type !== 'display') return data == null ? '' : data;
                return esc(data) + notaValidacion(row._vRfc);
            }
        },
        {'data': 'CodigoPostal', 'render': textoSeguro},
        {'data': 'Telefono', 'render': textoSeguro},
        {
            'data': 'Correo',
            'render': function (data, type, row) {
                if (type !== 'display') return data == null ? '' : data;
                // Un correo por renglón para que la columna no se estire
                const correos = String(data || '').split(/[;,\s]+/).map(s => s.trim()).filter(Boolean)
                    .map(c => `<span class="vf-correo">${esc(c)}</span>`).join('');
                return correos + notaValidacion(row._vCorreo.nivel === 'error' ? row._vCorreo : null);
            }
        },
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
        {
            // Solo para el Excel y la búsqueda: qué hay que revisar en RFC y correo
            'data': null,
            'visible': false,
            'render': function (data, type, row) {
                const partes = [];
                if (row._vRfc.nivel !== 'ok') partes.push('RFC: ' + row._vRfc.mensaje);
                if (row._vCorreo.nivel !== 'ok') partes.push('Correo: ' + row._vCorreo.mensaje);
                return partes.join(' | ');
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

// Filtro "Solo RFC o correo a revisar" (aplica solo a esta tabla)
$.fn.dataTable.ext.search.push(function (settings, searchData, dataIndex) {
    if (settings.nTable.id !== 'clientes_contado_table') return true;
    if (!$('#solo_revisar_contado').is(':checked')) return true;
    const d = clientes_contado_table.row(dataIndex).data();
    return rfcPorRevisar(d) || correoPorRevisar(d);
});

$('#solo_revisar_contado').on('change', function () {
    clientes_contado_table.settings()[0].oLanguage.sZeroRecords = this.checked ? EMPTY_SIN_REVISAR : ZERO_RECORDS;
    clientes_contado_table.draw();
});

function setMensajeTablaVacia(texto) {
    clientes_contado_table.settings()[0].oLanguage.sEmptyTable = texto;
}

// Título de la tarjeta ("· 02 Lerdo · 124 clientes · 3 RFC por revisar") y estado de Exportar/filtro
function actualizarResumen(truncado) {
    ultimoTruncado = truncado;
    const total = clientes_contado_table.rows().count();
    const estacion = $('#contado_estacion').val() ? $('#contado_estacion option:selected').text() : '';
    let resumen = '';
    if (estacion) {
        resumen = `· ${estacion}`;
        if (total > 0) {
            const n = total.toLocaleString('es-MX') + (truncado ? '+' : '');
            resumen += ` · ${n} ${total === 1 ? 'cliente' : 'clientes'}`;

            const filas = clientes_contado_table.rows().data().toArray();
            const nRfc = filas.filter(rfcPorRevisar).length;
            const nCorreo = filas.filter(correoPorRevisar).length;
            if (nRfc) resumen += ` · ${nRfc.toLocaleString('es-MX')} RFC por revisar`;
            if (nCorreo) resumen += ` · ${nCorreo.toLocaleString('es-MX')} ${nCorreo === 1 ? 'correo' : 'correos'} por revisar`;
        }
    }
    $('#clientes_contado_resumen').text(resumen);
    $('#exportExcelContado').toggleClass('disabled', total === 0)
                            .attr('aria-disabled', total === 0 ? 'true' : 'false')
                            .attr('tabindex', total === 0 ? '-1' : null);
    $('#solo_revisar_contado').prop('disabled', total === 0);
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
    setMensajeTablaVacia(hayEstacion ? EMPTY_SIN_BUSCAR : EMPTY_SIN_ESTACION);
    clientes_contado_table.draw();
    actualizarResumen(false);

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
        data: { codgas: codgas, termino: termino, estatus: $('#contado_estatus').val() },
        success: function (response) {
            clientes_contado_table.clear();
            setMensajeTablaVacia(EMPTY_SIN_RESULTADOS);
            if (response.data && response.data.length > 0) {
                clientes_contado_table.rows.add(response.data.map(validarFila)).draw();
                if (response.truncated) {
                    alertify.warning(`Se encontraron ${response.data.length} registros (puede haber más; afina la búsqueda para ver el resto)`);
                } else {
                    alertify.success(`Se encontraron ${response.data.length} registros`);
                }
            } else {
                clientes_contado_table.draw();
                alertify.warning(response.error || 'No se encontraron clientes con ese criterio');
            }
            actualizarResumen(!!response.truncated);
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

// Cambiar el estatus vuelve a consultar si ya había una búsqueda en pantalla
// (el filtro va en el servidor: el límite de filas aplica después de filtrar)
$('#contado_estatus').on('change', function () {
    if ($estacion.val() && clientes_contado_table.settings()[0].oLanguage.sEmptyTable === EMPTY_SIN_RESULTADOS) {
        buscarClientesContado();
    }
});

$termino.on('keydown', function (e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        buscarClientesContado();
    }
});

// ── Exportar a Excel ──
$('#exportExcelContado').on('click', function (e) {
    e.preventDefault();
    if ($(this).hasClass('disabled')) return;
    clientes_contado_table.button('.buttons-excel').trigger();
});

// ── Modal de edición ──
const modalEditEl = document.getElementById('modal_edit_cliente_contado');
const $formEdit = $('#form_edit_cliente_contado');
const $btnGuardar = $('#btn_guardar_cliente_contado');
const AYUDA_CORREO = 'Varios correos se separan con punto y coma (;) o uno por renglón. Puede quedar vacío.';
let guardando = false;

function limpiarErroresEdicion() {
    $formEdit.find('.is-invalid').removeClass('is-invalid');
    $formEdit.find('.invalid-feedback').text('');
}

function marcarErrorCampo(campo, mensaje) {
    $(`#edit_${campo}`).addClass('is-invalid').trigger('focus');
    $(`#edit_${campo}_feedback`).text(mensaje);
    actualizarHintRfc();
    actualizarHintCorreo();
}

function setHint($el, texto, nivel) {
    $el.text(texto).removeClass('vf-error vf-aviso vf-generico');
    if (nivel) $el.addClass('vf-' + nivel);
}

// Pistas en vivo: lo que no impide guardar (aviso/genérico) o lo que pasará al guardar.
// Si el campo ya está marcado en rojo, el error vive en su invalid-feedback y la pista no lo repite.
function actualizarHintRfc() {
    const v = VF.rfc($('#edit_rfc').val());
    const marcado = $('#edit_rfc').hasClass('is-invalid');
    if (v.nivel === 'aviso' || v.nivel === 'generico') {
        setHint($('#edit_rfc_hint'), v.mensaje + (v.nivel === 'aviso' ? '. Se puede guardar.' : ''), v.nivel);
    } else if (v.nivel === 'error' && !marcado && $('#edit_rfc').val().trim() !== '' && !$('#edit_rfc').is(':focus')) {
        setHint($('#edit_rfc_hint'), v.mensaje, 'error');
    } else {
        setHint($('#edit_rfc_hint'), '', null);
    }
}

function actualizarHintCorreo() {
    const raw = $('#edit_correo').val();
    const v = VF.correos(raw);
    if (v.nivel === 'aviso') {
        setHint($('#edit_correo_hint'), 'Al guardar quedará: ' + (v.normalizado || '(vacío)'), 'aviso');
    } else if (v.nivel === 'error' && !$('#edit_correo').hasClass('is-invalid') && !$('#edit_correo').is(':focus')) {
        setHint($('#edit_correo_hint'), v.mensaje, 'error');
    } else {
        setHint($('#edit_correo_hint'), AYUDA_CORREO, null);
    }
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
    $('#edit_correo').val(row.Correo || '');
    actualizarHintRfc();
    actualizarHintCorreo();

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
    actualizarHintRfc();
}).on('blur', actualizarHintRfc);

$('#edit_correo').on('input blur', actualizarHintCorreo);

// Código postal: solo dígitos
$('#edit_codpos').on('input', function () {
    const limpio = this.value.replace(/\D/g, '');
    if (limpio !== this.value) this.value = limpio;
});

// ── Validaciones (espejo de las del servidor, para feedback inmediato) ──
const DEN_PATTERN = /^[\p{L}0-9 &.,\-/()']+$/u;

// Devuelve { campo, mensaje } del primer error, o null si todo es válido
function validarClienteContado(p) {
    const den = p.den;
    if (!den) return { campo: 'den', mensaje: 'La razón social no puede estar vacía' };
    if (den.length < 3 || den.length > 255) return { campo: 'den', mensaje: 'La razón social debe tener entre 3 y 255 caracteres' };
    if (!DEN_PATTERN.test(den)) return { campo: 'den', mensaje: "La razón social solo puede tener letras, números, espacios y & . , - / ( ) '" };

    const vRfc = VF.rfc(p.rfc);
    if (vRfc.nivel === 'error') return { campo: 'rfc', mensaje: 'El RFC no es válido: ' + vRfc.mensaje.charAt(0).toLowerCase() + vRfc.mensaje.slice(1) };

    if (!/^[0-9]{2,5}$/.test(p.codpos)) return { campo: 'codpos', mensaje: 'El código postal debe ser numérico, de 2 a 5 dígitos' };

    const vCorreo = VF.correos(p.correo);
    if (vCorreo.nivel === 'error') return { campo: 'correo', mensaje: vCorreo.mensaje };

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
        correo: $('#edit_correo').val(),
    };

    limpiarErroresEdicion();
    const error = validarClienteContado(payload);
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
                        d.Correo = response.correo != null ? response.correo : VF.correos(payload.correo).normalizado;
                        this.data(validarFila(d));
                    }
                });
                clientes_contado_table.draw(false);
                actualizarResumen(ultimoTruncado);

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
