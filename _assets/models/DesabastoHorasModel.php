<?php
class DesabastoHorasModel extends Model{
    public $id_desabasto;
    public $horas;
    public $id_producto;
    public $fecha_desabasto;
    public $fecha_agregado;
    public $id_estacion;
    public $id_user;
    /**
     * @return array|false
     * @throws Exception
     */
    public function get_rows() : array|false {
        $query = 'SELECT
                    t1.id_desabasto,
                    t1.horas,
                    t1.id_producto,
                    t1.id_user,
                    Format(t1.fecha_desabasto, \'yyyy-MM-dd\') as fecha_desabasto,
                    t1.fecha_agregado,
                    t3.nombre as producto,
                    t2.nombre as estacion,
                    t2.Estacion as codigo_estacion,
                    t2.Denominacion as razon_social,
                    t2.Zona as zona
                    FROM [TGV2].[dbo].[DesabastoHoras] t1
                    LEFT JOIN [TG].[dbo].Estaciones t2 on t1.id_estacion = t2.Codigo
                    LEFT JOIN [TGV2].dbo.Productos t3 on t1.id_producto = t3.id_productos
                    WHere status  = 1
                    order by t1.id_desabasto asc';
        $params = [];
        return ($this->sql->select($query,$params)) ?: false ;
    }

    public function insert_row() : bool {
        $query = 'INSERT INTO [TGV2].[dbo].[DesabastoHoras] (
                                horas, id_producto,fecha_desabasto,id_estacion,id_user )
                                VALUES (?,?,?,?,?)';
        $params = [
                $this->horas,
                $this->id_producto,
                $this->fecha_desabasto,
                $this->id_estacion,
                $this->id_user
                ];
        return ($this->sql->insert($query,$params))? true : false ;
    }
    function delete_row($id_desabasto){
        $query = 'UPDATE [TGV2].dbo.DesabastoHoras set [status]=0 Where id_desabasto = ?';
        $params = [$id_desabasto];

        return ($this->sql->update($query,$params)) ? true : false;
    }

    function monthly_summary_shortge(): array|false {
        $currentYear = date('Y');
        $currentMonth = date('m');
        $startYear = $currentYear - 2;
        $startMonth = $currentMonth;
        $months = [];
        for ($year = $currentYear; $year >= $startYear; $year--) {
            $monthStart = ($year == $currentYear) ? $currentMonth : 12;
            $monthEnd = ($year == $startYear) ? $startMonth : 1;
            for ($month = $monthStart; $month >= $monthEnd; $month--) {
                $formattedMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
                $months[] = "$year-$formattedMonth";
            }
        }
        // Crear las partes de la consulta que dependen de los meses
        $isNullColumns = array_map(fn($m) => "ISNULL([$m], 0) AS [" . date('M-Y', strtotime($m . "-01")) . "]", $months);
        $pivotInClause = implode(', ', array_map(fn($m) => "[$m]", $months));
        // Construir la consulta completa
        $query = "
            SELECT
                nombre,
                Denominacion,
                Denominacion_name = CASE
                    WHEN CHARINDEX('DIAZ GAS', Denominacion) > 0 THEN 'Diaz Gas'
                    WHEN CHARINDEX('Distribuidora Gaso Mex', Denominacion) > 0 THEN 'Gasomex'
                    WHEN CHARINDEX('SERVICIO SYC S.A. DE C.V', Denominacion) > 0 THEN 'SYC'
                    WHEN CHARINDEX('ESTACION CUSTODIA', Denominacion) > 0 THEN 'Custodia'
                    WHEN CHARINDEX('Distribuidora Clara', Denominacion) > 0 THEN 'Clara'
                    WHEN CHARINDEX('Servicio el Jarudo', Denominacion) > 0 THEN 'Jarudo'
                END,
                " . implode(",\n            ", $isNullColumns) . "
            FROM (
                SELECT
                    t2.nombre,
                    t2.Denominacion,
                    CONCAT(YEAR(t1.[fecha_desabasto]), '-', FORMAT(MONTH(t1.[fecha_desabasto]), '00')) AS Mes,
                    SUM(t1.horas) AS HorasDesabasto
                FROM [TGV2].[dbo].[DesabastoHoras] t1
                LEFT JOIN [TG].[dbo].Estaciones t2 on t1.id_estacion = t2.Codigo
                WHERE t1.fecha_desabasto >= '" . ($startYear . '-' . str_pad($startMonth, 2, '0', STR_PAD_LEFT)) . "-01'
                and t1.status = 1
                GROUP BY t2.nombre,t2.Denominacion, YEAR(t1.[fecha_desabasto]), MONTH(t1.[fecha_desabasto])
            ) AS SourceTable
            PIVOT (
                SUM(HorasDesabasto)
                FOR Mes IN ($pivotInClause)
            ) AS PivotTable
            ORDER BY nombre;
        ";
        $params = [];
        return ($this->sql->select($query, $params)) ?: false;
    }

    // function monthly_summary_shortge(): array|false {
    //     // Generar el rango de meses desde 2022 hasta el mes actual
    //     $startYear = 2022;
    //     $currentYear = date('Y');
    //     $currentMonth = date('m');
    //     $months = [];
    //     for ($year = $startYear; $year <= $currentYear; $year++) {
    //         for ($month = 1; $month <= 12; $month++) {
    //             if ($year == $currentYear && $month > $currentMonth) {
    //                 break;
    //             }
    //             $formattedMonth = str_pad($month, 2, '0', STR_PAD_LEFT);
    //             $months[] = "$year-$formattedMonth";
    //         }
    //     }
    //     // Crear las partes de la consulta que dependen de los meses
    //     $isNullColumns = array_map(fn($m) => "ISNULL([$m], 0) AS [" . date('M-Y', strtotime($m . "-01")) . "]", $months);
    //     $pivotInClause = implode(', ', array_map(fn($m) => "[$m]", $months));
    //     // Construir la consulta completa
    //     $query = "
    //         SELECT
    //             nombre,
    //             Denominacion,
    //             Denominacion_name = Case
    //             WHEN CHARINDEX('DIAZ GAS', Denominacion) > 0 THEN 'Diaz Gas'
    //             WHEN CHARINDEX('Distribuidora Gaso Mex', Denominacion) > 0 THEN 'Gasomex'
    //             WHEN CHARINDEX('SERVICIO SYC S.A. DE C.V', Denominacion) > 0 THEN 'SYC'
    //             WHEN CHARINDEX('ESTACION CUSTODIA', Denominacion) > 0 THEN 'Custodia'
    //             WHEN CHARINDEX('Distribuidora Clara', Denominacion) > 0 THEN 'Clara'
    //             WHEN CHARINDEX('Servicio el Jarudo', Denominacion) > 0 THEN 'Jarudo'
    //             end,
    //             " . implode(",\n            ", $isNullColumns) . "
    //         FROM (
    //             SELECT
    //                 t2.nombre,
    //                 t2.Denominacion,
    //                 CONCAT(YEAR(t1.[fecha_desabasto]), '-', FORMAT(MONTH(t1.[fecha_desabasto]), '00')) AS Mes,
    //                 SUM(t1.horas) AS HorasDesabasto
    //             FROM [TGV2].[dbo].[DesabastoHoras] t1
    //             LEFT JOIN [TG].[dbo].Estaciones t2 on t1.id_estacion = t2.Codigo
    //             WHERE t1.fecha_desabasto >= '2022-01-01'
    //             GROUP BY t2.nombre,t2.Denominacion, YEAR(t1.[fecha_desabasto]), MONTH(t1.[fecha_desabasto])
    //         ) AS SourceTable
    //         PIVOT (
    //             SUM(HorasDesabasto)
    //             FOR Mes IN ($pivotInClause)
    //         ) AS PivotTable
    //         ORDER BY nombre;
    //     ";
    //     echo '<pre>';
    //     var_dump($query);
    //     die();
    //     $params = [];
    //     return ($this->sql->select($query, $params)) ?: false;
    // }

    function daily_summary_shortge_table($id_producto): array|false {
        $gasolineras = [
            '07 Gemela Grande', '19 Aguascalientes', '01 Malecón', '02 Lerdo',
            '05 Lopez Mateos', '06 Gemela Chica', '09 Municipio Libre',
            '10 Aztecas', '11 Misiones', '12 Puerto de palos',
            '13 Miguel de la madrid', '14 Permuta', '15 Electrolux',
            '16 Aeronáutica', '17 Custodia', '18 Anapra', '04 Parral',
            '03 Delicias', 'NO FUNCIONA', '08 Plutarco', '20 Tecnológico',
            '21 Ejército Nacional', '22 Satélite', '23 Las fuentes',
            '24 Clara', '25 Solis', '26 Santiago Troncoso', '27 Jarudo',
            '28 Hermanos Escobar', '29 Villa Ahumada', '30 El castaño',
            '31 Travel Center', '32 Picachos', '33 Ventanas','34 San Rafael','35 Puertecito','36 Jesus Maria'
        ];// Definir la lista de gasolineras
        // Crear las partes de la consulta que dependen de las gasolineras
        $isNullColumns = array_map(fn($g) => "ISNULL([" . $g . "], 0) AS [" . $g . "]", $gasolineras);
        $pivotInClause = implode(', ', array_map(fn($g) => "[$g]", $gasolineras));

        $query = "
            WITH DateSeries AS (
                SELECT CONCAT(YEAR(GETDATE()), '-', FORMAT(MONTH(GETDATE()), '00')) AS mes
                UNION ALL
                SELECT CONCAT(YEAR(DATEADD(MONTH, -1, CAST(mes + '-01' AS DATE))), '-', 
                            FORMAT(MONTH(DATEADD(MONTH, -1, CAST(mes + '-01' AS DATE))), '00'))
                FROM DateSeries
                WHERE DATEADD(MONTH, -1, CAST(mes + '-01' AS DATE)) >= DATEADD(YEAR, -2, GETDATE())
            )
            SELECT
                ds.mes,
                " . implode(",\n            ", $isNullColumns) . "
            FROM DateSeries ds
            LEFT JOIN (
                SELECT
                    CONCAT(YEAR(t1.[fecha_desabasto]), '-', FORMAT(MONTH(t1.[fecha_desabasto]), '00')) AS mes,
                    t2.Nombre AS NombreGasolinera,
                    SUM(t1.horas) AS HorasDesabasto
                FROM [TGV2].[dbo].[DesabastoHoras] t1
                LEFT JOIN [TG].[dbo].Estaciones t2 ON t1.id_estacion = t2.Codigo
                WHERE t1.id_producto=?
                GROUP BY CONCAT(YEAR(t1.[fecha_desabasto]), '-', FORMAT(MONTH(t1.[fecha_desabasto]), '00')), 
                        t2.Nombre
            ) pvt
            PIVOT (
                SUM(HorasDesabasto)
                FOR NombreGasolinera IN ($pivotInClause)
            ) AS pvt
            ON ds.mes = pvt.mes
            ORDER BY ds.mes DESC;
        ";

        $params = [
            $id_producto
        ];
        return ($this->sql->select($query, $params)) ?: false;
    }


}