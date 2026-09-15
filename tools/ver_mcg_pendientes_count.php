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
    SELECT COUNT(*) AS n
    FROM FacturasRecibidas
    WHERE EmisorRfc = 'MME141110IJ9' AND Destino IS NOT NULL AND Destino <> '' AND EstacionCodgas IS NULL
", []);
echo "Facturas MCG pendientes de resolver: {$rows[0]['n']}\n";

// Cuantos permisos CRE distintos hay en las pendientes, y cuantos matchean ya contra Estaciones
$rows2 = $db->select("
    SELECT DISTINCT Destino
    FROM FacturasRecibidas
    WHERE EmisorRfc = 'MME141110IJ9' AND Destino IS NOT NULL AND Destino <> '' AND EstacionCodgas IS NULL
", []);
echo "Permisos CRE distintos entre las pendientes: " . count($rows2) . "\n";
$sinMatch = 0;
foreach ($rows2 as $r) {
    $m = $db->select("SELECT Codigo FROM Estaciones WHERE PermisoCRE = ?", [$r['Destino']]);
    if (!$m) {
        $sinMatch++;
        echo "  SIN MATCH: {$r['Destino']}\n";
    }
}
echo "Permisos sin match en Estaciones: $sinMatch de " . count($rows2) . "\n";
