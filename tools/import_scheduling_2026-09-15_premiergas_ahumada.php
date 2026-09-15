<?php
/**
 * Import de una sola vez: recepciones de Premiergas (Ahumada) del
 * 15/09/2026, capturadas manualmente por el usuario desde el reporte de
 * programación que Abastos maneja fuera de la app.
 *
 * Las 2 filas de Diesel (mismo día/hora/litros) son 2 recepciones reales
 * distintas, confirmado con el usuario -- no se deduplican.
 *
 * Uso: php tools/import_scheduling_2026-09-15_premiergas_ahumada.php [--commit]
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

const SUPPLIER_ID = 138; // Premiergas
const TERMINAL_NAME = 'Ahumada';
const FECHA = '2026-09-15';
const STATION_CODE = 31; // Villa Ahumada

// hora "10:00 a. m." -> "10:00"
function parseHora(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    if (!preg_match('/^(\d{1,2}):(\d{2})\s*([ap])\.?\s*m\.?$/i', $raw, $m)) {
        return null;
    }
    $h = (int)$m[1];
    $isPm = strtolower($m[3]) === 'p';
    if ($isPm && $h !== 12) $h += 12;
    if (!$isPm && $h === 12) $h = 0;
    return sprintf('%02d:%s', $h, $m[2]);
}

// Filas pegadas por el usuario: litros, producto, hora
$rawRows = [
    [20000, 'Regular', '10:00 a. m.'],
    [20000, 'Premium', '10:00 a. m.'],
    [20000, 'Diesel',  '10:00 a. m.'],
    [20000, 'Diesel',  '10:00 a. m.'],
];

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
    [$litros, $productoRaw, $horaRaw] = $row;
    $lineNo = $i + 1;

    $hora = parseHora($horaRaw);
    if ($hora === null && trim($horaRaw) !== '') {
        $errors[] = "Fila $lineNo: no se pudo interpretar la hora '$horaRaw'";
        continue;
    }

    $product = trim($productoRaw);
    if (!in_array($product, ['Regular', 'Premium', 'Diesel'], true)) {
        $errors[] = "Fila $lineNo: producto no reconocido '$productoRaw'";
        continue;
    }

    $resolved[] = [
        'fecha' => FECHA,
        'hora' => $hora,
        'supplier_id' => SUPPLIER_ID,
        'terminal_id' => $terminalId,
        'station_code' => STATION_CODE,
        'product' => $product,
        'mezcla' => null,
        'litros' => $litros,
        'carrier_id' => null,
        'referencia' => null,
        'notas' => null,
    ];
}

echo "\n=== Filas resueltas (" . count($resolved) . " de " . count($rawRows) . ") ===\n";
foreach ($resolved as $r) {
    printf(
        "%s %s | %-10s | litros=%-6d | estacion=%d Villa Ahumada\n",
        $r['fecha'], $r['hora'] ?? '--:--', $r['product'], $r['litros'], $r['station_code']
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
        echo "  OK id=$id | {$r['product']} {$r['litros']}L\n";
        $inserted++;
    } catch (Exception $e) {
        echo "  FALLO {$r['product']}: " . $e->getMessage() . "\n";
    }
}
echo "\nInsertadas $inserted de " . count($resolved) . " filas resueltas.\n";
if ($errors) {
    echo count($errors) . " filas NO se importaron por error de mapeo (ver arriba). Revisar manualmente.\n";
}
