<?php
session_start();
require('_assets/classes/header.class.php');
require('_assets/classes/twig_functions.php');
require('_assets/classes/php_functions.php');
require('_assets/controllers/errors.php');

if (!isset($_SESSION['tg_user'])) {
    if (!isset($_GET['error'])) {
        echo $twig->render('/views/login.php');
    } else {
        $error = 'Credenciales incorrectas, intentar de nuevo.';
        echo $twig->render('/views/login.php', compact('error'));
    }
} else {
    // Pasamos la variable user como global
    $twig->addGlobal('tg_user', $_SESSION['tg_user']);
    // Creamos la instancia de la clase Error
    $error = new Errors();
    // Inicializamos la url
    $url = $_GET['url'] ?? array();
    // Separamos la URL por cada slash y lo convertimos en un arreglo
    $url = filter_var($url, FILTER_SANITIZE_URL);
    $url = explode('/', strtolower($url));
    // Vemos si existe un controlador
    if (isset($url[0]) AND $url[0]!='') {
        $controller = $url[0];
        unset($url[0]);
    } else {
        $controller = DEFAULT_CONTROLLER;
    }

    // Vemos si existe un método
    if (isset($url[1]) AND $url[1]!='') {
        $method = $url[1];
        unset($url[1]);
    } else {
        $method = DEFAULT_METHOD;
    }

    // Esta función sirve para hacer un REQUIRE a todos los archivos que se encuentren en la carpeta _assets/classes y no tener que cargarlos uno por uno
    spl_autoload_register(function($class) {
        // Si una clase es instanciada y el archivo existe
        if (file_exists(CLASSES.$class.'.class.php')) {
            require(CLASSES.$class.'.class.php');
        }
        // Si un controlador es instanciada y el archivo existe
        if (file_exists(CONTROLLERS.strtolower($class).'.php')) {
            require(CONTROLLERS.strtolower($class).'.php');
        }
        // Si un modelo es instanciado y el archivo existe
        if (file_exists(MODELS.$class.'.php')) {
            require(MODELS.$class.'.php');
        }
    });

    if (file_exists(CONTROLLERS.$controller.'.php')) {
        // Variable global para controlador
        define('CONTROLLER', $controller.DS);
        $twig->addGlobal('CONTROLLER', CONTROLLER);

        require(CONTROLLERS.$controller.'.php');
        // Instancia los controladores

        $controller = new $controller($twig);
        // Comprueba si la variable $method está seteada
        if (isset($method)) {
            // Comprueba se el metodo existe en el controlador
            if (method_exists($controller,$method)) {
                $params = array_values(empty($url) ? [] : $url);
                if (empty($params)) {
                    call_user_func([$controller,$method]);
                } else {
                    call_user_func_array([$controller,$method],$params);
                }
            } else {
                $error->get404();
            }
        } else {
            $error->get404();
        }
    } else {
        $error->get404();
    }
}