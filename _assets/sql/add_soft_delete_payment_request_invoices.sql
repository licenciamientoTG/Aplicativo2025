-- Soft-delete para TG.dbo.payment_request_invoices
-- Evita que un DELETE físico borre el rastro de una factura quitada de una requisición.
USE [TG]
GO

ALTER TABLE [dbo].[payment_request_invoices]
    ADD [is_deleted] BIT NOT NULL DEFAULT 0,
        [deleted_at] DATETIME NULL,
        [deleted_by] INT NULL;
GO

CREATE INDEX IX_payment_request_invoices_is_deleted
    ON [dbo].[payment_request_invoices] ([payment_request_id], [is_deleted]);
GO
