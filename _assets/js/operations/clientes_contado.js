const EMPTY_SIN_ESTACION = 'Selecciona una estación para ver sus clientes de contado';
const EMPTY_SIN_BUSCAR = 'Presiona Buscar (o Enter) para ver los clientes de esta estación';
const EMPTY_SIN_RESULTADOS = 'No hay clientes de contado que coincidan con la búsqueda';
const EMPTY_SIN_REVISAR = 'Ningún cliente de esta búsqueda tiene RFC, régimen o correo por revisar';
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

// Calcula (una vez por fila) el resultado de las reglas de RFC, régimen y correo
function validarFila(d) {
    d._vRfc = VF.rfc(d.RFC);
    d._vRegimen = VF.regimen(d.RegimenFiscal, d.RFC);
    d._vCorreo = VF.correos(d.Correo);
    return d;
}

// "Por revisar" = el RFC no está bien (error o aviso) o algún correo no es válido.
// Los avisos de correo (coma, espacios, repetidos) se corrigen solos al guardar.
function rfcPorRevisar(d) { return d._vRfc.nivel === 'error' || d._vRfc.nivel === 'aviso'; }
function correoPorRevisar(d) { return d._vCorreo.nivel === 'error'; }
// Régimen: clave fuera del catálogo, que no corresponde al RFC, o con espacios.
// Vacío no cuenta como "por revisar" (son la mayoría y no es un dato erróneo).
function regimenPorRevisar(d) { return d._vRegimen.nivel === 'error' || d._vRegimen.nivel === 'aviso'; }

function descripcionRegimen(clave) {
    const r = VF.REGIMENES[String(clave == null ? '' : clave).trim()];
    return r ? r[0] : '';
}

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
                columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13],
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
        {
            'data': 'RegimenFiscal',
            'render': function (data, type, row) {
                const clave = data == null ? '' : String(data).trim();
                if (type === 'filter') return clave + ' ' + descripcionRegimen(clave);
                if (type !== 'display') return clave;
                if (row._vRegimen.nivel === 'vacio') return '<span class="vf-vacio">Sin régimen</span>';
                const desc = descripcionRegimen(clave);
                return `<span${desc ? ` title="${esc(desc)}"` : ''}>${esc(clave)}</span>` + notaValidacion(row._vRegimen);
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
            // Llega en una segunda petición (cargarUltimasFacturas): undefined = aún cargando
            'data': 'UltimaFactura',
            'defaultContent': '',
            'render': function (data, type) {
                if (data === undefined) {
                    return type === 'display' ? '<span class="vf-vacio">Cargando…</span>' : '';
                }
                if (data === null || data === false) {
                    if (type !== 'display') return '';
                    return `<span class="vf-vacio">${data === null ? 'Sin facturas' : 'No disponible'}</span>`;
                }
                if (type === 'sort') return data.Fecha + ' ' + data.Factura;
                if (type !== 'display') return `${data.Fecha} ${data.Factura} (${data.Estacion})`;
                return `<span class="uf-factura">${esc(data.Fecha)} · ${esc(data.Factura)}</span>` +
                       `<span class="uf-estacion">(${esc(data.Estacion)})</span>`;
            }
        },
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
                if (row._vRegimen.nivel !== 'ok') partes.push('Régimen: ' + row._vRegimen.mensaje);
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
    return rfcPorRevisar(d) || regimenPorRevisar(d) || correoPorRevisar(d);
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
            const nRegimen = filas.filter(regimenPorRevisar).length;
            const nCorreo = filas.filter(correoPorRevisar).length;
            if (nRfc) resumen += ` · ${nRfc.toLocaleString('es-MX')} RFC por revisar`;
            if (nRegimen) resumen += ` · ${nRegimen.toLocaleString('es-MX')} ${nRegimen === 1 ? 'régimen' : 'regímenes'} por revisar`;
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
    cancelarCargaFacturas();

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

// ── Última factura de cada cliente (cualquier estación) ──
// Petición aparte: con 5000 clientes tarda varios segundos y no debe frenar la tabla.
let facturasXhr = null;

function cancelarCargaFacturas() {
    if (facturasXhr) facturasXhr.abort();
    facturasXhr = null;
}

function asignarUltimaFactura(porCodigo) {
    clientes_contado_table.rows().every(function () {
        const d = this.data();
        // false = no se pudo consultar; null = el cliente no tiene facturas
        d.UltimaFactura = porCodigo ? (porCodigo[d.Codigo] || null) : false;
        this.data(d);
    });
    clientes_contado_table.draw(false);
}

function cargarUltimasFacturas() {
    cancelarCargaFacturas();
    const codigos = clientes_contado_table.rows().data().toArray().map(d => d.Codigo);
    if (!codigos.length) return;

    const xhr = facturasXhr = $.ajax({
        url: '/operations/clientes_contado_facturas',
        method: 'POST',
        data: { codigos: codigos.join(',') },
        success: function (response) {
            if (response.error) {
                alertify.warning(response.error);
                asignarUltimaFactura(null);
                return;
            }
            asignarUltimaFactura(response.data || {});
        },
        error: function (xhr, status) {
            if (status === 'abort') return; // nueva búsqueda o cambio de estación
            alertify.warning(mensajeErrorAjax(xhr, 'consultar la última factura de los clientes'));
            asignarUltimaFactura(null);
        },
        complete: function () {
            if (facturasXhr === xhr) facturasXhr = null;
        }
    });
}

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

    cancelarCargaFacturas();
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
                cargarUltimasFacturas();
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
    actualizarHintRegimen();
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

// Régimen guardado en el cliente al abrir el modal (tal cual, con espacios si los tenía)
let regfisOriginal = '';

// Pone el valor del cliente en el <select>. Si la clave no está en el catálogo
// (p. ej. 666 o 0) se agrega una opción temporal para que se vea y no se borre
// sin que el usuario lo note; mientras siga elegida, no deja guardar.
function cargarRegimen(valor) {
    const $sel = $('#edit_regfis');
    $sel.find('option.regfis-temp').remove();
    regfisOriginal = valor == null ? '' : String(valor);
    const clave = regfisOriginal.trim();
    if (clave !== '' && !VF.REGIMENES[clave]) {
        $('<option class="regfis-temp">').val(clave).text(`${clave} - No existe en el catálogo del SAT`)
            .insertAfter($sel.find('option').first());
    }
    $sel.val(clave);
}

function actualizarHintRegimen() {
    const $sel = $('#edit_regfis');
    const v = VF.regimen($sel.val(), $('#edit_rfc').val());
    const marcado = $sel.hasClass('is-invalid');
    if (v.nivel === 'error' && !marcado) {
        setHint($('#edit_regfis_hint'), v.mensaje, 'error');
    } else if (v.nivel === 'vacio') {
        setHint($('#edit_regfis_hint'), v.mensaje, 'generico');
    } else if (v.nivel === 'ok' && regfisOriginal !== '' && regfisOriginal !== $sel.val() && regfisOriginal.trim() === $sel.val()) {
        setHint($('#edit_regfis_hint'), `Tenía espacios; al guardar quedará «${$sel.val()}»`, 'aviso');
    } else {
        setHint($('#edit_regfis_hint'), '', null);
    }
}

// Estatus del cliente al abrir el modal ('' si trae un valor fuera del catálogo)
let codestOriginal = '';

function actualizarHintEstatus() {
    const val = $('#edit_codest').val();
    if (val === '') {
        setHint($('#edit_codest_hint'), codestOriginal === '' ? 'El estatus actual no es válido; elige uno para poder guardar.' : '', codestOriginal === '' ? 'aviso' : null);
    } else if (val !== codestOriginal && val === '-1') {
        setHint($('#edit_codest_hint'), 'Al guardar, el cliente quedará deshabilitado (dado de baja) en la estación y en el corporativo.', 'aviso');
    } else if (val !== codestOriginal && val === '1') {
        setHint($('#edit_codest_hint'), 'Al guardar, el cliente quedará suspendido.', 'aviso');
    } else {
        setHint($('#edit_codest_hint'), '', null);
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
    cargarRegimen(row.RegimenFiscal);
    const codest = row.CodigoEstatus == null ? '' : String(row.CodigoEstatus);
    codestOriginal = $('#edit_codest option').filter((i, o) => o.value !== '' && o.value === codest).length ? codest : '';
    $('#edit_codest').val(codestOriginal);
    $('#edit_codpos').val(row.CodigoPostal);
    $('#edit_correo').val(row.Correo || '');
    actualizarHintRfc();
    actualizarHintRegimen();
    actualizarHintCorreo();
    actualizarHintEstatus();

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
    actualizarHintRegimen(); // el régimen depende de si el RFC es de física o moral
}).on('blur', actualizarHintRfc);

$('#edit_codest').on('change', function () {
    $(this).removeClass('is-invalid');
    $('#edit_codest_feedback').text('');
    actualizarHintEstatus();
});

$('#edit_regfis').on('change', function () {
    $(this).removeClass('is-invalid');
    $('#edit_regfis_feedback').text('');
    actualizarHintRegimen();
});

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

    const vRegimen = VF.regimen(p.regfis, p.rfc);
    if (vRegimen.nivel === 'error') return { campo: 'regfis', mensaje: vRegimen.mensaje };

    if (!/^[0-9]{2,5}$/.test(p.codpos)) return { campo: 'codpos', mensaje: 'El código postal debe ser numérico, de 2 a 5 dígitos' };

    const vCorreo = VF.correos(p.correo);
    if (vCorreo.nivel === 'error') return { campo: 'correo', mensaje: vCorreo.mensaje };

    if (p.codest === '') return { campo: 'codest', mensaje: 'Elige un estatus (Activo, Suspendido o Deshabilitado)' };

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
        regfis: $('#edit_regfis').val() || '',
        codpos: $('#edit_codpos').val().trim(),
        correo: $('#edit_correo').val(),
        codest: $('#edit_codest').val() || '',
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
                        d.RegimenFiscal = response.regfis !== undefined ? response.regfis : payload.regfis;
                        d.CodigoEstatus = response.codest !== undefined ? response.codest : parseInt(payload.codest, 10);
                        if (response.estatus) d.Estatus = response.estatus;
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
