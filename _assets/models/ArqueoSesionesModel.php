<?php

/**
 * Sesiones de arqueo simultáneo Dollar2Go.
 * Tabla: [TG].[dbo].[arqueo_sesiones]
 * Ciclo de estado: programado -> abierto -> cerrado (cerrado es inmutable).
 */
class ArqueoSesionesModel extends Model
{
    /**
     * Lista todas las sesiones (DESC por fecha) con el conteo de cajas
     * completadas vs total.
     */
    public function all(): array
    {
        $query = "
            SELECT  s.id,
                    s.nombre,
                    s.fecha,
                    s.estado,
                    s.created_by,
                    s.closed_by,
                    s.closed_at,
                    s.created_at,
                    (SELECT COUNT(*) FROM [TG].[dbo].[arqueo_cajas] c
                        WHERE c.sesion_id = s.id) AS total_cajas,
                    (SELECT COUNT(*) FROM [TG].[dbo].[arqueo_cajas] c
                        WHERE c.sesion_id = s.id AND c.estado = 'completado') AS cajas_completadas
            FROM [TG].[dbo].[arqueo_sesiones] s
            ORDER BY s.fecha DESC, s.id DESC;
        ";
        return $this->sql->select($query) ?: [];
    }

    public function find($id)
    {
        $rows = $this->sql->select(
            "SELECT * FROM [TG].[dbo].[arqueo_sesiones] WHERE id = ?;",
            [$id]
        );
        return $rows ? $rows[0] : false;
    }

    /**
     * Crea la sesión en estado 'programado' y devuelve su id.
     */
    public function create(string $nombre, string $fecha, int $created_by)
    {
        $query = "
            INSERT INTO [TG].[dbo].[arqueo_sesiones]
                ([nombre],[fecha],[estado],[created_by],[created_at])
            VALUES (?, ?, 'programado', ?, GETDATE());
        ";
        return $this->sql->insert($query, [$nombre, $fecha, $created_by]);
    }

    /**
     * Cambia el estado de la sesión. Para 'cerrado' guarda quién y cuándo.
     */
    public function set_estado(int $id, string $estado, ?int $user_id = null): bool
    {
        if ($estado === 'cerrado') {
            $query = "
                UPDATE [TG].[dbo].[arqueo_sesiones]
                SET [estado] = 'cerrado', [closed_by] = ?, [closed_at] = GETDATE()
                WHERE id = ?;
            ";
            return (bool) $this->sql->update($query, [$user_id, $id]);
        }

        $query = "UPDATE [TG].[dbo].[arqueo_sesiones] SET [estado] = ? WHERE id = ?;";
        return (bool) $this->sql->update($query, [$estado, $id]);
    }

    /**
     * Número de cajas que aún NO están completadas en la sesión.
     */
    public function count_pendientes(int $sesion_id): int
    {
        $rows = $this->sql->select(
            "SELECT COUNT(*) AS n FROM [TG].[dbo].[arqueo_cajas]
             WHERE sesion_id = ? AND estado <> 'completado';",
            [$sesion_id]
        );
        return $rows ? (int) $rows[0]['n'] : 0;
    }
}
