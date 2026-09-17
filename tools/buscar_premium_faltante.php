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

foreach ([13699.36, 14157.40] as $litros) {
    $rows = $db->select("
        SELECT fr.Fecha, fr.Folio, c.Cantidad, c.Descripcion
        FROM FacturasRecibidas fr
        JOIN FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
        WHERE fr.EmisorNombre = 'TESORO MEXICO SUPPLY & MARKETING'
          AND fr.ReceptorRfc = ?
          AND c.Cantidad BETWEEN ? AND ?
        ORDER BY fr.Fecha
    ", ['PET180213L66', $litros - 50, $litros + 50]);
    echo "Buscando litros~$litros (+-50):\n";
    foreach ($rows as $r) echo "  {$r['Fecha']} {$r['Folio']} {$r['Cantidad']} {$r['Descripcion']}\n";
    if (!$rows) echo "  (nada encontrado)\n";
    echo "\n";
}

// Tambien: todas las facturas de Tesoro sin filtrar por fecha, buscando esos dos folios especificos que faltan por contexto (antes/despues de 31-jul y 24-ago)
echo "Todas las facturas Tesoro->Petrotal cercanas a 31-jul (25jul-05ago):\n";
$rows = $db->select("
    SELECT fr.Fecha, fr.Folio, c.Cantidad, c.Descripcion
    FROM FacturasRecibidas fr
    JOIN FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
    WHERE fr.EmisorNombre = 'TESORO MEXICO SUPPLY & MARKETING' AND fr.ReceptorRfc = ?
      AND fr.Fecha BETWEEN '2026-07-25' AND '2026-08-05'
    ORDER BY fr.Fecha
", ['PET180213L66']);
foreach ($rows as $r) echo "  {$r['Fecha']} {$r['Folio']} {$r['Cantidad']} {$r['Descripcion']}\n";

echo "\nTodas las facturas Tesoro->Petrotal cercanas a 24-ago (20ago-28ago):\n";
$rows2 = $db->select("
    SELECT fr.Fecha, fr.Folio, c.Cantidad, c.Descripcion
    FROM FacturasRecibidas fr
    JOIN FacturasRecibidasConceptos c ON c.FacturaId = fr.Id
    WHERE fr.EmisorNombre = 'TESORO MEXICO SUPPLY & MARKETING' AND fr.ReceptorRfc = ?
      AND fr.Fecha BETWEEN '2026-08-20' AND '2026-08-28'
    ORDER BY fr.Fecha
", ['PET180213L66']);
foreach ($rows2 as $r) echo "  {$r['Fecha']} {$r['Folio']} {$r['Cantidad']} {$r['Descripcion']}\n";
