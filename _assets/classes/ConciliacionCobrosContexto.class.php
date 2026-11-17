<?php
/**
 * Contexto para ConciliacionAlertas::deGrupo en la conciliación de cobros guardada
 * (Income::conciliation_payments). Sin BD: se arma de lo ya leído (grupos activos,
 * movimientos y facturas frescos), sin consultas extra.
 * Pruebas: php tests/ConciliacionCobrosContextoTest.php
 */
class ConciliacionCobrosContexto {

    /**
     * Cuentas que "suele usar" cada estación: las de los movimientos (ocupa = 1) de los grupos
     * que cuadraron o se conciliaron a mano, una vez por cada estación de sus cobros.
     * @return array<int, array<string, int>> codgas => [cuenta => grupos]
     */
    public static function cuentasEstacion(array $grupos, array $movPorId): array {
        $out = [];
        foreach ($grupos as $g) {
            $estatus = (string)$g['estatus'];
            if (!str_starts_with($estatus, 'Cuadra') && $estatus !== 'Conciliado manual') continue;
            $codgases = [];
            foreach ($g['items'] as $it) {
                if ($it['tipo'] === 'C') $codgases[(int)$it['codgas']] = true;
            }
            foreach ($g['items'] as $it) {
                if ($it['tipo'] !== 'M' || (int)$it['ocupa'] !== 1) continue;
                $m = $movPorId[(int)$it['movimiento_id']] ?? null;
                if ($m === null) continue;
                $cuenta = (string)$m['cuenta'];
                foreach (array_keys($codgases) as $cg) $out[$cg][$cuenta] = ($out[$cg][$cuenta] ?? 0) + 1;
            }
        }
        return $out;
    }

    /**
     * Items (ocupa = 1) que están en 2 o más grupos activos: para cada grupo, la clave del item
     * ('C|codgas|nro', 'M|id', 'F|codgas|nro') y el primer otro grupo que lo tiene. Normalmente vacío.
     * @return array<int, array<string, array{id:int, origen:string}>> grupo_id => [clave => otro grupo]
     */
    public static function otrosGrupos(array $grupos): array {
        $porClave = [];
        foreach ($grupos as $g) {
            foreach ($g['items'] as $it) {
                if ((int)$it['ocupa'] !== 1) continue;
                $k = $it['tipo'] === 'M'
                    ? 'M|' . (int)$it['movimiento_id']
                    : $it['tipo'] . '|' . ConciliacionCobrosGuardado::claveDoc((int)$it['codgas'], (int)$it['nro']);
                $porClave[$k][(int)$g['id']] = ['id' => (int)$g['id'], 'origen' => (string)$g['origen']];
            }
        }
        $out = [];
        foreach ($porClave as $k => $gs) {
            if (count($gs) < 2) continue;
            foreach (array_keys($gs) as $id) {
                foreach ($gs as $otroId => $otro) {
                    if ($otroId !== $id) { $out[$id][$k] = $otro; break; }
                }
            }
        }
        return $out;
    }

    /**
     * Facturas del grupo (clave => fila de facturasPorClave) con 'pagada' sin lo que pagan los cobros
     * del mismo grupo ('pagos' = "codgas|nro:monto,…"): que el propio cobro pague la factura no es alerta.
     * Las que ya no existen no se incluyen (para la alerta son null).
     */
    public static function facturasDelGrupo(array $grupo, array $facturasPorClave): array {
        $propios = [];
        foreach ($grupo['items'] as $it) {
            if ($it['tipo'] === 'C') $propios[ConciliacionCobrosGuardado::claveDoc((int)$it['codgas'], (int)$it['nro'])] = true;
        }
        $out = [];
        foreach ($grupo['items'] as $it) {
            if ($it['tipo'] !== 'F') continue;
            $k = ConciliacionCobrosGuardado::claveDoc((int)$it['codgas'], (int)$it['nro']);
            $f = $facturasPorClave[$k] ?? null;
            if ($f === null) continue;
            $propio = 0.0;
            foreach (array_filter(explode(',', (string)($f['pagos'] ?? ''))) as $p) {
                [$cobro, $mto] = array_pad(explode(':', $p, 2), 2, 0);
                if (isset($propios[$cobro])) $propio += (float)$mto;
            }
            $f['pagada'] = max(0.0, round((float)($f['pagada'] ?? 0) - $propio, 2));
            $out[$k] = $f;
        }
        return $out;
    }
}
