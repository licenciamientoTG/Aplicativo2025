<?php
/**
 * Tarea programada: Agrupación diaria de requisiciones + correo de solicitud de pago.
 * Equivale a que Abastos presione "Mandar a pagos" manualmente.
 *
 * Configurar en Programador de Tareas de Windows (IIS) a las 11:00 AM:
 *   Programa:  php
 *   Argumentos: C:\ruta\AplicativoPhp\cron\auto_group_payments.php
 *
 * También se puede invocar vía HTTP:
 *   curl -X POST http://totalgasonline.net:400/payment/send_to_payments -d "cron_token=TG_CRON_2024"
 */

define('CRON_MODE', true);
define('CRON_SECRET', 'TG_CRON_2024');

$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
chdir($_SERVER['DOCUMENT_ROOT']);

require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';

spl_autoload_register(function ($class) {
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

$today   = date('Y-m-d');
$user_id = 0; // usuario sistema

echo "[" . date('Y-m-d H:i:s') . "] Iniciando tarea programada para fecha: $today\n";

// ─── 1. Agrupar requisiciones del día ─────────────────────────────────────────
$groupModel  = new PaymentAccountingGroupsModel();
$groupResult = $groupModel->auto_group_by_date($today, $user_id);

echo "[" . date('Y-m-d H:i:s') . "] Agrupación: {$groupResult['message']}\n";
if (!empty($groupResult['errors'])) {
    foreach ($groupResult['errors'] as $err) {
        echo "[ERROR agrupacion] $err\n";
    }
}

// ─── 2. Obtener pagos con PDF completo ────────────────────────────────────────
$paymentModel = new PaymentRequestsModel();
$pagos        = $paymentModel->get_payments_ready_for_request();

if (empty($pagos)) {
    echo "[" . date('Y-m-d H:i:s') . "] No hay pagos con PDF completo para notificar. Fin.\n";
    exit(0);
}

// ─── 3. Destinatarios ─────────────────────────────────────────────────────────
$recipientsModel = new PaymentNotificationRecipientsModel();
$emails          = $recipientsModel->get_active_emails('solicitud_pago');

if (empty($emails)) {
    echo "[" . date('Y-m-d H:i:s') . "] No hay destinatarios configurados. Fin.\n";
    exit(0);
}

// ─── 4. Enviar correo ─────────────────────────────────────────────────────────
$total_general = array_sum(array_map(fn($p) => (float)$p['total_amount'], $pagos));
$subject = 'Solicitud de pago a proveedores - ' . count($pagos) . ' pago(s) - ' . date('d/m/Y');
$body    = generar_html_solicitud_pagos_cron($pagos, $total_general);
$from    = 'no-reply@totalgas.com';

$ok = send_mail_with_fallback($subject, $body, $emails, $from);

if ($ok) {
    echo "[" . date('Y-m-d H:i:s') . "] Correo enviado a: " . implode(', ', $emails) . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] Grupos: " . ($groupResult['grupos'] ?? 0) . " | Pagos notificados: " . count($pagos) . " | Monto: $" . number_format($total_general, 2) . "\n";
} else {
    echo "[ERROR] No se pudo enviar el correo.\n";
    error_log('cron/auto_group_payments: fallo al enviar correo ' . date('Y-m-d H:i:s'));
    exit(1);
}

exit(0);

// ─── HTML del correo ──────────────────────────────────────────────────────────
function generar_html_solicitud_pagos_cron(array $pagos, float $total_general): string
{
    $fecha     = date('d/m/Y H:i');
    $total_fmt = '$' . number_format($total_general, 2, '.', ',');

    $filas = '';
    foreach ($pagos as $p) {
        $fecha_pago = $p['scheduled_payment_date']
            ? date('d/m/Y', strtotime($p['scheduled_payment_date']))
            : '—';
        $monto = '$' . number_format((float)$p['total_amount'], 2, '.', ',');
        $filas .= "
        <tr>
            <td style='padding:8px;border:1px solid #e2e8f0;'>#{$p['id']}</td>
            <td style='padding:8px;border:1px solid #e2e8f0;'>" . htmlspecialchars($p['emp_name'] ?? '') . "</td>
            <td style='padding:8px;border:1px solid #e2e8f0;'>" . htmlspecialchars($p['provider_name'] ?? '') . "</td>
            <td style='padding:8px;border:1px solid #e2e8f0;text-align:center;'>{$p['total_invoices']}</td>
            <td style='padding:8px;border:1px solid #e2e8f0;text-align:center;'>$fecha_pago</td>
            <td style='padding:8px;border:1px solid #e2e8f0;text-align:right;'>$monto</td>
        </tr>";
    }

    return "
    <div style='font-family:Arial,sans-serif;max-width:720px;margin:0 auto;color:#1e293b;'>
        <div style='background:#16a34a;color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;'>
            <h2 style='margin:0;font-size:18px;'>Solicitud de pago a proveedores</h2>
            <p style='margin:4px 0 0;font-size:13px;'>$fecha &middot; " . count($pagos) . " pago(s) listos para su pago</p>
        </div>
        <div style='border:1px solid #e2e8f0;border-top:none;padding:20px;border-radius:0 0 8px 8px;'>
            <p style='font-size:14px;'>Los siguientes pagos tienen <strong>todas sus facturas con PDF recibido</strong> y están listos para solicitar su pago a Tesorería:</p>
            <table style='width:100%;border-collapse:collapse;font-size:13px;'>
                <thead>
                    <tr style='background:#f1f5f9;'>
                        <th style='padding:8px;border:1px solid #e2e8f0;text-align:left;'>ID</th>
                        <th style='padding:8px;border:1px solid #e2e8f0;text-align:left;'>Empresa</th>
                        <th style='padding:8px;border:1px solid #e2e8f0;text-align:left;'>Proveedor</th>
                        <th style='padding:8px;border:1px solid #e2e8f0;text-align:center;'>Facturas</th>
                        <th style='padding:8px;border:1px solid #e2e8f0;text-align:center;'>Fecha pago esp.</th>
                        <th style='padding:8px;border:1px solid #e2e8f0;text-align:right;'>Monto</th>
                    </tr>
                </thead>
                <tbody>$filas</tbody>
                <tfoot>
                    <tr style='background:#f8fafc;font-weight:bold;'>
                        <td colspan='5' style='padding:8px;border:1px solid #e2e8f0;text-align:right;'>TOTAL</td>
                        <td style='padding:8px;border:1px solid #e2e8f0;text-align:right;'>$total_fmt</td>
                    </tr>
                </tfoot>
            </table>
            <p style='font-size:12px;color:#64748b;margin-top:16px;'>Este correo fue generado automáticamente por la tarea programada de las 11:00 AM.</p>
        </div>
    </div>";
}
