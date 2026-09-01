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
    // Subir el límite de memoria a 256M solo si el actual es menor: los endpoints
    // de datasets grandes ya lo elevaron (p. ej. a 1024M) y bajárselo aquí
    // provocaba fatales de memoria justo en el json_encode final.
    $limit = ini_get('memory_limit');
    if ($limit != '-1') {
        $bytes = (int) $limit;
        switch (strtoupper(substr(trim($limit), -1))) {
            case 'G': $bytes *= 1024;
            case 'M': $bytes *= 1024;
            case 'K': $bytes *= 1024;
        }
        if ($bytes < 256 * 1024 * 1024) {
            ini_set('memory_limit', '256M');
        }
    }
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json;charset=utf-8');

    if (is_array($json)) {
        // JSON_INVALID_UTF8_SUBSTITUTE: un byte mal codificado desde sqlsrv ya no
        // aborta el encode completo (antes devolvía false => respuesta vacía).
        $json = json_encode($json, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            if (!headers_sent()) {
                http_response_code(500);
            }
            $json = json_encode(['error' => 'Error al generar la respuesta JSON: ' . json_last_error_msg()]);
        }
    }

    // Se muestra en pantalla la información del JSON ya formateada como UTF-8
    echo $json;

    // Terminamos la función
    exit();
}

/**
 * Igual que json_output() pero comprime la respuesta con gzip explícito
 * (gzencode + Content-Length exacto). Pensado para endpoints que devuelven
 * datasets grandes (varios MB). No usa zlib.output_compression porque ese
 * mecanismo corrompe la respuesta bajo IIS/FastCGI (ERR_CONTENT_DECODING_FAILED).
 */
function json_output_gzip($json) {
    if (is_array($json)) {
        $json = json_encode($json, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            if (!headers_sent()) {
                http_response_code(500);
            }
            $json = json_encode(['error' => 'Error al generar la respuesta JSON: ' . json_last_error_msg()]);
        }
    }

    // Descartar cualquier salida previa accidental (warnings, BOM): un solo byte
    // extraño delante del cuerpo comprimido invalida el gzip completo.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Access-Control-Allow-Origin: *');
    header('Content-Type: application/json;charset=utf-8');

    $client_accepts_gzip = strpos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false;
    if ($client_accepts_gzip && function_exists('gzencode')) {
        $gz = gzencode($json, 6);
        if ($gz !== false) {
            header('Content-Encoding: gzip');
            header('Vary: Accept-Encoding');
            header('Content-Length: ' . strlen($gz));
            echo $gz;
            exit();
        }
    }

    header('Content-Length: ' . strlen($json));
    echo $json;
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

// Envío de correo a través del SMTP Relay de Google Workspace (smtp-relay.gmail.com).
// El relay autoriza por IP, no por credenciales, así que aquí no hay usuario ni
// contraseña: solo EHLO + STARTTLS + envío. Esto sustituye a las tres cuentas de
// Gmail con contraseña de aplicación que se usaban antes y que topaban con el
// límite de 500 correos diarios por cuenta.
function send_mail($subject, $body, $recipients, $setFrom, $attachment1=false, $attachment2=false, &$errorOut=null): bool {

    $errorOut = null;
    $mail = new PHPMailer(true);

    try {
        // --- SMTP Relay (sin autenticación, autorizado por IP) ---
        $mail->isSMTP();
        // En producción apaga el debug. Para diagnosticar el relay: SMTP::DEBUG_SERVER.
        $mail->SMTPDebug  = SMTP::DEBUG_OFF;
        $mail->Host       = MAIL_HOST;
        $mail->Port       = MAIL_PORT;
        $mail->SMTPAuth   = false;                          // el relay no pide credenciales
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;  // 587 con STARTTLS

        // --- Codificación y HTML ---
        $mail->CharSet  = 'UTF-8';        // CLAVE: todo en UTF-8
        $mail->Encoding = 'base64';       // o 'quoted-printable'
        $mail->isHTML(true);

        // Mensajes de PHPMailer en español (opcional, para errores)
        $mail->setLanguage('es');

        // --- Remitente ---
        // El relay solo acepta remitentes del dominio de la organización. Varios
        // llamadores todavía pasan direcciones @gmail.com, que el relay rechazaría
        // con "Mail relay denied", así que esas se reescriben a la cuenta oficial y
        // la original se conserva como Reply-To para no perder a quién responder.
        $from = trim((string)$setFrom);
        if ($from === '' || !str_ends_with(strtolower($from), '@' . MAIL_ALLOWED_DOMAIN)) {
            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($from);
            }
        } else {
            $mail->setFrom($from, MAIL_FROM_NAME);
        }

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

        $sent = $mail->send();
        if (!$sent) {
            $errorOut = $mail->ErrorInfo ?: 'PHPMailer devolvió false sin detalle.';
        }
        return $sent;

    } catch (Exception $e) {
        // Capturar el detalle real para que el caller pueda mostrarlo.
        $errorOut = $mail->ErrorInfo ?: $e->getMessage();
        error_log("Mailer Error: {$errorOut}");
        return false;
    }
}

// Antes existían tres implementaciones (send_mail/send_mail2/send_mail3), una por
// cada cuenta de Gmail, encadenadas para sortear el límite de 500 correos diarios.
// Con el SMTP Relay ese límite desaparece y la cadena ya no tiene sentido: las tres
// se conservan solo como alias para no romper los llamadores existentes.
function send_mail2($subject, $body, $recipients, $setFrom, $attachment1=false, $attachment2=false, &$errorOut=null): bool {
    return send_mail($subject, $body, $recipients, $setFrom, $attachment1, $attachment2, $errorOut);
}

function send_mail3($subject, $body, $recipients, $setFrom, $attachment1=false, $attachment2=false, &$errorOut=null): bool {
    return send_mail($subject, $body, $recipients, $setFrom, $attachment1, $attachment2, $errorOut);
}

// Alias histórico. Ya no hay cadena de respaldo que ejecutar: reintentar el mismo
// relay con otro remitente no cambiaría el resultado, así que un fallo aquí es un
// fallo real (red, IP no autorizada o remitente rechazado) y debe verse en el log.
function send_mail_with_fallback($subject, $body, $recipients, $setFrom, $attachment1=false, $attachment2=false, &$errorOut=null): bool {
    return send_mail($subject, $body, $recipients, $setFrom, $attachment1, $attachment2, $errorOut);
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
