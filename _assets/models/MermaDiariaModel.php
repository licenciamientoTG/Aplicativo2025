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

    /**
     * Rango plausible de un corte físico en litros. StockReal de las
     * estaciones trae lecturas corruptas (se han visto 2.5e+32, 1.7e+12,
     * 1.5e-12 y 3.1 en la 18). El valor crudo SÍ se guarda en inv_fisico
     * (para verlo y eventualmente corregirlo), pero recalc_contable lo trata
     * como "sin corte" (contable/diferencia NULL, no encadena basura).
     * Techo con precedente en get_consolidado_tanques de ApiER (vol < 1e6).
     */
    public const INV_FISICO_MIN = 100;
    public const INV_FISICO_MAX = 1000000;

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
        // Fuera de la transacción: descuenta compras excluidas y recalcula toda
        // la partición de la estación, incluidas las filas posteriores al rango
        // cuyo baseline cambió.
        $this->aplicar_exclusiones($codgas, $desde, $hasta);
        $this->recalc_contable($codgas);
        return $insertadas;
    }

    /**
     * Descuenta del snapshot las compras marcadas como excluidas (duplicadas/
     * fantasma) SOLO en este sistema — ControlGas no se toca. Se corre tras
     * cada replace del rango, antes del recalc. Si la tabla aún no existe no
     * debe tumbar el sync.
     */
    public function aplicar_exclusiones(int $codgas, string $desde, string $hasta): void
    {
        try {
            $this->sql->update(
                'UPDATE m SET compras = CASE WHEN ROUND(m.compras - e.litros, 2) < 0
                                             THEN 0 ELSE ROUND(m.compras - e.litros, 2) END
                 FROM [TG].[dbo].[merma_diaria] m
                 JOIN (SELECT codgas, fecha, codprd, turno, SUM(litros) AS litros
                       FROM [TG].[dbo].[merma_compras_excluidas]
                       GROUP BY codgas, fecha, codprd, turno) e
                   ON e.codgas = m.codgas AND e.fecha = m.fecha
                  AND e.codprd = m.codprd AND e.turno = m.turno
                 WHERE m.codgas = ? AND m.fecha BETWEEN ? AND ?;',
                [$codgas, $desde, $hasta]);
        } catch (Throwable $e) {
            // Tabla inexistente u otro fallo: el sync sigue, sin exclusiones
        }
    }

    /** Marca un doc de compra como excluido del reporte. */
    public function excluir_compra(int $codgas, string $fecha, int $codprd, int $turno,
                                   int $nro, float $litros, string $motivo, int $usuario): bool
    {
        return (bool)$this->sql->insert(
            'INSERT INTO [TG].[dbo].[merma_compras_excluidas]
                (codgas, fecha, fch, codprd, turno, nro_doc, litros, motivo, usuario)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
            [$codgas, $fecha, dateToInt($fecha), $codprd, $turno, $nro, round($litros), $motivo, $usuario]);
    }

    /** Quita la exclusión de un doc (vuelve a contar en el reporte). */
    public function incluir_compra(int $codgas, string $fecha, int $codprd, int $nro): bool
    {
        return (bool)$this->sql->delete(
            'DELETE FROM [TG].[dbo].[merma_compras_excluidas]
             WHERE codgas = ? AND fch = ? AND codprd = ? AND nro_doc = ?;',
            [$codgas, dateToInt($fecha), $codprd, $nro]);
    }

    /**
     * Sobreescribe inv_inicial/inv_contable/diferencia con la regla encadenada
     * del libro amarillo: inicial = físico del turno inmediato anterior
     * (LAG por estación/producto). Si el turno anterior no tuvo corte físico,
     * las tres columnas quedan NULL (la vista muestra s/d, no se arrastra un 0).
     * Las lecturas físicas fuera del rango plausible (INV_FISICO_MIN/MAX)
     * se tratan como "sin corte": el valor crudo queda en inv_fisico para
     * poder verlo/corregirlo, pero no entra al cálculo ni se encadena.
     * Con codgas = 0 recalcula todas las estaciones (backfill).
     */
    public function recalc_contable(int $codgas = 0): void
    {
        $where  = $codgas > 0 ? 'WHERE codgas = ?' : '';
        $params = $codgas > 0 ? [$codgas] : [];
        $min    = self::INV_FISICO_MIN;
        $max    = self::INV_FISICO_MAX;
        $query = "WITH b AS (
                      SELECT id,
                             CASE WHEN inv_fisico BETWEEN $min AND $max
                                  THEN inv_fisico END AS fis_ok,
                             LAG(CASE WHEN inv_fisico BETWEEN $min AND $max
                                      THEN inv_fisico END) OVER (
                                 PARTITION BY codgas, codprd
                                 ORDER BY fecha, turno) AS fis_prev
                      FROM [TG].[dbo].[merma_diaria] $where
                  )
                  UPDATE m SET
                      inv_inicial  = b.fis_prev,
                      inv_contable = ROUND(b.fis_prev - ISNULL(m.ventas_reales, 0)
                                           + ISNULL(m.compras, 0), 2),
                      diferencia   = ROUND(b.fis_ok - (b.fis_prev
                                           - ISNULL(m.ventas_reales, 0)
                                           + ISNULL(m.compras, 0)), 2)
                  FROM [TG].[dbo].[merma_diaria] m
                  JOIN b ON b.id = m.id;";
        $this->sql->update($query, $params);
    }

    public function get_resumen_rango(string $desde, string $hasta): array
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
                  WHERE fecha >= ? AND fecha < DATEADD(DAY, 1, CAST(? AS DATE))
                  GROUP BY codgas;';
        $rows = $this->sql->select($query, [$desde, $hasta]) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int)$r['codgas']] = $r;
        return $out;
    }

    public function get_fechas_por_estacion(string $desde, string $hasta): array
    {
        $query = 'SELECT DISTINCT codgas, fecha FROM [TG].[dbo].[merma_diaria]
                  WHERE fecha >= ? AND fecha < DATEADD(DAY, 1, CAST(? AS DATE))
                  ORDER BY codgas, fecha;';
        $rows = $this->sql->select($query, [$desde, $hasta]) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int)$r['codgas']][] = substr($r['fecha'], 0, 10);
        return $out;
    }

    /* ===================================================================== */
    /* Corrección de cortes físicos en la estación (StockReal vía OPENQUERY)  */
    /* ===================================================================== */

    /** Servidor (linked server) y base de datos de una estación. */
    public function get_estacion_conexion(int $codgas): ?array
    {
        $rs = $this->sql->select(
            'SELECT Servidor, BaseDatos, Nombre FROM [TG].[dbo].[Estaciones] WHERE Codigo = ?;',
            [$codgas]);
        return ($rs && !empty($rs[0]['Servidor']) && !empty($rs[0]['BaseDatos'])) ? $rs[0] : null;
    }

    /**
     * Filas crudas de StockReal de la estación para un día del snapshot
     * (una por producto/turno/tanque). La fecha mostrada en el reporte es
     * fch - 1, por eso fch = serial de la fecha (dateToInt).
     */
    public function get_cortes_fisicos(int $codgas, string $fecha, ?string $familia = null, ?int $turno = null): array
    {
        $est = $this->get_estacion_conexion($codgas);
        if (!$est) return [];
        $fch  = dateToInt($fecha);
        $prds = isset(self::FAMILIAS[$familia])
            ? implode(',', self::FAMILIAS[$familia])
            : implode(',', array_merge(...array_values(self::FAMILIAS)));
        // Turno mostrado (11/21/41) → nrotur crudos de StockReal
        $turnoNrotur = [11 => '10, 11', 21 => '20, 21', 41 => '40, 41'];
        $filtroTurno = isset($turnoNrotur[$turno]) ? " AND nrotur IN ({$turnoNrotur[$turno]})" : '';
        $inner = sprintf(
            'SELECT fch, codgas, codprd, nrotur, codtan, can, logfch, lognew
             FROM [%s].dbo.StockReal
             WHERE fch = %d AND codgas = %d AND codprd IN (%s) AND nrotur NOT IN (30, 31)%s',
            $est['BaseDatos'], $fch, $codgas, $prds, $filtroTurno);
        $query = sprintf("SELECT * FROM OPENQUERY([%s], '%s') ORDER BY codprd, nrotur, codtan;",
            $est['Servidor'], str_replace("'", "''", $inner));
        $cortes = $this->sql->select($query) ?: [];

        // Valor sugerido por corte = contable del libro amarillo: último
        // físico válido anterior (encadena días previos) − ventas + compras
        // del turno, calculado desde el snapshot local
        $snap = $this->sql->select(
            'SELECT fecha, codprd, turno, ventas_reales, compras, inv_fisico
             FROM [TG].[dbo].[merma_diaria]
             WHERE codgas = ? AND fecha BETWEEN DATEADD(DAY, -7, CAST(? AS DATE)) AND CAST(? AS DATE)
             ORDER BY codprd, fecha, turno;',
            [$codgas, $fecha, $fecha]);
        $rec  = [];  // "codprd-turno" => sugerido (solo turnos del día pedido)
        $last = [];  // codprd => último físico válido de la cadena
        foreach ($snap ?: [] as $s) {
            $prd = (int)$s['codprd'];
            if (substr($s['fecha'], 0, 10) === $fecha && isset($last[$prd])) {
                $rec[$prd . '-' . (int)$s['turno']] = round(
                    $last[$prd] - (float)$s['ventas_reales'] + (float)($s['compras'] ?? 0), 2);
            }
            $fis = $s['inv_fisico'];
            if ($fis !== null && $fis >= self::INV_FISICO_MIN && $fis <= self::INV_FISICO_MAX) {
                $last[$prd] = (float)$fis;
            }
        }
        // El contable es del TURNO completo (suma de tanques): a cada fila se
        // le sugiere contable - los demás tanques válidos de su mismo corte,
        // para no duplicar el turno en estaciones con varios tanques por
        // producto (caso Gemela Grande: tanque 7 real + tanque 78 en 0)
        $turnoMap = [10 => 11, 20 => 21, 30 => 41, 40 => 41];
        $validosPorCorte = [];
        foreach ($cortes as $c) {
            $key = $c['codprd'] . '-' . $c['nrotur'];
            $can = (float)$c['can'];
            if ($can >= self::INV_FISICO_MIN && $can <= self::INV_FISICO_MAX) {
                $validosPorCorte[$key][(int)$c['codtan']] = $can;
            }
        }
        foreach ($cortes as &$c) {
            $turno = $turnoMap[(int)$c['nrotur']] ?? (int)$c['nrotur'];
            $recTurno = $rec[(int)$c['codprd'] . '-' . $turno] ?? null;
            if ($recTurno === null) { $c['recomendado'] = null; continue; }
            $otros = 0.0;
            foreach ($validosPorCorte[$c['codprd'] . '-' . $c['nrotur']] ?? [] as $tan => $can) {
                if ($tan !== (int)$c['codtan']) $otros += $can;
            }
            $c['recomendado'] = max(0, round($recTurno - $otros, 2));
        }
        unset($c);
        return $cortes;
    }

    /**
     * Corrige el corte físico de UNA fila exacta de StockReal en la estación
     * y deja bitácora en TG.dbo.merma_fisico_log. Sin transacción distribuida:
     * primero el UPDATE remoto y, solo si afectó la fila, la bitácora local.
     */
    public function update_corte_fisico(int $codgas, string $fecha, int $codprd, int $nrotur,
                                        int $codtan, float $valor, int $usuario): array
    {
        $est = $this->get_estacion_conexion($codgas);
        if (!$est) return ['success' => false, 'message' => 'Estación sin servidor/BD configurados'];
        $fch = dateToInt($fecha);

        $inner = sprintf(
            'SELECT can, logfch FROM [%s].dbo.StockReal
             WHERE fch = %d AND codgas = %d AND codprd = %d AND nrotur = %d AND codtan = %d',
            $est['BaseDatos'], $fch, $codgas, $codprd, $nrotur, $codtan);
        $innerEsc = str_replace("'", "''", $inner);

        $actual = $this->sql->select(sprintf("SELECT * FROM OPENQUERY([%s], '%s');", $est['Servidor'], $innerEsc));
        if (!$actual || count($actual) !== 1) {
            return ['success' => false,
                    'message' => 'El corte no identifica exactamente una fila en la estación (' . count($actual ?: []) . ')'];
        }
        $anterior = (float)$actual[0]['can'];

        $ok = $this->sql->update(
            sprintf("UPDATE OPENQUERY([%s], '%s') SET can = ?, logfch = GETDATE();", $est['Servidor'], $innerEsc),
            [$valor]);
        if ($ok === false) {
            return ['success' => false, 'message' => 'El UPDATE en la estación falló (¿permisos del linked server?)'];
        }

        // Réplica corporativa: SG12.dbo.StockReal concentra todas las
        // estaciones (misma llave + codgas). Se actualiza después de la
        // estación para que otros reportes del central no queden desfasados;
        // si la fila aún no existe en la réplica no es error fatal.
        $sg12 = $this->sql->update(
            'UPDATE [SG12].[dbo].[StockReal] SET can = ?, logfch = GETDATE()
             WHERE fch = ? AND codgas = ? AND codprd = ? AND nrotur = ? AND codtan = ?;',
            [$valor, $fch, $codgas, $codprd, $nrotur, $codtan]);

        $log = $this->sql->insert(
            'INSERT INTO [TG].[dbo].[merma_fisico_log]
                (usuario, codgas, fecha, fch, codprd, nrotur, codtan, valor_anterior, valor_nuevo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
            [$usuario, $codgas, $fecha, $fch, $codprd, $nrotur, $codtan, $anterior, $valor]);

        return ['success' => true, 'anterior' => $anterior, 'sg12' => (bool)$sg12, 'log' => (bool)$log];
    }

    /**
     * Compras de combustible del rango cruzadas contra su recepción física en
     * telemetría: MovimientosTan tiptrn=4 liga nrodoc → folio del documento
     * (tiptrn=3 es la descarga medida). Un doc sin aplicación o con desviación
     * fuerte vs lo medido es compra fantasma/duplicada o telemetría caída.
     * Detectado en el caso López Mateos 04-jul-2026 (docs 5431/5432).
     */
    public function get_compras_vs_recepcion(int $codgas, string $desde, string $hasta): array
    {
        $est = $this->get_estacion_conexion($codgas);
        if (!$est) return [];
        $fchIni = dateToInt($desde);
        $fchFin = dateToInt($hasta);
        $prds   = implode(',', array_merge(...array_values(self::FAMILIAS)));
        $db     = $est['BaseDatos'];
        // Las aplicaciones pueden capturarse días después del doc, nunca antes
        // de la descarga: se acota fchtrn solo por abajo (con colchón)
        $inner = "SELECT m.fch, m.codprd, m.nrotur, m.nro, m.can, m.logfch,
                         t.volrec, ISNULL(t.aplicaciones, 0) AS aplicaciones
                  FROM [$db].dbo.Movimientos m
                  LEFT JOIN (
                      SELECT nrodoc, SUM(volrec) AS volrec, COUNT(*) AS aplicaciones
                      FROM [$db].dbo.MovimientosTan
                      WHERE tiptrn = 4 AND nrodoc <> 0 AND fchtrn >= " . ($fchIni - 10) . "
                      GROUP BY nrodoc
                  ) t ON t.nrodoc = m.nro
                  WHERE m.fch BETWEEN $fchIni AND $fchFin AND m.tip = 11 AND m.can > 0
                    AND m.codprd IN ($prds)";
        $query = sprintf("SELECT * FROM OPENQUERY([%s], '%s') ORDER BY fch, codprd, nro;",
            $est['Servidor'], str_replace("'", "''", $inner));
        $rows = $this->sql->select($query) ?: [];

        // Docs ya excluidos del reporte (la tabla puede no existir todavía)
        $excluidas = [];
        try {
            $ex = $this->sql->select(
                'SELECT fch, codprd, nro_doc FROM [TG].[dbo].[merma_compras_excluidas]
                 WHERE codgas = ? AND fch BETWEEN ? AND ?;', [$codgas, $fchIni, $fchFin]);
            foreach ($ex ?: [] as $e) $excluidas[$e['fch'] . '-' . $e['codprd'] . '-' . $e['nro_doc']] = true;
        } catch (Throwable $e) { /* sin tabla, nada excluido */ }

        $turnoMap = [10 => 11, 11 => 11, 20 => 21, 21 => 21, 30 => 41, 31 => 41, 40 => 41, 41 => 41];
        foreach ($rows as &$r) {
            // fch es serial ControlGas (días desde 1899-12-31)
            $r['fecha'] = date('Y-m-d', strtotime('1899-12-31 +' . (int)$r['fch'] . ' days'));
            $r['turno'] = $turnoMap[(int)$r['nrotur']] ?? 41;
            $r['excluida'] = isset($excluidas[$r['fch'] . '-' . $r['codprd'] . '-' . $r['nro']]);
            $doc = (float)$r['can'];
            $rec = $r['volrec'] !== null ? (float)$r['volrec'] : null;
            if ($rec === null)                                   $r['estado'] = 'sin_recepcion';
            elseif ($doc > 0 && abs($rec - $doc) / $doc > 0.10)  $r['estado'] = 'desviacion';
            else                                                 $r['estado'] = 'ok';
        }
        unset($r);
        return $rows;
    }

    /**
     * Días con lecturas físicas corruptas (fuera del rango plausible) por
     * estación, para marcarlos en el resumen. [codgas => ['YYYY-MM-DD', ...]]
     */
    public function get_corruptas_por_estacion(string $desde, string $hasta): array
    {
        $query = 'SELECT DISTINCT codgas, fecha FROM [TG].[dbo].[merma_diaria]
                  WHERE fecha >= ? AND fecha < DATEADD(DAY, 1, CAST(? AS DATE))
                    AND inv_fisico IS NOT NULL
                    AND (inv_fisico < ' . self::INV_FISICO_MIN . '
                         OR inv_fisico > ' . self::INV_FISICO_MAX . ')
                  ORDER BY codgas, fecha;';
        $rows = $this->sql->select($query, [$desde, $hasta]) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int)$r['codgas']][] = substr($r['fecha'], 0, 10);
        return $out;
    }

    public function get_detalle_rango(int $codgas, string $desde, string $hasta): array
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
                  WHERE codgas = ? AND fecha >= ? AND fecha < DATEADD(DAY, 1, CAST(? AS DATE))
                  GROUP BY fecha, turno
                  ORDER BY fecha, turno;';
        return $this->sql->select($query, [$codgas, $desde, $hasta]) ?: [];
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
        $min = self::INV_FISICO_MIN;
        $max = self::INV_FISICO_MAX;
        $query = "WITH u AS (
                      SELECT codprd, inv_fisico, fecha,
                             ROW_NUMBER() OVER (PARTITION BY codprd
                                                ORDER BY fecha DESC, turno DESC) AS rn
                      FROM [TG].[dbo].[merma_diaria]
                      WHERE codgas = ? AND fecha < ?
                        AND inv_fisico BETWEEN $min AND $max
                  )
                  SELECT MAX(fecha) AS fecha, " . implode(', ', $cols) . "
                  FROM u WHERE rn = 1;";
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

    /**
     * Última fila de merma_sync_log que sí trajo datos (estaciones_ok > 0).
     * Excluye intentos que fallaron por completo (ApiER caído, etc.) para
     * que "última actualización" no muestre un sync que no actualizó nada.
     */
    public function get_ultimo_sync_ok(): ?array
    {
        $rows = $this->sql->select(
            'SELECT TOP 1 fecha_sync, origen
             FROM [TG].[dbo].[merma_sync_log]
             WHERE estaciones_ok > 0
             ORDER BY id DESC;'
        );
        return $rows ? $rows[0] : null;
    }

    /* ===================================================================== */
    /* Reporte de ventas consolidado (/merma/ventas)                         */
    /* ===================================================================== */

    /**
     * Estaciones en orden de columna del reporte: por el número corporativo
     * que Nombre ya trae como prefijo ("02 Lerdo", "38 PRAXEDIS"). TRY_CAST
     * para que ordene como número y no como texto ("10" antes que "2").
     * Difiere de get_estaciones(), que ordena alfabéticamente.
     */
    public function get_estaciones_ordenadas(): array
    {
        $query = 'SELECT e.Codigo, e.Nombre, g.cveest
                  FROM [TG].[dbo].[Estaciones] e
                  LEFT JOIN [SG12].[dbo].[Gasolineras] g ON g.cod = e.Codigo
                  WHERE e.Codigo NOT IN (0, 4, 20)
                  ORDER BY TRY_CAST(LEFT(e.Nombre, 2) AS INT), e.Nombre;';
        return $this->sql->select($query) ?: [];
    }

    /**
     * Matriz día × estación del mes, con las tres familias en columnas.
     * ventas_reales es el mismo "VR" que el Excel jala de Formato<Mes><Año>.xlsm.
     *
     * @return array ['YYYY-MM-DD' => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]
     */
    public function get_ventas_mes(int $anio, int $mes): array
    {
        $primero = sprintf('%04d-%02d-01', $anio, $mes);
        $query = 'SELECT fecha, codgas,
                    ' . $this->familiaCase('maxima', 'ventas_reales') . ' AS maxima,
                    ' . $this->familiaCase('super', 'ventas_reales') . '  AS [super],
                    ' . $this->familiaCase('diesel', 'ventas_reales') . ' AS diesel
                  FROM [TG].[dbo].[merma_diaria]
                  WHERE fecha >= ? AND fecha < DATEADD(MONTH, 1, CAST(? AS DATE))
                  GROUP BY fecha, codgas
                  ORDER BY fecha, codgas;';
        $rows = $this->sql->select($query, [$primero, $primero]) ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $fecha = substr((string) $r['fecha'], 0, 10);
            $out[$fecha][(int) $r['codgas']] = [
                'maxima' => $r['maxima'] === null ? null : (float) $r['maxima'],
                'super'  => $r['super']  === null ? null : (float) $r['super'],
                'diesel' => $r['diesel'] === null ? null : (float) $r['diesel'],
            ];
        }
        return $out;
    }

    /**
     * Totales del mes por estación y familia (sin desglose por día). Se usa
     * para los comparativos % M.A. y % A.A. del reporte de ventas.
     *
     * @return array [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]
     */
    public function get_ventas_totales_mes(int $anio, int $mes): array
    {
        $primero = sprintf('%04d-%02d-01', $anio, $mes);
        $query = 'SELECT codgas,
                    ' . $this->familiaCase('maxima', 'ventas_reales') . ' AS maxima,
                    ' . $this->familiaCase('super', 'ventas_reales') . '  AS [super],
                    ' . $this->familiaCase('diesel', 'ventas_reales') . ' AS diesel
                  FROM [TG].[dbo].[merma_diaria]
                  WHERE fecha >= ? AND fecha < DATEADD(MONTH, 1, CAST(? AS DATE))
                  GROUP BY codgas;';
        $rows = $this->sql->select($query, [$primero, $primero]) ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $out[(int) $r['codgas']] = [
                'maxima' => $r['maxima'] === null ? null : (float) $r['maxima'],
                'super'  => $r['super']  === null ? null : (float) $r['super'],
                'diesel' => $r['diesel'] === null ? null : (float) $r['diesel'],
            ];
        }
        return $out;
    }

    /* ===================================================================== */
    /* Histórico mensual del reporte de ventas (/merma/ventas, pestaña       */
    /* HISTÓRICO). Misma tabla que el reporte diario: el usuario pidió       */
    /* explícitamente que todo salga de merma_diaria y no de SG12.Ventas,    */
    /* para que ninguna pestaña pueda contradecir a otra.                    */
    /* ===================================================================== */

    /**
     * Acumulado mensual por estación y familia, de enero del año $desde a
     * diciembre del año $hasta.
     *
     * @return array [anio => [mes => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]]
     */
    public function get_historico_mensual(int $desde, int $hasta): array
    {
        $ini = sprintf('%04d-01-01', $desde);
        $fin = sprintf('%04d-01-01', $hasta);
        // El filtro va contra "fecha" sin envolverla en una función (para
        // que sea sargable); con las ~30 mil filas de merma_diaria el
        // escaneo completo es irrelevante de cualquier forma. DATEADD sobre
        // el parámetro cierra el rango en el 31 de diciembre del año $hasta.
        $query = 'SELECT YEAR(fecha) AS anio, MONTH(fecha) AS mes, codgas,
                    ' . $this->familiaCase('maxima', 'ventas_reales') . ' AS maxima,
                    ' . $this->familiaCase('super', 'ventas_reales') . '  AS [super],
                    ' . $this->familiaCase('diesel', 'ventas_reales') . ' AS diesel
                  FROM [TG].[dbo].[merma_diaria]
                  WHERE fecha >= ? AND fecha < DATEADD(YEAR, 1, CAST(? AS DATE))
                  GROUP BY YEAR(fecha), MONTH(fecha), codgas
                  ORDER BY anio, mes, codgas;';
        $rows = $this->sql->select($query, [$ini, $fin]) ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $out[(int) $r['anio']][(int) $r['mes']][(int) $r['codgas']] = [
                'maxima' => $r['maxima'] === null ? null : (float) $r['maxima'],
                'super'  => $r['super']  === null ? null : (float) $r['super'],
                'diesel' => $r['diesel'] === null ? null : (float) $r['diesel'],
            ];
        }
        return $out;
    }

    /**
     * Primer año con datos en el snapshot. Alimenta el piso de los selectores
     * de año de la pestaña histórica, de modo que el rango disponible crezca
     * solo conforme se sincronice más historia hacia atrás.
     */
    public function get_anio_min_historico(): ?int
    {
        $rows = $this->sql->select(
            'SELECT MIN(YEAR(fecha)) AS anio FROM [TG].[dbo].[merma_diaria];'
        ) ?: [];
        $v = $rows[0]['anio'] ?? null;
        return $v === null ? null : (int) $v;
    }
}
