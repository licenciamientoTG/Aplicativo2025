<?php
class ClientesModel extends Model{

    public $cod;
    public $den;
    public $dom;
    public $col;
    public $del;
    public $ciu;
    public $est;
    public $tel;
    public $fax;
    public $rfc;
    public $tipval;
    public $mtoasg;
    public $mtodis;
    public $mtorep;
    public $cndpag;
    public $diarev;
    public $horrev;
    public $diapag;
    public $horpag;
    public $cto;
    public $obs;
    public $codext;
    public $datcon;
    public $codpos;
    public $pto;
    public $ptosdo;
    public $debsdo;
    public $cresdo;
    public $fmtexp;
    public $arcexp;
    public $polcor;
    public $ultcor;
    public $debnro;
    public $crenro;
    public $debglo;
    public $codtip;
    public $codzon;
    public $codgrp;
    public $codest;
    public $logusu;
    public $logfch;
    public $lognew;
    public $pai;
    public $correo;
    public $dattik;
    public $ptodebacu;
    public $ptodebfch;
    public $ptocreacu;
    public $ptocrefch;
    public $ptovenacu;
    public $ptovenfch;
    public $domnroext;
    public $domnroint;
    public $datvar;
    public $nroctapag;
    public $tipopepag;
    public $cveest;
    public $cvetra;
    public $geodat;
    public $geolat;
    public $geolng;
    public $taxext;
    public $taxextid;
    public $bcomn1cod;
    public $bcomn1den;
    public $bcomn1cta;
    public $bcomn2cod;
    public $bcomn2den;
    public $bcomn2cta;
    public $bcome1cod;
    public $bcome1den;
    public $bcome1cta;
    public $bcome2cod;
    public $bcome2den;
    public $bcome2cta;
    public $perfis;
    public $perfisnom;
    public $perfisapp;
    public $perfisapm;
    public $curp;
    public $codrefban;
    public $paisat;
    public $satuso;
    public $replegden;
    public $replegrfc;
    public $regfis;
    public $densat;
    public $fchsyn;

    /**
     * @return array|false
     * @throws Exception
     */
    public function get_clients() : array|false {
        $query = 'SELECT cod, abr, den, dom, col FROM Gasolineras ORDER BY abr;';
        return $this->sql->select($query) ?: false ;
    }



    public function get_clients_debit($status) : array|false {

        $query_status = '';
        if ($status== 0){
            $query_status = ' ';
        } elseif ($status == 1) {
            $query_status = ' AND t1.codest = 1';
        } elseif ($status == 2) {
            $query_status = ' AND t1.codest =  0';
        } else {
            return false;
        }
        $query = 'SELECT
                    t1.cod,
                    t1.den,
                    t1.tipval,
                    case 
                        When t1.codest = 1  then \'suspendido\'
                        When t1.codest =  0 then \'Activo\'
                        else \'NA\'
                        end as [status],
                    t1.dom,
                    t1.rfc, t1.debsdo
                    FROM [SG12].[dbo].[Clientes] t1
                    where 
                    tipval = 4 '. $query_status;
        $params = [];
        return $this->sql->select($query, $params) ?: false ;
    }

    /**
     * @param $codcli
     * @param $tar
     * @return int|false
     * @throws Exception
     */
    public function findClientForm($codcli, $tar) : int|false {
        // Si código de cliente esta vacio
        if (!empty($codcli)) {
            $query = 'SELECT TOP (1) * FROM [SG12].[dbo].[Clientes] WHERE cod = ?;';
            return ($rs=$this->sql->select($query, [$codcli])) ? $rs[0]['cod'] : false ;
        }

        if (!empty($tar)) {
            $query = 'SELECT TOP (1) t1.* FROM [SG12].[dbo].[Clientes] t1 LEFT JOIN [SG12].[dbo].[ClientesVehiculos] t2 ON t1.cod = codcli WHERE tar = ?;';
            return ($rs=$this->sql->select($query, [$tar])) ? $rs[0]['cod'] : false ;
        }
        return false;
    }

    /**
     * @param $codcli
     * @return array|false
     * @throws Exception
     */
    function getClient($codcli) : array|false {
        $query = 'SELECT TOP (1) cod, den Nombre, dom Domicilio, col colonia, ciu Ciudad, est Estado, rfc, correo, CASE tipval
                        WHEN 3 THEN N\'CRÉDITO\'
                        WHEN 4 THEN N\'DÉBITO\'
                        ELSE \'OTRO\'
                    END AS Tipo
                FROM [SG12].[dbo].[Clientes] WHERE cod = ?;
                ';
        return ($rs=$this->sql->select($query, [$codcli])) ? $rs[0] : false ;
    }

    function search_client($den) {
        $query = "SELECT cod, den FROM [dbo].[Clientes] WHERE tipval IN (3,4) AND den LIKE '%{$den}%'";
        return $this->sql->select($query);
    }

    function search_credit_and_debits_clients() {
        $query = "SELECT cod, den FROM [SG12].[dbo].[Clientes] WHERE tipval IN (3,4);";
        return $this->sql->select($query);
    }

    public function get_movpen_preview(): array|false
{
    $sql = <<<SQL
SET NOCOUNT ON;

DROP TABLE IF EXISTS #MovPen;
DROP TABLE IF EXISTS #Base;

CREATE TABLE #MovPen (
  codopr     INT         NULL,
  nrocta     INT         NULL,
  codgas     INT         NULL,
  tipope     TINYINT     NULL,
  nroope     INT         NULL,
  tipmov     TINYINT     NULL,
  tipant     TINYINT     NULL,
  nroant     INT         NULL,
  tipopr     TINYINT     NULL,
  fchope     INT         NULL,
  fchvto     INT         NULL,
  mtoopeori  FLOAT       NULL,
  mtoopecnv  FLOAT       NULL,
  mtopenori  FLOAT       NULL,
  tipref     TINYINT     NULL,
  nroref     INT         NULL,
  gasori     INT         NULL
);

INSERT INTO #MovPen
EXEC [SG12].dbo.sp_SelMovPen
  @Tip    = 1,
  @OprDde = 0,
  @OprHta = 2147483647,
  @CtaDde = 101032000,
  @CtaHta = 101032000,
  @GasDde = 2,
  @GasHta = 2;

SELECT
  M.codopr,
  C.den        AS Cliente,
  C.mtoasg,
  C.cndpag,
  M.codgas,
  G.den        AS Estacion,
  M.mtopenori
INTO #Base
FROM #MovPen AS M
LEFT JOIN [SG12].dbo.Clientes    AS C ON C.cod = M.codopr
LEFT JOIN [SG12].dbo.Gasolineras AS G ON G.cod = M.codgas
WHERE ISNULL(C.codest, 0) <> -1;

-- SOLO la segunda tabla (B)
SELECT
  B.codopr,
  B.Cliente,
  B.mtoasg,
  B.cndpag,
  B.codgas,
  B.Estacion,
  SUM(B.mtopenori/100) AS mtopenori_total
FROM #Base AS B
GROUP BY B.codopr, B.Cliente, B.mtoasg, B.cndpag, B.codgas, B.Estacion
ORDER BY B.codopr, B.codgas

DROP TABLE IF EXISTS #Base;
DROP TABLE IF EXISTS #MovPen;
SQL;

    return $this->sql->select($sql, []) ?: false;
}
}