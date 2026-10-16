# Router — Fase 0: infraestructura y red de seguridad

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dejar listo el router explícito (commit `90d58f0f`) para migrar los 22 controladores por fases sin romper nada: alias hacia las URLs viejas, linter de rutas, log de uso de URLs viejas, helper de URLs para JS y un 404 real. **No se migra ningún controlador nuevo en esta fase.**

**Architecture:** `Router` gana soporte de `alias` (URLs viejas que apuntan al mismo método que la ruta nueva), `lint()`, `export()` y `reset()`. Las rutas se parten en `routes/{modulo}.php`. Un `RouteLog` registra en `logs/legacy_routes.log` cada petición que entra por alias o por el fallback antiguo, y `tools/routes.php` lista rutas, las valida y resume ese log. El JS recibe las rutas con nombre en `window.ROUTES` y las resuelve con `routeUrl()`.

**Tech Stack:** PHP 8.4 (sin framework de pruebas: scripts `php tests/XTest.php` con el helper `check()`, igual que `tests/DespachosSqlTest.php`), Twig 3, Node 24 solo para probar el helper de JS.

**Spec:** `docs/superpowers/specs/2026-10-16-router-migracion-design.md` (objetivo, decisiones, fases 0-5 y convenciones de toda la migración). El router base es el commit `90d58f0f`; esta fase se apoya en `_assets/classes/Router.class.php`, `routes.php` e `index.php`.

**Estado (2026-10-16):** plan escrito y revisado; **todavía no se ejecuta**. Se retoma después. Para retomarlo: leer el spec, leer este plan completo y empezar por la Tarea 1 (todas las tareas son independientes de la base de datos salvo el checklist manual de la Tarea 8).

## Global Constraints

- PHP 8.4; el código nuevo sigue el estilo del repo (comentarios en español, `declare(strict_types=1)` solo en archivos nuevos de `tests/` y `tools/`).
- Sin dependencias nuevas de Composer ni de npm.
- `ROUTER_LEGACY_FALLBACK` se queda en `true`. Ninguna URL actual puede dejar de funcionar.
- Las funciones globales del router se llaman `route()` y `group()`; la función de Twig es `url()`; la de JS es `routeUrl()` (no `url()`, porque el JS y el CSS ya usan `url(...)` en otros contextos).
- Los alias son rutas absolutas (no heredan el prefijo del `group()`), deben tener los mismos parámetros, en el mismo orden, que la ruta principal.
- El log vive en `logs/legacy_routes.log` (la carpeta `logs/` ya está en `.gitignore`).
- Cada tarea termina con un commit. Los mensajes de commit terminan con la línea `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Directorio de trabajo: `C:\Users\alfo.ochoa\Documents\Desarrollo\Aplicativo2025`. Los comandos están en sintaxis bash (Git Bash).

## Decisiones acordadas

Confirmadas con el responsable del proyecto el 2026-10-16. El ejecutor no debe reabrirlas.

1. **Helper de JS = `routeUrl()`**, no `url()`. El JS y el CSS del proyecto ya usan `url(...)` con otro sentido y el linter daría falsos positivos. En Twig sí se llama `url()`, porque ahí el linter solo mira dentro de `{{ }}` y `{% %}`.
2. **El 404 deja de hacer `var_dump`.** Ahora responde con estado HTTP 404: una página HTML sencilla, o `{"error":"Ruta no encontrada"}` si la petición es AJAX. (Antes respondía 200 con un volcado de texto.)
3. **Orden de los archivos de rutas: alfabético por nombre de archivo, gana la primera coincidencia.** Dentro de un archivo, las rutas más específicas (`pay/new`) van **antes** que las generales (`pay/<str:id>`). No hay que acordarse de esto a mano: `Router::lint()` marca como "tapada" cualquier ruta que otra anterior ya atrapa (Tarea 2).
4. **Los alias son URLs absolutas hacia el mismo método** y existen solo mientras se migran los enlaces; se retiran en la Fase 5 con base en `logs/legacy_routes.log`.

## Review Focus

Entradas y condiciones que el diseño implica pero que ninguna prueba "feliz" cubre. Cada una tiene su prueba en la tarea indicada.

1. URL con segmentos de más o `%2F` decodificado en un parámetro `str` (`supply/pay/a/b`): debe no coincidir (devolver `null`) y caer al fallback, sin excepción. → Tarea 1.
2. Alias con parámetros distintos o en otro orden que la ruta principal: los argumentos posicionales llegarían cambiados al método. → Tarea 2.
3. Dos rutas que resuelven la misma URL, una ruta específica tapada por una general declarada antes (`pay/<str:id>` antes que `pay/new`) o un nombre repetido: la primera gana en silencio y la otra queda muerta. → Tarea 2.
4. Ruta que apunta a un método inexistente, privado o con otro número de parámetros: hoy falla recién en producción al visitarla. → Tarea 2.
5. Path con tabuladores o saltos de línea (inyección en el log) y carpeta `logs/` inexistente o sin permiso de escritura: el log nunca debe romper la petición ni permitir líneas falsas. → Tarea 4.

---

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `_assets/classes/Router.class.php` (modifica) | Resolver rutas; ahora con `alias`, `reset()`, `routes()`, `export()`, `lint()` |
| `_assets/classes/autoload.php` (nuevo) | Autoload de clases/controladores/modelos, compartido por `index.php` y `tools/routes.php` |
| `routes.php` (modifica) | Solo carga `routes/*.php` |
| `routes/home.php`, `routes/stations.php` (nuevos) | Rutas por módulo (un archivo por controlador) |
| `_assets/classes/RouteLog.class.php` (nuevo) | Escribir y resumir `logs/legacy_routes.log` |
| `_assets/classes/RouteUsage.class.php` (nuevo) | Encontrar `url('x')` en Twig y `routeUrl('x')` en JS |
| `_assets/controllers/errors.php` (modifica) | 404 real (estado 404, HTML o JSON) |
| `_assets/js/route_url.js` (nuevo) | `routeUrl(name, params)` para JS |
| `views/layouts/base.html` (modifica) | Publica `window.ROUTES` y carga `route_url.js` |
| `tools/routes.php` (nuevo) | CLI: `list`, `lint`, `legacy-hits` |
| `tests/support.php` (nuevo) | Helpers `check()`, `throws()`, `finish()` |
| `tests/RouterTest.php`, `tests/RouteLogTest.php`, `tests/RouteUsageTest.php`, `tests/ErrorsTest.php`, `tests/route_url_test.js` (nuevos) | Pruebas |
| `index.php`, `CLAUDE.md` (modifican) | Cableado y documentación |

---

### Task 1: Router — alias, `reset()`, `routes()`, `export()`

**Files:**
- Create: `tests/support.php`
- Create: `tests/RouterTest.php`
- Modify: `_assets/classes/Router.class.php` (`load`, `flatten`, nuevos métodos, función `route()`)

**Interfaces:**
- Produces:
  - `route(string $pattern, array $handler, ?string $name = null, array $methods = [], ?int $permission = null, bool $public = false, array $alias = []): array`
  - `Router::reset(): void`
  - `Router::routes(): array` — lista de definiciones; cada una con las claves `pattern, handler, name, methods, permission, public, alias, params, regex, is_alias, canonical_params`.
  - `Router::export(): array` — `[nombre => ['pattern' => string, 'params' => [param => tipo]]]`, solo rutas con nombre y no alias.
  - `Router::match()` ahora devuelve en `['route']` la clave `is_alias` (bool).
  - `tests/support.php`: `check(string $name, bool $condition): void`, `throws(callable $fn): bool` (true si lanza `InvalidArgumentException`), `finish(): never` (sale con código 1 si hubo fallas).

- [ ] **Step 1: Crear el helper de pruebas**

Crear `tests/support.php`:

```php
<?php
declare(strict_types=1);

/**
 * Helpers mínimos para las pruebas (sin framework):
 *
 *     php tests/RouterTest.php
 *
 * Cada archivo de prueba hace require de este y termina con finish().
 */
$GLOBALS['failures'] = 0;

function check(string $name, bool $condition): void
{
    echo ($condition ? 'ok    ' : 'FALLA ') . $name . "\n";
    if (!$condition) {
        $GLOBALS['failures']++;
    }
}

function throws(callable $fn): bool
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
}

function finish(): never
{
    echo $GLOBALS['failures'] === 0 ? "\nTodo bien\n" : "\n{$GLOBALS['failures']} falla(s)\n";
    exit($GLOBALS['failures'] === 0 ? 0 : 1);
}
```

- [ ] **Step 2: Escribir las pruebas que fallan**

Crear `tests/RouterTest.php`:

```php
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
```

- [ ] **Step 3: Verificar que falla**

Run: `php tests/RouterTest.php`
Expected: error fatal `Call to undefined method Router::reset()` (o `Unknown named parameter $alias`).

- [ ] **Step 4: Implementar**

En `_assets/classes/Router.class.php`:

4a. Justo antes de `public static function load`, agregar:

```php
    /** Vacía las rutas registradas (lo usan las pruebas). */
    public static function reset(): void {
        self::$routes = [];
        self::$named  = [];
    }

    /** Todas las definiciones registradas, alias incluidos (para herramientas). */
    public static function routes(): array {
        return self::$routes;
    }

    /** Rutas con nombre, listas para serializar a JSON y usar desde JS. */
    public static function export(): array {
        $out = [];
        foreach (self::$named as $name => $def) {
            $out[$name] = ['pattern' => $def['pattern'], 'params' => $def['params']];
        }
        return $out;
    }
```

4b. Reemplazar el cuerpo del `foreach` final de `flatten` (desde el comentario `// Las opciones del grupo rellenan…` hasta `$out[] = $def;`) para que quede así:

```php
            // Las opciones del grupo rellenan solo lo que la ruta dejó en su valor por defecto.
            foreach ($inherit as $key => $value) {
                if ($def[$key] === null || $def[$key] === false || $def[$key] === []) {
                    $def[$key] = $key === 'methods' ? array_map('strtoupper', $value) : $value;
                }
            }
            $def['pattern']   = self::normalize($prefix . $def['pattern']);
            $def['params']    = self::paramTypes($def['pattern']);
            $def['is_alias']  = false;
            $def['canonical_params'] = array_keys($def['params']);
            $out[] = $def;

            // Cada alias es la misma ruta bajo una URL absoluta (normalmente la URL vieja).
            foreach ($def['alias'] as $aliasPattern) {
                $alias = $def;
                $alias['pattern']  = self::normalize($aliasPattern);
                $alias['params']   = self::paramTypes($alias['pattern']);
                $alias['name']     = null;
                $alias['alias']    = [];
                $alias['is_alias'] = true;
                $out[] = $alias;
            }
```

4c. Reemplazar la función global `route()` por:

```php
function route(string $pattern, array $handler, ?string $name = null, array $methods = [], ?int $permission = null, bool $public = false, array $alias = []): array {
    return [
        'pattern'    => $pattern,
        'handler'    => $handler,
        'name'       => $name,
        'methods'    => array_map('strtoupper', $methods),
        'permission' => $permission,
        'public'     => $public,
        'alias'      => $alias,
    ];
}
```

Y en el docblock de `route()` agregar la línea ` *   alias      URLs viejas absolutas que apuntan a este mismo método (mismos parámetros)`.

- [ ] **Step 5: Verificar que pasa**

Run: `php tests/RouterTest.php`
Expected: todas las líneas `ok`, termina con `Todo bien`.

- [ ] **Step 6: Verificar que lo ya desplegado sigue sano**

Run: `php -l _assets/classes/Router.class.php && php -l routes.php && php -r 'require "_assets/classes/Router.class.php"; Router::load(require "routes.php"); echo count(Router::routes()), " rutas\n";'`
Expected: `No syntax errors…` dos veces y `2 rutas`.

- [ ] **Step 7: Commit**

```bash
git add tests/support.php tests/RouterTest.php _assets/classes/Router.class.php
git commit -m "feat(router): alias hacia URLs viejas, reset(), routes() y export()" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `Router::lint()`

**Files:**
- Modify: `_assets/classes/Router.class.php`
- Modify: `tests/RouterTest.php` (agregar secciones antes de `finish();`)

**Interfaces:**
- Consumes: `Router::load()`, `Router::reset()`, `Router::routes()` (Tarea 1).
- Produces: `Router::lint(bool $checkHandlers = false): array` — lista de textos de problema (vacía si todo está bien): nombres duplicados, URLs en conflicto, rutas "tapadas" por una anterior y alias con parámetros distintos. Con `$checkHandlers = true` también verifica que la clase exista (usa el autoload), que el método sea público y que el número de parámetros de la ruta sea compatible con el método.

- [ ] **Step 1: Escribir las pruebas que fallan**

En `tests/RouterTest.php`, justo antes de `finish();`, agregar:

```php
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
```

- [ ] **Step 2: Verificar que falla**

Run: `php tests/RouterTest.php`
Expected: error fatal `Call to undefined method Router::lint()`.

- [ ] **Step 3: Implementar**

En `_assets/classes/Router.class.php`, después de `reverse()` agregar:

```php
    /**
     * Revisa las rutas registradas y devuelve la lista de problemas (vacía = todo bien).
     * Con $checkHandlers también verifica clase, método y número de parámetros.
     */
    public static function lint(bool $checkHandlers = false): array {
        $problems = [];
        $names    = [];
        $seen     = [];
        $earlier  = [];

        foreach (self::$routes as $def) {
            $label = ($def['is_alias'] ? 'alias /' : 'ruta /') . $def['pattern'];

            if (!$def['is_alias'] && $def['name'] !== null) {
                if (isset($names[$def['name']])) {
                    $problems[] = "Nombre duplicado '{$def['name']}': {$names[$def['name']]} y $label";
                }
                $names[$def['name']] = $label;
            }

            if ($def['is_alias'] && array_keys($def['params']) !== $def['canonical_params']) {
                $problems[] = "$label: el alias debe tener los mismos parámetros y en el mismo orden que la ruta principal ("
                    . implode(', ', $def['canonical_params']) . ')';
            }

            // Dos rutas con la misma forma (ignorando tipos de parámetro) y verbos que se cruzan: la segunda queda muerta.
            $signature = preg_replace('/<(?:\w+:)?\w+>/', '<>', $def['pattern']);
            foreach ($seen[$signature] ?? [] as [$otherLabel, $otherMethods]) {
                if (!$def['methods'] || !$otherMethods || array_intersect($def['methods'], $otherMethods)) {
                    $problems[] = "Conflicto: $label y $otherLabel resuelven la misma URL";
                }
            }
            $seen[$signature][] = [$label, $def['methods']];

            // Una ruta anterior (de forma distinta) que ya atrapa esta URL la deja inalcanzable.
            // Se prueba con una URL de ejemplo: int => 1, path => x/y, el resto => x.
            $sample = preg_replace_callback('/<(?:(\w+):)?\w+>/', fn($m) => match ($m[1] ?? 'str') {
                'int'   => '1',
                'path'  => 'x/y',
                default => 'x',
            }, $def['pattern']);
            foreach ($earlier as [$prevLabel, $prev, $prevSignature]) {
                $overlap = !$def['methods'] || !$prev['methods'] || array_intersect($def['methods'], $prev['methods']);
                if ($prevSignature !== $signature && $overlap && preg_match($prev['regex'], $sample)) {
                    $problems[] = "$label queda tapada por $prevLabel (declárala antes)";
                    break;
                }
            }
            $earlier[] = [$label, $def, $signature];

            if ($checkHandlers) {
                array_push($problems, ...self::lintHandler($def, $label));
            }
        }

        return $problems;
    }

    private static function lintHandler(array $def, string $label): array {
        [$class, $method] = $def['handler'];

        if (!class_exists($class)) {
            return ["$label: la clase '$class' no existe"];
        }
        if (!method_exists($class, $method)) {
            return ["$label: $class::$method() no existe"];
        }

        $ref = new ReflectionMethod($class, $method);
        if (!$ref->isPublic()) {
            return ["$label: $class::$method() no es público"];
        }

        $problems = [];
        $given = count($def['params']);
        if ($given < $ref->getNumberOfRequiredParameters()) {
            $problems[] = "$label: $class::$method() requiere {$ref->getNumberOfRequiredParameters()} parámetro(s) y la ruta entrega $given";
        }
        if (!$ref->isVariadic() && $given > $ref->getNumberOfParameters()) {
            $problems[] = "$label: $class::$method() acepta {$ref->getNumberOfParameters()} parámetro(s) y la ruta entrega $given";
        }
        return $problems;
    }
```

- [ ] **Step 4: Verificar que pasa**

Run: `php tests/RouterTest.php`
Expected: todo `ok`, termina con `Todo bien`.

- [ ] **Step 5: Commit**

```bash
git add _assets/classes/Router.class.php tests/RouterTest.php
git commit -m "feat(router): lint() detecta nombres duplicados, conflictos, alias inválidos y handlers rotos" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Rutas por módulo y autoload compartido

**Files:**
- Create: `routes/home.php`, `routes/stations.php`
- Create: `_assets/classes/autoload.php`
- Modify: `routes.php`
- Modify: `index.php` (reemplaza el bloque `spl_autoload_register`)
- Modify: `tests/RouterTest.php`

**Interfaces:**
- Consumes: `route()`, `group()`, `Router::load()`, `Router::lint()`.
- Produces: `routes.php` devuelve el arreglo concatenado de todos los `routes/*.php` (en orden alfabético; **el orden importa porque la primera coincidencia gana**). `_assets/classes/autoload.php` registra el autoload y necesita las constantes `CLASSES`, `CONTROLLERS` y `MODELS`.

- [ ] **Step 1: Escribir la prueba que falla**

En `tests/RouterTest.php`, antes de `finish();`, agregar:

```php
// --- routes.php real (carga routes/*.php) ---
Router::reset();
Router::load(require __DIR__ . '/../routes.php');
check('routes.php: home registrada', Router::match('', 'GET') !== null);
check('routes.php: stations.readings acepta GET y POST',
    Router::match('stations/readings', 'GET') !== null && Router::match('stations/readings', 'POST') !== null);
check('routes.php: stations.readings rechaza PUT', (Router::match('stations/readings', 'PUT')['error'] ?? 0) === 405);
check('routes.php: reverse stations.readings', Router::reverse('stations.readings') === '/stations/readings');
check('routes.php: lint limpio (sin revisar handlers)', Router::lint() === []);
```

- [ ] **Step 2: Verificar que pasa antes de tocar nada (baseline)**

Run: `php tests/RouterTest.php`
Expected: pasa (el `routes.php` actual ya cumple). Esto fija el comportamiento que no debe cambiar al mover los archivos.

- [ ] **Step 3: Crear los archivos por módulo**

`routes/home.php`:

```php
<?php
return [
    route('', [Home::class, 'index'], name: 'home', methods: ['GET']),
];
```

`routes/stations.php`:

```php
<?php
return [
    group('stations/', [
        route('readings/', [Stations::class, 'readings'], name: 'stations.readings', methods: ['GET', 'POST']),
    ]),
];
```

Reemplazar todo `routes.php` por:

```php
<?php
// Rutas explícitas (estilo Django), un archivo por controlador en routes/.
// Lo que no esté declarado sigue resolviéndose por la convención antigua
// /controlador/método mientras ROUTER_LEGACY_FALLBACK sea true.
//
// Los archivos se cargan en orden alfabético y la primera coincidencia gana.
// Para ver conflictos: php tools/routes.php lint
$routes = [];
$files = glob(__DIR__ . '/routes/*.php');
sort($files);
foreach ($files as $file) {
    $routes = array_merge($routes, require $file);
}
return $routes;
```

- [ ] **Step 4: Extraer el autoload**

Crear `_assets/classes/autoload.php`:

```php
<?php
// Autoload de clases, controladores y modelos.
// Requiere las constantes CLASSES, CONTROLLERS y MODELS (header.class.php las define).
spl_autoload_register(function($class) {
    if (file_exists(CLASSES . $class . '.class.php')) {
        require CLASSES . $class . '.class.php';
    }
    if (file_exists(CONTROLLERS . strtolower($class) . '.php')) {
        require CONTROLLERS . strtolower($class) . '.php';
    }
    if (file_exists(MODELS . $class . '.php')) {
        require MODELS . $class . '.php';
    }
});
```

En `index.php`, reemplazar este bloque (está justo antes de `// 1) Router explícito (routes.php)`):

```php
// Autoload de clases, controladores y modelos
spl_autoload_register(function($class) {
    if (file_exists(CLASSES . $class . '.class.php')) {
        require CLASSES . $class . '.class.php';
    }
    if (file_exists(CONTROLLERS . strtolower($class) . '.php')) {
        require CONTROLLERS . strtolower($class) . '.php';
    }
    if (file_exists(MODELS . $class . '.php')) {
        require MODELS . $class . '.php';
    }
});
```

por:

```php
// Autoload de clases, controladores y modelos
require '_assets/classes/autoload.php';
```

- [ ] **Step 5: Verificar**

Run: `php -l index.php && php -l routes.php && php tests/RouterTest.php`
Expected: sin errores de sintaxis; todo `ok`.

Run (servidor local, sin sesión): `php -S localhost:8765 router.php` en otra terminal y luego `curl -s -o /dev/null -w '%{http_code}\n' localhost:8765/stations/readings`
Expected: `200` (muestra el login). Detener el servidor.

- [ ] **Step 6: Commit**

```bash
git add routes.php routes/ _assets/classes/autoload.php index.php tests/RouterTest.php
git commit -m "refactor(router): rutas por módulo en routes/ y autoload compartido" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `RouteLog` y registro de uso de URLs viejas

**Files:**
- Create: `_assets/classes/RouteLog.class.php`
- Create: `tests/RouteLogTest.php`
- Modify: `index.php` (dos puntos de registro)

**Interfaces:**
- Produces:
  - `RouteLog::write(string $file, string $kind, string $verb, string $path, ?string $when = null): void` — agrega una línea `fecha\tkind\tVERBO\t/path`; `$kind` es `alias` o `fallback`. Nunca lanza excepción.
  - `RouteLog::summarize(string $file): array` — lista de `['kind'=>, 'verb'=>, 'path'=>, 'count'=>int, 'last'=>string]` ordenada por `count` descendente (vacía si el archivo no existe).

- [ ] **Step 1: Escribir las pruebas que fallan**

Crear `tests/RouteLogTest.php`:

```php
<?php
declare(strict_types=1);

/**
 * Pruebas de RouteLog:  php tests/RouteLogTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/RouteLog.class.php';

$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'routelog_test_' . getmypid() . '.log';
@unlink($file);

RouteLog::write($file, 'fallback', 'get', 'income/list', '2026-10-16 09:00:00');
RouteLog::write($file, 'fallback', 'GET', '/income/list', '2026-10-16 09:05:00');
RouteLog::write($file, 'alias', 'POST', 'supply/show_pay/15', '2026-10-16 09:10:00');

$lines = file($file, FILE_IGNORE_NEW_LINES);
check('escribe una línea por petición', count($lines) === 3);
check('formato fecha/kind/VERBO/path', $lines[0] === "2026-10-16 09:00:00\tfallback\tGET\t/income/list");

$s = RouteLog::summarize($file);
check('summarize agrupa por kind+verbo+path', count($s) === 2);
check('summarize ordena por conteo', $s[0]['path'] === '/income/list' && $s[0]['count'] === 2);
check('summarize guarda la última fecha', $s[0]['last'] === '2026-10-16 09:05:00');
check('summarize archivo inexistente => []', RouteLog::summarize($file . '.no') === []);

// Review Focus 5: tabuladores / saltos de línea no pueden fabricar líneas falsas
@unlink($file);
RouteLog::write($file, 'fallback', 'GET', "x\t\nfalso\talias\tGET\t/otra", '2026-10-16 10:00:00');
$lines = file($file, FILE_IGNORE_NEW_LINES);
check('inyección: sigue siendo una sola línea', count($lines) === 1);
check('inyección: cuatro columnas exactas', count(explode("\t", $lines[0])) === 4);

// Path enorme se trunca
@unlink($file);
RouteLog::write($file, 'fallback', 'GET', str_repeat('a', 5000), '2026-10-16 10:00:00');
check('path largo se trunca', strlen(explode("\t", file($file, FILE_IGNORE_NEW_LINES)[0])[3]) <= 301);

// Review Focus 5: carpeta inexistente / sin permiso no rompe la petición
$threw = false;
try {
    RouteLog::write(sys_get_temp_dir() . '/no_existe_' . getmypid() . '/x.log', 'fallback', 'GET', 'a');
} catch (Throwable) {
    $threw = true;
}
check('carpeta inexistente no lanza excepción', !$threw);

@unlink($file);
finish();
```

- [ ] **Step 2: Verificar que falla**

Run: `php tests/RouteLogTest.php`
Expected: fallo al hacer `require` (archivo no existe) o `Class "RouteLog" not found`.

- [ ] **Step 3: Implementar**

Crear `_assets/classes/RouteLog.class.php`:

```php
<?php
/**
 * Registro de peticiones que NO llegaron por una ruta nueva:
 *   alias     entraron por una URL vieja declarada como alias en routes/
 *   fallback  entraron por la convención antigua /controlador/método
 * Sirve para saber cuándo una URL vieja ya no se usa y puede retirarse.
 *
 * Formato (una línea por petición, separado por tabuladores):
 *   2026-10-16 09:32:01<TAB>fallback<TAB>GET<TAB>/income/list
 */
class RouteLog {
    private const MAX_PATH = 300;

    public static function write(string $file, string $kind, string $verb, string $path, ?string $when = null): void {
        // Sin caracteres de control: un path con tabuladores o saltos de línea no puede fabricar líneas falsas.
        $path = preg_replace('/[\x00-\x1f\x7f]/', '', $path);
        $path = '/' . ltrim(substr($path, 0, self::MAX_PATH), '/');

        $line = implode("\t", [$when ?? date('Y-m-d H:i:s'), $kind, strtoupper($verb), $path]) . "\n";

        // El log nunca debe romper la petición (carpeta inexistente, sin permisos, disco lleno…).
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /** @return array<int,array{kind:string,verb:string,path:string,count:int,last:string}> */
    public static function summarize(string $file): array {
        if (!is_file($file)) {
            return [];
        }

        $groups = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $cols = explode("\t", $line);
            if (count($cols) !== 4) {
                continue;
            }
            [$when, $kind, $verb, $path] = $cols;
            $key = "$kind\t$verb\t$path";
            if (!isset($groups[$key])) {
                $groups[$key] = ['kind' => $kind, 'verb' => $verb, 'path' => $path, 'count' => 0, 'last' => $when];
            }
            $groups[$key]['count']++;
            if ($when > $groups[$key]['last']) {
                $groups[$key]['last'] = $when;
            }
        }

        $out = array_values($groups);
        usort($out, fn($a, $b) => $b['count'] <=> $a['count'] ?: strcmp($a['path'], $b['path']));
        return $out;
    }
}
```

- [ ] **Step 4: Verificar que pasa**

Run: `php tests/RouteLogTest.php`
Expected: todo `ok`, `Todo bien`.

- [ ] **Step 5: Cablear en `index.php`**

5a. En el bloque `// 1) Router explícito`, justo después del `if ($def['permission'] !== null && ...) { ... }` y antes de `[$class, $method] = $def['handler'];`, agregar:

```php
    if ($def['is_alias']) {
        RouteLog::write(ROOT . 'logs' . DS . 'legacy_routes.log', 'alias', $_SERVER['REQUEST_METHOD'], $routePath);
    }
```

5b. Antes de la línea `// Procesamos la URL para saber controlador y método`, agregar:

```php
RouteLog::write(ROOT . 'logs' . DS . 'legacy_routes.log', 'fallback', $_SERVER['REQUEST_METHOD'], $routePath);

```

- [ ] **Step 6: Verificar**

Run: `php -l index.php`
Expected: `No syntax errors detected`.

Verificación manual (requiere sesión; hacerla en el ambiente de pruebas o local con BD): iniciar sesión, abrir `/stations/readings` (ruta nueva → **no** debe agregar línea) y abrir cualquier pantalla vieja, por ejemplo `/it/stations`; luego:
Run: `tail -3 logs/legacy_routes.log`
Expected: una línea `fallback<TAB>GET<TAB>/it/stations` y ninguna para `/stations/readings`.

- [ ] **Step 7: Commit**

```bash
git add _assets/classes/RouteLog.class.php tests/RouteLogTest.php index.php
git commit -m "feat(router): registrar en logs/legacy_routes.log las entradas por alias o fallback" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: 404 real

**Files:**
- Modify: `_assets/controllers/errors.php`
- Create: `tests/ErrorsTest.php`

**Interfaces:**
- Produces: `Errors::wantsJson(): bool`, `Errors::render404(bool $json): string`; `Errors::get404(): void` ahora responde con estado HTTP 404 (HTML o JSON según la petición) y termina la ejecución con `exit`.

- [ ] **Step 1: Escribir las pruebas que fallan**

Crear `tests/ErrorsTest.php`:

```php
<?php
declare(strict_types=1);

/**
 * Pruebas de Errors:  php tests/ErrorsTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/controllers/errors.php';

$_SERVER['HTTP_X_REQUESTED_WITH'] = '';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
check('wantsJson: navegador normal => false', Errors::wantsJson() === false);

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
check('wantsJson: XMLHttpRequest => true', Errors::wantsJson() === true);

$_SERVER['HTTP_X_REQUESTED_WITH'] = '';
$_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain, */*';
check('wantsJson: Accept json => true', Errors::wantsJson() === true);

$json = json_decode(Errors::render404(true), true);
check('render404 json: trae error', ($json['error'] ?? '') === 'Ruta no encontrada');

$html = Errors::render404(false);
check('render404 html: documento completo', str_starts_with($html, '<!doctype html>') && str_contains($html, '404'));
check('render404 html: ya no usa var_dump', !str_contains($html, 'string('));

finish();
```

- [ ] **Step 2: Verificar que falla**

Run: `php tests/ErrorsTest.php`
Expected: `Call to undefined method Errors::wantsJson()`.

- [ ] **Step 3: Implementar**

Reemplazar todo `_assets/controllers/errors.php` por:

```php
<?php
class Errors{

    public function fail(): void {
        echo 'Error';
    }

    /** Peticiones AJAX / fetch con Accept json esperan JSON, no HTML. */
    public static function wantsJson(): bool {
        return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
    }

    public static function render404(bool $json): string {
        if ($json) {
            return json_encode(['error' => 'Ruta no encontrada'], JSON_UNESCAPED_UNICODE);
        }
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>404 - Ruta no encontrada</title></head>'
            . '<body style="font-family:sans-serif;text-align:center;margin-top:15vh">'
            . '<h1>404</h1><p>Ruta no encontrada</p><p><a href="/">Volver al inicio</a></p></body></html>';
    }

    public function get404(): void {
        $json = self::wantsJson();
        http_response_code(404);
        header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=utf-8');
        echo self::render404($json);
        exit;
    }
}
```

- [ ] **Step 4: Verificar que pasa**

Run: `php tests/ErrorsTest.php`
Expected: todo `ok`, `Todo bien`.

- [ ] **Step 5: Verificar con el servidor local (sin BD)**

La ruta pública `/administration/doc_agujita` pasa el login pero no hay forma sin sesión de llegar a un 404. Comprobar solo sintaxis y la prueba anterior:
Run: `php -l _assets/controllers/errors.php`
Expected: `No syntax errors detected`.

Verificación manual con sesión iniciada: abrir `/ruta/que/no/existe`.
Expected: página "404 - Ruta no encontrada" con estado HTTP 404 (pestaña Network del navegador); una llamada AJAX a una URL inexistente recibe `{"error":"Ruta no encontrada"}` con estado 404.

- [ ] **Step 6: Commit**

```bash
git add _assets/controllers/errors.php tests/ErrorsTest.php
git commit -m "feat: 404 real (estado 404, HTML o JSON) en lugar de var_dump" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `routeUrl()` para JS y `window.ROUTES`

**Files:**
- Create: `_assets/js/route_url.js`
- Create: `tests/route_url_test.js`
- Modify: `views/layouts/base.html` (antes de `app.js`)
- Modify: `index.php` (global de Twig `routes_json`)

**Interfaces:**
- Consumes: `Router::export()` (Tarea 1).
- Produces: `window.ROUTES` = `{ nombre: { pattern: 'stations/readings', params: {id: 'int'} } }`; `routeUrl(name: string, params?: object): string` (devuelve la URL con `/` inicial; lanza `Error` si la ruta no existe, falta un parámetro o el valor no cumple el tipo).

- [ ] **Step 1: Escribir la prueba que falla**

Crear `tests/route_url_test.js`:

```js
// Pruebas de routeUrl():  node tests/route_url_test.js
const assert = require('node:assert/strict');

globalThis.ROUTES = {
    'home': { pattern: '', params: [] },
    'stations.readings': { pattern: 'stations/readings', params: [] },
    'pay': { pattern: 'supply/pay/<int:id>', params: { id: 'int' } },
    'file': { pattern: 'supply/f/<path:p>', params: { p: 'path' } },
    'slug': { pattern: 'a/<slug:s>/<n>', params: { s: 'slug', n: 'str' } },
};
const routeUrl = require('../_assets/js/route_url.js');

assert.equal(routeUrl('home'), '/');
assert.equal(routeUrl('stations.readings'), '/stations/readings');
assert.equal(routeUrl('pay', { id: 15 }), '/supply/pay/15');
assert.equal(routeUrl('pay', { id: '15' }), '/supply/pay/15');
assert.equal(routeUrl('file', { p: 'a/b c.txt' }), '/supply/f/a/b c.txt');
assert.equal(routeUrl('slug', { s: 'x-1', n: 'a b' }), '/a/x-1/a%20b');
assert.throws(() => routeUrl('nope'), /Ruta no definida/);
assert.throws(() => routeUrl('pay'), /Falta el par/);
assert.throws(() => routeUrl('pay', { id: 'abc' }), /Valor inv/);
assert.throws(() => routeUrl('slug', { s: 'con espacio', n: 'x' }), /Valor inv/);
assert.throws(() => routeUrl('slug', { s: 'x', n: 'a/b' }), /Valor inv/);

console.log('Todo bien');
```

- [ ] **Step 2: Verificar que falla**

Run: `node tests/route_url_test.js`
Expected: `Cannot find module '../_assets/js/route_url.js'`.

- [ ] **Step 3: Implementar el helper**

Crear `_assets/js/route_url.js`:

```js
/**
 * routeUrl(name, params): equivalente en JS de url() de Twig.
 * Usa window.ROUTES, que base.html publica desde Router::export().
 *
 *   routeUrl('stations.readings')        -> '/stations/readings'
 *   routeUrl('supply.payment', {id: 15}) -> '/supply/payments/15'
 */
(function (global) {
    var TYPES = {
        int: /^[0-9]+$/,
        str: /^[^\/]+$/,
        slug: /^[A-Za-z0-9_-]+$/,
        path: /^.+$/
    };

    function routeUrl(name, params) {
        var routes = global.ROUTES || {};
        var route = routes[name];
        if (!route) {
            throw new Error("Ruta no definida: '" + name + "'");
        }
        params = params || {};

        var path = route.pattern.replace(/<(?:(\w+):)?(\w+)>/g, function (_, type, key) {
            type = type || 'str';
            if (!(key in params)) {
                throw new Error("Falta el parámetro '" + key + "' para la ruta '" + name + "'");
            }
            var value = String(params[key]);
            if (!TYPES[type].test(value)) {
                throw new Error("Valor inválido '" + value + "' para '" + key + "' en la ruta '" + name + "'");
            }
            return type === 'path' ? value : encodeURIComponent(value);
        });

        return '/' + path;
    }

    global.routeUrl = routeUrl;
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = routeUrl;
    }
})(typeof window !== 'undefined' ? window : globalThis);
```

- [ ] **Step 4: Verificar que pasa**

Run: `node tests/route_url_test.js`
Expected: `Todo bien`.

- [ ] **Step 5: Publicar las rutas a las vistas**

5a. En `index.php`, dentro del bloque `if (isset($_SESSION['tg_user'])) { ... }` (el que hace `addGlobal('tg_user', ...)`), agregar tras `$twig->addGlobal('tableros_csrf', ...)`:

```php
    $twig->addGlobal('routes_json', json_encode((object)Router::export(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES));
```

5b. En `views/layouts/base.html`, justo antes de `<script src="{{ js_v('app.js') }}"></script>`, agregar:

```html
	<script>window.ROUTES = {{ routes_json|raw }};</script>
	<script src="{{ js_v('route_url.js') }}"></script>
```

- [ ] **Step 6: Verificar**

Run: `php -l index.php`
Expected: `No syntax errors detected`.

Verificación manual con sesión: abrir `/` y en la consola del navegador ejecutar `routeUrl('stations.readings')`.
Expected: `"/stations/readings"`. Y `ROUTES` muestra `home` y `stations.readings`.

- [ ] **Step 7: Commit**

```bash
git add _assets/js/route_url.js tests/route_url_test.js views/layouts/base.html index.php
git commit -m "feat(router): routeUrl() para JS y window.ROUTES en el layout base" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: `RouteUsage` y `tools/routes.php`

**Files:**
- Create: `_assets/classes/RouteUsage.class.php`
- Create: `tests/RouteUsageTest.php`
- Create: `tools/routes.php`

**Interfaces:**
- Consumes: `Router::load/routes/lint`, `RouteLog::summarize`, `_assets/classes/autoload.php`.
- Produces:
  - `RouteUsage::inTwig(string $source): array` — nombres usados en `{{ url('x') }}` / `{% ... url('x') %}`.
  - `RouteUsage::inJs(string $source): array` — nombres usados en `routeUrl('x')`.
  - CLI `php tools/routes.php list|lint|legacy-hits`; `lint` sale con código 1 si hay problemas.

- [ ] **Step 1: Escribir las pruebas que fallan**

Crear `tests/RouteUsageTest.php`:

```php
<?php
declare(strict_types=1);

/**
 * Pruebas de RouteUsage:  php tests/RouteUsageTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/RouteUsage.class.php';

$twig = <<<'HTML'
<form action="{{ url('stations.readings') }}" method="post">
<a href="{{ url("supply.payment", {id: 15}) }}">x</a>
{% set u = url('home') %}
<style>.a { background: url('img/logo.png'); }</style>
<div style="background:url('fondo.jpg')"></div>
HTML;
check('Twig: encuentra url() dentro de {{ }} y {% %}',
    RouteUsage::inTwig($twig) === ['stations.readings', 'supply.payment', 'home']);
check('Twig: ignora url() de CSS', !in_array('img/logo.png', RouteUsage::inTwig($twig), true)
    && !in_array('fondo.jpg', RouteUsage::inTwig($twig), true));

$js = <<<'JS'
$.get(routeUrl('stations.readings'));
location = routeUrl("supply.payment", {id: 1});
el.style.background = "url('x.png')";
JS;
check('JS: encuentra routeUrl()', RouteUsage::inJs($js) === ['stations.readings', 'supply.payment']);
check('JS: ignora url() de CSS', !in_array('x.png', RouteUsage::inJs($js), true));
check('sin usos => []', RouteUsage::inTwig('<p>hola</p>') === [] && RouteUsage::inJs('var a=1;') === []);

finish();
```

- [ ] **Step 2: Verificar que falla**

Run: `php tests/RouteUsageTest.php`
Expected: fallo al hacer `require` de `RouteUsage.class.php`.

- [ ] **Step 3: Implementar `RouteUsage`**

Crear `_assets/classes/RouteUsage.class.php`:

```php
<?php
/**
 * Encuentra qué rutas con nombre usan las vistas y el JS, para que el linter
 * detecte nombres que no existen antes de que fallen en producción.
 */
class RouteUsage {
    /** url('nombre') dentro de {{ ... }} o {% ... %} (así no se confunde con url() de CSS). */
    public static function inTwig(string $source): array {
        preg_match_all('/\{[{%][^}]*?\burl\(\s*[\'"]([\w.\-]+)[\'"]/', $source, $m);
        return $m[1];
    }

    /** routeUrl('nombre') en archivos JS. */
    public static function inJs(string $source): array {
        preg_match_all('/\brouteUrl\(\s*[\'"]([\w.\-]+)[\'"]/', $source, $m);
        return $m[1];
    }
}
```

- [ ] **Step 4: Verificar que pasa**

Run: `php tests/RouteUsageTest.php`
Expected: todo `ok`, `Todo bien`.

- [ ] **Step 5: Escribir la herramienta**

Crear `tools/routes.php`:

```php
<?php
declare(strict_types=1);

/**
 * Herramienta de rutas.
 *
 *   php tools/routes.php list         tabla de rutas y alias
 *   php tools/routes.php lint         conflictos, handlers rotos y url('x') inexistentes (sale con 1 si hay problemas)
 *   php tools/routes.php legacy-hits  resumen de logs/legacy_routes.log (qué URLs viejas siguen en uso)
 */
chdir(dirname(__DIR__));
$_SERVER['REQUEST_URI'] ??= '/';
define('DS', DIRECTORY_SEPARATOR);
define('ROOT', getcwd() . DS);
define('ASSETS', ROOT . '_assets' . DS);
define('CLASSES', ASSETS . 'classes' . DS);
define('CONTROLLERS', ASSETS . 'controllers' . DS);
define('MODELS', ASSETS . 'models' . DS);

require ROOT . '_assets/classes/autoload.php';
require ROOT . '_assets/classes/Router.class.php';
Router::load(require ROOT . 'routes.php');

function files_in(string $dir, string $extension): array
{
    $found = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $name = $file->getFilename();
        if ($file->getExtension() === $extension && !str_ends_with($name, '.min.' . $extension)) {
            $found[] = $file->getPathname();
        }
    }
    return $found;
}

$command = $argv[1] ?? 'list';

switch ($command) {
    case 'list':
        printf("%-6s %-28s %-8s %-8s %s\n", 'TIPO', 'URL', 'VERBOS', 'PERMISO', 'NOMBRE -> HANDLER');
        foreach (Router::routes() as $r) {
            printf(
                "%-6s %-28s %-8s %-8s %s -> %s::%s\n",
                $r['is_alias'] ? 'alias' : 'ruta',
                '/' . $r['pattern'],
                $r['methods'] ? implode(',', $r['methods']) : '*',
                $r['permission'] ?? ($r['public'] ? 'pública' : '-'),
                $r['name'] ?? '-',
                $r['handler'][0],
                $r['handler'][1]
            );
        }
        break;

    case 'lint':
        $problems = Router::lint(true);
        $known = array_keys(Router::export());

        $scan = [
            ['views', 'html', 'inTwig'],
            ['_assets/js', 'js', 'inJs'],
        ];
        foreach ($scan as [$dir, $ext, $method]) {
            foreach (files_in(ROOT . $dir, $ext) as $path) {
                foreach (array_unique(RouteUsage::$method((string)file_get_contents($path))) as $name) {
                    if (!in_array($name, $known, true)) {
                        $problems[] = str_replace(ROOT, '', $path) . ": usa la ruta '$name' que no existe";
                    }
                }
            }
        }

        if ($problems) {
            echo implode("\n", $problems), "\n\n", count($problems), " problema(s)\n";
            exit(1);
        }
        echo count(Router::routes()), " rutas revisadas, sin problemas\n";
        break;

    case 'legacy-hits':
        $rows = RouteLog::summarize(ROOT . 'logs' . DS . 'legacy_routes.log');
        if (!$rows) {
            echo "Sin registros todavía en logs/legacy_routes.log\n";
            break;
        }
        printf("%-9s %-6s %6s  %-19s %s\n", 'TIPO', 'VERBO', 'VECES', 'ÚLTIMA', 'URL');
        foreach ($rows as $r) {
            printf("%-9s %-6s %6d  %-19s %s\n", $r['kind'], $r['verb'], $r['count'], $r['last'], $r['path']);
        }
        break;

    default:
        fwrite(STDERR, "Uso: php tools/routes.php list|lint|legacy-hits\n");
        exit(2);
}
```

- [ ] **Step 6: Verificar la herramienta**

Run: `php tools/routes.php list`
Expected: una tabla con 2 filas tipo `ruta` (`/` y `/stations/readings`).

Run: `php tools/routes.php lint`
Expected: `2 rutas revisadas, sin problemas` y código de salida 0 (`echo $?`). Esto confirma que `Stations::readings` y `Home::index` existen y que `views/stations/index.html` usa un nombre válido (`stations.readings`).

Run: `php tools/routes.php legacy-hits`
Expected: `Sin registros todavía…` (o la tabla, si ya hubo tráfico).

Prueba de que el linter realmente detecta: editar temporalmente `views/stations/index.html` cambiando `url('stations.readings')` por `url('stations.nope')`, correr `php tools/routes.php lint` y confirmar `views\stations\index.html: usa la ruta 'stations.nope' que no existe` con código de salida 1; **revertir el cambio** (`git checkout views/stations/index.html`).

- [ ] **Step 7: Commit**

```bash
git add _assets/classes/RouteUsage.class.php tests/RouteUsageTest.php tools/routes.php
git commit -m "feat(router): tools/routes.php (list, lint, legacy-hits) y detección de url() inexistentes" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Documentación y verificación de cierre de la Fase 0

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Actualizar CLAUDE.md**

1a. En la tabla "Key Files", agregar estas filas después de la de `router.php`:

```markdown
| `routes.php` + `routes/*.php` | Rutas explícitas (estilo Django), un archivo por controlador |
| `_assets/classes/Router.class.php` | Router: `route()`, `group()`, `url()` en Twig, `routeUrl()` en JS |
| `tools/routes.php` | `list` / `lint` / `legacy-hits` de rutas |
```

1b. Reemplazar la línea:

```markdown
4. If new controller needed, add routing case in `index.php`
```

por:

```markdown
4. Declare the route in `routes/{controller}.php` (see "Routing" below); controllers not yet migrated still resolve through the old `/controller/method` convention while `ROUTER_LEGACY_FALLBACK` is true
```

1c. Antes de la sección `### Database`, agregar:

````markdown
### Routing

`index.php` tries the explicit router first (`routes.php` → `routes/*.php`) and falls back to `/controller/method/params` only while `ROUTER_LEGACY_FALLBACK` (header.class.php) is `true`.

```php
// routes/supply.php
return [
    group('supply/', [
        route('payments/<int:id>/', [Supply::class, 'show_payment'],
            name: 'supply.payment', methods: ['GET'], permission: 112,
            alias: ['supply/show_payment/<int:id>']),   // URL vieja, mismo método
    ]),
];
```

- Converters: `<int:x>` (cast to int), `<str:x>` (default), `<slug:x>`, `<path:x>` (may contain `/`).
- `alias` URLs are absolute, must declare the same params in the same order, and are logged to `logs/legacy_routes.log`.
- Links: `{{ url('supply.payment', {id: 15}) }}` in Twig, `routeUrl('supply.payment', {id: 15})` in JS (never hardcode migrated URLs).
- Files in `routes/` load alphabetically and the first match wins. Inside a file, declare specific routes (`pay/new/`) before general ones (`pay/<str:id>/`); `tools/routes.php lint` reports any route shadowed by an earlier one.
- Checks: `php tools/routes.php lint` (conflicts, missing handlers, unknown `url('x')` names), `php tools/routes.php list`, `php tools/routes.php legacy-hits`.
- Tests (no framework): `php tests/RouterTest.php`, `php tests/RouteLogTest.php`, `php tests/RouteUsageTest.php`, `php tests/ErrorsTest.php`, `node tests/route_url_test.js`.
````

- [ ] **Step 2: Correr todas las pruebas y el linter**

Run:
```bash
for t in RouterTest RouteLogTest RouteUsageTest ErrorsTest; do php tests/$t.php || echo "FALLÓ $t"; done; node tests/route_url_test.js; php tools/routes.php lint
```
Expected: cada archivo termina con `Todo bien`, no aparece `FALLÓ`, y el linter imprime `2 rutas revisadas, sin problemas`.

- [ ] **Step 3: Checklist manual en el ambiente de pruebas (con sesión)**

Marcar cada punto; si alguno falla, **no** pasar a la Fase 1:
- [ ] Iniciar sesión, abrir `/` (home) y el menú: todo se ve igual que antes.
- [ ] Abrir `/stations/readings`, elegir un tabulador y enviar: la página muestra los datos (esto prueba también el arreglo `get_tabulator`).
- [ ] Abrir 3 o 4 pantallas viejas de módulos distintos (por ejemplo `/it/stations`, una de `income`, una de `supply`): funcionan igual.
- [ ] `/ruta/inexistente` muestra la página 404 (y en la pestaña Network el estado es 404).
- [ ] Consola del navegador: `routeUrl('stations.readings')` devuelve `"/stations/readings"`.
- [ ] `php tools/routes.php legacy-hits` lista las pantallas viejas que se abrieron y no lista `/stations/readings`.
- [ ] Revisar `logs/php_errors.log`: sin errores nuevos del router.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: documentar el sistema de rutas, alias y herramientas en CLAUDE.md" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Criterio de salida de la Fase 0

Todo lo siguiente se cumple en el ambiente de pruebas antes de empezar la Fase 1:

1. Las 4 pruebas PHP y la de Node pasan; `php tools/routes.php lint` termina con código 0.
2. El checklist manual de la Tarea 8 está completo.
3. `logs/legacy_routes.log` ya recibe entradas y `legacy-hits` las resume.
4. Ninguna URL anterior dejó de funcionar (`ROUTER_LEGACY_FALLBACK` sigue en `true`).

La Fase 1 (módulos chicos: `administration`, `marketing`, `humancapital`, `tableros`, `xml`, `errors`) se planifica después de esta fase, con lo que se aprenda aquí sobre las convenciones de nombres y URLs.
