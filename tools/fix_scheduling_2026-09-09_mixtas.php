<?php
/**
 * Corrección de las filas 'Mixta' insertadas por
 * import_scheduling_2026-09-09_tesoro.php: en el reporte de origen, una fila
 * "Mixta (21/11)" con 31,000 litros en realidad son DOS productos entregados
 * juntos: 21,000 L de Regular + 11,000 L de Super (Premium) -- no un producto
 * llamado "Mixta". Se cancelan las filas Mixta originales (ids conocidos de
 * la corrida anterior) y se insertan sus reemplazos como 2 filas Regular/
 * Premium cada una, mismos fecha/hora/estación/proveedor/terminal.
 *
 * Uso: php tools/fix_scheduling_2026-09-09_mixtas.php [--commit]
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

// ids insertados por import_scheduling_2026-09-09_tesoro.php que quedaron como 'Mixta'
$mixtaIds = [5648, 5655, 5656, 5659]; // Lopez Mateos 21/11, Misiones 16/16, Puerto de palos 21/11, Municipio Libre 21/11

echo "=== Filas Mixta actuales ===\n";
$rows = [];
foreach ($mixtaIds as $id) {
    $row = $model->get_one($id);
    if (!$row) {
        echo "  id=$id NO ENCONTRADA (¿ya fue modificada?)\n";
        continue;
    }
    if ($row['estatus'] === 'Cancelado') {
        echo "  id=$id ya está Cancelado, se omite\n";
        continue;
    }
    printf("  id=%d %s %s | %s (%s) | %d L | station_code=%d\n",
        $row['id'], $row['fecha'], $row['hora'], $row['product'], $row['mezcla'], $row['litros'], $row['station_code']);
    $rows[] = $row;
}

if (!$rows) {
    echo "\nNada que corregir.\n";
    exit(0);
}

echo "\n=== Reemplazos a crear ===\n";
$plan = [];
foreach ($rows as $row) {
    if (!preg_match('#^(\d+)/(\d+)$#', $row['mezcla'], $m)) {
        echo "  id={$row['id']}: mezcla '{$row['mezcla']}' no tiene formato N/N, se omite\n";
        continue;
    }
    $litrosA = (int)$m[1] * 1000; // "21" -> 21000
    $litrosB = (int)$m[2] * 1000; // "11" -> 11000

    $base = [
        'fecha' => $row['fecha'],
        'hora' => $row['hora'],
        'supplier_id' => $row['supplier_id'],
        'terminal_id' => $row['terminal_id'],
        'station_code' => $row['station_code'],
        'carrier_id' => $row['carrier_id'],
        'referencia' => $row['referencia'],
        'notas' => $row['notas'],
        'mezcla' => null,
    ];

    $plan[] = ['cancel_id' => $row['id'], 'insert' => array_merge($base, ['product' => 'Regular', 'litros' => $litrosA])];
    $plan[] = ['cancel_id' => $row['id'], 'insert' => array_merge($base, ['product' => 'Premium', 'litros' => $litrosB])];

    printf("  id=%d -> Regular %dL + Premium %dL | %s %s | station_code=%d\n",
        $row['id'], $litrosA, $litrosB, $row['fecha'], $row['hora'], $row['station_code']);
}

if (!$commit) {
    echo "\nDry-run: no se canceló ni insertó nada. Ejecuta con --commit para aplicar.\n";
    exit(0);
}

echo "\n=== Aplicando ===\n";
$cancelled = [];
foreach ($plan as $p) {
    $id = $p['cancel_id'];
    if (!in_array($id, $cancelled, true)) {
        $model->cancel($id, 0);
        echo "  Cancelada fila Mixta original id=$id\n";
        $cancelled[] = $id;
    }
    $newId = $model->add($p['insert'], 0);
    echo "    OK nueva fila id=$newId | {$p['insert']['product']} {$p['insert']['litros']}L | station_code={$p['insert']['station_code']}\n";
}

echo "\nListo.\n";
