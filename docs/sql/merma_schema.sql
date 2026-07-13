-- docs/sql/merma_schema.sql
-- Schema del módulo Análisis de Merma Diaria (/merma/...).
-- Snapshot diario por estación/producto/turno + captura manual + bitácora.
-- Spec: docs/superpowers/specs/2026-07-13-analisis-merma-diaria-design.md
USE TG;
GO

IF OBJECT_ID('dbo.merma_diaria') IS NULL
BEGIN
CREATE TABLE dbo.merma_diaria (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    fecha         DATE          NOT NULL,  -- día operativo (fch - 1)
    codgas        INT           NOT NULL,  -- TG.dbo.Estaciones.Codigo
    estacion      NVARCHAR(255) NOT NULL,  -- nombre denormalizado
    codprd        INT           NOT NULL,  -- 1,2,3,179,180,181,192,193
    producto      NVARCHAR(255) NULL,
    turno         INT           NOT NULL,  -- 11, 21, 41
    ventas_reales FLOAT         NULL,
    inv_fisico    FLOAT         NULL,      -- NULL = sin corte capturado
    compras       FLOAT         NULL,
    inv_inicial   FLOAT         NULL,
    inv_contable  FLOAT         NULL,
    diferencia    FLOAT         NULL,      -- negativo = merma
    updated_at    DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_merma_diaria UNIQUE (fecha, codgas, codprd, turno)
);
CREATE INDEX IX_merma_diaria_estacion ON dbo.merma_diaria (codgas, fecha);
END
GO

IF OBJECT_ID('dbo.merma_manual') IS NULL
BEGIN
CREATE TABLE dbo.merma_manual (
    id              INT IDENTITY(1,1) PRIMARY KEY,
    codgas          INT           NOT NULL,
    anio            INT           NOT NULL,
    mes             INT           NOT NULL,
    merma_sd_maxima FLOAT         NULL,
    merma_sd_super  FLOAT         NULL,
    merma_sd_diesel FLOAT         NULL,
    comentarios     NVARCHAR(MAX) NULL,
    updated_by      INT           NULL,    -- Id de usuario TG
    updated_at      DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_merma_manual UNIQUE (codgas, anio, mes)
);
END
GO

IF OBJECT_ID('dbo.merma_mes_config') IS NULL
BEGIN
CREATE TABLE dbo.merma_mes_config (
    id           INT      IDENTITY(1,1) PRIMARY KEY,
    anio         INT      NOT NULL,
    mes          INT      NOT NULL,
    precio_litro FLOAT    NOT NULL DEFAULT 18.99,
    updated_by   INT      NULL,
    updated_at   DATETIME NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_merma_mes_config UNIQUE (anio, mes)
);
END
GO

IF OBJECT_ID('dbo.merma_sync_log') IS NULL
BEGIN
CREATE TABLE dbo.merma_sync_log (
    id               INT IDENTITY(1,1) PRIMARY KEY,
    fecha_sync       DATETIME      NOT NULL DEFAULT GETDATE(),
    origen           NVARCHAR(10)  NOT NULL,  -- 'cron' | 'manual'
    usuario          INT           NULL,      -- NULL para cron
    desde            DATE          NOT NULL,
    hasta            DATE          NOT NULL,
    codgas           INT           NOT NULL DEFAULT 0,  -- 0 = todas
    estaciones_ok    INT           NOT NULL DEFAULT 0,
    estaciones_error INT           NOT NULL DEFAULT 0,
    detalle_errores  NVARCHAR(MAX) NULL,
    duracion_seg     FLOAT         NULL
);
END
GO
