<?php
class HistoricoPreciosModel extends Model{
    public $id_historico;
    public $precios;
    public $fecha_precio;
    public $fecha_agregado;
    public $grupo;
    public $producto;
    public $plaza;
    /**
     * @return array|false
     * @throws Exception
     */
    public function get_rows($from = false, $until = false) : array|false {
        $query = 'Select
                    t1.id_historico,
                    t1.precios,
                    t1.fecha_precio,
                    t1.fecha_agregado,
                    t2.nombre as grupo,
                    t3.nombre as producto,
                    t4.nombre as plaza
                    from [TGV2].dbo.Historico_precios t1
                    LEFT JOIN [TGV2].dbo.Grupo t2 on t1.id_grupo = t2.id_grupo
                    LEFT JOIN [TGV2].dbo.[Productos] t3 on t1.id_productos = t3.id_productos
                    LEFT JOIN [TGV2].dbo.Plaza t4 on t1.Id_plaza = t4.Id_plaza
                    Where t1.fecha_precio >= ? and t1.fecha_precio <= ?;';
                    $params = [$from, $until];
        return ($this->sql->select($query,$params)) ?: false ;
    }
    public function get_price_table_pivot($from = false, $until = false, $daysString,$product,$Id_plaza) : array|false {
        $query = 'SELECT grupo, id_grupo, ' . $daysString . '
                                FROM (
                                    SELECT
                                        t2.nombre AS grupo,
                                        t1.id_grupo,
                                        ROUND(t1.precios, 2) AS precios,
                                        CONCAT(MONTH(t1.fecha_precio), \'_\', DAY(t1.fecha_precio)) AS dia_mes
                                    FROM [TGV2].dbo.Historico_precios t1
                                    LEFT JOIN [TGV2].dbo.Grupo t2 ON t1.id_grupo = t2.id_grupo
                                    WHERE
                                        t1.id_productos = ?
                                        AND t1.Id_plaza = ?
                                        AND t1.fecha_precio >= ?
                                        AND t1.fecha_precio <= ?
                                ) AS SourceTable
                                PIVOT (
                                    AVG(precios) FOR dia_mes IN (' . $daysString . ')
                                ) AS PivotTable
                                ORDER BY grupo;';
        $params = [
            $product,
            $Id_plaza,
            $from,
            $until
        ];
        return ($this->sql->select($query,$params)) ?: false ;
    }
    public function get_price_table_pivot_v($from = false, $until = false, $daysString,$product,$Id_plaza) : array|false {
        $query = 'SELECT grupo, id_grupo, ' . $daysString . '
                                FROM (
                                    SELECT
                                        t2.nombre AS grupo,
                                        t1.id_grupo,
                                        ROUND(t1.precios, 2) AS precios,
                                        CONCAT(DAY(t1.fecha_precio), \'_\',MONTH(t1.fecha_precio) ) AS dia_mes
                                    FROM [TGV2].dbo.Historico_precios t1
                                    LEFT JOIN [TGV2].dbo.Grupo t2 ON t1.id_grupo = t2.id_grupo
                                    WHERE
                                        t1.id_grupo != 61 and
                                        t1.id_productos = ?
                                        AND t1.Id_plaza = ?
                                        AND t1.fecha_precio >= ?
                                        AND t1.fecha_precio <= ?
                                ) AS SourceTable
                                PIVOT (
                                    AVG(precios) FOR dia_mes IN (' . $daysString . ')
                                ) AS PivotTable
                                ORDER BY grupo;';
        $params = [
            $product,
            $Id_plaza,
            $from,
            $until
        ];

        return ($this->sql->select($query,$params)) ?: false ;
    }

    // public function get_price_table_pivot($from = false, $until = false, $daysString,$product,$Id_plaza) : array|false {
    //     $query = 'SELECT grupo, id_grupo, '.$daysString.'
    //                         FROM (
    //                             SELECT
    //                                 t2.nombre AS grupo,
    //                                 t1.id_grupo,
    //                                 ROUND(t1.precios, 2) AS precios,
    //                                 DAY(t1.fecha_precio) AS dia
    //                             FROM [TGV2].dbo.Historico_precios t1
    //                             LEFT JOIN [TGV2].dbo.Grupo t2 ON t1.id_grupo = t2.id_grupo
    //                             WHERE
    //                                 t1.id_productos = ?
    //                                 AND t1.Id_plaza = ?
    //                                 AND t1.fecha_precio >= ?
    //                                 AND t1.fecha_precio <= ?
    //                         ) AS SourceTable
    //                         PIVOT (
    //                             AVG(precios) FOR dia IN ('.$daysString.')
    //                         ) AS PivotTable
    //                         ORDER BY grupo;';
    //     $params = [
    //         $product,
    //         $Id_plaza,
    //         $from,
    //         $until
    //     ];
    //     echo '<pre>';
    //     var_dump($query);
    //     var_dump($from);
    //     var_dump($until);
    //     die();
    //     return ($this->sql->select($query,$params)) ?: false ;
    // }

    public function get_price_week_pivot($product,$Id_plaza,$from,$until) : array|false {
        $query = 'WITH WeeklyPrices AS (
                        SELECT
                            t2.nombre AS grupo,
                            t1.id_grupo,
                            CAST(ROUND(t1.precios, 4) AS DECIMAL(10, 4)) AS precios,
                            t1.fecha_precio,
                            DATEPART(YEAR, t1.fecha_precio) AS year_num,
                            DATEPART(WEEK, t1.fecha_precio) AS week_num
                        FROM [TGV2].dbo.Historico_precios t1
                        LEFT JOIN [TGV2].dbo.Grupo t2 ON t1.id_grupo = t2.id_grupo
                        WHERE
                            t1.id_grupo != 61 and
                            t1.id_productos = ?
                            AND t1.Id_plaza = ?
                            AND t1.fecha_precio >=\' '.$from. '\'
                            AND t1.fecha_precio <=\' '.$until.'\'
                    )
                    SELECT
                        grupo,
                        id_grupo,
                        year_num,
                        week_num,
                        CAST(MAX(precios) AS DECIMAL(10, 2)) AS max_precio,
                        MIN(fecha_precio) AS fecha_inicio_semana,
                        MAX(fecha_precio) AS fecha_fin_semana
                    FROM WeeklyPrices
                    Where grupo is not null
                    GROUP BY
                        grupo,
                        id_grupo,
                        year_num,
                        week_num
                    ORDER BY
                        year_num,
                        week_num';
        $params = [
            $product,
            $Id_plaza,
        ];


        return ($this->sql->select($query,$params)) ?: false ;
    }


    public function get_price_month_pivot($product,$Id_plaza,$from,$until) : array|false {
        $query = 'WITH MonthlyPrices AS (
                    SELECT
                        t2.nombre AS grupo,
                        t1.id_grupo,
                        CAST(ROUND(t1.precios, 4) AS DECIMAL(10, 4)) AS precios,
                        t1.fecha_precio,
                        DATEPART(YEAR, t1.fecha_precio) AS year_num,
                        DATEPART(MONTH, t1.fecha_precio) AS month_num
                    FROM [TGV2].dbo.Historico_precios t1
                    LEFT JOIN [TGV2].dbo.Grupo t2 ON t1.id_grupo = t2.id_grupo
                    WHERE
                        t1.id_grupo != 61 and
                        t1.id_productos = ?
                        AND t1.Id_plaza = ?
                        AND t1.fecha_precio >=\' '.$from. '\'
                        AND t1.fecha_precio <=\' '.$until.'\'
                )
                SELECT
                    grupo,
                    id_grupo,
                    year_num,
                    month_num,
                    CAST(avg(precios) AS DECIMAL(10, 2)) AS max_precio,
                    MIN(fecha_precio) AS fecha_inicio_mes,
                    MAX(fecha_precio) AS fecha_fin_mes
                FROM MonthlyPrices
                WHERE grupo IS NOT NULL
                GROUP BY
                   year_num,
                    month_num,
                    id_grupo,
                    grupo
                ORDER BY
                    year_num,
                    month_num;';
        $params = [
            $product,
            $Id_plaza,
        ];
        
        return ($this->sql->select($query,$params)) ?: false ;
    }

    public function insert_prices_with_transaction(array $priceInserts) : bool {
        try {
            $this->sql->beginTransaction(); // Inicia la transacción
            foreach ($priceInserts as $insert) {
                $query = 'INSERT INTO [TGV2].dbo.Historico_precios
                          (id_grupo, id_productos, Id_plaza, precios, fecha_precio)
                          VALUES (?, ?, ?, ?, ?)';
                $params = [
                    $insert['id_grupo'],
                    $insert['id_productos'],
                    $insert['Id_plaza'],
                    $insert['precios'],
                    $insert['fecha']
                ];
                // Ejecutar la consulta dentro de la transacción
                if (!$this->sql->insert($query, $params)) {
                    // Si algo falla, realizar un rollback y devolver false
                    $this->sql->rollBack();
                    return false;
                }
            }
            $this->sql->commit();// Confirmar la transacción
            return true;
        } catch (Exception $e) {
            // En caso de error, realizar un rollback
            $this->sql->rollBack();
            echo "Error: " . $e->getMessage();
            return false;
        }
    }


}