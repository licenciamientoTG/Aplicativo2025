/* ============================================================================
   Siembra el Capital de Trabajo BASE por sucursal (valores confirmados por
   negocio 2026-07-06; coinciden con el seed histórico de concentrado_extras).
   Requiere arqueo_capital_base (ver arqueo_schema.sql, sección 7).
   Seguro de re-ejecutar (NOT EXISTS evita duplicados; no pisa valores ya
   editados desde la aplicación).
   ============================================================================ */
USE [TG];
GO

DECLARE @base TABLE (sucursal_id INT, capital_trabajo DECIMAL(14,2));
INSERT INTO @base (sucursal_id, capital_trabajo) VALUES
    (1,  3090824.74), -- Waterfill
    (2,  390000.00),  -- Misiones
    (3,  300000.00),  -- Municipio
    (4,  350000.00),  -- Puerto de Palos
    (5,  300000.00),  -- Permuta
    (6,  280000.00),  -- Anapra
    (7,  250000.00),  -- Gomez Morin
    (8,  250000.00),  -- Lopez Mateos
    (9,  660000.00),  -- Villa Ahumada
    (10, 200000.00),  -- Km30
    (11, 650000.00),  -- Curva
    (12, 300000.00),  -- Custodia
    (13, 550000.00);  -- Perez Serna

INSERT INTO [TG].[dbo].[arqueo_capital_base] (sucursal_id, capital_trabajo)
SELECT b.sucursal_id, b.capital_trabajo
FROM @base b
WHERE NOT EXISTS (
    SELECT 1 FROM [TG].[dbo].[arqueo_capital_base] e
    WHERE e.sucursal_id = b.sucursal_id
);
GO

SELECT sucursal_id, capital_trabajo FROM [TG].[dbo].[arqueo_capital_base]
ORDER BY sucursal_id;
GO
