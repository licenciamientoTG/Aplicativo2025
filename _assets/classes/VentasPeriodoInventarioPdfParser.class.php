<?php

/**
 * Extrae inventario/ventas/compras diarios por producto del reporte
 * "Ventas Periodo Inventario" (E.S. 13792 - Colosio, Repsol Aguascalientes)
 * para esta estación sin sync automático de merma vía ApiER.
 *
 * Usa el binario pdftotext (Poppler) empaquetado en _assets/bin/poppler/,
 * mismo mecanismo que BalanceProductoPdfParser / NotaCreditoPdfParser.
 *
 * Uso: VentasPeriodoInventarioPdfParser::parse($rutaPdf, $nombreArchivo)
 */
class VentasPeriodoInventarioPdfParser
{
    /** Filas de producto esperadas -> codprd base (ver MermaDiariaModel::FAMILIAS). Colosio no vende Diesel. */
    const PRODUCTOS = [
        'Efitec 87' => ['codprd' => 1, 'producto' => 'MAXIMA'],
        'Efitec 92' => ['codprd' => 2, 'producto' => 'SUPER'],
    ];

    public static function parse(string $rutaPdf, string $nombreArchivo = ''): array
    {
        $base = [
            'archivo' => $nombreArchivo,
            'ok'      => false,
            'error'   => null,
            'fecha'   => '',
            'filas'   => [],
        ];

        $texto = self::extraerTexto($rutaPdf);
        if ($texto === null) {
            $base['error'] = 'No se pudo leer el PDF (pdftotext)';
            return $base;
        }

        if (!preg_match('/Fecha Inicio:\s*(\d{2})\/(\d{2})\/(\d{4})\s*-\s*Fecha Fin:\s*(\d{2})\/(\d{2})\/(\d{4})/', $texto, $mFecha)) {
            $base['error'] = 'No se encontraron las fechas del encabezado';
            return $base;
        }
        $inicio = "{$mFecha[3]}-{$mFecha[2]}-{$mFecha[1]}";
        $fin    = "{$mFecha[6]}-{$mFecha[5]}-{$mFecha[4]}";
        if ($inicio !== $fin) {
            $base['error'] = 'El PDF cubre un rango (Fecha Inicio != Fecha Fin), se esperaba un solo día';
            return $base;
        }

        $fecha = $inicio;
        $filas = [];
        foreach (self::PRODUCTOS as $nombreProd => $meta) {
            $fila = self::extraerFilaProducto($texto, $nombreProd);
            if ($fila !== null) {
                $filas[] = [
                    'codprd'        => $meta['codprd'],
                    'producto'      => $meta['producto'],
                    // Fin Real: inventario físico de cierre del día (columna
                    // "Fin Real" del reporte, distinto del "Fin" contable).
                    'inv_fisico'    => $fila['fin_real'],
                    'ventas_reales' => $fila['ventas'],
                    'compras'       => $fila['compras'],
                    'inv_inicial'   => $fila['inicio'],
                ];
            }
        }

        if (empty($filas)) {
            $base['error'] = 'El PDF no trae datos numéricos en ninguna familia de producto';
            return $base;
        }

        $base['ok']    = true;
        $base['fecha'] = $fecha;
        $base['filas'] = $filas;
        return $base;
    }

    /**
     * Ubica la línea de un producto (p.ej. "1 Efitec 87   15,242.371 ...")
     * y extrae sus valores numéricos. Columnas del reporte, en orden:
     * Inicio, Compras, Ajuste Entrada, Jarras, Ventas, Precio, Importe,
     * Fin, Fin Real, Merma, % Merma, % Compras.
     */
    private static function extraerFilaProducto(string $texto, string $nombreProd): ?array
    {
        $nombreEsc = preg_quote($nombreProd, '/');
        if (!preg_match('/^\s*\d+\s+' . $nombreEsc . '\s+(.+)$/m', $texto, $mFila)) {
            return null;
        }

        preg_match_all('/-?[\d,]+\.\d+/', $mFila[1], $mNums);
        $nums = array_map(fn($n) => (float) str_replace(',', '', $n), $mNums[0]);
        if (count($nums) < 9) {
            return null;
        }

        return [
            'inicio'   => $nums[0],
            'compras'  => $nums[1],
            'ventas'   => $nums[4],
            'fin'      => $nums[7],
            'fin_real' => $nums[8],
        ];
    }

    private static function extraerTexto(string $rutaPdf): ?string
    {
        $bin = self::binarioPdftotext();
        if (!$bin || !is_file($rutaPdf)) {
            return null;
        }

        $cmd = '"' . $bin . '" -layout ' . escapeshellarg($rutaPdf) . ' -';
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ($code === 0 && $out !== false && $out !== '') ? $out : null;
    }

    private static function binarioPdftotext(): ?string
    {
        $empaquetado = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
            . 'bin' . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR . 'pdftotext.exe';
        if (is_file($empaquetado)) {
            return realpath($empaquetado);
        }
        return 'pdftotext';
    }
}
