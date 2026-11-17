-- docs/sql/conciliacion_cobros_ligas_schema.sql
-- Conciliación de cobros guardada + conciliación manual (Ingresos > Conciliación, tab Conciliación cobros).
-- Spec: docs/superpowers/specs/2026-11-17-conciliacion-cobros-manual-design.md
-- Tablas propias: no tocan Conciliacion_V3_* (tarjetas) ni ControlGas. Idempotente.
USE TG;
GO

IF OBJECT_ID('dbo.conciliacion_cobros_mes') IS NULL
BEGIN
CREATE TABLE dbo.conciliacion_cobros_mes (
    mes              CHAR(7)       NOT NULL PRIMARY KEY,   -- 'Y-m'
    guardado_en      DATETIME      NOT NULL DEFAULT GETDATE(),
    guardado_por     VARCHAR(150)  NOT NULL,
    recalculado_en   DATETIME      NULL,
    recalculado_por  VARCHAR(150)  NULL,
    reintento_fecha  DATE          NULL                    -- último día en que se reintentaron los pendientes
);
END
GO

IF OBJECT_ID('dbo.conciliacion_cobros_grupo') IS NULL
BEGIN
CREATE TABLE dbo.conciliacion_cobros_grupo (
    id           INT IDENTITY(1,1) PRIMARY KEY,
    mes          CHAR(7)        NOT NULL,                  -- mes desde el que se creó
    origen       VARCHAR(10)    NOT NULL,                  -- 'auto' | 'manual'
    estatus      VARCHAR(40)    NOT NULL,                  -- 'Cuadra', 'Diferencia', 'Sin banco'… o 'Conciliado manual'
    comentario   NVARCHAR(500)  NULL,
    creado_en    DATETIME       NOT NULL DEFAULT GETDATE(),
    creado_por   VARCHAR(150)   NOT NULL,
    activo       BIT            NOT NULL DEFAULT 1,        -- 0 = deshecho o reemplazado
    baja_en      DATETIME       NULL,
    baja_por     VARCHAR(150)   NULL,
    CONSTRAINT ck_ccg_origen CHECK (origen IN ('auto', 'manual'))
);
CREATE INDEX ix_ccg_mes_activo ON dbo.conciliacion_cobros_grupo (mes, activo) INCLUDE (origen, estatus);
END
GO

IF OBJECT_ID('dbo.conciliacion_cobros_item') IS NULL
BEGIN
CREATE TABLE dbo.conciliacion_cobros_item (
    id             INT IDENTITY(1,1) PRIMARY KEY,
    grupo_id       INT            NOT NULL REFERENCES dbo.conciliacion_cobros_grupo (id),
    tipo           CHAR(1)        NOT NULL,                -- 'C' cobro (DocumentosC tip 4), 'M' movimiento, 'F' factura (DocumentosC tip 3)
    codgas         INT            NULL,                    -- C y F
    nro            INT            NULL,                    -- C y F
    movimiento_id  INT            NULL,                    -- M: TG.dbo.movimientos_bancarios.id
    monto          DECIMAL(18,2)  NOT NULL,                -- monto al guardar (para alertar si cambió)
    mes            CHAR(7)        NOT NULL,                -- mes del cobro / factura / movimiento
    ocupa          BIT            NOT NULL DEFAULT 1,      -- 0 = solo referencia (movimiento de una Diferencia)
    CONSTRAINT ck_cci_tipo CHECK (tipo IN ('C', 'M', 'F')),
    CONSTRAINT ck_cci_ref CHECK ((tipo = 'M' AND movimiento_id IS NOT NULL) OR (tipo IN ('C', 'F') AND codgas IS NOT NULL AND nro IS NOT NULL))
);
CREATE INDEX ix_cci_grupo ON dbo.conciliacion_cobros_item (grupo_id);
CREATE INDEX ix_cci_doc   ON dbo.conciliacion_cobros_item (tipo, codgas, nro) INCLUDE (grupo_id);
CREATE INDEX ix_cci_mov   ON dbo.conciliacion_cobros_item (movimiento_id) INCLUDE (grupo_id, ocupa);
CREATE INDEX ix_cci_mes   ON dbo.conciliacion_cobros_item (mes) INCLUDE (grupo_id);
END
GO
