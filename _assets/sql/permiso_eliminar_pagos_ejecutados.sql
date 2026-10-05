/* Permiso para eliminar pagos ejecutados (transacciones) desde /payment/all_payments.
   El código valida authorized(102), por eso se fuerza el ID 102.
   Ejecutar una vez en TG; es idempotente. Después, asignarlo desde
   Administración > Permisos solo a quien deba poder corregir pagos.

   No requiere cambios de esquema: el historial se guarda en
   TG.dbo.PaymentRequestAuditLog con Operacion = 'DELETE_PAYMENT'. */
USE [TG];
GO

IF NOT EXISTS (SELECT 1 FROM [TG].[dbo].[tg_permissions] WHERE [id] = 102)
BEGIN
    SET IDENTITY_INSERT [TG].[dbo].[tg_permissions] ON;
    INSERT INTO [TG].[dbo].[tg_permissions]
        ([id],[action],[department],[description],[status],[updated_at],[created_at])
    VALUES
        (102,'delete','Tesorería','Eliminar pagos ejecutados',1,GETDATE(),GETDATE());
    SET IDENTITY_INSERT [TG].[dbo].[tg_permissions] OFF;
END
GO

SELECT [id],[action],[department],[description],[status]
FROM [TG].[dbo].[tg_permissions]
WHERE [id] = 102;
GO
