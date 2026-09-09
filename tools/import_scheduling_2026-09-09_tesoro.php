<?php
/**
 * Import de una sola vez: recepciones de Tesoro México Supply and Marketing
 * (Diaz Gas) del 09/09/2026, capturadas manualmente por el usuario desde el
 * reporte de programación que Abastos maneja fuera de la app.
 *
 * Uso: php tools/import_scheduling_2026-09-09_tesoro.php [--commit]
 *   Sin --commit: solo resuelve y muestra el resultado (dry-run), no inserta nada.
 *   Con --commit: inserta las filas resueltas sin ambigüedad.
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
const TERMINAL_NAME = 'Diaz Gas';
const FECHA = '2026-09-09';

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

// "21/11 Mixta" -> ['Mixta', '21/11'] ; "16/16 Mixta" -> ['Mixta', '16/16'] ; "Regular" -> ['Regular', null]
function parseProducto(string $raw): array {
    $raw = trim($raw);
    if (preg_match('/^(\d+\/\d+)\s+Mixta$/i', $raw, $m)) {
        return ['Mixta', $m[1]];
    }
    if (in_array($raw, ['Regular', 'Premium', 'Diesel'], true)) {
        return [$raw, null];
    }
    return [$raw, null]; // se marcará como no válido en la validación
}

// Filas pegadas por el usuario: fecha, litros, producto, estacion (texto libre), horario, transp, factura
$rawRows = [
    ['09/09/2026', 31000, 'Regular',      '5465 aztecas',       '10:00 a. m.', '*', ''],
    ['09/09/2026', 31000, 'Regular',      '7167 madrid',        '10:00 a. m.', '*', ''],
    ['09/09/2026', 31000, 'Regular',      '9885 custodia',      '10:00 a. m.', 'TRANSPAC', '28800294820'],
    ['09/09/2026', 31000, 'Regular',      '9893 anapra',        '10:00 a. m.', '*', ''],
    ['09/09/2026', 31000, 'Regular',      '5317 municipio',     '11:00 a. m.', '', ''],
    ['09/09/2026', 31000, 'Regular',      '6410 misiones',      '11:00 a. m.', 'TRANSPAC', '28800294813'],
    ['09/09/2026', 31000, 'Regular',      '6947 pto de palos',  '11:00 a. m.', '', ''],
    ['09/09/2026', 31000, 'Regular',      '9191 electrolux',    '11:00 a. m.', '', ''],
    ['09/09/2026', 31000, '21/11 Mixta',  '2526 lopez mateos',  '12:00 p. m.', '', ''],
    ['09/09/2026', 31000, 'Regular',      '4188 g grande',      '12:00 p. m.', '', ''],
    ['09/09/2026', 31000, 'Regular',      '9235 aeronautica',   '12:00 p. m.', '', ''],
    ['09/09/2026', 31000, 'Regular',      '2526 lopez mateos',  '12:00 p. m.', '', ''],
    ['09/09/2026', 31000, 'Regular',      '8244 permuta',       '1:00 p. m.', '', ''],
    ['09/09/2026', 27000, 'Diesel',       '9191 electrolux',    '1:00 p. m.', '', ''],
    ['09/09/2026', 31000, 'Regular',      '4179 g chica',       '2:00 p. m.', '', ''],
    ['09/09/2026', 31000, '16/16 Mixta',  '6410 misiones',      '2:00 p. m.', '', ''],
    ['09/09/2026', 31000, '21/11 Mixta',  '6947 pto de palos',  '2:00 p. m.', '', ''],
    ['09/09/2026', 31000, 'Regular',      '7167 madrid',        '2:00 p. m.', '', ''],
    ['09/09/2026', 27000, 'Diesel',       '4188 g grande',      '2:00 p. m.', '', ''],
    ['09/09/2026', 31000, '21/11 Mixta',  '5317 municipio',     '2:00 p. m.', '', ''],
];

// Mapeo manual del texto libre de ESTACION (el número que lo acompaña es un
// identificador interno de ControlGas, NO Estaciones.Codigo -- ver
// docs/superpowers/specs/2026-09-05-programacion-recepciones-combustible-design.md)
// a un fragmento único del nombre real en TG.dbo.Estaciones, resuelto por
// LIKE contra el catálogo cargado abajo.
$estacionAliases = [
    'aztecas'       => 'azteca',
    'madrid'        => 'madrid',
    'custodia'      => 'custodia',
    'anapra'        => 'anapra',
    'municipio'     => 'municipio',
    'misiones'      => 'mision',
    'pto de palos'  => 'palos',
    'electrolux'    => 'electrolux',
    'lopez mateos'  => 'lopez mateos',
    'g grande'      => 'gemela grande',
    'aeronautica'   => 'aeron',
    'permuta'       => 'permuta',
    'g chica'       => 'gemela chica',
];

function resolveEstacionKey(string $raw): ?string {
    global $estacionAliases;
    // quita el número inicial ("9885 custodia" -> "custodia")
    $texto = trim(preg_replace('/^\d+\s*/', '', $raw));
    $texto = strtolower($texto);
    return $estacionAliases[$texto] ?? null;
}

$estacionesModel = new EstacionesModel();
$catalogo = $estacionesModel->get_select_stations(); // [Codigo, Nombre]
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
    [$fechaRaw, $litros, $productoRaw, $estacionRaw, $horaRaw, $transpRaw, $facturaRaw] = $row;
    $lineNo = $i + 1;

    $hora = parseHora($horaRaw);
    if ($hora === null && trim($horaRaw) !== '') {
        $errors[] = "Fila $lineNo: no se pudo interpretar la hora '$horaRaw'";
        continue;
    }

    [$product, $mezcla] = parseProducto($productoRaw);
    if (!in_array($product, ['Regular', 'Premium', 'Diesel', 'Mixta'], true)) {
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

    $referencia = trim($facturaRaw) !== '' ? trim($facturaRaw) : null;
    $notas = ($transp === '*') ? 'Transportista original: * (sin especificar en el reporte de origen)' : null;

    $resolved[] = [
        'fecha' => FECHA,
        'hora' => $hora,
        'supplier_id' => SUPPLIER_ID,
        'terminal_id' => $terminalId,
        'station_code' => $stationCode,
        'station_nombre' => $stationNombre,
        'product' => $product,
        'mezcla' => $mezcla,
        'litros' => $litros,
        'carrier_id' => $carrierId,
        'referencia' => $referencia,
        'notas' => $notas,
    ];
}

echo "\n=== Filas resueltas (" . count($resolved) . " de " . count($rawRows) . ") ===\n";
foreach ($resolved as $r) {
    printf(
        "%s %s | %-10s%-8s | litros=%-6d | estacion=%d %s | carrier=%s | ref=%s\n",
        $r['fecha'], $r['hora'] ?? '--:--', $r['product'], $r['mezcla'] ? " ({$r['mezcla']})" : '',
        $r['litros'], $r['station_code'], $r['station_nombre'],
        $r['carrier_id'] ?? '-', $r['referencia'] ?? '-'
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
