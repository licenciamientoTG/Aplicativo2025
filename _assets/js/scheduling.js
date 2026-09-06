function formatearFilaGrupo(fila) {
    return `
        <tr data-id="${fila.id}">
            <td>${fila.hora || '<span class="text-muted">—</span>'}</td>
            <td>${fila.product}${fila.mezcla ? ' (' + fila.mezcla + ')' : ''}</td>
            <td>${Number(fila.litros).toLocaleString('es-MX')}</td>
            <td>${fila.station_nombre || '<span class="text-muted">—</span>'}</td>
            <td>${fila.carrier_nombre || '<span class="text-muted">—</span>'}</td>
            <td>${fila.referencia || ''}</td>
            <td>${fila.notas || ''}</td>
            <td>
                <button type="button" class="btn btn-sm btn-outline-primary btn-editar-recepcion" data-id="${fila.id}">Editar</button>
                <button type="button" class="btn btn-sm btn-outline-danger btn-cancelar-recepcion" data-id="${fila.id}">Cancelar</button>
            </td>
        </tr>
    `;
}

function renderGrupos(filas) {
    const contenedor = $('#contenedorGrupos');
    contenedor.empty();

    if (!filas.length) {
        contenedor.html('<p class="text-muted text-center">Sin recepciones programadas para este día.</p>');
        $('#totalLitrosDia').text('0');
        return;
    }

    let totalLitros = 0;
    const grupos = {};
    filas.forEach(function (fila) {
        totalLitros += Number(fila.litros) || 0;
        const clave = (fila.supplier_nombre || 'Sin proveedor') + ' — ' + (fila.terminal_nombre || 'Sin terminal');
        if (!grupos[clave]) grupos[clave] = [];
        grupos[clave].push(fila);
    });

    Object.keys(grupos).sort().forEach(function (clave) {
        const filasGrupo = grupos[clave];
        const tabla = `
            <div class="mb-4">
                <h6>${clave}</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Hora</th><th>Producto</th><th>Litros</th><th>Estación</th>
                                <th>Transportista</th><th>Referencia</th><th>Notas</th><th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>${filasGrupo.map(formatearFilaGrupo).join('')}</tbody>
                    </table>
                </div>
            </div>
        `;
        contenedor.append(tabla);
    });

    $('#totalLitrosDia').text(totalLitros.toLocaleString('es-MX'));
}

function cargarDia(fecha) {
    $('#contenedorGrupos').html('<p class="text-muted text-center">Cargando…</p>');
    $.get('/supply/scheduling_day_data', { fecha: fecha })
        .done(function (resp) {
            renderGrupos(resp.data || []);
        })
        .fail(function () {
            $('#contenedorGrupos').html('<p class="text-danger text-center">No se pudo cargar la programación.</p>');
        });
}

function abrirModal(id, fecha) {
    $.post('/supply/scheduling_modal', { id: id || '', fecha: fecha })
        .done(function (resp) {
            if (!resp.success) {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo abrir el formulario.</p></div>');
                return;
            }
            $('#modalProgramacionContent').html(resp.html);
            $('.selectpicker').selectpicker();
            $('#product').on('change', function () {
                $('#mezcla_wrapper').toggle($(this).val() === 'Mixta');
            });
            const modal = new bootstrap.Modal(document.getElementById('modalProgramacion'));
            modal.show();
        })
        .fail(function () {
            alertify.myAlert('<div class="text-danger text-center"><p>No se pudo abrir el formulario.</p></div>');
        });
}

$(document).ready(function () {
    const fechaInput = $('#fecha_programacion');

    cargarDia(fechaInput.val());

    fechaInput.on('change', function () {
        cargarDia($(this).val());
    });

    $('#btnDiaAnterior').on('click', function () {
        const fecha = new Date(fechaInput.val() + 'T00:00:00');
        fecha.setDate(fecha.getDate() - 1);
        fechaInput.val(fecha.toISOString().slice(0, 10)).trigger('change');
    });

    $('#btnDiaSiguiente').on('click', function () {
        const fecha = new Date(fechaInput.val() + 'T00:00:00');
        fecha.setDate(fecha.getDate() + 1);
        fechaInput.val(fecha.toISOString().slice(0, 10)).trigger('change');
    });

    $('#btnAgregarRecepcion').on('click', function () {
        abrirModal(null, fechaInput.val());
    });

    $(document).on('click', '.btn-editar-recepcion', function () {
        abrirModal($(this).data('id'), fechaInput.val());
    });

    $(document).on('click', '.btn-cancelar-recepcion', function () {
        const id = $(this).data('id');
        if (!confirm('¿Cancelar esta recepción programada?')) return;
        $.post('/supply/scheduling_cancel', { id: id })
            .done(function () { cargarDia(fechaInput.val()); })
            .fail(function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo cancelar.</p></div>');
            });
    });

    $(document).on('submit', '#frmProgramacion', function (e) {
        e.preventDefault();
        const datos = $(this).serialize();
        const id = $('#id').val();
        const url = id ? '/supply/scheduling_update' : '/supply/scheduling_add';
        $.post(url, datos)
            .done(function (resp) {
                if (!resp.success) {
                    alertify.myAlert('<div class="text-danger text-center"><p>No se pudo guardar.</p></div>');
                    return;
                }
                bootstrap.Modal.getInstance(document.getElementById('modalProgramacion')).hide();
                cargarDia(fechaInput.val());
            })
            .fail(function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo guardar.</p></div>');
            });
    });

    $(document).on('click', '#btnNuevaTerminal', function () {
        const nombre = prompt('Nombre de la nueva terminal/base de carga:');
        if (!nombre) return;
        $.post('/supply/scheduling_add_terminal', { nombre: nombre })
            .done(function (resp) {
                if (!resp.success) return;
                $('#terminal_id').append(`<option value="${resp.id}" selected>${resp.nombre}</option>`);
            });
    });

    $(document).on('click', '#btnNuevoTransportista', function () {
        const nombre = prompt('Nombre del nuevo transportista:');
        if (!nombre) return;
        $.post('/supply/scheduling_add_carrier', { nombre: nombre })
            .done(function (resp) {
                if (!resp.success) return;
                $('#carrier_id').append(`<option value="${resp.id}" selected>${resp.nombre}</option>`);
            });
    });
});
