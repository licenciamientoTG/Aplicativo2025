<?php
// Alta de cuenta bancaria de proveedores (cuelga de Pagos extraordinarios).
// Toda la sección exige el 106 (se valida en el constructor del controlador);
// aprobar/rechazar exige además el 109 y se valida junto con el estatus. 'new/', 'save/' y 'check/' van antes que
// '<int:id>/'. Módulo nuevo: sin alias.
return [
    group('pagos-extraordinarios/alta-cuenta/', [
        route('', [BankAccountRequests::class, 'index'], name: 'bankaccounts.index', methods: ['GET']),
        route('new/', [BankAccountRequests::class, 'form'], name: 'bankaccounts.new', methods: ['GET']),
        route('save/', [BankAccountRequests::class, 'save'], name: 'bankaccounts.save', methods: ['POST']),
        route('check/', [BankAccountRequests::class, 'check'], name: 'bankaccounts.check', methods: ['GET']),
        route('<int:id>/decision-modal/', [BankAccountRequests::class, 'decision_modal'], name: 'bankaccounts.decision_modal', methods: ['GET'], permission: 109),
        route('<int:id>/approve/', [BankAccountRequests::class, 'approve'], name: 'bankaccounts.approve', methods: ['POST'], permission: 109),
        route('<int:id>/reject/', [BankAccountRequests::class, 'reject'], name: 'bankaccounts.reject', methods: ['POST'], permission: 109),
        route('<int:id>/upload/', [BankAccountRequests::class, 'upload'], name: 'bankaccounts.upload', methods: ['POST']),
        route('<int:id>/caratula/', [BankAccountRequests::class, 'caratula'], name: 'bankaccounts.caratula', methods: ['GET']),
        route('<int:id>/', [BankAccountRequests::class, 'detail'], name: 'bankaccounts.detail', methods: ['GET']),
    ]),
];
