<?php
declare(strict_types=1);

/**
 * Lectura de la lista de pagos que Tesorería recibe como texto (WhatsApp) para
 * armar layouts bancarios sin que los pagos estén registrados en el sistema.
 *
 * Formato esperado, una línea por pago (columnas separadas por espacios/tabs):
 *
 *     1948   ALTOS ENERGETICOS MEXICANOS   968,530.63   FORMULAGAS
 *     C1277  PREMIERGAS S.A. P. I. DE C.V. 709,057.79   DIAZ GAS
 *     1962   MGC MEXICO S.A. DE C.V.       2,118,708.94 GASOMEX   88400000001
 *
 * y opcionalmente un bloque de totales por empresa (comprobación):
 *
 *     FORMULAGAS   1,706,996.24
 *
 * Lógica pura (sin BD): los catálogos llegan como arreglos cod => nombre.
 * Pruebas: php tests/LayoutTextoTest.php
 */
class LayoutTexto
{
    /** Importe con dos decimales, con o sin separador de miles. */
    private const MONTO = '(?:\d{1,3}(?:,\d{3})+|\d+)\.\d{2}';

    /** Número de requisición/orden: dígitos con prefijo opcional de letras (C1277). */
    private const NUMERO = '[A-Za-z]{0,3}\d+';

    /** Partículas de razón social que no distinguen a una empresa de otra. */
    private const RUIDO = [
        'S', 'A', 'SA', 'DE', 'C', 'V', 'CV', 'P', 'I', 'SAPI', 'R', 'L', 'RL', 'SRL',
        'SC', 'SAB', 'AND', 'Y',
    ];

    /**
     * Separa el texto en líneas de pago, totales por empresa y líneas que no
     * se pudieron interpretar.
     *
     * @return array{lineas: array<int, array>, totales: array<int, array>, no_leidas: array<int, array>}
     */
    public static function parse(string $texto): array
    {
        $lineas = [];
        $totales = [];
        $noLeidas = [];

        $filas = preg_split('/\r\n|\r|\n/', $texto) ?: [];
        foreach ($filas as $i => $cruda) {
            // Tabs y espacios duros (WhatsApp/Excel) como espacio normal.
            $fila = trim((string)preg_replace('/[\t\x{00A0}\x{2007}\x{202F}]+/u', ' ', $cruda));
            // Prefijo de WhatsApp al copiar el mensaje: "[10/11/26, 9:15 a.m.] Nombre: "
            $fila = trim((string)preg_replace('/^\[[^\]]{4,40}\]\s*[^:]{1,60}:\s*/u', '', $fila));
            if ($fila === '') {
                continue;
            }

            $reDetalle = '/^(' . self::NUMERO . ')\s+(.+?)\s+\$?\s*(' . self::MONTO . ')\s+(.+?)(?:\s+(\d{6,}))?$/u';
            if (preg_match($reDetalle, $fila, $m)) {
                $lineas[] = [
                    'renglon'    => $i + 1,
                    'numero'     => strtoupper($m[1]),
                    'proveedor'  => trim($m[2]),
                    'monto'      => self::monto($m[3]),
                    'empresa'    => trim($m[4]),
                    'referencia' => $m[5] ?? '',
                ];
                continue;
            }

            $reTotal = '/^(.+?)\s+\$?\s*(' . self::MONTO . ')$/u';
            if (preg_match($reTotal, $fila, $m)) {
                $nombre = trim($m[1]);
                if (preg_match('/^' . self::NUMERO . '\s/', $nombre)) {
                    // Trae número y monto pero nada después: le falta la empresa.
                    $noLeidas[] = ['renglon' => $i + 1, 'texto' => $fila, 'motivo' => 'Falta la empresa después del monto'];
                } else {
                    $totales[] = ['renglon' => $i + 1, 'empresa' => $nombre, 'monto' => self::monto($m[2])];
                }
                continue;
            }

            $noLeidas[] = ['renglon' => $i + 1, 'texto' => $fila, 'motivo' => 'No tiene el formato número, proveedor, monto, empresa'];
        }

        return ['lineas' => $lineas, 'totales' => $totales, 'no_leidas' => $noLeidas];
    }

    /** "13,856,423.32" -> 13856423.32 */
    public static function monto(string $txt): float
    {
        return round((float)str_replace(',', '', $txt), 2);
    }

    /**
     * Nombre comparable: mayúsculas, sin acentos ni puntuación y sin las
     * partículas de razón social ("S.A. DE C.V.", "S. DE R.L.", "&").
     */
    public static function normalizar(string $nombre): string
    {
        $s = strtr($nombre, [
            'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U', 'ü' => 'U', 'ñ' => 'N',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
        $s = strtoupper($s);
        $s = (string)preg_replace('/[^A-Z0-9]+/', ' ', $s);
        $tokens = array_filter(
            explode(' ', trim($s)),
            fn($t) => $t !== '' && !in_array($t, self::RUIDO, true)
        );
        return implode(' ', $tokens);
    }

    /**
     * Busca un nombre escrito a mano dentro de un catálogo cod => nombre.
     * Orden: igualdad exacta, luego el texto contenido en el nombre del
     * catálogo (GASOMEX -> DISTRIBUIDORA GASO MEX), luego al revés.
     * Solo acepta el resultado si es único.
     *
     * @param array<int|string, string> $catalogo
     * @return array{cod: int|string|null, nombre: ?string, motivo: ?string}
     */
    public static function resolver(string $texto, array $catalogo): array
    {
        $norm = self::normalizar($texto);
        $junto = str_replace(' ', '', $norm);
        if (strlen($junto) < 3) {
            return ['cod' => null, 'nombre' => null, 'motivo' => 'nombre demasiado corto'];
        }

        $exactos = [];
        $contenidos = [];
        $inversos = [];
        foreach ($catalogo as $cod => $nombre) {
            $n = self::normalizar((string)$nombre);
            $j = str_replace(' ', '', $n);
            if ($j === '') {
                continue;
            }
            if ($j === $junto) {
                $exactos[$cod] = $nombre;
            } elseif (strpos($j, $junto) !== false) {
                $contenidos[$cod] = $nombre;
            } elseif (strlen($j) >= 4 && strpos($junto, $j) !== false) {
                $inversos[$cod] = $nombre;
            }
        }

        foreach ([$exactos, $contenidos, $inversos] as $candidatos) {
            if (count($candidatos) === 1) {
                $cod = array_key_first($candidatos);
                return ['cod' => $cod, 'nombre' => trim((string)$candidatos[$cod]), 'motivo' => null];
            }
            if (count($candidatos) > 1) {
                $nombres = array_slice(array_map('trim', array_values($candidatos)), 0, 4);
                return ['cod' => null, 'nombre' => null, 'motivo' => 'coincide con varios: ' . implode(' / ', $nombres)];
            }
        }
        return ['cod' => null, 'nombre' => null, 'motivo' => 'no existe en el catálogo'];
    }

    /**
     * Compara el bloque de totales por empresa contra la suma de las líneas.
     * Devuelve una advertencia por cada empresa que no cuadra.
     *
     * @return string[]
     */
    public static function verificarTotales(array $lineas, array $totales): array
    {
        if (!$totales) {
            return [];
        }
        $sumas = [];
        foreach ($lineas as $l) {
            $k = str_replace(' ', '', self::normalizar($l['empresa']));
            $sumas[$k] = ($sumas[$k] ?? 0) + $l['monto'];
        }

        $avisos = [];
        $vistos = [];
        foreach ($totales as $t) {
            $k = str_replace(' ', '', self::normalizar($t['empresa']));
            $vistos[$k] = true;
            if (!isset($sumas[$k])) {
                $avisos[] = sprintf('El total de %s (%s) no tiene líneas de pago arriba.', $t['empresa'], self::fmt($t['monto']));
                continue;
            }
            if (abs($sumas[$k] - $t['monto']) > 0.01) {
                $avisos[] = sprintf(
                    'El total de %s dice %s pero sus líneas suman %s (diferencia %s).',
                    $t['empresa'], self::fmt($t['monto']), self::fmt($sumas[$k]), self::fmt($sumas[$k] - $t['monto'])
                );
            }
        }
        foreach ($lineas as $l) {
            $k = str_replace(' ', '', self::normalizar($l['empresa']));
            if (!isset($vistos[$k])) {
                $vistos[$k] = true;
                $avisos[] = sprintf('%s tiene líneas de pago pero no aparece en los totales.', $l['empresa']);
            }
        }
        return $avisos;
    }

    public static function fmt(float $monto): string
    {
        return '$' . number_format($monto, 2, '.', ',');
    }
}
