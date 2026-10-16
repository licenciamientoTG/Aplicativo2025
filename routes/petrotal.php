<?php
// Los permisos siguen en los authorized() del controlador (D9).
// descargar_json/acuse declaran el id; sin id siguen entrando por el fallback.
return [
    group('petrotal/', [
        route('volumetricos/', [Petrotal::class, 'volumetricos'], name: 'petrotal.volumetricos', methods: ['GET']),
        route('preview-json/', [Petrotal::class, 'preview_json'], name: 'petrotal.preview_json', methods: ['GET'], alias: ['petrotal/preview_json']),
        route('generar-json/', [Petrotal::class, 'generar_json'], name: 'petrotal.generar_json', methods: ['POST'], alias: ['petrotal/generar_json']),
        route('enviar-reporte/', [Petrotal::class, 'enviar_reporte'], name: 'petrotal.enviar_reporte', methods: ['POST'], alias: ['petrotal/enviar_reporte']),
        route('configuracion-fiel/', [Petrotal::class, 'configuracion_fiel'], name: 'petrotal.configuracion_fiel', methods: ['GET'], alias: ['petrotal/configuracion_fiel']),
        route('guardar-fiel/', [Petrotal::class, 'guardar_fiel'], name: 'petrotal.guardar_fiel', methods: ['POST'], alias: ['petrotal/guardar_fiel']),
        route('historial/', [Petrotal::class, 'historial'], name: 'petrotal.historial', methods: ['GET']),
        route('descargar-json/<int:id>/', [Petrotal::class, 'descargar_json'], name: 'petrotal.descargar_json', methods: ['GET'], alias: ['petrotal/descargar_json/<int:id>']),
        route('descargar-acuse/<int:id>/', [Petrotal::class, 'descargar_acuse'], name: 'petrotal.descargar_acuse', methods: ['GET'], alias: ['petrotal/descargar_acuse/<int:id>']),
    ]),
];
