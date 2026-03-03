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
                v.[venta_estacion] - v.[volumen_total_xml] AS [dif_volumen],
                CASE
                    WHEN v.[volumen_total_xml] = 0 THEN NULL
                    ELSE ROUND((v.[venta_estacion] - v.[volumen_total_xml]) / v.[volumen_total_xml] * 100, 2)
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
}
