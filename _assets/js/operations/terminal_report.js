$(function () {
    const catalog = window.terminalInventoryTypeCatalog || {};
    const mojoUrl = id => 'https://totalgas.mojohelpdesk.com/ma/#/tickets/search?query_string=' + encodeURIComponent(id) + '&page=1';
    const escapeHtml = value => $('<div>').text(value == null ? '' : value).html();
    const formatDate = value => {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? escapeHtml(value) : date.toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' });
    };
    const brand = type => {
        const info = catalog[type] || { label: type, logo: String(type).toUpperCase() };
        return '<span class="terminal-brand terminal-brand-' + escapeHtml(type) + '">' + escapeHtml(info.logo) + '</span><strong>' + escapeHtml(info.label) + '</strong>';
    };
    const setExpanded = (row, child, expanded) => {
        $(child).toggleClass('d-none', !expanded);
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
        let html = '<div class="table-responsive"><table class="table table-sm terminal-incident-table mb-0"><thead><tr><th>Ticket Mojo</th><th>Descripción</th><th>Apertura</th><th>Días hábiles</th><th>Estado</th><th>Cierre</th></tr></thead><tbody>';
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
    if ($('#terminalReportTable').length) $('#terminalReportTable').DataTable({ pageLength: 25, language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json' } });
});
