/* Rollback de alter_urovo_terminales_serie_opcional.sql en TG.
   Abortara si existe cualquier terminal sin serie: primero asigne serie a esos
   registros de forma revisada; no se eliminan ni transforman movimientos. */
USE [TG];

IF OBJECT_ID(N'[dbo].[inv_urovo_terminales]', N'U') IS NULL
    THROW 51000, 'No existe dbo.inv_urovo_terminales en TG.', 1;

IF EXISTS (
    SELECT 1 FROM [dbo].[inv_urovo_terminales]
    WHERE [numero_serie] IS NULL OR [numero_serie_normalizado] IS NULL
)
    THROW 51000, 'Rollback cancelado: existen terminales sin numero de serie. Asigne una serie valida antes de reintentar.', 1;

IF EXISTS (
    SELECT 1 FROM [dbo].[inv_urovo_terminales]
    WHERE LEN(LTRIM(RTRIM([numero_serie]))) = 0
       OR LEN(LTRIM(RTRIM([numero_serie_normalizado]))) = 0
)
    THROW 51000, 'Rollback cancelado: existen series vacias o blancas.', 1;

IF EXISTS (
    SELECT [numero_serie_normalizado]
    FROM [dbo].[inv_urovo_terminales]
    GROUP BY [numero_serie_normalizado]
    HAVING COUNT_BIG(*) > 1
)
    THROW 51000, 'Rollback cancelado: existen numeros de serie normalizados duplicados.', 1;

IF EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE [parent_object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'CK_inv_urovo_terminales_serie_par'
)
    ALTER TABLE [dbo].[inv_urovo_terminales] DROP CONSTRAINT [CK_inv_urovo_terminales_serie_par];

IF EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE [object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'UX_inv_urovo_terminales_serie_norm'
)
    DROP INDEX [UX_inv_urovo_terminales_serie_norm] ON [dbo].[inv_urovo_terminales];

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE [object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'numero_serie' AND [is_nullable] = 0
)
    ALTER TABLE [dbo].[inv_urovo_terminales] ALTER COLUMN [numero_serie] NVARCHAR(100) NOT NULL;

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE [object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'numero_serie_normalizado' AND [is_nullable] = 0
)
    ALTER TABLE [dbo].[inv_urovo_terminales] ALTER COLUMN [numero_serie_normalizado] NVARCHAR(100) NOT NULL;

IF NOT EXISTS (
    SELECT 1 FROM sys.key_constraints
    WHERE [parent_object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'UQ_inv_urovo_terminales_serie_norm'
)
    ALTER TABLE [dbo].[inv_urovo_terminales]
        ADD CONSTRAINT [UQ_inv_urovo_terminales_serie_norm] UNIQUE ([numero_serie_normalizado]);
