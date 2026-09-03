/* =====================================================================
   Permiso para la herramienta "Expediente de facturas"
   (Ingresos -> Herramientas -> /income/expediente_facturas)

   Ejecutar UNA sola vez.

   views/layouts/sidebar.html ya apunta a authorized(94): el SELECT final debe
   devolver id = 94. Si devuelve otro (porque el ambiente ya tenía más permisos
   dados de alta), hay que igualar el número en el sidebar.

   Falta asignar el permiso a los usuarios desde /it (Permisos): hasta que se
   asigne, la opción no aparece en el menú de nadie. La página sí responde en
   /income/expediente_facturas, porque index.php no valida permisos por método.

   Mismo patrón que PermissionsModel::add() (_assets/models/PermissionsModel.php:33).
   ===================================================================== */

USE [TG];
GO

-- Idempotente: si ya se corrió antes, no duplica el permiso.
IF NOT EXISTS (
        SELECT 1 FROM [TG].[dbo].[tg_permissions]
        WHERE [department] = 'Ingresos' AND [description] = 'Expediente de facturas')
BEGIN
    INSERT INTO [TG].[dbo].[tg_permissions]
        ([action], [department], [description], [status], [updated_at], [created_at])
    VALUES
        ('read', 'Ingresos', 'Expediente de facturas', 1, GETDATE(), GETDATE());
END
GO

-- Este es el id que va en sidebar.html.
SELECT [id] AS id_para_sidebar, [action], [department], [description], [status]
FROM [TG].[dbo].[tg_permissions]
WHERE [department] = 'Ingresos' AND [description] = 'Expediente de facturas';
GO
