-- =====================================================================
-- Programación de recepciones de combustible (/supply/scheduling)
--   1) fecha_original: fecha en que se programó originalmente la
--      recepción. No cambia aunque se mueva de día varias veces.
--   2) fuel_reception_audit_log: bitácora de TODO cambio hecho en la
--      sección (quién, cuándo, qué acción, datos antes/después en JSON).
--
-- CORRER ANTES de desplegar el código que la usa (get_day() y add()
-- ya leen/escriben estas columnas y fallan si no existen).
-- Idempotente: se puede correr dos veces sin daño.
-- =====================================================================
USE TG;
GO

-- 1) Fecha original --------------------------------------------------
IF COL_LENGTH('dbo.fuel_reception_schedule', 'fecha_original') IS NULL
    ALTER TABLE dbo.fuel_reception_schedule ADD fecha_original DATE NULL;
GO

-- Las recepciones que ya existen: su fecha actual es la original (no hay
-- forma de recuperar movimientos anteriores, nunca se guardaron).
UPDATE dbo.fuel_reception_schedule
SET fecha_original = fecha
WHERE fecha_original IS NULL;
GO

-- 2) Bitácora de auditoría ------------------------------------------
IF OBJECT_ID('dbo.fuel_reception_audit_log', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.fuel_reception_audit_log (
        id               INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_fuel_reception_audit_log PRIMARY KEY,
        schedule_id      INT NULL,                 -- recepción afectada (NULL en acciones sin una sola fila)
        accion           VARCHAR(30)  NOT NULL,    -- CREAR, IMPORTAR, EDITAR, CAMBIAR_FECHA, CANCELAR, DUPLICAR,
                                                   -- CAMBIO_ESTATUS, VINCULAR_FACTURA, DESVINCULAR_FACTURA
        usuario_id       INT NULL,
        usuario_nombre   VARCHAR(150) NULL,
        datos_anteriores NVARCHAR(MAX) NULL,       -- JSON
        datos_nuevos     NVARCHAR(MAX) NULL,       -- JSON
        fecha            DATETIME NOT NULL CONSTRAINT DF_fuel_reception_audit_log_fecha DEFAULT (GETDATE())
    );
    CREATE INDEX IX_fuel_reception_audit_log_schedule
        ON dbo.fuel_reception_audit_log (schedule_id, accion, id DESC);
    CREATE INDEX IX_fuel_reception_audit_log_fecha
        ON dbo.fuel_reception_audit_log (fecha);
END
GO
