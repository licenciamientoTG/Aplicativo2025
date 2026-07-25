-- Bitácora de correcciones de cortes físicos (StockReal) hechas desde el
-- aplicativo (/merma/corregir_fisico). El UPDATE se ejecuta en la estación
-- vía linked server; aquí queda quién, cuándo y qué valor se cambió.
USE [TG]
GO

CREATE TABLE [dbo].[merma_fisico_log] (
    id               INT IDENTITY(1,1) PRIMARY KEY,
    fecha_correccion DATETIME NOT NULL DEFAULT GETDATE(),
    usuario          INT      NOT NULL,          -- TG.dbo.Usuario.Id
    codgas           INT      NOT NULL,          -- estación (TG.dbo.Estaciones.Codigo)
    fecha            DATE     NOT NULL,          -- día del corte como se ve en el reporte
    fch              INT      NOT NULL,          -- serial ControlGas de la fila afectada
    codprd           INT      NOT NULL,          -- producto
    nrotur           INT      NOT NULL,          -- turno crudo (10/20/40)
    codtan           INT      NOT NULL,          -- tanque
    valor_anterior   FLOAT    NOT NULL,
    valor_nuevo      FLOAT    NOT NULL
);
GO

CREATE INDEX IX_merma_fisico_log_codgas_fecha ON [dbo].[merma_fisico_log] (codgas, fecha);
GO
