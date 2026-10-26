<?php
/**
 * Migración de una sola vez: agrega TG.dbo.FacturasRecibidas.OrigenImportacion (varchar(20) NULL)
 * para distinguir las facturas importadas a mano desde el aplicativo (/payment/import_invoices).
 *
 * Valor 'aplicativo' = importada con el aplicativo. NULL = llegó por correo u otra vía / sin dato.
 * Las nuevas importaciones las marca PaymentController::import_invoice_pdf (update_ruta).
 *
 * Relleno histórico: solo filas con FechaImportacion (desde 2026-09-10) y RutaArchivo ABSOLUTA
 * ("C:\..."): el pipeline de correo guarda rutas relativas ("attachments\..."), el aplicativo
 * guarda la ruta completa. Antes de FechaImportacion el pipeline de correo también guardaba
 * rutas absolutas, por eso esas filas NO se tocan.
 *
 * Uso: php tools/add_origen_importacion_facturarecibidas.php [--commit]
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
});

$commit = in_array('--commit', $argv, true);

$sql = MySqlPdoHandler::getInstance();
$sql->connect('SG12');

$existe = $sql->select(
    "SELECT COUNT(*) AS n FROM TG.INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'FacturasRecibidas' AND COLUMN_NAME = 'OrigenImportacion'",
    []
);
$yaExiste = ($existe[0]['n'] ?? 0) > 0;
echo $yaExiste ? "La columna OrigenImportacion ya existe.\n" : "La columna OrigenImportacion NO existe todavía.\n";

$cond = "FechaImportacion IS NOT NULL AND RutaArchivo LIKE '[A-Za-z]:%'";
if ($yaExiste) {
    $cond .= " AND OrigenImportacion IS NULL";
    $n = $sql->select("SELECT COUNT(*) AS n FROM TG.dbo.FacturasRecibidas WHERE $cond", []);
    echo "Filas por marcar como 'aplicativo': " . ($n[0]['n'] ?? 0) . "\n";
} else {
    $n = $sql->select("SELECT COUNT(*) AS n FROM TG.dbo.FacturasRecibidas WHERE $cond", []);
    echo "Filas que se marcarían como 'aplicativo': " . ($n[0]['n'] ?? 0) . "\n";
}

if (!$commit) {
    echo "\nDry-run: no se modificó nada. Ejecuta con --commit para aplicar.\n";
    exit(0);
}

if (!$yaExiste) {
    $sql->query("ALTER TABLE TG.dbo.FacturasRecibidas ADD OrigenImportacion VARCHAR(20) NULL");
    echo "OK: columna OrigenImportacion agregada.\n";
}

$sql->query("UPDATE TG.dbo.FacturasRecibidas SET OrigenImportacion = 'aplicativo' WHERE $cond");
echo "OK: relleno histórico aplicado.\n";
