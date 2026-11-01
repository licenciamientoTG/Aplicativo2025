<?php
// Cookie de sesión endurecida: httponly impide que JavaScript la lea (mitiga
// robo de sesión vía XSS) y samesite=Lax que viaje en peticiones cross-site.
// No se usa 'secure' porque el sitio corre sobre http, no https.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
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
require '_assets/classes/Router.class.php';
Router::load(require 'routes.php');

// Ruta del router explícito (si la hay); se evalúa antes del login para respetar public: true
$routePath  = $_GET['url'] ?? ltrim($currentPath, '/');
$routeMatch = Router::match($routePath, $_SERVER['REQUEST_METHOD']);
$routeIsPublic = isset($routeMatch['route']) && $routeMatch['route']['public'];

// Si no hay sesión y la ruta NO está en el whitelist, forzamos login
if (!isset($_SESSION['tg_user']) && !$routeIsPublic && !in_array($currentPath, $publicRoutes)) {
    // Las peticiones AJAX (DataTables, $.ajax, fetch con Accept json) reciben
    // 401 con JSON en lugar del HTML del login con status 200: así el JS puede
    // detectar la sesión expirada y redirigir, en vez de mostrar el error
    // genérico de "no existen registros".
    $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
           || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        if ($currentPath === '/tableros_api' || str_starts_with($currentPath, '/tableros_api/')
            || $currentPath === '/tableros-api' || str_starts_with($currentPath, '/tableros-api/')) {
            echo json_encode([
                'success' => false,
                'code' => 'unauthenticated',
                'message' => 'Sesión expirada. Vuelve a iniciar sesión.',
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['error' => 'Sesión expirada. Vuelva a iniciar sesión.']);
        }
        exit;
    }

    if (!isset($_GET['error'])) {
        echo $twig->render('/views/login.html');
    } else {
        $error = 'Credenciales incorrectas, intentar de nuevo.';
        echo $twig->render('/views/login.html', compact('error'));
    }
    exit;
}

// Si estamos logueados, pasamos la variable tg_user a Twig
if (isset($_SESSION['tg_user'])) {
    $twig->addGlobal('tg_user', $_SESSION['tg_user']);
    if (empty($_SESSION['tableros_csrf']) || !is_string($_SESSION['tableros_csrf'])) {
        $_SESSION['tableros_csrf'] = bin2hex(random_bytes(32));
    }
    $twig->addGlobal('tableros_csrf', $_SESSION['tableros_csrf']);
    $twig->addGlobal('routes_json', json_encode((object)Router::export(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES));
}

// Instanciamos manejador de errores
$error = new Errors();

// Autoload de clases, controladores y modelos
require '_assets/classes/autoload.php';

// 1) Router explícito (routes.php)
if ($routeMatch !== null) {
    if (isset($routeMatch['error'])) {
        http_response_code($routeMatch['error']);
        header('Allow: ' . implode(', ', $routeMatch['allowed']));
        echo 'Método no permitido';
        exit;
    }

    $def = $routeMatch['route'];
    if ($def['permission'] !== null && !authorized($def['permission'])) {
        http_response_code(403);
        echo 'No tienes permiso para acceder a esta sección';
        exit;
    }

    // Estas consultas de la conciliación se lanzan juntas desde el navegador.
    // Liberar el lock de sesión tras autenticar permite que PHP las procese en
    // paralelo, en vez de hacer que cada petición espere a la anterior. Se
    // limita a endpoints GET que no modifican la sesión ni escriben datos.
    $parallelReadMethods = [
        'efc_conc_grupos_activos',
        'efc_conc_transitos',
        'efc_conc_analiticos_bandeja',
        'efc_conc_tipo_cambio_turnos',
        'get_cash_banorte_deposits',
    ];
    if ($_SERVER['REQUEST_METHOD'] === 'GET'
        && in_array($def['handler'][1], $parallelReadMethods, true)
        && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if ($def['is_alias']) {
        RouteLog::write(ROOT . 'logs' . DS . 'legacy_routes.log', 'alias', $_SERVER['REQUEST_METHOD'], $routePath);
    }

    [$class, $method] = $def['handler'];
    $controller = strtolower($class);
    define('CONTROLLER', $controller . DS);
    $twig->addGlobal('CONTROLLER', $controller);
    $twig->trackController = $controller;
    $twig->trackMethod     = $method;

    $controllerInstance = new $class($twig);
    call_user_func_array([$controllerInstance, $method], $routeMatch['args']);
    exit;
}

// 2) Convención antigua /controlador/método/params
if (!ROUTER_LEGACY_FALLBACK) {
    $error->get404();
    exit;
}

RouteLog::write(ROOT . 'logs' . DS . 'legacy_routes.log', 'fallback', $_SERVER['REQUEST_METHOD'], $routePath);

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

// Whitelist del nombre del controlador: solo letras, números y guion bajo.
// Sin esto, un segmento como "..\clases\archivo" pasa el file_exists de abajo
// y ejecutaría cualquier .php dentro del proyecto (FILTER_SANITIZE_URL
// permite puntos y barras invertidas).
if (!preg_match('/^[a-z][a-z0-9_]*$/', $controller)) {
    $error->get404();
    exit;
}

// Si existe el archivo del controlador, lo ejecutamos; si no, 404
if (file_exists(CONTROLLERS . $controller . '.php')) {
    define('CONTROLLER', $controller . DS);
    $twig->addGlobal('CONTROLLER', $controller);
    $twig->trackController = $controller;
    $twig->trackMethod     = $method;

    require CONTROLLERS . $controller . '.php';
    $controllerInstance = new $controller($twig);

    if (method_exists($controllerInstance, $method)) {
        $params = array_values($url);
        call_user_func_array([$controllerInstance, $method], $params);
    } else {
        $error->get404();
    }
} else {
    $error->get404();
}
