<?php

/**
 * Captura manual por sucursal+sesión para el Concentrado de arqueo.
 * Tabla: [TG].[dbo].[arqueo_concentrado_extras]
 */
class ArqueoConcentradoExtrasModel extends Model
{
    /**
     * Todas las filas de extras de una sesión, indexadas por sucursal_id.
     */
    public function by_sesion(int $sesion_id): array
    {
        $rows = $this->sql->select(
            "SELECT * FROM [TG].[dbo].[arqueo_concentrado_extras] WHERE sesion_id = ?;",
            [$sesion_id]
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sucursal_id']] = $r;
        }
        return $out;
    }

    /**
     * Upsert de los 5 campos manuales para sesion_id+sucursal_id.
     * Usa un único statement IF EXISTS...UPDATE...ELSE INSERT porque
     * MySqlPdoHandler::update() devuelve true en cualquier ejecución
     * exitosa, sin importar filas afectadas.
     */
    public function upsert(int $sesion_id, int $sucursal_id, array $d, ?int $usuario_id): bool
    {
        return (bool) $this->sql->update(
            "IF EXISTS (SELECT 1 FROM [TG].[dbo].[arqueo_concentrado_extras]
                         WHERE sesion_id = ? AND sucursal_id = ?)
                UPDATE [TG].[dbo].[arqueo_concentrado_extras] SET
                    capital_trabajo = ?, gastos_tramite = ?, adeudo = ?,
                    reinversion = ?, utilidad = ?, updated_by = ?, updated_at = GETDATE()
                WHERE sesion_id = ? AND sucursal_id = ?
             ELSE
                INSERT INTO [TG].[dbo].[arqueo_concentrado_extras]
                    (sesion_id, sucursal_id, capital_trabajo, gastos_tramite, adeudo, reinversion, utilidad, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?);",
            [
                $sesion_id, $sucursal_id,
                $d['capital_trabajo'], $d['gastos_tramite'], $d['adeudo'],
                $d['reinversion'], $d['utilidad'], $usuario_id,
                $sesion_id, $sucursal_id,
                $sesion_id, $sucursal_id, $d['capital_trabajo'], $d['gastos_tramite'],
                $d['adeudo'], $d['reinversion'], $d['utilidad'], $usuario_id,
            ]
        );
    }
}
