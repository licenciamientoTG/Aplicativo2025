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
    SELECT s.id, s.hora, s.product, s.litros, s.station_code, e.Nombre, s.estatus
    FROM TG.dbo.fuel_reception_schedule s
    LEFT JOIN TG.dbo.Estaciones e ON e.Codigo = s.station_code
    WHERE s.supplier_id = 123 AND s.terminal_id = 2
      AND s.fecha = '2026-09-15'
      AND s.estatus <> 'Cancelado'
    ORDER BY s.station_code, s.hora
", []);
foreach ($rows as $r) {
    echo "{$r['id']}\t{$r['hora']}\t{$r['product']}\t{$r['litros']}\t{$r['station_code']}\t{$r['Nombre']}\t{$r['estatus']}\n";
}
if (!$rows) echo "(sin filas existentes)\n";
