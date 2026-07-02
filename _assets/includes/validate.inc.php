<?php
    // Mismo log de errores que fija header.class.php (logs/php_errors.log en la
    // raíz de la app): este script recibe el POST del login directamente, sin
    // pasar por index.php, así que hay que fijarlo también aquí.
    $appLogDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($appLogDir)) { @mkdir($appLogDir, 0775, true); }
    ini_set('log_errors', '1');
    ini_set('error_log', $appLogDir . DIRECTORY_SEPARATOR . 'php_errors.log');

    require_once('../classes/common/MySqlPdoHandler.class.php');
    require_once('../classes/php_functions.php');

    $MySqlHandler = MySqlPdoHandler::getInstance();
    $MySqlHandler->connect('TG');
    
    // Suponiendo que tienes una conexión válida en $this->_connection
    if ($info_usuario = $MySqlHandler->executeStoredProcedure("sp_usuario_login", array('Usuario'   => $_POST['username'], 'Password'  => $_POST['password']))) {
        // if ($info_usuario[0]['remote'] != "1" ) {

        //     if ( !in_array($_POST['ip'], ['201.174.170.235', '200.76.161.50', '201.77.108.246', '45.174.79.124', '187.190.161.182', '187.190.236.20', '186.96.26.143', '189.239.96.67', '189.239.70.61'])) {
        //         session_destroy();
        //         unset($_SESSION['tg_user']);
        //         header('Location: /?error=no_remote');
        //         die();
        //     }
        // }
        
        // Ahora vamos a recolectar los permisos del usuario logueado
        $permissions = $MySqlHandler->select("SELECT permission_id FROM [TG].[dbo].[tg_permissions_users] WHERE user_id = ?;", [$info_usuario[0]['Id']]);

        // Extraer los valores de la columna permission_id y eliminar los espacios en blanco
        $ids = array_map("trim", array_column($permissions, "permission_id"));

        // Unir los valores con una coma
        $permissions_string = implode(",", $ids);

        $info_usuario[0]['permissions'] = $permissions_string;
        $info_usuario[0]['profile'] = $MySqlHandler->select('SELECT * FROM [TG].[dbo].[Perfil] WHERE Id = ?', [$info_usuario[0]['IdPerfil']])[0]['Nombre'];
        $info_usuario[0]['FechaRegistro'] = $MySqlHandler->select('SELECT * FROM [TG].[dbo].[Perfil] WHERE Id = ?', [$info_usuario[0]['IdPerfil']])[0]['FechaRegistro'];

        // Tambien agregamos la estación del usuario
        if ($estacion = $MySqlHandler->executeStoredProcedure("sp_consulta_usuario_estacion", array('IdUsuario' => $info_usuario[0]['Id']))) {
            $info_usuario[0]['IdEstacion'] = $estacion[0]['IdEstacion'];
            $info_usuario[0]['Estacion'] = $estacion[0]['Estacion'];
        }

        // Aqui vamos a hacer una consulta para obtener los permisos de cada usuario
        // Mismos atributos de cookie que en index.php: este script recibe el POST
        // del login directamente, así que también puede ser quien cree la cookie.
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
        session_start();
        $_SESSION['tg_user'] = $info_usuario[0];

        if (in_array('41', explode(',', $_SESSION['tg_user']['permissions']))) {
            binnacle_register($_SESSION['tg_user']['Id'], 'Login', 'Inicio de sesión', $_POST['ip'], 'Login', 'Login');
        }

        if (in_array('42', explode(',', $_SESSION['tg_user']['permissions']))) {
            binnacle_register_prices($_SESSION['tg_user']['Id'], 'Login', 'Inicio de sesión', $_POST['ip'], 'Login', 'Login');
        }

        /* -------------------------------------------------------------------------- */
        /* LÓGICA DE REDIRECCIÓN                             */
        /* -------------------------------------------------------------------------- */
        
        // 1. Obtenemos la ruta enviada por el formulario (inyectada por JS)
        $redirectRoute = isset($_POST['route']) ? trim($_POST['route']) : '';

        // 2. Filtramos rutas que NO queremos redigir (ej. la pagina de login misma o vacía)
        $ignoredRoutes = [
            '',
            '/',
            '/index.php',
            '/views/login.php', // ruta vieja, por si algún navegador la tiene cacheada en JS
            '/views/login.html'
        ];

        // 3. Verificamos: si la ruta contiene el archivo de validación o está en la lista de ignorados, forzamos home
        if (empty($redirectRoute) || in_array($redirectRoute, $ignoredRoutes) || strpos($redirectRoute, 'validate.inc.php') !== false) {
            $redirectRoute = '/home/index';
        }

        // 4. Aseguramos que la ruta tenga el slash inicial para el header Location
        if (substr($redirectRoute, 0, 1) !== '/') {
            $redirectRoute = '/' . $redirectRoute;
        }

        header("Location: " . $redirectRoute);
        die();

    } else {
        header('Location: /?error=bad_user');
        die();
    }