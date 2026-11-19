<?php
// Income aún no está migrado: aquí solo van las rutas nuevas; el resto de
// /income/<método> sigue resolviendo por la convención antigua.
// Conciliación va bajo el permiso 14 (Ingresos > Reportes), sin permiso propio.
return [
    group('income/', [
        // Pantalla del menú
        route('conciliation/', [Income::class, 'conciliation'], name: 'income.conciliation', methods: ['GET'], permission: 14),
        route('cash_reconciliation_papers', [Income::class, 'cash_reconciliation_papers'], name: 'income.cash_reconciliation_papers', methods: ['GET'], permission: 14),
        route('efc_conc_analiticos_papeletas', [Income::class, 'efc_conc_analiticos_papeletas'], name: 'income.efc_conc_analiticos_papeletas', methods: ['GET'], permission: 14),
        route('efc_conc_analiticos_historial', [Income::class, 'efc_conc_analiticos_historial'], name: 'income.efc_conc_analiticos_historial', methods: ['GET'], permission: 14),
        route('efc_conc_analiticos_archivo', [Income::class, 'efc_conc_analiticos_archivo'], name: 'income.efc_conc_analiticos_archivo', methods: ['GET'], permission: 14),
        route('efc_conc_analiticos_crear', [Income::class, 'efc_conc_analiticos_crear'], name: 'income.efc_conc_analiticos_crear', methods: ['POST'], permission: 14),
        route('efc_conc_analiticos_editar', [Income::class, 'efc_conc_analiticos_editar'], name: 'income.efc_conc_analiticos_editar', methods: ['POST'], permission: 14),
        route('efc_conc_analiticos_corregir_fecha', [Income::class, 'efc_conc_analiticos_corregir_fecha'], name: 'income.efc_conc_analiticos_corregir_fecha', methods: ['POST'], permission: 14),
        route('efc_conc_analiticos_corregir_estacion', [Income::class, 'efc_conc_analiticos_corregir_estacion'], name: 'income.efc_conc_analiticos_corregir_estacion', methods: ['POST'], permission: 14),
        route('efc_conc_usd_globales', [Income::class, 'efc_conc_usd_globales'], name: 'income.efc_conc_usd_globales', methods: ['GET'], permission: 14),
        route('efc_conc_resumen_papeletas_usd', [Income::class, 'efc_conc_resumen_papeletas_usd'], name: 'income.efc_conc_resumen_papeletas_usd', methods: ['GET'], permission: 14),
        route('efc_conc_resumen_general_datos', [Income::class, 'efc_conc_resumen_general_datos'], name: 'income.efc_conc_resumen_general_datos', methods: ['POST'], permission: 14),
        route('efc_conc_resumen_praxedis_guardar', [Income::class, 'efc_conc_resumen_praxedis_guardar'], name: 'income.efc_conc_resumen_praxedis_guardar', methods: ['POST'], permission: 14),
        route('efc_conc_usd_globales_asociar', [Income::class, 'efc_conc_usd_globales_asociar'], name: 'income.efc_conc_usd_globales_asociar', methods: ['POST'], permission: 14),
        route('efc_conc_usd_globales_desasociar', [Income::class, 'efc_conc_usd_globales_desasociar'], name: 'income.efc_conc_usd_globales_desasociar', methods: ['POST'], permission: 14),

        // Llamadas de views/income/conciliation.html
        route('conciliation/sales/', [Income::class, 'conciliation_sales'], name: 'income.conciliation_sales', methods: ['POST'], permission: 14),
        route('conciliation/client-dispatches/', [Income::class, 'conciliation_client_dispatches'], name: 'income.conciliation_client_dispatches', methods: ['POST'], permission: 14),
        route('conciliation/client-cuts/', [Income::class, 'conciliation_client_cuts'], name: 'income.conciliation_client_cuts', methods: ['POST'], permission: 14),
        route('conciliation/cuts-diff/', [Income::class, 'conciliation_cuts_diff'], name: 'income.conciliation_cuts_diff', methods: ['POST'], permission: 14),
        route('conciliation/invoices/', [Income::class, 'conciliation_invoices'], name: 'income.conciliation_invoices', methods: ['POST'], permission: 14),
        route('conciliation/payments/', [Income::class, 'conciliation_payments'], name: 'income.conciliation_payments', methods: ['POST'], permission: 14),
        route('conciliation/payments/retry/', [Income::class, 'conciliation_payments_retry'], name: 'income.conciliation_payments_retry', methods: ['POST'], permission: 14),
        route('conciliation/recalculate/', [Income::class, 'conciliation_recalculate'], name: 'income.conciliation_recalculate', methods: ['POST'], permission: 14),
        route('conciliation/unmatched-deposits/', [Income::class, 'conciliation_unmatched_deposits'], name: 'income.conciliation_unmatched_deposits', methods: ['POST'], permission: 14),
        // Conciliación manual de cobros: modal (vista parcial views/income/modals/conciliation_manual.html), buscadores, guardar y deshacer
        route('conciliation/manual/modal/', [Income::class, 'conciliation_manual_modal'], name: 'income.conciliation_manual_modal', methods: ['POST'], permission: 14),
        route('conciliation/manual/search-movements/', [Income::class, 'conciliation_search_movements'], name: 'income.conciliation_manual_search_movements', methods: ['POST'], permission: 14),
        route('conciliation/manual/search-payments/', [Income::class, 'conciliation_search_payments'], name: 'income.conciliation_manual_search_payments', methods: ['POST'], permission: 14),
        route('conciliation/manual/search-invoices/', [Income::class, 'conciliation_search_invoices'], name: 'income.conciliation_manual_search_invoices', methods: ['POST'], permission: 14),
        route('conciliation/manual/preview/', [Income::class, 'conciliation_manual_preview'], name: 'income.conciliation_manual_preview', methods: ['POST'], permission: 14),
        route('conciliation/manual/save/', [Income::class, 'conciliation_manual_save'], name: 'income.conciliation_manual_save', methods: ['POST'], permission: 14),
        route('conciliation/manual/undo/', [Income::class, 'conciliation_manual_undo'], name: 'income.conciliation_manual_undo', methods: ['POST'], permission: 14),
        route('conciliation/bank/', [Income::class, 'conciliation_bank'], name: 'income.conciliation_bank', methods: ['POST'], permission: 14),
        route('conciliation/app-sales/', [Income::class, 'conciliation_app_sales'], name: 'income.conciliation_app_sales', methods: ['POST'], permission: 14),
        route('conciliation/own-consumption/', [Income::class, 'conciliation_own_consumption'], name: 'income.conciliation_own_consumption', methods: ['POST'], permission: 14),

        // Conciliación débito (prepago): views/income/conciliation_debit.html
        route('conciliation-debit/', [Income::class, 'conciliation_debit'], name: 'income.conciliation_debit', methods: ['GET'], permission: 14),
        route('conciliation-debit/sales/', [Income::class, 'conciliation_debit_sales'], name: 'income.conciliation_debit_sales', methods: ['POST'], permission: 14),
        route('conciliation-debit/consumption/', [Income::class, 'conciliation_debit_consumption'], name: 'income.conciliation_debit_consumption', methods: ['POST'], permission: 14),
        route('conciliation-debit/own-consumption/', [Income::class, 'conciliation_debit_own'], name: 'income.conciliation_debit_own', methods: ['POST'], permission: 14),
        route('conciliation-debit/invoices/', [Income::class, 'conciliation_debit_invoices'], name: 'income.conciliation_debit_invoices', methods: ['POST'], permission: 14),
        route('conciliation-debit/pending-dispatches/', [Income::class, 'conciliation_debit_pending'], name: 'income.conciliation_debit_pending', methods: ['POST'], permission: 14),
        route('conciliation-debit/anticipos/', [Income::class, 'conciliation_debit_anticipos'], name: 'income.conciliation_debit_anticipos', methods: ['POST'], permission: 14),
        route('conciliation-debit/anticipos-clients/', [Income::class, 'conciliation_debit_anticipos_clients'], name: 'income.conciliation_debit_anticipos_clients', methods: ['POST'], permission: 14),
        route('conciliation-debit/payments/', [Income::class, 'conciliation_debit_payments'], name: 'income.conciliation_debit_payments', methods: ['POST'], permission: 14),
        route('conciliation-debit/bank/', [Income::class, 'conciliation_debit_bank'], name: 'income.conciliation_debit_bank', methods: ['POST'], permission: 14),
        route('conciliation-debit/payments-invoices/', [Income::class, 'conciliation_debit_payment_invoices'], name: 'income.conciliation_debit_payment_invoices', methods: ['POST'], permission: 14),

        route('efc_conc_diferencia_captura', [Income::class, 'efc_conc_diferencia_captura'], name: 'income.efc_conc_diferencia_captura', methods: ['POST'], permission: 14),
        route('efc_conc_diferencia_grupo', [Income::class, 'efc_conc_diferencia_grupo'], name: 'income.efc_conc_diferencia_grupo', methods: ['POST'], permission: 14),
        route('efc_conc_diferencia_desagrupar', [Income::class, 'efc_conc_diferencia_desagrupar'], name: 'income.efc_conc_diferencia_desagrupar', methods: ['POST'], permission: 14),
        route('efc_conc_cambios_historial', [Income::class, 'efc_conc_cambios_historial'], name: 'income.efc_conc_cambios_historial', methods: ['GET'], permission: 14),
        route('efc_conc_cambios_previsualizar', [Income::class, 'efc_conc_cambios_previsualizar'], name: 'income.efc_conc_cambios_previsualizar', methods: ['GET'], permission: 14),
        route('efc_conc_cortes_aceptados', [Income::class, 'efc_conc_cortes_aceptados'], name: 'income.efc_conc_cortes_aceptados', methods: ['GET'], permission: 14),
        route('efc_conc_cambios_decidir', [Income::class, 'efc_conc_cambios_decidir'], name: 'income.efc_conc_cambios_decidir', methods: ['POST'], permission: 14),
        route('efc_conc_cambios_revertir', [Income::class, 'efc_conc_cambios_revertir'], name: 'income.efc_conc_cambios_revertir', methods: ['POST'], permission: 14),

        // Clientes por tipo: misma vista que /income/clients filtrada a sus pestañas (views/income/clients.html, modo)
        route('clients-debit/', [Income::class, 'clients_debit'], name: 'income.clients_debit', methods: ['GET'], permission: 14),
        route('clients-credit/', [Income::class, 'clients_credit'], name: 'income.clients_credit', methods: ['GET'], permission: 14),
        route('clients-credit/vehicles/', [Income::class, 'clients_credit_vehicles'], name: 'income.clients_credit_vehicles', methods: ['POST'], permission: 14),
        // Estado de cuenta (fotos diarias TG.dbo.debit/credit_clients_cierre): resumen por rango y día por día de un cliente
        route('clients-debit/statement/', [Income::class, 'debit_statement'], name: 'income.debit_statement', methods: ['POST'], permission: 14),
        route('clients-debit/statement/detail/', [Income::class, 'debit_statement_detail'], name: 'income.debit_statement_detail', methods: ['POST'], permission: 14),
        route('clients-credit/statement/', [Income::class, 'credit_statement'], name: 'income.credit_statement', methods: ['POST'], permission: 14),
        route('clients-credit/statement/detail/', [Income::class, 'credit_statement_detail'], name: 'income.credit_statement_detail', methods: ['POST'], permission: 14),
        // Antes pasaban por el fallback legacy SIN permiso (auditoría 2026-11-17): misma URL, ahora con permiso 14
        route('clients', [Income::class, 'clients'], name: 'income.clients', methods: ['GET'], permission: 14),
        route('debit_snapshot_table', [Income::class, 'debit_snapshot_table'], name: 'income.debit_snapshot_table', methods: ['POST'], permission: 14),
        route('credit_snapshot_table', [Income::class, 'credit_snapshot_table'], name: 'income.credit_snapshot_table', methods: ['POST'], permission: 14),
        // Modal "Cortes que no cuadran" de Consumos Débito/Crédito: mismos tabs que el modal de Conciliación crédito
        route('clients/dispatches-invoice/', [Income::class, 'clients_dispatches_invoice'], name: 'income.clients_dispatches_invoice', methods: ['POST'], permission: 14),
        route('clients/dispatches-vs-cuts/', [Income::class, 'clients_dispatches_vs_cuts'], name: 'income.clients_dispatches_vs_cuts', methods: ['POST'], permission: 14),

        // Valeras: consumo diario del corte por razón social (permiso 14 como Conciliación)
        route('valeras/', [Income::class, 'valeras'], name: 'income.valeras', methods: ['GET'], permission: 14),
        route('valeras/daily/', [Income::class, 'valeras_daily'], name: 'income.valeras_daily', methods: ['POST'], permission: 14),
    ]),
];
