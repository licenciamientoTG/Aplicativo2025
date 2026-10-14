<?php
class StockModel extends Model {
    /**
     * Inventario de productos (no combustibles) por estación.
     * @return array|false
     */
    function get_product_inventory($codgas = null): array|false {
        $query = "SELECT
                s.codgas,
                MAX(TRIM(g.abr))                                                        AS Estacion,
                s.codprd,
                MAX(TRIM(p.den))                                                        AS Producto,
                MAX(TRIM(p.uni))                                                        AS Unidad,
                CASE MAX(p.hab)
                    WHEN 1 THEN 'Activo'
                    WHEN 0 THEN 'Inactivo'
                    ELSE 'Desconocido'
                END                                                                     AS [Status],
                ROUND(SUM(ISNULL(s.ingstk, 0) - ISNULL(s.salstk, 0)), 2)                AS Existencia,
                CONVERT(varchar(10), DATEADD(day, MAX(s.ultmov) - 1, '1900-01-01'), 23) AS UltimoMov
            FROM SG12.dbo.Stock AS s
            JOIN SG12.dbo.Productos AS p ON p.cod = s.codprd
            LEFT JOIN SG12.dbo.Gasolineras AS g ON g.cod = s.codgas
            WHERE s.tipalm <> 1
              AND s.codprd NOT IN (1, 2, 3, 179, 180, 181, 192, 193)";
        $params = [];
        if (!empty($codgas)) {
            $query .= " AND s.codgas = ?";
            $params[] = $codgas;
        }
        $query .= " GROUP BY s.codgas, s.codprd ORDER BY s.codgas, MAX(p.den)";

        return $this->sql->select($query, $params) ?: false;
    }
}
