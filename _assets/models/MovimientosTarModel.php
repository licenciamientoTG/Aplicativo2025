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
    function add($fchmov, $codgas, $ValorButt_Id, $nrotrn, $nrotar, $nroter, $nroref, $nroaut, $mto, $nrotur, $codbco, $codisl, $CodigoTar) : bool{
        $nromov = $this->sql->select("SELECT ISNULL(MAX(nromov), 0) + 1 AS sec FROM {$this->databases[$codgas]}.[MovimientosTar] WHERE fchmov = ?", [$fchmov])[0]['sec'];

        $nroitm = 1;
        $trxcod = 'TD-MANUAL';
        $tiptar = 102;
        $datref = null;
        $trxmsg = null; // nuevo campo

        if (in_array($ValorButt_Id, [13, 14, 15, 24, 26, 34])) {
            $nroitm = 1;
            $nrotar = 1;
            $trxcod = ($ValorButt_Id == 34) ? 'P05-TCAR+' : 'TD-MANUAL';
            $tiptar = ($ValorButt_Id == 34) ? 53 : 102;

            if ($ValorButt_Id == 34) {
                $nroitm = '200000223';
                $nrotar = '0000000000000223';
                $nroter = 'Terminal01';
                $nroref = '0000XXXX';
                $trxmsg = '05';
                $codbco = 223;
                $datref = '@Y:223';
            }
        } else if(in_array($ValorButt_Id, [20, 21])) {
            $nroitm = $this->sql->select("SELECT ISNULL(MAX(nroitm), 0) + 1 AS sec FROM {$this->databases[$codgas]}.[MovimientosTar] WHERE fchmov = ? AND nroitm > 200000000", [$fchmov])[0]['sec'];
            $nrotar = 0;
            $trxcod = 0;
            $tiptar = 68;
        }

        $query = "INSERT INTO {$this->databases[$codgas]}.[MovimientosTar]
            ([fchmov],[nromov],[codgas],[nroitm],[fchlog],[nrotrn],[nrotar],[nroter],[nroref],[trxcod],[nroaut],[mto],[fchapl],[tiptar],[tipmov],
            [estmov],[codbco],[codres],[fchcor],[nrotur],[lotfch],[lotnro],[lottrn],[mdacod],[mdactz],[mdamto],[logexp],[codisl],[mtoprp],[datref],[trxmsg])
        VALUES
            (?,?,?,?,GETDATE(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,GETDATE(),?,?,?,?);
        ";
        $params = [$fchmov, $nromov, $codgas, $nroitm, $nrotrn, $nrotar, $nroter, $nroref, $trxcod, $nroaut, $mto, 0, $tiptar, 65,
            0, $codbco, 0, $fchmov, $nrotur, 0, 0, 0, 0, 0, 0, $codisl, 0, $datref, $trxmsg];
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
                        WHEN t1.codbco = '223' THEN 'Tarjeta TicketCar +'
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

    /**
     * Tab Corte de /direction/valeras: valeras que declara el corte (SG12.Ingresos), en PESOS
     * (en Ingresos can = mto; el corte no guarda litros). Mismo origen y catálogo que
     * Venta por Estación Mensual > Ingresos (VentasModel::conceptosValeras()) y Ingresos > Valeras
     * (ValerasModel), para que los totales cuadren entre pantallas.
     * Antes salía de MovimientosTar y dejaba fuera estaciones que solo capturan la valera en el corte
     * (Villa Ahumada, Las Fuentes, Satélite...: despachos sin rut ni MovimientosTar).
     * Fecha del corte: el día real es fch - 1 (DATEADD(DAY, fch - 1, 0)).
     */
    function get_valeras_report(int $fromInt, int $untilInt, int $codgas = 0) : array|false {
        $valeras = (new VentasModel)->conceptosValeras();
        $query = "
            SET NOCOUNT ON;
            DECLARE @codgas INT = ?, @ini INT = ?, @fin INT = ?; -- @codgas 0 = todas las estaciones
            SELECT
                t2.abr,
                LTRIM(t13.den) AS denominacion, -- conceptosValeras() trae Pluxee con y sin espacio inicial: una sola fila
                YEAR(DATEADD(DAY, i.fch - 1, 0))  AS anio,
                MONTH(DATEADD(DAY, i.fch - 1, 0)) AS mes,
                ROUND(SUM(i.mto), 2) AS total
            FROM [SG12].[dbo].[Ingresos] i WITH (NOLOCK)
            INNER JOIN [SG12].[dbo].[Valores] t13 WITH (NOLOCK) ON t13.cod = i.codval
            INNER JOIN [SG12].[dbo].[gasolineras] t2 WITH (NOLOCK) ON t2.cod = i.codgas
            WHERE i.fch BETWEEN @ini AND @fin
                AND (@codgas = 0 OR i.codgas = @codgas)
                AND t13.den IN ({$valeras})
            GROUP BY
                t2.abr, LTRIM(t13.den),
                YEAR(DATEADD(DAY, i.fch - 1, 0)),
                MONTH(DATEADD(DAY, i.fch - 1, 0))
            HAVING ROUND(SUM(i.mto), 2) <> 0
            ORDER BY
                t2.abr,
                CASE LTRIM(t13.den)
                    WHEN 'SMARTBT - SODEXO WIZEO' THEN 1
                    WHEN 'Vale Efectivale' THEN 2
                    WHEN 'Tarjeta Inburgas' THEN 3
                    WHEN 'Vale Sodexo' THEN 4
                    WHEN 'Vale Edenred' THEN 5
                    WHEN 'SMARTBT - EFECTIVALE' THEN 6
                    WHEN 'Tarjeta EfectiCard' THEN 7
                    WHEN 'Ultra Gas' THEN 8
                    WHEN 'Tarjetas Sodexo (Pluxee)' THEN 9
                    WHEN 'Mobil FleetPro' THEN 10
                    WHEN 'Tarjeta TicketCar' THEN 11
                    ELSE 12
                END,
                anio, mes
            OPTION (RECOMPILE);
        ";
        return $this->sql->select($query, [$codgas, $fromInt, $untilInt]) ?: false;
    }

    /**
     * Valeras tomadas directo de Despachos, clasificadas por el texto de `rut`.
     * `rut` trae el medio de pago en todos los tiptrn (viene con espacio inicial, por eso LTRIM):
     *   - tiptrn 53 (integrados): 'Efectivale <tarjeta>', 'Tarjeta TicketCar [+]', 'Tarjeta EfectiCard',
     *     'GASNGO <n>', 'Monedero (M.Externo)'
     *   - capturas manuales (0/49): 'M-TIC:', 'M-TEFEC:', 'M-INB:', 'M-EFE:', 'M-ULT', 'M-SOD:', 'M-MOBIL:'
     *   - terminal SmartBT: 'SBT:<emisor> <n>'; aquí solo los emisores de vales (en MovimientosTar
     *     quedan como 'SMARTBT - Bancarias' y el reporte de valeras normal no los cuenta)
     * Lo que no casa (bancarias M-SAN/M-BANO/SBT:BBVA..., rutas de clientes, NULL) se descarta.
     * Solo combustibles: 179/180/181 y 192/193.
     */
    function get_valeras_despachos_report(int $fromInt, int $untilInt, int $codgas = 0) : array|false {
        $query = "
            DECLARE @codgas INT = ?; -- 0 = todas las estaciones
            WITH c AS (
                SELECT d.codgas, d.fchtrn, d.can, d.mto,
                    CASE
                        WHEN r LIKE 'Efectivale %'                                 THEN 'Efectivale (monedero)'
                        WHEN r LIKE 'Tarjeta TicketCar +%' OR r LIKE 'M-TCAR+%'    THEN 'TicketCar +'
                        WHEN r LIKE 'Tarjeta TicketCar%'   OR r LIKE 'M-TIC:%'     THEN 'TicketCar'
                        WHEN r LIKE 'Tarjeta EfectiCard%'  OR r LIKE 'M-TEFE%'     THEN 'EfectiCard'
                        WHEN r LIKE 'Tarjeta Inburgas%'    OR r LIKE 'M-INB:%'     THEN 'Inburgas'
                        WHEN r LIKE 'M-EFE:%'                                      THEN 'Vale Efectivale'
                        WHEN r LIKE 'M-ULT%'                                       THEN 'Ultra Gas'
                        WHEN r LIKE 'M-SOD:%'                                      THEN 'Sodexo (Pluxee)'
                        WHEN r LIKE 'M-MOBIL:%'                                    THEN 'Mobil FleetPro'
                        WHEN r LIKE 'GASNGO%'                                      THEN 'GASnGO'
                        WHEN r LIKE 'Monedero (M.Externo)%'                        THEN 'Monedero externo'
                        WHEN r LIKE 'SBT:SODEXO%' OR r LIKE 'SBT:PLUXEE%'          THEN 'SBT Sodexo / Pluxee'
                        WHEN r LIKE 'SBT:EFECTIVALE%'                              THEN 'SBT Efectivale'
                        WHEN r LIKE 'SBT:EDENRED%'                                 THEN 'SBT Edenred'
                        WHEN r LIKE 'SBT:SI VALE%'                                 THEN 'SBT Si Vale'
                        WHEN r LIKE 'SBT:TOKA%'                                    THEN 'SBT Toka'
                        WHEN r LIKE 'SBT:PRESTACIONES%'                            THEN 'SBT Prestaciones'
                        WHEN r LIKE 'SBT:TOTALVALE%'                               THEN 'SBT TotalVale'
                        WHEN r LIKE 'SBT:VALE%'                                    THEN 'SBT Vale'
                    END AS valera
                FROM [SG12].[dbo].[Despachos] d
                CROSS APPLY (SELECT LTRIM(d.rut) AS r) x
                WHERE d.fchtrn BETWEEN ? AND ?
                    AND (@codgas = 0 OR d.codgas = @codgas)
                    AND d.mto <> 0
                    AND d.rut IS NOT NULL
                    AND d.codprd IN (179, 180, 181, 192, 193)
            )
            SELECT
                g.abr,
                c.valera,
                YEAR(DATEADD(DAY, c.fchtrn - 1, 0))  AS anio,
                MONTH(DATEADD(DAY, c.fchtrn - 1, 0)) AS mes,
                COUNT(*)               AS despachos,
                ROUND(SUM(c.can), 2)   AS litros,
                ROUND(SUM(c.mto), 2)   AS monto
            FROM c
            INNER JOIN [SG12].[dbo].[gasolineras] g ON c.codgas = g.cod
            WHERE c.valera IS NOT NULL
            GROUP BY g.abr, c.valera, YEAR(DATEADD(DAY, c.fchtrn - 1, 0)), MONTH(DATEADD(DAY, c.fchtrn - 1, 0))
            ORDER BY g.abr, c.valera, anio, mes
            OPTION (RECOMPILE);
        ";
        return $this->sql->select($query, [$codgas, $fromInt, $untilInt]) ?: false;
    }

    function monthly_dollar_sales_report_table(){
        $query = "SELECT * FROM [TG].[dbo].VTGPivotDolaresMontos ORDER BY estacion, año;";
        return $this->sql->select($query, []) ?: false;

    }
}