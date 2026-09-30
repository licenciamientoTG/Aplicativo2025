/*
    Creates a ControlGas user-value backup table in TG only.

    Reads SG12.dbo.Usuarios to copy source column types and lengths. This script
    does not write to SG12 or alter its schema. Run with access to both databases.
    Existing target tables are not replaced; missing schema pieces are completed.
*/
SET XACT_ABORT ON;
BEGIN TRY
    BEGIN TRANSACTION;
    IF OBJECT_ID(N'[TG].[dbo].[ControlgasUserDisableBackup]', N'U') IS NULL
    BEGIN
        SELECT TOP (0)
            CAST(u.[COD] AS bigint) AS [cod],
            u.[DEN] AS [den_original],
            u.[CLV] AS [clv_original],
            u.[ACC] AS [acc_original],
            u.[ACCX] AS [accx_original],
            u.[CODROL] AS [codrol_original],
            u.[CODEST] AS [codest_original],
            u.[SERFAC] AS [serfac_original],
            u.[MDAFAC] AS [mdafac_original],
            u.[EFEFAC] AS [efefac_original],
            u.[ACC2] AS [acc2_original],
            u.[ACCX2] AS [accx2_original]
        INTO [TG].[dbo].[ControlgasUserDisableBackup]
        FROM [SG12].[dbo].[Usuarios] AS u;
    END;

    IF EXISTS (
        SELECT 1
        FROM [TG].[sys].[columns]
        WHERE [object_id] = OBJECT_ID(N'[TG].[dbo].[ControlgasUserDisableBackup]')
          AND [name] = N'cod'
          AND [is_nullable] = 1
    )
    BEGIN
        ALTER TABLE [TG].[dbo].[ControlgasUserDisableBackup]
            ALTER COLUMN [cod] bigint NOT NULL;
    END;

    IF COL_LENGTH(N'TG.dbo.ControlgasUserDisableBackup', N'is_active') IS NULL
    BEGIN
        ALTER TABLE [TG].[dbo].[ControlgasUserDisableBackup]
            ADD [is_active] bit NOT NULL
                CONSTRAINT [DF_ControlgasUserDisableBackup_is_active] DEFAULT (0) WITH VALUES;
    END;

    IF COL_LENGTH(N'TG.dbo.ControlgasUserDisableBackup', N'captured_at') IS NULL
    BEGIN
        ALTER TABLE [TG].[dbo].[ControlgasUserDisableBackup]
            ADD [captured_at] datetime2 NULL;
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM [TG].[sys].[key_constraints]
        WHERE [parent_object_id] = OBJECT_ID(N'[TG].[dbo].[ControlgasUserDisableBackup]')
          AND [type] = 'PK'
    )
    BEGIN
        ALTER TABLE [TG].[dbo].[ControlgasUserDisableBackup]
            ADD CONSTRAINT [PK_ControlgasUserDisableBackup] PRIMARY KEY ([cod]);
    END;
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;

/*
    Rollback: after all active snapshots have been recovered or exported, the
    TG backup table can be removed with:
    DROP TABLE [TG].[dbo].[ControlgasUserDisableBackup];
*/
