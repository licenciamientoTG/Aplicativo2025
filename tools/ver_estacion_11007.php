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

echo "--- buscar por sufijo Estacion=11007 o similar ---\n";
$rows = $db->select("SELECT Codigo, Nombre, Estacion FROM Estaciones WHERE Estacion LIKE '%11007%' OR Estacion LIKE '%1007%'", []);
foreach ($rows as $r) echo "{$r['Codigo']}\t{$r['Nombre']}\t{$r['Estacion']}\n";
if (!$rows) echo "(sin match)\n";

echo "--- todas las estaciones de zona Aguascalientes/Bajio (para revisar visualmente) ---\n";
$rows2 = $db->select("SELECT Codigo, Nombre, Ciudad, estructura FROM Estaciones WHERE Ciudad LIKE '%aguascalientes%' OR estructura LIKE '%bajio%' ORDER BY Codigo", []);
foreach ($rows2 as $r) echo "{$r['Codigo']}\t{$r['Nombre']}\t{$r['Ciudad']}\t{$r['estructura']}\n";
