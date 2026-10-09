/*
    TotalGas Tableros - comment mentions, attachments and notifications

    Additive and idempotent. Run in [tableros] after 006_file_comments.sql.
    This migration does not insert, update or delete business data.

    TG user IDs are stored as integers without foreign keys because the users
    live in a separate database. Attachment membership (same board/item and
    private-file authorization) is validated by the application.

    Rollback: deploy prior application code and leave these objects in place.
    Dropping them later deletes mention, attachment and notification data.
*/
USE [tableros];
GO
SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.tb_file_comment', N'U') IS NULL
    THROW 50130, 'dbo.tb_file_comment no existe. Ejecute primero 006_file_comments.sql.', 1;
IF OBJECT_ID(N'dbo.tb_file', N'U') IS NULL
    THROW 50131, 'dbo.tb_file no existe. Ejecute primero 001_create_schema.sql.', 1;
IF OBJECT_ID(N'dbo.tb_item', N'U') IS NULL
    THROW 50132, 'dbo.tb_item no existe. Ejecute primero 001_create_schema.sql.', 1;
IF OBJECT_ID(N'dbo.tb_board', N'U') IS NULL
    THROW 50133, 'dbo.tb_board no existe. Ejecute primero 001_create_schema.sql.', 1;
IF OBJECT_ID(N'dbo.tb_comment', N'U') IS NULL
    THROW 50134, 'dbo.tb_comment no existe. Ejecute primero 001_create_schema.sql.', 1;
GO

IF COL_LENGTH(N'dbo.tb_file_comment', N'mentions_json') IS NULL
    ALTER TABLE dbo.tb_file_comment ADD [mentions_json] NVARCHAR(MAX) NULL;
GO

IF OBJECT_ID(N'dbo.tb_file_comment_attachment', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_file_comment_attachment (
        [file_comment_id] BIGINT NOT NULL,
        [file_id] BIGINT NOT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_file_comment_attachment_created_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_tb_file_comment_attachment]
            PRIMARY KEY CLUSTERED ([file_comment_id], [file_id]),
        CONSTRAINT [FK_tb_file_comment_attachment_comment]
            FOREIGN KEY ([file_comment_id]) REFERENCES dbo.tb_file_comment ([id]),
        CONSTRAINT [FK_tb_file_comment_attachment_file]
            FOREIGN KEY ([file_id]) REFERENCES dbo.tb_file ([id])
    );
END;
GO

IF OBJECT_ID(N'dbo.tb_notification', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_notification (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [user_id] INT NOT NULL,
        [actor_user_id] INT NULL,
        [board_id] BIGINT NULL,
        [item_id] BIGINT NULL,
        [file_id] BIGINT NULL,
        [comment_id] BIGINT NULL,
        [file_comment_id] BIGINT NULL,
        [event_type] VARCHAR(64) NOT NULL,
        [payload_json] NVARCHAR(MAX) NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_notification_created_at] DEFAULT (SYSUTCDATETIME()),
        [read_at] DATETIME2(3) NULL,
        [dedupe_key] NVARCHAR(200) NULL,
        CONSTRAINT [PK_tb_notification] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [FK_tb_notification_board]
            FOREIGN KEY ([board_id]) REFERENCES dbo.tb_board ([id]),
        CONSTRAINT [FK_tb_notification_item_board]
            FOREIGN KEY ([item_id], [board_id]) REFERENCES dbo.tb_item ([id], [board_id]),
        CONSTRAINT [FK_tb_notification_file]
            FOREIGN KEY ([file_id]) REFERENCES dbo.tb_file ([id]),
        CONSTRAINT [FK_tb_notification_comment]
            FOREIGN KEY ([comment_id]) REFERENCES dbo.tb_comment ([id]),
        CONSTRAINT [FK_tb_notification_file_comment]
            FOREIGN KEY ([file_comment_id]) REFERENCES dbo.tb_file_comment ([id]),
        CONSTRAINT [CK_tb_notification_event_type]
            CHECK (DATALENGTH([event_type]) > 0),
        CONSTRAINT [CK_tb_notification_payload_json]
            CHECK ([payload_json] IS NULL OR ISJSON([payload_json]) = 1)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_file_comment_attachment') AND [name] = N'IX_tb_file_comment_attachment_file')
    CREATE INDEX [IX_tb_file_comment_attachment_file]
        ON dbo.tb_file_comment_attachment ([file_id], [file_comment_id]);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_notification') AND [name] = N'IX_tb_notification_user_unread_created')
    CREATE INDEX [IX_tb_notification_user_unread_created]
        ON dbo.tb_notification ([user_id], [created_at] DESC, [id] DESC)
        INCLUDE ([actor_user_id], [event_type], [board_id], [item_id], [file_id], [comment_id], [file_comment_id])
        WHERE [read_at] IS NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_notification') AND [name] = N'UX_tb_notification_dedupe_key')
    CREATE UNIQUE INDEX [UX_tb_notification_dedupe_key]
        ON dbo.tb_notification ([user_id], [dedupe_key])
        WHERE [dedupe_key] IS NOT NULL;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_notification') AND [name] = N'IX_tb_notification_board_item_created')
    CREATE INDEX [IX_tb_notification_board_item_created]
        ON dbo.tb_notification ([board_id], [item_id], [created_at] DESC, [id] DESC)
        WHERE [board_id] IS NOT NULL;
GO

PRINT N'Migracion 007_comment_mentions_notifications aplicada.';
GO
