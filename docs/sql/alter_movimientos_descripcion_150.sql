-- docs/sql/alter_movimientos_descripcion_150.sql
-- Amplía movimientos_bancarios.descripcion de NVARCHAR(60) a NVARCHAR(150).
--
-- Motivo: Bankaool manda descripciones de hasta 75 caracteres —el 15% de las
-- filas de su export— y son justo las que traen el nombre de la contraparte y
-- la CLABE destino:
--   ABONO POR SPEI -ROSA JANETH CHAPARRO REYES - 2026070240014BMOVP000421607170
--   DEBITO POR COMISION SPEI Cuenta: 014164655085457913 TRASPASOS ENTRE CUENTAS
--
-- Es un cambio de metadatos: SQL Server no reescribe las filas al ampliar un
-- NVARCHAR, y no hay índices sobre la columna. Aplicado el 2026-07-29 sobre
-- 8,137 filas sin cambio en los datos (mismo conteo y misma suma de largos).
--
-- Spec: docs/superpowers/specs/2026-07-28-tesoreria-saldos-por-empresa-design.md
USE TG;
GO

IF EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME = 'movimientos_bancarios'
      AND COLUMN_NAME = 'descripcion'
      AND CHARACTER_MAXIMUM_LENGTH < 150
)
    ALTER TABLE dbo.movimientos_bancarios ALTER COLUMN descripcion NVARCHAR(150) NULL;
GO
