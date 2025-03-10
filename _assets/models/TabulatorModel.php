<?php
class TabulatorModel extends Model{
    public $Id;
    public $CodigoEstacion;
    public $FechaTabular;
    public $Turno;
    public $Usuario;
    public $Total;
    public $Estatus;
    public $FechaCreacion;
    public $FechaCierre;

    /**
     * @return array|false
     * @throws Exception
     */
    public function get_tabulators() : array|false {
        $query = 'SELECT t1.*, t2.Nombre Estacion
                        FROM [TG].[dbo].[TabuladorEncabezado] t1
                        LEFT JOIN [TG].[dbo].[Estaciones] t2 ON t1.CodigoEstacion = t2.Codigo
                    ORDER BY Id DESC
                    ;';
        $params = [];
        return ($this->sql->select($query,$params)) ?: false ;
    }

    /**
     * @param $tab_id
     * @return array|false
     * @throws Exception
     */
    public function get_tabulator($tab_id) : array|false {
        return (($rs = $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_info_tabulador_completo]', [$tab_id])) ? $rs[0] : false );
    }

    /**
     * @param int $limit
     * @return array|false
     * @throws Exception
     */
    function all(int $limit = 1000, int $codgas = 0) : array|false {
        $query = "
            DECLARE @codgas INT = ?;
            SELECT TOP ({$limit})
                t1.Id
                ,t1.CodigoEstacion
                ,t2.Estacion
                ,t2.Denominacion
                ,t2.Nombre
                ,t1.FechaTabular
                ,t1.Turno
                ,t1.Usuario
                ,t1.Total
                , CASE
                    WHEN t1.Estatus = 1 THEN 'Abierto'
                    WHEN t1.Estatus = 2 THEN 'Cerrado'
                    ELSE 'Desconocido' -- Opcional, para manejar otros valores de Estatus
                END AS Estatus
                ,t1.FechaCreacion
                ,t1.FechaCierre
            FROM
                [TG].[dbo].[TabuladorEncabezado] t1
                LEFT JOIN [TG].[dbo].[Estaciones] t2 ON t1.CodigoEstacion = t2.Codigo
            WHERE (@codgas = 0 OR (t2.Codigo = @codgas))
            ORDER BY t1.Id DESC;
            ";
        return $this->sql->select($query, [$codgas]) ?: false;
    }

    /**
     * @param $CodigoEstacion
     * @param $FechaTabular
     * @param $Turno
     * @param $Usuario
     * @return int
     * @throws Exception
     */
    function add($CodigoEstacion, $FechaTabular, $Turno, $Usuario, $exchange_now, int $LimiteFajilla, int $LimiteRecolecta) : int {
        // Verificar que no exista un tabulador con la misma estacion, fecha y turno
        if ($this->sql->select('SELECT * FROM [TG].[dbo].[TabuladorEncabezado] WHERE CodigoEstacion = ? AND FechaTabular = ? AND Turno = ?;', [$CodigoEstacion, $FechaTabular, $Turno])) {
            return 2;
        }

        // Si no existe un tabulador con la misma fecha y turno, entonces lo creamos
        $query = 'INSERT INTO [TG].[dbo].[TabuladorEncabezado] (CodigoEstacion, FechaTabular, Turno, Usuario, Total, Estatus, TipoCambio, FechaCreacion, FechaCierre, LimiteFajilla, LimiteRecolecta, IdUsuario) VALUES (?, ?, ?, ?, 0, 1, ?, GETDATE(), NULL, ?, ?, ?);';
        return ($this->sql->insert($query, [$CodigoEstacion, $FechaTabular, $Turno, $Usuario, $exchange_now, $LimiteFajilla, $LimiteRecolecta, $_SESSION['tg_user']['Id']])) ? 1 : 0 ;
    }

    /**
     * @param $tabId
     * @return bool
     * @throws Exception
     */
    function delete_tabulator($tabId): bool
    {
        $query = 'DELETE FROM [TG].[dbo].[TabuladorEncabezado] WHERE Id = ?;';
        return ($this->sql->delete($query, [$tabId])) ? true : false ;
    }


    /**
     * @param $data
     * @param $operation
     * @return bool
     * @throws Exception
     */
    function updateTabuladorTotal($data, $operation = 'sumar') : bool {
        $query = "UPDATE [TG].[dbo].[TabuladorEncabezado] SET ";

        if ($operation === 'sumar') {
            $query .= "Total = (Total + ?)";
        } elseif ($operation === 'restar') {
            $query .= "Total = (Total - ?)";
        } elseif ($operation === 'igual') {
            $query .= "Total = ?";
        }

        $query .= " WHERE Id = ?;";
        $params = [$data['Monto'], $data['Id']];
        return (bool)$this->sql->update($query, $params);
    }

    function close_tabulator($tabuladorId) {
        return (bool)$this->sql->update('UPDATE [TG].[dbo].TabuladorEncabezado SET Estatus = 2, FechaCierre = GETDATE() WHERE Id = ?;', [intval($tabuladorId)]);
    }

    function open_tabulator($tabuladorId) {
        return (bool)$this->sql->update('UPDATE [TG].[dbo].TabuladorEncabezado SET Estatus = 1 WHERE Id = ?;', [intval($tabuladorId)]);
    }

    function is_closed_controlgas($turno, $fecha, $db_string) : bool {
        return (bool)$this->sql->select("SELECT 1 FROM {$db_string}.VentasC WHERE fch = ? AND nrotur = ?", [$fecha, $turno]);
    }

    function get_total_amounts_in_shift($tabId, $codgas, $fecha, $turno, $islands) : float | false {
        $query = "
        SELECT SUM(Total) AS TotalSum
        FROM (
            SELECT
                t1.Total
            FROM OPENQUERY({$this->linked_server[$codgas]}, N'
                SELECT
                    COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                FROM {$this->short_databases[$codgas]}.[MovimientosTar] t1
                    LEFT JOIN {$this->short_databases[$codgas]}.[Islas] t2 ON t1.codisl = t2.cod
                    LEFT JOIN {$this->short_databases[$codgas]}.[Valores] t3 ON t1.codbco = t3.cod
                WHERE
                    t1.fchmov = {$fecha} AND t1.codgas = {$codgas} AND t1.nrotur = {$turno} AND t1.codisl IN ({$islands}) AND NOT (t1.codbco = 0 AND t1.tiptar IN (84, 68, 67, 72))
                GROUP BY
                    t3.den, t1.codbco, t1.codisl, t2.den
        
                UNION
        
                SELECT
                    COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                FROM {$this->short_databases[$codgas]}.[Despachos] t1
                    LEFT JOIN {$this->short_databases[$codgas]}.[Clientes] t2 ON t1.codcli = t2.cod
                    LEFT JOIN {$this->short_databases[$codgas]}.[Islas] t4 ON t1.codisl = t4.cod
                WHERE t1.fchtrn = {$fecha} AND t1.codgas = {$codgas} AND t1.nrotur = {$turno} AND t1.codisl IN ({$islands}) AND t2.tipval IN(3,4)
                GROUP BY t1.fchtrn, t2.tipval, t1.codisl, t4.den
            ') AS t1
        
            UNION
        
            SELECT
                ISNULL(t2.Total, 0) AS Total
            FROM [SG12].[dbo].[Islas] t1
                LEFT JOIN (
                    SELECT
                        COALESCE(SUM(t1.Monto), 0) AS Total,
                        t1.Isla
                    FROM [TG].[dbo].[TabuladorDetalle] t1
                        RIGHT JOIN [SG12].[dbo].[Islas] t2 ON t1.Isla = t2.cod
                    WHERE
                        (t1.Id = {$tabId} AND t1.CodigoValor IN (6, 192) AND t1.Isla IN ({$islands}))
                    GROUP BY
                        t1.Isla, t1.CodigoValor
                ) t2 ON t1.cod = t2.Isla
            WHERE
                t1.cod IN ({$islands})
        ) AS CombinedTotals
        ";
        return $this->sql->select($query, [])[0]['TotalSum'] ?: false;
    }


    function get_totals_tab($tabId, $codgas, $fecha, $turno, $islands, $previous_date, $previous_shift) {

        $shifts = [11, 21, 31, 41];
        $index = array_search($previous_shift, $shifts);

        $next_shift = $shifts[($index + count($shifts) + 1) % count($shifts)];

        if ($next_shift == 11) {
            $next_date = ($previous_date + 1);
        } else {
            $next_date = $previous_date;
        }

        $query = "
            SELECT 
                SUM(total_ingresado) AS total_ingresado,
                SUM(total_ventas) AS total_ventas,
                SUM(total_ventas) - SUM(total_ingresado) AS saldo
            FROM (
                SELECT SUM(Total) AS total_ingresado, 0 AS total_ventas
                FROM (
                    SELECT
                        t1.Total
                    FROM OPENQUERY({$this->linked_server[$codgas]}, N'
                        SELECT
                            COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                        FROM {$this->short_databases[$codgas]}.[MovimientosTar] t1
                            LEFT JOIN {$this->short_databases[$codgas]}.[Islas] t2 ON t1.codisl = t2.cod
                            LEFT JOIN {$this->short_databases[$codgas]}.[Valores] t3 ON t1.codbco = t3.cod
                        WHERE
                            t1.fchmov = {$fecha} AND t1.codgas = {$codgas} AND t1.nrotur = {$turno} AND t1.codisl IN ({$islands}) AND NOT (t1.codbco = 0 AND t1.tiptar IN (84, 68, 67, 72))
                        GROUP BY
                            t3.den, t1.codbco, t1.codisl, t2.den
                
                        UNION ALL
                
                        SELECT
                            COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                        FROM {$this->short_databases[$codgas]}.[Despachos] t1
                            LEFT JOIN {$this->short_databases[$codgas]}.[Clientes] t2 ON t1.codcli = t2.cod
                            LEFT JOIN {$this->short_databases[$codgas]}.[Islas] t4 ON t1.codisl = t4.cod
                        WHERE t1.fchtrn = {$fecha} AND t1.codgas = {$codgas} AND t1.nrotur = {$turno} AND t1.codisl IN ({$islands}) AND t2.tipval IN(3,4)
                        GROUP BY t1.fchtrn, t2.tipval, t1.codisl, t4.den
                    ') AS t1
                
                    UNION ALL
                
                    SELECT
                        ISNULL(t2.Total, 0) AS Total
                    FROM [SG12].[dbo].[Islas] t1
                        LEFT JOIN (
                            SELECT
                                COALESCE(SUM(t1.Monto), 0) AS Total,
                                t1.Isla
                            FROM [TG].[dbo].[TabuladorDetalle] t1
                                RIGHT JOIN [SG12].[dbo].[Islas] t2 ON t1.Isla = t2.cod
                            WHERE
                                (t1.Id = {$tabId} AND t1.CodigoValor IN (6, 192) AND t1.Isla IN ({$islands}))
                            GROUP BY
                                t1.Isla, t1.CodigoValor
                        ) t2 ON t1.cod = t2.Isla
                    WHERE
                        t1.cod IN ({$islands})
                ) AS CombinedTotals
            
                UNION ALL
            
                SELECT 0 AS total_ingresado, SUM(finalAmount) AS total_ventas
                FROM OPENQUERY({$this->linked_server[$codgas]}, '
                    SELECT
                        SUM(t2.amount) AS finalAmount
                    FROM
                        {$this->short_databases[$codgas]}.[Medicion] t1
                        LEFT JOIN (
                            SELECT
                                nrobom,
                                COALESCE(SUM(can), 0) AS volume,
                                COALESCE(SUM(CASE WHEN tiptrn NOT IN (74, 65) THEN mto ELSE 0 END), 0) AS amount,
                                codprd,
                                codisl
                            FROM {$this->short_databases[$codgas]}.[Despachos]
                            WHERE
                                fchcor = {$next_date}
                                AND nrotur = {$next_shift}
                                AND codprd IN (1,2,3,179,180,181,192,193)
                                AND codisl IN ({$islands})
                            GROUP BY nrobom, codprd, codisl
                        ) t2 ON t1.nrobom = t2.nrobom AND t1.codprd = t2.codprd
                        WHERE
                            t1.fch = {$previous_date}
                            AND t1.nrotur = {$previous_shift}
                            AND t1.codisl IN ({$islands})
                    UNION ALL
                    SELECT
                        SUM(t1.mto) AS finalAmount
                    FROM {$this->short_databases[$codgas]}.[Despachos] t1
                        LEFT JOIN {$this->short_databases[$codgas]}.[Clientes] t2 ON t1.codcli = t2.cod
                        LEFT JOIN {$this->short_databases[$codgas]}.[ClientesValores] t3 ON t2.cod = t3.codcli
                        LEFT JOIN {$this->short_databases[$codgas]}.[Islas] t4 ON t1.codisl = t4.cod
                        LEFT JOIN {$this->short_databases[$codgas]}.[Productos] t5 ON t1.codprd = t5.cod
                    WHERE
                        t1.fchcor = {$next_date}
                        AND t1.nrotur = {$next_shift}
                        AND t1.codisl IN ({$islands})
                        AND t1.codprd NOT IN (0,179,180,181,192,193)
                ')
            ) AS CombinedResults;
        ";

        return $this->sql->select($query, [])[0] ?: false;
    }

    function get_totals_comparison($tabId, $codgas, $fecha, $turno, $islands) {
        // Configuración de la conexión
        $linkedServer = $this->linked_server[$codgas];
        $dbName = $this->short_databases[$codgas];

        return (($rs = $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_totales_comparacion]', [$tabId, $codgas, $fecha, $turno, $islands, $linkedServer, $dbName])) ? $rs[0] : false );

//        // Definir los turnos y calcular turno anterior y siguiente
//        $shifts = [11, 21, 31, 41];
//        $currentIndex = array_search($turno, $shifts);
//
//        // Calcular turno anterior y su fecha
//        $previousIndex = ($currentIndex + count($shifts) - 1) % count($shifts);
//        $previousShift = $shifts[$previousIndex];
//        $previousDate = ($previousShift == 41 && $turno == 11) ? ($fecha - 1) : $fecha;
//
//        // Calcular turno siguiente y su fecha
//        $nextIndex = ($currentIndex) % count($shifts);
//        $nextShift = $shifts[$nextIndex];
//        $nextDate = ($nextShift == 11 && $turno == 41) ? ($fecha + 1) : $fecha;
//
//        // Configuración de la conexión
//        $linkedServer = $this->linked_server[$codgas];
//        $dbName = $this->short_databases[$codgas];
//
//        // Consulta para obtener total ingresado
//        $totalIngresadoQuery = "
//        SELECT SUM(total) AS total_ingresado FROM (
//            -- Movimientos de tarjetas
//            SELECT COALESCE(query_result.mto_total, 0) AS total
//            FROM OPENQUERY({$linkedServer}, '
//                SELECT SUM(mto) AS mto_total
//                FROM {$dbName}.[MovimientosTar]
//                WHERE fchmov = {$fecha}
//                  AND codgas = {$codgas}
//                  AND nrotur = {$turno}
//                  AND codisl IN ({$islands})
//                  AND NOT (codbco = 0 AND tiptar IN (84, 68, 67, 72))
//            ') AS query_result
//
//            UNION ALL
//
//            -- Despachos a clientes específicos
//            SELECT COALESCE(query_result.mto_total, 0) AS total
//            FROM OPENQUERY({$linkedServer}, '
//                SELECT SUM(d.mto) AS mto_total
//                FROM {$dbName}.[Despachos] d
//                JOIN {$dbName}.[Clientes] c ON d.codcli = c.cod
//                WHERE d.fchtrn = {$fecha}
//                  AND d.codgas = {$codgas}
//                  AND d.nrotur = {$turno}
//                  AND d.codisl IN ({$islands})
//                  AND c.tipval IN(3,4)
//            ') AS query_result
//
//            UNION ALL
//
//            -- Valores del tabulador
//            SELECT COALESCE(SUM(t1.Monto), 0) AS total
//            FROM [TG].[dbo].[TabuladorDetalle] t1
//            WHERE t1.Id = {$tabId}
//              AND t1.CodigoValor IN (6, 192)
//              AND t1.Isla IN ({$islands})
//        ) AS ingresos";
//
//        // Consulta para obtener total ventas
//        $totalVentasQuery = "
//        SELECT SUM(total) AS total_ventas FROM (
//            -- Mediciones entre turnos
//            SELECT COALESCE(query_result.amount_total, 0) AS total
//            FROM OPENQUERY({$linkedServer}, '
//                SELECT SUM(med_desp.amount) AS amount_total
//                FROM {$dbName}.[Medicion] m
//                JOIN (
//                    SELECT
//                        nrobom,
//                        codprd,
//                        SUM(CASE WHEN tiptrn NOT IN (74, 65) THEN mto ELSE 0 END) AS amount
//                    FROM {$dbName}.[Despachos]
//                    WHERE fchcor = {$nextDate}
//                      AND nrotur = {$nextShift}
//                      AND codprd IN (1,2,3,179,180,181,192,193)
//                      AND codisl IN ({$islands})
//                    GROUP BY nrobom, codprd
//                ) med_desp ON m.nrobom = med_desp.nrobom AND m.codprd = med_desp.codprd
//                WHERE m.fch = {$previousDate}
//                  AND m.nrotur = {$previousShift}
//                  AND m.codisl IN ({$islands})
//            ') AS query_result
//
//            UNION ALL
//
//            -- Otros productos vendidos
//            SELECT COALESCE(query_result.mto_total, 0) AS total
//            FROM OPENQUERY({$linkedServer}, '
//                SELECT SUM(mto) AS mto_total
//                FROM {$dbName}.[Despachos]
//                WHERE fchcor = {$nextDate}
//                  AND nrotur = {$nextShift}
//                  AND codisl IN ({$islands})
//                  AND codprd NOT IN (0,179,180,181,192,193)
//            ') AS query_result
//        ) AS ventas";
//
//        // Ejecutar consultas
//        $totalIngresado = $this->sql->select($totalIngresadoQuery, [])[0]['total_ingresado'] ?? 0;
//        $totalVentas = $this->sql->select($totalVentasQuery, [])[0]['total_ventas'] ?? 0;
//
//        // Calcular saldo y asegurar precisión
//        $saldo = $totalVentas - $totalIngresado;
//
//        return [
//            'total_ingresado' => number_format($totalIngresado, 2, '.', ''),
//            'total_ventas' => number_format($totalVentas, 2, '.', ''),
//            'saldo' => number_format($saldo, 2, '.', '')
//        ];
    }

    function check_turno_status($turno, $fecha, $codgas) : bool {
        $query = "SELECT * FROM [TG].[dbo].[TabuladorEncabezado] WHERE FechaTabular = '{$fecha}' AND Turno = {$turno} AND CodigoEstacion = {$codgas} AND Estatus = ?";
        return (bool)$this->sql->select($query, [1]);
    }
}