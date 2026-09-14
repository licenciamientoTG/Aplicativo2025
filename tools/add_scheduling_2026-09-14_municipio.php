<?php
/**
 * Alta puntual: recepción de Tesoro México Supply and Marketing (Diaz Gas)
 * del 14/09/2026, 10:00, Regular 31000L, Municipio Libre -- usada como
 * ejemplo para probar el flujo nuevo de sugerencia de facturas.
 *
 * Uso: php tools/add_scheduling_2026-09-14_municipio.php [--commit]
 */

$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);

require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';

spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) {
        require CLASSES . $class . '.class.php';
    }
    if (file_exists(MODELS . $class . '.php')) {
        require MODELS . $class . '.php';
    }
});

$commit = in_array('--commit', $argv, true);

$row = [
    'fecha' => '2026-09-14',
    'hora' => '10:00',
    'supplier_id' => 123, // Tesoro
    'terminal_id' => 2,   // Diaz Gas
    'station_code' => 8,  // Municipio Libre
    'product' => 'Regular',
    'mezcla' => null,
    'litros' => 31000,
    'carrier_id' => null,
    'referencia' => null,
    'notas' => null,
];

echo "Fila a insertar: {$row['fecha']} {$row['hora']} | supplier=123 (Tesoro) terminal=2 (Diaz Gas) | estacion=8 (Municipio Libre) | {$row['product']} {$row['litros']}L\n";

if (!$commit) {
    echo "\nDry-run: no se insertó nada. Ejecuta con --commit para insertar.\n";
    exit(0);
}

$fuelReceptionScheduleModel = new FuelReceptionScheduleModel();
$id = $fuelReceptionScheduleModel->add($row, 0);
echo "OK id=$id\n";
