<?php
/**
 * Tarea programada: foto diaria v2 de clientes débito — cierre del DÍA DE
 * AYER en TG.dbo.debit_clients_cierre (bitácora en debit_clients_cierre_runs)
 * y, después, la de clientes crédito en TG.dbo.credit_clients_cierre
 * (bitácora en credit_clients_cierre_runs; spec 2026-11-12-credit-...).
 * Recupera solos los días que no se procesaron (hasta 31).
 * Spec: docs/superpowers/specs/2026-11-11-debit-clients-cierre-diario-design.md
 *
 * Tarea sya_debit_snapshot_diario en 192.168.0.3, diaria a las 08:00 AM
 * (el último corte del día, turno 41, se cierra a las 06:00):
 *   cmd.exe /d /c C:\php\php.exe cron\debit_snapshot_diario.php >> logs\debit_snapshot_diario.log 2>&1
 *   Iniciar en: C:\inetpub\wwwroot\TG_PHP
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

echo "[" . date('Y-m-d H:i:s') . "] Iniciando snapshot diario de clientes débito y crédito\n";

// El controlador autoriza el cron por token; en CLI lo pasamos por $_GET.
$_GET['cron_token'] = CRON_SECRET;

$income = new Income($twig);
$income->debit_snapshot_refresh(); // imprime el JSON del resultado y termina el proceso
