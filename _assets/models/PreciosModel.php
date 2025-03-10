<?php
class PreciosModel extends Model{
    public $codprd;
    public $codgas;
    public $fch;
    public $hra;
    public $pre;
    public $preesp;
    public $cos;
    public $iva;
    public $ptoeqv;
    public $preprd;
    public $preree;
    public $prefid;
    public $preespprd;
    public $preespree;
    public $preespfid;
    public $cosprd;
    public $cosrec;
    public $cosrece;
    public $cosbnf;
    public $cosbnfe;
    public $cosimp;
    public $preiie;
    public $preiig;
    public $codpza;
    public $codcpo;
    public $logusu;
    public $logfch;
    public $ptopre;
    public $preiigfac;
    public $pretiepsg;
    public $ivaexento;
    public $premin;
    public $premax;
    public $porcom;
    public $fchsyn;

    function capture_prices($codprd, $fch, $hour, $pre, $iva, $codgas, $ieps) {
        if (in_array($codgas, [33, 34, 35, 36, 37, 38])) { // Travel, Picachos, Ventanas, San Rafael, Puertecito
            if ($codprd == 179) {
                $codprd = 192;
            } elseif ($codprd == 180) {
                $codprd = 193;
            }
        }

        // Query for SG12 database
        $query_SG12 = "
            INSERT INTO [SG12].[dbo].[Precios] ([codprd],[codgas],[fch],[hra],[pre],[preesp],[cos],[iva],[ptoeqv],[preprd],[preree],[prefid],[preespprd],[preespree],[preespfid],[cosprd],[cosrec],[cosrece],[cosbnf],[cosbnfe],[cosimp],[preiie],[preiig],[codpza]
                       ,[codcpo],[logusu],[logfch],[ptopre],[preiigfac],[pretiepsg],[ivaexento],[premin],[premax],[porcom])
            VALUES
                       (?,?,?,?,?,0,0,?,0,0,0,0,0,0,0,0,0,0,0,0,?,?,0,0,0,?,GETDATE(),0,0,0,0,0,0,0);
        ";
        $params_SG12 = [$codprd, $codgas, $fch, $hour, $pre, $iva, $ieps, $ieps, $_SESSION['tg_user']['Id']];

        // Execute the first query
        $result_SG12 = $this->sql->insert($query_SG12, $params_SG12);

        // Query for specific station database
        $query_station = "
            INSERT INTO {$this->databases[$codgas]}.[Precios] ([codprd],[codgas],[fch],[hra],[pre],[preesp],[cos],[iva],[ptoeqv],[preprd],[preree],[prefid],[preespprd],[preespree],[preespfid],[cosprd],[cosrec],[cosrece],[cosbnf],[cosbnfe],[cosimp],[preiie],[preiig],[codpza]
                       ,[codcpo],[logusu],[logfch],[ptopre],[preiigfac],[pretiepsg],[ivaexento],[premin],[premax],[porcom])
            VALUES
                       (?,?,?,?,?,0,0,?,0,0,0,0,0,0,0,0,0,0,0,0,?,?,0,0,0,?,GETDATE(),0,0,0,0,0,0,0);
        ";
        $params_station = [$codprd, $codgas, $fch, $hour, $pre, $iva, $ieps, $ieps, $_SESSION['tg_user']['Id']];

        // Execute the second query
        $result_station = $this->sql->insert($query_station, $params_station);

        // Return the results of both queries
        return ['SG12' => $result_SG12, 'station' => $result_station];
    }

    function delete_price($codprd, $codgas, $fch, $hra) {
        $params = [$codprd, $codgas, $fch, $hra];

        $query = "DELETE FROM [SG12].[dbo].[Precios] WHERE codprd = ? AND codgas = ? AND fch = ? AND hra = ?;";
        $this->sql->delete($query, $params);

        $query2 = "DELETE FROM {$this->databases[$codgas]}.[Precios] WHERE codprd = {$codprd} AND codgas = {$codgas} AND fch = {$fch} AND hra = {$hra};";
        $this->sql->delete($query2, $params);

        return true;
    }

    function update_price($codprd, $codgas, $fch, $hra, $pre) : bool {
        $params = [$pre, $codprd, $codgas, $fch, $hra];
        $sql = "UPDATE [SG12].[dbo].[Precios]SET [pre] = ? WHERE [codprd] = ? AND [codgas] = ? AND [fch] = ? AND [hra] = ?;";
        $this->sql->update($sql, $params);

        $sql2 = "UPDATE {$this->databases[$codgas]}.[Precios] SET [pre] = ? WHERE [codprd] = ? AND [codgas] = ? AND [fch] = ? AND [hra] = ?;";
        $this->sql->update($sql2, $params);
        return true;
    }

    function get_today_schedules() : array | false {
        $today = dateToInt(date('Y-m-d'));
        $query = "SELECT
                        t2.abr Estacion,
                        CASE
                            WHEN t1.codprd = 179 THEN 'T-Maxima Regular'
                            WHEN t1.codprd = 180 THEN 'T-Super Premium'
                            WHEN t1.codprd = 181 THEN 'Diesel Automotriz'
                            WHEN t1.codprd = 192 THEN 'Gasolina regular menor a 91 octanos'
                            WHEN t1.codprd = 193 THEN 'Gasolina premiu mayor o igual a 91 octanos'
                        END AS Producto,
                        SUBSTRING(CONVERT(CHAR(5), t1.hra + 10000), 2, 2) + ':' + SUBSTRING(CONVERT(CHAR(5), t1.hra + 10000), 4, 2) Hora,
                        t1.pre Precio
                    FROM
                        [SG12].[dbo].[Precios] t1
                        LEFT JOIN [SG12].[dbo].[Gasolineras] t2 ON t1.codgas = t2.cod
                    WHERE
                        t1.codprd IN (179,180,181,192,193) AND
                        t1.fch = {$today}";
        return ($rs=$this->sql->select($query, [])) ? $rs : false ;
    }

    function getTomorrowSchedules() : array | false {
        $today = dateToInt(date('Y-m-d')) + 1;
        $query = "SELECT
                        t2.abr Estacion,
                        CASE
                            WHEN t1.codprd = 179 THEN 'T-Maxima Regular'
                            WHEN t1.codprd = 180 THEN 'T-Super Premium'
                            WHEN t1.codprd = 181 THEN 'Diesel Automotriz'
                            WHEN t1.codprd = 192 THEN 'Gasolina regular menor a 91 octanos'
                            WHEN t1.codprd = 193 THEN 'Gasolina premiu mayor o igual a 91 octanos'
                        END AS Producto,
                        SUBSTRING(CONVERT(CHAR(5), t1.hra + 10000), 2, 2) + ':' + SUBSTRING(CONVERT(CHAR(5), t1.hra + 10000), 4, 2) Hora,
                        t1.pre Precio
                    FROM
                        [SG12].[dbo].[Precios] t1
                        LEFT JOIN [SG12].[dbo].[Gasolineras] t2 ON t1.codgas = t2.cod
                    WHERE
                        t1.codprd IN (179,180,181,192,193) AND
                        t1.fch = {$today}";
        return ($rs=$this->sql->select($query, [])) ? $rs : false ;
    }
}



