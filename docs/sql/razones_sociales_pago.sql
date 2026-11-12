-- =============================================================
-- Catálogo propio de razones sociales que pagan (TG.dbo.razones_sociales_pago).
--
-- Reemplaza a SG12.dbo.Empresas (ControlGas) en:
--   /pagos-extraordinarios/alta-cuenta  (quién puede pagarle a la cuenta)
--   /tesoreria/bank_accounts            (casillas "Pueden pagar")
--   /pagos-extraordinarios              (Empresa que paga cada renglón)
--
-- - Las 13 que existen en ControlGas conservan su código (cod = SG12.dbo.Empresas.cod),
--   así no cambia nada de lo que ya guarda emp_cod / empresa_cod.
-- - Las 6 que no están en ControlGas reciben código desde 100.
-- - Las 10 de ControlGas que no pagan se cargan INACTIVAS (activo = 0): no salen en
--   las listas, pero el historial sigue mostrando su nombre.
-- - Se ligan (emp_cod) las cuentas Propias de las 6 nuevas que no tenían emp_cod. Las de
--   códigos de ControlGas NO se tocan (el layout de pago a proveedores las usa).
--
-- RFC pendiente (no aparece en FacturasRecibidas): THREE TIGERS, INMO DIAZ,
-- CONTROLADORA DE VALES, ZAIDENERGY. Completarlo con un UPDATE cuando se tenga; sin RFC
-- la validación del receptor del CFDI no podrá reconocer a esa empresa.
--
-- Script idempotente. Sin acentos literales (CASTAÑO con NCHAR(209)) para no depender
-- de la codificación con la que se abra el archivo.
-- =============================================================
USE [TG]
GO

IF OBJECT_ID('TG.dbo.razones_sociales_pago') IS NULL
BEGIN
    CREATE TABLE [TG].[dbo].[razones_sociales_pago] (
        cod        INT           NOT NULL CONSTRAINT PK_razones_sociales_pago PRIMARY KEY,
        den        NVARCHAR(150) NOT NULL,
        rfc        VARCHAR(13)   NULL,
        activo     BIT           NOT NULL CONSTRAINT DF_razones_sociales_pago_activo DEFAULT (1),
        created_at DATETIME      NOT NULL CONSTRAINT DF_razones_sociales_pago_created DEFAULT (GETDATE())
    );
END
GO

;WITH datos (cod, den, rfc, activo) AS (
    SELECT *
      FROM (VALUES
        -- En ControlGas: mismo código; RFC de SG12.dbo.Empresas (se completa abajo)
        ( 1, N'DIAZ GAS SA DE CV',                                   NULL, 1),
        (14, N'DISTRIBUIDORA CLARA SA DE CV',                        NULL, 1),
        (16, N'DISTRIBUIDORA GASOMEX SA DE CV',                      NULL, 1),
        (10, N'ESTACION CUSTODIA SA DE CV',                          NULL, 1),
        (17, N'GASOLINERA VILLA AHUMADA SA DE CV',                   NULL, 1),
        (21, N'GRUPO OPERADOR GASOLINERO TSA DEL CENTRO SA DE CV',   NULL, 1),
        (23, N'HECTOR ARMANDINO FIERRO HOLGUIN',                     NULL, 1),
        (15, N'SERVICIO EL JARUDO SA DE CV',                         NULL, 1),
        (11, N'SERVICIO SYC SA DE CV',                               NULL, 1),
        (18, N'SERVICIOS GASOLINEROS EL CASTA' + NCHAR(209) + N'O SA DE CV', NULL, 1),
        (19, N'SMA PICACHOS SA DE CV',                               NULL, 1),
        (20, N'SMA VENTANAS SA DE CV',                               NULL, 1),
        (22, N'FORMULA GAS SA DE CV',                                NULL, 1),
        -- Nuevas (no están en ControlGas)
        (100, N'THREE TIGERS',                                       NULL, 1),
        (101, N'INMO DIAZ SA DE CV',                                 NULL, 1),
        (102, N'PETROTAL SA DE CV',                                  'PET180213L66', 1),
        (103, N'TOTAL GAS DE JUAREZ',                                'TGJ130206E10', 1),
        (104, N'CONTROLADORA DE VALES SA DE CV',                     NULL, 1),
        (105, N'ZAIDENERGY SC',                                      NULL, 1)
      ) v (cod, den, rfc, activo)
)
INSERT INTO [TG].[dbo].[razones_sociales_pago] (cod, den, rfc, activo)
SELECT d.cod, d.den, d.rfc, d.activo
  FROM datos d
 WHERE NOT EXISTS (SELECT 1 FROM [TG].[dbo].[razones_sociales_pago] x WHERE x.cod = d.cod);
GO

-- Las de ControlGas que no pagan: inactivas (solo para que el historial tenga nombre).
INSERT INTO [TG].[dbo].[razones_sociales_pago] (cod, den, rfc, activo)
SELECT e.cod, e.den, NULLIF(LTRIM(RTRIM(e.rfc)), ''), 0
  FROM [SG12].[dbo].[Empresas] e
 WHERE e.cod > 0
   AND NOT EXISTS (SELECT 1 FROM [TG].[dbo].[razones_sociales_pago] x WHERE x.cod = e.cod);
GO

-- RFC de las que vienen de ControlGas (solo si está vacío).
UPDATE r SET r.rfc = UPPER(LTRIM(RTRIM(e.rfc)))
  FROM [TG].[dbo].[razones_sociales_pago] r
  JOIN [SG12].[dbo].[Empresas] e ON e.cod = r.cod
 WHERE (r.rfc IS NULL OR r.rfc = '') AND NULLIF(LTRIM(RTRIM(e.rfc)), '') IS NOT NULL;
GO

-- Cuentas Propias de las nuevas razones sociales (solo las que no tienen emp_cod).
UPDATE c SET c.emp_cod = m.cod
  FROM [TG].[dbo].[CatalogosCuentasBancarias] c
  JOIN (VALUES (101, 'INMO DIAZ%'), (102, 'PETROTAL%'), (103, 'TOTAL GAS DE JUAREZ%'),
               (104, 'CONTROLADORA DE VALES%'), (105, 'ZAIDENERGY%'), (100, 'THREE TIGERS%')) m (cod, patron)
    ON c.Descripcion LIKE m.patron
 WHERE c.Tipo = 'Propias' AND c.emp_cod IS NULL;
GO

SELECT cod, den, rfc, activo,
       (SELECT COUNT(*) FROM [TG].[dbo].[CatalogosCuentasBancarias] c
         WHERE c.Tipo = 'Propias' AND c.emp_cod = r.cod AND c.Activo = 1) AS cuentas_propias
  FROM [TG].[dbo].[razones_sociales_pago] r
 ORDER BY activo DESC, den;
GO
