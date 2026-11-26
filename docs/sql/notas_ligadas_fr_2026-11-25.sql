-- =====================================================================
-- Notas de crédito/cargo ligadas a FacturasRecibidas (2026-11-25)
--
-- Agrega a TG.dbo.invoice_credit_debit_notes las columnas para ligar cada
-- nota con su CFDI en TG.dbo.FacturasRecibidas:
--   factura_recibida_id INT         id en FacturasRecibidas
--   uuid                VARCHAR(50) UUID del CFDI de la nota
--   emp_cod             INT         empresa receptora (SG12.Empresas.cod)
--   codgas              INT         estación (si aplica)
--   origen              VARCHAR(20) 'ligada' | 'importada' | 'historica'
-- Índices:
--   UX_icdn_uuid_activa        único filtrado (uuid) WHERE status = 1 AND uuid IS NOT NULL
--   IX_icdn_factura_recibida   (factura_recibida_id)
--
-- Idempotente: se puede correr varias veces. Todas las filas actuales
-- quedan con NULL en las columnas nuevas.
-- =====================================================================
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;  -- sqlcmd lo trae OFF; el índice filtrado lo exige
SET ANSI_NULLS ON;
GO

IF COL_LENGTH('TG.dbo.invoice_credit_debit_notes', 'factura_recibida_id') IS NULL
    ALTER TABLE TG.dbo.invoice_credit_debit_notes ADD factura_recibida_id INT NULL;
IF COL_LENGTH('TG.dbo.invoice_credit_debit_notes', 'uuid') IS NULL
    ALTER TABLE TG.dbo.invoice_credit_debit_notes ADD uuid VARCHAR(50) NULL;
IF COL_LENGTH('TG.dbo.invoice_credit_debit_notes', 'emp_cod') IS NULL
    ALTER TABLE TG.dbo.invoice_credit_debit_notes ADD emp_cod INT NULL;
IF COL_LENGTH('TG.dbo.invoice_credit_debit_notes', 'codgas') IS NULL
    ALTER TABLE TG.dbo.invoice_credit_debit_notes ADD codgas INT NULL;
IF COL_LENGTH('TG.dbo.invoice_credit_debit_notes', 'origen') IS NULL
    ALTER TABLE TG.dbo.invoice_credit_debit_notes ADD origen VARCHAR(20) NULL;
GO

-- Las columnas ya existen en este punto (otro batch)
SET QUOTED_IDENTIFIER ON;
SET ANSI_NULLS ON;
GO

IF NOT EXISTS (SELECT 1 FROM TG.sys.indexes
               WHERE name = 'UX_icdn_uuid_activa'
                 AND object_id = OBJECT_ID('TG.dbo.invoice_credit_debit_notes'))
    CREATE UNIQUE NONCLUSTERED INDEX UX_icdn_uuid_activa
        ON TG.dbo.invoice_credit_debit_notes (uuid)
        WHERE status = 1 AND uuid IS NOT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM TG.sys.indexes
               WHERE name = 'IX_icdn_factura_recibida'
                 AND object_id = OBJECT_ID('TG.dbo.invoice_credit_debit_notes'))
    CREATE NONCLUSTERED INDEX IX_icdn_factura_recibida
        ON TG.dbo.invoice_credit_debit_notes (factura_recibida_id);
GO

-- Verificación
SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
FROM TG.INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'invoice_credit_debit_notes'
  AND COLUMN_NAME IN ('factura_recibida_id', 'uuid', 'emp_cod', 'codgas', 'origen');

SELECT name, is_unique, filter_definition
FROM TG.sys.indexes
WHERE object_id = OBJECT_ID('TG.dbo.invoice_credit_debit_notes')
  AND name IN ('UX_icdn_uuid_activa', 'IX_icdn_factura_recibida');

SELECT COUNT(*) AS con_uuid FROM TG.dbo.invoice_credit_debit_notes WHERE uuid IS NOT NULL;
GO
