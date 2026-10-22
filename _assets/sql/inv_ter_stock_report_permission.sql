USE [TG];

DECLARE @permission_count INT;
SELECT @permission_count=COUNT(*)
FROM [TG].[dbo].[tg_permissions]
WHERE [action]='read'
  AND [department]='Operaciones'
  AND [description]='Inventario terminales - Reporte de stock';

IF @permission_count=0
BEGIN
    INSERT INTO [TG].[dbo].[tg_permissions]
        ([action],[department],[description],[status],[updated_at],[created_at])
    VALUES
        ('read','Operaciones','Inventario terminales - Reporte de stock',1,GETDATE(),GETDATE());
END
ELSE IF @permission_count=1
BEGIN
    UPDATE [TG].[dbo].[tg_permissions]
    SET [status]=1,[updated_at]=GETDATE()
    WHERE [action]='read'
      AND [department]='Operaciones'
      AND [description]='Inventario terminales - Reporte de stock';
END;

SELECT [id],[action],[department],[description],[status]
FROM [TG].[dbo].[tg_permissions]
WHERE [action]='read'
  AND [department]='Operaciones'
  AND [description]='Inventario terminales - Reporte de stock';
