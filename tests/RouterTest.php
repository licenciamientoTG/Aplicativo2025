<?php
declare(strict_types=1);

/**
 * Pruebas del Router. No necesita base de datos:
 *
 *     php tests/RouterTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/Router.class.php';

class Fx
{
    public function index() {}
    public function show(int $id) {}
    public function edit(int $id) {}
    public function file(string $p) {}
}

function load_fixture(): void
{
    Router::reset();
    Router::load([
        route('', [Fx::class, 'index'], name: 'home', methods: ['GET']),
        group('supply/', [
            route('pay/<int:id>/', [Fx::class, 'show'], name: 'pay', methods: ['GET'], alias: ['supply/show_pay/<int:id>']),
            route('pay/<int:id>/edit/', [Fx::class, 'edit'], name: 'pay.edit', methods: ['POST'], permission: 5),
            route('f/<path:p>', [Fx::class, 'file'], name: 'file'),
        ], ['permission' => 9, 'public' => true]),
    ]);
}

load_fixture();

// --- Comportamiento base (regresión del commit 90d58f0f) ---
$m = Router::match('', 'GET');
check('raíz coincide', $m !== null && $m['route']['name'] === 'home');
$m = Router::match('/supply/pay/15/', 'GET');
check('int se castea', $m !== null && $m['args'] === [15]);
check('sin barra final coincide igual', Router::match('supply/pay/15', 'GET') !== null);
check('int rechaza letras', Router::match('supply/pay/abc', 'GET') === null);
check('verbo incorrecto => 405', (Router::match('supply/pay/15/edit', 'GET')['error'] ?? 0) === 405);
check('literal sin distinguir mayúsculas', Router::match('SUPPLY/pay/3/edit', 'POST') !== null);
check('path acepta barras', (Router::match('supply/f/a/b.txt', 'GET')['args'] ?? []) === ['a/b.txt']);
check('ruta desconocida => null', Router::match('nada', 'GET') === null);
check('group hereda permission', Router::match('supply/pay/15', 'GET')['route']['permission'] === 9);
check('route gana al group si define permission', Router::match('supply/pay/1/edit', 'POST')['route']['permission'] === 5);
check('group hereda public', Router::match('supply/pay/15', 'GET')['route']['public'] === true);
check('reverse', Router::reverse('pay', ['id' => 15]) === '/supply/pay/15');
check('reverse raíz', Router::reverse('home') === '/');
check('reverse path no escapa barras', Router::reverse('file', ['p' => 'a/b c.txt']) === '/supply/f/a/b c.txt');
check('reverse valida el valor', throws(fn() => Router::reverse('pay', ['id' => 'x'])));
check('reverse exige el parámetro', throws(fn() => Router::reverse('pay')));
check('reverse nombre desconocido', throws(fn() => Router::reverse('nope')));

// --- Review Focus 1: segmentos de más / %2F ya decodificado ---
check('str no cruza barras (segmentos de más => null)', Router::match('supply/pay/15/extra/more', 'GET') === null);
check('doble barra interna => null, sin excepción', Router::match('supply//pay/15', 'GET') === null);

// --- Alias ---
$a = Router::match('supply/show_pay/15', 'GET');
check('alias coincide', $a !== null);
check('alias marca is_alias', $a['route']['is_alias'] === true);
check('alias entrega el mismo handler', $a['route']['handler'] === [Fx::class, 'show']);
check('alias castea int', $a['args'] === [15]);
check('ruta principal no es alias', Router::match('supply/pay/15', 'GET')['route']['is_alias'] === false);
check('alias respeta los verbos de la principal', (Router::match('supply/show_pay/15', 'POST')['error'] ?? 0) === 405);
check('reverse usa la ruta principal, no el alias', Router::reverse('pay', ['id' => 7]) === '/supply/pay/7');
check('alias no hereda el prefijo del group', Router::match('supply/supply/show_pay/15', 'GET') === null);

// --- reset / routes / export ---
check('routes() incluye principal + alias', count(Router::routes()) === 5);
$ex = Router::export();
check('export solo trae rutas con nombre', array_keys($ex) === ['home', 'pay', 'pay.edit', 'file']);
check('export trae pattern y tipos', $ex['pay'] === ['pattern' => 'supply/pay/<int:id>', 'params' => ['id' => 'int']]);
Router::reset();
check('reset vacía todo', Router::routes() === [] && Router::match('', 'GET') === null);

finish();
