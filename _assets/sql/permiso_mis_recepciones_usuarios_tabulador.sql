/* 2026-10-06
   1) Los usuarios ACTIVOS que pueden ver el Tabulador de Operaciones (permiso 21)
      reciben el permiso 84 (Ver Mis Recepciones) para entrar a
      Operaciones > Mis Recepciones. Solo consulta: subir documentos ahora
      requiere el 86.
   2) El permiso 86 pasa a cubrir subir Y eliminar documentos; se actualiza su
      descripción para que en Administración > Permisos se entienda.
   Idempotente: no duplica asignaciones existentes. */
USE [TG];

BEGIN TRANSACTION;

INSERT INTO [TG].[dbo].[tg_permissions_users] ([user_id], [permission_id], [updated_at], [created_at])
SELECT DISTINCT p21.user_id, 84, GETDATE(), GETDATE()
FROM [TG].[dbo].[tg_permissions_users] p21
JOIN [TG].[dbo].[Usuario] u ON u.Id = p21.user_id AND u.Estatus = 1
WHERE p21.permission_id = 21
  AND NOT EXISTS (
      SELECT 1 FROM [TG].[dbo].[tg_permissions_users] p84
      WHERE p84.user_id = p21.user_id AND p84.permission_id = 84
  );

SELECT @@ROWCOUNT AS usuarios_con_84_agregado;

UPDATE [TG].[dbo].[tg_permissions]
SET [description] = 'Mis Recepciones: subir y eliminar documentos', [updated_at] = GETDATE()
WHERE [id] = 86;

COMMIT TRANSACTION;

SELECT [id], [description] FROM [TG].[dbo].[tg_permissions] WHERE [id] IN (84, 86);
