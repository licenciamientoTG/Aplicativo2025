<?php
/**
 * Corrección puntual: las filas id=5709 (Electrolux, 21/11 Mixta) y
 * id=5713 (Aeronáutica, 21/11 Mixta) del 14/09/2026 se insertaron con el
 * formato viejo (product='Mixta' + mezcla). Se cancelan y se reemplazan por
 * 2 filas cada una (Regular + Premium), según la nueva regla: el primer
 * número del par es litros de Regular, el segundo es litros de Premium.
 *
 * Uso: php tools/fix_mixta_2026-09-14.php [--commit]
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

const SUPPLIER_ID = 123; // Tesoro México Supply and Marketing
const TERMINAL_ID = 2;   // Diaz Gas
const FECHA = '2026-09-14';
const HORA = '14:00';

// [id_a_cancelar, station_code, station_nombre, litros_regular, litros_premium]
$toFix = [
    [5709, 14, 'Electrolux',   21000, 11000],
    [5713, 15, 'Aeronáutica',  21000, 11000],
];

$fuelReceptionScheduleModel = new FuelReceptionScheduleModel();

foreach ($toFix as [$oldId, $stationCode, $stationNombre, $litrosRegular, $litrosPremium]) {
    echo "--- $stationNombre (cancelando id=$oldId) ---\n";
    if ($commit) {
        $fuelReceptionScheduleModel->cancel($oldId, 0);
        echo "  Cancelada id=$oldId\n";

        $idRegular = $fuelReceptionScheduleModel->add([
            'fecha' => FECHA, 'hora' => HORA, 'supplier_id' => SUPPLIER_ID,
            'terminal_id' => TERMINAL_ID, 'station_code' => $stationCode,
            'product' => 'Regular', 'mezcla' => null, 'litros' => $litrosRegular,
            'carrier_id' => null, 'referencia' => null, 'notas' => null,
        ], 0);
        echo "  OK Regular {$litrosRegular}L -> id=$idRegular\n";

        $idPremium = $fuelReceptionScheduleModel->add([
            'fecha' => FECHA, 'hora' => HORA, 'supplier_id' => SUPPLIER_ID,
            'terminal_id' => TERMINAL_ID, 'station_code' => $stationCode,
            'product' => 'Premium', 'mezcla' => null, 'litros' => $litrosPremium,
            'carrier_id' => null, 'referencia' => null, 'notas' => null,
        ], 0);
        echo "  OK Premium {$litrosPremium}L -> id=$idPremium\n";
    } else {
        echo "  [dry-run] cancelaria id=$oldId, crearia Regular {$litrosRegular}L + Premium {$litrosPremium}L\n";
    }
}

if (!$commit) {
    echo "\nDry-run: no se modificó nada. Ejecuta con --commit para aplicar.\n";
}
