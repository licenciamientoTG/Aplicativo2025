$(function () {
    const catalog = window.terminalInventoryTypeCatalog || {};
    const mojoUrl = id => 'https://totalgas.mojohelpdesk.com/mc/tickets/' + encodeURIComponent(id);
    const escapeHtml = value => $('<div>').text(value == null ? '' : value).html();
    const formatDate = value => {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? escapeHtml(value) : date.toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
    };
    const brand = type => {
        const info = catalog[type] || { label: type, logo: String(type).toUpperCase() };
        return '<span class="terminal-brand terminal-brand-' + escapeHtml(type) + '" title="' + escapeHtml(info.label) + '" aria-label="' + escapeHtml(info.label) + '">' + escapeHtml(info.logo) + '</span>';
    };
    const setExpanded = (row, child, expanded) => {
        $(child).toggleClass('d-none', !expanded).toggle(expanded);
        $(row).toggleClass('is-expanded', expanded).attr('aria-expanded', String(expanded));
        $(row).find('.terminal-tree-toggle').html('<i data-feather="' + (expanded ? 'chevron-down' : 'chevron-right') + '"></i>');
        if (window.feather) feather.replace();
    };
    const activate = handler => event => {
        if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
        if (event.type === 'keydown') event.preventDefault();
        event.stopPropagation();
        handler(event.currentTarget);
    };
    const errorBlock = message => '<div class="alert alert-danger m-3">' + escapeHtml(message) + '</div>';

    function incidentTable(incidents) {
        if (!incidents.length) return '<div class="terminal-incidents-empty"><i data-feather="check-circle"></i> Sin incidencias vinculadas a este tipo en este inventario.</div>';
        let html = '<div class="table-responsive"><table class="table table-sm terminal-incident-table mb-0"><thead><tr><th>Ticket Mojo</th><th>Descripción</th><th>Apertura</th><th>Jornadas hábiles<br><small>08:00–18:00</small></th><th>Estado</th><th>Cierre</th></tr></thead><tbody>';
        incidents.forEach(incident => {
            const closed = !!incident.fecha_cierre_mojo;
            html += '<tr><td><a target="_blank" rel="noopener" href="' + mojoUrl(incident.ticket_mojo_id) + '">#' + escapeHtml(incident.ticket_mojo_id) + '</a></td>' +
                '<td>' + escapeHtml(incident.descripcion || '—') + '</td><td>' + formatDate(incident.fecha_apertura_mojo) + '</td><td>' + escapeHtml(incident.dias_habiles) + '</td>' +
                '<td><span class="badge ' + (closed ? 'bg-success' : 'bg-warning text-dark') + '">' + (closed ? 'Cerrado' : 'Abierto') + '</span></td><td>' + formatDate(incident.fecha_cierre_mojo) + '</td></tr>';
        });
        return html + '</tbody></table></div>';
    }
    function typeRows(types, inventoryId) {
        if (!types.length) return '<div class="terminal-incidents-empty"><i data-feather="info"></i> Este inventario no tiene detalle por terminal.</div>';
        let html = '<table class="table terminal-type-table mb-0"><thead><tr><th>Tipo de terminal</th><th class="text-center">Funcionando</th><th class="text-center">Dañadas</th><th>Incidencias</th></tr></thead><tbody>';
        types.forEach(item => {
            const damaged = Number(item.danadas || 0);
            html += '<tr class="terminal-type-row" data-inventory-id="' + Number(inventoryId) + '" data-type="' + escapeHtml(item.tipo_terminal) + '" tabindex="0" role="button" aria-expanded="false"><td><span class="terminal-tree-toggle"><i data-feather="chevron-right"></i></span>' + brand(item.tipo_terminal) + '</td><td class="text-center"><span class="terminal-summary-value">' + Number(item.funcionando || 0) + '</span></td><td class="text-center"><span class="terminal-summary-value ' + (damaged ? 'is-damaged' : '') + '">' + damaged + '</span></td><td><span class="terminal-view-incidents">Ver incidencias <i data-feather="arrow-right"></i></span></td></tr>';
            html += '<tr class="terminal-incident-child d-none"><td colspan="4"><div class="terminal-incidents-loading"><span class="spinner-border spinner-border-sm"></span> Cargando incidencias…</div></td></tr>';
        });
        return html + '</tbody></table>';
    }
    function stationRows(stations) {
        let html = '<table class="table terminal-station-table mb-0"><thead><tr><th>Estación</th><th class="text-center">Funcionando</th><th class="text-center">Dañadas</th><th>Capturado</th><th>Registrado por</th></tr></thead><tbody>';
        stations.forEach(station => {
            const captured = Number(station.inventario_id || 0) > 0;
            const damaged = Number(station.danadas || 0);
            if (captured) {
                html += '<tr class="terminal-station-row is-expandable" data-inventory-id="' + Number(station.inventario_id) + '" tabindex="0" role="button" aria-expanded="false"><td><span class="terminal-tree-toggle"><i data-feather="chevron-right"></i></span><strong>#' + escapeHtml(station.Codigo) + '</strong> · ' + escapeHtml(station.Nombre) + '</td><td class="text-center"><span class="terminal-summary-value">' + Number(station.funcionando || 0) + '</span></td><td class="text-center"><span class="terminal-summary-value ' + (damaged ? 'is-damaged' : '') + '">' + damaged + '</span></td><td>' + formatDate(station.fecha_registro) + '</td><td><span class="terminal-captured-by">' + escapeHtml(station.usuario_correo || '—') + '</span></td></tr>';
                html += '<tr class="terminal-tree-child d-none"><td colspan="5"><div class="terminal-tree-child-panel"><div class="terminal-incidents-loading"><span class="spinner-border spinner-border-sm"></span> Cargando terminales…</div></div></td></tr>';
            } else {
                html += '<tr class="terminal-station-row is-empty"><td><span class="terminal-tree-toggle"><i data-feather="minus"></i></span><strong>#' + escapeHtml(station.Codigo) + '</strong> · ' + escapeHtml(station.Nombre) + '</td><td class="text-center">—</td><td class="text-center">—</td><td><span class="badge bg-light text-muted border">Sin captura</span></td><td>—</td></tr>';
            }
        });
        return html + '</tbody></table>';
    }

    $(document).on('click keydown', '.terminal-date-row', activate(function (row) {
        const child = $(row).next('.terminal-tree-child')[0];
        const opening = $(child).hasClass('d-none');
        setExpanded(row, child, opening);
        if (!opening || $(child).data('loaded')) return;
        $.getJSON('/operations/terminal_inventory_group', { date: $(row).data('date') })
            .done(response => {
                if (!response.success) throw new Error(response.message || 'No fue posible consultar las estaciones.');
                $(child).data('loaded', true).find('.terminal-date-details').html(stationRows(response.stations || []));
                if (window.feather) feather.replace();
            })
            .fail(xhr => $(child).find('.terminal-date-details').html(errorBlock(xhr.responseJSON?.message || 'No fue posible consultar las estaciones.')));
    }));
    $(document).on('click keydown', '.terminal-station-row.is-expandable', activate(function (row) {
        const child = $(row).next('.terminal-tree-child')[0];
        const opening = $(child).hasClass('d-none');
        setExpanded(row, child, opening);
        if (!opening || $(child).data('loaded')) return;
        $.getJSON('/operations/terminal_inventory_types', { inventory_id: $(row).data('inventory-id') })
            .done(response => {
                if (!response.success) throw new Error(response.message || 'No fue posible consultar las terminales.');
                $(child).data('loaded', true).find('.terminal-tree-child-panel').html(typeRows(response.types || [], $(row).data('inventory-id')));
                if (window.feather) feather.replace();
            })
            .fail(xhr => $(child).find('.terminal-tree-child-panel').html(errorBlock(xhr.responseJSON?.message || 'No fue posible consultar las terminales.')));
    }));
    $(document).on('click keydown', '.terminal-type-row', activate(function (row) {
        const child = $(row).next('.terminal-incident-child')[0];
        const opening = $(child).hasClass('d-none');
        setExpanded(row, child, opening);
        if (!opening || $(child).data('loaded')) return;
        $.getJSON('/operations/terminal_inventory_incidents', { inventory_id: $(row).data('inventory-id'), type: $(row).data('type') })
            .done(response => {
                if (!response.success) throw new Error(response.message || 'No fue posible consultar las incidencias.');
                $(child).data('loaded', true).find('td').html(incidentTable(response.incidents || []));
                if (window.feather) feather.replace();
            })
            .fail(xhr => $(child).find('td').html(errorBlock(xhr.responseJSON?.message || 'No fue posible consultar las incidencias.')));
    }));
    function setupGroupBrowser() {
        const $rows = $('.terminal-date-row');
        if (!$rows.length) return;
        const dates = $rows.map(function () { return String($(this).data('date')); }).get().sort();
        const dateSet = new Set(dates);
        let page = 1;
        const pageSize = 10;
        const $pagination = $('<div class="terminal-report-pagination" aria-label="Paginación de fechas"></div>');
        $('.terminal-tree-wrap').after($pagination);
        const $count = $('<div class="terminal-report-result-count"></div>').insertBefore($pagination);
        const $calendar = $('#terminalInventoryCalendar');
        const $calendarToggle = $('#terminalCalendarToggle');
        const $calendarLabel = $('#terminalCalendarLabel');
        let selectedDate = '';
        let calendarMonth = new Date((dates[dates.length - 1] || new Date().toISOString().slice(0, 10)) + 'T12:00:00');
        calendarMonth.setDate(1);
        const monthName = date => date.toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
        function renderCalendar() {
            const year = calendarMonth.getFullYear(), month = calendarMonth.getMonth();
            const firstDay = new Date(year, month, 1).getDay();
            const offset = (firstDay + 6) % 7;
            const lastDay = new Date(year, month + 1, 0).getDate();
            let html = '<div class="terminal-calendar-header"><button type="button" class="btn btn-sm btn-light calendar-prev" aria-label="Mes anterior"><i data-feather="chevron-left"></i></button><strong>' + monthName(calendarMonth) + '</strong><button type="button" class="btn btn-sm btn-light calendar-next" aria-label="Mes siguiente"><i data-feather="chevron-right"></i></button></div><div class="terminal-calendar-weekdays"><span>L</span><span>M</span><span>M</span><span>J</span><span>V</span><span>S</span><span>D</span></div><div class="terminal-calendar-days">';
            for (let i = 0; i < offset; i++) html += '<span class="terminal-calendar-empty"></span>';
            for (let day = 1; day <= lastDay; day++) {
                const key = year + '-' + String(month + 1).padStart(2, '0') + '-' + String(day).padStart(2, '0');
                html += '<button type="button" class="terminal-calendar-day ' + (dateSet.has(key) ? 'has-inventory' : '') + (selectedDate === key ? ' is-selected' : '') + '" data-date="' + key + '" ' + (dateSet.has(key) ? '' : 'disabled') + '>' + day + '</button>';
            }
            $calendar.html(html + '</div><button type="button" class="terminal-calendar-clear">Mostrar todas las fechas</button>');
            if (window.feather) feather.replace();
        }
        function renderPagination(totalPages) {
            if (totalPages < 2) { $pagination.empty(); return; }
            let html = '<button type="button" class="btn btn-sm btn-light report-page-prev" ' + (page === 1 ? 'disabled' : '') + '>Anterior</button><span>Página ' + page + ' de ' + totalPages + '</span><button type="button" class="btn btn-sm btn-light report-page-next" ' + (page === totalPages ? 'disabled' : '') + '>Siguiente</button>';
            $pagination.html(html);
        }
        function renderRows() {
            const filtered = $rows.filter(function () {
                const rowDate = String($(this).data('date'));
                return !selectedDate || rowDate === selectedDate;
            });
            const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
            page = Math.min(page, totalPages);
            $rows.each(function () {
                $(this).removeClass('is-expanded').attr('aria-expanded', 'false').hide();
                $(this).next('.terminal-tree-child').addClass('d-none').hide();
            });
            filtered.slice((page - 1) * pageSize, page * pageSize).each(function () { $(this).show(); });
            $count.text(filtered.length + ' fecha' + (filtered.length === 1 ? '' : 's') + ' con inventario');
            renderPagination(totalPages);
        }
        $pagination.on('click', '.report-page-prev', function () { if (page > 1) { page--; renderRows(); } });
        $pagination.on('click', '.report-page-next', function () { const total = Math.ceil($rows.filter(function () { return !selectedDate || String($(this).data('date')) === selectedDate; }).length / pageSize); if (page < total) { page++; renderRows(); } });
        $calendar.on('click', '.calendar-prev', function (event) { event.stopPropagation(); calendarMonth.setMonth(calendarMonth.getMonth() - 1); renderCalendar(); });
        $calendar.on('click', '.calendar-next', function (event) { event.stopPropagation(); calendarMonth.setMonth(calendarMonth.getMonth() + 1); renderCalendar(); });
        $calendar.on('click', '.terminal-calendar-day.has-inventory', function (event) { event.stopPropagation(); selectedDate = String($(this).data('date')); page = 1; renderRows(); renderCalendar(); $calendarLabel.text(new Date(selectedDate + 'T12:00:00').toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' })); $calendar.addClass('d-none'); $calendarToggle.attr('aria-expanded', 'false'); });
        $calendar.on('click', '.terminal-calendar-clear', function (event) { event.stopPropagation(); selectedDate = ''; page = 1; renderRows(); renderCalendar(); $calendarLabel.text('Todas las fechas'); $calendar.addClass('d-none'); $calendarToggle.attr('aria-expanded', 'false'); });
        $calendarToggle.on('click', function (event) { event.stopPropagation(); const open = $calendar.hasClass('d-none'); $calendar.toggleClass('d-none', !open); $(this).attr('aria-expanded', String(open)); });
        $(document).on('click', function (event) { if (!$(event.target).closest('.terminal-date-picker').length) { $calendar.addClass('d-none'); $calendarToggle.attr('aria-expanded', 'false'); } });
        renderCalendar(); renderRows();
    }
    setupGroupBrowser();
    const $inventoryRows = $('.terminal-date-row');
    if ($inventoryRows.length && $.fn.DataTable && $('#terminalInventoryExportTable').length === 0) {
        const $exportTable = $('<table id="terminalInventoryExportTable" class="terminal-export-source"><thead><tr><th>Fecha de inventario</th><th>Estaciones</th><th>Total terminales</th><th>Funcionando</th><th>Dañadas</th></tr></thead><tbody></tbody></table>');
        $inventoryRows.each(function () {
            const cells = $(this).children('td').map(function () { return $('<div>').html($(this).html()).text().replace(/\s+/g, ' ').trim(); }).get();
            $exportTable.find('tbody').append($('<tr>').append(cells.map(value => $('<td>').text(value))));
        });
        $('body').append($exportTable);
        const inventoryExportTable = $exportTable.DataTable({
            paging: false,
            searching: false,
            info: false,
            dom: 'B',
            buttons: [
                { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-success btn-sm', title: 'Resumen de inventarios de terminales' },
                { extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF', className: 'btn btn-danger btn-sm', title: 'Resumen de inventarios de terminales', orientation: 'landscape', pageSize: 'LEGAL' }
            ]
        });
        inventoryExportTable.buttons().container().appendTo('#terminalInventoryExportActions');
    }
    if ($('#terminalReportTable').length) {
        const filterLabels = ['Estación', 'Terminal', 'Ticket', 'Folio', 'Apertura', 'Días', 'Estado', 'Cierre'];
        const filterRow = '<tr class="terminal-column-filters">' + filterLabels.map(label => '<th><input type="search" placeholder="' + label + '" aria-label="Filtrar por ' + label + '"></th>').join('') + '</tr>';
        $('#terminalReportTable thead tr:first').after(filterRow);
        const exportOptions = {
            columns: ':visible',
            modifier: { search: 'applied' },
            format: {
                header: (data, column) => $('#terminalReportTable thead tr:first th').eq(column).text().trim()
            }
        };
        const incidenceTable = $('#terminalReportTable').DataTable({
        pageLength: 25,
        searching: true,
        orderCellsTop: true,
        columnDefs: [{ targets: 5, type: 'num' }],
        dom: '<"terminal-dt-toolbar"B l>t<"terminal-dt-footer"ip>',
        buttons: [
            { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-success btn-sm', title: 'Reporte de incidencias de terminales', exportOptions: exportOptions },
            { extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF', className: 'btn btn-danger btn-sm', title: 'Reporte de incidencias de terminales', orientation: 'landscape', pageSize: 'LEGAL', exportOptions: exportOptions }
        ],
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json' }
        });
        $('#terminalReportTable thead tr.terminal-column-filters th').each(function (index) {
            $('input', this).on('keyup change clear', function () {
                if (incidenceTable.column(index).search() !== this.value) incidenceTable.column(index).search(this.value).draw();
            });
        });
    }
});
