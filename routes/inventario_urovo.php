<?php
return [
    group('inventario-urovo/', [
        route('', [inventario_urovo::class, 'index'], name: 'inventario_urovo.index', methods: ['GET']),
        route('data/', [inventario_urovo::class, 'data'], name: 'inventario_urovo.data', methods: []),
        route('batch/', [inventario_urovo::class, 'batch'], name: 'inventario_urovo.batch', methods: []),
        route('anonymous/', [inventario_urovo::class, 'anonymous'], name: 'inventario_urovo.anonymous', methods: []),
        route('anonymous/history/', [inventario_urovo::class, 'anonymous_history'], name: 'inventario_urovo.anonymous_history', methods: []),
        route('terminal/<int:id>/move/', [inventario_urovo::class, 'move'], name: 'inventario_urovo.move', methods: []),
        route('terminal/<int:id>/edit/', [inventario_urovo::class, 'edit'], name: 'inventario_urovo.edit', methods: []),
        route('terminal/<int:id>/history/', [inventario_urovo::class, 'history'], name: 'inventario_urovo.history', methods: []),
    ]),
];
