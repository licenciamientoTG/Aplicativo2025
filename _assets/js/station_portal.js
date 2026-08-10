let datatables_mis_recepciones = null;

function construirConfigDataTable() {
    return {
        dom: '<"top"f>rt<"bottom"lip>',
        pageLength: 100,
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
        columns: [
            { data: 'fecha' },
            { data: 'hora' },
            { data: 'producto' },
            { data: 'volumen', render: $.fn.dataTable.render.number(',', '.', 2) },
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
    };
}

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
