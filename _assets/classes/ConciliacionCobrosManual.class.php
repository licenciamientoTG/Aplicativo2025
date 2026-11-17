<?php
/**
 * Conciliación manual de cobros (modal de Ingresos > Conciliación > Cobros). Sin BD: valida los items
 * que manda la vista, les pone monto y mes de los datos frescos (nunca del POST) y calcula los totales.
 * Un item normalizado: ['tipo'=>'C'|'M'|'F', 'codgas'=>int|null, 'nro'=>int|null, 'movimiento_id'=>int|null].
 * Pruebas: php tests/ConciliacionCobrosManualTest.php
 */
class ConciliacionCobrosManual {

    public const MAX_ITEMS = 300;
    public const MAX_COMENTARIO = 500;
    public const ESTATUS = 'Conciliado manual';

    /**
     * JSON del POST ([{tipo, codgas, nro, movimiento_id}, …]) → items normalizados sin repetidos.
     * Lanza InvalidArgumentException si no es una lista válida o pasa de MAX_ITEMS.
     */
    public static function items(mixed $json): array {
        $json = trim((string)$json);
        if ($json === '') return [];
        $lista = json_decode($json, true);
        if (!is_array($lista) || !array_is_list($lista)) throw new InvalidArgumentException('Los items no son una lista válida');
        if (count($lista) > self::MAX_ITEMS) throw new InvalidArgumentException('Máximo ' . self::MAX_ITEMS . ' items por conciliación');
        $out = [];
        foreach ($lista as $x) {
            if (!is_array($x)) throw new InvalidArgumentException('Item inválido');
            $tipo = (string)($x['tipo'] ?? '');
            if ($tipo === 'M') {
                $id = intval($x['movimiento_id'] ?? 0);
                if ($id <= 0) throw new InvalidArgumentException('Movimiento sin id');
                $out['M|' . $id] = ['tipo' => 'M', 'codgas' => null, 'nro' => null, 'movimiento_id' => $id];
            } elseif ($tipo === 'C' || $tipo === 'F') {
                $codgas = intval($x['codgas'] ?? -1);
                $nro = intval($x['nro'] ?? 0);
                if ($codgas < 0 || $nro <= 0) throw new InvalidArgumentException(($tipo === 'C' ? 'Cobro' : 'Factura') . ' sin estación o folio');
                $out[$tipo . '|' . $codgas . '|' . $nro] = ['tipo' => $tipo, 'codgas' => $codgas, 'nro' => $nro, 'movimiento_id' => null];
            } else {
                throw new InvalidArgumentException('Tipo de item inválido');
            }
        }
        return array_values($out);
    }

    /** Claves por tipo: C y F "codgas|nro", M ids. */
    public static function claves(array $items): array {
        $out = ['C' => [], 'M' => [], 'F' => []];
        foreach ($items as $it) {
            $out[$it['tipo']][] = $it['tipo'] === 'M'
                ? (int)$it['movimiento_id']
                : ConciliacionCobrosGuardado::claveDoc((int)$it['codgas'], (int)$it['nro']);
        }
        return $out;
    }

    /** Clave de un item: 'C|codgas|nro', 'M|id' o 'F|codgas|nro' (la de ConciliacionCobrosContexto::otrosGrupos). */
    public static function claveItem(array $it): string {
        return $it['tipo'] === 'M'
            ? 'M|' . (int)$it['movimiento_id']
            : $it['tipo'] . '|' . ConciliacionCobrosGuardado::claveDoc((int)$it['codgas'], (int)$it['nro']);
    }

    /** Comentario opcional: recortado, '' → null; lanza si pasa de MAX_COMENTARIO caracteres. */
    public static function comentario(mixed $texto): ?string {
        $texto = trim((string)$texto);
        if ($texto === '') return null;
        if (mb_strlen($texto, 'UTF-8') > self::MAX_COMENTARIO) {
            throw new InvalidArgumentException('El comentario pasa de ' . self::MAX_COMENTARIO . ' caracteres');
        }
        return $texto;
    }

    /**
     * Items con monto y mes de los datos frescos ($cobros / $facturas por "codgas|nro", $movs por id) y ocupa = 1
     * (un manual siempre ocupa). Lanza InvalidArgumentException con la lista de los que no existen.
     */
    public static function completar(array $items, array $cobros, array $movs, array $facturas): array {
        $out = $faltan = [];
        foreach ($items as $it) {
            if ($it['tipo'] === 'M') {
                $f = $movs[(int)$it['movimiento_id']] ?? null;
                if ($f === null) { $faltan[] = 'movimiento ' . (int)$it['movimiento_id']; continue; }
                $monto = (float)$f['abono'];
            } else {
                $k = ConciliacionCobrosGuardado::claveDoc((int)$it['codgas'], (int)$it['nro']);
                $f = ($it['tipo'] === 'C' ? $cobros : $facturas)[$k] ?? null;
                if ($f === null) {
                    $faltan[] = ($it['tipo'] === 'C' ? 'cobro ' : 'factura ') . (int)$it['nro'] . ' (estación ' . (int)$it['codgas'] . ')';
                    continue;
                }
                $monto = (float)($it['tipo'] === 'C' ? $f['monto'] : $f['total']);
            }
            $out[] = $it + ['monto' => round($monto, 2), 'mes' => substr((string)$f['fecha'], 0, 7), 'ocupa' => 1];
        }
        if ($faltan) throw new InvalidArgumentException('No se encontró: ' . implode(', ', $faltan));
        return $out;
    }

    /**
     * Meses ('Y-m') cuyo candado toma el guardado manual: el del POST, los de los items elegidos y los de los
     * items del grupo que se edita (otro guardado de esos meses podría tomar los mismos cobros). Ordenados y sin repetir.
     */
    public static function mesesABloquear(string $mes, array $items, ?array $editado): array {
        $meses = [$mes];
        foreach (array_merge($items, $editado['items'] ?? []) as $it) $meses[] = (string)($it['mes'] ?? '');
        $meses = array_values(array_unique(array_filter($meses, fn($m) => preg_match('/^\d{4}-\d{2}$/', $m) === 1)));
        sort($meses);
        return $meses;
    }

    /** Grupo manual listo para guardarGrupos. */
    public static function grupo(array $items, ?string $comentario): array {
        return ['origen' => 'manual', 'estatus' => self::ESTATUS, 'comentario' => $comentario, 'items' => $items];
    }

    /**
     * Totales con datos frescos: cobros (sin cancelados), facturas y movimientos. La diferencia es movimientos
     * contra cobros; si no hay cobros, contra facturas (con cobros, las facturas son solo referencia, igual que
     * ConciliacionAlertas).
     */
    public static function totales(array $items, array $cobros, array $movs, array $facturas): array {
        $sC = $sF = $sM = 0.0;
        $hayC = false;
        foreach ($items as $it) {
            if ($it['tipo'] === 'M') {
                $sM += (float)($movs[(int)$it['movimiento_id']]['abono'] ?? 0);
                continue;
            }
            $k = ConciliacionCobrosGuardado::claveDoc((int)$it['codgas'], (int)$it['nro']);
            if ($it['tipo'] === 'C') {
                $hayC = true;
                $c = $cobros[$k] ?? null;
                if ($c !== null && empty($c['cancelado'])) $sC += (float)$c['monto'];
            } else {
                $sF += (float)($facturas[$k]['total'] ?? 0);
            }
        }
        $sC = round($sC, 2);
        $sF = round($sF, 2);
        $sM = round($sM, 2);
        return ['cobros' => $sC, 'facturas' => $sF, 'movimientos' => $sM, 'diferencia' => round($sM - ($hayC ? $sC : $sF), 2)];
    }
}
