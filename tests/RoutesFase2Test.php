<?php
declare(strict_types=1);

/**
 * Rutas de la Fase 2 (routes/*.php reales):  php tests/RoutesFase2Test.php
 *
 * Formato de fila: ver check_routes() en support.php.
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/Router.class.php';

Router::load(require __DIR__ . '/../routes.php');

$rows = [
    // petrotal: ids <int:id> (el controlador castea con (int))
    ['petrotal.volumetricos', ['GET'], 'petrotal/volumetricos', ['Petrotal', 'volumetricos'], [], [], null],
    ['petrotal.preview_json', ['GET'], 'petrotal/preview-json', ['Petrotal', 'preview_json'], [], [], 'petrotal/preview_json'],
    ['petrotal.generar_json', ['POST'], 'petrotal/generar-json', ['Petrotal', 'generar_json'], [], [], 'petrotal/generar_json'],
    ['petrotal.enviar_reporte', ['POST'], 'petrotal/enviar-reporte', ['Petrotal', 'enviar_reporte'], [], [], 'petrotal/enviar_reporte'],
    ['petrotal.configuracion_fiel', ['GET'], 'petrotal/configuracion-fiel', ['Petrotal', 'configuracion_fiel'], [], [], 'petrotal/configuracion_fiel'],
    ['petrotal.guardar_fiel', ['POST'], 'petrotal/guardar-fiel', ['Petrotal', 'guardar_fiel'], [], [], 'petrotal/guardar_fiel'],
    ['petrotal.historial', ['GET'], 'petrotal/historial', ['Petrotal', 'historial'], [], [], null],
    ['petrotal.descargar_json', ['GET'], 'petrotal/descargar-json/15', ['Petrotal', 'descargar_json'], [15], ['id' => 15], 'petrotal/descargar_json/15'],
    ['petrotal.descargar_acuse', ['GET'], 'petrotal/descargar-acuse/15', ['Petrotal', 'descargar_acuse'], [15], ['id' => 15], 'Petrotal/Descargar_Acuse/15/'],

    // arqueo: ids <int:x> (el controlador castea con (int)); '/arqueo' es el alias de index (menú)
    ['arqueo.index', ['GET'], 'arqueo/index', ['Arqueo', 'index'], [], [], 'Arqueo/'],
    ['arqueo.cajas', ['GET'], 'arqueo/cajas/7', ['Arqueo', 'cajas'], [7], ['sesion_id' => 7], null],
    ['arqueo.asignar_caja', ['POST'], 'arqueo/asignar-caja', ['Arqueo', 'asignar_caja'], [], [], 'arqueo/asignar_caja'],
    ['arqueo.crear_sesion', ['POST'], 'arqueo/crear-sesion', ['Arqueo', 'crear_sesion'], [], [], 'arqueo/crear_sesion'],
    ['arqueo.abrir', ['POST'], 'arqueo/abrir/7', ['Arqueo', 'abrir'], [7], ['sesion_id' => 7], null],
    ['arqueo.cerrar', ['POST'], 'arqueo/cerrar/7', ['Arqueo', 'cerrar'], [7], ['sesion_id' => 7], null],
    ['arqueo.caja', ['GET'], 'arqueo/caja/9', ['Arqueo', 'caja'], [9], ['caja_id' => 9], null],
    ['arqueo.imprimir_caja', ['GET'], 'arqueo/imprimir-caja/9', ['Arqueo', 'imprimir_caja'], [9], ['caja_id' => 9], 'arqueo/imprimir_caja/9'],
    ['arqueo.guardar_caja', ['POST'], 'arqueo/guardar-caja/9', ['Arqueo', 'guardar_caja'], [9], ['caja_id' => 9], 'arqueo/guardar_caja/9'],
    ['arqueo.concentrado', ['GET'], 'arqueo/concentrado/7', ['Arqueo', 'concentrado'], [7], ['sesion_id' => 7], null],
    ['arqueo.auditoria', ['GET'], 'arqueo/auditoria/7', ['Arqueo', 'auditoria'], [7], ['sesion_id' => 7], null],
    ['arqueo.guardar_concentrado_extra', ['POST'], 'arqueo/guardar-concentrado-extra', ['Arqueo', 'guardar_concentrado_extra'], [], [], 'arqueo/guardar_concentrado_extra'],
    ['arqueo.subir_imagen_concentrado', ['POST'], 'arqueo/subir-imagen-concentrado', ['Arqueo', 'subir_imagen_concentrado'], [], [], 'arqueo/subir_imagen_concentrado'],
    ['arqueo.imagenes_concentrado', ['GET'], 'arqueo/imagenes-concentrado', ['Arqueo', 'imagenes_concentrado'], [], [], 'arqueo/imagenes_concentrado'],
    ['arqueo.ver_imagen_concentrado', ['GET'], 'arqueo/ver-imagen-concentrado/3', ['Arqueo', 'ver_imagen_concentrado'], [3], ['imagen_id' => 3], 'arqueo/ver_imagen_concentrado/3'],
    ['arqueo.eliminar_imagen_concentrado', ['POST'], 'arqueo/eliminar-imagen-concentrado', ['Arqueo', 'eliminar_imagen_concentrado'], [], [], 'arqueo/eliminar_imagen_concentrado'],
    ['arqueo.exportar_cajas', ['GET'], 'arqueo/exportar-cajas/7', ['Arqueo', 'exportar_cajas'], [7], ['sesion_id' => 7], 'arqueo/exportar_cajas/7'],

    // commercial: pantallas del menú (GET; sale_lubricants también POST porque lee $_POST)
    ['commercial.sales_indicators', ['GET'], 'commercial/sales-indicators', ['Commercial', 'sales_indicators'], [], [], 'Commercial/Sales_Indicators/'],
    ['commercial.sale_month_turn', ['GET'], 'commercial/sale-month-turn', ['Commercial', 'sale_month_turn'], [], [], 'commercial/sale_month_turn'],
    ['commercial.sale_type_payment', ['GET'], 'commercial/sale-type-payment', ['Commercial', 'sale_type_payment'], [], [], 'commercial/sale_type_payment'],
    ['commercial.sale_lubricants', ['GET', 'POST'], 'commercial/sale-lubricants', ['Commercial', 'sale_lubricants'], [], [], 'commercial/sale_lubricants'],
    ['commercial.sale_week_zone', ['GET'], 'commercial/sale-week-zone', ['Commercial', 'sale_week_zone'], [], [], 'commercial/sale_week_zone'],
    ['commercial.sales_global_total', ['GET'], 'commercial/sales-global-total', ['Commercial', 'sales_global_total'], [], [], 'commercial/sales_global_total'],
    ['commercial.mystery_shopper', ['GET'], 'commercial/mystery-shopper', ['Commercial', 'mystery_shopper'], [], [], 'commercial/mystery_shopper'],
    // commercial: llamadas de commercial.js (POST; la descarga del formato es GET)
    ['commercial.mistery_shopper_table', ['POST'], 'commercial/mistery-shopper-table', ['Commercial', 'mistery_shopper_table'], [], [], 'commercial/mistery_shopper_table'],
    ['commercial.import_file_mystery_shopper', ['POST'], 'commercial/import-file-mystery-shopper', ['Commercial', 'import_file_mystery_shopper'], [], [], 'commercial/import_file_mystery_shopper'],
    ['commercial.lubricants_table', ['POST'], 'commercial/lubricants-table', ['Commercial', 'lubricants_table'], [], [], 'commercial/lubricants_table'],
    ['commercial.lubricants_table_month', ['POST'], 'commercial/lubricants-table-month', ['Commercial', 'lubricants_table_month'], [], [], 'commercial/lubricants_table_month'],
    ['commercial.lubricants_table_day', ['POST'], 'commercial/lubricants-table-day', ['Commercial', 'lubricants_table_day'], [], [], 'commercial/lubricants_table_day'],
    ['commercial.lubricants_table_base', ['POST'], 'commercial/lubricants-table-base', ['Commercial', 'lubricants_table_base'], [], [], 'commercial/lubricants_table_base'],
    ['commercial.sales_type_payment_table', ['POST'], 'commercial/sales-type-payment-table', ['Commercial', 'sales_type_payment_table'], [], [], 'commercial/sales_type_payment_table'],
    ['commercial.mounth_group_table', ['POST'], 'commercial/mounth-group-table', ['Commercial', 'mounth_group_table'], [], [], 'commercial/mounth_group_table'],
    ['commercial.mounth_company_table', ['POST'], 'commercial/mounth-company-table', ['Commercial', 'mounth_company_table'], [], [], 'commercial/mounth_company_table'],
    ['commercial.mounth_company_table2', ['POST'], 'commercial/mounth-company-table2', ['Commercial', 'mounth_company_table2'], [], [], 'commercial/mounth_company_table2'],
    ['commercial.mounth_estation_table', ['POST'], 'commercial/mounth-estation-table', ['Commercial', 'mounth_estation_table'], [], [], 'commercial/mounth_estation_table'],
    ['commercial.mounth_estation_eventos', ['POST'], 'commercial/mounth-estation-eventos', ['Commercial', 'mounth_estation_eventos'], [], [], 'commercial/mounth_estation_eventos'],
    ['commercial.differencesInfoModal', ['POST'], 'commercial/differences-info-modal', ['Commercial', 'differencesInfoModal'], [], [], 'commercial/differencesInfoModal'],
    ['commercial.sale_month_turn_table', ['POST'], 'commercial/sale-month-turn-table', ['Commercial', 'sale_month_turn_table'], [], [], 'commercial/sale_month_turn_table'],
    ['commercial.sale_month_turn_base_table', ['POST'], 'commercial/sale-month-turn-base-table', ['Commercial', 'sale_month_turn_base_table'], [], [], 'commercial/sale_month_turn_base_table'],
    ['commercial.sale_week_zone_table', ['POST'], 'commercial/sale-week-zone-table', ['Commercial', 'sale_week_zone_table'], [], [], 'commercial/sale_week_zone_table'],
    ['commercial.sales_indicators_table', ['POST'], 'commercial/sales-indicators-table', ['Commercial', 'sales_indicators_table'], [], [], 'commercial/sales_indicators_table'],
    ['commercial.sales_global_table', ['POST'], 'commercial/sales-global-table', ['Commercial', 'sales_global_table'], [], [], 'commercial/sales_global_table'],
    ['commercial.import_file_budget', ['POST'], 'commercial/import-file-budget', ['Commercial', 'import_file_budget'], [], [], 'commercial/import_file_budget'],
    ['commercial.download_format_budget', ['GET'], 'commercial/download-format-budget', ['Commercial', 'download_format_budget'], [], [], 'commercial/download_format_budget'],

    // direction: pantallas (historic_prices y consumption_customer_credit reciben POST de sus formularios)
    ['direction.tg6', ['GET'], 'direction/tg6', ['Direction', 'tg6'], [], [], null],
    ['direction.consult_consumption', ['GET'], 'direction/consult-consumption', ['Direction', 'consult_consumption'], [], [], 'direction/consult_consumption'],
    ['direction.tg6_product', ['GET'], 'direction/tg6-product', ['Direction', 'tg6_product'], [], [], 'Direction/TG6_Product/'],
    ['direction.valeras', ['GET'], 'direction/valeras', ['Direction', 'valeras'], [], [], null],
    ['direction.monthly_dollar_sales_report', ['GET'], 'direction/monthly-dollar-sales-report', ['Direction', 'monthly_dollar_sales_report'], [], [], 'direction/monthly_dollar_sales_report'],
    ['direction.historic_shortage', ['GET'], 'direction/historic-shortage', ['Direction', 'historic_shortage'], [], [], 'direction/historic_shortage'],
    ['direction.monthly_summary_shortge', ['GET'], 'direction/monthly-summary-shortge', ['Direction', 'monthly_summary_shortge'], [], [], 'direction/monthly_summary_shortge'],
    ['direction.daily_summary_shortge', ['GET'], 'direction/daily-summary-shortge/2', ['Direction', 'daily_summary_shortge'], ['2'], ['id_producto' => '2'], 'direction/daily_summary_shortge/2'],
    ['direction.graph_prices', ['GET'], 'direction/graph-prices', ['Direction', 'graph_prices'], [], [], 'direction/graph_prices'],
    ['direction.historic_prices', ['GET', 'POST'], 'direction/historic-prices', ['Direction', 'historic_prices'], [], [], 'direction/historic_prices'],
    ['direction.consumption_customer_credit', ['GET', 'POST'], 'direction/consumption-customer-credit', ['Direction', 'consumption_customer_credit'], [], [], 'direction/consumption_customer_credit'],
    // direction: llamadas JS (POST salvo download_format y excel_tg6)
    ['direction.monthly_dollar_sales_report_table', ['POST'], 'direction/monthly-dollar-sales-report-table', ['Direction', 'monthly_dollar_sales_report_table'], [], [], 'direction/monthly_dollar_sales_report_table'],
    ['direction.cumsumption_credit_table', ['POST'], 'direction/cumsumption-credit-table', ['Direction', 'cumsumption_credit_table'], [], [], 'direction/cumsumption_credit_table'],
    ['direction.historic_price_table', ['POST'], 'direction/historic-price-table', ['Direction', 'historic_price_table'], [], [], 'direction/historic_price_table'],
    ['direction.download_format', ['GET'], 'direction/download-format', ['Direction', 'download_format'], [], [], 'direction/download_format'],
    ['direction.import_file_historic_price', ['POST'], 'direction/import-file-historic-price', ['Direction', 'import_file_historic_price'], [], [], 'direction/import_file_historic_price'],
    ['direction.import_file_historic_price_horizontal', ['POST'], 'direction/import-file-historic-price-horizontal', ['Direction', 'import_file_historic_price_horizontal'], [], [], 'direction/import_file_historic_price_horizontal'],
    ['direction.historic_shortage_table', ['POST'], 'direction/historic-shortage-table', ['Direction', 'historic_shortage_table'], [], [], 'direction/historic_shortage_table'],
    ['direction.SaveHoursShortage', ['POST'], 'direction/save-hours-shortage', ['Direction', 'SaveHoursShortage'], [], [], 'direction/SaveHoursShortage'],
    ['direction.delete_shortage', ['POST'], 'direction/delete-shortage', ['Direction', 'delete_shortage'], [], [], 'direction/delete_shortage'],
    ['direction.credit_debit_product_table', ['POST'], 'direction/credit-debit-product-table', ['Direction', 'credit_debit_product_table'], [], [], 'direction/credit_debit_product_table'],
    ['direction.comsumption_credit_client_table', ['POST'], 'direction/comsumption-credit-client-table', ['Direction', 'comsumption_credit_client_table'], [], [], 'direction/comsumption_credit_client_table'],
    ['direction.comsumption_credit_count_table', ['POST'], 'direction/comsumption-credit-count-table', ['Direction', 'comsumption_credit_count_table'], [], [], 'direction/comsumption_credit_count_table'],
    ['direction.Vta_vs_meta_canvas', ['POST'], 'direction/vta-vs-meta-canvas', ['Direction', 'Vta_vs_meta_canvas'], [], [], 'direction/Vta_vs_meta_canvas'],
    ['direction.valeras_table', ['POST'], 'direction/valeras-table', ['Direction', 'valeras_table'], [], [], 'direction/valeras_table'],
    ['direction.excel_tg6', ['GET'], 'direction/excel-tg6', ['Direction', 'excel_tg6'], [], [], 'direction/excel_tg6'],
    ['direction.monthly_summary_shortge_table', ['POST'], 'direction/monthly-summary-shortge-table', ['Direction', 'monthly_summary_shortge_table'], [], [], 'direction/monthly_summary_shortge_table'],
    ['direction.daily_summary_shortge_table', ['POST'], 'direction/daily-summary-shortge-table', ['Direction', 'daily_summary_shortge_table'], [], [], 'direction/daily_summary_shortge_table'],
    ['direction.week_graph', ['POST'], 'direction/week-graph', ['Direction', 'week_graph'], [], [], 'direction/week_graph'],
    ['direction.graph_month', ['POST'], 'direction/graph-month', ['Direction', 'graph_month'], [], [], 'direction/graph_month'],
    ['direction.historic_price_table_pivot2', ['POST'], 'direction/historic-price-table-pivot2', ['Direction', 'historic_price_table_pivot2'], [], [], 'direction/historic_price_table_pivot2'],

    // administration (4a: varios). codgas de despachos es <int> (DespachosModel::get_rows(int $codgas, …))
    ['administration.monthly_dispatches', ['GET'], 'administration/monthly-dispatches', ['Administration', 'monthly_dispatches'], [], [], 'administration/monthly_dispatches'],
    ['administration.datatables_monthly_dispatches', ['POST'], 'administration/datatables-monthly-dispatches/2/2026-10-01/2026-10-16', ['Administration', 'datatables_monthly_dispatches'], [2, '2026-10-01', '2026-10-16'], ['codgas' => 2, 'from' => '2026-10-01', 'until' => '2026-10-16'], 'administration/datatables_monthly_dispatches/2/2026-10-01/2026-10-16'],
    ['administration.relation_corpo_estaciones', ['GET'], 'administration/relation-corpo-estaciones', ['Administration', 'relation_corpo_estaciones'], [], [], 'administration/relation_corpo_estaciones'],
    ['administration.exchange_rate', ['GET'], 'administration/exchange-rate', ['Administration', 'exchange_rate'], [], [], 'Administration/Exchange_Rate/'],
    ['administration.datatable_exchange_rate', ['POST'], 'administration/datatable-exchange-rate', ['Administration', 'datatable_exchange_rate'], [], [], 'administration/datatable_exchange_rate'],
    ['administration.exchange_rate_process', ['POST'], 'administration/exchange-rate-process', ['Administration', 'exchange_rate_process'], [], [], 'administration/exchange_rate_process'],
    ['administration.update_exchange', ['POST'], 'administration/update-exchange', ['Administration', 'update_exchange'], [], [], 'administration/update_exchange'],
    ['administration.delete_exchange', ['POST'], 'administration/delete-exchange', ['Administration', 'delete_exchange'], [], [], 'administration/delete_exchange'],
    ['administration.get_binnacle', ['GET'], 'administration/get-binnacle', ['Administration', 'get_binnacle'], [], [], 'administration/get_binnacle'],
    ['administration.binnacle_adjustments', ['GET'], 'administration/binnacle-adjustments', ['Administration', 'binnacle_adjustments'], [], [], 'administration/binnacle_adjustments'],
    ['administration.tablaAuditoria', ['GET'], 'administration/tabla-auditoria/2026-07-01/2026-10-16/0', ['Administration', 'tablaAuditoria'], ['2026-07-01', '2026-10-16', '0'], ['from' => '2026-07-01', 'until' => '2026-10-16', 'codgas' => '0'], 'administration/tablaAuditoria/2026-07-01/2026-10-16/0'],
    ['administration.tablaTickets', ['GET'], 'administration/tabla-tickets/2026-07-01/2026-10-16/0', ['Administration', 'tablaTickets'], ['2026-07-01', '2026-10-16', '0'], ['from' => '2026-07-01', 'until' => '2026-10-16', 'codgas' => '0'], 'administration/tablaTickets/2026-07-01/2026-10-16/0'],
    ['administration.doc_agujita', ['GET'], 'administration/doc-agujita', ['Administration', 'doc_agujita'], [], [], 'administration/doc_agujita'],
    ['administration.porcent_estacion_info', ['POST'], 'administration/porcent-estacion-info', ['Administration', 'porcent_estacion_info'], [], [], 'administration/porcent_estacion_info'],
    ['administration.porcent_estacion_facturados_info', ['POST'], 'administration/porcent-estacion-facturados-info', ['Administration', 'porcent_estacion_facturados_info'], [], [], 'administration/porcent_estacion_facturados_info'],
    ['administration.porcent_facturas_info', ['POST'], 'administration/porcent-facturas-info', ['Administration', 'porcent_facturas_info'], [], [], 'administration/porcent_facturas_info'],

    // administration (4b: anticipos y débito/crédito). percent <int> (se compara con == 80); codcli <str>
    ['administration.dashboard_debit_credit', ['GET'], 'administration/dashboard-debit-credit', ['Administration', 'dashboard_debit_credit'], [], [], 'administration/dashboard_debit_credit'],
    ['administration.in_process', ['GET'], 'administration/in-process', ['Administration', 'in_process'], [], [], 'Administration/In_Process/'],
    ['administration.search_credit_and_debits_clients', ['GET'], 'administration/search-credit-and-debits-clients', ['Administration', 'search_credit_and_debits_clients'], [], [], 'administration/search_credit_and_debits_clients'],
    ['administration.get_advances', ['GET'], 'administration/get-advances/2026-10-01/2026-10-31', ['Administration', 'get_advances'], ['2026-10-01', '2026-10-31'], ['from' => '2026-10-01', 'until' => '2026-10-31'], 'administration/get_advances/2026-10-01/2026-10-31'],
    ['administration.get_advance_clients', ['GET'], 'administration/get-advance-clients/80/2026-10-01/2026-10-31', ['Administration', 'get_advance_clients'], [80, '2026-10-01', '2026-10-31'], ['percent' => 80, 'from' => '2026-10-01', 'until' => '2026-10-31'], 'administration/get_advance_clients/80/2026-10-01/2026-10-31'],
    ['administration.get_advance_clients_details', ['GET'], 'administration/get-advance-clients-details/C123/2026-10-01/2026-10-31', ['Administration', 'get_advance_clients_details'], ['C123', '2026-10-01', '2026-10-31'], ['codcli' => 'C123', 'from' => '2026-10-01', 'until' => '2026-10-31'], 'administration/get_advance_clients_details/C123/2026-10-01/2026-10-31'],
    ['administration.datatables_anticipos', ['POST'], 'administration/datatables-anticipos/2026-10-01/2026-10-31', ['Administration', 'datatables_anticipos'], ['2026-10-01', '2026-10-31'], ['from' => '2026-10-01', 'until' => '2026-10-31'], 'administration/datatables_anticipos/2026-10-01/2026-10-31'],
    ['administration.datatables_customer_anticipos', ['POST'], 'administration/datatables-customer-anticipos/2026-10-01/2026-10-31', ['Administration', 'datatables_customer_anticipos'], ['2026-10-01', '2026-10-31'], ['from' => '2026-10-01', 'until' => '2026-10-31'], 'administration/datatables_customer_anticipos/2026-10-01/2026-10-31'],
    ['administration.datatables_customer_anticipos2', ['POST'], 'administration/datatables-customer-anticipos2/20/2026-10-01/2026-10-31', ['Administration', 'datatables_customer_anticipos2'], [20, '2026-10-01', '2026-10-31'], ['percent' => 20, 'from' => '2026-10-01', 'until' => '2026-10-31'], 'administration/datatables_customer_anticipos2/20/2026-10-01/2026-10-31'],
    ['administration.datatables_customer_advances_details', ['POST'], 'administration/datatables-customer-advances-details/C123/2026-10-01/2026-10-31', ['Administration', 'datatables_customer_advances_details'], ['C123', '2026-10-01', '2026-10-31'], ['codcli' => 'C123', 'from' => '2026-10-01', 'until' => '2026-10-31'], 'administration/datatables_customer_advances_details/C123/2026-10-01/2026-10-31'],
];

// Review Focus 4: doc_agujita se comparte fuera del sistema y no pide sesión, por la URL nueva y por la vieja
check('administration: doc_agujita es pública (ruta y alias)',
    (Router::match('administration/doc-agujita', 'GET')['route']['public'] ?? false) === true
    && (Router::match('administration/doc_agujita', 'GET')['route']['public'] ?? false) === true);
check('administration: el resto no es público',
    (Router::match('administration/exchange-rate', 'GET')['route']['public'] ?? true) === false);

// Review Focus 4: el id de producto es str (el controlador no lo castea)
check('direction: daily_summary_shortge acepta un id con letras',
    (Router::match('direction/daily-summary-shortge/ABC', 'GET')['args'] ?? null) === ['ABC']);

check_routes($rows);

check('lint limpio', Router::lint() === []);

finish();
