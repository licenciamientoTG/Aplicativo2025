<?php
/**
 * ConciliacionCobrosGuardadoModel — conciliación de cobros guardada y manual (TG).
 * Tablas: TG.dbo.conciliacion_cobros_mes / _grupo / _item (docs/sql/conciliacion_cobros_ligas_schema.sql).
 * Forma de grupo/item: ConciliacionCobrosGuardado (header de la clase).
 *
 * Los métodos que escriben varias filas (guardarGrupos, darDeBaja, bajaAutoDelMes) NO abren transacción:
 * el controlador las envuelve. Las listas IN van en línea ya saneadas (intval): SG12 está en nivel de
 * compatibilidad 110, sin STRING_SPLIT, y se parten en bloques de hasta 1000.
 * Las lecturas que deciden escrituras (mesGuardado, gruposActivos, gruposQueOcupan, movimientosOcupadosEntre)
 * van sin NOLOCK: el controlador las repite dentro de bloquearMes() para no guardar dos veces lo mismo.
 */
class ConciliacionCobrosGuardadoModel extends Model {

    private const MES = 'TG.dbo.conciliacion_cobros_mes';
    private const GRUPO = 'TG.dbo.conciliacion_cobros_grupo';
    private const ITEM = 'TG.dbo.conciliacion_cobros_item';

    /** Fila de conciliacion_cobros_mes o null si el mes no se ha guardado. */
    public function mesGuardado(string $mes): ?array {
        $query = "SELECT mes,
                         CONVERT(varchar(19), guardado_en, 120) AS guardado_en, guardado_por,
                         CONVERT(varchar(19), recalculado_en, 120) AS recalculado_en, recalculado_por,
                         CONVERT(varchar(10), reintento_fecha, 23) AS reintento_fecha
                    FROM " . self::MES . "
                   WHERE mes = ?";
        $r = $this->sql->select($query, [$mes]);
        return $r ? $r[0] : null;
    }

    /** Reserva el mes (el primero que lo logra lo guarda). false si ya existía (PK). */
    public function reservarMes(string $mes, string $usuario): bool {
        $query = "INSERT INTO " . self::MES . " (mes, guardado_por, reintento_fecha)
                  VALUES (?, ?, CAST(GETDATE() AS date))";
        try {
            $this->sql->insert($query, [$mes, $usuario]);
            return true;
        } catch (Exception $e) {
            // el handler oculta el código de error: si la fila ya existe fue la PK, si no se relanza
            if ($this->mesGuardado($mes) !== null) return false;
            throw $e;
        }
    }

    /** true si este llamado es el primero del día en reintentar los pendientes del mes. */
    public function tomarReintento(string $mes): bool {
        $query = "UPDATE " . self::MES . " SET reintento_fecha = CAST(GETDATE() AS date)
                   WHERE mes = ? AND (reintento_fecha IS NULL OR reintento_fecha < CAST(GETDATE() AS date))";
        $n = $this->sql->updateSafe($query, [$mes]);
        return $n !== false && $n > 0;
    }

    public function marcarRecalculado(string $mes, string $usuario): void {
        $query = "UPDATE " . self::MES . " SET recalculado_en = GETDATE(), recalculado_por = ? WHERE mes = ?";
        if ($this->sql->updateSafe($query, [$usuario, $mes]) === false) {
            throw new Exception('No se pudo marcar el mes como recalculado');
        }
    }

    /**
     * Inserta grupos con sus items (sin transacción propia). Devuelve los ids de grupo en el mismo orden.
     * $grupos: [['origen','estatus','comentario','items'=>[['tipo','codgas','nro','movimiento_id','monto','mes','ocupa'], …]], …]
     */
    public function guardarGrupos(array $grupos, string $mes, string $usuario): array {
        $ids = [];
        foreach ($grupos as $g) {
            $comentario = isset($g['comentario']) && $g['comentario'] !== '' ? (string)$g['comentario'] : null;
            $id = (int)$this->sql->insert(
                "INSERT INTO " . self::GRUPO . " (mes, origen, estatus, comentario, creado_por) VALUES (?, ?, ?, ?, ?)",
                [$mes, (string)$g['origen'], (string)$g['estatus'], $comentario, $usuario]
            );
            if ($id <= 0) throw new Exception('No se obtuvo el id del grupo');
            $ids[] = $id;
            foreach (array_chunk($g['items'] ?? [], 200) as $lote) {
                $marcas = [];
                $params = [];
                foreach ($lote as $it) {
                    $marcas[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                    array_push($params,
                        $id,
                        (string)$it['tipo'],
                        isset($it['codgas']) ? (int)$it['codgas'] : null,
                        isset($it['nro']) ? (int)$it['nro'] : null,
                        isset($it['movimiento_id']) ? (int)$it['movimiento_id'] : null,
                        round((float)$it['monto'], 2),
                        (string)($it['mes'] ?? $mes),
                        !empty($it['ocupa']) ? 1 : 0
                    );
                }
                if (!$marcas) continue;
                $this->sql->insert(
                    "INSERT INTO " . self::ITEM . " (grupo_id, tipo, codgas, nro, movimiento_id, monto, mes, ocupa) VALUES " . implode(', ', $marcas),
                    $params
                );
            }
        }
        return $ids;
    }

    /** Grupos activos con algún item de ese mes (item.mes), cada uno con todos sus items. */
    public function gruposActivos(string $mes): array {
        $query = "SELECT g.id AS grupo_id, g.mes AS grupo_mes, g.origen, g.estatus, g.comentario, g.creado_por,
                         CONVERT(varchar(19), g.creado_en, 120) AS creado_en,
                         i.tipo, i.codgas, i.nro, i.movimiento_id, i.monto, i.mes AS item_mes, i.ocupa
                    FROM " . self::GRUPO . " g
                    JOIN " . self::ITEM . " i ON i.grupo_id = g.id
                   WHERE g.activo = 1
                     AND EXISTS (SELECT 1 FROM " . self::ITEM . " x WHERE x.grupo_id = g.id AND x.mes = ?)
                   ORDER BY g.id, i.id";
        $grupos = [];
        foreach ($this->sql->select($query, [$mes]) ?: [] as $r) {
            $id = (int)$r['grupo_id'];
            if (!isset($grupos[$id])) {
                $grupos[$id] = [
                    'id' => $id,
                    'mes' => $r['grupo_mes'],
                    'origen' => $r['origen'],
                    'estatus' => $r['estatus'],
                    'comentario' => $r['comentario'],
                    'creado_por' => $r['creado_por'],
                    'creado_en' => $r['creado_en'],
                    'items' => [],
                ];
            }
            $grupos[$id]['items'][] = [
                'tipo' => $r['tipo'],
                'codgas' => $r['codgas'] !== null ? (int)$r['codgas'] : null,
                'nro' => $r['nro'] !== null ? (int)$r['nro'] : null,
                'movimiento_id' => $r['movimiento_id'] !== null ? (int)$r['movimiento_id'] : null,
                'monto' => (float)$r['monto'],
                'mes' => $r['item_mes'],
                'ocupa' => (int)$r['ocupa'],
            ];
        }
        return array_values($grupos);
    }

    /**
     * Ids de grupos activos que tienen alguno de estos items con ocupa = 1.
     * $clavesC / $clavesF: "codgas|nro"; $movIds: movimientos_bancarios.id.
     */
    public function gruposQueOcupan(array $clavesC, array $movIds, array $clavesF): array {
        $partes = [];
        foreach (['C' => $clavesC, 'F' => $clavesF] as $tipo => $claves) {
            foreach ($this->condicionesDoc($claves) as $cond) {
                $partes[] = "(i.tipo = '{$tipo}' AND {$cond})";
            }
        }
        $movIds = array_values(array_unique(array_filter(array_map('intval', $movIds), fn($m) => $m > 0)));
        foreach (array_chunk($movIds, 1000) as $bloque) {
            $partes[] = "(i.tipo = 'M' AND i.movimiento_id IN (" . implode(',', $bloque) . "))";
        }
        $ids = [];
        // un SELECT por cada 20 condiciones para no armar un OR enorme
        foreach (array_chunk($partes, 20) as $grupoPartes) {
            $query = "SELECT DISTINCT g.id
                        FROM " . self::ITEM . " i
                        JOIN " . self::GRUPO . " g ON g.id = i.grupo_id
                       WHERE g.activo = 1 AND i.ocupa = 1
                         AND (" . implode(' OR ', $grupoPartes) . ")";
            foreach ($this->sql->select($query) ?: [] as $r) $ids[(int)$r['id']] = (int)$r['id'];
        }
        $ids = array_values($ids);
        sort($ids);
        return $ids;
    }

    /** Da de baja los grupos (activo = 0). Sin transacción propia. Devuelve cuántos estaban activos y se dieron de baja. */
    public function darDeBaja(array $ids, string $usuario): int {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        $n = 0;
        foreach (array_chunk($ids, 1000) as $bloque) {
            $query = "UPDATE " . self::GRUPO . " SET activo = 0, baja_en = GETDATE(), baja_por = ?
                       WHERE activo = 1 AND id IN (" . implode(',', $bloque) . ")";
            $r = $this->sql->updateSafe($query, [$usuario]);
            if ($r === false) throw new Exception('No se pudo dar de baja los grupos');
            $n += (int)$r;
        }
        return $n;
    }

    /** Da de baja los grupos automáticos creados desde ese mes (para recalcular). Sin transacción propia. Devuelve cuántos. */
    public function bajaAutoDelMes(string $mes, string $usuario): int {
        $query = "UPDATE " . self::GRUPO . " SET activo = 0, baja_en = GETDATE(), baja_por = ?
                   WHERE activo = 1 AND origen = 'auto' AND mes = ?";
        $r = $this->sql->updateSafe($query, [$usuario, $mes]);
        if ($r === false) throw new Exception('No se pudo dar de baja los grupos automáticos del mes');
        return (int)$r;
    }

    /**
     * Candado exclusivo del mes hasta que termine la transacción abierta (sp_getapplock, espera hasta 30 s).
     * Toda escritura de grupos de un mes empieza con él; lanza si no se obtiene.
     */
    public function bloquearMes(string $mes): void {
        $r = $this->sql->select("SET NOCOUNT ON; DECLARE @r int;
            EXEC @r = sys.sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 30000;
            SELECT @r AS r;", ['conc_cobros_' . $mes]);
        if (!$r || (int)$r[0]['r'] < 0) {
            throw new Exception('Otro usuario está guardando la conciliación de cobros de ' . $mes . '; intenta de nuevo');
        }
    }

    /**
     * De estos movimientos, los que ya ocupa (ocupa = 1) algún grupo activo de cualquier mes, salvo los
     * grupos de $excluirGrupos (los que se van a rehacer).
     * @return array<int, true>
     */
    public function movimientosOcupadosEntre(array $movIds, array $excluirGrupos = []): array {
        $movIds = array_values(array_unique(array_filter(array_map('intval', $movIds), fn($i) => $i > 0)));
        $excluir = array_values(array_unique(array_filter(array_map('intval', $excluirGrupos), fn($i) => $i > 0)));
        $sinGrupos = '';
        foreach (array_chunk($excluir, 1000) as $bloque) $sinGrupos .= " AND g.id NOT IN (" . implode(',', $bloque) . ")";
        $out = [];
        foreach (array_chunk($movIds, 1000) as $bloque) {
            $query = "SELECT DISTINCT i.movimiento_id
                        FROM " . self::ITEM . " i
                        JOIN " . self::GRUPO . " g ON g.id = i.grupo_id
                       WHERE g.activo = 1 AND i.ocupa = 1 AND i.tipo = 'M'
                         AND i.movimiento_id IN (" . implode(',', $bloque) . "){$sinGrupos}";
            foreach ($this->sql->select($query) ?: [] as $r) $out[(int)$r['movimiento_id']] = true;
        }
        return $out;
    }

    /** Movimientos bancarios por id, con las mismas columnas que ConciliacionVentaModel::depositos(). */
    public function movimientosPorId(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
        $filas = [];
        foreach (array_chunk($ids, 1000) as $bloque) {
            $query = "SELECT id, banco, cuenta, CONVERT(varchar(10), fecha, 23) AS fecha, secuencia, abono,
                             nombre_contraparte, rfc_contraparte,
                             LEFT(COALESCE(NULLIF(descripcion_larga, ''), NULLIF(concepto, ''), descripcion), 300) AS detalle
                        FROM TG.dbo.movimientos_bancarios WITH (NOLOCK)
                       WHERE id IN (" . implode(',', $bloque) . ")
                       ORDER BY cuenta, fecha, secuencia";
            $filas = array_merge($filas, $this->sql->select($query) ?: []);
        }
        return $filas;
    }

    /**
     * Buscador de abonos del banco. $f: importe (exacto), importe_min, importe_max, desde, hasta ('Y-m-d'),
     * cuenta, secuencia, texto (contraparte / detalle). TOP 200 con el grupo activo que lo ocupa (si hay).
     * Sin ningún filtro regresa [].
     */
    public function buscarMovimientos(array $f): array {
        $conds = ['m.abono > 0'];
        $params = [];
        $hay = false;
        if (isset($f['importe']) && $f['importe'] !== '') {
            $conds[] = 'ABS(m.abono - ?) < 0.005'; $params[] = round((float)$f['importe'], 2); $hay = true;
        }
        if (isset($f['importe_min']) && $f['importe_min'] !== '') {
            $conds[] = 'm.abono >= ?'; $params[] = round((float)$f['importe_min'], 2); $hay = true;
        }
        if (isset($f['importe_max']) && $f['importe_max'] !== '') {
            $conds[] = 'm.abono <= ?'; $params[] = round((float)$f['importe_max'], 2); $hay = true;
        }
        if (!empty($f['desde'])) { $conds[] = 'm.fecha >= ?'; $params[] = (string)$f['desde']; $hay = true; }
        if (!empty($f['hasta'])) { $conds[] = 'm.fecha <= ?'; $params[] = (string)$f['hasta']; $hay = true; }
        if (!empty($f['cuenta'])) { $conds[] = 'm.cuenta = ?'; $params[] = (string)$f['cuenta']; $hay = true; }
        if (isset($f['secuencia']) && $f['secuencia'] !== '') { $conds[] = 'm.secuencia = ?'; $params[] = (int)$f['secuencia']; $hay = true; }
        $texto = trim((string)($f['texto'] ?? ''));
        if ($texto !== '') {
            $conds[] = "(m.nombre_contraparte LIKE ? OR m.rfc_contraparte LIKE ? OR COALESCE(NULLIF(m.descripcion_larga, ''), NULLIF(m.concepto, ''), m.descripcion) LIKE ?)";
            array_push($params, '%' . $texto . '%', '%' . $texto . '%', '%' . $texto . '%');
            $hay = true;
        }
        if (!$hay) return [];
        $query = "SELECT TOP 200 m.id, m.banco, m.cuenta, CONVERT(varchar(10), m.fecha, 23) AS fecha, m.secuencia, m.abono,
                         m.nombre_contraparte, m.rfc_contraparte,
                         LEFT(COALESCE(NULLIF(m.descripcion_larga, ''), NULLIF(m.concepto, ''), m.descripcion), 300) AS detalle,
                         o.grupo_id, o.origen, o.mes_grupo
                    FROM TG.dbo.movimientos_bancarios m WITH (NOLOCK)
                    OUTER APPLY (SELECT TOP 1 g.id AS grupo_id, g.origen, g.mes AS mes_grupo
                                   FROM " . self::ITEM . " i WITH (NOLOCK)
                                   JOIN " . self::GRUPO . " g WITH (NOLOCK) ON g.id = i.grupo_id
                                  WHERE i.tipo = 'M' AND i.movimiento_id = m.id AND i.ocupa = 1 AND g.activo = 1
                                  ORDER BY g.id) o
                   WHERE " . implode(' AND ', $conds) . "
                   ORDER BY m.fecha DESC, m.cuenta, m.secuencia";
        return $this->sql->select($query, $params) ?: [];
    }

    /**
     * Abonos de esas cuentas entre dos fechas 'Y-m-d' que no están ocupando ningún grupo activo
     * (item M con ocupa = 1). Mismas columnas que depositos().
     */
    public function depositosSinConciliar(string $desde, string $hasta, array $cuentas): array {
        $cuentas = array_values(array_unique(array_filter(array_map(fn($c) => preg_replace('/[^0-9A-Za-z]/', '', (string)$c), $cuentas), fn($c) => $c !== '')));
        if (!$cuentas) return [];
        $query = "SELECT m.id, m.banco, m.cuenta, CONVERT(varchar(10), m.fecha, 23) AS fecha, m.secuencia, m.abono,
                         m.nombre_contraparte, m.rfc_contraparte,
                         LEFT(COALESCE(NULLIF(m.descripcion_larga, ''), NULLIF(m.concepto, ''), m.descripcion), 300) AS detalle
                    FROM TG.dbo.movimientos_bancarios m WITH (NOLOCK)
                   WHERE m.fecha BETWEEN ? AND ?
                     AND m.abono > 0
                     AND m.cuenta IN ('" . implode("','", $cuentas) . "')
                     AND NOT EXISTS (SELECT 1 FROM " . self::ITEM . " i WITH (NOLOCK)
                                       JOIN " . self::GRUPO . " g WITH (NOLOCK) ON g.id = i.grupo_id
                                      WHERE i.tipo = 'M' AND i.movimiento_id = m.id AND i.ocupa = 1 AND g.activo = 1)
                   ORDER BY m.cuenta, m.fecha, m.secuencia";
        return $this->sql->select($query, [$desde, $hasta]) ?: [];
    }

    /** "codgas|nro" -> condiciones "(i.codgas = X AND i.nro IN (...))" en bloques de hasta 1000 (enteros saneados). */
    private function condicionesDoc(array $claves): array {
        $porGas = [];
        foreach ($claves as $k) {
            $p = explode('|', (string)$k);
            if (count($p) !== 2 || (int)$p[1] === 0) continue;
            $porGas[(int)$p[0]][(int)$p[1]] = (int)$p[1];
        }
        $conds = [];
        foreach ($porGas as $gas => $nros) {
            foreach (array_chunk(array_values($nros), 1000) as $bloque) {
                $conds[] = "(i.codgas = {$gas} AND i.nro IN (" . implode(',', $bloque) . "))";
            }
        }
        return $conds;
    }
}
