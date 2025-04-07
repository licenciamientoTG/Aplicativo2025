<?php

use PhpOffice\PhpSpreadsheet\Calculation\Logical\Boolean;

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
        if ($id = $this->sql->insert($query, [$xsdReportesVolumenesId])) {
            return $this->sql->select("SELECT	t1.* FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] t1 WHERE t1.id = ?;", [$id])[0];
        } else {
            return false;
        }
    }

    function upsert_volume_purchase($id, $reportVolumeId, $stationVolumeId, $stationId, $productId, $mainProductId, $subProductId, $brandId, $purchaseType, $documentType, $supplierPermit, $priceWithoutDiscount, $receivedDiscount, $paidFreight, $freightCost, $carrierPermit) {

        // First check if the record exists
        $existing = $this->sql->select("SELECT id FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] WHERE id = ?", [$id]);

        if ($existing) {
            // Record exists – perform update
            $query = "UPDATE [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado]
                        SET
                            [xsdReportesVolumenesId] = ?,
                            [xsdEstacionServicioVolumenId] = ?,
                            [controlGasStationId] = ?,
                            [controlGasProductId] = ?,
                            [productoId] = ?,
                            [subProductoId] = ?,
                            [subproductoMarcaId] = ?,
                            [tipoCompra] = ?,
                            [tipoDocumento] = ?,
                            [permisoProveedorCRE] = ?,
                            [precioCompraSinDescuento] = ?,
                            [recibioDescuento] = ?,
                            [pagoServicioFlete] = ?,
                            [costoFlete] = ?,
                            [permisoTransportistaCRE] = ?
                      WHERE id = ?";

            $params = [
                $reportVolumeId,
                $stationVolumeId,
                $stationId,
                $productId,
                $mainProductId,
                $subProductId,
                $brandId,
                $purchaseType,
                $documentType,
                $supplierPermit,
                $priceWithoutDiscount,
                $receivedDiscount,
                $paidFreight,
                $freightCost,
                $carrierPermit,
                $id
            ];

            if ($this->sql->update($query, $params)) {
                return $this->sql->select("SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] WHERE id = ?", [$id])[0];
            } else {
                return false;
            }

        } else {
            // Record does not exist – perform insert
            $query = "INSERT INTO [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado]
                           ([xsdReportesVolumenesId],[xsdEstacionServicioVolumenId],[controlGasStationId],[controlGasProductId],[productoId],[subProductoId],[subproductoMarcaId],[tipoCompra],[tipoDocumento],[permisoProveedorCRE],[precioCompraSinDescuento],[recibioDescuento],[pagoServicioFlete],[costoFlete],[permisoTransportistaCRE])
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

            $params = [
                $reportVolumeId,
                $stationVolumeId,
                $stationId,
                $productId,
                $mainProductId,
                $subProductId,
                $brandId,
                $purchaseType,
                $documentType,
                $supplierPermit,
                $priceWithoutDiscount,
                $receivedDiscount,
                $paidFreight,
                $freightCost,
                $carrierPermit
            ];

            if ($id = $this->sql->insert($query, $params)) {
                return $this->sql->select("SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] WHERE id = ?", [$id])[0];
            } else {
                return false;
            }
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
            // Con este código se agrega la función de eliminar compra
            // foreach ($rs as $row) {
            //     $purchase .= '<p class="text-nowrap m-0 p-0"><a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#purchaseDataModal" data-id="'. $row['id'] .'">'. number_format($row['volumenComprado'], 0, '.',',') .' lts ('. $row['permisoProveedorCRE'] .')</a>
            //     <a href="javascript:void(0);" class="text-danger ml-2" onclick="confirm_delete('. $row['id'] .');"><i data-feather="trash-2"></i></a>
            //     </p>';
            // }

            // Con esta función eliminas la opcion de eliminar compra
            foreach ($rs as $row) {
                $purchase .= '<p class="text-nowrap m-0 p-0"><a href="javascript:void(0);" data-bs-toggle="modal" data-bs-target="#addPurchaseModal" data-rowid="'. $row['id'] .'" data-codgas="'. $row['controlGasStationId'] .'" data-controlGasProductId="'. $row['controlGasProductId'] .'" data-creProductId="'. $row['productoId'] .'" data-creSubProductId="'. $row['subProductoId'] .'" data-creSubProductBrandId="'. $row['subproductoMarcaId'] .'">'. number_format($row['volumenComprado'], 0, '.',',') .' lts ('. $row['permisoProveedorCRE'] .')</a></p>';
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

    function insertOrUpdateVolumenComprado($reportId, $estacionServicioVolumenId, $codgas, $codprd, $VolumenRecibido, $controlGasNrotrn) {
        // Obtener el producto relacionado
        $product = $this->sql->select("SELECT * FROM [devTotalGas].[dbo].[creProductsByStations] WHERE [controlGasStationId] = ? AND [controlGasProductId] = ?;", [$codgas, $codprd]);

        // Verificar si ya existe el registro
        $existing = $this->sql->select("
            SELECT id FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado]
            WHERE controlGasNrotrn = ? AND controlGasStationId = ?;
        ", [$controlGasNrotrn, $codgas]);

        // Si ya existe, actualizamos
        if (!empty($existing)) {
            $query = "
                UPDATE [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado]
                SET
                    controlGasProductId = ?,
                    productoId = ?,
                    subProductoId = ?,
                    subproductoMarcaId = ?,
                    tipoCompra = 0,
                    volumenComprado = ?,
                    recibioDescuento = 0,
                    pagoServicioFlete = 0,
                    permisoTransportistaCRE = '-------PENDIENTE-------'
                WHERE
                    controlGasNrotrn = ? AND controlGasStationId = ?;
            ";

            $params = [
                $codprd,
                $product[0]['creProductId'],
                $product[0]['creSubProductId'],
                $product[0]['creSubProductBrandId'],
                round($VolumenRecibido),
                $controlGasNrotrn,
                $codgas
            ];

            return $this->sql->update($query, $params);
        }

        // Si no existe, insertamos
        $query = "
            INSERT INTO [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] (
                [xsdReportesVolumenesId],
                [xsdEstacionServicioVolumenId],
                controlGasStationId,
                controlGasProductId,
                productoId,
                subProductoId,
                subproductoMarcaId,
                tipoCompra,
                volumenComprado,
                recibioDescuento,
                pagoServicioFlete,
                permisoTransportistaCRE,
                controlGasNrotrn
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 0, 0, '-------PENDIENTE-------', ?);
        ";

        $params = [
            $reportId,
            $estacionServicioVolumenId,
            $codgas,
            $codprd,
            $product[0]['creProductId'],
            $product[0]['creSubProductId'],
            $product[0]['creSubProductBrandId'],
            round($VolumenRecibido),
            $controlGasNrotrn
        ];

        return $this->sql->insert($query, $params);
    }

    function get_purchase($id) : array|false {
        $existing = $this->sql->select("SELECT id FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] WHERE id = ?;", [$id]);
        if (!empty($existing)) {
            return $this->sql->select("SELECT * FROM [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado] WHERE id = ?;", [$id])[0];
        } else {
            return false;
        }
    }

    function update_volumen_comprado($cabeceraId,$stationId,$controlGasStationId,$controlGasProductId,$ProductoId,$SubProductoId,$creSubProductBrandId,$TipoCompra,$TipoDocumento,$PermisoProveedorCRE,$PrecioCompraSinDescuento,$RecibioDescuento,$PagoServicioFlete,$CostoFlete,$PermisoTransportistaCRE,$id) {
        $query = "
        UPDATE [devTotalGas].[dbo].[xsdEstacionServicioVolumenComprado]
        SET
            xsdReportesVolumenesId       = ?,
            xsdEstacionServicioVolumenId = ?,
            controlGasStationId          = ?,
            controlGasProductId          = ?,
            productoId                   = ?,
            subProductoId                = ?,
            subproductoMarcaId           = ?,
            tipoCompra                   = ?,
            tipoDocumento                = ?,
            permisoProveedorCRE          = ?,
            precioCompraSinDescuento     = ?,
            recibioDescuento             = ?,
            pagoServicioFlete            = ?,
            costoFlete                   = ?,
            permisoTransportistaCRE      = ?
        WHERE
            id = ?;
        ";
        $params = [
            $cabeceraId,
            $stationId,
            $controlGasStationId,
            $controlGasProductId,
            $ProductoId,
            $SubProductoId,
            $creSubProductBrandId,
            $TipoCompra,
            $TipoDocumento,
            $PermisoProveedorCRE,
            $PrecioCompraSinDescuento,
            $RecibioDescuento,
            $PagoServicioFlete,
            $CostoFlete,
            $PermisoTransportistaCRE,
            $id
        ];
        return $this->sql->update($query, $params);
    }
}