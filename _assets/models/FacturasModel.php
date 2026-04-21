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
        $query = "SELECT 
                        t1.Id,
                        t1.Folio,
                        t1.Serie,
                        t1.Fecha,
                        t1.FormaPago,
                        t1.MetodoPago,
                        t1.TipoCambio,
                        t1.Moneda,
                        t1.SubTotal,
                        t1.Total,
                        t1.Exportacion,
                        t1.TipoDeComprobante,
                        t1.EmisorNombre,
                        t1.EmisorRfc,
                        t1.EmisorRegimenFiscal,
                        t1.ReceptorNombre,
                        t1.ReceptorRfc,
                        t1.ReceptorRegimenFiscal,
                        t1.DomicilioFiscalReceptor,
                        t1.FechaTimbrado,
                        t1.UUID,
                        t1.TotalImpuestosTrasladados,
                        c.Descripcion,
                        c.TotalLitros
                    FROM [TGV2].[dbo].[Facturas] t1
                    OUTER APPLY (
                        SELECT
                            MAX(CASE WHEN rn = 1 THEN desc_clean END) AS Descripcion,
                            SUM(Cantidad) AS TotalLitros
                        FROM (
                            SELECT
                                ROW_NUMBER() OVER (ORDER BY t2.Id ASC) AS rn,
                                CASE
                                    WHEN CHARINDEX('(', t2.Descripcion) > 0
                                        THEN RTRIM(LEFT(t2.Descripcion, CHARINDEX('(', t2.Descripcion) - 1))
                                    ELSE t2.Descripcion
                                END AS desc_clean,
                                t2.Cantidad
                            FROM TGV2.dbo.FacturasConceptos t2
                            WHERE t2.FacturaId = t1.Id
                        ) x
                    ) c
                    WHERE t1.EmisorRfc = ?
                    AND t1.Fecha BETWEEN CONVERT(datetime, '{$startDate}', 102) AND CONVERT(datetime, '{$endDate}', 102)
                    order by t1.Fecha asc";
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

// 1. Obtener lista de RFCs para los TAGS (Solo los que tienen > 1 estación)
    public function get_rfcs_disponibles(): array
    {
        try {
            $query = "
                SELECT 
                    t3.rfc,
                    COUNT(t3.cod) as CantidadEstaciones
                FROM SG12.dbo.Gasolineras AS t3 WITH (NOLOCK)
                WHERE t3.rfc IS NOT NULL 
                  AND LEN(t3.rfc) > 4 
                  -- Opcional: Solo estaciones activas si tienes campo 'act'
                  -- AND t3.act = 1 
                GROUP BY t3.rfc
                HAVING COUNT(t3.cod) > 1 -- REQUERIMIENTO: Más de 1 estación
                ORDER BY t3.rfc ASC
            ";
            return $this->sql->select($query) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

   public function get_empresas_tags(): array
    {
        try {
            $query = "
                SELECT 
                    e.cod AS CodigoEmpresa,
                    e.den AS RazonSocial,
                    e.rfc AS RFC,
                    COUNT(g.cod) as CantidadEstaciones
                FROM SG12.dbo.Gasolineras AS g WITH (NOLOCK)
                INNER JOIN SG12.dbo.Empresas AS e WITH (NOLOCK) ON g.codemp = e.cod
                WHERE e.den IS NOT NULL 
                  AND LEN(e.den) > 0
                GROUP BY e.cod, e.den, e.rfc
                HAVING COUNT(g.cod) > 1 -- Regla: Más de 1 estación
                ORDER BY e.den ASC
            ";
            return $this->sql->select($query) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
// 2. Concentrado de Ventas
    public function get_concentrado_ventas(string $fechaInicio, string $fechaFin, ?int $codEmp = null): array
    {
        try {
            $query = "SET LANGUAGE Spanish;
            DECLARE @FechaInicio DATE = ?;
            DECLARE @FechaFin    DATE = ?; 
            DECLARE @CodEmp      INT  = ?;

            -- Pre-calculamos los enteros de fecha para no hacerlo fila por fila si fch es int
            DECLARE @FchIniInt INT = DATEDIFF(DAY, '1900-01-01', @FechaInicio) + 1;
            DECLARE @FchFinInt INT = DATEDIFF(DAY, '1900-01-01', @FechaFin) + 1;

            ;WITH cab AS (
                SELECT
                    t1.codgas AS CodigoEstacion,
                    t3.abr AS Estacion,
                    t3.den AS EstacionNombre,
                    e.den AS EmpresaNombre,
                    YEAR(DATEADD(DAY, -1, t1.fch)) AS Anio,
                    MONTH(DATEADD(DAY, -1, t1.fch)) AS Mes
                FROM SG12.dbo.DocumentosC AS t1 WITH (NOLOCK)
                LEFT JOIN SG12.dbo.Gasolineras AS t3 WITH (NOLOCK) ON t1.codgas = t3.cod
                LEFT JOIN SG12.dbo.Empresas    AS e  WITH (NOLOCK) ON t3.codemp = e.cod
                WHERE 
                    t1.fch BETWEEN @FchIniInt AND @FchFinInt
                    AND t1.tip IN (1,3,4,6)
                    AND t1.satuid IS NOT NULL AND LEN(t1.satuid) > 0
                    
                    -- FILTRO EMPRESA
                    AND (@CodEmp IS NULL OR @CodEmp = 0 OR t3.codemp = @CodEmp)
            )
            SELECT
                c.CodigoEstacion,
                c.Estacion,
                c.EstacionNombre,
                c.EmpresaNombre,
                c.Anio,
                c.Mes,
                COUNT(*) AS Conteo
            FROM cab AS c
            GROUP BY c.CodigoEstacion, c.Estacion, c.EstacionNombre, c.EmpresaNombre, c.Anio, c.Mes
            ORDER BY c.CodigoEstacion ASC, c.Anio, c.Mes
            OPTION (RECOMPILE); -- <--- ESTO ES CLAVE PARA EL RENDIMIENTO";

            $params = [$fechaInicio, $fechaFin, $codEmp];
            return $this->sql->select($query, $params) ?: [];
        } catch (Exception $e) { return []; }
    }

public function get_detalle_facturas_estacion_mes(string $codgas, string $fechaInicio, string $fechaFin, ?int $codEmp = null): array
{
    try {
        $query = "SET LANGUAGE Spanish;
        DECLARE @FechaInicio DATE = ?;
        DECLARE @FechaFin    DATE = ?;
        DECLARE @CodGas      INT  = ?;
        DECLARE @CodEmp      INT  = ?;

        DECLARE @FchIniInt INT = DATEDIFF(DAY, '1900-01-01', @FechaInicio) + 1;
        DECLARE @FchFinInt INT = DATEDIFF(DAY, '1900-01-01', @FechaFin) + 1;

        ;WITH cab AS (
            SELECT
                t1.tip, t1.nro AS NumeroDocumento, t1.codgas AS CodigoEstacion, 
                t3.abr AS Estacion, t3.den AS EstacionNombre, 
                e.den AS EmpresaNombre,
                CONVERT(varchar(10), DATEADD(DAY, -1, t1.fch), 23) AS Fecha,
                CONVERT(varchar(10), DATEADD(DAY, -1, t1.vto), 23) AS Vencimiento,
                CASE 
                    WHEN t1.tip = 6 THEN 'W' 
                    WHEN t1.nro BETWEEN 2100000000 AND 2499999999 THEN 'Z' 
                    WHEN t1.nro BETWEEN 2000000000 AND 2099999999 THEN 'T'
                    ELSE '' 
                END AS Serie, 
                CASE 
                    WHEN t1.tip = 1 THEN 'Compra' 
                    WHEN t1.tip = 3 THEN 'Venta'
                    WHEN t1.tip IN (4,6) THEN 'Nota de Crédito'
                    ELSE 'Otro'
                END AS TipoDocumento,
                COALESCE(t5.den, t6.den, 'N/A') AS EntidadNombre,
                t1.satuid AS UUID
            FROM SG12.dbo.DocumentosC AS t1 WITH (NOLOCK)
            LEFT JOIN SG12.dbo.Gasolineras AS t3 WITH (NOLOCK) ON t1.codgas = t3.cod
            LEFT JOIN SG12.dbo.Empresas    AS e  WITH (NOLOCK) ON t3.codemp = e.cod
            LEFT JOIN SG12.dbo.Proveedores AS t5 WITH (NOLOCK) ON t1.codopr = t5.cod AND t1.tip = 1
            LEFT JOIN SG12.dbo.Clientes    AS t6 WITH (NOLOCK) ON t1.codopr = t6.cod AND t1.tip IN (3,4,6)
            WHERE 
                t1.fch BETWEEN @FchIniInt AND @FchFinInt
                AND t1.tip IN (1,3,4,6)
                AND t1.satuid IS NOT NULL AND LEN(t1.satuid) > 0
                AND (@CodGas = -1 OR t1.codgas = @CodGas)
                AND (@CodEmp IS NULL OR @CodEmp = 0 OR t3.codemp = @CodEmp)
        ),
        productos_agrupados AS ( 
            SELECT d.nro, d.codgas, STRING_AGG(CAST(p.den AS NVARCHAR(MAX)), N', ') WITHIN GROUP (ORDER BY p.den) AS Producto 
            FROM SG12.dbo.Documentos d 
            INNER JOIN cab ON d.nro=cab.NumeroDocumento AND d.codgas=cab.CodigoEstacion AND d.tip=cab.tip 
            LEFT JOIN SG12.dbo.Productos p ON d.codprd=p.cod WHERE d.nroitm>0 GROUP BY d.nro, d.codgas 
        ),
        det AS ( 
            SELECT d.nro, d.codgas, 
                -- 1. CANTIDAD: Si es Venta (tip=3) aplicamos ABS, sino valor original
                ROUND(SUM(
                    CASE WHEN cab.tip = 3 THEN ABS(d.can) ELSE d.can END
                ), 3) AS Cantidad, 
                
                -- 2. SUBTOTAL: Aplicamos ABS al 'd.mto' si es venta
                ROUND(SUM(
                    CASE 
                        WHEN d.codcpt NOT IN (2, 21, 22, 23) THEN 
                             CASE WHEN cab.tip = 3 THEN ABS(d.mto) ELSE d.mto END
                        ELSE 0 
                    END
                ) / 100.0, 2) AS Subtotal, 

                -- 3. IVA: Aplicamos ABS tanto al 'mtoiva' como al 'mto' de los conceptos especiales
                ROUND((
                    SUM(CASE WHEN cab.tip = 3 THEN ABS(d.mtoiva) ELSE d.mtoiva END) + 
                    SUM(
                        CASE WHEN d.codcpt IN (2, 21, 22, 23) THEN 
                             CASE WHEN cab.tip = 3 THEN ABS(d.mto) ELSE d.mto END
                        ELSE 0 END
                    )
                ) / 100.0, 2) AS IVA, 

                -- 4. IEPS: ABS si es venta
                ROUND(SUM(
                    CASE WHEN cab.tip = 3 THEN ABS(d.mtoiie) ELSE d.mtoiie END
                )/100.0, 2) AS IEPS, 
                
                -- 5. TOTAL: Sumatoria de los componentes ya convertidos
                ROUND(SUM(
                    (CASE WHEN cab.tip = 3 THEN ABS(d.mto) ELSE d.mto END) + 
                    (CASE WHEN cab.tip = 3 THEN ABS(d.mtoiva) ELSE d.mtoiva END) + 
                    (CASE WHEN cab.tip = 3 THEN ABS(d.mtoiie) ELSE d.mtoiie END)
                )/100.0, 2) AS Total 

            FROM SG12.dbo.Documentos d 
            INNER JOIN cab ON d.nro=cab.NumeroDocumento AND d.codgas=cab.CodigoEstacion AND d.tip=cab.tip 
            WHERE d.nroitm>0 
            -- Agregamos cab.tip al Group By para poder usarlo en el CASE sin problemas
            GROUP BY d.codgas, d.nro, cab.tip 
        )

        SELECT 
            c.*, 
            c.Serie + ' ' + SUBSTRING(CAST(c.NumeroDocumento AS varchar(10)), 4, 10) AS FacturaFormateada,
            COALESCE(p.Producto, 'Sin producto') AS Producto, 
            COALESCE(d.Cantidad, 0) AS Cantidad,
            COALESCE(d.Subtotal, 0) AS Subtotal,
            COALESCE(d.IVA, 0) AS IVA,
            COALESCE(d.IEPS, 0) AS IEPS,
            COALESCE(d.Total, 0) AS Total
        FROM cab c
        LEFT JOIN det d ON d.codgas = c.CodigoEstacion AND d.nro = c.NumeroDocumento
        LEFT JOIN productos_agrupados p ON p.codgas = c.CodigoEstacion AND p.nro = c.NumeroDocumento
        ORDER BY c.Fecha DESC, c.NumeroDocumento DESC
        OPTION (RECOMPILE);";

        $params = [$fechaInicio, $fechaFin, $codgas, $codEmp];
        return $this->sql->select($query, $params) ?: [];
    } catch (\Exception $e) { return []; }
}

}