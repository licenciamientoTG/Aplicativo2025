<?php
session_start();
session_destroy();
unset($_SESSION['tg_user']);
echo json_encode(array('status' => 'success', 'message' => 'Logged out'));
?>