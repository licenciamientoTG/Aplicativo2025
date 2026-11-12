-- docs/sql/debit_clients_cierre_schema.sql
-- Foto diaria v2 de clientes débito: cierre del DÍA DE AYER por cliente
-- (/income/clients, tab "Estado de Cuenta Foto"). Esquema de vigencia: una
-- fila nueva solo cuando cambia algún monto. Más la bitácora de corridas.
-- Spec: docs/superpowers/specs/2026-11-11-debit-clients-cierre-diario-design.md
-- La v1 (dbo.debit_clients_snapshot) queda como respaldo, no se toca.
USE TG;
GO

IF OBJECT_ID('dbo.debit_clients_cierre') IS NULL
BEGIN
CREATE TABLE dbo.debit_clients_cierre (
    id                     INT IDENTITY(1,1) PRIMARY KEY,
    codcli                 INT            NOT NULL,
    cliente                NVARCHAR(255)  NOT NULL,
    debglo                 TINYINT        NOT NULL DEFAULT 0,  -- 1 = maneja bolsa global
    fecha_desde            DATE           NOT NULL,            -- día de la foto que abrió la fila
    fecha_hasta            DATE           NULL,                -- NULL = vigente

    -- Saldos iniciales del día fecha_desde
    saldo_ini_sistema      DECIMAL(18,2)  NULL,                -- saldo_fin_sistema de la fila anterior; NULL sin fila previa
    saldo_ini_calc_desp    DECIMAL(18,2)  NOT NULL,            -- anticipos - despachos de toda la historia antes del día
    saldo_ini_calc_cortes  DECIMAL(18,2)  NOT NULL,            -- anticipos - cortes (ValesR débito) antes del día
    ajuste_retro_desp      DECIMAL(18,2)  NULL,                -- saldo_ini_calc_desp - saldo_fin_calc_desp anterior
    ajuste_retro_cortes    DECIMAL(18,2)  NULL,                -- saldo_ini_calc_cortes - saldo_fin_calc_cortes anterior

    -- Movimientos del día fecha_desde
    anticipos_dia          DECIMAL(18,2)  NOT NULL,
    consumos_desp_dia      DECIMAL(18,2)  NOT NULL,            -- Despachos por fchtrn
    consumos_cortes_dia    DECIMAL(18,2)  NOT NULL,            -- ValesR codval 127 por fch (fecha de corte)
    desfase_dia            DECIMAL(18,2)  NOT NULL,            -- consumos_desp_dia - consumos_cortes_dia

    -- Saldos finales del día
    saldo_fin_calc_desp    DECIMAL(18,2)  NOT NULL,
    saldo_fin_calc_cortes  DECIMAL(18,2)  NOT NULL,
    saldo_fin_sistema      DECIMAL(18,2)  NULL,                -- debsdo reconstruido al cierre; NULL en días de recuperación
    saldo_fin_vehiculos    DECIMAL(18,2)  NULL,                -- suma vehículos reconstruida al cierre; NULL en recuperación

    -- Auditoría de la reconstrucción
    lectura_debsdo         DECIMAL(18,2)  NULL,
    lectura_vehiculos      DECIMAL(18,2)  NULL,
    consumos_post_cierre   DECIMAL(18,2)  NULL,                -- despachos de 00:00 a la lectura del día siguiente
    lectura_at             DATETIME       NULL,

    updated_at             DATETIME       NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_debit_cierre_cliente_desde UNIQUE (codcli, fecha_desde),
    CONSTRAINT CK_debit_cierre_fecha_orden CHECK (fecha_hasta IS NULL OR fecha_hasta >= fecha_desde)
);
CREATE INDEX IX_debit_cierre_vigente ON dbo.debit_clients_cierre (codcli, fecha_hasta);
CREATE INDEX IX_debit_cierre_fechas  ON dbo.debit_clients_cierre (fecha_desde, fecha_hasta);
END
GO

IF OBJECT_ID('dbo.debit_clients_cierre_runs') IS NULL
BEGIN
CREATE TABLE dbo.debit_clients_cierre_runs (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    fecha_dia     DATE           NOT NULL,      -- día procesado (ayer, o día recuperado)
    inicio        DATETIME       NOT NULL DEFAULT GETDATE(),
    fin           DATETIME       NULL,
    nuevos        INT            NOT NULL DEFAULT 0,
    actualizados  INT            NOT NULL DEFAULT 0,
    sin_cambio    INT            NOT NULL DEFAULT 0,
    cerrados      INT            NOT NULL DEFAULT 0,  -- clientes que salieron del universo
    ok            BIT            NOT NULL DEFAULT 0,
    mensaje       NVARCHAR(1000) NULL,
    CONSTRAINT UQ_debit_cierre_runs_dia UNIQUE (fecha_dia)
);
END
GO
