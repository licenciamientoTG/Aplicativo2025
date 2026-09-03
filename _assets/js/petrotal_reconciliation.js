let datatables_conciliacion = null;

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

async function confirmarAsignaciones(pares) {
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
            datatables_conciliacion.ajax.reload(null, false);
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
    }]);
});

$(document).on('click', '.btn-deshacer', async function () {
    const id = $(this).data('id');
    try {
        const response = await fetch('/supply/deshacer_asignacion_petrotal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`,
            credentials: 'include',
        });
        const result = await response.json();
        if (result.success) {
            datatables_conciliacion.ajax.reload(null, false);
        } else {
            alertify.myAlert(`<div class="text-danger text-center"><p>${result.message}</p></div>`);
        }
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al deshacer la asignación.</p></div>');
    }
});
