/* Permite terminales UROVO sin numero de serie en TG.
   Preflight: aborta ante series vacias/blancas o duplicados normalizados.
   No modifica movimientos ni existencias_movimientos; no requiere backfill.
   Ejecutar como script completo en DBeaver sobre TG; no ejecutar en estaciones. */
USE [TG];

IF OBJECT_ID(N'[dbo].[inv_urovo_terminales]', N'U') IS NULL
    THROW 51000, 'No existe dbo.inv_urovo_terminales en TG.', 1;

IF COL_LENGTH(N'dbo.inv_urovo_terminales', N'numero_serie') IS NULL
   OR COL_LENGTH(N'dbo.inv_urovo_terminales', N'numero_serie_normalizado') IS NULL
    THROW 51000, 'Faltan columnas de serie en dbo.inv_urovo_terminales.', 1;

IF EXISTS (
    SELECT 1
    FROM [dbo].[inv_urovo_terminales]
    WHERE ([numero_serie] IS NOT NULL AND LEN(LTRIM(RTRIM([numero_serie]))) = 0)
       OR ([numero_serie_normalizado] IS NOT NULL AND LEN(LTRIM(RTRIM([numero_serie_normalizado]))) = 0)
       OR (([numero_serie] IS NULL AND [numero_serie_normalizado] IS NOT NULL)
        OR ([numero_serie] IS NOT NULL AND [numero_serie_normalizado] IS NULL))
)
    THROW 51000, 'Migracion cancelada: hay series vacias/blancas o campos de serie incompletos. Corrija los registros antes de reintentar.', 1;

IF EXISTS (
    SELECT [numero_serie_normalizado]
    FROM [dbo].[inv_urovo_terminales]
    WHERE [numero_serie_normalizado] IS NOT NULL
    GROUP BY [numero_serie_normalizado]
    HAVING COUNT_BIG(*) > 1
)
    THROW 51000, 'Migracion cancelada: hay numeros de serie normalizados duplicados.', 1;

/* Eliminar primero la restriccion: su indice unico depende de la columna. */
IF EXISTS (
    SELECT 1 FROM sys.key_constraints
    WHERE [parent_object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'UQ_inv_urovo_terminales_serie_norm'
)
    ALTER TABLE [dbo].[inv_urovo_terminales] DROP CONSTRAINT [UQ_inv_urovo_terminales_serie_norm];

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE [object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'numero_serie' AND [is_nullable] = 1
)
    ALTER TABLE [dbo].[inv_urovo_terminales] ALTER COLUMN [numero_serie] NVARCHAR(100) NULL;

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE [object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'numero_serie_normalizado' AND [is_nullable] = 1
)
    ALTER TABLE [dbo].[inv_urovo_terminales] ALTER COLUMN [numero_serie_normalizado] NVARCHAR(100) NULL;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE [object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'UX_inv_urovo_terminales_serie_norm'
)
    CREATE UNIQUE INDEX [UX_inv_urovo_terminales_serie_norm]
        ON [dbo].[inv_urovo_terminales] ([numero_serie_normalizado])
        WHERE [numero_serie_normalizado] IS NOT NULL;

IF NOT EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE [parent_object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]')
      AND [name] = N'CK_inv_urovo_terminales_serie_par'
)
    ALTER TABLE [dbo].[inv_urovo_terminales] WITH CHECK ADD CONSTRAINT [CK_inv_urovo_terminales_serie_par]
        CHECK (
            ([numero_serie] IS NULL AND [numero_serie_normalizado] IS NULL)
            OR (
                [numero_serie] IS NOT NULL
                AND [numero_serie_normalizado] IS NOT NULL
                AND LEN(LTRIM(RTRIM([numero_serie]))) > 0
                AND LEN(LTRIM(RTRIM([numero_serie_normalizado]))) > 0
            )
        );
