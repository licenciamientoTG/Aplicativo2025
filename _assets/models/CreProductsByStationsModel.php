<?php

class CreProductsByStationsModel extends Model{
    public $id;
    public $controlGasStationId;
    public $creProductId;
    public $creSubProductId;
    public $creSubProductBrandId;
    public $createdAt;

    function getRows() : array|false {
        $query = 'SELECT
                    t1.*,
                    t2.Nombre AS gasStationName,
                    t3.nombre AS productName,
                    t4.nombre AS subProductName,
                    t5.nombre AS subProductBrandName
                FROM
                    [devTotalGas].[dbo].[creProductsByStations] t1
                    LEFT JOIN [TG].[dbo].[Estaciones] t2 ON t1.controlGasStationId = t2.Codigo
                    LEFT JOIN [devTotalGas].[dbo].[creProductos] t3 ON t1.creProductId = t3.productoId
                    LEFT JOIN [devTotalGas].[dbo].[creSubProductos] t4 ON t1.creSubProductId = t4.subProductoId
                    LEFT JOIN [devTotalGas].[dbo].[creTipoSubProductoMarca] t5 ON t1.creSubProductBrandId = t5.tipoSubProductoMarcaId
                ;';
        return ($this->sql->select($query)) ?: false ;
    }

    function addRow($controlGasStationId, $creProductId, $creSubProductId, $creSubProductBrandId)  {
        $query = "INSERT INTO [devTotalGas].[dbo].[creProductsByStations] (controlGasStationId, creProductId, creSubProductId, creSubProductBrandId) VALUES (?, ?, ?, ?)";
        return (bool)$this->sql->insert($query, [$controlGasStationId, $creProductId, $creSubProductId, $creSubProductBrandId]);
    }

    function getProductsByStations($codgas, $from) : array | false {
        $queryParts = []; // Arreglo para almacenar cada parte de la consulta
        foreach (explode(",", $codgas) as $key => $station) {
            $ip = trim($this->linked_server[intval($station)], '[]');
            // Vamos a verificar que haya conexion con el servidor de la estacion $this->linked_server[intval($station)]
            if (exec("ping -n 1 $ip", $output, $status)) {
                if ($status == 0) {
                    $t2 = $key + 100;
                    $t3 = $t2 + 100;
                    $t4 = $t3 + 100;
                    $t5 = $t4 + 100;
                    $t6 = $t5 + 100;
                    $t7 = $t6 + 100;
                    $queryParts[] = "
                        SELECT
                            t{$key}.*,
                            t{$t2}.*,
                            t{$t3}.nombre AS product,
                            t{$t4}.nombre AS subProduct,
                            t{$t5}.nombre AS subProductBrand,
                            LTRIM(t{$t6}.den) AS controlGasProduct,
                            t{$t7}.rfc
                        FROM
                            OPENQUERY({$this->linked_server[intval($station)]}, '
                                SELECT
                                    v.*,
                                    (v.SaldoReal - v.SaldoFinal) AS Merma,
                                    g.abr COLLATE Latin1_General_CI_AI AS gasStation,
                                    g.nropcc COLLATE Latin1_General_CI_AI AS numeroPermisoCRE
                                FROM (
                                    SELECT DISTINCT
                                        codprd
                                    FROM {$this->short_databases[intval($station)]}.[vw_ventas_resumen]
                                ) p
                                CROSS APPLY (
                                    SELECT TOP (1)
                                        x.*
                                    FROM {$this->short_databases[intval($station)]}.[vw_ventas_resumen] x
                                    WHERE x.codprd = p.codprd
                                        AND x.fch <= (
                                            SELECT
                                                CASE
                                                    WHEN EXISTS (
                                                        SELECT 1
                                                        FROM {$this->short_databases[intval($station)]}.[vw_ventas_resumen]
                                                        WHERE fch = {$from}
                                                    )
                                                    THEN {$from}
                                                    ELSE (
                                                        SELECT MAX(fch)
                                                        FROM {$this->short_databases[intval($station)]}.[vw_ventas_resumen]
                                                    )
                                                END
                                        )
                                    ORDER BY x.fch DESC
                                ) v
                                LEFT JOIN {$this->short_databases[intval($station)]}.Gasolineras g
                                    ON v.codgas = g.cod
                            ') AS t{$key}
                            LEFT JOIN [devTotalGas].[dbo].[creProductsByStations] t{$t2}
                                ON t{$key}.codgas = t{$t2}.controlGasStationId
                                AND t{$key}.codprd = t{$t2}.controlGasProductId
                            LEFT JOIN [devTotalGas].[dbo].[creProductos] t{$t3}
                                ON t{$t2}.creProductId = t{$t3}.productoId
                            LEFT JOIN [devTotalGas].[dbo].[creSubProductos] t{$t4}
                                ON t{$t2}.creSubProductId = t{$t4}.subProductoId
                            LEFT JOIN [devTotalGas].[dbo].[creTipoSubProductoMarca] t{$t5}
                                ON t{$t2}.creSubProductBrandId = t{$t5}.tipoSubProductoMarcaId
                            LEFT JOIN [SG12].[dbo].[Productos] t{$t6}
                                ON t{$key}.codprd = t{$t6}.cod
                            LEFT JOIN [TG].[dbo].[Estaciones] t{$t7}
                                ON t{$key}.codgas = t{$t7}.Codigo
                    ";
                }
            }
        }
        $query = implode(" UNION ", $queryParts); // Unir todas las partes con UNION
        return $this->sql->select($query) ?: false;
    }
}