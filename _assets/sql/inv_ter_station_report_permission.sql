/* Permiso de lectura para la vista de incidencias limitada a la estación del usuario.
   Ejecutar una vez en TG. Después, asignarlo únicamente a usuarios de estación
   desde Administración > Permisos. El acceso se limita usando IdEstacion de sesión. */
USE [TG];
GO

IF NOT EXISTS (
    SELECT 1
    FROM [TG].[dbo].[tg_permissions]
    WHERE [action]='read'
      AND [department]='Operaciones'
      AND [description]='Inventario terminales - Reporte de estación'
)
BEGIN
    INSERT INTO [TG].[dbo].[tg_permissions]
        ([action],[department],[description],[status],[updated_at],[created_at])
    VALUES
        ('read','Operaciones','Inventario terminales - Reporte de estación',1,GETDATE(),GETDATE());
END
GO

SELECT [id],[action],[department],[description],[status]
FROM [TG].[dbo].[tg_permissions]
WHERE [action]='read'
  AND [department]='Operaciones'
  AND [description]='Inventario terminales - Reporte de estación';
GO
