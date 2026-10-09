<?php
/**
 * Import de una sola vez: recepciones de AEMSA (Altos Energéticos
 * Mexicanos), terminal Aguascalientes, del 09/10/2026 -- estaciones Gabriela Mistral (39), El Castaño (32) y
 * Independencia; esta última es
 * "Independencia" (nombre de calle/domicilio; en TG.dbo.Estaciones está
 * dada de alta como "19 Aguascalientes", Codigo=3, confirmado con el
 * usuario 2026-09-15 vía coincidencia exacta Estacion='E11007').
 *
 * Uso: php tools/import_scheduling_2026-10-09_aemsa_ags.php [--commit]
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

const SUPPLIER_ID = 163; // Altos Energéticos Mexicanos (AEMSA)
const TERMINAL_NAME = 'Aguascalientes';
const FECHA = '2026-10-09';

// hora "4:00 p. m." -> "16:00"
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

// litros, producto, hora, Estaciones.Codigo, nombre
$rawRows = [
    [23500, 'Regular', '10:00 a. m.', 39, '37 Gabriela Mistral'],
    [23500, 'Premium', '10:00 a. m.', 32, '30 El castaño'],
    [33500, 'Regular', '2:00 p. m.',  3,  '19 Aguascalientes (Independencia)'],
    [33500, 'Regular', '6:00 p. m.',  32, '30 El castaño'],
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
    [$litros, $productoRaw, $horaRaw, $stationCode, $stationNombre] = $row;
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
        'station_code' => $stationCode,
        'station_nombre' => $stationNombre,
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
        "%s %s | %-10s | litros=%-6d | estacion=%d %s\n",
        $r['fecha'], $r['hora'] ?? '--:--', $r['product'], $r['litros'], $r['station_code'], $r['station_nombre']
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
        echo "  OK id=$id | {$r['station_nombre']} | {$r['product']} {$r['litros']}L\n";
        $inserted++;
    } catch (Exception $e) {
        echo "  FALLO {$r['product']}: " . $e->getMessage() . "\n";
    }
}
echo "\nInsertadas $inserted de " . count($resolved) . " filas resueltas.\n";
if ($errors) {
    echo count($errors) . " filas NO se importaron por error de mapeo (ver arriba). Revisar manualmente.\n";
}
