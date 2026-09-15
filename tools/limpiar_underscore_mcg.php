<?php
/**
 * Limpieza puntual: quita el guion bajo sobrante al final de
 * FacturasRecibidas.Destino para facturas de MCG (EmisorRfc=MME141110IJ9)
 * pendientes de resolver (EstacionCodgas IS NULL), donde el permiso CRE
 * "limpio" (sin el _) matchea exactamente 1 estación en TG.dbo.Estaciones.
 * No toca los casos ambiguos (más de 1 estación con el mismo PermisoCRE
 * limpio) -- esos se manejan aparte con criterio explícito.
 *
 * Uso: php tools/limpiar_underscore_mcg.php [--commit]
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
    SELECT Destino, COUNT(*) AS n
    FROM FacturasRecibidas
    WHERE EmisorRfc = 'MME141110IJ9' AND EstacionCodgas IS NULL
      AND Destino LIKE '%\_' ESCAPE '\\'
    GROUP BY Destino
    ORDER BY Destino
", []);

$totalActualizadas = 0;

foreach ($rows as $r) {
    $destino = $r['Destino'];
    $limpio = rtrim($destino, '_');
    $match = $db->select("SELECT Codigo FROM Estaciones WHERE PermisoCRE = ?", [$limpio]);

    if (count($match) !== 1) {
        echo "SALTADO [$destino] -> [$limpio]: " . count($match) . " match(es), requiere criterio manual.\n";
        continue;
    }

    if ($commit) {
        $n = $db->update(
            "UPDATE FacturasRecibidas SET Destino = ? WHERE EmisorRfc = 'MME141110IJ9' AND EstacionCodgas IS NULL AND Destino = ?",
            [$limpio, $destino]
        );
        echo "OK [$destino] -> [$limpio] ({$r['n']} facturas actualizadas)\n";
        $totalActualizadas += (int)$r['n'];
    } else {
        echo "[dry-run] [$destino] -> [$limpio] ({$r['n']} facturas)\n";
        $totalActualizadas += (int)$r['n'];
    }
}

echo "\nTotal facturas " . ($commit ? "actualizadas" : "a actualizar") . ": $totalActualizadas\n";
if (!$commit) {
    echo "Dry-run: no se modificó nada. Ejecuta con --commit para aplicar.\n";
}
