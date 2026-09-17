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

echo "=== Columnas en TG (BD actual) relacionadas a PermisoCRE/PermisoCliente/PermisoProveedor ===\n";
$rows = $db->select("
    SELECT t.name AS TableName, c.name AS ColumnName
    FROM sys.columns c
    JOIN sys.tables t ON c.object_id = t.object_id
    WHERE c.name LIKE '%PermisoCRE%' OR c.name LIKE '%PermisoCliente%' OR c.name LIKE '%PermisoProveedor%' OR c.name LIKE '%PermisoSENER%'
    ORDER BY t.name
", []);
foreach ($rows as $r) echo "  {$r['TableName']}.{$r['ColumnName']}\n";
if (!$rows) echo "  (nada encontrado en TG)\n";

echo "\n=== Buscando tablas con 'Cliente' o 'Comercializador' o 'CRE' en el nombre (TG) ===\n";
$rows2 = $db->select("
    SELECT name FROM sys.tables
    WHERE name LIKE '%Cliente%' OR name LIKE '%Comerciali%' OR name LIKE '%CRE%' OR name LIKE '%Permisionario%'
    ORDER BY name
", []);
foreach ($rows2 as $r) echo "  {$r['name']}\n";

echo "\n=== Buscando en SG12 (via linked server) ===\n";
try {
    $rows3 = $db->select("
        SELECT t.name AS TableName, c.name AS ColumnName
        FROM SG12.sys.columns c
        JOIN SG12.sys.tables t ON c.object_id = t.object_id
        WHERE c.name LIKE '%PermisoCRE%' OR c.name LIKE '%PermisoCliente%' OR c.name LIKE '%PermisoProveedor%'
        ORDER BY t.name
    ", []);
    foreach ($rows3 as $r) echo "  {$r['TableName']}.{$r['ColumnName']}\n";
    if (!$rows3) echo "  (nada encontrado en SG12)\n";
} catch (Exception $e) {
    echo "  ERROR consultando SG12: " . $e->getMessage() . "\n";
}
