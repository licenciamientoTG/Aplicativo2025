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

echo "=== Ultimos 10 registros insertados en FacturasRecibidas (por Id) ===\n";
$rows = $sql->select("SELECT TOP 10 Id, Folio, Fecha, EmisorNombre, UUID, RutaArchivo, RutaXml FROM TG.dbo.FacturasRecibidas ORDER BY Id DESC", []);
foreach ($rows as $r) {
    echo "{$r['Id']} | {$r['Fecha']} | {$r['EmisorNombre']} | {$r['UUID']}\n";
    echo "  PDF: {$r['RutaArchivo']}\n";
    echo "  XML: {$r['RutaXml']}\n";
}

echo "\n=== fuel_reception_invoices completo (ultimas 10) ===\n";
$rows2 = $sql->select("SELECT TOP 10 * FROM TG.dbo.fuel_reception_invoices ORDER BY id DESC", []);
foreach ($rows2 as $r) {
    print_r($r);
}
