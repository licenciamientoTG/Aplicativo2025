<?php
class MovimientosTarModel extends Model{

    public $fchmov;
    public $nromov;
    public $codgas;
    public $nroitm;
    public $fchlog;
    public $nrotrn;
    public $nrotar;
    public $nroter;
    public $nroref;
    public $trxcod;
    public $trxmsg;
    public $nroaut;
    public $mto;
    public $fchapl;
    public $tiptar;
    public $tipmov;
    public $estmov;
    public $codbco;
    public $codres;
    public $fchcor;
    public $nrotur;
    public $lotfch;
    public $lotnro;
    public $lottrn;
    public $mdacod;
    public $mdactz;
    public $mdamto;
    public $logexp;
    public $codisl;
    public $mtoprp;
    public $datref;
    public $datrsp;

    /**
     * @param $codgas
     * @param $intDate
     * @param $nrotur
     * @return float|false
     * @throws Exception
     */
    public function get_total_dolares_by_tab($codgas, $intDate, $nrotur) : float|false {
        $query = "SELECT * FROM OPENQUERY({$this->linked_server[$codgas]}, 'SELECT COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                    FROM
                        {$this->short_databases[$codgas]}.[MovimientosTar] t1
                        LEFT JOIN {$this->short_databases[$codgas]}.[Bancos] t2 ON t1.codbco = t2.cod
                    WHERE
                        t1.codgas = {$codgas}
                        AND t1.fchmov = {$intDate}
                        AND t1.nrotur = {$nrotur}
                        AND t2.cod IN(-1128);')";
        return ($rs=$this->sql->select($query,[])) ? $rs[0]['Total'] : false ;
    }

    /**
     * @param $fchmov
     * @param $codgas
     * @param $ValorButt_Id
     * @param $nrotrn
     * @param $nrotar
     * @param $nroter
     * @param $nroref
     * @param $nroaut
     * @param $mto
     * @param $nrotur
     * @param $codbco
     * @param $codisl
     * @return bool
     * @throws Exception
     */
    function add($fchmov, $codgas, $ValorButt_Id, $nrotrn, $nrotar, $nroter, $nroref, $nroaut, $mto, $nrotur, $codbco, $codisl) : bool{
        // Aqui vamos a obtener el numero consecutivo de la tabla del campo llamado `nromov`
        $nromov = $this->sql->select("SELECT ISNULL(MAX(nromov), 0) + 1 AS sec FROM {$this->databases[$codgas]}.[MovimientosTar] WHERE fchmov = ?", [$fchmov])[0]['sec'];

        $nroitm = 1;
        $trxcod = 'TD-MANUAL';
        $tiptar = 102;

        if (in_array($ValorButt_Id, [13, 14, 15, 24, 26])) { // Efecticar, TicketCar, Inburgas, Ultragas, Endenred
            $nroitm = 1;
            $nrotar = 1;
            $trxcod = 'TD-MANUAL';
            $tiptar = 102;
        } else if(in_array($ValorButt_Id, [20, 21])) { // Banorte, Santander
            // Aqui vamos a obtener el consecutivo de
            $nroitm = $this->sql->select("SELECT ISNULL(MAX(nroitm), 0) + 1 AS sec FROM {$this->databases[$codgas]}.[MovimientosTar] WHERE fchmov = ? AND nroitm > 200000000", [$fchmov])[0]['sec'];
            $nrotar = 0;
            $trxcod = 0;
            $tiptar = 68;
        }

        // Aqui vamos a obtener el numero consecutivo de la tabla del campo llamado `nromov`
        $query = "INSERT INTO {$this->databases[$codgas]}.[MovimientosTar]
            ([fchmov],[nromov],[codgas],[nroitm],[fchlog],[nrotrn],[nrotar],[nroter],[nroref],[trxcod],[nroaut],[mto],[fchapl],[tiptar],[tipmov],
            [estmov],[codbco],[codres],[fchcor],[nrotur],[lotfch],[lotnro],[lottrn],[mdacod],[mdactz],[mdamto],[logexp],[codisl],[mtoprp])
        VALUES
            (?,?,?,?,GETDATE(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,GETDATE(),?,?);
        ";
        $params = [$fchmov, $nromov, $codgas, $nroitm, $nrotrn, $nrotar, $nroter, $nroref, $trxcod, $nroaut, $mto, 0, $tiptar, 65,
            0, $codbco, 0, $fchmov, $nrotur, 0, 0, 0, 0, 0, 0, $codisl, 0];
        return (bool)$this->sql->insert($query, $params);
    }

    function dismark_dispatch_station($codest, $nrotrn) : bool {
        $query = "DELETE FROM {$this->databases[$codest]}.[MovimientosTar] WHERE nrotrn = ?;";
        $params = [$nrotrn];
        return (bool)$this->sql->delete($query, $params);
    }

    function dismark_dispatch_central($codest, $nrotrn) : bool {
        $query = "DELETE FROM [SG12].[dbo].[MovimientosTar] WHERE nrotrn = ? AND codgas = ?";
        $params = [$nrotrn, $codest];
        return (bool)$this->sql->delete($query, $params);
    }

    function get_total_by_island(int $fchmov, int $codgas, int $codisl, int $turno, int $tabId) : array |false {
        $query = "
                SELECT 
                    CASE
                        WHEN t1.codbco = '-3505' THEN 'INTERL Tarjeta American Express'
                        WHEN t1.codbco = '-3504' THEN N'INTERL Tarjeta de Débito'
                        WHEN t1.codbco = '-3503' THEN 'SMARTBT BROXEL'
                        WHEN t1.codbco = '-3501' THEN 'INTERL - Efectivo'
                        WHEN t1.codbco = '-3530' THEN 'SMARTBT BROXEL'
                        WHEN t1.codbco = '-3020' THEN 'SMARTBT INBURGAS'
                        WHEN t1.codbco = '-3015' THEN 'SODEXO WISEO'
                        WHEN t1.codbco = '-3010' THEN 'SMARTBT Efectivale'
                        WHEN t1.codbco = '-3006' THEN 'SMARTBT EDENRED'
                        WHEN t1.codbco = '-3005' THEN 'SMARTBT SODEXO'
                        WHEN t1.codbco = '-3002' THEN 'SMARTBT American Express'
                        WHEN t1.codbco = '-3001' THEN 'SMARTBT Bancarias'
                        WHEN t1.codbco = '-2101' THEN 'SANTANDER MIT'
                        WHEN t1.codbco = '-1128' THEN 'Dolares CG'
                        WHEN t1.codbco = '-1008' THEN 'AMERICAN EXPRESS'
                        WHEN t1.codbco = '-1004' THEN 'GASnGO MEXICO'
                        WHEN t1.codbco = '-1001' THEN 'BANORTE PAYWORKS'
                        WHEN t1.codbco = '5' THEN 'Efectivo USD'
                        WHEN t1.codbco = '6' THEN 'Efectivo'
                        WHEN t1.codbco = '11' THEN 'Diaz Gas, S.A. de C.V.'
                        WHEN t1.codbco = '28' THEN N'Crédito'
                        WHEN t1.codbco = '116' THEN 'Efectivo MN'
                        WHEN t1.codbco = '121' THEN 'Efectivo USD'
                        WHEN t1.codbco = '127' THEN 'Efectivo USD'
                        WHEN t1.codbco = '135' THEN 'Estacion Custodia Sa de Cv MN'
                        WHEN t1.codbco = '136' THEN 'Estacion Custodia Sa de Cv DLS'
                        WHEN t1.codbco = '137' THEN 'Estacion Custodia Sa de Cv TAR'
                        WHEN t1.codbco = '138' THEN 'Morralla MN'
                        WHEN t1.codbco = '141' THEN 'Bancomer'
                        WHEN t1.codbco = '145' THEN 'Promociones MKT'
                        WHEN t1.codbco = '192' THEN 'Morralla'
                        WHEN t1.codbco = '194' THEN 'Efecticard'
                        WHEN t1.codbco = '196' THEN 'Vale Endenred'
                        WHEN t1.codbco = '197' THEN 'Vales Efectivale'
                        WHEN t1.codbco = '198' THEN 'Vale SODEXO'
                        WHEN t1.codbco = '201' THEN 'Tarjeta TicketCar'
                        WHEN t1.codbco = '203' THEN 'SMARTBT - MANUAL Bancarias'
                        WHEN t1.codbco = '205' THEN 'Tarjeta Inburgas'
                        WHEN t1.codbco = '206' THEN 'Tarjetas Sodexo'
                        WHEN t1.codbco = '207' THEN 'Tarjetas Afirme'
                        WHEN t1.codbco = '208' THEN 'Tarjetas Scotiabank (Anterior)'
                        WHEN t1.codbco = '209' THEN 'Tarjetas American Express'
                        WHEN t1.codbco = '210' THEN 'Tarjetas Bancomer'
                        WHEN t1.codbco = '211' THEN 'Tarjetas Banorte'
                        WHEN t1.codbco = '212' THEN 'Tarjetas Santander'
                        WHEN t1.codbco = '213' THEN 'Tarjetas Scotiabank'
                        WHEN t1.codbco = '214' THEN 'Raspaditos'
                        WHEN t1.codbco = '215' THEN N'Custodio Dólares'
                        WHEN t1.codbco = '216' THEN 'Ultra Gas'
                        WHEN t1.codbco = '217' THEN 'Transferencias'
                        WHEN t1.codbco = '218' THEN 'Self-Service'
                        WHEN t1.codbco = '219' THEN 'Mobil FleetPro'
                        ELSE CAST(t1.codbco AS VARCHAR)
                    END AS ValorDescripcion,
                    COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                FROM {$this->databases[$codgas]}.[MovimientosTar] t1
                WHERE
                    t1.fchmov = ? AND t1.codgas = ? AND t1.nrotur = ? AND t1.codisl = ?
                GROUP BY
                    t1.codbco
                UNION
                SELECT
                    CASE
                        WHEN t3.codval = 28 THEN 'Crédito'
                        WHEN t3.codval = 127 THEN 'Débito'
                    ELSE
                            CAST(t3.codval AS VARCHAR)
                    END AS ValorDescripcion,
                    COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total 
                FROM {$this->databases[$codgas]}.[Despachos] t1
                    LEFT JOIN {$this->databases[$codgas]}.[Clientes] t2 ON t1.codcli = t2.cod
                    LEFT JOIN {$this->databases[$codgas]}.[ClientesValores] t3 ON t2.cod = t3.codcli
                WHERE t1.fchtrn = ? AND t1.codgas = ? AND t1.nrotur = ? AND t1.codisl = ? AND t3.codval IN(28,127)
                GROUP BY t1.fchtrn, t3.codval
                UNION
                SELECT
                    CASE
                        WHEN t1.CodigoValor = 6 THEN 'Efectivo Tabulador'
                        WHEN t1.CodigoValor = 192 THEN 'Morralla'
                    END AS ValorDescripcion,
                    COALESCE(CAST(SUM(t1.Monto) AS FLOAT), 0) AS Total
                FROM
                    [TG].[dbo].[TabuladorDetalle] t1
                WHERE
                    (t1.Id = ? AND t1.CodigoValor IN (6, 192) AND t1.Isla = ?)
                GROUP BY
                    t1.Isla, t1.CodigoValor;
        ";
        $params = [$fchmov, $codgas, $turno, $codisl, $fchmov, $codgas, $turno, $codisl, $tabId, $codisl];
        if ($rs = $this->sql->select($query, $params)) {
            return $rs;
        } else {
            return false;
        }
    }
    function get_total_islands(int $fchmov, int $codgas, $islands, int $turno, int $tabId) : array|false {

        $query = "
                SELECT *
                    FROM OPENQUERY({$this->linked_server[$codgas]}, N'
                        SELECT
                            DatosOrdenados.ValorDescripcion,
                            COALESCE(CAST(SUM(DatosOrdenados.mto) AS FLOAT), 0) AS Total,
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
                            FROM {$this->short_databases[$codgas]}.[MovimientosTar] t1
                                LEFT JOIN {$this->short_databases[$codgas]}.[Islas] t2 ON t1.codisl = t2.cod
                                LEFT JOIN {$this->short_databases[$codgas]}.[Valores] t3 ON t1.codbco = t3.cod
                            WHERE
                                t1.fchmov = {$fchmov}
                                AND t1.codgas = {$codgas}
                                AND t1.nrotur = {$turno}
                                AND t1.codisl IN ({$islands})
                                AND NOT (t1.codbco = 0 AND t1.tiptar IN (84, 68, 67, 72))
                        ) AS DatosOrdenados
                        WHERE DatosOrdenados.fila = 1
                        GROUP BY DatosOrdenados.ValorDescripcion, DatosOrdenados.codisl, DatosOrdenados.Isla
                    
                        UNION
                    
                        SELECT
                            CASE
                                WHEN t2.tipval = 3 THEN N''Crédito''
                                WHEN t2.tipval = 4 THEN N''Débito''
                                ELSE
                                    CAST(t2.tipval AS VARCHAR)
                            END COLLATE Modern_Spanish_CI_AS AS ValorDescripcion,
                            COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total,
                            t1.codisl,
                            t4.den COLLATE Modern_Spanish_CI_AS AS Isla
                        FROM {$this->short_databases[$codgas]}.[Despachos] t1
                            LEFT JOIN {$this->short_databases[$codgas]}.[Clientes] t2 ON t1.codcli = t2.cod
                            LEFT JOIN {$this->short_databases[$codgas]}.[Islas] t4 ON t1.codisl = t4.cod
                        WHERE t1.fchtrn = {$fchmov} AND t1.codgas = {$codgas} AND t1.nrotur = {$turno} AND t1.codisl IN ({$islands}) AND t2.tipval IN(3,4)
                        GROUP BY t1.fchtrn, t2.tipval, t1.codisl, t4.den
                    ')
                    
                    UNION
                                
                    SELECT
                        ISNULL(t2.ValorDescripcion, 'Tabulado') COLLATE Modern_Spanish_CI_AS AS ValorDescripcion,
                        ISNULL(t2.Total, 0) AS Total, t1.cod Isla, t1.den COLLATE Modern_Spanish_CI_AS AS IslaNombre
                    FROM
                        [SG12].[dbo].[Islas] t1
                        LEFT JOIN (
                            SELECT
                                CASE
                                    WHEN t1.CodigoValor = 6 THEN 'Efectivo Tabulador'
                                    WHEN t1.CodigoValor = 192 THEN 'Morralla'
                                END COLLATE Modern_Spanish_CI_AS AS ValorDescripcion,
                                COALESCE(SUM(t1.Monto), 0) AS Total,
                                t1.Isla,
                                t2.den COLLATE Modern_Spanish_CI_AS AS IslaNombre
                            FROM
                                [TG].[dbo].[TabuladorDetalle] t1
                                RIGHT JOIN [SG12].[dbo].[Islas] t2 ON t1.Isla = t2.cod
                            WHERE
                                (t1.Id = {$tabId} AND t1.CodigoValor IN (6, 192) AND t1.Isla IN ($islands))
                            GROUP BY
                                t1.Isla, t2.den, t1.CodigoValor
                        ) t2 ON t1.cod = t2.Isla
                    WHERE
                        t1.cod IN ({$islands})
                    ORDER BY Isla, ValorDescripcion DESC;
                ";

        if ($rs = $this->sql->select($query, [])) {
            return $rs;
        } else {
            return false;
        }
    }

    function get_total_products(int $fchmov, int $codgas, $islands, int $turno, int $tabId) : array|false {
        $query = "
            SELECT
                t1.nrobom, t1.nrotrn, t5.den ValorDescripcion, (t1.can * t1.mto) Total, t1.codisl, t4.den Isla
            FROM {$this->databases[$codgas]}.[Despachos] t1
                LEFT JOIN {$this->databases[$codgas]}.[Clientes] t2 ON t1.codcli = t2.cod
                LEFT JOIN {$this->databases[$codgas]}.[ClientesValores] t3 ON t2.cod = t3.codcli
                LEFT JOIN {$this->databases[$codgas]}.[Islas] t4 ON t1.codisl = t4.cod
                LEFT JOIN {$this->databases[$codgas]}.[Productos] t5 ON t1.codprd = t5.cod
            WHERE t1.fchtrn = ? AND t1.codgas = ? AND t1.nrotur = ? AND t1.codisl IN ($islands) AND t1.codprd NOT IN (0,179,180,181,192,193)
        ";
        $params = [$fchmov, $codgas, $turno, $fchmov, $codgas, $turno, $tabId, $fchmov, $codgas, $turno];
        if ($rs = $this->sql->select($query, $params)) {
            return $rs;
        } else {
            return false;
        }
    }

    function get_total_by_tabulator(int $fchmov, int $codgas, int $turno, int $tabId) : array|false {
        $query = "
            SELECT * FROM OPENQUERY({$this->linked_server[$codgas]}, 'SELECT
                            DatosOrdenados.ValorDescripcion COLLATE Modern_Spanish_CI_AS AS ValorDescripcion,
                            COALESCE(CAST(SUM(DatosOrdenados.mto) AS FLOAT), 0) AS Total
                        FROM (
                            SELECT
                                t3.den COLLATE Modern_Spanish_CI_AS AS ValorDescripcion,
                                t1.mto,
                                t1.codisl,
                                ROW_NUMBER() OVER (
                                    PARTITION BY t1.nrotrn
                                    ORDER BY t1.fchlog DESC
                                ) AS fila
                            FROM {$this->short_databases[$codgas]}.[MovimientosTar] t1
                                LEFT JOIN {$this->short_databases[$codgas]}.[Valores] t3 ON t1.codbco = t3.cod
                            WHERE
                                t1.fchmov = {$fchmov}
                                AND t1.codgas = {$codgas}
                                AND t1.nrotur = {$turno}
                                AND NOT (t1.codbco = 0 AND t1.tiptar IN (84, 68, 67, 72))
                        ) AS DatosOrdenados
                        WHERE DatosOrdenados.fila = 1
                        GROUP BY DatosOrdenados.ValorDescripcion COLLATE Modern_Spanish_CI_AS')
            UNION
            SELECT * FROM OPENQUERY({$this->linked_server[$codgas]}, 'SELECT
                    CASE
                        WHEN t3.codval = 28 THEN N''Crédito''
                        WHEN t3.codval = 127 THEN N''Débito''
                        ELSE
                            CAST(t3.codval AS VARCHAR) COLLATE Modern_Spanish_CI_AS
                    END AS ValorDescripcion,
                    COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                FROM {$this->short_databases[$codgas]}.[Despachos] t1
                    LEFT JOIN {$this->short_databases[$codgas]}.[Clientes] t2 ON t1.codcli = t2.cod
                    LEFT JOIN {$this->short_databases[$codgas]}.[ClientesValores] t3 ON t2.cod = t3.codcli
                WHERE t1.fchtrn = {$fchmov} AND t1.codgas = {$codgas} AND t1.nrotur = {$turno} AND t3.codval IN(28,127)
                GROUP BY t1.fchtrn, t3.codval')
            UNION
            SELECT
                CASE
                    WHEN v.CodigoValor = 6 THEN 'Efectivo Tabulador'
                    WHEN v.CodigoValor = 192 THEN 'Morralla'
                END AS ValorDescripcion,
                COALESCE(SUM(td.Monto), 0) AS Total
            FROM
                (SELECT 6 AS CodigoValor UNION ALL SELECT 192) AS v
            LEFT JOIN
                [TG].[dbo].[TabuladorDetalle] td ON td.CodigoValor = v.CodigoValor AND td.Id = {$tabId}
            GROUP BY
                v.CodigoValor
        ORDER BY ValorDescripcion DESC;
        ";

        return $this->sql->select($query, []) ?: false;
    }

    function monthly_dollar_sales_report_table(){
        $query = "SELECT * FROM [TG].[dbo].VTGPivotDolaresMontos ORDER BY estacion, año;";
        return $this->sql->select($query, []) ?: false;

    }
}