/**
 * Reporte "Despachos facturados" (views/income/invoiced_dispatched.html).
 *
 * La tabla se crea en la primera búsqueda y después solo se recarga
 * (ajax.reload), así que los eventos se enlazan una sola vez.
 */
(function ($) {
    'use strict';

    var $form = $('#idp_form');
    var $table = $('#datatables_dispatches');
    var $wrap = $('.control_dispaches_table');
    var $button = $('#search_invoice_dispatched');
    var buttonHtml = $button.html();
    var maxDaysAll = parseInt($form.data('max-days-all'), 10) || 31;
    var table = null;
    var lastParams = null;   // filtros de la última búsqueda (los que muestra la tabla)
    var loaded = false;      // evita pintar el resumen antes de que lleguen datos

    var fmtInt = new Intl.NumberFormat('es-MX');
    var fmtQty = new Intl.NumberFormat('es-MX', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
    var fmtMoney = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

    var text = $.fn.dataTable.render.text();
    var moneyFmt = $.fn.dataTable.render.number(',', '.', 2, '$');

    // Sin partida de factura no hay impuestos: celda vacía, no $0.00.
    function money(d, type, row, meta) {
        if (d === null || d === undefined || d === '') { return type === 'display' ? '' : null; }
        // Ordenar y filtrar con el número crudo; solo la vista lleva formato.
        return type === 'display' ? moneyFmt.display(d) : parseFloat(d);
    }

    // El IVA muestra la tasa aplicada como ayuda (8% frontera / 16%).
    function iva(d, type, row, meta) {
        var out = money(d, type, row, meta);
        if (type === 'display' && out && row.tasa_iva !== null && row.tasa_iva !== undefined) {
            var pct = Math.round(parseFloat(row.tasa_iva) * 100);
            return '<span title="Tasa ' + pct + '%">' + out + '</span>';
        }
        return out;
    }

    function params() {
        return {
            from: $('#from').val(),
            until: $('#until').val(),
            codgas: $('#codgas').val() || '0',
            uuid: $('#uuid').val() || '0'
        };
    }

    function stationLabel(codgas) {
        return codgas === '0' ? 'Todas las estaciones' : $('#codgas option:selected').text().trim();
    }

    /** Devuelve el mensaje de error o null si los filtros son válidos. */
    function validate(p) {
        $('#from, #until').removeClass('is-invalid');
        if (!p.from || !p.until) {
            $(!p.from ? '#from' : '#until').addClass('is-invalid');
            return 'Indique la fecha de inicio y la fecha final.';
        }
        if (p.from > p.until) {
            $('#from, #until').addClass('is-invalid');
            return 'La fecha de inicio no puede ser posterior a la fecha final.';
        }
        var days = Math.round((new Date(p.until) - new Date(p.from)) / 86400000) + 1;
        if (p.codgas === '0' && days > maxDaysAll) {
            $('#from, #until').addClass('is-invalid');
            return 'Con todas las estaciones el rango máximo es de ' + maxDaysAll + ' días (eligió ' + days + '). Elija una estación o acorte el rango.';
        }
        return null;
    }

    function setFormError(message) {
        $('#idp_form_error').text(message || '').prop('hidden', !message);
        $form.find('.idp-hint').prop('hidden', !!message);
    }

    function setLoading(loading) {
        $wrap.toggleClass('loading', loading);
        $button.prop('disabled', loading).html(loading
            ? '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Buscando…'
            : buttonHtml);
        if (!loading && window.feather) { feather.replace(); }
    }

    function showError(message) {
        $('#idp_error_text').text(message);
        $('#idp_error').prop('hidden', false);
        $('#element_hidden, #idp_summary, #idp_legend').prop('hidden', true);
    }

    function updateSummary(api) {
        if (!loaded) { return; }
        var rows = api.rows({ search: 'applied' }).data().toArray();
        var sums = { cantidad: 0, subtotal: 0, ieps: 0, iva: 0, importe: 0 };
        var unstamped = 0;
        rows.forEach(function (r) {
            Object.keys(sums).forEach(function (k) { sums[k] += parseFloat(r[k]) || 0; });
            if (!r.UUID) { unstamped++; }  // sin UUID = no timbrado
        });
        var qty = sums.cantidad, amount = sums.importe;

        // Pie de la tabla: cada celda con data-sum muestra el total de su campo.
        $table.find('tfoot th[data-sum]').each(function () {
            var key = $(this).data('sum');
            $(this).text(key === 'cantidad' ? fmtQty.format(sums[key]) : fmtMoney.format(sums[key]));
        });

        var p = lastParams;
        var html =
            '<span>' + $('<div>').text(stationLabel(String(p.codgas)) + ' · ' + p.from + ' a ' + p.until).html() + '</span>' +
            '<span><strong>' + fmtInt.format(rows.length) + '</strong> despachos</span>' +
            '<span><strong>' + fmtQty.format(qty) + '</strong> L</span>' +
            '<span><strong>' + fmtMoney.format(amount) + '</strong></span>' +
            (unstamped ? '<span class="idp-warn"><strong>' + fmtInt.format(unstamped) + '</strong> sin timbrar</span>' : '');
        $('#idp_summary').html(html).prop('hidden', false);
        $('#idp_legend').prop('hidden', unstamped === 0);
    }

    function exportTitle() {
        var p = lastParams || params();
        return 'Despachos facturados ' + stationLabel(p.codgas) + ' ' + p.from + ' a ' + p.until;
    }

    function createTable() {
        table = $table.DataTable({
            pageLength: 100,
            orderCellsTop: true,
            deferRender: true,
            autoWidth: false,
            order: [[0, 'asc'], [1, 'asc']],
            dom: '<"d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2"Bf>rt<"d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2"lip>',
            buttons: [
                { extend: 'excel', className: 'btn btn-sm btn-outline-success', text: 'Excel', footer: true, title: exportTitle },
                { extend: 'pdf', className: 'btn btn-sm btn-outline-secondary', text: 'PDF', footer: true, title: exportTitle,
                  orientation: 'landscape', pageSize: 'LEGAL' }
            ],
            language: {
                emptyTable: 'No hay despachos facturados con estos filtros.',
                zeroRecords: 'Ningún despacho coincide con los filtros de columna.',
                info: '_START_–_END_ de _TOTAL_ despachos',
                infoEmpty: '0 despachos',
                infoFiltered: '(de _MAX_)',
                lengthMenu: 'Mostrar _MENU_',
                search: 'Buscar en todo:',
                paginate: { first: 'Primera', last: 'Última', next: 'Siguiente', previous: 'Anterior' }
            },
            ajax: {
                method: 'POST',
                url: '/income/datatables_dispatches_invoiced',
                data: function () { return lastParams; },
                dataSrc: 'data',
                error: function (xhr) {
                    setLoading(false);
                    var msg = xhr.responseJSON && xhr.responseJSON.error;
                    if (!msg) {
                        msg = xhr.status === 0
                            ? 'No hay conexión con el servidor. Revise su red e intente de nuevo.'
                            : (xhr.status === 401 || xhr.status === 403)
                                ? 'Su sesión expiró o no tiene permiso. Vuelva a iniciar sesión.'
                                : 'El servidor respondió con un error (' + xhr.status + '). Intente de nuevo; si persiste, avise a sistemas.';
                    }
                    showError(msg);
                }
            },
            columns: [
                { data: 'fecha', render: text },
                { data: 'hora_formateada', render: text },
                { data: 'turno', render: text },
                { data: 'despacho', render: text },
                { data: 'producto', render: text },
                { data: 'estacion', render: text },
                { data: 'empresa', render: text },
                { data: 'cliente_fac', render: text },
                { data: 'rfc_cfdi', className: 'idp-uuid', render: text },
                { data: 'cantidad', className: 'idp-num', render: $.fn.dataTable.render.number(',', '.', 3, '') },
                { data: 'subtotal', className: 'idp-num', render: money },
                { data: 'ieps', className: 'idp-num', render: money },
                { data: 'iva', className: 'idp-num', render: iva },
                { data: 'importe', className: 'idp-num', render: money },
                { data: 'precio', className: 'idp-num', render: money },
                { data: 'tipo_pago', render: text },
                { data: 'factura', render: text },
                { data: 'serie_folio', render: text },
                { data: 'UUID', className: 'idp-uuid', render: text },
                { data: 'UUID_sat', className: 'idp-uuid', render: text },
                { data: 'FechaTimbrado', render: text },
                { data: 'txtref', render: text },
                { data: 'rut', render: text },
                { data: 'denominacion', render: text },
                { data: 'codigo_cliente', render: text },
                { data: 'tipo_cliente', render: text }
            ],
            columnDefs: [{ targets: '_all', defaultContent: '' }],
            createdRow: function (row, data) {
                if (!data.UUID) {  // TGV2 (UUID SAT) no está al día: el criterio es el UUID del despacho o la factura
                    $(row).addClass('table-warning').attr('title', 'Sin timbrar (no tiene UUID)');
                }
            },
            drawCallback: function () {
                updateSummary(this.api());
            }
        });

        // 'xhr' llega antes del redibujado, así que el resumen ya ve los datos nuevos.
        $table.on('xhr.dt', function (e, settings, json) {
            if (json) {
                loaded = true;
                setLoading(false);
                $('#element_hidden').prop('hidden', false);
            }
        });
        $table.on('draw.dt', function () {
            if (loaded && table) { table.columns.adjust(); }
        });

        // Filtros por columna: cada input filtra la columna en la que está.
        var timer = null;
        $table.find('thead tr.idp-col-filters').on('input', 'input', function () {
            var input = this;
            clearTimeout(timer);
            timer = setTimeout(function () {
                var idx = $(input).closest('th').index();
                if (table.column(idx).search() !== input.value.trim()) {
                    table.column(idx).search(input.value.trim()).draw();
                }
            }, 250);
        });
    }

    function search() {
        var p = params();
        var error = validate(p);
        setFormError(error);
        if (error) { return; }

        // La búsqueda queda en la URL para poder recargarla o compartirla.
        if (window.history && history.replaceState) {
            history.replaceState(null, '', location.pathname + '?' + $.param(p));
        }

        lastParams = p;
        $('#no_selected').prop('hidden', true);
        $('#idp_error').prop('hidden', true);
        setLoading(true);
        if (table) {
            table.ajax.reload();
        } else {
            createTable();
        }
    }

    $form.on('submit', function (e) {
        e.preventDefault();
        search();
    });
    $('#from, #until').on('change', function () {
        $(this).removeClass('is-invalid');
        setFormError(null);
    });

    if (String($form.data('autorun')) === '1') {
        search();
    }
})(jQuery);
