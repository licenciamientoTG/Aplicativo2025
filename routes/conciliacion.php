<?php
// Conciliaciones entre sistemas. Permiso 14 (Ingresos), igual que Conciliación crédito/débito.
return [
    group('conciliacion/', [
        route('onegoal-controlgas/', [Conciliacion::class, 'onegoal_controlgas'], name: 'conciliacion.onegoal_controlgas', methods: ['GET'], permission: 14),
        route('onegoal-controlgas/anticipos/', [Conciliacion::class, 'onegoal_controlgas_anticipos'], name: 'conciliacion.onegoal_controlgas_anticipos', methods: ['POST'], permission: 14),
    ]),
];
