<?php
/**
 * Corrección de dedo: las filas id 5670 (Jarudo, Diesel) y 5671 (Satélite,
 * Diesel) de Premier Gas/Gaso Mex se capturaron con fecha 2026-09-08 pero en
 * realidad son del 2026-09-09, igual que el resto del reporte. Se actualiza
 * la fecha en sitio (no se cancela/reinserta porque no hay ningún otro campo
 * a corregir).
 *
 * Uso: php tools/fix_scheduling_2026-09-08_to_09_premiergas.php [--commit]
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
$model = new FuelReceptionScheduleModel();

$ids = [5670, 5671];
$nuevaFecha = '2026-09-09';

foreach ($ids as $id) {
    $row = $model->get_one($id);
    if (!$row) {
        echo "id=$id NO ENCONTRADA\n";
        continue;
    }
    printf("id=%d %s -> %s | %s | %s | %d L\n",
        $row['id'], $row['fecha'], $nuevaFecha, $row['hora'], $row['product'], $row['litros']);

    if ($commit) {
        $data = $row;
        $data['fecha'] = $nuevaFecha;
        $model->update($id, $data, 0);
        echo "  OK actualizada\n";
    }
}

if (!$commit) {
    echo "\nDry-run: no se actualizó nada. Ejecuta con --commit para aplicar.\n";
}
