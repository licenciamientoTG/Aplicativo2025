<?php
class FacturasModel extends Model {
    public $Id;
    public $Folio;
    public $Serie;
    public $Fecha;
    public $FormaPago;
    public $MetodoPago;
    public $TipoCambio;
    public $Moneda;
    public $SubTotal;
    public $Total;
    public $Exportacion;
    public $TipoDeComprobante;
    public $LugarExpedicion;
    public $Certificado;
    public $NoCertificado;
    public $Sello;
    public $EmisorNombre;
    public $EmisorRfc;
    public $EmisorRegimenFiscal;
    public $ReceptorNombre;
    public $ReceptorRfc;
    public $ReceptorRegimenFiscal;
    public $DomicilioFiscalReceptor;
    public $UsoCFDI;
    public $FechaTimbrado;
    public $RfcProvCertif;
    public $UUID;
    public $NoCertificadoSAT;
    public $TotalImpuestosTrasladados;

    /**
     * Obtiene los primeros 1000 registros de la tabla Facturas.
     * 
     * @return array|false
     */
    public function get_first_1000_facturas(): array|false {
        $query = 'SELECT TOP (1000) * FROM [TGV2].[dbo].[Facturas]';
        $params = [];
        return ($this->sql->select($query, $params)) ?: false;
    }

    /**
     * Busca una factura por su UUID.
     *
     * @param string $uuid
     * @return array|false
     */
    public function get_factura_by_uuid(string $uuid): array|false {
        $query = 'SELECT t1.*,
                            t2.*
                            FROM [TGV2].[dbo].[Facturas] t1
                            LEFT JOIN TGV2.dbo.FacturasConceptos t2 on t1.Id = t2.FacturaId
                            where
                            UUID = ?';
        $params = [$uuid];
        return ($this->sql->select($query, $params)) ?: false;
    }


    public function filter_facturas_by_date_range( $startDate,  $endDate,$rfc): array|false {
        $query = "SELECT * 
                    FROM [TGV2].[dbo].[Facturas]
                    WHERE EmisorRfc = ?
                    AND Fecha BETWEEN CONVERT(datetime, '{$startDate}', 102) AND CONVERT(datetime, '{$endDate}', 102)
                    order by Fecha asc";
        $params = [$rfc];

        return ($this->sql->select($query, $params)) ?: false;
    }

    /**
     * Inserta múltiples facturas en la tabla con una transacción.
     *
     * @param array $facturas Array de facturas a insertar.
     * @return bool
     */
    public function insert_facturas_with_transaction(array $facturas): bool {
        try {
            $this->sql->beginTransaction(); // Inicia la transacción
            foreach ($facturas as $factura) {
                $query = 'INSERT INTO [TGV2].[dbo].[Facturas]
                          ([Folio], [Serie], [Fecha], [FormaPago], [MetodoPago], [TipoCambio], [Moneda], [SubTotal], [Total], 
                           [Exportacion], [TipoDeComprobante], [LugarExpedicion], [Certificado], [NoCertificado], [Sello], 
                           [EmisorNombre], [EmisorRfc], [EmisorRegimenFiscal], [ReceptorNombre], [ReceptorRfc], 
                           [ReceptorRegimenFiscal], [DomicilioFiscalReceptor], [UsoCFDI], [FechaTimbrado], [RfcProvCertif], 
                           [UUID], [NoCertificadoSAT], [TotalImpuestosTrasladados])
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $params = [
                    $factura['Folio'],
                    $factura['Serie'],
                    $factura['Fecha'],
                    $factura['FormaPago'],
                    $factura['MetodoPago'],
                    $factura['TipoCambio'],
                    $factura['Moneda'],
                    $factura['SubTotal'],
                    $factura['Total'],
                    $factura['Exportacion'],
                    $factura['TipoDeComprobante'],
                    $factura['LugarExpedicion'],
                    $factura['Certificado'],
                    $factura['NoCertificado'],
                    $factura['Sello'],
                    $factura['EmisorNombre'],
                    $factura['EmisorRfc'],
                    $factura['EmisorRegimenFiscal'],
                    $factura['ReceptorNombre'],
                    $factura['ReceptorRfc'],
                    $factura['ReceptorRegimenFiscal'],
                    $factura['DomicilioFiscalReceptor'],
                    $factura['UsoCFDI'],
                    $factura['FechaTimbrado'],
                    $factura['RfcProvCertif'],
                    $factura['UUID'],
                    $factura['NoCertificadoSAT'],
                    $factura['TotalImpuestosTrasladados'],
                ];

                if (!$this->sql->insert($query, $params)) {
                    $this->sql->rollBack();
                    return false;
                }
            }
            $this->sql->commit(); // Confirmar la transacción
            return true;
        } catch (Exception $e) {
            $this->sql->rollBack();
            echo "Error: " . $e->getMessage();
            return false;
        }
    }
}