<?php
/**
 * Tarea programada: propaga a las 37 BDs de estación remotas los cambios de
 * estatus (activar/desactivar) de responsables que quedaron encolados en
 * TG.dbo.responsables_sync_queue. deactivate_responsable() ya no espera a
 * estas 37 escrituras remotas para responder al usuario; esta tarea las
 * aplica en segundo plano.
 *
 * Configurar en Programador de Tareas de Windows cada 5-10 minutos:
 *   Programa:   php
 *   Argumentos: C:\ruta\AplicativoPhp\cron\responsables_sync_queue.php
 *
 * Nota: la ruta HTTP /operations/sync_responsables_queue NO sirve para el
 * cron porque el controlador solo la acepta desde CLI (ver
 * Operations::sync_responsables_queue()).
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_URI']   = '/cron/responsables_sync_queue';
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

echo "[" . date('Y-m-d H:i:s') . "] Iniciando propagación de cola de responsables\n";

// El controlador autoriza el cron por token; en CLI lo pasamos por $_GET.
$_GET['cron_token'] = CRON_SECRET;

$operations = new Operations($twig);
$operations->sync_responsables_queue(); // imprime el JSON del resultado y termina el proceso
