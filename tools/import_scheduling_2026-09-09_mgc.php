<?php
/**
 * Import de una sola vez: recepciones de MGC México del 09/09/2026 en sus 4
 * terminales (Diaz Gas, Gaso Mex, Aguascalientes, San Miguel de Allende).
 * Formato MGC (distinto de Tesoro/PremierGas): sin columna TRANSP fija, con
 * DESTINO (código de camión, ej. "T2") y EMBARQUE (folio) -- ver
 * docs/superpowers/specs/2026-09-05-programacion-recepciones-combustible-design.md.
 * DESTINO/EMBARQUE se guardan concatenados en 'referencia' (ej. "T2 / 4542650"),
 * no hay campo de transportista/carrier real en este formato.
 *
 * Uso: php tools/import_scheduling_2026-09-09_mgc.php [--commit]
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

function toIsoDate(string $raw): string {
    [$d, $m, $y] = explode('/', trim($raw));
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

// terminal, fecha, litros, producto, estacionRaw, destino, embarque
// (columna "HORARIO" del reporte de origen en realidad trae el código de
// camión tipo "T2"/"T3", no una hora -- confirmado por el propio encabezado
// "DESTINO" que sigue después; se guarda como referencia, igual que destino/embarque)
$rawRows = [
    // MGC (Diaz Gas) 7
    ['Diaz Gas', '09/09/2026', 20000, 'Regular', 'hnos escobar',    'T2', '1151', '4542650'],
    ['Diaz Gas', '09/09/2026', 20000, 'Regular', '1149 lerdo',      'T2', '1153', '4542653'],
    ['Diaz Gas', '09/09/2026', 20000, 'Regular', '1376 delicias',   'T2', '1137', '4542514'],
    ['Diaz Gas', '09/09/2026', 20000, 'Regular', '2526 lopez mateos', 'T2', '1154', '4542657'],
    ['Diaz Gas', '09/09/2026', 20000, 'Regular', '5317 municipio',  'T2', '1157', '4542660'],
    ['Diaz Gas', '09/09/2026', 20000, 'Diesel',  '9235 aeronautica', 'T2', '1148', '4542662'],
    ['Diaz Gas', '09/09/2026', 20000, 'Regular', '9885 custodia',   'T2', '1147', '4542666'],
    ['Diaz Gas', '09/09/2026', 20000, 'Diesel',  '9893 anapra',     'T2', '1149', '4542670'],
    ['Diaz Gas', '09/09/2026', 31500, 'Regular', '1163 tecnologico', 'T2', '1161', '4542679'],
    ['Diaz Gas', '09/09/2026', 31500, 'Diesel',  'travel center',   'T2', '3949', '4542680'],
    ['Diaz Gas', '09/09/2026', 31500, 'Diesel',  'travel center',   'T2', '3949', '4542683'],
    ['Diaz Gas', '09/09/2026', 20000, 'Diesel',  '4179 g chica',    'T3', '1155', '4542690'],
    ['Diaz Gas', '09/09/2026', 20000, 'Diesel',  '8244 permuta',    'T3', '1160', '4542694'],
    ['Diaz Gas', '09/09/2026', 20000, 'Regular', 'travel center',   'T3', '3949', '4542698'],

    // MGC (Gaso Mex) 1
    ['Gaso Mex', '09/09/2026', 31500, 'Regular', '4457 satelite',   'T2', '1139', '4542725'],
    ['Gaso Mex', '09/09/2026', 20000, 'Regular', '9733 ejercito',   'T2', '1140', '4542727'],
    ['Gaso Mex', '09/09/2026', 20000, 'Regular', '1159 fuentes',    'T3', '1143', '4542729'],
    ['Gaso Mex', '09/09/2026', 10000, 'Regular', '12097 santiago',  'T3', '1142', '4542730'],
    ['Gaso Mex', '09/09/2026', 10000, 'Premium', '12097 santiago',  'T3', '1142', '4542732'],

    // MGC (Aguascalientes)
    ['Aguascalientes', '09/09/2026', 20000, 'Regular', '3175 San rafael', 'T2', '3175', '4528449'],
    ['Aguascalientes', '09/09/2026', 10000, 'Premium', '3175 San rafael', 'T2', '3175', '4528450'],
    ['Aguascalientes', '09/09/2026', 10000, 'Diesel',  '3175 San rafael', 'T2', '3175', '4528451'],
    ['Aguascalientes', '09/09/2026', 20000, 'Regular', '5104 Puertecito', 'T2', '5104', '4528455'],
    ['Aguascalientes', '09/09/2026', 20000, 'Regular', '5346 jesus maria', 'T3', '5346', '4528453'],

    // MGC (San Miguel de Allende)
    ['San Miguel de Allende', '09/09/2026', 20000, 'Regular', '24499 piccahos', 'T3', '3996', '4441772'],
    ['San Miguel de Allende', '09/09/2026', 20000, 'Regular', '24500 ventanas', 'T2', '3995', '4441833'],
];

$estacionAliases = [
    'hnos escobar'  => 'hermanos escobar',
    'lerdo'         => 'lerdo',
    'delicias'      => 'delicias',
    'lopez mateos'  => 'lopez mateos',
    'municipio'     => 'municipio',
    'aeronautica'   => 'aeron',
    'custodia'      => 'custodia',
    'anapra'        => 'anapra',
    'tecnologico'   => 'tecnol',
    'travel center' => 'travel center',
    'g chica'       => 'gemela chica',
    'permuta'       => 'permuta',
    'satelite'      => 'satélite',
    'ejercito'      => 'ejército',
    'fuentes'       => 'fuentes',
    'santiago'      => 'santiago',
    'san rafael'    => 'san rafael',
    'puertecito'    => 'puertecito',
    'jesus maria'   => 'jesus maria',
    'piccahos'      => 'picachos', // typo en el reporte de origen
    'ventanas'      => 'ventanas',
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

$terminalIds = [];
foreach (['Diaz Gas', 'Gaso Mex', 'Aguascalientes', 'San Miguel de Allende'] as $nombre) {
    $t = $fuelTerminalsModel->find_by_name($nombre);
    if (!$t) {
        fwrite(STDERR, "Terminal '$nombre' no encontrada en fuel_terminals. Abortando.\n");
        exit(1);
    }
    $terminalIds[$nombre] = (int)$t['id'];
    echo "Terminal '$nombre' -> id {$t['id']}\n";
}

$resolved = [];
$errors = [];

foreach ($rawRows as $i => $row) {
    [$terminalNombre, $fechaRaw, $litros, $productoRaw, $estacionRaw, $destino, $embarque] = $row;
    $lineNo = $i + 1;

    $product = trim($productoRaw);
    if (!in_array($product, ['Regular', 'Premium', 'Diesel', 'Mixta'], true)) {
        $errors[] = "Fila $lineNo ($terminalNombre): producto no reconocido '$productoRaw'";
        continue;
    }

    $key = resolveEstacionKey($estacionRaw);
    if ($key === null) {
        $errors[] = "Fila $lineNo ($terminalNombre): no hay alias definido para estación '$estacionRaw'";
        continue;
    }

    $matches = array_values(array_filter($catalogo, function ($e) use ($key) {
        return mb_stripos($e['Nombre'], $key) !== false;
    }));

    if (count($matches) === 0) {
        $errors[] = "Fila $lineNo ($terminalNombre): estación '$estacionRaw' (alias '$key') no encontrada en catálogo";
        continue;
    }
    if (count($matches) > 1) {
        $nombres = implode(' | ', array_map(fn($m) => $m['Codigo'] . ':' . $m['Nombre'], $matches));
        $errors[] = "Fila $lineNo ($terminalNombre): estación '$estacionRaw' (alias '$key') ambigua, coincide con: $nombres";
        continue;
    }

    $stationCode = (int)$matches[0]['Codigo'];
    $stationNombre = $matches[0]['Nombre'];

    $referencia = trim("$destino / $embarque", ' /');

    $resolved[] = [
        'fecha' => toIsoDate($fechaRaw),
        'hora' => null,
        'supplier_id' => SUPPLIER_ID,
        'terminal_id' => $terminalIds[$terminalNombre],
        'terminal_nombre' => $terminalNombre,
        'station_code' => $stationCode,
        'station_nombre' => $stationNombre,
        'product' => $product,
        'mezcla' => null,
        'litros' => $litros,
        'carrier_id' => null,
        'referencia' => $referencia,
        'notas' => null,
    ];
}

echo "\n=== Filas resueltas (" . count($resolved) . " de " . count($rawRows) . ") ===\n";
foreach ($resolved as $r) {
    printf(
        "%-22s | %s | %-9s | litros=%-6d | estacion=%-25s | ref=%s\n",
        $r['terminal_nombre'], $r['fecha'], $r['product'],
        $r['litros'], $r['station_code'] . ' ' . $r['station_nombre'], $r['referencia']
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
        echo "  OK id=$id | {$r['terminal_nombre']} | {$r['station_nombre']} | {$r['product']} {$r['litros']}L | ref={$r['referencia']}\n";
        $inserted++;
    } catch (Exception $e) {
        echo "  FALLO {$r['station_nombre']} {$r['product']}: " . $e->getMessage() . "\n";
    }
}
echo "\nInsertadas $inserted de " . count($resolved) . " filas resueltas.\n";
if ($errors) {
    echo count($errors) . " filas NO se importaron por error de mapeo (ver arriba). Revisar manualmente.\n";
}
