-- =============================================================
-- Consumo propio, clientes CRÉDITO (tipval = 3) de SG12.
-- Mismo catálogo que débito (TG.dbo.clientes_consumo_propio, ver
-- clientes_consumo_propio.sql). Lo lee Consumos Crédito (/income/clients):
-- columna Propio + filtro incluir/excluir/solo.
-- Venta por Rubro NO cambia: solo mueve ValesR valor 127 (débito) y los
-- eventos exigen tipval = 4.
-- Lista revisada con el usuario el 2026-10-23. GCC CONCRETO = cliente real.
-- Script idempotente (correr con sqlcmd -f 65001).
-- =============================================================
USE [TG]
GO

MERGE [TG].[dbo].[clientes_consumo_propio] AS t
USING (VALUES
    -- Bonificaciones
    (1714315, N'Bonificaciones'),         -- C-TRANSPORTES RUNNER LINE (BONIFICACION)
    (1714335, N'Bonificaciones'),         -- TRANSPORTES Y SERVICIOS ROCA DE JUAREZ ( BONIFICACION)
    (1714319, N'Bonificaciones'),         -- C- IKRALG (BONIFICACION)
    (1714320, N'Bonificaciones'),         -- C-AUTOTRANSPORTES CAMPESINOS(BONIFICACION)
    (1714355, N'Bonificaciones'),         -- C- MARIO ALBERTO SAENZ GARCIA (BONIFICACION)
    (1714331, N'Bonificaciones'),         -- C-DELICIOUS CAKES AND ICE CREAM (BONIFICACION)
    -- Uso interno (apoyo de transporte, Diaz Gas, administrativas, operativas)
    (1714235, N'Uso interno'),            -- C-APOYO DE TRANSPORTE (DIAZ GAS)
    (1714227, N'Uso interno'),            -- C-APOYO DE TRANSPORTE (GASO MEX)
    (1714229, N'Uso interno'),            -- C-APOYO DE TRANSPORTE (JARUDO)
    (1714228, N'Uso interno'),            -- C-APOYO DE TRANSPORTE (CLARA)
    (1714338, N'Uso interno'),            -- C-APOYO DE TRANSPORTE (DELICIAS)
    (1714230, N'Uso interno'),            -- C-APOYO DE TRANSPORTE (VILLA AHUMADA)
    (1714336, N'Uso interno'),            -- C-APOYO DE TRANSPORTE (PLUTARCO)
    (21800260, N'Uso interno'),           -- C-DIAZ GAS, S.A. DE C.V. (CREDITO 09/12 CORPORATIVO)
    (1712590, N'Uso interno'),            -- C-DIAZ GAS SA DE CV (villa ahumada)
    (1713606, N'Uso interno'),            -- DIAZ GAS SA DE CV(ADMINISTRATIVA 28062024)
    (1713463, N'Uso interno'),            -- INMO DIAZ SA DE CV(ADMINISTRATIVA28022024)
    (1714495, N'Uso interno'),            -- SERVICIOS DE CALIDAD EN TRANSPORTE PRIVADO (GASOMEX ADMINISTRATIVA)
    (1713262, N'Uso interno'),            -- C-ESTACION CUSTODIA SA DE CV(13/09/2023OPERATIVA)
    (21802696, N'Uso interno'),           -- ESTACION CUSTODIA, S.A. DE C.V.(ADMINISTRATIVA)
    (1714268, N'Uso interno'),            -- C- GASO MEX MANTENIMIENTO (PLANTA DE LUZ)
    (1713763, N'Uso interno'),            -- C-INMO DIAZ SA DE CV(DG10122024)
    (1711552, N'Uso interno'),            -- C-DISTRIBUIDORA GASO MEX SA DE CV (ZONA 3 CREDITO 09/20)
    -- Pruebas (laboratorio y sistemas)
    (1714337, N'Pruebas'), (1714343, N'Pruebas'), (1714340, N'Pruebas'),
    (1714341, N'Pruebas'), (1714344, N'Pruebas'), (1714342, N'Pruebas'),   -- PRUEBA DE LABORATORIO (estaciones)
    (1709767, N'Pruebas'),                -- PRUEBA CREDITO
    (36592,   N'Pruebas'),                -- PRUEBA CREDITO NC
    (36606,   N'Pruebas'),                -- PRUEBA SISTEMAS VS CONTA VALES
    (36612,   N'Pruebas'),                -- prueba de sistemas depuracion cuentas clientes
    (34925,   N'Pruebas'),                -- PRUEBA SISTEMAS (CREDITO)
    (35219,   N'Pruebas'),                -- PRUEBA VALES
    (36836,   N'Pruebas'),                -- C-DIAZ GAS S.A. DE C.V. (PRUEBA DE SISTEMAS)
    -- Donativos
    (1707277, N'Cortesías y donativos'),  -- CRUZ ROJA MEXICANA (DONATIVO/2018)
    (1700636, N'Cortesías y donativos')   -- CRUZ ROJA MEXICANA I.A.P. (2015 DONATIVO 05/15)
) AS s (codcli, categoria)
ON t.codcli = s.codcli
WHEN NOT MATCHED THEN INSERT (codcli, categoria) VALUES (s.codcli, s.categoria);
GO
