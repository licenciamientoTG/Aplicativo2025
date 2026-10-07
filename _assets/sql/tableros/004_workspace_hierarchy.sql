/*
    Upgrade for Monday-like workspace navigation.
    Additive and non-destructive: existing boards/folders are retained.
*/
USE [tableros];
GO

/* The seeded General workspace acts as the shared workspace. Its boards keep
   their individual visibility, so private boards remain private. */
UPDATE dbo.tb_workspace
SET [visibility] = 'workspace', [updated_at] = SYSUTCDATETIME()
WHERE [workspace_key] = N'general'
  AND [workspace_type] = 'workspace'
  AND [deleted_at] IS NULL
  AND [visibility] = 'private';
GO

IF COL_LENGTH(N'dbo.tb_folder', N'color') IS NULL
    ALTER TABLE dbo.tb_folder ADD [color] VARCHAR(32) NULL;
GO

/* monday.com currently permits three folder levels inside a workspace.
   Enforce the same limit at the database boundary and reject parent cycles. */
CREATE OR ALTER TRIGGER dbo.TR_tb_folder_max_depth
ON dbo.tb_folder
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @invalid BIT = 0;

    ;WITH folder_chain AS (
        SELECT i.[id], i.[workspace_id], i.[parent_folder_id],
               CONVERT(INT, 1) AS [depth],
               CONVERT(VARCHAR(MAX), '/' + CONVERT(VARCHAR(20), i.[id]) + '/') AS [visited],
               CONVERT(BIT, 0) AS [has_cycle]
        FROM inserted AS i
        WHERE i.[deleted_at] IS NULL

        UNION ALL

        SELECT p.[id], p.[workspace_id], p.[parent_folder_id],
               c.[depth] + 1,
               CONVERT(VARCHAR(MAX), c.[visited] + CONVERT(VARCHAR(20), p.[id]) + '/'),
               CONVERT(BIT, CASE
                   WHEN CHARINDEX('/' + CONVERT(VARCHAR(20), p.[id]) + '/', c.[visited]) > 0 THEN 1
                   ELSE 0
               END)
        FROM folder_chain AS c
        INNER JOIN dbo.tb_folder AS p
            ON p.[id] = c.[parent_folder_id]
           AND p.[workspace_id] = c.[workspace_id]
           AND p.[deleted_at] IS NULL
        WHERE c.[parent_folder_id] IS NOT NULL
          AND c.[depth] <= 3
          AND c.[has_cycle] = 0
    )
    SELECT @invalid = 1 FROM folder_chain WHERE [depth] > 3 OR [has_cycle] = 1;
    IF @invalid = 1
    BEGIN
        THROW 50110, 'Las carpetas permiten hasta tres niveles y no pueden formar ciclos.', 1;
    END;
END;
GO

PRINT N'Upgrade 004_workspace_hierarchy aplicado.';
GO
