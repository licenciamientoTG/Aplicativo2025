import re

with open(r'C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp\views\payment\payment_list.html', 'r', encoding='utf-8') as f:
    content = f.read()

# Find the start of {% block content %} and end at {% endblock %} before myjs
# Keep everything before block content, replace block content, keep myjs block
block_start = content.index('{% block content %}') + len('{% block content %}')
block_end = content.index('{% endblock %}', block_start)

before = content[:content.index('{% block content %}') + len('{% block content %}')]
after_endblock = content[block_end:]  # starts with {% endblock %}

# Read the modals section — everything between the last modal and {% endblock %}
# We need to preserve the modals. Let's find where the modals section starts.
# The modals are after the tab-content closing. Let's grab from the original.
original_block = content[block_start:block_end]

# Extract modals — find the first <div class="modal fade" ...
modal_start_idx = original_block.find('<div class="modal fade"')
if modal_start_idx == -1:
    modal_start_idx = original_block.find('<!-- Modal')
modals_content = original_block[modal_start_idx:] if modal_start_idx != -1 else ''

new_block = r"""
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var sidebar = document.getElementById('sidebar');
        if (sidebar && !sidebar.classList.contains('collapsed')) {
            sidebar.classList.add('collapsed');
        }
    });
</script>

<style>
    .pl-tab-link { border-bottom: 2px solid transparent; color: #64748b; background: none; border-top:none; border-left:none; border-right:none; padding: 0.75rem 0; font-size: 0.875rem; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: color .15s, border-color .15s; white-space: nowrap; }
    .pl-tab-link:hover { color: #1e293b; }
    .pl-tab-link.active { border-bottom-color: #3d6b9e; color: #3d6b9e; }
    .pl-tab-content { display: none; }
    .pl-tab-content.active { display: block; }

    #payment_list_table thead th,
    #tabla_anticipos thead th { background-color: #f8fafc !important; color: #475569 !important; font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; border-bottom: 1px solid #e2e8f0 !important; }
    #tabla_facturas_autorizadas thead th { background-color: #f8fafc !important; color: #475569 !important; font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; font-weight: 700; }

    .auth-box { width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; border-radius: 5px; font-size: 12px; transition: all .2s; }
    .auth-box.bg-success { background-color: #dcfce7 !important; color: #16a34a !important; }
    .auth-box.bg-warning { background-color: #fef9c3 !important; color: #ca8a04 !important; }
    .auth-box.bg-info { background-color: #dbeafe !important; color: #2563eb !important; }
    .auth-box.bg-secondary { background-color: #f1f5f9 !important; color: #94a3b8 !important; }

    #collapseAprobacionMasiva { transition: all .3s ease; }
    .pipeline-card { display: flex; align-items: center; justify-content: space-between; padding: .75rem 1rem; border-radius: .5rem; border: 1px solid; }
    .pipeline-card.active-level { background: #eff6ff; border-color: #bfdbfe; }
    .pipeline-card.locked-level { background: #fff; border-color: #e2e8f0; opacity: .7; }
    .pipeline-icon { width: 2.2rem; height: 2.2rem; border-radius: .4rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

    .table tbody tr:hover { background-color: #f8fafc; }
    .table tbody tr td { font-size: .82rem; vertical-align: middle; color: #334155; border-color: #f1f5f9; }
    .badge.status-pending { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
    .badge.status-authorized { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
    .badge.status-paid { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
    .badge.status-cancelled { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

    #tabla_facturas_autorizadas .group-header { background-color: #f8fafc !important; font-weight: 700; border-top: 2px solid #e2e8f0; }
    .badge-updated { animation: pulse-badge .4s ease; }
    @keyframes pulse-badge { 0%,100%{transform:scale(1)} 50%{transform:scale(1.1)} }
    .badge[style*="C9302C"] { background-color: #C9302C !important; }
    .badge[style*="EC1C24"] { background-color: #EC1C24 !important; }
    .table-warning { --bs-table-bg: #fffbeb !important; }
</style>

<!-- PIPELINE DE APROBACIÓN MASIVA -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white border-bottom px-3 py-2 d-flex justify-content-between align-items-center" style="cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#collapseAprobacionMasiva" aria-expanded="false">
        <span class="d-flex align-items-center gap-2 fw-medium" style="font-size:.85rem;color:#334155;">
            <i class="fas fa-shield-alt text-warning"></i> Aprobación Masiva de Pagos
        </span>
        <i class="fas fa-chevron-down text-muted" style="font-size:.75rem;transition:transform .3s;"></i>
    </div>
    <div class="collapse" id="collapseAprobacionMasiva">
        <div class="card-body p-3">
            <div class="row g-3">

                <!-- Abastos -->
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="pipeline-card active-level" id="card-abastos">
                        <div class="d-flex align-items-center gap-3">
                            <div class="pipeline-icon" style="background:#dbeafe;color:#2563eb;"><i class="fas fa-truck" style="font-size:.9rem;"></i></div>
                            <div>
                                <p class="mb-0 fw-semibold text-dark" style="font-size:.85rem;">Abastos</p>
                                <p class="mb-0 text-muted" style="font-size:.72rem;">Nivel 1</p>
                            </div>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <span class="badge fw-bold px-2" style="background:#fbbf24;color:#1c1917;font-size:.72rem;" id="count-abastos">0</span>
                            {% if authorized(66) %}
                            <button class="btn btn-link p-0 fw-medium" style="font-size:.72rem;color:#2563eb;" onclick="abrirModalAprobacionMasiva(66, 'Abastos')" id="btn-aprobar-abastos" disabled>Aprobar</button>
                            {% endif %}
                        </div>
                    </div>
                </div>

                <!-- Contabilidad -->
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="pipeline-card locked-level" id="card-contabilidad">
                        <div class="d-flex align-items-center gap-3">
                            <div class="pipeline-icon" style="background:#f1f5f9;color:#64748b;"><i class="fas fa-calculator" style="font-size:.9rem;"></i></div>
                            <div>
                                <p class="mb-0 fw-semibold text-dark" style="font-size:.85rem;">Contabilidad</p>
                                <p class="mb-0 text-muted" style="font-size:.72rem;">Nivel 2</p>
                            </div>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <span class="badge fw-bold px-2" style="background:#e2e8f0;color:#475569;font-size:.72rem;" id="count-contabilidad">0</span>
                            {% if authorized(70) %}
                            <button class="btn btn-link p-0 fw-medium" style="font-size:.72rem;color:#2563eb;" onclick="abrirModalAprobacionMasiva(70, 'Contabilidad')" id="btn-aprobar-contabilidad" disabled>Aprobar</button>
                            <button class="btn btn-link p-0 fw-medium" style="font-size:.72rem;color:#64748b;" onclick="abrirModalCrearArchivoContabilidad()">Crear Archivo</button>
                            {% endif %}
                        </div>
                    </div>
                </div>

                <!-- Admin & Finanzas -->
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="pipeline-card locked-level" id="card-admin">
                        <div class="d-flex align-items-center gap-3">
                            <div class="pipeline-icon" style="background:#f1f5f9;color:#64748b;"><i class="fas fa-building" style="font-size:.9rem;"></i></div>
                            <div>
                                <p class="mb-0 fw-semibold text-dark" style="font-size:.85rem;">Admin & Finanzas</p>
                                <p class="mb-0 text-muted" style="font-size:.72rem;">Nivel 3</p>
                            </div>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <span class="badge fw-bold px-2" style="background:#e2e8f0;color:#475569;font-size:.72rem;" id="count-admin">0</span>
                            {% if authorized(67) %}
                            <button class="btn btn-link p-0 fw-medium" style="font-size:.72rem;color:#2563eb;" onclick="abrirModalAprobacionMasiva(67, 'Administración y Finanzas')" id="btn-aprobar-admin" disabled>Aprobar</button>
                            {% endif %}
                        </div>
                    </div>
                </div>

                <!-- Tesorería -->
                <div class="col-12 col-sm-6 col-lg-3">
                    <div class="pipeline-card locked-level" id="card-tesoreria">
                        <div class="d-flex align-items-center gap-3">
                            <div class="pipeline-icon" style="background:#f1f5f9;color:#64748b;"><i class="fas fa-landmark" style="font-size:.9rem;"></i></div>
                            <div>
                                <p class="mb-0 fw-semibold text-dark" style="font-size:.85rem;">Tesorería</p>
                                <p class="mb-0 text-muted" style="font-size:.72rem;">Nivel 4</p>
                            </div>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <span class="badge fw-bold px-2" style="background:#e2e8f0;color:#475569;font-size:.72rem;" id="count-tesoreria">0</span>
                            {% if authorized(68) %}
                            <button class="btn btn-link p-0 fw-medium" style="font-size:.72rem;color:#2563eb;" onclick="abrirModalAprobacionMasiva(68, 'Tesorería')" id="btn-aprobar-tesoreria" disabled>Aprobar</button>
                            <button class="btn btn-link p-0 fw-medium" style="font-size:.72rem;color:#64748b;" onclick="abrirModalAutorizarPagoMasivo()">Autorizar Pago</button>
                            {% endif %}
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- CARD PRINCIPAL: Tabs + Toolbar + Tabla -->
<div class="card border-0 shadow-sm">

    <!-- Tabs -->
    <div class="px-3 border-bottom d-flex gap-4" style="overflow-x:auto;">
        <button class="pl-tab-link active" onclick="plTab(this,'pl-tab-pagos')">
            <i class="fas fa-list"></i> Pagos
        </button>
        <button class="pl-tab-link" onclick="plTab(this,'pl-tab-anticipos')">
            <i class="fas fa-hand-holding-usd"></i> Anticipos
        </button>
        <button class="pl-tab-link" onclick="plTab(this,'pl-tab-facturas-auth')">
            <i class="fas fa-file-invoice-dollar"></i> Facturas Autorizadas
        </button>
        {% if authorized(70) %}
        <button class="pl-tab-link" onclick="plTab(this,'pl-tab-archivos'); loadAccountingGroupsTable();" id="tab_archivos_contabilidad_link">
            <i class="fas fa-folder-open"></i> Archivos Contabilidad
        </button>
        {% endif %}
    </div>

    <!-- ===== TAB: PAGOS ===== -->
    <div id="pl-tab-pagos" class="pl-tab-content active">

        <!-- Toolbar -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 px-3 py-3 border-bottom" style="background:#f8fafc;">
            <div class="d-flex align-items-center gap-2 w-100" style="max-width:480px;">
                <div class="position-relative flex-grow-1">
                    <i class="fas fa-search position-absolute" style="left:.7rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:.8rem;"></i>
                    <input type="text" id="search_pagos" placeholder="Buscar pago, proveedor..." class="form-control form-control-sm" style="padding-left:2.1rem;border-radius:.5rem;border-color:#cbd5e1;" oninput="loadPaymentList()">
                </div>
                <div class="d-flex align-items-center gap-2">
                    <select class="form-select form-select-sm" id="status_filter" style="border-radius:.5rem;border-color:#cbd5e1;font-size:.8rem;min-width:130px;">
                        <option value="all">Todos los estados</option>
                        <option value="pending">Pendiente</option>
                        <option value="authorized">Autorizado</option>
                        <option value="paid">Pagado</option>
                        <option value="cancelled">Cancelado</option>
                    </select>
                    <button onclick="loadPaymentList()" class="btn btn-sm border" style="border-color:#cbd5e1;color:#64748b;border-radius:.5rem;">
                        <i class="fas fa-filter" style="font-size:.8rem;"></i>
                    </button>
                </div>
            </div>
            <a href="/payment/add_payment" class="btn btn-sm d-flex align-items-center gap-1 fw-medium" style="background:#16a34a;color:#fff;border-radius:.5rem;font-size:.82rem;padding:.4rem .9rem;">
                <i class="fas fa-plus" style="font-size:.75rem;"></i> Nuevo Pago
            </a>
        </div>

        <!-- Tabla -->
        <div class="table-responsive">
            <table id="payment_list_table" class="table table-hover table-sm w-100 dataTable mb-0">
                <thead>
                    <tr>
                        <th>ID / Empresa</th>
                        <th>Fechas</th>
                        <th>Proveedor</th>
                        <th>Usuario</th>
                        <th class="text-end">Facturas</th>
                        <th class="text-end">Total</th>
                        <th class="text-end" title="Notas de Crédito">NC</th>
                        <th class="text-end" title="Notas de Débito">ND</th>
                        <th class="text-end">Monto Final</th>
                        <th class="text-end">Pagado</th>
                        <th class="text-center">Autorizadas</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center">Autorizaciones</th>
                        <th>Comentario</th>
                        <th class="text-center">Acciones</th>
                        {% if authorized(999) %}<th></th>{% endif %}
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- ===== TAB: ANTICIPOS ===== -->
    <div id="pl-tab-anticipos" class="pl-tab-content">

        <!-- Toolbar -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 px-3 py-3 border-bottom" style="background:#f8fafc;">
            <div class="d-flex align-items-center gap-2">
                <select class="form-select form-select-sm" id="status_filter_anticipos" style="border-radius:.5rem;border-color:#cbd5e1;font-size:.8rem;min-width:130px;">
                    <option value="all">Todos los estados</option>
                    <option value="pending">Pendiente</option>
                    <option value="authorized">Autorizado</option>
                    <option value="paid">Pagado</option>
                    <option value="cancelled">Cancelado</option>
                </select>
                <button onclick="loadAnticiposList()" class="btn btn-sm border" style="border-color:#cbd5e1;color:#64748b;border-radius:.5rem;">
                    <i class="fas fa-filter" style="font-size:.8rem;"></i>
                </button>
            </div>
            <button onclick="abrirModalCrearAnticipo()" class="btn btn-sm d-flex align-items-center gap-1 fw-medium" style="background:#16a34a;color:#fff;border-radius:.5rem;font-size:.82rem;padding:.4rem .9rem;">
                <i class="fas fa-plus" style="font-size:.75rem;"></i> Nuevo Anticipo
            </button>
        </div>

        <!-- Tabla -->
        <div class="table-responsive">
            <table id="tabla_anticipos" class="table table-hover table-sm w-100 mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Fecha Solicitud</th>
                        <th>Empresa</th>
                        <th>Proveedor</th>
                        <th>Usuario</th>
                        <th>Aplicaciones</th>
                        <th class="text-end">Monto Total</th>
                        <th class="text-end">Aplicado</th>
                        <th class="text-end">Saldo</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center">Autorizaciones</th>
                        <th>Comentario</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <!-- ===== TAB: FACTURAS AUTORIZADAS ===== -->
    <div id="pl-tab-facturas-auth" class="pl-tab-content">
        <div class="p-3">

            <!-- Resumen por banco -->
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4">
                    <div class="d-flex flex-column justify-content-center p-3 rounded-3" style="background:#fef2f2;border:1px solid #fecaca;">
                        <p class="mb-1 text-uppercase fw-semibold" style="font-size:.65rem;color:#dc2626;letter-spacing:.06em;"><i class="fas fa-university me-1"></i>Banorte</p>
                        <p class="mb-0 fw-bold" style="font-size:1.1rem;color:#b91c1c;" id="totalBanorte">$0.00</p>
                        <small style="color:#ef4444;" id="facturasBanorte">0 facturas</small>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="d-flex flex-column justify-content-center p-3 rounded-3" style="background:#fef2f2;border:1px solid #fecaca;">
                        <p class="mb-1 text-uppercase fw-semibold" style="font-size:.65rem;color:#dc2626;letter-spacing:.06em;"><i class="fas fa-university me-1"></i>Santander</p>
                        <p class="mb-0 fw-bold" style="font-size:1.1rem;color:#b91c1c;" id="totalSantander">$0.00</p>
                        <small style="color:#ef4444;" id="facturasSantander">0 facturas</small>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="d-flex flex-column justify-content-center p-3 rounded-3" style="background:#eff6ff;border:1px solid #bfdbfe;">
                        <p class="mb-1 text-uppercase fw-semibold" style="font-size:.65rem;color:#2563eb;letter-spacing:.06em;"><i class="fas fa-check-double me-1"></i>Seleccionadas</p>
                        <p class="mb-0 fw-bold" style="font-size:1.1rem;color:#1d4ed8;" id="totalSeleccionadas">$0.00</p>
                        <small style="color:#3b82f6;" id="facturasSeleccionadas">0 facturas</small>
                    </div>
                </div>
            </div>

            <!-- Filtros y acciones (se mantiene igual, solo el card wrapper cambia) -->
            <div id="cabecera_pagos_wrapper">
""" + r"""
            </div>

        </div>

        <!-- La tabla de facturas autorizadas se renderiza aquí por JS -->
        <div class="table-responsive">
            <table id="tabla_facturas_autorizadas" class="table table-hover table-sm w-100 mb-0">
                <thead>
                    <tr>
                        <th></th>
                        <th>UUID / Folio</th>
                        <th>Empresa</th>
                        <th>Proveedor</th>
                        <th>Estación</th>
                        <th>Banco</th>
                        <th class="text-end">Monto</th>
                        <th>Fecha Venc.</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    {% if authorized(70) %}
    <!-- ===== TAB: ARCHIVOS CONTABILIDAD ===== -->
    <div id="pl-tab-archivos" class="pl-tab-content">
        <div class="table-responsive">
            <table id="tabla_archivos_contabilidad" class="table table-hover table-sm w-100 mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Fecha</th>
                        <th>Pagos Incluidos</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    {% endif %}

</div>

<script>
function plTab(btn, tabId) {
    document.querySelectorAll('.pl-tab-link').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.pl-tab-content').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(tabId).classList.add('active');
    // Sync old Bootstrap tab IDs for JS compatibility
    const map = {
        'pl-tab-pagos': 'tab_pagos',
        'pl-tab-anticipos': 'tab_anticipos',
        'pl-tab-facturas-auth': 'tab_facturas_autorizadas',
        'pl-tab-archivos': 'tab_archivos_contabilidad'
    };
}
// Also handle chevron rotation for pipeline collapse
document.addEventListener('DOMContentLoaded', function() {
    var collapseEl = document.getElementById('collapseAprobacionMasiva');
    if (collapseEl) {
        collapseEl.addEventListener('show.bs.collapse', function() {
            document.querySelector('[data-bs-target="#collapseAprobacionMasiva"] .fa-chevron-down').style.transform = 'rotate(180deg)';
        });
        collapseEl.addEventListener('hide.bs.collapse', function() {
            document.querySelector('[data-bs-target="#collapseAprobacionMasiva"] .fa-chevron-down').style.transform = 'rotate(0deg)';
        });
    }
});
</script>

"""

# Now append modals
new_block += modals_content

result = before + new_block + after_endblock
with open(r'C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp\views\payment\payment_list.html', 'w', encoding='utf-8') as f:
    f.write(result)

print("Done. Lines:", result.count('\n'))
