-- =============================================================
-- Tabla de destinatarios de notificaciones de pago
-- Usada por: Payment::send_ready_payments() y la tarea programada futura
-- =============================================================
USE TG;
GO

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'payment_notification_recipients')
BEGIN
    CREATE TABLE [TG].[dbo].[payment_notification_recipients] (
        id         INT IDENTITY(1,1) PRIMARY KEY,
        email      VARCHAR(150) NOT NULL,
        nombre     VARCHAR(150) NULL,
        evento     VARCHAR(50)  NOT NULL CONSTRAINT DF_pnr_evento DEFAULT ('solicitud_pago'),
        activo     BIT          NOT NULL CONSTRAINT DF_pnr_activo DEFAULT (1),
        created_at DATETIME     NOT NULL CONSTRAINT DF_pnr_created DEFAULT (GETDATE())
    );
END
GO

-- Destinatario de PRUEBAS (Tesorería real luego)
IF NOT EXISTS (
    SELECT 1 FROM [TG].[dbo].[payment_notification_recipients]
    WHERE email = 'alejandro.martinez@totalgas.com' AND evento = 'solicitud_pago'
)
BEGIN
    INSERT INTO [TG].[dbo].[payment_notification_recipients] (email, nombre, evento, activo)
    VALUES ('alejandro.martinez@totalgas.com', 'Alejandro Martinez (pruebas)', 'solicitud_pago', 1);
END
GO
