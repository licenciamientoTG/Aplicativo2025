<?php
session_start();

// Lista de rutas “públicas” que no requieren login
$publicRoutes = [
    '/administration/doc_agujita',
    '/administration/close_ticket',
    '/administration/video/AGUJITA REVEAL_1.mp4',
];

// Extraemos la ruta actual (sin query string ni trailing slash)
$currentPath = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Cargamos clases y funciones necesarias
require '_assets/classes/header.class.php';
require '_assets/classes/twig_functions.php';  // aquí se inicializa $twig
require '_assets/classes/php_functions.php';
require '_assets/controllers/errors.php';

// Si no hay sesión y la ruta NO está en el whitelist, forzamos login
if (!isset($_SESSION['tg_user']) && !in_array($currentPath, $publicRoutes)) {
    if (!isset($_GET['error'])) {
        echo $twig->render('/views/login.php');
    } else {
        $error = 'Credenciales incorrectas, intentar de nuevo.';
        echo $twig->render('/views/login.php', compact('error'));
    }
    exit;
}

// Si estamos logueados, pasamos la variable tg_user a Twig
if (isset($_SESSION['tg_user'])) {
    $twig->addGlobal('tg_user', $_SESSION['tg_user']);
}

// Instanciamos manejador de errores
$error = new Errors();

// Procesamos la URL para saber controlador y método
$url = $_GET['url'] ?? '';
$url = filter_var($url, FILTER_SANITIZE_URL);
$url = explode('/', strtolower($url));

if (isset($url[0]) && $url[0] !== '') {
    $controller = $url[0];
    unset($url[0]);
} else {
    $controller = DEFAULT_CONTROLLER;
}

if (isset($url[1]) && $url[1] !== '') {
    $method = $url[1];
    unset($url[1]);
} else {
    $method = DEFAULT_METHOD;
}

// Autoload de clases, controladores y modelos
spl_autoload_register(function($class) {
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

// Si existe el archivo del controlador, lo ejecutamos; si no, 404
if (file_exists(CONTROLLERS . $controller . '.php')) {
    define('CONTROLLER', $controller . DS);
    $twig->addGlobal('CONTROLLER', CONTROLLER);

    require CONTROLLERS . $controller . '.php';
    $controllerInstance = new $controller($twig);

    if (method_exists($controllerInstance, $method)) {
        $params = array_values(empty($url) ? [] : $url);
        if (empty($params)) {
            call_user_func([$controllerInstance, $method]);
        } else {
            call_user_func_array([$controllerInstance, $method], $params);
        }
    } else {
        $error->get404();
    }
} else {
    $error->get404();
}
