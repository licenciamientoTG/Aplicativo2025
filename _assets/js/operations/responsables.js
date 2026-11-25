/**
 * Responsables (views/operations/responsables.html) y su modal de alta/edición
 * (views/operations/modals/responsableModal.html, se carga por AJAX).
 */
(function ($) {
    'use strict';

    var $table = $('#responsables_table');
    if (!$table.length) { return; }

    var $modal = $('#responsableModal');
    var table = null;
    var allRows = [];

    // ---------- utilidades ----------
    function esc(v) { return $('<div>').text(v == null ? '' : String(v)).html(); }
    var fmtInt = new Intl.NumberFormat('es-MX');
    function fmtDate(iso) {
        if (!iso) { return ''; }
        var p = iso.split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
    }
    var ICON = {
        edit: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>',
        off: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="18" y1="8" x2="23" y2="13"/><line x1="23" y1="8" x2="18" y2="13"/></svg>',
        on: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>',
        trash: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
        warn: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
    };

    // ---------- filtros ----------
    function filters() {
        return {
            view: $('input[name="rsp_view"]:checked').val() || 'activos',
            station: $('#rsp_station').val() || '',
            puesto: $('#rsp_puesto').val() || ''
        };
    }
    function matchesView(r, view) {
        if (view === 'activos') { return r.activo; }
        if (view === 'inactivos') { return !r.activo; }
        if (view === 'revisar') { return r.issues.length > 0; }
        return true;
    }
    function matchesSelects(r, f) {
        return (!f.station || (r.estacion || '').trim() === f.station.trim()) && (!f.puesto || r.puesto === f.puesto);
    }

    $.fn.dataTable.ext.search.push(function (settings, searchData, index, row) {
        if (settings.nTable !== $table[0]) { return true; }
        var f = filters();
        return matchesView(row, f.view) && matchesSelects(row, f);
    });

    // Conteos de las pestañas: respetan estación y puesto, no la búsqueda de texto.
    function updateCounts() {
        var f = filters(), c = { activos: 0, inactivos: 0, todos: 0, revisar: 0 };
        allRows.forEach(function (r) {
            if (!matchesSelects(r, f)) { return; }
            c.todos++;
            if (r.activo) { c.activos++; } else { c.inactivos++; }
            if (r.issues.length) { c.revisar++; }
        });
        $('[data-count]').each(function () { $(this).text(fmtInt.format(c[$(this).data('count')])); });
        $('#rsp_review_hint').prop('hidden', f.view !== 'revisar' || c.revisar === 0);
        $('#rsp_clear').prop('hidden', !(f.station || f.puesto || $('#rsp_search').val() || f.view !== 'activos'));
    }

    function redraw() { updateCounts(); table.draw(); }

    $('input[name="rsp_view"]').on('change', redraw);
    $('#rsp_station, #rsp_puesto').on('change', redraw);
    var searchTimer = null;
    $('#rsp_search').on('input', function () {
        var v = this.value;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { table.search(v).draw(); updateCounts(); }, 200);
    });
    $('#rsp_clear').on('click', function () {
        $('#rsp_view_activos').prop('checked', true);
        $('#rsp_station').selectpicker('val', '');
        $('#rsp_puesto').val('');
        $('#rsp_search').val('');
        table.search('');
        redraw();
    });

    // ---------- tabla ----------
    function load() {
        $('#rsp_error').prop('hidden', true);
        $('.rsp-wrap').addClass('loading');
        if (table) { table.ajax.reload(null, false); }
    }

    table = $table.DataTable({
        pageLength: 50,
        order: [[1, 'asc']],
        autoWidth: false,
        deferRender: true,
        dom: 'rt<"d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2"lip>',
        buttons: [{
            extend: 'excel', title: 'Responsables', filename: 'Responsables',
            exportOptions: { columns: [0, 1, 2, 3, 4, 5, 6], orthogonal: 'export', modifier: { search: 'applied' } }
        }],
        language: {
            emptyTable: 'No hay responsables registrados.',
            zeroRecords: 'Ningún responsable coincide con los filtros. <button type="button" class="btn btn-link btn-sm p-0 align-baseline rsp-clear-inline">Quitar filtros</button>',
            info: '_START_–_END_ de _TOTAL_',
            infoEmpty: '0 responsables',
            infoFiltered: '',
            lengthMenu: 'Mostrar _MENU_',
            paginate: { first: 'Primera', last: 'Última', next: 'Siguiente', previous: 'Anterior' },
            loadingRecords: 'Cargando…'
        },
        ajax: {
            method: 'POST',
            url: '/operations/responsables',
            dataSrc: function (json) { allRows = json.data || []; updateCounts(); return allRows; },
            error: function (xhr) {
                $('.rsp-wrap').removeClass('loading');
                $('#rsp_error_text').text(xhr.status === 0
                    ? 'No hay conexión con el servidor.'
                    : 'El servidor respondió con un error (' + xhr.status + ').');
                $('#rsp_error').prop('hidden', false);
            }
        },
        rowId: function (r) { return 'rsp-' + r.cod; },
        columns: [
            { data: 'reloj' },
            { data: 'nombre', render: function (d, type, r) {
                if (type !== 'display') { return d; }
                var html = '<div class="rsp-name">' + esc(d) + '</div>';
                r.issues.forEach(function (i) { html += '<div class="rsp-issue">' + ICON.warn + esc(i) + '</div>'; });
                return html;
            } },
            { data: 'puesto', render: function (d, type) { return type === 'display' ? esc(d) : d; } },
            { data: 'estacion', render: function (d, type, r) {
                var v = d || ('Estación ' + r.codgas + ' (no existe)');
                return type === 'display' ? (d ? esc(d) : '<span class="text-danger">' + esc(v) + '</span>') : v;
            } },
            { data: 'activo', render: function (d, type) {
                if (type === 'display') {
                    return d ? '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Activo</span>'
                             : '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Inactivo</span>';
                }
                return d ? 'Activo' : 'Inactivo';
            } },
            { data: 'alta', render: function (d, type) {
                if (type === 'sort' || type === 'type') { return d || ''; }
                return d ? fmtDate(d) : (type === 'display' ? '<span class="rsp-muted">—</span>' : '');
            } },
            { data: 'cod', className: 'rsp-muted' },
            { data: null, orderable: false, searchable: false, className: 'rsp-actions', render: function (d, type, r) {
                if (type !== 'display') { return ''; }
                var name = esc(r.nombre);
                return '<button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#responsableModal" data-id="' + r.cod + '" title="Editar" aria-label="Editar a ' + name + '">' + ICON.edit + '</button> ' +
                    (r.activo
                        ? '<button type="button" class="btn btn-outline-secondary btn-sm rsp-toggle" data-cod="' + r.cod + '" title="Desactivar" aria-label="Desactivar a ' + name + '">' + ICON.off + '</button> '
                        : '<button type="button" class="btn btn-outline-success btn-sm rsp-toggle" data-cod="' + r.cod + '" title="Activar" aria-label="Activar a ' + name + '">' + ICON.on + '</button> ') +
                    '<button type="button" class="btn btn-outline-danger btn-sm rsp-delete" data-cod="' + r.cod + '" title="Eliminar" aria-label="Eliminar a ' + name + '">' + ICON.trash + '</button>';
            } }
        ],
        createdRow: function (row, r) { if (!r.activo) { $(row).addClass('rsp-inactive'); } }
    });

    $table.on('preXhr.dt', function () { $('.rsp-wrap').addClass('loading'); });
    $table.on('xhr.dt', function (e, s, json) {
        $('.rsp-wrap').removeClass('loading');
        if (json) { $('#rsp_error').prop('hidden', true); }
    });
    $table.on('click', '.rsp-clear-inline', function () { $('#rsp_clear').trigger('click'); });

    $('#rsp_refresh, #rsp_retry').on('click', load);
    $('#rsp_excel').on('click', function () { table.button(0).trigger(); });

    function rowOf(cod) { return table.row('#rsp-' + cod); }

    // ---------- activar / desactivar ----------
    $table.on('click', '.rsp-toggle', function () {
        var $btn = $(this), cod = $btn.data('cod'), row = rowOf(cod), r = row.data();
        $btn.prop('disabled', true);
        $.ajax({ url: '/operations/deactivate_responsable/' + cod + '/' + (r.activo ? 1 : 0), method: 'POST', dataType: 'json' })
            .done(function (res) {
                if (res.status === 'success') {
                    r.activo = !!res.activo;
                    row.data(r).invalidate();
                    $(row.node()).toggleClass('rsp-inactive', !r.activo);
                    redraw();
                    toastr.success(res.message, r.nombre, { timeOut: 3500 });
                } else {
                    toastr.error(res.message, 'No se guardó');
                    $btn.prop('disabled', false);
                }
            })
            .fail(function () { toastr.error('No hay respuesta del servidor. Intente de nuevo.', 'No se guardó'); $btn.prop('disabled', false); });
    });

    // ---------- eliminar ----------
    $table.on('click', '.rsp-delete', function () {
        var cod = $(this).data('cod'), r = rowOf(cod).data();
        alertify.confirm('Eliminar responsable',
            '<p class="mb-2">Se eliminará a <strong>' + esc(r.reloj + ' ' + r.nombre) + '</strong> (código ' + cod + ') de SG12 y de todas las estaciones. No se puede deshacer.</p>' +
            '<p class="mb-0 text-muted small">Si solo dejó de trabajar, mejor desactívelo: se conserva su historial.</p>',
            function () {
                $('.rsp-wrap').addClass('loading');
                $.ajax({ url: '/operations/delete_responsable/' + cod, method: 'POST', dataType: 'json' })
                    .done(function (res) {
                        if (res.status === 'success') {
                            toastr.success(res.message, r.nombre, { timeOut: 3500 });
                            load();
                        } else {
                            $('.rsp-wrap').removeClass('loading');
                            toastr.error(res.message, 'No se eliminó');
                        }
                    })
                    .fail(function () { $('.rsp-wrap').removeClass('loading'); toastr.error('No hay respuesta del servidor.', 'No se eliminó'); });
            }, function () {}
        ).set('labels', { ok: 'Eliminar', cancel: 'Cancelar' });
    });

    // ---------- modal de alta / edición ----------
    function loadModal(cod) {
        $modal.find('.modal-dialog').removeClass('modal-xl modal-lg modal-dialog-centered');
        $modal.find('#responsableModalLabel').text(cod ? 'Editar responsable' : 'Alta de responsable');
        $modal.find('.content').html('<div class="p-5 text-center text-muted"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Cargando…</div>');
        $.ajax({ url: '/operations/responsableModal/' + cod, method: 'GET', dataType: 'json' })
            .done(function (data) {
                $modal.find('.modal-dialog').addClass(data.size + ' ' + data.position);
                $modal.find('#responsableModalLabel').text(data.title);
                $modal.find('.content').html(data.content);
                $modal.find('.selectpicker').selectpicker();
                previewName();
                checkClock();
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'No se pudo cargar el formulario. Intente de nuevo.';
                $modal.find('.content').html($('<div class="alert alert-danger m-3" role="alert"></div>').text(msg));
            });
    }
    $modal.on('show.bs.modal', function (e) {
        if (!e.relatedTarget) { return; }
        loadModal(parseInt($(e.relatedTarget).data('id'), 10) || 0);
    });
    $modal.on('shown.bs.modal', function () { $('#resp_name').trigger('focus'); });

    // Misma regla que ResponsablesModel::formatear_nombre(): minúsculas salvo iniciales.
    function nombreFormateado(v) {
        return v.trim().replace(/\s+/g, ' ').toLocaleLowerCase('es-MX')
            .replace(/(^|\s)(\S)/g, function (m, s, c) { return s + c.toLocaleUpperCase('es-MX'); });
    }
    function previewName() {
        var v = nombreFormateado($('#resp_name').val() || ''), reloj = $('#resp_clock').val();
        $('#resp_name_preview').text(v ? 'Se guardará como: ' + (reloj ? reloj + ' ' : '') + v : 'Se guarda con mayúscula inicial en cada palabra.');
    }
    $modal.on('input', '#resp_name, #resp_clock', previewName);

    // Reloj: otros registros con el mismo número y el código que se asignaría.
    var clockTimer = null, clockXhr = null;
    function checkClock() {
        var reloj = parseInt($('#resp_clock').val(), 10) || 0;
        var codgas = $('#resp_station').val();
        var cod = parseInt($('#responsableForm [name="cod"]').val(), 10) || 0;
        var $info = $('#resp_clock_info'), $code = $('#resp_code_info');
        if (clockXhr) { clockXhr.abort(); }
        if (!reloj) {
            $info.empty(); $('#resp_clock_info_col').prop('hidden', true);
            if (!cod) { $code.text('Se asigna al guardar: el número de reloj si está libre, si no el siguiente disponible.'); }
            return;
        }
        clockXhr = $.getJSON('/operations/responsable_reloj/' + reloj + '/' + (codgas === '' || codgas == null ? 'x' : codgas) + '/' + cod)
            .done(function (res) {
                if (!cod && res.codigo) {
                    $code.html(res.codigo === reloj
                        ? 'Se asignará el <strong>' + res.codigo + '</strong> (igual al reloj).'
                        : 'El ' + reloj + ' ya está ocupado como código; se asignará el <strong>' + res.codigo + '</strong>.');
                } else if (!cod) {
                    $code.text('Elija la estación para calcular el código.');
                }
                var same = res.matches.filter(function (m) { return m.activo && String(m.codgas) === String(codgas); });
                if (!res.matches.length) {
                    $info.removeClass('alert-warning alert-info').empty(); $('#resp_clock_info_col').prop('hidden', true);
                    return;
                }
                var html = same.length
                    ? '<strong>Ya hay un responsable activo con este reloj en esta estación.</strong> Si es la misma persona, edite ese registro en vez de crear otro.'
                    : '<strong>Este reloj también está registrado en:</strong>';
                html += '<ul class="mb-0 mt-1 ps-3">';
                res.matches.slice(0, 5).forEach(function (m) {
                    html += '<li>' + esc(m.nombre) + ' · ' + esc(m.estacion) + ' · ' + (m.activo ? 'Activo' : 'Inactivo') +
                        ' <button type="button" class="btn btn-link btn-sm p-0 align-baseline rsp-open" data-cod="' + m.cod + '">Abrir (código ' + m.cod + ')</button></li>';
                });
                if (res.matches.length > 5) { html += '<li>y ' + (res.matches.length - 5) + ' más…</li>'; }
                html += '</ul>';
                $info.toggleClass('alert-warning', same.length > 0).toggleClass('alert-info', !same.length)
                    .html('<div class="alert-message">' + html + '</div>'); $('#resp_clock_info_col').prop('hidden', false);
            });
    }
    $modal.on('input', '#resp_clock', function () { clearTimeout(clockTimer); clockTimer = setTimeout(checkClock, 350); });
    $modal.on('changed.bs.select change', '#resp_station', function () {
        $('#resp_station_error').hide();
        checkClock();
    });
    $modal.on('click', '.rsp-open', function () { loadModal(parseInt($(this).data('cod'), 10)); });

    // Guardar sin recargar la página.
    $modal.on('submit', '#responsableForm', function (e) {
        e.preventDefault();
        var form = this, $form = $(form);
        var stationOk = !!$('#resp_station').val();
        $('#resp_station_error').toggle(!stationOk);
        if (!form.checkValidity() || !stationOk) {
            $form.addClass('was-validated');
            $form.find(':invalid').first().trigger('focus');
            return;
        }
        $('#resp_name').val(nombreFormateado($('#resp_name').val()));
        var $btn = $('#resp_submit'), label = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span> Guardando…');
        $('#resp_form_error').prop('hidden', true);

        $.ajax({ url: $form.attr('action'), method: 'POST', data: $form.serialize(), dataType: 'json' })
            .done(function (res) {
                bootstrap.Modal.getOrCreateInstance($modal[0]).hide();
                toastr.success(res.message, 'Guardado', { timeOut: 4000 });
                load();
            })
            .fail(function (xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'No hay respuesta del servidor. Intente de nuevo.';
                $('#resp_form_error').empty().append($('<div class="alert-message"></div>').text(msg)).prop('hidden', false);
                $btn.prop('disabled', false).html(label);
            });
    });
})(jQuery);
