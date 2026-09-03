let datatables_conciliacion = null;
let datatables_sin_recepcion = null;

function construirConfigDataTable() {
    return {
        dom: '<"top"f>rt<"bottom"lip>',
        pageLength: 100,
        ajax: {
            url: '/supply/datatables_petrotal_reconciliation',
            data: function (d) {
                d.codgas = $('#codgas_conciliacion').val();
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
                actualizarBotonLote();
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
                    if (row.ya_asignada || !row.factura_proveedor || !row.confianza || row.facturas_petrotal.length !== 1) return '';
                    return `<input type="checkbox" class="chk-confirmar" data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}"
                        data-factura-proveedor="${row.factura_proveedor.Id}" data-factura-petrotal="${row.facturas_petrotal[0].Id}">`;
                }
            },
            { data: 'fecha' },
            { data: 'producto' },
            { data: 'litros', render: $.fn.dataTable.render.number(',', '.', 2) },
            {
                data: 'factura_proveedor',
                render: function (data) {
                    if (!data) return '<span class="badge bg-warning text-dark">Sin factura aún</span>';
                    return `${data.EmisorNombre}<br><small class="text-muted">${data.Folio} · $${Number(data.Total).toLocaleString('es-MX', {minimumFractionDigits: 2})}</small>`;
                }
            },
            {
                data: 'facturas_petrotal',
                render: function (data) {
                    if (!data || !data.length) return '<span class="text-muted">—</span>';
                    return data.map(f => `${f.Folio} · $${Number(f.Total).toLocaleString('es-MX', {minimumFractionDigits: 2})}`).join('<br>');
                }
            },
            {
                data: 'confianza',
                render: function (data) {
                    if (!data) return '';
                    const map = {
                        exacta_remision: '<span class="badge bg-success">Exacta (remisión)</span>',
                        exacta_folio: '<span class="badge bg-success">Exacta (folio)</span>',
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
                        return `<button type="button" class="btn btn-sm btn-outline-danger btn-deshacer" data-id="${row.asignacion_id}">Deshacer</button>`;
                    }
                    if (!row.factura_proveedor || !row.facturas_petrotal || !row.facturas_petrotal.length) return '';

                    if (row.facturas_petrotal.length === 1) {
                        return `<button type="button" class="btn btn-sm btn-primary btn-confirmar-una"
                            data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}"
                            data-factura-proveedor="${row.factura_proveedor.Id}" data-factura-petrotal="${row.facturas_petrotal[0].Id}">
                            Confirmar</button>`;
                    }

                    const opciones = row.facturas_petrotal.map(f =>
                        `<option value="${f.Id}">${f.Folio} · $${Number(f.Total).toLocaleString('es-MX', {minimumFractionDigits: 2})}</option>`
                    ).join('');
                    return `<div class="input-group input-group-sm">
                        <select class="form-select form-select-sm select-petrotal-manual">${opciones}</select>
                        <button type="button" class="btn btn-primary btn-confirmar-select"
                            data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}"
                            data-factura-proveedor="${row.factura_proveedor.Id}">Confirmar</button>
                    </div>`;
                }
            },
        ],
    };
}

// Pestaña "Sin recepción ControlGas": no hay nrotrn real (no hay recepción
// que journalizar), y las candidatas de Petrotal vienen ordenadas por
// cercanía de litros (no hay bandera de "confianza" tipo remisión/folio,
// así que siempre se listan todas las candidatas con un selector manual —
// nunca se ofrece confirmación en lote aquí, el usuario siempre elige).
function construirConfigDataTableSinRecepcion() {
    return {
        dom: '<"top"f>rt<"bottom"lip>',
        pageLength: 100,
        ajax: {
            url: '/supply/datatables_petrotal_sin_recepcion',
            data: function (d) {
                d.codgas = $('#codgas_conciliacion').val();
                d.fecha_desde = $('#fecha_desde').val();
                d.fecha_hasta = $('#fecha_hasta').val();
            },
            beforeSend: function () {
                $('#datatables_sin_recepcion').closest('.table-responsive').addClass('loading');
            },
            complete: function () {
                $('#datatables_sin_recepcion').closest('.table-responsive').removeClass('loading');
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
                    if (!data) return '';
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
                data: 'facturas_petrotal',
                render: function (data) {
                    if (!data || !data.length) return '<span class="text-muted">Sin candidatas</span>';
                    return data.map(f => `${f.Folio} · ${Number(f.Litros).toLocaleString('es-MX', {minimumFractionDigits: 2})} L · $${Number(f.Total).toLocaleString('es-MX', {minimumFractionDigits: 2})}`).join('<br>');
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
                        return `<button type="button" class="btn btn-sm btn-outline-danger btn-deshacer-sr" data-id="${row.asignacion_id}">Deshacer</button>`;
                    }
                    if (!row.factura_proveedor || !row.facturas_petrotal || !row.facturas_petrotal.length) return '';

                    const opciones = row.facturas_petrotal.map(f =>
                        `<option value="${f.Id}">${f.Folio} · ${Number(f.Litros).toLocaleString('es-MX', {minimumFractionDigits: 2})} L</option>`
                    ).join('');
                    return `<div class="input-group input-group-sm">
                        <select class="form-select form-select-sm select-petrotal-manual-sr">${opciones}</select>
                        <button type="button" class="btn btn-primary btn-confirmar-select-sr"
                            data-codgas="${row.codgas}" data-factura-proveedor="${row.factura_proveedor.Id}">Confirmar</button>
                    </div>`;
                }
            },
        ],
    };
}

function rangoEsValido() {
    if (!$('#codgas_conciliacion').val()) {
        alertify.myAlert('<div class="text-danger text-center"><p>Selecciona una estación.</p></div>');
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

function actualizarBotonLote() {
    const hayExactas = $('.chk-confirmar').length > 0;
    $('#btnConfirmarLote').prop('disabled', !hayExactas);
}

$('#btnBuscarConciliacion').on('click', function () {
    if (!rangoEsValido()) return;

    if (datatables_conciliacion === null) {
        datatables_conciliacion = $('#datatables_conciliacion').DataTable(construirConfigDataTable());
    } else {
        datatables_conciliacion.ajax.reload();
    }

    if (datatables_sin_recepcion === null) {
        datatables_sin_recepcion = $('#datatables_sin_recepcion').DataTable(construirConfigDataTableSinRecepcion());
    } else {
        datatables_sin_recepcion.ajax.reload();
    }
});

$('#checkAllExactas').on('change', function () {
    $('.chk-confirmar').prop('checked', $(this).is(':checked'));
});

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
        nrotrn: $(this).data('nrotrn'),
        codgas: $(this).data('codgas'),
        factura_proveedor_id: $(this).data('factura-proveedor'),
        factura_petrotal_id: $(this).data('factura-petrotal'),
    }], datatables_conciliacion);
});

$(document).on('click', '.btn-confirmar-select', function () {
    const facturaPetrotalId = $(this).closest('.input-group').find('.select-petrotal-manual').val();
    confirmarAsignaciones([{
        nrotrn: $(this).data('nrotrn'),
        codgas: $(this).data('codgas'),
        factura_proveedor_id: $(this).data('factura-proveedor'),
        factura_petrotal_id: facturaPetrotalId,
    }], datatables_conciliacion);
});

$('#btnConfirmarLote').on('click', function () {
    const pares = $('.chk-confirmar:checked').map(function () {
        return {
            nrotrn: $(this).data('nrotrn'),
            codgas: $(this).data('codgas'),
            factura_proveedor_id: $(this).data('factura-proveedor'),
            factura_petrotal_id: $(this).data('factura-petrotal'),
        };
    }).get();
    if (!pares.length) return;
    confirmarAsignaciones(pares, datatables_conciliacion);
});

$(document).on('click', '.btn-confirmar-select-sr', function () {
    const facturaPetrotalId = $(this).closest('.input-group').find('.select-petrotal-manual-sr').val();
    confirmarAsignaciones([{
        sin_recepcion: true,
        codgas: $(this).data('codgas'),
        factura_proveedor_id: $(this).data('factura-proveedor'),
        factura_petrotal_id: facturaPetrotalId,
    }], datatables_sin_recepcion);
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

$(document).on('click', '.btn-deshacer-sr', function () {
    deshacerAsignacion($(this).data('id'), datatables_sin_recepcion);
});
