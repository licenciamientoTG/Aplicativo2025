<?php

/**
 * Reporte de Ventas Consolidado — cálculo puro (sin BD).
 *
 * Reemplaza las cinco hojas vivas de "VETS X EST X MARCA Y PROTS <Mes><Año>.xlsm".
 * Recibe la matriz día × estación × familia que arma MermaDiariaModel y la
 * proyecta en la pestaña pedida, más las nueve filas de resumen del Excel.
 *
 * Spec: docs/superpowers/specs/2026-07-28-reporte-ventas-consolidado-design.md
 */
class VentasConsolidado
{
    /**
     * Las cinco hojas vivas del libro, en orden de pestaña.
     *  - familias: qué sumar de la matriz de ventas.
     *  - codprd:   qué sumar de TGV2.dbo.Budget. Los pares 179/192 (máxima) y
     *              180/193 (súper) conviven porque los años viejos usan los
     *              segundos; sumar ambos funciona en cualquier año.
     */
    public const PESTANAS = [
        'total'    => ['label' => 'LITROS DE COMBUSTIBLE', 'familias' => ['maxima', 'super', 'diesel'], 'codprd' => [179, 192, 180, 193, 181]],
        'reg_prem' => ['label' => 'REGULAR + PREMIUM',     'familias' => ['maxima', 'super'],           'codprd' => [179, 192, 180, 193]],
        'regular'  => ['label' => 'REGULAR',               'familias' => ['maxima'],                    'codprd' => [179, 192]],
        'premium'  => ['label' => 'PREMIUM',               'familias' => ['super'],                     'codprd' => [180, 193]],
        'diesel'   => ['label' => 'DIESEL',                'familias' => ['diesel'],                    'codprd' => [181]],
    ];

    /** Días de la semana en español, indexados por date('w'). */
    private const DIAS_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /** Meses en español, índice 0 = enero. */
    public const MESES = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
                          'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];

    /**
     * @param string $clave  llave de self::PESTANAS
     * @param array  $ctx    [
     *   'estaciones'    => [['Codigo'=>string|int,'Nombre'=>string,'cveest'=>?string], ...] en orden de columna
     *   'ventas'        => ['YYYY-MM-DD' => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]
     *   'presupuesto'   => [codgas => [codprd => float]]
     *   'mes_anterior'  => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]
     *   'anio_anterior' => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]
     *   'anio'          => int
     *   'mes'           => int
     * ]
     * @return array [
     *   'label'           => string,
     *   'dias'            => [['dia'=>int,'nombre'=>string,'celdas'=>[codgas=>?float],'total'=>?float], ...],
     *   'resumen'         => [clave => ['celdas'=>[codgas=>?float], 'total'=>?float]] con las claves
     *                        total, mix, proy, ppto, dif, pct_ppto, vs_semana, ma, aa
     *   'dias_del_mes'    => int,
     *   'dias_con_datos'  => int,
     *   'sin_presupuesto' => bool,
     * ]
     * @throws InvalidArgumentException si $clave no es una pestaña conocida
     */
    public static function construir(string $clave, array $ctx): array
    {
        if (!isset(self::PESTANAS[$clave])) {
            throw new InvalidArgumentException("Pestaña desconocida: $clave");
        }
        $pestana  = self::PESTANAS[$clave];
        $familias = $pestana['familias'];
        $anio     = (int) $ctx['anio'];
        $mes      = (int) $ctx['mes'];
        $codgases = array_map(fn($e) => (int) $e['Codigo'], $ctx['estaciones']);

        $diasDelMes = (int) date('t', mktime(0, 0, 0, $mes, 1, $anio));

        // Fechas con al menos un registro, en orden. Es un escalar único para
        // todo el reporte (equivale a "DIAS LABORADOS" del Excel, celda C299),
        // no un conteo por estación.
        $fechasConDatos = array_keys($ctx['ventas']);
        sort($fechasConDatos);
        $diasConDatos = count($fechasConDatos);

        // --- filas de día ---
        $dias = [];
        for ($d = 1; $d <= $diasDelMes; $d++) {
            $fecha  = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
            $delDia = $ctx['ventas'][$fecha] ?? [];
            $celdas = [];
            $total  = null;
            foreach ($codgases as $cod) {
                $valor = isset($delDia[$cod]) ? self::sumarFamilias($delDia[$cod], $familias) : null;
                $celdas[$cod] = $valor;
                if ($valor !== null) $total = ($total ?? 0.0) + $valor;
            }
            $dias[] = [
                'dia'    => $d,
                'nombre' => self::DIAS_SEMANA[(int) date('w', mktime(0, 0, 0, $mes, $d, $anio))],
                'celdas' => $celdas,
                'total'  => $total,
            ];
        }

        // --- TOTAL del mes por columna ---
        $total = self::filaVacia($codgases);
        foreach ($dias as $fila) {
            foreach ($codgases as $cod) {
                if ($fila['celdas'][$cod] !== null) {
                    $total['celdas'][$cod] = ($total['celdas'][$cod] ?? 0.0) + $fila['celdas'][$cod];
                }
            }
        }
        $total['total'] = self::sumarCeldas($total['celdas']);

        // --- % MIX ---
        $mix = self::filaVacia($codgases);
        foreach ($codgases as $cod) {
            $mix['celdas'][$cod] = ($total['total'] !== null && $total['total'] != 0.0 && $total['celdas'][$cod] !== null)
                ? $total['celdas'][$cod] / $total['total'] * 100 : null;
        }
        $mix['total'] = $total['total'] !== null && $total['total'] != 0.0 ? 100.0 : null;

        // --- PROY. MENSUAL = TOTAL / días con datos × días del mes ---
        $proy = self::filaVacia($codgases);
        foreach ($codgases as $cod) {
            $proy['celdas'][$cod] = ($diasConDatos > 0 && $total['celdas'][$cod] !== null)
                ? $total['celdas'][$cod] / $diasConDatos * $diasDelMes : null;
        }
        $proy['total'] = self::sumarCeldas($proy['celdas']);

        // --- PRESUPUESTO ---
        $ppto = self::filaVacia($codgases);
        foreach ($codgases as $cod) {
            $porPrd = $ctx['presupuesto'][$cod] ?? null;
            if ($porPrd === null) continue;
            $suma = null;
            foreach ($pestana['codprd'] as $prd) {
                if (isset($porPrd[$prd])) $suma = ($suma ?? 0.0) + (float) $porPrd[$prd];
            }
            $ppto['celdas'][$cod] = $suma;
        }
        $ppto['total'] = self::sumarCeldas($ppto['celdas']);

        // --- DIFERENCIA y % PRESUPUESTO ---
        $dif     = self::filaVacia($codgases);
        $pctPpto = self::filaVacia($codgases);
        foreach ($codgases as $cod) {
            $p = $proy['celdas'][$cod];
            $b = $ppto['celdas'][$cod];
            $dif['celdas'][$cod]     = ($p !== null && $b !== null) ? $p - $b : null;
            $pctPpto['celdas'][$cod] = self::pctCambio($p, $b);
        }
        // El TOTAL de DIFERENCIA debe ser la suma de las celdas visibles de
        // su propia fila, no proy['total'] - ppto['total']: proy suma TODAS
        // las estaciones y ppto solo las que tienen presupuesto cargado, así
        // que restarlos directamente descuadra el total contra sus celdas
        // cuando la cobertura de presupuesto es parcial.
        $dif['total'] = self::sumarCeldas($dif['celdas']);

        // % PRESUPUESTO total: comparar manzanas con manzanas. El numerador
        // es la proyección SOLO de las estaciones que sí tienen presupuesto
        // (no la de todas), para que el porcentaje sea consistente con las
        // celdas por estación de esta misma fila.
        $proyConPpto = null;
        foreach ($codgases as $cod) {
            if ($ppto['celdas'][$cod] === null) continue;
            if ($proy['celdas'][$cod] !== null) {
                $proyConPpto = ($proyConPpto ?? 0.0) + $proy['celdas'][$cod];
            }
        }
        $pctPpto['total'] = self::pctCambio($proyConPpto, $ppto['total']);

        // --- VS SEMANA PREVIA: últimos 7 días con dato contra los 7 anteriores.
        // El Excel usa filas fijas (33 vs 26); anclarlo al último día con dato
        // hace que también funcione a mitad de mes.
        $vsSemana = self::filaVacia($codgases);
        if ($diasConDatos >= 14) {
            $ultimos  = array_slice($fechasConDatos, -7);
            $previos  = array_slice($fechasConDatos, -14, 7);
            foreach ($codgases as $cod) {
                $a = self::sumarRango($ctx['ventas'], $ultimos, $cod, $familias);
                $b = self::sumarRango($ctx['ventas'], $previos, $cod, $familias);
                $vsSemana['celdas'][$cod] = self::pctCambio($a, $b);
            }
            $ta = self::sumarRangoTodas($ctx['ventas'], $ultimos, $codgases, $familias);
            $tb = self::sumarRangoTodas($ctx['ventas'], $previos, $codgases, $familias);
            $vsSemana['total'] = self::pctCambio($ta, $tb);
        }

        // --- % M.A. y % A.A.: proyección contra el total del mes de referencia ---
        $ma = self::filaVacia($codgases);
        $aa = self::filaVacia($codgases);
        $maTotal = null;
        $aaTotal = null;
        foreach ($codgases as $cod) {
            $refMa = isset($ctx['mes_anterior'][$cod])  ? self::sumarFamilias($ctx['mes_anterior'][$cod], $familias)  : null;
            $refAa = isset($ctx['anio_anterior'][$cod]) ? self::sumarFamilias($ctx['anio_anterior'][$cod], $familias) : null;
            $ma['celdas'][$cod] = self::pctCambio($proy['celdas'][$cod], $refMa);
            $aa['celdas'][$cod] = self::pctCambio($proy['celdas'][$cod], $refAa);
            if ($refMa !== null) $maTotal = ($maTotal ?? 0.0) + $refMa;
            if ($refAa !== null) $aaTotal = ($aaTotal ?? 0.0) + $refAa;
        }
        $ma['total'] = self::pctCambio($proy['total'], $maTotal);
        $aa['total'] = self::pctCambio($proy['total'], $aaTotal);

        return [
            'label'   => $pestana['label'],
            'dias'    => $dias,
            'resumen' => [
                'total'     => $total,
                'mix'       => $mix,
                'proy'      => $proy,
                'ppto'      => $ppto,
                'dif'       => $dif,
                'pct_ppto'  => $pctPpto,
                'vs_semana' => $vsSemana,
                'ma'        => $ma,
                'aa'        => $aa,
            ],
            'dias_del_mes'    => $diasDelMes,
            'dias_con_datos'  => $diasConDatos,
            'sin_presupuesto' => $ppto['total'] === null,
        ];
    }

    /** Fila de resumen con todas las celdas en null. */
    private static function filaVacia(array $codgases): array
    {
        return ['celdas' => array_fill_keys($codgases, null), 'total' => null];
    }

    /** Suma las familias de una fila; null si ninguna tiene dato. */
    private static function sumarFamilias(array $fila, array $familias): ?float
    {
        $suma = null;
        foreach ($familias as $f) {
            if (isset($fila[$f]) && $fila[$f] !== null) $suma = ($suma ?? 0.0) + (float) $fila[$f];
        }
        return $suma;
    }

    /** Suma las celdas no nulas; null si todas son null. */
    private static function sumarCeldas(array $celdas): ?float
    {
        $suma = null;
        foreach ($celdas as $v) {
            if ($v !== null) $suma = ($suma ?? 0.0) + $v;
        }
        return $suma;
    }

    /** Suma de una estación en un conjunto de fechas. */
    private static function sumarRango(array $ventas, array $fechas, int $codgas, array $familias): ?float
    {
        $suma = null;
        foreach ($fechas as $f) {
            if (!isset($ventas[$f][$codgas])) continue;
            $v = self::sumarFamilias($ventas[$f][$codgas], $familias);
            if ($v !== null) $suma = ($suma ?? 0.0) + $v;
        }
        return $suma;
    }

    /** Suma de todas las estaciones en un conjunto de fechas. */
    private static function sumarRangoTodas(array $ventas, array $fechas, array $codgases, array $familias): ?float
    {
        $suma = null;
        foreach ($codgases as $cod) {
            $v = self::sumarRango($ventas, $fechas, $cod, $familias);
            if ($v !== null) $suma = ($suma ?? 0.0) + $v;
        }
        return $suma;
    }

    /** Variación porcentual a/b - 1, en puntos porcentuales. null si no aplica. */
    private static function pctCambio(?float $a, ?float $b): ?float
    {
        if ($a === null || $b === null || $b == 0.0) return null;
        return ($a / $b - 1) * 100;
    }

    /**
     * Histórico mensual: una fila por mes del rango de años, más una fila de
     * subtotal después de diciembre de cada año.
     *
     * A diferencia de construir(), aquí las celdas son float y nunca null: un
     * mes sin sincronizar vale 0.0. Es una decisión explícita del usuario, y
     * la razón de que exista 'meses_con_datos' — sin esa lista, los ceros de
     * un mes no sincronizado son indistinguibles de un mes sin ventas.
     *
     * @param string $clave  llave de self::PESTANAS
     * @param array  $ctx    [
     *   'estaciones' => [['Codigo'=>int,'Nombre'=>string], ...] en orden de columna
     *   'historico'  => [anio => [mes => [codgas => ['maxima'=>?float,'super'=>?float,'diesel'=>?float]]]]
     *   'desde'      => int  (año)
     *   'hasta'      => int  (año)
     * ]
     * @return array [
     *   'label' => string,
     *   'filas' => [
     *     ['tipo'=>'mes',   'anio'=>int, 'mes'=>int, 'etiqueta'=>string,
     *      'celdas'=>[codgas=>float], 'total'=>float],
     *     ['tipo'=>'anual', 'anio'=>int, 'etiqueta'=>string,
     *      'celdas'=>[codgas=>float], 'total'=>float],
     *   ],
     *   'meses_con_datos' => string[],  // etiquetas de los meses sincronizados
     *   'meses_del_rango' => int,
     * ]
     * @throws InvalidArgumentException si $clave no es una pestaña conocida
     */
    public static function construirHistorico(string $clave, array $ctx): array
    {
        if (!isset(self::PESTANAS[$clave])) {
            throw new InvalidArgumentException("Pestaña desconocida: $clave");
        }
        $pestana  = self::PESTANAS[$clave];
        $familias = $pestana['familias'];
        $desde    = (int) $ctx['desde'];
        $hasta    = (int) $ctx['hasta'];
        $codgases = array_map(fn($e) => (int) $e['Codigo'], $ctx['estaciones']);

        $filas         = [];
        $mesesConDatos = [];
        $mesesDelRango = 0;

        for ($anio = $desde; $anio <= $hasta; $anio++) {
            $anual      = array_fill_keys($codgases, 0.0);
            $anualTotal = 0.0;

            for ($mes = 1; $mes <= 12; $mes++) {
                $mesesDelRango++;
                $etiqueta = self::MESES[$mes - 1] . ' ' . $anio;

                // "Con datos" = el mes fue sincronizado. Se mide por la
                // presencia del mes en el snapshot, no por el valor: en la
                // pestaña DIESEL un mes real puede sumar 0 y aun así estar
                // sincronizado.
                $sincronizado = isset($ctx['historico'][$anio][$mes]);
                if ($sincronizado) $mesesConDatos[] = $etiqueta;

                $delMes = $ctx['historico'][$anio][$mes] ?? [];
                $celdas = [];
                $total  = 0.0;
                foreach ($codgases as $cod) {
                    $v = isset($delMes[$cod])
                        ? (self::sumarFamilias($delMes[$cod], $familias) ?? 0.0)
                        : 0.0;
                    $celdas[$cod] = $v;
                    $total       += $v;
                    $anual[$cod] += $v;
                }
                $anualTotal += $total;

                $filas[] = ['tipo' => 'mes', 'anio' => $anio, 'mes' => $mes,
                            'etiqueta' => $etiqueta, 'celdas' => $celdas, 'total' => $total];
            }

            $filas[] = ['tipo' => 'anual', 'anio' => $anio, 'etiqueta' => 'TOTAL ' . $anio,
                        'celdas' => $anual, 'total' => $anualTotal];
        }

        return [
            'label'           => $pestana['label'],
            'filas'           => $filas,
            'meses_con_datos' => $mesesConDatos,
            'meses_del_rango' => $mesesDelRango,
        ];
    }
}
