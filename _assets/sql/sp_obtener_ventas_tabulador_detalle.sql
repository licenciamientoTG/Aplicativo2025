-- ============================================================
-- SP: [TG].[dbo].[sp_obtener_ventas_tabulador_detalle]
-- BD: TG
--
-- Objetivo:
--   Obtener en una sola llamada los datos completos de la pestaña
--   Ventas del tabulador:
--     1) Resumen por isla
--     2) Formas de pago por isla
--     3) Lecturas / ventas por bomba y producto
--
-- Nota para la app:
--   Este SP devuelve un solo result set. La columna TipoRegistro indica
--   como interpretar cada fila: SUMMARY, PAYMENT o READING.
-- ============================================================

USE [TG];
GO

CREATE OR ALTER PROCEDURE [dbo].[sp_obtener_ventas_tabulador_detalle]
    @TabId          INT,
    @CodigoEstacion INT,
    @FechaTabular   INT,
    @Turno          INT,
    @Islands        VARCHAR(MAX),
    @LinkedServer   SYSNAME,
    @RemoteDbSchema SYSNAME
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @PreviousShift INT;
    DECLARE @PreviousDate  INT;
    DECLARE @NextShift     INT;
    DECLARE @NextDate      INT;
    DECLARE @IslandList    VARCHAR(MAX);
    DECLARE @LinkedServerQuoted SYSNAME;

    -- El flujo actual calcula lecturas con el turno anterior y ventas con el turno siguiente.
    SET @PreviousShift = CASE @Turno
        WHEN 11 THEN 41
        WHEN 21 THEN 11
        WHEN 31 THEN 21
        WHEN 41 THEN 31
    END;

    SET @PreviousDate = CASE WHEN @PreviousShift = 41 THEN @FechaTabular - 1 ELSE @FechaTabular END;

    SET @NextShift = CASE @PreviousShift
        WHEN 11 THEN 21
        WHEN 21 THEN 31
        WHEN 31 THEN 41
        WHEN 41 THEN 11
    END;

    SET @NextDate = CASE WHEN @NextShift = 11 THEN @PreviousDate + 1 ELSE @PreviousDate END;

    -- Normaliza y valida la lista de islas para poder insertarla en OPENQUERY.
    DECLARE @ValidIslands TABLE (codisl INT PRIMARY KEY);

    DECLARE @IslandWork VARCHAR(MAX);
    DECLARE @CommaPosition INT;
    DECLARE @IslandValue VARCHAR(32);
    DECLARE @IslandInt INT;

    SET @IslandWork = ISNULL(@Islands, '') + ',';
    SET @CommaPosition = CHARINDEX(',', @IslandWork);

    WHILE @CommaPosition > 0
    BEGIN
        SET @IslandValue = LTRIM(RTRIM(SUBSTRING(@IslandWork, 1, @CommaPosition - 1)));
        SET @IslandInt = CASE
            WHEN @IslandValue NOT LIKE '%[^0-9]%' AND LEN(@IslandValue) > 0 THEN CAST(@IslandValue AS INT)
            ELSE NULL
        END;

        IF @IslandInt IS NOT NULL
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM @ValidIslands WHERE codisl = @IslandInt)
            BEGIN
                INSERT INTO @ValidIslands (codisl)
                SELECT @IslandInt;
            END
        END;

        SET @IslandWork = SUBSTRING(@IslandWork, @CommaPosition + 1, LEN(@IslandWork));
        SET @CommaPosition = CHARINDEX(',', @IslandWork);
    END;

    SELECT @IslandList = STUFF((
        SELECT ',' + CONVERT(VARCHAR(12), codisl)
        FROM @ValidIslands
        ORDER BY codisl
        FOR XML PATH(''), TYPE
    ).value('.', 'VARCHAR(MAX)'), 1, 1, '');

    IF @IslandList IS NULL OR LEN(@IslandList) = 0
    BEGIN
        RAISERROR('La lista de islas no contiene valores validos.', 16, 1);
        RETURN;
    END;

    -- El nombre del linked server llega usualmente como [192.168.x.101].
    -- Lo re-escapamos para reducir riesgo al usar SQL dinamico.
    SET @LinkedServerQuoted = QUOTENAME(REPLACE(REPLACE(@LinkedServer, '[', ''), ']', ''));

    IF @RemoteDbSchema LIKE '%''%' OR @RemoteDbSchema LIKE '%;%' OR @RemoteDbSchema LIKE '%--%'
    BEGIN
        RAISERROR('RemoteDbSchema contiene caracteres no permitidos.', 16, 1);
        RETURN;
    END;

    CREATE TABLE #IslandNames (
        codisl INT NOT NULL PRIMARY KEY,
        Isla NVARCHAR(150) NULL
    );

    CREATE TABLE #Payments (
        codisl INT NOT NULL,
        Isla NVARCHAR(150) NULL,
        ValorDescripcion NVARCHAR(150) NULL,
        Total DECIMAL(18, 2) NOT NULL
    );

    CREATE TABLE #Readings (
        codisl INT NOT NULL,
        Isla NVARCHAR(150) NULL,
        nrobom INT NULL,
        Producto NVARCHAR(150) NULL,
        codprd INT NULL,
        initialElectronicReading DECIMAL(18, 3) NOT NULL DEFAULT 0,
        amount DECIMAL(18, 2) NOT NULL DEFAULT 0,
        finalElectronicReading DECIMAL(18, 3) NOT NULL DEFAULT 0,
        finalAmount DECIMAL(18, 2) NOT NULL DEFAULT 0,
        difference DECIMAL(18, 3) NOT NULL DEFAULT 0,
        amountDifference DECIMAL(18, 2) NOT NULL DEFAULT 0
    );

    DECLARE @RemoteQuery NVARCHAR(MAX);
    DECLARE @Sql NVARCHAR(MAX);

    -- Nombres de islas desde la estacion.
    SET @RemoteQuery = N'
        SELECT cod AS codisl, den AS Isla
        FROM ' + @RemoteDbSchema + N'.[Islas]
        WHERE codgas = ' + CONVERT(VARCHAR(12), @CodigoEstacion) + N'
          AND cod IN (' + @IslandList + N')
    ';

    SET @Sql = N'
        INSERT INTO #IslandNames (codisl, Isla)
        SELECT codisl, Isla
        FROM OPENQUERY(' + @LinkedServerQuoted + N', ''' + REPLACE(@RemoteQuery, '''', '''''') + N''');
    ';
    EXEC sys.sp_executesql @Sql;

    -- Formas de pago remotas: MovimientosTar + Despachos de credito/debito.
    SET @RemoteQuery = N'
        SELECT
            DatosOrdenados.ValorDescripcion,
            COALESCE(CAST(SUM(DatosOrdenados.mto) AS DECIMAL(18, 2)), 0) AS Total,
            DatosOrdenados.codisl,
            DatosOrdenados.Isla
        FROM (
            SELECT
                t3.den COLLATE Modern_Spanish_CI_AS AS ValorDescripcion,
                t1.mto,
                t1.codisl,
                t2.den COLLATE Modern_Spanish_CI_AS AS Isla,
                ROW_NUMBER() OVER (
                    PARTITION BY t1.nrotrn
                    ORDER BY t1.fchlog DESC
                ) AS fila
            FROM ' + @RemoteDbSchema + N'.[MovimientosTar] t1
                LEFT JOIN ' + @RemoteDbSchema + N'.[Islas] t2 ON t1.codisl = t2.cod
                LEFT JOIN ' + @RemoteDbSchema + N'.[Valores] t3 ON t1.codbco = t3.cod
            WHERE
                t1.fchmov = ' + CONVERT(VARCHAR(12), @FechaTabular) + N'
                AND t1.codgas = ' + CONVERT(VARCHAR(12), @CodigoEstacion) + N'
                AND t1.nrotur = ' + CONVERT(VARCHAR(12), @Turno) + N'
                AND t1.codisl IN (' + @IslandList + N')
                AND NOT (t1.codbco = 0 AND t1.tiptar IN (84, 68, 67, 72))
        ) AS DatosOrdenados
        WHERE DatosOrdenados.fila = 1
        GROUP BY DatosOrdenados.ValorDescripcion, DatosOrdenados.codisl, DatosOrdenados.Isla

        UNION ALL

        SELECT
            CASE
                WHEN t2.tipval = 3 THEN N''Credito''
                WHEN t2.tipval = 4 THEN N''Debito''
                ELSE CAST(t2.tipval AS VARCHAR)
            END COLLATE Modern_Spanish_CI_AS AS ValorDescripcion,
            COALESCE(CAST(SUM(t1.mto) AS DECIMAL(18, 2)), 0) AS Total,
            t1.codisl,
            t4.den COLLATE Modern_Spanish_CI_AS AS Isla
        FROM ' + @RemoteDbSchema + N'.[Despachos] t1
            LEFT JOIN ' + @RemoteDbSchema + N'.[Clientes] t2 ON t1.codcli = t2.cod
            LEFT JOIN ' + @RemoteDbSchema + N'.[Islas] t4 ON t1.codisl = t4.cod
        WHERE
            t1.fchtrn = ' + CONVERT(VARCHAR(12), @FechaTabular) + N'
            AND t1.codgas = ' + CONVERT(VARCHAR(12), @CodigoEstacion) + N'
            AND t1.nrotur = ' + CONVERT(VARCHAR(12), @Turno) + N'
            AND t1.codisl IN (' + @IslandList + N')
            AND t2.tipval IN (3, 4)
        GROUP BY t1.fchtrn, t2.tipval, t1.codisl, t4.den
    ';

    SET @Sql = N'
        INSERT INTO #Payments (ValorDescripcion, Total, codisl, Isla)
        SELECT ValorDescripcion, Total, codisl, Isla
        FROM OPENQUERY(' + @LinkedServerQuoted + N', ''' + REPLACE(@RemoteQuery, '''', '''''') + N''');
    ';
    EXEC sys.sp_executesql @Sql;

    -- Formas de pago locales: fajillas del tabulador.
    INSERT INTO #Payments (ValorDescripcion, Total, codisl, Isla)
    SELECT
        CASE
            WHEN td.CodigoValor = 6 THEN 'Efectivo Tabulador'
            WHEN td.CodigoValor = 192 THEN 'Morralla'
        END AS ValorDescripcion,
        COALESCE(CAST(SUM(td.Monto) AS DECIMAL(18, 2)), 0) AS Total,
        td.Isla AS codisl,
        COALESCE(i.Isla, sg.den) COLLATE Modern_Spanish_CI_AS AS Isla
    FROM [TG].[dbo].[TabuladorDetalle] td WITH (NOLOCK)
        LEFT JOIN #IslandNames i ON td.Isla = i.codisl
        LEFT JOIN [SG12].[dbo].[Islas] sg WITH (NOLOCK) ON td.Isla = sg.cod
    WHERE
        td.Id = @TabId
        AND td.CodigoValor IN (6, 192)
        AND td.Isla IN (SELECT codisl FROM @ValidIslands)
    GROUP BY td.Isla, COALESCE(i.Isla, sg.den), td.CodigoValor;

    -- Lecturas / ventas por bomba-producto.
    SET @RemoteQuery = N'
        SELECT
            COALESCE(t2.codisl, t1.codisl) AS codisl,
            t4.den COLLATE Modern_Spanish_CI_AS AS Isla,
            t1.nrobom,
            CASE
                WHEN t1.codprd IN (179, 192) THEN N''T-Maxima Regular''
                WHEN t1.codprd IN (180, 193) THEN N''T-Super Premium''
                WHEN t1.codprd = 181 THEN N''Diesel Automotriz''
                ELSE t5.den
            END COLLATE Modern_Spanish_CI_AS AS Producto,
            t1.codprd,
            ROUND(t1.canacu, 3) AS initialElectronicReading,
            ROUND(t1.mtoacu, 2) AS amount,
            CASE WHEN t1.mtoacu < 1 THEN 0 ELSE COALESCE(t2.volume, 0) END AS finalElectronicReading,
            CASE WHEN t1.mtoacu < 1 THEN 0 ELSE COALESCE(t2.amount, 0) END AS finalAmount,
            CASE WHEN t1.mtoacu < 1 THEN 0 ELSE ROUND(t1.canacu - COALESCE(t2.volume, 0), 3) END AS difference,
            CASE WHEN t1.mtoacu < 1 THEN 0 ELSE ROUND(t1.mtoacu - COALESCE(t2.amount, 0), 2) END AS amountDifference
        FROM ' + @RemoteDbSchema + N'.[Medicion] t1
            LEFT JOIN (
                SELECT
                    nrobom,
                    COALESCE(SUM(can), 0) AS volume,
                    COALESCE(SUM(CASE WHEN tiptrn NOT IN (74, 65) THEN mto ELSE 0 END), 0) AS amount,
                    codprd,
                    codisl
                FROM ' + @RemoteDbSchema + N'.[Despachos]
                WHERE
                    fchcor = ' + CONVERT(VARCHAR(12), @NextDate) + N'
                    AND nrotur = ' + CONVERT(VARCHAR(12), @NextShift) + N'
                    AND codprd IN (1,2,3,179,180,181,192,193)
                    AND codisl IN (' + @IslandList + N')
                GROUP BY nrobom, codprd, codisl
            ) t2 ON t1.nrobom = t2.nrobom AND t1.codprd = t2.codprd
            LEFT JOIN ' + @RemoteDbSchema + N'.[Islas] t4 ON COALESCE(t2.codisl, t1.codisl) = t4.cod
            LEFT JOIN ' + @RemoteDbSchema + N'.[Productos] t5 ON t1.codprd = t5.cod
        WHERE
            t1.fch = ' + CONVERT(VARCHAR(12), @PreviousDate) + N'
            AND t1.nrotur = ' + CONVERT(VARCHAR(12), @PreviousShift) + N'
            AND t1.codisl IN (' + @IslandList + N')

        UNION ALL

        SELECT
            t1.codisl,
            t4.den COLLATE Modern_Spanish_CI_AS AS Isla,
            t1.nrobom,
            t5.den COLLATE Modern_Spanish_CI_AS AS Producto,
            t1.codprd,
            0 AS initialElectronicReading,
            t1.mto AS amount,
            0 AS finalElectronicReading,
            t1.mto AS finalAmount,
            0 AS difference,
            0 AS amountDifference
        FROM ' + @RemoteDbSchema + N'.[Despachos] t1
            LEFT JOIN ' + @RemoteDbSchema + N'.[Clientes] t2 ON t1.codcli = t2.cod
            LEFT JOIN ' + @RemoteDbSchema + N'.[ClientesValores] t3 ON t2.cod = t3.codcli
            LEFT JOIN ' + @RemoteDbSchema + N'.[Islas] t4 ON t1.codisl = t4.cod
            LEFT JOIN ' + @RemoteDbSchema + N'.[Productos] t5 ON t1.codprd = t5.cod
        WHERE
            t1.fchcor = ' + CONVERT(VARCHAR(12), @NextDate) + N'
            AND t1.nrotur = ' + CONVERT(VARCHAR(12), @NextShift) + N'
            AND t1.codisl IN (' + @IslandList + N')
            AND t1.codprd NOT IN (0,179,180,181,192,193)
    ';

    SET @Sql = N'
        INSERT INTO #Readings (
            codisl, Isla, nrobom, Producto, codprd,
            initialElectronicReading, amount, finalElectronicReading,
            finalAmount, difference, amountDifference
        )
        SELECT
            codisl, Isla, nrobom, Producto, codprd,
            initialElectronicReading, amount, finalElectronicReading,
            finalAmount, difference, amountDifference
        FROM OPENQUERY(' + @LinkedServerQuoted + N', ''' + REPLACE(@RemoteQuery, '''', '''''') + N''');
    ';
    EXEC sys.sp_executesql @Sql;

    ;WITH PaymentTotals AS (
        SELECT codisl, SUM(Total) AS TotalVentasReportadas
        FROM #Payments
        GROUP BY codisl
    ),
    ReadingTotals AS (
        SELECT codisl, SUM(finalAmount) AS TotalVentas
        FROM #Readings
        GROUP BY codisl
    ),
    SummaryRows AS (
        SELECT
            vi.codisl,
            COALESCE(MAX(i.Isla), MAX(p.Isla), MAX(r.Isla), 'Isla ' + CONVERT(VARCHAR(12), vi.codisl)) AS Isla,
            COALESCE(MAX(pt.TotalVentasReportadas), 0) AS TotalVentasReportadas,
            COALESCE(MAX(rt.TotalVentas), 0) AS TotalVentas
        FROM @ValidIslands vi
            LEFT JOIN #IslandNames i ON vi.codisl = i.codisl
            LEFT JOIN #Payments p ON vi.codisl = p.codisl
            LEFT JOIN #Readings r ON vi.codisl = r.codisl
            LEFT JOIN PaymentTotals pt ON vi.codisl = pt.codisl
            LEFT JOIN ReadingTotals rt ON vi.codisl = rt.codisl
        GROUP BY vi.codisl
    ),
    ResultRows AS (
        SELECT
            1 AS OrdenRegistro,
            'SUMMARY' AS TipoRegistro,
            s.codisl,
            s.Isla,
            CAST(NULL AS NVARCHAR(150)) AS ValorDescripcion,
            CAST(NULL AS DECIMAL(18, 2)) AS Total,
            CAST(NULL AS INT) AS nrobom,
            CAST(NULL AS NVARCHAR(150)) AS Producto,
            CAST(NULL AS INT) AS codprd,
            CAST(NULL AS DECIMAL(18, 3)) AS initialElectronicReading,
            CAST(NULL AS DECIMAL(18, 2)) AS amount,
            CAST(NULL AS DECIMAL(18, 3)) AS finalElectronicReading,
            CAST(NULL AS DECIMAL(18, 2)) AS finalAmount,
            CAST(NULL AS DECIMAL(18, 3)) AS difference,
            CAST(NULL AS DECIMAL(18, 2)) AS amountDifference,
            s.TotalVentasReportadas,
            s.TotalVentas,
            s.TotalVentasReportadas - s.TotalVentas AS Diferencia
        FROM SummaryRows s

        UNION ALL

        SELECT
            2 AS OrdenRegistro,
            'PAYMENT' AS TipoRegistro,
            p.codisl,
            COALESCE(p.Isla, i.Isla, 'Isla ' + CONVERT(VARCHAR(12), p.codisl)) AS Isla,
            p.ValorDescripcion,
            p.Total,
            CAST(NULL AS INT) AS nrobom,
            CAST(NULL AS NVARCHAR(150)) AS Producto,
            CAST(NULL AS INT) AS codprd,
            CAST(NULL AS DECIMAL(18, 3)) AS initialElectronicReading,
            CAST(NULL AS DECIMAL(18, 2)) AS amount,
            CAST(NULL AS DECIMAL(18, 3)) AS finalElectronicReading,
            CAST(NULL AS DECIMAL(18, 2)) AS finalAmount,
            CAST(NULL AS DECIMAL(18, 3)) AS difference,
            CAST(NULL AS DECIMAL(18, 2)) AS amountDifference,
            CAST(NULL AS DECIMAL(18, 2)) AS TotalVentasReportadas,
            CAST(NULL AS DECIMAL(18, 2)) AS TotalVentas,
            CAST(NULL AS DECIMAL(18, 2)) AS Diferencia
        FROM #Payments p
            LEFT JOIN #IslandNames i ON p.codisl = i.codisl

        UNION ALL

        SELECT
            3 AS OrdenRegistro,
            'READING' AS TipoRegistro,
            r.codisl,
            COALESCE(r.Isla, i.Isla, 'Isla ' + CONVERT(VARCHAR(12), r.codisl)) AS Isla,
            CAST(NULL AS NVARCHAR(150)) AS ValorDescripcion,
            CAST(NULL AS DECIMAL(18, 2)) AS Total,
            r.nrobom,
            r.Producto,
            r.codprd,
            r.initialElectronicReading,
            r.amount,
            r.finalElectronicReading,
            r.finalAmount,
            r.difference,
            r.amountDifference,
            CAST(NULL AS DECIMAL(18, 2)) AS TotalVentasReportadas,
            CAST(NULL AS DECIMAL(18, 2)) AS TotalVentas,
            CAST(NULL AS DECIMAL(18, 2)) AS Diferencia
        FROM #Readings r
            LEFT JOIN #IslandNames i ON r.codisl = i.codisl
    )
    SELECT
        TipoRegistro,
        codisl,
        Isla,
        ValorDescripcion,
        Total,
        nrobom,
        Producto,
        codprd,
        initialElectronicReading,
        amount,
        finalElectronicReading,
        finalAmount,
        difference,
        amountDifference,
        TotalVentasReportadas,
        TotalVentas,
        Diferencia
    FROM ResultRows
    ORDER BY
        codisl,
        OrdenRegistro,
        ValorDescripcion,
        nrobom,
        codprd;
END;
GO

-- Ejemplo de prueba:
-- EXEC [TG].[dbo].[sp_obtener_ventas_tabulador_detalle]
--     @TabId = 12345,
--     @CodigoEstacion = 7,
--     @FechaTabular = 45350,
--     @Turno = 11,
--     @Islands = '1,2,3,4',
--     @LinkedServer = '[192.168.6.101]',
--     @RemoteDbSchema = '[SG12_4179_20].[dbo]';
