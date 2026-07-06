<?php

/**
 * Capital de Trabajo BASE por sucursal: catálogo que se copia a
 * arqueo_concentrado_extras al crear una sesión de arqueo.
 * Tabla: [TG].[dbo].[arqueo_capital_base]
 */
class ArqueoCapitalBaseModel extends Model
{
    /** Mapa sucursal_id => capital_trabajo (float). */
    public function get_all(): array
    {
        $rows = $this->sql->select(
            "SELECT sucursal_id, capital_trabajo FROM [TG].[dbo].[arqueo_capital_base];"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['sucursal_id']] = (float) $r['capital_trabajo'];
        }
        return $out;
    }

    /**
     * Upsert del capital base de una sucursal. Un solo statement
     * IF EXISTS...UPDATE...ELSE INSERT (ver ArqueoConcentradoExtrasModel).
     */
    public function upsert(int $sucursal_id, float $capital, ?int $usuario_id): bool
    {
        return (bool) $this->sql->update(
            "IF EXISTS (SELECT 1 FROM [TG].[dbo].[arqueo_capital_base]
                         WHERE sucursal_id = ?)
                UPDATE [TG].[dbo].[arqueo_capital_base] SET
                    capital_trabajo = ?, updated_by = ?, updated_at = GETDATE()
                WHERE sucursal_id = ?
             ELSE
                INSERT INTO [TG].[dbo].[arqueo_capital_base]
                    (sucursal_id, capital_trabajo, updated_by)
                VALUES (?, ?, ?);",
            [
                $sucursal_id,
                $capital, $usuario_id, $sucursal_id,
                $sucursal_id, $capital, $usuario_id,
            ]
        );
    }
}
