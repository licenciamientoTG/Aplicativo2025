<?php

class DocumentosModel extends Model{

    function get_month_anticipos($from, $until) : array | false {
        $query = "
            DECLARE @DateFrom DATE = '{$from}';
            DECLARE @DateTo DATE = '{$until}';
            SELECT * FROM [SG12].[dbo].[VTGAnticiposClientesDebitoXMes] WHERE [DiaInicialDelMes] >= @DateFrom AND [DiaFinalDelMes] <= @DateTo ORDER BY Mes DESC;";
        return $this->sql->select($query);
    }

    function get_anticipos($from, $until) {
        $query = "
            DECLARE @DateFrom DATE = '{$from}';
            DECLARE @DateTo DATE = '{$until}';

            SELECT * FROM [SG12].[dbo].[VTGAnticiposClientesDebito] WHERE Fecha BETWEEN @DateFrom AND @DateTo ORDER BY Fecha DESC;
        ";
        return $this->sql->select($query);
    }
    function GetInvoicePurchase($from, $until,$product){
        $queryProduct='';
        if($product == 1){
            $queryProduct = "and t3.codprd in (179, 192)";
        }
        if($product == 2){
            $queryProduct = "and t3.codprd in (180, 193)";
        }
        if($product == 3){
            $queryProduct = "and t3.codprd in (181)";
        }
        $fromInt = dateToInt($from);
        $untilInt = dateToInt($until);

        $query = "

       SELECT 
    CONVERT(SMALLDATETIME, t1.fch - 1, 103) AS 'Fecha',
    CONVERT(SMALLDATETIME, t1.vto - 1, 103) AS 'Fecha_vencimiento',
    t2.cod AS cod_proveedor,
    t2.den AS proveedor,
    REPLACE(
        SUBSTRING(
            t1.txtref, 
            CHARINDEX('@F:', t1.txtref) + 3, 
            CHARINDEX('@', t1.txtref, CHARINDEX('@F:', t1.txtref) + 3) - CHARINDEX('@F:', t1.txtref) - 3
        ), 
        '-', 
        ''
    ) COLLATE Modern_Spanish_CI_AS AS Factura,
    t1.txtref,
	t1.codgas,
	t6.abr as Estacion,
    t4.den AS producto,
    t5.den AS Empresa,
    t1.satuid,
    t3.can,
    t3.pre,
    (t3.mto)/100 AS mto,
    (t3.mtoori)/100 AS mtoori,
    (t3.mtoiva)/100 AS mtoiva,
    (t3.mtoiie)/100 AS mtoiie,
	vt.imp_importe_simptos as Subtotal,
    vt.imp_total as Total,
    vt.imp_impto as IvaImporte,
	vt2.imp_cant as cantidad,
	vt2.imp_ult_cto as precio,
	vt2.imp_importe as importe,
	vt2.imp_mto_ieps as IEPS,
	vt2.imp_des_pro,
	vt2.imp_id_otr_sis_pro
FROM SG12.dbo.DocumentosC t1
LEFT JOIN SG12.dbo.Proveedores t2 ON t1.codopr = t2.cod   
LEFT JOIN SG12.dbo.Documentos t3 ON t1.nro = t3.nro AND t1.codgas = t3.codgas AND t3.nroitm = 1 AND t3.tip = 1
LEFT JOIN SG12.dbo.Productos t4 ON t3.codprd = t4.cod 
LEFT JOIN SG12.dbo.Empresas t5 ON t1.codemp = t5.cod
Left JOIN SG12.dbo.Gasolineras t6 on t1.codgas= t6.cod
LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.imp_com_doc vt   ON  t1.satuid  COLLATE Modern_Spanish_CI_AS  = vt.imp_uuid  COLLATE Modern_Spanish_CI_AS 
LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.imp_com_part vt2   ON vt.imp_id_com  =vt2.imp_id_com and vt2.imp_id_otr_sis_pro !='0'
WHERE 
    t1.fch BETWEEN $fromInt AND $untilInt
    AND t1.codemp = 1 
    AND t1.tip = 1
    AND t1.codopr != 0
    AND t1.satuid IS NOT NULL
	 $queryProduct
                            
        ";

    

        return $this->sql->select($query, []);
    } 


    function GetInvoicePurchase2($from, $until, $product) {
        $queryProduct = '';
        if ($product == 1) {
            $queryProduct = "and t3.codprd in (179, 192)";
        }
        if ($product == 2) {
            $queryProduct = "and t3.codprd in (180, 193)";
        }
        if ($product == 3) {
            $queryProduct = "and t3.codprd in (181)";
        }
        $fromInt = dateToInt($from);
        $untilInt = dateToInt($until);
    
        // Instead of temporary tables, use a subquery approach
        $query = "
        SELECT 
            base.*,
            vt.no_fact,
            Ogcfdi.RfcEmisor,
            Ogcfdi.Subtotal,
            Ogcfdi.Total,
            Ogcfdi.MetodoPago,
            Ogcfdi.IvaImporte,
            Ogcfdi.TasaOCuota
        FROM (
            SELECT 
                CONVERT(VARCHAR, CONVERT(SMALLDATETIME, t1.fch - 1, 103), 103) AS 'Fecha',
                CONVERT(VARCHAR, CONVERT(SMALLDATETIME, t1.vto - 1, 103), 103) AS 'Fecha_vencimiento',
                t2.cod AS cod_proveedor,
                t2.den AS proveedor,
                REPLACE(
                    SUBSTRING(
                        t1.txtref, 
                        CHARINDEX('@F:', t1.txtref) + 3, 
                        CHARINDEX('@', t1.txtref, CHARINDEX('@F:', t1.txtref) + 3) - CHARINDEX('@F:', t1.txtref) - 3
                    ), 
                    '-', 
                    ''
                ) COLLATE Modern_Spanish_CI_AS AS Factura,
                t1.txtref,
                t4.den AS producto,
                t5.den AS Empresa,
                t1.satuid,
                t3.can,
                t3.pre,
                (t3.mto)/100 AS mto,
                (t3.mtoori)/100 AS mtoori,
                (t3.mtoiva)/100 AS mtoiva,
                (t3.mtoiie)/100 AS mtoiie
            FROM SG12.dbo.DocumentosC t1
            LEFT JOIN SG12.dbo.Proveedores t2 ON t1.codopr = t2.cod   
            LEFT JOIN SG12.dbo.Documentos t3 ON t1.nro = t3.nro AND t1.codgas = t3.codgas AND t3.nroitm = 1 AND t3.tip = 1
            LEFT JOIN SG12.dbo.Productos t4 ON t3.codprd = t4.cod 
            LEFT JOIN SG12.dbo.Empresas t5 ON t1.codemp = t5.cod
            WHERE 
                t1.fch BETWEEN $fromInt AND $untilInt
                AND t1.codemp = 1 
                AND t1.tip = 1
                AND t1.codopr != 0
                AND t1.satuid IS NOT NULL
                $queryProduct
        ) AS base
        LEFT JOIN OPENQUERY([192.168.0.5], 
            'SELECT
                t1.RfcEmisor,
                t1.Subtotal, 
                t1.Total, 
                t1.MetodoPago, 
                t1.UUIDTimbre,
                sub.IvaImporte,
                sub.TasaOCuota
            FROM [1G_TOTALGAS].dbo.CFDIdataComprobante t1
            LEFT JOIN (  
                SELECT 
                    t1.IdComprobante,
                    t1.UUIDTimbre,
                    SUM(t3.importe) AS IvaImporte,
                    TasaOCuota 
                FROM [1G_TOTALGAS].[dbo].[CFDIdataComprobante] t1
                LEFT JOIN [1G_TOTALGAS].[dbo].[CFDIdataConcepto] t2 ON t1.IdComprobante = t2.IdComprobante
                LEFT JOIN [1G_TOTALGAS].[dbo].[CFDIdataImpuestoConcepto] t3 ON t2.IdConcepto = t3.IdConcepto
                GROUP BY t1.IdComprobante, t1.UUIDTimbre, TasaOCuota
            ) sub ON sub.UUIDTimbre = t1.UUIDTimbre') Ogcfdi 
        ON Ogcfdi.UUIDTimbre COLLATE Modern_Spanish_CI_AS = base.satuid COLLATE Modern_Spanish_CI_AS
        LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.vt_cxp_pagos_det vt 
        ON base.Factura COLLATE Modern_Spanish_CI_AS = vt.no_fact COLLATE Modern_Spanish_CI_AS
        ORDER BY Fecha";
    
        return $this->sql->select($query, []);
    }

    function get_anticipos_customer($from, $until) : array | false {
        $query = "
            DECLARE @from DATE = '{$from}';
            DECLARE @until DATE = '{$until}';
            
            WITH Consumos AS (
                SELECT
                    t1.codcli,
                    SUM(t1.mto) Consumos
                FROM
                    [SG12].[dbo].[Despachos] t1
                    LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod
                WHERE
                    t1.fchcor BETWEEN (DATEDIFF(dd, 0-1, @from)) AND (DATEDIFF(dd, -1, @until))
                    AND t2.tipval = 4
                    AND t1.mto > 0
                GROUP BY t1.codcli
            )
            SELECT
                t2.codopr AS cod,
                t3.den AS Cliente,
                t3.rfc,
                N'Débito' Tipo,
                CASE 
                    WHEN t3.codest < 0 THEN N'Deshabilitado'
                    ELSE N'Activo'
                END AS status,
                'Anticipo del bien o servicio' AS Producto,
                SUM((t2.mtoori + t2.mtoiva) / 100) AS AnticiposPeriodo,
                ISNULL(c.Consumos, 0) AS ConsumosPeriodo,
                SUM((t2.mtoori + t2.mtoiva) / 100) - ISNULL(c.Consumos, 0) AS Diferencia,
                t3.debsdo SaldoIngresos,
                t5.Monto AS UltimoAnticipoMonto,
                t5.FechaUltimoAnticipo,
                t6.mto AS UltimoConsumoMonto,
                t6.FechaUltimoConsumo
            FROM 
                [SG12].[dbo].[DocumentosC] t1 WITH (NOLOCK)
                LEFT JOIN [SG12].[dbo].[Documentos] t2 WITH (NOLOCK) ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
                INNER JOIN [SG12].[dbo].[Clientes] t3 WITH (NOLOCK) ON t2.codopr = t3.cod
                LEFT JOIN Consumos c WITH (NOLOCK) ON t2.codopr = c.codcli
                LEFT JOIN [dbo].[VTGUltimosAnticiposDebito] t5 ON t2.codopr = t5.codopr
                LEFT JOIN [dbo].[VTGUltimosConsumosDebito] t6 ON t2.codopr = t6.codcli
            WHERE
                t1.fch BETWEEN (DATEDIFF(dd, 0, @from) + 1) AND (DATEDIFF(dd, 0, @until) + 1)
                AND t2.mtoiva > 0
                AND t2.codprd NOT IN (1, 2, 3, -64, 179, 180, 181, 192, 193)
                AND t2.mto > 100
                AND t2.codopr <> 0
                AND t3.tipval = 4
            GROUP BY 
                t2.codopr, t3.den, t3.rfc, c.Consumos, t3.debsdo, t3.tipval, t3.codest, t5.Monto, t5.FechaUltimoAnticipo, t6.mto, t6.FechaUltimoConsumo
            ORDER BY t2.codopr ASC;
        ";


        return $this->sql->select($query);
    }

    function get_anticipos_customer_80($from, $until) : array | false {
        $query = "
            DECLARE @DateFrom DATE = '{$from}';
            DECLARE @DateTo DATE = '{$until}';
            
            -- Consulta principal para calcular los anticipos por cliente
            WITH DocumentosData AS (
                SELECT
                    t3.cod,
                    t3.den Cliente,
                    t3.rfc,
                    'Débito' Tipo, 
                    'Anticipo del bien o servicio' Producto,
                    SUM((t1.mtoori / 100)) Monto,
                    SUM((t1.mtoiva / 100)) IVA,
                    SUM(((t1.mtoori / 100) + (t1.mtoiva / 100))) Total
                FROM
                    [SG12].[dbo].[Documentos]  t1 WITH (NOLOCK)
                    LEFT JOIN [SG12].[dbo].[DocumentosC] t2 ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
                    INNER JOIN [SG12].[dbo].[Clientes] t3 ON t1.codopr = t3.cod
                WHERE
                    t1.codprd = 163 AND
                    t1.tip = 3 AND
                    t2.fch >= (DATEDIFF(dd, 0, @DateFrom) + 1) AND
                    t2.fch <= (DATEDIFF(dd, 0, @DateTo) + 1)
                GROUP BY t3.cod, t3.den, t3.rfc
            ),
            -- Consulta de consumos
            ConsumosData AS (
                SELECT
                    codcli,
                    SUM(mto) Consumos
                FROM
                    [SG12].[dbo].[Despachos] t1 WITH (NOLOCK)
                WHERE
                    t1.fchcor >= (DATEDIFF(dd, 0, @DateFrom) + 1) AND
                    t1.fchcor <= (DATEDIFF(dd, 0, @DateTo) + 1)
                GROUP BY t1.codcli
            ),
            -- Total general de anticipos
            TotalAnticipos AS (
                SELECT SUM(Total) AS TotalGeneral
                FROM DocumentosData
            ),
            -- Calcular el porcentaje y el total acumulado
            AnticiposPorCliente AS (
                SELECT
                    d.cod,
                    d.Cliente,
                    d.rfc,
                    d.Monto,
                    d.IVA,
                    d.Total,
                    ISNULL(c.Consumos, 0) Consumos,
                    d.Total - ISNULL(c.Consumos, 0) Diferencia,
                    d.Total / tg.TotalGeneral * 100 AS Porcentaje,
                    SUM(d.Total) OVER (ORDER BY d.Total DESC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS TotalAcumulado
                FROM
                    DocumentosData d
                LEFT JOIN
                    ConsumosData c ON d.cod = c.codcli,
                    TotalAnticipos tg
            )
            -- Seleccionar clientes que representan el 80% del total de anticipos
            SELECT
                cod,
                Cliente,
                rfc,
                'Débito' Tipo, 
                'Anticipo del bien o servicio' Producto,
                Monto,
                IVA,
                Total,
                Consumos,
                Diferencia,
                Porcentaje,
                TotalAcumulado
            FROM
                AnticiposPorCliente
            WHERE
                TotalAcumulado <= (SELECT TotalGeneral * 0.8 FROM TotalAnticipos)
            ORDER BY
                Total DESC;
        ";
        return $this->sql->select($query);
    }

    function get_anticipos_customer_20($from, $until) : array | false {
        $query = "
            DECLARE @DateFrom DATE = '{$from}';
            DECLARE @DateTo DATE = '{$until}';
            
            -- Consulta principal para calcular los anticipos por cliente
            WITH DocumentosData AS (
                SELECT
                    t3.cod,
                    t3.den Cliente,
                    t3.rfc,
                    'Débito' Tipo, 
                    'Anticipo del bien o servicio' Producto,
                    SUM((t1.mtoori / 100)) Monto,
                    SUM((t1.mtoiva / 100)) IVA,
                    SUM(((t1.mtoori / 100) + (t1.mtoiva / 100))) Total
                FROM
                    [SG12].[dbo].[Documentos] t1
                    LEFT JOIN [SG12].[dbo].[DocumentosC] t2 ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
                    INNER JOIN [SG12].[dbo].[Clientes] t3 ON t1.codopr = t3.cod
                WHERE
                    (t1.codprd NOT IN (1, 2, 3, -64, 179, 180, 181, 192, 193)) AND 
                    t1.tip = 3 AND
                    t2.fch >= (DATEDIFF(dd, 0, @DateFrom) + 1) AND
                    t2.fch <= (DATEDIFF(dd, 0, @DateTo) + 1)
                GROUP BY t3.cod, t3.den, t3.rfc
            ),
            -- Consulta de consumos
            ConsumosData AS (
                SELECT
                    codcli,
                    SUM(mto) Consumos
                FROM
                    [SG12].[dbo].[Despachos] t1
                WHERE
                    t1.fchcor >= (DATEDIFF(dd, 0, @DateFrom) + 1) AND
                    t1.fchcor <= (DATEDIFF(dd, 0, @DateTo) + 1)
                GROUP BY t1.codcli
            ),
            -- Total general de anticipos
            TotalAnticipos AS (
                SELECT SUM(Total) AS TotalGeneral
                FROM DocumentosData
            ),
            -- Calcular el porcentaje y el total acumulado
            AnticiposPorCliente AS (
                SELECT
                    d.cod,
                    d.Cliente,
                    d.rfc,
                    d.Monto,
                    d.IVA,
                    d.Total,
                    ISNULL(c.Consumos, 0) Consumos,
                    d.Total - ISNULL(c.Consumos, 0) Diferencia,
                    d.Total / tg.TotalGeneral * 100 AS Porcentaje,
                    SUM(d.Total) OVER (ORDER BY d.Total DESC ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS TotalAcumulado
                FROM
                    DocumentosData d
                LEFT JOIN
                    ConsumosData c ON d.cod = c.codcli,
                    TotalAnticipos tg
            )
            -- Seleccionar clientes que representan el otro 20% del total de anticipos
            SELECT
                cod,
                Cliente,
                rfc,
                'Débito' Tipo, 
                'Anticipo del bien o servicio' Producto,
                Monto,
                IVA,
                Total,
                Consumos,
                Diferencia,
                Porcentaje,
                TotalAcumulado
            FROM
                AnticiposPorCliente
            WHERE
                TotalAcumulado > (SELECT TotalGeneral * 0.8 FROM TotalAnticipos)
            ORDER BY
                Total DESC;
        ";
        return $this->sql->select($query);
    }

    function get_anticipos_customer_details($codcli, $from, $until) {
        $query = "
        DECLARE @DateFrom DATE = '{$from}';
        DECLARE @DateTo DATE = '{$until}';
        DECLARE @codcli INT = {$codcli};
        
        
        DECLARE @Diferencia DECIMAL(18, 2);
        
        SELECT 
            @Diferencia = (ISNULL(
                (SELECT SUM((t1.mtoori + t1.mtoiva) / 100) 
                    FROM Documentos t1
                    INNER JOIN DocumentosC t2 ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
                    WHERE (t1.codprd NOT IN (1, 2, 3, -64, 179, 180, 181, 192, 193)) 
                    AND (t1.mtoiva > 0) 
                    AND (t1.mto > 100) 
                    AND t1.codopr = @codcli 
                    AND t2.fch < dbo.DateToInt(@DateFrom)
                ), 0)
            )
            -
            (ISNULL(
                (SELECT SUM(t1.mto) 
                    FROM [SG12].[dbo].[ValesR] t1
                    WHERE t1.fch < dbo.DateToInt(@DateFrom) 
                    AND t1.codval = 127 
                    AND t1.codcli = @codcli
                    AND t1.mto > 0
                    GROUP BY t1.codcli
                ), 0)
            );

        -- Primer CTE para combinar los datos de consumo y anticipos
        WITH CombinedData AS (
            -- Consulta para los datos de consumo
            SELECT
                t1.codcli,                 -- Código del cliente
                t2.den Cliente,            -- Nombre del cliente
                t2.rfc,                    -- RFC del cliente
                'Consumo' AS Tipo,         -- Tipo de registro (Consumo)
                t1.nrofac AS FactDesp,     -- Número de factura de despacho
                t3.den Producto,           -- Nombre del producto
                0 AS MontoAnticipo,        -- Monto del anticipo (0 para consumos)
                t1.mto AS MontoConsumo,    -- Monto del consumo
                t4.abr Estacion,           -- Abreviatura de la estación
                dbo.IntToDate(t1.fch) Fecha, -- Fecha del registro, convertido desde entero a fecha
                1000 AS Saldo              -- Saldo inicial de 1000 (solo para registros de consumo)
            FROM
                [SG12].[dbo].[ValesR] t1
                LEFT JOIN [SG12].[dbo].[Clientes] t2 ON t1.codcli = t2.cod    -- Unir con la tabla de clientes
                LEFT JOIN [SG12].[dbo].[Productos] t3 ON t1.codprd = t3.cod    -- Unir con la tabla de productos
                LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod  -- Unir con la tabla de gasolineras
            WHERE
                t1.fch BETWEEN (DATEDIFF(dd, 0, @DateFrom) + 1) AND (DATEDIFF(dd, 0, @DateTo) + 1) -- Filtrar por rango de fechas
                AND t1.codval = 127     -- Filtro específico para el código de vales
                AND t1.codcli = @codcli -- Filtrar por código de cliente
                AND t1.mto > 0
            UNION ALL
        
            -- Consulta para los datos de anticipos
            SELECT
                t1.codopr AS codcli,            -- Código del cliente (para anticipos)
                t3.den AS Cliente,             -- Nombre del cliente
                t3.rfc,                       -- RFC del cliente
                'Anticipo' AS Tipo,           -- Tipo de registro (Anticipo)
                t1.nro AS FactDesp,           -- Número de documento de anticipo
                'Anticipo del bien o servicio' AS Producto, -- Descripción del producto (fijo para anticipos)
                ((t1.mtoori / 100) + (t1.mtoiva / 100)) AS MontoAnticipo, -- Cálculo del monto del anticipo
                0 AS MontoConsumo,           -- Monto del consumo (0 para anticipos)
                t4.abr AS Estacion,          -- Abreviatura de la estación
                dbo.IntToDate(t2.fch) Fecha, -- Fecha del registro, convertido desde entero a fecha
                0 AS Saldo                   -- Saldo inicial de 0 (solo para anticipos)
            FROM 
                [SG12].[dbo].[Documentos] t1
                LEFT JOIN [SG12].[dbo].[DocumentosC] t2 ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip -- Unir con la tabla de documentos complementarios
                INNER JOIN [SG12].[dbo].[Clientes] t3 ON t1.codopr = t3.cod -- Unir con la tabla de clientes
                LEFT JOIN [SG12].[dbo].[Gasolineras] t4 ON t1.codgas = t4.cod -- Unir con la tabla de gasolineras
            WHERE
                t1.codopr = @codcli AND t2.fch BETWEEN (DATEDIFF(dd, 0, @DateFrom) + 1) AND (DATEDIFF(dd, 0, @DateTo) + 1) -- Filtrar por rango de fechas y código de cliente
                AND t1.mtoiva > 0            -- Filtrar por anticipos con IVA mayor a 0
                AND codprd NOT IN (1,2,3,-64,179,180,181,192,193) -- Excluir ciertos códigos de producto
                AND t1.mto > 100             -- Filtrar por montos mayores a 100
        )
        
        -- Segundo CTE para calcular el saldo acumulado
        , CalculatedData AS (
            SELECT
                codcli,
                Cliente,
                rfc,
                Tipo,
                FactDesp,
                Producto,
                MontoAnticipo,
                MontoConsumo,
                Estacion,
                Fecha,
                -- Calculamos el saldo acumulado usando la función SUM() con OVER()
                SUM(MontoAnticipo - MontoConsumo) OVER (PARTITION BY codcli ORDER BY Fecha ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) + 
                @Diferencia AS Saldo -- Sumamos el saldo inicial de 1000
            FROM CombinedData
        )
        
        -- Consulta final para seleccionar todos los campos y ordenar los resultados por fecha
        SELECT *
        FROM CalculatedData
        ORDER BY Fecha ASC;
        ";


        return $this->sql->select($query);
    }


    function relation_invoice_advance($from,$until){

        $query ="select 
                    t1.fch,
                    t1.fchcor,
                    t1.vto,
                    CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fch), 23) as fecha,
                    CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchcor), 23) as vigencia,
                    CONVERT(VARCHAR(10), DATEADD(day, -1, t1.vto), 23) as vencimiento,
                    CASE 
                        WHEN t1.nro BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1200000000 AND 1299999999 THEN 'D ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        ELSE CAST(t1.nro AS VARCHAR(10)) 
                    END AS 'factura',
                    CASE 
                        WHEN t3.nroapl BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 1200000000 AND 1299999999 THEN 'D ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        ELSE CAST(t3.nroapl AS VARCHAR(10)) 
                    END AS 'factura_anticipo',
                    t3.nroapl,
                    ((-1 * t3.mtoapl)/100) AS monto_aplicado,
                    t2.den as client,
                    t2.tipval,
                    t1.satuid AS 'UUID',
                    t4.satuid AS 'uid_anticipo',
                    t4.mto_ori AS 'monto_original',
                    t4.txtref as txt_anticipo,
                    t4.flgcon as flg_anticipo,
                    t4.concepto AS concepto_anticipo
                    --'t1',
                    --t1.*,
                    --'t3',
                    --t3.*
                    from DocumentosC t1
                    LEFT JOIN Clientes t2 on t1.codopr = t2.cod
                    LEFT JOIN DocumentosA t3 on t1.nro = t3.nro and t3.codgas =0
                    LEFT JOIN (select 
                                ( t2.mto + t2.mtoiva)/100 as mto_ori,
                                t1.nro,
                                t1.flgcon,
                                t1.txtref,
                                t1.satuid,
                                t3.den as concepto
                                
                                from DocumentosC t1
                                LEFT JOIN Documentos t2 on t1.nro= t2.nro and t2.nroitm != (-1) and t2.codgas = 0
                                left join Productos t3 on t2.codprd = t3.cod
                                where t1.codgas = 0
                                --t1.nro =1200205972
                                ) t4 on t3.nroapl = t4.nro
                    where 
                    t1.tip=3----para credito
                    and t1.codgas= 0
                    and t1.fch BETWEEN  ? and ?
                    and t1.subope = 44 ----44 para egreso 18 para anticipo
                    --and t1.nro =1300085005
                    --and t1.codopr = 1709558
                    --and t3.tip = 3
                    --and t3.gasapl !=0
                    --and t4.flgcon !=129
                    order by t1.fch desc";
        $params = [
            $from,
             $until
        ];
        return $this->sql->select($query, $params);


    }
    function relation_credit_table($from,$until){

        $query ="select 
                    t1.fch,
                    t1.fchcor,
                    t1.vto,
                    CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fch), 23) as fecha,
                    CONVERT(VARCHAR(10), DATEADD(day, -1, t1.fchcor), 23) as vigencia,
                    CONVERT(VARCHAR(10), DATEADD(day, -1, t1.vto), 23) as vencimiento,
                    CASE 
                        WHEN t1.nro BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1200000000 AND 1299999999 THEN 'D ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1500000000 AND 1599999999 THEN 'G ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        ELSE CAST(t1.nro AS VARCHAR(10)) 
                    END AS \"factura\",
                    CASE 
                        WHEN t3.nroapl BETWEEN 2100000000 AND 2499999999 THEN 'Z ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 2000000000 AND 2099999999 THEN 'T ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 1900000000 AND 1999999999 THEN 'K ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 1100000000 AND 1199999999 THEN 'C ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 1200000000 AND 1299999999 THEN 'D ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 1700000000 AND 1799999999 THEN 'I ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t3.nroapl BETWEEN 1300000000 AND 1399999999 THEN 'E ' + SUBSTRING(CAST(t3.nroapl AS VARCHAR(10)), 4, 10)
                        WHEN t1.nro BETWEEN 1500000000 AND 1599999999 THEN 'G ' + SUBSTRING(CAST(t1.nro AS VARCHAR(10)), 4, 10)
                        ELSE CAST(t3.nroapl AS VARCHAR(10)) 
                    END AS \"factura_anticipo\",
                    t3.nroapl,
                    ((-1 * t3.mtoapl)/100) AS monto_aplicado,
                    t2.den as client,
                    t2.tipval,
                    t1.satuid AS 'UUID',
                    t4.satuid AS 'uid_anticipo',
                    t4.mto_ori AS 'monto_original',
                    t4.txtref as txt_anticipo,
                    t1.txtref AS txt_note_credit,
                    t4.flgcon as flg_anticipo,
                    'inicio t1',
                    t1.*,
                    't3',
                    t3.*
                    from DocumentosC t1
                    LEFT JOIN Clientes t2 on t1.codopr = t2.cod
                    LEFT JOIN DocumentosA t3 on t1.nro = t3.nro and t3.codgas =0
                    LEFT JOIN (SELECT 
                        d.codopr,
                        d.nro,
                        SUM((d.mto + d.mtoiva) / 100) AS mto_ori,
                        dc.flgcon,
                        dc.satuid,
                        CAST(dc.txtref AS NVARCHAR(MAX)) AS txtref -- Conversión aquí
                    FROM Documentos d
                    LEFT JOIN DocumentosC dc ON d.nro = dc.nro
                    WHERE d.nroitm != -1
                    AND d.codgas = 0
                    GROUP BY d.nro, d.codopr, dc.flgcon, dc.satuid, CAST(dc.txtref AS NVARCHAR(MAX))
                                ) t4 on t3.nroapl = t4.nro
                    where 
                    t1.tip=6----para credito
                    and t1.codgas= 0
                    and t1.fch BETWEEN  ? and ?
                    and t1.fch >  45670
                    --and t1.subope = 44 ----44 para egreso 18 para anticipo
                    --and t1.nro =1500085666
                    --and t1.codopr = 1709558
                    --and t3.tip = 3
                    --and t3.gasapl !=0
                    --and t4.flgcon !=129
                    --and t1.nro = 1300002662
                    order by t1.nro desc
                    ";
        $params = [
            $from,
             $until
        ];
        return $this->sql->select($query, $params);


    }



    function GetInvoicePurchase3($from, $until, $product) {
        $queryProduct = '';
        if ($product == 1) {
            $queryProduct = "and t3.codprd in (179, 192)";
        } elseif ($product == 2) {
            $queryProduct = "and t3.codprd in (180, 193)";
        } elseif ($product == 3) {
            $queryProduct = "and t3.codprd in (181)";
        }
        
        $fromInt = dateToInt($from);
        $untilInt = dateToInt($until);
        
        // Conexión PDO (asume que ya tienes una conexión configurada)
        // Si no la tienes, deberías crearla:
        $pdo = new PDO('sqlsrv:Server=192.168.0.6;Database=SG12', 'cguser', 'sahei1712');
        
        try {
            // 1. Primero, asegúrate de que la tabla temporal no exista
            // $pdo->exec("DROP TABLE IF EXISTS #ControlGasTable");

            $pdo->exec("IF OBJECT_ID('tempdb..#ControlGasTable') IS NOT NULL DROP TABLE #ControlGasTable");
            
            // 2. Crea la tabla temporal
            $pdo->exec("
                CREATE TABLE #ControlGasTable (
                    Fecha date,
                    Fecha_vencimiento date,
                    cod_proveedor INT,
                    proveedor VARCHAR(255),
                    Factura VARCHAR(50),
                    txtref VARCHAR(255),
                    producto VARCHAR(255),
                    Empresa VARCHAR(255),
                    satuid VARCHAR(50),
                    can DECIMAL(18,2),
                    pre DECIMAL(18,2),
                    mto DECIMAL(18,2),
                    mtoori DECIMAL(18,2),
                    mtoiva DECIMAL(18,2),
                    mtoiie DECIMAL(18,2)
                )
            ");
            
            // 3. Inserta datos en la tabla temporal
            $insertQuery = "
                INSERT INTO #ControlGasTable
                SELECT 
                    CONVERT(SMALLDATETIME, t1.fch - 1, 103) AS 'Fecha',
                    CONVERT(SMALLDATETIME, t1.vto - 1, 103) AS 'Fecha_vencimiento',
                    t2.cod AS cod_proveedor,
                    t2.den AS proveedor,
                    REPLACE(
                        SUBSTRING(
                            t1.txtref, 
                            CHARINDEX('@F:', t1.txtref) + 3, 
                            CHARINDEX('@', t1.txtref, CHARINDEX('@F:', t1.txtref) + 3) - CHARINDEX('@F:', t1.txtref) - 3
                        ), 
                        '-', 
                        ''
                    ) COLLATE Modern_Spanish_CI_AS AS Factura,
                    t1.txtref,
                    t4.den AS producto,
                    t5.den AS Empresa,
                    t1.satuid,
                    t3.can,
                    t3.pre,
                    (t3.mto)/100 AS mto,
                    (t3.mtoori)/100 AS mtoori,
                    (t3.mtoiva)/100 AS mtoiva,
                    (t3.mtoiie)/100 AS mtoiie
                FROM SG12.dbo.DocumentosC t1
                LEFT JOIN SG12.dbo.Proveedores t2 ON t1.codopr = t2.cod   
                LEFT JOIN SG12.dbo.Documentos t3 ON t1.nro = t3.nro AND t1.codgas = t3.codgas AND t3.nroitm = 1 AND t3.tip = 1
                LEFT JOIN SG12.dbo.Productos t4 ON t3.codprd = t4.cod 
                LEFT JOIN SG12.dbo.Empresas t5 ON t1.codemp = t5.cod
                WHERE 
                    t1.fch BETWEEN :fromInt AND :untilInt
                    AND t1.codemp = 1 
                    AND t1.tip = 1
                    AND t1.codopr != 0
                    AND t1.satuid IS NOT NULL
                    {$queryProduct}
            ";
            
            $stmt = $pdo->prepare($insertQuery);
            $stmt->bindParam(':fromInt', $fromInt, PDO::PARAM_INT);
            $stmt->bindParam(':untilInt', $untilInt, PDO::PARAM_INT);
            $stmt->execute();
            
            // 4. Consulta final para obtener los resultados
            $finalQuery = "
                SELECT 
                    cgt.*,
                    vt.no_fact,
                    Ogcfdi.RfcEmisor,
                    Ogcfdi.Subtotal,
                    Ogcfdi.Total,
                    Ogcfdi.MetodoPago,
                    Ogcfdi.IvaImporte,
                    Ogcfdi.TasaOCuota
                FROM #ControlGasTable cgt
                LEFT JOIN OPENQUERY([192.168.0.5], 
                    'SELECT
                        t1.RfcEmisor,
                        (t1.Subtotal - t1.Descuento) as Subtotal, 
                        (t1.Total - t1.Descuento) as Total, 
                        t1.MetodoPago, 
                        t1.UUIDTimbre,
                        sub.IvaImporte,
                        sub.TasaOCuota
                    FROM [1G_TOTALGAS].dbo.CFDIdataComprobante t1
                    LEFT JOIN (  
                        SELECT 
                            t1.IdComprobante,
                            t1.UUIDTimbre,
                            SUM(t3.importe) AS IvaImporte,
                            TasaOCuota 
                        FROM [1G_TOTALGAS].[dbo].[CFDIdataComprobante] t1
                        LEFT JOIN [1G_TOTALGAS].[dbo].[CFDIdataConcepto] t2 ON t1.IdComprobante = t2.IdComprobante
                        LEFT JOIN [1G_TOTALGAS].[dbo].[CFDIdataImpuestoConcepto] t3 ON t2.IdConcepto = t3.IdConcepto
                        GROUP BY t1.IdComprobante, t1.UUIDTimbre, TasaOCuota
                    ) sub ON sub.UUIDTimbre = t1.UUIDTimbre') Ogcfdi 
                ON Ogcfdi.UUIDTimbre COLLATE Modern_Spanish_CI_AS = cgt.satuid COLLATE Modern_Spanish_CI_AS
                LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.cxp_aux vt 
     ON cgt.Factura COLLATE Modern_Spanish_CI_AS = vt.no_fact COLLATE Modern_Spanish_CI_AS   AND vt.id_tip_doc = 92 AND vt.saldo = 0 and vt.id_cpt=39
                ORDER BY Fecha
            ";

            $records=array();
            $i=0;
            $stmt = $pdo->query($finalQuery);
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $records[$i++] = $row;	//Put row into array
            }
            
            // echo '<pre>';
            // var_dump($records);
            // die();
            
            // 5. Limpia la tabla temporal (opcional, pero recomendado)
           
            return $records;
            
        } catch (PDOException $e) {
            // Manejo de errores
            error_log('Error en la consulta SQL: ' . $e->getMessage());
            return false;
        }
    }
}