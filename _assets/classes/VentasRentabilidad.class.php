<?php

/**
 * Sección "Rentabilidad" del RESUMEN DIRECCIÓN de /merma/ventas. Recibe lo
 * que trae VentasRentabilidadModel::get_mes() y arma: margen bruto por
 * estación, zona y producto; desglose por proveedor (con sobrecosto contra
 * el mejor precio de la zona); serie diaria de precio de venta vs costo; y
 * la cascada venta → margen ajustado.
 *
 * TODO EN PESOS CON IVA (pedido del usuario 2026-10-07): la venta es lo
 * cobrado en bomba tal cual (IVA incluido) y los costos de compra se llevan
 * a con IVA con la tasa de cada estación (8% frontera / 16%). El estímulo
 * fronterizo es un monto por litro sin IVA y se suma tal cual.
 *
 * Margen bruto = venta − costo de lo vendido + estímulo, donde el costo de lo
 * vendido es litros vendidos × costo promedio por litro de lo comprado en
 * el mes (misma estación y familia). No se usa el total comprado: lo que se
 * compra y no se vende queda en inventario y no es costo del mes.
 *
 * Solo cuentan para el costo las recepciones que ya tienen precio. Si una
 * estación no tiene ninguna recepción con precio de cierta familia en el
 * mes (no compró, o sus facturas aún no se capturan), su costo por litro se
 * toma del promedio de todas las estaciones para esa familia y la estación
 * se marca con 'costo_estimado'.
 */
class VentasRentabilidad
{
    private const TOP = 5;

    /**
     * Para elegir "el mejor precio" de una zona y producto, un proveedor
     * necesita volumen real: al menos este % de lo comprado en esa zona y
     * producto. Evita que una sola pipa barata marque la referencia.
     */
    private const MIN_PCT_REFERENCIA = 5.0;

    /**
     * Estímulo fiscal del IEPS en la franja fronteriza, $ por litro VENDIDO
     * en estaciones fronterizas (las de IVA 8%). Montos indicados por el
     * usuario el 2026-10-06: gasolina < 91 octanos (Regular) $3.410 y
     * ≥ 91 octanos (Premium) $2.860; diesel sin monto. SHCP los publica cada
     * semana en el DOF: si cambian, actualizar aquí.
     */
    public const ESTIMULO_FRONTERA = ['maxima' => 3.410, 'super' => 2.860, 'diesel' => 0.0];
    private const IVA_FRONTERA = 0.08;

    /**
     * @param array $datos      VentasRentabilidadModel::get_mes()
     * @param array $estaciones [['Codigo','Nombre','zona'], ...] — todas
     * @param array $merma      [codgas => [familia => ?float]] diferencia de
     *                          inventario del mes en litros (fís − contable)
     */
    public static function construir(array $datos, array $estaciones, array $merma = []): array
    {
        $familias = array_keys(MermaDiariaModel::FAMILIAS);
        $porCod   = $datos['estaciones'];

        // Costo promedio por litro de cada familia en todas las estaciones,
        // para la estación que vendió una familia que no compró en el mes.
        $costoGlobal = [];
        foreach ($familias as $fam) {
            $l = 0.0; $c = 0.0;
            foreach ($porCod as $d) {
                $l += $d['familias'][$fam]['litros_con_precio'] ?? 0;
                $c += $d['familias'][$fam]['costo_compra'] ?? 0;
            }
            $costoGlobal[$fam] = $l > 0 ? $c / $l : null;
        }

        $vacio = fn() => ['litros' => 0.0, 'venta' => 0.0, 'costo' => 0.0, 'estimulo' => 0.0];
        $litrosEstimulo = 0.0;
        $total   = $vacio();
        $porFam  = [];
        foreach ($familias as $fam) $porFam[$fam] = $vacio();
        $porZona = [];
        foreach (VentasConsolidado::ZONAS as $z => $_) $porZona[$z] = $vacio();

        $filas     = [];
        $sinDatos  = [];
        $atipicas  = [];
        $costoEF   = [];   // [codgas][familia] => costo por litro usado
        $tasaEst   = [];   // [codgas] => tasa IVA
        $zonaEst   = [];   // [codgas] => clave de zona
        $difInvLitros = 0.0;
        $difInvPesos  = 0.0;
        $compras  = ['facturado' => 0.0, 'costo' => 0.0, 'docs' => 0, 'docs_sin_factura' => 0, 'costo_sin_factura' => 0.0,
                     'docs_sin_precio' => 0, 'litros_sin_precio' => 0.0];

        foreach ($estaciones as $e) {
            $cod = (int) $e['Codigo'];
            $zonaEst[$cod] = $e['zona'];
            $d   = $porCod[$cod] ?? null;
            if ($d === null || $d['tasa_iva'] === null || empty($d['familias'])) {
                $sinDatos[] = $e['Nombre'];
                continue;
            }
            $tasaEst[$cod] = $d['tasa_iva'];

            if ($d['ventas_atipicas'] > 0) $atipicas[] = $e['Nombre'] . ' (' . $d['ventas_atipicas'] . ')';

            $compras['facturado']         += $d['factura_total'];
            $compras['docs']              += $d['docs'];
            $compras['docs_sin_factura']  += $d['docs_sin_factura'];
            $compras['costo_sin_factura'] += $d['costo_sin_factura'] * (1 + $d['tasa_iva']);
            $compras['docs_sin_precio']   += $d['docs_sin_precio'];
            $compras['litros_sin_precio'] += $d['litros_sin_precio'];

            $est = $vacio();
            $estFam = [];   // métricas de la estación por familia (selector de producto)
            $estimado = false;
            foreach ($familias as $fam) {
                $f = $d['familias'][$fam] ?? null;
                $conPrecio  = $f && $f['litros_con_precio'] > 0;
                $costoLitro = $conPrecio ? $f['costo_compra'] / $f['litros_con_precio'] : $costoGlobal[$fam];
                // A con IVA con la tasa de la estación (ver encabezado)
                if ($costoLitro !== null) $costoLitro *= 1 + $d['tasa_iva'];
                $costoEF[$cod][$fam] = $costoLitro;

                // Diferencia de inventario valorada al costo de la estación
                $dif = $merma[$cod][$fam] ?? null;
                if ($dif !== null && $costoLitro !== null) {
                    $difInvLitros += $dif;
                    $difInvPesos  += $dif * $costoLitro;
                }

                if (!$f) continue;
                $compras['costo'] += $f['costo_compra'] * (1 + $d['tasa_iva']);
                if ($f['litros_venta'] <= 0 || $costoLitro === null) continue;
                if (!$conPrecio) $estimado = true;

                $estimuloLitro = self::estimuloLitro($d['tasa_iva'], $fam);
                if ($estimuloLitro > 0) $litrosEstimulo += $f['litros_venta'];
                $linea = [
                    'litros'   => $f['litros_venta'],
                    'venta'    => $f['pesos_venta'],
                    'costo'    => $f['litros_venta'] * $costoLitro,
                    'estimulo' => $f['litros_venta'] * $estimuloLitro,
                ];
                $estFam[$fam] = self::metricas($linea);
                foreach (['litros', 'venta', 'costo', 'estimulo'] as $k) {
                    $est[$k]                 += $linea[$k];
                    $porFam[$fam][$k]        += $linea[$k];
                    $porZona[$e['zona']][$k] += $linea[$k];
                    $total[$k]               += $linea[$k];
                }
            }
            if ($est['litros'] <= 0) {
                $sinDatos[] = $e['Nombre'];
                continue;
            }
            $filas[] = self::metricas($est) + [
                'codgas'         => $cod,
                'nombre'         => $e['Nombre'],
                'zona'           => VentasConsolidado::ZONAS[$e['zona']]['label'],
                'iva'            => $d['tasa_iva'] * 100,
                'frontera'       => $d['tasa_iva'] == self::IVA_FRONTERA,
                'costo_estimado' => $estimado,
                'familias'       => $estFam,
            ];
        }

        usort($filas, fn($a, $b) => $b['margen_litro'] <=> $a['margen_litro']);

        $zonas = [];
        foreach ($porZona as $z => $v) {
            $zonas[] = self::metricas($v) + ['label' => VentasConsolidado::ZONAS[$z]['label']];
        }
        $productos = [];
        foreach ($porFam as $fam => $v) {
            $productos[] = self::metricas($v) + ['clave' => $fam];
        }

        $proveedores = self::proveedores($datos, $zonaEst, $tasaEst);
        $descuentos  = array_sum($datos['descuentos']);
        $tot = self::metricas($total);

        // Cascada: margen antes de estímulo → + estímulo fronterizo = margen
        // bruto → descuentos → diferencia de inventario → margen ajustado. La
        // diferencia de inventario es fís − contable: positiva = sobrante
        // (combustible que no costó y se venderá), negativa = faltante; por
        // eso se SUMA con su signo.
        $cascada = null;
        if ($tot['margen'] !== null) {
            $cascada = [
                'venta'      => $tot['venta'],
                'costo'      => $tot['costo'],
                'antes'      => $tot['venta'] - $tot['costo'],
                'estimulo'   => $tot['estimulo'],
                'margen'     => $tot['margen'],
                'descuentos' => $descuentos,
                'dif_inv'    => $difInvPesos,
                'dif_inv_litros' => $difInvLitros,
                'ajustado'   => $tot['margen'] + $descuentos + $difInvPesos,
            ];
        }

        return [
            'total'       => $tot,
            'zonas'       => $zonas,
            'productos'   => $productos,
            'mejores'     => array_slice($filas, 0, self::TOP),
            'peores'      => array_reverse(array_slice($filas, -self::TOP)),
            'todas'       => $filas,
            'estimadas'   => count(array_filter($filas, fn($f) => $f['costo_estimado'])),
            'negativas'   => count(array_filter($filas, fn($f) => $f['margen'] < 0)),
            'estaciones'  => count($filas),
            'compras'     => $compras,
            'proveedores' => $proveedores,
            'por_estacion'=> self::porEstacion($datos, $filas, $tasaEst, $costoEF),
            'cascada'     => $cascada,
            'estimulo'    => [
                'tarifas' => self::ESTIMULO_FRONTERA,
                'total'   => $tot['estimulo'],
                'litros'  => $litrosEstimulo,
            ],
            'sin_datos'   => $sinDatos,
            'atipicas'    => $atipicas,
        ];
    }

    /**
     * Una fila por proveedor: litros y $/L por familia, facturado, descuentos
     * y sobrecosto contra el proveedor más barato de la misma zona y familia
     * (con volumen mínimo, ver MIN_PCT_REFERENCIA).
     */
    private static function proveedores(array $datos, array $zonaEst, array $tasaEst): array
    {
        $sinFactura = VentasRentabilidadModel::SIN_FACTURA;

        // Agrupado por zona + tasa de IVA × familia × proveedor. La tasa entra
        // en la llave porque los precios van con IVA: MARCA Y PROTS mezcla
        // estaciones de 8% (Juárez) y 16% (Delicias, Parral…), y sin separar
        // el proveedor que surte a las de 16% saldría "más caro" solo por el
        // impuesto, inflando el sobrecosto (pasaba de $3.7 M a $9.2 M).
        $grupo = [];
        foreach ($datos['proveedores'] as $r) {
            $tasa = $tasaEst[$r['codgas']] ?? 0.16;
            $zona = ($zonaEst[$r['codgas']] ?? 'marca_prots') . '|' . $tasa;
            $g = &$grupo[$zona][$r['familia']][$r['proveedor']];
            $g['litros'] = ($g['litros'] ?? 0) + $r['litros'];
            // Con IVA, con la tasa de la estación que recibió la descarga
            $g['costo']  = ($g['costo'] ?? 0) + $r['costo'] * (1 + $tasa);
            unset($g);
        }

        $out = [];
        $vacia = fn($p) => [
            'proveedor' => $p, 'litros' => 0.0, 'costo' => 0.0, 'sobrecosto' => 0.0,
            'familias'  => [], 'docs' => $datos['prov_docs'][$p]['docs'] ?? 0,
            'facturado' => $datos['prov_docs'][$p]['facturado'] ?? 0.0,
            'descuentos'=> $datos['descuentos'][$p] ?? 0.0,
        ];

        foreach ($grupo as $zona => $porFam) {
            foreach ($porFam as $fam => $porProv) {
                $volZona = array_sum(array_column($porProv, 'litros'));
                $mejor = null;
                foreach ($porProv as $p => $g) {
                    if ($p === $sinFactura || $g['litros'] <= 0) continue;
                    if ($g['litros'] / $volZona * 100 < self::MIN_PCT_REFERENCIA) continue;
                    $pxl = $g['costo'] / $g['litros'];
                    if ($mejor === null || $pxl < $mejor) $mejor = $pxl;
                }
                foreach ($porProv as $p => $g) {
                    $out[$p] ??= $vacia($p);
                    $out[$p]['litros'] += $g['litros'];
                    $out[$p]['costo']  += $g['costo'];
                    $out[$p]['familias'][$fam]['litros'] = ($out[$p]['familias'][$fam]['litros'] ?? 0) + $g['litros'];
                    $out[$p]['familias'][$fam]['costo']  = ($out[$p]['familias'][$fam]['costo'] ?? 0) + $g['costo'];
                    if ($mejor !== null && $p !== $sinFactura && $g['litros'] > 0) {
                        $out[$p]['sobrecosto'] += max(0.0, ($g['costo'] / $g['litros'] - $mejor) * $g['litros']);
                    }
                }
            }
        }

        // Proveedores con descargas pero sin ninguna con precio todavía
        foreach ($datos['prov_docs'] as $p => $_) $out[$p] ??= $vacia($p);

        $litrosTot = array_sum(array_column($out, 'litros'));
        foreach ($out as &$p) {
            $p['pct']   = $litrosTot > 0 ? $p['litros'] / $litrosTot * 100 : null;
            $p['pxl']   = $p['litros'] > 0 ? $p['costo'] / $p['litros'] : null;
            foreach ($p['familias'] as &$f) $f['pxl'] = $f['litros'] > 0 ? $f['costo'] / $f['litros'] : null;
            unset($f);
        }
        unset($p);

        $out = array_values($out);
        usort($out, fn($a, $b) => $b['litros'] <=> $a['litros']);
        return $out;
    }

    /**
     * Serie diaria POR ESTACIÓN y familia para la gráfica "Precio de compra
     * y venta por estación". Por cada día:
     *   v = precio de venta con IVA por litro (lo cobrado en bomba)
     *   c = precio por litro con IVA de las descargas con precio de ESE día (null si
     *       ese día no hubo descarga de esa familia)
     *   m = margen por litro = v + estímulo − costo de reposición, donde el
     *       costo de reposición es el precio de la última descarga conocida
     *       (arrastrado día a día; antes de la primera descarga del mes se usa
     *       el costo promedio del mes de la estación)
     *   l = litros vendidos
     * Usar la última descarga y no el promedio del mes es lo que hace que el
     * margen "se mueva" con cada cambio de precio del proveedor.
     *
     * @return array ['fechas' => ['Y-m-d', ...], 'estaciones' => [
     *     ['cod','nombre','zona','frontera','litros', 'familias' => [fam => ['v'=>[],'c'=>[],'m'=>[],'l'=>[]]]], ...]]
     */
    private static function porEstacion(array $datos, array $filas, array $tasaEst, array $costoEF): array
    {
        $fechas = array_unique(array_merge(array_keys($datos['diario']), array_keys($datos['compras_dia'])));
        sort($fechas);

        $estaciones = [];
        foreach ($filas as $s) {
            $cod = $s['codgas'];
            $tasa = $tasaEst[$cod];
            $familias = [];
            foreach (array_keys(MermaDiariaModel::FAMILIAS) as $fam) {
                $reposicion = $costoEF[$cod][$fam] ?? null;
                $estimulo   = self::estimuloLitro($tasa, $fam);
                $serie = ['v' => [], 'c' => [], 'm' => [], 'l' => []];
                $hayVenta = false;
                foreach ($fechas as $fecha) {
                    $venta  = $datos['diario'][$fecha][$cod][$fam] ?? null;
                    $compra = $datos['compras_dia'][$fecha][$cod][$fam] ?? null;
                    $pc = $compra && $compra['litros'] > 0 ? $compra['costo'] / $compra['litros'] * (1 + $tasa) : null;
                    if ($pc !== null) $reposicion = $pc;

                    $pv = $venta && $venta['litros'] > 0 ? $venta['pesos'] / $venta['litros'] : null;
                    if ($pv !== null) $hayVenta = true;

                    $serie['v'][] = $pv === null ? null : round($pv, 3);
                    $serie['c'][] = $pc === null ? null : round($pc, 3);
                    $serie['m'][] = ($pv === null || $reposicion === null) ? null : round($pv + $estimulo - $reposicion, 3);
                    $serie['l'][] = $venta ? round($venta['litros']) : 0;
                }
                if ($hayVenta) $familias[$fam] = $serie;
            }
            if (!$familias) continue;
            $estaciones[] = [
                'cod'      => $cod,
                'nombre'   => $s['nombre'],
                'zona'     => $s['zona'],
                'frontera' => $s['frontera'],
                'litros'   => round($s['litros']),
                'familias' => $familias,
            ];
        }
        usort($estaciones, fn($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));

        return ['fechas' => $fechas, 'estaciones' => $estaciones];
    }

    /** Estímulo por litro: solo estaciones fronterizas (IVA 8%). */
    private static function estimuloLitro(float $tasaIva, string $familia): float
    {
        return $tasaIva == self::IVA_FRONTERA ? (self::ESTIMULO_FRONTERA[$familia] ?? 0.0) : 0.0;
    }

    /**
     * Agrega margen (venta − costo + estímulo fronterizo, con IVA), margen
     * por litro y % sobre la venta.
     */
    private static function metricas(array $v): array
    {
        $margen = $v['litros'] > 0 ? $v['venta'] - $v['costo'] + $v['estimulo'] : null;
        return $v + [
            'margen'       => $margen,
            'margen_litro' => $margen !== null ? $margen / $v['litros'] : null,
            'margen_pct'   => ($margen !== null && $v['venta'] > 0) ? $margen / $v['venta'] * 100 : null,
        ];
    }
}
