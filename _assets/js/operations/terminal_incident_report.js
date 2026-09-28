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
    table.on('click', '.terminal-incident-row', function () {
        const target=$(this).attr('data-bs-target'), modal=$(target), incidentId=Number(String(target).replace('#terminalIncidentDetail',''));
        const body=modal.find('.modal-body');
        if(!incidentId||body.data('history-loaded'))return;
        body.data('history-loaded',true);
        const section=$('<section class="terminal-detail-section"><h6>Historial de estados</h6><p class="text-muted mb-0">Cargando trazabilidad…</p></section>');
        body.append(section);
        $.getJSON('/operations/terminal_incident_history',{incident_id:incidentId}).done(response=>{
            const list=$('<div class="terminal-state-history"></div>');
            (response.history||[]).forEach(item=>{
                const transition=$('<div class="terminal-state-history-item"></div>');
                $('<strong></strong>').text((item.estado_anterior||'Inicio')+' → '+item.estado_nuevo).appendTo(transition);
                $('<small></small>').text((item.fecha_registro||item.fecha_estado_mojo||'')+' · '+(item.usuario_correo||'Sistema')+' · '+(item.origen||'')).appendTo(transition);
                if(item.comentario)$('<p></p>').text(item.comentario).appendTo(transition);
                if(item.sincronizacion)$('<span class="badge bg-light text-secondary"></span>').text(item.sincronizacion).appendTo(transition);
                list.append(transition);
            });
            section.empty().append('<h6>Historial de estados</h6>').append(list);
            if(!list.children().length)section.append('<p class="text-muted mb-0">Sin cambios registrados.</p>');
        }).fail(()=>section.html('<h6>Historial de estados</h6><p class="text-muted mb-0">No se pudo cargar la trazabilidad.</p>'));
    });
    table.on('keydown', '.terminal-incident-row', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        $(this).trigger('click');
    });
    if (window.feather) feather.replace();
});
