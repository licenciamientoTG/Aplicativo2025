<?php

class FacturasRecibidasModel extends Model {
    
    /**
     * Buscar facturas por UUIDs
     */
    public function buscarPorUUIDs($uuids) {
        if (empty($uuids)) {
            return false;
        }
        
        // Crear placeholders para la consulta IN
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        
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
                 
        
        return $this->sql->select($query, $uuids) ?: false;
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
}