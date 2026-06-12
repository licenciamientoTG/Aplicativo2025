<?php

/**
 * Destinatarios de las notificaciones de pago.
 *
 * Tabla: [TG].[dbo].[payment_notification_recipients]
 *   id            INT IDENTITY PK
 *   email         VARCHAR(150)  NOT NULL
 *   nombre        VARCHAR(150)  NULL
 *   evento        VARCHAR(50)   NOT NULL DEFAULT 'solicitud_pago'  -- tipo de notificación
 *   activo        BIT           NOT NULL DEFAULT 1
 *   created_at    DATETIME      NOT NULL DEFAULT GETDATE()
 *
 * Ver script de creación en docs/sql/payment_notification_recipients.sql
 */
class PaymentNotificationRecipientsModel extends Model
{
    /**
     * Devuelve los correos activos para un evento dado.
     * @return string[] lista de emails válidos
     */
    public function get_active_emails(string $evento = 'solicitud_pago'): array
    {
        $query = "
            SELECT email
            FROM [TG].[dbo].[payment_notification_recipients]
            WHERE activo = 1 AND evento = ?";

        $rows = $this->sql->select($query, [$evento]) ?: [];

        $emails = array_filter(
            array_map(fn($r) => trim($r['email'] ?? ''), $rows),
            fn($e) => !empty($e) && filter_var($e, FILTER_VALIDATE_EMAIL)
        );

        return array_values(array_unique($emails));
    }
}
