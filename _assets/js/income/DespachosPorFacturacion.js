(function ($) {
    'use strict';

    var table;
    var filterTimer;
    var $form = $('#billing-dispatches-form');
    var $wrap = $('#billing-dispatches-table-wrap');
    var $status = $('#billing-dispatches-status');
    var $validation = $('#billing-dispatches-validation');
    var $stationFeedback = $('#billing-dispatches-station-feedback');
    var $scopeNote = $('#billing-query-scope');
    var $tableTitle = $('#billing-dispatches-table-title');
    var $exportNote = $('#billing-dispatches-export-note');
    var filterLabels = $('#billing-dispatches-table thead .billing-dispatches-filter-row th').map(function () { return $(this).text(); }).get();
    var responseMessage = '';
    var columns = [
        'fecha', 'hora_formateada', 'turno', 'despacho', 'producto', 'estacion', 'empresa', 'cliente_fac',
        'cantidad', 'importe', 'precio', 'despachador', 'tipo_pago', 'factura', 'FechaFactura', 'UUID',
        'txtref', 'rut', 'denominacion', 'codigo_cliente', 'tipo_cliente', 'tipo_cliente_aplicativo', 'vehiculo', 'placas'
    ];

    function currentMonthRange() {
        var now = new Date();
        var first = new Date(now.getFullYear(), now.getMonth(), 1);
        var last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        return [formatDate(first), formatDate(last)];
    }

    function formatDate(date) {
        return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
    }

    function filters() {
        return {
            from: $('#billing-from').val(), until: $('#billing-until').val(), codgas: $('#billing-codgas').val(),
            dispatch_from: $('#billing-dispatch-from').val(), dispatch_until: $('#billing-dispatch-until').val(),
            uuid: $('#billing-uuid').val(), modo_consulta: $('#billing-query-mode').val()
        };
    }

    function isStationMode() {
        return $('#billing-query-mode').val() === 'estaciones';
    }

    function updateScopeCopy() {
        if (isStationMode()) {
            $scopeNote.text('Conecta directamente con el servidor de la estación seleccionada o de todas las estaciones. Consultar todas las estaciones puede tardar más.');
            $tableTitle.text('Control de despachos por estaciones');
            $exportNote.text($('#billing-codgas').val() === '0'
                ? 'CSV y Excel incluyen todos los resultados filtrados agrupados por estación; el orden no es el global de la tabla. PDF página actual sólo incluye las filas visibles.'
                : 'Excel incluye todos los resultados filtrados; PDF página actual sólo incluye las filas visibles.');
            return;
        }

        $scopeNote.text('Consulta los datos corporativos. Puede elegir una estación o consultar todas las estaciones.');
        $tableTitle.text('Control de despachos corporativo');
        $exportNote.text('Excel incluye todos los resultados filtrados; PDF página actual sólo incluye las filas visibles.');
    }

    function loadingMessage() {
        if (!isStationMode()) { return 'Consultando despachos…'; }
        return $('#billing-codgas').val() === '0'
            ? 'Conectando directamente con todas las estaciones; esta consulta puede tardar más…'
            : 'Conectando directamente con la estación seleccionada…';
    }

    function setLoading(loading, message) {
        $wrap.toggleClass('loading', loading);
        $status.text(message || (loading ? 'Consultando despachos…' : 'Consulta actualizada.'));
    }

    function showValidation(message) {
        $validation.text(message).prop('hidden', !message);
    }

    function stationErrorsMessage(response) {
        if (!response || !response.stationErrors) { return ''; }

        var raw = response.stationErrors;
        var items = $.isArray(raw) ? raw : (raw.stations || raw.errors || raw.items || []);
        var labels = $.map(items, function (item) {
            if (typeof item === 'string' || typeof item === 'number') { return String(item); }
            if (!item || typeof item !== 'object') { return null; }
            return item.label || item.station || item.abr || item.name || null;
        });
        var count = raw && typeof raw === 'object' && !$.isArray(raw) ? parseInt(raw.count, 10) : NaN;
        count = isNaN(count) ? labels.length : count;

        if (!count) { return ''; }
        var summary = 'No fue posible consultar ' + count + ' ' + (count === 1 ? 'estación' : 'estaciones') + '.';
        if (labels.length) { summary += ' Estaciones: ' + labels.slice(0, 5).join(', ') + (labels.length > 5 ? '…' : '') + '.'; }
        return summary;
    }

    function responseFeedbackMessage(response) {
        var stationMessage = stationErrorsMessage(response);
        var serverMessage = response && typeof response.error === 'string' ? response.error : '';
        if (serverMessage && stationMessage) { return serverMessage + ' ' + stationMessage; }
        return serverMessage || stationMessage;
    }

    function showStationFeedback(response) {
        var message = responseFeedbackMessage(response);
        $stationFeedback.text(message).prop('hidden', !message);
        return message;
    }

    function clearStationFeedback() {
        $stationFeedback.text('').prop('hidden', true);
    }

    function validRange() {
        var values = filters();
        if (!values.from || !values.until) { showValidation('Indique ambas fechas de facturación.'); return false; }
        if (values.from > values.until) { showValidation('La fecha inicial no puede ser posterior a la fecha final.'); return false; }
        if ((values.dispatch_from && !values.dispatch_until) || (!values.dispatch_from && values.dispatch_until)) {
            showValidation('Indique ambas fechas de despacho o deje las dos vacías.'); return false;
        }
        if (values.dispatch_from && values.dispatch_from > values.dispatch_until) {
            showValidation('La fecha inicial de despacho no puede ser posterior a la fecha final.'); return false;
        }
        showValidation('');
        return true;
    }

    function showExportError(xhr, generic) {
        if (!(xhr.response instanceof Blob)) { window.alert(generic); return; }
        var reader = new FileReader();
        reader.onload = function () {
            try {
                var response = JSON.parse(reader.result);
                window.alert(response && response.error ? generic : generic);
            } catch (error) { window.alert(generic); }
        };
        reader.onerror = function () { window.alert(generic); };
        reader.readAsText(xhr.response);
    }

    function exportExcel(e, dt) {
        setLoading(true, 'Generando Excel con todos los resultados filtrados…');
        $.ajax({
            url: '/income/export_dispatches_by_billing_excel', method: 'POST', data: dt.ajax.params(), xhrFields: { responseType: 'blob' }
        }).done(function (blob) {
            var url = window.URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href = url; link.download = 'Despachos_por_facturacion.xlsx';
            document.body.appendChild(link); link.click(); link.remove(); window.URL.revokeObjectURL(url);
            setLoading(false, 'Excel descargado.');
        }).fail(function (xhr) { setLoading(false, 'No se pudo generar el Excel.'); showExportError(xhr, 'No se pudo generar el archivo Excel. Intente nuevamente.'); });
    }

    function exportCsv(e, dt) {
        setLoading(true, 'Generando CSV rápido con todos los resultados filtrados…');
        $.ajax({
            url: '/income/export_dispatches_by_billing_csv', method: 'POST', data: dt.ajax.params(), xhrFields: { responseType: 'blob' }
        }).done(function (blob) {
            var url = window.URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href = url; link.download = 'Despachos_por_facturacion.csv';
            document.body.appendChild(link); link.click(); link.remove(); window.URL.revokeObjectURL(url);
            setLoading(false, 'CSV descargado.');
        }).fail(function (xhr) { setLoading(false, 'No se pudo generar el CSV.'); showExportError(xhr, 'No se pudo generar el archivo CSV. Intente nuevamente.'); });
    }

    function restoreColumnFilters() {
        $('#billing-dispatches-table thead .billing-dispatches-filter-row th').each(function (index) {
            $(this).empty().text(filterLabels[index]);
        });
    }

    function resetTable() {
        if (table) {
            table.destroy();
            table = null;
        }
        $('#billing-dispatches-table').off('xhr.dt.billingDispatches').find('tbody').empty();
        restoreColumnFilters();
        clearStationFeedback();
    }

    function buildTable() {
        var $filterCells = $('#billing-dispatches-table thead .billing-dispatches-filter-row th');
        $filterCells.each(function (index) {
            var label = filterLabels[index];
            $(this).html('<input type="search" class="form-control form-control-sm column-filter" aria-label="Filtrar ' + label + '" placeholder="Filtrar">');
            $(this).find('input').on('input', function () {
                clearTimeout(filterTimer);
                filterTimer = setTimeout(function () { table.column(index).search($filterCells.eq(index).find('input').val()).draw(); }, 600);
            }).on('click keydown', function (event) {
                event.stopPropagation();
            });
        });

        $('#billing-dispatches-table').off('xhr.dt.billingDispatches').on('xhr.dt.billingDispatches', function (event, settings, json) {
            var warning = showStationFeedback(json);
            responseMessage = warning ? 'Consulta completada con incidencias.' : 'Consulta actualizada.';
        });

        table = $('#billing-dispatches-table').DataTable({
            pageLength: 100, processing: true, serverSide: true, deferRender: true, scrollX: true, orderCellsTop: true,
            dom: '<"d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2"Bf>rt<"d-flex flex-wrap gap-2 justify-content-between align-items-center mt-2"lip>',
            order: [[14, 'desc']],
            buttons: [
                { text: '<i data-feather="download"></i> CSV rápido completo', className: 'btn btn-outline-primary', action: exportCsv },
                { text: '<i data-feather="download"></i> Excel completo', className: 'btn btn-outline-success', action: exportExcel },
                { extend: 'pdfHtml5', text: 'PDF página actual', className: 'btn btn-outline-danger', title: 'Despachos por facturación — página actual', orientation: 'landscape', pageSize: 'LEGAL', exportOptions: { modifier: { page: 'current' } } }
            ],
            ajax: {
                url: '/income/datatables_dispatches_by_billing_paginated', method: 'POST', data: function (data) { return $.extend(data, filters()); },
                beforeSend: function () {
                    responseMessage = '';
                    clearStationFeedback();
                    setLoading(true, loadingMessage());
                },
                complete: function () { setLoading(false, responseMessage || 'Consulta actualizada.'); },
                error: function (xhr) {
                    var response = xhr.responseJSON;
                    if (!response && xhr.responseText) {
                        try { response = JSON.parse(xhr.responseText); } catch (error) { response = null; }
                    }
                    var warning = showStationFeedback(response);
                    setLoading(false, warning || 'No fue posible consultar los despachos.');
                }
            },
            columns: columns.map(function (name) { return { data: name }; }).map(function (column, index) {
                if (index === 8) { column.render = $.fn.dataTable.render.number(',', '.', 3, ''); }
                if (index === 9 || index === 10) { column.render = $.fn.dataTable.render.number(',', '.', 2, index === 9 ? '$' : ''); }
                return column;
            }),
            initComplete: function () { if (window.feather) { window.feather.replace(); } }
        });
    }

    $(function () {
        var range = currentMonthRange();
        $('#billing-from').val(range[0]); $('#billing-until').val(range[1]);
        updateScopeCopy();
        // No consultar al abrir la pantalla: un periodo de facturación puede
        // devolver muchos despachos. La primera carga ocurre únicamente cuando
        // el usuario confirma el rango con el botón Consultar.
        $form.on('submit', function (event) {
            event.preventDefault();
            if (!validRange()) { return; }

            if (table) {
                table.ajax.reload();
                return;
            }

            buildTable();
        });

        $('#billing-query-mode').on('change', function () {
            resetTable();
            updateScopeCopy();
            $status.text('Origen de consulta actualizado. Configure los filtros y pulse Consultar.');
        });

        $('#billing-codgas').on('change', updateScopeCopy);
    });
}(jQuery));
