/*
    TotalGas Tableros - TG permission catalog seed

    Server-side prerequisite: confirm the intended SQL Server host and backup
    policy before execution. This file inserts only the three catalog rows in
    [TG].[dbo].[tg_permissions]. It creates no tables, grants nothing to users,
    and does not update matching rows. It stops if a discoverable name already
    exists with another action or appears more than once.

    Run with a SQL Server client that supports GO batch separators.
*/
USE [TG];
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.tg_permissions', N'U') IS NULL
    THROW 50200, 'No existe [TG].[dbo].[tg_permissions]. No se inserto ningun permiso.', 1;

SET TRANSACTION ISOLATION LEVEL SERIALIZABLE;

DECLARE @required_permissions TABLE (
    [action] VARCHAR(32) NOT NULL,
    [department] NVARCHAR(100) NOT NULL,
    [description] NVARCHAR(200) NOT NULL,
    PRIMARY KEY ([department], [description])
);

INSERT INTO @required_permissions ([action], [department], [description])
VALUES
    ('read',   N'General', N'Tableros - Acceso'),
    ('create', N'General', N'Tableros - Crear'),
    ('update', N'General', N'Tableros - Administrar');

BEGIN TRY
    BEGIN TRANSACTION;

    IF EXISTS (
        SELECT r.[department], r.[description]
        FROM @required_permissions AS r
        JOIN dbo.tg_permissions AS p WITH (UPDLOCK, HOLDLOCK)
          ON p.[department] = r.[department]
         AND p.[description] = r.[description]
        GROUP BY r.[department], r.[description]
        HAVING COUNT_BIG(*) > 1
    )
        THROW 50201, 'Hay nombres de permiso Tableros duplicados en TG. Revise el catalogo antes de continuar.', 1;

    IF EXISTS (
        SELECT 1
        FROM @required_permissions AS r
        JOIN dbo.tg_permissions AS p WITH (UPDLOCK, HOLDLOCK)
          ON p.[department] = r.[department]
         AND p.[description] = r.[description]
        WHERE p.[action] IS NULL OR p.[action] <> r.[action]
    )
        THROW 50202, 'Un permiso Tableros ya existe con otra accion. No se modifico el catalogo.', 1;

    INSERT INTO dbo.tg_permissions
        ([action], [department], [description], [status], [updated_at], [created_at])
    SELECT r.[action], r.[department], r.[description], 1, GETDATE(), GETDATE()
    FROM @required_permissions AS r
    WHERE NOT EXISTS (
        SELECT 1
        FROM dbo.tg_permissions AS p WITH (UPDLOCK, HOLDLOCK)
        WHERE p.[department] = r.[department]
          AND p.[description] = r.[description]
    );

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    SET TRANSACTION ISOLATION LEVEL READ COMMITTED;
    THROW;
END CATCH;

SET TRANSACTION ISOLATION LEVEL READ COMMITTED;

SELECT [id], [action], [department], [description], [status]
FROM dbo.tg_permissions
WHERE [department] = N'General'
  AND [description] IN (
      N'Tableros - Acceso', N'Tableros - Crear', N'Tableros - Administrar'
  )
ORDER BY [id];
GO
