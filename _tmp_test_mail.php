<?php
// Prueba aislada de send_mail() — mismas credenciales y config que php_functions.php
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->SMTPDebug  = SMTP::DEBUG_SERVER; // verbose para diagnóstico
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;
    $mail->Username   = 'no-reply@totalgas.com';
    $mail->Password   = 'sysdhepknmlkigbs';
    $mail->CharSet    = 'UTF-8';
    $mail->isHTML(true);
    $mail->setFrom('no-reply@totalgas.com', 'TotalGas | Prueba diagnóstico');
    $mail->addAddress('alejandro.martinez@totalgas.com');
    $mail->Subject = 'Prueba diagnóstico send_to_payments ' . date('Y-m-d H:i:s');
    $mail->Body    = '<p>Prueba de SMTP con la misma configuración de send_mail().</p>';
    $ok = $mail->send();
    echo "\nRESULTADO: " . ($ok ? 'ENVIADO OK' : 'FALLO') . "\n";
} catch (Exception $e) {
    echo "\nEXCEPCION: {$mail->ErrorInfo}\n";
}
