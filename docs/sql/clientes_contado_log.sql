-- Bitácora de ediciones de clientes de contado (razón social/RFC/código
-- postal) hechas desde el aplicativo (/operations/clientes_contado). El
-- UPDATE se ejecuta primero en la estación vía linked server y luego se
-- replica a SG12 (corporativo); solo se registra aquí cuando AMBOS lados
-- quedaron correctamente actualizados (política todo o nada).
USE [TG]
GO

CREATE TABLE [dbo].[clientes_contado_log] (
    id               INT IDENTITY(1,1) PRIMARY KEY,
    fecha_correccion DATETIME      NOT NULL DEFAULT GETDATE(),
    usuario          INT           NOT NULL,          -- TG.dbo.Usuario.Id
    codgas           INT           NOT NULL,          -- estación (TG.dbo.Estaciones.Codigo)
    cod              INT           NOT NULL,          -- código del cliente
    campo            VARCHAR(20)   NOT NULL,          -- den | rfc | codpos
    valor_anterior   NVARCHAR(255) NULL,
    valor_nuevo      NVARCHAR(255) NULL,
    sg12_sync        BIT           NOT NULL DEFAULT 1 -- siempre 1: solo se inserta cuando SG12 también quedó sincronizado
);
GO

CREATE INDEX IX_clientes_contado_log_codgas_cod ON [dbo].[clientes_contado_log] (codgas, cod);
GO
