<?php
/**
 * Corrección 06/10/2026 AEMSA (San Miguel de Allende) para que quede igual al
 * Excel actualizado que pegó el usuario:
 *   - agrega Picachos Diesel 27,000 L 14:00 (faltaba)
 *   - cancela id 5929 Ventanas Premium 23,500 L 18:00 (ya no viene en el
 *     programa de AEMSA; la Premium de Ventanas de ese día viene por MGC T2)
 *
 * Uso: php tools/fix_scheduling_2026-10-06_aemsa_sma.php [--commit]
 */
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});
$commit = in_array('--commit', $argv, true);

const SUPPLIER_ID = 163;      // AEMSA
const ID_A_CANCELAR = 5929;   // Ventanas Premium 18:00

$model = new FuelReceptionScheduleModel();
$terminal = (new FuelTerminalsModel())->find_by_name('San Miguel de Allende');
$picachos = array_values(array_filter((new EstacionesModel())->get_select_stations(), fn($e) => mb_stripos($e['Nombre'], 'picach') !== false));
if (!$terminal || count($picachos) !== 1) { fwrite(STDERR, "No se resolvió terminal o estación\n"); exit(1); }

$cancelar = $model->get_one(ID_A_CANCELAR);
if (!$cancelar || (int)$cancelar['supplier_id'] !== SUPPLIER_ID || $cancelar['product'] !== 'Premium' || $cancelar['fecha'] !== '2026-10-06') {
    fwrite(STDERR, "La fila " . ID_A_CANCELAR . " no es la esperada, abortando\n"); exit(1);
}

$nueva = [
    'fecha' => '2026-10-06', 'hora' => '14:00', 'supplier_id' => SUPPLIER_ID,
    'terminal_id' => (int)$terminal['id'], 'station_code' => (int)$picachos[0]['Codigo'],
    'product' => 'Diesel', 'mezcla' => null, 'litros' => 27000,
    'carrier_id' => null, 'referencia' => null, 'notas' => null,
];
echo "Agregar: 2026-10-06 14:00 Diesel 27000 L -> {$picachos[0]['Nombre']} (terminal {$terminal['id']})\n";
echo "Cancelar: id " . ID_A_CANCELAR . " ({$cancelar['product']} {$cancelar['litros']} L {$cancelar['hora']}, estatus {$cancelar['estatus']})\n";

if (!$commit) { echo "\nDry-run: no se cambió nada. Ejecuta con --commit.\n"; exit(0); }

$id = $model->add($nueva, 0);
echo "OK agregada id=$id\n";
$model->cancel(ID_A_CANCELAR, 0);
echo "OK cancelada id=" . ID_A_CANCELAR . "\n";
