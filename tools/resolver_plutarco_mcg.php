<?php
/**
 * Resuelve directo EstacionCodgas=21 (Plutarco) para las facturas de MCG
 * con Destino='PL/2060/EXP/ES/2015_' -- caso ambiguo (el permiso limpio
 * matchea Codigo=20 "NO FUNCIONA" y Codigo=21 "08 Plutarco"), confirmado
 * manualmente 2026-09-14 que corresponden a Plutarco, descartando la
 * entrada muerta del catálogo.
 *
 * Uso: php tools/resolver_plutarco_mcg.php [--commit]
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

$rows = $db->select("
    SELECT COUNT(*) AS n
    FROM FacturasRecibidas
    WHERE EmisorRfc = 'MME141110IJ9' AND EstacionCodgas IS NULL
      AND Destino = 'PL/2060/EXP/ES/2015_'
", []);
$n = $rows[0]['n'] ?? 0;
echo "Facturas a resolver a EstacionCodgas=21 (Plutarco): $n\n";

if ($commit) {
    // MySqlPdoHandler::update() exige un array de params NO vacío (ver
    // hallazgo similar ya documentado para desvincular() en
    // FuelReceptionInvoiceModel.php -- aquí es el caso opuesto: params
    // vacío hace que el UPDATE nunca se ejecute, en silencio, sin error).
    $resultado = $db->update("
        UPDATE FacturasRecibidas
        SET EstacionCodgas = ?
        WHERE EmisorRfc = 'MME141110IJ9' AND EstacionCodgas IS NULL
          AND Destino = 'PL/2060/EXP/ES/2015_'
    ", [21]);
    echo $resultado ? "OK: UPDATE ejecutado.\n" : "FALLO: el UPDATE no se ejecutó.\n";
} else {
    echo "Dry-run: no se modificó nada. Ejecuta con --commit para aplicar.\n";
}
