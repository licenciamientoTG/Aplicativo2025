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

// Para el reporte de antiguedad de saldos

// Obtiene opciones para el <select> de estaciones
public function get_gasolineras(): array
{
    $sql = "SELECT cod, den FROM [SG12].dbo.Gasolineras WHERE ISNULL(codest,0) <> -1 and cod not in (0,4,20) ORDER BY den";
    return $this->sql->select($sql, []) ?: [];
}

// Reporte estricto: CtaDde=CtaHta y GasDde=GasHta (SIN rangos)
public function get_balance_age(int $cta, int $gas): array|false
{
    $sql = <<<SQL
SET NOCOUNT ON;

DROP TABLE IF EXISTS #MovPen;

CREATE TABLE #MovPen (
  codopr INT NULL, nrocta INT NULL, codgas INT NULL, tipope TINYINT NULL,
  nroope INT NULL, tipmov TINYINT NULL, tipant TINYINT NULL, nroant INT NULL,
  tipopr TINYINT NULL, fchope INT NULL, fchvto INT NULL, mtoopeori FLOAT NULL,
  mtoopecnv FLOAT NULL, mtopenori FLOAT NULL, tipref TINYINT NULL, nroref INT NULL,
  gasori INT NULL
);

INSERT INTO #MovPen
EXEC [SG12].dbo.sp_SelMovPen
  @Tip    = 1,
  @OprDde = 0,
  @OprHta = 2147483647,
  @CtaDde = ?,
  @CtaHta = ?,
  @GasDde = ?,
  @GasHta = ?;

;WITH Base AS (
  SELECT
    M.codopr,
    M.codgas,
    M.tipope,
    M.mtopenori,
    CONVERT(date, DATEADD(DAY, M.fchope - 1, '19000101')) AS fchope_dt,
    CONVERT(date, DATEADD(DAY, M.fchvto - 1, '19000101')) AS fchvto_dt,
    C.den    AS Cliente,
    C.mtoasg AS Credito,
    C.cndpag AS [cond. pago],
    G.den    AS Estacion
  FROM #MovPen AS M
  LEFT JOIN [SG12].dbo.Clientes    AS C ON C.cod = M.codopr
  LEFT JOIN [SG12].dbo.Gasolineras AS G ON G.cod = M.codgas
  WHERE C.codest <> -1
    AND C.tipval = 3
    AND C.cod NOT IN (37106,37173)          -- excluir clientes
    AND M.tipope <> 101
    -- Excluir solo negativos con fchope <= 2022-09-30
    AND NOT (
      M.mtopenori < 0
      AND CONVERT(date, DATEADD(DAY, M.fchope - 1, '19000101')) <= '2022-09-30'
    )
),
Ultimos AS (
  SELECT
    B.codopr,
    B.codgas,
    MAX(CASE WHEN B.tipope = 3 THEN B.fchope_dt END)      AS max_fchope_deb,
    MAX(CASE WHEN B.tipope IN (4,6) THEN B.fchope_dt END) AS max_fchope_cred
  FROM Base B
  GROUP BY B.codopr, B.codgas
),
Resultados AS (
  SELECT
    B.codopr,
    B.Cliente,
    B.codgas,
    B.Estacion,
    CONVERT(varchar(10), U.max_fchope_deb , 23) AS [ult. deb.],
    CONVERT(varchar(10), U.max_fchope_cred, 23) AS [ult. cred.],
    MAX(B.Credito)      AS Credito,
    MAX(B.[cond. pago]) AS [cond. pago],

    -- Saldo actual
    SUM(B.mtopenori/100.0) AS [Saldo actual],

    -- Por vencer
    SUM(CASE 
          WHEN B.fchvto_dt >= CAST(GETDATE() AS date) THEN B.mtopenori/100.0
          ELSE 0
        END) AS [Por vencer],

    -- Buckets vencidos
    SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 1 AND 15
             THEN B.mtopenori/100.0 ELSE 0 END) AS [1-15],

    SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 16 AND 30
             THEN B.mtopenori/100.0 ELSE 0 END) AS [16-30],

    SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 31 AND 45
             THEN B.mtopenori/100.0 ELSE 0 END) AS [31-45],

    SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) > 45
             THEN B.mtopenori/100.0 ELSE 0 END) AS [45+],

    -- Total vencido
    (
      SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 1 AND 15
               THEN B.mtopenori/100.0 ELSE 0 END)
    + SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 16 AND 30
               THEN B.mtopenori/100.0 ELSE 0 END)
    + SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 31 AND 45
               THEN B.mtopenori/100.0 ELSE 0 END)
    + SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) > 45
               THEN B.mtopenori/100.0 ELSE 0 END)
    ) AS [Total vencido],

    -- % de vencimiento = Total vencido / Crédito
    CASE
      WHEN NULLIF(MAX(B.Credito),0) IS NULL THEN NULL
      ELSE (
        (
          SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 1 AND 15
                   THEN B.mtopenori/100.0 ELSE 0 END)
        + SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 16 AND 30
                   THEN B.mtopenori/100.0 ELSE 0 END)
        + SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 31 AND 45
                   THEN B.mtopenori/100.0 ELSE 0 END)
        + SUM(CASE WHEN DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) > 45
                   THEN B.mtopenori/100.0 ELSE 0 END)
        ) / NULLIF(MAX(B.Credito),0)
      ) * 100.0
    END AS [% de vencimiento]
  FROM Base B
  LEFT JOIN Ultimos U ON U.codopr = B.codopr AND U.codgas = B.codgas
  GROUP BY B.codopr, B.Cliente, B.codgas, B.Estacion, U.max_fchope_deb, U.max_fchope_cred
)
SELECT *
FROM Resultados
WHERE [Saldo actual] > 0
ORDER BY codopr, codgas;

DROP TABLE IF EXISTS #MovPen;
SQL;

    // Orden de parámetros: CtaDde, CtaHta, GasDde, GasHta (estrictos)
    $params = [$cta, $cta, $gas, $gas];
    return $this->sql->select($sql, $params) ?: false;
}


public function get_balance_age_detalle(int $cta, int $gas): array|false
{
    $sql = <<<SQL
SET NOCOUNT ON;

DROP TABLE IF EXISTS #MovPen;

CREATE TABLE #MovPen (
  codopr INT NULL, nrocta INT NULL, codgas INT NULL, tipope TINYINT NULL,
  nroope INT NULL, tipmov TINYINT NULL, tipant TINYINT NULL, nroant INT NULL,
  tipopr TINYINT NULL, fchope INT NULL, fchvto INT NULL, mtoopeori FLOAT NULL,
  mtoopecnv FLOAT NULL, mtopenori FLOAT NULL, tipref TINYINT NULL, nroref INT NULL,
  gasori INT NULL
);

INSERT INTO #MovPen
EXEC [SG12].dbo.sp_SelMovPen
  @Tip    = 1,
  @OprDde = 0,
  @OprHta = 2147483647,
  @CtaDde = ?,
  @CtaHta = ?,
  @GasDde = ?,
  @GasHta = ?;

;WITH Base AS (
  SELECT
    M.codopr,
    M.codgas,
    M.tipope,
    M.nroope,
    M.mtopenori,
    CONVERT(date, DATEADD(DAY, M.fchope - 1, '19000101')) AS fchope_dt,
    CONVERT(date, DATEADD(DAY, M.fchvto - 1, '19000101')) AS fchvto_dt,
    C.den    AS Cliente,
    C.mtoasg AS Credito,
    C.cndpag AS [cond. pago],
    C.correo AS correo,
    G.den    AS Estacion
  FROM #MovPen AS M
  LEFT JOIN [SG12].dbo.Clientes    AS C ON C.cod = M.codopr
  LEFT JOIN [SG12].dbo.Gasolineras AS G ON G.cod = M.codgas
  WHERE C.codest <> -1
    AND C.tipval = 3
    AND C.cod NOT IN (37106,37173)          -- excluir clientes
    AND M.tipope <> 101
    -- Excluir solo negativos con fchope <= 2022-09-30
    AND NOT (
      M.mtopenori < 0
      AND CONVERT(date, DATEADD(DAY, M.fchope - 1, '19000101')) <= '2022-09-30'
    )
),
-- Mantener únicamente (cliente, estación) con saldo > 0
SaldosPositivos AS (
  SELECT
    B.codopr,
    B.codgas
  FROM Base B
  GROUP BY B.codopr, B.codgas
  HAVING SUM(B.mtopenori/100.0) > 0
),
Detalles AS (
  SELECT
    B.codopr,
    B.codgas,
    B.Cliente,
    B.Estacion,
    B.[cond. pago],
    B.Credito,
    B.nroope,
    (B.nroope - 1700000000) AS nrofac,
    B.fchope_dt,
    B.fchvto_dt,
    B.correo,
    CAST(B.mtopenori/100.0 AS decimal(18,2)) AS [Saldo actual],
    CAST(CASE WHEN B.fchvto_dt >= CAST(GETDATE() AS date)
              THEN B.mtopenori/100.0 ELSE 0 END AS decimal(18,2)) AS [Por vencer],
    CAST(CASE WHEN B.fchvto_dt < CAST(GETDATE() AS date)
               AND DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 1 AND 15
              THEN B.mtopenori/100.0 ELSE 0 END AS decimal(18,2)) AS [1-15],
    CAST(CASE WHEN B.fchvto_dt < CAST(GETDATE() AS date)
               AND DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 16 AND 30
              THEN B.mtopenori/100.0 ELSE 0 END AS decimal(18,2)) AS [16-30],
    CAST(CASE WHEN B.fchvto_dt < CAST(GETDATE() AS date)
               AND DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 31 AND 45
              THEN B.mtopenori/100.0 ELSE 0 END AS decimal(18,2)) AS [31-45],
    CAST(CASE WHEN B.fchvto_dt < CAST(GETDATE() AS date)
               AND DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) > 45
              THEN B.mtopenori/100.0 ELSE 0 END AS decimal(18,2)) AS [45+],
    CAST((
          CASE WHEN B.fchvto_dt < CAST(GETDATE() AS date)
                 AND DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 1 AND 15
               THEN B.mtopenori/100.0 ELSE 0 END
        + CASE WHEN B.fchvto_dt < CAST(GETDATE() AS date)
                 AND DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 16 AND 30
               THEN B.mtopenori/100.0 ELSE 0 END
        + CASE WHEN B.fchvto_dt < CAST(GETDATE() AS date)
                 AND DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) BETWEEN 31 AND 45
               THEN B.mtopenori/100.0 ELSE 0 END
        + CASE WHEN B.fchvto_dt < CAST(GETDATE() AS date)
                 AND DATEDIFF(DAY, B.fchvto_dt, CAST(GETDATE() AS date)) > 45
               THEN B.mtopenori/100.0 ELSE 0 END
        ) AS decimal(18,2)) AS [Total vencido]
  FROM Base B
  INNER JOIN SaldosPositivos SP
          ON SP.codopr = B.codopr
         AND SP.codgas = B.codgas
)
SELECT *
FROM Detalles
ORDER BY codopr, Cliente, codgas, Estacion, fchope_dt, fchvto_dt;

DROP TABLE IF EXISTS #MovPen;
SQL;

    // Orden de parámetros: CtaDde, CtaHta, GasDde, GasHta (estrictos)
    $params = [$cta, $cta, $gas, $gas];
    return $this->sql->select($sql, $params) ?: false;
}



}