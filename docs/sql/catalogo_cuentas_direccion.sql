-- =============================================================
-- Catálogo de cuentas bancarias: dirección del beneficiario.
-- Al aprobar una alta de cuenta (/pagos-extraordinarios/alta-cuenta/) se copian al catálogo:
--   PESOS:   CuentaLocal = cuenta, Clave = CLABE (18 dígitos)
--   DOLARES: CuentaLocal = account number, EntidadCuentaLocalABASwift = routing number
--   emp_cod = razón social de la solicitud, Direccion = dirección completa.
-- El proveedor y la carátula se quedan en bank_account_requests (liga por catalogo_cuenta_id).
-- Script idempotente.
-- =============================================================
USE [TG]
GO

IF COL_LENGTH('dbo.CatalogosCuentasBancarias', 'Direccion') IS NULL
    ALTER TABLE [TG].[dbo].[CatalogosCuentasBancarias] ADD Direccion VARCHAR(300) NULL;
GO

-- Completa las cuentas ya aprobadas con lo que traía su solicitud (sin pisar lo capturado).
UPDATE c
   SET c.emp_cod   = COALESCE(c.emp_cod, r.empresa_cod),
       c.Direccion = COALESCE(NULLIF(c.Direccion, ''), r.benef_direccion)
  FROM [TG].[dbo].[CatalogosCuentasBancarias] c
  JOIN [TG].[dbo].[bank_account_requests] r ON r.catalogo_cuenta_id = c.Id AND r.status = 2;
GO
