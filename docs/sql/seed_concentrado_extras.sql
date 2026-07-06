/* ============================================================================
   Siembra Capital de Trabajo (columna C del Excel "NUEVO 17 JUN.xlsx",
   hoja "Concentrado") para las sesiones de arqueo que ya existen.
   Gastos en trámite / Adeudo / Reinversión / Utilidad quedan en 0: se
   capturan desde el modal del Concentrado en adelante.

   Requiere que arqueo_concentrado_extras ya exista (ver arqueo_schema.sql).
   Seguro de re-ejecutar (NOT EXISTS evita duplicados).
   ============================================================================ */
USE [TG];
GO

DECLARE @capital TABLE (sucursal_id INT, capital_trabajo DECIMAL(14,2));
INSERT INTO @capital (sucursal_id, capital_trabajo) VALUES
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

INSERT INTO [TG].[dbo].[arqueo_concentrado_extras] (sesion_id, sucursal_id, capital_trabajo)
SELECT s.id, c.sucursal_id, c.capital_trabajo
FROM [TG].[dbo].[arqueo_sesiones] s
CROSS JOIN @capital c
WHERE NOT EXISTS (
    SELECT 1 FROM [TG].[dbo].[arqueo_concentrado_extras] e
    WHERE e.sesion_id = s.id AND e.sucursal_id = c.sucursal_id
);
GO

SELECT s.nombre AS sesion, e.sucursal_id, e.capital_trabajo
FROM [TG].[dbo].[arqueo_concentrado_extras] e
JOIN [TG].[dbo].[arqueo_sesiones] s ON s.id = e.sesion_id
ORDER BY s.id, e.sucursal_id;
GO
