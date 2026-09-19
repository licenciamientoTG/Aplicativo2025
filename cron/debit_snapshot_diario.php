<?php
/**
 * Tarea programada: genera la foto diaria de TG.dbo.debit_clients_snapshot
 * para todos los clientes débito activos. Equivalente al backfill manual
 * corrido una vez el 2026-09-17, pero ejecutado cada día.
 *
 * Configurar en Programador de Tareas de Windows a las 06:00 AM:
 *   Programa:   php
 *   Argumentos: C:\ruta\AplicativoPhp\cron\debit_snapshot_diario.php
 *
 * Nota: la ruta HTTP /income/debit_snapshot_refresh NO sirve para el cron
 * porque index.php exige sesión antes de despachar al controlador.
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_URI']   = '/cron/debit_snapshot_diario';
chdir($_SERVER['DOCUMENT_ROOT']);

require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';

spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) {
        require CLASSES . $class . '.class.php';
    }
    if (file_exists(CONTROLLERS . strtolower($class) . '.php')) {
        require CONTROLLERS . strtolower($class) . '.php';
    }
    if (file_exists(MODELS . $class . '.php')) {
        require MODELS . $class . '.php';
    }
});

echo "[" . date('Y-m-d H:i:s') . "] Iniciando snapshot diario de clientes débito\n";

// El controlador autoriza el cron por token; en CLI lo pasamos por $_GET.
$_GET['cron_token'] = CRON_SECRET;

$income = new Income($twig);
$income->debit_snapshot_refresh(); // imprime el JSON del resultado y termina el proceso
