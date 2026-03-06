<?php
class XmlVsVentasModel extends Model {

    function get_xml_vs_ventas(): array {
        $query = "
            DECLARE @PrimerDiaMesAnterior DATE = DATEADD(MONTH, DATEDIFF(MONTH, 0, GETDATE()) - 1, 0)
            DECLARE @UltimoDiaMesAnterior DATE = DATEADD(DAY, -1, DATEADD(MONTH, DATEDIFF(MONTH, 0, GETDATE()), 0))

            SELECT
                v.[nombre_estacion],
                CASE 
                    WHEN e.[grupo] = 'TG' THEN 'DIAZ GAS'
                    ELSE UPPER(e.[grupo])
                END AS [grupo],
                CONVERT(VARCHAR(10), v.[fecha], 23) AS [fecha],
                CASE
                    WHEN v.[octanaje] = 87 THEN 'T-Maxima'
                    WHEN v.[octanaje] = 91 THEN 'T-Super'
                    ELSE 'Diesel'
                END AS [tipo_combustible],
                v.[volumen_total_xml] AS [volumen_xml],
                v.[venta_estacion] AS [volumen_ventas],
                v.[jarreos] AS [jarreos],
                v.[venta_estacion] - (v.[volumen_total_xml] - v.[jarreos]) AS [dif_volumen],
                CASE
                    WHEN (v.[volumen_total_xml] - v.[jarreos]) = 0 THEN NULL
                    ELSE ROUND((v.[venta_estacion] - (v.[volumen_total_xml] - v.[jarreos])) / (v.[volumen_total_xml] - v.[jarreos]) * 100, 2)
                END AS [dif_porcentaje],
                v.[archivo_origen] AS [archivo]
            FROM [TG].[dbo].[cv_ventas_diarias] v
            INNER JOIN [TG].[dbo].[Estaciones] e
                ON v.[codgas] = e.[Codigo]
            WHERE v.[fecha] BETWEEN @PrimerDiaMesAnterior AND @UltimoDiaMesAnterior
            ORDER BY v.[nombre_estacion], v.[fecha]
        ";

        return $this->sql->select($query) ?: [];
    }


    function get_ventas_volumetricas(): array {
        $query = "
            DECLARE @AnioDelMesAnterior SMALLINT = YEAR(DATEADD(MONTH, -1, GETDATE()));
            DECLARE @MesAnterior        TINYINT  = MONTH(DATEADD(MONTH, -1, GETDATE()));

            SELECT
                CASE
                    WHEN e.[grupo] = 'TG' THEN 'DIAZ GAS'
                    ELSE UPPER(e.[grupo])
                END AS [grupo],
                m.codgas,
                m.station,
                m.nombre_estacion,
                m.anio,
                m.mes,
                m.clave_producto,
                m.clave_sub_producto,
                m.octanaje,
                m.volumen_total,
                m.venta_total,
                m.volumen_timbrado_corporativo,
                m.volumen_timbrado_estacion,
                m.dias_con_venta,
                ISNULL(SUM(d.monto_valido),0)            AS monto_valido_mes,
                ISNULL(SUM(d.monto_no_valido),0)         AS monto_no_valido_mes,
                ISNULL(SUM(d.transacciones_validas),0)   AS transacciones_validas_mes,
                ISNULL(SUM(d.transacciones_invalidas),0) AS transacciones_invalidas_mes
            FROM [TG].[dbo].[cv_ventas_mensuales] m
            LEFT JOIN [TG].[dbo].[cv_ventas_diarias] d
                ON  d.codgas = m.codgas
                AND (d.octanaje = m.octanaje OR (d.octanaje IS NULL AND m.octanaje IS NULL))
                AND YEAR(d.fecha)  = @AnioDelMesAnterior
                AND MONTH(d.fecha) = @MesAnterior
            INNER JOIN [TG].[dbo].[Estaciones] e ON m.[codgas] = e.[Codigo]
            WHERE
                m.anio = @AnioDelMesAnterior
                AND m.mes = @MesAnterior
            GROUP BY
                e.grupo,
                m.codgas,
                m.station,
                m.nombre_estacion,
                m.anio,
                m.mes,
                m.clave_producto,
                m.clave_sub_producto,
                m.octanaje,
                m.volumen_total,
                m.venta_total,
                m.volumen_timbrado_corporativo,
                m.volumen_timbrado_estacion,
                m.dias_con_venta
            ORDER BY transacciones_invalidas_mes DESC;
        ";

        return $this->sql->select($query) ?: [];
    }


    function get_xml_vs_ventas_mensuales() {
        $query = "
            DECLARE @AnioDelMesAnterior SMALLINT = YEAR(DATEADD(MONTH, -1, GETDATE()))
            DECLARE @MesAnterior        TINYINT  = MONTH(DATEADD(MONTH, -1, GETDATE()))

            SELECT
                v.[nombre_estacion],
                CASE 
                    WHEN e.[grupo] = 'TG' THEN 'DIAZ GAS'
                    ELSE UPPER(e.[grupo])
                END AS [grupo],
                CASE
                    WHEN v.[octanaje] = 87 THEN 'T-Maxima'
                    WHEN v.[octanaje] = 91 THEN 'T-Super'
                    ELSE 'Diesel'
                END AS [tipo_combustible],
                v.[volumen_total] AS [volumen_xml],
                ISNULL(d.[total_jarreos], 0) AS [jarreos],
                v.[venta_total] AS [volumen_ventas],
                v.[venta_total] - (v.[volumen_total] - ISNULL(d.[total_jarreos], 0)) AS [dif_volumen],
                CASE
                    WHEN (v.[volumen_total] - ISNULL(d.[total_jarreos], 0)) = 0 THEN NULL
                    ELSE ROUND((v.[venta_total] - (v.[volumen_total] - ISNULL(d.[total_jarreos], 0))) / (v.[volumen_total] - ISNULL(d.[total_jarreos], 0)) * 100, 2)
                END AS [dif_porcentaje],
                v.[archivo_origen] AS [archivo]
            FROM [TG].[dbo].[cv_ventas_mensuales] v
            INNER JOIN [TG].[dbo].[Estaciones] e
                ON v.[codgas] = e.[Codigo]
            LEFT JOIN (
                SELECT
                    [codgas],
                    [clave_producto],
                    [clave_sub_producto],
                    [octanaje],
                    SUM([jarreos]) AS [total_jarreos]
                FROM [TG].[dbo].[cv_ventas_diarias]
                WHERE [fecha] >= DATEFROMPARTS(YEAR(DATEADD(MONTH, -1, GETDATE())), MONTH(DATEADD(MONTH, -1, GETDATE())), 1)
                AND [fecha] <  DATEFROMPARTS(YEAR(GETDATE()), MONTH(GETDATE()), 1)
                GROUP BY [codgas], [clave_producto], [clave_sub_producto], [octanaje]
            ) d
                ON  v.[codgas]             = d.[codgas]
                AND (v.[octanaje] = d.[octanaje] OR (v.[octanaje] IS NULL AND d.[octanaje] IS NULL))
            WHERE v.[anio] = @AnioDelMesAnterior AND v.[mes] = @MesAnterior
            ORDER BY v.[nombre_estacion]
        ";

        return $this->sql->select($query) ?: [];
    }
}
