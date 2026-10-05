-- Carta porte en Mis Recepciones (/station_portal/mis_recepciones).
-- Reutiliza TG.dbo.recepcion_remisiones: cada documento subido se distingue
-- por tipo_documento ('remision' | 'carta_porte'). Las filas existentes
-- quedan como 'remision' por el DEFAULT.

IF COL_LENGTH('TG.dbo.recepcion_remisiones', 'tipo_documento') IS NULL
BEGIN
    ALTER TABLE [TG].[dbo].[recepcion_remisiones]
        ADD tipo_documento VARCHAR(20) NOT NULL
            CONSTRAINT DF_recepcion_remisiones_tipo_documento DEFAULT 'remision'
            WITH VALUES;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE name = 'CK_recepcion_remisiones_tipo_documento'
)
BEGIN
    ALTER TABLE [TG].[dbo].[recepcion_remisiones]
        ADD CONSTRAINT CK_recepcion_remisiones_tipo_documento
            CHECK (tipo_documento IN ('remision', 'carta_porte'));
END
GO
