<?php
/**
 * Cruce de cobros de ControlGas contra depósitos del banco (Ingresos > Conciliación).
 * Sin BD: recibe los cobros (ConciliacionVentaModel::cobros) y los depósitos
 * (ConciliacionVentaModel::depositos_para_cobros) y le pone a cada cobro su
 * estatus y el depósito que le corresponde. Pruebas: php tests/ConciliacionCobrosTest.php
 *
 * El MOV del cobro ("@N:MOV.<n>" en txtref) es la secuencia del estado de
 * cuenta, pero se captura a mano: hay MOV compartidos por cobros de depósitos
 * distintos, MOV intercambiados entre vecinos y diferencias de centavos. Por eso
 * el cruce va en pasadas, de la más segura a la menos segura; un depósito se usa
 * una sola vez:
 *
 *   1. Cuadra            — la suma de los cobros del MOV = abono con esa secuencia.
 *   2. Cuadra (centavos) — igual, con diferencia de hasta $1.
 *   3. Cuadra (MOV compartido) — en un MOV con varios cobros, los que juntos
 *                          (uno o varios) son exactamente el abono de esa secuencia.
 *   4. Cuadra (MOV vecinos) — 2 o 3 MOV consecutivos cuyos cobros suman lo mismo
 *                          que sus abonos (cobros repartidos o intercambiados).
 *   5. Cuadra por importe — abono libre del mismo importe a ±3 días (se prefiere
 *                          la cuenta del MOV y la secuencia más cercana).
 *   Lo que queda: Diferencia (hay abono con esa secuencia pero no cuadra) o
 *   Sin banco. Además: Cancelado y Sin MOV (que también pasan por la regla 5).
 */
class ConciliacionCobros {

    public const TOL_EXACTO   = 0.005;
    public const TOL_CENTAVOS = 1.00;
    public const DIAS_IMPORTE = 3;
    public const DIAS_ANTES   = 60;
    public const DIAS_DESPUES = 15;
    public const MAX_VECINOS  = 3;
    public const MAX_SUBCONJUNTO = 14;

    /** @var array<int, array> */
    private array $cobros;
    /** @var array<int|string, array> depósitos por id */
    private array $depositos = [];
    /** @var array<int, array<int|string>> ids de depósito por secuencia */
    private array $porSecuencia = [];
    /** @var array<int|string, bool> */
    private array $usados = [];

    /**
     * $cobros: filas con nro, codgas, fecha (Y-m-d), monto, mov (int|null), cancelado.
     * $depositos: filas con id, banco, cuenta, fecha (Y-m-d), secuencia, abono,
     *             nombre_contraparte, rfc_contraparte, detalle.
     * Regresa los cobros en el mismo orden con: estatus, deposito_id, banco, cuenta,
     * fecha_banco, abono, contraparte, rfc_contraparte, detalle_banco, diferencia,
     * cobros_mov (cuántos cobros comparten el depósito) y monto_mov (su suma).
     */
    public static function conciliar(array $cobros, array $depositos): array {
        $c = new self($cobros, $depositos);
        return $c->correr();
    }

    private function __construct(array $cobros, array $depositos) {
        $this->cobros = array_values(array_map(function ($r) {
            $r['mov']   = ($r['mov'] ?? null) === null || $r['mov'] === '' ? null : (int)$r['mov'];
            $r['monto'] = round((float)($r['monto'] ?? 0), 2);
            $r['estatus'] = null;
            return $r;
        }, $cobros));
        foreach ($depositos as $d) {
            $d['abono'] = round((float)$d['abono'], 2);
            $this->depositos[$d['id']] = $d;
            if ($d['secuencia'] !== null && $d['secuencia'] !== '') {
                $this->porSecuencia[(int)$d['secuencia']][] = $d['id'];
            }
        }
    }

    private function correr(): array {
        $grupos = [];
        foreach ($this->cobros as $i => $r) {
            if (!empty($r['cancelado'])) {
                $this->marcar([$i], 'Cancelado', null);
            } elseif ($r['mov'] !== null) {
                $grupos[$r['mov']][] = $i;
            }
        }
        ksort($grupos);

        // 1 y 2: el MOV completo contra el abono de su secuencia
        foreach ($grupos as $mov => $idx) {
            $suma = $this->suma($idx);
            $dep = $this->mejorPorSecuencia($mov, $idx, $suma);
            if (!$dep) continue;
            $dif = abs($dep['abono'] - $suma);
            if ($dif <= self::TOL_EXACTO)        $this->marcar($idx, 'Cuadra', $dep);
            elseif ($dif <= self::TOL_CENTAVOS)  $this->marcar($idx, 'Cuadra (centavos)', $dep);
        }

        // 3: MOV con varios cobros, una parte de ellos (uno o varios) es el abono exacto
        foreach ($grupos as $mov => $idx) {
            $pend = $this->pendientes($idx);
            if (count($idx) < 2 || !$pend) continue;
            $dep = $this->mejorPorSecuencia($mov, $pend, $this->suma($pend));
            $sub = $dep ? $this->subconjunto($pend, $dep['abono']) : null;
            if ($sub) $this->marcar($sub, 'Cuadra (MOV compartido)', $dep);
        }

        // 4: MOV consecutivos que juntos cuadran
        $movs = array_keys($grupos);
        foreach ($movs as $mov) {
            for ($k = 1; $k < self::MAX_VECINOS; $k++) {
                if (!$this->pendientes($grupos[$mov])) break;
                $idx = []; $deps = []; $ok = true;
                for ($m = $mov; $m <= $mov + $k; $m++) {
                    $pend = isset($grupos[$m]) ? $this->pendientes($grupos[$m]) : [];
                    $dep = $pend ? $this->mejorPorSecuencia($m, $pend, $this->suma($pend)) : null;
                    if (!$pend || !$dep) { $ok = false; break; }
                    $idx[$m] = $pend; $deps[$m] = $dep;
                }
                if (!$ok) continue;
                $sumaCobros = array_sum(array_map(fn($p) => $this->suma($p), $idx));
                $sumaAbonos = array_sum(array_map(fn($d) => $d['abono'], $deps));
                if (abs($sumaCobros - $sumaAbonos) <= self::TOL_EXACTO) {
                    foreach ($idx as $m => $p) $this->marcar($p, 'Cuadra (MOV vecinos)', $deps[$m], round($sumaAbonos - $sumaCobros, 2));
                    break;
                }
            }
        }

        // 5: por importe y fecha (también los que no traen MOV)
        foreach ($this->cobros as $i => $r) {
            if ($r['estatus'] !== null) continue;
            $dep = $this->mejorPorImporte($i);
            if ($dep) $this->marcar([$i], $r['mov'] === null ? 'Cuadra por importe (sin MOV)' : 'Cuadra por importe', $dep);
        }

        // Lo que queda: si al quitar los que cuadraron por importe el resto ya es
        // el abono, cuadra; si hay abono libre con esa secuencia, Diferencia; si no
        // (no existe o ya lo tomó otro cobro), Sin banco.
        foreach ($grupos as $mov => $idx) {
            $pend = $this->pendientes($idx);
            if (!$pend) continue;
            $suma = $this->suma($pend);
            $dep = $this->mejorPorSecuencia($mov, $pend, $suma);
            $dif = $dep ? abs($dep['abono'] - $suma) : null;
            if ($dep && $dif <= self::TOL_EXACTO)       $this->marcar($pend, 'Cuadra', $dep);
            elseif ($dep && $dif <= self::TOL_CENTAVOS) $this->marcar($pend, 'Cuadra (centavos)', $dep);
            else                                        $this->marcar($pend, $dep ? 'Diferencia' : 'Sin banco', $dep);
        }
        foreach ($this->cobros as $i => $r) {
            if ($r['estatus'] === null) $this->marcar([$i], 'Sin MOV', null);
        }
        return $this->cobros;
    }

    private function suma(array $idx): float {
        return round(array_sum(array_map(fn($i) => $this->cobros[$i]['monto'], $idx)), 2);
    }

    private function pendientes(array $idx): array {
        return array_values(array_filter($idx, fn($i) => $this->cobros[$i]['estatus'] === null));
    }

    /**
     * Subconjunto de los cobros $idx cuya suma es $objetivo (el más grande si
     * hay varios), o null. Fuerza bruta: solo con hasta MAX_SUBCONJUNTO cobros.
     */
    private function subconjunto(array $idx, float $objetivo): ?array {
        $n = count($idx);
        if ($n > self::MAX_SUBCONJUNTO) return null;
        $mejor = null;
        $objetivo = (int)round($objetivo * 100);
        $cent = array_map(fn($i) => (int)round($this->cobros[$i]['monto'] * 100), $idx);
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $s = 0; $sel = [];
            for ($b = 0; $b < $n; $b++) {
                if ($mask & (1 << $b)) { $s += $cent[$b]; $sel[] = $idx[$b]; }
            }
            if ($s === $objetivo && ($mejor === null || count($sel) > count($mejor))) $mejor = $sel;
        }
        return $mejor;
    }

    /** Días entre dos fechas Y-m-d (b − a). */
    private static function dias(string $a, string $b): int {
        return (int)round((strtotime($b) - strtotime($a)) / 86400);
    }

    /**
     * Depósito con secuencia $mov dentro de la ventana de los cobros $idx, el de
     * importe más parecido a $monto y luego el de fecha más cercana. $aunUsado:
     * para mostrar el depósito en una Diferencia aunque ya lo haya tomado otro.
     */
    private function mejorPorSecuencia(int $mov, array $idx, float $monto, bool $aunUsado = false): ?array {
        $fechas = array_map(fn($i) => $this->cobros[$i]['fecha'], $idx);
        $min = min($fechas); $max = max($fechas);
        $mejor = null; $clave = null;
        foreach ($this->porSecuencia[$mov] ?? [] as $id) {
            if (!$aunUsado && isset($this->usados[$id])) continue;
            $d = $this->depositos[$id];
            if (self::dias($min, $d['fecha']) < -self::DIAS_ANTES || self::dias($max, $d['fecha']) > self::DIAS_DESPUES) continue;
            $k = [isset($this->usados[$id]) ? 1 : 0, round(abs($d['abono'] - $monto), 2), abs(self::dias($min, $d['fecha']))];
            if ($clave === null || $k < $clave) { $mejor = $d; $clave = $k; }
        }
        return $mejor;
    }

    /** Depósito libre del mismo importe a ±DIAS_IMPORTE; prefiere la cuenta del MOV y la secuencia más cercana. */
    private function mejorPorImporte(int $i): ?array {
        $r = $this->cobros[$i];
        if ($r['monto'] <= 0) return null;
        $cuentaMov = null;
        if ($r['mov'] !== null) {
            foreach ($this->porSecuencia[$r['mov']] ?? [] as $id) { $cuentaMov = $this->depositos[$id]['cuenta']; break; }
        }
        $mejor = null; $clave = null;
        foreach ($this->depositos as $id => $d) {
            if (isset($this->usados[$id]) || abs($d['abono'] - $r['monto']) > self::TOL_EXACTO) continue;
            $dd = abs(self::dias($r['fecha'], $d['fecha']));
            if ($dd > self::DIAS_IMPORTE) continue;
            $dist = ($r['mov'] !== null && $d['secuencia'] !== null && $d['secuencia'] !== '') ? abs((int)$d['secuencia'] - $r['mov']) : PHP_INT_MAX;
            $k = [$cuentaMov !== null && $d['cuenta'] === $cuentaMov ? 0 : 1, $dist, $dd];
            if ($clave === null || $k < $clave) { $mejor = $d; $clave = $k; }
        }
        return $mejor;
    }

    private function marcar(array $idx, string $estatus, ?array $dep, ?float $diferencia = null): void {
        $suma = $this->suma($idx);
        if ($dep && $estatus !== 'Diferencia') $this->usados[$dep['id']] = true;
        foreach ($idx as $i) {
            $this->cobros[$i] = array_merge($this->cobros[$i], [
                'estatus'         => $estatus,
                'deposito_id'     => $dep['id'] ?? null,
                'banco'           => $dep['banco'] ?? null,
                'cuenta'          => $dep['cuenta'] ?? null,
                'fecha_banco'     => $dep['fecha'] ?? null,
                'secuencia_banco' => $dep['secuencia'] ?? null,
                'abono'           => $dep['abono'] ?? null,
                'contraparte'     => $dep['nombre_contraparte'] ?? null,
                'rfc_contraparte' => $dep['rfc_contraparte'] ?? null,
                'detalle_banco'   => $dep['detalle'] ?? null,
                'cobros_mov'      => count($idx),
                'monto_mov'       => $suma,
                'diferencia'      => $dep ? ($diferencia ?? round($dep['abono'] - $suma, 2)) : null,
            ]);
        }
    }
}
