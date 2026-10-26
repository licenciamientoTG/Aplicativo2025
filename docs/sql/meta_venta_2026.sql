-- Metas 2026 de crédito (28) y débito (127), tomadas de la hoja "Vta vs Meta Emp Historico" del Excel
-- "TG6 Clientes Credito-Debito 30-Septiembre 2026.xlsx" (filas 14 y 15). Misma convención que 2025: una fila por mes y tipo, zona = 1.
-- Si ya existen metas 2026 no inserta nada.
SET NOCOUNT ON;
IF EXISTS (SELECT 1 FROM TGV2.dbo.MetaVenta WHERE [año] = 2026)
    PRINT 'Ya existen metas 2026; no se insertó nada.';
ELSE
BEGIN
    BEGIN TRAN;
    INSERT INTO TGV2.dbo.MetaVenta ([año], mes, cantidad, tipo_id, zona, fecha_ingreso)
    SELECT v.anio, v.mes, v.cantidad, v.tipo_id, v.zona, GETDATE()
    FROM (VALUES
    (2026, 1, 1840271.29100, 28, 1),
    (2026, 2, 1733465.33825, 28, 1),
    (2026, 3, 2005985.79159, 28, 1),
    (2026, 4, 1931459.50584, 28, 1),
    (2026, 5, 2206005.64050, 28, 1),
    (2026, 6, 2191564.59630, 28, 1),
    (2026, 7, 1987985.45407, 28, 1),
    (2026, 8, 2129617.06890, 28, 1),
    (2026, 9, 1895714.67621, 28, 1),
    (2026, 10, 2127611.81160, 28, 1),
    (2026, 11, 1908816.59760, 28, 1),
    (2026, 12, 1712315.86470, 28, 1),
    (2026, 1, 992931.46287, 127, 1),
    (2026, 2, 921499.86386, 127, 1),
    (2026, 3, 1032255.36526, 127, 1),
    (2026, 4, 984887.52044, 127, 1),
    (2026, 5, 1067106.02472, 127, 1),
    (2026, 6, 1062345.99896, 127, 1),
    (2026, 7, 1064108.91988, 127, 1),
    (2026, 8, 1035275.92718, 127, 1),
    (2026, 9, 1115963.32981, 127, 1),
    (2026, 10, 1113753.86006, 127, 1),
    (2026, 11, 1031970.01429, 127, 1),
    (2026, 12, 929528.08284, 127, 1)) AS v(anio, mes, cantidad, tipo_id, zona);
    IF @@ROWCOUNT <> 24 BEGIN PRINT 'Se esperaban 24 filas; rollback.'; ROLLBACK; END ELSE COMMIT;
END;
SELECT tipo_id, COUNT(*) AS filas, SUM(cantidad) AS total_2026 FROM TGV2.dbo.MetaVenta WHERE [año] = 2026 GROUP BY tipo_id ORDER BY tipo_id;
