-- Borrado lógico de solicitudes de alta de cuenta bancaria (misma convención que payment_requests).
-- Una solicitud con is_deleted = 1 no se lista, no se abre, no se decide y no cuenta como duplicado.
-- Idempotente. CORRER ANTES de desplegar el código que filtra r.is_deleted = 0.
USE [TG];
GO
IF COL_LENGTH('dbo.bank_account_requests', 'is_deleted') IS NULL
    ALTER TABLE dbo.bank_account_requests ADD is_deleted BIT NOT NULL CONSTRAINT DF_bank_account_requests_is_deleted DEFAULT 0;
IF COL_LENGTH('dbo.bank_account_requests', 'deleted_at') IS NULL
    ALTER TABLE dbo.bank_account_requests ADD deleted_at DATETIME NULL;
IF COL_LENGTH('dbo.bank_account_requests', 'deleted_by') IS NULL
    ALTER TABLE dbo.bank_account_requests ADD deleted_by INT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_bank_account_requests_is_deleted')
    CREATE INDEX IX_bank_account_requests_is_deleted ON dbo.bank_account_requests (is_deleted, status);
GO
