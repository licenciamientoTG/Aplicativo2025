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
$rows = $db->select("SELECT Codigo, Nombre FROM Estaciones WHERE Nombre LIKE '%lerdo%' ORDER BY Codigo", []);
foreach ($rows as $r) echo "{$r['Codigo']}\t{$r['Nombre']}\n";

echo "--- proveedor MCG ---\n";
$rows2 = $db->select("
    SELECT t1.id, t2.den
    FROM Proveedores t1
    JOIN SG12.dbo.Proveedores t2 ON t2.cod = t1.id_control_gas
    WHERE t2.den LIKE '%MGC%' OR t2.den LIKE '%MCG%'
", []);
foreach ($rows2 as $r) echo "{$r['id']}\t{$r['den']}\n";
