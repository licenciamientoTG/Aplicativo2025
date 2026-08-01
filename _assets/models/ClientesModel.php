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

    public function get_clients_credit($status) : array|false {
        $query_status = '';
        if ($status == 0) {
            $query_status = ' ';
        } elseif ($status == 1) {
            $query_status = ' AND t1.codest = 1';
        } elseif ($status == 2) {
            $query_status = ' AND t1.codest = 0';
        } else {
            return false;
        }
        $query = 'SELECT
                    t1.cod,
                    t1.den,
                    t1.tipval,
                    CASE
                        WHEN t1.codest = 1 THEN \'Suspendido\'
                        WHEN t1.codest = 0 THEN \'Activo\'
                        ELSE \'NA\'
                    END AS [status],
                    t1.dom,
                    t1.rfc,
                    t1.cresdo,
                    t1.mtoasg,
                    t1.cndpag
                FROM [SG12].[dbo].[Clientes] t1
                WHERE tipval = 3' . $query_status . '
                ORDER BY t1.den';
        return $this->sql->select($query, []) ?: false;
    }

    public function get_dispatches_by_client(int $from, int $until, int $tipval, int $codcli = 0) : array|false {
        $cli_filter = $codcli > 0 ? "AND t1.codcli = {$codcli}" : '';
        $query = "SELECT
                    t2.den AS Cliente,
                    t1.codcli,
                    CAST(CONVERT(VARCHAR(10), CAST(t1.fchtrn AS DATETIME) - 1, 23) AS VARCHAR(10)) AS Fecha,
                    t1.hratrn,
                    t1.nrotrn,
                    t1.can   AS Litros,
                    t1.mto   AS Monto,
                    t4.abr   AS Estacion,
                    t3.den as producto
                FROM [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                LEFT JOIN [SG12].[dbo].[Clientes]    t2 ON t1.codcli = t2.cod
                LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod
                LEFT JOIn [SG12].[dbo].[Productos] t3 on t1.codprd = t3.cod
                WHERE t1.fchtrn BETWEEN {$from} AND {$until}
                    AND t1.codcli > 0
                    AND t2.tipval = {$tipval}
                    {$cli_filter}
                ORDER BY t2.den, t1.fchtrn, t1.hratrn";
        return $this->sql->select($query) ?: false;
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

    function get_debit_clients_list() : array {
        $query = "SELECT cod, den FROM [SG12].[dbo].[Clientes] WHERE tipval = 4 and codest =0  ORDER BY den;";
        return $this->sql->select($query) ?: [];
    }
    function get_credit_clients_list() : array {
        $query = "SELECT cod, den FROM [SG12].[dbo].[Clientes] WHERE tipval = 3 and codest =0  ORDER BY den;";
        return $this->sql->select($query) ?: [];
    }

// Para el reporte de antiguedad de saldos

// Obtiene opciones para el <select> de estaciones
    public function get_gasolineras(): array
    {
        $query = "SELECT cod, den FROM [SG12].dbo.Gasolineras WHERE ISNULL(codest,0) <> -1 and cod not in (0,4,20) ORDER BY den";
        return $this->sql->select($query, []) ?: [];
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

// ── Estado de cuenta ────────────────────────────────────────────────────────

/**
 * Movimientos de un cliente de crédito en el periodo (fechas internas int).
 * Fuente: MovimientosAnl, mismos filtros que sp_SelMovPen / antigüedad de saldos.
 */
public function get_account_statement_credit(int $from, int $until, int $codcli) : array|false {
    $query = "
        SELECT
            M.tipope,
            G.den AS Estacion,
            CONVERT(varchar(10), DATEADD(DAY, M.fchope - 1, '19000101'), 23) AS Fecha,
            CONVERT(varchar(10), DATEADD(DAY, M.fchvto - 1, '19000101'), 23) AS Vencimiento,
            CASE WHEN M.tipope = 3      THEN 'CARGO (factura)'
                 WHEN M.tipope IN (4,6) THEN 'ABONO (pago / nota de crédito)'
                 ELSE 'OTRO (' + CAST(M.tipope AS varchar) + ')' END AS Movimiento,
            CASE
              WHEN M.nroope BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 1200000000 AND 1299999999 THEN 'D ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 1500000000 AND 1599999999 THEN 'G ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              ELSE CAST(M.nroope AS varchar(10))
            END AS Documento,
            CAST(M.mtoopeori/100.0 AS decimal(18,2)) AS Importe,
            CAST(M.mtopenori/100.0 AS decimal(18,2)) AS Pendiente,
            CASE WHEN M.mtopenori = 0            THEN 'Saldado'
                 WHEN M.mtopenori = M.mtoopeori  THEN 'Sin aplicar'
                 ELSE 'Parcial' END AS Estatus,
            CASE
              WHEN ISNULL(M.nroref, 0) = 0 THEN ''
              WHEN M.nroref BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(M.nroref AS varchar(10)),4,10)
              WHEN M.nroref BETWEEN 1200000000 AND 1299999999 THEN 'D ' + SUBSTRING(CAST(M.nroref AS varchar(10)),4,10)
              WHEN M.nroref BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(M.nroref AS varchar(10)),4,10)
              WHEN M.nroref BETWEEN 1500000000 AND 1599999999 THEN 'G ' + SUBSTRING(CAST(M.nroref AS varchar(10)),4,10)
              WHEN M.nroref BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(M.nroref AS varchar(10)),4,10)
              WHEN M.nroref BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(M.nroref AS varchar(10)),4,10)
              WHEN M.nroref BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(M.nroref AS varchar(10)),4,10)
              WHEN M.nroref BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(M.nroref AS varchar(10)),4,10)
              ELSE CAST(M.nroref AS varchar(10))
            END AS Referencia
        FROM [SG12].dbo.MovimientosAnl M WITH (NOLOCK)
        LEFT JOIN [SG12].dbo.Gasolineras G ON G.cod = M.codgas
        WHERE M.tipopr = 1
          AND M.tipmov = 1
          AND M.tipope <> 101
          AND M.codopr = ?
          AND M.fchope BETWEEN ? AND ?
        ORDER BY M.fchope, M.nroope";
    return $this->sql->select($query, [$codcli, $from, $until]) ?: false;
}

/**
 * Saldo inicial (movimientos previos al periodo) y saldo oficial de un cliente de crédito.
 * EXPERIMENTO: el saldo inicial solo considera movimientos del año de la fecha "Desde"
 * (corte al 1 de enero) — ignora el histórico anterior.
 */
public function get_initial_balance_credit(int $from, int $codcli) : array|false {
    $query = "
        DECLARE @Desde INT = ?;
        DECLARE @IniAnio INT = DATEDIFF(dd, 0, DATEFROMPARTS(YEAR(DATEADD(DAY, @Desde - 1, '19000101')), 1, 1)) + 1;

        SELECT
            (SELECT CAST(ISNULL(SUM(mtoopeori),0)/100.0 AS decimal(18,2))
               FROM [SG12].dbo.MovimientosAnl WITH (NOLOCK)
              WHERE tipopr = 1 AND tipmov = 1 AND tipope <> 101
                AND codopr = ?
                AND fchope >= @IniAnio AND fchope < @Desde) AS SaldoInicial,
            C.cresdo AS SaldoSistema,
            C.den    AS Cliente
        FROM [SG12].dbo.Clientes C
        WHERE C.cod = ?";
    return ($rs = $this->sql->select($query, [$from, $codcli, $codcli])) ? $rs[0] : false;
}

/**
 * Movimientos de un cliente de débito en el periodo (fechas internas int).
 * Abonos = facturas de anticipo (DocumentosC/Documentos); cargos = consumos (Despachos).
 */
public function get_account_statement_debit(int $from, int $until, int $codcli) : array|false {
    $query = "
        SELECT Fecha, Movimiento, Detalle, Estacion, Monto FROM (
            SELECT
                CONVERT(varchar(10), DATEADD(DAY, t1.fch - 1, '19000101'), 23) AS Fecha,
                t1.fch AS fchint,
                'ABONO (anticipo)' AS Movimiento,
                'Anticipo ' + CAST(t1.nro AS varchar(12)) AS Detalle,
                G.den AS Estacion,
                CAST(SUM(t2.mtoori + t2.mtoiva)/100.0 AS decimal(18,2)) AS Monto
            FROM [SG12].dbo.DocumentosC t1 WITH (NOLOCK)
            JOIN [SG12].dbo.Documentos t2 WITH (NOLOCK)
              ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
            LEFT JOIN [SG12].dbo.Gasolineras G ON t1.codgas = G.cod
            WHERE t2.codopr = ?
              AND t1.fch BETWEEN ? AND ?
              AND t2.mtoiva > 0
              AND t2.codprd NOT IN (1,2,3,-64,179,180,181,192,193)
              AND t2.mto > 100
              AND ISNULL(t1.flgcon, 0) <> 141   -- excluir facturas de anticipo canceladas
            GROUP BY t1.fch, t1.nro, G.den

            UNION ALL

            SELECT
                CONVERT(varchar(10), CAST(d.fchtrn AS datetime) - 1, 23),
                d.fchtrn,
                'CARGO (consumo)',
                ISNULL(p.den,'') + ' / trn ' + CAST(d.nrotrn AS varchar(12)),
                g.abr,
                CAST(-d.mto AS decimal(18,2))   -- Despachos.mto ya viene en pesos
            FROM [SG12].dbo.Despachos d WITH (NOLOCK)
            LEFT JOIN [SG12].dbo.Productos   p ON d.codprd = p.cod
            LEFT JOIN [SG12].dbo.Gasolineras g ON d.codgas = g.cod
            WHERE d.codcli = ?
              AND d.fchtrn BETWEEN ? AND ?
        ) X
        ORDER BY fchint, Movimiento";
    return $this->sql->select($query, [$codcli, $from, $until, $codcli, $from, $until]) ?: false;
}

/**
 * Resumen del periodo para TODOS los clientes de crédito con movimientos:
 * saldo inicial, cargos, consumos (despachos), abonos y saldo final por cliente.
 * Consumos viene de Despachos (lo despachado); Cargos de MovimientosAnl (lo
 * facturado) — si difieren hay consumo pendiente de facturar.
 */
public function get_account_summary_credit(int $from, int $until) : array|false {
    $query = "
        DECLARE @Hoy INT = DATEDIFF(dd, 0, CAST(GETDATE() AS date)) + 1;
        DECLARE @Desde INT = ?;
        DECLARE @Hasta INT = ?;
        -- EXPERIMENTO: el saldo inicial solo considera movimientos del año de \"Desde\"
        DECLARE @IniAnio INT = DATEDIFF(dd, 0, DATEFROMPARTS(YEAR(DATEADD(DAY, @Desde - 1, '19000101')), 1, 1)) + 1;

        ;WITH Mov AS (
            SELECT M.codopr, M.tipope, M.fchope, M.fchvto, M.mtoopeori, M.mtopenori
            FROM [SG12].dbo.MovimientosAnl M WITH (NOLOCK)
            JOIN [SG12].dbo.Clientes C ON C.cod = M.codopr
            WHERE M.tipopr = 1 AND M.tipmov = 1 AND M.tipope <> 101
              AND C.tipval = 3 AND C.codest <> -1
        ),
        SaldoIni AS (
            SELECT codopr, SUM(mtoopeori)/100.0 AS SaldoInicial
            FROM Mov WHERE fchope >= @IniAnio AND fchope < @Desde GROUP BY codopr
        ),
        Det AS (
            SELECT codopr,
                   SUM(CASE WHEN tipope = 3      THEN mtoopeori/100.0 ELSE 0 END) AS Cargos,
                   SUM(CASE WHEN tipope IN (4,6) THEN mtoopeori/100.0 ELSE 0 END) AS Abonos,
                   SUM(mtoopeori/100.0) AS Neto
            FROM Mov WHERE fchope BETWEEN @Desde AND @Hasta
            GROUP BY codopr
        ),
        Con AS (
            SELECT d.codcli, CAST(SUM(d.mto) AS decimal(18,2)) AS Consumos
            FROM [SG12].dbo.Despachos d WITH (NOLOCK)
            WHERE d.fchtrn BETWEEN @Desde AND @Hasta AND d.codcli > 0
            GROUP BY d.codcli
        ),
        Pen AS (   -- partidas abiertas HOY, clasificadas por su fecha de vencimiento
            SELECT codopr,
                   SUM(CASE WHEN fchvto >= @Hoy THEN mtopenori/100.0 ELSE 0 END) AS PorVencer,
                   SUM(CASE WHEN fchvto <  @Hoy THEN mtopenori/100.0 ELSE 0 END) AS Vencido
            FROM Mov WHERE mtopenori <> 0
            GROUP BY codopr
        )
        SELECT
            C.cod AS CodCliente,
            C.den AS Cliente,
            CAST(ISNULL(SI.SaldoInicial,0) AS decimal(18,2)) AS SaldoInicial,
            CAST(ISNULL(D.Cargos,0)  AS decimal(18,2)) AS Cargos,
            ISNULL(Co.Consumos,0)                      AS Consumos,
            CAST(ISNULL(D.Abonos,0)  AS decimal(18,2)) AS Abonos,
            -- Abonos viene con signo negativo: positiva = consumió más de lo que pagó
            CAST(ISNULL(Co.Consumos,0) + ISNULL(D.Abonos,0) AS decimal(18,2)) AS Diferencia,
            CAST(ISNULL(SI.SaldoInicial,0) + ISNULL(D.Neto,0) AS decimal(18,2)) AS SaldoFinal,
            C.cresdo AS SaldoSistema,
            CAST(ISNULL(P.PorVencer,0) AS decimal(18,2)) AS PorVencer,
            CAST(ISNULL(P.Vencido,0)   AS decimal(18,2)) AS Vencido
        FROM [SG12].dbo.Clientes C
        LEFT JOIN Det D       ON D.codopr  = C.cod
        LEFT JOIN SaldoIni SI ON SI.codopr = C.cod
        LEFT JOIN Con Co      ON Co.codcli = C.cod
        LEFT JOIN Pen P       ON P.codopr  = C.cod
        WHERE C.tipval = 3 AND C.codest <> -1
          AND (D.codopr IS NOT NULL OR Co.codcli IS NOT NULL)
        ORDER BY C.den";
    return $this->sql->select($query, [$from, $until]) ?: false;
}

/** Facturas vencidas HOY de un cliente de crédito (partidas abiertas con vencimiento pasado). */
public function get_client_overdue(int $codcli) : array|false {
    $query = "
        SELECT
            CONVERT(varchar(10), DATEADD(DAY, M.fchope - 1, '19000101'), 23) AS Fecha,
            CONVERT(varchar(10), DATEADD(DAY, M.fchvto - 1, '19000101'), 23) AS Vencimiento,
            CASE
              WHEN M.nroope BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 1200000000 AND 1299999999 THEN 'D ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 1500000000 AND 1599999999 THEN 'G ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              WHEN M.nroope BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(M.nroope AS varchar(10)),4,10)
              ELSE CAST(M.nroope AS varchar(10))
            END AS Documento,
            G.den AS Estacion,
            CAST(M.mtoopeori/100.0 AS decimal(18,2)) AS Importe,
            CAST(M.mtopenori/100.0 AS decimal(18,2)) AS Pendiente,
            DATEDIFF(DAY, DATEADD(DAY, M.fchvto - 1, '19000101'), CAST(GETDATE() AS date)) AS DiasVencido
        FROM [SG12].dbo.MovimientosAnl M WITH (NOLOCK)
        LEFT JOIN [SG12].dbo.Gasolineras G ON G.cod = M.codgas
        WHERE M.tipopr = 1 AND M.tipmov = 1 AND M.tipope <> 101
          AND M.codopr = ?
          AND M.mtopenori <> 0
          AND M.fchvto < DATEDIFF(dd, 0, CAST(GETDATE() AS date)) + 1
        ORDER BY M.fchvto, M.nroope";
    return $this->sql->select($query, [$codcli]) ?: false;
}

/**
 * Resumen del periodo para TODOS los clientes de débito con movimientos:
 * anticipos y consumos del periodo (sin saldo inicial, para no recorrer
 * todo el histórico de Despachos) más la bolsa oficial.
 */
public function get_account_summary_debit(int $from, int $until) : array|false {
    $query = "
        ;WITH Ant AS (
            SELECT t2.codopr, CAST(SUM(t2.mtoori + t2.mtoiva)/100.0 AS decimal(18,2)) AS Anticipos
            FROM [SG12].dbo.DocumentosC t1 WITH (NOLOCK)
            JOIN [SG12].dbo.Documentos t2 WITH (NOLOCK)
              ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
            WHERE t1.fch BETWEEN ? AND ?
              AND t2.mtoiva > 0
              AND t2.codprd NOT IN (1,2,3,-64,179,180,181,192,193)
              AND t2.mto > 100
              AND t2.codopr <> 0
              AND ISNULL(t1.flgcon, 0) <> 141   -- excluir facturas de anticipo canceladas
            GROUP BY t2.codopr
        ),
        Con AS (
            SELECT d.codcli AS codopr, CAST(SUM(d.mto) AS decimal(18,2)) AS Consumos
            FROM [SG12].dbo.Despachos d WITH (NOLOCK)
            WHERE d.fchtrn BETWEEN ? AND ? AND d.codcli > 0
            GROUP BY d.codcli
        )
        SELECT
            C.cod AS CodCliente,
            C.den AS Cliente,
            ISNULL(A.Anticipos,0) AS Anticipos,
            ISNULL(Co.Consumos,0) AS Consumos,
            CAST(ISNULL(A.Anticipos,0) - ISNULL(Co.Consumos,0) AS decimal(18,2)) AS Diferencia,
            C.debsdo AS SaldoSistema
        FROM [SG12].dbo.Clientes C
        LEFT JOIN Ant A  ON A.codopr  = C.cod
        LEFT JOIN Con Co ON Co.codopr = C.cod
        WHERE C.tipval = 4 AND C.codest <> -1
          AND (A.codopr IS NOT NULL OR Co.codopr IS NOT NULL)
        ORDER BY C.den";
    return $this->sql->select($query, [$from, $until, $from, $until]) ?: false;
}

/** Facturas de anticipo de un cliente de débito en el periodo (para el drill-down del resumen). */
public function get_client_anticipos(int $from, int $until, int $codcli) : array|false {
    $query = "
        SELECT
            CONVERT(varchar(10), DATEADD(DAY, t1.fch - 1, '19000101'), 23) AS Fecha,
            t1.nro AS Factura,
            G.den AS Estacion,
            CAST(SUM(t2.mtoori)/100.0 AS decimal(18,2)) AS Subtotal,
            CAST(SUM(t2.mtoiva)/100.0 AS decimal(18,2)) AS IVA,
            CAST(SUM(t2.mtoori + t2.mtoiva)/100.0 AS decimal(18,2)) AS Total
        FROM [SG12].dbo.DocumentosC t1 WITH (NOLOCK)
        JOIN [SG12].dbo.Documentos t2 WITH (NOLOCK)
          ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
        LEFT JOIN [SG12].dbo.Gasolineras G ON t1.codgas = G.cod
        WHERE t2.codopr = ?
          AND t1.fch BETWEEN ? AND ?
          AND t2.mtoiva > 0
          AND t2.codprd NOT IN (1,2,3,-64,179,180,181,192,193)
          AND t2.mto > 100
          AND ISNULL(t1.flgcon, 0) <> 141   -- excluir facturas de anticipo canceladas
        GROUP BY t1.fch, t1.nro, G.den
        ORDER BY t1.fch, t1.nro";
    return $this->sql->select($query, [$codcli, $from, $until]) ?: false;
}

/** Saldo inicial (anticipos menos consumos previos al periodo) y bolsa oficial de un cliente de débito. */
public function get_initial_balance_debit(int $from, int $codcli) : array|false {
    $query = "
        SELECT
            (SELECT CAST(ISNULL(SUM(t2.mtoori + t2.mtoiva),0)/100.0 AS decimal(18,2))
               FROM [SG12].dbo.DocumentosC t1 WITH (NOLOCK)
               JOIN [SG12].dbo.Documentos t2 WITH (NOLOCK)
                 ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
              WHERE t2.codopr = ? AND t1.fch < ?
                AND t2.mtoiva > 0
                AND t2.codprd NOT IN (1,2,3,-64,179,180,181,192,193)
                AND t2.mto > 100
                AND ISNULL(t1.flgcon, 0) <> 141)
          - (SELECT CAST(ISNULL(SUM(d.mto),0) AS decimal(18,2))
               FROM [SG12].dbo.Despachos d WITH (NOLOCK)
              WHERE d.codcli = ? AND d.fchtrn < ?) AS SaldoInicial,
            C.debsdo AS SaldoSistema,
            C.den    AS Cliente
        FROM [SG12].dbo.Clientes C
        WHERE C.cod = ?";
    return ($rs = $this->sql->select($query, [$codcli, $from, $codcli, $from, $codcli])) ? $rs[0] : false;
}

}