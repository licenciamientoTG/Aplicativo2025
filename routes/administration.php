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
    ]),
];
