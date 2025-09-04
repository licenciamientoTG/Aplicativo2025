<?php
class DespachosModel extends Model{
    public $id;
    public $name;
    public $lastname;
    public $image;
    public $username;
    public $password;
    public $type;
    public $active;
    public $updated_at;
    public $created_at;
    public $short_databases;
    public $linked_server;

    /**
     * @param $codgas
     * @param $from
     * @param $until
     * @return array|false
     * @throws Exception
     */
    public function get_rows($codgas, $from, $until) : array|false {
        $query = 'SELECT
                        t5.abr,
                        t1.nrotrn,
                        CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                        t1.nrotrn Despacho,
                        t1.nrobom Posicion,
                        t2.abr Producto,
                        t1.can Cantidad,
                        t1.pre Precio,
                        t1.mto Importe,
                        t1.nrocte Nota,
                        t1.nrofac Factura,
                        t1.satuid UUID,
                        t3.den Cliente,
                        t3.cod Codigo,
                        t1.nroveh Vehiculo,
                        --t4.plc Placas,
                        t1.tiptrn,
                        t1.nrotur
                    FROM [SG12].[dbo].[Despachos] t1
                        LEFT JOIN [SG12].[dbo].[Productos] 			t2 ON t1.codprd = t2.cod
                        LEFT JOIN [SG12].[dbo].[Clientes] 			t3 ON t1.codcli = t3.cod
                        --LEFT JOIN [SG12].[dbo].[ClientesVehiculos] 	t4 ON t1.nroveh = t4.nroveh
                        LEFT JOIN [SG12].[dbo].[Gasolineras]		t5 ON t1.codgas = t5.cod
                    WHERE
                    CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) BETWEEN ? AND ?
                    AND t1.codgas = ?
                    ;';
        $params = [$from, $until, $codgas];
        return ($this->sql->select($query,$params)) ?: false ;
    }

    /**
     * @param $dispatches_date
     * @param $until
     * @param $interval
     * @param $codgas
     * @param $clientName
     * @return array|false
     * @throws Exception
     */
    function sp_obtener_despachos_duplicados($dispatches_date, $until, $interval, $codgas, $clientName) : array|false {
        $params = [$dispatches_date, $until, $interval, $codgas, $clientName];
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_despachos_duplicados]', $params) ?: false;
    }

    /**
     * @param $todayInt
     * @return array|false
     * @throws Exception
     */
    function get_today_sales($todayInt) : array|false {
        $query = 'SELECT
                    CAST(SUM(mto) AS DECIMAL(18, 2)) AS Monto,
                    CAST(SUM(can) AS DECIMAL(18, 3)) AS Volumen,
                    CASE
                    WHEN codprd IN (179, 192) THEN \'T-Maxima Regular\'
                    WHEN codprd IN (180, 193) THEN \'T-Super Premium\'
                    WHEN codprd = 181 THEN \'Diesel Automotriz\'
                    END AS Producto
                FROM [SG12].[dbo].[Despachos]
                WHERE codprd IN (179,180,181,192,193)
                    AND fchtrn = ?
                GROUP BY codprd
                ORDER BY codprd;';

        return $this->sql->select($query, [$todayInt]) ?: false;
    }

    function get_turn_sales($codgas, $fchtrn, $nrotur) : array|false {
        $query = "SELECT * FROM OPENQUERY({$this->linked_server[$codgas]}, 'SELECT
                    CAST(SUM(CASE WHEN tiptrn NOT IN (74, 65) THEN mto ELSE 0 END) AS DECIMAL(10, 2)) AS Monto,  -- Descartamos los jarreos
                    CAST(SUM(can) AS DECIMAL(10, 3)) AS Volumen,
                    CASE
                    WHEN codprd IN (179,192) THEN ''T-Maxima Regular''
                    WHEN codprd IN (180,193) THEN ''T-Super Premium''
                    WHEN codprd = 181 THEN ''Diesel Automotriz''
                    END AS Producto
                FROM {$this->short_databases[$codgas]}.[Despachos]
                WHERE codprd IN (179,180,181,192,193)
                    AND fchcor = {$fchtrn} AND nrotur = {$nrotur}
                GROUP BY codprd
                ORDER BY codprd;')";
        return $this->sql->select($query, []) ?: false;
    }

    /**
     * @param $from
     * @param $until
     * @param $codgas
     * @param $client
     * @return array|false
     * @throws Exception
     */
    function get_credit_and_debit_dispatches($from, $until, $codgas, $client, $tipval) : array|false {
        $query = "
        SELECT
            t1.nrotrn Despacho,
            t1.nrofac Factura,
            t1.satuid UUID,
            t1.satrfc AS RFC,
            t1.codcli,
            t2.den AS Cliente,
            CASE t2.tipval
                WHEN 3 THEN N'Crédito'
                WHEN 4 THEN N'Débito'
                ELSE 'Otro'
            END AS Tipo,
            t1.can,
            CONVERT(TIME, DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0))) AS hora_formateada,
            CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
            t1.mto,
            t3.abr,
            t3.den Producto,
            t1.hratrn,
            ROW_NUMBER() OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS rn,
            LAG(t1.hratrn, 1, NULL) OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS hora_anterior,
            t4.abr Estacion,
            t5.tar Tarjeta,
            t5.grp Grupo,
            t5.den Descripcion,
            t5.plc Placas,
            t1.nrobom Bomba,
            t6.pos Posicion,
            t1.rut
        FROM
            [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
            LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
            LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod
            LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
            LEFT JOIN [SG12].[dbo].[ClientesVehiculos] t5 ON t1.codcli = t5.codcli AND t1.nroveh = t5.nroveh
            LEFT JOIN [SG12].[dbo].[Bombas] t6 ON t1.codgas = t6.codgas AND t1.graprd = t6.graprd AND t1.nrobom = t6.nro
        WHERE
            t1.fchtrn BETWEEN {$from} AND {$until}
            AND t1.codcli > 0
            AND ({$codgas} = '0' OR t1.codgas = {$codgas})
            AND ({$client} = '0' OR t2.den LIKE '%{$client}%')
            AND (
                {$tipval} = 0 AND t2.tipval IN (3, 4) OR 
                {$tipval} IN (3, 4) AND t2.tipval = {$tipval}
            )
        ";

        return $this->sql->select($query) ?: false;
    }

    /**
     * @param $from
     * @param $until
     * @param $codgas
     * @return array|false
     * @throws Exception
     */
    function get_to_release($nrotrn, $codgas) : array|false {
        // Definir los datos
        $query = "SELECT
                    t1.nrotrn, t1.fchtrn, t1.can, t1.mto, t1.nrofac, t1.gasfac, t1.satuid, t1.satrfc, t1.logfch, t2.abr AS station
                FROM {$this->databases[$codgas]}.[Despachos] t1
                    LEFT JOIN {$this->databases[$codgas]}.[Gasolineras] t2 ON t1.codgas = t2.cod
                WHERE t1.nrotrn = ?;";
        $params = [$nrotrn];
        return $this->sql->select($query, $params) ?: false;
    }

    /**
     * @param $from
     * @param $until
     * @param $codgas
     * @return bool
     * @throws Exception
     */
    function release_dispatches($from, $until, $codgas) : bool {
        $query = "UPDATE {$this->databases[$codgas]}.[Despachos]
                    SET satuid = NULL, satrfc = NULL, codcli = 0, gasfac = 0, nrofac = 0
                    WHERE
                        (fchtrn BETWEEN ? AND ?) AND
                        (
                            (satuid IS NULL AND satrfc IS NOT NULL)
                            OR
                            (satrfc LIKE 'XAXX010101000' AND satuid = '' AND nrofac = 0)
                            OR
                            (satrfc IS NOT NULL AND satuid = '' AND nrofac = 0)
                            OR
                            (satrfc = 'XAXX010101000' AND nrofac = 0)
                        );";
        $params = [$from, $until];
        return (bool)$this->sql->update($query, $params);
    }

    function release_dispatch($nrotrn, $codgas, int $central = 0) : bool {
        if ($central == 1) {
            $query = "UPDATE [SG12].[dbo].[Despachos] SET satuid = NULL, satrfc = NULL WHERE nrotrn = ? AND codgas = ?;";
            $params = [$nrotrn, $codgas];
            $this->sql->update($query, $params);

            $query = "UPDATE [SG12].[dbo].[Despachos] SET codcli = 0 WHERE nrotrn = ? AND codgas = ?;";
            $params = [$nrotrn, $codgas];
            $this->sql->update($query, $params);

            $query = "UPDATE [SG12].[dbo].[Despachos] SET gasfac = 0, nrofac = 0 WHERE nrotrn = ? AND codgas = ?;";
            $params = [$nrotrn, $codgas];
            return (bool)$this->sql->update($query, $params);
        } else {
            $query = "UPDATE {$this->databases[$codgas]}.[Despachos] SET satuid = NULL, satrfc = NULL WHERE nrotrn = ?;";
            $params = [$nrotrn];
            $this->sql->update($query, $params);

            $query = "UPDATE {$this->databases[$codgas]}.[Despachos] SET codcli = 0 WHERE nrotrn = ?;";
            $params = [$nrotrn];
            $this->sql->update($query, $params);

            $query = "UPDATE {$this->databases[$codgas]}.[Despachos] SET gasfac = 0, nrofac = 0 WHERE nrotrn = ?;";
            $params = [$nrotrn];
            return (bool)$this->sql->update($query, $params);
        }
    }

    /**
     * @param $codgas
     * @param $tabDate
     * @return array|false
     * @throws Exception
     */
    function get_jarreos($codgas, $tabDate, $turno) : array|false {
        $query = "SELECT * FROM OPENQUERY({$this->linked_server[$codgas]}, 'SELECT TOP 100 d.nrotrn AS Transaccion
                        , d.codgas AS Gasolinera
                        , CONVERT(VARCHAR, CAST(d.fchcor as DATETIME) -1, 23) AS Fecha
                        , SUBSTRING(CONVERT(CHAR(5), d.hratrn + 10000), 2, 2) + '':'' + SUBSTRING(CONVERT(CHAR(5), d.hratrn + 10000), 4, 2) Hora
                        , d.nrotur AS Turno
                        , d.nrobom AS Bomba
                        , d.codprd AS Producto
                        , LTRIM(p.den) AS Descripcion
                        , ROUND(d.can, 3) AS Cantidad
                        , d.mto AS Total
                    FROM {$this->short_databases[$codgas]}.Despachos d(NOLOCK)
                        INNER JOIN {$this->short_databases[$codgas]}.Productos p(NOLOCK) ON d.codprd = p.cod
                    WHERE
                        d.codgas = {$codgas}
                        AND d.fchcor = {$tabDate}
                        AND d.tiptrn IN(65, 74)
                        AND d.nrotur = {$turno}
                    ORDER BY nrotrn DESC;')";
        return $this->sql->select($query, []) ?: false;
    }

    /**
     * @param $CodigoEstacion
     * @param $date
     * @return array|false
     * @throws Exception
     */
    function get_dispatches_by_station($CodigoEstacion, $date) : array|false {
        $query = "SELECT
                    CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                    t1.nrotrn Despacho,
                    t1.nrobom Posicion,
                    CASE
                        WHEN t1.codprd IN (179,192) THEN 'T-Maxima Regular'
                        WHEN t1.codprd  IN (180,193) THEN 'T-Super Premium'
                        WHEN t1.codprd = 181 THEN 'Diesel Automotriz'
                    END AS Producto,
                    t1.pre Precio,
                    CAST(t1.mto AS DECIMAL(10, 2)) AS Monto,
                    CAST(t1.can AS DECIMAL(10, 3)) AS Volumen,
                    t1.nrocte Nota,
                    t1.nrofac Factura,
                    t1.satuid UUID,
                    t3.den Cliente,
                    t3.cod Codigo,
                    t1.nroveh Vehiculo,
                    t1.tiptrn,
                    t1.nrotur,
                    t1.nrobom Bomba
                FROM {$this->databases[$CodigoEstacion]}.[Despachos] t1
                    LEFT JOIN [SG12].[dbo].[Productos] 	 t2 ON t1.codprd = t2.cod
                    LEFT JOIN [SG12].[dbo].[Clientes] 	 t3 ON t1.codcli = t3.cod
                WHERE
                    t1.codgas = ?
                    AND t1.fchcor = ?
                ORDER BY t1.nrotrn DESC;";
        $params = [$CodigoEstacion, $date];
        return ($this->sql->select($query, $params)) ?: false ;
    }

    /**
     * @param $CodigoEstacion
     * @param $date
     * @param $turno
     * @return array|false
     * @throws Exception
     *///////////tabulador
    function sp_obtener_despachos_para_marcar($CodigoEstacion, $date, $turno) : array|false {
        $query = "
        SELECT * FROM OPENQUERY({$this->linked_server[$CodigoEstacion]}, 'SELECT
			CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) AS Fecha,
			t1.nrotrn AS Despacho,
			t1.nrobom AS Posicion,
			t2.den AS Producto,
			t1.pre AS Precio,
			CAST(t1.mto AS DECIMAL(10, 2)) AS Monto,
			CAST(t1.can AS DECIMAL(10, 3)) AS Volumen,
			t1.nrocte AS Nota,
			t1.nrofac AS Factura,
			t1.satuid AS UUID,
			t3.den AS Cliente,
			t3.cod AS Codigo,
			t1.nroveh AS Vehiculo,
			t1.tiptrn,
			t1.nrotur,
			t5.den AS Isla,
			t1.nrobom AS Bomba,
			t1.codgas,
			t1.tar,
			t1.nrofac,
			t3.tipval,
			t6.trxmsg,
			t1.satuid,
			t6.trxcod,
			t7.den Valor
		FROM
			{$this->short_databases[$CodigoEstacion]}.[Despachos] t1
		LEFT JOIN
			{$this->short_databases[$CodigoEstacion]}.[Productos] t2 ON t1.codprd = t2.cod
		LEFT JOIN
			{$this->short_databases[$CodigoEstacion]}.[Clientes] t3 ON t1.codcli = t3.cod
		LEFT JOIN
			{$this->short_databases[$CodigoEstacion]}.[Bombas] t4 ON t1.codgas = t4.codgas AND t1.graprd = t4.graprd AND t1.nrobom = t4.nro
		LEFT JOIN
			{$this->short_databases[$CodigoEstacion]}.[Islas] t5 ON t4.codgas = t5.codgas AND t4.codisl = t5.cod
		LEFT JOIN (
            SELECT
                mt1.codbco,
                mt1.nrotrn,
                mt1.trxmsg,
                mt1.trxcod
            FROM
                {$this->short_databases[$CodigoEstacion]}.[MovimientosTar] mt1
            WHERE
                NOT EXISTS (
                    SELECT 1
                    FROM {$this->short_databases[$CodigoEstacion]}.[MovimientosTar] mt2
                    WHERE mt1.nrotrn = mt2.nrotrn
                    AND (mt2.fchmov > mt1.fchmov OR (mt2.fchmov = mt1.fchmov AND mt2.fchlog > mt1.fchlog))
                )
        ) t6 ON t1.nrotrn = t6.nrotrn
		LEFT JOIN
			{$this->short_databases[$CodigoEstacion]}.[Valores] t7 ON t6.codbco = t7.cod
		WHERE
			t1.codgas = {$CodigoEstacion}
			AND t1.fchcor = {$date}
			AND t1.nrotur = {$turno}
			AND (t1.tiptrn IN (0, 49, 53, 51) OR (t1.tiptrn = 51 AND t1.codcli = 0)) 
			AND (t3.tipval IS NULL OR t3.tipval NOT IN (3, 4))
			AND t1.mto > 0
		-- AND t1.nrofac < 1
		ORDER BY
			t1.nrotrn DESC;');
        ";
      
        return $this->sql->select($query, []) ?: false;
    }

    /**
     * @param $codest
     * @param $nrotrn
     * @return array|false
     * @throws Exception
     */
    function get_dispatch($codest, $nrotrn) : array|false {
        return ($rs = $this->sql->select("SELECT * FROM {$this->databases[$codest]}.[Despachos] WHERE nrotrn = ?;", [$nrotrn])) ? $rs[0] : false ;
    }

    /**
     * @param $tabular
     * @param $dispatch
     * @param $CodigoValor
     * @param $CodigoTar
     * @param $CodigoRut
     * @return bool
     * @throws Exception
     */
    function sp_marcar_despacho_onegoal($tabular, $dispatch, $CodigoValor, $CodigoTar, $CodigoRut) : bool {
        // El siguiente SP returno 1 si el despacho fue actualizado, 0 si no
        return (bool)$this->sql->executeStoredProcedure('[TG].[dbo].[sp_marcar_despacho_onegoal]', array(
            'TabulatorId'        => $tabular['Id'],
            'IslaId'             => $dispatch['codisl'],
            'ValorButtNum'       => $CodigoValor['ValorButt_Num'],
            'Monto'              => $dispatch['mto'],
            'TipoCambio'         => $tabular['TipoCambio'],
            'Usuario'            => $tabular['Usuario'],
            'CodigoEstacion'     => $tabular['CodigoEstacion'],
            'Turno'              => $tabular['Turno'],
            'IdUsuario'          => $_SESSION['tg_user']['Id'],
            'CodigoValor'        => $CodigoValor['ValorButt_Id'],
            'ValorButtCodigoTar' => $CodigoValor['ValorButt_CodigoTar'],
            'CodigoTar'          => $CodigoTar,
            'CodigoRut'          => $CodigoRut,
            'DatabaseName'       => $this->databases[$tabular['CodigoEstacion']],
            'nrotrn'             => $dispatch['nrotrn'],
            'Resultado'          => 0));
    }

    /**
     * @param $fchtrn
     * @param $hratrn_init
     * @param $hratrn_final
     * @param $codgas
     * @return array|false
     * @throws Exception
     */
    function get_credit_dispatches_tabular($fchtrn, $hratrn_init, $hratrn_final, $codgas) : array | false {
        $query = '
            SELECT
                t1.nrotrn Despacho,
                t1.nrofac Factura,
                t1.satuid UUID,
                t1.satrfc AS RFC,
                t1.codcli,
                t2.den AS Cliente,
                CASE t2.tipval
                    WHEN 3 THEN N\'Crédito\'
                    WHEN 4 THEN N\'Débito\'
                    ELSE \'Otro\'
                END AS Tipo,
                t1.can,
                CONVERT(TIME, DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0))) AS hora_formateada,
                CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                t1.mto,
                t3.abr,
                t3.den Producto,
                t1.hratrn,
                ROW_NUMBER() OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS rn,
                LAG(t1.hratrn, 1, NULL) OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS hora_anterior,
                t4.abr Estacion,
                t5.tar Tarjeta,
                t5.grp Grupo,
                t5.den Descripcion,
                t5.plc Placas,
                t1.nrobom Bomba,
                t6.pos Posicion,
                t1.rut
            FROM
                [SG12].[dbo].[Despachos] t1
                LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
                LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod
                LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
                LEFT JOIN [SG12].[dbo].[ClientesVehiculos] t5 ON t1.codcli = t5.codcli AND t1.nroveh = t5.nroveh
                LEFT JOIN [SG12].[dbo].[Bombas] t6 ON t1.codgas = t6.codgas AND t1.graprd = t6.graprd AND t1.nrobom = t6.nro
            WHERE
                t1.fchcor = ?
                AND t1.hratrn BETWEEN ? AND ?
                AND t1.codprd IN (179,180,181,192,193)
                AND t1.codgas = ?
                AND t2.tipval = 3
        ';

        $params = [$fchtrn, $hratrn_init, $hratrn_final, $codgas];
        return ($rs = $this->sql->select($query, $params)) ? $rs : false ;
    }

    /**
     * @param $fchtrn
     * @param $hratrn_init
     * @param $hratrn_final
     * @param $codgas
     * @return array|false
     * @throws Exception
     */
    function get_debit_dispatches_tabular($fchtrn, $hratrn_init, $hratrn_final, $codgas) : array | false {
        $query = "
            SELECT
                t1.nrotrn Despacho,
                t1.nrofac Factura,
                t1.satuid UUID,
                t1.satrfc AS RFC,
                t1.codcli,
                t2.den AS Cliente,
                CASE t2.tipval
                    WHEN 3 THEN N'Crédito'
                    WHEN 4 THEN N'Débito'
                    ELSE 'Otro'
                END AS Tipo,
                t1.can,
                CONVERT(TIME, DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0))) AS hora_formateada,
                CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                t1.mto,
                t3.abr,
                t3.den Producto,
                t1.hratrn,
                ROW_NUMBER() OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS rn,
                LAG(t1.hratrn, 1, NULL) OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS hora_anterior,
                t4.abr Estacion,
                t5.tar Tarjeta,
                t5.grp Grupo,
                t5.den Descripcion,
                t5.plc Placas,
                t1.nrobom Bomba,
                t6.pos Posicion,
                t1.rut,
                t7.den Isla
            FROM
                {$this->databases[$codgas]}.[Despachos] t1
                LEFT JOIN {$this->databases[$codgas]}.[Clientes] t2 ON t1.codcli = t2.cod
                LEFT JOIN {$this->databases[$codgas]}.[Productos] t3 ON t1.codprd = t3.cod
                LEFT JOIN {$this->databases[$codgas]}.[Gasolineras] t4 ON t1.codgas = t4.cod
                LEFT JOIN {$this->databases[$codgas]}.[ClientesVehiculos] t5 ON t1.codcli = t5.codcli AND t1.nroveh = t5.nroveh
                LEFT JOIN {$this->databases[$codgas]}.[Bombas] t6 ON t1.codgas = t6.codgas AND t1.graprd = t6.graprd AND t1.nrobom = t6.nro
                LEFT JOIN {$this->databases[$codgas]}.[Islas]  t7 ON t1.codisl = t7.cod
            WHERE
                t1.fchcor = ?
                AND t1.hratrn BETWEEN ? AND ?
                AND t1.codprd IN (179,180,181,192,193)
                AND t1.codgas = ?
                AND t2.tipval = 4
        ";

        $params = [$fchtrn, $hratrn_init, $hratrn_final, $codgas];
        return ($rs = $this->sql->select($query, $params)) ? $rs : false ;
    }

    /**
     * @return array|false
     * @throws Exception
     */
    function get_today_loyalty_dispatches() : array | false {
        $query = "SELECT 
                        CASE WHEN ProductoId = 179 OR ProductoId = 180 THEN 'TAX_ID' END document_type
                        ,t.ClienteRazonSocial AS document_number
                        ,t.DespachoMonto AS amount,
                        est.place_uid AS place_uid --falta agregar este dato
                        ,t.ProductoDescripcion AS description
                        ,CASE WHEN ProductoId = 179 OR ProductoId = 180 THEN 'LITERS' END unit_measure
                        ,t.DespachoLitros AS quantity
                        ,t.DespachoId AS DespachoID
                    FROM [TG].[dbo].[DespachosLealtad]  t
                        INNER JOIN [TG].[dbo].[Estaciones] est on t.EstacionId = est.Codigo
                    WHERE (t.procesado = 0 OR t.procesado = 'NULL' )
                        AND t.ClienteRazonSocial != 'NULL' 
                        -- AND CAST(t.DespachoFecha AS DATE) = CAST(GETDATE() AS DATE)
                        AND CAST(t.DespachoFecha AS DATE) = CAST(GETDATE() - 1 AS DATE)

        ";
        return ($this->sql->select($query)) ?: false;
    }

    /**
     * @return array|false
     * @throws Exception
     */
    function get_pendings_loyalty_dispatches() : array | false {
        $query = "DECLARE @fecha_inicio INT = DATEDIFF(dd, 0, getdate()) +1;
                SELECT 
                    nrotrn AS IdDespacho,
                    CONVERT(VARCHAR, CONVERT(SMALLDATETIME, fchtrn - 1, 103), 103) AS Fecha,
                    nrotur AS Turno,
                    mto AS Monto,
                    can AS Litros,
                    codprd AS Producto,
                    p.den AS ProductoDesc,
                    codgas AS Estacion,
                    g.abr AS EstacionDesc,
                    d.codcli AS CodCliente,
                    c.den AS Cliente,
                    c.pto AS Puntos,
                    c.rfc AS RazonSocial
                FROM [SG12].[dbo].Despachos d
                    INNER JOIN [SG12].[dbo].Productos p ON d.codprd = p.cod 
                    INNER JOIN [SG12].[dbo].Gasolineras g ON d.codgas = g.cod 
                    INNER JOIN [SG12].[dbo].Clientes c ON d.codcli = c.cod
                WHERE 
                    d.fchtrn = @fecha_inicio
                    AND d.codcli > 0
                    AND c.pto = 1
                    AND P.cod != 4
                    AND nrotrn >= 0
                ORDER BY nrotrn DESC
                ";
        return $this->sql->select($query) ?: false;
    }

    /**
     * @param $dispatch
     * @param $codest
     * @return array|false
     * @throws Exception
     */
    function get_dispatch_by_transaction($dispatch, $codest) : array | false {
        $query = "SELECT * FROM {$this->databases[$codest]}.[Despachos] WHERE nrotrn = ?;";
        $params = [$dispatch];
        return ($rs = $this->sql->select($query, $params)) ? $rs[0] : false ;
    }

    function dismark_dispatch_station($codest, $nrotrn) : bool {
        $query = "UPDATE {$this->databases[$codest]}.[Despachos] SET rut = NULL, tar = 0 WHERE nrotrn = ?;";
        $params = [$nrotrn];
        return (bool)$this->sql->update($query, $params);
    }

    function dismark_dispatch_central($codest, $nrotrn) : bool {
        $query = "UPDATE [SG12].[dbo].[Despachos] SET rut = NULL, tar = 0 WHERE nrotrn = ? AND codgas = ?;";
        $params = [$nrotrn, $codest];
        return (bool)$this->sql->update($query, $params);
    }

    function sp_obtener_diferencias_por_valor($from, $until, $codgas) : array|false {

        if ($codgas == 0) {
            $query = "SELECT Codigo, Servidor FROM [TG].dbo.Estaciones WHERE Codigo NOT IN (0,4,20)";
            $stations = $this->sql->select($query, []);
            if (!$stations) {
                return false;
            } else {
                $connected_stations = [];

                foreach ($stations as $station) {
                    $codigo = $station['Codigo'];
                    $servidor = $station['Servidor'];

                    // Verificar conexión al puerto 1433 (SQL Server)
                    $connection = @fsockopen($servidor, 1433, $errno, $errstr, 5); // 5 segundos timeout

                    if ($connection) {
                        // Conexión exitosa
                        $connected_stations[] = $codigo;
                        fclose($connection);
                    }
                }

                // Convertir array de códigos a string separado por comas
                $result_string = implode(',', $connected_stations);
            }
        } else {
            $result_string = $codgas;
        }
        set_time_limit(0);
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 300);
        $params = [$from, $until, $result_string];
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_diferencias]', $params) ?: false;
    }

    function get_mark_dispatches_by_island_shift($fch,$CodigoEstacion) : array|false {
        $query = "SELECT
                    CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                    CAST(DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, CAST('00:00' AS TIME))) AS TIME) AS Hora,
                    t1.nrotrn Despacho,
                    t1.nrobom Posicion,
                    CASE
                        WHEN t1.codprd IN (179,192) THEN 'T-Maxima Regular'
                        WHEN t1.codprd IN (180,193) THEN 'T-Super Premium'
                        WHEN t1.codprd = 181 THEN 'Diesel Automotriz'
                    END AS Producto,
                    t1.pre Precio,
                    CAST(t1.mto AS DECIMAL(10, 2)) AS Monto,
                    CAST(t1.can AS DECIMAL(10, 3)) AS Volumen,
                    t1.nrocte Nota,
                    t1.nrofac Factura,
                    t1.satuid UUID,
                    t3.den Cliente,
                    t3.cod Codigo,
                    t1.nroveh Vehiculo,
                    t1.tiptrn,
                    t1.nrotur,
                    (t1.nrotur / 10) AS turno,
                    (t1.nrotur % 10) AS subcorte,
                    t5.den Isla,
                    t1.nrobom Bomba,
                    t1.codgas,
                    t1.tar,
                    t1.nrofac,
                    t3.tipval,
                    CASE
                        WHEN t3.tipval = 3 THEN N'Crédito'
                        WHEN t3.tipval = 4 THEN N'Débito'
                    END Tipo,
                    CASE 
                        WHEN t6.Despacho IS NOT NULL AND t1.mto = t6.mto THEN 1
                        ELSE 0 
                    END AS CoincidenciaEncontrada,
                t8.den Valor,
                t9.abr Estacion
                FROM [SG12].[dbo].[Despachos] t1
                    LEFT JOIN [SG12].[dbo].[Clientes] t3 ON t1.codcli = t3.cod
                    LEFT JOIN [SG12].[dbo].[Islas] t5 ON t1.codisl = t5.cod
                    LEFT JOIN (SELECT DISTINCT ABS(t1.sec) Despacho, mto FROM [SG12].[dbo].[ValesR] t1 WHERE t1.codgas = $CodigoEstacion AND t1.codval IN (28,127,145) AND t1.fch = $fch) t6 ON t1.nrotrn = t6.Despacho
                    LEFT JOIN [SG12].[dbo].[MovimientosTar] t7 ON t1.nrotrn = t7.nrotrn AND t7.codgas = $CodigoEstacion
                    LEFT JOIN [SG12].[dbo].[Valores] t8 ON t7.codbco = t8.cod
                    LEFT JOIN [SG12].[dbo].[Gasolineras] t9 ON t1.codgas = t9.cod
                WHERE
                    t1.fchcor = $fch
                    AND t3.tipval IN (3,4)
                AND t1.codgas = $CodigoEstacion
                AND t1.mto > 0
                ORDER BY t1.nrotrn DESC;";
        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_pending_dispatches_for_invoice($from, $until, int $type, $status) : array|false {
       
        $stringStatus = '';

        if($status == 'pendiente'){
            $stringStatus = 'AND t1.nrofac = 0';
        }else{
            $stringStatus = 'AND t1.nrofac != 0';
        }
        $stringType = ' and t2.tipval in (4,3)';
        if ($type == 4) {
            $stringType = ' and t2.tipval =4';
        } else if ($type == 3) {
            $stringType = 'and t2.tipval =3';
        }
        $query = "
            DECLARE @tipo INT = $type;
            WITH ValuesTable AS (
                    SELECT
                            CONVERT(DATE, DATEADD(DAY, -1, t1.fchtrn)) AS Fecha,
                            t1.nrotrn,
                            t3.abr Estacion,
                            t4.den as Producto,
                            t1.codcli,
                            t2.den Cliente,
                            t1.can Volumen,
                            t1.mto Monto,
                            CASE
                                WHEN t2.tipval = 3 THEN 'Crédito'
                                WHEN t2.tipval = 4 THEN 'Débito'
                            END Tipo,
                            t1.nrofac Factura,
                            t1.satuid UUID
                        FROM
                            [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                            LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
                            LEFT JOIN [SG12].[dbo].[Gasolineras] t3 ON t1.codgas = t3.cod
                            LEFT JOIN [SG12].[dbo].[Productos] t4 ON t1.codprd = t4.cod
                        WHERE
                            t1.fchtrn BETWEEN $from AND $until
                            and t1.mto != 0
                            and t1.can != 0
                           $stringType
                            $stringStatus
            )
            select * from  ValuesTable order by Fecha desc;
            ";
        return ($this->sql->select($query, [])) ?: false ;
    }

    function control_dispatches($from, $until, $codgas) :array|false {
        $query = "
            SELECT
                t1.nrotrn Despacho,
                t1.nrofac Factura,
                t1.satuid UUID,
                t1.satrfc AS RFC,
                t1.codcli,
                t2.den AS Cliente,
                CASE t2.tipval
                    WHEN 3 THEN N'Crédito'
                    WHEN 4 THEN N'Débito'
                    ELSE 'Otro'
                END AS Tipo,
                t1.can,
                CONVERT(TIME, DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0))) AS hora_formateada,
                CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                t1.mto,
                t3.abr,
                t3.den Producto,
                t1.hratrn,
                ROW_NUMBER() OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS rn,
                LAG(t1.hratrn, 1, NULL) OVER (PARTITION BY t1.codcli ORDER BY t1.hratrn) AS hora_anterior,
                t4.abr Estacion,
                t5.tar Tarjeta,
                t5.grp Grupo,
                t5.den Descripcion,
                t5.plc Placas,
                t1.nrobom Bomba,
                t6.pos Posicion,
                t1.rut
            FROM
                [SG12].[dbo].[Despachos] t1
                LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
                LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod
                LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
                LEFT JOIN [SG12].[dbo].[ClientesVehiculos] t5 ON t1.codcli = t5.codcli AND t1.nroveh = t5.nroveh
                LEFT JOIN [SG12].[dbo].[Bombas] t6 ON t1.codgas = t6.codgas AND t1.graprd = t6.graprd AND t1.nrobom = t6.nro
            WHERE
                t1.fchtrn BETWEEN ? AND ? AND t1.tiptrn NOT IN (65,74) AND nrotrn > 0 AND t1.codgas = ? ORDER BY t1.nrotrn
        ";
        $params = [$from, $until, $codgas];
        return ($this->sql->select($query, $params)) ?: false ;
    }

        function control_dispatches2($from, $until, $codgas,$uuid,$tipo_cliente,$billed) :array|false {
            $uuid_true = " AND (t1.satuid IS NOT NULL OR t3.satuid IS NOT NULL)";
            $uuid_false = " AND  t1.satuid IS NULL AND t3.satuid IS NULL AND t1.tiptrn != 53";


            $billed_true = " AND (t3.nro is not null or t1.nrofac !=0)";
            $billed_false = " AND  t3.nro is null and t1.nrofac =0 ";
            $where = "";
            $where = $codgas != 0 ? "AND t1.codgas = $codgas" : "";
            $where .= ($uuid != 0) ? ($uuid == 1 ? $uuid_false : ($uuid == 2 ? $uuid_true : "")): "";
            $where .= ($billed != 0) ? ($billed == 1 ? $billed_false : ($billed == 2 ? $billed_true : "")): "";
            if (!empty($tipo_cliente)) {
                $where .= " AND 
                            CASE 
                                WHEN t10.codval = 28 THEN 'cliente_credito'
                                WHEN t10.codval = 127 THEN 'cliente_debito'
                                WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'monedero'
                                WHEN t3.codopr != 21701354 AND t10.codval IS NULL THEN 'contado'
                                WHEN t3.codopr = 21701354 THEN 'factura_global'
                                ELSE 'N/A'
                            END = '$tipo_cliente'";
            }

            $params = [$from, $until, ];
            $query = "WITH CTE AS (
                        SELECT
                        CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchtrn), 23) as fecha, 
                        CAST(CONVERT(TIME, DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0))) AS TIME(0)) AS hora_formateada,
                        SUBSTRING(CAST(t1.nrotur AS VARCHAR(3)), 1, 1)  as 'turno',
                        t1.nrotrn as 'despacho',
                        t8.den as 'producto',
                        t6.abr as 'estacion',
                        t7.den as 'empresa',
                        t2.den as 'cliente_des',
                        t5.den as 'cliente_fac',
                        t1.can as 'cantidad',
                        t1.mto as 'importe',
                        t1.pre as 'precio',
                        t1.gasfac,
                        t1.nrofac,
                        CASE WHEN t9.cod <> 0 THEN t9.den ELSE '' END AS 'despachador',
                        CASE 
                            WHEN t3.nro BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                            WHEN t3.nro BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                            WHEN t3.nro BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                            WHEN t3.nro BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                            WHEN t3.nro BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                            WHEN t3.nro BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)

                            ELSE CAST(t3.nro AS VARCHAR(10)) 
                        END AS 'factura',
                        CONVERT(DATE, DATEADD(DAY, -1, t3.fch)) AS 'FechaFactura',
                        CASE 
                            WHEN t1.nrofac BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            WHEN t1.nrofac BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            WHEN t1.nrofac BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            WHEN t1.nrofac BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            WHEN t1.nrofac BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            WHEN t1.nrofac BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            ELSE CAST(t1.nrofac AS VARCHAR(10)) 
                        END AS 'factura_desp',
                        case 
                        when t1.satuid is not null Then t1.satuid 
                        else t3.satuid
                        end as 'UUID',
                        t3.satuid as 'UUID_fac',
                        t1.satuid as 'UUID_dep',
                        t1.rut,
                        t13.den as 'denominacion',
                        t1.codcli as 'codigo_cliente',
                        t10.codval,
                        t2.tipval,
                        CASE
                            WHEN t13.den in (' Tarjeta EfectiCard',
                                            ' SMARTBT - EFECTIVALE',
                                            ' Tarjeta TicketCar',
                                            ' Vale Efectivale',
                                            'Ultra Gas',
                                            ' Tarjeta Inburgas',
                                            ' Vale Edenred',
                                            ' Tarjetas Sodexo (Pluxee)',
                                            'Mobil FleetPro',
                                            ' SMARTBT - SODEXO WIZEO',
                                            ' Vale Sodexo') THEN 'Monedero'
                            WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'Monedero'
                            WHEN t10.codval = 28 THEN 'Cliente Crédito'
                            WHEN t10.codval = 127 THEN 'Cliente Débito'
                            WHEN  t3.codopr != 21701354 AND t10.codval is null THEN 'Contado'
                            WHEN t3.codopr = 21701354 THEN 'Factura Global'
                            else 'N/A'
                        END as 'tipo_cliente_aplicativo',
                        CASE 
                            WHEN t10.codval = 28 THEN 'Cliente Crédito'
                            WHEN t10.codval = 127 THEN 'Cliente Débito'
                            WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'Monedero'
                            WHEN  t3.codopr != 21701354 AND t10.codval is null THEN 'Contado'
                            WHEN t3.codopr = 21701354 THEN 'Factura Global'
                            else 'N/A'
                        END as 'tipo_cliente',
                        t14.den as 'efectos_c',
                        t11.nroveh as 'vehiculo',
                        t11.plc as 'placas',
                        t3.txtref,
                        t3.tipref,
                        CASE
                            WHEN t3.tipref = 4 AND t1.logmsk IN (2, 3) THEN 'Tarjeta Credito'
                            WHEN t3.tipref = 28 AND t1.logmsk IN (2, 3) THEN 'Tarjeta Debito'
                            WHEN t3.tipref = 1 AND t1.logmsk IN (2, 3) THEN 'Efectivo'
                        END AS 'tipo_pago',
                        CASE
                            WHEN t1.tiptrn = 51 AND t1.gasfac != 2 THEN 'Tarjeta Credito'
                            WHEN t1.tiptrn = 52 AND t1.gasfac != 2 THEN 'Tarjeta Debito'
                            WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'Efectivale'
                            WHEN t1.tiptrn = 0 THEN 'Efectivo'
                        END AS 'tipo_pago_despacho',
                        t1.tiptrn,
                        t6.cvecli,
                        ROW_NUMBER() OVER(PARTITION BY t1.nrotrn ORDER BY t1.fchtrn ASC, t1.hratrn ASC) AS rn
                        FROM [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                        LEFT JOIN [SG12].[dbo].Clientes t2 on t1.codcli = t2.cod
                        LEFT JOIN [SG12].[dbo].DocumentosC t3 on t1.nrofac = t3.nro and t1.codgas = t3.codgas
                        LEFT JOIN [SG12].[dbo].Clientes t5 on t3.codopr = t5.cod
                        LEFT JOIN [SG12].[dbo].Gasolineras t6 ON t1.codgas = t6.cod
                        LEFT JOIN [SG12].[dbo].[Empresas] t7 ON t6.codemp = t7.cod
                        LEFT JOIN [SG12].[dbo].[Productos] t8 on t1.codprd = t8.cod
                        LEFT JOIN [SG12].[dbo].[Responsables] t9 on t1.codres = t9.cod
                        LEFT JOIN [SG12].[dbo].[ClientesValores] t10 ON t1.codcli = t10.codcli and t10.codest !=-1 and  t10.codval in(127,28)
                        LEFT JOIN [SG12].[dbo].[ClientesVehiculos] t11 on t1.codcli = t11.codcli  and t1.nroveh = t11.nroveh
                        /* LEFT JOIN [SG12].[dbo].MovimientosTar t12 on t1.nrotrn=t12.nrotrn and t1.codgas = t12.codgas and t12.mto != 0 and t12.tipmov != 97 and t12.tipmov != 86 */
                        LEFT JOIN(Select nrotrn,sum(mto) as mto, codbco, codgas from [SG12].[dbo].MovimientosTar Where tipmov != 86 and tipmov !=97  and mto != 0   group by nrotrn, codgas,codbco) t12 on t1.nrotrn=t12.nrotrn and t1.codgas = t12.codgas
                        LEFT JOIN [SG12].[dbo].Valores t13 on t12.codbco = t13.cod
                        LEFT JOIN [SG12].[dbo].[EfectosC] t14 on t3.tip = t14.tipope and t3.subope =t14.subope
                            Where
                            t1.mto != 0 and
                            t1.tiptrn  not in (65,74) and
                            t1.fchtrn BETWEEN  $from and  $until  {$where}
                            )
                SELECT *
                    FROM CTE WITH (NOLOCK)
                    WHERE rn = 1
                    ORDER BY fecha, hora_formateada;";
                    echo '<pre>';
                    var_dump($query);
                    die();
                    try {

                        return ($rs=$this->sql->select($query,array())) ? $rs : false ;

                    } catch (Exception $e) {
                        echo "Error al ejecutar la consulta: " . $e->getMessage();
                        return false;
                    }
                 }

    function pivot_dispatches($from, $until, $codgas){
        $where = "";
        $codgas != 0 ? $where = "and t1.codgas = $codgas" : $where = "";
        $params = [$from, $until, ];
        $query="SELECT *,
                    ISNULL([cliente_credito], 0) + 
                    ISNULL([cliente_debito], 0) + 
                    ISNULL([monedero], 0) + 
                    ISNULL([contado], 0) + 
                    ISNULL([factura_global], 0) + 
                    ISNULL([N/A], 0) AS total
                FROM (
                    SELECT
                        t6.abr AS estacion,
                        CASE
                            WHEN t10.codval = 28 THEN 'cliente_credito'
                            WHEN t10.codval = 127 THEN 'cliente_debito'
                            WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'monedero'
                            WHEN t3.codopr != 21701354 AND t10.codval IS NULL THEN 'contado'
                            --WHEN t3.tip = 3 AND t3.subope = -1 THEN 'contado'
                            WHEN t3.codopr = 21701354 THEN 'factura_global'
                            ELSE 'N/A'
                        END AS tipo_cliente,
                        t1.mto AS importe
                     FROM [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                    --LEFT JOIN [SG12].[dbo].Clientes t2 on t1.codcli = t2.cod
                    LEFT JOIN [SG12].[dbo].DocumentosC t3 on t1.nrofac = t3.nro and t1.codgas = t3.codgas
                    --LEFT JOIN [SG12].[dbo].Clientes t5 on t3.codopr = t5.cod
                    LEFT JOIN [SG12].[dbo].Gasolineras t6 ON t1.codgas = t6.cod
                    --LEFT JOIN [SG12].[dbo].[Empresas] t7 ON t6.codemp = t7.cod
                    --LEFT JOIN [SG12].[dbo].[Productos] t8 on t1.codprd = t8.cod
                    --LEFT JOIN [SG12].[dbo].[Responsables] t9 on t1.codres = t9.cod
                    LEFT JOIN [SG12].[dbo].[ClientesValores] t10 ON t1.codcli = t10.codcli and t10.codest !=-1 and  t10.codval in(127,28)
                    WHERE
                    	t1.tiptrn  not in (65,74) and 
                        t1.fchtrn BETWEEN ? AND ? {$where}
                    ) AS SourceTable
                    PIVOT (
                        SUM(importe)
                        FOR tipo_cliente IN ([cliente_credito], [cliente_debito], [monedero], [contado], [factura_global], [N/A])
                    ) AS PivotTable
                    ORDER BY estacion;";


        return ($this->sql->select($query, $params)) ?: false ;
    }
    function pivot_daily_dispatches_table($from, $until, $codgas){
        $where = "";
        $codgas != 0 ? $where = "and t1.codgas = $codgas" : $where = "";
        $params = [$from, $until, ];
        $query="SELECT *,
                    ISNULL([cliente_credito], 0) + 
                    ISNULL([cliente_debito], 0) + 
                    ISNULL([monedero], 0) + 
                    ISNULL([contado], 0) + 
                    ISNULL([factura_global], 0) + 
                    ISNULL([N/A], 0) AS total
                FROM (
                    SELECT
                        t6.abr AS estacion,
                        t1.codgas,
                        CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchtrn), 23) as fecha,
                        CASE
                            WHEN t10.codval = 28 THEN 'cliente_credito'
                            WHEN t10.codval = 127 THEN 'cliente_debito'
                            WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'monedero'
                            WHEN t3.codopr != 21701354 AND t10.codval IS NULL THEN 'contado'
                            --WHEN t3.tip = 3 AND t3.subope = -1 THEN 'contado'
                            WHEN t3.codopr = 21701354 THEN 'factura_global'
                            ELSE 'N/A'
                        END AS tipo_cliente,
                        t1.mto AS importe
                     FROM [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                    LEFT JOIN [SG12].[dbo].Clientes t2 on t1.codcli = t2.cod
                    LEFT JOIN [SG12].[dbo].DocumentosC t3 on t1.nrofac = t3.nro and t1.codgas = t3.codgas
                    LEFT JOIN [SG12].[dbo].Clientes t5 on t3.codopr = t5.cod
                    LEFT JOIN [SG12].[dbo].Gasolineras t6 ON t1.codgas = t6.cod
                    LEFT JOIN [SG12].[dbo].[Empresas] t7 ON t6.codemp = t7.cod
                    LEFT JOIN [SG12].[dbo].[Productos] t8 on t1.codprd = t8.cod
                    LEFT JOIN [SG12].[dbo].[Responsables] t9 on t1.codres = t9.cod
                    LEFT JOIN [SG12].[dbo].[ClientesValores] t10 ON t1.codcli = t10.codcli and t10.codest !=-1 and  t10.codval in(127,28)
                    WHERE 
                    t1.tiptrn  not in (65,74) and 
                        t1.fchtrn BETWEEN ? AND ? {$where}
                    ) AS SourceTable
                    PIVOT (
                        SUM(importe)
                        FOR tipo_cliente IN ([cliente_credito], [cliente_debito], [monedero], [contado], [factura_global], [N/A])
                    ) AS PivotTable
                    ORDER BY estacion,fecha;";
        return ($this->sql->select($query, $params)) ?: false ;
    }


    function sp_obtener_total_productos($tabId) {
        return $this->sql->executeStoredProcedure('[TG].[dbo].[sp_obtener_total_productos_tabulador]', [$tabId]) ?: 0;
    }

    function get_dashboard_debit_credit($from, $until, $clients) : array|false {
        $query = "
        -- Declaramos las variables de rango para la fecha
        DECLARE @from INT = '{$from}';
        DECLARE @until INT = '{$until}';
        DECLARE @clients VARCHAR(MAX) = '{$clients}';
        
        -- CTE (Common Table Expression) para agrupar y sumar datos relevantes
        WITH Clientes AS (
            SELECT
                t2.den Clientes, -- Nombre del cliente
                SUM(t1.mto) MontosTotales, -- Suma total de los montos
                SUM(t1.can) VolumenesTotales, -- Suma total de los volúmenes
                COUNT(*) TotalRegistros, -- Conteo total de registros
                CASE
                    WHEN t2.tipval = 3 THEN N'Crédito' -- Asigna Crédito si tipval es 3
                    WHEN t2.tipval = 4 THEN N'Débito' -- Asigna Débito si tipval es 4
                END Tipos
            FROM
                [SG12].[dbo].[Despachos] t1 WITH (NOLOCK) -- Tabla de despachos con NOLOCK para evitar bloqueos
                INNER JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod -- Unión con la tabla de clientes
                LEFT JOIN [SG12].[dbo].[Gasolineras] t3 ON t1.codgas = t3.cod -- Unión opcional con la tabla de gasolineras
            WHERE
                t1.fchcor BETWEEN @from AND @until -- Rango de fechas
                AND t2.tipval IN (3, 4) -- Filtra por tipos de valor 3 (Crédito) o 4 (Débito)
                AND t1.mto > 0 -- Filtra registros con monto mayor a 0
                AND t1.can > 0 -- Filtra registros con cantidad mayor a 0
                AND (@clients = '0' OR t1.codcli IN ({$clients})) -- Filtra por clientes específicos
            GROUP BY t2.den, t2.tipval -- Agrupa por nombre de cliente y tipo de transacción
        )
        -- Selección final con cálculos adicionales
        SELECT
            Clientes, -- Nombre del cliente
            Tipos, -- Tipo de transacción (Crédito o Débito)
            VolumenesTotales, -- Suma total de los volúmenes
            -- Acumulado de VolumenesTotales dentro de cada tipo, ordenado por MontosTotales descendente
            SUM(VolumenesTotales) OVER (PARTITION BY Tipos ORDER BY MontosTotales DESC) AcumuladoVolumenesTotales,
            TotalRegistros, -- Conteo total de registros
            -- Acumulado de TotalRegistros dentro de cada tipo, ordenado por MontosTotales descendente
            SUM(TotalRegistros) OVER (PARTITION BY Tipos ORDER BY MontosTotales DESC) AcumuladoRegistros,
            MontosTotales, -- Suma total de los montos
            -- Acumulado de MontosTotales dentro de cada tipo, ordenado por MontosTotales descendente
            SUM(MontosTotales) OVER (PARTITION BY Tipos ORDER BY MontosTotales DESC) TotalesGenerales,
            -- Porcentaje de MontosTotales respecto al total de MontosTotales dentro de cada tipo
            (MontosTotales / SUM(MontosTotales) OVER (PARTITION BY Tipos)) * 100 Porcentaje,
            -- Porcentaje acumulado de MontosTotales dentro de cada tipo, ordenado por MontosTotales descendente
            (SUM(MontosTotales) OVER (PARTITION BY Tipos ORDER BY MontosTotales DESC) / SUM(MontosTotales) OVER (PARTITION BY Tipos)) * 100 Acumulado
        FROM
            Clientes -- Utiliza el CTE Clientes
        ORDER BY
            MontosTotales DESC; -- Ordena por tipo de transacción y montos totales descendente
        ";

        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_graph1($from, $until, $clients) {
        $query = "
            DECLARE @from INT = '{$from}';
            DECLARE @until INT = '{$until}';
            DECLARE @clients VARCHAR(MAX) = '{$clients}';
            SELECT
                YEAR(DATEADD(DAY, t1.fchcor, '1899-31-12')) AS Year,
                MONTH(DATEADD(DAY, t1.fchcor, '1899-31-12')) AS Month,
                DATENAME(MONTH, DATEADD(DAY, t1.fchcor, '1899-31-12')) AS MonthName,
                SUM(CASE WHEN t2.tipval = 3 THEN t1.mto ELSE 0 END) AS MontosDebito,
                SUM(CASE WHEN t2.tipval = 4 THEN t1.mto ELSE 0 END) AS MontosCredito
            FROM
                [SG12].[dbo].[Despachos] t1
            INNER JOIN
                SG12.dbo.Clientes t2 ON t1.codcli = t2.cod
            WHERE
                t2.tipval IN (3, 4) AND
                t1.fchcor BETWEEN @from AND @until
                AND (@clients = '0' OR t1.codcli IN ({$clients}))
            GROUP BY
                YEAR(DATEADD(DAY, t1.fchcor, '1899-31-12')),
                MONTH(DATEADD(DAY, t1.fchcor, '1899-31-12')),
                DATENAME(MONTH, DATEADD(DAY, t1.fchcor, '1899-31-12'))
            ORDER BY
                YEAR(DATEADD(DAY, t1.fchcor, '1899-31-12')),
                MONTH(DATEADD(DAY, t1.fchcor, '1899-31-12'));
        ";
        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_saldos_islas($CodigoEstacion, $fch, $nrotur, $Islands, $FechaTabular, $Turno, $tabId) {

        $shifts = [11, 21, 31, 41];
        $index = array_search($nrotur, $shifts);

        $next_shift = $shifts[($index + count($shifts) + 1) % count($shifts)];

        if ($next_shift == 11) {
            $next_date = ($fch + 1);
        } else {
            $next_date = $fch;
        }

        $query = "
            WITH IslasRemotas AS ( -- Paso 1: Obtener Islas del Servidor Remoto y Ventas Reportadas
                SELECT den, cod AS codisl
                FROM OPENQUERY({$this->linked_server[$CodigoEstacion]}, 'SELECT den, cod FROM {$this->short_databases[$CodigoEstacion]}.[Islas] WHERE codgas = {$CodigoEstacion};')
            ),
            
            VentasReportadas AS ( -- Paso 2: Consulta de Ventas Reportadas Agrupadas por Isla
                SELECT
                    codisl,
                    SUM(Total) AS TotalVentasReportadas
                FROM (
                    SELECT
                        t1.codisl,
                        COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                    FROM {$this->databases[$CodigoEstacion]}.[MovimientosTar] t1
                    LEFT JOIN {$this->databases[$CodigoEstacion]}.[Islas] t2 ON t1.codisl = t2.cod
                    WHERE
                        t1.fchmov = {$FechaTabular} AND
                        t1.codgas = {$CodigoEstacion} AND
                        t1.nrotur = {$Turno} AND
                        t1.codisl IN ({$Islands}) AND
                        NOT (t1.codbco = 0 AND t1.tiptar IN (84, 68, 67, 72))
                    GROUP BY
                        t1.codisl
            
                    UNION ALL
            
                    SELECT
                        t1.codisl,
                        COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                    FROM {$this->databases[$CodigoEstacion]}.[Despachos] t1
                    LEFT JOIN {$this->databases[$CodigoEstacion]}.[Clientes] t2 ON t1.codcli = t2.cod
                    LEFT JOIN {$this->databases[$CodigoEstacion]}.[Islas] t4 ON t1.codisl = t4.cod
                    WHERE
                        t1.fchtrn = {$FechaTabular} AND
                        t1.codgas = {$CodigoEstacion} AND
                        t1.nrotur = {$Turno} AND
                        t1.codisl IN ({$Islands}) AND
                        t2.tipval IN (3, 4)
                    GROUP BY
                        t1.codisl
            
                    UNION ALL
            
                    SELECT
                        t1.Isla AS codisl,
                        COALESCE(SUM(t1.Monto), 0) AS Total
                    FROM [TG].[dbo].[TabuladorDetalle] t1
                    RIGHT JOIN [SG12].[dbo].[Islas] t2 ON t1.Isla = t2.cod
                    WHERE
                        t1.Id = {$tabId} AND
                        t1.CodigoValor IN (6, 192) AND
                        t1.Isla IN ({$Islands})
                    GROUP BY
                        t1.Isla
                ) AS combined
                GROUP BY
                    codisl
            ),
            
            VentasTotales AS ( -- Paso 3: Consulta de Ventas Totales Agrupadas por Isla
                SELECT
                    codisl,
                    SUM(finalAmount) AS TotalVentas
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
                GROUP BY codisl
            )
            
            SELECT -- Paso 4: Combinar las CTEs y Calcular la Diferencia
                ir.codisl,
                ir.den Isla,
                COALESCE(vr.TotalVentasReportadas, 0) AS TotalVentasReportadas,
                COALESCE(vt.TotalVentas, 0) AS TotalVentas,
                COALESCE(vr.TotalVentasReportadas, 0) - COALESCE(vt.TotalVentas, 0) AS Diferencia
            FROM IslasRemotas ir
            LEFT JOIN VentasReportadas vr ON ir.codisl = vr.codisl
            LEFT JOIN VentasTotales vt ON ir.codisl = vt.codisl
            ORDER BY ir.codisl;
        ";
        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_saldos_isla($CodigoEstacion, $fch, $nrotur, $Island, $FechaTabular, $Turno, $tabId) {

        $shifts = [11, 21, 31, 41];
        $index = array_search($nrotur, $shifts);

        $next_shift = $shifts[($index + count($shifts) + 1) % count($shifts)];

        if ($next_shift == 11) {
            $next_date = ($fch + 1);
        } else {
            $next_date = $fch;
        }

        $islaEspecifica = $Island; // Aquí pones el valor de la isla específica
        $query = "
            WITH VentasReportadas AS ( -- Paso 1: Consulta de Ventas Reportadas Agrupadas por Isla
                SELECT
                    codisl,
                    SUM(Total) AS TotalVentasReportadas
                FROM (
                    SELECT
                        t1.codisl,
                        COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                    FROM OPENQUERY({$this->linked_server[$CodigoEstacion]}, '
                        SELECT
                            t1.codisl,
                            t1.mto
                        FROM {$this->short_databases[$CodigoEstacion]}.[MovimientosTar] t1
                        LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[Islas] t2 ON t1.codisl = t2.cod
                        WHERE
                            t1.fchmov = {$FechaTabular} AND
                            t1.codgas = {$CodigoEstacion} AND
                            t1.nrotur = {$Turno} AND
                            t1.codisl = {$islaEspecifica} AND
                            NOT (t1.codbco = 0 AND t1.tiptar IN (84, 68, 67, 72))
                    ') t1
                    GROUP BY
                        t1.codisl
        
                    UNION ALL
        
                    SELECT
                        t1.codisl,
                        COALESCE(CAST(SUM(t1.mto) AS FLOAT), 0) AS Total
                    FROM OPENQUERY({$this->linked_server[$CodigoEstacion]}, '
                        SELECT
                            t1.codisl,
                            t1.mto
                        FROM {$this->short_databases[$CodigoEstacion]}.[Despachos] t1
                        LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[Clientes] t2 ON t1.codcli = t2.cod
                        LEFT JOIN {$this->short_databases[$CodigoEstacion]}.[Islas] t4 ON t1.codisl = t4.cod
                        WHERE
                            t1.fchtrn = {$FechaTabular} AND
                            t1.codgas = {$CodigoEstacion} AND
                            t1.nrotur = {$Turno} AND
                            t1.codisl = {$islaEspecifica} AND
                            t2.tipval IN (3, 4)
                    ') t1
                    GROUP BY
                        t1.codisl
        
                    UNION ALL
        
                    SELECT
                        t1.Isla AS codisl,
                        COALESCE(SUM(t1.Monto), 0) AS Total
                    FROM [TG].[dbo].[TabuladorDetalle] t1
                    RIGHT JOIN [SG12].[dbo].[Islas] t2 ON t1.Isla = t2.cod
                    WHERE
                        t1.Id = {$tabId} AND
                        t1.CodigoValor IN (6, 192) AND
                        t1.Isla = {$islaEspecifica}
                    GROUP BY
                        t1.Isla
                ) AS combined
                GROUP BY
                    codisl
            ),
        
            VentasTotales AS ( -- Paso 2: Consulta de Ventas Totales Agrupadas por Isla
            
                SELECT
                    codisl,
                    SUM(finalAmount) AS TotalVentas
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
                                AND codisl = {$islaEspecifica}
                            GROUP BY nrobom, codprd, codisl
                        ) t2 ON t1.nrobom = t2.nrobom AND t1.codprd = t2.codprd
                    WHERE
                        t1.fch = {$fch} AND t1.nrotur = {$nrotur} AND t1.codisl = {$islaEspecifica}
        
                    UNION ALL
        
                    SELECT
                        COALESCE(t1.mto, 0) AS finalAmount,
                        t1.codisl
                    FROM {$this->short_databases[$CodigoEstacion]}.[Despachos] t1
                    WHERE t1.fchcor = {$next_date} AND t1.nrotur = {$next_shift} AND t1.codisl = {$islaEspecifica} AND t1.codprd NOT IN (0,179,180,181,192,193)
                ') AS SubQuery
                WHERE codisl IS NOT NULL
                GROUP BY codisl
            )
        
            SELECT -- Paso 3: Combinar las CTEs y Calcular la Diferencia
                '{$islaEspecifica}' AS codisl,
                ir.den Isla,
                COALESCE(vr.TotalVentasReportadas, 0) AS TotalVentasReportadas,
                COALESCE(vt.TotalVentas, 0) AS TotalVentas,
                COALESCE(vr.TotalVentasReportadas, 0) - COALESCE(vt.TotalVentas, 0) AS Diferencia
            FROM (
                SELECT '{$islaEspecifica}' AS codisl, den
                FROM OPENQUERY({$this->linked_server[$CodigoEstacion]}, 'SELECT den, cod FROM {$this->short_databases[$CodigoEstacion]}.[Islas] WHERE codgas = {$CodigoEstacion} AND cod = {$islaEspecifica};')
            ) ir
            LEFT JOIN VentasReportadas vr ON ir.codisl = vr.codisl
            LEFT JOIN VentasTotales vt ON ir.codisl = vt.codisl
            ORDER BY ir.codisl;
        ";

        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_sales_stations($from, $until, $codgas, $codprd) : array | false {


        $query = "
        DECLARE @from INT = {$from};
        DECLARE @until INT = {$until};
        DECLARE @codgas INT = {$codgas};
        DECLARE @codprd INT = {$codprd}; -- Aquí defines la variable @codprd
                
        WITH Datos AS (
            SELECT
                CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) -1, 23) AS VARCHAR(10)) AS Fecha,
                t1.fchcor AS FechaCompleta, -- Agregar la fecha completa
                t4.cod AS CodigoGasolinera, -- Agregar el código de la gasolinera
                t4.abr AS Gasolinera,
                t2.cod AS CodigoProducto, -- Agregar el código del producto
                t2.den AS Producto,
                COUNT(*) AS TotalDespachos,
                SUM(t1.can) AS Volumen,
                ROUND(SUM(t1.mto), 2) AS Importe,
                ROUND(SUM(CASE WHEN t3.tipval = 3 THEN t1.can ELSE 0 END), 3) AS Credito,
                ROUND(SUM(CASE WHEN t3.tipval = 4 THEN t1.can ELSE 0 END), 3) AS Debito,
                MIN(CASE WHEN t1.pre > 0 AND t1.codprd IN (179, 180, 181, 192, 193) THEN t1.pre ELSE NULL END) AS precio_minimo,
                MAX(CASE WHEN t1.pre > 0 AND t1.codprd IN (179, 180, 181, 192, 193) THEN t1.pre ELSE NULL END) AS precio_maximo
            FROM
                Despachos t1
                LEFT JOIN Productos t2 ON t1.codprd = t2.cod
                LEFT JOIN [SG12].[dbo].[Clientes] t3 ON t1.codcli = t3.cod
                LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
            WHERE
                t1.fchcor BETWEEN @from AND @until
                AND (@codgas = 0 OR t1.codgas = @codgas) -- Aquí se agrega la condición para @codgas
                AND (
                    (@codprd = 0 AND t1.codprd IN (179, 180, 181, 192, 193)) 
                    OR 
                    (@codprd != 0 AND t1.codprd = @codprd)
                )
            GROUP BY
                t1.fchcor, t1.codgas, t4.abr, t4.cod, t2.cod, t2.den
        )
        
        SELECT
            Fecha,
            LTRIM(Gasolinera) AS Gasolinera,
            CodigoProducto,
            CodigoGasolinera,
            FechaCompleta,
            LTRIM(Producto) AS Producto,
            TotalDespachos,
            ROUND(Volumen, 3) AS Volumen,
            CASE 
                WHEN ROUND(precio_minimo, 2) < ROUND(precio_maximo, 2) THEN CONCAT(precio_minimo, ' - ', precio_maximo)
                ELSE CAST(precio_maximo AS VARCHAR)
            END AS Precio,
            Importe,
            Credito,
            Debito
        FROM
            Datos
        ORDER BY Fecha ASC, Gasolinera DESC;";
        

        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_sales_details($fch, $codgas, $prd) : array | false {
        $query = "
        DECLARE @from INT = {$fch};
        DECLARE @codgas INT = {$codgas};
        DECLARE @codprd INT = {$prd}; -- Aquí defines la variable @codprd
        
        SELECT
                CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) -1, 23) AS VARCHAR(10)) AS Fecha,
                t1.fchcor AS FechaCompleta, -- Agregar la fecha completa
                t4.cod AS CodigoGasolinera, -- Agregar el código de la gasolinera
                t4.abr AS Gasolinera,
                t2.cod AS CodigoProducto, -- Agregar el código del producto
                t2.den AS Producto,
                t1.can AS Volumen,
                ROUND(t1.mto, 2) AS Importe,
                t1.pre Precio,
                CASE
                    WHEN t3.tipval = 3 THEN 'Crédito'
                    WHEN t3.tipval = 4 THEN 'Débito'
                    ELSE 'Otro'
                END AS Tipo
            FROM
                Despachos t1
                LEFT JOIN Productos t2 ON t1.codprd = t2.cod
                LEFT JOIN [SG12].[dbo].[Clientes] t3 ON t1.codcli = t3.cod
                LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
            WHERE
                t1.fchcor = @from
                AND t1.codgas = @codgas
                AND t1.codprd = @codprd
        ";

        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_credit_dispatches_to_release($from, $codgas, $shift) : array|false {

        $Fecha = dateToInt($from);
        $query = "
        DECLARE @Fecha INT = {$Fecha};
        DECLARE @shift INT = {$shift};
        SELECT
            t5.id,
            t1.nrotrn Despacho,
			t4.abr Estacion,
			t7.den Isla,
            t1.codcli,
            t2.den AS Cliente,
            t1.can Volumen,
			t1.mto Monto,
			COALESCE(t5.notes, '--') AS notes,
			CASE t2.tipval
                WHEN 3 THEN N'Crédito'
                WHEN 4 THEN N'Débito'
            END AS Tipo,
            CONVERT(VARCHAR(5), DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0)), 108) AS hora_formateada,
            CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
            (t1.nrotur / 10) AS turno,
            t3.abr,
            t3.den Producto,
	        COALESCE(Nombre, 'Sin verificar') AS Verificador,
	        CASE 
                WHEN DATEDIFF(DAY, CAST(CONVERT(VARCHAR(100), CAST(t5.fchcor AS DATETIME) - 1, 23) AS DATE), CAST(t5.created_at AS DATE)) > 2 
                THEN 1 
                ELSE 0 
            END AS incidencia,
            t1.rut,
            t1.nroveh
        FROM
            [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
            LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
            LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod
            LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
            LEFT JOIN [TG].[dbo].[despachos_liberados] t5 ON t1.codgas = t5.codgas AND t1.nrotrn = t5.nrotrn
	        LEFT JOIN [TG].[dbo].[Usuario] t6 ON t5.codemp = t6.Id
	        LEFT JOIN [SG12].[dbo].[Islas] t7 ON t1.codisl = t7.cod
        WHERE
            t1.fchcor = @Fecha
            AND t1.codcli > 0
            AND t2.tipval IN (3)
            AND t1.codgas = {$codgas}
            AND (
                @shift = 0 
                OR t1.nrotur BETWEEN (@shift * 10) AND ((@shift * 10) + 9)
            )
            AND t1.mto > 0
        ";
        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_debit_dispatches_to_release($from, $codgas, $shift) : array|false {

        $Fecha = dateToInt($from);
        $query = "
        DECLARE @Fecha INT = {$Fecha};
        DECLARE @shift INT = {$shift};
        SELECT
            t5.id,
            t1.nrotrn Despacho,
			t4.abr Estacion,
			t7.den Isla,
            t1.codcli,
            t2.den AS Cliente,
            t1.can Volumen,
			t1.mto Monto,
			t1.nroveh,
			t1.rut,
			COALESCE(t5.notes, '--') AS notes,
			CASE t2.tipval
                WHEN 3 THEN N'Crédito'
                WHEN 4 THEN N'Débito'
            END AS Tipo,
            CONVERT(VARCHAR(5), DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0)), 108) AS hora_formateada,
            CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
            (t1.nrotur / 10) AS turno,
            t3.abr,
            t3.den Producto,
	        COALESCE(Nombre, 'Sin verificar') AS Verificador,
	        CASE 
                WHEN DATEDIFF(DAY, CAST(CONVERT(VARCHAR(100), CAST(t5.fchcor AS DATETIME) - 1, 23) AS DATE), CAST(t5.created_at AS DATE)) > 2 
                THEN 1 
                ELSE 0 
            END AS incidencia
        FROM
            [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
            LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
            LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod
            LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
            LEFT JOIN [TG].[dbo].[despachos_liberados] t5 ON t1.codgas = t5.codgas AND t1.nrotrn = t5.nrotrn
	        LEFT JOIN [TG].[dbo].[Usuario] t6 ON t5.codemp = t6.Id
	        LEFT JOIN [SG12].[dbo].[Islas] t7 ON t1.codisl = t7.cod
        WHERE
            t1.fchcor = @Fecha
            AND t1.codcli > 0
            AND t2.tipval IN (4)
            AND t1.codgas = {$codgas}
            AND (
                @shift = 0 OR
                t1.nrotur BETWEEN (@shift * 10) AND ((@shift * 10) + 9)
            )
            AND t1.mto > 0;
        ";
        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_credit_and_debit_dispatches_released($from, $codgas, $shift, $dispatch_type) : array|false {

        $dispatch_type = ($dispatch_type == 'Payworks') ? '0' : '3, 4';

        $Fecha = dateToInt($from);
        $query = "DECLARE @Fecha INT = {$Fecha};
                  DECLARE @shift INT = {$shift};
                  SELECT
                    t1.id,
                    t1.nrotrn Despacho,
                    t4.abr Estacion,
                    t7.den Isla,
                    t1.codcli,
                    t2.den AS Cliente,
                    t1.can Volumen,
                    t1.mto Monto,
                    COALESCE(t1.notes, '--') AS notes,
                    CASE t2.tipval
                        WHEN 3 THEN N'Crédito'
                        WHEN 4 THEN N'Débito'
                    END AS Tipo,
                    t1.hratrn AS hora_formateada,
                    CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                    (t1.nrotur / 10) AS turno,
                    t3.abr,
                    t3.den Producto,
                    COALESCE(t5.Nombre, 'Sin verificar') AS Verificador,
                    CASE 
                        WHEN DATEDIFF(DAY, CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS DATE), CAST(t1.created_at AS DATE)) > 2 
                        THEN 1 
                        ELSE 0 
                    END AS incidencia
                  FROM
                    [TG].[dbo].[despachos_liberados] t1
                    LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
                    LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod
                    LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
                    LEFT JOIN [TG].[dbo].[Usuario] t5 ON t1.codemp = t5.Id
                    LEFT JOIN [SG12].[dbo].[Islas] t7 ON t1.codisl = t7.cod
                  WHERE
                    fchcor = @Fecha AND
                    t1.codgas = {$codgas} AND
                    (
                        @shift = 0 
                        OR t1.nrotur BETWEEN (@shift * 10) AND ((@shift * 10) + 9)
                    )
                    AND t1.tipval IN ({$dispatch_type})
                  ;";


        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_credit_dispatches_just_to_release($from, $codgas, $shift) : array|false {
        $Fecha = dateToInt($from);
        $query = "DECLARE @Fecha INT = {$Fecha};
                  DECLARE @shift INT = {$shift};
                  SELECT
                    t2.id,
                    t1.nrotrn Despacho,
                    t4.abr Estacion,
                    t7.den Isla,
                    t1.codcli,
                    t6.den AS Cliente,
                    t1.can Volumen,
                    t1.mto Monto,
                    COALESCE(t2.notes, '--') AS notes,
                    CASE t6.tipval
                        WHEN 3 THEN N'Crédito'
                        WHEN 4 THEN N'Débito'
                    END AS Tipo,
                    CONVERT(VARCHAR(5), DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0)), 108) AS hora_formateada,
                    CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                    (t1.nrotur / 10) AS turno,
                    t5.abr,
                    t5.den Producto,
                    'Sin verificar' AS Verificador, 
                    CASE 
                        WHEN DATEDIFF(DAY, CAST(CONVERT(VARCHAR(100), CAST(t2.fchcor AS DATETIME) - 1, 23) AS DATE), CAST(t2.created_at AS DATE)) > 2 
                        THEN 1 
                        ELSE 0 
                    END AS incidencia
                  FROM SG12.dbo.Despachos t1
                    LEFT JOIN (SELECT * FROM [TG].[dbo].[despachos_liberados] WHERE fchcor = @Fecha AND codgas = {$codgas}) t2 ON t1.nrotrn = t2.nrotrn AND t1.codgas = t2.codgas
                    LEFT JOIN SG12.dbo.Clientes t3 ON t1.codcli = t3.cod
                    LEFT JOIN SG12.dbo.Gasolineras t4 ON t1.codgas = t4.cod
                    LEFT JOIN SG12.dbo.Productos t5 ON t1.codprd = t5.cod
                    LEFT JOIN SG12.dbo.Clientes t6 ON t1.codcli = t6.cod
                    LEFT JOIN [SG12].[dbo].[Islas] t7 ON t1.codisl = t7.cod
                  WHERE
                    t2.nrotrn IS NULL
                    AND t2.codgas IS NULL
                    AND t1.fchcor = @Fecha
                    AND t1.codgas = {$codgas}
                    AND (
                        @shift = 0 
                        OR t1.nrotur BETWEEN (@shift * 10) AND ((@shift * 10) + 9)
                    )
                    AND t1.codcli > 0
                    AND t3.tipval IN (3)
                    AND t1.mto > 0
                  ORDER BY t1.nrotrn ASC;
                    ;";
        return ($this->sql->select($query, [])) ?: false ;
    }

    function get_debit_dispatches_just_to_release($from, $codgas, $shift) : array|false {
        $Fecha = dateToInt($from);
        $query = "DECLARE @Fecha INT = {$Fecha};
                  DECLARE @Shift INT = {$shift};
                  SELECT
                    t2.id,
                    t1.nrotrn Despacho,
                    t4.abr Estacion,
                    t7.den Isla,
                    t1.codcli,
                    t6.den AS Cliente,
                    t1.can Volumen,
                    t1.mto Monto,
                    COALESCE(t2.notes, '--') AS notes,
                    CASE t6.tipval
                        WHEN 3 THEN N'Crédito'
                        WHEN 4 THEN N'Débito'
                    END AS Tipo,
                    CONVERT(VARCHAR(5), DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0)), 108) AS hora_formateada,
                    CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                    (t1.nrotur / 10) AS turno,
                    t5.abr,
                    t5.den Producto,
                    'Sin verificar' AS Verificador, 
                    CASE 
                        WHEN DATEDIFF(DAY, CAST(CONVERT(VARCHAR(100), CAST(t2.fchcor AS DATETIME) - 1, 23) AS DATE), CAST(t2.created_at AS DATE)) > 2 
                        THEN 1 
                        ELSE 0 
                    END AS incidencia
                  FROM SG12.dbo.Despachos t1
                    LEFT JOIN (SELECT * FROM [TG].[dbo].[despachos_liberados] WHERE fchcor = @Fecha AND codgas = {$codgas}) t2 ON t1.nrotrn = t2.nrotrn AND t1.codgas = t2.codgas
                    LEFT JOIN SG12.dbo.Clientes t3 ON t1.codcli = t3.cod
                    LEFT JOIN SG12.dbo.Gasolineras t4 ON t1.codgas = t4.cod
                    LEFT JOIN SG12.dbo.Productos t5 ON t1.codprd = t5.cod
                    LEFT JOIN SG12.dbo.Clientes t6 ON t1.codcli = t6.cod
                    LEFT JOIN [SG12].[dbo].[Islas] t7 ON t1.codisl = t7.cod
                  WHERE
                    t2.nrotrn IS NULL
                    AND t2.codgas IS NULL
                    AND t1.fchcor = @Fecha
                    AND t1.codgas = {$codgas}
                    AND (
                        @shift = 0 
                        OR t1.nrotur BETWEEN (@shift * 10) AND ((@shift * 10) + 9)
                    )
                    AND t1.codcli > 0
                    AND t3.tipval IN (4)
                    AND t1.mto > 0
                  ORDER BY t1.nrotrn ASC;
                    ;";
        return ($this->sql->select($query, [])) ?: false ;
    }

    function check_dispatch($nrotrn, $codgas, $fecha) : array|false {
        $query = "SELECT
                    t1.*,
                    CONVERT(VARCHAR(5), DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0)), 108) AS hora_formateada,
                    t2.tipval
                FROM [SG12].[dbo].[Despachos] t1 LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod WHERE t1.nrotrn = {$nrotrn} AND t1.codgas = {$codgas} AND t1.fchcor = {$fecha};";

        return $this->sql->select($query, []);
    }

    function check_dispatch_released($nrotrn, $codgas) : bool {
        $query = "SELECT * FROM [TG].[dbo].[despachos_liberados] WHERE nrotrn = {$nrotrn} AND codgas = {$codgas};";
        return (bool)$this->sql->select($query, []);
    }

    function release_dispatch_TG($dispatch) : bool {
        $query = "INSERT INTO [TG].[dbo].[despachos_liberados] ([nrotrn],[codgas],[codisl],[nrotur],[codcli],[tipval],[codprd],[can],[mto],[codemp],[fchtrn],[fchcor],[hratrn]) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?);";
        $params = [$dispatch['nrotrn'], $dispatch['codgas'], $dispatch['codisl'], $dispatch['nrotur'], $dispatch['codcli'], (is_null($dispatch['tipval']) ? 0 : $dispatch['tipval']), $dispatch['codprd'], $dispatch['can'], $dispatch['mto'], $_SESSION['tg_user']['Id'], $dispatch['fchtrn'], $dispatch['fchcor'], $dispatch['hora_formateada']];

        return $this->sql->insert($query, $params);
    }

    function get_dispatch_by_nrotrn_and_date($nrotrn, $fchcor) {
        $query = "DECLARE @Fecha INT = {$fchcor};
        SELECT
            t1.*, t2.tipval, t3.abr Estacion
        FROM
            [SG12].[dbo].[Despachos] t1
            LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
            LEFT JOIN [SG12].[dbo].[Gasolineras] t3 ON t1.codgas = t3.cod
        WHERE t1.nrotrn = {$nrotrn} AND t1.fchcor = @Fecha;";
        $data = $this->sql->select($query, []);
        // Vamos a contar cuantos registros existen con este nrotrn y fchcor
        $count = count($data);
        // Si no hay registros, retornamos false
        if ($count == 0) return false;
        // Si hay más de un registro, retornamos false
        if ($count > 1) return false;
        // Si hay un solo registro, retornamos los datos
        return $data[0];
    }

    function get_dispatch_by_nrotrn($nrotrn, $codgas) : array|false {
        $query = "SELECT * FROM [SG12].[dbo].[Despachos] WHERE nrotrn = ? AND codgas = ?;";
        $params = [$nrotrn, $codgas];
        return $this->sql->select($query, $params);
    }

    function save_notes($id, $notes) : bool {
        $query = "UPDATE [TG].[dbo].[despachos_liberados] SET [notes] = ? WHERE id = ?";
        $params = [$notes, $id];
        return $this->sql->update($query, $params);
    }

    function get_payworks_dispatches_to_release($codgas, $fch, $nrotur) : array |false {

        $query = "
            DECLARE @fch INT = '{$fch}';
            DECLARE @shift INT = '{$nrotur}';
            SELECT
                t6.id,
                t1.nrotrn Despacho,
                t5.den Estacion,
                t7.den Isla,
                t2.codcli,
                t4.den AS Cliente,
                t3.cod,
                t3.den Tipo,
                t1.mto Monto,
                t2.can Volumen,
                t2.rut,
                t2.nroveh,
                COALESCE(t6.notes, '--') AS notes,
                CONVERT(VARCHAR(5), DATEADD(MINUTE, t2.hratrn % 100, DATEADD(HOUR, t2.hratrn / 100, 0)), 108) AS hora_formateada,
                CAST(CONVERT(VARCHAR(100), CAST(t1.fchcor AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
                (t1.nrotur / 10) AS turno,
                t8.abr,
                t8.den Producto,
                COALESCE(t9.Nombre, 'Sin verificar') AS Verificador,
                CASE
                    WHEN DATEDIFF(DAY, CAST(CONVERT(VARCHAR(100), CAST(t6.fchcor AS DATETIME) - 1, 23) AS DATE), CAST(t6.created_at AS DATE)) > 2
                    THEN 1
                    ELSE 0
                END AS incidencia
            FROM [SG12].[dbo].[MovimientosTar] t1
                LEFT JOIN [SG12].[dbo].[Despachos] t2 ON t1.nrotrn = t2.nrotrn AND t1.codgas = t2.codgas
                LEFT JOIN [SG12].[dbo].[Valores] t3 ON t1.codbco = t3.cod
                LEFT JOIN [SG12].[dbo].[Clientes] t4 ON t2.codcli = t4.cod
                LEFT JOIN [SG12].[dbo].[Gasolineras] t5 ON t1.codgas = t5.cod
                LEFT JOIN [TG].[dbo].[despachos_liberados] t6 ON t1.nrotrn= t6.nrotrn AND t1.codgas = t6.codgas
                LEFT JOIN [SG12].[dbo].[Islas] t7 ON t1.codisl = t7.cod
                LEFT JOIN [SG12].[dbo].[Productos] t8 ON t2.codprd = t8.cod
                LEFT JOIN [TG].[dbo].[Usuario] t9 ON t6.codemp = t9.Id
            WHERE
                t1.fchmov = @fch
                AND t1.codgas = {$codgas}
                AND (
                        @shift = 0 
                        OR t1.nrotur BETWEEN (@shift * 10) AND ((@shift * 10) + 9)
                    )
                AND NOT (t1.codbco = 0 AND t1.tiptar IN (84, 68, 67, 72)) AND t3.cod NOT IN (-1128) AND t1.mto > 0
            ORDER BY t2.nrotrn ASC;
        ";
        return ($this->sql->select($query, [])) ?: false ;
    }

    function overal_invoice_out_table($from, $until,$stations,$status){
        $queryParts = [];
        $estusQuery = '';
        if ($status == 0) {
            $estusQuery = " AND (
                            CASE 
                                WHEN NOT EXISTS (
                                    SELECT 1
                                    FROM STRING_SPLIT(t3.FechasConcatenadas, '','') fechas
                                    WHERE DATEPART(MONTH, CAST(fechas.value AS DATE)) != DATEPART(MONTH,  CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchcor), 23))
                                )
                                THEN ''Correcto''
                                ELSE ''Incorrecto''
                            END
                        ) = ''Incorrecto''";
        }
        foreach ($stations as $key => $station) {
            if ($station['codigo'] == 39) {
               continue;
            }
            $station_name = str_replace(' ', '_', $station['estacion_nombre']);

            $server_ip = $station['servidor'];
            $database = $station['base_datos'];

            $collationsatuid ='';
            $collationtxtref ='';
            if ( $station['codigo']==33) {
                $collationsatuid ='COLLATE  Modern_Spanish_CI_AS as satuid';
                $collationtxtref ='COLLATE  Modern_Spanish_CI_AS as txtref';
            }

            $subQuery  = "SELECT * 
                    FROM OPENQUERY([$server_ip],
                        '
                        SELECT  
                            t1.nro,
                            CASE 
                                WHEN t1.nro BETWEEN 2100000000 AND 2499999999 THEN ''Z '' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                                WHEN t1.nro BETWEEN 2000000000 AND 2099999999 THEN ''T '' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                                WHEN t1.nro BETWEEN 1900000000 AND 1999999999 THEN ''K '' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                                WHEN t1.nro BETWEEN 1100000000 AND 1199999999 THEN ''C '' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                                WHEN t1.nro BETWEEN 1700000000 AND 1799999999 THEN ''I '' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                                WHEN t1.nro BETWEEN 1300000000 AND 1399999999 THEN ''E '' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)

                                ELSE CAST(t1.nro AS VARCHAR(10)) 
                            END AS ''factura'',
                            t1.satuid $collationsatuid,
                            t1.tip,
                            CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fch), 23) AS fecha ,
                            CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchcor), 23) AS vigencia,
                            t3.FechasConcatenadas,
                            t1.txtref $collationtxtref,
                            CASE 
                                WHEN t1.tipref = 1 THEN ''Efectivo''
                                WHEN t1.tipref = 2 THEN ''Cheque Nominativo''
                                WHEN t1.tipref = 3 THEN ''Transferencia Electronica''
                                WHEN t1.tipref = 4 THEN ''Tarjeta de crédito''
                                WHEN t1.tipref = 28 THEN ''Tarjeta de débito''
                                ELSE ''Otro''
                            END AS TipoPago,
                            t3.NrotrnConcatenados,
                            ''$station_name'' as estacion,
                            CASE 
                            WHEN NOT EXISTS (
                                SELECT 1
                                FROM STRING_SPLIT(t3.FechasConcatenadas, '','') fechas
                                WHERE DATEPART(MONTH, CAST(fechas.value AS DATE)) != DATEPART(MONTH,  CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchcor), 23))
                            )
                            THEN ''Correcto''
                            ELSE ''Incorrecto''
                        END AS estado
                        FROM 
                            [$database].[dbo].[DocumentosC] t1
                        LEFT JOIN (
                            SELECT 
                                t5.nrofac,
                                STUFF((
                                    SELECT DISTINCT
                                        '', '' + CONVERT(VARCHAR(10), DATEADD(day, -1, fchtrn), 23)
                                    FROM [$database].[dbo].[despachos] t2
                                    WHERE t2.nrofac = t5.nrofac
                                    FOR XML PATH(''''), TYPE).value(''.'', ''NVARCHAR(MAX)''), 1, 2, '''') AS FechasConcatenadas,
                            COUNT(*) as NrotrnConcatenados
                            FROM [$database].[dbo].[despachos] t5
                            GROUP BY t5.nrofac
                        ) t3 ON t1.nro = t3.nrofac
                        LEFT JOIN [$database].[dbo].Gasolineras t4 ON t1.codgas = t4.cod
                        WHERE t1.codopr = 21701354
                        AND t1.fch BETWEEN $from AND $until
                        AND t1.tip = 3
                        $estusQuery
                        ORDER BY t1.nro ASC
                        '
                    )";
            $queryParts[] = $subQuery;
        }
        $finalQuery = implode(" UNION ALL ", $queryParts);

   

         $params = [];
        return ($this->sql->select($finalQuery,$params)) ? : false;
    }


    function pivot_facturacion_diaria_table($from, $until,$from_date,$until_date,$estations){
        $select_columns = ["fecha"];
        $pivot_columns = [];
        $final_select_columns = [];

        foreach ($estations as $station) {
            $station_name = str_replace(' ', '_', $station['estacion_nombre']);
            $select_columns[] = "ISNULL([$station_name], 0) as [$station_name]";
            $pivot_columns[] = "[$station_name]";
            $final_select_columns[] = "ISNULL(dq.[$station_name], 0) as [$station_name]";
        }
        // Construir las subconsultas con OPENQUERY
        $union_queries = [];

        foreach ($estations as $station) {
            $collation ='';
            if ( $station['codigo']==33) {
                $collation ='COLLATE  Modern_Spanish_CI_AS as fecha';
            }

            $station_name = str_replace(' ', '_', $station['estacion_nombre']);
            $server_ip = $station['servidor'];
            $database = $station['base_datos'];
            if($station['vis_fac']== 1){
                $union_queries[] = " 
                 SELECT * FROM OPENQUERY([$server_ip],
                'SELECT  fecha $collation , ''$station_name'' as estacion, total
                FROM [$database].[dbo].[v_factura_global_diaria] WITH (NOLOCK) WHERE fecha BETWEEN ''$from_date'' AND ''$until_date'' ')
                ";
            }else{

                $union_queries[] = "
                SELECT * FROM OPENQUERY([$server_ip],
                'SELECT
                    CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchtrn), 23) AS fecha,
                    ''$station_name'' as estacion,
                    CAST(ROUND(SUM(CASE WHEN t3.codopr = 21701354 THEN t1.mto ELSE 0 END), 2) AS DECIMAL(18, 2)) as total
                FROM [$database].[dbo].[Despachos] t1 WITH (NOLOCK)
                LEFT JOIN [$database].[dbo].DocumentosC t3 ON t1.nrofac = t3.nro AND t1.codgas = t3.codgas
                WHERE t3.codopr = 21701354 and t1.fchtrn BETWEEN $from AND $until
                GROUP BY CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchtrn), 23)')";
            }
        }

        // Construir el query final
        $query = "
         WITH DateRange AS (
            SELECT CAST('$from_date' AS DATE) AS fecha
            UNION ALL
            SELECT DATEADD(day, 1, fecha)
            FROM DateRange
            WHERE fecha < '$until_date'
        ),
        DataQuery AS (
            SELECT " . implode(",\n        ", $select_columns) . "
            FROM (
                " . implode("\n        UNION ALL\n        ", $union_queries) . "
            ) AS SourceTable
            PIVOT (
                MAX(total)
                FOR estacion IN (
                    " . implode(",\n            ", $pivot_columns) . "
                )
            ) AS PivotTable
        )
             SELECT 
                d.fecha,
                " . implode(",\n        ", $final_select_columns) . "
            FROM DateRange d
            LEFT JOIN DataQuery dq ON d.fecha = dq.fecha
            ORDER BY d.fecha;";

        return ($this->sql->select($query)) ?: false;
    }

    function get_all_dispatches_just_to_release($from) : array|false {
        $query = "DECLARE @Fecha INT = {$from};
                  SELECT
                    dl.id,
                    d.nrotrn AS Despacho,
                    g.abr AS Estacion,
                    i.den AS Isla,
                    d.codcli,
                    c.den AS Cliente,
                    d.can AS Volumen,
                    d.mto AS Monto,
                    COALESCE(dl.notes, '--') AS notes,
                    CASE c.tipval
                        WHEN 3 THEN N'Crédito'
                        WHEN 4 THEN N'Débito'
                    END AS Tipo,
                    CONVERT(VARCHAR(5), DATEADD(MINUTE, d.hratrn % 100, DATEADD(HOUR, d.hratrn / 100, 0)), 108) AS hora_formateada,
                    CONVERT(VARCHAR(10), DATEADD(DAY, -1, CAST(d.fchtrn AS DATETIME)), 23) AS Fecha,
                    (d.nrotur / 10) AS turno,
                    p.abr,
                    p.den AS Producto,
                    'Sin verificar' AS Verificador, 
                    CASE 
                        WHEN dl.fchcor IS NOT NULL AND DATEDIFF(DAY, DATEADD(DAY, -1, CAST(dl.fchcor AS DATETIME)), CAST(dl.created_at AS DATE)) > 2 
                        THEN 1 
                        ELSE 0 
                    END AS incidencia
                FROM SG12.dbo.Despachos d
                LEFT JOIN (
                    SELECT * 
                    FROM [TG].[dbo].[despachos_liberados] 
                    WHERE fchcor = @Fecha
                ) dl ON d.nrotrn = dl.nrotrn AND d.codgas = dl.codgas
                LEFT JOIN SG12.dbo.Clientes c ON d.codcli = c.cod
                LEFT JOIN SG12.dbo.Gasolineras g ON d.codgas = g.cod
                LEFT JOIN SG12.dbo.Productos p ON d.codprd = p.cod
                LEFT JOIN [SG12].[dbo].[Islas] i ON d.codisl = i.cod
                WHERE
                    dl.nrotrn IS NULL
                    AND d.fchcor = @Fecha
                    AND d.codcli > 0
                    AND c.tipval IN (3, 4)
                    AND d.mto > 0
                ORDER BY d.nrotrn ASC;";
        return ($this->sql->select($query, [])) ?: false ;
    }


    function control_dispatches_invoiced($from, $until, $codgas,$uuid,$tipo_cliente,$billed) :array|false {
        $uuid_true = " AND (t1.satuid IS NOT NULL OR t3.satuid IS NOT NULL)";
        $uuid_false = " AND  t1.satuid IS NULL AND t3.satuid IS NULL AND t1.tiptrn != 53";


        $billed_true = " AND (t3.nro is not null or t1.nrofac !=0)";
        $billed_false = " AND  t3.nro is null and t1.nrofac =0 ";
        $where = "";
        $where = $codgas != 0 ? "AND t1.codgas = $codgas" : "";
        $where .= ($uuid != 0) ? ($uuid == 1 ? $uuid_false : ($uuid == 2 ? $uuid_true : "")): "";
        $where .= ($billed != 0) ? ($billed == 1 ? $billed_false : ($billed == 2 ? $billed_true : "")): "";
        if (!empty($tipo_cliente)) {
            $where .= " AND 
                        CASE 
                            WHEN t10.codval = 28 THEN 'cliente_credito'
                            WHEN t10.codval = 127 THEN 'cliente_debito'
                            WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'monedero'
                            WHEN t3.codopr != 21701354 AND t10.codval IS NULL THEN 'contado'
                            WHEN t3.codopr = 21701354 THEN 'factura_global'
                            ELSE 'N/A'
                        END = '$tipo_cliente'";
        }

        $params = [$from, $until, ];
        $query = "WITH CTE AS (
                    SELECT
                        --CAST(CONVERT(VARCHAR(100), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) as fecha,
                        CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchtrn), 23) as fecha,
                        CAST(CONVERT(TIME, DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0))) AS TIME(0)) AS hora_formateada,
                        SUBSTRING(CAST(t1.nrotur AS VARCHAR(3)), 1, 1)  as 'turno',
                        t1.nrotrn as 'despacho',
                        t8.den as 'producto',
                        t6.abr as 'estacion',
                        t7.den as 'empresa',
                        t2.den as 'cliente_des',
                        t5.den as 'cliente_fac',
                        t1.can as 'cantidad',
                        t1.mto as 'importe',
                        t1.pre as 'precio',
                        t1.gasfac,
                        t1.nrofac,
                        CASE 
                            WHEN t3.nro BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                            WHEN t3.nro BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                            WHEN t3.nro BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                            WHEN t3.nro BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                            WHEN t3.nro BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                            WHEN t3.nro BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)

                            ELSE CAST(t3.nro AS VARCHAR(10)) 
                        END AS 'factura',
                        CASE 
                            WHEN t1.nrofac BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            WHEN t1.nrofac BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            WHEN t1.nrofac BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            WHEN t1.nrofac BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            WHEN t1.nrofac BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            WHEN t1.nrofac BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                            ELSE CAST(t1.nrofac AS VARCHAR(10)) 
                        END AS 'factura_desp',
                        t15.Folio,
                        case 
                        when t1.satuid is not null Then t1.satuid 
                        else t3.satuid
                        end as 'UUID',
                        t15.UUID as 'UUID_sat',
                        t3.satuid as 'UUID_fac',
                        t1.satuid as 'UUID_dep',
                        t1.rut,
                        t13.den as 'denominacion',
                        t1.codcli as 'codigo_cliente',
                        t10.codval,
                        t2.tipval,
                        CASE
                            WHEN t13.den in (' Tarjeta EfectiCard',
                                            ' SMARTBT - EFECTIVALE',
                                            ' Tarjeta TicketCar',
                                            ' Vale Efectivale',
                                            'Ultra Gas',
                                            ' Tarjeta Inburgas',
                                            ' Vale Edenred',
                                            ' Tarjetas Sodexo (Pluxee)',
                                            'Mobil FleetPro',
                                            ' SMARTBT - SODEXO WIZEO',
                                            ' Vale Sodexo') THEN 'Monedero'
                            WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'Monedero'
                            WHEN t10.codval = 28 THEN 'Cliente Crédito'
                            WHEN t10.codval = 127 THEN 'Cliente Débito'
                            WHEN  t3.codopr != 21701354 AND t10.codval is null THEN 'Contado'
                            WHEN t3.codopr = 21701354 THEN 'Factura Global'
                            else 'N/A'
                        END as 'tipo_cliente_aplicativo',
                        CASE 
                            WHEN t10.codval = 28 THEN 'Cliente Crédito'
                            WHEN t10.codval = 127 THEN 'Cliente Débito'
                            WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'Monedero'
                            WHEN  t3.codopr != 21701354 AND t10.codval is null THEN 'Contado'
                            WHEN t3.codopr = 21701354 THEN 'Factura Global'
                            else 'N/A'
                        END as 'tipo_cliente',
                        t14.den as 'efectos_c',
                        t3.txtref,
                        t3.tipref,
                        CASE
                            WHEN t3.tipref = 4 AND t1.logmsk IN (2, 3) THEN 'Tarjeta Credito'
                            WHEN t3.tipref = 28 AND t1.logmsk IN (2, 3) THEN 'Tarjeta Debito'
                            WHEN t3.tipref = 1 AND t1.logmsk IN (2, 3) THEN 'Efectivo'
                        END AS 'tipo_pago',
                        CASE
                            WHEN t1.tiptrn = 51 AND t1.gasfac != 2 THEN 'Tarjeta Credito'
                            WHEN t1.tiptrn = 52 AND t1.gasfac != 2 THEN 'Tarjeta Debito'
                            WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'Efectivale'
                            WHEN t1.tiptrn = 0 THEN 'Efectivo'
                        END AS 'tipo_pago_despacho',
                        t1.tiptrn,
                        t6.cvecli,
                        t15.FechaTimbrado,
                        ROW_NUMBER() OVER(PARTITION BY t1.nrotrn ORDER BY t1.fchtrn ASC, t1.hratrn ASC) AS rn
                        FROM [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                        LEFT JOIN [SG12].[dbo].Clientes t2 on t1.codcli = t2.cod
                        LEFT JOIN [SG12].[dbo].DocumentosC t3 on t1.nrofac = t3.nro and t1.codgas = t3.codgas
                        LEFT JOIN [SG12].[dbo].Clientes t5 on t3.codopr = t5.cod
                        LEFT JOIN [SG12].[dbo].Gasolineras t6 ON t1.codgas = t6.cod
                        LEFT JOIN [SG12].[dbo].[Empresas] t7 ON t6.codemp = t7.cod
                        LEFT JOIN [SG12].[dbo].[Productos] t8 on t1.codprd = t8.cod
                        LEFT JOIN [SG12].[dbo].[ClientesValores] t10 ON t1.codcli = t10.codcli and t10.codest !=-1 and  t10.codval in(127,28)
                        LEFT JOIN(Select nrotrn,sum(mto) as mto, codbco, codgas from [SG12].[dbo].MovimientosTar Where tipmov != 86 and tipmov !=97  and mto != 0   group by nrotrn, codgas,codbco) t12 on t1.nrotrn=t12.nrotrn and t1.codgas = t12.codgas
                        LEFT JOIN [SG12].[dbo].Valores t13 on t12.codbco = t13.cod 
                        LEFT JOIN [SG12].[dbo].[EfectosC] t14 on t3.tip = t14.tipope and t3.subope =t14.subope
					    LEFT JOIN TGV2.dbo.Facturas t15 on t1.satuid = t15.UUID
                    Where 
                    t1.mto != 0 and
                    t1.tiptrn  not in (65,74) and
                    t1.fchtrn BETWEEN  ? and  ? {$where}
                        )
            SELECT *
            FROM CTE
            WHERE rn = 1
            ORDER BY fecha, hora_formateada;
                    ";
        return ($this->sql->select($query, $params)) ?: false ;
    }


    function control_dispatches_est($from, $until, $codgas,$uuid,$tipo_cliente,$billed,$estations) :array|false {
        foreach ($estations as $station) {
            $server_ip = $station['servidor'];
            $database = $station['base_datos'];
            $station_name = str_replace(' ', '_', $station['estacion_nombre']);
        
            // Construye la parte del WHERE para cada estación.
            $uuid_condition = ($uuid != 0) 
                ? ($uuid == 1 
                    ? "AND t1.satuid IS NULL AND t3.satuid IS NULL AND t1.tiptrn != 53" 
                    : "AND (t1.satuid IS NOT NULL OR t3.satuid IS NOT NULL)"
                  ) 
                : "";
        
            $billed_condition = ($billed != 0)
                ? ($billed == 1 
                    ? "AND t3.nro IS NULL AND t1.nrofac = 0" 
                    : "AND (t3.nro IS NOT NULL OR t1.nrofac != 0)"
                  )
                : "";
        
            $tipo_cliente_condition = !empty($tipo_cliente)
                ? "AND CASE 
                        WHEN t10.codval = 28 THEN 'cliente_credito'
                        WHEN t10.codval = 127 THEN 'cliente_debito'
                        WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN 'monedero'
                        WHEN t3.codopr != 21701354 AND t10.codval IS NULL THEN 'contado'
                        WHEN t3.codopr = 21701354 THEN 'factura_global'
                        ELSE 'N/A'
                    END = ''$tipo_cliente''"
                : "";
        
            $where = "t1.fchtrn BETWEEN ''$from'' AND ''$until''
                      AND t1.mto != 0 
                      AND t1.tiptrn NOT IN (65, 74)
                      $uuid_condition
                      $billed_condition
                      $tipo_cliente_condition";
        
            $query = "SELECT
                    CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchtrn), 23) as fecha,
                    CAST(CONVERT(TIME, DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0))) AS TIME(0)) AS hora_formateada,
                    SUBSTRING(CAST(t1.nrotur AS VARCHAR(3)), 1, 1)  as ''turno'',
                    t1.nrotrn as \"despacho\",
                    t8.den as \"producto\",
                    t6.abr as \"estacion\",
                    t7.den as \"empresa\",
                    t2.den as \"cliente_des\",
                    t5.den as \"cliente_fac\",
                    t1.can as \"cantidad\",
                    t1.mto as \"importe\",
                    t1.pre as \"precio\",
                    t1.gasfac,
                    t1.nrofac,
					 CASE WHEN t9.cod <> 0 THEN t9.den ELSE '''' END AS \"despachador\",
					CASE 
                        WHEN t3.nro BETWEEN 2100000000 AND 2499999999 THEN ''Z '' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                        WHEN t3.nro BETWEEN 2000000000 AND 2099999999 THEN ''T '' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                        WHEN t3.nro BETWEEN 1900000000 AND 1999999999 THEN ''K '' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                        WHEN t3.nro BETWEEN 1100000000 AND 1199999999 THEN ''C '' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                        WHEN t3.nro BETWEEN 1700000000 AND 1799999999 THEN ''I '' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                        WHEN t3.nro BETWEEN 1300000000 AND 1399999999 THEN ''E '' + SUBSTRING(CAST(t3.nro AS VARCHAR(10)), 4, 10)
                        ELSE CAST(t3.nro AS VARCHAR(10)) 
                    END AS \"factura\",
                    CONVERT(DATE, DATEADD(DAY, -1, t3.fch)) AS [FechaFactura],
					 CASE 
                        WHEN t1.nrofac BETWEEN 2100000000 AND 2499999999 THEN ''Z '' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                        WHEN t1.nrofac BETWEEN 2000000000 AND 2099999999 THEN ''T '' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                        WHEN t1.nrofac BETWEEN 1900000000 AND 1999999999 THEN ''K '' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                        WHEN t1.nrofac BETWEEN 1100000000 AND 1199999999 THEN ''C '' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                        WHEN t1.nrofac BETWEEN 1700000000 AND 1799999999 THEN ''I '' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                        WHEN t1.nrofac BETWEEN 1300000000 AND 1399999999 THEN ''E '' + SUBSTRING(CAST(t1.nrofac AS VARCHAR(10)), 4, 10)
                        ELSE CAST(t1.nrofac AS VARCHAR(10)) 
                    END AS \"factura_desp\",
                    case 
                    when t1.satuid is not null Then t1.satuid 
                    else t3.satuid
                    end as \"UUID\",
                    t3.satuid as \"UUID_fac\",
                    t1.satuid as \"UUID_dep\",
                    t1.rut,
                    t13.den as \"denominacion\",
                    t1.codcli as \"codigo_cliente\",
                    t10.codval,
                    t2.tipval,
					CASE
                        WHEN t13.den in ('' Tarjeta EfectiCard'',
										'' SMARTBT - EFECTIVALE'',
										'' Tarjeta TicketCar'',
										'' Vale Efectivale'',
										''Ultra Gas'',
										'' Tarjeta Inburgas'',
										'' Vale Edenred'',
										'' Tarjetas Sodexo (Pluxee)'',
										''Mobil FleetPro'',
										'' SMARTBT - SODEXO WIZEO'',
										'' Vale Sodexo'') THEN ''Monedero''
                        WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN ''Monedero''
                        WHEN t10.codval = 28 THEN ''Cliente Crédito''
                        WHEN t10.codval = 127 THEN ''Cliente Débito''
                        WHEN  t3.codopr != 21701354 AND t10.codval is null THEN ''Contado''
                        WHEN t3.codopr = 21701354 THEN ''Factura Global''
                        else ''N/A''
                    END as ''tipo_cliente_aplicativo'',
                    CASE 
                        WHEN t10.codval = 28 THEN ''Cliente Crédito''
                        WHEN t10.codval = 127 THEN ''Cliente Débito''
                        WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN ''Monedero''
                        WHEN  t3.codopr != 21701354 AND t10.codval is null THEN ''Contado''
                        WHEN t3.codopr = 21701354 THEN ''Factura Global''
                        else ''N/A''
                    END as ''tipo_cliente'',
                    t14.den as ''efectos_c'',
                    t11.nroveh as ''vehiculo'',
                    t11.plc as ''placas'',
                    t3.txtref,
                    t3.tipref,
                    CASE
                        WHEN t3.tipref = 4 AND t1.logmsk IN (2, 3) THEN ''Tarjeta Credito''
                        WHEN t3.tipref = 28 AND t1.logmsk IN (2, 3) THEN ''Tarjeta Debito''
                        WHEN t3.tipref = 1 AND t1.logmsk IN (2, 3) THEN ''Efectivo''
                    END AS ''tipo_pago'',
                    CASE
                        WHEN t1.tiptrn = 51 AND t1.gasfac != 2 THEN ''Tarjeta Credito''
                        WHEN t1.tiptrn = 52 AND t1.gasfac != 2 THEN ''Tarjeta Debito''
                        WHEN t1.tiptrn = 53 AND t1.gasfac != 2 THEN ''Efectivale''
                        WHEN t1.tiptrn = 0 THEN ''Efectivo''
                    END AS ''tipo_pago_despacho'',
					 t1.tiptrn,
                    t6.cvecli,
                    ROW_NUMBER() OVER(PARTITION BY t1.nrotrn ORDER BY t1.fchtrn ASC, t1.hratrn ASC) AS rn
                      FROM [$database].[dbo].[Despachos] t1 WITH (NOLOCK)
                    LEFT JOIN [$database].[dbo].Clientes t2 on t1.codcli = t2.cod
                    LEFT JOIN [$database].[dbo].DocumentosC t3 on t1.nrofac = t3.nro and t1.codgas = t3.codgas
                    LEFT JOIN [$database].[dbo].Clientes t5 on t3.codopr = t5.cod
                    LEFT JOIN [$database].[dbo].Gasolineras t6 ON t1.codgas = t6.cod
                    LEFT JOIN [$database].[dbo].[Empresas] t7 ON t6.codemp = t7.cod
                    LEFT JOIN [$database].[dbo].[Productos] t8 on t1.codprd = t8.cod
                    LEFT JOIN [$database].[dbo].[Responsables] t9 on t1.codres = t9.cod
                    LEFT JOIN [$database].[dbo].[ClientesValores] t10 ON t1.codcli = t10.codcli and t10.codest !=-1 and  t10.codval in(127,28)
                    LEFT JOIN [$database].[dbo].[ClientesVehiculos] t11 on t1.codcli = t11.codcli  and t1.nroveh = t11.nroveh
                    LEFT JOIN(Select nrotrn,sum(mto) as mto, codbco, codgas from [$database].[dbo].MovimientosTar Where tipmov != 86 and tipmov !=97  and mto != 0   group by nrotrn, codgas,codbco) t12 on t1.nrotrn=t12.nrotrn and t1.codgas = t12.codgas
                    LEFT JOIN [$database].[dbo].Valores t13 on t12.codbco = t13.cod 
                    LEFT JOIN [$database].[dbo].[EfectosC] t14 on t3.tip = t14.tipope and t3.subope =t14.subope
                    WHERE $where";
            $union_queries[] = "
                SELECT * FROM OPENQUERY([$server_ip], '$query')
            ";
        }
        $final_query = implode(" UNION ALL ", $union_queries);
    

        return ($this->sql->select($final_query)) ?: false ;
    }

    function get_credit_dispatches($from, $until){
       $query = 'Declare @fini int
                Declare @ffin int
                Declare @Inicial date;
                Declare @Final date;

                set @Inicial = Cast(\''. $from .'\' As Datetime)-- Establecer la fecha inicial en formato DD/MM/AAAA
                set @Final = Cast(\''.$until .'\' As Datetime)  -- Establecer la fecha final en formato DD/MM/AAAA

                SELECT @fini = DATEDIFF(dd, 0, @Inicial)+1
                SELECT @ffin = DATEDIFF(dd, 0, @Final)+1

                Select 
                convert(varchar,convert(smalldatetime,fch,100)-1,103) as [date],
                t2.abr as station,
                t3.codext as cod_client,
                t3.den as client, 
                Case t3.tipval when 3 then \'Credito\' when 4 then \'Debito\' END as Tipo,
                Case t1.nrotur when 11 then 1 when 21 then 2 when 31 then 3 when 41 then 4 else t1.nrotur END as Turno,
                t4.den as [product],
                t1.can as Cantidad,
                val as Valor,t1.sec*-1 dispatch,
                imp as import,
                dbo.Serie(t1.nrofac) as series, 
                (t1.nrofac-(t1.nrofac/100000000*100000000)) as nrofac ,
                t5.can as can
                from ValesR t1 Inner Join Gasolineras t2 
                ON t1.codgas=t2.cod Inner Join Clientes t3
                ON t1.codcli=t3.cod Inner Join Productos t4
                ON t1.codprd=t4.cod
                Left join Despachos t5 ON ABS(t1.sec)=t5.nrotrn and t1.codgas=t5.codgas
                where t1.fch>=@fini and t1.fch<=@ffin and t3.tipval=3
                order by t1.fch,t3.tipval
                ';
        return ($this->sql->select($query)) ?: false ;

    }
    function sales_cash_hour_table($fromDate, $untilDate, $codgas) {
        // Generar fechas como columnas dinámicas
        $dates = [];
        $start = new DateTime($fromDate);
        $end = new DateTime($untilDate);
        $from_int = dateToInt($fromDate);
        $until_int = dateToInt($untilDate);
    
        while ($start <= $end) {
            $formatted = $start->format('Y-m-d');
            $dates[] = $formatted;
            $start->modify('+1 day');
        }
    
        // Crear columnas para el PIVOT y SELECT con ISNULL
        $columnsList = implode(',', array_map(fn($d) => "[$d]", $dates));
        $selectList = implode(',', array_merge(
            ['Hora'],
            array_map(fn($d) => "ISNULL([$d], 0) AS [$d]", $dates)
        ));
    
        $query = "
            WITH datosDespachos AS (
                SELECT 
                    DATEPART(HOUR, CAST(DATEADD(MINUTE, t1.hratrn % 100, DATEADD(HOUR, t1.hratrn / 100, 0)) AS TIME)) AS Hora,
                    CONVERT(VARCHAR, DATEADD(DAY, t1.fchtrn - 1, '19000101'), 23) AS FechaTexto,
                    t1.mto
                FROM despachos t1
                LEFT JOIN Clientes t2 ON t1.codcli = t2.cod
                LEFT JOIN ClientesValores t3 ON t2.cod = t3.codcli AND t3.codest = 0 AND t3.codval IN (127, 28)
                LEFT JOIN MovimientosTar t4 ON t1.nrotrn = t4.nrotrn AND t1.codgas = t4.codgas AND t4.tipmov != 82
                LEFT JOIN [SG12].[dbo].Valores t13 ON t4.codbco = t13.cod AND t4.codbco = -1128
                WHERE
                    t1.codgas = {$codgas}
                    AND (t2.tipval NOT IN (3, 4) OR t2.tipval IS NULL)
                    AND t1.tar >= 0
                    and t1.fchtrn BETWEEN $from_int AND $until_int

            ),
            resumen AS (
                SELECT Hora, FechaTexto, SUM(ISNULL(mto, 0)) AS TotalMto
                FROM datosDespachos
                GROUP BY Hora, FechaTexto
            )
            SELECT {$selectList}
            FROM (
                SELECT Hora, FechaTexto, TotalMto
                FROM resumen
            ) AS SourceTable
            PIVOT (
                SUM(TotalMto)
                FOR FechaTexto IN ({$columnsList})
            ) AS PivotTable
            ORDER BY Hora;
        ";
    
        return $this->sql->select($query, []);
    }
    
    
}   