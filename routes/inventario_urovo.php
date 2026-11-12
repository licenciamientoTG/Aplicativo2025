<?php
return [
    group('inventario-urovo/', [
        route('', [inventario_urovo::class, 'index'], name: 'inventario_urovo.index', methods: ['GET']),
        route('data/', [inventario_urovo::class, 'data'], name: 'inventario_urovo.data', methods: []),
        route('dashboard/', [inventario_urovo::class, 'dashboard'], name: 'inventario_urovo.dashboard', methods: ['GET']),
        route('summary/', [inventario_urovo::class, 'summary'], name: 'inventario_urovo.summary', methods: ['GET']),
        route('summary/<int:station_id>/', [inventario_urovo::class, 'summary_detail'], name: 'inventario_urovo.summary_detail', methods: ['GET']),
        route('batch/', [inventario_urovo::class, 'batch'], name: 'inventario_urovo.batch', methods: []),
        route('terminal/<int:id>/move/', [inventario_urovo::class, 'move'], name: 'inventario_urovo.move', methods: []),
        route('terminal/<int:id>/edit/', [inventario_urovo::class, 'edit'], name: 'inventario_urovo.edit', methods: []),
        route('terminal/<int:id>/history/', [inventario_urovo::class, 'history'], name: 'inventario_urovo.history', methods: []),
    ]),
];
