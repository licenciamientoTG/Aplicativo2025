/* Rollback de alter_inventario_terminal_tipo.sql en TG.
   Se cancela si hay terminales Verifone para evitar perder su clasificación. */
USE [TG];

IF OBJECT_ID(N'[dbo].[inv_urovo_terminales]', N'U') IS NULL
    THROW 51000, 'No existe dbo.inv_urovo_terminales en TG.', 1;

IF COL_LENGTH(N'dbo.inv_urovo_terminales', N'tipo_terminal') IS NOT NULL
   AND EXISTS (
       SELECT 1 FROM [dbo].[inv_urovo_terminales]
       WHERE [tipo_terminal] = 'verifone'
   )
    THROW 51000, 'Rollback cancelado: hay registros Verifone; quitar la columna perdería esa clasificación.', 1;

IF EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE [parent_object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'CK_inv_urovo_terminales_tipo_terminal'
)
    ALTER TABLE [dbo].[inv_urovo_terminales] DROP CONSTRAINT [CK_inv_urovo_terminales_tipo_terminal];

IF EXISTS (
    SELECT 1 FROM sys.default_constraints
    WHERE [parent_object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'DF_inv_urovo_terminales_tipo_terminal'
)
    ALTER TABLE [dbo].[inv_urovo_terminales] DROP CONSTRAINT [DF_inv_urovo_terminales_tipo_terminal];

IF COL_LENGTH(N'dbo.inv_urovo_terminales', N'tipo_terminal') IS NOT NULL
    ALTER TABLE [dbo].[inv_urovo_terminales] DROP COLUMN [tipo_terminal];
