-- =============================================================
-- Alta de cuenta bancaria: CLABE y Routing number como campos propios.
--   MXN (PESOS):   num_cuenta = cuenta, clabe = CLABE (18 digitos)   -> ambos obligatorios
--   USD (DOLARES): num_cuenta = account number, routing_number (ABA, 9 digitos)
--   Direccion completa (opcional, ambos tipos): se guarda en benef_direccion (ya existia).
-- Al aprobar, CatalogosCuentasBancarias.CuentaLocal = CLABE (MXN) o account number (USD);
-- el routing number va en EntidadCuentaLocalABASwift.
-- Las solicitudes anteriores quedan con clabe/routing_number en NULL (se usa num_cuenta).
-- Script idempotente.
-- =============================================================
USE [TG]
GO

IF COL_LENGTH('dbo.bank_account_requests', 'clabe') IS NULL
    ALTER TABLE [TG].[dbo].[bank_account_requests] ADD clabe VARCHAR(18) NULL;
GO
IF COL_LENGTH('dbo.bank_account_requests', 'routing_number') IS NULL
    ALTER TABLE [TG].[dbo].[bank_account_requests] ADD routing_number VARCHAR(9) NULL;
GO
-- Direccion completa: mas holgura que los 200 originales
ALTER TABLE [TG].[dbo].[bank_account_requests] ALTER COLUMN benef_direccion VARCHAR(300) NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_bank_account_requests_clabe')
    CREATE INDEX IX_bank_account_requests_clabe ON [TG].[dbo].[bank_account_requests] (clabe);
GO
