// =====================================================
// Renegociacion de Proveedores
// =====================================================

function renegTab(btn, tabId) {
    document.querySelectorAll('.reneg-tab-link').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.reneg-tab-content').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(tabId).classList.add('active');
}

function getUploadId() {
    return parseInt(document.getElementById('sel_upload')?.value || '0');
}

function onUploadChange() {
    loadPendientes();
    loadResumen();
    loadResponsablesFiltro();
    loadEmailLog();
}

// ── Importar Excel ──────────────────────────────────

function importarExcel() {
    const file = document.getElementById('inp_excel').files[0];
    if (!file) { alert('Selecciona un archivo .xlsx'); return; }

    const btn = document.querySelector('#modalImport .modal-footer button:last-child');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Importando...'; }

    const fd = new FormData();
    fd.append('excel_file', file);

    fetch('/accounting/reneg_import_excel', {
            method: 'POST',
            body: fd,
            signal: AbortSignal.timeout(120000) // 2 minutos
        })
        .then(r => r.json())
        .then(res => {
            const div = document.getElementById('import_result');
            div.classList.remove('d-none');
            if (res.success) {
                div.innerHTML = `<div class="alert alert-success mb-0">
                    <i class="fas fa-check-circle me-1"></i>
                    Se importaron <strong>${res.total}</strong> registros correctamente.
                </div>`;
                // Recargar selector de cargas
                setTimeout(() => location.reload(), 1500);
            } else {
                div.innerHTML = `<div class="alert alert-danger mb-0">${res.message}</div>`;
            }
        })
        .catch(err => {
            document.getElementById('import_result').classList.remove('d-none');
            document.getElementById('import_result').innerHTML =
                `<div class="alert alert-danger mb-0">Error de conexión: ${err.message}</div>`;
        })
        .finally(() => {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-upload me-1"></i> Importar'; }
        });
}

// ── Pendientes ──────────────────────────────────────

let dtPendientes = null;

function loadPendientes() {
    const uploadId    = getUploadId();
    const responsable = document.getElementById('filtro_responsable')?.value || '';
    const renegociado = document.getElementById('filtro_reneg')?.value ?? '';
    if (!uploadId) return;

    fetch(`/accounting/reneg_get_pendientes?upload_id=${uploadId}&responsable=${encodeURIComponent(responsable)}&renegociado=${renegociado}`)
        .then(r => r.json())
        .then(res => {
            const rows = res.data || [];
            document.getElementById('lbl_total_pendientes').textContent = rows.length + ' registros';

            const tbody = document.getElementById('tbody_pendientes');

            // Destruir DataTable anterior si existe
            if (dtPendientes) { dtPendientes.destroy(); dtPendientes = null; }

            tbody.innerHTML = rows.map(r => {
                const done    = parseInt(r.renegociado) === 1;
                const chkHtml = `<input type="checkbox" class="form-check-input chk-reneg"
                                    data-id="${r.id}" ${done ? 'checked' : ''}
                                    onchange="toggleRenegociado(this)"
                                    title="${done ? 'Marcar como pendiente' : 'Marcar como renegociado'}">`;
                return `
                <tr class="${done ? 'reneg-done' : ''}">
                    <td class="text-center">${chkHtml}</td>
                    <td>${esc(r.oc)}</td>
                    <td>${esc(r.proveedor)}</td>
                    <td>${esc(r.razon_social)}</td>
                    <td>${esc(r.factura)}</td>
                    <td class="text-center">${fmtDate(r.fecha_factura)}</td>
                    <td class="text-end">${fmtMoney(r.total)}</td>
                    <td class="text-end">${r.importe_dlls ? fmtMoney(r.importe_dlls) : '—'}</td>
                    <td><span class="badge bg-light text-dark border">${esc(r.responsable)}</span></td>
                </tr>`;
            }).join('') || '<tr><td colspan="9" class="text-center py-4 text-muted">Sin datos.</td></tr>';

            // Inicializar DataTables con filtros en tfoot
            dtPendientes = $('#tbl_pendientes').DataTable({
                paging:   true,
                pageLength: 25,
                ordering: true,
                language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
                columnDefs: [{ orderable: false, targets: 0 }],
                initComplete: function () {
                    // Conectar inputs del tfoot a búsqueda por columna
                    this.api().columns().every(function (idx) {
                        if (idx === 0) return; // skip checkbox col
                        const col   = this;
                        const input = $('tfoot tr th').eq(idx).find('input');
                        input.on('keyup change clear', function () {
                            if (col.search() !== this.value) {
                                col.search(this.value).draw();
                            }
                        });
                    });
                }
            });

            const totalMxn = rows.reduce((s, r) => s + (parseFloat(r.total) || 0), 0);
            document.getElementById('card_total_facturas').textContent = rows.length.toLocaleString('es-MX');
            document.getElementById('card_monto_mxn').textContent = '$' + totalMxn.toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
        })
        .catch(() => {
            document.getElementById('tbody_pendientes').innerHTML =
                '<tr><td colspan="9" class="text-center text-danger py-3">Error al cargar datos.</td></tr>';
        });
}

function toggleRenegociado(chk) {
    const id    = chk.dataset.id;
    const valor = chk.checked ? 1 : 0;
    const tr    = chk.closest('tr');

    const fd = new FormData();
    fd.append('id',    id);
    fd.append('valor', valor);

    fetch('/accounting/reneg_toggle_renegociado', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                tr.classList.toggle('reneg-done', valor === 1);
                chk.title = valor === 1 ? 'Marcar como pendiente' : 'Marcar como renegociado';
            } else {
                chk.checked = !chk.checked; // revertir
            }
        })
        .catch(() => { chk.checked = !chk.checked; });
}

function loadResponsablesFiltro() {
    const uploadId = getUploadId();
    if (!uploadId) return;
    fetch(`/accounting/reneg_get_responsables?upload_id=${uploadId}`)
        .then(r => r.json())
        .then(res => {
            const sel = document.getElementById('filtro_responsable');
            const current = sel.value;
            sel.innerHTML = '<option value="">Todos los responsables</option>';
            (res.data || []).forEach(r => {
                const opt = document.createElement('option');
                opt.value = r.responsable;
                opt.textContent = r.responsable;
                if (r.responsable === current) opt.selected = true;
                sel.appendChild(opt);
            });
        });
}

// ── Contactos ───────────────────────────────────────

function loadContactos() {
    fetch('/accounting/reneg_get_contactos')
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('tbody_contactos');
            const rows  = res.data || [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center py-3 text-muted">Sin contactos.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(c => `
            <tr>
                <td>${esc(c.responsable)}</td>
                <td><small>${esc(c.correo)}</small></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-primary py-0 px-1 me-1"
                            onclick="abrirModalContacto('${esc(c.responsable)}','${esc(c.correo)}')"
                            title="Editar">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger py-0 px-1"
                            onclick="eliminarContacto(${c.id},'${esc(c.responsable)}')"
                            title="Eliminar">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>`).join('');
        });
}

function abrirModalContacto(responsable = '', correo = '') {
    document.getElementById('inp_responsable').value   = responsable;
    document.getElementById('inp_correo').value        = correo;
    document.getElementById('modalContactoTitle').textContent =
        responsable ? 'Editar Contacto' : 'Agregar Contacto';
    new bootstrap.Modal(document.getElementById('modalContacto')).show();
}

function guardarContacto() {
    const responsable = document.getElementById('inp_responsable').value.trim();
    const correo      = document.getElementById('inp_correo').value.trim();
    if (!responsable || !correo) { alert('Completa nombre y correo.'); return; }

    const fd = new FormData();
    fd.append('responsable', responsable);
    fd.append('correo', correo);

    fetch('/accounting/reneg_save_contacto', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                bootstrap.Modal.getInstance(document.getElementById('modalContacto')).hide();
                loadContactos();
                loadResumen();
            } else {
                alert(res.message || 'Error al guardar.');
            }
        });
}

function eliminarContacto(id, nombre) {
    if (!confirm(`¿Eliminar contacto "${nombre}"?`)) return;
    const fd = new FormData();
    fd.append('id', id);
    fetch('/accounting/reneg_delete_contacto', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(() => { loadContactos(); loadResumen(); });
}

// ── Resumen y envío ─────────────────────────────────

function loadResumen() {
    const uploadId = getUploadId();
    if (!uploadId) return;

    fetch(`/accounting/reneg_get_resumen?upload_id=${uploadId}`)
        .then(r => r.json())
        .then(res => {
            const rows  = res.data || [];
            const tbody = document.getElementById('tbody_resumen');

            let conCorreo = 0;
            rows.forEach(r => { if (r.tiene_correo) conCorreo++; });

            document.getElementById('card_responsables').textContent  = rows.length;
            document.getElementById('card_con_correo').textContent    = conCorreo + ' / ' + rows.length;

            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">Sin datos.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const badgeCorreo = r.tiene_correo
                    ? `<span class="badge badge-correo-ok">✓ ${esc(r.correo)}</span>`
                    : `<span class="badge badge-correo-no">✗ Sin correo</span>`;
                const chkDisabled = r.tiene_correo ? '' : 'disabled';
                return `
                <tr>
                    <td><input type="checkbox" class="chk_resp" value="${esc(r.responsable)}" ${chkDisabled}></td>
                    <td>${esc(r.responsable)}</td>
                    <td class="text-center">${r.total_facturas}</td>
                    <td class="text-end">${fmtMoney(r.monto_total)}</td>
                    <td class="text-center">${badgeCorreo}</td>
                </tr>`;
            }).join('');
        });
}

function toggleSelectAll() {
    const chkAll = document.getElementById('chk_all');
    document.querySelectorAll('.chk_resp:not(:disabled)').forEach(c => {
        c.checked = chkAll ? chkAll.checked : !c.checked;
    });
}

function enviarCorreos() {
    const seleccionados = [...document.querySelectorAll('.chk_resp:checked')]
        .map(c => c.value);
    if (!seleccionados.length) { alert('Selecciona al menos un responsable.'); return; }

    const uploadId = getUploadId();
    if (!confirm(`¿Enviar correos a ${seleccionados.length} responsable(s)?`)) return;

    const btn = event.currentTarget;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Enviando...';

    const fd = new FormData();
    fd.append('upload_id', uploadId);
    seleccionados.forEach(r => fd.append('responsables[]', r));

    fetch('/accounting/reneg_send_emails', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            let html = `
            <div class="row g-3 text-center mb-3">
                <div class="col-4">
                    <div class="fw-bold fs-4 text-success">${res.enviados}</div>
                    <div class="small text-muted">Enviados</div>
                </div>
                <div class="col-4">
                    <div class="fw-bold fs-4 text-danger">${res.errores}</div>
                    <div class="small text-muted">Errores</div>
                </div>
                <div class="col-4">
                    <div class="fw-bold fs-4 text-warning">${res.sin_correo}</div>
                    <div class="small text-muted">Sin correo</div>
                </div>
            </div>`;
            if (res.detalle && res.detalle.length) {
                html += '<table class="table table-sm"><thead><tr><th>Responsable</th><th>Estado</th></tr></thead><tbody>';
                res.detalle.forEach(d => {
                    const badge = d.status === 'sent'
                        ? '<span class="badge bg-success">Enviado</span>'
                        : d.status === 'error'
                        ? '<span class="badge bg-danger">Error</span>'
                        : '<span class="badge bg-warning text-dark">Sin correo</span>';
                    html += `<tr><td>${esc(d.responsable)}</td><td>${badge}</td></tr>`;
                });
                html += '</tbody></table>';
            }
            document.getElementById('envio_result_body').innerHTML = html;
            new bootstrap.Modal(document.getElementById('modalEnvioResult')).show();
            loadEmailLog();
        })
        .catch(() => alert('Error de conexión al enviar correos.'))
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Enviar seleccionados';
        });
}

// ── Log de envíos ───────────────────────────────────

function loadEmailLog() {
    const uploadId = getUploadId();
    fetch(`/accounting/reneg_get_email_log${uploadId ? '?upload_id=' + uploadId : ''}`)
        .then(r => r.json())
        .then(res => {
            const tbody = document.getElementById('tbody_log');
            const rows  = res.data || [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-2">Sin envíos registrados.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(r => {
                const badge = r.status === 'sent'
                    ? '<span class="badge bg-success">Enviado</span>'
                    : '<span class="badge bg-danger">Error</span>';
                return `
                <tr>
                    <td><small>${fmtDateTime(r.sent_at)}</small></td>
                    <td>${esc(r.responsable)}</td>
                    <td><small>${esc(r.correo)}</small></td>
                    <td class="text-center">${r.total_facturas}</td>
                    <td class="text-center">${badge}</td>
                </tr>`;
            }).join('');
        });
}

// ── Utilidades ──────────────────────────────────────

function esc(v) {
    if (v === null || v === undefined) return '—';
    return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;');
}

function fmtMoney(v) {
    const n = parseFloat(v);
    if (isNaN(n)) return '—';
    return '$' + n.toLocaleString('es-MX', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function fmtDate(v) {
    if (!v) return '—';
    const d = new Date(v + 'T00:00:00');
    return isNaN(d) ? v : d.toLocaleDateString('es-MX', {day:'2-digit',month:'2-digit',year:'numeric'});
}

function fmtDateTime(v) {
    if (!v) return '—';
    const d = new Date(v);
    return isNaN(d) ? v : d.toLocaleDateString('es-MX',{day:'2-digit',month:'2-digit',year:'numeric'})
                          + ' ' + d.toLocaleTimeString('es-MX',{hour:'2-digit',minute:'2-digit'});
}
