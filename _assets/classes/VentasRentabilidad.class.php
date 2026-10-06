<?php

/**
 * Sección "Rentabilidad" del RESUMEN DIRECCIÓN de /merma/ventas. Recibe lo
 * que trae VentasRentabilidadModel::get_mes() y arma: margen bruto por
 * estación, zona y producto; desglose por proveedor (con sobrecosto contra
 * el mejor precio de la zona); serie diaria de precio de venta vs costo; y
 * la cascada venta → margen ajustado.
 *
 * Margen bruto = venta sin IVA − costo de lo vendido, donde el costo de lo
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

        $vacio = fn() => ['litros' => 0.0, 'venta' => 0.0, 'costo' => 0.0];
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
            $compras['costo_sin_factura'] += $d['costo_sin_factura'];
            $compras['docs_sin_precio']   += $d['docs_sin_precio'];
            $compras['litros_sin_precio'] += $d['litros_sin_precio'];

            $est = $vacio();
            $estimado = false;
            foreach ($familias as $fam) {
                $f = $d['familias'][$fam] ?? null;
                $conPrecio  = $f && $f['litros_con_precio'] > 0;
                $costoLitro = $conPrecio ? $f['costo_compra'] / $f['litros_con_precio'] : $costoGlobal[$fam];
                $costoEF[$cod][$fam] = $costoLitro;

                // Diferencia de inventario valorada al costo de la estación
                $dif = $merma[$cod][$fam] ?? null;
                if ($dif !== null && $costoLitro !== null) {
                    $difInvLitros += $dif;
                    $difInvPesos  += $dif * $costoLitro;
                }

                if (!$f) continue;
                $compras['costo'] += $f['costo_compra'];
                if ($f['litros_venta'] <= 0 || $costoLitro === null) continue;
                if (!$conPrecio) $estimado = true;

                $linea = [
                    'litros' => $f['litros_venta'],
                    'venta'  => $f['pesos_venta'] / (1 + $d['tasa_iva']),
                    'costo'  => $f['litros_venta'] * $costoLitro,
                ];
                foreach (['litros', 'venta', 'costo'] as $k) {
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
                'costo_estimado' => $estimado,
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

        $proveedores = self::proveedores($datos, $zonaEst);
        $descuentos  = array_sum($datos['descuentos']);
        $tot = self::metricas($total);

        // Cascada: venta → costo → margen bruto → descuentos → diferencia
        // de inventario → margen ajustado. La diferencia de inventario es
        // fís − contable: positiva = sobrante (combustible que no costó y se
        // venderá), negativa = faltante; por eso se SUMA con su signo.
        $cascada = null;
        if ($tot['margen'] !== null) {
            $cascada = [
                'venta'      => $tot['venta'],
                'costo'      => $tot['costo'],
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
            'diario'      => self::diario($datos, $tasaEst, $costoEF),
            'cascada'     => $cascada,
            'sin_datos'   => $sinDatos,
            'atipicas'    => $atipicas,
        ];
    }

    /**
     * Una fila por proveedor: litros y $/L por familia, facturado, descuentos
     * y sobrecosto contra el proveedor más barato de la misma zona y familia
     * (con volumen mínimo, ver MIN_PCT_REFERENCIA).
     */
    private static function proveedores(array $datos, array $zonaEst): array
    {
        $sinFactura = VentasRentabilidadModel::SIN_FACTURA;

        // Agrupado por zona × familia × proveedor
        $grupo = [];
        foreach ($datos['proveedores'] as $r) {
            $zona = $zonaEst[$r['codgas']] ?? 'marca_prots';
            $g = &$grupo[$zona][$r['familia']][$r['proveedor']];
            $g['litros'] = ($g['litros'] ?? 0) + $r['litros'];
            $g['costo']  = ($g['costo'] ?? 0) + $r['costo'];
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
     * Serie diaria por familia y total: litros, precio de venta sin IVA por
     * litro, precio de compra del día ($/L de las descargas con precio) y
     * margen del día (venta sin IVA − litros × costo promedio del mes).
     */
    private static function diario(array $datos, array $tasaEst, array $costoEF): array
    {
        $serie = [];
        $fechas = array_unique(array_merge(array_keys($datos['diario']), array_keys($datos['compras_dia'])));
        sort($fechas);

        foreach ($fechas as $fecha) {
            $dia = ['fecha' => $fecha, 'dia' => (int) substr($fecha, 8, 2)];
            $tot = ['litros' => 0.0, 'venta' => 0.0, 'costo' => 0.0, 'c_litros' => 0.0, 'c_costo' => 0.0];
            foreach (array_keys(MermaDiariaModel::FAMILIAS) as $fam) {
                $v = ['litros' => 0.0, 'venta' => 0.0, 'costo' => 0.0];
                foreach ($datos['diario'][$fecha] ?? [] as $cod => $porFam) {
                    if (!isset($porFam[$fam], $tasaEst[$cod]) || ($costoEF[$cod][$fam] ?? null) === null) continue;
                    $v['litros'] += $porFam[$fam]['litros'];
                    $v['venta']  += $porFam[$fam]['pesos'] / (1 + $tasaEst[$cod]);
                    $v['costo']  += $porFam[$fam]['litros'] * $costoEF[$cod][$fam];
                }
                $c = $datos['compras_dia'][$fecha][$fam] ?? ['litros' => 0.0, 'costo' => 0.0];
                $dia[$fam] = self::puntoDiario($v, $c['litros'], $c['costo']);
                foreach (['litros', 'venta', 'costo'] as $k) $tot[$k] += $v[$k];
                $tot['c_litros'] += $c['litros'];
                $tot['c_costo']  += $c['costo'];
            }
            $dia['total'] = self::puntoDiario($tot, $tot['c_litros'], $tot['c_costo']);
            $serie[] = $dia;
        }
        return $serie;
    }

    private static function puntoDiario(array $v, float $cLitros, float $cCosto): array
    {
        return [
            'litros'        => round($v['litros']),
            'precio_venta'  => $v['litros'] > 0 ? round($v['venta'] / $v['litros'], 3) : null,
            'precio_compra' => $cLitros > 0 ? round($cCosto / $cLitros, 3) : null,
            'margen'        => $v['litros'] > 0 ? round($v['venta'] - $v['costo']) : null,
        ];
    }

    /** Agrega margen, margen por litro y % sobre la venta sin IVA. */
    private static function metricas(array $v): array
    {
        $margen = $v['litros'] > 0 ? $v['venta'] - $v['costo'] : null;
        return $v + [
            'margen'       => $margen,
            'margen_litro' => $margen !== null ? $margen / $v['litros'] : null,
            'margen_pct'   => ($margen !== null && $v['venta'] > 0) ? $margen / $v['venta'] * 100 : null,
        ];
    }
}
