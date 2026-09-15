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

foreach (['PL/2060/EXP/ES/2015_', 'E14946'] as $destino) {
    $rows = $db->select("SELECT TOP 1 Id, Destino, DATALENGTH(Destino) AS len FROM FacturasRecibidas WHERE Destino = ?", [$destino]);
    foreach ($rows as $r) {
        echo "Id={$r['Id']} Destino=[{$r['Destino']}] bytes={$r['len']}\n";
    }
}

// Buscar si PL/2060 (sin el guion) SI existe en Estaciones
$m = $db->select("SELECT Codigo, PermisoCRE, DATALENGTH(PermisoCRE) AS len FROM Estaciones WHERE PermisoCRE LIKE 'PL/2060/%'", []);
foreach ($m as $r) {
    echo "Estaciones Codigo={$r['Codigo']} PermisoCRE=[{$r['PermisoCRE']}] bytes={$r['len']}\n";
}

// E14946 -- buscar si corresponde a algo en Estaciones.Estacion
$m2 = $db->select("SELECT Codigo, Nombre, Estacion FROM Estaciones WHERE Estacion = 'E14946'", []);
foreach ($m2 as $r) {
    echo "Estaciones (via Estacion) Codigo={$r['Codigo']} Nombre={$r['Nombre']} Estacion={$r['Estacion']}\n";
}
if (!$m2) echo "E14946 no matchea Estaciones.Estacion tampoco\n";
