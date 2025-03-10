<?php
class XsdEstacionServicioVolumenCompradoModel extends Model{
    public $id;
    public $xsdReportesVolumenesId;
    public $productoId;
    public $subProductoId;
    public $subproductoMarcaId;
    public $tipoCompra;
    public $tipoDocumento;
    public $permisoProveedorCRE;
    public $volumenComprado;
    public $precioCompraSinDescuento;
    public $recibioDescuento;
    public $tipoDescuentoId;
    public $otroTipoDescuento;
    public $precioCompraConDescuento;
    public $pagoServicioFlete;
    public $costoFlete;
    public $permisoTransportistaCRE;
    public $razonSocialImportacion;
    public $puntoInternacionId;
    public $paisOrigenId;
    public $medioEntradaAduanaId;
    public $medioSalidaAduanaId;
    public $createdAt;

    function save($xsdReportesVolumenesId,$xsdEstacionServicioVolumenId,$controlGasStationId,$controlGasProductId,$ProductoId,$SubProductoId,$creSubProductBrandId,$TipoCompra,$TipoDocumento,$PermisoProveedorCRE,$VolumenComprado,$PrecioCompraSinDescuento,$RecibioDescuento,$TipoDescuentoId,$OtroTipoDescuento,$PrecioCompraConDescuento,$PagoServicioFlete,$CostoFlete,$PermisoTransportistaCRE) {
        $query = "INSERT INTO [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado]
                       ([xsdReportesVolumenesId],[xsdEstacionServicioVolumenId],[controlGasStationId],[controlGasProductId],[productoId],[subProductoId],[subproductoMarcaId],[tipoCompra],[tipoDocumento],[permisoProveedorCRE],[volumenComprado],[precioCompraSinDescuento],[recibioDescuento],[tipoDescuentoId],[otroTipoDescuento],[precioCompraConDescuento],[pagoServicioFlete],[costoFlete],[permisoTransportistaCRE])
                 VALUES
                       (?,{$xsdEstacionServicioVolumenId},{$controlGasStationId},{$controlGasProductId},{$ProductoId},{$SubProductoId},{$creSubProductBrandId},{$TipoCompra},{$TipoDocumento},'{$PermisoProveedorCRE}',{$VolumenComprado},{$PrecioCompraSinDescuento},{$RecibioDescuento},{$TipoDescuentoId},'{$OtroTipoDescuento}',{$PrecioCompraConDescuento},{$PagoServicioFlete},{$CostoFlete},'{$PermisoTransportistaCRE}');";
        echo '<pre>';
        var_dump($query);
        die();
        if ($id = $this->sql->insert($query, [$xsdReportesVolumenesId])) {
            return $this->sql->select("SELECT	t1.* FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] t1 WHERE t1.id = ?;", [$id])[0];
        } else {
            return false;
        }
    }

    function save_no_discount($xsdReportesVolumenesId,$xsdEstacionServicioVolumenId,$controlGasStationId, $controlGasProductId,$ProductoId,$SubProductoId,$creSubProductBrandId,$TipoCompra,$TipoDocumento,$PermisoProveedorCRE,$VolumenComprado,$PrecioCompraSinDescuento,$RecibioDescuento,$PagoServicioFlete,$CostoFlete,$PermisoTransportistaCRE) {
        $query = "INSERT INTO [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado]
                       ([xsdReportesVolumenesId],[xsdEstacionServicioVolumenId],[controlGasStationId],[controlGasProductId],[productoId],[subProductoId],[subproductoMarcaId],[tipoCompra],[tipoDocumento],[permisoProveedorCRE],[volumenComprado],[precioCompraSinDescuento],[recibioDescuento],[pagoServicioFlete],[costoFlete],[permisoTransportistaCRE])
                 VALUES
                       (?,{$xsdEstacionServicioVolumenId},{$controlGasStationId},{$controlGasProductId},{$ProductoId},{$SubProductoId},{$creSubProductBrandId},{$TipoCompra},{$TipoDocumento},'{$PermisoProveedorCRE}',{$VolumenComprado},{$PrecioCompraSinDescuento},{$RecibioDescuento},{$PagoServicioFlete},{$CostoFlete},'{$PermisoTransportistaCRE}');";
        if ($id = $this->sql->insert($query, [$xsdReportesVolumenesId])) {
            return $this->sql->select("SELECT	t1.* FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] t1 WHERE t1.id = ?;", [$id])[0];
        } else {
            return false;
        }
    }

    function get_purchases($xsdReportesVolumenesId, $xsdEstacionServicioVolumenId) : array|false {
        $query = 'SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] WHERE xsdReportesVolumenesId = ? AND xsdEstacionServicioVolumenId = ?;';
        $params = [$xsdReportesVolumenesId, $xsdEstacionServicioVolumenId];
        return ($this->sql->select($query,$params)) ?: false ;
    }

    function getPurchaseByProduct($xsdReportesVolumenesId, $xsdEstacionServicioVolumenId, $controlGasProductId) {
        $purchase = "";
        $query = "SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] WHERE xsdReportesVolumenesId = {$xsdReportesVolumenesId} AND xsdEstacionServicioVolumenId = {$xsdEstacionServicioVolumenId} AND controlGasProductId = {$controlGasProductId};";
        $rs = $this->sql->select($query);
        if ($rs) {
            foreach ($rs as $row) {
                $purchase .= '<p class="text-nowrap m-0 p-0"><a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#purchaseDataModal" data-id="'. $row['id'] .'">'. number_format($row['volumenComprado'], 0, '.',',') .' lts ('. $row['permisoProveedorCRE'] .')</a><a href="javascript:void(0);" class="text-danger ml-2" onclick="confirm_delete('. $row['id'] .');"><i data-feather="trash-2"></i></a></p>';
            }
            return $purchase;
        } else {
            return false;
        }
    }

    function getRow($id) : array|false {
        $query = "SELECT
                        t1.*,
                        t2.companyName carrier,
                        t3.companyName supplier
                    FROM
                        [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] t1
                        LEFT JOIN [devTotalGas].[dbo].[creCarriers] t2 ON t1.permisoTransportistaCRE = t2.crePermissionCarrier
                        LEFT JOIN [devTotalGas].[dbo].[creSuppliers] t3 ON t1.permisoProveedorCRE= t3.crePermissionSupplier WHERE t1.id = {$id};";
        return ($rs = $this->sql->select($query, [])) ? $rs[0] : false ;
    }

    function delete($id) : bool {
        $query = "DELETE FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] WHERE id = ?;";
        return (bool)$this->sql->delete($query, [$id]);
    }
}