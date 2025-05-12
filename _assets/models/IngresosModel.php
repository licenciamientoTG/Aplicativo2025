<?php
class IngresosModel extends Model{
    public int $fch; // Llave primaria
    public int $codisl; // Llave primaria
    public int $nrotur; // Llave primaria
    public int $codval; // Llave primaria
    public float $can;
    public float $mto;
    public int $codgas;
    public $logexp;
    public int $logusu;
    public $logfch;
    public $lognew;

    public function get_corte($fch, $codgas) : array | false {
        $query = "SELECT
                        TOP(1) CAST(CONVERT(VARCHAR(100), CAST(t1.fch AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha
                        , t2.den Gasolinera
                        , LEFT(t1.nrotur, LEN(t1.nrotur) - 1) AS Turno,
                        RIGHT(t1.nrotur, 1) AS Subcorte
                    FROM
                        [SG12].[dbo].[Ingresos] t1
                        LEFT JOIN [SG12].[dbo].[Gasolineras] t2 ON t1.codgas = t2.cod
                    WHERE
                        t1.fch = $fch AND
                        t1.codgas = $codgas AND
                        t1.codval IN (28,127);";
        return $this->sql->select($query, []) ?: false;
    }

    function get_cash_sales($from, $until, $codgas = null) : array | false {
        $codgas_query = '';
        if ($codgas != 0) {
            $codgas_query = ' AND i.codgas ='.$codgas;
        }
        $query = 'SELECT
                        g.cod AS CodigoGasolinera,
                            CONVERT(VARCHAR(10), DATEADD(day, -1, i.fch), 23) as Fecha, 
                        g.abr AS Gasolinera,
                        CASE
                            WHEN i.nrotur >= 11 AND i.nrotur < 21 THEN 1
                            WHEN i.nrotur >= 21 AND i.nrotur < 31 THEN 2
                            WHEN i.nrotur >= 31 AND i.nrotur < 41 THEN 3
                            WHEN i.nrotur >= 41 AND i.nrotur <= 51 THEN 4
                            ELSE i.nrotur
                        END AS Turno,
                        ISNULL(SUM(CASE WHEN v.cod = 6 THEN i.mto END), 0) AS Mn,
                        ISNULL(SUM(CASE WHEN v.cod = 5 THEN i.mto END), 0) AS Dolares2,
                        ISNULL(SUM(CASE WHEN v.cod = 192 THEN i.mto END), 0) AS Morralla,
                        ISNULL(SUM(CASE WHEN v.cod = 53 THEN i.mto END), 0) AS Cheques,
                        ISNULL(SUM(CASE WHEN v.cod = -1128 THEN i.mto END), 0) AS Dolares,
                        ISNULL(SUM(CASE WHEN v.cod = -3501 THEN i.mto END), 0) AS  [INTERL - Efectivo]
                    FROM SG12.dbo.Valores v
                    INNER JOIN SG12.dbo.Ingresos i ON v.cod = i.codval
                    INNER JOIN SG12.dbo.Gasolineras g ON i.codgas = g.cod
                    WHERE i.fch BETWEEN ? AND  ?
                    AND v.cod IN (5, 6, 192, 53, -1128,-3501) '.$codgas_query.'
                    GROUP BY 
                        CONVERT(VARCHAR(10), DATEADD(day, -1, i.fch), 23),
                        i.nrotur,
                        g.cod,
                        g.abr
                    ORDER BY g.cod, i.nrotur;';
        $params = [$from, $until];
        return $this->sql->select($query,$params) ?: false;
    }
}

