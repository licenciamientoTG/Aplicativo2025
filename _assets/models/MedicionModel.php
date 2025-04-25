<?php
class MedicionModel extends Model{
    public $fch;
    public $nrotur;
    public $codgas;
    public $nrobom;
    public $graprd;
    public $codprd;
    public $codisl;
    public $mto;
    public $can;
    public $jar;
    public $mtoacu;
    public $canacu;
    public $ent;
    public $logusu;
    public $logfch;
    public $lognew;

    /**
     * @param $CodigoEstacion
     * @param $Fecha
     * @param $Turno
     * @return array|false
     * @throws Exception
     */
    public function get_measurements_by_tabulator($CodigoEstacion, $Fecha, $Turno) : array|false{
        $query = "SELECT t1.codgas 'Gasolinera'
                        , t3.cod 'CodIsla'
                        , t3.den 'Isla'
                        , t1.nrobom 'Bomba'
                        , t1.codprd 'CodProducto'
                        , t2.den 'Producto'
                        , ROUND(SUM(t1.canacu), 3) 'LecturaFinalElectronica'
                        , ROUND(SUM(t1.mto), 2) 'ImporteLecturaElectronica'
                    FROM {$this->databases[$CodigoEstacion]}.Medicion t1
                        LEFT JOIN {$this->databases[$CodigoEstacion]}.Productos t2 ON t1.codprd = t2.cod
                        LEFT JOIN {$this->databases[$CodigoEstacion]}.Islas t3  ON t1.codisl = t3.cod
                    WHERE t1.codgas = ?
                        AND t1.fch = DATEDIFF(dd, 0, ?) + 1
                        AND t1.nrotur = ?
                    GROUP BY t1.codgas, t1.nrobom, t1.codprd, t2.den, t3.cod, t3.den;";
        $params = [$CodigoEstacion, $Fecha, $Turno];
        return ($this->sql->select($query,$params)) ?: false ;
    }

    /**
     * @param $CodigoEstacion
     * @param $fch
     * @param $nrotur
     * @return array|false
     * @throws Exception
     */
    function getInitialReadings($CodigoEstacion, $fch, $nrotur) : array|false {

        $shifts = [11, 21, 31, 41];
        $index = array_search($nrotur, $shifts);

        $next_shift = $shifts[($index + count($shifts) + 1) % count($shifts)];

        if ($next_shift == 11) {
            $next_date = date('Ymd', strtotime('+1 day', strtotime($fch)));
        } else {
            $next_date = $fch;
        }

        $query = "SELECT
                    t1.nrobom,
                    CASE
                        WHEN t1.codprd IN (179,192) THEN 'T-Máxima Regular'
                        WHEN t1.codprd IN (180,193) THEN 'T-Super Premium'
                        WHEN t1.codprd = 181 THEN 'Diesel Automotriz'
                    END AS Producto,
					t1.codprd,
					ROUND(t1.canacu, 3) initialElectronicReading,
					ROUND(t1.mtoacu, 2) amount,
					t2.volume finalElectronicReading,
					t2.amount finalAmount,
					ROUND(t1.canacu - t2.volume, 3) difference,
					ROUND(t1.mtoacu - t2.amount, 2) amountDifference
                FROM
                    {$this->databases[$CodigoEstacion]}.[Medicion] t1
                    LEFT JOIN (
                        SELECT nrobom, SUM(can) 'volume', SUM(mto) 'amount', codprd
                        FROM {$this->databases[$CodigoEstacion]}.[Despachos]
                        WHERE
                            fchcor = ?
                            AND nrotur = ?
                            AND codprd IN(1,2,3,179,180,181,192,193)
                        GROUP BY nrobom, codprd
                    ) t2 ON t1.nrobom = t2.nrobom AND t1.codprd = t2.codprd
                WHERE
                    t1.fch = ? AND t1.nrotur = ?;";
        $params = [$next_date, $next_shift, $fch, $nrotur];
        return ($this->sql->select($query, $params)) ?: false ;
    }

    /**
     * @param $CodigoEstacion
     * @param $fch
     * @param $nrotur
     * @param $codisl
     * @return array|false
     * @throws Exception
     */
    function getInitialReadingsByIsland($CodigoEstacion, $fch, $nrotur, $codisl) : array|false {

        $shifts = [11, 21, 31, 41];
        $index = array_search($nrotur, $shifts);

        $next_shift = $shifts[($index + count($shifts) + 1) % count($shifts)];

        if ($next_shift == 11) {
            $next_date = date('Ymd', strtotime('+1 day', strtotime($fch)));
        } else {
            $next_date = $fch;
        }

        $query = "SELECT
                    t1.nrobom,
                    CASE
                        WHEN t1.codprd IN (179,192) THEN 'T-Máxima Regular'
                        WHEN t1.codprd IN (180,193) THEN 'T-Super Premium'
                        WHEN t1.codprd = 181 THEN 'Diesel Automotriz'
                    END AS Producto,
					t1.codprd,
					ROUND(t1.canacu, 3) initialElectronicReading,
					ROUND(t1.mtoacu, 2) amount,
					t2.volume finalElectronicReading,
					t2.amount finalAmount,
					ROUND(t1.canacu - t2.volume, 3) difference,
					ROUND(t1.mtoacu - t2.amount, 2) amountDifference
                FROM
                    {$this->databases[$CodigoEstacion]}.[Medicion] t1
                    LEFT JOIN (
                        SELECT nrobom, SUM(can) 'volume', SUM(mto) 'amount', codprd
                        FROM {$this->databases[$CodigoEstacion]}.[Despachos]
                        WHERE
                            fchcor = ?
                            AND nrotur = ?
                            AND codprd IN(1,2,3,179,180,181,192,193)
                            AND codisl = ?
                        GROUP BY nrobom, codprd
                    ) t2 ON t1.nrobom = t2.nrobom AND t1.codprd = t2.codprd
                WHERE
                    t1.fch = ? AND t1.nrotur = ? AND t1.codisl = ?;";

        $params = [$next_date, $next_shift, $codisl, $fch, $nrotur, $codisl];
        return ($this->sql->select($query, $params)) ?: false ;
    }

    /**
     * @param $CodigoEstacion
     * @param $fch
     * @param $nrotur
     * @param $codisl
     * @return array|false
     * @throws Exception
     */
    function getInitialReadingsByIslands($CodigoEstacion, $fch, $nrotur, $Islands) : array|false {

        $shifts = [11, 21, 31, 41];
        $index = array_search($nrotur, $shifts);

        $next_shift = $shifts[($index + count($shifts) + 1) % count($shifts)];

        if ($next_shift == 11) {
            $next_date = ($fch + 1);
        } else {
            $next_date = $fch;
        }

        $query = "
                SELECT *
                FROM OPENQUERY({$this->linked_server[$CodigoEstacion]}, '
                    SELECT
                        t1.nrobom,
                        CASE
                            WHEN t1.codprd IN (179, 192) THEN N''T-Máxima Regular''
                            WHEN t1.codprd IN (180, 193) THEN N''T-Super Premium''
                            WHEN t1.codprd = 181 THEN N''Diesel Automotriz''
                        END AS Producto,
                        t1.codprd,
                        ROUND(t1.canacu, 3) initialElectronicReading,
                        ROUND(t1.mtoacu, 2) amount,
                       case 
							WHEN t1.mtoacu  <1  THEN 0
							else  t2.volume
						END AS finalElectronicReading,
						--t2.volume finalElectronicReading,
						case 
							WHEN t1.mtoacu <1 THEN 0
							else  t2.amount
						END AS finalAmount,
						--t2.amount finalAmount,
						case 
							WHEN t1.mtoacu  <1  THEN 0
							else   ROUND(t1.canacu - t2.volume, 3)
						END AS [difference],
						--ROUND(t1.canacu - t2.volume, 3) difference,
						case 
							WHEN t1.mtoacu  <1  THEN 0
							else   ROUND(t1.mtoacu - t2.amount, 2) 
						END AS [amountDifference],
						--ROUND(t1.mtoacu - t2.amount, 2) amountDifference,
                        t2.codisl
                    FROM
                        {$this->short_databases[$CodigoEstacion]}.[Medicion] t1
                        LEFT JOIN (
                            SELECT nrobom, COALESCE(SUM(can), 0) AS volume, COALESCE(SUM(CASE WHEN tiptrn NOT IN (74, 65) THEN mto ELSE 0 END), 0) AS amount, codprd, codisl
                            FROM {$this->short_databases[$CodigoEstacion]}.[Despachos]
                            WHERE
                                fchcor = {$next_date}
                                AND nrotur = {$next_shift}
                                AND codprd IN(1,2,3,179,180,181,192,193)
                                AND codisl IN({$Islands})
                            GROUP BY nrobom, codprd, codisl
                        ) t2 ON t1.nrobom = t2.nrobom AND t1.codprd = t2.codprd
                    WHERE
                        t1.fch = {$fch} AND t1.nrotur = {$nrotur} AND  t1.codisl IN($Islands)  
                
                    UNION ALL
                
                    SELECT
                        t1.nrobom, t5.den Producto, t1.codprd, 0 AS initialElectronicReading, t1.mto AS amount, 0 AS finalElectronicReading, t1.mto finalAmount, 0 AS difference, 0 AS amountDifference, t1.codisl
                    FROM {$this->short_databases[$CodigoEstacion]}.[Despachos] t1
                        LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[Clientes] t2 ON t1.codcli = t2.cod
                        LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[ClientesValores] t3 ON t2.cod = t3.codcli
                        LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[Islas] t4 ON t1.codisl = t4.cod
                        LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[Productos] t5 ON t1.codprd = t5.cod
                    WHERE t1.fchcor = {$next_date} AND t1.nrotur = {$next_shift} AND t1.codisl IN ({$Islands}) AND t1.codprd NOT IN (0,179,180,181,192,193)
                ')
            ";
            // echo '<pre>';
            // var_dump($query);
            // die();

        return ($this->sql->select($query, [])) ?: false ;
    }


    function get_total_sales_in_shift($CodigoEstacion, $fch, $nrotur, $Islands) {

        $shifts = [11, 21, 31, 41];
        $index = array_search($nrotur, $shifts);

        $next_shift = $shifts[($index + count($shifts) + 1) % count($shifts)];

        if ($next_shift == 11) {
            $next_date = ($fch + 1);
        } else {
            $next_date = $fch;
        }

        $query = "
            SELECT SUM(finalAmount) AS TotalFinalAmount
            FROM OPENQUERY({$this->linked_server[$CodigoEstacion]}, '
                SELECT
                    SUM(t2.amount) AS finalAmount
                FROM7
                    {$this->short_databases[$CodigoEstacion]}.[Medicion] t1
                    LEFT JOIN (
                        SELECT
                            nrobom,
                            COALESCE(SUM(can), 0) AS volume,
                            COALESCE(SUM(CASE WHEN tiptrn NOT IN (74, 65) THEN mto ELSE 0 END), 0) AS amount,
                            codprd,
                            codisl
                        FROM {$this->short_databases[$CodigoEstacion]}.[Despachos]
                        WHERE
                            fchcor = {$next_date}
                            AND nrotur = {$next_shift}
                            AND codprd IN (1,2,3,179,180,181,192,193)
                            AND codisl IN ({$Islands})
                        GROUP BY nrobom, codprd, codisl
                    ) t2 ON t1.nrobom = t2.nrobom AND t1.codprd = t2.codprd
                    WHERE
                        t1.fch = {$fch}
                        AND t1.nrotur = {$nrotur}
                        AND t1.codisl IN ({$Islands})
                UNION ALL
                SELECT
                    SUM(t1.mto) AS finalAmount
                FROM {$this->short_databases[$CodigoEstacion]}.[Despachos] t1
                    LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[Clientes] t2 ON t1.codcli = t2.cod
                    LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[ClientesValores] t3 ON t2.cod = t3.codcli
                    LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[Islas] t4 ON t1.codisl = t4.cod
                    LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[Productos] t5 ON t1.codprd = t5.cod
                WHERE
                    t1.fchcor = {$next_date}
                    AND t1.nrotur = {$next_shift}
                    AND t1.codisl IN ({$Islands})
                    AND t1.codprd NOT IN (0,179,180,181,192,193)
            ')
        ";
        return ($this->sql->select($query, []))[0]['TotalFinalAmount'] ?: false ;
    }

    function getInitialReadingsByIslands2($CodigoEstacion, $fch, $nrotur, $Islands) {
        $shifts = [11, 21, 31, 41];
        $index = array_search($nrotur, $shifts);

        $next_shift = $shifts[($index + count($shifts) + 1) % count($shifts)];

        if ($next_shift == 11) {
            $next_date = ($fch + 1);
        } else {
            $next_date = $fch;
        }

        $query = "
            SELECT
                codisl,
                SUM(finalAmount) AS finalAmount
            FROM OPENQUERY({$this->linked_server[$CodigoEstacion]}, '
                SELECT
                    COALESCE(t2.amount, 0) AS finalAmount,
                    t2.codisl
                FROM
                    {$this->short_databases[$CodigoEstacion]}.[Medicion] t1
                    LEFT JOIN (
                        SELECT nrobom, COALESCE(SUM(can), 0) AS volume, COALESCE(SUM(CASE WHEN tiptrn NOT IN (74, 65) THEN mto ELSE 0 END), 0) AS amount, codprd, codisl
                        FROM {$this->short_databases[$CodigoEstacion]}.[Despachos]
                        WHERE
                            fchcor = {$next_date}
                            AND nrotur = {$next_shift}
                            AND codprd IN (1,2,3,179,180,181,192,193)
                            AND codisl IN ({$Islands})
                        GROUP BY nrobom, codprd, codisl
                    ) t2 ON t1.nrobom = t2.nrobom AND t1.codprd = t2.codprd
                WHERE
                    t1.fch = {$fch} AND t1.nrotur = {$nrotur} AND t1.codisl IN ({$Islands})
                
                UNION ALL
                
                SELECT
                    COALESCE(t1.mto, 0) AS finalAmount, 
                    t1.codisl
                FROM {$this->short_databases[$CodigoEstacion]}.[Despachos] t1
                WHERE t1.fchcor = {$next_date} AND t1.nrotur = {$next_shift} AND t1.codisl IN ({$Islands}) AND t1.codprd NOT IN (0,179,180,181,192,193)
            ') AS SubQuery
            WHERE codisl IS NOT NULL
            GROUP BY codisl;
        ";

        return ($this->sql->select($query, [])) ?: false ;
    }


}