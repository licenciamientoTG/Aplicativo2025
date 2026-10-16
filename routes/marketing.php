<?php
// Parámetros <str:x>: tarjetas y códigos de cliente pueden traer letras o ceros a la izquierda.
// print_sticker solo declara los 3 parámetros que usa el menú; con $image sigue entrando por el fallback.
return [
    group('marketing/', [
        route('client-cards/', [Marketing::class, 'client_cards'], name: 'marketing.client_cards', methods: ['GET'], alias: ['marketing/client_cards']),
        route('client-cards-nexus/', [Marketing::class, 'client_cards_nexus'], name: 'marketing.client_cards_nexus', methods: ['GET'], alias: ['marketing/client_cards_nexus']),
        route('mqg-cards/', [Marketing::class, 'mqg_cards'], name: 'marketing.mqg_cards', methods: ['GET'], alias: ['marketing/mqg_cards']),

        route('find-client-form/', [Marketing::class, 'findClientForm'], name: 'marketing.findClientForm', methods: ['POST'], alias: ['marketing/findClientForm']),
        route('find-client-table/', [Marketing::class, 'findClientTable'], name: 'marketing.findClientTable', methods: ['POST'], alias: ['marketing/findClientTable']),
        route('sticker-modal/', [Marketing::class, 'stickerModal'], name: 'marketing.stickerModal', methods: ['POST'], alias: ['marketing/stickerModal']),

        route('print-card/<str:card>/<str:codcli>/', [Marketing::class, 'print_card'], name: 'marketing.print_card', methods: ['GET'], alias: ['marketing/print_card/<str:card>/<str:codcli>']),
        route('print-card-2/<str:card>/<str:codcli>/', [Marketing::class, 'print_card_2'], name: 'marketing.print_card_2', methods: ['GET'], alias: ['marketing/print_card_2/<str:card>/<str:codcli>']),
        route('print-uber-card/<str:card>/<str:codcli>/', [Marketing::class, 'print_uber_card'], name: 'marketing.print_uber_card', methods: ['GET'], alias: ['marketing/print_uber_card/<str:card>/<str:codcli>']),
        route('print-sticker-2/<str:card>/<str:codcli>/', [Marketing::class, 'print_sticker_2'], name: 'marketing.print_sticker_2', methods: ['GET'], alias: ['marketing/print_sticker_2/<str:card>/<str:codcli>']),
        route('print-driver-card/<str:codcli>/<str:nrocho>/', [Marketing::class, 'print_driver_card'], name: 'marketing.print_driver_card', methods: ['GET'], alias: ['marketing/print_driver_card/<str:codcli>/<str:nrocho>']),
        route('print-card-nexus/', [Marketing::class, 'print_card_nexus'], name: 'marketing.print_card_nexus', methods: ['GET'], alias: ['marketing/print_card_nexus']),
        route('print-sticker/<str:station>/<str:from>/<str:until>/', [Marketing::class, 'print_sticker'], name: 'marketing.print_sticker', methods: ['GET'], alias: ['marketing/print_sticker/<str:station>/<str:from>/<str:until>']),
    ]),
];
