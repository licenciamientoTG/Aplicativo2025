/*
    TotalGas Tableros - comments attached to files

    Additive and idempotent. Run after 001_create_schema.sql in [tableros].
    File comments belong to the logical file (not an individual version), so
    they remain available when the file receives a new version.

    Rollback: deploy the prior application code and leave this table in place
    to preserve comments. If the feature is retired, archive/backup the rows
    before deliberately dropping dbo.tb_file_comment; dropping it permanently
    deletes comment text and metadata. This migration does not execute rollback
    or destructive statements.
*/
USE [tableros];
GO
SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.tb_file', N'U') IS NULL
    THROW 50120, 'dbo.tb_file no existe. Ejecute primero 001_create_schema.sql.', 1;
GO

IF OBJECT_ID(N'dbo.tb_file_comment', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.tb_file_comment (
        [id] BIGINT IDENTITY(1,1) NOT NULL,
        [file_id] BIGINT NOT NULL,
        [parent_comment_id] BIGINT NULL,
        [body] NVARCHAR(MAX) NOT NULL,
        [created_by] INT NOT NULL,
        [updated_by] INT NULL,
        [created_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_file_comment_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] DATETIME2(3) NOT NULL
            CONSTRAINT [DF_tb_file_comment_updated_at] DEFAULT (SYSUTCDATETIME()),
        [deleted_at] DATETIME2(3) NULL,
        [version] ROWVERSION,
        CONSTRAINT [PK_tb_file_comment] PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [UQ_tb_file_comment_id_file] UNIQUE ([id], [file_id]),
        CONSTRAINT [FK_tb_file_comment_file]
            FOREIGN KEY ([file_id]) REFERENCES dbo.tb_file ([id]),
        CONSTRAINT [FK_tb_file_comment_parent_file]
            FOREIGN KEY ([parent_comment_id], [file_id])
            REFERENCES dbo.tb_file_comment ([id], [file_id]),
        CONSTRAINT [CK_tb_file_comment_not_empty]
            CHECK (DATALENGTH([body]) > 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE [object_id] = OBJECT_ID(N'dbo.tb_file_comment')
      AND [name] = N'IX_tb_file_comment_file_created'
)
    CREATE INDEX [IX_tb_file_comment_file_created]
        ON dbo.tb_file_comment ([file_id], [created_at] DESC, [id] DESC)
        WHERE [deleted_at] IS NULL;
GO

PRINT N'Migracion 006_file_comments aplicada.';
GO
