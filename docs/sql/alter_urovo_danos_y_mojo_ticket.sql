/* Extiende el inventario UROVO central en TG.
   damage_types_json conserva la lista de daños como JSON válido cuando no es NULL.
   DBeaver: ejecutar como script completo; no ejecutar sobre bases de estaciones. */
USE [TG];

IF OBJECT_ID(N'[dbo].[inv_urovo_terminales]', N'U') IS NULL
    THROW 51000, 'No existe dbo.inv_urovo_terminales en TG.', 1;

IF COL_LENGTH(N'dbo.inv_urovo_terminales', N'damage_types_json') IS NULL
    ALTER TABLE [dbo].[inv_urovo_terminales] ADD [damage_types_json] NVARCHAR(MAX) NULL;

IF COL_LENGTH(N'dbo.inv_urovo_terminales', N'mojo_ticket_number') IS NULL
    ALTER TABLE [dbo].[inv_urovo_terminales] ADD [mojo_ticket_number] NVARCHAR(30) NULL;

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE [name] = N'CK_inv_urovo_terminales_damage_types_json' AND [parent_object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]'))
    ALTER TABLE [dbo].[inv_urovo_terminales] WITH CHECK ADD CONSTRAINT [CK_inv_urovo_terminales_damage_types_json]
        CHECK ([damage_types_json] IS NULL OR ISJSON([damage_types_json]) = 1);

