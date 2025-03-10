<?php
class BombasModel extends Model{
    public $codcli;
    public $nrocho;
    public $den;
    public $diacar;
    public $hraini;
    public $hrafin;
    public $tag;
    public $codest;
    public $logusu;
    public $logfch;

    /**
     * @param $station_id
     * @return array|false
     * @throws Exception
     */
    public function get_pumps_by_station($station_id) : array|false {
        $query = 'SELECT
                        t1.*, t2.abr, t3.den
                    FROM
                        [SG12].[dbo].[Bombas] t1
                        LEFT JOIN [SG12].[dbo].[Gasolineras] t2 ON t1.codgas = t2.cod
                        LEFT JOIN [SG12].[dbo].[Islas] t3 ON t1.codisl = t3.cod
                    WHERE
                        t1.codgas = ?;';
        $params = [$station_id];
        return ($this->sql->select($query,$params)) ?: false ;
    }
}