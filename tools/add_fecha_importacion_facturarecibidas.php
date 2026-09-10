<?php
/**
 * Migración de una sola vez: agrega TG.dbo.FacturasRecibidas.FechaImportacion
 * (datetime, DEFAULT GETDATE()) para registrar cuándo se insertó cada
 * factura en la base -- hasta ahora no existía ningún campo con ese
 * significado (Fecha/FechaTimbrado son datos del propio CFDI, no de la
 * inserción). Las filas ya existentes quedan con FechaImportacion=NULL;
 * todo INSERT nuevo (vía la API de ApiER o el modal de scheduling en
 * AplicativoPhp) la llena automáticamente por el DEFAULT, sin tocar código.
 *
 * Uso: php tools/add_fecha_importacion_facturarecibidas.php [--commit]
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
    "SELECT COUNT(*) AS n FROM TG.INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'FacturasRecibidas' AND COLUMN_NAME = 'FechaImportacion'",
    []
);
if (($existe[0]['n'] ?? 0) > 0) {
    echo "La columna FechaImportacion ya existe. No se hace nada.\n";
    exit(0);
}

echo "La columna FechaImportacion NO existe todavía.\n";

if (!$commit) {
    echo "\nDry-run: no se alteró la tabla. Ejecuta con --commit para aplicar.\n";
    exit(0);
}

$sql->query("ALTER TABLE TG.dbo.FacturasRecibidas ADD FechaImportacion DATETIME NULL CONSTRAINT DF_FacturasRecibidas_FechaImportacion DEFAULT GETDATE()");

echo "\nOK: columna FechaImportacion agregada con DEFAULT GETDATE().\n";
