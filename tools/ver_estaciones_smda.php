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
$rows = $db->select("SELECT Codigo, Nombre FROM Estaciones WHERE Nombre LIKE '%picach%' OR Nombre LIKE '%ventana%'", []);
foreach ($rows as $r) echo "{$r['Codigo']}\t{$r['Nombre']}\n";
if (!$rows) echo "(sin match)\n";

echo "--- terminal San Miguel de Allende ---\n";
$rows2 = $db->select("SELECT id, nombre FROM fuel_terminals WHERE nombre LIKE '%san miguel%'", []);
foreach ($rows2 as $r) echo "{$r['id']}\t{$r['nombre']}\n";
