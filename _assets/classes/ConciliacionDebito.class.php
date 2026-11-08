<?php
declare(strict_types=1);

/**
 * Lógica pura de la conciliación débito (sin BD, sin Twig).
 * Los textos de estatus son constantes públicas porque la vista los reutiliza.
 */
class ConciliacionDebito
{
    const PERIODO_DEL_MES = 'Del mes';
    const PERIODO_MES_SIGUIENTE = 'Mes siguiente';
    const PERIODO_MES_ANTERIOR = 'Mes anterior';
    const PERIODO_SIN = 'Sin periodo';

    const EST_NO_SE_FACTURA = 'No se factura';
    const EST_FACTURADO = 'Facturado';
    const EST_REDONDEO = 'Redondeo';
    const EST_MES_SIGUIENTE = 'Se factura al mes siguiente';
    const EST_CANCELADA = 'Factura cancelada';
    const EST_BASE_CORTES = 'Facturado con base en cortes';
    const EST_SIN_CORTE = 'Consumo sin corte';
    const EST_SIN_FACTURA = 'Consumo sin factura';
    const EST_DE_MENOS = 'Facturado de menos';
    const EST_DE_MAS = 'Facturado de más';

    const MOV_RETIRO = 'Retiro';
    const MOV_PREPAGO = 'Cobro prepago';
    const MOV_CREDITO = 'Cobro crédito';
    const MOV_TRASPASO = 'Traspaso entre cuentas';
    const MOV_NO_IDENTIFICADO = 'No identificado';

    private const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    /** Minúsculas y sin acentos. */
    private static function normalizar(string $s): string
    {
        return strtr(mb_strtolower($s, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    }

    /**
     * Periodo al que pertenece una factura global respecto al mes solicitado.
     * El llamador debe descartar facturas con fecha anterior al mes pedido o posterior al mes pedido que no sean
     * 'Mes siguiente'; 'Sin periodo' cuenta como del mes solo para facturas emitidas dentro del mes.
     */
    public static function periodoFactura(string $txtref, string $mes, string $fechaFactura): string
    {
        if (!preg_match('/@N:\s*Consumos\s+(\p{L}+)(?:\s+Diesel)?\s+(\d{4})/iu', $txtref, $m)) {
            return self::PERIODO_SIN;
        }
        $num = self::MESES[self::normalizar($m[1])] ?? null;
        if ($num === null || !preg_match('/^(\d{4})-(\d{2})$/', $mes, $pm)) {
            return self::PERIODO_SIN;
        }
        $periodo = sprintf('%04d-%02d', (int)$m[2], $num);
        $mesAnterior = date('Y-m', mktime(0, 0, 0, (int)$pm[2] - 1, 1, (int)$pm[1]));
        $mesFactura = substr($fechaFactura, 0, 7);

        if ($periodo === $mes) {
            if ($mesFactura === $mes) return self::PERIODO_DEL_MES;
            if ($mesFactura > $mes) return self::PERIODO_MES_SIGUIENTE;
        } elseif ($periodo === $mesAnterior && $mesFactura === $mes) {
            return self::PERIODO_MES_ANTERIOR;
        }
        return self::PERIODO_SIN;
    }

    /** Extrae [['nro'=>int,'importe'=>float]] de "Fac.D242940 ($3,500.00); ...". */
    public static function facturasDeReferencia(string $referencia): array
    {
        $out = [];
        if (!preg_match_all('/Fac\.([A-Za-z])(\d+)(?:\s*\(\s*(-?\$?-?[\d,]*\.?\d*)\))?/', $referencia, $ms, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($ms as $m) {
            $serie = strtoupper($m[1]);
            if ($serie === 'D') $base = 1200000000;
            elseif ($serie === 'E') $base = 1300000000;
            else continue;
            $out[] = [
                'nro' => $base + (int)$m[2],
                'importe' => (float)str_replace([',', '$'], '', $m[3] ?? '0'),
            ];
        }
        return $out;
    }

    const PERIODO_CANCELADA = 'Cancelada';

    /** Clase del cliente según su categoría de consumo propio ('' = Venta; con "bonificación" = Bonificación). */
    public static function clasePorCategoria(string $categoria): string
    {
        $c = trim($categoria);
        if ($c === '') return 'Venta';
        return strpos(self::normalizar($c), 'bonificacion') !== false ? 'Bonificación' : 'Consumo propio';
    }

    /**
     * Pone 'periodo' a cada factura Serie E (filas de ConciliacionDebitoModel::facturas_serie_e) y descarta las que
     * no son del mes pedido: fecha anterior al primer día, o posterior al último que no sea 'Mes siguiente'.
     * Las canceladas quedan con periodo 'Cancelada'.
     */
    public static function clasificarFacturas(array $facturas, string $mes): array
    {
        $primero = $mes . '-01';
        $ultimo = date('Y-m-t', strtotime($primero));
        $out = [];
        foreach ($facturas as $f) {
            $fecha = (string)($f['fecha'] ?? '');
            if ($fecha < $primero) continue;
            $periodo = self::periodoFactura((string)($f['txtref'] ?? ''), $mes, $fecha);
            if ($fecha > $ultimo && $periodo !== self::PERIODO_MES_SIGUIENTE) continue;
            $f['periodo'] = (int)($f['cancelada'] ?? 0) === 1 ? self::PERIODO_CANCELADA : $periodo;
            $out[] = $f;
        }
        return $out;
    }

    /**
     * Una fila por cliente: consumos (get_dispatches_summary_by_client) + facturas ya clasificadas.
     * Cancelada suma a 'cancelada' y no a 'facturado_*'; Mes anterior / Mes siguiente van a su columna;
     * Del mes y Sin periodo cuentan como del mes. Clientes solo con facturas entran con monto 0.
     */
    public static function ventaPorCliente(array $consumos, array $facturasClasificadas): array
    {
        $por = [];
        foreach ($consumos as $r) {
            $id = (int)$r['codcli'];
            $por[$id] = [
                'codcli' => $id,
                'cliente' => (string)$r['Cliente'],
                'clase' => self::clasePorCategoria((string)($r['Categoria'] ?? '')),
                'despachos' => (int)$r['Despachos'],
                'litros' => (float)$r['Litros'],
                'monto' => (float)$r['Monto'],
                'cortes' => (float)$r['MontoCortes'],
                'facturado_mes' => 0.0, 'facturado_mes_anterior' => 0.0, 'facturado_siguiente' => 0.0,
                'cancelada' => 0.0, 'facturas' => 0,
            ];
        }
        foreach ($facturasClasificadas as $f) {
            $id = (int)$f['codcli'];
            if (!isset($por[$id])) {
                $por[$id] = [
                    'codcli' => $id, 'cliente' => trim((string)($f['cliente'] ?? '')) ?: '(Cliente ' . $id . ')',
                    'clase' => 'Venta', 'despachos' => 0, 'litros' => 0.0, 'monto' => 0.0, 'cortes' => 0.0,
                    'facturado_mes' => 0.0, 'facturado_mes_anterior' => 0.0, 'facturado_siguiente' => 0.0,
                    'cancelada' => 0.0, 'facturas' => 0,
                ];
            }
            $total = (float)$f['total'];
            switch ($f['periodo']) {
                case self::PERIODO_CANCELADA: $por[$id]['cancelada'] += $total; continue 2;
                case self::PERIODO_MES_ANTERIOR: $por[$id]['facturado_mes_anterior'] += $total; break;
                case self::PERIODO_MES_SIGUIENTE: $por[$id]['facturado_siguiente'] += $total; break;
                default: $por[$id]['facturado_mes'] += $total;
            }
            $por[$id]['facturas']++;
        }
        $out = [];
        foreach ($por as $c) {
            foreach (['litros', 'monto', 'cortes', 'facturado_mes', 'facturado_mes_anterior', 'facturado_siguiente', 'cancelada'] as $k) {
                $c[$k] = round($c[$k], $k === 'litros' ? 3 : 2);
            }
            $c['dif_cortes'] = round($c['monto'] - $c['cortes'], 2);
            $c['diferencia'] = round($c['monto'] - $c['facturado_mes'], 2);
            $c['estatus'] = self::estatusCliente($c);
            $out[] = $c;
        }
        usort($out, static fn($a, $b) => $b['monto'] <=> $a['monto']);
        return $out;
    }

    public static function estatusCliente(array $c): string
    {
        $monto = (float)($c['monto'] ?? 0);
        $cortes = (float)($c['cortes'] ?? 0);
        $fm = (float)($c['facturado_mes'] ?? 0);
        $fs = (float)($c['facturado_siguiente'] ?? 0);
        $canc = (float)($c['cancelada'] ?? 0);
        $dif = round($monto - $fm, 6);

        if (($c['clase'] ?? 'Venta') !== 'Venta') return self::EST_NO_SE_FACTURA;
        // monto 0 con cortes y sin factura no es "Facturado": cae en Consumo sin factura
        $sinFacturaConCortes = $fm == 0.0 && $cortes > 0 && abs($monto) < 1;
        if (!$sinFacturaConCortes) {
            if (abs($dif) < 0.01) return self::EST_FACTURADO;
            if (abs($dif) < 1) return self::EST_REDONDEO;
        }
        if ($dif > 0 && $fs > 0 && abs($dif - $fs) < 1) return self::EST_MES_SIGUIENTE;
        if ($canc > 0 && $dif >= 1) return self::EST_CANCELADA;
        if (abs($fm - $cortes) < 0.01 && $fm > 0 && abs($monto - $cortes) >= 0.01) return self::EST_BASE_CORTES;
        if ($cortes == 0.0 && $fm == 0.0) return self::EST_SIN_CORTE;
        if ($fm == 0.0) return self::EST_SIN_FACTURA;
        if ($dif > 0) return self::EST_DE_MENOS;
        return self::EST_DE_MAS;
    }

    public static function clasificarMovimiento(array $mov, array $idsPrepago, array $idsCredito, array $cuentasPropias): string
    {
        if ((float)($mov['abono'] ?? 0) <= 0) return self::MOV_RETIRO;
        $id = $mov['id'] ?? null;
        if (in_array($id, $idsPrepago, false)) return self::MOV_PREPAGO;
        if (in_array($id, $idsCredito, false)) return self::MOV_CREDITO;
        $texto = ($mov['detalle'] ?? '') . ' ' . ($mov['contraparte'] ?? '');
        foreach ($cuentasPropias as $cta) {
            $cta = (string)$cta;
            if (strlen($cta) >= 6 && strpos($texto, $cta) !== false) return self::MOV_TRASPASO;
        }
        return self::MOV_NO_IDENTIFICADO;
    }

    const ANT_SI = 'Sí';
    const ANT_NO = 'No';
    const ANT_SIN_FACTURA = 'Sin factura en referencia';

    /**
     * Una fila por (cobro prepago, factura que paga). Las facturas salen de DocumentosA (`facturas` = "nroapl:mto,…",
     * solo series D y E: DocumentosA también trae el vale/consumo de ControlGas, nro < 1,200,000,000) y, si el cobro
     * no trae ninguna, de la Referencia (txtref, "Fac.D242940 ($3,500.00)"). Un cobro sin facturas genera UNA fila
     * con existe_en_anticipos = ANT_SIN_FACTURA e importe = monto del cobro.
     * $nrosAnticipos: números de factura de los anticipos del mes (ConciliacionDebitoModel::anticipos).
     * Filas: cobro, fecha, codcli, cliente, monto (del cobro), nro_factura (int|null), importe, existe_en_anticipos.
     */
    public static function cobrosPorFactura(array $cobros, array $nrosAnticipos): array
    {
        $enMes = array_fill_keys(array_map('intval', $nrosAnticipos), true);
        $out = [];
        foreach ($cobros as $c) {
            $facturas = [];
            foreach (array_filter(explode(',', (string)($c['facturas'] ?? ''))) as $par) {
                [$nro, $mto] = array_pad(explode(':', $par), 2, '0');
                $nro = (int)$nro;
                if ($nro >= 1200000000 && $nro < 1400000000) $facturas[] = ['nro' => $nro, 'importe' => (float)$mto];
            }
            if (!$facturas) $facturas = self::facturasDeReferencia((string)($c['txtref'] ?? ''));
            $base = [
                'cobro' => (int)$c['nro'], 'fecha' => (string)$c['fecha'], 'codcli' => (int)($c['codopr'] ?? 0),
                'cliente' => trim((string)($c['cliente'] ?? '')), 'monto' => round((float)$c['monto'], 2),
            ];
            if (!$facturas) {
                $out[] = $base + ['nro_factura' => null, 'importe' => $base['monto'], 'existe_en_anticipos' => self::ANT_SIN_FACTURA];
                continue;
            }
            foreach ($facturas as $f) {
                $out[] = $base + [
                    'nro_factura' => $f['nro'], 'importe' => round((float)$f['importe'], 2),
                    'existe_en_anticipos' => isset($enMes[$f['nro']]) ? self::ANT_SI : self::ANT_NO,
                ];
            }
        }
        return $out;
    }

    /**
     * Partidas del card B (cobros prepago vs anticipos facturados):
     *   diferencia = cobros − anticipos = otro_mes + sin_factura + anticipos_sin_cobro + sin_explicar.
     * $anticipos: filas de ConciliacionDebitoModel::anticipos (Factura, Total, cobro_aplicado, estatus).
     * $cobrosFactura: salida de cobrosPorFactura. $canceladas: número de anticipos cancelados del mes (solo se cuentan:
     * no conservan importe). $cobros: total de los cobros prepago. Importes con el signo con que explican la diferencia.
     */
    public static function partidasAnticipos(array $anticipos, array $cobrosFactura, int $canceladas, float $cobros): array
    {
        $totalAnticipos = 0.0;
        $sinCobro = ['n' => 0, 'importe' => 0.0, 'filas' => []];
        foreach ($anticipos as $a) {
            $total = (float)$a['Total'];
            $totalAnticipos += $total;
            if (($a['estatus'] ?? '') === 'Sin cobro') {
                $resta = round($total - (float)($a['cobro_aplicado'] ?? 0), 2);
                $sinCobro['n']++;
                $sinCobro['importe'] -= $resta;
                $sinCobro['filas'][] = ['nro' => (int)$a['Factura'], 'importe' => -$resta];
            }
        }
        $otroMes = ['n' => 0, 'importe' => 0.0, 'filas' => []];
        $sinFactura = ['n' => 0, 'importe' => 0.0, 'filas' => []];
        foreach ($cobrosFactura as $r) {
            if ($r['existe_en_anticipos'] === self::ANT_NO) {
                $otroMes['n']++; $otroMes['importe'] += $r['importe'];
                $otroMes['filas'][] = ['nro' => (int)$r['nro_factura'], 'cobro' => $r['cobro'], 'importe' => $r['importe']];
            } elseif ($r['existe_en_anticipos'] === self::ANT_SIN_FACTURA) {
                $sinFactura['n']++; $sinFactura['importe'] += $r['importe'];
                $sinFactura['filas'][] = ['cobro' => $r['cobro'], 'importe' => $r['importe']];
            }
        }
        $totalAnticipos = round($totalAnticipos, 2);
        $diferencia = round($cobros - $totalAnticipos, 2);
        $otroMes['importe'] = round($otroMes['importe'], 2);
        $sinFactura['importe'] = round($sinFactura['importe'], 2);
        $sinCobro['importe'] = round($sinCobro['importe'], 2);
        return [
            'cobros' => round($cobros, 2),
            'anticipos' => $totalAnticipos,
            'diferencia' => $diferencia,
            'otro_mes' => $otroMes,
            'sin_factura' => $sinFactura,
            'anticipos_sin_cobro' => $sinCobro,
            'canceladas' => $canceladas,
            'sin_explicar' => round($diferencia - $otroMes['importe'] - $sinFactura['importe'] - $sinCobro['importe'], 2),
        ];
    }
}
