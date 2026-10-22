<?php
// Los permisos siguen en guard()/authorized() del controlador (D9).
// exportar($sesion_id) no se usa en ningún lado: sin ruta (D11).
return [
    group('arqueo/', [
        route('index/', [Arqueo::class, 'index'], name: 'arqueo.index', methods: ['GET'], alias: ['arqueo']),
        route('cajas/<int:sesion_id>/', [Arqueo::class, 'cajas'], name: 'arqueo.cajas', methods: ['GET']),
        route('asignar-caja/', [Arqueo::class, 'asignar_caja'], name: 'arqueo.asignar_caja', methods: ['POST'], alias: ['arqueo/asignar_caja']),
        route('crear-sesion/', [Arqueo::class, 'crear_sesion'], name: 'arqueo.crear_sesion', methods: ['POST'], alias: ['arqueo/crear_sesion']),
        route('abrir/<int:sesion_id>/', [Arqueo::class, 'abrir'], name: 'arqueo.abrir', methods: ['POST']),
        route('eliminar/<int:sesion_id>/', [Arqueo::class, 'eliminar_sesion'], name: 'arqueo.eliminar', methods: ['POST']),
        route('cerrar/<int:sesion_id>/', [Arqueo::class, 'cerrar'], name: 'arqueo.cerrar', methods: ['POST']),

        route('caja/<int:caja_id>/', [Arqueo::class, 'caja'], name: 'arqueo.caja', methods: ['GET']),
        route('imprimir-caja/<int:caja_id>/', [Arqueo::class, 'imprimir_caja'], name: 'arqueo.imprimir_caja', methods: ['GET'], alias: ['arqueo/imprimir_caja/<int:caja_id>']),
        route('guardar-caja/<int:caja_id>/', [Arqueo::class, 'guardar_caja'], name: 'arqueo.guardar_caja', methods: ['POST'], alias: ['arqueo/guardar_caja/<int:caja_id>']),

        route('concentrado/<int:sesion_id>/', [Arqueo::class, 'concentrado'], name: 'arqueo.concentrado', methods: ['GET']),
        route('auditoria/<int:sesion_id>/', [Arqueo::class, 'auditoria'], name: 'arqueo.auditoria', methods: ['GET']),
        route('guardar-concentrado-extra/', [Arqueo::class, 'guardar_concentrado_extra'], name: 'arqueo.guardar_concentrado_extra', methods: ['POST'], alias: ['arqueo/guardar_concentrado_extra']),
        route('subir-imagen-concentrado/', [Arqueo::class, 'subir_imagen_concentrado'], name: 'arqueo.subir_imagen_concentrado', methods: ['POST'], alias: ['arqueo/subir_imagen_concentrado']),
        route('imagenes-concentrado/', [Arqueo::class, 'imagenes_concentrado'], name: 'arqueo.imagenes_concentrado', methods: ['GET'], alias: ['arqueo/imagenes_concentrado']),
        route('ver-imagen-concentrado/<int:imagen_id>/', [Arqueo::class, 'ver_imagen_concentrado'], name: 'arqueo.ver_imagen_concentrado', methods: ['GET'], alias: ['arqueo/ver_imagen_concentrado/<int:imagen_id>']),
        route('eliminar-imagen-concentrado/', [Arqueo::class, 'eliminar_imagen_concentrado'], name: 'arqueo.eliminar_imagen_concentrado', methods: ['POST'], alias: ['arqueo/eliminar_imagen_concentrado']),
        route('exportar-cajas/<int:sesion_id>/', [Arqueo::class, 'exportar_cajas'], name: 'arqueo.exportar_cajas', methods: ['GET'], alias: ['arqueo/exportar_cajas/<int:sesion_id>']),
    ]),
];
