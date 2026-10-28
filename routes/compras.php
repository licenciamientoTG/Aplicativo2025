<?php
// Sin permission: en el Router, igual que merma: el control es authorized()
// dentro del controlador.
return [
    group('compras/', [
        route('registro/', [Compras::class, 'registro'], name: 'compras.registro', methods: ['GET']),
    ]),
];
