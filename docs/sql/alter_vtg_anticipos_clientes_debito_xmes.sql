-- Fix: VTGAnticiposClientesDebitoXMes incluía anticipos de TODOS los tipos de
-- cliente (tipval 0, 3, 4, 6), no solo de clientes de débito (tipval = 4),
-- pese a lo que indica el nombre de la vista.
--
-- Verificado contra datos reales (rango de fch 46040-46070):
--   Sin filtro tipval:      5,848 registros / $22,136,817.91
--   Con tipval = 4 (debito): 2,108 registros / $20,031,852.74
--
-- Cambios respecto a la version original:
--   1. Agrega el filtro AND t3.tipval = 4 (fix principal).
--   2. Quita el LEFT JOIN a Gasolineras (t4): no se usaba ninguna columna.
--   3. Quita el estilo "106" en el CONVERT: no tiene efecto porque el origen
--      es un int, no un varchar (SQL Server ignora el estilo en ese caso).
--   4. Calcula la fecha base (primer dia del mes) una sola vez en una
--      subconsulta en vez de repetir la misma expresion DATEADD/DATEDIFF
--      cuatro veces; DiaFinalDelMes ahora usa EOMONTH() sobre esa fecha.
--
-- Los nombres y tipos de columnas de salida (Mes, NombreDelMes,
-- DiaInicialDelMes, DiaFinalDelMes, Total, CantidadDeRegistros) no cambian,
-- para no romper a los consumidores actuales
-- (DocumentosModel::get_month_anticipos(), administration.php:1674 y :1685).

USE [SG12];
GO

ALTER VIEW [dbo].[VTGAnticiposClientesDebitoXMes]
AS
SELECT
    FORMAT(base.FechaBase, 'yyyy-MM') AS Mes,
    DATENAME(MONTH, base.FechaBase) AS NombreDelMes,
    FORMAT(base.FechaBase, 'yyyy-MM-dd') AS DiaInicialDelMes,
    FORMAT(EOMONTH(base.FechaBase), 'yyyy-MM-dd') AS DiaFinalDelMes,
    SUM((base.mtoori / 100) + (base.mtoiva / 100)) AS Total,
    COUNT(base.nro) AS CantidadDeRegistros
FROM (
    SELECT
        t1.nro,
        t1.mtoori,
        t1.mtoiva,
        DATEADD(MONTH, DATEDIFF(MONTH, 0, t2.fch - 1), 0) AS FechaBase
    FROM
        [SG12].[dbo].[Documentos] t1
        LEFT JOIN [SG12].[dbo].[DocumentosC] t2 ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
        INNER JOIN [SG12].[dbo].[Clientes] t3 ON t1.codopr = t3.cod
    WHERE
        t1.codprd NOT IN (1, 2, 3, -64, 179, 180, 181, 192, 193) AND
        t1.mtoiva > 0 AND
        t1.mto > 100 AND
        t3.tipval = 4
) AS base
GROUP BY
    base.FechaBase;
GO
