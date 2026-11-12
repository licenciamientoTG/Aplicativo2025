-- =============================================================
-- Cuentas de proveedor: qué razones sociales propias pueden pagarles.
--
-- 1) bank_account_request_payers: razones sociales elegidas en la solicitud de alta
--    (/pagos-extraordinarios/alta-cuenta/new, selección múltiple).
-- 2) bank_account_payers: razones sociales que pueden pagar a cada cuenta de proveedor
--    del catálogo (CatalogosCuentasBancarias, Tipo = 'Terceros'). Al aprobar la alta se
--    copian de la solicitud; Tesorería las edita en /tesoreria/bank_accounts.
-- (Uso: solo pagos extraordinarios; el layout de pago a proveedores de ControlGas no cambia).
--
-- emp_cod en el catálogo sigue siendo SOLO de cuentas Propias.
-- Script idempotente.
-- =============================================================
USE [TG]
GO

IF OBJECT_ID('dbo.bank_account_request_payers', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[bank_account_request_payers] (
        request_id  INT NOT NULL,   -- bank_account_requests.id
        emp_cod     INT NOT NULL,   -- SG12.dbo.Empresas.cod
        CONSTRAINT PK_bank_account_request_payers PRIMARY KEY (request_id, emp_cod)
    );
END
GO

IF OBJECT_ID('dbo.bank_account_payers', 'U') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[bank_account_payers] (
        catalogo_cuenta_id INT      NOT NULL,   -- CatalogosCuentasBancarias.Id (cuenta de proveedor)
        emp_cod            INT      NOT NULL,   -- SG12.dbo.Empresas.cod que puede pagarle
        created_at         DATETIME NOT NULL CONSTRAINT DF_bank_account_payers_created DEFAULT GETDATE(),
        created_by         INT      NULL,
        CONSTRAINT PK_bank_account_payers PRIMARY KEY (catalogo_cuenta_id, emp_cod)
    );
    CREATE INDEX IX_bank_account_payers_emp ON [TG].[dbo].[bank_account_payers] (emp_cod);
END
GO


-- Solicitudes existentes: su única razón social pasa a la tabla de la solicitud.
INSERT INTO [TG].[dbo].[bank_account_request_payers] (request_id, emp_cod)
SELECT r.id, r.empresa_cod
  FROM [TG].[dbo].[bank_account_requests] r
 WHERE r.empresa_cod > 0
   AND NOT EXISTS (SELECT 1 FROM [TG].[dbo].[bank_account_request_payers] p WHERE p.request_id = r.id);
GO


-- Cuentas ya aprobadas: quién puede pagarles = lo que pidió su solicitud.
INSERT INTO [TG].[dbo].[bank_account_payers] (catalogo_cuenta_id, emp_cod, created_by)
SELECT DISTINCT r.catalogo_cuenta_id, p.emp_cod, r.decided_by
  FROM [TG].[dbo].[bank_account_requests] r
  JOIN [TG].[dbo].[bank_account_request_payers] p ON p.request_id = r.id
 WHERE r.status = 2 AND r.catalogo_cuenta_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM [TG].[dbo].[bank_account_payers] x
                    WHERE x.catalogo_cuenta_id = r.catalogo_cuenta_id AND x.emp_cod = p.emp_cod);
GO
