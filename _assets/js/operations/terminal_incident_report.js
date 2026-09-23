$(function () {
    const advanced = $('#advancedIncidentFilters');
    const toggle = $('#toggleIncidentFilters');
    if (toggle.length) {
        toggle.on('click', function () {
            const expanded = $(this).attr('aria-expanded') === 'true';
            $(this).attr('aria-expanded', String(!expanded));
            advanced.toggleClass('d-none', expanded);
            $(this).find('svg').replaceWith($('<i data-feather="' + (expanded ? 'sliders' : 'chevron-up') + '"></i>'));
            if (window.feather) feather.replace();
        });
    }
    const table = $('#terminalIncidentReportTable');
    if (!table.length || !$.fn.DataTable) return;
    const filterLabels = ['Estación','Tipo','Ticket','Descripción','Folio','Serie','Apertura','Estado','Asignado','Prioridad','Cola','Cierre','Días','Confirmación'];
    table.find('thead tr:first').after('<tr class="terminal-column-filters">' + filterLabels.map(label => '<th><input type="search" placeholder="' + label + '" aria-label="Filtrar por ' + label + '"></th>').join('') + '</tr>');
    const exportOptions = { columns: ':visible', modifier: { search: 'applied' }, format: { header: (data, column) => table.find('thead tr:first th').eq(column).text().trim() } };
    const dt = table.DataTable({
        pageLength: 25, searching: true, orderCellsTop: true,
        columnDefs: [{ targets: [6, 12], type: 'num' }],
        dom: '<"terminal-dt-toolbar"B l>t<"terminal-dt-footer"ip>',
        buttons: [
            { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-success btn-sm', title: 'Gestión de incidencias de terminales', exportOptions },
            { extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF', className: 'btn btn-danger btn-sm', title: 'Gestión de incidencias de terminales', orientation: 'landscape', pageSize: 'LEGAL', exportOptions }
        ],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json' }
    });
    table.closest('.terminal-report-table-wrap').scrollLeft(0);
    table.find('thead tr.terminal-column-filters th').each(function (index) {
        $('input', this).on('keyup change clear', function () {
            if (dt.column(index).search() !== this.value) dt.column(index).search(this.value).draw();
        });
    });
});
