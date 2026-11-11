-- =============================================================
-- Pagos extraordinarios: XML (CFDI) y PDF por renglón.
--   xml_document_id / pdf_document_id -> TG.dbo.extra_payment_documents (kind 'soporte')
--   cfdi_uuid -> UUID del timbre, para no pagar dos veces la misma factura.
-- El XML es obligatorio para enviar la solicitud; el PDF es opcional.
-- Script idempotente.
-- =============================================================
USE [TG]
GO

IF COL_LENGTH('dbo.extra_payment_lines', 'xml_document_id') IS NULL
    ALTER TABLE [TG].[dbo].[extra_payment_lines] ADD xml_document_id INT NULL;
GO
IF COL_LENGTH('dbo.extra_payment_lines', 'pdf_document_id') IS NULL
    ALTER TABLE [TG].[dbo].[extra_payment_lines] ADD pdf_document_id INT NULL;
GO
IF COL_LENGTH('dbo.extra_payment_lines', 'cfdi_uuid') IS NULL
    ALTER TABLE [TG].[dbo].[extra_payment_lines] ADD cfdi_uuid VARCHAR(36) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_extra_payment_lines_cfdi_uuid')
    CREATE INDEX IX_extra_payment_lines_cfdi_uuid ON [TG].[dbo].[extra_payment_lines] (cfdi_uuid);
GO
