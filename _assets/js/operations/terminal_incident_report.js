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
    const exportOptions = { columns: ':visible', modifier: { search: 'applied' }, format: { header: (data, column) => table.find('thead tr:first th').eq(column).text().trim() } };
    const dt = table.DataTable({
        pageLength: 25, searching: false, orderCellsTop: false,
        dom: '<"terminal-dt-toolbar"B l>t<"terminal-dt-footer"ip>',
        buttons: [
            { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-success btn-sm', title: 'Gestión de incidencias de terminales', exportOptions },
            { extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF', className: 'btn btn-danger btn-sm', title: 'Gestión de incidencias de terminales', orientation: 'landscape', pageSize: 'LEGAL', exportOptions }
        ],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json' }
    });
    table.on('click', '.terminal-ticket-link', function (event) {
        event.stopPropagation();
    });
    table.on('keydown', '.terminal-incident-row', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        $(this).trigger('click');
    });
    if (window.feather) feather.replace();
});
