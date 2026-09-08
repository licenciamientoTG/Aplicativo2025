USE [TG];
GO

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'fuel_reception_invoices'
)
BEGIN
    CREATE TABLE [TG].[dbo].[fuel_reception_invoices] (
        id INT IDENTITY(1,1) PRIMARY KEY,
        schedule_id INT NOT NULL,
        invoice_id INT NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        CONSTRAINT FK_fri_schedule FOREIGN KEY (schedule_id)
            REFERENCES [TG].[dbo].[fuel_reception_schedule](id),
        CONSTRAINT FK_fri_invoice FOREIGN KEY (invoice_id)
            REFERENCES [TG].[dbo].[FacturasRecibidas](Id),
        CONSTRAINT UQ_fri_schedule UNIQUE (schedule_id)
    );
    PRINT 'Tabla fuel_reception_invoices creada.';
END
ELSE
    PRINT 'Tabla fuel_reception_invoices ya existía, sin cambios.';
GO
