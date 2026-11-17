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
     * Grupos que se pintan en el mes: los que tienen algún cobro de ese mes (item.mes); los que no tienen
     * cobros, si alguno de sus movimientos es del mes (o, sin movimientos, alguna de sus facturas). Un grupo
     * que sale en el mes solo por su movimiento o una factura pero tiene cobros de otro mes no se pinta.
     */
    public static function gruposDelMes(array $grupos, string $mes): array {
        return array_values(array_filter($grupos, function ($g) use ($mes) {
            $porTipo = ['C' => [], 'M' => [], 'F' => []];
            foreach ($g['items'] as $it) $porTipo[$it['tipo']][] = (string)$it['mes'];
            $ancla = $porTipo['C'] ?: ($porTipo['M'] ?: $porTipo['F']);
            return in_array($mes, $ancla, true);
        }));
    }

    /**
     * Una fila por cobro del grupo (misma forma que arma conciliation_payments) más
     * grupo_id, origen, comentario, creado_por, items_guardados, tiene_mov y borrado (el cobro ya no
     * está en $cobrosPorClave: sale con el monto guardado). Un grupo sin cobros da una sola fila con
     * las columnas del cobro vacías y los folios de sus facturas. Con $mes, solo salen las filas de
     * los cobros de ese mes (item.mes); las sumas del grupo siguen contando todos sus cobros.
     * Los cobros cancelados no suman en monto_mov ni en diferencia (igual que ConciliacionCobrosManual::totales).
     */
    public static function filasDesdeGrupos(array $grupos, array $cobrosPorClave, array $movPorId, array $facturasPorClave, ?string $mes = null): array {
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
            foreach ($cs as $i => $it) {
                if ($cobrosRef[$i] === null) $sumaCobros += (float)$it['monto'];
                elseif (empty($cobrosRef[$i]['cancelado'])) $sumaCobros += (float)$cobrosRef[$i]['monto'];
            }
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
                'tiene_mov'       => (bool)$ms,
                'borrado'         => false,
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
                if ($mes !== null && (string)$it['mes'] !== $mes) continue;
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
                    'borrado'  => $c === null,
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
     * Plan del reintento diario (sin BD). $pendientes: grupos auto pendientes con id; $cobros: filas frescas
     * de sus cobros (con mov); $depositos: bolsa de depositos(); $actualesPorId: id => fila de los movimientos
     * que ya tienen los pendientes; $fijosDb: id => true, ocupa = 1 en grupos activos que no son pendientes.
     * Solo se rehacen los pendientes elegibles (gruposReintentables); los movimientos ocupa = 1 de los que no
     * se rehacen también son fijos y no entran a la bolsa. Se repite hasta que los elegibles no cambian.
     * @return array{cruce: array, componentes: array} componentes de componentesReintento ya sin conflictos.
     */
    public static function planReintento(array $pendientes, array $cobros, array $depositos, array $actualesPorId, array $fijosDb, string $mes): array {
        $cobrosPorClave = [];
        foreach ($cobros as $c) $cobrosPorClave[self::claveDoc((int)$c['codgas'], (int)$c['nro'])] = $c;
        $elegibles = self::gruposReintentables($pendientes, [], $cobrosPorClave, []);   // sin cancelados ni borrados
        $cruce = [];
        $fijos = $fijosDb;
        for ($vuelta = 0; $vuelta < 10; $vuelta++) {
            $ids = array_flip(array_map(fn($g) => (int)$g['id'], $elegibles));
            $fijos = $fijosDb;
            $actuales = $claves = [];
            foreach ($pendientes as $g) {
                $eleg = isset($ids[(int)$g['id']]);
                foreach ($g['items'] as $it) {
                    if ($it['tipo'] === 'M') {
                        $id = (int)$it['movimiento_id'];
                        if (!$eleg && (int)$it['ocupa'] === 1) $fijos[$id] = true;
                        elseif ($eleg && isset($actualesPorId[$id])) $actuales[$id] = $actualesPorId[$id];
                    } elseif ($it['tipo'] === 'C' && $eleg) {
                        $claves[self::claveDoc((int)$it['codgas'], (int)$it['nro'])] = true;
                    }
                }
            }
            $cobrosEleg = array_values(array_filter($cobros, fn($c) => isset($claves[self::claveDoc((int)$c['codgas'], (int)$c['nro'])])));
            $cruce = $cobrosEleg ? ConciliacionCobros::conciliar($cobrosEleg, self::bolsaReintento($depositos, array_values($actuales), $fijos)) : [];
            $siguen = self::gruposReintentables($elegibles, $cruce, $cobrosPorClave, $fijos);
            if (count($siguen) === count($elegibles)) break;
            $elegibles = $siguen;
        }
        return ['cruce' => $cruce, 'componentes' => self::componentesSinConflicto(self::componentesReintento($elegibles, $cruce, $mes), $fijos)];
    }

    /**
     * Grupos nuevos de los pendientes elegibles, armados con UN solo gruposDesdeCruce (un depósito junta cobros
     * de varios grupos viejos). Componente = grupos viejos unidos por algún grupo nuevo + esos grupos nuevos: se
     * dan de baja y se guardan juntos. Solo regresa los componentes con algún cobro de otro estatus o depósito.
     * @return array<int, array{bajas: int[], viejosM: int[], nuevos: array}> viejosM = movimientos ocupa = 1 de las bajas
     */
    public static function componentesReintento(array $elegibles, array $cruceNuevo, string $mes): array {
        $viejoDe = $antes = $padre = [];
        foreach ($elegibles as $g) {
            $id = (int)$g['id'];
            $padre[$id] = $id;
            $dep = null;
            foreach ($g['items'] as $it) {
                if ($it['tipo'] === 'M') { $dep = (int)$it['movimiento_id']; break; }
            }
            foreach ($g['items'] as $it) {
                if ($it['tipo'] !== 'C') continue;
                $k = self::claveDoc((int)$it['codgas'], (int)$it['nro']);
                $viejoDe[$k] = $id;
                $antes[$k] = [(string)$g['estatus'], $dep];
            }
        }
        $raiz = function (int $x) use (&$padre): int {
            while ($padre[$x] !== $x) $x = $padre[$x];
            return $x;
        };
        $filas = array_values(array_filter($cruceNuevo, fn($r) => isset($viejoDe[self::claveDoc((int)$r['codgas'], (int)$r['nro'])])));
        $nuevos = self::gruposDesdeCruce($filas, $mes);
        $viejosDeNuevo = [];
        foreach ($nuevos as $i => $n) {
            $vs = [];
            foreach ($n['items'] as $it) {
                if ($it['tipo'] === 'C') $vs[] = $viejoDe[self::claveDoc((int)$it['codgas'], (int)$it['nro'])];
            }
            $viejosDeNuevo[$i] = $vs;
            $r0 = $raiz($vs[0]);
            foreach ($vs as $v) $padre[$raiz($v)] = $r0;
        }

        $comps = [];
        foreach ($elegibles as $g) {
            $r = $raiz((int)$g['id']);
            $comps[$r] ??= ['bajas' => [], 'viejosM' => [], 'nuevos' => [], 'cambio' => false];
            $comps[$r]['bajas'][] = (int)$g['id'];
            foreach ($g['items'] as $it) {
                if ($it['tipo'] === 'M' && (int)$it['ocupa'] === 1) $comps[$r]['viejosM'][] = (int)$it['movimiento_id'];
            }
        }
        foreach ($nuevos as $i => $n) $comps[$raiz($viejosDeNuevo[$i][0])]['nuevos'][] = $n;
        foreach ($filas as $f) {
            $k = self::claveDoc((int)$f['codgas'], (int)$f['nro']);
            $dep = ($f['deposito_id'] ?? null) === null ? null : (int)$f['deposito_id'];
            if ([(string)$f['estatus'], $dep] !== $antes[$k]) $comps[$raiz($viejoDe[$k])]['cambio'] = true;
        }

        $out = [];
        foreach ($comps as $c) {
            if (!$c['cambio']) continue;
            sort($c['bajas']);
            unset($c['cambio']);
            $out[] = $c;
        }
        return $out;
    }

    /**
     * Red final antes de guardar: quita los componentes con un movimiento ocupa = 1 que esté en $ocupados o en
     * más de un grupo nuevo. Sus grupos viejos siguen activos, así que sus movimientos (viejosM) quedan ocupados
     * y se vuelve a revisar el resto (cascada).
     */
    public static function componentesSinConflicto(array $componentes, array $ocupados): array {
        do {
            $cuenta = [];
            foreach ($componentes as $c) {
                foreach (array_keys(self::movimientosOcupados($c['nuevos'])) as $m) $cuenta[$m] = ($cuenta[$m] ?? 0) + 1;
            }
            $quedan = [];
            $tirados = false;
            foreach ($componentes as $c) {
                $choca = false;
                foreach (array_keys(self::movimientosOcupados($c['nuevos'])) as $m) {
                    if ($cuenta[$m] > 1 || isset($ocupados[$m])) { $choca = true; break; }
                }
                if (!$choca) { $quedan[] = $c; continue; }
                $tirados = true;
                foreach ($c['viejosM'] as $m) $ocupados[$m] = true;
            }
            $componentes = $quedan;
        } while ($tirados && $componentes);
        return $componentes;
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
