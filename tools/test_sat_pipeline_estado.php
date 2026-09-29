<?php
// Uso: php tools/test_sat_pipeline_estado.php  (solo lectura)
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_URI']   = '/it/llamadas_sat_estado';
chdir($_SERVER['DOCUMENT_ROOT']);
require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';
spl_autoload_register(function ($c) {
    foreach ([CLASSES . $c . '.class.php', CONTROLLERS . strtolower($c) . '.php', MODELS . $c . '.php'] as $f) if (file_exists($f)) require $f;
});
$_SESSION['tg_user'] = ['Id' => 6296];   // usuario de SAT_USERS
ob_start();
register_shutdown_function(function () {
    $json = json_decode(ob_get_clean(), true);
    $t = $json['tarjetas'] ?? [];
    $okAll = ($json['success'] ?? false)
        && isset($t['peticiones']['semaforo'], $t['zip']['semaforo'], $t['xml']['semaforo'], $t['importado']['semaforo']);
    print_r($json);
    echo $okAll ? "\nOK estructura\n" : "\nFAIL estructura\n";
});
(new It($twig))->llamadas_sat_estado();
