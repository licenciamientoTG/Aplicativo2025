<?php

/**
 * Snapshot y captura del módulo Análisis de Merma Diaria (/merma/...).
 * Tablas: TG.dbo.merma_diaria | merma_manual | merma_mes_config | merma_sync_log
 * Schema: docs/sql/merma_schema.sql
 *
 * inv_inicial/inv_contable/diferencia usan la regla del libro amarillo (Excel):
 * el inicial de cada turno es el físico del turno inmediato anterior, encadenado
 * entre días y meses (recalc_contable). Por eso NO cuadran por turno con
 * /supply/tgr01, que conserva la regla del SP (mismo turno del día anterior).
 * Spec: docs/superpowers/specs/2026-07-16-merma-libro-amarillo-encadenado-design.md
 */
class MermaDiariaModel extends Model
{
    /** Familias de producto para presentación (codprd reales en el snapshot). */
    public const FAMILIAS = [
        'maxima' => [1, 179, 192],
        'super'  => [2, 180, 193],
        'diesel' => [3, 181],
    ];

    private function familiaCase(string $familia, string $columna): string
    {
        $codes = implode(',', self::FAMILIAS[$familia]);
        return "SUM(CASE WHEN codprd IN ($codes) THEN $columna END)";
    }

    public function get_estaciones(): array
    {
        $query = 'SELECT e.Codigo, e.Nombre, g.cveest
                  FROM [TG].[dbo].[Estaciones] e
                  LEFT JOIN [SG12].[dbo].[Gasolineras] g ON g.cod = e.Codigo
                  WHERE e.Codigo NOT IN (0, 4, 20) ORDER BY e.Nombre;';
        return $this->sql->select($query) ?: [];
    }

    /**
     * Reemplaza el snapshot de UNA estación en un rango de fechas (delete +
     * insert dentro de transacción). Solo se llama con estaciones que SÍ
     * respondieron, para no borrar datos de estaciones caídas.
     */
    public function replace_station_range(int $codgas, string $estacion, string $desde, string $hasta, array $filas): int
    {
        $this->sql->beginTransaction();
        try {
            $this->sql->delete(
                'DELETE FROM [TG].[dbo].[merma_diaria] WHERE codgas = ? AND fecha BETWEEN ? AND ?;',
                [$codgas, $desde, $hasta]
            );
            $insertadas = 0;
            foreach ($filas as $f) {
                // Turnos fuera de 11/21/41 no deben existir (el SP normaliza), se ignoran por seguridad
                if (!in_array((int)$f['Turno'], [11, 21, 41])) continue;
                $this->sql->insert(
                    'INSERT INTO [TG].[dbo].[merma_diaria]
                     (fecha, codgas, estacion, codprd, producto, turno, ventas_reales,
                      inv_fisico, compras, inv_inicial, inv_contable, diferencia, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE());',
                    [
                        $f['Fecha'], $codgas, $estacion, (int)$f['CodProducto'], $f['Producto'],
                        (int)$f['Turno'], $f['VentasReales'], $f['Inventario'], $f['CantidadCompra'],
                        $f['InventarioInicial'], $f['InventarioContable'], $f['Diferencia'],
                    ]
                );
                $insertadas++;
            }
            $this->sql->commit();
        } catch (Exception $e) {
            $this->sql->rollBack();
            throw $e;
        }
        // Fuera de la transacción: recalcula toda la partición de la estación,
        // incluidas las filas posteriores al rango cuyo baseline cambió.
        $this->recalc_contable($codgas);
        return $insertadas;
    }

    /**
     * Sobreescribe inv_inicial/inv_contable/diferencia con la regla encadenada
     * del libro amarillo: inicial = físico del turno inmediato anterior
     * (LAG por estación/producto). Si el turno anterior no tuvo corte físico,
     * las tres columnas quedan NULL (la vista muestra s/d, no se arrastra un 0).
     * Con codgas = 0 recalcula todas las estaciones (backfill).
     */
    public function recalc_contable(int $codgas = 0): void
    {
        $where  = $codgas > 0 ? 'WHERE codgas = ?' : '';
        $params = $codgas > 0 ? [$codgas] : [];
        $query = "WITH b AS (
                      SELECT id, LAG(inv_fisico) OVER (
                                 PARTITION BY codgas, codprd
                                 ORDER BY fecha, turno) AS fis_prev
                      FROM [TG].[dbo].[merma_diaria] $where
                  )
                  UPDATE m SET
                      inv_inicial  = b.fis_prev,
                      inv_contable = ROUND(b.fis_prev - ISNULL(m.ventas_reales, 0)
                                           + ISNULL(m.compras, 0), 2),
                      diferencia   = ROUND(m.inv_fisico - (b.fis_prev
                                           - ISNULL(m.ventas_reales, 0)
                                           + ISNULL(m.compras, 0)), 2)
                  FROM [TG].[dbo].[merma_diaria] m
                  JOIN b ON b.id = m.id;";
        $this->sql->update($query, $params);
    }

    public function get_resumen_mensual(int $anio, int $mes): array
    {
        $query = 'SELECT codgas, MAX(estacion) AS estacion,
                    ' . $this->familiaCase('maxima', 'diferencia') . ' AS merma_maxima,
                    ' . $this->familiaCase('super', 'diferencia') . '  AS merma_super,
                    ' . $this->familiaCase('diesel', 'diferencia') . ' AS merma_diesel,
                    SUM(diferencia)          AS merma_total,
                    SUM(ventas_reales)       AS venta_total,
                    COUNT(DISTINCT fecha)    AS dias_con_datos,
                    MAX(updated_at)          AS last_update
                  FROM [TG].[dbo].[merma_diaria]
                  WHERE YEAR(fecha) = ? AND MONTH(fecha) = ?
                  GROUP BY codgas;';
        $rows = $this->sql->select($query, [$anio, $mes]) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int)$r['codgas']] = $r;
        return $out;
    }

    public function get_fechas_por_estacion(int $anio, int $mes): array
    {
        $query = 'SELECT DISTINCT codgas, fecha FROM [TG].[dbo].[merma_diaria]
                  WHERE YEAR(fecha) = ? AND MONTH(fecha) = ? ORDER BY codgas, fecha;';
        $rows = $this->sql->select($query, [$anio, $mes]) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int)$r['codgas']][] = substr($r['fecha'], 0, 10);
        return $out;
    }

    public function get_detalle_mensual(int $codgas, int $anio, int $mes): array
    {
        $cols = [];
        foreach (self::FAMILIAS as $fam => $codes) {
            $cols[] = $this->familiaCase($fam, 'ventas_reales') . " AS vr_$fam";
            $cols[] = $this->familiaCase($fam, 'compras') . " AS compras_$fam";
            $cols[] = $this->familiaCase($fam, 'inv_contable') . " AS cont_$fam";
            $cols[] = $this->familiaCase($fam, 'inv_fisico') . " AS fis_$fam";
            $cols[] = $this->familiaCase($fam, 'diferencia') . " AS dif_$fam";
        }
        $query = 'SELECT fecha, turno, ' . implode(', ', $cols) . '
                  FROM [TG].[dbo].[merma_diaria]
                  WHERE codgas = ? AND YEAR(fecha) = ? AND MONTH(fecha) = ?
                  GROUP BY fecha, turno
                  ORDER BY fecha, turno;';
        return $this->sql->select($query, [$codgas, $anio, $mes]) ?: [];
    }

    /**
     * Inventario físico con el que arranca el mes (INV. INIC. del libro
     * amarillo): última fila anterior al día 1 por producto, sumada por
     * familia. Es el mismo baseline que encadena recalc_contable para la
     * primera fila del mes. NULL si no hay snapshot del mes anterior.
     */
    public function get_inv_inicial_mes(int $codgas, int $anio, int $mes): ?array
    {
        $primerDia = sprintf('%04d-%02d-01', $anio, $mes);
        $cols = [];
        foreach (self::FAMILIAS as $fam => $codes) {
            $c = implode(',', $codes);
            $cols[] = "SUM(CASE WHEN codprd IN ($c) THEN inv_fisico END) AS ini_$fam";
        }
        $query = 'WITH u AS (
                      SELECT codprd, inv_fisico, fecha,
                             ROW_NUMBER() OVER (PARTITION BY codprd
                                                ORDER BY fecha DESC, turno DESC) AS rn
                      FROM [TG].[dbo].[merma_diaria]
                      WHERE codgas = ? AND fecha < ?
                  )
                  SELECT MAX(fecha) AS fecha, ' . implode(', ', $cols) . '
                  FROM u WHERE rn = 1;';
        $rows = $this->sql->select($query, [$codgas, $primerDia]);
        return ($rows && $rows[0]['fecha'] !== null) ? $rows[0] : null;
    }

    public function get_manual(int $anio, int $mes): array
    {
        $rows = $this->sql->select(
            'SELECT * FROM [TG].[dbo].[merma_manual] WHERE anio = ? AND mes = ?;',
            [$anio, $mes]
        ) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int)$r['codgas']] = $r;
        return $out;
    }

    public function save_manual(int $codgas, int $anio, int $mes, string $campo, $valor, int $usuario): bool
    {
        // Whitelist de columnas: el nombre viene del cliente
        if (!in_array($campo, ['merma_sd_maxima', 'merma_sd_super', 'merma_sd_diesel', 'comentarios'])) {
            return false;
        }
        if ($valor === '') $valor = null;
        $exists = $this->sql->select(
            'SELECT id FROM [TG].[dbo].[merma_manual] WHERE codgas = ? AND anio = ? AND mes = ?;',
            [$codgas, $anio, $mes]
        );
        if ($exists) {
            $this->sql->update(
                "UPDATE [TG].[dbo].[merma_manual]
                 SET $campo = ?, updated_by = ?, updated_at = GETDATE() WHERE id = ?;",
                [$valor, $usuario, $exists[0]['id']]
            );
        } else {
            $this->sql->insert(
                "INSERT INTO [TG].[dbo].[merma_manual] (codgas, anio, mes, $campo, updated_by)
                 VALUES (?, ?, ?, ?, ?);",
                [$codgas, $anio, $mes, $valor, $usuario]
            );
        }
        return true;
    }

    public function get_precio(int $anio, int $mes): float
    {
        $rows = $this->sql->select(
            'SELECT precio_litro FROM [TG].[dbo].[merma_mes_config] WHERE anio = ? AND mes = ?;',
            [$anio, $mes]
        );
        return $rows ? (float)$rows[0]['precio_litro'] : 18.99;
    }

    public function save_precio(int $anio, int $mes, float $precio, int $usuario): bool
    {
        $exists = $this->sql->select(
            'SELECT id FROM [TG].[dbo].[merma_mes_config] WHERE anio = ? AND mes = ?;',
            [$anio, $mes]
        );
        if ($exists) {
            $this->sql->update(
                'UPDATE [TG].[dbo].[merma_mes_config]
                 SET precio_litro = ?, updated_by = ?, updated_at = GETDATE() WHERE id = ?;',
                [$precio, $usuario, $exists[0]['id']]
            );
        } else {
            $this->sql->insert(
                'INSERT INTO [TG].[dbo].[merma_mes_config] (anio, mes, precio_litro, updated_by)
                 VALUES (?, ?, ?, ?);',
                [$anio, $mes, $precio, $usuario]
            );
        }
        return true;
    }

    public function add_sync_log(string $origen, ?int $usuario, string $desde, string $hasta, int $codgas, int $ok, int $err, string $detalle, float $duracion): void
    {
        $this->sql->insert(
            'INSERT INTO [TG].[dbo].[merma_sync_log]
             (origen, usuario, desde, hasta, codgas, estaciones_ok, estaciones_error, detalle_errores, duracion_seg)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
            [$origen, $usuario, $desde, $hasta, $codgas, $ok, $err, $detalle, $duracion]
        );
    }
}
