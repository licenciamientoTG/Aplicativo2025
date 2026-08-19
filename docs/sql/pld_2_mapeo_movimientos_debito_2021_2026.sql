-- PLD Prepago -- Query 2: Mapeo completo de movimientos (depositos/anticipos) de
-- clientes de debito, Julio 2021 a la fecha. Un renglon por documento (factura),
-- con fecha, monto, IVA y UUID.
--
-- A peticion expresa se corre en una sola pasada, sin particionar por año/trimestre.
--
-- Aviso de rendimiento (medido en sesion previa contra este mismo servidor):
-- un SELECT agregado simple (solo SUM/COUNT, sin traer detalle) sobre este mismo
-- rango tardo ~3 minutos y devolvio 129,119 registros / 2,113 clientes distintos /
-- ~$1,278,960,897 MXN acumulado. Esta version trae el detalle completo (un renglon
-- por documento, sin agregar), por lo que puede tardar sensiblemente mas y el
-- resultset sera grande -- considerar exportar directo a archivo (bcp / "Save Results As")
-- en vez de a una grilla interactiva en SSMS.

USE [SG12];
GO

DECLARE @from  DATE = '2021-07-01';
DECLARE @until DATE = GETDATE();

SELECT
    g.abr                                          AS Entidad,
    c.cod                                           AS CodCliente,
    c.den                                            AS Cliente,
    c.rfc                                            AS RFC,
    CONVERT(date, CONVERT(datetime, t2.fch - 1))     AS Fecha,
    DATENAME(MONTH, CONVERT(datetime, t2.fch - 1))   AS Mes,
    YEAR(CONVERT(datetime, t2.fch - 1))               AS Anio,
    t1.nro                                           AS NroDocumento,
    t1.tip                                           AS TipoDocumento,
    t1.codgas                                        AS CodEstacion,
    (t1.mtoori / 100)                                AS Subtotal,
    (t1.mtoiva / 100)                                AS IVA,
    ((t1.mtoori + t1.mtoiva) / 100)                  AS MontoTotal,
    t2.satuid                                        AS UUID
FROM
    [SG12].[dbo].[Documentos] t1 WITH (NOLOCK)
    LEFT JOIN [SG12].[dbo].[DocumentosC] t2 WITH (NOLOCK)
        ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
    INNER JOIN [SG12].[dbo].[Clientes] c WITH (NOLOCK)
        ON t1.codopr = c.cod
    LEFT JOIN [SG12].[dbo].[Gasolineras] g WITH (NOLOCK)
        ON t1.codgas = g.cod
WHERE
    t1.codprd NOT IN (1, 2, 3, -64, 179, 180, 181, 192, 193)
    AND t1.mtoiva > 0
    AND t1.mto > 100
    AND c.tipval = 4
    AND t2.fch BETWEEN (DATEDIFF(day, 0, @from) + 1) AND (DATEDIFF(day, 0, @until) + 1)
ORDER BY
    c.den, t2.fch;
GO


-- ============================================================================
-- 2b) OPCIONAL -- Resumen mensual por cliente (Cliente x Mes/Anio), con el mismo
-- criterio del punto 1, pero aplicando el umbral 645 UMA vigente EN CADA AÑO
-- (no el fijo de 2026), tal como lo documenta la hoja "Umbrales por Año" del
-- tablero Excel. Util para extender la logica de "Avisos 2026" a años anteriores.
--
-- Los valores de UMA/umbral por año son los que trae la propia hoja "Umbrales por
-- Año" del Excel; si INEGI publica una cifra distinta, ajustar la tabla @Umbrales.
--
-- EXTENDIDO con conteo de tarjetas (a peticion expresa), usando dos tablas que
-- SI viven centralizadas en SG12 -- no hace falta ir a las 40+ estaciones:
--   - ClientesVehiculos: catalogo de tarjetas por cliente (codcli, tar)
--     -> TotalTarjetas = tarjetas distintas registradas para ese cliente
--        (fotografia actual del catalogo, no varia mes a mes).
--   - Despachos: bitacora real de cargas de combustible (codcli, tar, logfch)
--     -> TarjetasActivasMes = tarjetas distintas que tuvieron AL MENOS UN
--        despacho real dentro de ese mes especifico. "Activa" se definio
--        como uso real, no como un estatus estatico en catalogo.
--
-- Supuesto a confirmar: se uso Despachos.logfch como fecha de la transaccion
-- (no hay otro campo de fecha mas evidente en esa tabla). Si logfch representa
-- otra cosa (ej. fecha de sincronizacion en vez de fecha real del despacho),
-- hay que cambiar a la columna correcta.
-- ============================================================================

USE [SG12];
GO

DECLARE @from  DATE = '2021-07-01';
DECLARE @until DATE = GETDATE();

DECLARE @Umbrales TABLE (Anio INT PRIMARY KEY, UmbralMXN DECIMAL(18,2));
INSERT INTO @Umbrales (Anio, UmbralMXN) VALUES
    (2021, 57804.90),
    (2022, 62061.90),
    (2023, 66912.30),
    (2024, 70027.65),
    (2025, 72975.30),
    (2026, 75664.95);

;WITH Cobros AS (
    SELECT
        c.cod                                            AS CodCliente,
        c.den                                             AS Cliente,
        c.rfc                                              AS RFC,
        YEAR(CONVERT(datetime, t2.fch - 1))                AS Anio,
        MONTH(CONVERT(datetime, t2.fch - 1))                AS NumMes,
        DATENAME(MONTH, CONVERT(datetime, t2.fch - 1))      AS Mes,
        COUNT(t1.nro)                                       AS NumEventos,
        SUM((t1.mtoori + t1.mtoiva) / 100)                  AS TotalMes
    FROM
        [SG12].[dbo].[Documentos] t1 WITH (NOLOCK)
        LEFT JOIN [SG12].[dbo].[DocumentosC] t2 WITH (NOLOCK)
            ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
        INNER JOIN [SG12].[dbo].[Clientes] c WITH (NOLOCK)
            ON t1.codopr = c.cod
    WHERE
        t1.codprd NOT IN (1, 2, 3, -64, 179, 180, 181, 192, 193)
        AND t1.mtoiva > 0
        AND t1.mto > 100
        AND c.tipval = 4
        AND t2.fch BETWEEN (DATEDIFF(day, 0, @from) + 1) AND (DATEDIFF(day, 0, @until) + 1)
    GROUP BY
        c.cod, c.den, c.rfc,
        YEAR(CONVERT(datetime, t2.fch - 1)),
        MONTH(CONVERT(datetime, t2.fch - 1)),
        DATENAME(MONTH, CONVERT(datetime, t2.fch - 1))
),
TotalTarjetas AS (
    SELECT
        codcli              AS CodCliente,
        COUNT(DISTINCT tar) AS TotalTarjetas
    FROM [SG12].[dbo].[ClientesVehiculos] WITH (NOLOCK)
    GROUP BY codcli
),
TarjetasActivasMes AS (
    SELECT
        codcli                     AS CodCliente,
        YEAR(logfch)                AS Anio,
        MONTH(logfch)                AS NumMes,
        COUNT(DISTINCT tar)          AS TarjetasActivas
    FROM [SG12].[dbo].[Despachos] WITH (NOLOCK)
    WHERE logfch BETWEEN @from AND @until
    GROUP BY codcli, YEAR(logfch), MONTH(logfch)
)
SELECT
    cb.CodCliente,
    cb.Cliente,
    cb.RFC,
    cb.Anio,
    cb.NumMes,
    cb.Mes,
    cb.NumEventos,
    cb.TotalMes,
    u.UmbralMXN                                             AS UmbralDelAnio,
    CASE WHEN cb.TotalMes >= u.UmbralMXN THEN 1 ELSE 0 END  AS ProcedeAviso,
    ISNULL(tt.TotalTarjetas, 0)                             AS TotalTarjetas,
    ISNULL(tam.TarjetasActivas, 0)                          AS TarjetasActivasMes
FROM
    Cobros cb
    LEFT JOIN @Umbrales u
        ON u.Anio = cb.Anio
    LEFT JOIN TotalTarjetas tt
        ON tt.CodCliente = cb.CodCliente
    LEFT JOIN TarjetasActivasMes tam
        ON tam.CodCliente = cb.CodCliente AND tam.Anio = cb.Anio AND tam.NumMes = cb.NumMes
ORDER BY
    cb.Cliente, cb.Anio, cb.NumMes;
GO
