<?php
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
        session_start();
        $_SESSION['tg_user'] = $info_usuario[0];

        if (in_array('41', explode(',', $_SESSION['tg_user']['permissions']))) {
            binnacle_register($_SESSION['tg_user']['Id'], 'Login', 'Inicio de sesión', $_POST['ip'], 'Login', 'Login');
        }

        if (in_array('42', explode(',', $_SESSION['tg_user']['permissions']))) {
            binnacle_register_prices($_SESSION['tg_user']['Id'], 'Login', 'Inicio de sesión', $_POST['ip'], 'Login', 'Login');
        }


        $redirectRoute = $_POST['route'] != "/index.php" ? $_POST['route'] : 'home/index';
        header("Location: /$redirectRoute");
        die();
    } else {
        header('Location: /?error=bad_user');
        die();
    }