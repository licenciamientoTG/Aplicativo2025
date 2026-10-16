<?php

// Definimos el uso horario por defecto
date_default_timezone_set('America/Ciudad_Juarez'); // Horario local de Ciudad Juárez

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

// Log de errores PHP en una ruta propia y estable de la aplicación (en vez de
// depender del php.ini de cada máquina). Aquí cae todo lo que se manda a
// error_log(), incluidos los errores de BD de MySqlPdoHandler. Se consulta
// desde la interfaz en /it/error_log o directamente en logs/php_errors.log.
if (!is_dir(ROOT . 'logs')) {
    @mkdir(ROOT . 'logs', 0775, true);
}
ini_set('log_errors', '1');
ini_set('error_log', ROOT . 'logs' . DS . 'php_errors.log');

// --- Correo saliente: SMTP Relay de Google Workspace ---
// El relay autoriza por IP (la IP pública de la oficina/servidor está dada de alta
// en la consola de administración de Google), por eso NO lleva usuario ni contraseña.
// A cambio, solo acepta remitentes del dominio de la organización: cualquier
// dirección fuera de MAIL_ALLOWED_DOMAIN es reescrita por send_mail() a MAIL_FROM
// y conservada como Reply-To.
define('MAIL_HOST',           'smtp-relay.gmail.com');
define('MAIL_PORT',           587);                  // STARTTLS
define('MAIL_FROM',           'no-reply@totalgas.com');
define('MAIL_FROM_NAME',      'TotalGas | Sistema de Gestión de correos');
define('MAIL_ALLOWED_DOMAIN', 'totalgas.com');

// Controlador por defecto / Metodo por defecto / Controlador de error por defecto
define('CRON_SECRET', 'TG_CRON_2024');
define('DEFAULT_CONTROLLER', 'home');
define('DEFAULT_METHOD', 'index');
// true = lo que no esté en routes.php se resuelve por /controlador/método (convención antigua)
define('ROUTER_LEGACY_FALLBACK', true);
define('DEFAULT_ERROR_CONTROLLER', 'error');
// Clave de cifrado simétrico para secretos guardados en BD (ej. password de
// la FIEL de Petrotal en PetrotalFielConfig). AES-256-CBC vía openssl_*,
// nativo de PHP, sin dependencias nuevas.
define('PETROTAL_FIEL_ENCRYPTION_KEY', 'TG_Petrotal_FIEL_2026_ChangeMe!');
// IA (Gemini) para el análisis ejecutivo de /merma/ventas. La llave real NO
// se versiona: vive en _assets/classes/gemini_local_config.php (gitignored),
// que debe hacer define('GEMINI_API_KEY', '...') antes de llegar aquí.
// Sin ese archivo, queda vacía y el botón responde "falta configurar".
$gemini_local_config = __DIR__ . DS . 'gemini_local_config.php';
if (is_file($gemini_local_config)) {
    require_once $gemini_local_config;
}
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', '');
}
define('GEMINI_MODEL', 'gemini-3.5-flash-lite'); // 3.8-flash: más capaz, pero hoy (14-10-2026) daba "high demand"
// Cargamos el archivo autoload.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

// Inicializamos el motor de plantillas
$loader = new \Twig\Loader\FilesystemLoader(ROOT);

require_once __DIR__ . '/TgTwig.class.php';
$twig = new TgTwig($loader, [
    // debug en false evita exponer información con dump() y permite a Twig
    // optimizar; poner en true solo para depurar en desarrollo.
    'debug' => false,
    // Caché de plantillas compiladas: sin esto Twig recompila cada plantilla
    // en cada petición. Con auto_reload solo recompila si el .html cambió.
    'cache' => ROOT . 'temp' . DS . 'twig',
    'auto_reload' => true,
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
