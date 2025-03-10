<?php
    session_start();
    require_once $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
    binnacle_register($_SESSION['tg_user']['Id'], 'logout', 'Cierre de sesión', $_SERVER['REMOTE_ADDR'], 'logout', 'logout');
    session_destroy();
    unset($_SESSION['tg_user']);
    header('location: /index.php');
?>