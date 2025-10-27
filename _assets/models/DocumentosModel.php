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
                CONVERT(DATE, DATEADD(DAY, -1, t1.fch)) AS 'Fecha',
                CONVERT(DATE, DATEADD(DAY, -1, t1.vto)) AS 'Fecha_vencimiento',
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
                t7.imp_importe_simptos as Subtotal,
                t7.imp_total as Total,
                t7.imp_impto as IvaImporte,
                t8.imp_cant as cantidad,
                t8.imp_ult_cto as precio,
                t8.imp_importe as importe,
                t8.imp_mto_ieps as IEPS,
                t8.imp_des_pro,
                t8.imp_id_otr_sis_pro,
                t9.folio_dr,
                t9.num_parc_dr,
                t9.id_pag_det,
                t13.num_doc as num_factura_OG,
                t10.num_doc as Numero_pago_OG,
                t10.num_doc_cli as Ref_Numerica,
                CONVERT(VARCHAR(10), t10.fecha, 120) AS fecha_pago,
                t10.monto AS monto_pago,
                t9.ImpPag_dr AS monto_pago_fac,
                t11.num as cuenta,
                t12.nom as banco
            FROM SG12.dbo.DocumentosC t1
            LEFT JOIN SG12.dbo.Proveedores t2 ON t1.codopr = t2.cod
            LEFT JOIN SG12.dbo.Documentos t3 ON t1.nro = t3.nro AND t1.codgas = t3.codgas AND t3.nroitm = 1 AND t3.tip = 1
            LEFT JOIN SG12.dbo.Productos t4 ON t3.codprd = t4.cod 
            LEFT JOIN SG12.dbo.Empresas t5 ON t1.codemp = t5.cod
            Left JOIN SG12.dbo.Gasolineras t6 on t1.codgas= t6.cod
            LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.imp_com_doc t7   ON  t1.satuid  COLLATE Modern_Spanish_CI_AS  = t7.imp_uuid  COLLATE Modern_Spanish_CI_AS
            LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.imp_com_part t8   ON t7.imp_id_com  =t8.imp_id_com and t8.imp_id_otr_sis_pro !='0'
            LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.cxp_pag_det_aux_prov t9 on t1.satuid  COLLATE Modern_Spanish_CI_AS  = t9.uuid_dr  COLLATE Modern_Spanish_CI_AS  and t9.uuid !=''and t9.num_parc_dr not in  (2,3,4) and t9.id_pag_det !=0
            LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.cxp_pagos t10 on t9.id_pago = t10.id_pago
            LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.bco_cuentas t11 on t10.id_cta = t11.id_cta
            LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.bco_bancos t12 on t11.id_bco = t12.id_bco
            LEFT JOIN [192.168.0.5].[1G_TOTALGAS].dbo.cxp_doc t13 on t1.satuid  COLLATE Modern_Spanish_CI_AS  = t13.uuid  COLLATE Modern_Spanish_CI_AS 
        WHERE 
            t1.fch BETWEEN $fromInt AND $untilInt
            AND t1.codemp = 1 
            AND t1.tip = 1
            AND t1.codopr != 0
            AND t1.satuid IS NOT NULL
	 $queryProduct
        ";
        // echo '<pre>';
        // var_dump($query);
        // die();
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
                    t4.mto AS [monto],
                    t4.mtoiva as [mtoiva],
                    (t4.mto+t4.mtoiva) AS [monto_original],
                    t4.txtref as [txt_anticipo],
                    t6.mto as [mto_fact_e],
					t6.mtoiva as [mto_iva_e],
					t6.mto  + t6.mtoiva as [mto_total_e]
                    --'t1',
                    --t1.*,
                    --'t3',
                    --t3.*
                    from DocumentosC t1
                    LEFT JOIN Clientes t2 on t1.codopr = t2.cod
                    LEFT JOIN DocumentosA t3 on t1.nro = t3.nro and t3.codgas =0
                    LEFT JOIN (select
                                --( t2.mto + t2.mtoiva)/100 as mto_ori,
                                sum(mto)/100 as mto,
                                sum(mtoiva)/100 as mtoiva,
                                t1.nro,
                                STRING_AGG(CAST(t1.txtref AS NVARCHAR(MAX)), ' | ') AS txtref,
                                t1.satuid
                                from DocumentosC t1
                                LEFT JOIN Documentos t2 on t1.nro= t2.nro and t2.nroitm != (-1) and t2.codgas = 0
                                left join Productos t3 on t2.codprd = t3.cod
                                where
                                t1.codgas = 0
                                and t2.tip  = 3
                                and t2.nroitm >0
                                --t1.nro =1200205972
                                group by t1.nro,t1.satuid
                                ) t4 on t3.nroapl = t4.nro
                   	LEFT JOIN DocumentosC t5 on t3.nroapl = t5.nro and t5.codgas =0
    				LEFT JOIN(select sum(mto)/100 as mto, sum(mtoiva)/100 as mtoiva, nro from Documentos where nroitm > 0  and nrocta = 202050000 and tip = 3 and codgas = 0 group by nro ) t6 on t1.nro = t6.nro
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
                    and t5.flgcon != 141 -- cancelada 
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
                    t4.mto AS 'monto_sub',
                    t4.mto_iva AS 'monto_iva',
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
                        SUM((d.mto) / 100) AS mto,
                        SUM((d.mtoiva) / 100) AS mto_iva,
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

    public function get_purchase_from_station($codgas, $from, $until) : array|false {
        $query = "SELECT * FROM OPENQUERY({$this->linked_server[$codgas]}, 
                    'SELECT
                        t1.nro,
                        CASE 
                            WHEN CHARINDEX(''@F:'', CAST(t1.txtref AS VARCHAR(MAX))) > 0 THEN
                                SUBSTRING(
                                    CAST(t1.txtref AS VARCHAR(MAX)),
                                    CHARINDEX(''@F:'', CAST(t1.txtref AS VARCHAR(MAX))) + 3,
                                    CHARINDEX(''@'', CAST(t1.txtref AS VARCHAR(MAX)) + ''@'', CHARINDEX(''@F:'', CAST(t1.txtref AS VARCHAR(MAX))) + 3)
                                    - (CHARINDEX(''@F:'', CAST(t1.txtref AS VARCHAR(MAX))) + 3)
                                )
                            ELSE NULL
                        END AS Factura,
                        CASE 
                            WHEN CHARINDEX(''@R:'', CAST(t1.txtref AS VARCHAR(MAX))) > 0 THEN
                                SUBSTRING(
                                    CAST(t1.txtref AS VARCHAR(MAX)),
                                    CHARINDEX(''@R:'', CAST(t1.txtref AS VARCHAR(MAX))) + 3,
                                    CHARINDEX(''@'',
                                        CAST(t1.txtref AS VARCHAR(MAX)) + ''@'',
                                        CHARINDEX(''@R:'', CAST(t1.txtref AS VARCHAR(MAX))) + 3
                                    )
                                    - (CHARINDEX(''@R:'', CAST(t1.txtref AS VARCHAR(MAX))) + 3)
                                )
                            ELSE NULL
                        END AS Remision,
                        CONVERT(VARCHAR(10), DATEADD(DAY, -1, t1.fch), 23) AS fecha,
                        CONVERT(VARCHAR(10), DATEADD(DAY, -1, t1.vto), 23) AS fechaVto,
                        t3.den as [producto],
                        t4.den as [proveedor],
                        t8.volrec,
                        t2.can,
                        t2.pre,
                        (t2.mto/100) as [mto],
                        (t2.mtoiie/100) as [mtoiie],
                        (t2.mtoiva/100) as [iva8],
                        (t5.mto/100) as [iva],
                        ((isnull(t2.mtoiva,0) + isnull(t5.mto,0))/100) as [iva_total],
                        t6.mto as [servicio],
                        t7.mto as [iva_servicio],
                        ((t2.mto + (isnull(t2.mtoiva,0) + isnull(t5.mto,0)) + isnull(t6.mto,0)+isnull(t7.mto,0))/100) as [total_fac],
                        t1.satuid,
                        t1.codgas
                        FROM {$this->short_databases[$codgas]}.DocumentosC t1
                        LEFT JOIN {$this->short_databases[$codgas]}.Documentos t2 ON t1.nro =t2.nro and t1.codgas = t2.codgas and t2.codcpt in(1,2,3)
                        LEFT JOIN {$this->short_databases[$codgas]}.Documentos t5 ON t1.nro =t5.nro and t1.codgas = t5.codgas and t5.codcpt in(21,22,23)
                        LEFT JOIN {$this->short_databases[$codgas]}.Documentos t6 ON t1.nro =t6.nro and t1.codgas = t6.codgas and t6.codcpt in(18,19,20)
                        LEFT JOIN {$this->short_databases[$codgas]}.Documentos t7 ON t1.nro =t7.nro and t1.codgas = t7.codgas and t7.codcpt in(24,25,26)
                        LEFT JOIN {$this->short_databases[$codgas]}.Productos t3 ON t2.codprd =t3.cod
                        LEFT JOIN {$this->short_databases[$codgas]}.Proveedores t4 on t1.codopr =t4.cod
                        LEFT JOIN (SELECT sum(volrec) as volrec, nrodoc  FROM {$this->short_databases[$codgas]}.[MovimientosTan] where  tiptrn = 4 group by nrodoc) t8 on t1.nro = t8.nrodoc 
                        WHERE 
                        t1.tip = 1 
                        AND t1.subope = 2 
                        AND t1.fch BETWEEN $from AND $until
                        order by t1.nro asc
                    ')";
        return $this->sql->select($query, []) ?: false;
    }

    function movement_analysis_table($from, $until, $codgas=0,$supplier=0) {

        // Construye la base del WHERE
        $where = "
            WHERE
                t1.fch BETWEEN {$from} AND {$until}
        ";

        // Si se envía una estación específica (distinta de 0 o cadena vacía)
        if (!empty($codgas) && $codgas != 0) {
            $where .= " AND t1.codgas = {$codgas}";
        }

        // Si se envía una estación específica (distinta de 0 o cadena vacía)
        if (!empty($supplier) && $supplier != 0) {
            $where .= " AND t1.codopr = {$supplier}";
        }


        $query = "
        SELECT
            t1.logfch AS LogRegistro,
            t1.codgas,
            t1.nro AS [Número],
            t1.Entidad AS Proveedor,

            -- FACTURA
            LTRIM(RTRIM(
                SUBSTRING(
                    x.txt,
                    CHARINDEX('@F:', x.txt) + 3,
                    CASE
                        WHEN CHARINDEX('@R:', x.txt) > 0 
                            THEN CHARINDEX('@R:', x.txt) - (CHARINDEX('@F:', x.txt) + 3)
                        WHEN CHARINDEX('@V:', x.txt) > 0 
                            THEN CHARINDEX('@V:', x.txt) - (CHARINDEX('@F:', x.txt) + 3)
                        ELSE LEN(x.txt)
                    END
                )
            )) AS Factura,

            t1.nroref AS [Orden de Compra],
            CONVERT(date, DATEADD(DAY, t1.fch, '1899-12-31')) AS Fecha,
            CONVERT(date, DATEADD(DAY, t1.vto, '1899-12-31')) AS Vencimiento,
            TRIM(t2.den) AS [Producto],

            -- Suma priorizada
            t3.VolumenRecibido,
            t3.Recepcion,
            ROUND(t1.can, 3) AS Facturado,
            ROUND(t1.mto / 100.0, 2) AS Importe,
            ROUND(t1.mtoiie / 100.0, 2) AS [I.E.P.S],
            ROUND(t1.mtoiva / 100.0, 2) AS [I.V.A.],
            ROUND(ISNULL(t4.Recargos, 0) / 100.0, 2) AS [Recargos],
            ROUND(ISNULL(t7.iva_concepto, 0), 2) AS iva_concepto,
            ROUND((t1.mto / 100.0) + (t1.mtoiva / 100.0), 2) AS TotalFactura,
            t5.abr AS [Estación],
            t5.den AS [DocDenominacion],
            t5.nropcc,
            t1.satuid AS UUID,
            t1.RFC,

            -- REMISIÓN
            LTRIM(RTRIM(
                SUBSTRING(
                    x.txt,
                    CHARINDEX('@R:', x.txt) + 3,
                    CASE 
                        WHEN CHARINDEX('@V:', x.txt) > 0 
                            THEN CHARINDEX('@V:', x.txt) - (CHARINDEX('@R:', x.txt) + 3)
                        ELSE LEN(x.txt)
                    END
                )
            )) AS Remision,

            -- VEHÍCULO
            LTRIM(RTRIM(
                SUBSTRING(
                    x.txt,
                    CHARINDEX('@V:', x.txt) + 3,
                    LEN(x.txt)
                )
            )) AS Vehiculo,

            t6.den AS [Empresa],
            LTRIM(RTRIM(COALESCE(t6.dom, '') + ' ' + COALESCE(t6.col, ''))) AS [Domicilio],
            COALESCE(t6.codpos, '') + ' - ' + COALESCE(t6.del, '') + ', ' + COALESCE(t6.ciu, '') + ', ' + COALESCE(t6.est, '') AS [Ciudad],
            'R.F.C.: ' + COALESCE(t6.rfc, '') AS [RFC],
            COALESCE(t2.den, '') + ' ' + COALESCE(t5.nropcc, '') AS [Denominación],
            CAST(ISNULL(t1.nro, 0) AS VARCHAR(20)) + ' Compra Combustibles Pesos' AS [NroDocumento],
            CONVERT(VARCHAR(10), DATEADD(DAY, t1.fch, '1899-12-31'), 103) 
            + ', Vencimiento ' 
            + CONVERT(VARCHAR(10), DATEADD(DAY, t1.vto, '1899-12-31'), 103) AS DocFecha,

            -- 🔹 TURNO según hratrn del movimiento prioritario
            CONVERT(VARCHAR(10), DATEADD(DAY, t1.fch, '1899-12-31'), 103) + ', ' +
            CASE 
                WHEN t3.HrSel BETWEEN 0 AND 599 THEN '1 (00:00 a 06:00) [4]'
                WHEN t3.HrSel BETWEEN 600 AND 1399 THEN '2 (06:01 a 14:00) [1]'
                WHEN t3.HrSel BETWEEN 1400 AND 2199 THEN '3 (14:01 a 22:00) [2]'
                WHEN t3.HrSel BETWEEN 2200 AND 2399 THEN '4 (22:01 a 23:59) [3]'
                ELSE 'Sin turno'
            END AS DocTurno,

            -- 🔹 HORA del turno (derivada de HrSel en formato time)
            CASE 
                WHEN t3.HrSel IS NOT NULL THEN
                    CONVERT(time, DATEADD(MINUTE, (t3.HrSel % 100), DATEADD(HOUR, (t3.HrSel / 100), '00:00')))
                ELSE NULL
            END AS HoraTurno,
            t1.Entidad AS Proveedor,
            'Remisión ' + 
        LTRIM(RTRIM(
            SUBSTRING(
                x.txt,
                CHARINDEX('@R:', x.txt) + 3,
                CASE 
                    WHEN CHARINDEX('@V:', x.txt) > 0 
                        THEN CHARINDEX('@V:', x.txt) - (CHARINDEX('@R:', x.txt) + 3)
                    ELSE LEN(x.txt)
                END
            )
        )) + 
        ' Vehículo ' + 
        LTRIM(RTRIM(
            SUBSTRING(
                x.txt,
                CHARINDEX('@V:', x.txt) + 3,
                LEN(x.txt)
            )
        )) AS [RemisionVehiculo]

        FROM [TG].[dbo].[vw_Documentos_Unificados] AS t1
        LEFT JOIN [SG12].[dbo].[Productos] AS t2
            ON t1.codprd = t2.cod

        CROSS APPLY (SELECT CAST(t1.TxtRef AS VARCHAR(MAX)) AS txt) AS x

        -- Subconsulta priorizada: primero 3, si no hay 4; con HrSel (usa MIN, cambia a MAX si quieres)
        LEFT JOIN (
            SELECT 
                s.nrodoc,
                s.codgas,
                s.VolumenRecibido,
                s.HrSel,
                s.nrotrn AS Recepcion
            FROM (
                SELECT
                    MAX(mt.nrotrn) AS nrotrn,
                    mt.nrodoc,
                    mt.codgas,
                    mt.tiptrn,
                    ROUND(SUM(CAST(mt.volrec AS DECIMAL(14,3))), 3) AS VolumenRecibido,
                    MAX(mt.hratrn) AS HrSel,  -- 👈 cambia a MAX(mt.hratrn) si prefieres
                    CASE WHEN mt.tiptrn = 3 THEN 1 ELSE 2 END AS prioridad,
                    MIN(CASE WHEN mt.tiptrn = 3 THEN 1 ELSE 2 END)
                        OVER (PARTITION BY mt.nrodoc, mt.codgas) AS prioridad_grupo
                FROM [SG12].[dbo].[MovimientosTan] AS mt
                WHERE mt.tiptrn IN (3, 4)
                AND mt.nrodoc > 0
                GROUP BY mt.nrodoc, mt.codgas, mt.tiptrn
            ) AS s
            WHERE s.prioridad = s.prioridad_grupo
        ) AS t3
            ON t1.codgas = t3.codgas
        AND t1.nro    = t3.nrodoc

        LEFT JOIN (
            SELECT SUM(mto) AS Recargos, nro, codgas
            FROM [SG12].[dbo].[Documentos]
            WHERE satdat = '@e:7'
            GROUP BY nro, codgas
        ) AS t4
            ON t1.nro    = t4.nro
        AND t1.codgas = t4.codgas

        LEFT JOIN [SG12].[dbo].[Gasolineras] AS t5
            ON t1.codgas = t5.cod

        LEFT JOIN [SG12].[dbo].[Empresas] AS t6
            ON t5.codemp = t6.cod

        LEFT JOIN (
            SELECT SUM(mto/100) AS iva_concepto, nro, codgas FROM [SG12].[dbo].Documentos WHERE codcpt > 0 AND satdat = '@e:4' AND codcpt NOT IN (4) GROUP BY nro, codgas
        ) AS t7 ON t1.nro    = t7.nro AND t1.codgas = t7.codgas

        {$where}

        ORDER BY
            t1.nro ASC;
        ";
                   
        $params = [];
        return $this->sql->select($query, $params);
    }


    function movement_analysis_table2($folios, $codgas) {
        // Construye la base del WHERE
        $where = "
            WHERE
                t1.nro IN ({$folios}) AND t1.codgas = {$codgas}
        ";

        $query = "
        SELECT
            t1.logfch AS LogRegistro,
            t1.codgas,
            t1.nro AS [Número],
            t1.Entidad AS Proveedor,

            -- FACTURA
            LTRIM(RTRIM(
                SUBSTRING(
                    x.txt,
                    CHARINDEX('@F:', x.txt) + 3,
                    CASE
                        WHEN CHARINDEX('@R:', x.txt) > 0 
                            THEN CHARINDEX('@R:', x.txt) - (CHARINDEX('@F:', x.txt) + 3)
                        WHEN CHARINDEX('@V:', x.txt) > 0 
                            THEN CHARINDEX('@V:', x.txt) - (CHARINDEX('@F:', x.txt) + 3)
                        ELSE LEN(x.txt)
                    END
                )
            )) AS Factura,

            t1.nroref AS [Orden de Compra],
            CONVERT(date, DATEADD(DAY, t1.fch, '1899-12-31')) AS Fecha,
            CONVERT(date, DATEADD(DAY, t1.vto, '1899-12-31')) AS Vencimiento,
            TRIM(t2.den) AS [Producto],

            -- Suma priorizada
            t3.VolumenRecibido,
            t3.Recepcion,
            ROUND(t1.can, 3) AS Facturado,
            ROUND(t1.mto / 100.0, 2) AS Importe,
            ROUND(t1.mtoiie / 100.0, 2) AS [I.E.P.S],
            ROUND(t1.mtoiva / 100.0, 2) AS [I.V.A.],
            ROUND(ISNULL(t4.Recargos, 0) / 100.0, 2) AS [Recargos],
            ROUND(ISNULL(t7.iva_concepto, 0), 2) AS iva_concepto,
            ROUND((t1.mto / 100.0) + (t1.mtoiva / 100.0), 2) AS TotalFactura,
            t5.abr AS [Estación],
            t5.den AS [DocDenominacion],
            t5.nropcc,
            t1.satuid AS UUID,
            t1.RFC,

            -- REMISIÓN
            LTRIM(RTRIM(
                SUBSTRING(
                    x.txt,
                    CHARINDEX('@R:', x.txt) + 3,
                    CASE 
                        WHEN CHARINDEX('@V:', x.txt) > 0 
                            THEN CHARINDEX('@V:', x.txt) - (CHARINDEX('@R:', x.txt) + 3)
                        ELSE LEN(x.txt)
                    END
                )
            )) AS Remision,

            -- VEHÍCULO
            LTRIM(RTRIM(
                SUBSTRING(
                    x.txt,
                    CHARINDEX('@V:', x.txt) + 3,
                    LEN(x.txt)
                )
            )) AS Vehiculo,

            t6.den AS [Empresa],
            LTRIM(RTRIM(COALESCE(t6.dom, '') + ' ' + COALESCE(t6.col, ''))) AS [Domicilio],
            COALESCE(t6.codpos, '') + ' - ' + COALESCE(t6.del, '') + ', ' + COALESCE(t6.ciu, '') + ', ' + COALESCE(t6.est, '') AS [Ciudad],
            'R.F.C.: ' + COALESCE(t6.rfc, '') AS [RFC],
            COALESCE(t2.den, '') + ' ' + COALESCE(t5.nropcc, '') AS [Denominación],
            CAST(ISNULL(t1.nro, 0) AS VARCHAR(20)) + ' Compra Combustibles Pesos' AS [NroDocumento],
            CONVERT(VARCHAR(10), DATEADD(DAY, t1.fch, '1899-12-31'), 103) 
            + ', Vencimiento ' 
            + CONVERT(VARCHAR(10), DATEADD(DAY, t1.vto, '1899-12-31'), 103) AS DocFecha,

            -- 🔹 TURNO según hratrn del movimiento prioritario
            CONVERT(VARCHAR(10), DATEADD(DAY, t1.fch, '1899-12-31'), 103) + ', ' +
            CASE 
                WHEN t3.HrSel BETWEEN 0 AND 599 THEN '1 (00:00 a 06:00) [4]'
                WHEN t3.HrSel BETWEEN 600 AND 1399 THEN '2 (06:01 a 14:00) [1]'
                WHEN t3.HrSel BETWEEN 1400 AND 2199 THEN '3 (14:01 a 22:00) [2]'
                WHEN t3.HrSel BETWEEN 2200 AND 2399 THEN '4 (22:01 a 23:59) [3]'
                ELSE 'Sin turno'
            END AS DocTurno,

            -- 🔹 HORA del turno (derivada de HrSel en formato time)
            CASE 
                WHEN t3.HrSel IS NOT NULL THEN
                    CONVERT(time, DATEADD(MINUTE, (t3.HrSel % 100), DATEADD(HOUR, (t3.HrSel / 100), '00:00')))
                ELSE NULL
            END AS HoraTurno,
            t1.Entidad AS Proveedor,
            'Remisión ' + 
        LTRIM(RTRIM(
            SUBSTRING(
                x.txt,
                CHARINDEX('@R:', x.txt) + 3,
                CASE 
                    WHEN CHARINDEX('@V:', x.txt) > 0 
                        THEN CHARINDEX('@V:', x.txt) - (CHARINDEX('@R:', x.txt) + 3)
                    ELSE LEN(x.txt)
                END
            )
        )) + 
        ' Vehículo ' + 
        LTRIM(RTRIM(
            SUBSTRING(
                x.txt,
                CHARINDEX('@V:', x.txt) + 3,
                LEN(x.txt)
            )
        )) AS [RemisionVehiculo]

        FROM [TG].[dbo].[vw_Documentos_Unificados] AS t1
        LEFT JOIN [SG12].[dbo].[Productos] AS t2
            ON t1.codprd = t2.cod

        CROSS APPLY (SELECT CAST(t1.TxtRef AS VARCHAR(MAX)) AS txt) AS x

        -- Subconsulta priorizada: primero 3, si no hay 4; con HrSel (usa MIN, cambia a MAX si quieres)
        LEFT JOIN (
            SELECT 
                s.nrodoc,
                s.codgas,
                s.VolumenRecibido,
                s.HrSel,
                s.nrotrn AS Recepcion
            FROM (
                SELECT
                    MAX(mt.nrotrn) AS nrotrn,
                    mt.nrodoc,
                    mt.codgas,
                    mt.tiptrn,
                    ROUND(SUM(CAST(mt.volrec AS DECIMAL(14,3))), 3) AS VolumenRecibido,
                    MAX(mt.hratrn) AS HrSel,  -- 👈 cambia a MAX(mt.hratrn) si prefieres
                    CASE WHEN mt.tiptrn = 3 THEN 1 ELSE 2 END AS prioridad,
                    MIN(CASE WHEN mt.tiptrn = 3 THEN 1 ELSE 2 END)
                        OVER (PARTITION BY mt.nrodoc, mt.codgas) AS prioridad_grupo
                FROM [SG12].[dbo].[MovimientosTan] AS mt
                WHERE mt.tiptrn IN (3, 4)
                AND mt.nrodoc > 0
                GROUP BY mt.nrodoc, mt.codgas, mt.tiptrn
            ) AS s
            WHERE s.prioridad = s.prioridad_grupo
        ) AS t3
            ON t1.codgas = t3.codgas
        AND t1.nro    = t3.nrodoc

        LEFT JOIN (
            SELECT SUM(mto) AS Recargos, nro, codgas
            FROM [SG12].[dbo].[Documentos]
            WHERE satdat = '@e:7'
            GROUP BY nro, codgas
        ) AS t4
            ON t1.nro    = t4.nro
        AND t1.codgas = t4.codgas

        LEFT JOIN [SG12].[dbo].[Gasolineras] AS t5
            ON t1.codgas = t5.cod

        LEFT JOIN [SG12].[dbo].[Empresas] AS t6
            ON t5.codemp = t6.cod

        LEFT JOIN (
            SELECT SUM(mto/100) AS iva_concepto, nro, codgas FROM [SG12].[dbo].Documentos WHERE codcpt > 0 AND satdat = '@e:4' AND codcpt NOT IN (4) GROUP BY nro, codgas
        ) AS t7 ON t1.nro    = t7.nro AND t1.codgas = t7.codgas

        {$where}

        ORDER BY
            t1.nro ASC;
        ";                   
        $params = [];
        return $this->sql->select($query, $params);
    }

    function get_concepts($codgas, $nro) {
        $query = "
        SELECT
            t2.dencpt AS Concepto,
            COALESCE(t3.den, '') AS Producto,
            NULLIF(t1.can, 0) AS Cantidad,
            NULLIF(t1.pre, 0) AS Precio,
            (t1.mto / 100) AS Monto
        FROM Documentos t1
        LEFT JOIN (SELECT * FROM Efectos WHERE subope = 2) t2 ON t1.codcpt = t2.nrocpt
        LEFT JOIN Productos t3 ON t1.codprd = t3.cod
        WHERE t1.codgas = {$codgas} AND t1.nro = {$nro} AND t1.satdat IN ('@e:7','@e:2','@e:4') AND t1.codcpt NOT IN (4) AND t1.codcpt > 0
        ";

        return ($rs=$this->sql->select($query, [])) ? $rs : false ;
    }

    function get_receptions($codgas, $nro) {
        $query = "
        SELECT t1.nrotrn,
        t2.nrotf1 AS Tanque, CONVERT(date, DATEADD(DAY, t1.fchtrn, '1899-12-31')) AS Fecha, t1.hratrn, t1.volrec AS VolumenRecibido FROM MovimientosTan t1
        LEFT JOIN Tanques t2 ON t1.codtan = t2.cod WHERE t1.nrodoc = {$nro} AND t1.codgas = {$codgas} AND t1.tiptrn = 3
        ";
        
        if ($rs=$this->sql->select($query, [])) {
            return $rs;
        } else {
            $query = "
            SELECT t1.nrotrn,
            t2.nrotf1 AS Tanque, CONVERT(date, DATEADD(DAY, t1.fchtrn, '1899-12-31')) AS Fecha, t1.hratrn, t1.volrec AS VolumenRecibido FROM MovimientosTan t1
            LEFT JOIN Tanques t2 ON t1.codtan = t2.cod WHERE t1.nrodoc = {$nro} AND t1.codgas = {$codgas} AND t1.tiptrn = 4
            ";
            return ($rs=$this->sql->select($query, [])) ? $rs : false ;
        }
    }

    function get_suppliers() {
        $query = "SELECT codopr, Entidad FROM [TG].[dbo].[vw_Documentos_Unificados] GROUP BY Entidad, codopr ORDER BY Entidad;";
        return ($rs=$this->sql->select($query, [])) ? $rs : false ;
    }
}