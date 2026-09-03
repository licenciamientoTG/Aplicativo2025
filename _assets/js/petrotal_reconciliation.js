let datatables_conciliacion = null;
let datatables_recepciones_proveedor = null;

function construirConfigDataTable() {
    return {
        dom: '<"top"f>rt<"bottom"lip>',
        pageLength: 100,
        ajax: {
            url: '/supply/datatables_petrotal_reconciliation',
            data: function (d) {
                d.proveedor_rfc = $('#proveedor_rfc').val();
                d.fecha_desde = $('#fecha_desde').val();
                d.fecha_hasta = $('#fecha_hasta').val();
            },
            beforeSend: function () {
                $('#datatables_conciliacion').closest('.table-responsive').addClass('loading');
                $('#btnBuscarConciliacion').prop('disabled', true);
            },
            complete: function () {
                $('#datatables_conciliacion').closest('.table-responsive').removeClass('loading');
                $('#btnBuscarConciliacion').prop('disabled', false);
            },
            error: function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudieron cargar las facturas.</p></div>');
            }
        },
        deferRender: true,
        order: [[0, 'desc']],
        columns: [
            { data: 'factura_proveedor.Fecha' },
            {
                data: 'factura_proveedor',
                render: function (data) {
                    return `${data.EmisorNombre}<br><small class="text-muted">${data.Folio} · $${Number(data.Total).toLocaleString('es-MX', {minimumFractionDigits: 2})}</small>`;
                }
            },
            {
                data: 'factura_proveedor.Litros',
                render: function (data) {
                    return data ? Number(data).toLocaleString('es-MX', { minimumFractionDigits: 2 }) : '—';
                }
            },
            {
                data: 'estacion',
                render: function (data) {
                    if (!data) return '<span class="badge bg-secondary">Sin identificar</span>';
                    return `${data.Estacion}`;
                }
            },
            {
                data: 'estacion',
                render: function (data) {
                    if (!data) return '<span class="text-muted">—</span>';
                    return data.Nombre || '<span class="text-muted">—</span>';
                }
            },
            {
                data: 'factura_petrotal',
                render: function (data) {
                    if (!data) return '<span class="text-muted">—</span>';
                    return `${data.Folio} · ${Number(data.Litros).toLocaleString('es-MX', {minimumFractionDigits: 2})} L · $${Number(data.Total).toLocaleString('es-MX', {minimumFractionDigits: 2})}`;
                }
            },
            {
                data: 'confianza',
                render: function (data) {
                    if (!data) return '';
                    const map = {
                        exacta_recepcion: '<span class="badge bg-success">Exacta (recepción)</span>',
                        exacta_litros: '<span class="badge bg-success">Exacta (litros)</span>',
                        aproximada_litros: '<span class="badge bg-warning text-dark">Aproximada (litros)</span>',
                    };
                    return map[data] || `<span class="badge bg-secondary">${data}</span>`;
                }
            },
            {
                data: 'en_controlgas',
                render: function (data) {
                    if (data === null) return '<span class="text-muted">Desconocido</span>';
                    return data
                        ? '<span class="badge bg-success">Sí</span>'
                        : '<span class="badge bg-light text-dark border">No</span>';
                }
            },
            {
                data: 'ya_asignada',
                render: function (data) {
                    return data
                        ? '<span class="badge bg-primary">Confirmada</span>'
                        : '<span class="badge bg-light text-dark border">Pendiente</span>';
                }
            },
            {
                data: null,
                orderable: false,
                render: function (row) {
                    if (row.ya_asignada) {
                        return `<button type="button" class="btn btn-sm btn-outline-danger btn-deshacer" data-id="${row.asignacion_id}">Deshacer</button>`;
                    }
                    if (!row.estacion || !row.factura_petrotal) return '';
                    return `<button type="button" class="btn btn-sm btn-primary btn-confirmar-una"
                        data-codgas="${row.estacion.Codigo}"
                        data-factura-proveedor="${row.factura_proveedor.Id}"
                        data-factura-petrotal="${row.factura_petrotal.Id}">Confirmar</button>`;
                }
            },
        ],
    };
}

// Pestaña "Confirmar recepción ↔ factura": el checkbox de lote solo se
// habilita para confianza "exacta_litros" (diferencia ≤ 1 L) — el caso
// "aproximada_litros" siempre se confirma fila por fila, nunca en lote, para
// que el usuario vea la diferencia antes de aprobar cada una.
function construirConfigDataTableRecepciones() {
    return {
        dom: '<"top"f>rt<"bottom"lip>',
        pageLength: 100,
        ajax: {
            url: '/supply/datatables_recepciones_proveedor',
            data: function (d) {
                d.proveedor_rfc = $('#proveedor_rfc_recepciones').val();
                d.codgas = $('#codgas_recepciones').val();
                d.fecha_desde = $('#fecha_desde_recepciones').val();
                d.fecha_hasta = $('#fecha_hasta_recepciones').val();
            },
            beforeSend: function () {
                $('#datatables_recepciones_proveedor').closest('.table-responsive').addClass('loading');
                $('#btnBuscarRecepciones').prop('disabled', true);
            },
            complete: function () {
                $('#datatables_recepciones_proveedor').closest('.table-responsive').removeClass('loading');
                $('#btnBuscarRecepciones').prop('disabled', false);
                actualizarBotonLoteRecepciones();
            },
            error: function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudieron cargar las recepciones.</p></div>');
            }
        },
        deferRender: true,
        order: [[1, 'desc']],
        columns: [
            {
                data: null,
                orderable: false,
                render: function (row) {
                    if (row.ya_asignada || !row.factura_proveedor || row.confianza !== 'exacta_litros') return '';
                    return `<input type="checkbox" class="chk-confirmar-recepcion" data-codgas="${row.codgas}" data-nrotrn="${row.nrotrn}"
                        data-factura-proveedor="${row.factura_proveedor.Id}">`;
                }
            },
            { data: 'estacion_nombre' },
            { data: 'fecha' },
            { data: 'producto' },
            { data: 'volumen', render: $.fn.dataTable.render.number(',', '.', 2) },
            {
                data: null,
                render: function (row) {
                    if (!row.en_controlgas) return '<span class="badge bg-light text-dark border">No</span>';
                    return `<span class="badge bg-success">Sí</span>${row.documento_controlgas ? `<br><small class="text-muted">${row.documento_controlgas}</small>` : ''}`;
                }
            },
            {
                data: 'factura_proveedor',
                render: function (data) {
                    if (!data) return '<span class="badge bg-warning text-dark">Sin sugerencia</span>';
                    return `${data.Folio} · $${Number(data.Total).toLocaleString('es-MX', {minimumFractionDigits: 2})}`;
                }
            },
            {
                data: 'factura_proveedor.Litros',
                render: function (data) {
                    return data ? Number(data).toLocaleString('es-MX', { minimumFractionDigits: 2 }) : '—';
                }
            },
            {
                data: null,
                render: function (row) {
                    if (!row.factura_proveedor) return '—';
                    const diff = Math.abs(Number(row.factura_proveedor.Litros) - Number(row.volumen));
                    return diff.toLocaleString('es-MX', { minimumFractionDigits: 2 }) + ' L';
                }
            },
            {
                data: 'confianza',
                render: function (data) {
                    if (!data) return '';
                    const map = {
                        exacta_remision: '<span class="badge bg-success">Exacta (remisión)</span>',
                        exacta_litros: '<span class="badge bg-success">Exacta (litros)</span>',
                        aproximada_litros: '<span class="badge bg-warning text-dark">Aproximada</span>',
                    };
                    return map[data] || `<span class="badge bg-secondary">${data}</span>`;
                }
            },
            {
                data: 'ya_asignada',
                render: function (data) {
                    return data
                        ? '<span class="badge bg-primary">Confirmada</span>'
                        : '<span class="badge bg-light text-dark border">Pendiente</span>';
                }
            },
            {
                data: null,
                orderable: false,
                render: function (row) {
                    if (row.ya_asignada) {
                        return `<button type="button" class="btn btn-sm btn-outline-danger btn-deshacer-recepcion" data-id="${row.asignacion_id}">Deshacer</button>`;
                    }
                    if (!row.factura_proveedor) return '';
                    return `<button type="button" class="btn btn-sm btn-primary btn-confirmar-recepcion"
                        data-codgas="${row.codgas}" data-nrotrn="${row.nrotrn}"
                        data-factura-proveedor="${row.factura_proveedor.Id}">Confirmar</button>`;
                }
            },
        ],
    };
}

// Corrige el ancho de columnas de la tabla activa: DataTables calcula mal
// el ancho si se inicializa dentro de un tab-pane oculto (display:none).
// Con "Buscar" ya no se inicializa nunca oculta (el usuario debe estar
// parado en la pestaña para hacer clic), pero al VOLVER a una pestaña
// después de haber estado en la otra, Bootstrap no dispara ningún resize
// nativo — sin este ajuste las columnas quedan angostas hasta la primera
// interacción manual del usuario.
$('#tabsConciliacion button[data-bs-toggle="tab"]').on('shown.bs.tab', function (e) {
    if (e.target.id === 'tab-btn-facturas-petrotal' && datatables_conciliacion) {
        datatables_conciliacion.columns.adjust();
    }
    if (e.target.id === 'tab-btn-recepciones' && datatables_recepciones_proveedor) {
        datatables_recepciones_proveedor.columns.adjust();
    }
});

function rangoEsValido() {
    if (!$('#proveedor_rfc').val()) {
        alertify.myAlert('<div class="text-danger text-center"><p>Selecciona un proveedor.</p></div>');
        return false;
    }
    const desde = $('#fecha_desde').val();
    const hasta = $('#fecha_hasta').val();
    if (!desde || !hasta) {
        alertify.myAlert('<div class="text-danger text-center"><p>Selecciona ambas fechas.</p></div>');
        return false;
    }
    if (desde > hasta) {
        alertify.myAlert('<div class="text-danger text-center"><p>La fecha "Desde" no puede ser posterior a "Hasta".</p></div>');
        return false;
    }
    return true;
}

$('#btnBuscarConciliacion').on('click', function () {
    if (!rangoEsValido()) return;
    if (datatables_conciliacion === null) {
        datatables_conciliacion = $('#datatables_conciliacion').DataTable(construirConfigDataTable());
    } else {
        datatables_conciliacion.ajax.reload();
    }
});

// El select de estación trae TODAS las opciones de todos los proveedores
// precargadas (cada <option> con data-proveedor), no vía AJAX — son pocas
// estaciones en total. Al cambiar el proveedor de esta pestaña, se
// muestran/ocultan opciones vía la API de bootstrap-select (hide/show +
// refresh). Ningún filtro llega preseleccionado: el usuario siempre elige
// proveedor y estación a mano, así que aquí solo se habilitan las opciones
// que corresponden y se resetea la estación al placeholder — nunca se
// autoselecciona una estación real.
function filtrarEstacionesPorProveedor() {
    const proveedorRfc = $('#proveedor_rfc_recepciones').selectpicker('val');
    const $select = $('#codgas_recepciones');

    $select.find('option[value]:not([value=""])').each(function () {
        $(this).prop('disabled', $(this).data('proveedor') !== proveedorRfc);
    });

    $select.selectpicker('val', '');
    $select.selectpicker('refresh');
}

$('#proveedor_rfc_recepciones').on('change', filtrarEstacionesPorProveedor);
$(function () { filtrarEstacionesPorProveedor(); });

function rangoRecepcionesEsValido() {
    if (!$('#proveedor_rfc_recepciones').val()) {
        alertify.myAlert('<div class="text-danger text-center"><p>Selecciona un proveedor.</p></div>');
        return false;
    }
    if (!$('#codgas_recepciones').val()) {
        alertify.myAlert('<div class="text-danger text-center"><p>Selecciona una estación.</p></div>');
        return false;
    }
    const desde = $('#fecha_desde_recepciones').val();
    const hasta = $('#fecha_hasta_recepciones').val();
    if (!desde || !hasta) {
        alertify.myAlert('<div class="text-danger text-center"><p>Selecciona ambas fechas.</p></div>');
        return false;
    }
    if (desde > hasta) {
        alertify.myAlert('<div class="text-danger text-center"><p>La fecha "Desde" no puede ser posterior a "Hasta".</p></div>');
        return false;
    }
    return true;
}

$('#btnBuscarRecepciones').on('click', function () {
    if (!rangoRecepcionesEsValido()) return;
    if (datatables_recepciones_proveedor === null) {
        datatables_recepciones_proveedor = $('#datatables_recepciones_proveedor').DataTable(construirConfigDataTableRecepciones());
    } else {
        datatables_recepciones_proveedor.ajax.reload();
    }
});

$('#checkAllRecepciones').on('change', function () {
    $('.chk-confirmar-recepcion').prop('checked', $(this).is(':checked'));
});

function actualizarBotonLoteRecepciones() {
    const hayExactas = $('.chk-confirmar-recepcion').length > 0;
    $('#btnConfirmarLoteRecepciones').prop('disabled', !hayExactas);
}

async function confirmarAsignaciones(pares, tabla) {
    try {
        const response = await fetch('/supply/confirmar_asignacion_petrotal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pares }),
            credentials: 'include',
        });
        const result = await response.json();
        if (result.success) {
            if (result.omitidos > 0) {
                alertify.myAlert(`<div class="text-center"><p>${result.confirmados} confirmada(s). ${result.omitidos} se omitieron por ya estar asignadas o tener datos incompletos.</p></div>`);
            }
            tabla.ajax.reload(null, false);
        } else {
            alertify.myAlert(`<div class="text-danger text-center"><p>${result.message}</p></div>`);
        }
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al confirmar la asignación.</p></div>');
    }
}

$(document).on('click', '.btn-confirmar-una', function () {
    confirmarAsignaciones([{
        codgas: $(this).data('codgas'),
        factura_proveedor_id: $(this).data('factura-proveedor'),
        factura_petrotal_id: $(this).data('factura-petrotal'),
    }], datatables_conciliacion);
});

$(document).on('click', '.btn-confirmar-recepcion', function () {
    confirmarAsignaciones([{
        codgas: $(this).data('codgas'),
        nrotrn: $(this).data('nrotrn'),
        factura_proveedor_id: $(this).data('factura-proveedor'),
    }], datatables_recepciones_proveedor);
});

$('#btnConfirmarLoteRecepciones').on('click', function () {
    const pares = $('.chk-confirmar-recepcion:checked').map(function () {
        return {
            codgas: $(this).data('codgas'),
            nrotrn: $(this).data('nrotrn'),
            factura_proveedor_id: $(this).data('factura-proveedor'),
        };
    }).get();
    if (!pares.length) return;
    confirmarAsignaciones(pares, datatables_recepciones_proveedor);
});

async function deshacerAsignacion(id, tabla) {
    try {
        const response = await fetch('/supply/deshacer_asignacion_petrotal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`,
            credentials: 'include',
        });
        const result = await response.json();
        if (result.success) {
            tabla.ajax.reload(null, false);
        } else {
            alertify.myAlert(`<div class="text-danger text-center"><p>${result.message}</p></div>`);
        }
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al deshacer la asignación.</p></div>');
    }
}

$(document).on('click', '.btn-deshacer', function () {
    deshacerAsignacion($(this).data('id'), datatables_conciliacion);
});

$(document).on('click', '.btn-deshacer-recepcion', function () {
    deshacerAsignacion($(this).data('id'), datatables_recepciones_proveedor);
});
