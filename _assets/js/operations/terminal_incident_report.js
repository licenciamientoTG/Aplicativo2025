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
    if (table.length && $.fn.DataTable) {
        const exportOptions = { columns: ':visible', modifier: { search: 'applied' }, format: { header: (data, column) => table.find('thead tr:first th').eq(column).text().trim() } };
        const dt = table.DataTable({
            pageLength: 25, searching: true, orderCellsTop: true,
            dom: '<"terminal-dt-toolbar"B l>t<"terminal-dt-footer"ip>',
            buttons: [
                { extend: 'excelHtml5', text: '<i class="fas fa-file-excel"></i> Excel', className: 'btn btn-success btn-sm', title: 'Reporte de incidencias', exportOptions },
                { extend: 'pdfHtml5', text: '<i class="fas fa-file-pdf"></i> PDF', className: 'btn btn-danger btn-sm', title: 'Reporte de incidencias', orientation: 'landscape', pageSize: 'LEGAL', exportOptions }
            ],
            language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/es-ES.json' }
        });
        table.find('.terminal-column-search').on('click', event => event.stopPropagation());
        table.on('keydown', '.terminal-incident-row', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            $(this).trigger('click');
        });
        table.on('input change', '.terminal-column-search', function () {
            const column = dt.column(Number(this.dataset.column));
            const value = this.value.trim();
            const exact = this.tagName === 'SELECT' && value !== '';
            column.search(exact ? '^' + $.fn.dataTable.util.escapeRegex(value) + '$' : value, exact, !exact).draw();
        });
        table.on('click', '.terminal-ticket-link', event => event.stopPropagation());
    }

    $(document).on('click', '.terminal-reopen-closed-ticket', function () {
        const button = $(this);
        const original = button.text();
        button.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Reabriendo…');
        $.ajax({
            url: '/operations/terminal_incident_action',
            method: 'POST',
            data: { incident_id: button.data('incident-id'), action: 'reopen' }
        }).done(response => {
            if (!response?.success) {
                button.prop('disabled', false).text(original);
                if (window.toastr) toastr.error(response?.message || 'No se pudo reabrir el ticket.');
                return;
            }
            if (window.toastr) toastr.success('Ticket reabierto.');
            window.setTimeout(() => window.location.reload(), 500);
        }).fail(xhr => {
            button.prop('disabled', false).text(original);
            const message = xhr.responseJSON?.message || 'No se pudo reabrir el ticket.';
            if (window.toastr) toastr.error(message);
        });
    });

    const escape = value => $('<div>').text(value == null ? '' : String(value)).html();
    const empty = value => value == null || String(value).trim() === '' ? 'No disponible' : String(value);
    const typeLabel = value => ({ urovo: 'Urovo', verifone: 'Verifone', efecticard: 'EfectiCard', inburgas: 'Inburgas', ticketcard: 'Ticket Card', sodexo: 'Sodexo', ultragas: 'Ultragas', mobil: 'Mobil', eox: 'EOX' })[value] || value || 'Terminal';
    function cleanDescription(value) {
        return String(value || '').replace(/<!--[\s]*terminal-request-[a-f0-9-]+[\s]*-->/gi, '').replace(/[\s→]+$/, '').trim();
    }
    function dateLabel(value) {
        if (!value) return 'No disponible';
        const raw = String(value);
        const date = new Date(raw);
        if (Number.isNaN(date.getTime())) return raw;
        return new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    }
    function stateLabel(value) {
        const normalized = String(value || '').toLowerCase();
        if (['solved', 'resolved', 'resuelto', 'resuelta'].includes(normalized)) return 'Resuelto';
        if (['closed', 'cerrado', 'cerrada'].includes(normalized)) return 'Cerrado';
        if (['reopened', 'reabierto', 'reabierta'].includes(normalized)) return 'Reabierto';
        if (['open', 'new', 'abierto', 'abierta'].includes(normalized)) return 'Abierto';
        return value || 'Sin estado';
    }
    function safePortalUrl(value) {
        try {
            const url = new URL(String(value || ''), window.location.origin);
            return url.origin === window.location.origin && /^\/operations\/terminal_incident_attachment\/\d+\/\d+$/.test(url.pathname) ? url.href : '';
        } catch (error) { return ''; }
    }
    function heading(icon, eyebrow, title, count) {
        const countBadge = count == null ? '' : '<span class="terminal-comment-count">' + Number(count) + '</span>';
        return '<div class="terminal-section-heading"><span class="terminal-section-icon" aria-hidden="true"><i class="fa fa-' + icon + '"></i></span><div><span class="terminal-section-kicker">' + escape(eyebrow) + '</span><h6>' + escape(title) + '</h6></div>' + countBadge + '</div>';
    }
    function section(icon, eyebrow, title, content, count) {
        return '<section class="terminal-detail-section terminal-info-card">' + heading(icon, eyebrow, title, count) + '<div class="terminal-section-body">' + content + '</div></section>';
    }
    function detailGrid(fields) {
        return '<div class="terminal-detail-grid">' + fields.map(([label, value]) => '<div><span>' + escape(label) + '</span><strong>' + escape(empty(value)) + '</strong></div>').join('') + '</div>';
    }
    function detailList(fields) {
        return '<dl class="terminal-detail-list">' + fields.map(([label, value]) => '<div><dt>' + escape(label) + '</dt><dd>' + escape(empty(value)) + '</dd></div>').join('') + '</dl>';
    }
    function renderDetail(response, modal) {
        const incident = response.incident || {};
        const ticket = response.ticket || {};
        const comments = response.comments || [];
        const history = response.state_history || [];
        const body = modal.find('.modal-body');
        const status = ticket.status || incident.state;
        const isSolved = stateLabel(status) === 'Resuelto';
        const closedAt = isSolved ? ticket.solved : ticket.closed;
        const hero = '<div class="terminal-ticket-hero"><div><span class="terminal-detail-kicker">' + escape(typeLabel(incident.type)) + ' · Ticket #' + Number(incident.ticket_id || 0) + '</span><h4>' + escape(ticket.title || cleanDescription(incident.description) || 'Incidencia de terminal') + '</h4><span class="terminal-ticket-state">' + escape(stateLabel(status)) + '</span></div></div>';
        const summary = detailGrid([
            ['Estación', incident.station], ['Estado en Mojo', stateLabel(status)], ['Solicitante', ticket.requester], ['Técnico TI', ticket.assignee],
            ['Apertura', dateLabel(ticket.created || incident.opened)], ['Última actualización', dateLabel(ticket.updated)], ['Vencimiento', dateLabel(ticket.due)],
            ['Antigüedad', modal.data('age-days') + ' días transcurridos · ' + modal.data('age-hours') + ' horas laborales'], ['Serie Urovo', incident.serial]
        ]);
        const reportDescription = cleanDescription(incident.description) || 'Sin descripción registrada.';
        const mojoDescription = cleanDescription(ticket.description);
        let html = hero + summary;
        html += section('file-alt', 'REPORTE', 'Descripción de la incidencia', '<p>' + escape(reportDescription) + '</p>');
        if (mojoDescription && mojoDescription !== reportDescription) html += section('comment-alt', 'MOJO', 'Descripción del ticket', '<p>' + escape(mojoDescription) + '</p>');

        const closeLabel = isSolved ? 'Resuelto el' : 'Cerrado el';
        const operational = [
            ['Tipo de terminal', typeLabel(incident.type)], ['Prioridad', ticket.priority], ['Cola', ticket.queue], ['Formulario', ticket.form],
            ['Empresa', ticket.company], ['Folio del proveedor', incident.provider_folio], ['Reporte al proveedor', dateLabel(incident.provider_date)],
            [closeLabel, dateLabel(closedAt)], ['Resuelto/cerrado por', ticket.closed_by],
            ['Confirmación en portal', incident.resolution_confirmed ? 'Confirmada' : (incident.state === 'Closed' ? 'Pendiente' : 'No aplica')],
            ['Confirmada por', incident.resolution_confirmed_by], ['Confirmada el', dateLabel(incident.resolution_confirmed_at)],
            ['Resolución registrada en Mojo', ticket.resolution]
        ];
        html += section('clipboard-list', 'DATOS DEL TICKET', 'Información operativa', detailList(operational));
        if (incident.resolution_note) html += section('check-circle', 'CONFIRMACIÓN', 'Nota de cierre', '<p>' + escape(incident.resolution_note) + '</p>');
        if (ticket.custom_fields?.length) {
            html += section('list-alt', 'MOJO', 'Campos adicionales', detailList(ticket.custom_fields.map(field => [field.label, field.value])));
        }
        if (ticket.attachments?.length) {
            const files = ticket.attachments.map(file => {
                const url = safePortalUrl(file.url);
                return '<li>' + (url ? '<a href="' + escape(url) + '" target="_blank" rel="noopener">' + escape(file.name) + '</a>' : escape(file.name)) + '</li>';
            }).join('');
            const attachmentNote = ticket.attachments_unavailable ? '<p class="terminal-detail-warning">Algunos adjuntos podrían no estar disponibles en este momento.</p>' : '';
            html += section('paperclip', 'ARCHIVOS', 'Documentos adjuntos', '<ul class="terminal-ticket-attachments">' + files + '</ul>' + attachmentNote, ticket.attachments.length);
        } else if (ticket.attachments_unavailable) {
            html += section('paperclip', 'ARCHIVOS', 'Documentos adjuntos', '<p class="terminal-detail-warning">No se pudieron consultar los adjuntos en Mojo. Vuelve a abrir el detalle para intentarlo de nuevo.</p>');
        } else {
            html += section('paperclip', 'ARCHIVOS', 'Documentos adjuntos', '<p class="terminal-empty-conversation">Este ticket no tiene archivos adjuntos.</p>', 0);
        }

        const conversationId = 'terminalCommentHistory' + Number(incident.id || 0);
        const conversation = comments.length ? '<div id="' + conversationId + '" class="terminal-comment-timeline">' + comments.map((comment, index) => {
            const actor = comment.user_name || (comment.user_id ? 'Usuario MOJO #' + comment.user_id : 'Autor sin identificar');
            const visibility = comment.is_private ? 'Nota interna para el técnico' : 'Respuesta pública';
            const files = (comment.attachments || []).map(file => {
                const url = safePortalUrl(file.url);
                return '<li>' + (url ? '<a href="' + escape(url) + '" target="_blank" rel="noopener">' + escape(file.name) + '</a>' : escape(file.name)) + '</li>';
            }).join('');
            const older = index < comments.length - 1;
            return '<article class="terminal-comment' + (comment.is_private ? ' is-internal' : '') + (older ? ' terminal-comment-older d-none' : '') + '"><div class="terminal-comment-heading"><strong>' + escape(actor) + '</strong><time>' + escape(dateLabel(comment.created_on || comment.created_at)) + '</time></div><span class="terminal-comment-visibility">' + visibility + '</span><p>' + escape(comment.body || comment.comment || '') + '</p>' + (files ? '<ul class="terminal-comment-files">' + files + '</ul>' : '') + '</article>';
        }).join('') + '</div>' + (comments.length > 1 ? '<button type="button" class="btn btn-sm btn-link terminal-conversation-toggle px-0" aria-expanded="false" aria-controls="' + conversationId + '" data-count="' + (comments.length - 1) + '">Mostrar más (' + (comments.length - 1) + ' anteriores)</button>' : '') : '<p class="terminal-empty-conversation">MOJO no devolvió mensajes para este ticket.</p>';
        html += section('comments', 'SEGUIMIENTO', 'Conversación con el técnico', conversation, comments.length);

        const activity = history.length ? '<div class="terminal-state-history">' + history.map(item => '<div class="terminal-state-history-item"><strong>' + escape(stateLabel(item.estado_anterior || 'Creado')) + ' <i class="fa fa-arrow-right" aria-hidden="true"></i> ' + escape(stateLabel(item.estado_nuevo)) + '</strong><small>' + escape(dateLabel(item.fecha_registro || item.fecha_estado_mojo)) + ' · ' + escape(item.actor || item.usuario_correo || item.origen || 'Autor no identificado') + '</small>' + (item.comentario ? '<p>' + escape(item.comentario) + '</p>' : '') + '</div>').join('') + '</div>' : '<p class="terminal-empty-conversation">No hay actividad registrada en el portal.</p>';
        html += section('history', 'TRAZABILIDAD', 'Actividad del portal', activity, history.length);
        body.html(html);
        modal.data('detailLoaded', true).removeData('detailRequest');
    }
    function showLoadError(modal, message) {
        modal.find('.modal-body').html('<div class="alert alert-danger terminal-detail-load-error" role="alert"><strong>No fue posible cargar el detalle.</strong><p class="mb-2">' + escape(message) + '</p><button type="button" class="btn btn-sm btn-outline-primary terminal-detail-retry">Reintentar carga</button></div>');
        modal.removeData('detailRequest');
    }
    function loadDetail(modal) {
        if (modal.data('detailLoaded') || modal.data('detailRequest')) return;
        const incidentId = Number(modal.data('incident-id'));
        if (!incidentId) return;
        modal.find('.modal-body').html('<div class="terminal-detail-loading" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Cargando el ticket, la conversación y sus archivos desde Mojo…</div>');
        const request = $.getJSON('/operations/terminal_incident_detail', { incident_id: incidentId })
            .done(response => {
                if (!response || !response.success) {
                    showLoadError(modal, response?.message || 'Mojo no devolvió el detalle del ticket.');
                    return;
                }
                renderDetail(response, modal);
            })
            .fail(xhr => {
                if (xhr.statusText === 'abort') return;
                const message = xhr.responseJSON?.message || 'No se pudo cargar toda la información desde Mojo. Revisa la conexión e inténtalo de nuevo.';
                showLoadError(modal, message);
            });
        modal.data('detailRequest', request);
    }
    $(document).on('show.bs.modal', '.terminal-incident-detail', function () { loadDetail($(this)); });
    $(document).on('click', '.terminal-detail-retry', function () {
        const modal = $(this).closest('.terminal-incident-detail');
        modal.removeData('detailLoaded').removeData('detailRequest');
        loadDetail(modal);
    });
    $(document).on('click', '.terminal-conversation-toggle', function () {
        const button = $(this), expanded = button.attr('aria-expanded') === 'true';
        const history = $('#' + button.attr('aria-controls'));
        history.find('.terminal-comment-older').toggleClass('d-none', expanded);
        button.attr('aria-expanded', String(!expanded)).text(expanded ? 'Mostrar más (' + Number(button.data('count')) + ' anteriores)' : 'Mostrar menos');
    });
    $(document).on('hidden.bs.modal', '.terminal-incident-detail', function () {
        const modal = $(this);
        const request = modal.data('detailRequest');
        if (request) request.abort();
        modal.removeData('detailRequest');
    });
    if (window.feather) feather.replace();
});
