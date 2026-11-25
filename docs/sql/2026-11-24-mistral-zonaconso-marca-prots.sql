USE [TG];
GO

-- Gabriela Mistral (39) pasa de TSA AGS (ZonaConso 2) a MARCA Y PROTS (ZonaConso 1).
UPDATE [TG].[dbo].[Estaciones]
SET ZonaConso = 1
WHERE Codigo = 39 AND (ZonaConso IS NULL OR ZonaConso <> 1);
GO
