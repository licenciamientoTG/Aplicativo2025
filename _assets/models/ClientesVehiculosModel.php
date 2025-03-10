<?php

class ClientesVehiculosModel extends Model{
    public $codcli;
    public $nroveh;
    public $tar;
    public $plc;
    public $den;
    public $rsp;
    public $grp;
    public $diacar;
    public $hraini;
    public $hrafin;
    public $carmax;
    public $candia;
    public $cansem;
    public $canmes;
    public $acudia;
    public $acusem;
    public $acumes;
    public $ultcar;
    public $ultodm;
    public $codgas;
    public $codprd;
    public $debsdo;
    public $debfch;
    public $debnro;
    public $debcan;
    public $nip;
    public $ptosdo;
    public $ptofch;
    public $ptocan;
    public $premto;
    public $prepgo;
    public $prefid;
    public $cnvemp;
    public $cnvobs;
    public $cnvfch;
    public $manobs;
    public $manper;
    public $manult;
    public $rut;
    public $tag;
    public $vto;
    public $limtur;
    public $ulttur;
    public $acutur;
    public $limprd;
    public $acuprd;
    public $crefch;
    public $crenro;
    public $crecan;
    public $crefch2;
    public $crenro2;
    public $crecan2;
    public $debfch2;
    public $debnro2;
    public $debcan2;
    public $est;
    public $niplog;
    public $logusu;
    public $logfch;
    public $lognew;
    public $tagadi;
    public $ctapre;
    public $nropat;
    public $nroeco;
    public $hraini2;
    public $hrafin2;
    public $hraini3;
    public $hrafin3;
    public $aju;
    public $ptodebacu;
    public $ptodebfch;
    public $ptocreacu;
    public $ptocrefch;
    public $ptovenacu;
    public $ptovenfch;
    public $tagex1;
    public $tagex2;
    public $tagex3;
    public $ultcan;
    public $datvar;
    public $catprd;
    public $catuni;
    public $dialim;
    public $fchsyn;
    public $odmmin;
    public $odmmax;

    /**
     * @return array|false
     * @throws Exception
     */
    function get_vehicles() : array|false {
        $query = 'SELECT
            t1.*,
            CASE
                WHEN t1.nip IS NULL OR t1.nip = 0 THEN 0
                ELSE t1.nip - 12345678
            END AS nip_decrypted,
            t2.den Cliente
        FROM
            [SG12].[dbo].[ClientesVehiculos] t1
            LEFT JOIN Clientes t2 ON t1.codcli = t2.cod;';
        return ($this->sql->select($query)) ?: false ;
    }

    /**
     * @param $codcli
     * @return array|false
     * @throws Exception
     */
    public function getVehiclesClient($codcli) : array|false {
        $query = 'SELECT
                        t1.cod,
                        t2.nroveh NV,
                        t2.tar Tarjeta,
                        t2.plc Placas,
                        t2.den Descripcion,
                                CASE
                        WHEN t2.nip IS NULL OR t2.nip = 0 THEN 0
                        ELSE t2.nip - 12345678
                    END AS nip_decrypted,
                        CASE t2.est
                            WHEN 1 THEN N\'Habilitado\'
                            WHEN 2 THEN N\'Cargando\'
                            WHEN 3 THEN N\'Suspendido\'
                            WHEN 4 THEN N\'Uso interno\'
                            WHEN 5 THEN N\'Verificación pendiente\'
                            WHEN 6 THEN N\'Baja administrativa\'
                            ELSE N\'Valor Desconocido\'
                        END AS Estado,
                        CONCAT (\'124331\',replicate(\'0\', 8-LEN([tar])) + rtrim([tar]),replicate(\'0\', 8-LEN([codext])) + rtrim([codext])) Engomado,
                        t2.nropat
                    FROM [SG12].[dbo].[Clientes] t1
                        INNER JOIN [SG12].[dbo].[ClientesVehiculos] t2 ON t1.cod = t2.codcli
                    WHERE [codcli] = ?;';
        return ($this->sql->select($query, [$codcli])) ?: false ;
    }

    /**
     * @param $card
     * @param $client_id
     * @return array|false
     * @throws Exception
     */
    public function getVehiclesInfo($card, $client_id) : array|false {
        $query = 'SELECT
                t2.nroveh NV,
                t2.tar Tarjeta,
                t2.plc Placas,
                t2.den Descripcion,
                CONCAT (\'124331\',replicate(\'0\', 8-LEN([tar])) + rtrim([tar]),replicate(\'0\', 8-LEN([codext])) + rtrim([codext])) Engomado,
                t1.den Cliente,
                CASE t1.tipval
                    WHEN 3 THEN \'CRÉDITO\'
                    WHEN 4 THEN \'DÉBITO\'
                    ELSE \'OTRO\'
                END AS Tipo,
                CASE
                    WHEN t2.nip IS NULL OR t2.nip = 0 THEN 0
                    ELSE t2.nip - 12345678
                END AS nip_decrypted,
                CASE t2.est
                    WHEN 1 THEN \'Habilitado\'
                    WHEN 2 THEN \'Cargando\'
                    WHEN 3 THEN \'Suspendido\'
                    WHEN 4 THEN \'Uso interno\'
                    WHEN 5 THEN \'Verificación pendiente\'
                    WHEN 6 THEN \'Baja administrativa\'
                    ELSE \'Valor Desconocido\'
                END AS Estado,
                t3.codgas_concatenados
            FROM [SG12].[dbo].[Clientes] t1
                INNER JOIN [SG12].[dbo].[ClientesVehiculos] t2 ON t1.cod = t2.codcli
                LEFT JOIN (SELECT t1.codcli,
                STUFF((SELECT \', \' + CONVERT(VARCHAR(10), t1a.codgas)
                        FROM [SG12].[dbo].[ClientesGasolineras] t1a
                        WHERE t1a.codcli = t1.codcli
                        AND codest = 0
                        FOR XML PATH(\'\')), 1, 2, \'\') AS codgas_concatenados
            FROM [SG12].[dbo].[ClientesGasolineras] t1
            WHERE t1.codcli = ?
            GROUP BY t1.codcli) t3 ON t1.cod = t3.codcli
            WHERE [tar] = ?;';
        return ($rs=$this->sql->select($query, [$client_id, $card])) ? $rs[0] : false ;
    }

    /**
     * @param $codcli
     * @param $nroveh
     * @return array|false
     * @throws Exception
     */
    function getCard($codcli, $nroveh) : array|false {
        $query = "SELECT
                    t1.tar,
                    t1.nroeco Economico,
                    CASE
                        WHEN t1.nip IS NULL OR t1.nip = 0 THEN 0
                        ELSE t1.nip - 12345678
                    END AS nip_decrypted,
                    CASE t1.est
                        WHEN 1 THEN 'Habilitado'
                        WHEN 2 THEN 'Cargando'
                        WHEN 3 THEN 'Suspendido'
                        WHEN 4 THEN 'Uso interno'
                        WHEN 5 THEN 'Verificación pendiente'
                        WHEN 6 THEN 'Baja administrativa'
                        ELSE 'Valor Desconocido'
                    END AS Estado,
                    CONCAT ('124331',replicate('0', 8-LEN(t1.tar)) + rtrim(t1.tar),replicate('0', 8-LEN(t2.codext)) + rtrim(t2.codext)) Engomado
                    FROM [SG12].[dbo].[ClientesVehiculos] t1 LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod WHERE t1.codcli = ? AND t1.nroveh = ?;";
        return ($rs=$this->sql->select($query, [$codcli, $nroveh])) ? $rs[0] : false ;
    }

    /**
     * @param $codcli
     * @param $nroveh
     * @return array|false
     * @throws Exception
     */
    function getStickers($codcli, $nroveh) : array|false {
        $query = "SELECT
                    t1.tar,
                    t1.nroeco Economico,
                    CASE
                        WHEN t1.nip IS NULL OR t1.nip = 0 THEN 0
                        ELSE t1.nip - 12345678
                    END AS nip_decrypted,
                    CASE t1.est
                        WHEN 1 THEN 'Habilitado'
                        WHEN 2 THEN 'Cargando'
                        WHEN 3 THEN 'Suspendido'
                        WHEN 4 THEN 'Uso interno'
                        WHEN 5 THEN N'Verificación pendiente'
                        WHEN 6 THEN 'Baja administrativa'
                        ELSE 'Valor Desconocido'
                    END AS Estado,
                    CONCAT ('124331',replicate('0', 8-LEN(t1.tar)) + rtrim(t1.tar),replicate('0', 8-LEN(t2.codext)) + rtrim(t2.codext)) Engomado
                FROM [SG12].[dbo].[ClientesVehiculos] t1 LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod WHERE t1.codcli = ? AND t1.nroveh = ?;";
        return ($rs=$this->sql->select($query, [$codcli, $nroveh])) ? $rs[0] : false ;
    }

    function getFolio() : int {
        $query = "INSERT INTO [TG].[dbo].[folios_docs] ([user_id],[created_at]) VALUES (?,GETDATE());";
        return $this->sql->insert($query, [1]);
    }
}