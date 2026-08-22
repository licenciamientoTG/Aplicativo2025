-- =====================================================================
-- Fase 0 (Plan maestro pago a proveedores combustible)
-- Guardar la ubicacion del XML (CFDI) junto a la del PDF ya existente.
--
-- Contexto: CorreoFactruras.py ya descarga el .xml junto al .pdf en
-- attachments/<proveedor>/. Estas columnas permiten registrar donde quedo
-- archivado ese XML, con la MISMA convencion que RutaArchivo/NombreArchivo:
--   RutaXml   -> ruta RELATIVA a la carpeta del importador,
--                p.ej. attachments\tesoro\procesadas\<uuid>.xml
--   NombreXml -> solo el nombre del archivo, p.ej. <uuid>.xml
--
-- Ambas quedan NULL cuando el proveedor no adjunto XML (acuses de Enerey,
-- historico anterior a Fase 0, proveedores que solo mandan PDF).
--
-- Idempotente: se puede correr varias veces sin error.
-- Base: TG    Tabla: dbo.FacturasRecibidas
-- =====================================================================

USE [TG];
GO

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo'
      AND TABLE_NAME   = 'FacturasRecibidas'
      AND COLUMN_NAME  = 'RutaXml'
)
BEGIN
    ALTER TABLE [dbo].[FacturasRecibidas] ADD [RutaXml] NVARCHAR(500) NULL;
    PRINT 'Columna RutaXml agregada.';
END
ELSE
    PRINT 'Columna RutaXml ya existia, sin cambios.';
GO

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'dbo'
      AND TABLE_NAME   = 'FacturasRecibidas'
      AND COLUMN_NAME  = 'NombreXml'
)
BEGIN
    ALTER TABLE [dbo].[FacturasRecibidas] ADD [NombreXml] NVARCHAR(255) NULL;
    PRINT 'Columna NombreXml agregada.';
END
ELSE
    PRINT 'Columna NombreXml ya existia, sin cambios.';
GO

-- Verificacion
SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'FacturasRecibidas'
  AND COLUMN_NAME IN ('RutaArchivo', 'NombreArchivo', 'RutaXml', 'NombreXml')
ORDER BY COLUMN_NAME;
GO
