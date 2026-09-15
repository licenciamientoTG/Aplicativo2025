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

$rows = $db->select("
    SELECT (
        SELECT STRING_AGG(CONCAT(c.Descripcion, ' (', FORMAT(c.Litros, 'N0'), ' L)'), ' + ')
        FROM (
            SELECT Descripcion, SUM(Cantidad) AS Litros
            FROM FacturasRecibidasConceptos
            WHERE FacturaId = 72696 AND Descripcion IS NOT NULL AND Descripcion <> ''
            GROUP BY Descripcion
        ) c
    ) AS Productos
", []);
var_dump($rows[0]['Productos'] ?? null);
