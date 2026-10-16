<?php
/**
 * Alertas de Mis Recepciones para usuarios con estación asignada: facturas
 * que Abastos ligó (TG.dbo.fuel_reception_invoices) a recepciones
 * programadas de su estación después de la última vez que entraron a la
 * vista (TG.dbo.station_portal_alert_seen).
 *
 * Se cuenta por fecha de VÍNCULO (fri.created_at), no por fecha de la
 * recepción: Abastos llega a ligar facturas varios días después.
 */
class StationPortalAlertModel extends Model
{
    // Vínculos más viejos que esto ya no cuentan como alerta, para que un
    // usuario que casi no entra no acumule un número viejo y enorme.
    const VENTANA_DIAS = 7;

    /**
     * Vínculos nuevos para el usuario (posteriores a su última visita).
     * Debe llamarse ANTES de mark_seen(), que mueve la marca a "ahora".
     */
    function get_nuevas(int $userId, int $stationCode): array {
        $query = "
            SELECT
                s.id, s.fecha, s.product, s.mezcla, s.litros,
                f.Serie AS invoice_serie, f.Folio AS invoice_folio, f.EmisorNombre AS invoice_proveedor,
                CONVERT(VARCHAR(16), fri.created_at, 120) AS linked_at
            FROM TG.dbo.fuel_reception_invoices fri
            JOIN TG.dbo.fuel_reception_schedule s ON s.id = fri.schedule_id
            LEFT JOIN TG.dbo.FacturasRecibidas f ON f.Id = fri.invoice_id
            LEFT JOIN TG.dbo.station_portal_alert_seen a ON a.user_id = ?
            WHERE s.station_code = ?
              AND s.estatus <> 'Cancelado'
              AND fri.created_at >= DATEADD(day, -" . self::VENTANA_DIAS . ", GETDATE())
              AND (a.last_seen_at IS NULL OR fri.created_at > a.last_seen_at)
            ORDER BY fri.created_at DESC
        ";
        return $this->sql->selectSafe($query, [$userId, $stationCode]) ?: [];
    }

    function mark_seen(int $userId): void {
        // updateSafe exige la palabra "update" en el texto de la query.
        $query = "
            UPDATE TG.dbo.station_portal_alert_seen SET last_seen_at = GETDATE() WHERE user_id = ?;
            IF @@ROWCOUNT = 0
                INSERT INTO TG.dbo.station_portal_alert_seen (user_id, last_seen_at) VALUES (?, GETDATE());
        ";
        $this->sql->updateSafe($query, [$userId, $userId]);
    }
}
