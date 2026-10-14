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
                CONVERT(varchar(10), DATEADD(day, MAX(s.ultmov) - 1, '1900-01-01'), 23) AS UltimoMov,
                MAX(TRIM(p.codbar))                                                     AS BarcodeCorp,
                MAX(TRIM(p.codext))                                                     AS CodextCorp
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

    /**
     * Historial de movimientos de stock (entradas/salidas por período) de un
     * producto en una estación.
     * @return array|false
     */
    function get_product_history($codprd, $codgas): array|false {
        $query = "SELECT TOP (1000)
                s.[codprd]
                , TRIM(p.den)                                             AS Producto
                , TRIM(p.uni)                                             AS Unidad
                , CASE p.hab
                      WHEN 1 THEN 'Activo'
                      WHEN 0 THEN 'Inactivo'
                      ELSE 'Desconocido'
                  END                                                     AS [Status]
                , s.[codgas]
                , s.[percon]
                , CONVERT(VARCHAR(10), CAST(s.ultmov AS DATETIME) - 1, 23) AS Fecha
                , s.[ingstk]
                , s.[salstk]
            FROM [SG12].[dbo].[Stock] AS s
            LEFT JOIN [SG12].[dbo].[Productos] AS p ON p.cod = s.codprd
            WHERE s.codprd = ?
              AND s.codgas = ?
            ORDER BY s.percon DESC";

        return $this->sql->select($query, [$codprd, $codgas]) ?: false;
    }

    /**
     * Código de barras (codbar) y código externo (codext) reportados por la
     * propia base de cada estación vía linked server, para comparar contra el
     * catálogo corporativo (SG12.dbo.Productos, que puede ir desfasado de lo
     * que hay en estación).
     *
     * Se valida conectividad con fsockopen() al puerto 1433 (mismo patrón que
     * DespachosModel::sp_obtener_diferencias_por_valor) en vez de ping/exec():
     * exec() suele estar deshabilitado en PHP de producción y, aunque no lo
     * estuviera, muchas redes bloquean ICMP aunque el puerto SQL sí esté
     * abierto, lo que haría fallar el chequeo para estaciones que sí están
     * disponibles.
     *
     * Cada estación se consulta en una sentencia aparte (no un solo UNION
     * ALL): si se combinaran en una sola consulta, una sola estación caída
     * tira la sentencia completa y deja sin resultado a todas las demás.
     *
     * @param int[] $codgasList
     * @return array<int, array<int, array{codbar: string, codext: string}>> codgas => [codprd => campos]
     */
    function get_station_product_fields(array $codgasList): array {
        $map = [];
        foreach ($codgasList as $codgas) {
            $codgas = (int)$codgas;
            if (empty($this->linked_server[$codgas]) || empty($this->short_databases[$codgas])) continue;

            $ip = trim($this->linked_server[$codgas], '[]');
            $connection = @fsockopen($ip, 1433, $errno, $errstr, 5);
            if (!$connection) continue;
            fclose($connection);

            $query = "SELECT cod, codbar, codext
                FROM OPENQUERY({$this->linked_server[$codgas]}, 'SELECT cod, codbar, codext FROM {$this->short_databases[$codgas]}.[Productos]')";
            $rows = $this->sql->selectSafe($query);
            if ($rows === false) continue;

            foreach ($rows as $row) {
                $map[$codgas][(int)$row['cod']] = [
                    'codbar' => trim((string)($row['codbar'] ?? '')),
                    'codext' => trim((string)($row['codext'] ?? '')),
                ];
            }
        }
        return $map;
    }
}
