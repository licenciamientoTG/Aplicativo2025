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
