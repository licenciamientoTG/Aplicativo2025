<?php
// La URL se deriva del nombre del método, erratas incluidas (mounth_, mistery_).
// sale_lubricants acepta POST porque lee $_POST['from'] / $_POST['until'].
// Sin ruta (no se usan): sale_lubricants_month, sales_type_payment_totals_table,
// date_report, import_data, getFileErrorMessage, import_data_budget.
return [
    group('commercial/', [
        // Pantallas del menú
        route('sales-indicators/', [Commercial::class, 'sales_indicators'], name: 'commercial.sales_indicators', methods: ['GET'], alias: ['commercial/sales_indicators']),
        route('sale-month-turn/', [Commercial::class, 'sale_month_turn'], name: 'commercial.sale_month_turn', methods: ['GET'], alias: ['commercial/sale_month_turn']),
        route('sale-type-payment/', [Commercial::class, 'sale_type_payment'], name: 'commercial.sale_type_payment', methods: ['GET'], alias: ['commercial/sale_type_payment']),
        route('sale-lubricants/', [Commercial::class, 'sale_lubricants'], name: 'commercial.sale_lubricants', methods: ['GET', 'POST'], alias: ['commercial/sale_lubricants']),
        route('sale-week-zone/', [Commercial::class, 'sale_week_zone'], name: 'commercial.sale_week_zone', methods: ['GET'], alias: ['commercial/sale_week_zone']),
        route('sales-global-total/', [Commercial::class, 'sales_global_total'], name: 'commercial.sales_global_total', methods: ['GET'], alias: ['commercial/sales_global_total']),
        route('mystery-shopper/', [Commercial::class, 'mystery_shopper'], name: 'commercial.mystery_shopper', methods: ['GET'], alias: ['commercial/mystery_shopper']),

        // Llamadas de commercial.js
        route('mistery-shopper-table/', [Commercial::class, 'mistery_shopper_table'], name: 'commercial.mistery_shopper_table', methods: ['POST'], alias: ['commercial/mistery_shopper_table']),
        route('import-file-mystery-shopper/', [Commercial::class, 'import_file_mystery_shopper'], name: 'commercial.import_file_mystery_shopper', methods: ['POST'], alias: ['commercial/import_file_mystery_shopper']),
        route('lubricants-table/', [Commercial::class, 'lubricants_table'], name: 'commercial.lubricants_table', methods: ['POST'], alias: ['commercial/lubricants_table']),
        route('lubricants-table-month/', [Commercial::class, 'lubricants_table_month'], name: 'commercial.lubricants_table_month', methods: ['POST'], alias: ['commercial/lubricants_table_month']),
        route('lubricants-table-day/', [Commercial::class, 'lubricants_table_day'], name: 'commercial.lubricants_table_day', methods: ['POST'], alias: ['commercial/lubricants_table_day']),
        route('lubricants-table-base/', [Commercial::class, 'lubricants_table_base'], name: 'commercial.lubricants_table_base', methods: ['POST'], alias: ['commercial/lubricants_table_base']),
        route('sales-type-payment-table/', [Commercial::class, 'sales_type_payment_table'], name: 'commercial.sales_type_payment_table', methods: ['POST'], alias: ['commercial/sales_type_payment_table']),
        route('mounth-group-table/', [Commercial::class, 'mounth_group_table'], name: 'commercial.mounth_group_table', methods: ['POST'], alias: ['commercial/mounth_group_table']),
        route('mounth-company-table/', [Commercial::class, 'mounth_company_table'], name: 'commercial.mounth_company_table', methods: ['POST'], alias: ['commercial/mounth_company_table']),
        route('mounth-company-table2/', [Commercial::class, 'mounth_company_table2'], name: 'commercial.mounth_company_table2', methods: ['POST'], alias: ['commercial/mounth_company_table2']),
        route('mounth-estation-table/', [Commercial::class, 'mounth_estation_table'], name: 'commercial.mounth_estation_table', methods: ['POST'], alias: ['commercial/mounth_estation_table']),
        route('mounth-estation-eventos/', [Commercial::class, 'mounth_estation_eventos'], name: 'commercial.mounth_estation_eventos', methods: ['POST'], alias: ['commercial/mounth_estation_eventos']),
        route('differences-info-modal/', [Commercial::class, 'differencesInfoModal'], name: 'commercial.differencesInfoModal', methods: ['POST'], alias: ['commercial/differencesInfoModal']),
        route('sale-month-turn-table/', [Commercial::class, 'sale_month_turn_table'], name: 'commercial.sale_month_turn_table', methods: ['POST'], alias: ['commercial/sale_month_turn_table']),
        route('sale-month-turn-base-table/', [Commercial::class, 'sale_month_turn_base_table'], name: 'commercial.sale_month_turn_base_table', methods: ['POST'], alias: ['commercial/sale_month_turn_base_table']),
        route('sale-week-zone-table/', [Commercial::class, 'sale_week_zone_table'], name: 'commercial.sale_week_zone_table', methods: ['POST'], alias: ['commercial/sale_week_zone_table']),
        route('sales-indicators-table/', [Commercial::class, 'sales_indicators_table'], name: 'commercial.sales_indicators_table', methods: ['POST'], alias: ['commercial/sales_indicators_table']),
        route('sales-global-table/', [Commercial::class, 'sales_global_table'], name: 'commercial.sales_global_table', methods: ['POST'], alias: ['commercial/sales_global_table']),
        route('import-file-budget/', [Commercial::class, 'import_file_budget'], name: 'commercial.import_file_budget', methods: ['POST'], alias: ['commercial/import_file_budget']),
        route('download-format-budget/', [Commercial::class, 'download_format_budget'], name: 'commercial.download_format_budget', methods: ['GET'], alias: ['commercial/download_format_budget']),
    ]),
];
