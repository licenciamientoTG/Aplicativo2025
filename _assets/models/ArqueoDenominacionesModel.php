<?php

/**
 * Detalle de conteo por denominación de una caja.
 * Tabla: [TG].[dbo].[arqueo_denominaciones]
 */
class ArqueoDenominacionesModel extends Model
{
    public function by_caja(int $caja_id): array
    {
        return $this->sql->select(
            "SELECT * FROM [TG].[dbo].[arqueo_denominaciones] WHERE caja_id = ?;",
            [$caja_id]
        ) ?: [];
    }

    /**
     * Borra todas las denominaciones de la caja (se reinsertan al guardar).
     */
    public function delete_by_caja(int $caja_id): bool
    {
        return (bool) $this->sql->delete(
            "DELETE FROM [TG].[dbo].[arqueo_denominaciones] WHERE caja_id = ?;",
            [$caja_id]
        );
    }

    /**
     * Inserta un lote de filas de denominación.
     * Cada fila: seccion, moneda, tipo, denominacion, valor_bolsa, cantidad, total.
     * Debe llamarse dentro de la transacción del controlador.
     */
    public function insert_bulk(int $caja_id, array $rows): void
    {
        $query = "
            INSERT INTO [TG].[dbo].[arqueo_denominaciones]
                ([caja_id],[seccion],[moneda],[tipo],[denominacion],[valor_bolsa],[cantidad],[total])
            VALUES (?, ?, ?, ?, ?, ?, ?, ?);
        ";
        foreach ($rows as $r) {
            $this->sql->insert($query, [
                $caja_id,
                $r['seccion'],
                $r['moneda'],
                $r['tipo'],
                $r['denominacion'],
                $r['valor_bolsa'] ?? null,
                (int) ($r['cantidad'] ?? 0),
                $r['total'] ?? 0,
            ]);
        }
    }
}
