<?php

class FacturasRecibidasModel extends Model {
    
    /**
     * Buscar facturas por UUIDs
     */
public function buscarPorUUIDs($uuids) {
        if (empty($uuids)) {
            return []; // Return empty array instead of false for consistency
        }
        
        // 1. LIMPIEZA Y NORMALIZACIÓN DE UUIDS
        // Esto soluciona el problema de que el Excel traiga guiones bajos (_)
        // y la BD tenga guiones medios (-).
        $cleanUuids = [];
        foreach ($uuids as $uuid) {
            $u = trim($uuid);
            $u = str_replace(['_', ' '], ['-', ''], $u); // Reemplazar _ por - y quitar espacios
            // $u = strtoupper($u); // Descomentar si tu BD es estricta con mayúsculas
            if (!empty($u)) {
                $cleanUuids[] = $u;
            }
        }
        
        if (empty($cleanUuids)) {
            return [];
        }
        
        
        // Crear placeholders para la consulta IN (?,?,?)
        $placeholders = implode(',', array_fill(0, count($cleanUuids), '?'));
        
        $query = "SELECT 
                    Id, 
                    Folio, 
                    UUID, 
                    RutaArchivo, 
                    NombreArchivo,
                    EmisorNombre,
                    ReceptorNombre,
                    Total,
                    Fecha
                  FROM [TG].[dbo].[FacturasRecibidas]
                  WHERE UUID IN ($placeholders)
                  AND RutaArchivo IS NOT NULL
                  AND RutaArchivo != ''";

        // F791050F-555D-4B82-82DF-518E222E9271
                  
        // Pasamos el array limpio
        return $this->sql->select($query, $cleanUuids) ?: [];
    }
    
    /**
     * Obtener factura por ID
     */
    public function obtenerPorId($id) {
        $query = "SELECT 
                    Id, 
                    Folio, 
                    UUID, 
                    RutaArchivo, 
                    NombreArchivo,
                    EmisorNombre,
                    ReceptorNombre,
                    Total
                  FROM [TG].[dbo].[FacturasRecibidas]
                  WHERE Id = ?";
        
        $result = $this->sql->select($query, [$id]);
        return $result ? $result[0] : false;
    }

    public function obtener_facturas_asignadas() {
            $query = "
                SELECT 
                    fmt.nrotrn,
                    fmt.codgas,
                    fmt.UUID,
                    fmt.FacturaId,
                    fr.Folio,
                    fr.Total,
                    fr.EmisorNombre,
                    fr.Fecha
                FROM tg.dbo.FacturasMovimientosTanques fmt
                INNER JOIN tg.dbo.FacturasRecibidas fr ON fmt.FacturaId = fr.Id
                WHERE fmt.Activo = 1
            ";
            
            return ($this->sql->select($query, [])) ?: [];
    }

    public function buscar_facturas_disponibles($searchTerm = '', $fechaInicio = '', $fechaFin = '') {
        $whereClauses = [];
        $params = [];
        
        // 1. SEGURIDAD: Usar parámetros (?) en lugar de meter variables directas
        if (!empty($searchTerm)) {
            // SQL Server usa '+' para concatenar strings en LIKE, o pasamos los % en el parámetro
            $whereClauses[] = "(fr.UUID LIKE ? OR fr.Folio LIKE ? OR fr.EmisorNombre LIKE ?)";
            $likeTerm = "%" . $searchTerm . "%";
            $params[] = $likeTerm;
            $params[] = $likeTerm;
            $params[] = $likeTerm;
        }
        
        if (!empty($fechaInicio) && !empty($fechaFin)) {
            $whereClauses[] = "fr.Fecha BETWEEN ? AND ?";
            $params[] = $fechaInicio;
            $params[] = $fechaFin;
        }
        
        $whereSQL = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $query = "
            SELECT TOP 50
                fr.Id,
                fr.UUID,
                fr.Folio,
                fr.Fecha,
                fr.Total,
                fr.EmisorNombre,
                fr.EmisorRfc,
                fr.Destino,
                fr.Remision,
                CASE 
                    WHEN fmt.Id IS NOT NULL THEN 1 
                    ELSE 0 
                END AS YaAsignada
            FROM tg.dbo.FacturasRecibidas fr
            LEFT JOIN tg.dbo.FacturasMovimientosTanques fmt ON fr.Id = fmt.FacturaId AND fmt.Activo = 1
            $whereSQL
            ORDER BY fr.Fecha DESC
        ";
        
        return ($this->sql->select($query, $params)) ?: [];
    }

    public function compras_facturas_table($from,$until,$codgas,$proveedor,$company) {
        $from = date('Y-m-d', strtotime($from)) . ' 00:00:01';
        $until = date('Y-m-d', strtotime($until)) . ' 23:59:59';

        $whereClauses = ["t1.Fecha BETWEEN ? AND ?"];
        
        // Filtro de proveedor
        if ($proveedor != '0') {
            $whereClauses[] = "t1.EmisorNombre LIKE '%$proveedor%'";
        }
        
        // Filtro de estación
        if ($codgas != '0') {
            $whereClauses[] = "(t2.codgas = $codgas OR t1.Destino LIKE '%$codgas%')";
        }
        
        // Filtro de empresa
        if ($company == '1') {
            $whereClauses[] = "t1.ReceptorRfc NOT LIKE 'PET%'";
        } elseif ($company == '2') {
            $whereClauses[] = "t1.ReceptorRfc LIKE 'PET%'";
        }
        
        $whereClause = "WHERE " . implode(" AND ", $whereClauses);

        $query = "SELECT 
                    t1.Id as FacturaId,
                    t1.Folio as NumeroFacturaProveedorOriginal,
                    CONVERT(VARCHAR(10), t1.Fecha, 23) as FechaRecepcion,
                    t1.UUID,
                    t4.satuid,
                    t1.RutaArchivo,
                    t1.NombreArchivo,
                    -- EMISOR (PROVEEDOR ORIGINAL)
                    t1.EmisorNombre as ProveedorOriginal,
                    t1.EmisorRfc as RfcProveedorOriginal,
                    -- RECEPTOR
                    t1.ReceptorNombre as Receptor,
                    t1.ReceptorRfc,
                    -- DETERMINAR EMPRESA
                    CASE 
                        WHEN t1.ReceptorRfc LIKE 'PET%' THEN 'Petrotal'
                        ELSE 'TotalGas'
                    END as Empresa,
                    -- MONTOS
                    t1.SubTotal,
                    t1.Total as MontoFactura,
                    t1.TotalImpuestosTrasladados as IVATotal,
                    -- OTROS DATOS
                    t1.FormaPago,
                    t1.MetodoPago,
                    t1.Moneda,
                    t1.Destino,
                    t1.Remision,
                    t1.LugarExpedicion,
                    t1.TipoDeComprobante,
                    -- RELACIÓN CON MOVIMIENTOS (si existe)
                    t2.nrotrn as NumeroTransaccion,
                    t2.codgas as CodigoEstacion,
                    t2.TipoOperacion,
                    t2.Observaciones as ObservacionesAsignacion,
                    -- FACTURA PETROTAL (si es operación tipo 2)
                    t3.Folio as NumeroFacturaPetrotal,
                    t3.Total as MontoFacturaPetrotal,
                    t2.LitrosPetrotal,
                    t2.PrecioPetrotal,
                    -- ESTADO
                    CASE 
                        WHEN t2.Id IS NOT NULL THEN 'ASIGNADA'
                        ELSE 'PENDIENTE'
                    END as EstadoAsignacion,
                    t5.cveest as [numero_estacion],
                    t4.codgas,
                    --t5.abr as [estacion_control_gas]
                    LTRIM(SUBSTRING(t5.abr, PATINDEX('%[^0-9 ]%', t5.abr), 8000)) AS [estacion_control_gas],
                    t7.codprd as producto_tanque,
				    CASE
                        WHEN (t7.codprd) IN (179,192) THEN 'Regular'
                        WHEN (t7.codprd) IN (180,193) THEN 'Super'
                        WHEN (t7.codprd) = 181        THEN 'Diesel'
                    END AS producto_tanque_nombre,
                    t8.ValorUnitario,
					t8.Cantidad,
					t8.Descripcion
                    FROM TG.dbo.FacturasRecibidas t1 WITH (NOLOCK)
                    LEFT JOIN TG.dbo.FacturasMovimientosTanques t2 ON t1.Id = t2.FacturaProveedorId AND t2.Activo = 1
                    LEFT JOIN TG.dbo.FacturasRecibidas t3 ON t2.FacturaPetrotalId = t3.Id
                    LEFT JOIN SG12.dbo.DocumentosC t4 WITH(NOLOCK) 
                    ON t1.UUID COLLATE DATABASE_DEFAULT = t4.satuid COLLATE DATABASE_DEFAULT
                    LEFT JOIN sg12.dbo.MovimientosTan t6 on t6.codgas = t4.codgas and t4.nro = t6.nrodoc and t6.tiptrn = 4 
                    LEFT JOIN SG12.dbo.Tanques t7 on t6.codtan = t7.cod 
                    left join SG12.dbo.Gasolineras t5 on t4.codgas = t5.cod
                    LEFT JOIN TG.dbo.FacturasRecibidasConceptos t8 on t1.Id = t8.FacturaId and t8.ClaveUnidad = 'LTR'

                    $whereClause
                    ORDER BY t1.Fecha DESC, t1.Folio";
        $params = [$from, $until];


        return ($this->sql->select($query, $params)) ?: [];
    }

    public function ver_facturas($facturaId){
        $query = "SELECT RutaArchivo, NombreArchivo FROM TG.dbo.FacturasRecibidas WHERE Id = ?";
        $result = $this->sql->select($query, [$facturaId]);
        return $result;
    }

    /**
     * Proveedores (emisores) que tienen facturas en FacturasRecibidas, para el filtro de búsqueda.
     */
    public function emisores_con_facturas() {
        $query = "SELECT EmisorRfc, MAX(EmisorNombre) AS EmisorNombre, COUNT(*) AS Total
                  FROM TG.dbo.FacturasRecibidas WITH (NOLOCK)
                  WHERE EmisorRfc IS NOT NULL AND EmisorRfc <> ''
                  GROUP BY EmisorRfc
                  ORDER BY MAX(EmisorNombre)";
        return ($this->sql->select($query, [])) ?: [];
    }

    /**
     * Búsqueda de facturas ya importadas.
     * Filtros: emisor_rfc, codgas ('' = todas, 'none' = sin estación resuelta), q (folio/UUID/remisión),
     * from/until sobre Fecha (emisión) o FechaImportacion según $campo_fecha ('fecha'|'importacion').
     * Devuelve como máximo $limite filas (+1 para detectar truncamiento).
     */
    public function search_invoices(array $f, int $limite = 1000) {
        $where = [];
        $params = [];

        $campo = ($f['campo_fecha'] ?? 'fecha') === 'importacion' ? 'fr.FechaImportacion' : 'fr.Fecha';
        if (!empty($f['from'])) {
            $where[] = "$campo >= ?";
            $params[] = date('Y-m-d', strtotime($f['from'])) . ' 00:00:00';
        }
        if (!empty($f['until'])) {
            $where[] = "$campo <= ?";
            $params[] = date('Y-m-d', strtotime($f['until'])) . ' 23:59:59';
        }
        if (!empty($f['emisor_rfc'])) {
            $where[] = "fr.EmisorRfc = ?";
            $params[] = $f['emisor_rfc'];
        }
        if (($f['codgas'] ?? '') === 'none') {
            $where[] = "fr.EstacionCodgas IS NULL";
        } elseif (($f['codgas'] ?? '') !== '') {
            $where[] = "fr.EstacionCodgas = ?";
            $params[] = (string)(int)$f['codgas'];
        }
        if (!empty($f['q'])) {
            $like = '%' . trim($f['q']) . '%';
            $where[] = "(fr.UUID LIKE ? OR fr.Folio LIKE ? OR fr.Remision LIKE ?)";
            array_push($params, $like, $like, $like);
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $top = $limite + 1;

        $query = "SELECT TOP $top
                    fr.Id, fr.Folio, fr.Serie, fr.UUID,
                    CONVERT(VARCHAR(10), fr.Fecha, 23) AS Fecha,
                    CONVERT(VARCHAR(16), fr.FechaImportacion, 120) AS FechaImportacion,
                    fr.EmisorNombre, fr.EmisorRfc, fr.ReceptorNombre,
                    fr.Total, fr.Moneda, fr.TipoDeComprobante, fr.Remision, fr.Destino,
                    fr.EstacionCodgas, fr.OrigenImportacion,
                    e.Nombre AS EstacionNombre,
                    CASE WHEN ISNULL(fr.RutaArchivo, '') <> '' THEN 1 ELSE 0 END AS TienePdf,
                    CASE WHEN ISNULL(fr.RutaXml, '') <> '' THEN 1 ELSE 0 END AS TieneXml
                  FROM TG.dbo.FacturasRecibidas fr WITH (NOLOCK)
                  LEFT JOIN TG.dbo.Estaciones e ON CAST(e.Codigo AS VARCHAR(10)) = fr.EstacionCodgas
                  $whereSQL
                  ORDER BY fr.Fecha DESC, fr.Id DESC";

        return ($this->sql->select($query, $params)) ?: [];
    }

    public function rutas_archivos($facturaId) {
        $rows = $this->sql->select("SELECT RutaArchivo, RutaXml FROM TG.dbo.FacturasRecibidas WHERE Id = ?", [$facturaId]);
        return $rows[0] ?? null;
    }

    /**
     * Rutas de PDF de varias facturas en una sola consulta. Devuelve [Id => RutaArchivo] (solo las que tienen ruta).
     */
    public function rutas_pdf_por_ids(array $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->sql->select(
            "SELECT Id, RutaArchivo FROM TG.dbo.FacturasRecibidas WHERE Id IN ($in) AND ISNULL(RutaArchivo, '') <> ''",
            $ids
        );
        $out = [];
        foreach ($rows as $r) $out[(int)$r['Id']] = $r['RutaArchivo'];
        return $out;
    }

    /** Solo la usa la importación manual de PDF del aplicativo, por eso marca el origen. */
    public function update_ruta($facturaId, $ruta, $nombreArchivo) {
        $query = "UPDATE TG.dbo.FacturasRecibidas SET RutaArchivo = ?, NombreArchivo = ?, OrigenImportacion = 'aplicativo' WHERE Id = ?";
        return $this->sql->update($query, [$ruta, $nombreArchivo, $facturaId]);
    }

}