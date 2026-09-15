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

$facturaIds = [72696, 72398]; // Municipio Libre (Tesoro), Petrotal (ejemplo con más variedad)
foreach ($facturaIds as $id) {
    echo "=== FacturaId=$id ===\n";
    $rows = $db->select("
        SELECT FacturaId, Cantidad, ClaveProdServ, Descripcion, ValorUnitario, Importe
        FROM FacturasRecibidasConceptos
        WHERE FacturaId = ?
    ", [$id]);
    foreach ($rows as $r) {
        echo "  {$r['Cantidad']}\t{$r['ClaveProdServ']}\t{$r['Descripcion']}\t{$r['ValorUnitario']}\t{$r['Importe']}\n";
    }
    if (!$rows) echo "  (sin conceptos)\n";
}

// Buscar una factura de Tesoro con MULTIPLES conceptos/productos distintos
echo "\n=== Facturas de Tesoro con mas de 1 concepto (buscando ejemplo Mixta/multi-producto) ===\n";
$rows2 = $db->select("
    SELECT TOP 5 c.FacturaId, COUNT(*) AS n
    FROM FacturasRecibidasConceptos c
    JOIN FacturasRecibidas f ON f.Id = c.FacturaId
    WHERE f.EmisorNombre = 'TESORO MEXICO SUPPLY & MARKETING'
    GROUP BY c.FacturaId
    HAVING COUNT(*) > 1
    ORDER BY c.FacturaId DESC
", []);
foreach ($rows2 as $r) {
    echo "FacturaId={$r['FacturaId']} conceptos={$r['n']}\n";
    $detalle = $db->select("SELECT Cantidad, ClaveProdServ, Descripcion FROM FacturasRecibidasConceptos WHERE FacturaId = ?", [$r['FacturaId']]);
    foreach ($detalle as $d) {
        echo "    {$d['Cantidad']}\t{$d['ClaveProdServ']}\t{$d['Descripcion']}\n";
    }
}
