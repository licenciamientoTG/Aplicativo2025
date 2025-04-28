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
                    $queryParts[] = "SELECT t{$key}.*, t{$t2}.*, t{$t3}.nombre AS product, t{$t4}.nombre AS subProduct, t{$t5}.nombre AS subProductBrand, LTRIM(t{$t6}.den) AS controlGasProduct, t{$t7}.rfc FROM     OPENQUERY({$this->linked_server[intval($station)]}, 'SELECT t1.*, (t1.SaldoReal - t1.SaldoFinal) AS Merma, t2.abr COLLATE Latin1_General_CI_AI AS gasStation, t2.nropcc COLLATE Latin1_General_CI_AI AS numeroPermisoCRE FROM {$this->short_databases[intval($station)]}.[vw_ventas_resumen] t1 LEFT JOIN {$this->short_databases[intval($station)]}.Gasolineras t2 ON t1.codgas = t2.cod WHERE t1.fch = {$from}') AS t{$key} LEFT JOIN [devTotalGas].[dbo].[creProductsByStations] t{$t2} ON t{$key}.codgas = t{$t2}.controlGasStationId AND t{$key}.codprd = t{$t2}.controlGasProductId LEFT JOIN [devTotalGas].[dbo].[creProductos] t{$t3} ON t{$t2}.creProductId = t{$t3}.productoId LEFT JOIN [devTotalGas].[dbo].[creSubProductos] t{$t4} ON t{$t2}.creSubProductId = t{$t4}.subProductoId LEFT JOIN [devTotalGas].[dbo].[creTipoSubProductoMarca] t{$t5} ON t{$t2}.creSubProductBrandId = t{$t5}.tipoSubProductoMarcaId LEFT JOIN [SG12].[dbo].[Productos] t{$t6} ON t{$key}.codprd = t{$t6}.cod LEFT JOIN [TG].[dbo].[Estaciones] t{$t7} ON t{$key}.codgas = t{$t7}.Codigo"; // Agregar cada parte de la consulta
                }
            }
        }
        $query = implode(" UNION ", $queryParts); // Unir todas las partes con UNION
        
        if ($_SESSION['tg_user']['Id'] == 6177) {
        echo '<pre>';
        var_dump($query);
        die();
        }
        return $this->sql->select($query) ?: false;
    }
}