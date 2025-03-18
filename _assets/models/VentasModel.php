<?php
class VentasModel extends Model{
    public $fch;
    public $codisl;
    public $nrotur;
    public $codprd;
    public $canexp;
    public $canven;
    public $mtoven;
    public $canent;
    public $logexp;
    public $fchsyn;

    function get_sales($fch) : array|false {
        $query = "
            SELECT
                Fecha,
                ISNULL([02 LERDO], 0) '02 LERDO',
                ISNULL([03 DELICIAS], 0) '03 DELICIAS',
                ISNULL([04 PARRAL], 0) '04 PARRAL',
                ISNULL([05 LOPEZ MATEOS], 0) '05 LOPEZ MATEOS',
                ISNULL([06 GEMELA CHICA], 0) '06 GEMELA CHICA',
                ISNULL([07 GEMEL GRANDE], 0) '07 GEMEL GRANDE',
                ISNULL([08 PLUTARCO], 0) '08 PLUTARCO',
                ISNULL([09 MPIO. LIBRE], 0) '09 MPIO. LIBRE',
                ISNULL([10 AZTECAS], 0) '10 AZTECAS',
                ISNULL([11 MISIONES], 0) '11 MISIONES',
                ISNULL([12 PTO DE PALOS], 0) '12 PTO DE PALOS',
                ISNULL([13 MIGUEL D MAD], 0) '13 MIGUEL D MAD',
                ISNULL([14 PERMUTA], 0) '14 PERMUTA',
                ISNULL([15 ELECTROLUX], 0) '15 ELECTROLUX',
                ISNULL([16 AERONAUTICA], 0) '16 AERONAUTICA',
                ISNULL([17 CUSTODIA], 0) '17 CUSTODIA',
                ISNULL([18 ANAPRA], 0) '18 ANAPRA',
                ISNULL([19 INDEPENDENCI], 0) '19 INDEPENDENCI',
                ISNULL([20 TECNOLOGICO], 0) '20 TECNOLOGICO',
                ISNULL([21 EJERCITO NAL], 0) '21 EJERCITO NAL',
                ISNULL([22 SATELITE], 0) '22 SATELITE',
                ISNULL([23 LAS FUENTES], 0) '23 LAS FUENTES',
                ISNULL([24 CLARA], 0) '24 CLARA',
                ISNULL([25 SOLIS], 0) '25 SOLIS',
                ISNULL([26 SANTIAGO TRO], 0) '26 SANTIAGO TRO',
                ISNULL([27 JARUDO], 0) '27 JARUDO',
                ISNULL([28 HERMANOS ESC], 0) '28 HERMANOS ESC',
                ISNULL([29 VILLA AHUMAD], 0) '29 VILLA AHUMAD',
                ISNULL([30 EL CASTAÑO], 0) '30 EL CASTAÑO',
                ISNULL([31 TRAVEL CENTE], 0) '31 TRAVEL CENTE',
                ISNULL([32 PICACHOS], 0) '32 Picachos',
                ISNULL([33 VENTANAS], 0) '33 Ventanas',
                ISNULL([34 SAN RAFAEL], 0) '34 SAN RAFAEL',
                ISNULL([35 PUERTECITO], 0) '35 PUERTECITO',
                ISNULL([36 JESUS MARIA], 0) '36 JESUS MARIA'
            FROM
                (SELECT
                    DATEADD(DAY, -1, CAST(t1.fch AS DATETIME)) AS Fecha,
                    t3.abr Estacion,
                    CASE
                        WHEN T1.codprd IN (1, 181) THEN 'Diesel Automotriz'
                        WHEN T1.codprd IN (2, 179, 192) THEN 'T-Maxima Regular'
                        WHEN T1.codprd IN (3, 180, 193) THEN 'T-Super Premium'
                    END AS Producto,
                    SUM(T1.canven) AS VentasReales
                FROM
                    [SG12].[dbo].[Ventas] t1
                        LEFT JOIN [SG12].[dbo].[Islas] t2 ON t1.codisl = t2.cod
                        LEFT JOIN [SG12].[dbo].[Gasolineras] t3 ON t2.codgas = t3.cod
                WHERE
                    t1.fch = ({$fch}) AND t1.codprd IN (1, 2, 3, 179, 180, 181, 192, 193)
                GROUP BY t1.fch, t3.abr, t3.cod,
                CASE
                    WHEN t1.codprd IN (1, 181) THEN 'Diesel Automotriz'
                    WHEN t1.codprd IN (2, 179, 192) THEN 'T-Maxima Regular'
                    WHEN t1.codprd IN (3, 180, 193) THEN 'T-Super Premium'
                END
                ) AS Source
            PIVOT
            (
                SUM(VentasReales)
                FOR Estacion IN ([02 LERDO], [03 DELICIAS], [04 PARRAL], [05 LOPEZ MATEOS], [06 GEMELA CHICA],
                [07 GEMEL GRANDE], [08 PLUTARCO], [09 MPIO. LIBRE], [10 AZTECAS], [11 MISIONES],
                [12 PTO DE PALOS], [13 MIGUEL D MAD], [14 PERMUTA], [15 ELECTROLUX], [16 AERONAUTICA],
                [17 CUSTODIA], [18 ANAPRA], [19 INDEPENDENCI], [20 TECNOLOGICO], [21 EJERCITO NAL],
                [22 SATELITE], [23 LAS FUENTES], [24 CLARA], [25 SOLIS], [26 SANTIAGO TRO],
                [27 JARUDO], [28 HERMANOS ESC], [29 VILLA AHUMAD], [30 EL CASTAÑO], [31 TRAVEL CENTE], [32 Picachos], [33 Ventanas], [34 SAN RAFAEL], [35 PUERTECITO], [36 JESUS MARIA])
            ) AS PivotTable
            ORDER BY Fecha;";

        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_inventories($from, $until) {
        $query = "
        DECLARE @from INT = {$from};
        DECLARE @until INT = {$until};
        
        WITH VentasTotales AS (
            SELECT
                t2.codgas,
                t1.codprd,
                fch,
                ROUND(SUM(t1.canven), 3) AS Total
            FROM
                [SG12].[dbo].[Ventas] t1
                LEFT JOIN [SG12].[dbo].[Islas] t2 ON t1.codisl = t2.cod 
            WHERE
                fch BETWEEN @from AND @until AND
                t1.codprd IN (179, 180, 181, 192, 193)
            GROUP BY t2.codgas, t1.codprd, fch
        ),
        MovimientosTotales AS (
            SELECT 
                SUM(can) Total, 
                fch, 
                codgas,
                codprd
            FROM 
                [SG12].[dbo].[Movimientos]
            WHERE 
                fch BETWEEN @from AND @until 
                AND codprd IN (179, 180, 181, 192, 193) 
                AND can > 0
            GROUP BY fch, codgas, codprd
        ),
        Calculado AS (
            SELECT 
                t1.fch,
                t1.codgas,
                t1.codprd,
                dbo.IntToDate(t1.fch) Fecha,
                ROUND(COALESCE(
                    LAG(t1.can) OVER (
                        PARTITION BY t1.codgas, t1.codprd 
                        ORDER BY t1.fch, t1.nrotur
                    ), 
                    0
                ), 3) AS SdoInicial,
                ISNULL(t3.Total, 0) AS Compras,
                ISNULL(t2.Total, 0) AS Ventas,
                ROUND((COALESCE(
                    LAG(t1.can) OVER (
                        PARTITION BY t1.codgas, t1.codprd 
                        ORDER BY t1.fch, t1.nrotur
                    ), 
                    0
                ) + ISNULL(t3.Total, 0) - ISNULL(t2.Total, 0)), 3) AS Saldo_Final,
                ROUND(t1.can, 3) SaldoReal,
                ROUND((t1.can - (COALESCE(
                    LAG(t1.can) OVER (
                        PARTITION BY t1.codgas, t1.codprd 
                        ORDER BY t1.fch, t1.nrotur
                    ), 
                    0
                ) + ISNULL(t3.Total, 0) - ISNULL(t2.Total, 0))), 3) Merma
            FROM 
                [SG12].[dbo].[StockReal] t1
                LEFT JOIN VentasTotales t2 ON t1.fch = t2.fch AND t1.codgas = t2.codgas AND t1.codprd = t2.codprd
                LEFT JOIN MovimientosTotales t3 ON t1.fch = t3.fch AND t1.codgas = t3.codgas AND t1.codprd = t3.codprd  
            WHERE
                t1.fch BETWEEN (@from - 1) AND @until AND
                t1.codprd IN (179, 180, 181, 192, 193) AND
                t1.nrotur >= 40
        )
        SELECT
            t3.den Producto,
            t1.abr Estacion,
            ROUND(COALESCE(t2.Total, 0), 3) SaldoInicial,
            ROUND(COALESCE(t4.Total, 0), 3) AS Compras,
            COALESCE(t5.Total, 0) Ventas,
            ROUND(COALESCE((COALESCE(t2.Total, 0) + COALESCE(t4.Total, 0) - COALESCE(t5.Total, 0)), 0), 3) AS SaldoFinal,
            ROUND(COALESCE(t6.Total, 0), 3) SaldoReal,
            -- ,t8.Total AS SumaSaldoReal -- Para sumar con el total de Saldos Finales
            ROUND(COALESCE((COALESCE(t8.Total, 0) - (COALESCE(t9.Total, 0) + COALESCE(t4.Total, 0) - COALESCE(t5.Total, 0))), 0), 3) AS Merma,
            t2.codgas,
            t2.codprd
        FROM
            [SG12].[dbo].Gasolineras t1
            
            LEFT JOIN (
                SELECT 
                    fch,
                    codgas, 
                    codprd, 
                    Total
                FROM (
                    SELECT 
                        fch,
                        codgas, 
                        codprd, 
                        SUM(can) AS Total,
                        ROW_NUMBER() OVER (PARTITION BY codprd, codgas ORDER BY fch ASC) AS rn
                    FROM [SG12].[dbo].[StockReal]
                    WHERE fch BETWEEN (@from - 1) AND @until
                      AND codprd IN (179, 180, 181, 192, 193) 
                      AND nrotur >= 40
                    GROUP BY fch, codgas, codprd
                ) AS RankedResults
                WHERE rn = 1
            ) t2 ON t1.cod = t2.codgas
            LEFT JOIN [SG12].[dbo].Productos t3 ON t2.codprd = t3.cod
            
            LEFT JOIN (
                SELECT codgas, codprd, SUM(can) Total 
                FROM [SG12].[dbo].[Movimientos] 
                WHERE fch BETWEEN @from AND @until 
                AND codprd IN (179, 180, 181, 192, 193) 
                AND can > 0 
                GROUP BY codgas, codprd
            ) t4 ON t1.cod = t4.codgas AND t2.codprd = t4.codprd
            
            LEFT JOIN (
                SELECT t2.codgas, t1.codprd, ROUND(SUM(t1.canven), 3) AS Total 
                FROM [SG12].[dbo].[Ventas] t1 
                LEFT JOIN [SG12].[dbo].[Islas] t2 ON t1.codisl = t2.cod 
                WHERE t1.fch BETWEEN @from AND @until 
                AND t1.codprd IN (179, 180, 181, 192, 193) 
                GROUP BY codgas, codprd
            ) t5 ON t1.cod = t5.codgas AND t2.codprd = t5.codprd
            
            LEFT JOIN (
                SELECT 
					codgas, 
					codprd, 
					Total
				FROM (
					SELECT 
						codgas, 
						codprd, 
						SUM(can) AS Total,
						ROW_NUMBER() OVER (PARTITION BY codprd, codgas ORDER BY fch DESC) AS rn
					FROM [SG12].[dbo].[StockReal]
					WHERE fch BETWEEN @from AND @until
					  AND codprd IN (179, 180, 181, 192, 193) 
					  AND nrotur >= 40
					GROUP BY codgas, codprd, fch
				) AS RankedResults
				WHERE rn = 1
            ) t6 ON t1.cod = t6.codgas AND t2.codprd = t6.codprd
            
            LEFT JOIN (
                SELECT codgas, codprd, SUM(can) Total 
                FROM [SG12].[dbo].[StockReal] 
                WHERE fch = (@until-1) 
                AND codprd IN (179, 180, 181, 192, 193) 
                AND nrotur >= 40 
                GROUP BY codgas, codprd
            ) t7 ON t1.cod = t7.codgas AND t2.codprd = t7.codprd
            
            LEFT JOIN ( -- Aqui obtenemos la suma del SaldoReal del periodo dado
                SELECT codgas, codprd, SUM(can) Total
                FROM [SG12].[dbo].[StockReal] 
                WHERE fch BETWEEN @from AND @until
                AND codprd IN (179, 180, 181, 192, 193) 
                AND nrotur >= 40 
                GROUP BY codgas, codprd
            ) t8 ON t1.cod = t8.codgas AND t2.codprd = t8.codprd
        
            LEFT JOIN ( -- Aqui obtenemos la suma de la cantidad inicial del  periodo por gasolinera y producto
                SELECT codgas, codprd, SUM(can) Total 
                FROM [SG12].[dbo].[StockReal] 
                WHERE fch BETWEEN (@from - 1) AND (@until - 1)
                AND codprd IN (179, 180, 181, 192, 193) 
                AND nrotur >= 40 
                GROUP BY codgas, codprd
            ) t9 ON t1.cod = t9.codgas AND t2.codprd = t9.codprd
        
            LEFT JOIN ( -- Las compras del ultimo día
                SELECT codgas, codprd, SUM(can) Total 
                FROM [SG12].[dbo].[Movimientos] 
                WHERE fch = @until 
                AND codprd IN (179, 180, 181, 192, 193) 
                AND can > 0 
                GROUP BY codgas, codprd
            ) t10 ON t1.cod = t10.codgas AND t2.codprd = t10.codprd

            LEFT JOIN (
                SELECT t2.codgas, t1.codprd, ROUND(SUM(t1.canven), 3) AS Total 
                FROM [SG12].[dbo].[Ventas] t1 
                LEFT JOIN [SG12].[dbo].[Islas] t2 ON t1.codisl = t2.cod 
                WHERE t1.fch = @until 
                AND t1.codprd IN (179, 180, 181, 192, 193) 
                GROUP BY codgas, codprd
            ) t11 ON t1.cod = t11.codgas AND t2.codprd = t11.codprd
        WHERE
            t1.cod NOT IN (0, 4, 20)
        ORDER BY 
            Estacion;
        ";

        return $this->sql->select($query, []);
    }

    function get_details($from, $until, $codgas, $codprd) {
        $query = "
        DECLARE @from INT = {$from};
        DECLARE @until INT = {$until};
        DECLARE @codgas INT = {$codgas}; 
        DECLARE @codprd INT = {$codprd};

        WITH VentasTotales AS (
            SELECT
                fch,
                ROUND(SUM(t1.canven), 3) AS Total
            FROM
                [SG12].[dbo].[Ventas] t1
                LEFT JOIN [SG12].[dbo].[Islas] t2 ON t1.codisl = t2.cod 
            WHERE
                t2.codgas = @codgas AND
                fch BETWEEN @from AND @until AND
                codprd = @codprd
            GROUP BY fch
        )
        SELECT 
            dbo.IntToDate(t1.fch) Fecha,
            ROUND(COALESCE(
                LAG(t1.can) OVER (
                    PARTITION BY t1.codgas, t1.codprd 
                    ORDER BY t1.fch, t1.nrotur
                ), 
                0
            ), 3) AS SdoInicial,
            ISNULL(t3.Total, 0) AS Compras,
            t2.Total AS Ventas,
            -- Ajustes
            ROUND((COALESCE(
                LAG(t1.can) OVER (
                    PARTITION BY t1.codgas, t1.codprd 
                    ORDER BY t1.fch, t1.nrotur
                ), 
                0
            ) + ISNULL(t3.Total, 0) - t2.Total), 3) AS Saldo_Final,
            ROUND(t1.can, 3) SaldoReal,
            ROUND((t1.can - (COALESCE(
                LAG(t1.can) OVER (
                    PARTITION BY t1.codgas, t1.codprd 
                    ORDER BY t1.fch, t1.nrotur
                ), 
                0
            ) + ISNULL(t3.Total, 0) - t2.Total)), 3) Merma,
            t4.abr Estacion,
            t5.den Producto
        FROM 
            [SG12].[dbo].[StockReal] t1
            LEFT JOIN VentasTotales t2 ON t1.fch = t2.fch
            LEFT JOIN (SELECT TOP (1000) SUM(can) Total,fch
                      FROM [SG12].[dbo].[Movimientos]
                      Where fch BETWEEN @from AND @until and codgas = @codgas and codprd = @codprd and can > 0
                      GROUP BY fch) t3 on t1.fch = t3.fch
            LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
	        LEFT JOIN [SG12].[dbo].[Productos] t5 ON t1.codprd = t5.cod
        WHERE
            t1.fch BETWEEN (@from-1) AND @until AND
            t1.codgas = @codgas AND
            t1.codprd = @codprd AND
            t1.nrotur >= 40
        ";

        return $this->sql->select($query, []);

    }

    function get_month_sales(){
        $inicio_anio_actual = date('Y-01-01'); // Primer día del año actual
        $dia_anterior = date('Y-d-m', strtotime('-1 day'));
        $query="SELECT
                MONTH(DATEADD(DAY, fch - 1, '19000101')) AS Mes,
                YEAR(DATEADD(DAY, fch - 1, '19000101')) AS Año,
                SUM(canven) AS VentasReales

            FROM [SG12].[dbo].[Ventas] v
            INNER JOIN ISLAS isd ON v.codisl = isd.cod
            INNER JOIN Gasolineras g ON isd.codgas = g.cod
            INNER JOIN Productos T3 ON v.codprd = T3.cod

            WHERE
            fch BETWEEN (DATEDIFF(dd, 0, '$inicio_anio_actual') + 1) 
            AND (DATEDIFF(dd, 0, '$dia_anterior'))
                            AND codprd IN (179, 180, 181, 2, 3, 1, 192, 193)

            GROUP BY
            MONTH(DATEADD(DAY, fch - 1, '19000101')),
            YEAR(DATEADD(DAY, fch - 1, '19000101'))

            ORDER BY Año, Mes;";

        return $this->sql->select($query, []);
    }

    function GetSalesIndicator($from,$until,$zona,$total){
        $zona_query = "";
        if (isset($zona) && $zona != 0) {
            $zona_query = "AND E.estructura = '{$zona}'";
        }
        $dateFrom = DateTime::createFromFormat('Y-m-d', $from);
        $dateUntil = DateTime::createFromFormat('Y-m-d', $until);
        $fromInt = dateToInt($from);
        $untilInt = dateToInt($until);
        // Normalizar las fechas al primer día del mes
        $dateFrom->modify('first day of this month');
        $dateUntil->modify('first day of this month');

        $months = [];
        $currentDate = clone $dateFrom;

        // Generar dinámicamente las columnas del PIVOT
        while ($currentDate <= $dateUntil) {
            $year = $currentDate->format('Y');
            $monthNumber = (int)$currentDate->format('m');
            $months[] = "{$year}_{$monthNumber}";
            $currentDate->modify('+1 month');
        }
        // Convertir el array en una cadena separada por comas
        $pivotColumns = implode(",\n", array_map(function($col) {
            return "MAX(CASE WHEN p.date_concat = '$col' THEN p.VentasCantidad END) AS Ventas_$col,
                    MAX(CASE WHEN p.date_concat = '$col' THEN p.ProyeccionMensual END) AS Proyeccion_$col,
                    MAX(CASE WHEN p.date_concat = '$col' THEN p.presupuesto_mensual END) AS Presupuesto_$col,
                    MAX(CASE WHEN p.date_concat = '$col' THEN p.CumplimientoPresupuesto END) AS Cumplimiento_$col,
                    MAX(CASE WHEN p.date_concat = '$col' THEN p.DiferenciaPresupuesto END) AS Diferencia_$col";
        }, $months));
        $producto_total = " p.producto,p.CodProducto,";
        $group_total = " p.codigo, p.Estacion, p.producto, p.CodProducto";
        if($total == 'true'){
            $producto_total = "  
            CASE WHEN GROUPING(p.producto) = 1 THEN 'TOTAL' ELSE p.producto END AS producto,
            CASE WHEN GROUPING(p.CodProducto) = 1 THEN NULL ELSE p.CodProducto END AS CodProducto,";
            $group_total = " GROUPING SETS (
                                (p.codigo, p.Estacion, p.producto, p.CodProducto), -- Agrupación normal por producto
                                (p.codigo, p.Estacion) -- Total por estación
                            )";

            $pivotColumns = implode(",\n", array_map(function($col) {
                return "SUM(CASE WHEN p.date_concat = '$col' THEN p.VentasCantidad END) AS Ventas_$col,
                        SUM(CASE WHEN p.date_concat = '$col' THEN p.ProyeccionMensual END) AS Proyeccion_$col,
                        SUM(CASE WHEN p.date_concat = '$col' THEN p.presupuesto_mensual END) AS Presupuesto_$col,
                        AVG(CASE WHEN p.date_concat = '$col' THEN p.CumplimientoPresupuesto END) AS Cumplimiento_$col,
                        SUM(CASE WHEN p.date_concat = '$col' THEN p.DiferenciaPresupuesto END) AS Diferencia_$col";
            }, $months));

        }

        
        $query = "
            WITH DatosMensual AS (
                SELECT
                    DATEPART(YEAR, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) AS Año,
                    DATEPART(MONTH, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) AS Mes,
                    isd.codgas AS codigo,
                    g.abr AS Estacion,
                    T3.den AS producto,
                    v.codprd AS CodProducto,
                    SUM(v.canven) AS VentasCantidad
                FROM [SG12].[dbo].[Ventas] v
                INNER JOIN [SG12].[dbo].ISLAS isd ON v.codisl = isd.cod 
                INNER JOIN [SG12].[dbo].Gasolineras g ON codgas = g.cod 
                INNER JOIN TG.dbo.Estaciones E ON isd.codgas = E.Codigo
                INNER JOIN [SG12].[dbo].Productos T3 ON V.codprd = T3.cod
                WHERE
                    fch BETWEEN $fromInt AND $untilInt 
                    AND codprd IN (179, 180, 181, 1, 2, 3, 192, 193) $zona_query
                GROUP BY
                    DATEPART(YEAR, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)),
                    DATEPART(MONTH, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)),
                    g.abr,
                    T3.den,
                    codgas,
                    codprd
            ),
            BasePivot AS (
                SELECT 
                    CONCAT(dm.Año, '_', dm.Mes) AS date_concat,
                    dm.*,
                    CASE 
                        WHEN dm.Año = YEAR(GETDATE()) AND dm.Mes = MONTH(GETDATE()) 
                            THEN (dm.VentasCantidad / DAY(GETDATE())) * DAY(EOMONTH(GETDATE()))
                        ELSE dm.VentasCantidad
                    END AS ProyeccionMensual,
                    t2.budget_monthy AS presupuesto_mensual,
                    CASE
                        WHEN t2.budget_monthy IS NULL OR t2.budget_monthy = 0 THEN NULL
                        ELSE CASE 
                            WHEN dm.Año = YEAR(GETDATE()) AND dm.Mes = MONTH(GETDATE()) 
                                THEN (((dm.VentasCantidad / DAY(GETDATE())) * DAY(EOMONTH(GETDATE()))) / t2.budget_monthy) * 100
                            ELSE (dm.VentasCantidad / t2.budget_monthy) * 100
                            END
                    END AS CumplimientoPresupuesto,
                    CASE 
                        WHEN dm.Año = YEAR(GETDATE()) AND dm.Mes = MONTH(GETDATE()) 
                            THEN ((dm.VentasCantidad / DAY(GETDATE())) * DAY(EOMONTH(GETDATE()))) - t2.budget_monthy
                        ELSE dm.VentasCantidad - t2.budget_monthy
                    END AS DiferenciaPresupuesto
                FROM DatosMensual dm
                LEFT JOIN TGV2.dbo.Budget t2 
                    ON dm.Mes = t2.[month] 
                    AND dm.Año = t2.[year] 
                    AND dm.codigo = t2.codgas 
                    AND dm.CodProducto = t2.codprd
            ),
            PivotFinal AS (
                SELECT 
                    Estacion,
                    codigo,
                    CodProducto,
                    producto,
                    date_concat,
                    VentasCantidad,
                    ProyeccionMensual,
                    presupuesto_mensual,
                    CumplimientoPresupuesto,
                    DiferenciaPresupuesto
                FROM BasePivot
            )
            SELECT 
                p.codigo,
                p.Estacion,
                $producto_total 
                $pivotColumns
            FROM PivotFinal p
            GROUP BY $group_total
            ORDER BY p.Estacion, p.producto;";

           
            return $this->sql->select($query, []);
    }

    function getSalesTypePayment($from, $until, $zona,$total) {
        $fromstring = date('Y-d-m', strtotime($from));
        $untilstring = date('Y-d-m', strtotime($until));

        $zona_query = "";
        if (isset($zona) && $zona != 0) {
            $zona_query = "AND E.estructura = '{$zona}'";
        }
        $dateFrom = DateTime::createFromFormat('Y-m-d', $from);
        $dateUntil = DateTime::createFromFormat('Y-m-d', $until);
        // Normalizar las fechas al primer día del mes
        $dateFrom->modify('first day of this month');
        $dateUntil->modify('first day of this month');
        $months = [];
        $currentDate = clone $dateFrom;

        // Generar dinámicamente las columnas del PIVOT
        while ($currentDate <= $dateUntil) {

            $year = $currentDate->format('Y');
            $monthNumber = (int)$currentDate->format('m');
            $months[] = "[".$year ."_". $monthNumber ."]";
            $currentDate->modify('+1 month');
        }
        // Convertir el array en una cadena separada por comas
        $pivotColumns = implode(', ', $months);
        $pivotColumns_final = implode(' , ', array_map(function($col) {
            return "ISNULL($col, 0) as $col";
        }, $months));
        // Calcular la suma total dinámicamente
        $totalSum = implode(' + ', array_map(function($col) {
            return "ISNULL($col, 0)";
        }, $months));

        $group_total=' empresa, Zona, Estacion, Descripcion, YEAR(Fecha), MONTH(Fecha)';
        $Descripcion = 'Descripcion,';
        if($total == '1'){

            $group_total = " GROUPING SETS (
                                (empresa, Zona, Estacion, Descripcion, YEAR(Fecha), MONTH(Fecha)), -- Agrupación normal por producto
                                (empresa, Zona, Estacion, YEAR(Fecha), MONTH(Fecha)) -- Total por estación
                            )";
            $Descripcion ='CASE
                WHEN Descripcion IS NULL THEN \'Total Estación\'
                ELSE Descripcion
            END AS Descripcion,';

        }

    
    

        $query = "
                DECLARE @fecha_inicial_int INT = DATEDIFF(dd, 0, '$fromstring') + 1;
                DECLARE @fecha_fin_int INT = DATEDIFF(dd, 0, '$untilstring') + 1;

                WITH ValuesTable AS (
                    SELECT 
                        v.cod AS CodFormaPago,
                        E.Codigo AS CodGas,
                        CONVERT(DATE, DATEADD(DAY, -1, i.fch)) AS Fecha,
                        E.Nombre AS Estacion,
                        E.estructura AS Zona,
                        v.den AS Descripcion,
                        SUM(i.can) AS Cantidad,
                        SUM(i.mto) AS Monto,
                        emp.den AS Empresa
                    FROM SG12.dbo.Valores v
                    INNER JOIN SG12.dbo.Ingresos i ON v.cod = i.codval
                    INNER JOIN TG.dbo.Estaciones E ON i.codgas = E.Codigo
                    INNER JOIN SG12.dbo.Gasolineras g ON i.codgas = g.cod
                    INNER JOIN SG12.dbo.Empresas emp ON g.codemp = emp.cod
                    WHERE i.fch BETWEEN @fecha_inicial_int AND @fecha_fin_int $zona_query
                    GROUP BY 
                        CONVERT(DATE, DATEADD(DAY, -1, i.fch)), 
                        v.cod, E.Codigo, E.Nombre, v.den, E.estructura, emp.den
                )

                SELECT 
                    Empresa,
                    Zona,
                    Estacion,
                    $Descripcion
                    ($totalSum) AS Total,
                    $pivotColumns_final
                FROM (
                    SELECT 
                        Empresa,
                        Zona,
                        Estacion,
                        Descripcion,
                        SUM(Monto) AS sum_monto,
                        CONCAT(YEAR(Fecha), '_', MONTH(Fecha)) AS AñoMes
                    FROM ValuesTable
                  GROUP BY  $group_total
                ) AS src
                PIVOT (
                    SUM(sum_monto)
                    FOR AñoMes IN ($pivotColumns)
                ) ptv
                ORDER BY Estacion, Descripcion;
            ";

        return $this->sql->select($query, []);
    }
    function getSalesTypePaymentTotal($from, $until, $zona) {

        $fromstring = date('Y-d-m', strtotime($from));
        $untilstring = date('Y-d-m', strtotime($until));

        $zona_query = "";
        if (isset($zona) && $zona != 0) {
            $zona_query = "AND E.estructura = '{$zona}'";
        }
        $dateFrom = DateTime::createFromFormat('Y-m-d', $from);
        $dateUntil = DateTime::createFromFormat('Y-m-d', $until);
        // Normalizar las fechas al primer día del mes
        $dateFrom->modify('first day of this month');
        $dateUntil->modify('first day of this month');
        $months = [];
        $currentDate = clone $dateFrom;

        // Generar dinámicamente las columnas del PIVOT
        while ($currentDate <= $dateUntil) {

            $year = $currentDate->format('Y');
            $monthNumber = (int)$currentDate->format('m');
            $monthName = ucfirst(strftime('%B', $currentDate->getTimestamp())); // Nombre del mes en español
            $months[] = "[".$year ."_". $monthNumber ."]";
            $currentDate->modify('+1 month');
        }
        // Convertir el array en una cadena separada por comas
        $pivotColumns = implode(', ', $months);

        $totalSum = implode(' + ', array_map(function($col) {
            return "ISNULL($col, 0)";
        }, $months));

        $query ="
                    DECLARE @fecha_inicial_int INT = DATEDIFF(dd, 0, '". $fromstring."') + 1;
                    DECLARE @fecha_fin_int INT = DATEDIFF(dd, 0, '". $untilstring."') + 1;
                    declare  @cod_gas INT = NULL;

                    WITH ValuesTable as ( 
                        SELECT v.cod AS CodFormaPago,
                           E.Codigo AS CodGas,
                            CONVERT(VARCHAR, CONVERT(SMALLDATETIME, i.fch - 1, 103), 103) AS Fecha,
                            E.Nombre AS Estacion,
							 E.estructura as Zona,
                            /* i.nrotur AS Turno,*/
                            v.den AS Descripcion,
                            SUM(i.can) AS Cantidad,
                            SUM(i.mto) AS Monto
                        FROM SG12.dbo.Valores v
                            INNER JOIN SG12.dbo.Ingresos i ON v.cod = i.codval
                            INNER JOIN TG.dbo.Estaciones E ON i.codgas = E.Codigo
                        WHERE i.fch
                            BETWEEN @fecha_inicial_int AND @fecha_fin_int $zona_query
                             AND E.Codigo = CASE
                                            WHEN @cod_gas IS NOT NULL AND @cod_gas <> 0 THEN
                                                @cod_gas
                                            ELSE
                                                E.Codigo
                                        END
                            /* AND v.cod IN ( 5, 6, 192, 53 )*/
                        GROUP BY CONVERT(VARCHAR, CONVERT(SMALLDATETIME, i.fch - 1, 103), 103), v.cod, E.Codigo, E.Nombre, v.den,E.estructura
                    ),
                    BasePivot AS (
                    SELECT
                            Zona,
                            Estacion,
                            CASE
                                WHEN GROUPING(Descripcion) = 1 THEN 'Total Estación'
                                ELSE Descripcion
                            END AS Descripcion,
                            --Descripcion,
                            ISNULL(SUM(Monto),0) as sum_monto,
                            concat(DATEPART(Year, Fecha) ,'_',DATEPART(MONTH, Fecha)) as [AñoMes]
                        FROM ValuesTable
                        GROUP BY GROUPING SETS(
                            (Zona, Estacion, Descripcion, DATEPART(Year, Fecha), DATEPART(MONTH, Fecha)),
                            (Zona, Estacion, DATEPART(Year, Fecha), DATEPART(MONTH, Fecha))
                        )
                    )
                    select *,
                            ($totalSum) AS Total
                    from BasePivot
                    PIVOT (
                        sum(sum_monto)
                        FOR AñoMes IN ($pivotColumns)
                    )ptv
                    ORDER BY Estacion, 
                            CASE 
                                WHEN Descripcion = 'Total Estación' THEN 2
                                ELSE 1
                            END,
                            Descripcion";

        return $this->sql->select($query, []);
    }


    function getMounthGruopPayment($from, $until, $grupo, $total) {
        $fromstring = date('Y-d-m', strtotime($from));
        $untilstring = date('Y-d-m', strtotime($until));

        $dateFrom = DateTime::createFromFormat('Y-m-d', $from);
        $dateUntil = DateTime::createFromFormat('Y-m-d', $until);
        // Normalizar las fechas al primer día del mes
        $dateFrom->modify('first day of this month');
        $dateUntil->modify('first day of this month');
        $months = [];
        $currentDate = clone $dateFrom;

        // Generar dinámicamente las columnas del PIVO
        while ($currentDate <= $dateUntil) {

            $year = $currentDate->format('Y');
            $monthNumber = (int)$currentDate->format('m');
            $months[] = "[".$year ."_". $monthNumber ."]";
            $currentDate->modify('+1 month');
        }
        // Convertir el array en una cadena separada por comas
        $pivotColumns = implode(', ', $months);
        $pivotColumns_final = implode(' , ', array_map(function($col) {
            return "ISNULL($col, 0) as $col";
        }, $months));
        // Calcular la suma total dinámicamente
        $totalSum = implode(' + ', array_map(function($col) {
            return "ISNULL($col, 0)";
        }, $months));

        $group_total='  Grupo,empresa, Descripcion,MedioPago, DATEPART(Year, Fecha), DATEPART(MONTH, Fecha)';
        $Descripcion = 'Descripcion,';
        if($total == '1'){

            $group_total = " GROUPING SETS (
                                 (Grupo,empresa, Descripcion, DATEPART(Year, Fecha), DATEPART(MONTH, Fecha)),
                                (Grupo,empresa, DATEPART(Year, Fecha), DATEPART(MONTH, Fecha))
                            )";
            $Descripcion ='CASE
                WHEN Descripcion IS NULL THEN \'Total Estación\'
                ELSE Descripcion
            END AS Descripcion,';

        }

        $grupo_string ='';

        if ($grupo != '0'){
            $grupo_string ="and E.grupo = '{$grupo}' ";
        }
        $query = "
                DECLARE @fecha_inicial_int INT = DATEDIFF(dd, 0, '$fromstring') + 1;
                DECLARE @fecha_fin_int INT = DATEDIFF(dd, 0, '$untilstring') + 1;

                WITH ValuesTable AS (
                    SELECT 
                        v.cod AS CodFormaPago,
                        CONVERT(DATE, DATEADD(DAY, -1, i.fch)) AS Fecha,
                        v.den AS Descripcion,
                        SUM(i.can) AS Cantidad,
                        SUM(i.mto) AS Monto,
                        emp.den AS Empresa,
						E.grupo as Grupo,
                       CASE 
							WHEN v.den IN (' Efectivo MN', ' DOLARES', ' Morralla MN', 'Transferencias') THEN 'EFECTIVO'
							WHEN v.den IN ('Clientes Crédito') THEN 'CREDITO'
							WHEN v.den IN ('Clientes Débito') THEN 'DEBITO'
							WHEN v.den IN (' SMARTBT - MANUAL Bancarias',' SMARTBT - Bancarias',' Tarjetas Bancomer', ' SMARTBT - American Express', ' Tarjetas Santander', ' Tarjetas Banorte', ' Tarjetas Afirme', 'SMARTBT - MANUAL Bancarias', 'INTERL - Tarjeta de Crédito', 'INTERL - Tarjeta de Débito', 'INTERLOGIC Manual','HTI - Tarjeta de Crédito','HTI - Tarjeta de Débito',' Tarjetas Scotiabank',' Tarjetas American Express') THEN 'TARJETAS'
							WHEN v.den IN (' Tarjeta EfectiCard',' Tarjetas Sodexo (Pluxee)',' SMARTBT - SODEXO WIZEO',' Vale Edenred',' Vale Sodexo','Mobil FleetPro', ' Tarjeta Inburgas', ' Tarjeta TicketCar', ' Vale Efectivale', ' SMARTBT - EFECTIVALE', 'Ultra Gas', 'Tarjetas Sodexo (Pluxee)') THEN 'VALERAS'
						    ELSE 'OTRO'
                        END AS MedioPago
                    FROM SG12.dbo.Valores v
                    INNER JOIN SG12.dbo.Ingresos i ON v.cod = i.codval
                    INNER JOIN TG.dbo.Estaciones E ON i.codgas = E.Codigo
                    INNER JOIN SG12.dbo.Empresas emp ON E.codemp = emp.cod
                    WHERE i.fch BETWEEN @fecha_inicial_int AND @fecha_fin_int 
                    $grupo_string 
                    GROUP BY 
                        CONVERT(DATE, DATEADD(DAY, -1, i.fch)), 
                        v.cod,v.den,emp.den,E.grupo
                )

                SELECT 
                     Grupo,
                    Empresa,
                    MedioPago,
                    $Descripcion
                    ($totalSum) AS Total,
                    $pivotColumns_final
                FROM (
                    SELECT 
                        Grupo,
                        Empresa,
                        MedioPago,
                        Descripcion,
                        SUM(Monto) AS sum_monto,
                        CONCAT(YEAR(Fecha), '_', MONTH(Fecha)) AS AñoMes
                    FROM ValuesTable
                  GROUP BY  $group_total
                ) AS src
                PIVOT (
                    SUM(sum_monto)
                    FOR AñoMes IN ($pivotColumns)
                ) ptv
                ORDER BY Grupo, Descripcion;
            ";

        return $this->sql->select($query, []);
    }
    function getSalesMonthTotal($from, $until, $zona,$total) {
        $fromstring = date('Y-d-m', strtotime($from));
        $untilstring = date('Y-d-m', strtotime($until));

        $zona_query = "";
        if (isset($zona) && $zona != 0) {
            $zona_query = "AND E.estructura = '{$zona}'";
        }
        $dateFrom = DateTime::createFromFormat('Y-m-d', $from);
        $dateUntil = DateTime::createFromFormat('Y-m-d', $until);

        // Verificar si las fechas son válidas
        if (!$dateFrom || !$dateUntil) {
            die("Error: Formato de fecha incorrecto. Asegúrate de enviar las fechas en formato 'd-m-Y'.");
        }

        // Normalizar las fechas al primer día del mes
        $dateFrom->modify('first day of this month');
        $dateUntil->modify('first day of this month');

        $months = [];
        $turns = [11,21,31,41];
        $currentDate = clone $dateFrom;

        // Generar dinámicamente las columnas del PIVOT
        while ($currentDate <= $dateUntil) {
            foreach ($turns as $key => $turn) {
                $year = $currentDate->format('Y');
                $monthNumber = (int)$currentDate->format('m');
                $monthName = ucfirst(strftime('%B', $currentDate->getTimestamp())); // Nombre del mes en español
                $months[] = "[".$year ."_". $monthNumber ."_".$turn."]";
            }
            $currentDate->modify('+1 month');
        }
        // Convertir el array en una cadena separada por comas
        $pivotColumns = implode(', ', $months);
 

        $totalSum = implode(' + ', array_map(function($col) {
            return "ISNULL($col, 0)";
        }, $months));
        if($total == 1){

            $query_total = "UNION ALL
    
                        -- Agregamos los totales por estación
                        SELECT  
                            DATEPART(Year, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as Año,
                            DATEPART(MONTH, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as Mes,
                            nrotur AS Turno,
                            'Total Estación' AS Producto,
                            isd.codgas AS CodGasolinera,
                            g.abr AS Estacion,
                            NULL AS CodProducto,
                            SUM(canven) AS VentasReales
                        FROM [SG12].[dbo].[Ventas] v
                        INNER JOIN ISLAS isd ON v.codisl = isd.cod 
                        INNER JOIN Gasolineras g ON codgas = g.cod 
                        INNER JOIN Productos T3 ON V.codprd = T3.cod
                        INNER JOIN TG.dbo.Estaciones E ON g.cod = E.Codigo
                        WHERE 
                            fch BETWEEN @fecha_inicial_int AND @fecha_fin_int 
                            AND codprd IN(179, 180, 181, 2, 3, 1, 192, 193) 
                             $zona_query
                        GROUP BY
                            DATEPART(Year, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)),
                            DATEPART(MONTH, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)),
                            nrotur,
                            isd.codgas,
                            g.abr";
        }else{
            $query_total = "";
        }

        $query ="
                    DECLARE @fecha_inicial_int INT = DATEDIFF(dd, 0, '". $fromstring."') + 1;
                    DECLARE @fecha_fin_int INT = DATEDIFF(dd, 0, '". $untilstring."') + 1;
                    declare  @cod_gas INT = NULL;

                   WITH ValuesTable AS ( 
                    SELECT  
                        DATEPART(Year, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as Año,
                        DATEPART(MONTH, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as Mes,
                        nrotur AS Turno,
                        CASE 
                            WHEN T3.den IS NULL THEN 'Total Estación'
                            ELSE T3.den 
                        END AS Producto,
                        isd.codgas AS CodGasolinera,
                        g.abr AS Estacion,
                        [codprd] AS CodProducto,
                        SUM(canven) AS VentasReales
                    FROM [SG12].[dbo].[Ventas] v
                    INNER JOIN ISLAS isd ON v.codisl = isd.cod 
                    INNER JOIN Gasolineras g ON codgas = g.cod 
                    INNER JOIN Productos T3 ON V.codprd = T3.cod
                    INNER JOIN TG.dbo.Estaciones E ON g.cod = E.Codigo
                    WHERE 
                        fch BETWEEN @fecha_inicial_int AND @fecha_fin_int 
                        AND codprd IN(179, 180, 181, 2, 3, 1, 192, 193) 
                         $zona_query
                    GROUP BY
                        DATEPART(Year, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)),
                        DATEPART(MONTH, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)),
                        nrotur,
                        T3.den,
                        isd.codgas,
                        g.abr,
                        [codprd]
                    $query_total
                )
                SELECT * 
                FROM (
                    SELECT 
                        Producto, 
                        CodGasolinera, 
                        Estacion, 
                        VentasReales,
                        CONCAT(Año,'_',Mes,'_',Turno) as turno_mes
                    FROM ValuesTable
                ) AS SourceTable
                PIVOT (
                    SUM(VentasReales)
                    FOR turno_mes IN ($pivotColumns)
                ) AS PivotTable
                WHERE 
                    COALESCE($pivotColumns) IS NOT NULL
                ORDER BY 
                    CodGasolinera,
                    CASE 
                        WHEN Producto = 'Total Estación' THEN 1
                        ELSE 0
                    END;";

        return $this->sql->select($query, []);
    }
    function GetSalesMonthBase($from, $until, $zona,){
        $fromint = dateToInt($from);
        $untilint = dateToInt($until);
        $query = "              
                   WITH ValuesTable AS ( 
                    SELECT  
                        DATEPART(Year, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as Año,
                        DATEPART(MONTH, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as Mes,
                        nrotur AS Turno,
                        T3.den as Producto,
                        isd.codgas AS CodGasolinera,
                        g.abr AS Estacion,
                        [codprd] AS CodProducto,
                       round(SUM(v.canven),2)  AS VentasReales,
						round (sum(v.mtoven),2) as MontoVendido,
						g.codemp as CodEmp,
						t2.den,
						E.estructura
                    FROM [SG12].[dbo].[Ventas] v
                    INNER JOIN ISLAS isd ON v.codisl = isd.cod 
                    INNER JOIN Gasolineras g ON codgas = g.cod 
                    INNER JOIN Productos T3 ON V.codprd = T3.cod
                    INNER JOIN TG.dbo.Estaciones E ON g.cod = E.Codigo
					LEFT JOIN SG12.dbo.Empresas t2 on g.codemp = t2.cod
                    WHERE 
                        fch BETWEEN $fromint AND $untilint
                        AND codprd IN(179, 180, 181, 2, 3, 1, 192, 193) 
                    GROUP BY
                        DATEPART(Year, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)),
                        DATEPART(MONTH, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)),
                        nrotur,
                        T3.den,
                        isd.codgas,
                        g.abr,
                        [codprd],
						g.codemp,
						t2.den,
						E.estructura
                )
                select * from ValuesTable";
        return $this->sql->select($query, []);
    }
    function GetSalesDayTurnBase($from, $until, $zona,){
        $fromint = dateToInt($from);
        $untilint = dateToInt($until);
        $query = "              
                   WITH ValuesTable AS ( 
                    SELECT  
                        --CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103) AS 'Fecha', 
                        CONVERT(Date, CONVERT(SMALLDATETIME, fch - 1, 103), 103) AS 'Fecha',
                        Year(CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as 'year',
                      month (CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as 'mounth',
                        datename(day, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as 'day',
                        isd.codgas AS CodGasolinera,
						v.codprd CodProduct,
                        case
						when T3.den =' Magna ' then 'T-Maxima Regular'
						when T3.den ='   T-Maxima Regular' then 'T-Maxima Regular'
						when T3.den =' Gasolina Regular Menor a 91 Octanos' then 'T-Maxima Regular'
						when T3.den =' Premium' then 'T-Super Premium'
						when T3.den ='   T-Super Premium' then 'T-Super Premium'
						when T3.den =' Gasolina Premium Mayor o Igual a 91 Octanos' then 'T-Super Premium'
						when T3.den =' Diesel ' then 'Diesel Automotriz'
						when T3.den ='   Diesel Automotriz' then 'Diesel Automotriz'
						else T3.den
						end
						as 'product',
                        LEFT(CAST(v.nrotur AS VARCHAR), 1)  AS turn,
                        sum(v.canven) AS VentasReales,
                        v.fch,
						g.abr as estation_name,
						E.estructura as [zone],
						g.codemp as CodEmp,
						t2.den as [empresa]
                    FROM [SG12].[dbo].[Ventas] v
                    INNER JOIN ISLAS isd on v.codisl = isd.cod 
                    INNER JOIN Gasolineras g ON codgas = g.cod 
                    INNER JOIN Productos T3 ON V.codprd = T3.cod
					INNER JOIN TG.dbo.Estaciones E ON g.cod = E.Codigo
					LEFT JOIN SG12.dbo.Empresas t2 on g.codemp = t2.cod
					WHERE 
                        fch BETWEEN $fromint AND $untilint
                         codprd IN (179, 180, 181, 2, 3, 1, 192, 193)
                        AND nrotur IN (11, 21, 31, 41)
                    GROUP BY
                        fch,
                        isd.codgas,
                        T3.den,
                        nrotur,
                        codprd,g.abr,E.estructura,g.codemp,t2.den
                )
                select * from ValuesTable";
        return $this->sql->select($query, []);
    }

    function getLubricants($from, $until){

        $fromDate = DateTime::createFromFormat('Y-m-d', $from);
        $untilDate = DateTime::createFromFormat('Y-m-d', $until);
        $fromInt = dateToInt($from);
        $untilInt = dateToInt($until);
        $currentDate = clone $fromDate;
        
        $weeks = [];
        while ($currentDate <= $untilDate) {
            $weekNumber = $currentDate->format('W'); // Número de semana
            $weekNumber = (int)$weekNumber; // Eliminar ceros a la izquierda

            $year = $currentDate->format('Y'); // Año, para manejar semanas cruzadas de años
            if ($weekNumber == 1 && $currentDate->format('m') == '12') {
                $year = $currentDate->format('Y') + 1;
            }
            $weeks[] = ['week' => $weekNumber, 'year' => $year];
            $currentDate->modify('next monday');
        }

        // Generar columnas dinámicas para el PIVOT
        $columns = [];
        foreach ($weeks as $week) {
            $columns[] = "[{$week['year']}_{$week['week']}_monto]";
            $columns[] = "[{$week['year']}_{$week['week']}_cantidad]";
        }
        $columnsList = implode(',', $columns);

        // Construir consulta SQL dinámica
        $query = "
        WITH DatosSemanal AS (
               SELECT
                CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103) AS Fecha,
                DATEPART(ISO_WEEK, (CONVERT(SMALLDATETIME, fch - 1, 103))) AS Semana,
                CASE 
                    WHEN DATEPART(ISO_WEEK, (CONVERT(SMALLDATETIME, fch - 1, 103))) = 1 AND MONTH(CONVERT(SMALLDATETIME, fch - 1, 103)) = 12 THEN DATEPART(YEAR, (CONVERT(SMALLDATETIME, fch - 1, 103))) + 1
                    WHEN DATEPART(ISO_WEEK, (CONVERT(SMALLDATETIME, fch - 1, 103))) >= 52 AND MONTH(CONVERT(SMALLDATETIME, fch - 1, 103)) = 1 THEN DATEPART(YEAR, (CONVERT(SMALLDATETIME, fch - 1, 103))) - 1
                    ELSE DATEPART(YEAR, (CONVERT(SMALLDATETIME, fch - 1, 103)))
                END AS [year],
                isd.codgas AS codigo,
                g.abr AS Estacion,
                T3.den AS producto,
                [codprd] AS CodProducto,
                SUM(canven) AS VentasCantidad,
                SUM(mtoven) AS MontoVentas
            FROM [SG12].[dbo].[Ventas] v
            INNER JOIN [SG12].[dbo].ISLAS isd ON v.codisl = isd.cod 
            INNER JOIN [SG12].[dbo].Gasolineras g ON codgas = g.cod 
            INNER JOIN [SG12].[dbo].Productos T3 ON V.codprd = T3.cod
            WHERE 
                fch BETWEEN $fromInt
                AND $untilInt
                AND codprd NOT IN (179, 180, 181, 1, 2, 3, 192, 193)
            GROUP BY
                g.abr,
                T3.den,
                fch,
                codgas,
                codprd
        ),
        DatosParaPivot AS (
            SELECT 
                 CONCAT([year],'_',[Semana]) as [date],
                codigo,
                Estacion,
                producto,
                SUM(MontoVentas) AS VentasTotalesMonto,
                SUM(VentasCantidad) AS VentasTotalesCantidad
            FROM DatosSemanal
        GROUP BY [year], Semana, codigo, Estacion, producto)
        SELECT 
            codigo,
            Estacion,
            producto,
            {$columnsList}
        FROM (
            SELECT 
                codigo,
                Estacion,
                Producto,
                --CAST(Semana AS VARCHAR) + '_monto' AS TipoSemana,
                CONCAT([date], '_monto') AS TipoSemana,
                VentasTotalesMonto AS Valor
            FROM DatosParaPivot
            UNION ALL
            SELECT 
                codigo,
                Estacion,
                producto,
                --CAST(Semana AS VARCHAR) + '_cantidad' AS TipoSemana,
                CONCAT([date], '_cantidad') AS TipoSemana,

                VentasTotalesCantidad AS Valor
            FROM DatosParaPivot
        ) AS SourceTable
        PIVOT (
            SUM(Valor)
            FOR TipoSemana IN (
                {$columnsList}
            )
        ) AS PivotTable
        ORDER BY codigo;
        ";


        return $this->sql->select($query, []);
    }
    function getLubricantsMonth($from, $until){

        $fromDate = DateTime::createFromFormat('Y-m-d', $from);
        $untilDate = DateTime::createFromFormat('Y-m-d', $until);
        $fromInt = dateToInt($from);
        $untilInt = dateToInt($until);
        $currentDate = clone $fromDate;
        
        $months = [];
        while ($currentDate <= $untilDate) {
            $monthNumber = (int)$currentDate->format('m'); // Número de mes sin ceros a la izquierda
            $year = $currentDate->format('Y');
            $months[] = ['month' => $monthNumber, 'year' => $year];
            $currentDate->modify('first day of next month');
        }
    
        // Generar columnas dinámicas para el PIVOT
        $columns = [];
        foreach ($months as $month) {
            $columns[] = "[{$month['year']}_{$month['month']}_monto]";
            $columns[] = "[{$month['year']}_{$month['month']}_cantidad]";
        }
        $columnsList = implode(',', $columns);
    
        // Construir consulta SQL dinámica
        $query = "
                WITH DatosMensual AS (
            SELECT
                CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103) AS Fecha,
                MONTH(CONVERT(SMALLDATETIME, fch - 1, 103)) AS Mes,
                YEAR(CONVERT(SMALLDATETIME, fch - 1, 103)) AS [year],
                isd.codgas AS codigo,
                g.abr AS Estacion,
                T3.den AS producto,
                [codprd] AS CodProducto,
                SUM(canven) AS VentasCantidad,
                SUM(mtoven) AS MontoVentas
            FROM [SG12].[dbo].[Ventas] v
            INNER JOIN [SG12].[dbo].ISLAS isd ON v.codisl = isd.cod 
            INNER JOIN [SG12].[dbo].Gasolineras g ON codgas = g.cod 
            INNER JOIN [SG12].[dbo].Productos T3 ON V.codprd = T3.cod
            WHERE 
                fch BETWEEN $fromInt
                AND $untilInt
                AND codprd NOT IN (179, 180, 181, 1, 2, 3, 192, 193)
            GROUP BY
                g.abr,
                T3.den,
                fch,
                codgas,
                codprd
        ),
        DatosParaPivot AS (
            SELECT 
                CONCAT([year], '_', [Mes]) as [date],
                codigo,
                Estacion,
                producto,
                SUM(MontoVentas) AS VentasTotalesMonto,
                SUM(VentasCantidad) AS VentasTotalesCantidad
            FROM DatosMensual
            GROUP BY [year], Mes, codigo, Estacion, producto
        )
        SELECT 
            codigo,
            Estacion,
            producto,
            {$columnsList}
        FROM (
            SELECT 
                codigo,
                Estacion,
                Producto,
                CONCAT([date], '_monto') AS TipoMes,
                VentasTotalesMonto AS Valor
            FROM DatosParaPivot
            UNION ALL
            SELECT 
                codigo,
                Estacion,
                producto,
                CONCAT([date], '_cantidad') AS TipoMes,
                VentasTotalesCantidad AS Valor
            FROM DatosParaPivot
        ) AS SourceTable
        PIVOT (
            SUM(Valor)
            FOR TipoMes IN (
                {$columnsList}
            )
        ) AS PivotTable
        ORDER BY codigo;
        ";
        
        return $this->sql->select($query, []);
    }

    function getSaleWeekZone($from, $until){
        $fromDate = DateTime::createFromFormat('Y-m-d', $from);
        $untilDate = DateTime::createFromFormat('Y-m-d', $until);
        $fromInt = dateToInt($from);
        $untilInt = dateToInt($until);
        $currentDate = clone $fromDate;
        
        $weeks = [];
        while ($currentDate <= $untilDate) {
            $weekNumber = $currentDate->format('W'); // Número de semana
            $weekNumber = (int)$weekNumber; // Eliminar ceros a la izquierda

            $year = $currentDate->format('Y'); // Año, para manejar semanas cruzadas de años
            if ($weekNumber == 1 && $currentDate->format('m') == '12') {
                $year = $currentDate->format('Y') + 1;
            }
            $weeks[] = ['week' => $weekNumber, 'year' => $year];
            $currentDate->modify('next monday');
        }

        // Generar columnas dinámicas para el PIVOT
        $columns = [];
        foreach ($weeks as $week) {
            $columns[] = "[{$week['year']}_{$week['week']}]";
        }
        $columnsList = implode(',', $columns);

        // Construir consulta SQL dinámica
        $query = "
            WITH ValuesTable as (
                    SELECT  
                        CONVERT(date, CONVERT(SMALLDATETIME, fch - 1, 103), 103) AS 'Fecha',
                        DATEPARt(ISO_WEEK, CONVERT(date, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) AS 'Semana',
                        CASE 
                            WHEN DATEPART(ISO_WEEK, CONVERT(date, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) = 1
                            AND DATEPART(MONTH, CONVERT(date, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) = 12
                            THEN DATEPART(YEAR, CONVERT(date, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) + 1
                            ELSE DATEPART(YEAR, CONVERT(date, CONVERT(SMALLDATETIME, fch - 1, 103), 103))
                        END AS 'Año',
                        isd.codgas AS CodGasolinera,
                        T3.den AS Producto,
                        v.nrotur as Turno,
                        [codprd] AS CodProducto,
                        sum(v.canven) AS VentasReales,
                        E.estructura as Zona,
                        E.Nombre as Estacion
                    FROM [SG12].[dbo].[Ventas] v
                    INNER JOIN ISLAS isd on v.codisl = isd.cod 
                    INNER JOIN Productos T3 ON V.codprd = T3.cod
                    INNER JOIN TG.dbo.Estaciones E ON isd.codgas = E.Codigo
                    Where 
                    fch BETWEEN $fromInt
                    AND $untilInt
                   AND codprd IN(179, 180,181,2,3,1,192,193) 
                    GROUP BY
                        E.Codigo,T3.den, fch,nrotur,codgas,codprd,E.estructura,E.Nombre
                ),
                BasePivot as (
                    SELECT 
                        Zona,
                        CASE 
							WHEN GROUPING(Estacion) = 1 THEN 'Total ' + Zona
							ELSE Estacion 
						END as Estacion,
                        CodGasolinera,
                        SUM(VentasReales) as Ventas_Reales,
                        CONCAT(Año,'_',Semana) as column_name
                    FROM ValuesTable
                    GROUP BY 
					GROUPING SETS(
						(Zona, Estacion, CodGasolinera, CONCAT(Año,'_',Semana)),
						(Zona, CONCAT(Año,'_',Semana))
					)
                )
                SELECT DISTINCT 
                    Zona,
                    Estacion,
                    CodGasolinera,
                   {$columnsList}
                FROM BasePivot
                PIVOT(
                    SUM(Ventas_Reales)
                    FOR column_name IN (
                        {$columnsList}
                    )
                ) AS ptv
                ORDER BY Zona,Estacion asc;
            ";
          

        return $this->sql->select($query, []);
    }

    function get_sales_day_product($from, $until,$id_shift,$id_producto,$estaciones){
        $columns = [];
        $columnsName = [];

        $columns = array_map(fn($estacion) => "[{$estacion['codigo']}]", $estaciones);
        $columnsList = implode(',', $columns);
        $from = date('Y-d-m', strtotime($from));
        $until = date('Y-d-m', strtotime($until));

        $shiftOptions = [
            0 => "nrotur IN (11, 21, 31, 41)",
            11 => "nrotur = 11",
            21 => "nrotur = 21",
            31 => "nrotur = 31",
            41 => "nrotur = 41",
        ];
        $shift = $shiftOptions[$id_shift] ?? "";



        if ($id_producto == 0) {
           $producto  = "codprd IN (179, 180, 181, 2, 3, 1, 192, 193)";
        }if ($id_producto == 1) {
            $producto  = "codprd IN (179, 192)";
        }elseif ($id_producto == 2) {
            $producto  = "codprd IN (180, 193)";
        }elseif ($id_producto == 3) {
            $producto  = "codprd IN (181)";
        }


        $query="
                DECLARE @fecha_inicial_int INT = DATEDIFF(dd, 0, '{$from}') + 1;
                DECLARE @fecha_fin_int INT = DATEDIFF(dd, 0, '{$until}') + 1;

                WITH SalesData AS (
                    SELECT  
                        CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103) AS 'Fecha',
                        Year(CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as 'year',
                        datename(month, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as 'mounth',
                        datename(day, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as 'day1',
                        isd.codgas AS CodGasolinera,
                        case
						when T3.den ='   T-Maxima Regular' then 'T-Maxima Regular'
						when T3.den =' Gasolina Regular Menor a 91 Octanos' then 'T-Maxima Regular'
						when T3.den ='   T-Super Premium' then 'T-Super Premium'
						when T3.den =' Gasolina Premium Mayor o Igual a 91 Octanos' then 'T-Super Premium'
						when T3.den ='   Diesel Automotriz' then 'Diesel Automotriz'
						else 'diferente'
						end
						as 'product',
                        LEFT(CAST(nrotur AS VARCHAR), 1)  AS turn,
                        sum(canven) AS VentasReales,
                        fch
                    FROM [SG12].[dbo].[Ventas] v
                    INNER JOIN ISLAS isd on v.codisl = isd.cod 
                    INNER JOIN Gasolineras g ON codgas = g.cod 
                    INNER JOIN Productos T3 ON V.codprd = T3.cod
                    WHERE 
                        fch BETWEEN @fecha_inicial_int AND @fecha_fin_int 
                        AND {$producto}
                        AND {$shift}
                    GROUP BY
                        fch,
                        isd.codgas,
                        T3.den,
                        nrotur,
                        codprd
                )
                SELECT 
                    fch,
                    [year],
                    mounth,
                    day1,
                    turn,
                    [product],
                     {$columnsList}
                FROM 
                    (SELECT fch,Fecha, [year], day1,mounth, turn, [product], CodGasolinera, VentasReales
                    FROM SalesData) AS SourceTable
                PIVOT
                (
                    SUM(VentasReales)
                    FOR CodGasolinera IN ( {$columnsList})
                ) AS PivotTable
                ORDER BY fch desc, turn, [product];";

        return $this->sql->select($query, []);

    }

    function get_sales_day_trn($from, $until,$id_shift,$id_producto,$estaciones){
        $columns = [];
        $columnsName = [];
        // foreach ($estaciones as $estacion) {
        //     $columns[] = "[{$estacion['codigo']}]";
        // }
        $columns = array_map(fn($estacion) => "[{$estacion['codigo']}]", $estaciones);
        $columnsList = implode(',', $columns);
        $from = date('Y-d-m', strtotime($from));
        $until = date('Y-d-m', strtotime($until));
        $shiftOptions = [
            0 => "nrotur IN (11, 21, 31, 41)",
            11 => "nrotur = 11",
            21 => "nrotur = 21",
            31 => "nrotur = 31",
            41 => "nrotur = 41",
        ];
        $shift = $shiftOptions[$id_shift] ?? "";



        if ($id_producto == 0) {
           $producto  = "codprd IN (179, 180, 181, 2, 3, 1, 192, 193)";
        }if ($id_producto == 1) {
            $producto  = "codprd IN (179, 192)";
        }elseif ($id_producto == 2) {
            $producto  = "codprd IN (180, 193)";
        }elseif ($id_producto == 3) {
            $producto  = "codprd IN (181)";
        }


        $query="
                DECLARE @fecha_inicial_int INT = DATEDIFF(dd, 0, '{$from}') + 1;
                DECLARE @fecha_fin_int INT = DATEDIFF(dd, 0, '{$until}') + 1;

                WITH SalesData AS (
                    SELECT
                        CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103) AS 'Fecha',
                        Year(CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as 'year',
                        datename(month, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as 'mounth',
                        datename(day, CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fch - 1, 103), 103)) as 'day1',
                        isd.codgas AS CodGasolinera,
                        LEFT(CAST(nrotur AS VARCHAR), 1)  AS turn,
                        sum(canven) AS VentasReales,
                        fch
                    FROM [SG12].[dbo].[Ventas] v
                    INNER JOIN ISLAS isd on v.codisl = isd.cod 
                    INNER JOIN Gasolineras g ON codgas = g.cod 
                    INNER JOIN Productos T3 ON V.codprd = T3.cod
                    WHERE 
                        fch BETWEEN @fecha_inicial_int AND @fecha_fin_int 
                        AND {$producto}
                        AND {$shift}
                    GROUP BY
                        fch,
                        isd.codgas,
                        T3.den,
                        nrotur,
                        codprd
                )
                SELECT 
                    fch,
                    [year],
                    mounth,
                    day1,
                    turn,
                     {$columnsList}
                FROM 
                    (SELECT fch,Fecha, [year], day1,mounth, turn,  CodGasolinera, VentasReales
                    FROM SalesData) AS SourceTable
                PIVOT
                (
                    SUM(VentasReales)
                    FOR CodGasolinera IN ( {$columnsList})
                ) AS PivotTable
                ORDER BY fch desc, turn;";

        return $this->sql->select($query, []);
    }
}