<?php
/**
 * DebitClientsCierreModel — foto diaria v2 de clientes débito: cierre del día
 * de ayer por cliente, guardada con vigencia en TG.dbo.debit_clients_cierre
 * (una fila nueva solo cuando cambia algún monto) + bitácora de corridas en
 * TG.dbo.debit_clients_cierre_runs.
 * Spec: docs/superpowers/specs/2026-11-11-debit-clients-cierre-diario-design.md
 * Lógica pura (armar fila, cambio, día en medio, días pendientes): DebitCierre.
 *
 * Todas las tablas van con nombre completo ([SG12]/[TG]) para no cambiar la
 * base de la conexión compartida (MySqlPdoHandler es singleton).
 */
require_once __DIR__ . '/../classes/DebitCierre.class.php';

class DebitClientsCierreModel extends Model
{
    /** Tablas y lógica pura; CreditClientsCierreModel las redefine. */
    const TABLA      = '[TG].dbo.debit_clients_cierre';
    const TABLA_RUNS = '[TG].dbo.debit_clients_cierre_runs';
    const LOGICA     = 'DebitCierre';

    /** Columnas que regresa fotoDelDia (además de codcli, cliente, debglo y fechas). */
    const COLUMNAS_FOTO = [
        'saldo_ini_sistema', 'saldo_ini_calc_desp', 'saldo_ini_calc_cortes',
        'ajuste_retro_desp', 'ajuste_retro_cortes',
        'anticipos_dia', 'consumos_desp_dia', 'consumos_cortes_dia', 'desfase_dia',
        'saldo_fin_calc_desp', 'saldo_fin_calc_cortes', 'saldo_fin_sistema', 'saldo_fin_vehiculos',
    ];

    /** Columnas de datos que se insertan/actualizan (sin id, fechas ni updated_at). */
    const COLUMNAS = [
        'codcli', 'cliente', 'debglo',
        'saldo_ini_sistema', 'saldo_ini_calc_desp', 'saldo_ini_calc_cortes',
        'ajuste_retro_desp', 'ajuste_retro_cortes',
        'anticipos_dia', 'consumos_desp_dia', 'consumos_cortes_dia', 'desfase_dia',
        'saldo_fin_calc_desp', 'saldo_fin_calc_cortes', 'saldo_fin_sistema', 'saldo_fin_vehiculos',
        'lectura_debsdo', 'lectura_vehiculos', 'consumos_post_cierre', 'lectura_at',
    ];

    /**
     * Una fila por cliente débito (tipval 4, codest <> -1) para el día $dia
     * ('Y-m-d'): históricos ANTES del día (anticipos, despachos, cortes), los
     * movimientos DEL día y, con $conLectura, la lectura actual de la bolsa,
     * la suma de vehículos y los despachos posteriores al día (para
     * reconstruir el cierre). Sin lectura esas cuatro columnas van NULL.
     */
    public function calcularDia(string $dia, bool $conLectura): array
    {
        $query = "
            SET NOCOUNT ON;
            DECLARE @d int = ?, @lec bit = ?;
            DROP TABLE IF EXISTS #u, #ant, #des, #cor;

            SELECT C.cod, C.den, CAST(ISNULL(C.debglo, 0) AS tinyint) AS debglo,
                   CAST(C.debsdo AS decimal(18,2)) AS debsdo
              INTO #u
              FROM [SG12].dbo.Clientes C WITH (NOLOCK)
             WHERE C.tipval = 4 AND C.codest <> -1;
            CREATE UNIQUE CLUSTERED INDEX ix ON #u (cod);

            -- Anticipos: mismas reglas que Edo. Cuenta Débito y la foto v1
            SELECT t2.codopr AS cod,
                   SUM(CASE WHEN t1.fch <  @d THEN t2.mtoori + t2.mtoiva ELSE 0 END) / 100.0 AS hist,
                   SUM(CASE WHEN t1.fch =  @d THEN t2.mtoori + t2.mtoiva ELSE 0 END) / 100.0 AS dia
              INTO #ant
              FROM [SG12].dbo.DocumentosC t1 WITH (NOLOCK)
              JOIN [SG12].dbo.Documentos t2 WITH (NOLOCK)
                ON t1.nro = t2.nro AND t1.codgas = t2.codgas AND t1.tip = t2.tip
              JOIN #u ON #u.cod = t2.codopr
             WHERE t1.fch <= @d
               AND t2.mtoiva > 0
               AND t2.codprd NOT IN (1,2,3,-64,179,180,181,192,193)
               AND t2.mto > 100
               AND ISNULL(t1.flgcon, 0) <> 141
             GROUP BY t2.codopr
            OPTION (RECOMPILE);

            -- Despachos (Despachos.mto ya en pesos); post = después del día, para la reconstrucción
            SELECT d.codcli AS cod,
                   SUM(CASE WHEN d.fchtrn <  @d THEN d.mto ELSE 0 END) AS hist,
                   SUM(CASE WHEN d.fchtrn =  @d THEN d.mto ELSE 0 END) AS dia,
                   SUM(CASE WHEN d.fchtrn >  @d THEN d.mto ELSE 0 END) AS post
              INTO #des
              FROM [SG12].dbo.Despachos d WITH (NOLOCK)
              JOIN #u ON #u.cod = d.codcli
             GROUP BY d.codcli
            OPTION (RECOMPILE);

            -- Cortes: vales débito (codval 127) por fecha del corte
            SELECT r.codcli AS cod,
                   SUM(CASE WHEN r.fch <  @d THEN r.mto ELSE 0 END) AS hist,
                   SUM(CASE WHEN r.fch =  @d THEN r.mto ELSE 0 END) AS dia
              INTO #cor
              FROM [SG12].dbo.ValesR r WITH (NOLOCK)
              JOIN #u ON #u.cod = r.codcli
             WHERE r.codval = 127 AND r.fch <= @d
             GROUP BY r.codcli
            OPTION (RECOMPILE);

            SELECT u.cod AS codcli, u.den AS cliente, u.debglo,
                   CAST(ISNULL(a.hist, 0) - ISNULL(de.hist, 0) AS decimal(18,2)) AS saldo_ini_calc_desp,
                   CAST(ISNULL(a.hist, 0) - ISNULL(co.hist, 0) AS decimal(18,2)) AS saldo_ini_calc_cortes,
                   CAST(ISNULL(a.dia, 0)  AS decimal(18,2)) AS anticipos_dia,
                   CAST(ISNULL(de.dia, 0) AS decimal(18,2)) AS consumos_desp_dia,
                   CAST(ISNULL(co.dia, 0) AS decimal(18,2)) AS consumos_cortes_dia,
                   CASE WHEN @lec = 1 THEN u.debsdo END AS lectura_debsdo,
                   CASE WHEN @lec = 1 THEN ISNULL(v.s, 0) END AS lectura_vehiculos,
                   CASE WHEN @lec = 1 THEN CAST(ISNULL(de.post, 0) AS decimal(18,2)) END AS consumos_post_cierre,
                   CASE WHEN @lec = 1 THEN CONVERT(varchar(19), GETDATE(), 120) END AS lectura_at
              FROM #u u
              LEFT JOIN #ant a  ON a.cod  = u.cod
              LEFT JOIN #des de ON de.cod = u.cod
              LEFT JOIN #cor co ON co.cod = u.cod
              LEFT JOIN (SELECT codcli, CAST(SUM(debsdo) AS decimal(18,2)) AS s
                           FROM [SG12].dbo.ClientesVehiculos WITH (NOLOCK)
                          GROUP BY codcli) v ON v.codcli = u.cod
             ORDER BY u.cod;

            DROP TABLE IF EXISTS #u, #ant, #des, #cor;";
        return $this->sql->select($query, [dateToInt($dia), $conLectura ? 1 : 0]) ?: [];
    }

    /**
     * Foto de todos los clientes en el día $dia ('Y-m-d'): la fila vigente ese
     * día de cada cliente, proyectada con DebitCierre::filaParaDia (en días
     * posteriores a fecha_desde no hubo movimientos). Clientes sin fila ese
     * día (p. ej. antes de la primera foto) no aparecen. Un día que la
     * bitácora no tiene como procesado OK (aún no corre el cron, falló o se
     * saltó por el tope) regresa vacío: proyectar la vigente lo haría pasar
     * por un día "sin movimientos" que en realidad no se calculó.
     */
    public function fotoDelDia(string $dia): array
    {
        if (!$this->diaProcesado($dia)) {
            return [];
        }
        $rows = $this->sql->select(
            "SELECT codcli, cliente, debglo,
                    CONVERT(varchar(10), fecha_desde, 23) AS fecha_desde,
                    CONVERT(varchar(10), fecha_hasta, 23) AS fecha_hasta,
                    " . implode(', ', static::COLUMNAS_FOTO) . "
               FROM " . static::TABLA . "
              WHERE fecha_desde <= ? AND (fecha_hasta IS NULL OR fecha_hasta >= ?)
              ORDER BY cliente",
            [$dia, $dia]
        ) ?: [];
        $l = static::LOGICA;
        return array_map(fn($r) => $l::filaParaDia($r, $dia), $rows);
    }

    /** ¿El día tiene corrida OK en la bitácora? */
    public function diaProcesado(string $dia): bool
    {
        return (bool)$this->sql->select(
            "SELECT 1 AS x FROM " . static::TABLA_RUNS . " WHERE fecha_dia = ? AND ok = 1", [$dia]
        );
    }

    /** Último día procesado sin error ('Y-m-d') o null. */
    public function ultimaCorridaOk(): ?string
    {
        $r = $this->sql->select(
            "SELECT CONVERT(varchar(10), MAX(fecha_dia), 23) AS f FROM " . static::TABLA_RUNS . " WHERE ok = 1"
        );
        return $r[0]['f'] ?? null;
    }

    /**
     * Corrida diaria: procesa en orden cada día pendiente según la bitácora
     * (DebitCierre::diasPendientes) hasta $ayer. Solo $ayer lleva lectura de
     * saldos (reconstrucción del cierre); los días recuperados van sin ella.
     * Se detiene en el primer día que falle.
     */
    public function procesar(string $ayer): array
    {
        $t0 = microtime(true);
        $ultimoOk = $this->ultimaCorridaOk();

        $dias = [];
        $ok = true;
        $mensaje = null;
        $pendientes = DebitCierre::diasPendientes($ultimoOk, $ayer);
        // Más de TOPE_DIAS pendientes: diasPendientes deja solo $ayer y el hueco queda sin foto; se avisa
        if ($ultimoOk !== null && $pendientes === [$ayer] && $ultimoOk < date('Y-m-d', strtotime($ayer . ' -1 day'))) {
            $mensaje = "Se saltaron los días del " . date('Y-m-d', strtotime($ultimoOk . ' +1 day'))
                     . " al " . date('Y-m-d', strtotime($ayer . ' -1 day'))
                     . " (más de " . DebitCierre::TOPE_DIAS . " días pendientes); quedan sin foto.";
        }
        foreach ($pendientes as $i => $dia) {
            $r = $this->procesarDia($dia, $dia === $ayer);
            $dias[] = $r;
            if (!$r['ok']) {
                $ok = false;
                $faltan = count($pendientes) - $i;
                // Se detiene para no saltarse el orden: los días siguientes esperan a que este pase
                $mensaje = trim(($mensaje ? $mensaje . ' ' : '') . "Falló el día $dia: {$r['mensaje']}. "
                         . "Quedan $faltan día(s) sin procesar hasta $ayer; se reintentan en la siguiente corrida "
                         . "(con más de " . DebitCierre::TOPE_DIAS . " pendientes se saltan).");
                break;
            }
        }
        return [
            'success'      => $ok,
            'dias'         => $dias,
            'duracion_seg' => round(microtime(true) - $t0, 1),
            'mensaje'      => $mensaje,
        ];
    }

    /** Un día: calcula, compara contra la vigente y guarda en una transacción; deja la bitácora. */
    private function procesarDia(string $dia, bool $conLectura): array
    {
        $diaAnterior = date('Y-m-d', strtotime($dia . ' -1 day'));
        $res = ['fecha' => $dia, 'nuevos' => 0, 'actualizados' => 0, 'sin_cambio' => 0, 'cerrados' => 0, 'ok' => false, 'mensaje' => null];

        try {
            if ($this->sql->select("SELECT id FROM " . static::TABLA_RUNS . " WHERE fecha_dia = ?", [$dia])) {
                $this->sql->updateSafe(
                    "UPDATE " . static::TABLA_RUNS . " SET inicio = GETDATE(), fin = NULL, ok = 0, mensaje = NULL WHERE fecha_dia = ?",
                    [$dia]
                );
            } else {
                $this->sql->insert("INSERT INTO " . static::TABLA_RUNS . " (fecha_dia) VALUES (?)", [$dia]);
            }
        } catch (Throwable $e) {
            $res['mensaje'] = 'No se pudo abrir la bitácora: ' . mb_substr($e->getMessage(), 0, 900);
            return $res;
        }

        try {
            $calculado = $this->calcularDia($dia, $conLectura);
            if (!$calculado) {
                throw new Exception("La consulta del día $dia no regresó clientes");
            }
            $vigentes = array_column($this->sql->select(
                "SELECT *, CONVERT(varchar(10), fecha_desde, 23) AS fd FROM " . static::TABLA . " WHERE fecha_hasta IS NULL"
            ) ?: [], null, 'codcli');
            // Para re-correr un día: la "anterior" de una fila abierta ese mismo día es la que cerró el día previo
            $cerradasAyer = array_column($this->sql->select(
                "SELECT * FROM " . static::TABLA . " WHERE fecha_hasta = ?", [$diaAnterior]
            ) ?: [], null, 'codcli');

            $l = static::LOGICA;
            $this->sql->beginTransaction();
            $vistos = [];
            foreach ($calculado as $calc) {
                $codcli = (int)$calc['codcli'];
                $vistos[$codcli] = true;
                $vig = $vigentes[$codcli] ?? null;

                if ($vig !== null && $vig['fd'] === $dia) {
                    $fila = $l::armarFila($calc, $cerradasAyer[$codcli] ?? null);
                    $this->actualizar((int)$vig['id'], $fila);
                    $res['actualizados']++;
                    continue;
                }
                $fila = $l::armarFila($calc, $vig);
                if ($vig === null) {
                    $this->insertar($fila, $dia);
                    $res['nuevos']++;
                } elseif (!$l::hayCambio($vig, $fila)) {
                    $res['sin_cambio']++;
                } else {
                    $this->cerrar((int)$vig['id'], $diaAnterior);
                    $this->insertar($fila, $dia);
                    $res['actualizados']++;
                }
            }
            // Clientes que salieron del universo (baja o ya no son débito)
            foreach ($vigentes as $codcli => $vig) {
                if (!isset($vistos[(int)$codcli])) {
                    $this->cerrar((int)$vig['id'], max($diaAnterior, $vig['fd']));
                    $res['cerrados']++;
                }
            }
            // ok = 1 dentro de la misma transacción que los datos: si no se puede
            // marcar, no se guarda nada (si se guardaran los datos con ok = 0, al
            // día siguiente se reprocesaría como recuperación y la actualización
            // in place borraría la lectura buena de saldo sistema).
            if ($this->marcarCorrida($dia, $res, true, null) === false) {
                throw new Exception("No se pudo marcar la corrida del día $dia en la bitácora");
            }
            $this->sql->commit();
            $res['ok'] = true;
        } catch (Throwable $e) {
            try { $this->sql->rollBack(); } catch (Throwable) {}
            $res['mensaje'] = mb_substr($e->getMessage(), 0, 1000);
            $this->marcarCorrida($dia, $res, false, $res['mensaje']);
        }
        return $res;
    }

    /** Cierra la fila de bitácora del día con sus conteos; regresa false si no se pudo. */
    private function marcarCorrida(string $dia, array $res, bool $ok, ?string $mensaje): int|false
    {
        return $this->sql->updateSafe(
            "UPDATE " . static::TABLA_RUNS . "
                SET fin = GETDATE(), nuevos = ?, actualizados = ?, sin_cambio = ?, cerrados = ?, ok = ?, mensaje = ?
              WHERE fecha_dia = ?",
            [$res['nuevos'], $res['actualizados'], $res['sin_cambio'], $res['cerrados'], $ok ? 1 : 0, $mensaje, $dia]
        );
    }

    private function insertar(array $fila, string $dia): void
    {
        $cols = static::COLUMNAS;
        $params = array_map(fn($c) => $fila[$c], $cols);
        $params[] = $dia;
        $this->sql->insert(
            "INSERT INTO " . static::TABLA . " (" . implode(', ', $cols) . ", fecha_desde)
             VALUES (" . implode(', ', array_fill(0, count($cols) + 1, '?')) . ")",
            $params
        );
    }

    private function actualizar(int $id, array $fila): void
    {
        $cols = array_values(array_diff(static::COLUMNAS, ['codcli']));
        $params = array_map(fn($c) => $fila[$c], $cols);
        $params[] = $id;
        $ok = $this->sql->updateSafe(
            "UPDATE " . static::TABLA . " SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ", updated_at = GETDATE()
              WHERE id = ?",
            $params
        );
        if ($ok === false) {
            throw new Exception("No se pudo actualizar la fila $id");
        }
    }

    private function cerrar(int $id, string $fechaHasta): void
    {
        $ok = $this->sql->updateSafe(
            "UPDATE " . static::TABLA . " SET fecha_hasta = ?, updated_at = GETDATE() WHERE id = ?",
            [$fechaHasta, $id]
        );
        if ($ok === false) {
            throw new Exception("No se pudo cerrar la vigencia de la fila $id");
        }
    }
}
