<?php
// Descarga de APKs de pruebas. Públicas (sin login de usuario); el acceso lo protege un código de acceso.
return [
    group('deploy/', [
        route('', [Deploy::class, 'index'], name: 'deploy.index', methods: ['GET', 'POST'], public: true),
        route('download/<str:file>/', [Deploy::class, 'download'], name: 'deploy.download', methods: ['GET'], public: true),
        route('salir/', [Deploy::class, 'logout'], name: 'deploy.logout', methods: ['GET'], public: true),
    ]),
];
