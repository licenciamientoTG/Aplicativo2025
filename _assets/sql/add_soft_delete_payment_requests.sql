-- Soft-delete para TG.dbo.payment_requests (fase 2 del soft-delete de facturas)
-- El botón "Eliminar" ya no borra físicamente la requisición: la marca is_deleted=1
-- junto con sus facturas, conservando historial y sin chocar con la FK de
-- anticipo_invoice_applications hacia payment_request_invoices.
USE [TG]
GO

ALTER TABLE [dbo].[payment_requests]
    ADD [is_deleted] BIT NOT NULL DEFAULT 0,
        [deleted_at] DATETIME NULL,
        [deleted_by] INT NULL;
GO

CREATE INDEX IX_payment_requests_is_deleted
    ON [dbo].[payment_requests] ([is_deleted]);
GO
