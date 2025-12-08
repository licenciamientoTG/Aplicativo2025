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

public function get_concentrado_ventas(string $fechaInicio, string $fechaFin): array
{
    try {
        $query = "SET LANGUAGE Spanish;

        DECLARE @FechaInicio DATE = ?;
        DECLARE @FechaFin    DATE = ?; 

        ;WITH cab AS (
            SELECT
                t1.codgas AS CodigoEstacion,
                t3.abr AS Estacion,
                t3.den AS EstacionNombre,
                YEAR(DATEADD(DAY, -1, t1.fch)) AS Anio,
                MONTH(DATEADD(DAY, -1, t1.fch)) AS Mes
            FROM SG12.dbo.DocumentosC AS t1 WITH (NOLOCK)
            LEFT JOIN SG12.dbo.Gasolineras AS t3 WITH (NOLOCK) ON t1.codgas = t3.cod
            WHERE 
                t1.fch BETWEEN (DATEDIFF(DAY, '1900-01-01', @FechaInicio) + 1) 
                           AND (DATEDIFF(DAY, '1900-01-01', @FechaFin) + 1)
                AND t1.tip IN (1,3,4,6)
                -- FILTRO AGREGADO: Solo contar si tiene UUID
                AND t1.satuid IS NOT NULL 
                AND LEN(t1.satuid) > 0
        )
        SELECT
            c.CodigoEstacion,
            c.Estacion,
            c.EstacionNombre,
            c.Anio,
            c.Mes,
            COUNT(*) AS Conteo
        FROM cab AS c
        GROUP BY
            c.CodigoEstacion,
            c.Estacion,
            c.EstacionNombre,
            c.Anio,
            c.Mes
        ORDER BY
            c.CodigoEstacion ASC,
            c.Anio,
            c.Mes;";

        $params = [
            $fechaInicio,
            $fechaFin
        ];

        $result = $this->sql->select($query, $params);
        return $result ?: [];

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
        return [];
    }
}


public function get_detalle_facturas_estacion_mes(string $codgas, string $fechaInicio, string $fechaFin): array
{
    try {
        $query = "SET LANGUAGE Spanish;

        DECLARE @FechaInicio DATE = ?;
        DECLARE @FechaFin    DATE = ?;
        DECLARE @CodGas      INT  = ?;

        ;WITH cab AS (
            SELECT
                t1.tip,
                t1.nro AS NumeroDocumento,
                t1.codgas AS CodigoEstacion,
                t3.abr AS Estacion,
                t3.den AS EstacionNombre,
                CONVERT(varchar(10), DATEADD(DAY, -1, t1.fch), 23) AS Fecha,
                CONVERT(varchar(10), DATEADD(DAY, -1, t1.vto), 23) AS Vencimiento,
                CASE
                    WHEN t1.tip = 6 THEN 'W'
                    WHEN t1.nro BETWEEN 2100000000 AND 2499999999 THEN 'Z'
                    WHEN t1.nro BETWEEN 2000000000 AND 2099999999 THEN 'T'
                    WHEN t1.nro BETWEEN 1900000000 AND 1999999999 THEN 'K'
                    WHEN t1.nro BETWEEN 1100000000 AND 1199999999 THEN 'C'
                    WHEN t1.nro BETWEEN 1200000000 AND 1299999999 THEN 'D'
                    WHEN t1.nro BETWEEN 1700000000 AND 1799999999 THEN 'I'
                    WHEN t1.nro BETWEEN 1300000000 AND 1399999999 THEN 'E'
                    WHEN t1.nro BETWEEN 1500000000 AND 1599999999 THEN 'G'
                    ELSE ''
                END AS Serie,
                CASE
                    WHEN t1.tip = 1 THEN 'Compra'
                    WHEN t1.tip = 3 THEN 'Venta'
                    WHEN t1.tip IN (4,6) THEN 'Nota de Crédito'
                    ELSE 'Tipo ' + CAST(t1.tip AS varchar(10))
                END AS TipoDocumento,
                COALESCE(t5.den, t6.den, 'N/A') AS EntidadNombre,
                -- Aquí ya no necesitamos COALESCE 'N/A' porque filtramos los nulos abajo
                t1.satuid AS UUID
            FROM SG12.dbo.DocumentosC AS t1 WITH (NOLOCK)
            LEFT JOIN SG12.dbo.Gasolineras AS t3 WITH (NOLOCK) ON t1.codgas = t3.cod
            LEFT JOIN SG12.dbo.Proveedores AS t5 WITH (NOLOCK) ON t1.codopr = t5.cod AND t1.tip = 1
            LEFT JOIN SG12.dbo.Clientes AS t6 WITH (NOLOCK) ON t1.codopr = t6.cod AND t1.tip IN (3,4,6)
            WHERE 
                t1.fch BETWEEN (DATEDIFF(DAY, '1900-01-01', @FechaInicio) + 1)
                           AND (DATEDIFF(DAY, '1900-01-01', @FechaFin) + 1)
              AND t1.tip IN (1,3,4,6)
              AND t1.codgas = @CodGas
              -- FILTRO AGREGADO: Solo traer registros que tengan UUID
              AND t1.satuid IS NOT NULL 
              AND LEN(t1.satuid) > 0
        ),
        productos_agrupados AS (
            SELECT
                d.nro,
                d.codgas,
                STRING_AGG(CAST(p.den AS NVARCHAR(MAX)), N', ') 
                    WITHIN GROUP (ORDER BY p.den) AS Producto
            FROM SG12.dbo.Documentos AS d WITH (NOLOCK)
            INNER JOIN cab ON d.nro = cab.NumeroDocumento 
                          AND d.codgas = cab.CodigoEstacion 
                          AND d.tip = cab.tip
            LEFT JOIN SG12.dbo.Productos AS p WITH (NOLOCK) ON d.codprd = p.cod
            WHERE d.nroitm > 0 AND p.den IS NOT NULL
            GROUP BY d.nro, d.codgas
        ),
        det AS (
            SELECT
                d.nro,
                d.codgas,
                ROUND(SUM(d.can) / 100.0, 3) AS Cantidad,
                ROUND(SUM(d.mto) / 100.0, 2) AS Subtotal,
                ROUND(SUM(d.mtoiva) / 100.0, 2) AS IVA,
                ROUND(SUM(d.mtoiie) / 100.0, 2) AS IEPS,
                ROUND(SUM(d.mto + d.mtoiva + d.mtoiie) / 100.0, 2) AS Total
            FROM SG12.dbo.Documentos AS d WITH (NOLOCK)
            INNER JOIN cab ON d.nro = cab.NumeroDocumento 
                          AND d.codgas = cab.CodigoEstacion 
                          AND d.tip = cab.tip
            WHERE d.nroitm > 0
            GROUP BY d.codgas, d.nro
        )
        SELECT
            c.NumeroDocumento,
            c.CodigoEstacion,
            c.Estacion,
            c.EstacionNombre,
            c.Serie,
            c.Fecha,
            c.Vencimiento,
            c.Serie + ' ' + SUBSTRING(CAST(c.NumeroDocumento AS varchar(10)), 4, 10) AS FacturaFormateada,
            COALESCE(p.Producto, 'Sin producto') AS Producto,
            COALESCE(d.Cantidad, 0) AS Cantidad,
            COALESCE(d.Subtotal, 0) AS Subtotal,
            COALESCE(d.IVA, 0) AS IVA,
            COALESCE(d.IEPS, 0) AS IEPS,
            COALESCE(d.Total, 0) AS Total,
            c.TipoDocumento,
            c.EntidadNombre,
            c.UUID
        FROM cab AS c
        LEFT JOIN det AS d ON d.codgas = c.CodigoEstacion AND d.nro = c.NumeroDocumento
        LEFT JOIN productos_agrupados AS p ON p.codgas = c.CodigoEstacion AND p.nro = c.NumeroDocumento
        ORDER BY c.Fecha DESC, c.NumeroDocumento DESC
        OPTION (MAXDOP 4);";

        $params = [
            $fechaInicio,
            $fechaFin,
            $codgas
        ];

        $result = $this->sql->select($query, $params);
        return $result ?: [];

    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage();
        return [];
    }
}



}