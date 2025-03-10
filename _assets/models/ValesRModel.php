<?php

class ValesRModel extends Model
{
    public int $fch;
    public int $codisl;
    public int $nrotur;
    public int $codcli;
    public int $codval;
    public float $val;
    public int $sec;
    public float $imp;
    public float $mto;
    public int $codgas;
    public int $codprd;
    public float $can;
    public int $nrofac;
    public int $gasfac;

    function get_consumes_by_island_shift($fch, $codgas)
    {
        $query = "
        SELECT
            CAST(CONVERT(VARCHAR(100), CAST(t1.fch AS DATETIME) - 1, 23) AS VARCHAR(10)) Fecha,
            CASE
                WHEN t1.codprd IN (179,192) THEN 'T-Maxima Regular'
                WHEN t1.codprd IN (180,193) THEN 'T-Super Premium'
                WHEN t1.codprd = 181 THEN 'Diesel Automotriz'
            END AS Producto,
            t1.mto Precio,
            CAST(t1.mto AS DECIMAL(10, 2)) AS Monto,
            CAST(t1.can AS DECIMAL(10, 3)) AS Volumen,
            t1.nrofac Factura,
            t3.den Cliente,
            t3.cod Codigo,
            t1.nrotur,
            nrotur / 10 AS turno,
            nrotur % 10 AS subcorte,
            t5.den Isla,
            t1.codgas,
            t1.nrofac,
            t3.tipval,
            t1.sec,
            CASE
                WHEN t3.tipval = 3 THEN N'Crédito'
                WHEN t3.tipval = 4 THEN N'Débito'
            END Tipo,
            CASE 
                WHEN t4.nrotrn IS NOT NULL AND t1.mto = t4.mto THEN 1 
                ELSE 0 
            END AS CoincidenciaEncontrada
        FROM [SG12].[dbo].[ValesR] t1
            INNER JOIN [SG12].[dbo].[Clientes] t3 ON t1.codcli = t3.cod
            INNER JOIN [SG12].[dbo].[Islas] t5 ON t1.codisl = t5.cod
            LEFT JOIN (SELECT t1.nrotrn, t1.codcli, t1.codgas, t2.tipval, t1.codisl, t1.fchcor, t1.mto FROM [SG12].[dbo].[Despachos] t1 LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod WHERE t1.codgas = $codgas AND t1.fchcor = $fch) t4 ON abs(t1.sec) = t4.nrotrn
        WHERE
            t1.fch = $fch
            AND t3.tipval IN (3,4)
            AND t1.codgas = $codgas
        ORDER BY t1.fch DESC;
        ";
        $params = [];
        return $this->sql->select($query, $params);
    }
}