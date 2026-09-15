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
    SELECT cc.name, cc.definition
    FROM sys.check_constraints cc
    JOIN sys.tables t ON t.object_id = cc.parent_object_id
    WHERE t.name = 'fuel_reception_schedule'
", []);
foreach ($rows as $r) echo "{$r['name']}\t{$r['definition']}\n";
if (!$rows) echo "(sin CHECK constraints en esta tabla)\n";
