-- docs/sql/station_portal_alert_seen.sql
-- Alerta "Abastos ligó una factura a tu recepción programada" (Mis Recepciones).
-- Una fila por usuario: la última vez que entró a /station_portal/mis_recepciones.
-- El badge del header/sidebar cuenta los vínculos de fuel_reception_invoices
-- de su estación con created_at posterior a last_seen_at (últimos 7 días).
USE TG;
GO

IF OBJECT_ID('dbo.station_portal_alert_seen') IS NULL
BEGIN
CREATE TABLE dbo.station_portal_alert_seen (
    user_id       INT      NOT NULL PRIMARY KEY,
    last_seen_at  DATETIME NOT NULL
);
END
GO
