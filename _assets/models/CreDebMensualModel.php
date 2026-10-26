<?php

class CreDebMensualModel extends Model{
    public $id;
    public $cliente_id;
    public $nombre;
    public $tipo;
    public $litros;
    public $cantidad;
    public $monto;
    public $tipo_id;
    public $año;
    public $mes;
    public $fecha_subida;

    function get_rows() : array|false {
        $query = 'SELECT *   FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual]';
        return ($this->sql->select($query)) ?: false ;
    }

    function sum_month(){

        $query = "SELECT
                    [año],
                    [mes],
                    FORMAT(ROUND(SUM(CASE WHEN [tipo_id] = 28 THEN [litros] ELSE 0 END), 0), 'N0', 'en-US') AS 'sum_cre',
                    FORMAT(ROUND(SUM(CASE WHEN [tipo_id] = 127 THEN [litros] ELSE 0 END), 0), 'N0', 'en-US') AS 'sum_deb',
                    FORMAT(ROUND(SUM( [litros] ), 0), 'N0', 'en-US') AS 'sum_mouth'
                FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual]
                WHERE [tipo_id] IN (28, 127)
                GROUP BY [año], [mes]
                ORDER BY [año], [mes];";
        return ($this->sql->select($query)) ?: false ;
    }

    function inser_all($from,$until){

        $query="DECLARE @fecha_inicial_int INT = DATEDIFF(dd, 0, '{$from}') + 1;
            DECLARE @fecha_final_int INT = DATEDIFF(dd, 0, '{$until}') + 1;
            DECLARE @tipo INT = 127;

            INSERT INTO TGV2.dbo.ConsumoCreditoDebitoMensual (cliente_id, nombre, tipo, litros, cantidad, monto, tipo_id, año, mes)
            SELECT
                v.codcli AS CodigoCliente, -- Código del cliente
                c.den AS Cliente, -- Nombre del cliente
                CASE
                    WHEN v.codval = 28 THEN 'Credito' -- Vale de crédito
                    WHEN v.codval = 127 THEN 'Debito' -- Vale de débito
                    ELSE '' -- Otros tipos no especificados
                END AS Tipo,
                ISNULL(
                    (
                        SELECT SUM(d.can)
                        FROM [SG12].dbo.Despachos d (NOLOCK)
                        WHERE d.codcli = v.codcli -- Filtrar por cliente
                        AND d.fchtrn BETWEEN @fecha_inicial_int + 1 AND @fecha_final_int
                    ), 0) AS Litros, -- Valor por defecto 0 si no hay datos
                ISNULL(
                    (
                        SELECT SUM(d.mto)
                        FROM [SG12].dbo.Despachos d (NOLOCK)
                        WHERE d.codcli = v.codcli -- Filtrar por cliente
                        AND d.fchtrn BETWEEN @fecha_inicial_int AND @fecha_final_int
                    ), 0) AS Cantidad, -- Valor por defecto 0 si no hay datos
                SUM(v.mto) AS Monto,
                28 as tipo_id,
                YEAR(DATEADD(dd, @fecha_inicial_int - 1, 0)) AS año, -- Extraer el año de la fecha inicial
                MONTH(DATEADD(dd, @fecha_inicial_int - 1, 0)) AS mes -- Suma del monto de los vales
            FROM
                [SG12].[dbo].[ValesR] v
            LEFT JOIN [SG12].[dbo].[Clientes] c ON c.cod = v.codcli -- Unir con Clientes
            LEFT JOIN [SG12].[dbo].[ClientesValores] cv ON cv.codcli = v.codcli -- Unir con ClientesValores
            WHERE
                cv.codest = 0 and-- Filtrar clientes activos
                v.codval = CASE
                    WHEN @tipo IS NULL OR @tipo = 0 THEN v.codval -- Si el tipo es NULL o 0, filtra por todos los vales
                    ELSE @tipo -- Filtrar por el tipo de vale
                END
                AND v.fch BETWEEN @fecha_inicial_int AND @fecha_final_int -- Filtrar por rango de fechas
                AND v.codval IN (28, 127) -- Filtrar por tipos de vale específicos
            GROUP BY
                v.codcli,
                c.den,
                CASE
                    WHEN v.codval = 28 THEN 'Credito'
                    WHEN v.codval = 127 THEN 'Debito'
                    ELSE ''
                END;";
            $params=[$from,$until];
        return ($this->sql->insert($query,$params))? true : false ;
    }

    // Asesores que se pueden asignar desde TG6 (por ahora solo estos dos; el apellido de Alberto se completa después)
    const ASESORES_ASIGNABLES = ['Hugo', 'Alberto'];

    // Clientes de TG6 de un tipo (28 crédito, 127 débito). Débito: solo los que consumieron en el año en curso
    private function clients_filter(int $tipo): string {
        $base = "SELECT cliente_id FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual] WHERE tipo_id = " . ($tipo === 127 ? 127 : 28);
        if ($tipo === 127) {
            $base .= " AND [año] = YEAR(GETDATE()) GROUP BY cliente_id HAVING SUM(litros) > 0";
        }
        return "cod IN ($base)";
    }

    // Busca clientes por código, nombre o RFC dentro de los de TG6 del tipo indicado
    function search_clients(string $q, int $tipo = 28): array {
        $like = '%' . $q . '%';
        $filter = $this->clients_filter($tipo);
        $query = "SELECT TOP 100 c.cod, LTRIM(RTRIM(c.den)) AS den, LTRIM(RTRIM(c.rfc)) AS rfc,
                         LTRIM(RTRIM(a.nombre_asesor)) AS asesor
                  FROM [SG12].[dbo].[Clientes] c
                  LEFT JOIN [TGV2].[dbo].[ClientesTG6] t ON t.cod = c.cod
                  LEFT JOIN [TGV2].[dbo].[Asesores] a ON a.asesor_id = t.asesor_id
                  WHERE c.{$filter}
                    AND (? = '' OR c.den LIKE ? OR c.rfc LIKE ? OR CAST(c.cod AS VARCHAR(20)) LIKE ?)
                  ORDER BY c.den";
        return $this->sql->select($query, [$q, $like, $like, $like]) ?: [];
    }

    // id del asesor por nombre de pila; si no existe se crea (asesor_id no es identity)
    private function advisor_id(string $firstName): int {
        $row = $this->sql->select(
            "SELECT TOP 1 asesor_id FROM [TGV2].[dbo].[Asesores] WHERE LTRIM(nombre_asesor) LIKE ? ORDER BY asesor_id",
            [$firstName . '%']
        );
        if ($row) {
            return (int)$row[0]['asesor_id'];
        }
        $next = (int)$this->sql->select("SELECT ISNULL(MAX(asesor_id), 0) + 1 AS id FROM [TGV2].[dbo].[Asesores]")[0]['id'];
        $this->sql->query("INSERT INTO [TGV2].[dbo].[Asesores] (asesor_id, nombre_asesor) VALUES (?, ?)", [$next, $firstName]);
        return $next;
    }

    // Asigna el asesor a los clientes indicados (del tipo dado); devuelve cuántos clientes se actualizaron
    function assign_advisor(array $clientIds, string $firstName, int $tipo = 28): int {
        if (!in_array($firstName, self::ASESORES_ASIGNABLES, true)) {
            throw new Exception('Asesor no permitido');
        }
        $clientIds = array_values(array_unique(array_filter(array_map('intval', $clientIds))));
        if (!$clientIds) {
            return 0;
        }
        $advisorId = $this->advisor_id($firstName);
        $in = implode(',', array_fill(0, count($clientIds), '?'));
        $filter = $this->clients_filter($tipo);
        $valid = (int)$this->sql->select(
            "SELECT COUNT(*) AS n FROM [SG12].[dbo].[Clientes] WHERE cod IN ($in) AND $filter",
            $clientIds
        )[0]['n'];
        $this->sql->beginTransaction();
        try {
            // Alta en ClientesTG6 de los que todavía no tienen fila, y después se asigna el asesor
            $this->sql->query(
                "INSERT INTO [TGV2].[dbo].[ClientesTG6] (cod)
                 SELECT s.cod FROM [SG12].[dbo].[Clientes] s
                 WHERE s.cod IN ($in) AND s.$filter
                   AND NOT EXISTS (SELECT 1 FROM [TGV2].[dbo].[ClientesTG6] t WHERE t.cod = s.cod)",
                $clientIds
            );
            $this->sql->query(
                "UPDATE [TGV2].[dbo].[ClientesTG6] SET asesor_id = ? WHERE cod IN ($in) AND $filter",
                array_merge([$advisorId], $clientIds)
            );
            $this->sql->commit();
        } catch (Exception $e) {
            $this->sql->rollBack();
            throw $e;
        }
        return $valid;
    }

    // Filas de crédito/débito de un mes, para comparar antes y después de sincronizar
    function month_snapshot(int $year, int $month): array {
        $rows = $this->sql->select(
            "SELECT cliente_id, nombre, tipo_id, litros, monto
             FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual]
             WHERE [año] = {$year} AND [mes] = {$month} AND [tipo_id] IN (28, 127)"
        );
        return $rows ?: [];
    }

    /**
     * Recalcula crédito (28) y débito (127) de un mes con los despachos de $fromInt a $untilInt
     * (seriales de Excel, ver dateToInt()). Reemplaza lo que hubiera en ese año/mes.
     * Para el mes en curso el rango suele ser del día 1 a hoy.
     */
    function sync_month(int $fromInt, int $untilInt, int $year, int $month): int {
        $delete = "DELETE FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual]
                   WHERE [año] = {$year} AND [mes] = {$month} AND [tipo_id] IN (28, 127)";

        $insert = "
            INSERT INTO [TGV2].[dbo].[ConsumoCreditoDebitoMensual] (cliente_id, nombre, tipo, litros, cantidad, monto, tipo_id, año, mes)
            SELECT
                n.codcli,
                c.den,
                CASE n.codval WHEN 28 THEN 'Credito' ELSE 'Debito' END,
                ISNULL(d.litros, 0),
                ISNULL(d.cantidad, 0),
                n.monto,
                n.codval,
                {$year},
                {$month}
            FROM (
                SELECT v.codcli, v.codval, SUM(v.mto) AS monto
                FROM [SG12].[dbo].[ValesR] v
                WHERE v.codval IN (28, 127)
                  AND v.fch BETWEEN {$fromInt} AND {$untilInt}
                  AND EXISTS (SELECT 1 FROM [SG12].[dbo].[ClientesValores] cv WHERE cv.codcli = v.codcli AND cv.codest = 0)
                GROUP BY v.codcli, v.codval
            ) n
            LEFT JOIN [SG12].[dbo].[Clientes] c ON c.cod = n.codcli
            LEFT JOIN (
                SELECT codcli, SUM(can) AS litros, SUM(mto) AS cantidad
                FROM [SG12].[dbo].[Despachos] (NOLOCK)
                WHERE fchtrn BETWEEN {$fromInt} AND {$untilInt}
                GROUP BY codcli
            ) d ON d.codcli = n.codcli";

        $this->sql->beginTransaction();
        try {
            $this->sql->query($delete);
            $this->sql->query($insert);
            $monthClients = "SELECT cliente_id FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual]
                             WHERE [año] = {$year} AND [mes] = {$month} AND tipo_id IN (28, 127)";
            // Cliente nuevo en TG6: se inserta la primera vez que aparece
            $this->sql->query("
                INSERT INTO [TGV2].[dbo].[ClientesTG6] (cod)
                SELECT DISTINCT m.cliente_id FROM ($monthClients) m
                WHERE NOT EXISTS (SELECT 1 FROM [TGV2].[dbo].[ClientesTG6] t WHERE t.cod = m.cliente_id)");
            // Primer consumo: se guarda una sola vez, solo para clientes que todavía no tienen fecha_ingreso
            $this->sql->query("
                UPDATE t SET t.fecha_ingreso = DATEADD(dd, x.f - 1, 0)
                FROM [TGV2].[dbo].[ClientesTG6] t
                JOIN (SELECT d.codcli, MIN(d.fchtrn) AS f
                      FROM [SG12].[dbo].[Despachos] d (NOLOCK)
                      WHERE d.codcli IN ($monthClients)
                      GROUP BY d.codcli) x ON x.codcli = t.cod
                WHERE t.fecha_ingreso IS NULL");
            $count = $this->sql->select(
                "SELECT COUNT(*) AS n FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual]
                 WHERE [año] = {$year} AND [mes] = {$month} AND [tipo_id] IN (28, 127)"
            );
            $this->sql->commit();
        } catch (Exception $e) {
            $this->sql->rollBack();
            throw $e;
        }
        return (int)($count[0]['n'] ?? 0);
    }

    function comsumption_credit_count_table2($columnData): array|false {
        $query = "
            SELECT 
                cliente_id as CodigoCliente,
                nombre as Cliente,
                lognew,
                nombre_asesor,
                nombre_zona,
                  {$columnData['columns']}
            FROM (
                SELECT
                    t1.cliente_id,
                    t1.nombre,
                    CONCAT(t1.año, '-', t1.mes) AS AñoMes,  -- Combina año y mes en una única columna
                    t1.litros,
                    ISNULL(t2.fecha_ingreso, s.lognew) AS lognew,
					t3.[nombre_asesor],
					t4.[nombre_zona]
                FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual] t1
                LEFT JOIN [TGV2].[dbo].[ClientesTG6] t2 on t1.cliente_id = t2.cod
                LEFT JOIN [SG12].[dbo].[Clientes] s on t1.cliente_id = s.cod
				LEFT JOIN [TGV2].[dbo].[Asesores] t3 on t2.asesor_id = t3.asesor_id
				LEFT JOIN [TGV2].[dbo].[Zonas] t4 on t2.[zona_id] = t4.[zona_id]
                WHERE
                    (t1.año = ".$columnData['currentYear']."  OR t1.año = ".$columnData['previousYear'].")  -- Incluye el año actual y el anterior
                    AND t1.tipo_id = 28
            ) AS SourceTable
            PIVOT (
                SUM(litros)
                FOR AñoMes IN (".$columnData['pivotInClause'].")
            ) AS PivotTable
            ORDER BY cliente_id;
        ";
        $params = [];
       
        return ($this->sql->select($query, $params)) ?: false;
    }


    function comsumption_credit_count_table($columnData,$type): array|false {
        $selectColumns = implode(",\n            ", array_merge(
            [
                "CodigoCliente",
                "Cliente",
                "lognew",
                "nombre_asesor",
                "nombre_zona"
            ],
            $columnData['columns2']
        ));
        $caseWhen = '';
        if (!empty($columnData['columns_name'])) {
            $previousColumns = array_filter($columnData['columns2'], function($name) use ($columnData) {
                // Extraer el mes desde el formato [YYYY_M]
                if (preg_match('/\[(\d+)_(\d+)\]/', $name, $matches)) {
                    $year = intval($matches[1]); // Año
                    $month = intval($matches[2]); // Mes
                    return $year == $columnData['previousYear'] && $month >= 7 && $month <= 12;
                }
                return false;
            });
            $currentColumns = array_filter($columnData['columns2'], function($name) use ($columnData) {
                if (preg_match('/\[(\d+)_(\d+)\]/', $name, $matches)) {
                    $year = intval($matches[1]); // Año
                    $month = intval($matches[2]); // Mes
                    return $year == $columnData['currentYear'] && $month >= 1 && $month <= 6;
                }
                return false;
            });
            // Formatear columnas para la consulta
            $previousColumns = implode(' UNION ALL SELECT ', array_map(function($col) {
                return (strpos($col, ' AS val') === false) ? "$col AS val" : $col;
            }, $previousColumns));
            $currentColumns = implode(' UNION ALL SELECT ', array_map(function($col) {
                return (strpos($col, ' AS val') === false) ? "$col AS val" : $col;
            }, $currentColumns));
            // Construir la cláusula CASE
            $caseWhen = "
                CASE 
                    WHEN @currentMonth <= 6 THEN
                        (SELECT MAX(val) 
                        FROM (
                            SELECT $previousColumns
                        ) AS MonthValues)
                    ELSE
                        (SELECT MAX(val) 
                        FROM (
                            SELECT $currentColumns
                        ) AS MonthValues)
                END AS MaxValue
            ";
        }
        $query = "
        DECLARE @currentYear INT = YEAR(GETDATE());
        DECLARE @currentMonth INT = MONTH(GETDATE());

        -- Determinar el semestre actual
        DECLARE @startMonth INT;
        DECLARE @endMonth INT;
        DECLARE @yearToCompare INT;

        IF @currentMonth <= 6
        BEGIN
            SET @startMonth = 7;
            SET @endMonth = 12;
            SET @yearToCompare = @currentYear - 1;
        END
        ELSE
        BEGIN
            SET @startMonth = 1;
            SET @endMonth = 6;
            SET @yearToCompare = @currentYear;
        END
        ;WITH PivotedData AS (
            SELECT 
                cliente_id as CodigoCliente,
                nombre as Cliente,
                lognew,
                nombre_asesor,
                nombre_zona,
                  {$columnData['columns']}
            FROM (
                SELECT
                    t1.cliente_id,
                    t1.nombre,
                    CONCAT(t1.año, '-', t1.mes) AS AñoMes,  -- Combina año y mes en una única columna
                    t1.litros,
                    ISNULL(t2.fecha_ingreso, s.lognew) AS lognew,
					t3.[nombre_asesor],
					t4.[nombre_zona]
                FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual] t1
                LEFT JOIN [TGV2].[dbo].[ClientesTG6] t2 on t1.cliente_id = t2.cod
                LEFT JOIN [SG12].[dbo].[Clientes] s on t1.cliente_id = s.cod
				LEFT JOIN [TGV2].[dbo].[Asesores] t3 on t2.asesor_id = t3.asesor_id
				LEFT JOIN [TGV2].[dbo].[Zonas] t4 on t2.[zona_id] = t4.[zona_id]
                WHERE
                    (t1.año = ".$columnData['currentYear']."  OR t1.año = ".$columnData['previousYear'].")  -- Incluye el año actual y el anterior
                    AND t1.tipo_id = ".$type."
            ) AS SourceTable
            PIVOT (
                SUM(litros)
                FOR AñoMes IN (".$columnData['pivotInClause'].")
            ) AS PivotTable
        )
            SELECT
            $selectColumns,
            $caseWhen
        FROM PivotedData
        ORDER BY CodigoCliente;
        ";

        $params = [];
        return ($this->sql->select($query, $params)) ?: false;
    }

    function comsumption_credit_client_table($columnData,$type){
        $selectColumns = implode(",\n            ", array_merge(
            [
                "tc.nombre as Cliente",
			    "tp.rfc",
                "tc.ingreso"

            ],
            $columnData['columns2']
        ));
        $caseWhen = '';
        if (!empty($columnData['columns_name'])) {
            $previousColumns = array_filter($columnData['columns2'], function($name) use ($columnData) {
                // Extraer el mes desde el formato [YYYY_M]
                if (preg_match('/\[(\d+)_(\d+)\]/', $name, $matches)) {
                    $year = intval($matches[1]); // Año
                    $month = intval($matches[2]); // Mes
                    return $year == $columnData['previousYear'] && $month >= 7 && $month <= 12;
                }
                return false;
            });
            $currentColumns = array_filter($columnData['columns2'], function($name) use ($columnData) {
                if (preg_match('/\[(\d+)_(\d+)\]/', $name, $matches)) {
                    $year = intval($matches[1]); // Año
                    $month = intval($matches[2]); // Mes
                    return $year == $columnData['currentYear'] && $month >= 1 && $month <= 6;
                }
                return false;
            });
            // Formatear columnas para la consulta
            $previousColumns = implode(' UNION ALL SELECT ', array_map(function($col) {
                return (strpos($col, ' AS val') === false) ? "$col AS val" : $col;
            }, $previousColumns));
            $currentColumns = implode(' UNION ALL SELECT ', array_map(function($col) {
                return (strpos($col, ' AS val') === false) ? "$col AS val" : $col;
            }, $currentColumns));
            // Construir la cláusula CASE
            $caseWhen = "
                CASE 
                    WHEN  MONTH(GETDATE()) <= 6 THEN
                        (SELECT MAX(val) 
                        FROM (
                            SELECT $previousColumns
                        ) AS MonthValues)
                    ELSE
                        (SELECT MAX(val) 
                        FROM (
                            SELECT $currentColumns
                        ) AS MonthValues)
                END AS MaxValue
            ";
        }
        $query = "
        
        WITH PivotedData AS (
            SELECT 
               rfc,
                  {$columnData['columns']}
            FROM (
                SELECT
                   LTRIM(RTRIM(t2.rfc)) AS rfc,  -- Elimina espacios en blanco alrededor del RFC
                    CONCAT(t1.año, '-', t1.mes) AS AñoMes,  -- Combina año y mes en una única columna
                    sum(t1.litros) as litros
                FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual] t1
                LEFT JOIN [SG12].[dbo].[Clientes] t2 on t1.cliente_id = t2.cod
                WHERE
                    (t1.año = ".$columnData['currentYear']."  OR t1.año = ".$columnData['previousYear'].")  -- Incluye el año actual y el anterior
                    AND t1.tipo_id = ".$type."
                    Group by t2.rfc, t1.año, t1.mes
            ) AS SourceTable
            PIVOT (
                SUM(litros)
                FOR AñoMes IN (".$columnData['pivotInClause'].")
            ) AS PivotTable
        )
            SELECT
            $selectColumns,
            $caseWhen
        FROM PivotedData tp
       JOIN (
        SELECT
			max(s.den) as nombre,
			min(ISNULL(t.fecha_ingreso, s.lognew)) as ingreso,
			s.rfc
			FROM [SG12].[dbo].[Clientes] s
			LEFT JOIN [TGV2].[dbo].[ClientesTG6] t ON t.cod = s.cod
			WHERE s.cod IN (SELECT cliente_id FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual])
			Group by s.rfc
        ) tc on tp.rfc = tc.rfc
        order by rfc
        ";
        $params = [];
        return ($this->sql->select($query, $params)) ?: false;

    }

    public function get_cre_mun_jua(){

        $query = "SELECT
                    LTRIM(RTRIM(t2.rfc)) AS rfc,  -- Elimina espacios en blanco alrededor del RFC
                    t1.mes AS mes,  -- Combina año y mes en una única columna
                    t1.año AS año,  -- Combina año y mes en una única columna
                    FORMAT(ROUND(sum(t1.litros),0), 'N0', 'en-US')    as litros
                FROM [TGV2].[dbo].[ConsumoCreditoDebitoMensual] t1
                LEFT JOIN [SG12].[dbo].[Clientes] t2 on t1.cliente_id = t2.cod
                WHERE
                    (t1.año = 2024  OR t1.año = 2023)  -- Incluye el año actual y el anterior
                    AND t1.tipo_id = 28
                    and t2.rfc = 'MJU741010ET1'
                    Group by t2.rfc, t1.año, t1.mes
                    order by año,mes";
        $params = [];
        return ($this->sql->select($query, $params)) ?: false;
    }




}