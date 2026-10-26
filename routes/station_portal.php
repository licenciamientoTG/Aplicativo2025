<?php
// Sin permission: en el Router, igual que el resto de la Fase 2 (D9
// pospuesto): el control sigue siendo authorized() dentro del controlador
// (PERM_VER=84 / PERM_TODAS_ESTACIONES=85 / PERM_ELIMINAR=86).
return [
    group('station-portal/', [
        route('alertas/', [station_portal::class, 'alertas'], name: 'station_portal.alertas', methods: ['GET'], alias: ['station_portal/alertas']),
        route('mis-recepciones/', [station_portal::class, 'mis_recepciones'], name: 'station_portal.mis_recepciones', methods: ['GET'], alias: ['station_portal/mis_recepciones']),
        route('recepciones-programadas/', [station_portal::class, 'recepciones_programadas'], name: 'station_portal.recepciones_programadas', methods: ['GET'], alias: ['station_portal/recepciones_programadas']),
        route('datatables-recepciones/', [station_portal::class, 'datatables_recepciones'], name: 'station_portal.datatables_recepciones', methods: ['GET'], alias: ['station_portal/datatables_recepciones']),
        route('resumen-sin-documento/', [station_portal::class, 'resumen_sin_documento'], name: 'station_portal.resumen_sin_documento', methods: ['GET'], alias: ['station_portal/resumen_sin_documento']),
        route('upload-remision/', [station_portal::class, 'upload_remision'], name: 'station_portal.upload_remision', methods: ['POST'], alias: ['station_portal/upload_remision']),
        route('remisiones-by-recepcion/', [station_portal::class, 'remisiones_by_recepcion'], name: 'station_portal.remisiones_by_recepcion', methods: ['GET'], alias: ['station_portal/remisiones_by_recepcion']),
        route('delete-remision/', [station_portal::class, 'delete_remision'], name: 'station_portal.delete_remision', methods: ['POST'], alias: ['station_portal/delete_remision']),
        route('view-remision/<int:id>/', [station_portal::class, 'view_remision'], name: 'station_portal.view_remision', methods: ['GET'], alias: ['station_portal/view_remision/<int:id>']),
        route('manual/', [station_portal::class, 'manual'], name: 'station_portal.manual', methods: ['GET'], alias: ['station_portal/manual']),
        route('descargar-factura-programada/<int:id>/<str:tipo>/', [station_portal::class, 'descargar_factura_programada'], name: 'station_portal.descargar_factura_programada', methods: ['GET'], alias: ['station_portal/descargar_factura_programada/<int:id>/<str:tipo>']),
        route('descargar-factura-recepcion/<int:nrotrn>/<str:tipo>/', [station_portal::class, 'descargar_factura_recepcion'], name: 'station_portal.descargar_factura_recepcion', methods: ['GET'], alias: ['station_portal/descargar_factura_recepcion/<int:nrotrn>/<str:tipo>']),
    ]),
];
