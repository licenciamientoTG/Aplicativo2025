-- 1. SIMULACIÓN DE VARIABLES QUE ENVÍA PHP
-- Tu código original ponía: $first_date = date('Y-m-01') y $last_date = date('Y-m-d')
-- Suponiendo que hoy es 09 de Enero de 2026:
DECLARE @FromDate datetime = '2026-01-01 00:00:00'; 
DECLARE @UntilDate datetime = '2026-01-09 23:59:59';
DECLARE @CodGas int = NULL; -- Asumimos que no filtraste por estación específica
-- Tu código busca estos tipos por defecto:
-- WHERE t1.tip IN (1,3,4,6)

-- 2. TU CONSULTA TRADUCIDA A SQL PURO
SELECT 
    t1.satuid AS UUID,
    t1.nro AS NumeroDocumento,
    t1.fch AS FechaSQL,
    CONVERT(varchar(10), DATEADD(DAY, -1, t1.fch), 23) AS FechaLeible,
    t1.tip AS TipoDocumento
FROM SG12.dbo.DocumentosC AS t1
WHERE 
    1=1
    -- Filtro de Fechas (AQUÍ ES DONDE FALLA TU FACTURA DE 2025)
    AND t1.fch BETWEEN @FromDate AND @UntilDate
    -- Filtro de Tipos
    AND t1.tip IN (1, 3, 4, 6)
    -- Filtro específico para ver si aparece TU factura
    AND t1.satuid = '1BF37372-DA83-49F2-991B-1CD3FFDEEB17';

-- 3. PRUEBA DE "CORRECCIÓN"
-- Esta segunda consulta simula si cambiaras las fechas como te sugerí
PRINT '--------------------------------------------------'
PRINT 'INTENTO 2: CON RANGO DE FECHAS AMPLIADO (DESDE 2020)'
DECLARE @FromDateFix datetime = '2020-01-01 00:00:00'; 

SELECT 
    '¡ENCONTRADA!' AS Estado,
    t1.satuid AS UUID,
    CONVERT(varchar(10), DATEADD(DAY, -1, t1.fch), 23) AS FechaCorrecta
FROM SG12.dbo.DocumentosC AS t1
WHERE 
    t1.fch BETWEEN @FromDateFix AND @UntilDate
    AND t1.satuid = '1BF37372-DA83-49F2-991B-1CD3FFDEEB17';