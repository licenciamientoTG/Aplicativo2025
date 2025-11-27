<?php

class FacturasMovimientosTanquesModel extends Model {

    /**
     * Relacionar una factura con un movimiento de tanque
     *
     * @param int $nrotrn Número de transacción
     * @param string $codgas Código de estación
     * @param int $facturaProveedorId ID de la factura del proveedor
     * @param string $uuidProveedor UUID de la factura
     * @param string $folioProveedor Folio de la factura
     * @param float $montoProveedor Monto de la factura
     * @param float $litrosProveedor Litros del documento
     * @param float $precioProveedor Precio por litro
     * @param string $observaciones Observaciones
     * @param int $tipoOperacion Tipo de operación (1=Directa, 2=Con Petrotal)
     * @param int $petrotal Indica si lleva Petrotal como intermediario (0=No, 1=Sí)
     * @param string $usuario Usuario que realiza la asignación
     * @return array ['success' => bool, 'message' => string]
     */
    public function relacionarFacturaMovimiento(
        $nrotrn,
        $codgas,
        $facturaProveedorId,
        $uuidProveedor,
        $folioProveedor,
        $montoProveedor,
        $litrosProveedor,
        $precioProveedor,
        $observaciones = '',
        $tipoOperacion = 1,
        $petrotal = 0,
        $usuario = 'Sistema'
    ) {
        // Validar parámetros
        if (empty($nrotrn) || empty($codgas) || empty($facturaProveedorId)) {
            return [
                'success' => false,
                'message' => 'Parámetros inválidos: nrotrn, codgas y factura_proveedor_id son requeridos'
            ];
        }

        try {
            // Verificar si ya existe una asignación activa para este movimiento
            $queryCheck = "SELECT Id, Activo
                          FROM TG.dbo.FacturasMovimientosTanques
                          WHERE nrotrn = ? AND codgas = ? AND Activo = 1";

            $existeAsignacion = $this->sql->select($queryCheck, [$nrotrn, $codgas]);

            if ($existeAsignacion && count($existeAsignacion) > 0) {
                // Ya existe una asignación activa, actualizar
                $queryUpdate = "
                    UPDATE TG.dbo.FacturasMovimientosTanques
                    SET TipoOperacion = ?,
                        FacturaProveedorId = ?,
                        UUIDProveedor = ?,
                        FolioProveedor = ?,
                        MontoProveedor = ?,
                        LitrosProveedor = ?,
                        PrecioProveedor = ?,
                        Petrotal = ?,
                        FechaAsignacion = GETDATE(),
                        UsuarioAsignacion = ?,
                        Observaciones = ?,
                        Activo = 1
                    WHERE nrotrn = ? AND codgas = ? AND Activo = 1
                ";

                $params = [
                    $tipoOperacion,
                    $facturaProveedorId,
                    $uuidProveedor,
                    $folioProveedor,
                    $montoProveedor,
                    $litrosProveedor,
                    $precioProveedor,
                    $petrotal,
                    $usuario,
                    $observaciones,
                    $nrotrn,
                    $codgas
                ];

                $result = $this->sql->update($queryUpdate, $params);

                if ($result === false) {
                    return [
                        'success' => false,
                        'message' => 'Error al actualizar la asignación en la base de datos'
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Asignación actualizada correctamente'
                ];
            } else {
                // No existe asignación activa, insertar nueva
                $queryInsert = "
                    INSERT INTO TG.dbo.FacturasMovimientosTanques
                    (nrotrn, codgas, TipoOperacion, FacturaProveedorId, UUIDProveedor, FolioProveedor,
                     MontoProveedor, LitrosProveedor, PrecioProveedor, Petrotal, FechaAsignacion, UsuarioAsignacion,
                     Observaciones, Activo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE(), ?, ?, 1)
                ";

                $params = [
                    $nrotrn,
                    $codgas,
                    $tipoOperacion,
                    $facturaProveedorId,
                    $uuidProveedor,
                    $folioProveedor,
                    $montoProveedor,
                    $litrosProveedor,
                    $precioProveedor,
                    $petrotal,
                    $usuario,
                    $observaciones
                ];

                $result = $this->sql->insert($queryInsert, $params);

                if ($result === false) {
                    return [
                        'success' => false,
                        'message' => 'Error al insertar la asignación en la base de datos'
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Factura relacionada correctamente con el movimiento de tanque'
                ];
            }
        } catch (Exception $e) {
            error_log("Error en relacionarFacturaMovimiento: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al procesar la relación: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtener asignación por nrotrn y codgas
     *
     * @param int $nrotrn Número de transacción
     * @param string $codgas Código de estación
     * @return array|false
     */
    public function obtenerAsignacion($nrotrn, $codgas) {
        $query = "
            SELECT
                fmt.*,
                fr.Folio,
                fr.Total,
                fr.EmisorNombre,
                fr.Fecha as FechaFactura
            FROM TG.dbo.FacturasMovimientosTanques fmt
            LEFT JOIN TG.dbo.FacturasRecibidas fr ON fmt.FacturaProveedorId = fr.Id
            WHERE fmt.nrotrn = ?
              AND fmt.codgas = ?
              AND fmt.Activo = 1
        ";

        $result = $this->sql->select($query, [$nrotrn, $codgas]);
        return $result ? $result[0] : false;
    }

    /**
     * Desactivar (eliminar lógicamente) una asignación
     *
     * @param int $nrotrn Número de transacción
     * @param string $codgas Código de estación
     * @return array ['success' => bool, 'message' => string]
     */
    public function desactivarAsignacion($nrotrn, $codgas) {
        try {
            $query = "
                UPDATE TG.dbo.FacturasMovimientosTanques
                SET Activo = 0
                WHERE nrotrn = ? AND codgas = ?
            ";

            $result = $this->sql->update($query, [$nrotrn, $codgas]);

            if ($result === false) {
                return [
                    'success' => false,
                    'message' => 'Error al desactivar la asignación'
                ];
            }

            return [
                'success' => true,
                'message' => 'Asignación desactivada correctamente'
            ];
        } catch (Exception $e) {
            error_log("Error en desactivarAsignacion: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al desactivar la asignación: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtener todas las asignaciones activas
     *
     * @param string $fechaDesde Fecha desde (opcional)
     * @param string $fechaHasta Fecha hasta (opcional)
     * @return array
     */
    public function obtenerAsignacionesActivas($fechaDesde = null, $fechaHasta = null) {
        $whereClauses = ["fmt.Activo = 1"];
        $params = [];

        if ($fechaDesde && $fechaHasta) {
            $whereClauses[] = "fmt.FechaAsignacion BETWEEN ? AND ?";
            $params[] = $fechaDesde . ' 00:00:00';
            $params[] = $fechaHasta . ' 23:59:59';
        }

        $whereClause = "WHERE " . implode(" AND ", $whereClauses);

        $query = "
            SELECT
                fmt.*,
                fr.Folio,
                fr.Total,
                fr.EmisorNombre,
                fr.Fecha as FechaFactura
            FROM TG.dbo.FacturasMovimientosTanques fmt
            INNER JOIN TG.dbo.FacturasRecibidas fr ON fmt.FacturaProveedorId = fr.Id
            $whereClause
            ORDER BY fmt.FechaAsignacion DESC
        ";

        return $this->sql->select($query, $params) ?: [];
    }

    /**
     * Verificar si un movimiento ya tiene factura asignada
     *
     * @param int $nrotrn Número de transacción
     * @param string $codgas Código de estación
     * @return bool
     */
    public function tieneFacturaAsignada($nrotrn, $codgas) {
        $query = "
            SELECT COUNT(*) as total
            FROM TG.dbo.FacturasMovimientosTanques
            WHERE nrotrn = ?
              AND codgas = ?
              AND Activo = 1
        ";

        $result = $this->sql->select($query, [$nrotrn, $codgas]);
        return $result && $result[0]['total'] > 0;
    }
}
