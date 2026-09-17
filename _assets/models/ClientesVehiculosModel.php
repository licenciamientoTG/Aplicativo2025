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

    /**
     * @param $codcli
     * @return array|false
     * @throws Exception
     */
    /**
     * Construye el reporte "Vehículos y Saldo — Cliente Débito" con CTEs.
     * DebitClients acota el universo ANTES de tocar Despachos/DocumentosC
     * (las tablas más grandes) para no escanear el histórico completo de
     * todos los clientes. "Último Consumo" solo considera despachos cuya
     * factura (nrofac) cae en la serie E (1300000000-1399999999); cualquier
     * despacho fuera de ese rango (otra serie, o aún sin facturar) queda
     * excluido del cálculo.
     */
    public function getVehiclesBalanceByClient($codcli) : array|false {
        $debitClientsFilter = $codcli > 0
            ? "SELECT cod FROM [SG12].dbo.Clientes WHERE cod = {$codcli}"
            : "SELECT cod FROM [SG12].dbo.Clientes WHERE tipval = 4 AND codest = 0";

        $clienteWhere = $codcli > 0 ? 'WHERE t1.cod = ?' : 'WHERE t1.tipval = 4 AND t1.codest = 0';
        $clienteOrder = $codcli > 0 ? '' : ' ORDER BY t1.cod DESC';
        $params = $codcli > 0 ? [$codcli] : null;

        $query = "
            ;WITH DebitClients AS (
                {$debitClientsFilter}
            ),
            UltRanked AS (
                SELECT
                    t2.codopr,
                    t1.fch,
                    t1.nro,
                    ROW_NUMBER() OVER (PARTITION BY t2.codopr ORDER BY t1.fch DESC, t1.nro DESC) AS rn
                FROM DebitClients dc
                JOIN [SG12].dbo.Documentos t2 WITH (NOLOCK)
                  ON t2.codopr = dc.cod
                JOIN [SG12].dbo.DocumentosC t1 WITH (NOLOCK)
                  ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
                WHERE t2.mtoiva > 0
                  AND t2.codprd NOT IN (1,2,3,-64,179,180,181,192,193)
                  AND t2.mto > 100
                  AND ISNULL(t1.flgcon, 0) <> 141
            ),
            UltDoc AS (
                SELECT codopr, fch AS UltimaFch, nro AS UltimaNro
                FROM UltRanked
                WHERE rn = 1
            ),
            ConRanked AS (
                SELECT
                    d.codcli,
                    d.fchtrn,
                    d.nrotrn,
                    d.nrofac,
                    ROW_NUMBER() OVER (PARTITION BY d.codcli ORDER BY d.fchtrn DESC, d.nrotrn DESC) AS rn
                FROM DebitClients dc
                JOIN [SG12].dbo.Despachos d WITH (NOLOCK) ON d.codcli = dc.cod
                WHERE d.nrofac BETWEEN 1300000000 AND 1399999999
            ),
            ConFac AS (
                SELECT codcli, fchtrn AS UltimoConsumoFch, nrofac AS UltimoConsumoNrofac
                FROM ConRanked
                WHERE rn = 1
            )
            SELECT
                t1.cod AS codcli,
                t2.tar,
                t2.den,
                t2.debsdo,
                t1.den AS cliente,
                t1.debglo,
                t1.debsdo AS saldo_global,
                CASE WHEN UD.UltimaFch IS NOT NULL
                     THEN CONVERT(varchar(10), DATEADD(DAY, UD.UltimaFch - 1, '19000101'), 23)
                     ELSE NULL END AS ultima_carga,
                UD.UltimaNro AS ultima_carga_nro,
                CASE
                    WHEN UD.UltimaNro BETWEEN 2100000000 AND 2499999999 THEN 'Z'
                    WHEN UD.UltimaNro BETWEEN 2000000000 AND 2099999999 THEN 'T'
                    WHEN UD.UltimaNro BETWEEN 1900000000 AND 1999999999 THEN 'K'
                    WHEN UD.UltimaNro BETWEEN 1100000000 AND 1199999999 THEN 'C'
                    WHEN UD.UltimaNro BETWEEN 1200000000 AND 1299999999 THEN 'D'
                    WHEN UD.UltimaNro BETWEEN 1700000000 AND 1799999999 THEN 'I'
                    WHEN UD.UltimaNro BETWEEN 1300000000 AND 1399999999 THEN 'E'
                    WHEN UD.UltimaNro BETWEEN 1500000000 AND 1599999999 THEN 'G'
                    ELSE NULL
                END AS ultima_carga_serie,
                CASE WHEN UD.UltimaNro IS NOT NULL
                     THEN SUBSTRING(CAST(UD.UltimaNro AS varchar(10)), 4, 10)
                     ELSE NULL END AS ultima_carga_folio,
                CASE WHEN CF.UltimoConsumoFch IS NOT NULL
                     THEN CONVERT(varchar(10), CAST(CF.UltimoConsumoFch AS datetime) - 1, 23)
                     ELSE NULL END AS ultimo_consumo,
                CF.UltimoConsumoNrofac AS ultimo_consumo_nrofac,
                CASE
                    WHEN CF.UltimoConsumoNrofac BETWEEN 2100000000 AND 2499999999 THEN 'Z'
                    WHEN CF.UltimoConsumoNrofac BETWEEN 2000000000 AND 2099999999 THEN 'T'
                    WHEN CF.UltimoConsumoNrofac BETWEEN 1900000000 AND 1999999999 THEN 'K'
                    WHEN CF.UltimoConsumoNrofac BETWEEN 1100000000 AND 1199999999 THEN 'C'
                    WHEN CF.UltimoConsumoNrofac BETWEEN 1200000000 AND 1299999999 THEN 'D'
                    WHEN CF.UltimoConsumoNrofac BETWEEN 1700000000 AND 1799999999 THEN 'I'
                    WHEN CF.UltimoConsumoNrofac BETWEEN 1300000000 AND 1399999999 THEN 'E'
                    WHEN CF.UltimoConsumoNrofac BETWEEN 1500000000 AND 1599999999 THEN 'G'
                    ELSE NULL
                END AS ultimo_consumo_serie,
                CASE WHEN CF.UltimoConsumoNrofac IS NOT NULL AND CF.UltimoConsumoNrofac > 0
                     THEN SUBSTRING(CAST(CF.UltimoConsumoNrofac AS varchar(10)), 4, 10)
                     ELSE NULL END AS ultimo_consumo_folio
            FROM [SG12].[dbo].[Clientes] t1
                LEFT JOIN [SG12].[dbo].[ClientesVehiculos] t2 ON t1.cod = t2.codcli
                LEFT JOIN UltDoc UD ON UD.codopr = t1.cod
                LEFT JOIN ConFac CF ON CF.codcli = t1.cod
            {$clienteWhere}
            {$clienteOrder};
        ";

        return ($this->sql->select($query, $params)) ?: false;
    }

    /**
     * Clientes débito con al menos un despacho cuyo nrofac quedó ligado a la
     * factura de un cliente distinto al propio despacho (Factura Global u otro
     * tercero) en vez de facturarse a su propio nombre. No depende de la
     * serie/rango del folio (Z, T, D, etc.) — cualquier serie puede estar mal
     * ligada. Sirve para detectar despachos mal capturados por el despachador
     * que distorsionan el "Último Consumo".
     */
    function getClientsWithMisroutedGlobalInvoice() : array|false {
        $query = "
            SELECT DISTINCT
                t1.cod AS codcli,
                t1.den AS cliente,
                d.nrotrn,
                d.nrofac,
                fac.codopr AS ligado_a_codopr,
                fac2.den AS ligado_a_cliente,
                CASE WHEN fac.codopr = 21701354 THEN 'Factura Global' ELSE 'Otro cliente' END AS tipo_error,
                CONVERT(varchar(10), CAST(d.fchtrn AS datetime) - 1, 23) AS fecha_despacho
            FROM [SG12].[dbo].[Despachos] d WITH (NOLOCK)
            JOIN [SG12].[dbo].[DocumentosC] fac WITH (NOLOCK)
              ON d.nrofac = fac.nro AND d.codgas = fac.codgas
            JOIN [SG12].[dbo].[Clientes] t1 WITH (NOLOCK)
              ON t1.cod = d.codcli
            LEFT JOIN [SG12].[dbo].[Clientes] fac2 WITH (NOLOCK)
              ON fac2.cod = fac.codopr
            WHERE d.codcli > 0
              AND t1.tipval = 4
              AND d.nrofac > 0
              AND fac.codopr <> d.codcli
            ORDER BY t1.den, d.fchtrn DESC;
        ";
        return ($this->sql->select($query)) ?: false ;
    }

    function getFolio() : int {
        $query = "INSERT INTO [TG].[dbo].[folios_docs] ([user_id],[created_at]) VALUES (?,GETDATE());";
        return $this->sql->insert($query, [1]);
    }
}