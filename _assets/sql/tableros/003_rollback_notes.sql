/*
    TotalGas Tableros - non-destructive rollback notes

    Default behavior: this file is read-only. To roll back application code,
    deploy the prior application version and leave [tableros] data and the TG
    catalog rows in place. This preserves boards, files, comments, and history.
    The forward scripts do not assign any permissions to users.

    Server-side prerequisite for any optional cleanup: confirm the target host,
    verify a restorable backup, inspect active users and dependencies, and get
    the database owner's approval. Do not drop the database just to hide the
    module from the application.

    If an operator deliberately chooses a destructive database drop, all
    Tableros workspaces, boards, cells, people assignments, files metadata,
    comments, and history will be permanently removed from this database. A
    SQL backup is required for recovery. The destructive statements below
    remain comments and are not executed by this file.
*/
SET NOCOUNT ON;

/* Read-only permission and assignment review. */
IF DB_ID(N'TG') IS NOT NULL
BEGIN
    SELECT p.[id], p.[action], p.[department], p.[description], p.[status],
           COUNT(pu.[permission_id]) AS [assigned_user_count]
    FROM [TG].[dbo].[tg_permissions] AS p
    LEFT JOIN [TG].[dbo].[tg_permissions_users] AS pu
      ON pu.[permission_id] = p.[id]
    WHERE p.[department] = N'General'
      AND p.[description] IN (
          N'Tableros - Acceso', N'Tableros - Crear', N'Tableros - Administrar'
      )
    GROUP BY p.[id], p.[action], p.[department], p.[description], p.[status]
    ORDER BY p.[id];
END;

/* Read-only table row counts, if the data database exists. */
IF DB_ID(N'tableros') IS NOT NULL
BEGIN
    EXEC(N'
        USE [tableros];
        SELECT t.[name] AS [table_name], SUM(ps.[row_count]) AS [row_count]
        FROM sys.tables AS t
        JOIN sys.dm_db_partition_stats AS ps ON ps.[object_id] = t.[object_id]
        WHERE ps.[index_id] IN (0, 1)
        GROUP BY t.[name]
        ORDER BY t.[name];
    ');
END;

/*
    Optional permission-catalog cleanup, only after confirming that no user was
    assigned these permissions and that the module has been fully retired.
    This is intentionally commented out; permissions are preserved by default.

    USE [TG];
    DELETE p
    FROM dbo.tg_permissions AS p
    WHERE p.[department] = N'General'
      AND p.[description] IN (
          N'Tableros - Acceso', N'Tableros - Crear', N'Tableros - Administrar'
      )
      AND NOT EXISTS (
          SELECT 1 FROM dbo.tg_permissions_users AS pu
          WHERE pu.[permission_id] = p.[id]
      );

    Optional destructive database removal, after verified backup and approval:

    USE [master];
    ALTER DATABASE [tableros] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
    DROP DATABASE [tableros];
*/
