/*
    Item comment sticker and file associations.
    Run in [tableros] after 008_comment_stickers.sql and before deploying the
    application code that reads or writes these associations. The new tables
    are additive and idempotent; the migration also removes the old non-empty
    body check so a comment can consist of stickers alone. No existing rows
    are changed.

    Rollback: deploy the previous application version and retain these tables.
    To restore CK_tb_comment_not_empty, first ensure every comment body is
    non-empty; dropping either new table permanently removes its associations.
*/
USE [tableros];
SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.tb_comment', N'U') IS NULL
    THROW 50150, 'dbo.tb_comment no existe. Ejecute primero 001_create_schema.sql.', 1;
IF OBJECT_ID(N'dbo.tb_file', N'U') IS NULL
    THROW 50151, 'dbo.tb_file no existe. Ejecute primero 001_create_schema.sql.', 1;
IF OBJECT_ID(N'dbo.tb_user_sticker', N'U') IS NULL
    THROW 50152, 'dbo.tb_user_sticker no existe. Ejecute primero 008_comment_stickers.sql.', 1;

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE [parent_object_id] = OBJECT_ID(N'dbo.tb_comment') AND [name] = N'CK_tb_comment_not_empty')
    ALTER TABLE dbo.tb_comment DROP CONSTRAINT [CK_tb_comment_not_empty];

IF OBJECT_ID(N'dbo.tb_comment_sticker', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_comment_sticker (
        [comment_id] BIGINT NOT NULL,
        [sticker_id] BIGINT NOT NULL,
        [created_by] INT NOT NULL,
        [created_at] DATETIME2(3) NOT NULL CONSTRAINT [DF_tb_comment_sticker_created_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_tb_comment_sticker] PRIMARY KEY CLUSTERED ([comment_id], [sticker_id]),
        CONSTRAINT [FK_tb_comment_sticker_comment] FOREIGN KEY ([comment_id]) REFERENCES dbo.tb_comment ([id]),
        CONSTRAINT [FK_tb_comment_sticker_sticker] FOREIGN KEY ([sticker_id]) REFERENCES dbo.tb_user_sticker ([id])
    );
END;

IF OBJECT_ID(N'dbo.tb_comment_attachment', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_comment_attachment (
        [comment_id] BIGINT NOT NULL,
        [file_id] BIGINT NOT NULL,
        [created_at] DATETIME2(3) NOT NULL CONSTRAINT [DF_tb_comment_attachment_created_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_tb_comment_attachment] PRIMARY KEY CLUSTERED ([comment_id], [file_id]),
        CONSTRAINT [FK_tb_comment_attachment_comment] FOREIGN KEY ([comment_id]) REFERENCES dbo.tb_comment ([id]),
        CONSTRAINT [FK_tb_comment_attachment_file] FOREIGN KEY ([file_id]) REFERENCES dbo.tb_file ([id])
    );
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_comment_sticker') AND [name] = N'IX_tb_comment_sticker_sticker')
    CREATE INDEX [IX_tb_comment_sticker_sticker] ON dbo.tb_comment_sticker ([sticker_id], [comment_id]);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_comment_attachment') AND [name] = N'IX_tb_comment_attachment_file')
    CREATE INDEX [IX_tb_comment_attachment_file] ON dbo.tb_comment_attachment ([file_id], [comment_id]);

PRINT N'Migracion 015_item_comment_media aplicada.';
