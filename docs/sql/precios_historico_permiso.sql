/* Permiso independiente para consultar el histórico de precios de combustibles.
   El permiso se asigna a Oliver de la Cruz (ID 6314, usuario oliver.delacruz).
   Ejecutar en TG mediante DBeaver. Es idempotente. */
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[Usuario] WHERE [Id] = 6314 AND [Usuario] = 'oliver.delacruz')
    THROW 51000, 'No se encontró el usuario Oliver esperado (6314 / oliver.delacruz).', 1;

BEGIN TRANSACTION;

IF NOT EXISTS (
    SELECT 1 FROM [TG].[dbo].[tg_permissions]
    WHERE [action] = 'read' AND [department] = 'Abastos'
      AND [description] = 'Histórico precios combustibles - Lectura'
)
    INSERT INTO [TG].[dbo].[tg_permissions]
        ([action], [department], [description], [status], [updated_at], [created_at])
    VALUES
        ('read', 'Abastos', 'Histórico precios combustibles - Lectura', 1, GETDATE(), GETDATE());

DECLARE @permission_id INT = (
    SELECT TOP 1 [id] FROM [TG].[dbo].[tg_permissions]
    WHERE [action] = 'read' AND [department] = 'Abastos'
      AND [description] = 'Histórico precios combustibles - Lectura'
      AND [status] = 1
    ORDER BY [id]
);

IF NOT EXISTS (
    SELECT 1 FROM [TG].[dbo].[tg_permissions_users]
    WHERE [user_id] = 6314 AND [permission_id] = @permission_id
)
    INSERT INTO [TG].[dbo].[tg_permissions_users]
        ([user_id], [permission_id], [updated_at], [created_at])
    VALUES (6314, @permission_id, GETDATE(), GETDATE());

COMMIT TRANSACTION;

SELECT p.[id], p.[action], p.[department], p.[description], pu.[user_id]
FROM [TG].[dbo].[tg_permissions] p
JOIN [TG].[dbo].[tg_permissions_users] pu ON pu.[permission_id] = p.[id]
WHERE pu.[user_id] = 6314 AND p.[description] = 'Histórico precios combustibles - Lectura';
