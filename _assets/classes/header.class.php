<?php

// Definimos el uso horario por defecto
// date_default_timezone_set('America/Chihuahua'); // 1 hora atras
date_default_timezone_set('America/Mexico_City'); // 1 hora adelante

// // Para desplegar la hora // //
// $dtz = new DateTimeZone("Asia/Tashkent");
// $dt = new DateTime("now", $dtz);
// $currentTime = $dt->format("Y-m-d H:i:s");
// echo '<pre>';
// var_dump($currentTime);
// die();

// Definimos el lenguaje
define('LANG', 'es');

// Creamos constantes para rutas de directorios y archivos
define('DS', DIRECTORY_SEPARATOR);
define('ROOT', getcwd().DS);


define('APP_NAME', DS);
define('ASSETS', ROOT.'_assets'.DS);
define('CLASSES', ASSETS.'classes'.DS);
define('CONTROLLERS', ASSETS.'controllers'.DS);
define('MODELS', ASSETS.'models'.DS);

// Creamos constantes para rutas de directorios y archivos basadas en URL
define('URL', 'http://192.168.0.3:400/'.APP_NAME);
define('URI', $_SERVER["REQUEST_URI"]);
define('REL_ASSETS', APP_NAME.'_assets'.DS);
define('REL_CLASSES', REL_ASSETS.'classes'.DS);
define('CSS', REL_ASSETS.'css'.DS);
define('JS', REL_ASSETS.'js'.DS);
define('PLUGINS', REL_ASSETS.'plugins'.DS);
define('IMAGES', REL_ASSETS.'images'.DS);
define('TEMPLATE', REL_ASSETS.'template'.DS);
define('VIEWS', ROOT.'views'.DS);

// Controlador por defecto / Metodo por defecto / Controlador de error por defecto
define('DEFAULT_CONTROLLER', 'home');
define('DEFAULT_METHOD', 'index');
define('DEFAULT_ERROR_CONTROLLER', 'error');
// Cargamos el archivo autoload.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

// Inicializamos el motor de plantillas
$loader = new \Twig\Loader\FilesystemLoader(ROOT);

$twig = new \Twig\Environment($loader, [
    'debug' => true,
]);

// Crear una instancia de la aplicación (en este caso, como un arreglo asociativo)
$app = [];

// Agregar variables globales a Twig
$twig->addGlobal('app', $app);

$twig->addExtension(new \Twig\Extension\DebugExtension());
$twig->addGlobal('APP_NAME', APP_NAME);
$twig->addGlobal('CSS', CSS);
$twig->addGlobal('JS', JS);
$twig->addGlobal('PLUGINS', PLUGINS);
$twig->addGlobal('IMAGES', IMAGES);
$twig->addGlobal('URL', URL);
$twig->addGlobal('URI', URI);
$twig->addGlobal('TEMPLATE', TEMPLATE);
$twig->addGlobal('REL_CLASSES', REL_CLASSES);

require('common/MySqlPdoHandler.class.php');
require('ean13.class.php');
require('Barcode.class.php');
require('extractor.php');

$MySqlHandler = MySqlPdoHandler::getInstance();
$MySqlHandler->connect('TG');
?>