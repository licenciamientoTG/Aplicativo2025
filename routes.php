<?php
// Rutas explícitas (estilo Django). Lo que no esté aquí sigue resolviéndose por la
// convención antigua /controlador/método mientras ROUTER_LEGACY_FALLBACK sea true.
return [
    route('', [Home::class, 'index'], name: 'home', methods: ['GET']),

    group('stations/', [
        route('readings/', [Stations::class, 'readings'], name: 'stations.readings', methods: ['GET', 'POST']),
    ]),
];
