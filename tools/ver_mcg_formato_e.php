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

// Todos los Destino pendientes de MCG que empiezan con E o P seguido de digitos
$rows = $db->select("
    SELECT DISTINCT Destino
    FROM FacturasRecibidas
    WHERE EmisorRfc = 'MME141110IJ9' AND EstacionCodgas IS NULL
      AND Destino IS NOT NULL AND Destino <> ''
      AND (Destino LIKE 'E[0-9][0-9][0-9][0-9][0-9]' OR Destino LIKE 'P[0-9][0-9][0-9][0-9][0-9]')
", []);
echo "Destinos formato E##### o P#####: " . count($rows) . "\n";
foreach ($rows as $r) {
    $d = $r['Destino'];
    $sufijo = substr($d, 1); // quita la primera letra (E o P), compara solo el número
    $match = $db->select("SELECT Codigo, Nombre, Estacion FROM Estaciones WHERE RIGHT(Estacion, LEN(?)) = ?", [$sufijo, $sufijo]);
    if ($match) {
        foreach ($match as $m) {
            echo "  $d (sufijo=$sufijo) -> Codigo={$m['Codigo']} {$m['Nombre']} (Estacion={$m['Estacion']})\n";
        }
    } else {
        echo "  $d (sufijo=$sufijo) -> SIN MATCH ni por sufijo\n";
    }
}
