/*
    Papelera, archivo y limpieza diferida para Tableros.
    Aditivo e idempotente. Ejecutar tras 010_allow_sticker_only_comments.sql.
    Rollback: desplegar el código anterior y conservar las tablas/columnas.
    No eliminar estas tablas si hay objetos pendientes de restaurar o purgar.
*/
USE [tableros];
GO
SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.tb_trash_entry', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_trash_entry (
        [id] BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [entry_kind] VARCHAR(12) NOT NULL,
        [entity_type] VARCHAR(24) NOT NULL,
        [entity_id] BIGINT NOT NULL,
        [entity_name] NVARCHAR(500) NOT NULL,
        [location] NVARCHAR(1000) NULL,
        [workspace_id] BIGINT NULL,
        [board_id] BIGINT NULL,
        [deleted_by] INT NOT NULL,
        [deleted_at] DATETIME2(3) NOT NULL CONSTRAINT [DF_tb_trash_entry_deleted_at] DEFAULT (SYSUTCDATETIME()),
        [expires_at] DATETIME2(3) NULL,
        [purge_requested_at] DATETIME2(3) NULL,
        [restored_at] DATETIME2(3) NULL,
        CONSTRAINT [CK_tb_trash_entry_kind] CHECK ([entry_kind] IN ('trash', 'archive')),
        CONSTRAINT [CK_tb_trash_entry_expiry] CHECK (([entry_kind] = 'trash' AND [expires_at] IS NOT NULL) OR ([entry_kind] = 'archive' AND [expires_at] IS NULL))
    );
END;
GO

IF COL_LENGTH(N'dbo.tb_trash_entry', N'purge_started_at') IS NULL
    ALTER TABLE dbo.tb_trash_entry ADD [purge_started_at] DATETIME2(3) NULL;
GO

IF OBJECT_ID(N'dbo.tb_trash_entity', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_trash_entity (
        [entry_id] BIGINT NOT NULL,
        [table_name] SYSNAME NOT NULL,
        [entity_id] BIGINT NOT NULL,
        CONSTRAINT [PK_tb_trash_entity] PRIMARY KEY ([entry_id], [table_name], [entity_id]),
        CONSTRAINT [FK_tb_trash_entity_entry] FOREIGN KEY ([entry_id]) REFERENCES dbo.tb_trash_entry ([id])
    );
END;
GO

IF OBJECT_ID(N'dbo.tb_storage_cleanup', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_storage_cleanup (
        [id] BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [entry_id] BIGINT NOT NULL,
        [storage_provider] VARCHAR(32) NOT NULL,
        [storage_container] NVARCHAR(128) NOT NULL,
        [storage_object_key] NVARCHAR(512) NOT NULL,
        [attempts] INT NOT NULL CONSTRAINT [DF_tb_storage_cleanup_attempts] DEFAULT (0),
        [last_error] NVARCHAR(1000) NULL,
        [completed_at] DATETIME2(3) NULL,
        [created_at] DATETIME2(3) NOT NULL CONSTRAINT [DF_tb_storage_cleanup_created_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [FK_tb_storage_cleanup_entry] FOREIGN KEY ([entry_id]) REFERENCES dbo.tb_trash_entry ([id]),
        CONSTRAINT [UQ_tb_storage_cleanup_object] UNIQUE ([entry_id], [storage_provider], [storage_container], [storage_object_key])
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_trash_entry') AND [name] = N'IX_tb_trash_entry_active_owner')
    CREATE INDEX [IX_tb_trash_entry_active_owner] ON dbo.tb_trash_entry ([entry_kind], [deleted_by], [deleted_at] DESC) INCLUDE ([entity_type], [entity_name], [workspace_id], [board_id], [expires_at]) WHERE [restored_at] IS NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_storage_cleanup') AND [name] = N'IX_tb_storage_cleanup_pending')
    CREATE INDEX [IX_tb_storage_cleanup_pending] ON dbo.tb_storage_cleanup ([completed_at], [id]) INCLUDE ([entry_id], [attempts]) WHERE [completed_at] IS NULL;
GO
PRINT N'Migracion 011_trash_archive aplicada.';
GO
