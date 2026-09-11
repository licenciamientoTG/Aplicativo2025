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

$sql = MySqlPdoHandler::getInstance();
$sql->connect('SG12');

echo "=== FacturasRecibidas.Id=72219 ===\n";
$rows = $sql->select("SELECT * FROM TG.dbo.FacturasRecibidas WHERE Id = ?", [72219]);
print_r($rows);
