/*
  Registra el permiso 114 para consultar y cargar estados de cuenta de
  Banorte 0185322470. Ejecutar en TG una sola vez; el script es idempotente.
  Después, asignar el permiso 114 al usuario desde Administración > Permisos.
  No asigna permisos a usuarios.
*/
USE [TG];

IF EXISTS (
    SELECT 1 FROM [TG].[dbo].[tg_permissions]
    WHERE [id] = 114
      AND NOT ([action] = 'read' AND [department] = 'Tesorería'
               AND [description] = 'Banorte 0185322470')
)
    THROW 51114, 'El permiso ID 114 ya está ocupado; no se registró el permiso de la cuenta Banorte.', 1;

IF EXISTS (
    SELECT 1 FROM [TG].[dbo].[tg_permissions]
    WHERE [action] = 'read' AND [department] = 'Tesorería'
      AND [description] = 'Banorte 0185322470'
      AND [id] <> 114
)
    THROW 51112, 'El permiso de la cuenta Banorte ya existe con otro ID; revisar antes de continuar.', 1;

IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE [id] = 114)
BEGIN
    -- Limpia el estado de una ejecución previa que falló después del ON.
    SET IDENTITY_INSERT [TG].[dbo].[tg_permissions] OFF;
    BEGIN TRY
        SET IDENTITY_INSERT [TG].[dbo].[tg_permissions] ON;
        INSERT INTO [TG].[dbo].[tg_permissions]
            ([id], [action], [department], [description], [status], [updated_at], [created_at])
        VALUES
            (114, 'read', 'Tesorería', 'Banorte 0185322470', 1, GETDATE(), GETDATE());
        SET IDENTITY_INSERT [TG].[dbo].[tg_permissions] OFF;
    END TRY
    BEGIN CATCH
        SET IDENTITY_INSERT [TG].[dbo].[tg_permissions] OFF;
        THROW;
    END CATCH;
END;

SELECT [id], [action], [department], [description], [status]
FROM [TG].[dbo].[tg_permissions]
WHERE [id] = 114;
