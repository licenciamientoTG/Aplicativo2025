<?php

/**
 * Cajas/cajeros de un arqueo. Una fila por caja física.
 * Tabla: [TG].[dbo].[arqueo_cajas]
 */
class ArqueoCajasModel extends Model
{
    /**
     * Todas las cajas de una sesión, con el nombre del usuario asignado.
     */
    public function by_sesion(int $sesion_id): array
    {
        $query = "
            SELECT c.*, u.Nombre AS asignado_nombre
            FROM [TG].[dbo].[arqueo_cajas] c
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = c.asignado_user_id
            WHERE c.sesion_id = ?
            ORDER BY c.sucursal_id, c.caja_numero;
        ";
        return $this->sql->select($query, [$sesion_id]) ?: [];
    }

    public function find($id)
    {
        $rows = $this->sql->select(
            "SELECT * FROM [TG].[dbo].[arqueo_cajas] WHERE id = ?;",
            [$id]
        );
        return $rows ? $rows[0] : false;
    }

    /**
     * Crea una caja 'pendiente' para una sesión/sucursal y devuelve su id.
     */
    public function create(array $d)
    {
        $query = "
            INSERT INTO [TG].[dbo].[arqueo_cajas]
                ([sesion_id],[sucursal_id],[sucursal_nombre],[caja_numero],
                 [cajero_nombre],[encargado_revision],[estado],[created_at],[updated_at])
            VALUES (?, ?, ?, ?, ?, ?, 'pendiente', GETDATE(), GETDATE());
        ";
        return $this->sql->insert($query, [
            $d['sesion_id'],
            $d['sucursal_id']      ?? null,
            $d['sucursal_nombre']  ?? null,
            $d['caja_numero']      ?? 1,
            $d['cajero_nombre']    ?? null,
            $d['encargado_revision'] ?? null,
        ]);
    }

    /**
     * Guarda encabezado (Go Exchange / tipos de cambio) y todos los totales
     * calculados, y marca la caja como 'completado'.
     */
    public function update_totales(int $id, array $t): bool
    {
        $query = "
            UPDATE [TG].[dbo].[arqueo_cajas] SET
                cajero_nombre        = ?,
                encargado_revision   = ?,
                go_exchange_dolares  = ?,
                go_exchange_mxn      = ?,
                costo_promedio       = ?,
                total_fisico_dolares = ?,
                total_fisico_mxn     = ?,
                total_arqueo_mxn     = ?,
                total_en_sistema     = ?,
                total_vales_dolares  = ?,
                total_vales_mxn      = ?,
                gran_total_vales_mxn = ?,
                diferencia_dolares   = ?,
                diferencia_mxn       = ?,
                resultado_final      = ?,
                estado               = 'completado',
                updated_at           = GETDATE()
            WHERE id = ?;
        ";
        return (bool) $this->sql->update($query, [
            $t['cajero_nombre']        ?? null,
            $t['encargado_revision']   ?? null,
            $t['go_exchange_dolares']  ?? 0,
            $t['go_exchange_mxn']      ?? 0,
            $t['costo_promedio']       ?? 0,
            $t['total_fisico_dolares'] ?? 0,
            $t['total_fisico_mxn']     ?? 0,
            $t['total_arqueo_mxn']     ?? 0,
            $t['total_en_sistema']     ?? 0,
            $t['total_vales_dolares']  ?? 0,
            $t['total_vales_mxn']      ?? 0,
            $t['gran_total_vales_mxn'] ?? 0,
            $t['diferencia_dolares']   ?? 0,
            $t['diferencia_mxn']       ?? 0,
            $t['resultado_final']      ?? 0,
            $id,
        ]);
    }

    /**
     * ¿Están TODAS las cajas de la sesión completadas? (true si hay al menos
     * una caja y ninguna pendiente).
     */
    public function todas_completadas(int $sesion_id): bool
    {
        $rows = $this->sql->select(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN estado = 'completado' THEN 1 ELSE 0 END) AS hechas
             FROM [TG].[dbo].[arqueo_cajas] WHERE sesion_id = ?;",
            [$sesion_id]
        );
        if (!$rows || (int) $rows[0]['total'] === 0) {
            return false;
        }
        return (int) $rows[0]['total'] === (int) $rows[0]['hechas'];
    }

    /**
     * Cajas pendientes de la sesión (para el mensaje al intentar cerrar).
     */
    public function pendientes(int $sesion_id): array
    {
        return $this->sql->select(
            "SELECT id, sucursal_nombre, caja_numero
             FROM [TG].[dbo].[arqueo_cajas]
             WHERE sesion_id = ? AND estado <> 'completado'
             ORDER BY sucursal_id, caja_numero;",
            [$sesion_id]
        ) ?: [];
    }

    /**
     * Cajas de la sesión asignadas a un usuario (vista del capturista).
     */
    public function by_sesion_asignadas(int $sesion_id, int $user_id): array
    {
        $query = "
            SELECT c.*, u.Nombre AS asignado_nombre
            FROM [TG].[dbo].[arqueo_cajas] c
            LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = c.asignado_user_id
            WHERE c.sesion_id = ? AND c.asignado_user_id = ?
            ORDER BY c.sucursal_id, c.caja_numero;
        ";
        return $this->sql->select($query, [$sesion_id, $user_id]) ?: [];
    }

    /**
     * Asigna (o desasigna con NULL) el usuario capturista de una caja.
     */
    public function asignar(int $caja_id, ?int $user_id): bool
    {
        return (bool) $this->sql->update(
            "UPDATE [TG].[dbo].[arqueo_cajas] SET asignado_user_id = ?, updated_at = GETDATE()
             WHERE id = ?;",
            [$user_id, $caja_id]
        );
    }
}
