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

        $codgas_filter_base   = '';
        $codgas_filter_ventas = '';

        if (!empty($codgas) && $codgas !== 0) {
            $codgas_filter_base   = ' WHERE g.cod = ' . (int)$codgas;
            $codgas_filter_ventas = ' AND i.codgas = ' . (int)$codgas;
        }

        $query = "
            WITH Fechas AS (
                SELECT CAST(? AS INT) AS fch   -- <-- CAST explícito
                UNION ALL
                SELECT fch + 1
                FROM Fechas
                WHERE fch < CAST(? AS INT)     -- <-- también aquí por consistencia
            ),
            Turnos AS (
                SELECT 1 AS Turno UNION ALL
                SELECT 2          UNION ALL
                SELECT 3          UNION ALL
                SELECT 4
            ),
            Base AS (
                SELECT
                    g.cod  AS CodigoGasolinera,
                    g.abr  AS Gasolinera,
                    CONVERT(VARCHAR(10), DATEADD(day, -1, f.fch), 23) AS Fecha,
                    t.Turno
                FROM SG12.dbo.Gasolineras g
                CROSS JOIN Fechas f
                CROSS JOIN Turnos t
                {$codgas_filter_base}
            ),
            Ventas AS (
                SELECT
                    i.codgas,
                    CONVERT(VARCHAR(10), DATEADD(day, -1, i.fch), 23) AS Fecha,
                    CASE
                        WHEN i.nrotur >= 11 AND i.nrotur < 21 THEN 1
                        WHEN i.nrotur >= 21 AND i.nrotur < 31 THEN 2
                        WHEN i.nrotur >= 31 AND i.nrotur < 41 THEN 3
                        WHEN i.nrotur >= 41 AND i.nrotur <= 51 THEN 4
                        ELSE i.nrotur
                    END AS Turno,
                    ISNULL(SUM(CASE WHEN v.cod = 6     THEN i.mto END), 0) AS Mn,
                    ISNULL(SUM(CASE WHEN v.cod = 5     THEN i.mto END), 0) AS Dolares2,
                    ISNULL(SUM(CASE WHEN v.cod = 192   THEN i.mto END), 0) AS Morralla,
                    ISNULL(SUM(CASE WHEN v.cod = 53    THEN i.mto END), 0) AS Cheques,
                    ISNULL(SUM(CASE WHEN v.cod = -1128 THEN i.mto END), 0) AS Dolares,
                    ISNULL(SUM(CASE WHEN v.cod = -3501 THEN i.mto END), 0) AS [INTERL - Efectivo]
                FROM SG12.dbo.Ingresos i
                INNER JOIN SG12.dbo.Valores v ON v.cod = i.codval
                WHERE i.fch BETWEEN ? AND ?
                AND v.cod IN (5, 6, 192, 53, -1128, -3501)
                {$codgas_filter_ventas}
                GROUP BY
                    i.codgas,
                    CONVERT(VARCHAR(10), DATEADD(day, -1, i.fch), 23),
                    CASE
                        WHEN i.nrotur >= 11 AND i.nrotur < 21 THEN 1
                        WHEN i.nrotur >= 21 AND i.nrotur < 31 THEN 2
                        WHEN i.nrotur >= 31 AND i.nrotur < 41 THEN 3
                        WHEN i.nrotur >= 41 AND i.nrotur <= 51 THEN 4
                        ELSE i.nrotur
                    END
            )
            SELECT
                b.CodigoGasolinera,
                b.Fecha,
                b.Gasolinera,
                b.Turno,
                ISNULL(ve.Mn,                  0) AS Mn,
                ISNULL(ve.Dolares2,            0) AS Dolares2,
                ISNULL(ve.Morralla,            0) AS Morralla,
                ISNULL(ve.Cheques,             0) AS Cheques,
                ISNULL(ve.Dolares,             0) AS Dolares,
                ISNULL(ve.[INTERL - Efectivo], 0) AS [INTERL - Efectivo]
            FROM Base b
            LEFT JOIN Ventas ve
                ON  ve.codgas = b.CodigoGasolinera
                AND ve.Fecha  = b.Fecha
                AND ve.Turno  = b.Turno
            ORDER BY b.CodigoGasolinera, b.Fecha, b.Turno
            OPTION (MAXRECURSION 0);
        ";
        
        $params = [$from, $until, $from, $until];

        return $this->sql->select($query, $params) ?: false;
    }

    function get_dolar_sales_table($from, $until) : array|false {
        $query = "
        SELECT
            t3.abr,
            Ingresos.codgas,
            Valores.tip,
            Valores.codbco,
            Valores.den,
            Ingresos.codval,
            Valores.prp,
            SUM(Ingresos.can) AS SumOfcan,
            SUM(Ingresos.mto) AS SumOfmto,
            ROUND(
                CASE
                    WHEN SUM(Ingresos.can) <> 0 THEN SUM(Ingresos.mto) / SUM(Ingresos.can)
                    ELSE 0
                END, 2
            ) AS TipoCambio
        FROM Valores
        INNER JOIN Ingresos ON Valores.cod = Ingresos.codval
        LEFT JOIN Gasolineras t3 ON Ingresos.codgas = t3.cod
        WHERE Ingresos.fch BETWEEN ? AND ?
        AND Valores.cod = -1128
        GROUP BY
            Ingresos.codgas, Valores.tip, Valores.codbco,
            Valores.den, Ingresos.codval, Valores.prp, t3.abr
        ORDER BY
            Valores.tip, Valores.prp, Valores.codbco,
            Valores.den, Ingresos.codval, Ingresos.codgas, t3.abr
        ";
        $params = [$from, $until];
        return $this->sql->select($query, $params) ?: false;
    }
}

