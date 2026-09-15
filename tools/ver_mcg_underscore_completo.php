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

// Todos los Destino de MCG pendientes que terminan en _ (cualquier cantidad de facturas)
$rows = $db->select("
    SELECT Destino, COUNT(*) AS n
    FROM FacturasRecibidas
    WHERE EmisorRfc = 'MME141110IJ9' AND EstacionCodgas IS NULL
      AND Destino LIKE '%\_' ESCAPE '\\'
    GROUP BY Destino
    ORDER BY Destino
", []);
echo "Destinos con _ al final, agrupados: " . count($rows) . "\n\n";
$totalFacturas = 0;
$ambiguos = 0;
$sinMatch = 0;
$limpios = 0;
foreach ($rows as $r) {
    $destino = $r['Destino'];
    $n = $r['n'];
    $totalFacturas += $n;
    $limpio = rtrim($destino, '_');
    $match = $db->select("SELECT Codigo, Nombre FROM Estaciones WHERE PermisoCRE = ?", [$limpio]);
    if (count($match) === 0) {
        echo "  [$destino] ($n facturas) -> limpio=[$limpio] SIN MATCH tampoco\n";
        $sinMatch++;
    } elseif (count($match) > 1) {
        $nombres = implode(' | ', array_map(fn($m) => $m['Codigo'] . ':' . $m['Nombre'], $match));
        echo "  [$destino] ($n facturas) -> limpio=[$limpio] AMBIGUO: $nombres\n";
        $ambiguos++;
    } else {
        echo "  [$destino] ($n facturas) -> limpio=[$limpio] -> Codigo={$match[0]['Codigo']} {$match[0]['Nombre']}\n";
        $limpios++;
    }
}
echo "\nResumen: $limpios resolubles limpio, $ambiguos ambiguos, $sinMatch sin match ni limpio\n";
echo "Total facturas afectadas por el patron _: $totalFacturas\n";
