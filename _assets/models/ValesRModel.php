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
            AND t1.mto > 0
	        AND t1.can > 0
        ORDER BY t1.fch DESC;
        ";
        $params = [];
        return $this->sql->select($query, $params);
    }

    function GetCreditoProduct($from,$until,$tipo){
        $fromstring = date('Y-d-m', strtotime($from));
        $untilstring = date('Y-d-m', strtotime($until));

        $query = "
            DECLARE @fecha_inicial_int INT = DATEDIFF(dd, 0, '$fromstring') + 1;
            DECLARE @fecha_fin_int INT = DATEDIFF(dd, 0, '$untilstring') + 1;
            DECLARE @tipo INT = $tipo;

            WITH Datos AS (
                SELECT 
                v.codcli AS CodigoCliente, -- C�digo del cliente
                c.den AS Cliente, -- Nombre del cliente
                --pr.den as producto,
                CASE
                WHEN pr.den =' Gasolina Regular Menor a 91 Octanos' THEN 'T-Maxima Regular'
                WHEN pr.den ='   T-Maxima Regular' THEN 'T-Maxima Regular'
                WHEN pr.den ='   T-Super Premium' THEN 'T-Super Premium'
                WHEN pr.den =' Gasolina Premium Mayor o Igual a 91 Octanos' THEN 'T-Super Premium'
                WHEN pr.den ='   Diesel Automotriz' THEN 'Diesel Automotriz'
                ELSE ''
                END AS producto,
                CASE
                    WHEN v.codval = 28 THEN 'Credito' -- Vale de cr�dito
                    WHEN v.codval = 127 THEN 'Debito' -- Vale de d�bito
                    ELSE '' -- Otros tipos no especificados
                END AS Tipo,
                ISNULL( -- Subconsulta para obtener la suma de litros
                    (
                        SELECT SUM(d.can) 
                        FROM [SG12].dbo.Despachos d (NOLOCK)
                        WHERE d.codcli = v.codcli -- Filtrar por cliente
                        and d.codprd = v.codprd
                        AND d.fchtrn BETWEEN @fecha_inicial_int AND @fecha_fin_int
                    ), 0) AS Litros -- Valor por defecto 0 si no hay datos
            FROM 
                [SG12].[dbo].[ValesR] v
            LEFT JOIN [SG12].[dbo].[Clientes] c ON c.cod = v.codcli -- Unir con Clientes
            LEFT JOIN [SG12].dbo.Productos pr on v.codprd = pr.cod
            WHERE 
                c.codest >= 0 -- Filtrar clientes activos
                AND v.fch BETWEEN @fecha_inicial_int AND @fecha_fin_int -- Filtrar por rango de fechas
                AND v.codval IN (@tipo) -- Filtrar por tipos de vale espec�ficos
                and pr.den is not null
            GROUP BY	
                v.codcli, -- Agrupar por cliente
                pr.den, -- Agrupar por cliente
                v.codprd, -- Agrupar por nombre de cliente
                c.den, -- Agrupar por nombre de cliente
                CASE 
                    WHEN v.codval = 28 THEN 'Credito' -- Agrupar por tipo de vale
                    WHEN v.codval = 127 THEN 'Debito'
                    ELSE ''
                END
            )
            --select * from Datos

            -- PIVOT: Litros por Producto
            SELECT 
                CodigoCliente,
                Cliente,
                Tipo,
                ISNULL([Diesel Automotriz], 0) AS [Diesel Automotriz],
                ISNULL([T-Maxima Regular], 0) AS [T-Maxima Regular],
                ISNULL([T-Super Premium], 0) AS [T-Super Premium],
                ISNULL([Diesel Automotriz], 0) 
				+ ISNULL([T-Maxima Regular], 0) 
				+ ISNULL([T-Super Premium], 0) AS [Total Litros]
            FROM Datos
            PIVOT (
                SUM(Litros)
                FOR Producto IN ([Diesel Automotriz], [T-Maxima Regular], [T-Super Premium])
            ) AS p
            ORDER BY CodigoCliente;
        ";

        $params = [];
        return $this->sql->select($query, $params);
    }
}