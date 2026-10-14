-- Análisis ejecutivo generado por IA (Gemini) para el DASHBOARD de
-- /merma/ventas. Prototipo: uno por (anio, mes, fecha de generación);
-- regenerar el mismo día sobrescribe la fila, al día siguiente se crea otra.
-- La vista muestra el más reciente del mes consultado.
USE [TG]
GO

CREATE TABLE [dbo].[merma_ventas_analisis_ia] (
    id         INT IDENTITY(1,1) PRIMARY KEY,
    anio       INT           NOT NULL,
    mes        INT           NOT NULL,
    fecha      DATE          NOT NULL,  -- día en que se generó
    texto      NVARCHAR(MAX) NOT NULL,  -- markdown simple devuelto por el modelo
    modelo     VARCHAR(60)   NOT NULL,
    usuario    INT           NOT NULL,  -- TG.dbo.Usuario.Id
    creado_en  DATETIME      NOT NULL DEFAULT GETDATE(),
    CONSTRAINT UQ_merma_ventas_analisis_ia UNIQUE (anio, mes, fecha)
);
GO
