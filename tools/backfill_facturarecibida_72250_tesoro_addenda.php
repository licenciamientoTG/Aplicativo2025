<?php
/**
 * Backfill puntual: la factura Id=72250 (Tesoro/Diaz Gas, UUID
 * 8C25E357-364D-49D5-8CCD-35C3A94ED498) se subió por el modal de
 * /supply/scheduling ANTES del fix a
 * FuelReceptionInvoiceModel::parseCfdiXml() que ahora sí lee la Addenda de
 * Tesoro (Destino/Remision/PresentacionTesoro). Se re-parsea el mismo XML
 * que el usuario subió y se actualizan esas 3 columnas en el registro ya
 * existente -- no se reinserta ni se re-vincula nada.
 *
 * Uso: php tools/backfill_facturarecibida_72250_tesoro_addenda.php [--commit]
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

const INVOICE_ID = 72250;
const XML_PATH = 'C:\\Users\\alejandro.martinez\\Downloads\\claudia ventas\\SECFD_20260909_061939.xml';

$invoiceModel = new FuelReceptionInvoiceModel();

$parsed = $invoiceModel->parseCfdiXml(XML_PATH);
$factura = $parsed['factura'];

echo "UUID del XML: {$factura['UUID']}\n";
echo "Destino: {$factura['Destino']}\n";
echo "Remision: {$factura['Remision']}\n";
echo "PresentacionTesoro: {$factura['PresentacionTesoro']}\n";

$existente = $invoiceModel->buscarPorUuid($factura['UUID']);
if (!$existente || (int)$existente['Id'] !== INVOICE_ID) {
    fwrite(STDERR, "El UUID del XML no corresponde al registro Id=" . INVOICE_ID . ". Abortando.\n");
    exit(1);
}

if (!$commit) {
    echo "\nDry-run: no se actualizó nada. Ejecuta con --commit para aplicar.\n";
    exit(0);
}

$sql = MySqlPdoHandler::getInstance();
$sql->connect('SG12');
$query = "UPDATE TG.dbo.FacturasRecibidas SET Destino = ?, Remision = ?, PresentacionTesoro = ? WHERE Id = ?";
$sql->update($query, [$factura['Destino'], $factura['Remision'], $factura['PresentacionTesoro'], INVOICE_ID]);

echo "\nOK: FacturasRecibidas.Id=" . INVOICE_ID . " actualizado.\n";
