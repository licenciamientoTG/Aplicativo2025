<?php
declare(strict_types=1);

/**
 * Rutas de la Fase 1 (routes/*.php reales):  php tests/RoutesFase1Test.php
 *
 * Cada fila: [nombre, verbos, URL nueva, handler, args, parámetros de reverse, alias de ejemplo o null]
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/Router.class.php';

Router::load(require __DIR__ . '/../routes.php');

$rows = [
    // xml: la URL no cambia (generatexml no tiene '_'), sin alias
    ['xml.generatexml', ['GET'], 'xml/generatexml', ['Xml', 'generatexml'], [], [], null],
];

foreach ($rows as [$name, $verbs, $url, $handler, $args, $params, $alias]) {
    foreach ($verbs as $verb) {
        $m = Router::match($url, $verb);
        check("$name: $verb $url coincide",
            isset($m['route']) && $m['route']['handler'] === $handler && $m['route']['is_alias'] === false && $m['args'] === $args);
    }
    foreach (['GET', 'POST'] as $other) {
        if (!in_array($other, $verbs, true)) {
            check("$name: $other => 405", (Router::match($url, $other)['error'] ?? 0) === 405);
        }
    }

    try {
        $reversed = Router::reverse($name, $params);
    } catch (InvalidArgumentException) {
        $reversed = false;
    }
    check("$name: reverse => /$url", $reversed === '/' . $url);

    if ($alias !== null) {
        $a = Router::match($alias, $verbs[0]);
        check("$name: alias $alias => mismo handler",
            isset($a['route']) && $a['route']['handler'] === $handler && $a['route']['is_alias'] === true && $a['args'] === $args);
    }
}

check('lint limpio', Router::lint() === []);

finish();
