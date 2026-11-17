<?php
/**
 * Alertas de un grupo de la conciliación de cobros (Ingresos > Conciliación > Cobros).
 * Sin BD: la conciliación manual nunca bloquea, solo avisa. El llamador arma $ctx con los
 * datos frescos; un valor null en ctx['cobros'|'movs'|'facturas'] = "ya no existe".
 * Pruebas: php tests/ConciliacionAlertasTest.php
 *
 * $ctx: ['cobros'=>clave=>fila|null, 'movs'=>id=>fila|null, 'facturas'=>clave=>fila|null,
 *        'otrosGrupos'=>['C|codgas|nro'|'M|id'|'F|codgas|nro' => ['id','origen']],
 *        'cuentasEstacion'=>codgas=>[cuenta=>n], 'mes'=>'Y-m']
 */
class ConciliacionAlertas {

    private static function money(float $x): string {
        return number_format($x, 2);
    }

    /** Palabras de ≥ 4 letras, en minúsculas y sin acentos. */
    private static function palabras(string $s): array {
        $s = strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        preg_match_all('/[a-z]{4,}/', $s, $m);
        return array_values(array_unique($m[0]));
    }

    private static function dias(string $a, string $b): ?int {
        $ta = strtotime($a);
        $tb = strtotime($b);
        if ($ta === false || $tb === false) return null;
        return (int)round(abs($ta - $tb) / 86400);
    }

    public static function deGrupo(array $grupo, array $ctx): array {
        $al = [];
        $items = $grupo['items'] ?? [];
        $origen = (string)($grupo['origen'] ?? '');
        $estatus = (string)($grupo['estatus'] ?? '');
        $mesCtx = (string)($ctx['mes'] ?? '');
        $cobrosCtx = $ctx['cobros'] ?? [];
        $movsCtx = $ctx['movs'] ?? [];
        $factCtx = $ctx['facturas'] ?? [];

        $iC = $iM = $iF = [];
        foreach ($items as $it) {
            if ($it['tipo'] === 'C') $iC[] = $it;
            elseif ($it['tipo'] === 'M') $iM[] = $it;
            elseif ($it['tipo'] === 'F') $iF[] = $it;
        }

        if (!$iC && !$iF) $al[] = 'Sin cobros ni facturas';
        if (!$iM && $origen === 'manual') $al[] = 'Sin movimiento bancario';

        // Filas frescas existentes
        $cobros = []; // "codgas|nro" => fila (dos estaciones pueden tener el mismo nro)
        $nros = [];   // "codgas|nro" => nro
        $movs = [];   // id => fila
        $sumCobros = 0.0;
        $sumFact = 0.0;
        $sumMov = 0.0;

        foreach ($iC as $it) {
            $nro = (int)$it['nro'];
            $k = ConciliacionCobrosGuardado::claveDoc((int)$it['codgas'], $nro);
            $f = $cobrosCtx[$k] ?? null;
            if ($f === null) { $al[] = "Cambió después de conciliar: cobro $nro ya no existe"; continue; }
            if (!empty($f['cancelado'])) {
                // un grupo Cancelado ya se guardó con el cobro cancelado: no cambió nada
                if ($estatus !== 'Cancelado') $al[] = "Cambió después de conciliar: cobro $nro cancelado";
                continue;
            }
            if (abs((float)$f['monto'] - (float)$it['monto']) >= 0.005) {
                $al[] = "Cambió después de conciliar: monto del cobro $nro era $" . self::money((float)$it['monto']) . ', hoy $' . self::money((float)$f['monto']);
            }
            $cobros[$k] = $f;
            $nros[$k] = $nro;
            $sumCobros += (float)$f['monto'];
            $mes = substr((string)($f['fecha'] ?? ''), 0, 7);
            if ($mes === '') $mes = (string)$it['mes'];
            if ($mesCtx !== '' && $mes !== $mesCtx) $al[] = "Cobro de otro mes ($mes)";
        }

        foreach ($iM as $it) {
            $id = (int)$it['movimiento_id'];
            $f = $movsCtx[$id] ?? null;
            if ($f === null) { $al[] = "Cambió después de conciliar: movimiento $id ya no existe"; continue; }
            if (abs((float)$f['abono'] - (float)$it['monto']) >= 0.005) {
                $al[] = "Cambió después de conciliar: monto del movimiento $id era $" . self::money((float)$it['monto']) . ', hoy $' . self::money((float)$f['abono']);
            }
            $movs[$id] = $f;
            // La referencia de una Diferencia lleva ocupa = 0; en el resto suman los de ocupa = 1
            if (($estatus === 'Diferencia') === ((int)$it['ocupa'] === 0)) $sumMov += (float)$f['abono'];
            $mes = substr((string)($f['fecha'] ?? ''), 0, 7);
            if ($mes === '') $mes = (string)$it['mes'];
            if ($mesCtx !== '' && $mes !== $mesCtx) $al[] = "Movimiento de otro mes ($mes)";
        }

        $facturas = [];
        foreach ($iF as $it) {
            $k = ConciliacionCobrosGuardado::claveDoc((int)$it['codgas'], (int)$it['nro']);
            $f = $factCtx[$k] ?? null;
            if ($f === null) { $al[] = "Cambió después de conciliar: factura $k ya no existe"; continue; }
            $folio = (string)$f['folio'];
            if (abs((float)$f['total'] - (float)$it['monto']) >= 0.005) {
                $al[] = "Cambió después de conciliar: monto de la factura $folio era $" . self::money((float)$it['monto']) . ', hoy $' . self::money((float)$f['total']);
            }
            if (!empty($f['cancelada'])) $al[] = "Factura $folio cancelada";
            if ((float)($f['pagada'] ?? 0) >= 0.005) $al[] = "Factura $folio ya pagada por cobros ($" . self::money((float)$f['pagada']) . ')';
            $facturas[$k] = $f;
            $sumFact += (float)$f['total'];
        }

        // Factura de otro cliente (solo si el grupo tiene cobros con los que comparar)
        if ($cobros) {
            $clientes = array_map(fn($c) => self::palabras((string)($c['cliente'] ?? '')), $cobros);
            foreach ($facturas as $f) {
                $pf = self::palabras((string)($f['cliente'] ?? ''));
                $coincide = false;
                foreach ($clientes as $pc) if (array_intersect($pf, $pc)) { $coincide = true; break; }
                if (!$coincide) $al[] = 'Factura ' . $f['folio'] . ' de otro cliente';
            }
        }

        if ($iM) {
            // No cuadra: solo con movimiento; contra cobros, o contra facturas si no hay cobros
            if ($movs) {
                $contra = $iC ? $sumCobros : $sumFact;
                $dif = round($sumMov - $contra, 2);
                if (abs($dif) >= 0.005 && ($iC || $iF)) $al[] = 'No cuadra: diferencia $' . self::money($dif);
            }

            // MOV capturado del cobro vs movimientos elegidos
            $secs = [];
            foreach ($movs as $m) if (($m['secuencia'] ?? null) !== null) $secs[(int)$m['secuencia']] = true;
            foreach ($cobros as $k => $c) {
                $nro = $nros[$k];
                $mov = $c['mov'] ?? null;
                if ($mov !== null && $movs && !isset($secs[(int)$mov])) {
                    $al[] = "El MOV capturado $mov del cobro $nro no es ninguno de los movimientos elegidos";
                }
            }

            // Días entre cobro y el movimiento más cercano
            foreach ($cobros as $k => $c) {
                $nro = $nros[$k];
                $mejor = null; $sec = null;
                foreach ($movs as $m) {
                    $d = self::dias((string)$c['fecha'], (string)$m['fecha']);
                    if ($d !== null && ($mejor === null || $d < $mejor)) { $mejor = $d; $sec = $m['secuencia'] ?? $m['id']; }
                }
                if ($mejor !== null && $mejor > 3) $al[] = "$mejor días entre el cobro $nro y el movimiento $sec";
            }

            // Ordenante vs cliente: en auto solo donde el depósito se eligió por importe (en los demás el MOV ya lo respalda)
            $revisarOrdenante = $origen === 'manual' || str_starts_with($estatus, 'Cuadra por importe') || $estatus === 'Sin MOV';
            $palMovs = [];
            foreach ($movs as $m) {
                $nombre = trim((string)($m['nombre_contraparte'] ?? ''));
                if ($nombre !== '') $palMovs = array_merge($palMovs, self::palabras($nombre));
            }
            if ($palMovs && $revisarOrdenante) {
                $vistos = [];
                foreach ($cobros as $c) {
                    $cli = trim((string)($c['cliente'] ?? ''));
                    if ($cli === '' || isset($vistos[$cli])) continue;
                    $vistos[$cli] = true;
                    if (!array_intersect(self::palabras($cli), $palMovs)) $al[] = "El ordenante del banco no se parece al cliente $cli";
                }
            }

            // Cuentas
            $cuentas = [];
            foreach ($movs as $m) $cuentas[(string)$m['cuenta']] = true;
            if (count($cuentas) > 1) $al[] = 'Movimientos de cuentas distintas';
            $codgases = [];
            foreach ($cobros as $c) $codgases[(int)$c['codgas']] = true;
            $avisadas = [];
            foreach (array_keys($codgases) as $cg) {
                $hab = $ctx['cuentasEstacion'][$cg] ?? [];
                if (!$hab) continue;
                foreach (array_keys($cuentas) as $cu) {
                    if (empty($hab[$cu]) && !isset($avisadas[$cu])) {
                        $avisadas[$cu] = true;
                        $al[] = "Cuenta $cu no habitual para la estación";
                    }
                }
            }
        }

        // Ya estaba en otra conciliación (una vez por grupo distinto)
        $otros = [];
        foreach ($items as $it) {
            $k = $it['tipo'] === 'M' ? 'M|' . (int)$it['movimiento_id'] : $it['tipo'] . '|' . ConciliacionCobrosGuardado::claveDoc((int)$it['codgas'], (int)$it['nro']);
            if (isset($ctx['otrosGrupos'][$k])) {
                $o = $ctx['otrosGrupos'][$k];
                $otros[($o['origen'] ?? '') . '#' . ($o['id'] ?? '')] = true;
            }
        }
        foreach (array_keys($otros) as $o) {
            [$org, $id] = explode('#', $o, 2);
            $al[] = "Ya estaba en otra conciliación ($org #$id)";
        }

        return $al;
    }
}
