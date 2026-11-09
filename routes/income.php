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
        route('efc_conc_usd_globales_asociar', [Income::class, 'efc_conc_usd_globales_asociar'], name: 'income.efc_conc_usd_globales_asociar', methods: ['POST'], permission: 14),
        route('efc_conc_usd_globales_desasociar', [Income::class, 'efc_conc_usd_globales_desasociar'], name: 'income.efc_conc_usd_globales_desasociar', methods: ['POST'], permission: 14),

        // Llamadas de views/income/conciliation.html
        route('conciliation/sales/', [Income::class, 'conciliation_sales'], name: 'income.conciliation_sales', methods: ['POST'], permission: 14),
        route('conciliation/client-dispatches/', [Income::class, 'conciliation_client_dispatches'], name: 'income.conciliation_client_dispatches', methods: ['POST'], permission: 14),
        route('conciliation/client-cuts/', [Income::class, 'conciliation_client_cuts'], name: 'income.conciliation_client_cuts', methods: ['POST'], permission: 14),
        route('conciliation/cuts-diff/', [Income::class, 'conciliation_cuts_diff'], name: 'income.conciliation_cuts_diff', methods: ['POST'], permission: 14),
        route('conciliation/invoices/', [Income::class, 'conciliation_invoices'], name: 'income.conciliation_invoices', methods: ['POST'], permission: 14),
        route('conciliation/payments/', [Income::class, 'conciliation_payments'], name: 'income.conciliation_payments', methods: ['POST'], permission: 14),
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
        route('efc_conc_diferencia_neteo', [Income::class, 'efc_conc_diferencia_neteo'], name: 'income.efc_conc_diferencia_neteo', methods: ['POST'], permission: 14),
        route('efc_conc_cambios_historial', [Income::class, 'efc_conc_cambios_historial'], name: 'income.efc_conc_cambios_historial', methods: ['GET'], permission: 14),
        route('efc_conc_cambios_previsualizar', [Income::class, 'efc_conc_cambios_previsualizar'], name: 'income.efc_conc_cambios_previsualizar', methods: ['GET'], permission: 14),
        route('efc_conc_cortes_aceptados', [Income::class, 'efc_conc_cortes_aceptados'], name: 'income.efc_conc_cortes_aceptados', methods: ['GET'], permission: 14),
        route('efc_conc_cambios_decidir', [Income::class, 'efc_conc_cambios_decidir'], name: 'income.efc_conc_cambios_decidir', methods: ['POST'], permission: 14),
        route('efc_conc_cambios_revertir', [Income::class, 'efc_conc_cambios_revertir'], name: 'income.efc_conc_cambios_revertir', methods: ['POST'], permission: 14),

        // Valeras: consumo diario del corte por razón social (permiso 14 como Conciliación)
        route('valeras/', [Income::class, 'valeras'], name: 'income.valeras', methods: ['GET'], permission: 14),
        route('valeras/daily/', [Income::class, 'valeras_daily'], name: 'income.valeras_daily', methods: ['POST'], permission: 14),
    ]),
];
