<?php
class IslasModel extends Model{
    public $cod;
    public $den;
    public $codgas;
    public $codred;
    public $logusu;
    public $logfch;
    public $lognew;
    public $fchsyn;
    public $fchsynalm;

    /**
     * @return array|false
     * @throws Exception
     */
    public function get_isles() : array|false {
        $query = "
            SELECT
                TOP (1000)
                t1.cod,
                t1.den Isla,
                t1.codgas,
                t2.abr Estacion,
                CASE 
                   WHEN t1.[cod] IN (SELECT DISTINCT(codisl) FROM [SG12].[dbo].[Bombas] WHERE codtan IN (7,18,23,32,35,38,43,46,49,59,60,65,68,78,82,85,88,71,73,76,93,94,97)) 
                   THEN 'DIESEL'
                   WHEN t1.[cod] IN (SELECT DISTINCT(codisl) FROM [SG12].[dbo].[Bombas] WHERE codtan IN (5,8,11,13,15,16,20,22,25,27,29,31,34,37,40,42,45,48,52,55,56,58,70,64,66,81,84,86,75,92))
                   THEN 'PREMIUM'
                   WHEN t1.[cod] IN (SELECT DISTINCT(codisl) FROM [SG12].[dbo].[Bombas] WHERE codtan IN (3,9,10,12,14,17,19,21,24,26,28,30,33,36,39,41,44,47,53,54,57,61,62,69,63,67,77,79,80,83,87,89,72,74,90,91))
                   THEN 'SUPER'
                   ELSE 'OTHER'
               END AS [FuelType]
            FROM
                [SG12].[dbo].[Islas] t1
                LEFT JOIN [SG12].[dbo].[Gasolineras] t2 ON t1.codgas = t2.cod
            ORDER BY Estacion ASC
        ";
        return ($this->sql->select($query,[])) ?: false ;
    }

    /**
     * @param $station_id
     * @return false|array
     * @throws Exception
     */
    public function get_isles_by_station($station_id): false|array {

        $query = "SELECT * FROM OPENQUERY({$this->linked_server[$station_id]}, 'SELECT cod, den Isla, codgas FROM {$this->short_databases[$station_id]}.[Islas] WHERE codgas = {$station_id};')";
        return ($this->sql->select($query,[])) ?: false ;
    }

    /**
     * @param $codgas
     * @param $tabId
     * @return array|false
     * @throws Exception
     */
    function get_available_islands_by_tab($codgas, $tabId) : array|false {
        $query = "SELECT
                        t1.cod, t1.den Isla, t1.codgas
                    FROM
                        {$this->databases[$codgas]}.[Islas] t1
                        LEFT JOIN (SELECT * FROM [TG].[dbo].[Asignaciones] WHERE IdTabulador = ?) t2 ON t1.cod = t2.Isla
                        LEFT JOIN {$this->databases[$codgas]}.[Gasolineras] t3 ON t1.codgas = t3.cod
                    WHERE
                        t1.codgas = ?
                        AND t2.Isla IS NULL;";
        $params = [$tabId, $codgas];
        return ($this->sql->select($query,$params)) ?: false ;
    }

    function get_wads_islands($tabId) : array | false {
        $query = "SELECT t1.Isla, t2.den FROM [TG].[dbo].[Asignaciones] t1 LEFT JOIN [SG12].[dbo].[Islas] t2 ON t2.cod = t1.Isla WHERE t1.IdTabulador = ? ORDER BY Isla;";
        return ($this->sql->select($query,[$tabId])) ?: false ;
    }

    /**
     * @param $tabId
     * @param $islandId
     * @return array|false
     * @throws Exception
     */
    function get_isle($tabId, $islandId) : array|false {
        $query = "SELECT cod, den Isla, codgas FROM [SG12].[dbo].[Islas] WHERE codgas = ? AND cod = ?;";
        $params = [$tabId, $islandId];
        return ($rs = $this->sql->select($query,$params)) ? $rs[0] : false ;
    }
}