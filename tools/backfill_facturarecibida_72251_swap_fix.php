<?php
/**
 * Backfill puntual: la factura Id=72251 (Tesoro/Diaz Gas, UUID
 * 8C25E357-364D-49D5-8CCD-35C3A94ED498, folio 8800294841) se insertó por el
 * modal de /supply/scheduling usando la primera versión del fix a
 * parseCfdiXml(), que tenía Destino/Remision invertidos (confirmado contra
 * la factura 72219, insertada por el pipeline automático de correos y nunca
 * tocada por este código: ahí Destino=permiso HYP en texto, Remision=
 * comprobante de carga numérico). Se corrige el swap en el registro ya
 * insertado, sin volver a parsear el XML.
 *
 * Uso: php tools/backfill_facturarecibida_72251_swap_fix.php [--commit]
 */

$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);

require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';

spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) {
        require CLASSES . $class . '.class.php';
    }
    if (file_exists(MODELS . $class . '.php')) {
        require MODELS . $class . '.php';
    }
});

$commit = in_array('--commit', $argv, true);

const INVOICE_ID = 72251;
const UUID = '8C25E357-364D-49D5-8CCD-35C3A94ED498';

$sql = MySqlPdoHandler::getInstance();
$sql->connect('SG12');

$rows = $sql->select("SELECT Id, UUID, Destino, Remision, PresentacionTesoro FROM TG.dbo.FacturasRecibidas WHERE Id = ?", [INVOICE_ID]);
$row = $rows[0] ?? null;
if (!$row || $row['UUID'] !== UUID) {
    fwrite(STDERR, "Registro Id=" . INVOICE_ID . " no encontrado o UUID no coincide.\n");
    exit(1);
}

echo "Actual: Destino={$row['Destino']} | Remision={$row['Remision']} | PresentacionTesoro={$row['PresentacionTesoro']}\n";
echo "Nuevo:  Destino={$row['Remision']} | Remision={$row['Destino']} | PresentacionTesoro={$row['PresentacionTesoro']} (sin cambio)\n";

if (!$commit) {
    echo "\nDry-run: no se actualizó nada. Ejecuta con --commit para aplicar.\n";
    exit(0);
}

$sql->update(
    "UPDATE TG.dbo.FacturasRecibidas SET Destino = ?, Remision = ? WHERE Id = ?",
    [$row['Remision'], $row['Destino'], INVOICE_ID]
);

echo "\nOK: swap corregido en FacturasRecibidas.Id=" . INVOICE_ID . ".\n";
