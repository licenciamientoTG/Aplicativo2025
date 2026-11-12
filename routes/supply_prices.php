<?php
// Fuel price screens keep their permission checks in Supply so the historical
// read grant and current-price maintenance grant can differ on the same page.
return [
    group('supply/', [
        route('fuel_prices/', [Supply::class, 'fuel_prices'], name: 'supply.fuel_prices', methods: ['GET']),
        route('fuel_price_history/', [Supply::class, 'fuel_price_history'], name: 'supply.fuel_price_history', methods: ['POST']),
        route('datatable_product_prices/', [Supply::class, 'datatable_product_prices'], name: 'supply.datatable_product_prices', methods: ['GET']),
        route('send_prices/', [Supply::class, 'send_prices'], name: 'supply.send_prices', methods: ['GET']),
        route('update_price/', [Supply::class, 'update_price'], name: 'supply.update_price', methods: ['POST']),
        route('delete_price/<int:codprd>/<int:codgas>/<int:fch>/<int:hra>/', [Supply::class, 'delete_price'], name: 'supply.delete_price', methods: ['GET']),
        route('get_ieps/<int:codprd>/', [Supply::class, 'get_ieps'], name: 'supply.get_ieps', methods: ['GET']),
    ]),
];
