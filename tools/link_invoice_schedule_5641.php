<?php
/**
 * Vincula la recepción programada id 5641 (Tesoro/Diaz Gas, 09/09/2026 10:00,
 * Regular 31,000L, 13 Miguel de la madrid) con la factura que el pipeline de
 * correos ya insertó en TG.dbo.FacturasRecibidas (Id=72250, UUID
 * 8C25E357-364D-49D5-8CCD-35C3A94ED498, folio 8800294841). No se re-sube
 * PDF/XML: reproduce la rama "ya existe por UUID" de
 * Supply::scheduling_upload_invoice(), que solo hace vincular().
 *
 * Uso: php tools/link_invoice_schedule_5641.php [--commit]
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

const SCHEDULE_ID = 5641;
const UUID = '8C25E357-364D-49D5-8CCD-35C3A94ED498';

$scheduleModel = new FuelReceptionScheduleModel();
$invoiceModel = new FuelReceptionInvoiceModel();

$recepcion = $scheduleModel->get_one(SCHEDULE_ID);
if (!$recepcion) {
    fwrite(STDERR, "Recepción " . SCHEDULE_ID . " no existe.\n");
    exit(1);
}
echo "Recepción: {$recepcion['fecha']} {$recepcion['hora']} | supplier_id={$recepcion['supplier_id']} | station_code={$recepcion['station_code']} | {$recepcion['product']} {$recepcion['litros']}L\n";

$factura = $invoiceModel->buscarPorUuid(UUID);
if (!$factura) {
    fwrite(STDERR, "No se encontró factura con UUID " . UUID . " en FacturasRecibidas.\n");
    exit(1);
}
$invoiceId = (int)$factura['Id'];
echo "Factura encontrada: Id=$invoiceId | Folio={$factura['Folio']} | EmisorNombre={$factura['EmisorNombre']} | Total={$factura['Total']}\n";

if ((int)$factura['EmisorRfc'] === null) {
    // no-op, solo para claridad
}
$proveedorPorRfc = $invoiceModel->resolverProveedorPorRfc($factura['EmisorRfc'] ?? '');
if (!$proveedorPorRfc || (int)$proveedorPorRfc['id'] !== (int)$recepcion['supplier_id']) {
    echo "ADVERTENCIA: el RFC del emisor no resuelve al mismo supplier_id de la recepción (esperado {$recepcion['supplier_id']}, resuelto: " . ($proveedorPorRfc['id'] ?? 'ninguno') . "). Se vincula de todas formas, igual que el flujo del modal.\n";
}

$existenteVinculo = $invoiceModel->obtenerFacturaDeRecepcion(SCHEDULE_ID);
if ($existenteVinculo) {
    echo "La recepción ya tiene una factura vinculada (Id={$existenteVinculo['Id']}, Folio={$existenteVinculo['Folio']}). No se hace nada.\n";
    exit(0);
}

if (!$commit) {
    echo "\nDry-run: no se vinculó nada. Ejecuta con --commit para aplicar.\n";
    exit(0);
}

$userId = 0; // capturado fuera de sesión, igual convención que los imports anteriores
$invoiceModel->vincular(SCHEDULE_ID, $invoiceId, $userId);
echo "\nOK: recepción " . SCHEDULE_ID . " vinculada a factura Id=$invoiceId.\n";
