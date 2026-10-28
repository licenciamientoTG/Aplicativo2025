/* ============================================================================
   Compras de combustible - columnas capturables del resto del Excel COMPRAS
   (proveedor final, litros de soporte, Petrotal, Tesoro y fletes).
   Agrega las mismas columnas a compras_factura_datos y compras_renglon_manual.
   Idempotente: se puede correr más de una vez.
   ============================================================================ */

USE [TG];
GO

DECLARE @tablas TABLE (t SYSNAME);
INSERT INTO @tablas VALUES ('compras_factura_datos'), ('compras_renglon_manual');

DECLARE @cols TABLE (c SYSNAME, tipo VARCHAR(40));
INSERT INTO @cols VALUES
    ('proveedor_final',     'VARCHAR(100)'),   -- L  PROVEEDOR QUE REALIZA LA FACTURA FINAL
    ('litros_soporte',      'DECIMAL(18,3)'),  -- M  litros en documentos soporte (si difiere de la factura)
    ('subtotal_tesoro',     'DECIMAL(18,6)'),  -- AD
    ('descuento_tesoro',    'DECIMAL(18,6)'),  -- AE
    ('factura_petrotal',    'VARCHAR(100)'),   -- AF F/FACTURA PETROTAL (razón social a la que factura Petrotal)
    ('factura_nueva',       'VARCHAR(50)'),    -- AG
    ('monto_petrotal',      'DECIMAL(18,6)'),  -- AH
    ('iva_cliente_final',   'DECIMAL(5,4)'),   -- AI
    ('precio_petrotal',     'DECIMAL(18,6)'),  -- AJ precio por litro sin IVA facturado por Petrotal
    ('factura_flete',       'VARCHAR(50)'),    -- AP
    ('monto_carta_porte',   'DECIMAL(18,6)'),  -- AQ
    ('total_a_pagar',       'DECIMAL(18,6)'),  -- AR
    ('razon_social_flete',  'VARCHAR(100)'),   -- AT
    ('pago_flete',          'DATE');           -- AU

DECLARE @t SYSNAME, @c SYSNAME, @tipo VARCHAR(40), @sql NVARCHAR(MAX);
DECLARE cur CURSOR LOCAL FAST_FORWARD FOR SELECT t.t, c.c, c.tipo FROM @tablas t CROSS JOIN @cols c;
OPEN cur;
FETCH NEXT FROM cur INTO @t, @c, @tipo;
WHILE @@FETCH_STATUS = 0
BEGIN
    IF COL_LENGTH('TG.dbo.' + @t, @c) IS NULL
    BEGIN
        SET @sql = N'ALTER TABLE [TG].[dbo].[' + @t + N'] ADD [' + @c + N'] ' + @tipo + N' NULL;';
        EXEC sp_executesql @sql;
    END
    FETCH NEXT FROM cur INTO @t, @c, @tipo;
END
CLOSE cur;
DEALLOCATE cur;
GO

SELECT t.name AS tabla, COUNT(*) AS columnas_nuevas
FROM sys.columns c JOIN sys.tables t ON t.object_id = c.object_id
WHERE t.name IN ('compras_factura_datos', 'compras_renglon_manual')
  AND c.name IN ('proveedor_final','litros_soporte','subtotal_tesoro','descuento_tesoro','factura_petrotal','factura_nueva',
                 'monto_petrotal','iva_cliente_final','precio_petrotal','factura_flete','monto_carta_porte','total_a_pagar',
                 'razon_social_flete','pago_flete')
GROUP BY t.name;   -- esperado: 14 y 14
GO
