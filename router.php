<?php
// router.php

// Ruta solicitada (sin query string)
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Si el archivo existe físicamente (CSS, JS, imágenes, etc.), que lo sirva el servidor embebido
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false;
}

// URLs amigables de DataStudio: /DataStudio/{version}/{report} -> DataStudio/index.php?version=&report=
if (preg_match('#^/DataStudio/([^/]+)/([^/]+)$#', $path, $m)) {
    $_GET['version'] = $m[1];
    $_GET['report']  = $m[2];
    require __DIR__ . '/DataStudio/index.php';
    return;
}

// Simular lo que hacía web.config: index.php?url={R:1}
$_GET['url'] = ltrim($path, '/'); // ejemplo: "/administration/doc_agujita" -> "administration/doc_agujita"

// Cargar tu front controller
require __DIR__ . '/index.php';
