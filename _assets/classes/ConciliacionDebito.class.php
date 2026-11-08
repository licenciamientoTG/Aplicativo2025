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
}
