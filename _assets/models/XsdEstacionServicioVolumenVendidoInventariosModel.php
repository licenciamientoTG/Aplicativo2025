<?php
class XsdEstacionServicioVolumenVendidoInventariosModel extends Model{
    public $id;
    public $xsdReportesVolumenesId;
    public $xsdEstacionServicioVolumenId;
    public $controlGasStationId;
    public $controlGasProductId;
    public $productoId;
    public $subProductoId;
    public $subproductoMarcaId;
    public $inventarioInicial;
    public $volumenVendido;
    public $inventarioFinal;
    public $exportaProducto;
    public $createdAt;


    function insertOrUpdateRow($reportId,$xsdEstacionServicioVolumenId,$controlGasStationId,$controlGasProductId,$productoId,$subProductoId,$subproductoMarcaId,$inventarioInicial,$volumenVendido,$inventarioFinal, $merma) : array | false {

        // Vamos a verificar que $controlGasStationId no venga nulo o vacio, si viene nulo o vacio retornamos un false
        if (empty($controlGasStationId)) {
            return false;
        } else {
            if ($row = $this->sql->select("SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] WHERE xsdEstacionServicioVolumenId = ? AND productoId = ? AND subProductoId = ?", [$xsdEstacionServicioVolumenId, $productoId, $subProductoId])) {
                $query = "UPDATE [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] SET inventarioInicial = ?, volumenVendido = ?, inventarioFinal = ? WHERE id = ?;";
                $update = $this->sql->update($query, [$inventarioInicial,$volumenVendido,$inventarioFinal,$row[0]['id']]);
                if ($update) {
                    return ($rs = $this->sql->select("SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] WHERE id = ?", [$row[0]['id']])) ? $rs[0] : false ;
                }
            } else {
                $query = "INSERT INTO [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios](
                         xsdReportesVolumenesId,
                         xsdEstacionServicioVolumenId,
                         controlGasStationId,
                         controlGasProductId,
                         productoId,
                         subProductoId,
                         subproductoMarcaId,
                         inventarioInicial,
                         volumenVendido,
                         inventarioFinal,
                         merma,
                         exportaProducto) VALUES ({$reportId},{$xsdEstacionServicioVolumenId},{$controlGasStationId},{$controlGasProductId},{$productoId},{$subProductoId},{$subproductoMarcaId},{$inventarioInicial},{$volumenVendido},{$inventarioFinal},{$merma},?);";

                $insert = $this->sql->insert($query, [0]);

                if ($insert) {
                    return ($rs = $this->sql->select("SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] WHERE id = ?", [$insert])) ? $rs[0] : false ;
                }
            }
        }
    }

    function get_inventory($stationVolumenId) : array | false {
        $query = "SELECT t1.* FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] t1 WHERE xsdEstacionServicioVolumenId = ?;";
        return ($rs=$this->sql->select($query, [$stationVolumenId])) ? $rs : false ;
    }

    function get_inventory_product($xsdEstacionServicioVolumenId, $creProductId, $creSubProductId) : array | false {
        $query = "SELECT t1.* FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] t1 WHERE xsdEstacionServicioVolumenId = {$xsdEstacionServicioVolumenId} AND productoId = {$creProductId} AND subProductoId = {$creSubProductId};";
        return ($rs=$this->sql->select($query)) ? $rs[0] : false ;
    }

    function update_inventory_product($id, $inventarioInicial, $volumenVendido, $inventarioFinal, $Merma) : array | false {
        $query = "UPDATE [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] SET inventarioInicial = ?, volumenVendido = ?, inventarioFinal = ?, merma = ? WHERE id = ?;";
        $update = $this->sql->update($query, [$inventarioInicial, $volumenVendido, $inventarioFinal, $Merma, $id]);
        if ($update) {
            return ($rs = $this->sql->select("SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] WHERE id = ?", [$id])) ? $rs[0] : false ;
        } else {
            return false;
        }
    }

    function getProductsByStations($codgas, $reportId) : array | false {
        $query = "
        SELECT
            t1.*,
            t2.controlGasStationId,
            t2.creProductId,
            t2.creSubProductId,
            t2.creSubProductBrandId,
            t3.nombre AS product,
            t4.nombre AS subProduct,
            t5.nombre AS subProductBrand,
            t6.nombre gasStation,
            t6.PermisoCRE AS numeroPermisoCRE,
            t6.rfc,
            t1.inventarioInicial SaldoInicial,
            t1.inventarioFinal SaldoFinal,
            t1.merma AS Merma,
            t1.volumenVendido Ventas,
            LTRIM(t7.den) AS controlGasProduct,
            t4.nombre AS subProduct,
            t5.nombre AS subProductBrand,
            t8.TotalVolumenComprado
        FROM
            [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] t1
            LEFT JOIN [devTotalGas].[dbo].[creProductsByStations] t2 ON t1.controlGasStationId = t2.controlGasStationId AND t1.controlGasProductId = t2.controlGasProductId
            LEFT JOIN [devTotalGas].[dbo].[creProductos] t3 ON t2.creProductId = t3.productoId
            LEFT JOIN [devTotalGas].[dbo].[creSubProductos] t4 ON t2.creSubProductId = t4.subProductoId
            LEFT JOIN [devTotalGas].[dbo].[creTipoSubProductoMarca] t5 ON t2.creSubProductBrandId = t5.tipoSubProductoMarcaId
            LEFT JOIN [TG].[dbo].[Estaciones] t6 ON t1.controlGasStationId = t6.Codigo
            LEFT JOIN [SG12].[dbo].[Productos] t7 ON t1.controlGasProductId = t7.cod
            LEFT JOIN (SELECT
                    xsdReportesVolumenesId,
                    xsdEstacionServicioVolumenId,
                    controlGasStationId,
                    controlGasProductId,
                    SUM(volumenComprado) AS TotalVolumenComprado
                FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado]
                WHERE xsdEstacionServicioVolumenId IS NOT NULL
                GROUP BY
                    xsdReportesVolumenesId,
                    xsdEstacionServicioVolumenId,
                    controlGasStationId,
                    controlGasProductId) t8 ON t1.controlGasStationId = t8.controlGasStationId
   AND t1.controlGasProductId = t8.controlGasProductId
   AND t1.xsdReportesVolumenesId = t8.xsdReportesVolumenesId
        WHERE t1.xsdReportesVolumenesId = {$reportId} AND t1.controlGasStationId IN ({$codgas})";

        return ($rs=$this->sql->select($query)) ? $rs : false ;
    }

    function exists($xsdReportesVolumenesId, $controlGasStationId, $controlGasProductId) : bool {
        // Vamos a validar que la variable $controlGasProductId no venga vacia o nula
        // Si es asi, entonces vamos a retornar false
        if (empty($controlGasProductId)) {
            return false;
        } else {
            $query = "SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenVendidoInventarios] WHERE xsdReportesVolumenesId = {$xsdReportesVolumenesId} AND controlGasStationId = {$controlGasStationId} AND controlGasProductId = {$controlGasProductId}";
            return (bool)$this->sql->select($query);
        }
    }
}