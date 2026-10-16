<?php
declare(strict_types=1);

/**
 * Pruebas del layout base:  php tests/LayoutTest.php
 *
 * window.ROUTES y routeUrl() deben existir antes que cualquier script que los use
 * al cargar (tableros_transition.js corre en el <head>).
 */
require __DIR__ . '/support.php';

$html = (string)file_get_contents(__DIR__ . '/../views/layouts/base.html');
$pos  = fn(string $needle) => strpos($html, $needle);

check('ROUTES se define una sola vez', substr_count($html, 'window.ROUTES') === 1);
check('route_url.js se carga una sola vez', substr_count($html, "js_v('route_url.js')") === 1);
check('ROUTES antes de route_url.js', $pos('window.ROUTES') < $pos("js_v('route_url.js')"));
check('route_url.js antes de tableros_transition.js', $pos("js_v('route_url.js')") < $pos("js_v('tableros_transition.js')"));
check('ROUTES conserva el default para páginas sin sesión', str_contains($html, "routes_json|default('{}')|raw"));

finish();
