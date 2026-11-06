/* Revierte las columnas añadidas al inventario UROVO central en TG.
   Se cancela si alguna columna contiene datos para evitar pérdida. */
USE [TG];

IF OBJECT_ID(N'[dbo].[inv_urovo_terminales]', N'U') IS NULL
    THROW 51000, 'No existe dbo.inv_urovo_terminales en TG.', 1;

DECLARE @has_data BIT = 0;
IF COL_LENGTH(N'dbo.inv_urovo_terminales', N'damage_types_json') IS NOT NULL
    EXEC sys.sp_executesql N'IF EXISTS (SELECT 1 FROM [dbo].[inv_urovo_terminales] WHERE [damage_types_json] IS NOT NULL) SET @found = 1;', N'@found BIT OUTPUT', @found = @has_data OUTPUT;
IF @has_data = 1
    THROW 51000, 'Rollback cancelado: damage_types_json contiene datos.', 1;

IF COL_LENGTH(N'dbo.inv_urovo_terminales', N'mojo_ticket_number') IS NOT NULL
    EXEC sys.sp_executesql N'IF EXISTS (SELECT 1 FROM [dbo].[inv_urovo_terminales] WHERE [mojo_ticket_number] IS NOT NULL) SET @found = 1;', N'@found BIT OUTPUT', @found = @has_data OUTPUT;
IF @has_data = 1
    THROW 51000, 'Rollback cancelado: mojo_ticket_number contiene datos.', 1;

IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE [name] = N'CK_inv_urovo_terminales_damage_types_json' AND [parent_object_id] = OBJECT_ID(N'[dbo].[inv_urovo_terminales]'))
    ALTER TABLE [dbo].[inv_urovo_terminales] DROP CONSTRAINT [CK_inv_urovo_terminales_damage_types_json];

IF COL_LENGTH(N'dbo.inv_urovo_terminales', N'damage_types_json') IS NOT NULL
    ALTER TABLE [dbo].[inv_urovo_terminales] DROP COLUMN [damage_types_json];
IF COL_LENGTH(N'dbo.inv_urovo_terminales', N'mojo_ticket_number') IS NOT NULL
    ALTER TABLE [dbo].[inv_urovo_terminales] DROP COLUMN [mojo_ticket_number];
