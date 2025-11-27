<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function sql() {
    $MySqlHandler = MySqlPdoHandler::getInstance();
    $MySqlHandler->connect('TG');
    return $MySqlHandler;
}

function connect_bd() {
    try {
        $conn =  new PDO("sqlsrv:Server=192.168.0.6;Database=TG;TrustServerCertificate=yes", 'cguser', 'sahei1712');
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $conn;
    } catch (PDOException $e) {
        die("Error de conexión: " . $e->getMessage());
    }
}

function json_output($json) {
    ini_set('memory_limit', '256M');
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json;charset=utf-8');

    if (is_array($json)) {
        $json = json_encode($json);
    }

    // Se muestra en pantalla la información del JSON ya formateada como UTF-8
    echo $json;

    // Terminamos la función
    exit();
}

function json_modal($title, $html) {

    $json = [
        'title' => $title,
        'html'  => $html
    ];

    // Se muestra en pantalla la información del JSON ya formateada como UTF-8
    echo json_encode($json);

    // Terminamos la función
    exit();
}

// Subject: Bienvenido
// Body: The HTML
// Recipents = ['aaguirre@totalgas.com','acarrasco@totalgas.com','aochoa@totalgas.com','customerservice@totalgas.com','lcoronel@totalgas.com','dfong@totalgas.com','jfong@totalgas.com'];
// CCAddress = ['hcastorena@totalgas.com'];
// SetFrom: 'corsys@totalgas.com'
function send_mail($subject, $body, $recipients, $setFrom, $attachment1=false, $attachment2=false): bool {

    $mail = new PHPMailer(true);

    try {
        // --- SMTP Gmail ---
        $mail->isSMTP();
        // En producción apaga el debug:
        $mail->SMTPDebug = SMTP::DEBUG_OFF;               // antes: DEBUG_SERVER
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        // TLS (587) o SMTPS (465). Con Gmail cualquiera; dejo SMTPS:
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;   // antes: 'ssl' (string)
        $mail->Port       = 465;

       // $mail->Username   = 'totalgasdesarrollo@gmail.com';
       // $mail->Password   = 'bdppgxrwzhmyfrmf';

        $mail->Username   = 'no-reply@totalgas.com';
        $mail->Password   = 'sysdhepknmlkigbs';

        // --- Codificación y HTML ---
        $mail->CharSet  = 'UTF-8';        // CLAVE: todo en UTF-8
        $mail->Encoding = 'base64';       // o 'quoted-printable'
        $mail->isHTML(true);

        // Mensajes de PHPMailer en español (opcional, para errores)
        $mail->setLanguage('es');

        // --- Remitente ---
        // ¡Quita la conversión a ISO-8859-1!
        $mail->setFrom($setFrom, 'TotalGas | Sistema de Gestión de correos');

        // --- Destinatarios ---
        // No agregues dos veces el primero; sólo recorre el arreglo
        foreach ((array)$recipients as $to) {
            if ($to) { $mail->addAddress(trim($to)); }
        }

        // --- Asunto y cuerpo (UTF-8) ---
        $mail->Subject = (string)$subject;           // Puede llevar acentos y ñ
        $mail->Body    = (string)$body;              // HTML permitido
        $mail->AltBody = strip_tags((string)$body);  // texto plano de respaldo

        // --- Adjuntos ---
        if ($attachment1) { $mail->addAttachment($attachment1); }
        if ($attachment2) { $mail->addAttachment($attachment2); }

        // (Opcional) Asegura el contexto interno a UTF-8
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        return $mail->send();

    } catch (Exception $e) {
        // Si estás devolviendo JSON desde el endpoint, no hagas echo aquí.
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

function send_mail2($subject, $body, $recipients, $setFrom, $attachment1=false, $attachment2=false): bool {

    $mail = new PHPMailer(true);

    try {
        // --- SMTP Gmail ---
        $mail->isSMTP();
        // En producción apaga el debug:
        $mail->SMTPDebug = SMTP::DEBUG_OFF;               // antes: DEBUG_SERVER
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        // TLS (587) o SMTPS (465). Con Gmail cualquiera; dejo SMTPS:
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;   // antes: 'ssl' (string)
        $mail->Port       = 465;

        $mail->Username   = 'no-reply@totalgas.com';
        $mail->Password   = 'sysdhepknmlkigbs';

        // --- Codificación y HTML ---
        $mail->CharSet  = 'UTF-8';        // CLAVE: todo en UTF-8
        $mail->Encoding = 'base64';       // o 'quoted-printable'
        $mail->isHTML(true);

        // Mensajes de PHPMailer en español (opcional, para errores)
        $mail->setLanguage('es');

        // --- Remitente ---
        // ¡Quita la conversión a ISO-8859-1!
        $mail->setFrom($setFrom, 'TotalGas | Sistema de Gestión de correos');

        // --- Destinatarios ---
        // No agregues dos veces el primero; sólo recorre el arreglo
        foreach ((array)$recipients as $to) {
            if ($to) { $mail->addAddress(trim($to)); }
        }

        // --- Asunto y cuerpo (UTF-8) ---
        $mail->Subject = (string)$subject;           // Puede llevar acentos y ñ
        $mail->Body    = (string)$body;              // HTML permitido
        $mail->AltBody = strip_tags((string)$body);  // texto plano de respaldo

        // --- Adjuntos ---
        if ($attachment1) { $mail->addAttachment($attachment1); }
        if ($attachment2) { $mail->addAttachment($attachment2); }

        // (Opcional) Asegura el contexto interno a UTF-8
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        return $mail->send();

    } catch (Exception $e) {

        var_dump($mail->ErrorInfo);var_dump($e);
        die();
        // Si estás devolviendo JSON desde el endpoint, no hagas echo aquí.
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

function dateToInt($date) {

    $referenceDate = new DateTime('1900-01-01');
    $inputDate = new DateTime($date);

    $daysDifference = $inputDate->diff($referenceDate)->days + 1;

    return $daysDifference;
}

function intToDate($daysDifference) {
    $daysDifference -= 1;
    $referenceDate = new DateTime('1900-01-01');
    $referenceDate->modify("+$daysDifference days");

    return $referenceDate->format('Y-m-d');
}


function redirect($to = null) {
    if (!headers_sent()) {
        if (is_null($to)) {
            if (isset($_SERVER['HTTP_REFERER'])) {
                header('Location: ' . $_SERVER['HTTP_REFERER']);
            } else {
                header('Location: /'); // Redirigir a la página principal si no hay referer
            }
        } else {
            header('Location: ' . $to);
        }
        exit();
    }
}

// Función para establecer un mensaje flash
function setFlashMessage($type, $message) {
    $_SESSION['flash'][$type] = $message;
}

// Función para saber si una asesion tiene determinados permisos
function authorized($permission_id) : bool {
    return (in_array($permission_id, explode(",", $_SESSION['tg_user']['permissions']))) ? true : false ;
}

function binnacle_register($user_id, $action, $description, $ip_address, $controller, $function_name) {
    $conn = connect_bd(); // Conexión a la base de datos

    // Insertar en la bitácora
    $sql = "INSERT INTO tg_binnacle (user_id, action, description, ip_address, controller, function_name) VALUES (?, ?, ?, ?, ?, ?)";
    $params = array($user_id, $action, $description, $ip_address, $controller, $function_name);

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        // Puedes agregar aquí manejo de errores o mensajes de éxito si lo deseas
    } catch (PDOException $e) {
        die("Error al insertar en la bitácora: " . $e->getMessage());
    }

    // Cierre de la conexión
    $conn = null;
}

function binnacle_register_prices($user_id, $action, $description, $ip_address, $controller, $function_name) {
    $conn = connect_bd(); // Conexión a la base de datos

    // Insertar en la bitácora
    $sql = "INSERT INTO tg_binnacle_prices (user_id, action, description, ip_address, controller, function_name) VALUES (?, ?, ?, ?, ?, ?)";
    $params = array($user_id, $action, $description, $ip_address, $controller, $function_name);

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        // Puedes agregar aquí manejo de errores o mensajes de éxito si lo deseas
    } catch (PDOException $e) {
        die("Error al insertar en la bitácora: " . $e->getMessage());
    }

    // Cierre de la conexión
    $conn = null;
}

function get_invoice_series($invoice_number) {
    $series_map = [
        2300000000 => 'Z',
        2000000000 => 'T',
        1900000000 => 'K',
        1800000000 => 'J',
        1700000000 => 'I',
        1600000000 => 'H',
        1500000000 => 'G',
        1400000000 => 'F',
        1300000000 => 'E',
        1200000000 => 'D',
        1100000000 => 'C',
        1000000000 => 'B',
    ];

    foreach ($series_map as $limit => $serie) {
        if ($invoice_number > $limit) {
            $restante = $invoice_number - $limit;
            return $serie . '-' . $restante;
        }
    }

    return $invoice_number; // Maneja el caso en el que el número de factura no encaja en ningún rango
}

function generarMensajeEstacion($estacion) {
    return "{$estacion['Estacion']} ({$estacion['Producto']} a \$" . number_format($estacion['Precio'], 2, '.', ','). " a las {$estacion['Hora']} Hrs)";
}
