<?php
/**
 * Correcciones puntuales a la programación de Tesoro/Diaz Gas del 14/09/2026,
 * detectadas al comparar el reporte definitivo del usuario contra lo ya
 * insertado (import original del 14/09 + fix de Mixta parcial + prueba del
 * modal de sugerencia de facturas):
 *
 * 1) Duplicado: id=5727 (Municipio Libre, Regular 31000L, sin transportista)
 *    es un duplicado de id=5704 (misma fila, ya con Carretera/28800295750).
 *    Se cancela 5727.
 * 2) id=5703 (Lopez Mateos) le faltaba transportista/factura del reporte
 *    definitivo: TRANSPAC / 28800295779.
 * 3) id=5716 (Misiones, Mixta 16/16) e id=5717 (Anapra, Mixta 21/11) se
 *    quedaron sin dividir cuando se aplicó la regla nueva (solo se
 *    corrigieron Electrolux/Aeronáutica en ese momento) -- se dividen ahora
 *    en Regular+Premium.
 * 4) id=5723 (Electrolux, Regular 21000, ya dividida) le faltaba el
 *    transportista CARRETERA del reporte definitivo.
 *
 * Uso: php tools/fix_scheduling_2026-09-14_tesoro.php [--commit]
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

const SUPPLIER_ID = 123;
const TERMINAL_ID = 2;
const FECHA = '2026-09-14';

$fuelReceptionScheduleModel = new FuelReceptionScheduleModel();
$fuelCarriersModel = new FuelCarriersModel();

function resolveCarrier(FuelCarriersModel $model, string $nombre, bool $commit): ?int {
    $carrier = $model->find_by_name($nombre);
    if ($carrier) {
        return (int)$carrier['id'];
    }
    if ($commit) {
        $id = $model->add($nombre);
        echo "  Transportista nuevo '$nombre' -> id $id\n";
        return $id;
    }
    echo "  [dry-run] transportista nuevo a crear: '$nombre'\n";
    return null;
}

// --- 1) Cancelar duplicado ---
echo "--- 1) Duplicado Municipio Libre: cancelar id=5727 (se queda id=5704) ---\n";
if ($commit) {
    $fuelReceptionScheduleModel->cancel(5727, 0);
    echo "  Cancelada id=5727\n";
} else {
    echo "  [dry-run] cancelaria id=5727\n";
}

// --- 2) Completar Lopez Mateos (id=5703) ---
echo "\n--- 2) Lopez Mateos (id=5703): agregar TRANSPAC / 28800295779 ---\n";
$carrierTranspac = resolveCarrier($fuelCarriersModel, 'TRANSPAC', $commit);
if ($commit) {
    $recepcion = $fuelReceptionScheduleModel->get_one(5703);
    $fuelReceptionScheduleModel->update(5703, [
        'fecha' => $recepcion['fecha'], 'hora' => $recepcion['hora'],
        'supplier_id' => $recepcion['supplier_id'], 'terminal_id' => $recepcion['terminal_id'],
        'station_code' => $recepcion['station_code'], 'product' => $recepcion['product'],
        'mezcla' => $recepcion['mezcla'], 'litros' => $recepcion['litros'],
        'carrier_id' => $carrierTranspac, 'referencia' => '28800295779', 'notas' => $recepcion['notas'],
    ], 0);
    echo "  OK id=5703 actualizada\n";
} else {
    echo "  [dry-run] actualizaria id=5703 con carrier=$carrierTranspac referencia=28800295779\n";
}

// --- 3) Dividir Misiones (id=5716, 16/16) y Anapra (id=5717, 21/11) ---
$aDividir = [
    ['id' => 5716, 'station_code' => 10, 'nombre' => 'Misiones', 'hora' => '16:00', 'regular' => 16000, 'premium' => 16000],
    ['id' => 5717, 'station_code' => 17, 'nombre' => 'Anapra',   'hora' => '16:00', 'regular' => 21000, 'premium' => 11000],
];
foreach ($aDividir as $d) {
    echo "\n--- 3) Dividir {$d['nombre']} (cancelando id={$d['id']}) ---\n";
    if ($commit) {
        $fuelReceptionScheduleModel->cancel($d['id'], 0);
        echo "  Cancelada id={$d['id']}\n";

        $idRegular = $fuelReceptionScheduleModel->add([
            'fecha' => FECHA, 'hora' => $d['hora'], 'supplier_id' => SUPPLIER_ID,
            'terminal_id' => TERMINAL_ID, 'station_code' => $d['station_code'],
            'product' => 'Regular', 'mezcla' => null, 'litros' => $d['regular'],
            'carrier_id' => null, 'referencia' => null, 'notas' => null,
        ], 0);
        echo "  OK Regular {$d['regular']}L -> id=$idRegular\n";

        $idPremium = $fuelReceptionScheduleModel->add([
            'fecha' => FECHA, 'hora' => $d['hora'], 'supplier_id' => SUPPLIER_ID,
            'terminal_id' => TERMINAL_ID, 'station_code' => $d['station_code'],
            'product' => 'Premium', 'mezcla' => null, 'litros' => $d['premium'],
            'carrier_id' => null, 'referencia' => null, 'notas' => null,
        ], 0);
        echo "  OK Premium {$d['premium']}L -> id=$idPremium\n";
    } else {
        echo "  [dry-run] cancelaria id={$d['id']}, crearia Regular {$d['regular']}L + Premium {$d['premium']}L\n";
    }
}

// --- 4) Completar Electrolux Regular (id=5723) con CARRETERA ---
echo "\n--- 4) Electrolux Regular (id=5723): agregar CARRETERA ---\n";
$carrierCarretera = resolveCarrier($fuelCarriersModel, 'CARRETERA', $commit);
if ($commit) {
    $recepcion = $fuelReceptionScheduleModel->get_one(5723);
    $fuelReceptionScheduleModel->update(5723, [
        'fecha' => $recepcion['fecha'], 'hora' => $recepcion['hora'],
        'supplier_id' => $recepcion['supplier_id'], 'terminal_id' => $recepcion['terminal_id'],
        'station_code' => $recepcion['station_code'], 'product' => $recepcion['product'],
        'mezcla' => $recepcion['mezcla'], 'litros' => $recepcion['litros'],
        'carrier_id' => $carrierCarretera, 'referencia' => $recepcion['referencia'], 'notas' => $recepcion['notas'],
    ], 0);
    echo "  OK id=5723 actualizada\n";
} else {
    echo "  [dry-run] actualizaria id=5723 con carrier=$carrierCarretera\n";
}

if (!$commit) {
    echo "\nDry-run: no se modificó nada. Ejecuta con --commit para aplicar.\n";
}
