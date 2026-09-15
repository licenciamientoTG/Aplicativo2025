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
try {
    $rows = $db->select("SELECT STRING_AGG(Descripcion, ' + ') AS x FROM FacturasRecibidasConceptos WHERE FacturaId = 72696", []);
    echo "STRING_AGG funciona: " . ($rows[0]['x'] ?? 'null') . "\n";
} catch (Exception $e) {
    echo "STRING_AGG FALLA: " . $e->getMessage() . "\n";
}
$version = $db->select("SELECT @@VERSION AS v", []);
echo "Version: " . ($version[0]['v'] ?? '?') . "\n";
