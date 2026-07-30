-- docs/sql/alter_movimientos_referencia_40.sql
-- Amplía movimientos_bancarios.referencia de NVARCHAR(20) a NVARCHAR(40).
--
-- Motivo: la columna Referencia de Banregio llega a 26 caracteres y es
-- polimórfica —clave de rastreo SPEI, folio de crédito o texto libre—:
--   8846APR2202607075509271085   (26, clave de rastreo)
--   066001530357001              (15, número de crédito)
--   Compra OPTION 302            (17, texto)
-- Cortarla a 20 perdería el final de la clave de rastreo, que es justo lo que
-- la hace única para conciliar contra el SPEI. 40 iguala a clave_rastreo, que
-- guarda ese mismo tipo de dato.
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
      AND COLUMN_NAME = 'referencia'
      AND CHARACTER_MAXIMUM_LENGTH < 40
)
    ALTER TABLE dbo.movimientos_bancarios ALTER COLUMN referencia NVARCHAR(40) NULL;
GO
