<?php
// Income aún no está migrado: aquí solo van las rutas nuevas; el resto de
// /income/<método> sigue resolviendo por la convención antigua.
// Conciliación va bajo el permiso 14 (Ingresos > Reportes), sin permiso propio.
return [
    group('income/', [
        // Pantalla del menú
        route('conciliation/', [Income::class, 'conciliation'], name: 'income.conciliation', methods: ['GET'], permission: 14),

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
    ]),
];
