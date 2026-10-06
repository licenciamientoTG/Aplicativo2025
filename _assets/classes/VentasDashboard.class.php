<?php

/**
 * Pestaña RESUMEN DIRECCIÓN de /merma/ventas: consolida las tres zonas en
 * los indicadores que un director lee primero (avance vs. presupuesto,
 * crecimiento real vs. año anterior, zonas, estaciones a vigilar, mezcla de
 * producto) y los cruza con compras y merma del mismo snapshot
 * (merma_diaria).
 *
 * No consulta la BD: todos los números de venta salen de
 * VentasConsolidado::construir() sobre el mismo contexto que usan las
 * pestañas por zona, así que el resumen no puede contradecir las tablas.
 */
class VentasDashboard
{
    /** Cuántas estaciones se muestran en cada ranking. */
    private const TOP = 5;

    /** Pestañas de producto que forman la mezcla (sin los agregados). */
    private const MIX = ['regular', 'premium', 'diesel'];

    /**
     * @param array $ctx Mismo contexto que VentasConsolidado::construir()
     *   (estaciones = TODAS, cada una con 'zona' => clave de ZONAS), más:
     *   'merma'  => [codgas => ['merma'=>?float,'venta'=>?float,'compras'=>?float]]
     *   'precio' => float  precio por litro para valorizar la merma
     *   'norma'  => float  % de merma permitido (el mismo del Análisis)
     */
    public static function construir(array $ctx): array
    {
        $consolidado = VentasConsolidado::construir('total', $ctx);
        $res         = $consolidado['resumen'];
        $diasDelMes  = $consolidado['dias_del_mes'];
        $diasConDatos = $consolidado['dias_con_datos'];

        // --- Estaciones: una fila plana por estación con lo que se rankea ---
        $estaciones = [];
        foreach ($ctx['estaciones'] as $e) {
            $cod   = (int) $e['Codigo'];
            $refAa = self::totalFamilias($ctx['anio_anterior'][$cod] ?? null);
            $estaciones[$cod] = [
                'codgas'   => $cod,
                'nombre'   => $e['Nombre'],
                'zona'     => VentasConsolidado::ZONAS[$e['zona']]['label'],
                'litros'   => $res['total']['celdas'][$cod],
                'proy'     => $res['proy']['celdas'][$cod],
                'avance'   => self::avance($res['pct_ppto']['celdas'][$cod]),
                'aa'       => $res['aa']['celdas'][$cod],
                // Sin venta el mismo mes del año pasado: estación nueva (o sin
                // historia sincronizada). No entra al crecimiento "mismas
                // estaciones" ni al ranking de crecimiento.
                'nueva'    => $refAa === null || $refAa == 0.0,
                'ref_aa'   => $refAa,
            ];
        }

        // --- Crecimiento con mismas estaciones (excluye las nuevas) ---
        $proyMismas = null;
        $refMismas  = null;
        foreach ($estaciones as $s) {
            if ($s['nueva'] || $s['proy'] === null) continue;
            $proyMismas = ($proyMismas ?? 0.0) + $s['proy'];
            $refMismas  = ($refMismas ?? 0.0) + $s['ref_aa'];
        }
        $nuevas = array_values(array_filter($estaciones, fn($s) => $s['nueva'] && $s['litros'] !== null));

        // --- Ritmo: cuánto hay que vender por día para llegar al presupuesto ---
        $ppto  = $res['ppto']['total'];
        $total = $res['total']['total'];
        $diasRestantes = $diasDelMes - $diasConDatos;
        $ritmoPpto      = $ppto !== null ? $ppto / $diasDelMes : null;
        $ritmoNecesario = ($ppto !== null && $total !== null && $diasRestantes > 0)
            ? max(0.0, $ppto - $total) / $diasRestantes : null;
        $ritmoActual    = ($total !== null && $diasConDatos > 0) ? $total / $diasConDatos : null;

        $diario = [];
        foreach ($consolidado['dias'] as $d) {
            $diario[] = ['dia' => $d['dia'], 'nombre' => $d['nombre'], 'total' => $d['total']];
        }

        $kpis = [
            'litros'          => $total,
            'proy'            => $res['proy']['total'],
            'ppto'            => $ppto,
            'avance'          => self::avance($res['pct_ppto']['total']),
            'aa'              => $res['aa']['total'],
            'aa_mismas'       => self::pctCambio($proyMismas, $refMismas),
            'ma'              => $res['ma']['total'],
            'vs_semana'       => $res['vs_semana']['total'],
            'dias_con_datos'  => $diasConDatos,
            'dias_del_mes'    => $diasDelMes,
            'ritmo_actual'    => $ritmoActual,
            'ritmo_ppto'      => $ritmoPpto,
            'ritmo_necesario' => $ritmoNecesario,
        ];

        // --- Rankings ---
        $conAvance = array_filter($estaciones, fn($s) => $s['avance'] !== null);
        usort($conAvance, fn($a, $b) => $b['avance'] <=> $a['avance']);
        $conAa = array_filter($estaciones, fn($s) => !$s['nueva'] && $s['aa'] !== null);
        usort($conAa, fn($a, $b) => $b['aa'] <=> $a['aa']);

        $ranking = [
            'avance_mejores' => array_slice($conAvance, 0, self::TOP),
            'avance_peores'  => array_reverse(array_slice($conAvance, -self::TOP)),
            'aa_mejores'     => array_slice($conAa, 0, self::TOP),
            'aa_peores'      => array_reverse(array_slice($conAa, -self::TOP)),
            'bajo_ppto'      => count(array_filter($conAvance, fn($s) => $s['avance'] < 100)),
            'con_ppto'       => count($conAvance),
        ];

        // --- Mezcla de producto ---
        $mix = [];
        foreach (self::MIX as $clave) {
            $p = VentasConsolidado::construir($clave, $ctx)['resumen'];
            $mix[] = [
                'clave'  => $clave,
                'label'  => VentasConsolidado::PESTANAS[$clave]['label'],
                'litros' => $p['total']['total'],
                'pct'    => ($total && $p['total']['total'] !== null) ? $p['total']['total'] / $total * 100 : null,
                'avance' => self::avance($p['pct_ppto']['total']),
                'aa'     => $p['aa']['total'],
            ];
        }

        // --- Zonas ---
        $zonas = [];
        foreach (VentasConsolidado::ZONAS as $zonaClave => $info) {
            $ctxZona = $ctx;
            $ctxZona['estaciones'] = array_values(array_filter(
                $ctx['estaciones'], fn($e) => $e['zona'] === $zonaClave));
            $codZona = array_map(fn($e) => (int) $e['Codigo'], $ctxZona['estaciones']);
            $z = VentasConsolidado::construir('total', $ctxZona)['resumen'];
            $mz = self::merma($ctx['merma'], $codZona, $ctx['norma']);
            $zonas[] = [
                'clave'      => $zonaClave,
                'label'      => $info['label'],
                'estaciones' => count($codZona),
                'litros'     => $z['total']['total'],
                'proy'       => $z['proy']['total'],
                'avance'     => self::avance($z['pct_ppto']['total']),
                'aa'         => $z['aa']['total'],
                'ma'         => $z['ma']['total'],
                'compras'    => $mz['compras'],
                'merma_pct'  => $mz['pct'],
            ];
        }

        // --- Compras y merma (todas las estaciones) ---
        $merma = self::merma($ctx['merma'], array_keys($estaciones), $ctx['norma']);
        $merma['valor']  = $merma['litros'] !== null ? $merma['litros'] * $ctx['precio'] : null;
        $merma['precio'] = $ctx['precio'];
        $merma['norma']  = $ctx['norma'];
        $porEstacion = [];
        foreach ($estaciones as $cod => $s) {
            $m = $ctx['merma'][$cod] ?? null;
            if (!$m || !(float) ($m['venta'] ?? 0)) continue;
            $porEstacion[] = [
                'codgas' => $cod,
                'nombre' => $s['nombre'],
                'zona'   => $s['zona'],
                'litros' => (float) $m['merma'],
                'pct'    => (float) $m['merma'] / (float) $m['venta'] * 100,
            ];
        }
        usort($porEstacion, fn($a, $b) => $b['pct'] <=> $a['pct']);
        $merma['peores']   = array_slice($porEstacion, 0, self::TOP);
        $merma['en_norma'] = count(array_filter($porEstacion, fn($s) => $s['pct'] <= $ctx['norma']));
        $merma['evaluables'] = count($porEstacion);

        return [
            'kpis'    => $kpis,
            'zonas'   => $zonas,
            'diario'  => $diario,
            'ranking' => $ranking,
            'nuevas'  => $nuevas,
            'mix'     => $mix,
            'merma'   => $merma,
            'sin_presupuesto' => $consolidado['sin_presupuesto'],
        ];
    }

    /** Suma compras / ventas / merma de un conjunto de estaciones. */
    private static function merma(array $merma, array $codgases, float $norma): array
    {
        $out = ['compras' => null, 'ventas' => null, 'litros' => null, 'pct' => null];
        foreach ($codgases as $cod) {
            $m = $merma[$cod] ?? null;
            if (!$m) continue;
            foreach (['compras' => 'compras', 'ventas' => 'venta', 'litros' => 'merma'] as $k => $src) {
                if ($m[$src] !== null) $out[$k] = ($out[$k] ?? 0.0) + (float) $m[$src];
            }
        }
        if ($out['litros'] !== null && $out['ventas']) {
            $out['pct'] = $out['litros'] / $out['ventas'] * 100;
        }
        return $out;
    }

    /** % PRESUPUESTO (variación) → avance (100 = en meta), como la vista. */
    private static function avance(?float $pctPpto): ?float
    {
        return $pctPpto === null ? null : 100 + $pctPpto;
    }

    private static function totalFamilias(?array $fila): ?float
    {
        if ($fila === null) return null;
        $suma = null;
        foreach (['maxima', 'super', 'diesel'] as $f) {
            if (isset($fila[$f]) && $fila[$f] !== null) $suma = ($suma ?? 0.0) + (float) $fila[$f];
        }
        return $suma;
    }

    private static function pctCambio(?float $a, ?float $b): ?float
    {
        if ($a === null || $b === null || $b == 0.0) return null;
        return ($a / $b - 1) * 100;
    }
}
