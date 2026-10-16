<?php
// Autoload de clases, controladores y modelos.
// Requiere las constantes CLASSES, CONTROLLERS y MODELS (header.class.php las define).
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
