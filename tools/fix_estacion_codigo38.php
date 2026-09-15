<?php
/**
 * Corrección puntual: TG.dbo.Estaciones.Estacion para Codigo=38 (36 Jesus
 * Maria) tiene un typo -- 'E15091' en vez de 'E15901'. Confirmado con el
 * usuario 2026-09-14: BaseDatos='CG_15901' y el email
 * 'es15901_jesusmaria@...' de esa misma fila usan el número correcto
 * (15901), consistente con el Destino de las facturas de MCG para esta
 * estación.
 *
 * Uso: php tools/fix_estacion_codigo38.php [--commit]
 */

$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) require CLASSES . $class . '.class.php';
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});

$commit = in_array('--commit', $argv, true);
$db = MySqlPdoHandler::getInstance();

$antes = $db->select("SELECT Codigo, Nombre, Estacion, BaseDatos FROM Estaciones WHERE Codigo = 38", []);
echo "Antes: " . json_encode($antes[0] ?? null) . "\n";

if ($commit) {
    $resultado = $db->update("UPDATE Estaciones SET Estacion = ? WHERE Codigo = ?", ['E15901', 38]);
    echo $resultado ? "OK: UPDATE ejecutado.\n" : "FALLO: el UPDATE no se ejecutó.\n";

    $despues = $db->select("SELECT Codigo, Nombre, Estacion, BaseDatos FROM Estaciones WHERE Codigo = 38", []);
    echo "Después: " . json_encode($despues[0] ?? null) . "\n";
} else {
    echo "Dry-run: no se modificó nada. Ejecuta con --commit para aplicar.\n";
}
