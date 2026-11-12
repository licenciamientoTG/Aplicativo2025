/* Agrega el tipo de terminal al inventario físico central de TG.
   Los registros existentes se clasifican como Urovo; los INSERT que omitan
   la columna también reciben 'urovo'. Ejecutar en DBeaver. */
USE [TG];

IF OBJECT_ID(N'[dbo].[inv_urovo_terminales]', N'U') IS NULL
    THROW 51000, 'No existe dbo.inv_urovo_terminales en TG.', 1;

IF COL_LENGTH(N'dbo.inv_urovo_terminales', N'tipo_terminal') IS NULL
    EXEC sys.sp_executesql N'ALTER TABLE [dbo].[inv_urovo_terminales] ADD [tipo_terminal] VARCHAR(20) NULL;';

/* Dynamic SQL makes the new column visible to SQL Server's batch compiler. */
EXEC sys.sp_executesql N'
    UPDATE [dbo].[inv_urovo_terminales]
    SET [tipo_terminal] = ''urovo''
    WHERE [tipo_terminal] IS NULL;';

IF EXISTS (
    SELECT 1
    FROM sys.columns
    WHERE [object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'tipo_terminal'
      AND [is_nullable] = 1
)
    EXEC sys.sp_executesql N'ALTER TABLE [dbo].[inv_urovo_terminales] ALTER COLUMN [tipo_terminal] VARCHAR(20) NOT NULL;';

IF NOT EXISTS (
    SELECT 1
    FROM sys.default_constraints AS dc
    INNER JOIN sys.columns AS c ON c.[object_id] = dc.[parent_object_id] AND c.[column_id] = dc.[parent_column_id]
    WHERE dc.[parent_object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND c.[name] = N'tipo_terminal'
)
    EXEC sys.sp_executesql N'ALTER TABLE [dbo].[inv_urovo_terminales] ADD CONSTRAINT [DF_inv_urovo_terminales_tipo_terminal] DEFAULT (''urovo'') FOR [tipo_terminal];';

IF NOT EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE [parent_object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'CK_inv_urovo_terminales_tipo_terminal'
)
    EXEC sys.sp_executesql N'ALTER TABLE [dbo].[inv_urovo_terminales] WITH CHECK ADD CONSTRAINT [CK_inv_urovo_terminales_tipo_terminal] CHECK ([tipo_terminal] IN (''urovo'',''verifone''));';
