<?php
class GasolinerasModel extends Model{
    public $cod;
    public $abr;
    public $den;
    public $dom;
    public $col;
    public $del;
    public $ciu;
    public $est;
    public $tel;
    public $fax;
    public $hraini1;
    public $hrafin1;
    public $hraini2;
    public $hrafin2;
    public $hraini3;
    public $hrafin3;
    public $hraini4;
    public $hrafin4;
    public $codalm;
    public $codext;
    public $dia1;
    public $dia2;
    public $dia3;
    public $dia4;
    public $codemp;
    public $cvecli;
    public $cveest;
    public $codred;
    public $fchact;
    public $turact;
    public $codpza;
    public $codcpo;
    public $dentdc;
    public $sersuc;
    public $facbdd;
    public $facusu;
    public $facpwd;
    public $facdir;
    public $codest;
    public $logusu;
    public $logfch;
    public $lognew;
    public $polcor;
    public $ultcor;
    public $geodat;
    public $geolat;
    public $geolng;
    public $codref;
    public $nropcc;
    public $prp;
    public $tipest;
    public $tiptdc;
    public $supter;
    public $supcon;
    public $fchini;
    public $cntemp;
    public $faccmp;
    public $datvar;
    public $correo;
    public $cto;
    public $codpos;
    public $paisat;
    public $nropic;
    public $codflg;
    public $prdcon;
    public $estgem;
    public $satdat;
    public $fchsyn;
    public $denamp;

    /**
     * @return array|false
     * @throws Exception
     */
    public function get_stations() : array|false {
        $query = 'SELECT 
                    t1.cod, t1.abr, t1.den, t1.dom, t1.col, t1.codemp, t2.den
                    FROM [SG12].[dbo].[Gasolineras] t1 
                    LEFT JOIN SG12.dbo.Empresas t2 on t1.codemp = t2.cod
                    WHERE t1.datvar = 0 
                    ORDER BY abr;';
        return ($this->sql->select($query)) ?: false ;
    }

    public function get_station_by_code($cod) : array|false {
        $query = 'SELECT TOP (1) cod, abr, den, dom, col FROM [SG12].[dbo].[Gasolineras] WHERE cod = ?;';
        return ($this->sql->select($query, [$cod])) ?: false ;
    }
    public function get_company(){
        $query = 'SELECT t1.codemp, t2.den
                    FROM [SG12].[dbo].[Gasolineras] t1
                    left join SG12.dbo.Empresas t2 on t1.codemp = t2.cod
                    WHERE t1.datvar = 0 
                    group by t1.codemp,t2.den';
        $params = [];
        return ($this->sql->select($query)) ?: false ;
    }

    /**
     * @return array|false
     * @throws Exception
     */
    public function get_active_stations() : array|false {
        $query = 'SELECT cod, abr, den, dom, col FROM [SG12].[dbo].[Gasolineras] WHERE datvar = 0 ORDER BY abr;';
        return ($this->sql->select($query)) ?: false ;
    }

    function GetVentasLogistica($from, $until, $codgas, $product) {
        set_time_limit(0);
        ini_set('max_execution_time', 0);
        return ($this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_inventarios_por_turno]', [$from, $until, $codgas, $product])) ?: false ;
    }

    public function get_active_station_TG() : array|false {
        $query = 'SELECT Codigo, Nombre, Estacion, iva, Servidor, BaseDatos, PermisoCRE FROM [TG].[dbo].[Estaciones] WHERE activa = 1 AND Codigo > 0;';
        $rs = $this->sql->select($query);
        $actives = [];
        if ($rs) {
            foreach ($rs as $key => $value) {
                // Vamos a revisar si el puertp 1433 esta abierto
                if (@fsockopen($value['Servidor'], 1433, $errno, $errstr, 1)) {
                    $actives[] = $value;
                }
            }
        }
        return ($actives) ?: false ;
    }

    function get_fuel_prices() : array|false {
        return ($this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_precios_combustibles]')) ?: false ;
    }

    function capture_prices($codprd, $fch, $hour, $pre, $iva, $codgas) : void {
        $this->sql->executeStoredProcedure('[TG].[dbo].[sp_capture_prices]', [$codprd, $fch, $hour, $pre, $iva, $codgas, $this->databases[$codgas]]);
    }

    function get_estations_servidor() : array|false{
        $query = 'SELECT
                    t1.cod as codigo,
                    t1.abr as estacion_nombre,
                    t1.den as estacion_empresa,
                    t2.den as empresa,
                    t1.facusu as usuario,
                    t1.facpwd as contra,
                    t3.BaseDatos as base_datos,
                    t3.email as email,
                    t3.Servidor as servidor,
                    t3.vis_fac
                    from [SG12].dbo.Gasolineras t1
                    LEFT JOIN [sg12].dbo.Empresas t2 on t1.codemp = t2.cod
                    LEFT JOIN [TG].dbo.Estaciones t3 on t1.cod = t3.Codigo
                    Where
                    BaseDatos is not null
                    and BaseDatos != \'\' ';
        return $this->sql->select($query);
    }
    function get_estations_servidor_cod_gas($cod) : array|false{
        $query = 'SELECT
                    t1.cod as codigo,
                    t1.abr as estacion_nombre,
                    t1.den as estacion_empresa,
                    t2.den as empresa,
                    t1.facusu as usuario,
                    t1.facpwd as contra,
                    t3.BaseDatos as base_datos,
                    t3.email as email,
                    t3.Servidor as servidor,
                    t3.vis_fac
                    from [SG12].dbo.Gasolineras t1
                    LEFT JOIN [sg12].dbo.Empresas t2 on t1.codemp = t2.cod
                    LEFT JOIN [TG].dbo.Estaciones t3 on t1.cod = t3.Codigo
                    Where
                    BaseDatos is not null
                    and BaseDatos != \'\' 
                    and t1.cod = ?
                    ';
        $params = [$cod];
        return ($rm = $this->sql->select($query,$params)) ? $rm[0] : false ;
    }

    function get_fuel_prices_by_station($Servidor, $BaseDatos, $Codigo, $Estacion, $Nombre) {
        return ($this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_precios_combustibles_estacion]', [$Servidor, $BaseDatos, $Codigo, $Estacion, $Nombre])) ?: false ;
    }

    function GetInventarioMermasConsolidado($from, $until, $codgas, $codprd) {
        $sql = "
        SET NOCOUNT ON;
        DECLARE @from_param    INT = ?;
        DECLARE @until_param   INT = ?;
        DECLARE @codgas_param  INT = ?;
        DECLARE @codprd_param  INT = ?;

        DROP TABLE IF EXISTS #ResultadosEstaciones;
        DROP TABLE IF EXISTS #ErrorLog;

        CREATE TABLE #ResultadosEstaciones (
            Servidor            NVARCHAR(255),
            BaseDatos           NVARCHAR(255),
            Fecha               NVARCHAR(10),
            Estacion            NVARCHAR(255),
            Producto            NVARCHAR(255),
            Turno               NVARCHAR(10),
            InventarioInicial   FLOAT,
            CantidadCompra      FLOAT,
            VentasReales        FLOAT,
            InventarioContable  FLOAT,
            Inventario          FLOAT,
            Diferencia          FLOAT,
            Estado              NVARCHAR(10),
            PctMerma            FLOAT,
        );

        CREATE TABLE #ErrorLog (
            Estacion  NVARCHAR(255),
            Servidor  NVARCHAR(255),
            TipoError NVARCHAR(20),
            ErrNum    INT,
            ErrMsg    NVARCHAR(MAX),
            Momento   DATETIME DEFAULT GETDATE()
        );

        DECLARE @condicion_codprd NVARCHAR(MAX) =
            CASE WHEN @codprd_param = 0
                 THEN 'codprd IN (1,2,3,179,180,181,192,193)'
                 ELSE 'codprd = ' + CAST(@codprd_param AS NVARCHAR)
            END;

        DECLARE @extra_where NVARCHAR(MAX) =
            CASE WHEN @codgas_param = 2
                 THEN N' WHERE SdoReal > 0 AND SdoInicial > 0'
                 ELSE N''
            END;

        DECLARE
            @Nombre      NVARCHAR(255),
            @Servidor    NVARCHAR(255),
            @BaseDatos   NVARCHAR(255),
            @test_result INT,
            @Consulta    NVARCHAR(MAX);

        DECLARE cursor_estaciones CURSOR LOCAL FAST_FORWARD FOR
            SELECT Nombre, Servidor, BaseDatos
            FROM [TG].[dbo].[Estaciones]
            WHERE (@codgas_param = 0 AND Codigo NOT IN (0, 4, 20))
               OR (@codgas_param <> 0 AND Codigo = @codgas_param);

        OPEN cursor_estaciones;
        FETCH NEXT FROM cursor_estaciones INTO @Nombre, @Servidor, @BaseDatos;

        WHILE @@FETCH_STATUS = 0
        BEGIN
            SET @test_result = -1;
            BEGIN TRY
                EXEC @test_result = sp_testlinkedserver @servername = @Servidor;
            END TRY
            BEGIN CATCH
                INSERT INTO #ErrorLog (Estacion, Servidor, TipoError, ErrNum, ErrMsg)
                VALUES (@Nombre, @Servidor, 'SIN_CONEXION', ERROR_NUMBER(), ERROR_MESSAGE());
            END CATCH;

            IF @test_result = 0
            BEGIN
                BEGIN TRY
                    SET @Consulta = N'
                    INSERT INTO #ResultadosEstaciones
                    SELECT
                        ''' + @Servidor + ''',
                        ''' + @BaseDatos + ''',
                        Fecha,
                        ''' + @Nombre   + ''',
                        Producto,
                        ''DÍA'' as Turno,
                        SdoInicial as InventarioInicial,
                        Compras as CantidadCompra,
                        Ventas as VentasReales,
                        SdoFinal as InventarioContable,
                        SdoReal as Inventario,
                        Merma as Diferencia,
                        Estado,
                        PctMerma
                    FROM OPENQUERY([' + @Servidor + '], ''
                        SELECT
                            Fecha,
                            Producto,
                            SUM(SdoInicial)                                                                         AS SdoInicial,
                            SUM(Compras)                                                                            AS Compras,
                            SUM(Ventas)                                                                             AS Ventas,
                            ROUND((SUM(SdoInicial) - SUM(Ventas)) + SUM(Compras), 2)                               AS SdoFinal,
                            SUM(SdoReal)                                                                            AS SdoReal,
                            ROUND(SUM(SdoReal) - ROUND((SUM(SdoInicial) - SUM(Ventas)) + SUM(Compras), 2), 2)      AS Merma,
                            CASE
                                WHEN ABS(ROUND(SUM(SdoReal) - ROUND((SUM(SdoInicial) - SUM(Ventas)) + SUM(Compras), 2), 2)) >= 9000
                                THEN ''''DIFERENCIA''''
                            END AS Estado,
                            ROUND(
                                CASE WHEN SUM(SdoInicial) = 0 THEN 0
                                ELSE (SUM(SdoReal) - ROUND((SUM(SdoInicial) - SUM(Ventas)) + SUM(Compras), 2))
                                     / NULLIF(SUM(Ventas), 0) * 100
                                END,
                            2) AS PctMerma
                        FROM (
                            SELECT
                                V.Fecha,
                                V.Producto,
                                ROUND(ISNULL(IA.Cantidad, 0), 2) AS SdoInicial,
                                ISNULL(C.CantidadCompra,  0)     AS Compras,
                                ROUND(V.VentasReales, 2)         AS Ventas,
                                ROUND(ISNULL(I.Cantidad,  0), 2) AS SdoReal
                            FROM (
                                SELECT
                                    CONVERT(VARCHAR(10), CAST(cal.fch AS DATETIME) - 1, 23) AS Fecha,
                                    p.den          AS Producto,
                                    cal.codprd     AS CodProducto,
                                    cal.codgas     AS CodGasolinera,
                                    cal.fch,
                                    ISNULL(SUM(v.canven), 0) AS VentasReales
                                FROM (
                                    SELECT DISTINCT v2.fch, v2.codprd, i3.codgas
                                    FROM ' + QUOTENAME(@BaseDatos) + '.dbo.Ventas v2
                                    JOIN ' + QUOTENAME(@BaseDatos) + '.dbo.Islas i3 ON v2.codisl = i3.cod
                                    WHERE ' + @condicion_codprd + '
                                      AND v2.fch BETWEEN ' + CAST(@from_param AS NVARCHAR) + ' AND ' + CAST(@until_param AS NVARCHAR) + '
                                    UNION
                                    SELECT DISTINCT fch, codprd, codgas
                                    FROM ' + QUOTENAME(@BaseDatos) + '.dbo.StockReal (NOLOCK)
                                    WHERE ' + @condicion_codprd + '
                                      AND fch BETWEEN ' + CAST(@from_param AS NVARCHAR) + ' AND ' + CAST(@until_param AS NVARCHAR) + '
                                      AND nrotur BETWEEN 40 AND 49
                                ) cal
                                LEFT JOIN ' + QUOTENAME(@BaseDatos) + '.dbo.Productos p  ON cal.codprd = p.cod
                                LEFT JOIN ' + QUOTENAME(@BaseDatos) + '.dbo.Ventas    v  ON cal.fch = v.fch AND cal.codprd = v.codprd
                                LEFT JOIN ' + QUOTENAME(@BaseDatos) + '.dbo.Islas     i2 ON v.codisl = i2.cod AND i2.codgas = cal.codgas
                                GROUP BY cal.fch, cal.codprd, cal.codgas, p.den
                            ) V
                            LEFT JOIN (
                                SELECT
                                    CONVERT(VARCHAR(10), CAST(sr.fch AS DATETIME) - 1, 23) AS Fecha,
                                    sr.codprd          AS CodProducto,
                                    sr.codgas          AS CodGasolinera,
                                    SUM(sr.can)        AS Cantidad
                                FROM ' + QUOTENAME(@BaseDatos) + '.dbo.StockReal sr (NOLOCK)
                                INNER JOIN (
                                    SELECT fch, codprd, codgas, MAX(nrotur) AS UltimoCorte
                                    FROM ' + QUOTENAME(@BaseDatos) + '.dbo.StockReal (NOLOCK)
                                    WHERE fch BETWEEN ' + CAST(@from_param AS NVARCHAR) + ' AND ' + CAST(@until_param AS NVARCHAR) + '
                                      AND ' + @condicion_codprd + '
                                      AND nrotur BETWEEN 40 AND 49
                                    GROUP BY fch, codprd, codgas
                                ) ult ON sr.fch = ult.fch AND sr.codprd = ult.codprd
                                      AND sr.codgas = ult.codgas AND sr.nrotur = ult.UltimoCorte
                                GROUP BY CONVERT(VARCHAR(10), CAST(sr.fch AS DATETIME) - 1, 23), sr.codprd, sr.codgas
                            ) I  ON V.Fecha         = I.Fecha
                                AND V.CodProducto   = I.CodProducto
                                AND V.CodGasolinera = I.CodGasolinera
                            LEFT JOIN (
                                SELECT
                                    fch,
                                    codprd      AS CodProducto,
                                    codgas      AS CodGasolinera,
                                    SUM(can)    AS Cantidad
                                FROM ' + QUOTENAME(@BaseDatos) + '.dbo.StockReal (NOLOCK)
                                WHERE fch BETWEEN (' + CAST(@from_param AS NVARCHAR) + ' - 1) AND (' + CAST(@until_param AS NVARCHAR) + ' - 1)
                                  AND ' + @condicion_codprd + '
                                  AND nrotur = 40
                                GROUP BY fch, codprd, codgas
                            ) IA ON (V.fch - 1)      = IA.fch
                                 AND V.CodProducto   = IA.CodProducto
                                 AND V.CodGasolinera = IA.CodGasolinera
                        LEFT JOIN (
                            SELECT
                                CONVERT(VARCHAR(10), CAST(fch AS DATETIME) - 1, 23) AS Fecha,
                                codprd AS CodProducto,
                                codgas AS CodGasolinera,
                                SUM(can) AS CantidadCompra
                            FROM ' + QUOTENAME(@BaseDatos) + '.dbo.Movimientos (NOLOCK)
                            WHERE fch BETWEEN ' + CAST(@from_param AS NVARCHAR) + ' AND ' + CAST(@until_param AS NVARCHAR) + '
                              AND can > 0
                              AND ' + @condicion_codprd + '
                            GROUP BY fch, codgas, codprd
                            ) C  ON V.Fecha         = C.Fecha
                                AND V.CodProducto   = C.CodProducto
                                AND V.CodGasolinera = C.CodGasolinera
                        ) base
                        GROUP BY Fecha, Producto
                    '')' + @extra_where;

                    EXEC sp_executesql @Consulta;
                END TRY
                BEGIN CATCH
                    INSERT INTO #ErrorLog (Estacion, Servidor, TipoError, ErrNum, ErrMsg)
                    VALUES (@Nombre, @Servidor, 'QUERY_ERROR', ERROR_NUMBER(), ERROR_MESSAGE());
                END CATCH;
            END

            FETCH NEXT FROM cursor_estaciones INTO @Nombre, @Servidor, @BaseDatos;
        END

        CLOSE cursor_estaciones;
        DEALLOCATE cursor_estaciones;

        SELECT * FROM #ResultadosEstaciones
        ORDER BY Fecha, Estacion, Producto;
        ";

        return $this->sql->select($sql, [$from, $until, $codgas, $codprd]);
    }
}