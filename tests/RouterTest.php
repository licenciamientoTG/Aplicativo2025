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

// --- Lint ---
load_fixture();
check('lint: fixture sano => sin problemas', Router::lint() === []);

Router::reset();
Router::load([
    route('a/', [Fx::class, 'index'], name: 'dup'),
    route('b/', [Fx::class, 'index'], name: 'dup'),
]);
$p = Router::lint();
check('lint: nombre duplicado', count($p) === 1 && str_contains($p[0], "Nombre duplicado 'dup'"));

// Review Focus 3: misma URL declarada dos veces
Router::reset();
Router::load([
    route('x/<int:id>', [Fx::class, 'show'], name: 'x1'),
    route('x/<slug:id>', [Fx::class, 'show'], name: 'x2'),
]);
$p = Router::lint();
check('lint: conflicto de URL (aunque cambie el tipo)', count($p) === 1 && str_contains($p[0], 'Conflicto'));

Router::reset();
Router::load([
    route('x/', [Fx::class, 'index'], name: 'g', methods: ['GET']),
    route('x/', [Fx::class, 'edit'], name: 'p', methods: ['POST']),
]);
check('lint: misma URL con verbos distintos no es conflicto', Router::lint() === []);

Router::reset();
Router::load([
    route('x/', [Fx::class, 'index'], name: 'g', methods: ['GET']),
    route('x/', [Fx::class, 'index'], name: 'todos'),
]);
check('lint: verbo vacío (todos) choca con GET', count(Router::lint()) === 1);

// Review Focus 3: ruta específica tapada por una general anterior
Router::reset();
Router::load([
    route('pay/<str:id>', [Fx::class, 'show'], name: 'general'),
    route('pay/new', [Fx::class, 'index'], name: 'nueva'),
]);
$p = Router::lint();
check('lint: ruta literal tapada por una con parámetro anterior', count($p) === 1 && str_contains($p[0], 'tapada'));

Router::reset();
Router::load([
    route('pay/new', [Fx::class, 'index'], name: 'nueva'),
    route('pay/<str:id>', [Fx::class, 'show'], name: 'general'),
]);
check('lint: específica antes que general es correcto', Router::lint() === []);

Router::reset();
Router::load([
    route('pay/<str:id>', [Fx::class, 'show'], name: 'general', methods: ['GET']),
    route('pay/new', [Fx::class, 'index'], name: 'nueva', methods: ['POST']),
]);
check('lint: con verbos distintos no se tapan', Router::lint() === []);

Router::reset();
Router::load([
    route('files/<path:p>', [Fx::class, 'file'], name: 'archivos'),
    route('files/<slug:s>/edit', [Fx::class, 'file'], name: 'editar'),
]);
check('lint: path tapa a las rutas más largas que le siguen', count(Router::lint()) === 1);

// Review Focus 2: alias con parámetros distintos / en otro orden
Router::reset();
Router::load([
    route('a/<int:id>/<slug:s>', [Fx::class, 'show'], name: 'a', alias: ['old/<slug:s>/<int:id>']),
]);
$p = Router::lint();
check('lint: alias con otro orden de parámetros', count($p) === 1 && str_contains($p[0], 'alias'));

Router::reset();
Router::load([
    route('a/<int:id>', [Fx::class, 'show'], name: 'a', alias: ['old/<int:otro>']),
]);
check('lint: alias con otro nombre de parámetro', count(Router::lint()) === 1);

Router::reset();
Router::load([
    route('a/<int:id>', [Fx::class, 'show'], name: 'a', alias: ['old/a/<int:id>']),
]);
check('lint: alias correcto no da problemas', Router::lint() === []);

// Review Focus 4: handlers rotos
class FxPriv
{
    private function oculto() {}
    public function dos(int $a, int $b) {}
    public function ninguno() {}
}
Router::reset();
Router::load([
    route('r1', ['NoExiste', 'x'], name: 'r1'),
    route('r2', [Fx::class, 'nada'], name: 'r2'),
    route('r3', [FxPriv::class, 'oculto'], name: 'r3'),
    route('r4/<int:id>', [FxPriv::class, 'dos'], name: 'r4'),
    route('r5/<int:id>', [FxPriv::class, 'ninguno'], name: 'r5'),
    route('r6/<int:id>', [Fx::class, 'show'], name: 'r6'),
]);
check('lint sin handlers no los revisa', Router::lint() === []);
$p = Router::lint(true);
check('lint handlers: clase inexistente', count(array_filter($p, fn($t) => str_contains($t, "'NoExiste' no existe"))) === 1);
check('lint handlers: método inexistente', count(array_filter($p, fn($t) => str_contains($t, 'Fx::nada() no existe'))) === 1);
check('lint handlers: método privado', count(array_filter($p, fn($t) => str_contains($t, 'no es público'))) === 1);
check('lint handlers: faltan parámetros', count(array_filter($p, fn($t) => str_contains($t, 'FxPriv::dos') && str_contains($t, 'requiere'))) === 1);
check('lint handlers: sobran parámetros', count(array_filter($p, fn($t) => str_contains($t, 'FxPriv::ninguno') && str_contains($t, 'acepta'))) === 1);
check('lint handlers: ruta correcta no da problema', count(array_filter($p, fn($t) => str_contains($t, 'r6'))) === 0);

finish();
