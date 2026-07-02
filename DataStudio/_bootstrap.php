<?php
if (!defined('DATASTUDIO_ENTRY')) {
    http_response_code(404);
    exit;
}
// Bootstrap standalone para la API de DataStudio. No depende de
// _assets/classes/header.class.php ni del autoloader de la app: solo
// carga la clase de conexión a BD directamente.

date_default_timezone_set('America/Mazatlan');

// Mismo log de errores que fija header.class.php (logs/php_errors.log en la
// raíz de la app): DataStudio no pasa por index.php.
$appLogDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($appLogDir)) { @mkdir($appLogDir, 0775, true); }
ini_set('log_errors', '1');
ini_set('error_log', $appLogDir . DIRECTORY_SEPARATOR . 'php_errors.log');

define('DATASTUDIO_API_KEY', 'TG_DATASTUDIO_2026_Hf83Kx01Qz');

require dirname(__DIR__) . '/_assets/classes/common/MySqlPdoHandler.class.php';

MySqlPdoHandler::getInstance()->connect('TG');
