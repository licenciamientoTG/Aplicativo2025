-- Compras fantasma/duplicadas marcadas desde /merma (botón Validar compras)
-- para que el snapshot merma_diaria las ignore SOLO en este sistema (ControlGas
-- no se toca). El descuento se aplica en cada sync (aplicar_exclusiones) y el
-- recalc del libro amarillo absorbe el efecto en contable/diferencia.
USE [TG]
GO

CREATE TABLE [dbo].[merma_compras_excluidas] (
    id         INT IDENTITY(1,1) PRIMARY KEY,
    codgas     INT           NOT NULL,       -- TG.dbo.Estaciones.Codigo
    fecha      DATE          NOT NULL,       -- fecha operativa del doc
    fch        INT           NOT NULL,       -- serial ControlGas
    codprd     INT           NOT NULL,
    turno      INT           NOT NULL,       -- 11/21/41 (mapeado del nrotur)
    nro_doc    INT           NOT NULL,       -- folio en Movimientos de la estación
    litros     FLOAT         NOT NULL,       -- ROUND(can, 0), como suma el SP
    motivo     NVARCHAR(300) NULL,
    usuario    INT           NOT NULL,
    created_at DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_merma_compras_excl UNIQUE (codgas, fch, codprd, nro_doc)
);
GO

CREATE INDEX IX_merma_compras_excl_codgas_fecha ON [dbo].[merma_compras_excluidas] (codgas, fecha);
GO
