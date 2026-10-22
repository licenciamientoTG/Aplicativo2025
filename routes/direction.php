<?php
// La URL se deriva del nombre del método, erratas incluidas (shortge, comsumption, cumsumption).
// historic_prices y consumption_customer_credit aceptan POST: sus formularios se envían a la misma URL.
// Sin ruta (nada las enlaza): graph_historic_prices, consumption_account_credit, consumption_debit,
// y las funciones internas get_months_list, generate_columns_and_pivot_clause, getMonthsArray,
// generateDaysString, historic_price_table_pivot.
return [
    group('direction/', [
        // Pantallas del menú
        route('tg6/', [Direction::class, 'tg6'], name: 'direction.tg6', methods: ['GET']),
        route('consult-consumption/', [Direction::class, 'consult_consumption'], name: 'direction.consult_consumption', methods: ['GET'], alias: ['direction/consult_consumption']),
        route('tg6-product/', [Direction::class, 'tg6_product'], name: 'direction.tg6_product', methods: ['GET'], alias: ['direction/tg6_product']),
        route('valeras/', [Direction::class, 'valeras'], name: 'direction.valeras', methods: ['GET']),
        route('monthly-dollar-sales-report/', [Direction::class, 'monthly_dollar_sales_report'], name: 'direction.monthly_dollar_sales_report', methods: ['GET'], alias: ['direction/monthly_dollar_sales_report']),
        route('historic-shortage/', [Direction::class, 'historic_shortage'], name: 'direction.historic_shortage', methods: ['GET'], alias: ['direction/historic_shortage']),
        route('monthly-summary-shortge/', [Direction::class, 'monthly_summary_shortge'], name: 'direction.monthly_summary_shortge', methods: ['GET'], alias: ['direction/monthly_summary_shortge']),
        route('daily-summary-shortge/<str:id_producto>/', [Direction::class, 'daily_summary_shortge'], name: 'direction.daily_summary_shortge', methods: ['GET'], alias: ['direction/daily_summary_shortge/<str:id_producto>']),
        route('graph-prices/', [Direction::class, 'graph_prices'], name: 'direction.graph_prices', methods: ['GET'], alias: ['direction/graph_prices']),
        route('historic-prices/', [Direction::class, 'historic_prices'], name: 'direction.historic_prices', methods: ['GET', 'POST'], alias: ['direction/historic_prices']),
        route('consumption-customer-credit/', [Direction::class, 'consumption_customer_credit'], name: 'direction.consumption_customer_credit', methods: ['GET', 'POST'], alias: ['direction/consumption_customer_credit']),

        // Llamadas JS
        route('monthly-dollar-sales-report-table/', [Direction::class, 'monthly_dollar_sales_report_table'], name: 'direction.monthly_dollar_sales_report_table', methods: ['POST'], alias: ['direction/monthly_dollar_sales_report_table']),
        route('cumsumption-credit-table/', [Direction::class, 'cumsumption_credit_table'], name: 'direction.cumsumption_credit_table', methods: ['POST'], alias: ['direction/cumsumption_credit_table']),
        route('historic-price-table/', [Direction::class, 'historic_price_table'], name: 'direction.historic_price_table', methods: ['POST'], alias: ['direction/historic_price_table']),
        route('download-format/', [Direction::class, 'download_format'], name: 'direction.download_format', methods: ['GET'], alias: ['direction/download_format']),
        route('import-file-historic-price/', [Direction::class, 'import_file_historic_price'], name: 'direction.import_file_historic_price', methods: ['POST'], alias: ['direction/import_file_historic_price']),
        route('import-file-historic-price-horizontal/', [Direction::class, 'import_file_historic_price_horizontal'], name: 'direction.import_file_historic_price_horizontal', methods: ['POST'], alias: ['direction/import_file_historic_price_horizontal']),
        route('historic-shortage-table/', [Direction::class, 'historic_shortage_table'], name: 'direction.historic_shortage_table', methods: ['POST'], alias: ['direction/historic_shortage_table']),
        route('save-hours-shortage/', [Direction::class, 'SaveHoursShortage'], name: 'direction.SaveHoursShortage', methods: ['POST'], alias: ['direction/SaveHoursShortage']),
        route('delete-shortage/', [Direction::class, 'delete_shortage'], name: 'direction.delete_shortage', methods: ['POST'], alias: ['direction/delete_shortage']),
        route('credit-debit-product-table/', [Direction::class, 'credit_debit_product_table'], name: 'direction.credit_debit_product_table', methods: ['POST'], alias: ['direction/credit_debit_product_table']),
        route('comsumption-credit-client-table/', [Direction::class, 'comsumption_credit_client_table'], name: 'direction.comsumption_credit_client_table', methods: ['POST'], alias: ['direction/comsumption_credit_client_table']),
        route('comsumption-credit-count-table/', [Direction::class, 'comsumption_credit_count_table'], name: 'direction.comsumption_credit_count_table', methods: ['POST'], alias: ['direction/comsumption_credit_count_table']),
        route('search-clients/', [Direction::class, 'search_clients'], name: 'direction.search_clients', methods: ['POST']),
        route('assign-advisor/', [Direction::class, 'assign_advisor'], name: 'direction.assign_advisor', methods: ['POST']),
        route('sync-consumption/', [Direction::class, 'sync_consumption'], name: 'direction.sync_consumption', methods: ['POST']),
        route('vta-vs-meta-canvas/', [Direction::class, 'Vta_vs_meta_canvas'], name: 'direction.Vta_vs_meta_canvas', methods: ['POST'], alias: ['direction/Vta_vs_meta_canvas']),
        route('valeras-table/', [Direction::class, 'valeras_table'], name: 'direction.valeras_table', methods: ['POST'], alias: ['direction/valeras_table']),
        route('excel-tg6/', [Direction::class, 'excel_tg6'], name: 'direction.excel_tg6', methods: ['GET'], alias: ['direction/excel_tg6']),
        route('monthly-summary-shortge-table/', [Direction::class, 'monthly_summary_shortge_table'], name: 'direction.monthly_summary_shortge_table', methods: ['POST'], alias: ['direction/monthly_summary_shortge_table']),
        route('daily-summary-shortge-table/', [Direction::class, 'daily_summary_shortge_table'], name: 'direction.daily_summary_shortge_table', methods: ['POST'], alias: ['direction/daily_summary_shortge_table']),
        route('week-graph/', [Direction::class, 'week_graph'], name: 'direction.week_graph', methods: ['POST'], alias: ['direction/week_graph']),
        route('graph-month/', [Direction::class, 'graph_month'], name: 'direction.graph_month', methods: ['POST'], alias: ['direction/graph_month']),
        route('historic-price-table-pivot2/', [Direction::class, 'historic_price_table_pivot2'], name: 'direction.historic_price_table_pivot2', methods: ['POST'], alias: ['direction/historic_price_table_pivot2']),
    ]),
];
