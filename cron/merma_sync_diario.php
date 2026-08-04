<?php
/**
 * Tarea programada: sincronización diaria del snapshot de merma, cubriendo
 * el mes en curso completo (día 1 -> ayer) para no dejar huecos si algún
 * día falla. Equivale al botón "Actualizar datos" de /merma/analisis para
 * todas las estaciones. Consulta ApiER en paralelo y reemplaza
 * TG.dbo.merma_diaria.
 *
 * Configurar en Programador de Tareas de Windows a las 06:00 AM:
 *   Programa:   php
 *   Argumentos: C:\ruta\AplicativoPhp\cron\merma_sync_diario.php
 *
 * Nota: la ruta HTTP /merma/sync_diario NO sirve para el cron porque
 * index.php exige sesión antes de despachar al controlador.
 */

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_URI']   = '/cron/merma_sync_diario';
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

echo "[" . date('Y-m-d H:i:s') . "] Iniciando sincronización de merma diaria\n";

// El controlador autoriza el cron por token; en CLI lo pasamos por $_GET.
$_GET['cron_token'] = CRON_SECRET;

$merma = new Merma($twig);
$merma->sync_diario(); // imprime el JSON del resultado y termina el proceso
