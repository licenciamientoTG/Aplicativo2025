<?php
class XmlVsVentasModel extends Model {

    function get_xml_vs_ventas(): array {
        $query = "
            DECLARE @PrimerDiaMesAnterior DATE = DATEADD(MONTH, DATEDIFF(MONTH, 0, GETDATE()) - 1, 0)
            DECLARE @UltimoDiaMesAnterior DATE = DATEADD(DAY, -1, DATEADD(MONTH, DATEDIFF(MONTH, 0, GETDATE()), 0))

            SELECT
                [nombre_estacion],
                CONVERT(VARCHAR(10), [fecha], 23) AS [fecha],
                CASE
                    WHEN [octanaje] = 87 THEN 'T-Maxima'
                    WHEN [octanaje] = 91 THEN 'T-Super'
                    ELSE 'Diesel'
                END AS [tipo_combustible],
                [volumen_total_xml]                                                          AS [volumen_xml],
                [venta_estacion]                                                             AS [volumen_ventas],
                [venta_estacion] - [volumen_total_xml]                                       AS [dif_volumen],
                CASE
                    WHEN [volumen_total_xml] = 0 THEN NULL
                    ELSE ROUND(([venta_estacion] - [volumen_total_xml]) / [volumen_total_xml] * 100, 2)
                END                                                                          AS [dif_porcentaje],
                [archivo_origen]                                                             AS [archivo]
            FROM [TG].[dbo].[cv_ventas_diarias]
            WHERE [fecha] BETWEEN @PrimerDiaMesAnterior AND @UltimoDiaMesAnterior
            ORDER BY [nombre_estacion], [fecha]
        ";
        return $this->sql->select($query) ?: [];
    }
}
