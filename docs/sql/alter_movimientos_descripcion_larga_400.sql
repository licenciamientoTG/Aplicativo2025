-- docs/sql/alter_movimientos_descripcion_larga_400.sql
-- Amplía movimientos_bancarios.descripcion_larga de NVARCHAR(150) a NVARCHAR(400).
--
-- Motivo: la DESCRIPCIÓN DETALLADA de Banorte (Cuentas de Cheques) llega a 304
-- caracteres —el 21% de las filas de su export pasa de 150— y es el campo del
-- que se extrae la contraparte de los SPEI:
--   =REFERENCIA  CTA/CLABE: 147180000001034722, BEM SPEI, BCO:147
--   BENEF:ASOCIACION PATRONAL SANTA ENGRA (DATO NO VERIFICADO, POR ESTA
--   INSTITUCION), ..., CVE RASTREO: 8846APR2202607095515921130
--   RFC: XXX010101XXX, IVA: 000000000000.00
-- Cortar a 150 se lleva el final, donde están el RFC y la hora de liquidación.
--
-- Es un cambio de metadatos: SQL Server no reescribe las filas al ampliar un
-- NVARCHAR, y no hay índices sobre la columna.
--
-- Spec: docs/superpowers/specs/2026-07-28-tesoreria-saldos-por-empresa-design.md
USE TG;
GO

IF EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'movimientos_bancarios'
      AND COLUMN_NAME = 'descripcion_larga'
      AND CHARACTER_MAXIMUM_LENGTH < 400
)
    ALTER TABLE dbo.movimientos_bancarios ALTER COLUMN descripcion_larga NVARCHAR(400) NULL;
GO
