/* Rollback del esquema Inventario UROVO. Conserva el permiso seed y aborta si
   hay datos de usuario en cualquiera de las tablas del módulo. */
USE [TG];
IF OBJECT_ID(N'[dbo].[inv_urovo_movimientos]', N'U') IS NOT NULL AND EXISTS (SELECT 1 FROM [dbo].[inv_urovo_movimientos])
    THROW 51000, 'Rollback cancelado: inv_urovo_movimientos contiene datos.', 1;
IF OBJECT_ID(N'[dbo].[inv_urovo_existencias_movimientos]', N'U') IS NOT NULL AND EXISTS (SELECT 1 FROM [dbo].[inv_urovo_existencias_movimientos])
    THROW 51000, 'Rollback cancelado: inv_urovo_existencias_movimientos contiene datos.', 1;
IF OBJECT_ID(N'[dbo].[inv_urovo_terminales]', N'U') IS NOT NULL AND EXISTS (SELECT 1 FROM [dbo].[inv_urovo_terminales])
    THROW 51000, 'Rollback cancelado: inv_urovo_terminales contiene datos.', 1;
IF EXISTS (SELECT 1 FROM [dbo].[tg_permissions] WHERE [action] = 'update' AND [department] = 'Sistemas' AND [description] = 'Inventario UROVO')
    UPDATE [dbo].[tg_permissions] SET [status] = '0', [updated_at] = GETDATE()
    WHERE [action] = 'update' AND [department] = 'Sistemas' AND [description] = 'Inventario UROVO';

IF OBJECT_ID(N'[dbo].[inv_urovo_movimientos]', N'U') IS NOT NULL DROP TABLE [dbo].[inv_urovo_movimientos];
IF OBJECT_ID(N'[dbo].[inv_urovo_existencias_movimientos]', N'U') IS NOT NULL DROP TABLE [dbo].[inv_urovo_existencias_movimientos];
IF OBJECT_ID(N'[dbo].[inv_urovo_terminales]', N'U') IS NOT NULL DROP TABLE [dbo].[inv_urovo_terminales];


