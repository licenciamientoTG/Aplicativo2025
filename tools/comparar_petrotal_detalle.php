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
const BBL = 158.987;

// Ventana amplia: 31-jul a 29-ago para cubrir el desfase de fecha factura vs DiaReporte
$desde = '2026-07-28 00:00:00';
$hasta = '2026-08-27 23:59:59';

function clasificar($desc) {
    $d = mb_strtoupper($desc, 'UTF-8');
    if (strpos($d, 'PREMIUM') !== false || strpos($d, 'SUPER') !== false) return 'Premium';
    if (strpos($d, 'REGULAR') !== false || strpos($d, 'MAXIMA') !== false || strpos($d, 'MÁXIMA') !== false) return 'Regular';
    if (strpos($d, 'DIESEL') !== false || strpos($d, 'DIÉSEL') !== false || strpos($d, 'DIÉ') !== false) return 'Diesel';
    return 'Otro: ' . trim(preg_replace('/\s+/', ' ', $d));
}

echo "=== COMPRAS (Petrotal como receptor) por producto, litros->bbl ===\n";
$rows = $db->select("
    SELECT fr.Id, fr.Fecha, fr.Folio, fr.EmisorNombre, c.Cantidad, c.Descripcion
    FROM FacturasRecibidas fr
    JOIN FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
    WHERE fr.ReceptorRfc = ? AND fr.Fecha BETWEEN ? AND ?
      AND fr.EmisorNombre IN ('TESORO MEXICO SUPPLY & MARKETING', 'ESSA FUEL ADVISORS')
    ORDER BY fr.Fecha
", [PETROTAL_RFC, $desde, $hasta]);

$sumCompras = [];
foreach ($rows as $r) {
    $prod = clasificar($r['Descripcion']);
    $bbl = round($r['Cantidad'] / BBL, 2);
    $sumCompras[$prod] = ($sumCompras[$prod] ?? 0) + $bbl;
    echo "  {$r['Fecha']} {$r['Folio']} {$r['EmisorNombre']} | $prod | {$r['Cantidad']} L = $bbl bbl\n";
}
echo "\nSUBTOTALES COMPRAS (bbl):\n";
foreach ($sumCompras as $p => $t) echo "  $p: " . round($t, 2) . "\n";

echo "\n\n=== VENTAS (Petrotal como emisor) por producto, litros->bbl ===\n";
$rows2 = $db->select("
    SELECT fr.Id, fr.Fecha, fr.Folio, fr.ReceptorNombre, c.Cantidad, c.Descripcion
    FROM FacturasRecibidas fr
    JOIN FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
    WHERE fr.EmisorRfc = ? AND fr.Fecha BETWEEN ? AND ?
    ORDER BY fr.Fecha
", [PETROTAL_RFC, $desde, $hasta]);

$sumVentas = [];
foreach ($rows2 as $r) {
    $prod = clasificar($r['Descripcion']);
    $bbl = round($r['Cantidad'] / BBL, 2);
    $sumVentas[$prod] = ($sumVentas[$prod] ?? 0) + $bbl;
    echo "  {$r['Fecha']} {$r['Folio']} -> {$r['ReceptorNombre']} | $prod | {$r['Cantidad']} L = $bbl bbl\n";
}
echo "\nSUBTOTALES VENTAS (bbl):\n";
foreach ($sumVentas as $p => $t) echo "  $p: " . round($t, 2) . "\n";
