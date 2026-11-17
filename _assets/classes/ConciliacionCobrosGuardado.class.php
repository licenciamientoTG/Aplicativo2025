<?php
/**
 * Grupos guardables de la conciliación de cobros (Ingresos > Conciliación > Cobros).
 * Sin BD: convierte el resultado de ConciliacionCobros::conciliar() en "grupos"
 * (N cobros, M movimientos y K facturas conciliados entre sí) y los grupos
 * guardados otra vez en las filas que pinta la vista.
 * Pruebas: php tests/ConciliacionCobrosGuardadoTest.php
 *
 * Forma de un grupo:
 *   ['id'=>int|null, 'origen'=>'auto'|'manual', 'estatus'=>string, 'comentario'?, 'creado_por'?,
 *    'items'=>[['tipo'=>'C'|'M'|'F', 'codgas', 'nro', 'movimiento_id', 'monto', 'mes', 'ocupa'], …]]
 */
class ConciliacionCobrosGuardado {

    /** Estatus de grupos auto que se reintentan una vez por día. */
    public const REINTENTAR = ['Sin banco', 'Sin MOV', 'Diferencia', 'Cuadra por importe', 'Cuadra por importe (sin MOV)'];

    public static function claveDoc(int $codgas, int $nro): string {
        return "$codgas|$nro";
    }

    /**
     * Agrupa por depósito + estatus; los cobros sin depósito van uno por grupo.
     * El M de una Diferencia lleva ocupa = 0 (el depósito sigue libre para el cruce).
     */
    public static function gruposDesdeCruce(array $cruce, string $mes): array {
        $grupos = [];
        foreach ($cruce as $r) {
            $depId = $r['deposito_id'] ?? null;
            $estatus = (string)$r['estatus'];
            $itemC = [
                'tipo' => 'C', 'codgas' => (int)$r['codgas'], 'nro' => (int)$r['nro'], 'movimiento_id' => null,
                'monto' => round((float)$r['monto'], 2), 'mes' => substr((string)$r['fecha'], 0, 7), 'ocupa' => 1,
            ];
            if ($depId === null) {
                $grupos[] = ['origen' => 'auto', 'estatus' => $estatus, 'items' => [$itemC]];
                continue;
            }
            $clave = $depId . '|' . $estatus;
            if (!isset($grupos[$clave])) {
                $fechaDep = (string)($r['fecha_banco'] ?? '');
                $grupos[$clave] = ['origen' => 'auto', 'estatus' => $estatus, 'items' => [[
                    'tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => (int)$depId,
                    'monto' => round((float)($r['abono'] ?? 0), 2),
                    'mes' => $fechaDep !== '' ? substr($fechaDep, 0, 7) : $mes,
                    'ocupa' => $estatus === 'Diferencia' ? 0 : 1,
                ]]];
            }
            $grupos[$clave]['items'][] = $itemC;
        }
        return array_values($grupos);
    }

    /** Cobros (del mes) cuya clave no está en ningún item C de los grupos. */
    public static function cobrosSinGrupo(array $cobros, array $grupos): array {
        $usados = [];
        foreach ($grupos as $g) {
            foreach ($g['items'] as $it) {
                if ($it['tipo'] === 'C') $usados[self::claveDoc((int)$it['codgas'], (int)$it['nro'])] = true;
            }
        }
        return array_values(array_filter($cobros, fn($c) => !isset($usados[self::claveDoc((int)$c['codgas'], (int)$c['nro'])])));
    }

    /** @return array<int,true> ids de movimientos tomados (ocupa = 1) */
    public static function movimientosOcupados(array $grupos): array {
        $out = [];
        foreach ($grupos as $g) {
            foreach ($g['items'] as $it) {
                if ($it['tipo'] === 'M' && (int)$it['ocupa'] === 1) $out[(int)$it['movimiento_id']] = true;
            }
        }
        return $out;
    }

    /**
     * Una fila por cobro del grupo (misma forma que arma conciliation_payments) más
     * grupo_id, origen, comentario, creado_por e items_guardados. Un grupo sin cobros
     * da una sola fila con las columnas del cobro vacías y los folios de sus facturas.
     */
    public static function filasDesdeGrupos(array $grupos, array $cobrosPorClave, array $movPorId, array $facturasPorClave): array {
        $filas = [];
        foreach ($grupos as $g) {
            $cs = $ms = $fs = [];
            foreach ($g['items'] as $it) {
                if ($it['tipo'] === 'C') $cs[] = $it;
                elseif ($it['tipo'] === 'M') $ms[] = $it;
                elseif ($it['tipo'] === 'F') $fs[] = $it;
            }

            // movimientos que cuentan: los ocupa = 1; en una Diferencia, el de referencia
            $refs = array_values(array_filter($ms, fn($m) => (int)$m['ocupa'] === 1));
            if (!$refs && $ms) $refs = [$ms[0]];
            $deps = [];
            $abono = 0.0; $secs = [];
            foreach ($refs as $m) {
                $d = $movPorId[(int)$m['movimiento_id']] ?? null;
                $deps[] = $d;
                $abono += $d !== null ? (float)$d['abono'] : (float)$m['monto'];
                if ($d !== null && $d['secuencia'] !== null && $d['secuencia'] !== '') $secs[] = (string)$d['secuencia'];
            }
            $abono = round($abono, 2);
            $primero = $deps[0] ?? null;

            // cobros y facturas del grupo
            $cobrosRef = [];
            foreach ($cs as $it) {
                $cobrosRef[] = $cobrosPorClave[self::claveDoc((int)$it['codgas'], (int)$it['nro'])] ?? null;
            }
            $sumaCobros = 0.0;
            foreach ($cs as $i => $it) $sumaCobros += $cobrosRef[$i] !== null ? (float)$cobrosRef[$i]['monto'] : (float)$it['monto'];
            $sumaCobros = round($sumaCobros, 2);
            $folios = []; $sumaFact = 0.0;
            foreach ($fs as $it) {
                $f = $facturasPorClave[self::claveDoc((int)$it['codgas'], (int)$it['nro'])] ?? null;
                $folios[] = $f !== null ? (string)$f['folio'] : (string)$it['nro'];
                $sumaFact += $f !== null ? (float)$f['total'] : (float)$it['monto'];
            }
            $sumaFact = round($sumaFact, 2);

            $base = $cs ? $sumaCobros : $sumaFact;
            $comun = [
                'mov'             => null,
                'estatus'         => $g['estatus'],
                'deposito_id'     => $refs ? (int)$refs[0]['movimiento_id'] : null,
                'banco'           => $primero['banco'] ?? null,
                'cuenta'          => $primero['cuenta'] ?? null,
                'fecha_banco'     => $primero['fecha'] ?? null,
                'secuencia_banco' => $secs ? implode(', ', $secs) : null,
                'abono'           => $refs ? $abono : null,
                'contraparte'     => $primero['nombre_contraparte'] ?? null,
                'rfc_contraparte' => $primero['rfc_contraparte'] ?? null,
                'detalle_banco'   => $primero['detalle'] ?? null,
                'cobros_mov'      => count($cs),
                'monto_mov'       => $sumaCobros,
                'diferencia'      => $refs ? round($abono - $base, 2) : null,
                'candidatos'      => null,
                'grupo_id'        => $g['id'] ?? null,
                'origen'          => $g['origen'],
                'comentario'      => $g['comentario'] ?? null,
                'creado_por'      => $g['creado_por'] ?? null,
                'items_guardados' => $g['items'],
            ];

            if (!$cs) {
                $filas[] = array_merge($comun, [
                    'codgas' => null, 'nro' => null, 'estacion' => null, 'empresa' => null, 'fecha' => null,
                    'codopr' => null, 'cliente' => '', 'tipo' => null, 'txtref' => null, 'satuid' => null,
                    'monto' => null, 'facturas' => implode(', ', $folios),
                ]);
                continue;
            }
            foreach ($cs as $i => $it) {
                $c = $cobrosRef[$i];
                $filas[] = array_merge($comun, [
                    'codgas'   => (int)$it['codgas'],
                    'nro'      => (int)$it['nro'],
                    'estacion' => $c['estacion'] ?? null,
                    'empresa'  => $c['empresa'] ?? null,
                    'fecha'    => $c['fecha'] ?? null,
                    'codopr'   => $c['codopr'] ?? null,
                    'cliente'  => $c !== null ? ($c['cliente'] ?? '') : '',
                    'tipo'     => $c['tipo'] ?? null,
                    'txtref'   => $c['txtref'] ?? null,
                    'satuid'   => $c['satuid'] ?? null,
                    'monto'    => $c !== null ? (float)$c['monto'] : (float)$it['monto'],
                    'facturas' => $c['facturas'] ?? null,
                    'mov'      => $c['mov'] ?? null,
                ]);
            }
        }
        return $filas;
    }

    /**
     * Grupos viejos (auto, pendientes) contra el cruce nuevo de sus cobros: solo los
     * que tienen algún cobro con otro estatus u otro depósito. 'nuevo' es el grupo
     * (sin id) que lo reemplaza; si el cruce parte el grupo, uno por cada nuevo.
     */
    public static function cambiosReintento(array $gruposViejos, array $cruceNuevo): array {
        $porClave = [];
        foreach ($cruceNuevo as $r) $porClave[self::claveDoc((int)$r['codgas'], (int)$r['nro'])] = $r;

        $out = [];
        foreach ($gruposViejos as $g) {
            $depViejo = null;
            foreach ($g['items'] as $it) {
                if ($it['tipo'] === 'M') { $depViejo = (int)$it['movimiento_id']; break; }
            }
            $cambio = false; $claves = []; $mes = '';
            foreach ($g['items'] as $it) {
                if ($it['tipo'] !== 'C') continue;
                $k = self::claveDoc((int)$it['codgas'], (int)$it['nro']);
                $n = $porClave[$k] ?? null;
                if ($n === null) continue;
                $claves[$k] = true;
                if ($mes === '') $mes = (string)$it['mes'];
                $depNuevo = ($n['deposito_id'] ?? null) === null ? null : (int)$n['deposito_id'];
                if ($n['estatus'] !== $g['estatus'] || $depNuevo !== $depViejo) $cambio = true;
            }
            if (!$cambio) continue;
            $filas = array_values(array_filter($cruceNuevo, fn($r) => isset($claves[self::claveDoc((int)$r['codgas'], (int)$r['nro'])])));
            foreach (self::gruposDesdeCruce($filas, $mes) as $nuevo) {
                $out[] = ['grupo_id' => (int)$g['id'], 'nuevo' => $nuevo];
            }
        }
        return $out;
    }

    /**
     * Bolsa del reintento: $depositos sin los $fijos (id => true, ocupa = 1 en grupos activos que no se
     * reintentan), más los depósitos que ya tenían los pendientes ($actuales) que no estén. Los actuales
     * regresan aunque sean fijos (la referencia ocupa = 0 de una Diferencia), para que la Diferencia
     * se siga viendo igual; gruposReintentables impide que otro estatus se quede con un fijo.
     */
    public static function bolsaReintento(array $depositos, array $actuales, array $fijos): array {
        $out = [];
        foreach ($depositos as $d) {
            if (!isset($fijos[(int)$d['id']])) $out[(int)$d['id']] = $d;
        }
        foreach ($actuales as $d) {
            if (!isset($out[(int)$d['id']])) $out[(int)$d['id']] = $d;
        }
        return array_values($out);
    }

    /**
     * Pendientes que sí se pueden rehacer con el cruce nuevo. Se quedan como están (la alerta lo avisa)
     * los que tienen un cobro cancelado o que ya no existe ($cobrosPorClave), y los que tienen un cobro
     * que en el cruce nuevo toma un depósito fijo con un estatus que no es Diferencia (lo usaría doble).
     */
    public static function gruposReintentables(array $pendientes, array $cruceNuevo, array $cobrosPorClave, array $fijos): array {
        $porClave = [];
        foreach ($cruceNuevo as $r) $porClave[self::claveDoc((int)$r['codgas'], (int)$r['nro'])] = $r;
        return array_values(array_filter($pendientes, function ($g) use ($porClave, $cobrosPorClave, $fijos) {
            foreach ($g['items'] as $it) {
                if ($it['tipo'] !== 'C') continue;
                $k = self::claveDoc((int)$it['codgas'], (int)$it['nro']);
                $c = $cobrosPorClave[$k] ?? null;
                if ($c === null || !empty($c['cancelado'])) return false;
                $n = $porClave[$k] ?? null;
                if ($n !== null && $n['estatus'] !== 'Diferencia' && ($n['deposito_id'] ?? null) !== null && isset($fijos[(int)$n['deposito_id']])) return false;
            }
            return true;
        }));
    }

    /**
     * Grupos nuevos que todavía se pueden guardar: ninguno de sus cobros está ya en un grupo activo
     * ($activos, leídos dentro del candado) y ninguno de sus movimientos con ocupa = 1 está en $movOcupados.
     */
    public static function gruposLibres(array $nuevos, array $activos, array $movOcupados): array {
        $usados = [];
        foreach ($activos as $g) {
            foreach ($g['items'] as $it) {
                if ($it['tipo'] === 'C') $usados[self::claveDoc((int)$it['codgas'], (int)$it['nro'])] = true;
            }
        }
        return array_values(array_filter($nuevos, function ($g) use ($usados, $movOcupados) {
            foreach ($g['items'] as $it) {
                if ($it['tipo'] === 'C' && isset($usados[self::claveDoc((int)$it['codgas'], (int)$it['nro'])])) return false;
                if ($it['tipo'] === 'M' && (int)$it['ocupa'] === 1 && isset($movOcupados[(int)$it['movimiento_id']])) return false;
            }
            return true;
        }));
    }
}
