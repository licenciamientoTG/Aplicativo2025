<?php
// Sin permission: en el Router, igual que merma: el control es authorized()
// dentro del controlador.
return [
    group('compras/', [
        route('registro/', [Compras::class, 'registro'], name: 'compras.registro', methods: ['GET']),
        route('registro-datos/', [Compras::class, 'registro_datos'], name: 'compras.registro_datos', methods: ['GET']),
        route('guardar-celda/', [Compras::class, 'guardar_celda'], name: 'compras.guardar_celda', methods: ['POST']),
        route('renglon-manual/crear/', [Compras::class, 'renglon_manual_crear'], name: 'compras.renglon_manual_crear', methods: ['POST']),
        route('renglon-manual/eliminar/', [Compras::class, 'renglon_manual_eliminar'], name: 'compras.renglon_manual_eliminar', methods: ['POST']),
    ]),
];
