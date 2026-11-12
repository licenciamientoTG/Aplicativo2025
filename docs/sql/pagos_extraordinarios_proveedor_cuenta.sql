-- =============================================================
-- Pagos extraordinarios: cada renglón apunta a la cuenta del proveedor.
--
-- extra_payment_lines.proveedor_cuenta_id = CatalogosCuentasBancarias.Id de la cuenta
-- (Terceros) a la que se le paga. En /pagos-extraordinarios/new el proveedor se elige
-- con un buscador entre las cuentas que entraron por una alta de cuenta aprobada
-- (bank_account_requests.status = 2). La columna "proveedor" sigue guardando el nombre
-- (lo pone el servidor con el proveedor de la alta).
--
-- Los renglones anteriores quedan con NULL. Script idempotente.
-- =============================================================
USE [TG]
GO

IF COL_LENGTH('TG.dbo.extra_payment_lines', 'proveedor_cuenta_id') IS NULL
    ALTER TABLE [TG].[dbo].[extra_payment_lines] ADD proveedor_cuenta_id INT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_extra_payment_lines_proveedor_cuenta'
                 AND object_id = OBJECT_ID('TG.dbo.extra_payment_lines'))
    CREATE INDEX IX_extra_payment_lines_proveedor_cuenta
        ON [TG].[dbo].[extra_payment_lines] (proveedor_cuenta_id);
GO
