/*
    Canonical visibility and board roles for Tableros access control.
    Forward-only, idempotent migration. Run after 011_trash_archive.sql and
    before deploying application code that reads/writes the canonical values.
    No rows are deleted. Existing board-member editor grants become contributor
    so the new editor role does not expand access.
*/
USE [tableros];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    /* Drop checks before rewriting values; use the known schema constraint names. */
    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE [parent_object_id] = OBJECT_ID(N'dbo.tb_workspace') AND [name] = N'CK_tb_workspace_visibility')
        ALTER TABLE dbo.tb_workspace DROP CONSTRAINT [CK_tb_workspace_visibility];
    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE [parent_object_id] = OBJECT_ID(N'dbo.tb_board') AND [name] = N'CK_tb_board_visibility')
        ALTER TABLE dbo.tb_board DROP CONSTRAINT [CK_tb_board_visibility];
    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE [parent_object_id] = OBJECT_ID(N'dbo.tb_board_member') AND [name] = N'CK_tb_board_member_role')
        ALTER TABLE dbo.tb_board_member DROP CONSTRAINT [CK_tb_board_member_role];

    UPDATE dbo.tb_workspace
       SET [visibility] = CASE [visibility]
           WHEN 'private' THEN 'closed'
           WHEN 'workspace' THEN 'open'
           WHEN 'public' THEN 'open'
           ELSE [visibility]
       END
     WHERE [visibility] IN ('private', 'workspace', 'public');

    UPDATE dbo.tb_board
       SET [visibility] = 'organization'
     WHERE [visibility] = 'public';

    UPDATE dbo.tb_board_member
       SET [role] = 'contributor'
     WHERE [role] = 'editor';

    /* Fail instead of silently accepting an unexpected legacy value. */
    IF EXISTS (SELECT 1 FROM dbo.tb_workspace WHERE [visibility] NOT IN ('open', 'closed'))
        THROW 51200, 'tb_workspace contiene una visibilidad no reconocida.', 1;
    IF EXISTS (SELECT 1 FROM dbo.tb_board WHERE [visibility] NOT IN ('private', 'workspace', 'organization'))
        THROW 51201, 'tb_board contiene una visibilidad no reconocida.', 1;
    IF EXISTS (SELECT 1 FROM dbo.tb_board_member WHERE [role] NOT IN ('designer', 'editor', 'contributor', 'viewer'))
        THROW 51202, 'tb_board_member contiene un rol no reconocido.', 1;

    ALTER TABLE dbo.tb_workspace WITH CHECK ADD CONSTRAINT [CK_tb_workspace_visibility]
        CHECK ([visibility] IN ('open', 'closed'));
    ALTER TABLE dbo.tb_board WITH CHECK ADD CONSTRAINT [CK_tb_board_visibility]
        CHECK ([visibility] IN ('private', 'workspace', 'organization'));
    ALTER TABLE dbo.tb_board_member WITH CHECK ADD CONSTRAINT [CK_tb_board_member_role]
        CHECK ([role] IN ('designer', 'editor', 'contributor', 'viewer'));

    /* The workspace default follows the new canonical vocabulary. */
    DECLARE @workspace_default_name SYSNAME;
    SELECT @workspace_default_name = dc.[name]
      FROM sys.default_constraints AS dc
      JOIN sys.columns AS c ON c.[object_id] = dc.[parent_object_id] AND c.[column_id] = dc.[parent_column_id]
     WHERE dc.[parent_object_id] = OBJECT_ID(N'dbo.tb_workspace') AND c.[name] = N'visibility';
    IF @workspace_default_name IS NOT NULL
        EXEC(N'ALTER TABLE dbo.tb_workspace DROP CONSTRAINT ' + QUOTENAME(@workspace_default_name) + N';');
    ALTER TABLE dbo.tb_workspace ADD CONSTRAINT [DF_tb_workspace_visibility] DEFAULT ('closed') FOR [visibility];

    IF OBJECT_ID(N'dbo.tb_access_audit', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.tb_access_audit (
            [id] BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT [PK_tb_access_audit] PRIMARY KEY CLUSTERED,
            [identity_key] NVARCHAR(200) NOT NULL,
            [actor_user_id] INT NOT NULL,
            [action] VARCHAR(64) NOT NULL,
            [resource_type] VARCHAR(32) NOT NULL,
            [resource_id] BIGINT NULL,
            [occurred_at] DATETIME2(3) NOT NULL CONSTRAINT [DF_tb_access_audit_occurred_at] DEFAULT (SYSUTCDATETIME()),
            [metadata_json] NVARCHAR(MAX) NULL,
            CONSTRAINT [CK_tb_access_audit_metadata_json] CHECK ([metadata_json] IS NULL OR ISJSON([metadata_json]) = 1)
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_access_audit') AND [name] = N'IX_tb_access_audit_identity_time')
        CREATE INDEX [IX_tb_access_audit_identity_time] ON dbo.tb_access_audit ([identity_key], [occurred_at] DESC, [id] DESC) INCLUDE ([actor_user_id], [action], [resource_type], [resource_id]);
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE [object_id] = OBJECT_ID(N'dbo.tb_access_audit') AND [name] = N'IX_tb_access_audit_resource_time')
        CREATE INDEX [IX_tb_access_audit_resource_time] ON dbo.tb_access_audit ([resource_type], [resource_id], [occurred_at] DESC, [id] DESC) INCLUDE ([identity_key], [actor_user_id], [action]);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
PRINT N'Migracion 012_access_control aplicada.';
GO
