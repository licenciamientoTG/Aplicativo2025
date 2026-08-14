USE [TG];
GO

CREATE OR ALTER PROCEDURE [dbo].[sp_obtener_ingresos_por_estacion]
    @CodigoEstacion INT = NULL,
    @FechaInicio    DATE,
    @FechaFin       DATE,
    @CodVal         VARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    ------------------------------------------------------------
    -- Validaciones
    ------------------------------------------------------------
    IF @FechaInicio IS NULL OR @FechaFin IS NULL
    BEGIN
        RAISERROR('Debe indicar @FechaInicio y @FechaFin.', 16, 1);
        RETURN;
    END;

    DECLARE @FchInicio INT =
        DATEDIFF(DAY, '19000101', @FechaInicio) + 1;

    DECLARE @FchFin INT =
        DATEDIFF(DAY, '19000101', @FechaFin) + 1;

    IF @FchInicio > @FchFin
    BEGIN
        RAISERROR('La fecha inicial no puede ser mayor a la fecha final.', 16, 1);
        RETURN;
    END;

    ------------------------------------------------------------
    -- Estaciones
    ------------------------------------------------------------
    DECLARE @Estaciones TABLE
    (
        CodigoEstacion INT PRIMARY KEY,
        LinkedServer    SYSNAME,
        RemoteDb       SYSNAME,
        Nombre         VARCHAR(50)
    );

    INSERT INTO @Estaciones
    (
        CodigoEstacion,
        LinkedServer,
        RemoteDb,
        Nombre
    )
    VALUES
        (2,  '[192.168.7.101]',  '[SG12_41882020]',   'Gemela Grande'),
        (3,  '[192.168.28.101]', '[SG12_11007+2020]', 'Aguascalientes'),
        (5,  '[192.168.2.101]',  '[SG12_114912020]',  'Lerdo'),
        (6,  '[192.168.5.101]',  '[SG12_25262020]',   'Lopez Mateos'),
        (7,  '[192.168.6.101]',  '[SG12_4179_20]',    'Gemela Chica'),
        (8,  '[192.168.9.101]',  '[SG12_53172020]',   'Municipio Libre'),
        (9,  '[192.168.10.101]', '[SG12_5465]',       'Aztecas'),
        (10, '[192.168.11.101]', '[SG12_6410]',       'Misiones'),
        (11, '[192.168.19.101]', '[SG12_6947_2020]',  'Puerto de Palos'),
        (12, '[192.168.13.101]', '[CG_7167]',         'Miguel de la Madrid'),
        (13, '[192.168.14.101]', '[SG12_8244]',       'Permuta'),
        (14, '[192.168.15.101]', '[SG12_9191]',       'Electrolux'),
        (15, '[192.168.16.101]', '[SG12_92352020]',   'Aeronautica'),
        (16, '[192.168.17.101]', '[SG12_98852020]',   'Custodia'),
        (17, '[192.168.18.101]', '[SG12_9893]',       'Anapra'),
        (18, '[192.168.4.101]',  '[sg2172]',          'Parral'),
        (19, '[192.168.3.101]',  '[CG_1376]',         'Delicias'),
        (21, '[192.168.8.101]',  '[Custodia5170]',    'Plutarco'),
        (22, '[192.168.30.101]', '[CG_1163]',         'Tecnologico'),
        (23, '[192.168.21.101]', '[CG_9733]',         'Ejercito Nacional'),
        (24, '[192.168.22.101]', '[CG_4457]',         'Satelite'),
        (25, '[192.168.23.101]', '[cg_1159]',         'Las Fuentes'),
        (26, '[192.168.24.101]', '[CG_1156]',         'Clara'),
        (27, '[192.168.25.101]', '[CG_10141]',        'Solis'),
        (28, '[192.168.26.101]', '[SG12_12097]',      'Santiago Troncoso'),
        (29, '[192.168.27.101]', '[CG_1148]',         'Jarudo'),
        (30, '[192.168.29.101]', '[CG_23214]',        'Hermanos Escobar'),
        (31, '[192.168.32.101]', '[CG_1242]',         'Villa Ahumada'),
        (32, '[192.168.33.101]', '[CG_19190]',        'El Castano'),
        (33, '[192.168.31.101]', '[CG_24938]',        'Travel Center'),
        (34, '[192.168.34.101]', '[CG_24499]',        'Picachos'),
        (35, '[192.168.35.101]', '[CG_24500]',        'Ventanas'),
        (36, '[192.168.36.101]', '[CG_14946]',        'San Rafael'),
        (37, '[192.168.37.101]', '[CG_15071]',        'Puertecito'),
        (38, '[192.168.38.101]', '[CG_15901]',        'Jesus Maria'),
        (39, '[192.168.39.101]', '[CG_12442]',        'Gabriela Mistral'),
        (40, '[192.168.40.101]', '[E10702]',          'Praxedis');

    ------------------------------------------------------------
    -- Validar estacion
    ------------------------------------------------------------
    IF @CodigoEstacion IS NOT NULL
       AND @CodigoEstacion <> 0
       AND NOT EXISTS
       (
           SELECT 1
           FROM @Estaciones
           WHERE CodigoEstacion = @CodigoEstacion
       )
    BEGIN
        RAISERROR('CodigoEstacion no reconocido.', 16, 1);
        RETURN;
    END;

    ------------------------------------------------------------
    -- Tabla de resultados
    ------------------------------------------------------------
    CREATE TABLE #Ingresos
    (
        CodigoEstacion INT NOT NULL,
        Estacion       VARCHAR(50) NULL,
        fch            INT NOT NULL,
        Fecha          DATE NULL,
        Isla           NVARCHAR(150) NULL,
        nrotur         INT NULL,
        codval         INT NULL,
        Valor          NVARCHAR(150) NULL,
        can            DECIMAL(18, 3) NULL,
        mto            DECIMAL(18, 2) NULL
    );

    ------------------------------------------------------------
    -- Tabla de errores
    ------------------------------------------------------------
    CREATE TABLE #Errores
    (
        CodigoEstacion INT NOT NULL,
        Estacion       VARCHAR(50) NULL,
        Mensaje        NVARCHAR(4000) NULL
    );

    ------------------------------------------------------------
    -- Parsear @CodVal
    ------------------------------------------------------------
    DECLARE @CodValList TABLE
    (
        codval INT PRIMARY KEY
    );

    DECLARE @CodValWork VARCHAR(MAX) =
        LTRIM(RTRIM(ISNULL(@CodVal, '')));

    DECLARE @CodValPos INT;
    DECLARE @CodValItem VARCHAR(32);
    DECLARE @CodValInt INT;

    ------------------------------------------------------------
    -- Solo parseamos si realmente viene algo
    ------------------------------------------------------------
    IF @CodValWork <> ''
    BEGIN

        -- Agregamos coma final para facilitar el recorrido
        SET @CodValWork = @CodValWork + ',';

        SET @CodValPos = CHARINDEX(',', @CodValWork);

        WHILE @CodValPos > 0
        BEGIN
            SET @CodValItem =
                LTRIM(RTRIM(
                    SUBSTRING(
                        @CodValWork,
                        1,
                        @CodValPos - 1
                    )
                ));

            SET @CodValInt = TRY_CAST(@CodValItem AS INT);

            IF @CodValInt IS NOT NULL
               AND NOT EXISTS
               (
                   SELECT 1
                   FROM @CodValList
                   WHERE codval = @CodValInt
               )
            BEGIN
                INSERT INTO @CodValList (codval)
                VALUES (@CodValInt);
            END;

            SET @CodValWork =
                SUBSTRING(
                    @CodValWork,
                    @CodValPos + 1,
                    LEN(@CodValWork)
                );

            SET @CodValPos = CHARINDEX(',', @CodValWork);
        END;
    END;

    ------------------------------------------------------------
    -- Determinar si se debe aplicar filtro
    ------------------------------------------------------------
    DECLARE @HasCodVal BIT = 0;
    DECLARE @CodValFilter VARCHAR(MAX) = NULL;

    IF EXISTS (SELECT 1 FROM @CodValList)
    BEGIN
        SET @HasCodVal = 1;

        SELECT @CodValFilter =
            STUFF
            (
                (
                    SELECT
                        ',' + CONVERT(VARCHAR(12), codval)
                    FROM @CodValList
                    ORDER BY codval
                    FOR XML PATH(''), TYPE
                ).value('.', 'VARCHAR(MAX)'),
                1,
                1,
                ''
            );
    END;

    ------------------------------------------------------------
    -- Variables para cursor
    ------------------------------------------------------------
    DECLARE
        @CodEst INT,
        @LS SYSNAME,
        @Db SYSNAME,
        @Nombre VARCHAR(50),
        @LSQuoted SYSNAME,
        @RemoteQuery NVARCHAR(MAX),
        @Sql NVARCHAR(MAX);

    ------------------------------------------------------------
    -- Cursor de estaciones
    ------------------------------------------------------------
    DECLARE estaciones_cursor CURSOR LOCAL FAST_FORWARD FOR
        SELECT
            CodigoEstacion,
            LinkedServer,
            RemoteDb,
            Nombre
        FROM @Estaciones
        WHERE
            @CodigoEstacion IS NULL
            OR @CodigoEstacion = 0
            OR CodigoEstacion = @CodigoEstacion
        ORDER BY CodigoEstacion;

    OPEN estaciones_cursor;

    FETCH NEXT FROM estaciones_cursor
        INTO @CodEst, @LS, @Db, @Nombre;

    WHILE @@FETCH_STATUS = 0
    BEGIN

        SET @LSQuoted =
            QUOTENAME(
                REPLACE(
                    REPLACE(@LS, '[', ''),
                    ']',
                    ''
                )
            );

        --------------------------------------------------------
        -- Consulta remota
        --------------------------------------------------------
        SET @RemoteQuery = N'
            SELECT
                i.fch,
                CONVERT(VARCHAR(10), CAST(i.fch AS DATETIME) - 1, 23) AS Fecha,
                isl.den COLLATE Modern_Spanish_CI_AS AS Isla,
                i.nrotur,
                i.codval,
                v.den COLLATE Modern_Spanish_CI_AS AS Valor,
                i.can,
                i.mto
            FROM ' + @Db + N'.[dbo].[Ingresos] i
            LEFT JOIN ' + @Db + N'.[dbo].[Valores] v
                ON i.codval = v.cod
            LEFT JOIN ' + @Db + N'.[dbo].[Islas] isl
                ON i.codisl = isl.cod
            WHERE
                i.fch BETWEEN ' + CONVERT(VARCHAR(12), @FchInicio) + N'
                          AND ' + CONVERT(VARCHAR(12), @FchFin) + N'
                AND i.codgas = ' + CONVERT(VARCHAR(12), @CodEst);

        --------------------------------------------------------
        -- IMPORTANTE:
        -- Solo agregar filtro por codval si se recibió alguno.
        --------------------------------------------------------
        IF @HasCodVal = 1
        BEGIN
            SET @RemoteQuery =
                @RemoteQuery
                + N' AND i.codval IN (' + @CodValFilter + N')';
        END;

        --------------------------------------------------------
        -- OPENQUERY
        --------------------------------------------------------
        SET @Sql = N'
            INSERT INTO #Ingresos
            (
                CodigoEstacion,
                Estacion,
                fch,
                Fecha,
                Isla,
                nrotur,
                codval,
                Valor,
                can,
                mto
            )
            SELECT
                ' + CONVERT(VARCHAR(12), @CodEst) + N',
                ''' + REPLACE(@Nombre, '''', '''''') + N''',
                fch,
                TRY_CONVERT(DATE, Fecha),
                Isla,
                nrotur,
                codval,
                Valor,
                can,
                mto
            FROM OPENQUERY
            (
                ' + @LSQuoted + N',
                ''' + REPLACE(@RemoteQuery, '''', '''''') + N'''
            );
        ';

        --------------------------------------------------------
        -- Ejecutar
        --------------------------------------------------------
        BEGIN TRY

            EXEC sys.sp_executesql @Sql;

        END TRY
        BEGIN CATCH

            INSERT INTO #Errores
            (
                CodigoEstacion,
                Estacion,
                Mensaje
            )
            VALUES
            (
                @CodEst,
                @Nombre,
                ERROR_MESSAGE()
            );

        END CATCH;

        FETCH NEXT FROM estaciones_cursor
            INTO @CodEst, @LS, @Db, @Nombre;
    END;

    CLOSE estaciones_cursor;
    DEALLOCATE estaciones_cursor;

    ------------------------------------------------------------
    -- Resultado principal
    ------------------------------------------------------------
    SELECT
        CodigoEstacion,
        Estacion,
        Fecha,
        Isla,
        nrotur AS Turno,
        codval AS CodigoValor,
        Valor,
        can AS Cantidad,
        mto AS Monto
    FROM #Ingresos
    ORDER BY
        CodigoEstacion,
        Fecha,
        nrotur,
        Isla,
        codval;

    ------------------------------------------------------------
    -- Errores de estaciones
    ------------------------------------------------------------
    IF EXISTS (SELECT 1 FROM #Errores)
    BEGIN
        SELECT
            CodigoEstacion,
            Estacion,
            Mensaje
        FROM #Errores
        ORDER BY CodigoEstacion;
    END;

END;
GO