<?php
$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});
$db = MySqlPdoHandler::getInstance();

const PETROTAL_RFC = 'PET180213L66';

// Rango cubierto por los 4 acuses: 31 jul 2026 - 27 ago 2026
$desde = '2026-07-31';
$hasta = '2026-08-27';

echo "=== Facturas RECIBIDAS por Petrotal (EmisorRfc <> PET, ReceptorRfc = PET) ===\n";
echo "(lo que Petrotal COMPRO, ej. a Tesoro)\n\n";
$rows = $db->select("
    SELECT Id, Fecha, Folio, UUID, EmisorNombre, EmisorRfc, ReceptorNombre, ReceptorRfc, Total, Destino
    FROM FacturasRecibidas
    WHERE ReceptorRfc = ?
      AND Fecha BETWEEN ? AND ?
    ORDER BY Fecha
", [PETROTAL_RFC, $desde . ' 00:00:00', $hasta . ' 23:59:59']);

$totalCompras = 0;
foreach ($rows as $r) {
    echo "  {$r['Fecha']} | Folio={$r['Folio']} | {$r['EmisorNombre']} ({$r['EmisorRfc']}) -> {$r['ReceptorNombre']} | Total={$r['Total']} | Destino={$r['Destino']}\n";
    $totalCompras += (float)$r['Total'];
}
echo "\nTOTAL filas: " . count($rows) . "  |  SUMA Total: " . number_format($totalCompras, 2) . "\n";

echo "\n\n=== Facturas GENERADAS por Petrotal (EmisorRfc = PET) ===\n";
echo "(lo que Petrotal VENDIO, ej. a Estacion Custodia)\n\n";
$rows2 = $db->select("
    SELECT Id, Fecha, Folio, UUID, EmisorNombre, EmisorRfc, ReceptorNombre, ReceptorRfc, Total, Destino
    FROM FacturasRecibidas
    WHERE EmisorRfc = ?
      AND Fecha BETWEEN ? AND ?
    ORDER BY Fecha
", [PETROTAL_RFC, $desde . ' 00:00:00', $hasta . ' 23:59:59']);

$totalVentas = 0;
foreach ($rows2 as $r) {
    echo "  {$r['Fecha']} | Folio={$r['Folio']} | {$r['EmisorNombre']} -> {$r['ReceptorNombre']} ({$r['ReceptorRfc']}) | Total={$r['Total']} | Destino={$r['Destino']}\n";
    $totalVentas += (float)$r['Total'];
}
echo "\nTOTAL filas: " . count($rows2) . "  |  SUMA Total: " . number_format($totalVentas, 2) . "\n";

echo "\n\n=== Resumen por semana (compras recibidas, agrupado por fecha) ===\n";
$porFecha = [];
foreach ($rows as $r) {
    $f = substr($r['Fecha'], 0, 10);
    $porFecha[$f] = ($porFecha[$f] ?? 0) + 1;
}
foreach ($porFecha as $f => $n) echo "  $f: $n facturas recibidas\n";

echo "\n=== Resumen por semana (ventas generadas, agrupado por fecha) ===\n";
$porFecha2 = [];
foreach ($rows2 as $r) {
    $f = substr($r['Fecha'], 0, 10);
    $porFecha2[$f] = ($porFecha2[$f] ?? 0) + 1;
}
foreach ($porFecha2 as $f => $n) echo "  $f: $n facturas emitidas\n";
