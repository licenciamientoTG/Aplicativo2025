<?php
// Pagos extraordinarios (captura). Permiso 106 = solicitar; el detalle valida
// 106-110 y la propiedad de la solicitud en el controlador (no en la ruta).
// 'new/' y 'save/' van antes que '<int:id>/'. Módulo nuevo: sin alias.
return [
    group('pagos-extraordinarios/', [
        route('', [ExtraPayments::class, 'index'], name: 'extrapayments.index', methods: ['GET'], permission: 106),
        route('new/', [ExtraPayments::class, 'form'], name: 'extrapayments.new', methods: ['GET'], permission: 106),
        route('save/', [ExtraPayments::class, 'save'], name: 'extrapayments.save', methods: ['POST'], permission: 106),
        route('<int:id>/edit/', [ExtraPayments::class, 'form'], name: 'extrapayments.edit', methods: ['GET'], permission: 106),
        route('<int:id>/cancel/', [ExtraPayments::class, 'cancel'], name: 'extrapayments.cancel', methods: ['POST'], permission: 106),
        // Bandejas y decisiones: el permiso (107/108/109) depende de la etapa o del
        // estatus ACTUAL de la solicitud, por eso se valida en el controlador.
        route('inbox/<str:stage>/', [ExtraPayments::class, 'inbox'], name: 'extrapayments.inbox', methods: ['GET']),
        route('<int:id>/decision-modal/', [ExtraPayments::class, 'decision_modal'], name: 'extrapayments.decision_modal', methods: ['GET']),
        route('<int:id>/approve/', [ExtraPayments::class, 'approve'], name: 'extrapayments.approve', methods: ['POST']),
        route('<int:id>/reject/', [ExtraPayments::class, 'reject'], name: 'extrapayments.reject', methods: ['POST']),
        route('<int:id>/pay-modal/', [ExtraPayments::class, 'pay_modal'], name: 'extrapayments.pay_modal', methods: ['GET']),
        route('<int:id>/pay/', [ExtraPayments::class, 'pay'], name: 'extrapayments.pay', methods: ['POST'], permission: 109),
        route('<int:id>/', [ExtraPayments::class, 'detail'], name: 'extrapayments.detail', methods: ['GET']),
    ]),
];
