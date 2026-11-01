<?php
/**
 * Columnas calculadas del registro de compras: port de las fórmulas de la hoja
 * COMPRAS de COMPRAS 2026.xlsx (Tabla2). Cada método referencia la columna del
 * Excel. Los parámetros (IEPS, estímulo, almacenaje) salían de las hojas
 * Matriz, Estimulos y Costo Almacenaje.
 *
 * Plan: docs/superpowers/specs/2026-10-27-compras-registro-plan.md
 * Prueba: php tests/ComprasCalculosTest.php
 */
class ComprasCalculos
{
    // IEPS por litro, por año de la fecha de descarga (hoja Matriz, tablas IEPS 2026 / IEPS 2025).
    private const IEPS = [
        2026 => ['REGULAR' => 0.59139,  'SUPER' => 0.721605, 'DIESEL' => 0.490817],
        2025 => ['REGULAR' => 0.569795, 'SUPER' => 0.695255, 'DIESEL' => 0.472895],
    ];

    // Estímulo por litro (hoja Estimulos). Solo regular y super; diésel no tiene (VLOOKUP falla -> 0).
    private const ESTIMULO = ['REGULAR' => 3.41, 'SUPER' => 2.86];

    // Costo de almacenamiento por litro cuando factura Petrotal (hoja Costo Almacenaje, 2026: igual todos los meses).
    private const ALMACENAJE_2026 = ['REGULAR' => 0.09038, 'SUPER' => 0.09038, 'DIESEL' => 0.148887585];

    private const MESES = [1 => 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];

    /**
     * Zona (col G). Las estaciones de fuera de Juárez (Aguascalientes, Villa Ahumada, Delicias, Parral,
     * San Miguel de Allende) son "Foranea"; el resto lleva su número de zona (vacío si no tiene).
     * Sin ciudad (estación no identificada) devuelve null.
     */
    public static function zona(?string $ciudad, $zonaNum): ?string
    {
        if ($ciudad === null || $ciudad === '') {
            return null;
        }
        if (preg_match('/aguascalientes|ahumada|delicias|parral|san miguel/i', $ciudad)) {
            return 'Foranea';
        }
        return $zonaNum === null ? '' : (string)(int)$zonaNum;
    }

    public static function ieps(?string $producto, int $anio): ?float
    {
        if ($producto === null) {
            return null;
        }
        $tabla = self::IEPS[$anio] ?? ($anio > 2026 ? self::IEPS[2026] : self::IEPS[2025]);
        return $tabla[$producto] ?? null;
    }

    /**
     * Agrega las columnas calculadas a un renglón.
     *
     * Entradas usadas del renglón: fecha, fecha_descarga, producto, litros, monto, iva, zona (ya calculada),
     * proveedor_final, monto_pagado, precio_venta, factura_petrotal, monto_petrotal, iva_cliente_final,
     * en_controlgas. Columnas que dependen de precio cotizado (R) y TAR PEMEX (AB) quedan en null:
     * esos precios están fuera de alcance.
     */
    public static function calcular(array $r): array
    {
        $fechaRef = $r['fecha_descarga'] ?: $r['fecha'];
        $ts       = strtotime($fechaRef);
        $anio     = (int)date('Y', $ts);
        $litros   = (float)$r['litros'];
        $monto    = (float)$r['monto'];
        $iva      = $r['iva'] !== null ? (float)$r['iva'] : null;
        $prod     = $r['producto'];
        $zona     = $r['zona'];

        $r['anio'] = $anio;
        $r['mes']  = self::MESES[(int)date('n', $ts)];

        // P saldo
        $r['saldo'] = round($monto - (float)($r['monto_pagado'] ?? 0), 2);

        // Q precio por litro facturado
        $q = $litros > 0 ? $monto / $litros : null;
        $r['precio_litro'] = $q !== null ? round($q, 4) : null;

        // V estímulo (solo Cd. Juárez)
        $v = ($zona === 'Foranea' || $zona === null) ? 0.0 : (self::ESTIMULO[$prod] ?? 0.0);
        $r['estimulo'] = $v;

        // X costo de almacenamiento (solo si el proveedor final es Petrotal)
        $final = (string)($r['proveedor_final'] ?? '');
        $x = 0.0;
        if (stripos($final, 'PETROTAL') === 0 && $prod !== null) {
            $x = $anio === 2026 ? (self::ALMACENAJE_2026[$prod] ?? 0.0) : 0.0;
        }
        $r['costo_almacen'] = $x;

        // AY..BD IEPS y precios derivados
        $ieps = self::ieps($prod, $anio);
        $r['ieps']          = $ieps;
        $r['ieps_total']    = $ieps !== null ? round($ieps * $litros, 2) : null;
        $menosIeps          = ($q !== null && $ieps !== null) ? $q - $ieps : null;
        $r['menos_ieps']    = $menosIeps !== null ? round($menosIeps, 6) : null;
        $sinIva16           = $menosIeps !== null ? $menosIeps / 1.16 : null;
        $r['precio_sin_iva16'] = $sinIva16 !== null ? round($sinIva16, 6) : null;
        $por08              = $sinIva16 !== null ? $sinIva16 * 1.08 : null;
        $r['por_iva08']     = $por08 !== null ? round($por08, 6) : null;
        $aa                 = ($por08 !== null && $ieps !== null) ? $por08 + $ieps : null;   // BD = AA
        $r['precio_c_iva8_ieps'] = $aa !== null ? round($aa, 6) : null;
        $r['precio_8']      = $r['precio_c_iva8_ieps'];

        // Y utilidad por litro / Z utilidad por factura
        $u = $r['precio_venta'] !== null ? (float)$r['precio_venta'] : null;
        $y = null;
        if ($monto > 0 && $u !== null && $q !== null) {
            if ($iva !== null && abs($iva - 0.08) < 0.0001) {
                $y = (($u - $q - $x) / 1.08) + $v;
            } elseif ($zona === 'Foranea') {
                $y = ($u - $q - $x) / 1.16;
            } elseif ($aa !== null) {
                $y = (($u - $aa - $x) / 1.08) + $v;
            }
        } elseif ($monto <= 0) {
            $y = 0.0;
        }
        $r['utilidad_litro']   = $y !== null ? round($y, 6) : null;
        $r['utilidad_factura'] = $y !== null ? round($y * $litros, 2) : null;

        // R/S/T y AB/AC: precio cotizado y TAR PEMEX fuera de alcance
        $r['precio_cotizado'] = null;
        $r['dif_cotizado']    = null;
        $r['precio_factura_cotizado'] = null;
        $r['tar_pemex']       = null;
        $r['dif_tar']         = null;

        // AK..AN litros
        $facturadoPetrotal = trim((string)($r['factura_petrotal'] ?? '')) !== '';
        $ak = $facturadoPetrotal ? $litros : 0.0;
        $am = !empty($r['en_controlgas']) ? $litros : 0.0;
        $r['litros_facturados']      = $ak;
        $r['litros_pend_facturar']   = round($litros - $ak, 3);
        $r['litros_controlgas']      = $am;
        $r['litros_pend_controlgas'] = round($ak - $am, 3);

        // BE..BG montos sin IVA y utilidad Petrotal
        $be = $iva !== null ? ($monto - (float)$r['ieps_total']) / (1 + $iva) : null;
        $r['monto_sin_iva'] = $be !== null && $r['ieps_total'] !== null ? round($be, 2) : null;
        $ah = $r['monto_petrotal'] !== null ? (float)$r['monto_petrotal'] : null;
        $ivaCf = $r['iva_cliente_final'] !== null ? (float)$r['iva_cliente_final'] : null;
        $r['monto_petrotal_sin_iva'] = ($ah !== null && $ivaCf !== null && $r['ieps_total'] !== null)
            ? round(($ah - (float)$r['ieps_total']) / (1 + $ivaCf), 2) : null;
        $r['utilidad_petrotal'] = ($ah !== null && $r['monto_sin_iva'] !== null) ? round($ah - $r['monto_sin_iva'], 2) : null;

        return $r;
    }
}
