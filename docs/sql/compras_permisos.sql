/* Compras de combustible (/compras/*): permisos propios en tg_permissions.
   Los ids que se generan van en las constantes PERM_* de _assets/controllers/compras.php
   y en el sidebar (esperados 112 y 113; confirmar con el SELECT final). */
USE [TG];
GO
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE [department] = 'Abastos' AND [description] = 'Compras de combustible - Ver registro')
    INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
    VALUES ('read','Abastos','Compras de combustible - Ver registro','1',GETDATE(),GETDATE());
IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE [department] = 'Abastos' AND [description] = 'Compras de combustible - Capturar y editar')
    INSERT INTO [TG].[dbo].[tg_permissions] ([action],[department],[description],[status],[updated_at],[created_at])
    VALUES ('update','Abastos','Compras de combustible - Capturar y editar','1',GETDATE(),GETDATE());
GO
SELECT [id],[action],[description] FROM [TG].[dbo].[tg_permissions] WHERE [description] LIKE 'Compras de combustible%';
GO
