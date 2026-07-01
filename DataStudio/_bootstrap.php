<?php
// Bootstrap standalone para la API de DataStudio. No depende de
// _assets/classes/header.class.php ni del autoloader de la app: solo
// carga la clase de conexión a BD directamente.

date_default_timezone_set('America/Mazatlan');

define('DATASTUDIO_API_KEY', 'TG_DATASTUDIO_2026_Hf83Kx01Qz');

require dirname(__DIR__) . '/_assets/classes/common/MySqlPdoHandler.class.php';

MySqlPdoHandler::getInstance()->connect('TG');
