<?php
session_start();
ini_set('memory_limit', '1000M');
require('header.class.php');
require_once('php_functions.php');
require_once('code128.php');

// Esta función sirve para hacer un REQUIRE a todos los archivos que se encuentren en la carpeta _assets/classes y no tener que cargarlos uno por uno
spl_autoload_register(function($class) {
    // Si un modelo es instanciado y el archivo existe
    if (file_exists('../models/'.$class.'.php')) {
        require('../models/'.$class.'.php');
    }
});

class PDF extends FPDF {
    public $sql;
    // Constructor
    public function __construct($sql) {
        parent::__construct();
        $this->sql = $sql;
    }
}

if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'some_action':
            // Aqui va la logica del PDF
            break;

    }
}
?>