-- Documentos (factura/comprobante en PDF, JPG o PNG) adjuntos a un
-- movimiento de tarjeta de crédito. Se abre desde el icono junto al campo
-- Factura en /tesoreria/movimientos_bancos_cheques. Varios documentos por
-- movimiento (ej. factura + comprobante de pago aparte).
-- Mismo patrón que merma_fisico_evidencia.sql: insertar primero (sin path)
-- para obtener el id, mover el archivo a disco usando ese id como nombre,
-- luego actualizar file_path; soft-delete (el archivo nunca se borra de
-- disco, solo se oculta de la lista).
USE [TG]
GO

CREATE TABLE [dbo].[tarjetas_credito_documentos] (
    id                 INT IDENTITY(1,1) PRIMARY KEY,
    movimiento_id      INT          NOT NULL,  -- TG.dbo.tarjetas_credito_movimientos.id
    file_path          VARCHAR(300) NOT NULL,
    file_extension     VARCHAR(10)  NOT NULL,
    original_filename  VARCHAR(255) NOT NULL,
    file_size          INT          NOT NULL,
    created_by         INT          NOT NULL,  -- TG.dbo.Usuario.Id
    created_at         DATETIME     NOT NULL DEFAULT GETDATE(),
    is_deleted         INT          NOT NULL DEFAULT 0,
    deleted_at         DATETIME     NULL,
    deleted_by         INT          NULL       -- TG.dbo.Usuario.Id
);
GO

CREATE INDEX IX_tarjetas_credito_documentos_movimiento
    ON [dbo].[tarjetas_credito_documentos] (movimiento_id, is_deleted);
GO
