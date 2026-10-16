<?php
// board usa <str:id>: el controlador valida el entero y responde notFound().
return [
    group('tableros/', [
        route('index/', [Tableros::class, 'index'], name: 'tableros.index', methods: ['GET'], alias: ['tableros']),
        route('manage/', [Tableros::class, 'manage'], name: 'tableros.manage', methods: ['GET']),
        route('manage-workspaces/', [Tableros::class, 'manage_workspaces'], name: 'tableros.manage_workspaces', methods: ['GET'], alias: ['tableros/manage_workspaces']),
        route('board/<str:id>/', [Tableros::class, 'board'], name: 'tableros.board', methods: ['GET']),
    ]),
];
