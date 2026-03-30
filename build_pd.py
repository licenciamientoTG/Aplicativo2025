import sys

with open(r'C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp\views\payment\payment_detail.html', 'rb') as f:
    lines = f.read().decode('utf-8').splitlines()

# Keep modals from line 1090 onwards (0-indexed: 1089)
modals = '\n'.join(lines[1089:])

# Transactions tbody (lines 998-1069, 0-indexed 997-1068)
tx_body = '\n'.join(lines[997:1069])

new_top = r"""{# payment_detail.html #}
{% extends "views/layouts/base.html" %}
{% block title %}Detalle de Pago #{{ payment.id }}{% endblock %}
{% block content %}
	<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

	<style>
		.pd-tab-link { border-bottom: 2px solid transparent; color: #6c757d; background: none; border-top:none; border-left:none; border-right:none; padding: 0.75rem 0; font-size: 0.875rem; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 0.4rem; transition: color .15s, border-color .15s; }
		.pd-tab-link:hover { color: #343a40; }
		.pd-tab-link.active { border-bottom-color: #3d6b9e; color: #3d6b9e; }
		.pd-tab-content { display: none; }
		.pd-tab-content.active { display: block; }
		#tablaFacturasIncluidas tr.table-success { background-color: #d1fae5 !important; }
		#tablaFacturasIncluidas tr.table-success:hover { background-color: #a7f3d0 !important; }
		#tablaAutorizarPago tr.table-success { background-color: #d1fae5 !important; }
		#tablaAutorizarPago tr.table-success td { opacity: 0.8; }
		.monto-autorizar:focus { border-color: #3d6b9e; box-shadow: 0 0 0 .2rem rgba(61,107,158,.25); }
		input[readonly] { cursor: not-allowed; }
		.pd-footer-spacer { height: 4.5rem; }
		#pd-sticky-footer { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #dee2e6; box-shadow: 0 -2px 8px rgba(0,0,0,.07); z-index: 1040; }
		.auth-step { display: flex; align-items: flex-start; gap: 1rem; padding: 0.6rem 0; }
		.auth-step-icon { width: 2.1rem; height: 2.1rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.85rem; }
		@keyframes fadeIn { from { opacity:0; transform:scale(.8); } to { opacity:1; transform:scale(1); } }
		#tablaFacturasIncluidas .fa-check-circle { animation: fadeIn .5s ease-in; }
	</style>

	{% set total_facturas = invoices|length %}
	{% set facturas_autorizadas = 0 %}
	{% for invoice in invoices %}{% if invoice.payment_authorized == 1 %}{% set facturas_autorizadas = facturas_autorizadas + 1 %}{% endif %}{% endfor %}

	<!-- HEADER COMPRIMIDO -->
	<div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #3d6b9e !important;">
		<div class="card-body py-2 px-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
			<div class="d-flex align-items-center gap-3">
				<a href="/payment/payment_list" class="btn btn-sm btn-light border">
					<i class="fas fa-arrow-left"></i>
				</a>
				<div>
					<span class="fw-semibold" style="font-size:1rem;">Pago Programado #{{ payment.id }}</span>
					&nbsp;
					{% if payment.status == 0 %}
						<span class="badge" style="background:#fff3cd;color:#856404;border:1px solid #ffc107;font-weight:600;">Pendiente Autorización</span>
					{% elseif payment.status == 1 %}
						<span class="badge" style="background:#cfe2ff;color:#084298;border:1px solid #9ec5fe;font-weight:600;">Autorizado - Listo para Pagar</span>
					{% elseif payment.status == 2 %}
						<span class="badge" style="background:#d1e7dd;color:#0a3622;border:1px solid #a3cfbb;font-weight:600;">Pagado</span>
					{% else %}
						<span class="badge bg-danger">Cancelado</span>
					{% endif %}
				</div>
			</div>
			<div class="d-flex gap-3 text-muted flex-wrap" style="font-size:0.82rem;">
				<span><i class="fas fa-user fa-xs me-1"></i>{{ payment.usuario_nombre ?? 'N/A' }}</span>
				<span><i class="fas fa-clock fa-xs me-1"></i>{{ payment.request_date|date('d/m/Y H:i') }}</span>
				{% if payment.comment %}<span><i class="fas fa-comment fa-xs me-1"></i>{{ payment.comment }}</span>{% endif %}
			</div>
		</div>
	</div>

	<!-- MÉTRICAS -->
	<div class="row g-2 mb-3">
		<div class="col-6 col-md-3">
			<div class="card border-0 shadow-sm h-100" style="border-left:4px solid #3d6b9e !important;">
				<div class="card-body py-2 px-3">
					<p class="mb-1 text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;">Total Facturas</p>
					<p class="mb-0 fw-bold text-dark" style="font-size:1.05rem;">${{ summary.total_amount|number_format(2) }}</p>
					<small class="text-muted">{{ summary.total_invoices }} factura(s)</small>
				</div>
			</div>
		</div>
		<div class="col-6 col-md-3">
			<div class="card border-0 shadow-sm h-100" style="border-left:4px solid #6c757d !important;">
				<div class="card-body py-2 px-3">
					<p class="mb-1 text-muted" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;">Notas (Créd / Cargo)</p>
					<p class="mb-0 fw-bold" style="font-size:1.05rem;">
						<span style="color:#3a7d60;">-${{ notes_totals.total_credits|number_format(2) }}</span>
						&nbsp;/&nbsp;
						<span style="color:#b05c3a;">+${{ notes_totals.total_debits|number_format(2) }}</span>
					</p>
					<small class="text-muted">Ajustes aplicados</small>
				</div>
			</div>
		</div>
		<div class="col-6 col-md-3">
			<div class="card border-0 shadow-sm h-100" style="border-left:4px solid #3a7d60 !important;">
				<div class="card-body py-2 px-3">
					<p class="mb-1" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:#3a7d60;">Pagado</p>
					<p class="mb-0 fw-bold" style="font-size:1.05rem;color:#3a7d60;">${{ summary.total_paid|number_format(2) }}</p>
					<small class="text-muted">Abonos realizados</small>
				</div>
			</div>
		</div>
		<div class="col-6 col-md-3">
			{% set pendiente = payment_calculation.final_amount - summary.total_paid %}
			<div class="card border-0 shadow-sm h-100" style="border-left:4px solid {% if pendiente <= 0 %}#3a7d60{% else %}#8b1a1a{% endif %} !important;">
				<div class="card-body py-2 px-3">
					<p class="mb-1" style="font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:{% if pendiente <= 0 %}#3a7d60{% else %}#8b1a1a{% endif %};">Saldo Pendiente</p>
					<p class="mb-0 fw-bold" style="font-size:1.05rem;color:{% if pendiente <= 0 %}#3a7d60{% else %}#8b1a1a{% endif %};">${{ pendiente|number_format(2) }}</p>
					<small class="text-muted">{% if pendiente <= 0 %}Liquidado{% else %}Por pagar{% endif %}</small>
				</div>
			</div>
		</div>
	</div>

	<!-- TABS -->
	<div class="card border-0 shadow-sm">
		<div class="px-3 border-bottom d-flex gap-4" style="overflow-x:auto;">
			<button class="pd-tab-link active" onclick="pdTab(this,'tab-facturas')">
				<i class="fas fa-file-invoice-dollar"></i> Facturas
				<span class="badge bg-secondary ms-1">{{ total_facturas }}</span>
			</button>
			<button class="pd-tab-link" onclick="pdTab(this,'tab-notas')">
				<i class="fas fa-receipt"></i> Notas de Crédito/Cargo
			</button>
			<button class="pd-tab-link" onclick="pdTab(this,'tab-auth')">
				<i class="fas fa-shield-alt"></i> Autorizaciones
				<span class="badge ms-1" style="background:{% if authorization_status.completed %}#d1e7dd;color:#0a3622{% else %}#fff3cd;color:#856404{% endif %};">
					{% set done_count = (authorization_status.abastos ? 1 : 0) + (authorization_status.contabilidad ? 1 : 0) + (authorization_status.admin_finanzas ? 1 : 0) + (authorization_status.tesoreria ? 1 : 0) %}{{ done_count }}/4
				</span>
			</button>
			{% if transactions and transactions|length > 0 %}
			<button class="pd-tab-link" onclick="pdTab(this,'tab-transacciones')">
				<i class="fas fa-money-check-alt"></i> Transacciones
				<span class="badge bg-secondary ms-1">{{ transactions|length }}</span>
			</button>
			{% endif %}
		</div>

		<!-- Tab: Facturas -->
		<div id="tab-facturas" class="pd-tab-content active">
			<div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
				<div class="d-flex gap-2">
					<span class="badge bg-secondary">Total: {{ total_facturas }}</span>
					<span class="badge" style="background:#d1e7dd;color:#0a3622;">Autorizadas: {{ facturas_autorizadas }}</span>
					<span class="badge" style="background:#fff3cd;color:#856404;">Pendientes: {{ total_facturas - facturas_autorizadas }}</span>
				</div>
				{% if payment.status == 0 or payment.status == 1 %}
				<button class="btn btn-sm btn-outline-secondary" onclick="window.location.href='/payment/add_more_invoices_to_payment?payment_id={{ payment.id }}'">
					<i class="fas fa-plus"></i> Agregar Factura
				</button>
				{% endif %}
			</div>
			<div class="table-responsive">
				<table class="table table-sm table-hover mb-0" id="tablaFacturasIncluidas">
					<thead class="table-light" style="font-size:.78rem;">
						<tr>
							<th width="32"><i class="fas fa-check-circle text-success" title="Autorizada"></i></th>
							<th>Folio / Factura</th>
							<th>Proveedor</th>
							<th>Estación</th>
							<th class="text-end">Monto</th>
							<th class="text-end">Saldo</th>
							<th class="text-end">Saldo Neto</th>
							<th>Estado</th>
							<th>Vencimiento</th>
							<th>Acciones</th>
						</tr>
					</thead>
					<tbody>
					{% set total_monto = 0 %}
					{% set total_pagado_calc = 0 %}
					{% set total_nc = 0 %}
					{% set total_nd = 0 %}
					{% set total_autorizado_tabla = 0 %}
					{% for invoice in invoices %}
						{% set saldo = invoice.amount - invoice.paid_amount %}
						{% set autorizada = invoice.payment_authorized == 1 %}
						{% set neto_notas = invoice.total_notas_cargo - invoice.total_notas_credito %}
						{% set saldo_neto = saldo + neto_notas %}
						{% set total_monto = total_monto + invoice.amount %}
						{% set total_pagado_calc = total_pagado_calc + invoice.paid_amount %}
						{% set total_nc = total_nc + invoice.total_notas_credito %}
						{% set total_nd = total_nd + invoice.total_notas_cargo %}
						{% if autorizada %}{% set total_autorizado_tabla = total_autorizado_tabla + invoice.authorized_amount %}{% endif %}
						<tr{% if autorizada %} class="table-success"{% endif %}>
							<td class="text-center">
								{% if autorizada %}<i class="fas fa-check-circle text-success"></i>{% else %}<i class="fas fa-circle fa-sm text-muted"></i>{% endif %}
							</td>
							<td>
								<strong>{{ invoice.folio }}</strong>
								<br><small class="text-muted">{{ invoice.invoice_number }}</small>
								{% if autorizada %}<br><small class="text-muted">Auth: ${{ invoice.authorized_amount|number_format(2) }} &middot; {{ invoice.authorized_at|date('d/m/Y') }}</small>{% endif %}
							</td>
							<td style="max-width:150px;" class="text-truncate" title="{{ invoice.proveedor_nombre }}">{{ invoice.proveedor_nombre }}</td>
							<td>{{ invoice.estacion_nombre }}</td>
							<td class="text-end">${{ invoice.amount|number_format(2) }}</td>
							<td class="text-end">
								{% if saldo > 0 %}<strong class="text-danger">${{ saldo|number_format(2) }}</strong>{% else %}<span class="text-success">$0.00</span>{% endif %}
							</td>
							<td class="text-end">
								{% if saldo_neto > 0 %}<strong class="text-danger">${{ saldo_neto|number_format(2) }}</strong>
								{% elseif saldo_neto < 0 %}<strong class="text-warning">${{ saldo_neto|number_format(2) }}</strong>
								{% else %}<span class="text-success">$0.00</span>{% endif %}
							</td>
							<td>
								{% if invoice.status == 2 %}<span class="badge" style="background:#d1e7dd;color:#0a3622;">Pagado</span>
								{% elseif invoice.status == 3 %}<span class="badge bg-secondary">Pago Parcial</span>
								{% else %}<span class="badge bg-secondary">Pendiente</span>{% endif %}
							</td>
							<td>
								{% if invoice.expiration_date %}{{ invoice.expiration_date|date('d/m/Y') }}{% else %}<span class="text-muted">-</span>{% endif %}
							</td>
							<td class="text-center" style="white-space:nowrap;">
								<button class="btn btn-sm btn-outline-secondary" onclick="verHistorialPagos({{ invoice.id }}, '{{ invoice.folio }}')" title="Historial"><i class="fas fa-history"></i></button>
								{% if payment.status == 0 or payment.status == 1 and invoice.paid_amount == 0 %}
								<button class="btn btn-sm btn-outline-danger" onclick="removeInvoiceFromPayment({{ invoice.id }}, '{{ invoice.folio }}')" title="Quitar"><i class="fas fa-times"></i></button>
								{% endif %}
							</td>
						</tr>
					{% endfor %}
					</tbody>
					{% set total_saldo = total_monto - total_pagado_calc %}
					{% set total_neto_notas = total_nd - total_nc %}
					{% set total_saldo_neto = total_saldo + total_neto_notas %}
					<tfoot class="table-light" style="font-size:.82rem;">
						<tr>
							<th colspan="4" class="text-end text-muted">TOTALES:</th>
							<th class="text-end">${{ total_monto|number_format(2) }}</th>
							<th class="text-end"><strong class="text-danger">${{ total_saldo|number_format(2) }}</strong></th>
							<th class="text-end">
								{% if total_saldo_neto > 0 %}<strong class="text-danger">${{ total_saldo_neto|number_format(2) }}</strong>{% else %}<span class="text-success">$0.00</span>{% endif %}
							</th>
							<th colspan="3"></th>
						</tr>
						<tr>
							<th colspan="4" class="text-end text-muted">MONTO AUTORIZADO:</th>
							<th class="text-end" colspan="3"><strong class="text-success">${{ total_autorizado_tabla|number_format(2) }}</strong></th>
							<th colspan="3"><small class="text-muted">{{ facturas_autorizadas }} de {{ total_facturas }} autorizadas</small></th>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>

		<!-- Tab: Notas -->
		<div id="tab-notas" class="pd-tab-content">
			<div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
				<span class="text-muted" style="font-size:.85rem;">Notas aplicadas a este pago</span>
				<div class="d-flex gap-2">
					<button class="btn btn-sm btn-outline-secondary" onclick="openApplyCreditNoteModal()"><i class="fas fa-link"></i> Aplicar Nota</button>
					<button class="btn btn-sm btn-outline-secondary" onclick="addNoteModal()"><i class="fas fa-plus"></i> Nueva Nota</button>
				</div>
			</div>
			<div class="table-responsive">
				<table class="table table-sm table-hover mb-0" id="noteApplicationsTable">
					<thead class="table-light" style="font-size:.78rem;">
						<tr>
							<th class="text-center">#ID</th>
							<th class="text-center">Tipo</th>
							<th>Número de Nota</th>
							<th class="text-center">Fecha</th>
							<th>Factura</th>
							<th class="text-end">Monto Aplicado</th>
							<th class="text-center">Acciones</th>
						</tr>
					</thead>
					<tbody class="text-center">
						{% if note_applications and note_applications|length > 0 %}
							{% for app in note_applications %}
								<tr class="{{ app.note_type == 'CREDIT' ? 'table-success' : 'table-warning' }}">
									<td class="text-muted small">{{ app.credit_note_id }}</td>
									<td>
										{% if app.note_type == 'CREDIT' %}<span class="badge bg-success"><i class="fas fa-minus-circle"></i> Crédito</span>
										{% else %}<span class="badge bg-secondary"><i class="fas fa-plus-circle"></i> Cargo</span>{% endif %}
									</td>
									<td>{{ app.note_number ?: '—' }}</td>
									<td>{{ app.note_date|date('d/m/Y') }}</td>
									<td>{% if app.invoice_folio %}{{ app.invoice_folio }} — {{ app.invoice_number }}{% else %}<span class="text-muted small">Sin factura</span>{% endif %}</td>
									<td class="text-end">{{ app.note_type == 'CREDIT' ? '-' : '+' }} ${{ app.applied_amount|number_format(2) }}</td>
									<td style="white-space:nowrap;">
										{% if app.documents_count > 0 %}
											<button class="btn btn-sm btn-outline-secondary view-note-docs-btn me-1" data-note-id="{{ app.credit_note_id }}" title="Ver PDF(s)"><i class="fas fa-file-pdf"></i> {{ app.documents_count }}</button>
										{% endif %}
										<button class="btn btn-sm btn-outline-secondary me-1" onclick="openUploadDocModalPD({{ app.credit_note_id }})" title="Subir PDF"><i class="fas fa-upload"></i></button>
										<button class="btn btn-sm btn-outline-danger" onclick="removeApplication({{ app.id }})" title="Quitar"><i class="fas fa-unlink"></i></button>
									</td>
								</tr>
							{% endfor %}
						{% else %}
							<tr><td colspan="7" class="text-center text-muted py-4"><i class="fas fa-receipt fa-lg d-block mb-2 text-muted"></i>Sin notas aplicadas a este pago</td></tr>
						{% endif %}
					</tbody>
				</table>
			</div>
			{% if notes_totals.total_credits > 0 or notes_totals.total_debits > 0 %}
			<div class="px-3 py-2 border-top bg-light d-flex justify-content-end gap-4" style="font-size:.85rem;">
				<span>Total Crédito: <strong style="color:#3a7d60;">-${{ notes_totals.total_credits|number_format(2) }}</strong></span>
				<span>Total Cargo: <strong style="color:#b05c3a;">+${{ notes_totals.total_debits|number_format(2) }}</strong></span>
			</div>
			{% endif %}
		</div>

		<!-- Tab: Autorizaciones -->
		<div id="tab-auth" class="pd-tab-content">
			<div class="px-3 pt-3 pb-2">
				{% set progress = 0 %}
				{% if authorization_status.abastos %}{% set progress = progress + 25 %}{% endif %}
				{% if authorization_status.contabilidad %}{% set progress = progress + 25 %}{% endif %}
				{% if authorization_status.admin_finanzas %}{% set progress = progress + 25 %}{% endif %}
				{% if authorization_status.tesoreria %}{% set progress = progress + 25 %}{% endif %}
				<div class="d-flex justify-content-between mb-1">
					<small class="text-muted fw-semibold">Progreso de autorización</small>
					<small class="text-muted">{{ progress }}% completado</small>
				</div>
				<div class="progress mb-4" style="height:5px;">
					<div class="progress-bar" style="width:{{ progress }}%;background:#3a7d60;"></div>
				</div>

				<!-- Nivel 1: Abastos -->
				{% set done = authorization_status.abastos %}
				{% set is_next = authorization_status.next_level == 66 %}
				<div class="auth-step">
					<div class="auth-step-icon" style="background:{% if done %}#3a7d60{% elseif is_next %}#e9ecef{% else %}#f8f9fa{% endif %};border:2px solid {% if done %}#3a7d60{% elseif is_next %}#343a40{% else %}#dee2e6{% endif %};color:{% if done %}#fff{% else %}#6c757d{% endif %};">
						<i class="fas {% if done %}fa-check{% else %}fa-box{% endif %}"></i>
					</div>
					<div class="flex-grow-1 d-flex justify-content-between align-items-center">
						<div>
							<p class="mb-0 fw-semibold" style="font-size:.9rem;">Nivel 1 — Abastos</p>
							{% if done and auth_info.abastos is defined %}<small class="text-muted">{{ auth_info.abastos.autorizador_nombre }} &middot; {{ auth_info.abastos.authorization_date|date('d/m/Y H:i') }}</small>
							{% elseif is_next %}<small class="text-muted">Turno actual</small>
							{% else %}<small class="text-muted">En espera</small>{% endif %}
						</div>
						<div>
							{% if done %}<span class="badge" style="background:#d1e7dd;color:#0a3622;"><i class="fas fa-check me-1"></i>Autorizado</span>
							{% elseif is_next and authorized(66) %}<button class="btn btn-sm btn-dark" onclick="openAuthModal(66, 'Abastos')"><i class="fas fa-check me-1"></i>Autorizar</button>
							{% elseif is_next %}<span class="badge bg-secondary"><i class="fas fa-clock me-1"></i>Esperando</span>
							{% else %}<span class="badge bg-light text-muted border"><i class="fas fa-lock me-1"></i>Bloqueado</span>{% endif %}
						</div>
					</div>
				</div>
				<div style="width:2px;height:1.2rem;background:#e9ecef;margin-left:1.05rem;"></div>

				<!-- Nivel 2: Contabilidad -->
				{% set done = authorization_status.contabilidad %}
				{% set is_next = authorization_status.next_level == 70 %}
				<div class="auth-step">
					<div class="auth-step-icon" style="background:{% if done %}#3a7d60{% elseif is_next %}#e9ecef{% else %}#f8f9fa{% endif %};border:2px solid {% if done %}#3a7d60{% elseif is_next %}#343a40{% else %}#dee2e6{% endif %};color:{% if done %}#fff{% else %}#6c757d{% endif %};">
						<i class="fas {% if done %}fa-check{% else %}fa-calculator{% endif %}"></i>
					</div>
					<div class="flex-grow-1 d-flex justify-content-between align-items-center">
						<div>
							<p class="mb-0 fw-semibold" style="font-size:.9rem;">Nivel 2 — Contabilidad</p>
							{% if done and auth_info.contabilidad is defined %}<small class="text-muted">{{ auth_info.contabilidad.autorizador_nombre }} &middot; {{ auth_info.contabilidad.authorization_date|date('d/m/Y H:i') }}</small>
							{% elseif is_next %}<small class="text-muted">Turno actual</small>
							{% else %}<small class="text-muted">En espera</small>{% endif %}
						</div>
						<div>
							{% if done %}<span class="badge" style="background:#d1e7dd;color:#0a3622;"><i class="fas fa-check me-1"></i>Autorizado</span>
							{% elseif is_next and authorized(70) %}<button class="btn btn-sm btn-dark" onclick="openAuthModal(70, 'Contabilidad')"><i class="fas fa-check me-1"></i>Autorizar</button>
							{% elseif is_next %}<span class="badge bg-secondary"><i class="fas fa-clock me-1"></i>Esperando</span>
							{% else %}<span class="badge bg-light text-muted border"><i class="fas fa-lock me-1"></i>Bloqueado</span>{% endif %}
						</div>
					</div>
				</div>
				<div style="width:2px;height:1.2rem;background:#e9ecef;margin-left:1.05rem;"></div>

				<!-- Nivel 3: Admin y Finanzas -->
				{% set done = authorization_status.admin_finanzas %}
				{% set is_next = authorization_status.next_level == 67 %}
				<div class="auth-step">
					<div class="auth-step-icon" style="background:{% if done %}#3a7d60{% elseif is_next %}#e9ecef{% else %}#f8f9fa{% endif %};border:2px solid {% if done %}#3a7d60{% elseif is_next %}#343a40{% else %}#dee2e6{% endif %};color:{% if done %}#fff{% else %}#6c757d{% endif %};">
						<i class="fas {% if done %}fa-check{% else %}fa-building{% endif %}"></i>
					</div>
					<div class="flex-grow-1 d-flex justify-content-between align-items-center">
						<div>
							<p class="mb-0 fw-semibold" style="font-size:.9rem;">Nivel 3 — Administración y Finanzas</p>
							{% if done and auth_info.admin_finanzas is defined %}<small class="text-muted">{{ auth_info.admin_finanzas.autorizador_nombre }} &middot; {{ auth_info.admin_finanzas.authorization_date|date('d/m/Y H:i') }}</small>
							{% elseif is_next %}<small class="text-muted">Turno actual</small>
							{% else %}<small class="text-muted">En espera</small>{% endif %}
						</div>
						<div>
							{% if done %}<span class="badge" style="background:#d1e7dd;color:#0a3622;"><i class="fas fa-check me-1"></i>Autorizado</span>
							{% elseif is_next and authorized(67) %}<button class="btn btn-sm btn-dark" onclick="openAuthModal(67, 'Administración y Finanzas')"><i class="fas fa-check me-1"></i>Autorizar</button>
							{% elseif is_next %}<span class="badge bg-secondary"><i class="fas fa-clock me-1"></i>Esperando</span>
							{% else %}<span class="badge bg-light text-muted border"><i class="fas fa-lock me-1"></i>Bloqueado</span>{% endif %}
						</div>
					</div>
				</div>
				<div style="width:2px;height:1.2rem;background:#e9ecef;margin-left:1.05rem;"></div>

				<!-- Nivel 4: Tesorería -->
				{% set done = authorization_status.tesoreria %}
				{% set is_next = authorization_status.next_level == 68 %}
				<div class="auth-step">
					<div class="auth-step-icon" style="background:{% if done %}#3a7d60{% elseif is_next %}#e9ecef{% else %}#f8f9fa{% endif %};border:2px solid {% if done %}#3a7d60{% elseif is_next %}#343a40{% else %}#dee2e6{% endif %};color:{% if done %}#fff{% else %}#6c757d{% endif %};">
						<i class="fas {% if done %}fa-check{% else %}fa-money-check-alt{% endif %}"></i>
					</div>
					<div class="flex-grow-1 d-flex justify-content-between align-items-center">
						<div>
							<p class="mb-0 fw-semibold" style="font-size:.9rem;">Nivel 4 — Tesorería</p>
							{% if done and auth_info.tesoreria is defined %}<small class="text-muted">{{ auth_info.tesoreria.autorizador_nombre }} &middot; {{ auth_info.tesoreria.authorization_date|date('d/m/Y H:i') }}</small>
							{% elseif is_next %}<small class="text-muted">Turno actual</small>
							{% else %}<small class="text-muted">En espera</small>{% endif %}
						</div>
						<div>
							{% if done %}<span class="badge" style="background:#d1e7dd;color:#0a3622;"><i class="fas fa-check me-1"></i>Autorizado</span>
							{% elseif is_next and authorized(68) %}<button class="btn btn-sm btn-dark" onclick="openAuthModal(68, 'Tesorería')"><i class="fas fa-check me-1"></i>Autorizar</button>
							{% elseif is_next %}<span class="badge bg-secondary"><i class="fas fa-clock me-1"></i>Esperando</span>
							{% else %}<span class="badge bg-light text-muted border"><i class="fas fa-lock me-1"></i>Bloqueado</span>{% endif %}
						</div>
					</div>
				</div>

				{% if authorizations %}
				<hr class="my-3">
				<p class="text-muted mb-2" style="font-size:.75rem;font-weight:700;text-transform:uppercase;">Historial</p>
				<table class="table table-sm mb-0" style="font-size:.82rem;">
					<thead class="table-light">
						<tr><th>Nivel</th><th>Departamento</th><th>Autorizador</th><th>Fecha</th></tr>
					</thead>
					<tbody>
					{% for auth in authorizations %}
						<tr>
							<td><span class="badge bg-secondary">Nivel {{ auth.permission_number == 66 ? 1 : (auth.permission_number == 70 ? 2 : (auth.permission_number == 67 ? 3 : 4)) }}</span></td>
							<td>{{ auth.departamento }}</td>
							<td>{{ auth.autorizador_nombre }}</td>
							<td>{{ auth.authorization_date|date('d/m/Y H:i') }}</td>
						</tr>
					{% endfor %}
					</tbody>
				</table>
				{% endif %}
			</div>
		</div>

		<!-- Tab: Transacciones -->
		{% if transactions and transactions|length > 0 %}
		<div id="tab-transacciones" class="pd-tab-content">
			<div class="table-responsive">
				<table class="table table-sm table-hover mb-0" style="font-size:.82rem;">
					<thead class="table-light">
						<tr>
							<th>#</th><th>Folio Factura</th><th>Factura</th><th>Fecha Pago</th>
							<th class="text-end">Monto</th><th>Método</th><th>Referencia</th>
							<th>Cuenta Beneficiario</th><th>Procesado Por</th><th>Estado</th><th>Acciones</th>
						</tr>
					</thead>
					<tbody>
"""

new_top += tx_body

new_top += """
					</tbody>
				</table>
			</div>
		</div>
		{% endif %}
	</div>

	<div class="pd-footer-spacer"></div>

	<!-- STICKY FOOTER -->
	<div id="pd-sticky-footer">
		<div class="container-fluid px-3">
			<div class="d-flex align-items-center justify-content-between py-2 flex-wrap gap-2">
				<div class="d-flex gap-4 align-items-center flex-wrap" style="font-size:.82rem;">
					<span class="text-muted">Facturas: <strong class="text-dark">${{ payment_calculation.invoice_total|number_format(2) }}</strong></span>
					{% if payment_calculation.credit_notes_total > 0 %}
					<span class="text-muted">NC: <strong style="color:#3a7d60;">-${{ payment_calculation.credit_notes_total|number_format(2) }}</strong></span>
					{% endif %}
					{% if payment_calculation.debit_notes_total > 0 %}
					<span class="text-muted">ND: <strong style="color:#b05c3a;">+${{ payment_calculation.debit_notes_total|number_format(2) }}</strong></span>
					{% endif %}
					{% if payment_calculation.advance_total > 0 %}
					<span class="text-muted">Anticipos: <strong style="color:#8b1a1a;">-${{ payment_calculation.advance_total|number_format(2) }}</strong></span>
					{% endif %}
					<span class="text-muted">Autorizado: <strong class="text-success">${{ total_autorizado_tabla|number_format(2) }}</strong> <small class="text-muted">({{ facturas_autorizadas }}/{{ total_facturas }})</small></span>
				</div>
				<div class="d-flex align-items-center gap-3">
					<div class="text-end">
						<p class="mb-0 text-muted" style="font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;">Total a Pagar</p>
						<p class="mb-0 fw-bold" style="font-size:1.3rem;color:#4a3f8f;">${{ payment_calculation.final_amount|number_format(2) }}</p>
					</div>
					{% if payment.status == 1 and authorized(68) %}
					<button class="btn btn-dark px-4" onclick="autorizarPago()">
						<i class="fas fa-check-circle me-1"></i> Autorizar Pago
					</button>
					{% elseif payment.status == 0 and authorized(66) and not authorization_status.abastos %}
					<button class="btn btn-dark px-4" onclick="openAuthModal(66, 'Abastos')">
						<i class="fas fa-check me-1"></i> Autorizar
					</button>
					{% endif %}
				</div>
			</div>
		</div>
	</div>

	<script>
		function pdTab(btn, tabId) {
			document.querySelectorAll('.pd-tab-link').forEach(b => b.classList.remove('active'));
			document.querySelectorAll('.pd-tab-content').forEach(t => t.classList.remove('active'));
			btn.classList.add('active');
			document.getElementById(tabId).classList.add('active');
		}
	</script>

	<input type="number" id="paymentId" value="{{ payment.id }}" hidden>
	<input type="hidden" id="paymentProviderId" value="{{ payment.provider_id }}">

"""

final = new_top + modals

with open(r'C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp\views\payment\payment_detail.html', 'wb') as f:
    f.write(final.encode('utf-8'))

print('Done. Lines:', len(final.splitlines()))
