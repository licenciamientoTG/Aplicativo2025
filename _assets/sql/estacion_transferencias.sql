/*
   Historial de cambios de estación de usuarios. Contrato para el módulo:
   estacion_transferencias_historial con nombres de columnas en español.

   Despliegue: ejecutar el script completo en SQL Editor conectado a TG;
   no usa GO, así que también funciona en clientes que lo envían a SQL Server
   como una instrucción normal. El script es idempotente.
   El permiso usa el ID 101 porque el ID 100 ya está asignado a otro permiso.

   Reversión: después de respaldar/exportar el historial y quitar el uso del
   permiso desde la aplicación, eliminar los índices y la tabla indicados y
   borrar tg_permissions.id = 100. La eliminación de la tabla borra el historial.
*/
USE [TG];

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

/*
   Insertar explícitamente el permiso que consume authorized(101). Si el ID 101
   ya pertenece a otra acción, o el permiso existe con otro ID, detenerse para
   evitar que la aplicación y el catálogo queden desalineados.
*/
IF EXISTS (
    SELECT 1 FROM [dbo].[tg_permissions]
    WHERE [id] = 101
      AND NOT ([action] = 'read' AND [department] = 'Operaciones' AND [description] = 'Cambio de estación')
)
BEGIN
    THROW 50010, 'El permiso ID 101 ya está ocupado por otro permiso; no se insertó Cambio de estación.', 1;
END;

IF EXISTS (
    SELECT 1 FROM [dbo].[tg_permissions]
    WHERE [action] = 'read'
      AND [department] = 'Operaciones'
      AND [description] = 'Cambio de estación'
      AND [id] <> 101
)
BEGIN
    THROW 50011, 'El permiso Cambio de estación ya existe con un ID distinto de 100.', 1;
END;

IF NOT EXISTS (SELECT 1 FROM [dbo].[tg_permissions] WHERE [id] = 101)
BEGIN
    BEGIN TRY
        SET IDENTITY_INSERT [dbo].[tg_permissions] ON;

        INSERT INTO [dbo].[tg_permissions]
            ([id], [action], [department], [description], [status], [updated_at], [created_at])
        VALUES
            (101, 'read', 'Operaciones', 'Cambio de estación', 1, GETDATE(), GETDATE());

        SET IDENTITY_INSERT [dbo].[tg_permissions] OFF;
    END TRY
    BEGIN CATCH
        IF OBJECTPROPERTY(OBJECT_ID(N'dbo.tg_permissions'), 'TableHasIdentity') = 1
            SET IDENTITY_INSERT [dbo].[tg_permissions] OFF;
        THROW;
    END CATCH;
END;

SELECT [id], [action], [department], [description], [status]
FROM [dbo].[tg_permissions]
WHERE [id] = 101;
