<?php
/**
 * Tarea programada: alerta de turno 4 sin cerrar en ControlGas. SOLO LECTURA.
 *
 * Revisa el turno 4 (nrotur 41) de AYER en todas las estaciones con ControlGas.
 * Un turno está cerrado si hay lecturas electrónicas en Medicion (misma regla
 * que el reporte /operations/corte-t4/, ver TabulatorModel::turn4_closure()).
 * Si encuentra turnos abiertos o estaciones que no respondieron, envía un correo.
 * Si todo está cerrado, no envía nada.
 *
 * Configurar en el Programador de tareas de Windows, diario a las 00:05:
 *   Programa:   php
 *   Argumentos: C:\ruta\AplicativoPhp\cron\cierre_t4_alerta.php
 *
 * Pruebas desde consola:
 *   php cron/cierre_t4_alerta.php --dry-run                  revisa y muestra el resultado, NO envía
 *   php cron/cierre_t4_alerta.php --dry-run --fecha=2026-11-21
 */

// Destinatarios de la alerta.
const CIERRE_T4_DESTINATARIOS = [
    'aldo.ochoa@totalgas.com',
];

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REQUEST_URI']   = '/cron/cierre_t4_alerta';
chdir($_SERVER['DOCUMENT_ROOT']);

require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';

spl_autoload_register(function ($class) {
    if (file_exists(CLASSES . $class . '.class.php')) {
        require CLASSES . $class . '.class.php';
    }
    if (file_exists(MODELS . $class . '.php')) {
        require MODELS . $class . '.php';
    }
});

set_time_limit(0);

$options = getopt('', ['dry-run', 'fecha:']);
$dryRun  = isset($options['dry-run']);
$fecha   = $options['fecha'] ?? date('Y-m-d', strtotime('-1 day'));
$date    = DateTime::createFromFormat('Y-m-d', $fecha);
if (!$date || $date->format('Y-m-d') !== $fecha) {
    fwrite(STDERR, "Fecha no válida: {$fecha} (usa AAAA-MM-DD)\n");
    exit(2);
}

$log = fn(string $msg) => print('[' . date('Y-m-d H:i:s') . "] {$msg}\n");
$log("Revisando cierre del turno 4 del {$fecha}" . ($dryRun ? ' (dry-run, sin envío)' : ''));

$tabulatorModel  = new TabulatorModel();
$estacionesModel = new EstacionesModel();
$stations = $tabulatorModel->turn4_stations($estacionesModel->get_actives_stations() ?: []);

$abiertos = [];
$sinConexion = [];
$cerrados = 0;
foreach ($stations as $station) {
    $closure = $tabulatorModel->turn4_closure($station['codgas'], $fecha);
    if ($closure === false) {
        // Un reintento: a esta hora un enlace puede tardar en responder.
        $closure = $tabulatorModel->turn4_closure($station['codgas'], $fecha);
    }

    if ($closure === false) {
        $sinConexion[] = $station;
        $log("  {$station['nombre']}: sin conexión");
    } elseif ($closure['estado'] === 'cerrado') {
        $cerrados++;
        $log("  {$station['nombre']}: cerrado {$closure['cierre']}");
    } else {
        $abiertos[] = $station + $closure;
        $log("  {$station['nombre']}: ABIERTO ({$closure['despachos']} despachos)");
    }
}

$log(sprintf('Resultado: %d cerrados, %d abiertos, %d sin conexión, de %d estaciones.',
    $cerrados, count($abiertos), count($sinConexion), count($stations)));

if (!$abiertos && !$sinConexion) {
    $log('Todo cerrado: no se envía correo.');
    exit(0);
}

// --- Correo ---
$fechaTxt  = $date->format('d/m/Y');
$reportUrl = 'http://192.168.0.3:400/operations/corte-t4/?fecha=' . $fecha;
$esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$partes = [];
if ($abiertos)    { $partes[] = count($abiertos) . (count($abiertos) === 1 ? ' estación sin cerrar' : ' estaciones sin cerrar'); }
if ($sinConexion) { $partes[] = count($sinConexion) . ' sin conexión'; }
$subject = "Turno 4 del {$fechaTxt}: " . implode(', ', $partes);

$td = 'padding:6px 10px;border-bottom:1px solid #e5e7eb;';
$body  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#111827;">';
$body .= "<p>Revisión automática del cierre del turno 4 (10:00 p. m. – 12:00 a. m.) del <b>{$fechaTxt}</b> en ControlGas, a las " . date('H:i') . '.</p>';

if ($abiertos) {
    $body .= '<h3 style="color:#b91c1c;margin:18px 0 6px;">Turnos 4 sin cerrar</h3>';
    $body .= '<p style="margin:0 0 8px;color:#4b5563;">No hay lecturas electrónicas (Medicion) del turno 4 en estas estaciones.</p>';
    $body .= '<table style="border-collapse:collapse;"><tr>'
           . "<th style=\"{$td}text-align:left;\">Estación</th>"
           . "<th style=\"{$td}text-align:right;\">Despachos T4</th>"
           . "<th style=\"{$td}text-align:right;\">Venta T4</th>"
           . "<th style=\"{$td}text-align:left;\">Ventas declaradas</th></tr>";
    foreach ($abiertos as $row) {
        $body .= '<tr>'
               . "<td style=\"{$td}\">{$esc($row['nombre'])}</td>"
               . "<td style=\"{$td}text-align:right;\">" . number_format($row['despachos']) . '</td>'
               . "<td style=\"{$td}text-align:right;\">$" . number_format($row['monto'], 2) . '</td>'
               . "<td style=\"{$td}\">" . ($row['declarado'] ? 'Sí' : 'No') . '</td></tr>';
    }
    $body .= '</table>';
}

if ($sinConexion) {
    $body .= '<h3 style="color:#92400e;margin:18px 0 6px;">No se pudieron revisar</h3>';
    $body .= '<p style="margin:0 0 8px;color:#4b5563;">El ControlGas de estas estaciones no respondió (se intentó dos veces):</p><ul>';
    foreach ($sinConexion as $station) {
        $body .= '<li>' . $esc($station['nombre']) . '</li>';
    }
    $body .= '</ul>';
}

$body .= "<p style=\"margin-top:18px;\">Cerradas correctamente: {$cerrados} de " . count($stations) . ' estaciones.</p>';
$body .= "<p><a href=\"{$esc($reportUrl)}\">Ver el reporte de cierre del turno 4</a></p>";
$body .= '<p style="color:#6b7280;font-size:12px;">Este correo solo informa: la revisión no cierra ni modifica nada en ControlGas.</p></div>';

if ($dryRun) {
    $log('Dry-run: se enviaría a ' . implode(', ', CIERRE_T4_DESTINATARIOS));
    $log("Asunto: {$subject}");
    exit(0);
}

$error = null;
if (send_mail($subject, $body, CIERRE_T4_DESTINATARIOS, MAIL_FROM, false, false, $error)) {
    $log('Correo enviado a ' . implode(', ', CIERRE_T4_DESTINATARIOS));
    exit(0);
}
$log('ERROR al enviar el correo: ' . ($error ?? 'desconocido'));
exit(1);
