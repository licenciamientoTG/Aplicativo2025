USE [TG];
GO

UPDATE [TG].[dbo].[Estaciones]
SET ZonaConso = 2
WHERE Codigo = 199 AND (ZonaConso IS NULL OR ZonaConso <> 2);
GO
