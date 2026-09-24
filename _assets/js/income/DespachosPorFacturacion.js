(function ($) {
    'use strict';

    var table;
    var filterTimer;
    var $form = $('#billing-dispatches-form');
    var $wrap = $('#billing-dispatches-table-wrap');
    var $status = $('#billing-dispatches-status');
    var $validation = $('#billing-dispatches-validation');
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
        return { from: $('#billing-from').val(), until: $('#billing-until').val(), codgas: $('#billing-codgas').val(), uuid: $('#billing-uuid').val() };
    }

    function setLoading(loading, message) {
        $wrap.toggleClass('loading', loading);
        $status.text(message || (loading ? 'Consultando despachos…' : 'Consulta actualizada.'));
    }

    function showValidation(message) {
        $validation.text(message).prop('hidden', !message);
    }

    function validRange() {
        var values = filters();
        if (!values.from || !values.until) { showValidation('Indique ambas fechas de facturación.'); return false; }
        if (values.from > values.until) { showValidation('La fecha inicial no puede ser posterior a la fecha final.'); return false; }
        showValidation('');
        return true;
    }

    function showExportError(xhr) {
        var generic = 'No se pudo generar el archivo Excel. Intente nuevamente.';
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
        }).fail(function (xhr) { setLoading(false, 'No se pudo generar el Excel.'); showExportError(xhr); });
    }

    function buildTable() {
        $('#billing-dispatches-table tfoot th').each(function (index) {
            var label = $(this).text();
            $(this).html('<input type="search" class="form-control form-control-sm column-filter" aria-label="Filtrar ' + label + '" placeholder="Filtrar">');
            $(this).find('input').on('input', function () {
                clearTimeout(filterTimer);
                filterTimer = setTimeout(function () { table.column(index).search($('#billing-dispatches-table tfoot th').eq(index).find('input').val()).draw(); }, 600);
            });
        });

        table = $('#billing-dispatches-table').DataTable({
            pageLength: 100, processing: true, serverSide: true, deferRender: true, scrollX: true,
            dom: '<"d-flex flex-wrap gap-2 justify-content-between align-items-center mb-2"Bf>rt<"d-flex flex-wrap gap-2 justify-content-between align-items-center mt-2"lip>',
            order: [[14, 'desc']],
            buttons: [
                { text: '<i data-feather="download"></i> Excel completo', className: 'btn btn-outline-success', action: exportExcel },
                { extend: 'pdfHtml5', text: 'PDF página actual', className: 'btn btn-outline-danger', title: 'Despachos por facturación — página actual', orientation: 'landscape', pageSize: 'LEGAL', exportOptions: { modifier: { page: 'current' } } }
            ],
            ajax: {
                url: '/income/datatables_dispatches_by_billing_paginated', method: 'POST', data: function (data) { return $.extend(data, filters()); },
                beforeSend: function () { setLoading(true, 'Consultando despachos…'); },
                complete: function () { setLoading(false); },
                error: function () { setLoading(false, 'No fue posible consultar los despachos.'); }
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
        buildTable();
        $form.on('submit', function (event) { event.preventDefault(); if (validRange()) { table.ajax.reload(); } });
    });
}(jQuery));
