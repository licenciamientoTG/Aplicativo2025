<?php
/**
 * Import de una sola vez: recepciones de Tesoro México Supply and
 * Marketing (Petrotal) del 15/09/2026, capturadas manualmente por el
 * usuario desde el reporte de programación que Abastos maneja fuera de
 * la app.
 *
 * Regla de Mixta (vigente desde 2026-09-14): "16/16 Mixta" se divide en
 * 2 filas independientes -- Regular 16000L + Premium 16000L.
 *
 * Uso: php tools/import_scheduling_2026-09-15_tesoro_petrotal.php [--commit]
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
const TERMINAL_NAME = 'Petrotal';
const FECHA = '2026-09-15';

// hora "11:00 a. m." -> "11:00"
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

// Filas pegadas por el usuario: litros, producto, estacion (texto libre), horario, transp
// La fila "16/16 Mixta" se expande abajo en Regular+Premium.
$rawRowsCrudo = [
    [31000, '16/16 Mixta', 'plutarco', '11:00 a. m.', ''],
    [31000, 'Diesel',      'praxedis', '10:00 a. m.', '*'],
];

$rawRows = [];
foreach ($rawRowsCrudo as $row) {
    [$litros, $productoRaw, $estacionRaw, $horaRaw, $transpRaw] = $row;
    if (preg_match('#^(\d+)/(\d+)\s+Mixta$#i', trim($productoRaw), $m)) {
        $rawRows[] = [(int)$m[1] * 1000, 'Regular', $estacionRaw, $horaRaw, $transpRaw];
        $rawRows[] = [(int)$m[2] * 1000, 'Premium', $estacionRaw, $horaRaw, $transpRaw];
    } else {
        $rawRows[] = $row;
    }
}

$estacionAliases = [
    'plutarco' => 'plutarco',
    'praxedis' => 'praxedis',
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

$fuelCarriersModel = new FuelCarriersModel();
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
    [$litros, $productoRaw, $estacionRaw, $horaRaw, $transpRaw] = $row;
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

    $carrierId = null;
    $transp = trim($transpRaw);
    if ($transp !== '' && $transp !== '*') {
        $carrier = $fuelCarriersModel->find_by_name($transp);
        if ($carrier) {
            $carrierId = (int)$carrier['id'];
        } else {
            if ($commit) {
                $carrierId = $fuelCarriersModel->add($transp);
                echo "Transportista nuevo '$transp' -> id $carrierId\n";
            } else {
                echo "  [dry-run] transportista nuevo a crear: '$transp'\n";
            }
        }
    }

    $notas = ($transp === '*') ? 'Transportista original: * (sin especificar en el reporte de origen)' : null;

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
        'carrier_id' => $carrierId,
        'referencia' => null,
        'notas' => $notas,
    ];
}

echo "\n=== Filas resueltas (" . count($resolved) . " de " . count($rawRows) . ") ===\n";
foreach ($resolved as $r) {
    printf(
        "%s %s | %-10s | litros=%-6d | estacion=%d %s | carrier=%s | notas=%s\n",
        $r['fecha'], $r['hora'] ?? '--:--', $r['product'],
        $r['litros'], $r['station_code'], $r['station_nombre'],
        $r['carrier_id'] ?? '-', $r['notas'] ?? '-'
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
        echo "  FALLO {$r['station_nombre']} {$r['product']}: " . $e->getMessage() . "\n";
    }
}
echo "\nInsertadas $inserted de " . count($resolved) . " filas resueltas.\n";
if ($errors) {
    echo count($errors) . " filas NO se importaron por error de mapeo (ver arriba). Revisar manualmente.\n";
}
