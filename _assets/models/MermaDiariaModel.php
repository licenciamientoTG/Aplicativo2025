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

    /** codprd base para captura manual sin conexión (Praxedis, Colosio). */
    public const FAMILIAS_CAPTURA_MANUAL = [
        'maxima' => ['codprd' => 1, 'producto' => 'MAXIMA'],
        'super'  => ['codprd' => 2, 'producto' => 'SUPER'],
        'diesel' => ['codprd' => 3, 'producto' => 'DIESEL'],
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

    /**
     * Tanques físicamente inhabilitados que StockReal sigue reportando junto
     * al tanque real del mismo producto (caso Gemela Grande: tanque 7 real +
     * tanque 78 inhabilitado). Se ignoran en todo el módulo de merma.
     * codgas => [codtan, ...]
     */
    private const TANQUES_INHABILITADOS = [
        2 => [78],
    ];

    /** Clausula SQL " AND codtan NOT IN (...)" para excluir tanques inhabilitados de una estación, o '' si no aplica. */
    private function filtroTanquesInhabilitados(int $codgas): string
    {
        $tanques = self::TANQUES_INHABILITADOS[$codgas] ?? [];
        return $tanques ? ' AND codtan NOT IN (' . implode(',', $tanques) . ')' : '';
    }

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
            $vistos = []; // "fecha-codprd-turno" => true, para rellenar los que ApiER no trajo
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
                $vistos[$f['Fecha'] . '-' . (int)$f['CodProducto'] . '-' . (int)$f['Turno']] = true;
            }
            $this->rellenar_turnos_faltantes($codgas, $estacion, $filas, $vistos);
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
     * ApiER a veces no trae los 3 turnos (11/21/41) de un producto en un día
     * donde el resto de turnos sí llegaron con datos — StockReal se saltó ese
     * corte (caso Villa Ahumada 18-ago: turno 21 de diésel desaparecido). Sin
     * esa fila, recalc_contable() (LAG) salta el turno entero y arrastra sus
     * compras/ventas al turno siguiente, generando una "diferencia" de miles
     * de litros que no es merma real.
     *
     * Se inserta el hueco con ventas=0, compras=0 e inv_fisico=NULL — igual
     * que resuelve el libro amarillo a mano: sin corte físico propio, el
     * turno de relleno no encadena su propio dato, y recalc_contable (ajuste
     * abajo) repite el físico del turno anterior para no romper la cadena.
     * Un producto que de verdad no vende en ningún turno del día (ej. súper
     * en una estación solo-máxima) nunca aparece en $filas para ninguno de
     * sus 3 turnos y por tanto no genera huecos aquí.
     */
    private function rellenar_turnos_faltantes(int $codgas, string $estacion, array $filas, array $vistos): void
    {
        // Fecha+producto que sí reportaron AL MENOS un turno: solo esos
        // productos/día participan del relleno (un producto ausente los 3
        // turnos simplemente no se vende ese día en esa estación).
        $productosPorFecha = []; // "fecha-codprd" => ['producto' => nombre]
        foreach ($filas as $f) {
            if (!in_array((int)$f['Turno'], [11, 21, 41])) continue;
            $clave = $f['Fecha'] . '-' . (int)$f['CodProducto'];
            $productosPorFecha[$clave] = ['fecha' => $f['Fecha'], 'codprd' => (int)$f['CodProducto'], 'producto' => $f['Producto']];
        }

        foreach ($productosPorFecha as $clave => $info) {
            foreach ([11, 21, 41] as $turno) {
                $key = $info['fecha'] . '-' . $info['codprd'] . '-' . $turno;
                if (isset($vistos[$key])) continue;
                $this->sql->insert(
                    'INSERT INTO [TG].[dbo].[merma_diaria]
                     (fecha, codgas, estacion, codprd, producto, turno, ventas_reales,
                      inv_fisico, compras, inv_inicial, inv_contable, diferencia, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 0, NULL, 0, NULL, NULL, NULL, GETDATE());',
                    [$info['fecha'], $codgas, $estacion, $info['codprd'], $info['producto'], $turno]
                );
            }
        }
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
     *
     * Turno de relleno (rellenar_turnos_faltantes: el turno no llegó de
     * ApiER, ventas=0/compras=0/fisico=NULL): igual que resuelve el libro
     * amarillo a mano, se le asigna como físico "efectivo" el físico previo
     * repetido (fis_ok = fis_prev), para que ese turno no rompa el LAG y el
     * turno siguiente encadene desde el dato real más reciente en vez de
     * saltarse el hueco y arrastrar compras/ventas de dos turnos juntas. Su
     * propia diferencia sale ~0 (no representa merma real, es solo relleno).
     *
     * Con codgas = 0 recalcula todas las estaciones (backfill).
     */
    public function recalc_contable(int $codgas = 0): void
    {
        $where  = $codgas > 0 ? 'WHERE codgas = ?' : '';
        $params = $codgas > 0 ? [$codgas] : [];
        $min    = self::INV_FISICO_MIN;
        $max    = self::INV_FISICO_MAX;
        // fis_ok: físico "efectivo" de cada fila — el propio si es plausible,
        // si no (relleno o corrupto) el fis_ok de la fila anterior repetido.
        // LAG normal no sirve para huecos consecutivos (un relleno seguido de
        // otro relleno seguiría dando NULL), así que se arrastra con MAX(...)
        // OVER una partición por "grupo de fisico plausible más reciente"
        // (tantas veces como filas tenga la racha de huecos desde el último
        // corte real): grp = ventana acumulada de fisico NO nulo hasta la
        // fila, y el fis_ok de todo el grupo es el MAX del primer valor real
        // que abrió ese grupo.
        $query = "WITH b AS (
                      SELECT id, codgas, codprd, fecha, turno,
                             CASE WHEN inv_fisico BETWEEN $min AND $max
                                  THEN inv_fisico END AS fis_real
                      FROM [TG].[dbo].[merma_diaria] $where
                  ),
                  g AS (
                      SELECT id,
                             COUNT(fis_real) OVER (
                                 PARTITION BY codgas, codprd
                                 ORDER BY fecha, turno
                                 ROWS UNBOUNDED PRECEDING) AS grp
                      FROM b
                  ),
                  c AS (
                      SELECT b.id, b.codgas, b.codprd, b.fecha, b.turno, b.fis_real,
                             MAX(b.fis_real) OVER (
                                 PARTITION BY b.codgas, b.codprd, g.grp) AS fis_ok
                      FROM b JOIN g ON g.id = b.id
                  ),
                  d AS (
                      SELECT id, fis_ok,
                             LAG(fis_ok) OVER (
                                 PARTITION BY codgas, codprd
                                 ORDER BY fecha, turno) AS fis_prev
                      FROM c
                  )
                  UPDATE m SET
                      inv_inicial  = d.fis_prev,
                      inv_contable = CASE WHEN m.ventas_reales IS NULL THEN NULL ELSE
                                     ROUND(d.fis_prev - ISNULL(m.ventas_reales, 0)
                                          + ISNULL(m.compras, 0), 2) END,
                      diferencia   = CASE WHEN m.ventas_reales IS NULL THEN NULL ELSE
                                     ROUND(d.fis_ok - (d.fis_prev
                                          - ISNULL(m.ventas_reales, 0)
                                          + ISNULL(m.compras, 0)), 2) END
                  FROM [TG].[dbo].[merma_diaria] m
                  JOIN d ON d.id = m.id;";
        $this->sql->update($query, $params);
    }

    /**
     * ¿Ya existe algún corte físico plausible (dentro de INV_FISICO_MIN/MAX)
     * para este codgas/codprd en una fecha estrictamente anterior a $fecha?
     * Usado por la carga manual de Praxedis/Colosio para decidir si hace
     * falta sembrar un día previo con el "Inv Inicial"/"Inicio" que trae el
     * propio PDF (si no, el LAG de recalc_contable no tiene de dónde
     * encadenar y inv_inicial/inv_contable/diferencia quedan en s/d).
     *
     * Con $desdeFecha se acota la búsqueda a partir de esa fecha (inclusive)
     * en vez de "cualquier fecha anterior" — evita que el LAG encadene contra
     * un dato viejo y lejano cuando hay un hueco de captura entre medio
     * (p.ej. Colosio: cierre de julio con hueco hasta mediados de agosto).
     */
    public function existe_fisico_previo(int $codgas, int $codprd, string $fecha, ?string $desdeFecha = null): bool
    {
        $params = [$codgas, $codprd, $fecha];
        $condDesde = '';
        if ($desdeFecha !== null) {
            $condDesde = ' AND fecha >= ?';
            $params[] = $desdeFecha;
        }
        $params[] = self::INV_FISICO_MIN;
        $params[] = self::INV_FISICO_MAX;
        $rs = $this->sql->select(
            "SELECT TOP 1 1 FROM [TG].[dbo].[merma_diaria]
             WHERE codgas = ? AND codprd = ? AND fecha < ?{$condDesde}
               AND inv_fisico BETWEEN ? AND ?;",
            $params);
        return !empty($rs);
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

    /**
     * Subconjunto de $fechas que ya tienen al menos una fila guardada para
     * este codgas. Usado en el preview de cargas manuales por PDF (Praxedis,
     * Colosio) para avisar antes de confirmar que una fecha se sobrescribirá.
     */
    public function fechas_existentes(int $codgas, array $fechas): array
    {
        $fechas = array_values(array_unique($fechas));
        if (empty($fechas)) return [];
        $placeholders = implode(',', array_fill(0, count($fechas), '?'));
        $rows = $this->sql->select(
            "SELECT DISTINCT fecha FROM [TG].[dbo].[merma_diaria]
             WHERE codgas = ? AND fecha IN ({$placeholders});",
            array_merge([$codgas], $fechas)
        ) ?: [];
        return array_map(fn($r) => substr($r['fecha'], 0, 10), $rows);
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
        // Turno mostrado (11/21/41) → nrotur crudos de StockReal, y su inverso
        $turnoNrotur = [11 => '10, 11', 21 => '20, 21', 41 => '40, 41'];
        $turnoMap    = [10 => 11, 20 => 21, 30 => 41, 40 => 41];
        $filtroTurno = isset($turnoNrotur[$turno]) ? " AND nrotur IN ({$turnoNrotur[$turno]})" : '';
        // Tanque 78 de Gemela Grande (codgas 2): inhabilitado, se ignora en
        // todo el módulo de merma (ver self::TANQUES_INHABILITADOS)
        $filtroTanque = $this->filtroTanquesInhabilitados($codgas);
        $inner = sprintf(
            'SELECT fch, codgas, codprd, nrotur, codtan, can, logfch, lognew
             FROM [%s].dbo.StockReal
             WHERE fch = %d AND codgas = %d AND codprd IN (%s) AND nrotur NOT IN (30, 31)%s%s',
            $est['BaseDatos'], $fch, $codgas, $prds, $filtroTurno, $filtroTanque);
        $query = sprintf("SELECT * FROM OPENQUERY([%s], '%s') ORDER BY codprd, nrotur, codtan;",
            $est['Servidor'], str_replace("'", "''", $inner));
        $cortes = $this->sql->select($query) ?: [];

        // Valor sugerido por corte = contable del libro amarillo: último
        // físico válido anterior (encadena días previos, sin importar el
        // turno) − ventas + compras del turno, calculado desde el snapshot
        // local. Ventana de 7 días: alcanza para encadenar el "último físico
        // válido" incluso con turnos intermedios sin dato.
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
            $day = substr($s['fecha'], 0, 10);
            if ($day === $fecha && isset($last[$prd])) {
                $rec[$prd . '-' . (int)$s['turno']] = round(
                    $last[$prd] - (float)$s['ventas_reales'] + (float)($s['compras'] ?? 0), 2);
            }
            $fis = $s['inv_fisico'];
            if ($fis !== null && $fis >= self::INV_FISICO_MIN && $fis <= self::INV_FISICO_MAX) {
                $last[$prd] = (float)$fis;
            }
        }

        // Historial mostrado en el modal: últimos 5 cortes de CADA tanque
        // específico (codprd+nrotur+codtan), leído directo de StockReal (no
        // de merma_diaria, que ya viene sumado por turno) — así una estación
        // con 2 tanques por producto (ej. Satélite) muestra cada tanque por
        // separado en vez de un solo total que mezcla ambos. Con 1 tanque se
        // ve igual que antes (una sola columna). "rn<=5" por tanque, no por
        // día calendario, por la misma razón que el turno: un tanque que
        // reporta poco no debe salir con historial vacío.
        $innerHist = sprintf(
            'SELECT fch, codprd, nrotur, codtan, can FROM [%s].dbo.StockReal
             WHERE fch < %d AND codgas = %d AND codprd IN (%s) AND nrotur NOT IN (30, 31)%s',
            $est['BaseDatos'], $fch, $codgas, $prds, $filtroTanque);
        $queryHist = sprintf(
            "SELECT * FROM (
                 SELECT *, ROW_NUMBER() OVER (PARTITION BY codprd, nrotur, codtan ORDER BY fch DESC) AS rn
                 FROM OPENQUERY([%s], '%s')
             ) u WHERE rn <= 5 ORDER BY codprd, nrotur, codtan, fch DESC;",
            $est['Servidor'], str_replace("'", "''", $innerHist));
        $histRows = $this->sql->select($queryHist) ?: [];

        $historial = [];  // "codprd-turno" => [{fecha, tanques: {codtan: fisico}}, ...] descendente
        $porFechaTurno = [];  // "codprd-turno-fch" => ['fecha'=>, 'tanques'=>[codtan=>fisico]]
        foreach ($histRows as $h) {
            $prd    = (int)$h['codprd'];
            $turno  = $turnoMap[(int)$h['nrotur']] ?? (int)$h['nrotur'];
            $fchDia = (int)$h['fch'];
            $key    = $prd . '-' . $turno . '-' . $fchDia;
            $can    = $h['can'] !== null ? (float)$h['can'] : null;
            $corrupto = $can !== null && ($can < self::INV_FISICO_MIN || $can > self::INV_FISICO_MAX);
            if (!isset($porFechaTurno[$key])) {
                $porFechaTurno[$key] = ['prd' => $prd, 'turno' => $turno, 'fch' => $fchDia, 'tanques' => []];
            }
            $porFechaTurno[$key]['tanques'][(int)$h['codtan']] = $corrupto ? null : $can;
        }
        // Ordena por fch desc dentro de cada codprd-turno y limita a 5 fechas
        // (un tanque corrupto/faltante ya insertó su fecha con valor null,
        // así que el conteo de "5 más recientes" es por fecha, no por fila)
        $porGrupo = [];
        foreach ($porFechaTurno as $row) {
            $porGrupo[$row['prd'] . '-' . $row['turno']][] = $row;
        }
        foreach ($porGrupo as $gk => $rows) {
            usort($rows, fn($a, $b) => $b['fch'] <=> $a['fch']);
            $historial[$gk] = array_map(fn($r) => [
                'fecha'   => intToDate($r['fch']),
                'tanques' => $r['tanques'], // codtan => fisico|null
            ], array_slice($rows, 0, 5));
        }
        // El contable es del TURNO completo (suma de tanques): a cada fila se
        // le sugiere contable - los demás tanques válidos de su mismo corte,
        // para no duplicar el turno en estaciones con varios tanques por
        // producto (ej. Satélite). Gemela Grande ya no cae aquí: su tanque 78
        // inhabilitado se filtra antes, en filtroTanquesInhabilitados().
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
            if ($recTurno === null) { $c['recomendado'] = null; } else {
                $otros = 0.0;
                foreach ($validosPorCorte[$c['codprd'] . '-' . $c['nrotur']] ?? [] as $tan => $can) {
                    if ($tan !== (int)$c['codtan']) $otros += $can;
                }
                $c['recomendado'] = max(0, round($recTurno - $otros, 2));
            }
            // Historial por tanque (ver arriba): [{fecha, tanques:{codtan:fisico|null}}, ...]
            $c['historial'] = $historial[(int)$c['codprd'] . '-' . $turno] ?? [];
        }
        unset($c);

        // Sugerencia retro por tanque — SOLO Satélite (codgas 24) por ahora:
        // es la única estación confirmada con 2 tanques por producto y el
        // patrón de "cae el turno completo, se recupera solo en el
        // siguiente" (ver docs/superpowers/specs de este análisis). Usa el
        // turno SIGUIENTE (ya válido) para retro-calcular hacia atrás:
        // retro_total = físico_total_siguiente + ventas_siguiente − compras_siguiente,
        // repartido entre tanques proporcional al peso de cada uno en el
        // turno siguiente (StockReal no reporta venta/compra por tanque,
        // solo por turno completo, así que el reparto es una aproximación
        // explícita, no un hecho — se etiqueza "recomendado_retro" para no
        // confundirla con "recomendado", que si es exacto).
        if ($codgas === 24) {
            $this->agregarRecomendadoRetro($cortes, $est, $fecha, $turnoMap);
        }

        return $cortes;
    }

    /**
     * Calcula recomendado_retro por tanque para Satélite (ver comentario en
     * get_cortes_fisicos). Muta $cortes por referencia agregando la clave.
     */
    private function agregarRecomendadoRetro(array &$cortes, array $est, string $fecha, array $turnoMap): void
    {
        // Orden cronológico de turnos: 11 → 21 → 41 → 11 del día siguiente
        $secuenciaTurno = [11 => 21, 21 => 41, 41 => 11];

        // Candidatos a "turno siguiente": todos los turnos de merma_diaria
        // en los 5 días posteriores a la fecha pedida, para encontrar el
        // primero con ventas/compras y físico por tanque válidos.
        $siguientes = $this->sql->select(
            'SELECT fecha, codprd, turno, ventas_reales, compras
             FROM [TG].[dbo].[merma_diaria]
             WHERE codgas = 24 AND fecha BETWEEN CAST(? AS DATE) AND DATEADD(DAY, 5, CAST(? AS DATE))
             ORDER BY codprd, fecha, turno;',
            [$fecha, $fecha]) ?: [];
        // "codprd-fecha-turno" => ['ventas'=>, 'compras'=>]
        $ventasCompras = [];
        foreach ($siguientes as $s) {
            $key = (int)$s['codprd'] . '-' . substr($s['fecha'], 0, 10) . '-' . (int)$s['turno'];
            $ventasCompras[$key] = ['ventas' => (float)$s['ventas_reales'], 'compras' => (float)($s['compras'] ?? 0)];
        }

        // Por cada codprd+turno presente en $cortes, busca el primer turno
        // siguiente (misma secuencia 11→21→41→11 del día siguiente...) con
        // físico por tanque válido en TODOS los tanques de ese codprd.
        $codprds = array_unique(array_map(fn($c) => (int)$c['codprd'], $cortes));
        $retroPorGrupo = []; // "codprd-turno" => [codtan => valor]
        foreach ($codprds as $prd) {
            $tanquesProd = array_unique(array_map(fn($c) => (int)$c['codtan'],
                array_filter($cortes, fn($c) => (int)$c['codprd'] === $prd)));
            if (count($tanquesProd) < 2) continue; // solo aplica con 2+ tanques

            foreach ([11, 21, 41] as $turnoOrigen) {
                $filasGrupo = array_filter($cortes, fn($c) => (int)$c['codprd'] === $prd
                    && ($turnoMap[(int)$c['nrotur']] ?? (int)$c['nrotur']) === $turnoOrigen);
                if (!$filasGrupo) continue;
                // Sin ningún tanque dañado en este corte, la sugerencia retro no aporta nada
                $hayCorrupto = false;
                foreach ($filasGrupo as $f) {
                    $canF = (float)$f['can'];
                    if ($canF < self::INV_FISICO_MIN || $canF > self::INV_FISICO_MAX) { $hayCorrupto = true; break; }
                }
                if (!$hayCorrupto) continue;

                $diaOffset = 0;
                $turnoBusca = $turnoOrigen;
                $encontrado = null;
                for ($i = 0; $i < 6; $i++) { // hasta 6 turnos hacia adelante (2 días)
                    $turnoBusca = $secuenciaTurno[$turnoBusca];
                    if ($turnoBusca === 11) $diaOffset++;
                    $fechaBusca = date('Y-m-d', strtotime($fecha . " +{$diaOffset} day"));
                    $key = $prd . '-' . $fechaBusca . '-' . $turnoBusca;
                    if (!isset($ventasCompras[$key])) continue;

                    $fisicoTanques = $this->get_fisico_tanques_dia($est, $fechaBusca, $prd, $turnoBusca, $tanquesProd);
                    if ($fisicoTanques === null) continue; // algún tanque sin valor válido

                    $totalSiguiente = array_sum($fisicoTanques);
                    if ($totalSiguiente <= 0) continue;
                    $retroTotal = $totalSiguiente + $ventasCompras[$key]['ventas'] - $ventasCompras[$key]['compras'];
                    $encontrado = [];
                    foreach ($fisicoTanques as $tan => $val) {
                        $encontrado[$tan] = max(0, round($retroTotal * ($val / $totalSiguiente), 2));
                    }
                    break;
                }
                if ($encontrado !== null) $retroPorGrupo[$prd . '-' . $turnoOrigen] = $encontrado;
            }
        }

        foreach ($cortes as &$c) {
            $can = (float)$c['can'];
            $corrupto = $can < self::INV_FISICO_MIN || $can > self::INV_FISICO_MAX;
            if (!$corrupto) { $c['recomendado_retro'] = null; continue; } // solo tiene sentido si el corte real está dañado
            $turno = $turnoMap[(int)$c['nrotur']] ?? (int)$c['nrotur'];
            $grupo = $retroPorGrupo[(int)$c['codprd'] . '-' . $turno] ?? null;
            $c['recomendado_retro'] = $grupo[(int)$c['codtan']] ?? null;
        }
        unset($c);
    }

    /**
     * Físico por tanque de un codprd+turno en un día dado, leído de
     * StockReal. Retorna null si falta algún tanque de $tanquesEsperados o
     * si alguno está fuera del rango plausible (no sirve como referencia).
     */
    private function get_fisico_tanques_dia(array $est, string $fecha, int $codprd, int $turno, array $tanquesEsperados): ?array
    {
        $turnoNrotur = [11 => '10, 11', 21 => '20, 21', 41 => '40, 41'];
        $fch = dateToInt($fecha);
        $inner = sprintf(
            'SELECT codtan, can FROM [%s].dbo.StockReal
             WHERE fch = %d AND codgas = 24 AND codprd = %d AND nrotur IN (%s)',
            $est['BaseDatos'], $fch, $codprd, $turnoNrotur[$turno]);
        $rows = $this->sql->select(sprintf("SELECT * FROM OPENQUERY([%s], '%s');",
            $est['Servidor'], str_replace("'", "''", $inner))) ?: [];

        $porTanque = [];
        foreach ($rows as $r) $porTanque[(int)$r['codtan']] = (float)$r['can'];

        $out = [];
        foreach ($tanquesEsperados as $tan) {
            $val = $porTanque[$tan] ?? null;
            if ($val === null || $val < self::INV_FISICO_MIN || $val > self::INV_FISICO_MAX) return null;
            $out[$tan] = $val;
        }
        return $out;
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
     * Set "fecha-familia-turno" (familia = maxima/super/diesel, turno
     * normalizado 11/21/41, no el nrotur crudo 10/20/40 de StockReal) de
     * todos los turnos del rango que ya tienen al menos una corrección en
     * merma_fisico_log — para marcar en el detalle qué celdas fueron
     * editadas antes (aunque ya no estén corruptas) y así seguir
     * permitiendo reabrir el modal de corrección sobre ellas.
     */
    public function get_turnos_corregidos(int $codgas, string $desde, string $hasta): array
    {
        $rs = $this->sql->select(
            'SELECT DISTINCT fecha, codprd, nrotur FROM [TG].[dbo].[merma_fisico_log]
             WHERE codgas = ? AND fecha BETWEEN ? AND ?;',
            [$codgas, $desde, $hasta]);
        $turnoMap = [10 => 11, 11 => 11, 20 => 21, 21 => 21, 30 => 41, 31 => 41, 40 => 41, 41 => 41];
        $codprdFamilia = [];
        foreach (self::FAMILIAS as $fam => $codes) {
            foreach ($codes as $c) $codprdFamilia[$c] = $fam;
        }
        $set = [];
        foreach ($rs ?: [] as $r) {
            $fam = $codprdFamilia[(int)$r['codprd']] ?? null;
            if (!$fam) continue;
            $turno = $turnoMap[(int)$r['nrotur']] ?? (int)$r['nrotur'];
            $set[substr($r['fecha'], 0, 10) . '-' . $fam . '-' . $turno] = true;
        }
        return $set;
    }

    /** Bitácora de correcciones de cortes físicos de una estación en un rango, con usuario. */
    public function get_bitacora_fisico(int $codgas, string $desde, string $hasta): array
    {
        return $this->sql->select(
            'SELECT l.fecha_correccion, l.fecha, l.codprd, l.nrotur, l.codtan,
                    l.valor_anterior, l.valor_nuevo, u.Nombre AS usuario_nombre
             FROM [TG].[dbo].[merma_fisico_log] l
             LEFT JOIN [TG].[dbo].[Usuario] u ON u.Id = l.usuario
             WHERE l.codgas = ? AND l.fecha BETWEEN ? AND ?
             ORDER BY l.fecha_correccion DESC;',
            [$codgas, $desde, $hasta]) ?: [];
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
