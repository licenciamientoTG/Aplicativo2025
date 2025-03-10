<?php
class ClientesChoferesModel extends Model{
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
    public $datvar;
    public $telcel;
    public $correo;

    /**
     * @param $codcli
     * @return array|false
     * @throws Exception
     */
    function getDriversClient($codcli) : array|false {
        $query = "SELECT * FROM [SG12].[dbo].[ClientesChoferes] WHERE codcli = ?;";
        return ($this->sql->select($query, [$codcli])) ?: false ;
    }

    /**
     * @param $codcli
     * @param $nrocho
     * @return array|false
     * @throws Exception
     */
    function getDriverInfo($codcli, $nrocho) : array|false {
        $query = "SELECT
                    t1.*,
                    t2.den Cliente,
                    CASE t2.tipval
                        WHEN 3 THEN N'CRÉDITO'
                        WHEN 4 THEN N'DÉBITO'
                        ELSE 'OTRO'
                    END AS Tipo
                FROM
                    [SG12].[dbo].[ClientesChoferes] t1
                    LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
                WHERE
                    t1.codcli = ?
                    AND t1.nrocho = ?
                ;";
        $params = [$codcli, $nrocho];
        return ($rs=$this->sql->select($query, $params)) ? $rs[0]: false ;
    }
}