-- Evidencia (imagen/PDF) que justifica una corrección de corte físico hecha
-- desde /merma/corregir_fisico. No es obligatoria ni se sube forzosamente
-- junto con la corrección: se liga por (codgas, fecha, codprd, turno) —la
-- misma llave que ya identifica una celda "corregida" en merma_fisico_log—
-- no a una fila específica del log, porque puede haber varias correcciones
-- en el tiempo para la misma celda y la evidencia es general para esa celda.
USE [TG]
GO

CREATE TABLE [dbo].[merma_fisico_evidencia] (
    id                 INT IDENTITY(1,1) PRIMARY KEY,
    codgas             INT          NOT NULL,
    fecha              DATE         NOT NULL,
    codprd             INT          NOT NULL,
    turno              INT          NOT NULL,  -- turno normalizado 11/21/41
    file_path          VARCHAR(300) NOT NULL,
    file_extension     VARCHAR(10)  NOT NULL,
    original_filename  VARCHAR(255) NOT NULL,
    file_size          INT          NOT NULL,
    created_by         INT          NOT NULL,  -- TG.dbo.Usuario.Id
    created_at         DATETIME     NOT NULL DEFAULT GETDATE()
);
GO

CREATE INDEX IX_merma_fisico_evidencia_lookup
    ON [dbo].[merma_fisico_evidencia] (codgas, fecha, codprd, turno);
GO

-- Soft-delete: el archivo nunca se borra de disco, solo se oculta de la
-- lista. Migración para tablas ya creadas con el schema de arriba.
ALTER TABLE [dbo].[merma_fisico_evidencia] ADD
    is_deleted INT      NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    deleted_by INT      NULL;  -- TG.dbo.Usuario.Id
GO
