<?php
/**
 * Import de una sola vez: recepciones de MGC México (Diaz Gas) del
 * 07/10/2026, capturadas manualmente por el usuario desde el reporte de
 * programación que Abastos maneja fuera de la app.
 *
 * Mismo formato ya usado para MCG/Diaz Gas: turno (T1/T2/T3) en vez de
 * hora real en la columna "hora". EMBARQUE va a "referencia". El número
 * de 4 dígitos entre turno y embarque no se usa, ni el "1" suelto que
 * traen algunas filas antes del turno.
 *
 * Uso: php tools/import_scheduling_2026-10-07_mcg.php [--commit]
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

const SUPPLIER_ID = 139; // MGC México
const TERMINAL_NAME = 'Diaz Gas';
const FECHA = '2026-10-07';

// litros, producto, estacion (texto libre), turno, embarque
$rawRows = [
    [20000, 'Regular', '1149 lerdo',    'T2', '4654108'],
    [10000, 'Regular', '1376 delicias', 'T2', '4654082'],
    [10000, 'Premium', '1376 delicias', 'T2', '4654099'],
    [31500, 'Regular', '2172 parral',   'T2', '4654114'],
    [20000, 'Regular', '8244 permuta',  'T2', '4654123'],
    [20000, 'Diesel',  '9893 anapra',   'T2', '4654136'],
    [31500, 'Regular', 'travel center', 'T2', '4654145'],
    [31500, 'Diesel',  'travel center', 'T2', '4654150'],
];

$estacionAliases = [
    'hnos escobar'  => 'escobar',
    'lerdo'         => 'lerdo',
    'delicias'      => 'delicias',
    'travel center' => 'travel center',
    'aeronautica'   => 'aeron',
    'g chica'       => 'gemela chica',
    'g grande'      => 'gemela grande',
    'madrid'        => 'madrid',
    'permuta'       => 'permuta',
    'custodia'      => 'custodia',
    'electrolux'    => 'electrolux',
    'parral'        => 'parral',
    'anapra'        => 'anapra',
];

function resolveEstacionKey(string $raw): ?string {
    global $estacionAliases;
    $texto = trim(preg_replace('/^\d+\s*/', '', $raw));
    $texto = strtolower($texto);
    return $estacionAliases[$texto] ?? null;
}

$estacionesModel = new EstacionesModel();
$catalogo = $estacionesModel->get_select_stations();
if (!$catalogo) {
    fwrite(STDERR, "No se pudo leer el catálogo de Estaciones.\n");
    exit(1);
}

$fuelTerminalsModel = new FuelTerminalsModel();
$fuelReceptionScheduleModel = new FuelReceptionScheduleModel();

$terminal = $fuelTerminalsModel->find_by_name(TERMINAL_NAME);
if (!$terminal) {
    fwrite(STDERR, "Terminal '" . TERMINAL_NAME . "' no encontrada en fuel_terminals. Abortando.\n");
    exit(1);
}
$terminalId = (int)$terminal['id'];
echo "Terminal '" . TERMINAL_NAME . "' -> id $terminalId\n";

$resolved = [];
$errors = [];

foreach ($rawRows as $i => $row) {
    [$litros, $productoRaw, $estacionRaw, $turno, $embarque] = $row;
    $lineNo = $i + 1;

    $product = trim($productoRaw);
    if (!in_array($product, ['Regular', 'Premium', 'Diesel'], true)) {
        $errors[] = "Fila $lineNo: producto no reconocido '$productoRaw'";
        continue;
    }

    $key = resolveEstacionKey($estacionRaw);
    if ($key === null) {
        $errors[] = "Fila $lineNo: no hay alias definido para estación '$estacionRaw'";
        continue;
    }

    $matches = array_values(array_filter($catalogo, function ($e) use ($key) {
        return mb_stripos($e['Nombre'], $key) !== false;
    }));

    if (count($matches) === 0) {
        $errors[] = "Fila $lineNo: estación '$estacionRaw' (alias '$key') no encontrada en catálogo";
        continue;
    }
    if (count($matches) > 1) {
        $nombres = implode(' | ', array_map(fn($m) => $m['Codigo'] . ':' . $m['Nombre'], $matches));
        $errors[] = "Fila $lineNo: estación '$estacionRaw' (alias '$key') ambigua, coincide con: $nombres";
        continue;
    }

    $stationCode = (int)$matches[0]['Codigo'];
    $stationNombre = $matches[0]['Nombre'];

    $resolved[] = [
        'fecha' => FECHA,
        'hora' => $turno,
        'supplier_id' => SUPPLIER_ID,
        'terminal_id' => $terminalId,
        'station_code' => $stationCode,
        'station_nombre' => $stationNombre,
        'product' => $product,
        'mezcla' => null,
        'litros' => $litros,
        'carrier_id' => null,
        'referencia' => $embarque,
        'notas' => null,
    ];
}

echo "\n=== Filas resueltas (" . count($resolved) . " de " . count($rawRows) . ") ===\n";
foreach ($resolved as $r) {
    printf(
        "%s %-4s | %-10s | litros=%-6d | estacion=%d %s | ref=%s\n",
        $r['fecha'], $r['hora'], $r['product'],
        $r['litros'], $r['station_code'], $r['station_nombre'],
        $r['referencia'] ?? '-'
    );
}

if ($errors) {
    echo "\n=== Filas con error, NO se insertan (" . count($errors) . ") ===\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}

if (!$commit) {
    echo "\nDry-run: no se insertó nada. Ejecuta con --commit para insertar las " . count($resolved) . " filas resueltas.\n";
    exit($errors ? 1 : 0);
}

echo "\n=== Insertando " . count($resolved) . " filas (usuario del sistema, created_by=0) ===\n";
$inserted = 0;
foreach ($resolved as $r) {
    try {
        $id = $fuelReceptionScheduleModel->add($r, 0);
        echo "  OK id=$id | {$r['station_nombre']} | {$r['product']} {$r['litros']}L | {$r['hora']}\n";
        $inserted++;
    } catch (Exception $e) {
        echo "  FALLO {$r['station_nombre']} {$r['product']}: " . $e->getMessage() . "\n";
    }
}
echo "\nInsertadas $inserted de " . count($resolved) . " filas resueltas.\n";
if ($errors) {
    echo count($errors) . " filas NO se importaron por error de mapeo (ver arriba). Revisar manualmente.\n";
}
