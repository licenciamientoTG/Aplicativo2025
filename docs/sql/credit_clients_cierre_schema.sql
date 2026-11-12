-- docs/sql/credit_clients_cierre_schema.sql
-- Foto diaria de clientes crédito: cierre del DÍA DE AYER por cliente
-- (/income/clients, tab "Estado de Cuenta Foto Crédito"). Esquema de vigencia:
-- una fila nueva solo cuando cambia algún monto. Más la bitácora de corridas.
-- Spec: docs/superpowers/specs/2026-11-12-credit-clients-cierre-diario-design.md
USE TG;
GO

IF OBJECT_ID('dbo.credit_clients_cierre') IS NULL
BEGIN
CREATE TABLE dbo.credit_clients_cierre (
    id                     INT IDENTITY(1,1) PRIMARY KEY,
    codcli                 INT            NOT NULL,
    cliente                NVARCHAR(255)  NOT NULL,
    debglo                 TINYINT        NOT NULL DEFAULT 0,  -- 1 = maneja bolsa global
    limite                 DECIMAL(18,2)  NOT NULL DEFAULT 0,  -- Clientes.mtoasg
    fecha_desde            DATE           NOT NULL,
    fecha_hasta            DATE           NULL,                -- NULL = vigente

    -- Saldos iniciales del día fecha_desde (positivo = lo que debe)
    saldo_ini_sistema      DECIMAL(18,2)  NULL,                -- saldo_fin_sistema de la fila anterior
    saldo_ini_calc_desp    DECIMAL(18,2)  NOT NULL,            -- final calc. anterior; sin anterior se siembra con el sistema
    saldo_ini_calc_cortes  DECIMAL(18,2)  NOT NULL,

    -- Movimientos del día fecha_desde
    cobros_dia             DECIMAL(18,2)  NOT NULL,            -- MovimientosAnl tipope 4 tipmov 1 (positivo)
    notas_dia              DECIMAL(18,2)  NOT NULL,            -- MovimientosAnl tipope 6 tipmov 1 (positivo)
    facturado_dia          DECIMAL(18,2)  NOT NULL,            -- DocumentosC tip 3 (informativo, no mueve el saldo)
    consumos_desp_dia      DECIMAL(18,2)  NOT NULL,            -- Despachos por fchtrn
    consumos_cortes_dia    DECIMAL(18,2)  NOT NULL,            -- ValesR codval 28 por fch
    desfase_dia            DECIMAL(18,2)  NOT NULL,            -- consumos_desp_dia - consumos_cortes_dia

    -- Saldos finales del día
    saldo_fin_calc_desp    DECIMAL(18,2)  NOT NULL,            -- ini + despachos - cobros - notas
    saldo_fin_calc_cortes  DECIMAL(18,2)  NOT NULL,            -- ini + cortes - cobros - notas
    saldo_fin_sistema      DECIMAL(18,2)  NULL,                -- cresdo reconstruido al cierre; NULL en recuperación
    saldo_fin_vehiculos    DECIMAL(18,2)  NULL,                -- suma debsdo vehículos (contador) al cierre
    disponible             DECIMAL(18,2)  NULL,                -- limite - saldo_fin_sistema (limite > 0)

    -- Auditoría de la reconstrucción
    lectura_cresdo         DECIMAL(18,2)  NULL,
    lectura_vehiculos      DECIMAL(18,2)  NULL,
    consumos_post_cierre   DECIMAL(18,2)  NULL,                -- despachos de después del día hasta la lectura
    abonos_post_cierre     DECIMAL(18,2)  NULL,                -- cobros + notas con fecha posterior al día
    lectura_at             DATETIME       NULL,

    updated_at             DATETIME       NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_credit_cierre_cliente_desde UNIQUE (codcli, fecha_desde),
    CONSTRAINT CK_credit_cierre_fecha_orden CHECK (fecha_hasta IS NULL OR fecha_hasta >= fecha_desde)
);
CREATE INDEX IX_credit_cierre_vigente ON dbo.credit_clients_cierre (codcli, fecha_hasta);
CREATE INDEX IX_credit_cierre_fechas  ON dbo.credit_clients_cierre (fecha_desde, fecha_hasta);
END
GO

IF OBJECT_ID('dbo.credit_clients_cierre_runs') IS NULL
BEGIN
CREATE TABLE dbo.credit_clients_cierre_runs (
    id            INT IDENTITY(1,1) PRIMARY KEY,
    fecha_dia     DATE           NOT NULL,
    inicio        DATETIME       NOT NULL DEFAULT GETDATE(),
    fin           DATETIME       NULL,
    nuevos        INT            NOT NULL DEFAULT 0,
    actualizados  INT            NOT NULL DEFAULT 0,
    sin_cambio    INT            NOT NULL DEFAULT 0,
    cerrados      INT            NOT NULL DEFAULT 0,
    ok            BIT            NOT NULL DEFAULT 0,
    mensaje       NVARCHAR(1000) NULL,
    CONSTRAINT UQ_credit_cierre_runs_dia UNIQUE (fecha_dia)
);
END
GO
