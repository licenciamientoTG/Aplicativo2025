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

/**
 * Verifica una tabla de rutas contra el Router ya cargado. Cada fila:
 *   [nombre, verbos, URL nueva, handler [Clase, método], args, parámetros de reverse, alias de ejemplo o null]
 */
function check_routes(array $rows): void
{
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

        // Una ruta que falta se reporta como FALLA en lugar de abortar la prueba
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
}

function finish(): never
{
    echo $GLOBALS['failures'] === 0 ? "\nTodo bien\n" : "\n{$GLOBALS['failures']} falla(s)\n";
    exit($GLOBALS['failures'] === 0 ? 0 : 1);
}
