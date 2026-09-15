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

$rows = $db->select("
    SELECT c.FacturaId, COUNT(DISTINCT c.Descripcion) AS n_descripciones
    FROM FacturasRecibidasConceptos c
    JOIN FacturasRecibidas f ON f.Id = c.FacturaId
    WHERE f.EmisorNombre LIKE '%TESORO%' OR f.EmisorNombre LIKE '%PETROTAL%'
    GROUP BY c.FacturaId
    HAVING COUNT(DISTINCT c.Descripcion) > 1
    ORDER BY c.FacturaId DESC
", []);
echo "Facturas con multiples descripciones distintas: " . count($rows) . "\n";
foreach (array_slice($rows, 0, 5) as $r) {
    echo "FacturaId={$r['FacturaId']}\n";
    $detalle = $db->select("SELECT Cantidad, Descripcion FROM FacturasRecibidasConceptos WHERE FacturaId = ?", [$r['FacturaId']]);
    foreach ($detalle as $d) {
        echo "    {$d['Cantidad']}\t{$d['Descripcion']}\n";
    }
}
