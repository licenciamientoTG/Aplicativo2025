/*
   Historial de cambios de estación de usuarios. Contrato para el módulo:
   estacion_transferencias_historial con nombres de columnas en español.

   Despliegue: ejecutar una vez en la base TG; el script es idempotente.
   El permiso usa el ID 100 porque el módulo valida authorized(100).

   Reversión: después de respaldar/exportar el historial y quitar el uso del
   permiso desde la aplicación, eliminar los índices y la tabla indicados y
   borrar tg_permissions.id = 100. La eliminación de la tabla borra el historial.
*/
USE [TG];
GO

IF OBJECT_ID(N'dbo.estacion_transferencias_historial', N'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[estacion_transferencias_historial] (
        [id]                    BIGINT IDENTITY(1,1) NOT NULL,
        [tipo_operacion]        VARCHAR(10) NOT NULL,
        [usuario_id_1]          INT NOT NULL,
        [estacion_origen_1]     INT NOT NULL,
        [estacion_destino_1]    INT NOT NULL,
        [usuario_id_2]          INT NULL,
        [estacion_origen_2]     INT NULL,
        [estacion_destino_2]    INT NULL,
        [actor_usuario_id]      INT NOT NULL,
        [ocurrido_en]           DATETIME2(3) NOT NULL
            CONSTRAINT [DF_estacion_transferencias_historial_ocurrido_en]
            DEFAULT (SYSDATETIME()),
        CONSTRAINT [PK_estacion_transferencias_historial]
            PRIMARY KEY CLUSTERED ([id]),
        CONSTRAINT [CK_estacion_transferencias_historial_tipo]
            CHECK ([tipo_operacion] IN ('move', 'exchange')),
        CONSTRAINT [CK_estacion_transferencias_historial_usuario_2]
            CHECK (
                ([usuario_id_2] IS NULL AND [estacion_origen_2] IS NULL AND [estacion_destino_2] IS NULL)
                OR
                ([usuario_id_2] IS NOT NULL AND [estacion_origen_2] IS NOT NULL AND [estacion_destino_2] IS NOT NULL)
            ),
        CONSTRAINT [CK_estacion_transferencias_historial_exchange]
            CHECK ([tipo_operacion] <> 'exchange' OR [usuario_id_2] IS NOT NULL),
        CONSTRAINT [CK_estacion_transferencias_historial_move]
            CHECK ([tipo_operacion] <> 'move' OR [usuario_id_2] IS NULL)
    );
END;
GO

IF OBJECT_ID(N'dbo.estacion_transferencias_historial', N'U') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1 FROM sys.indexes
       WHERE [object_id] = OBJECT_ID(N'dbo.estacion_transferencias_historial')
         AND [name] = N'IX_estacion_transferencias_historial_usuario_1_fecha'
   )
BEGIN
    CREATE INDEX [IX_estacion_transferencias_historial_usuario_1_fecha]
        ON [dbo].[estacion_transferencias_historial] ([usuario_id_1], [ocurrido_en] DESC);
END;
GO

IF OBJECT_ID(N'dbo.estacion_transferencias_historial', N'U') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1 FROM sys.indexes
       WHERE [object_id] = OBJECT_ID(N'dbo.estacion_transferencias_historial')
         AND [name] = N'IX_estacion_transferencias_historial_usuario_2_fecha'
   )
BEGIN
    CREATE INDEX [IX_estacion_transferencias_historial_usuario_2_fecha]
        ON [dbo].[estacion_transferencias_historial] ([usuario_id_2], [ocurrido_en] DESC)
        WHERE [usuario_id_2] IS NOT NULL;
END;
GO

/*
   Insertar explícitamente el permiso que consume authorized(100). Si el ID 100
   ya pertenece a otra acción, o el permiso existe con otro ID, detenerse para
   evitar que la aplicación y el catálogo queden desalineados.
*/
IF EXISTS (
    SELECT 1 FROM [dbo].[tg_permissions]
    WHERE [id] = 100
      AND NOT ([action] = 'read' AND [department] = 'Operaciones' AND [description] = 'Cambio de estación')
)
    THROW 50010, 'El permiso ID 100 ya está ocupado por otro permiso; no se insertó Cambio de estación.', 1;
GO

IF EXISTS (
    SELECT 1 FROM [dbo].[tg_permissions]
    WHERE [action] = 'read'
      AND [department] = 'Operaciones'
      AND [description] = 'Cambio de estación'
      AND [id] <> 100
)
    THROW 50011, 'El permiso Cambio de estación ya existe con un ID distinto de 100.', 1;
GO

IF NOT EXISTS (SELECT 1 FROM [dbo].[tg_permissions] WHERE [id] = 100)
BEGIN
    BEGIN TRY
        SET IDENTITY_INSERT [dbo].[tg_permissions] ON;

        INSERT INTO [dbo].[tg_permissions]
            ([id], [action], [department], [description], [status], [updated_at], [created_at])
        VALUES
            (100, 'read', 'Operaciones', 'Cambio de estación', 1, GETDATE(), GETDATE());

        SET IDENTITY_INSERT [dbo].[tg_permissions] OFF;
    END TRY
    BEGIN CATCH
        IF OBJECTPROPERTY(OBJECT_ID(N'dbo.tg_permissions'), 'TableHasIdentity') = 1
            SET IDENTITY_INSERT [dbo].[tg_permissions] OFF;
        THROW;
    END CATCH;
END;
GO

SELECT [id], [action], [department], [description], [status]
FROM [dbo].[tg_permissions]
WHERE [id] = 100;
GO
