<?php
// Operations aún no está migrado: el resto de sus URLs sigue resolviendo por el
// fallback legacy /operations/<metodo>. Aquí solo van las rutas nuevas.
return [
    group('operations/', [
        // Reporte de solo lectura: ¿el turno 4 quedó cerrado en ControlGas (Medicion)?
        route('corte-t4/', [Operations::class, 'corte_t4'], name: 'operations.corte_t4', methods: ['GET']),
        route('corte-t4/<int:codgas>/', [Operations::class, 'corte_t4_estacion'], name: 'operations.corte_t4_estacion', methods: ['GET']),
    ]),
];
