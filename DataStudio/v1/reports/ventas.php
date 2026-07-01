<?php
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
    throw new InvalidArgumentException('Los parámetros desde/hasta deben tener formato YYYY-MM-DD');
}

$query = "
    SELECT
        g.abr AS estacion,
        CONVERT(varchar(10), DATEADD(DAY, v.fch - 1, '19000101'), 23) AS fecha,
        CASE
            WHEN v.codprd IN (1, 181) THEN 'Diesel Automotriz'
            WHEN v.codprd IN (2, 179, 192) THEN 'T-Maxima Regular'
            WHEN v.codprd IN (3, 180, 193) THEN 'T-Super Premium'
        END AS producto,
        SUM(v.canven) AS litros_vendidos
    FROM [SG12].[dbo].[Ventas] v
    INNER JOIN [SG12].[dbo].[Islas] isd ON v.codisl = isd.cod
    INNER JOIN [SG12].[dbo].[Gasolineras] g ON isd.codgas = g.cod
    WHERE
        v.fch BETWEEN (DATEDIFF(dd, 0, ?) + 1) AND (DATEDIFF(dd, 0, ?) + 1)
        AND v.codprd IN (1, 2, 3, 179, 180, 181, 192, 193)
    GROUP BY
        g.abr,
        DATEADD(DAY, v.fch - 1, '19000101'),
        CASE
            WHEN v.codprd IN (1, 181) THEN 'Diesel Automotriz'
            WHEN v.codprd IN (2, 179, 192) THEN 'T-Maxima Regular'
            WHEN v.codprd IN (3, 180, 193) THEN 'T-Super Premium'
        END
    ORDER BY fecha, estacion
";

$rows = MySqlPdoHandler::getInstance()->selectSafe($query, [$desde, $hasta]);

if ($rows === false) {
    throw new RuntimeException('Error al consultar ventas');
}

return [
    'schema' => [
        ['name' => 'estacion', 'dataType' => 'STRING'],
        ['name' => 'fecha', 'dataType' => 'DATE'],
        ['name' => 'producto', 'dataType' => 'STRING'],
        ['name' => 'litros_vendidos', 'dataType' => 'NUMBER'],
    ],
    'rows' => $rows,
];
