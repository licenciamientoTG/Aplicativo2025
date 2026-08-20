-- Reglas de auto-clasificación de Departamento por concepto, para
-- /tesoreria/movimientos_bancos_cheques. Se aplican al importar un cargo
-- nuevo (si no trae departamento capturado) y bajo demanda vía el botón
-- "Reaplicar reglas" (solo a cargos que sigan sin departamento: nunca pisa
-- una edición manual ya hecha).
-- Match: palabra_clave "contenida en" la descripción del cargo, sin
-- importar mayúsculas/minúsculas (stripos). Si varias reglas matchean el
-- mismo concepto, gana la de palabra_clave más larga/específica.
USE [TG]
GO

CREATE TABLE [dbo].[tarjetas_credito_reglas_departamento] (
    id               INT IDENTITY(1,1) PRIMARY KEY,
    palabra_clave    NVARCHAR(80) NOT NULL,
    departamento_id  INT          NOT NULL,  -- TG.dbo.Departamentos.Id
    activo           BIT          NOT NULL DEFAULT 1,
    created_by       INT          NULL,      -- TG.dbo.Usuario.Id
    created_at       DATETIME     NOT NULL DEFAULT GETDATE(),
    CONSTRAINT FK_tcrd_departamento FOREIGN KEY (departamento_id) REFERENCES [dbo].[Departamentos](Id)
);
GO
