-- docs/superpowers/plans/sql/2026-08-09-recepcion-remisiones.sql
CREATE TABLE [TG].[dbo].[recepcion_remisiones] (
    id                 INT IDENTITY(1,1) PRIMARY KEY,
    nrotrn             INT NOT NULL,
    codgas             INT NOT NULL,
    fchtrn             INT NOT NULL,
    file_path          VARCHAR(500) NOT NULL,
    file_extension     VARCHAR(10) NOT NULL,
    original_filename  VARCHAR(255) NOT NULL,
    file_size          INT NOT NULL,
    created_by         INT NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT GETDATE(),
    is_deleted         BIT NOT NULL DEFAULT 0,
    deleted_at         DATETIME NULL,
    deleted_by         INT NULL
);
GO

CREATE INDEX IX_recepcion_remisiones_recepcion
    ON [TG].[dbo].[recepcion_remisiones] (codgas, fchtrn, nrotrn)
    WHERE is_deleted = 0;
GO

INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
VALUES ('read', 'Operaciones', 'Ver Mis Recepciones (portal estaciones)', 1, GETDATE(), GETDATE());

INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
VALUES ('read', 'Operaciones', 'Mis Recepciones: ver todas las estaciones', 1, GETDATE(), GETDATE());

INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
VALUES ('delete', 'Operaciones', 'Mis Recepciones: eliminar remisión', 1, GETDATE(), GETDATE());
