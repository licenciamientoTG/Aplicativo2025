<?php

/**
 * Vales de una caja (hasta 15).
 * Tabla: [TG].[dbo].[arqueo_vales]
 */
class ArqueoValesModel extends Model
{
    public function by_caja(int $caja_id): array
    {
        return $this->sql->select(
            "SELECT * FROM [TG].[dbo].[arqueo_vales] WHERE caja_id = ? ORDER BY numero_vale;",
            [$caja_id]
        ) ?: [];
    }

    public function delete_by_caja(int $caja_id): bool
    {
        return (bool) $this->sql->delete(
            "DELETE FROM [TG].[dbo].[arqueo_vales] WHERE caja_id = ?;",
            [$caja_id]
        );
    }

    /**
     * Inserta los vales con monto > 0 (los vacíos se omiten).
     * Cada fila: numero_vale, fecha, concepto, dolares, mxn.
     * Debe llamarse dentro de la transacción del controlador.
     */
    public function insert_bulk(int $caja_id, array $vales): void
    {
        $query = "
            INSERT INTO [TG].[dbo].[arqueo_vales]
                ([caja_id],[numero_vale],[fecha],[concepto],[dolares],[mxn])
            VALUES (?, ?, ?, ?, ?, ?);
        ";
        foreach ($vales as $v) {
            $dolares = (float) ($v['dolares'] ?? 0);
            $mxn     = (float) ($v['mxn'] ?? 0);
            // Omitir vales totalmente vacíos.
            if ($dolares == 0 && $mxn == 0 && empty($v['concepto'])) {
                continue;
            }
            $this->sql->insert($query, [
                $caja_id,
                (int) ($v['numero_vale'] ?? 0),
                !empty($v['fecha']) ? $v['fecha'] : null,
                $v['concepto'] ?? null,
                $dolares,
                $mxn,
            ]);
        }
    }
}
