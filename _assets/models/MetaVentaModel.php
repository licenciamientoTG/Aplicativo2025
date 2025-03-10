<?php
class MetaVentaModel extends Model{
    public $id;
    public $año;
    public $mes;
    public $cantidad;
    public $tipo_id;
    public $fecha_ingreso;
    public $zona;

    function get_mount_resumen() : array|false {
        $query = "SELECT
                    [año],
                    [mes],
                    FORMAT(ROUND(SUM(CASE WHEN [tipo_id] = 28 THEN [cantidad] ELSE 0 END), 0), 'N0', 'en-US') AS 'sum_meta_cre',
                    FORMAT(ROUND(SUM(CASE WHEN [tipo_id] = 127 THEN [cantidad] ELSE 0 END), 0), 'N0', 'en-US') AS 'sum_meta_deb',
                    FORMAT(ROUND(SUM( [cantidad] ), 0), 'N0', 'en-US') AS 'sum_meta_mouth',
                    
                    FORMAT(ROUND(SUM(CASE WHEN [tipo_id] = 28 and zona=1 THEN [cantidad] ELSE 0 END), 0), 'N0', 'en-US') AS 'sum_meta_cre_1',
                    FORMAT(ROUND(SUM(CASE WHEN [tipo_id] = 127 and zona=1 THEN [cantidad] ELSE 0 END), 0), 'N0', 'en-US') AS 'sum_meta_deb_1',
                    FORMAT(ROUND(SUM(CASE WHEN zona=1 THEN [cantidad] ELSE 0 END), 0), 'N0', 'en-US') AS 'sum_meta_mouth_1',

                    FORMAT(ROUND(SUM(CASE WHEN [tipo_id] = 28 and zona=3 THEN [cantidad] ELSE 0 END), 0), 'N0', 'en-US') AS 'sum_meta_cre_3',
                    FORMAT(ROUND(SUM(CASE WHEN [tipo_id] = 127 and zona=3 THEN [cantidad] ELSE 0 END), 0), 'N0', 'en-US') AS 'sum_meta_deb_3',
                    FORMAT(ROUND(SUM(CASE WHEN zona=3 THEN [cantidad] ELSE 0 END), 0), 'N0', 'en-US') AS 'sum_meta_mouth_3'

                FROM [TGV2].[dbo].[MetaVenta]
                WHERE [tipo_id] IN (28, 127)
                GROUP BY [año], [mes]
                ORDER BY [año], [mes]";
        return ($this->sql->select($query)) ?: false ;
    }

    function get_mount_resumen_end() : array|false {
        $query = "SELECT
                    t1.[año],
                    t1.[mes],
                    FORMAT(t5.sum_meta_cre, 'N0', 'en-US') as 'sum_meta_cre',
                    FORMAT(t5.sum_meta_deb, 'N0', 'en-US') as 'sum_meta_deb',
                    FORMAT(t5.sum_meta_mouth, 'N0', 'en-US') as 'sum_meta_mouth',
                    FORMAT(t5.sum_meta_cre_1, 'N0', 'en-US') as 'sum_meta_cre_1',
                    FORMAT(t5.sum_meta_deb_1, 'N0', 'en-US') as 'sum_meta_deb_1',
                    FORMAT(t5.sum_meta_mouth_1, 'N0', 'en-US') as 'sum_meta_mouth_1',
                    FORMAT(t5.sum_meta_cre_3, 'N0', 'en-US') as 'sum_meta_cre_3',
                    FORMAT(t5.sum_meta_deb_3, 'N0', 'en-US') as 'sum_meta_deb_3', 
                    FORMAT(t5.sum_meta_mouth_3, 'N0', 'en-US') as 'sum_meta_mouth_3',
                    ISNULL(FORMAT(ROUND(SUM( t1.[litros] ), 0), 'N0', 'en-US'),0) as 'sum_mouth',
                    ISNULL(FORMAT(ROUND(SUM(CASE WHEN t1.[tipo_id] = 28 THEN t1.[litros] ELSE 0 END), 0), 'N0', 'en-US'),0) as 'sum_cre',
                    ISNULL(FORMAT(ROUND(SUM(CASE WHEN t1.[tipo_id] = 127 THEN t1.[litros] ELSE 0 END), 0), 'N0', 'en-US'),0) as 'sum_deb',

                    ISNULL(FORMAT(ROUND(((SUM( t1.[litros] ))-t4.litros), 0), 'N0', 'en-US'),0) as 'sum_mouth_mun',
                    ISNULL(FORMAT(ROUND(((SUM(CASE WHEN t1.[tipo_id] = 28 THEN t1.[litros] ELSE 0 END))-t4.litros), 0), 'N0', 'en-US'),0) as 'sum_cre_mun',

                    FORMAT(ROUND(CASE 
                                WHEN t5.sum_meta_mouth is null THEN 0 
                                ELSE (((SUM(t1.litros))-t4.litros) / t5.sum_meta_mouth) *100
                            END, 2), 'N2', 'en-US') AS 'percentage_achieved',

                    FORMAT(ROUND(CASE 
                            WHEN t5.sum_meta_cre is null THEN 0 
                                ELSE (((SUM(CASE WHEN t1.[tipo_id] = 28 THEN t1.[litros] ELSE 0 END))-t4.litros) / t5.sum_meta_cre) *100
                        END, 2), 'N2', 'en-US') AS 'percentage_achieved_cred',
                    FORMAT(ROUND(CASE 
                            WHEN t5.sum_meta_deb is null THEN 0 
                                ELSE ((SUM(CASE WHEN t1.[tipo_id] = 127 THEN t1.[litros] ELSE 0 END)) / t5.sum_meta_deb) *100
                        END, 2), 'N2', 'en-US') AS 'percentage_achieved_deb',


                    ISNULL(FORMAT(ROUND(t3.VentasReales, 0), 'N0', 'en-US'),0) as 'VentasReales',
                    ISNULL(FORMAT(ROUND((t3.VentasReales-SUM( t1.[litros] )), 0), 'N0', 'en-US'),0) as 'sales_cash',

                    ISNULL(FORMAT(ROUND(t4.litros, 0), 'N0', 'en-US'),0) as 'cred_mun_jua'  

                    FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual] t1
                    LEFT JOIN (
                        SELECT
                        MONTH(DATEADD(DAY, fch - 1, '19000101')) AS Mes,
                        YEAR(DATEADD(DAY, fch - 1, '19000101')) AS Año,
                        SUM(canven) AS VentasReales
                        FROM [SG12].[dbo].[Ventas] v
                        INNER JOIN [SG12].[dbo].Productos p ON v.codprd = p.cod
                        WHERE codprd IN (179, 180, 181, 2, 3, 1, 192, 193)
                        AND YEAR(DATEADD(DAY, fch - 1, '19000101')) IN (YEAR(GETDATE()), YEAR(GETDATE()) - 1)
                        GROUP BY
                        MONTH(DATEADD(DAY, fch - 1, '19000101')),
                        YEAR(DATEADD(DAY, fch - 1, '19000101'))
                        ) t3 on t1.año = t3.año and t1.mes = t3.mes
                    LEFT JOIN(
                        SELECT
                            LTRIM(RTRIM(t2.rfc)) AS rfc,  -- Elimina espacios en blanco alrededor del RFC
                            t1.mes AS mes,  -- Combina año y mes en una única columna
                            t1.año AS año,  -- Combina año y mes en una única columna
                            ROUND(sum(t1.litros),0)   as litros
                        FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual] t1
                        LEFT JOIN [TGV2].[dbo].[Clientes] t2 on t1.cliente_id = t2.cod
                        LEFT JOIN [TGV2].[dbo].[Asesores] t3 on t2.asesor_id = t3.asesor_id
                        LEFT JOIN [TGV2].[dbo].[Zonas] t4 on t2.[zona_id] = t4.[zona_id]
                        WHERE
                            (t1.año = YEAR(GETDATE()) OR t1.año = YEAR(GETDATE()) - 1)  
                            AND t1.tipo_id = 28
                            and t2.rfc = 'MJU741010ET1'
                            Group by t2.rfc, t1.año, t1.mes) t4 on t1.año = t4.año and t1.mes = t4.mes
                    LEFT JOIN(
                        SELECT  t1.[año],t1.[mes],
                        ROUND(SUM(CASE WHEN t1.[tipo_id] = 28 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_cre',
                        ROUND(SUM(CASE WHEN t1.[tipo_id] = 127 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_deb',
                        ROUND(SUM( t1.[cantidad] ), 0) AS 'sum_meta_mouth',
                        ROUND(SUM(CASE WHEN t1.[tipo_id] = 28 and t1.zona=1 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_cre_1',
                        ROUND(SUM(CASE WHEN t1.[tipo_id] = 127 and t1.zona=1 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_deb_1',
                                    ROUND(SUM(CASE WHEN t1.zona=1 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_mouth_1',

                                    ROUND(SUM(CASE WHEN t1.[tipo_id] = 28 and zona=3 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_cre_3',
                                    ROUND(SUM(CASE WHEN t1.[tipo_id] = 127 and zona=3 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_deb_3',
                                    ROUND(SUM(CASE WHEN t1.zona=3 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_mouth_3'
                    FROM [TGV2].[dbo].[MetaVenta] t1
                    WHERE [tipo_id] IN (28, 127) 
                    GROUP BY t1.[año], t1.[mes]
                    ) t5 on t1.año = t5.año and t1.mes = t5.mes
                    WHERE t1.[tipo_id] IN (28, 127) 
                    and t1.año >= 2024
                    GROUP BY 
                    t1.[año], 
                    t1.[mes],
                    t3.VentasReales,
                    t4.litros,
                    t5.sum_meta_cre,
                    t5.sum_meta_deb,
                    t5.sum_meta_mouth,
                    t5.sum_meta_cre_1,
                    t5.sum_meta_deb_1,
                    t5.sum_meta_mouth_1,
                    t5.sum_meta_cre_3,
                    t5.sum_meta_deb_3,
                    t5.sum_meta_mouth_3
                    ORDER BY t1.[año] desc, t1.[mes] desc;
                ";
            return ($this->sql->select($query)) ?: false ;

    }

    function get_mount_resumen_export() : array|false {
        $query = "SELECT
                t1.[año],
                t1.[mes],
                ROUND(SUM(CASE WHEN t1.[tipo_id] = 28 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_cre',
                ROUND(SUM(CASE WHEN t1.[tipo_id] = 127 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_deb',
                ROUND(SUM( t1.[cantidad] ), 0) AS 'sum_meta_mouth',
                
                ROUND(SUM(CASE WHEN t1.[tipo_id] = 28 and t1.zona=1 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_cre_1',
                ROUND(SUM(CASE WHEN t1.[tipo_id] = 127 and t1.zona=1 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_deb_1',
                ROUND(SUM(CASE WHEN t1.zona=1 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_mouth_1',

                ROUND(SUM(CASE WHEN t1.[tipo_id] = 28 and zona=3 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_cre_3',
                ROUND(SUM(CASE WHEN t1.[tipo_id] = 127 and zona=3 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_deb_3',
                ROUND(SUM(CASE WHEN t1.zona=3 THEN t1.[cantidad] ELSE 0 END), 0) AS 'sum_meta_mouth_3',

                ISNULL(ROUND(t2.sum_mouth, 0), 0) as 'sum_mouth',
                ISNULL(ROUND(t2.sum_cre, 0), 0) as 'sum_cre',
                ISNULL(ROUND(t2.sum_deb, 0), 0) as 'sum_deb',
                ISNULL(ROUND((t2.sum_mouth-t4.litros), 0), 0) as 'sum_mouth_mun',
                ISNULL(ROUND((t2.sum_cre-t4.litros), 0), 0) as 'sum_cre_mun',


               ROUND(CASE 
                        WHEN SUM(t1.[cantidad]) = 0 THEN 0 
                        ELSE ((t2.sum_mouth-t4.litros) / SUM(t1.[cantidad])) *100
                    END, 2) AS 'percentage_achieved',
               ROUND(CASE 
                        WHEN SUM(CASE WHEN t1.[tipo_id] = 28 THEN t1.[cantidad] ELSE 0 END) = 0 THEN 0 
                        ELSE ((t2.sum_cre-t4.litros) / SUM(CASE WHEN t1.[tipo_id] = 28 THEN t1.[cantidad] ELSE 0 END)) *100
                    END, 2) AS 'percentage_achieved_cred',
               ROUND(CASE 
                        WHEN SUM(CASE WHEN t1.[tipo_id] = 127 THEN t1.[cantidad] ELSE 0 END) = 0 THEN 0 
                        ELSE (t2.sum_deb / SUM(CASE WHEN t1.[tipo_id] = 127 THEN t1.[cantidad] ELSE 0 END)) *100
                    END, 2) AS 'percentage_achieved_deb',

                ISNULL(ROUND(t3.VentasReales, 0), 0) as 'VentasReales',
                ISNULL(ROUND((t3.VentasReales-t2.sum_mouth), 0), 0) as 'sales_cash',

                ISNULL(ROUND(t4.litros, 0), 0) as 'cred_mun_jua'


            FROM [TGV2].[dbo].[MetaVenta] t1
            Left join (
                SELECT
                t1.[año],
                t1.[mes],
                SUM(CASE WHEN t1.[tipo_id] = 28 THEN t1.[litros] ELSE 0 END) AS 'sum_cre',
                SUM(CASE WHEN t1.[tipo_id] = 127 THEN t1.[litros] ELSE 0 END) AS 'sum_deb',
                SUM( t1.[litros] ) AS 'sum_mouth'
                FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual] t1
                WHERE t1.[tipo_id] IN (28, 127)
                GROUP BY t1.[año], t1.[mes]
            )t2 on t1.año = t2.año and t1.mes = t2.mes
            LEFT JOIN (
                SELECT
                MONTH(DATEADD(DAY, fch - 1, '19000101')) AS Mes,
                YEAR(DATEADD(DAY, fch - 1, '19000101')) AS Año,
                SUM(canven) AS VentasReales
                FROM [SG12].[dbo].[Ventas] v
                INNER JOIN [SG12].[dbo].Productos p ON v.codprd = p.cod
                WHERE codprd IN (179, 180, 181, 2, 3, 1, 192, 193)
                AND YEAR(DATEADD(DAY, fch - 1, '19000101')) IN (YEAR(GETDATE()), YEAR(GETDATE()) - 1)
                GROUP BY
                MONTH(DATEADD(DAY, fch - 1, '19000101')),
                YEAR(DATEADD(DAY, fch - 1, '19000101'))
                ) t3 on t1.año = t3.año and t1.mes = t3.mes
            LEFT JOIN(
                SELECT
                    LTRIM(RTRIM(t2.rfc)) AS rfc,  -- Elimina espacios en blanco alrededor del RFC
                    t1.mes AS mes,  -- Combina año y mes en una única columna
                    t1.año AS año,  -- Combina año y mes en una única columna
                    ROUND(sum(t1.litros),0)   as litros
                FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual] t1
                LEFT JOIN [TGV2].[dbo].[Clientes] t2 on t1.cliente_id = t2.cod
                LEFT JOIN [TGV2].[dbo].[Asesores] t3 on t2.asesor_id = t3.asesor_id
                LEFT JOIN [TGV2].[dbo].[Zonas] t4 on t2.[zona_id] = t4.[zona_id]
                WHERE
                    (t1.año = YEAR(GETDATE()) OR t1.año = YEAR(GETDATE()) - 1)  
                    AND t1.tipo_id = 28
                    and t2.rfc = 'MJU741010ET1'
                    Group by t2.rfc, t1.año, t1.mes) t4 on t1.año = t4.año and t1.mes = t4.mes
            WHERE t1.[tipo_id] IN (28, 127) 
            GROUP BY t1.[año], t1.[mes],t2.sum_mouth,t2.sum_cre,t2.sum_deb,t3.VentasReales,t4.litros
            ORDER BY t1.[año], t1.[mes];
                ";

            return ($this->sql->select($query)) ?: false ;

    }
 




}