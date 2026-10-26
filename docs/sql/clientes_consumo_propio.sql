-- =============================================================
-- Consumo propio: clientes débito de SG12 que en realidad son consumo
-- interno (marketing, bonificaciones, cortesías, inventarios, arqueo...).
-- Lo leen:
--   * Venta por Rubro (/commercial/sale-type-payment): saca su ValesR
--     (valor 127) de DEBITO y lo muestra como MedioPago 'CONSUMO PROPIO'
--     con la categoría como Descripcion.
--   * Consumos Débito (/income/clients): columna Categoría + filtro.
-- Solo débito (tipval = 4). Lista revisada con el usuario el 2026-10-21.
-- Script idempotente: se puede correr más de una vez (correr con sqlcmd -f 65001).
-- =============================================================
USE [TG]
GO

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'clientes_consumo_propio')
BEGIN
    CREATE TABLE [TG].[dbo].[clientes_consumo_propio] (
        codcli      INT           NOT NULL PRIMARY KEY,   -- SG12.dbo.Clientes.cod
        categoria   NVARCHAR(60)  NOT NULL,
        activo      BIT           NOT NULL DEFAULT 1,
        created_at  DATETIME      NOT NULL DEFAULT GETDATE()
    );
END
GO

-- Lista inicial ---------------------------------------------------
MERGE [TG].[dbo].[clientes_consumo_propio] AS t
USING (VALUES
    -- Marketing / dinámicas / patrocinios
    (1713442, N'Marketing'),        -- D- DINAMICA MKT (DIAZ GAS)
    (1712104, N'Marketing'),        -- D- TALENTOS TG ( MKT )
    (1714015, N'Marketing'),        -- D-MEGARADIO MKT
    (1713806, N'Marketing'),        -- D- DINAMICAS MKT AQUA CAR (DG 2025)
    (1714234, N'Marketing'),        -- D- DINAMICA MTK (AGS PEMEX)
    (1714141, N'Marketing'),        -- D-PATROCINIO SELECT FEST 2025
    (1714085, N'Marketing'),        -- D-DINAMICAS MKT ARCO
    (1713520, N'Marketing'),        -- D- DINAMICAS MKT AQUA CAR (2024)
    (1713786, N'Marketing'),        -- D- MARKETING - DINAMICA (GRUPO GASOLINERO TSA)
    (1711976, N'Marketing'),        -- D- PROMOCIONES MKT ( ZONA 3 )
    (1713567, N'Marketing'),        -- D- DINAMICAS MKT (GASOMEX)
    -- Bonificaciones
    (1714366, N'Bonificaciones'),   -- BONIFICACION ANTRAMEX
    (1714434, N'Bonificaciones'),   -- BONIFICACION MOTO UNION
    -- Cortesías / donativos / responsabilidad social
    (1714388, N'Cortesías y donativos'),  -- DONATIVO MUNICIPIO DE JUAREZ (DG 2026)
    (1713888, N'Cortesías y donativos'),  -- D-RESPONSABILIDAD SOCIAL JUAREZ (INSTITUTO MUNICIPAL DE LA MUJER)
    (1713625, N'Cortesías y donativos'),  -- D- CORTESIAS (DIAZ GAS 07/2024)
    (1712570, N'Cortesías y donativos'),  -- D- INSTITUTO MUNICIPAL DE LAS MUJERES (CORTESIAS)
    -- Arqueo
    (1714213, N'Arqueo'),           -- D-ARQUEO DOLLAR2GO (DG 11-25)
    -- Uso interno (cliente con RFC de nuestra razón social)
    (5551,    N'Uso interno')       -- D- DIAZ GAS S.A. DE C.V.
) AS s (codcli, categoria)
ON t.codcli = s.codcli
WHEN NOT MATCHED THEN INSERT (codcli, categoria) VALUES (s.codcli, s.categoria);
GO

-- Inventarios: todos los clientes débito "(INVENTARIO)" / "(INVENTARIOS)" (67 al 2026-10-21)
INSERT INTO [TG].[dbo].[clientes_consumo_propio] (codcli, categoria)
SELECT c.cod, N'Inventarios'
FROM [SG12].[dbo].[Clientes] c
WHERE c.tipval = 4
  AND UPPER(c.den) LIKE '%INVENTARI%'
  AND NOT EXISTS (SELECT 1 FROM [TG].[dbo].[clientes_consumo_propio] p WHERE p.codcli = c.cod);
GO

SELECT categoria, COUNT(*) AS clientes FROM [TG].[dbo].[clientes_consumo_propio] GROUP BY categoria ORDER BY categoria;
GO
