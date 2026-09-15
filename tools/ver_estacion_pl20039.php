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
$rows = $db->select("SELECT Codigo, Nombre, PermisoCRE, Estacion FROM Estaciones WHERE PermisoCRE = 'PL/20039/EXP/ES/2017'", []);
foreach ($rows as $r) echo "{$r['Codigo']}\t{$r['Nombre']}\t{$r['PermisoCRE']}\t{$r['Estacion']}\n";
if (!$rows) echo "(sin match)\n";
