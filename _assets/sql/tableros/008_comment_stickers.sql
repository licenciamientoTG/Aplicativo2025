/*
    TotalGas Tableros - custom stickers for file comments

    Prerequisites: run in [tableros] after 006_file_comments.sql.
    Additive and idempotent; does not modify existing rows.

    Sticker catalogs are owned by the uploader, while sticker links may be
    used by any collaborator authorized to comment on the file. User IDs
    belong to TG and intentionally have no local foreign key. A sticker is
    retired by setting deleted_at; retain its row and stored object so linked
    comments remain renderable.

    Rollback: deploy prior application code and leave these objects in place.
    Deliberately dropping these tables later permanently removes sticker
    metadata and comment associations. Deleting stored sticker objects also
    makes existing comments that reference them unrenderable. This migration
    does not execute destructive rollback statements.
*/
USE [tableros];
GO
SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.tb_file_comment', N'U') IS NULL
    THROW 50140, 'dbo.tb_file_comment no existe. Ejecute primero 006_file_comments.sql.', 1;
GO

IF OBJECT_ID(N'dbo.tb_user_sticker', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_user_sticker (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [user_id] INT NOT NULL,
        [name] NVARCHAR(255) NOT NULL,
        [content_type] VARCHAR(100) NOT NULL,
        [byte_size] BIGINT NOT NULL,
        [sha256_hash] VARBINARY(32) NOT NULL,
        [storage_provider] VARCHAR(30) NOT NULL,
        [storage_container] NVARCHAR(128) NOT NULL,
        [storage_object_key] VARCHAR(512) NOT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_user_sticker_created_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        CONSTRAINT [PK_tb_user_sticker] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [CK_tb_user_sticker_byte_size] CHECK ([byte_size] > 0),
        CONSTRAINT [CK_tb_user_sticker_hash_length] CHECK (DATALENGTH([sha256_hash]) = 32),
        CONSTRAINT [CK_tb_user_sticker_name] CHECK (DATALENGTH([name]) > 0),
        CONSTRAINT [CK_tb_user_sticker_storage] CHECK (
            DATALENGTH([content_type]) > 0
            AND DATALENGTH([storage_provider]) > 0
            AND DATALENGTH([storage_object_key]) > 0
        )
    );
END;
GO

IF OBJECT_ID(N'dbo.tb_file_comment_sticker', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_file_comment_sticker (
        [file_comment_id] BIGINT NOT NULL,
        [sticker_id] BIGINT NOT NULL,
        [created_by] INT NOT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_file_comment_sticker_created_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_tb_file_comment_sticker]
            PRIMARY KEY CLUSTERED ([file_comment_id], [sticker_id]),
        CONSTRAINT [FK_tb_file_comment_sticker_comment]
            FOREIGN KEY ([file_comment_id]) REFERENCES dbo.tb_file_comment ([id]),
        CONSTRAINT [FK_tb_file_comment_sticker_sticker]
            FOREIGN KEY ([sticker_id]) REFERENCES dbo.tb_user_sticker ([id])
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE [object_id] = OBJECT_ID(N'dbo.tb_user_sticker')
      AND [name] = N'IX_tb_user_sticker_active_catalog'
)
    CREATE INDEX [IX_tb_user_sticker_active_catalog]
        ON dbo.tb_user_sticker ([user_id], [created_at] DESC, [id] DESC)
        WHERE [deleted_at] IS NULL;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE [object_id] = OBJECT_ID(N'dbo.tb_file_comment_sticker')
      AND [name] = N'IX_tb_file_comment_sticker_sticker'
)
    CREATE INDEX [IX_tb_file_comment_sticker_sticker]
        ON dbo.tb_file_comment_sticker ([sticker_id], [file_comment_id]);
GO

PRINT N'Migracion 008_comment_stickers aplicada.';
GO
