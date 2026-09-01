let datatables_mis_recepciones = null;

/**
 * Agrega una fila de filtros de texto por columna en el thead del DataTable
 * (mismo patrón usado en payment.js::addColumnFilters). Soporta tablas con
 * y sin scrollX/scrollY, y conserva lo escrito al reconstruirse.
 * @param {string} tableId id de la tabla (sin '#')
 * @param {object} api     instancia DataTables API (this.api() dentro de initComplete)
 */
function addColumnFilters(tableId, api) {
    var settings = api.settings()[0];
    var usaScroll = !!(settings.oScroll.sX || settings.oScroll.sY);

    function getHeaderContainer() {
        if (usaScroll) {
            return $("#" + tableId + "_wrapper .dataTables_scrollHead thead");
        }
        return $("#" + tableId + " thead");
    }

    function rebuildFilterRow() {
        var $head = getHeaderContainer();
        if (!$head.length) return;

        var valores = {};
        $head.find("tr.filter th input").each(function () {
            valores[$(this).closest("th").data("col-idx")] = this.value;
        });

        $head.find("tr.filter").remove();

        var $headerCells = $head.find("tr:first th");
        if (!$headerCells.length) return;

        var totalCols = $headerCells.length;
        var $filterRow = $('<tr class="filter"></tr>');
        $headerCells.each(function (colIdx) {
            var title = $(this).text().trim();
            var isLast = colIdx === totalCols - 1;
            if (isLast || title === "") {
                $filterRow.append('<th data-col-idx="' + colIdx + '"></th>');
            } else {
                var $th = $('<th data-col-idx="' + colIdx + '"></th>');
                var $input = $('<input type="text" class="form-control form-control-sm" placeholder="' +
                    title + '" style="font-size:.75rem;" />');
                if (valores[colIdx] !== undefined) $input.val(valores[colIdx]);
                $th.append($input);
                $filterRow.append($th);
            }
        });
        $head.append($filterRow);

        $head.find("tr.filter th input").on("keyup change", function () {
            var colIdx = $(this).closest("th").data("col-idx");
            api.column(colIdx).search(this.value).draw();
        });

        $head.find("tr.filter th input").on("click", function (e) {
            e.stopPropagation();
        });
    }

    rebuildFilterRow();
    setTimeout(rebuildFilterRow, 50);

    if (usaScroll) {
        api.on("column-sizing.dt", function () {
            rebuildFilterRow();
        });
    }

    api.on("column-visibility.dt", function () {
        rebuildFilterRow();
    });
}

function construirConfigDataTable() {
    return {
        dom: '<"top"Bf>rt<"bottom"lip>',
        pageLength: 100,
        buttons: [
            {
                extend: 'excel',
                className: 'btn btn-success',
                text: '<i class="bi bi-file-excel"></i> Excel',
                exportOptions: { columns: ':visible:not(:last-child)' },
            },
            {
                extend: 'colvis',
                className: 'btn btn-sm btn-secondary',
                text: '<i class="bi bi-eye"></i> Columnas',
                columns: ':not(:last-child)',
            },
            {
                text: '<i class="bi bi-exclamation-triangle"></i> Sin documento',
                className: 'btn btn-outline-warning',
                action: function (e, dt, node) {
                    filtroSinDocumentoActivo = !filtroSinDocumentoActivo;
                    node.toggleClass('btn-outline-warning btn-warning');
                    dt.draw();
                },
            },
        ],
        ajax: {
            url: '/station_portal/datatables_recepciones',
            data: function (d) {
                d.fecha_desde = $('#fecha_desde').val();
                d.fecha_hasta = $('#fecha_hasta').val();
                const codgasSelect = $('#codgas_recepciones');
                if (codgasSelect.length) {
                    d.codgas = codgasSelect.val();
                }
            },
            beforeSend: function () {
                $('#datatables_mis_recepciones').closest('.table-responsive').addClass('loading');
                $('#btnBuscarRecepciones').prop('disabled', true);
            },
            complete: function () {
                $('#datatables_mis_recepciones').closest('.table-responsive').removeClass('loading');
                $('#btnBuscarRecepciones').prop('disabled', false);
            },
            error: function() {
                alertify.myAlert(
                    `<div class="container text-center text-danger">
                        <h4 class="mt-2 text-danger">¡Error!</h4>
                    </div>
                    <div class="text-dark">
                        <p class="text-center">No se pudieron cargar las recepciones. Intente nuevamente.</p>
                    </div>`
                );
            }
        },
        deferRender: true,
        order: [[0, 'desc'], [1, 'desc']],
        columns: [
            { data: 'fecha' },
            { data: 'hora' },
            { data: 'tanque' },
            { data: 'producto' },
            { data: 'volumen', render: $.fn.dataTable.render.number(',', '.', 2) },
            { data: 'documento', defaultContent: '' },
            { data: 'referencia', defaultContent: '' },
            {
                data: 'total_remisiones',
                render: function (data) {
                    return data > 0
                        ? `<span class="badge bg-success">${data} subida(s)</span>`
                        : `<span class="badge bg-warning text-dark">Sin remisión</span>`;
                }
            },
            {
                data: null,
                render: function (row) {
                    let html = `<button type="button" class="btn btn-sm btn-primary btn-subir-remision" data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}" data-fchtrn="${row.fchtrn}" data-fecha="${row.fecha}">Subir</button> `;
                    if (row.total_remisiones > 0) {
                        html += `<button type="button" class="btn btn-sm btn-secondary btn-ver-remisiones" data-nrotrn="${row.nrotrn}" data-codgas="${row.codgas}" data-fchtrn="${row.fchtrn}">Ver</button>`;
                    }
                    return html;
                }
            },
        ],
        initComplete: function () {
            addColumnFilters('datatables_mis_recepciones', this.api());
        },
    };
}

// Filtro "Sin documento": togglea vía el botón nativo de DataTables (junto a
// Excel/Columnas). El estado vive en esta variable, no en una clase del DOM,
// porque el botón se re-renderiza en cada init de la tabla.
let filtroSinDocumentoActivo = false;

// Solo afecta datatables_mis_recepciones y solo mientras el filtro está activo.
$.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
    if (settings.nTable.id !== 'datatables_mis_recepciones') return true;
    if (!filtroSinDocumentoActivo) return true;

    const rowData = settings.aoData[dataIndex] ? settings.aoData[dataIndex]._aData : null;
    return rowData ? !rowData.documento : true;
});

function rangoEsValido() {
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

$('#btnBuscarRecepciones').on('click', function () {
    if (!rangoEsValido()) {
        return;
    }

    if (datatables_mis_recepciones === null) {
        datatables_mis_recepciones = $('#datatables_mis_recepciones').DataTable(construirConfigDataTable());
    } else {
        datatables_mis_recepciones.ajax.reload();
    }

    // El resumen "Sin documento" solo se recalcula si ya estaba abierto
    // (nunca se dispara solo). Si está cerrado, cargarResumenSinDocumento()
    // se llamará solo cuando el usuario lo abra (evento shown.bs.collapse).
    if ($('#collapseResumenSinDocumento').hasClass('show')) {
        cargarResumenSinDocumento();
    }
});

// --- Resumen "Sin documento" (solo visible con permiso de todas las
// estaciones, card colapsado por defecto en la vista) ---
// Se calcula únicamente al abrir el panel — nunca en la carga de la página ni
// como efecto de "Buscar" mientras está cerrado — para no generar tráfico
// extra a quien no lo abre. "(TODAS)" implica que el backend recorre las
// 38 estaciones, así que puede tardar más que la tabla normal.
const $collapseResumen = $('#collapseResumenSinDocumento');

if ($collapseResumen.length) {
    $collapseResumen.on('shown.bs.collapse', function () {
        $('#iconResumenSinDocumento').attr('data-feather', 'chevron-down');
        if (typeof feather !== 'undefined') feather.replace();
        cargarResumenSinDocumento();
    });

    $collapseResumen.on('hidden.bs.collapse', function () {
        $('#iconResumenSinDocumento').attr('data-feather', 'chevron-right');
        if (typeof feather !== 'undefined') feather.replace();
    });
}

// Días transcurridos entre una fecha (YYYY-MM-DD) y hoy, calculado en el
// cliente para no depender del timezone del servidor. 0 = hoy mismo.
function diasDesde(fechaStr) {
    if (!fechaStr) return null;
    const hoy = new Date();
    const fecha = new Date(fechaStr + 'T00:00:00');
    const diffMs = new Date(hoy.getFullYear(), hoy.getMonth(), hoy.getDate()) - fecha;
    return Math.round(diffMs / 86400000);
}

function formatoVolumen(v) {
    return Number(v || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function cargarResumenSinDocumento() {
    if (!rangoEsValido()) {
        return;
    }

    const $body = $('#resumenSinDocumentoBody');
    const $badge = $('#badgeResumenSinDocumento');
    $body.html('<div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm me-2"></div>Calculando (puede tardar unos segundos con "TODAS")...</div>');

    const params = {
        fecha_desde: $('#fecha_desde').val(),
        fecha_hasta: $('#fecha_hasta').val(),
    };
    const codgasSelect = $('#codgas_recepciones');
    if (codgasSelect.length) {
        params.codgas = codgasSelect.val();
    }

    $.get('/station_portal/resumen_sin_documento', params)
        .done(function (resp) {
            if (resp.error) {
                $badge.html('');
                $body.html(`
                    <div class="text-center text-danger py-2">
                        <i data-feather="alert-circle"></i>
                        <p class="mb-2">${resp.error}</p>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btnReintentarResumen">Reintentar</button>
                    </div>
                `);
                if (typeof feather !== 'undefined') feather.replace();
                return;
            }

            if (!resp.total) {
                $badge.html('<span class="badge bg-success">0</span>');
                $body.html('<p class="text-success mb-0"><i data-feather="check-circle" class="align-middle"></i> Todas las recepciones del rango tienen documento.</p>');
                if (typeof feather !== 'undefined') feather.replace();
                return;
            }

            $badge.html(`<span class="badge bg-warning text-dark">${resp.total}</span>`);

            const diasMasAntiguo = resp.por_estacion.reduce((max, f) => {
                const d = diasDesde(f.fecha_mas_antigua);
                return d !== null && d > max ? d : max;
            }, 0);

            let html = '<div class="row g-3 mb-3">';
            html += statTile('alert-triangle', 'text-warning', resp.total, 'Recepciones sin documento');
            html += statTile('droplet', 'text-primary', formatoVolumen(resp.volumen_total) + ' L', 'Volumen involucrado');
            if (diasMasAntiguo > 0) {
                html += statTile('clock', diasMasAntiguo >= 7 ? 'text-danger' : 'text-warning', diasMasAntiguo + ' día(s)', 'Más antigua sin documento');
            }
            if (resp.total_estaciones_consultadas > 1) {
                html += statTile('map-pin', 'text-secondary', resp.por_estacion.length + ' / ' + resp.total_estaciones_consultadas, 'Estaciones con pendientes');
            }
            html += '</div>';

            // por_estacion solo trae más de una fila cuando el pedido fue
            // "(TODAS)"; con una sola estación seleccionada siempre hay 0 o 1,
            // así que la tabla de desglose no aporta nada y se omite.
            if (resp.por_estacion && resp.por_estacion.length > 1) {
                html += '<div class="table-responsive"><table class="table table-sm table-striped table-hover mb-0" id="tablaResumenPorEstacion"><thead><tr>' +
                    '<th>Estación</th><th class="text-end">Sin documento</th><th class="text-end">Volumen (L)</th><th class="text-end">Antigüedad</th></tr></thead><tbody>';
                resp.por_estacion.forEach(function (fila) {
                    const dias = diasDesde(fila.fecha_mas_antigua);
                    const badgeDias = dias === null ? '-' : `<span class="badge ${dias >= 7 ? 'bg-danger' : (dias >= 3 ? 'bg-warning text-dark' : 'bg-light text-dark')}">${dias} día(s)</span>`;
                    html += `<tr class="resumen-fila-estacion" style="cursor:pointer;" data-codgas="${fila.codgas}" title="Filtrar la tabla por esta estación">` +
                        `<td>${fila.nombre}</td><td class="text-end">${fila.total}</td><td class="text-end">${formatoVolumen(fila.volumen)}</td><td class="text-end">${badgeDias}</td></tr>`;
                });
                html += '</tbody></table></div>';
            }

            if (resp.fallidas && resp.fallidas.length) {
                html += `<p class="text-warning small mt-2 mb-0"><i data-feather="alert-triangle" class="align-middle"></i> No se pudo consultar ${resp.fallidas.length} estación(es); el resumen puede estar incompleto.</p>`;
            }

            $body.html(html);
            if (typeof feather !== 'undefined') feather.replace();
        })
        .fail(function () {
            $badge.html('');
            $body.html(`
                <div class="text-center text-danger py-2">
                    <i data-feather="wifi-off"></i>
                    <p class="mb-2">Error al calcular el resumen.</p>
                    <button type="button" class="btn btn-sm btn-outline-danger" id="btnReintentarResumen">Reintentar</button>
                </div>
            `);
            if (typeof feather !== 'undefined') feather.replace();
        });
}

function statTile(icon, colorClass, valor, etiqueta) {
    return `
        <div class="col-6 col-md-3">
            <div class="border rounded p-2 text-center h-100">
                <i data-feather="${icon}" class="${colorClass}"></i>
                <div class="fs-5 fw-bold">${valor}</div>
                <div class="text-muted small">${etiqueta}</div>
            </div>
        </div>
    `;
}

$(document).on('click', '#btnReintentarResumen', function () {
    cargarResumenSinDocumento();
});

// Click en una fila de estación del resumen: filtra la tabla principal por
// esa estación y refresca. Solo aplica cuando el resumen fue "(TODAS)".
$(document).on('click', '.resumen-fila-estacion', function () {
    const codgas = $(this).data('codgas');
    const $select = $('#codgas_recepciones');
    if ($select.length) {
        $select.val(String(codgas));
        $select.selectpicker('refresh');
    }
    $('#btnBuscarRecepciones').trigger('click');
});

$(document).on('click', '.btn-subir-remision', function () {
    // reset() primero: limpia el input de archivo y también pondría en blanco
    // los hidden inputs, así que los valores se asignan después.
    $('#formSubirRemision')[0].reset();
    $('#subir_nrotrn').val($(this).data('nrotrn'));
    $('#subir_codgas').val($(this).data('codgas'));
    $('#subir_fchtrn').val($(this).data('fchtrn'));
    $('#subir_fecha').val($(this).data('fecha'));
    $('#modalSubirRemision').modal('show');
});

$('#btnConfirmarSubirRemision').on('click', async function () {
    const formData = new FormData($('#formSubirRemision')[0]);

    try {
        const response = await fetch('/station_portal/upload_remision', {
            method: 'POST',
            body: formData,
            credentials: 'include',
        });
        const result = await response.json();

        if (result.success) {
            $('#modalSubirRemision').modal('hide');
            datatables_mis_recepciones.ajax.reload(null, false);
        } else {
            alertify.myAlert(`<div class="text-danger text-center"><p>${result.message}</p></div>`);
        }
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al subir el archivo.</p></div>');
    }
});

$(document).on('click', '.btn-ver-remisiones', async function () {
    const nrotrn = $(this).data('nrotrn');
    const codgas = $(this).data('codgas');
    const fchtrn = $(this).data('fchtrn');

    try {
        const response = await fetch(`/station_portal/remisiones_by_recepcion?nrotrn=${nrotrn}&codgas=${codgas}&fchtrn=${fchtrn}`, {
            method: 'GET',
            credentials: 'include',
        });
        const content = await response.text();
        $('#modalVerRemisionesContent').html(content);
        $('#modalVerRemisiones').modal('show');
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al cargar las remisiones.</p></div>');
    }
});

$(document).on('click', '.btn-eliminar-remision', async function () {
    const id = $(this).data('id');

    try {
        const response = await fetch('/station_portal/delete_remision', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`,
            credentials: 'include',
        });
        const result = await response.json();

        if (result.success) {
            $(this).closest('.remision-row').remove();
            datatables_mis_recepciones.ajax.reload(null, false);
        } else {
            alertify.myAlert(`<div class="text-danger text-center"><p>${result.message}</p></div>`);
        }
    } catch (error) {
        alertify.myAlert('<div class="text-danger text-center"><p>Error al eliminar la remisión.</p></div>');
    }
});
