/*
    TotalGas Tableros - allow sticker-only file comments

    Run in [tableros] after 006_file_comments.sql. This migration is additive,
    idempotent, and preserves all existing comment data. The application
    validates that a comment has text or at least one sticker.

    Rollback: the prior constraint cannot be restored safely while
    sticker-only comments exist. If rollback is required, first add text to
    every comment with an empty body, then recreate CK_tb_file_comment_not_empty.
    This migration does not modify comment rows.
*/
USE [tableros];
GO
SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.tb_file_comment', N'U') IS NULL
    THROW 50150, 'dbo.tb_file_comment no existe. Ejecute primero 006_file_comments.sql.', 1;
GO

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE [parent_object_id] = OBJECT_ID(N'dbo.tb_file_comment')
      AND [name] = N'CK_tb_file_comment_not_empty'
)
    ALTER TABLE dbo.tb_file_comment
        DROP CONSTRAINT [CK_tb_file_comment_not_empty];
GO

PRINT N'Migracion 010_allow_sticker_only_comments aplicada.';
GO
