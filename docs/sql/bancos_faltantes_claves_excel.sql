-- Agrega al catálogo de bancos del alta de cuenta (TG.dbo.bank_account_banks) los 6 bancos que faltaban,
-- con las claves de la hoja CLAVES de "SOLICITUD ALTA DE CUENTA BANCARIA 2.xlsx" (2026-11-17).
-- Además llena clave_banco en las solicitudes de esos bancos que la tenían vacía (las importadas del Excel).
-- Idempotente: se puede correr más de una vez.
SET NOCOUNT ON; SET XACT_ABORT ON;
USE [TG];
BEGIN TRAN;

INSERT INTO dbo.bank_account_banks (nombre, no_inst, clave_transfer, activo)
SELECT v.nombre, v.no_inst, v.clave, 1
  FROM (VALUES ('MERCADO PAGO W', 90722, 'MERCA'),
               ('SPIN BY OXXO',   90728, 'SPINB'),
               ('KLAR',           90661, 'ALTER'),
               ('CITI MEXICO',    40124, 'CASFIN'),
               ('INTERCAM BANCO', 40136, 'REGIO'),
               ('NU MEXICO',      90638, '90638')) v (nombre, no_inst, clave)
 WHERE NOT EXISTS (SELECT 1 FROM dbo.bank_account_banks b WHERE b.nombre = v.nombre);

UPDATE r SET clave_banco = b.clave_transfer
  FROM dbo.bank_account_requests r
  JOIN dbo.bank_account_banks b ON b.nombre = r.banco
 WHERE ISNULL(r.clave_banco, '') = ''
   AND b.nombre IN ('MERCADO PAGO W', 'SPIN BY OXXO', 'KLAR', 'CITI MEXICO', 'INTERCAM BANCO', 'NU MEXICO');

SELECT nombre, no_inst, clave_transfer FROM dbo.bank_account_banks
 WHERE nombre IN ('MERCADO PAGO W', 'SPIN BY OXXO', 'KLAR', 'CITI MEXICO', 'INTERCAM BANCO', 'NU MEXICO');
SELECT banco, clave_banco, COUNT(*) AS solicitudes FROM dbo.bank_account_requests
 WHERE banco IN ('MERCADO PAGO W', 'SPIN BY OXXO', 'KLAR', 'CITI MEXICO', 'INTERCAM BANCO', 'NU MEXICO')
 GROUP BY banco, clave_banco;

COMMIT;
