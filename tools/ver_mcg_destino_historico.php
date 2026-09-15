<?php
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});
$db = MySqlPdoHandler::getInstance();

// Cuantas facturas de MCG tienen Destino poblado vs vacio, por fecha de importacion
$rows = $db->select("
    SELECT CAST(FechaImportacion AS DATE) AS dia,
           SUM(CASE WHEN Destino IS NOT NULL AND Destino <> '' THEN 1 ELSE 0 END) AS con_destino,
           SUM(CASE WHEN Destino IS NULL OR Destino = '' THEN 1 ELSE 0 END) AS sin_destino,
           COUNT(*) AS total
    FROM FacturasRecibidas
    WHERE EmisorRfc = 'MME141110IJ9'
    GROUP BY CAST(FechaImportacion AS DATE)
    ORDER BY dia DESC
", []);
foreach ($rows as $r) {
    echo "{$r['dia']}\tcon_destino={$r['con_destino']}\tsin_destino={$r['sin_destino']}\ttotal={$r['total']}\n";
}
