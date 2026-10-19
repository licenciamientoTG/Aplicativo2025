<?php
// administration se migra en tres sub-bloques: 4a (varios, este archivo), 4b (anticipos y
// débito/crédito) y 4c (tickets de Mojo) agregan sus rutas aquí.
// doc_agujita es pública: se comparte fuera del sistema (también está en $publicRoutes de index.php).
return [
    group('administration/', [
        // Despachos mensuales (DespachosModel::get_rows exige int $codgas)
        route('monthly-dispatches/', [Administration::class, 'monthly_dispatches'], name: 'administration.monthly_dispatches', methods: ['GET'], alias: ['administration/monthly_dispatches']),
        route('datatables-monthly-dispatches/<int:codgas>/<str:from>/<str:until>/', [Administration::class, 'datatables_monthly_dispatches'], name: 'administration.datatables_monthly_dispatches', methods: ['POST'], alias: ['administration/datatables_monthly_dispatches/<int:codgas>/<str:from>/<str:until>']),
        route('relation-corpo-estaciones/', [Administration::class, 'relation_corpo_estaciones'], name: 'administration.relation_corpo_estaciones', methods: ['GET'], alias: ['administration/relation_corpo_estaciones']),

        // Tipo de cambio
        route('exchange-rate/', [Administration::class, 'exchange_rate'], name: 'administration.exchange_rate', methods: ['GET'], alias: ['administration/exchange_rate']),
        route('datatable-exchange-rate/', [Administration::class, 'datatable_exchange_rate'], name: 'administration.datatable_exchange_rate', methods: ['POST'], alias: ['administration/datatable_exchange_rate']),
        route('exchange-rate-process/', [Administration::class, 'exchange_rate_process'], name: 'administration.exchange_rate_process', methods: ['POST'], alias: ['administration/exchange_rate_process']),
        route('update-exchange/', [Administration::class, 'update_exchange'], name: 'administration.update_exchange', methods: ['POST'], alias: ['administration/update_exchange']),
        route('delete-exchange/', [Administration::class, 'delete_exchange'], name: 'administration.delete_exchange', methods: ['POST'], alias: ['administration/delete_exchange']),
        route('get-binnacle/', [Administration::class, 'get_binnacle'], name: 'administration.get_binnacle', methods: ['GET'], alias: ['administration/get_binnacle']),

        // Bitácora de ajustes
        route('binnacle-adjustments/', [Administration::class, 'binnacle_adjustments'], name: 'administration.binnacle_adjustments', methods: ['GET'], alias: ['administration/binnacle_adjustments']),
        route('tabla-auditoria/<str:from>/<str:until>/<str:codgas>/', [Administration::class, 'tablaAuditoria'], name: 'administration.tablaAuditoria', methods: ['GET'], alias: ['administration/tablaAuditoria/<str:from>/<str:until>/<str:codgas>']),
        route('tabla-tickets/<str:from>/<str:until>/<str:codgas>/', [Administration::class, 'tablaTickets'], name: 'administration.tablaTickets', methods: ['GET'], alias: ['administration/tablaTickets/<str:from>/<str:until>/<str:codgas>']),

        // Documento público
        route('doc-agujita/', [Administration::class, 'doc_agujita'], name: 'administration.doc_agujita', methods: ['GET'], public: true, alias: ['administration/doc_agujita']),

        // Indicadores del inicio (main.js, cargado en todas las pantallas)
        route('porcent-estacion-info/', [Administration::class, 'porcent_estacion_info'], name: 'administration.porcent_estacion_info', methods: ['POST'], alias: ['administration/porcent_estacion_info']),
        route('porcent-estacion-facturados-info/', [Administration::class, 'porcent_estacion_facturados_info'], name: 'administration.porcent_estacion_facturados_info', methods: ['POST'], alias: ['administration/porcent_estacion_facturados_info']),
        route('porcent-facturas-info/', [Administration::class, 'porcent_facturas_info'], name: 'administration.porcent_facturas_info', methods: ['POST'], alias: ['administration/porcent_facturas_info']),

        // 4b: anticipos y débito/crédito. percent <int> (se compara con == 80); codcli <str> (viene de la base).
        // datatables_customer_advances no tiene ruta: el método no existe y su tabla no está en ninguna vista.
        route('dashboard-debit-credit/', [Administration::class, 'dashboard_debit_credit'], name: 'administration.dashboard_debit_credit', methods: ['GET'], alias: ['administration/dashboard_debit_credit']),
        route('in-process/', [Administration::class, 'in_process'], name: 'administration.in_process', methods: ['GET'], alias: ['administration/in_process']),
        route('search-credit-and-debits-clients/', [Administration::class, 'search_credit_and_debits_clients'], name: 'administration.search_credit_and_debits_clients', methods: ['GET'], alias: ['administration/search_credit_and_debits_clients']),
        route('get-advances/<str:from>/<str:until>/', [Administration::class, 'get_advances'], name: 'administration.get_advances', methods: ['GET'], alias: ['administration/get_advances/<str:from>/<str:until>']),
        route('get-advance-clients/<int:percent>/<str:from>/<str:until>/', [Administration::class, 'get_advance_clients'], name: 'administration.get_advance_clients', methods: ['GET'], alias: ['administration/get_advance_clients/<int:percent>/<str:from>/<str:until>']),
        route('get-advance-clients-details/<str:codcli>/<str:from>/<str:until>/', [Administration::class, 'get_advance_clients_details'], name: 'administration.get_advance_clients_details', methods: ['GET'], alias: ['administration/get_advance_clients_details/<str:codcli>/<str:from>/<str:until>']),
        route('datatables-anticipos/<str:from>/<str:until>/', [Administration::class, 'datatables_anticipos'], name: 'administration.datatables_anticipos', methods: ['POST'], alias: ['administration/datatables_anticipos/<str:from>/<str:until>']),
        route('datatables-customer-anticipos/<str:from>/<str:until>/', [Administration::class, 'datatables_customer_anticipos'], name: 'administration.datatables_customer_anticipos', methods: ['POST'], alias: ['administration/datatables_customer_anticipos/<str:from>/<str:until>']),
        route('datatables-customer-anticipos2/<int:percent>/<str:from>/<str:until>/', [Administration::class, 'datatables_customer_anticipos2'], name: 'administration.datatables_customer_anticipos2', methods: ['POST'], alias: ['administration/datatables_customer_anticipos2/<int:percent>/<str:from>/<str:until>']),
        route('datatables-customer-advances-details/<str:codcli>/<str:from>/<str:until>/', [Administration::class, 'datatables_customer_advances_details'], name: 'administration.datatables_customer_advances_details', methods: ['POST'], alias: ['administration/datatables_customer_advances_details/<str:codcli>/<str:from>/<str:until>']),

        // 4c: tickets de Mojo. ticket_id <int> (id_mojo numérico); ticket_form_id <int> (se castea); el resto de
        // parámetros de texto/fecha es <str>. Las 10 datatables_* no llevan parámetros: sus filtros van en el
        // body POST. get_latest_tickets, get_ticket, update_groups, update_ticket_queues, update_users,
        // update_ticket_tags, update_ticket_forms, update_ticket_types y los helpers getMondayFromWeek/
        // getSundayFromWeek/getFirstDayOfMonth/getLastDayOfMonth/getFirstDayOfYear/getLastDayOfYear no tienen
        // ruta (D11): son internos, invocados con $this->... desde update_mojo()/list_tickets()/stats_tickets().
        // get_statistics tampoco: no está enlazado desde ningún lado.
        route('list-tickets/', [Administration::class, 'list_tickets'], name: 'administration.list_tickets', methods: ['GET'], alias: ['administration/list_tickets']),
        route('stats-tickets/', [Administration::class, 'stats_tickets'], name: 'administration.stats_tickets', methods: ['GET'], alias: ['administration/stats_tickets']),
        route('ecv-calc/', [Administration::class, 'ecv_calc'], name: 'administration.ecv_calc', methods: ['GET'], alias: ['administration/ecv_calc']),
        route('filtered-statistics/<str:action>/<str:period>/<int:ticket_form_id>/<str:agent_id>/', [Administration::class, 'filtered_statistics'], name: 'administration.filtered_statistics', methods: ['GET'], alias: ['administration/filtered_statistics/<str:action>/<str:period>/<int:ticket_form_id>/<str:agent_id>']),
        route('datatables-tickets/', [Administration::class, 'datatables_tickets'], name: 'administration.datatables_tickets', methods: ['POST'], alias: ['administration/datatables_tickets']),
        route('datatables-tickets-2/', [Administration::class, 'datatables_tickets_2'], name: 'administration.datatables_tickets_2', methods: ['POST'], alias: ['administration/datatables_tickets_2']),
        route('update-mojo/', [Administration::class, 'update_mojo'], name: 'administration.update_mojo', methods: ['POST'], alias: ['administration/update_mojo']),
        route('delete-ticket/<int:ticket_id>/', [Administration::class, 'delete_ticket'], name: 'administration.delete_ticket', methods: ['GET'], alias: ['administration/delete_ticket/<int:ticket_id>']),
        route('datatables-urgentes-tickets/', [Administration::class, 'datatables_urgentes_tickets'], name: 'administration.datatables_urgentes_tickets', methods: ['POST'], alias: ['administration/datatables_urgentes_tickets']),
        route('datatables-normales-tickets/', [Administration::class, 'datatables_normales_tickets'], name: 'administration.datatables_normales_tickets', methods: ['POST'], alias: ['administration/datatables_normales_tickets']),
        route('datatables-abiertos-tickets/', [Administration::class, 'datatables_abiertos_tickets'], name: 'administration.datatables_abiertos_tickets', methods: ['POST'], alias: ['administration/datatables_abiertos_tickets']),
        route('datatables-resueltos-tickets/', [Administration::class, 'datatables_resueltos_tickets'], name: 'administration.datatables_resueltos_tickets', methods: ['POST'], alias: ['administration/datatables_resueltos_tickets']),
        route('datatables-usuarios-tickets/', [Administration::class, 'datatables_usuarios_tickets'], name: 'administration.datatables_usuarios_tickets', methods: ['POST'], alias: ['administration/datatables_usuarios_tickets']),
        route('datatables-grupos-tickets/', [Administration::class, 'datatables_grupos_tickets'], name: 'administration.datatables_grupos_tickets', methods: ['POST'], alias: ['administration/datatables_grupos_tickets']),
        route('update-ticket-db/<int:ticket_id>/', [Administration::class, 'update_ticket_db'], name: 'administration.update_ticket_db', methods: ['GET'], alias: ['administration/update_ticket_db/<int:ticket_id>']),
        route('datatables-departments-tickets/', [Administration::class, 'datatables_departments_tickets'], name: 'administration.datatables_departments_tickets', methods: ['POST'], alias: ['administration/datatables_departments_tickets']),
        route('datatables-supports-tickets/', [Administration::class, 'datatables_supports_tickets'], name: 'administration.datatables_supports_tickets', methods: ['POST'], alias: ['administration/datatables_supports_tickets']),
        route('get-priority/<str:from>/<str:until>/<str:ticket_form>/<str:priority>/<str:user_id>/', [Administration::class, 'get_priority'], name: 'administration.get_priority', methods: ['GET'], alias: ['administration/get_priority/<str:from>/<str:until>/<str:ticket_form>/<str:priority>/<str:user_id>']),
        route('get-agent-report/<str:agent>/<str:from>/<str:until>/<str:date_range>/<str:ticket_form>/', [Administration::class, 'get_agent_report'], name: 'administration.get_agent_report', methods: ['GET'], alias: ['administration/get_agent_report/<str:agent>/<str:from>/<str:until>/<str:date_range>/<str:ticket_form>']),
        route('close-ticket/<int:ticket_id>/', [Administration::class, 'close_ticket'], name: 'administration.close_ticket', methods: ['GET'], public: true, alias: ['administration/close_ticket/<int:ticket_id>']),
    ]),
];
