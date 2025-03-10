<?php

session_start();
ini_set('memory_limit', '1000M');

require_once('./phpqrcode/qrlib.php');

if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'get_mp_package':
            QRcode::png('Prueba Jajaja');
            break;

        default:
            echo '<pre>';
            var_dump("Action no valid");
            die();
            break;
    }
} else {
    echo '<pre>';
    var_dump("No entraste por GET");
    die();
}

?>