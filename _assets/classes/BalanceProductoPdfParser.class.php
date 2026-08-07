<?php

/**
 * Extrae inventario/ventas/compras diarios por producto del reporte
 * "Balance de Producto" (ControlGas) para estaciones sin sync automático
 * de merma vía ApiER (hoy: Praxedis).
 *
 * Usa el binario pdftotext (Poppler) empaquetado en _assets/bin/poppler/,
 * mismo mecanismo que NotaCreditoPdfParser.
 *
 * Uso: BalanceProductoPdfParser::parse($rutaPdf, $nombreArchivo)
 */
class BalanceProductoPdfParser
{
    /** Secciones de producto esperadas -> codprd base (ver MermaDiariaModel::FAMILIAS). */
    const SECCIONES = [
        '87 Octanos' => ['codprd' => 1, 'producto' => 'MAXIMA'],
        '91 Octanos' => ['codprd' => 2, 'producto' => 'SUPER'],
        'Diesel'     => ['codprd' => 3, 'producto' => 'DIESEL'],
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

        if (!preg_match('/Fecha\s+(\d{4}-\d{2}-\d{2})/', $texto, $mFecha)
            || !preg_match('/Fecha Hasta\s+(\d{4}-\d{2}-\d{2})/', $texto, $mFechaHasta)) {
            $base['error'] = 'No se encontraron las fechas del encabezado';
            return $base;
        }
        if ($mFecha[1] !== $mFechaHasta[1]) {
            $base['error'] = 'El PDF cubre un rango (Fecha != Fecha Hasta), se esperaba un solo día';
            return $base;
        }
        if (!preg_match('/Estación\s+(\S+)/u', $texto, $mEst) || strtoupper($mEst[1]) !== 'PRAXEDIS') {
            $base['error'] = 'El PDF no es de la estación PRAXEDIS';
            return $base;
        }
        if (!preg_match('/Tipo\s+(\S+)/', $texto, $mTipo) || strtolower($mTipo[1]) !== 'diario') {
            $base['error'] = 'El PDF no es de tipo Diario';
            return $base;
        }

        $fecha = $mFecha[1];
        $filas = [];
        foreach (self::SECCIONES as $titulo => $meta) {
            $fila = self::extraerFilaSeccion($texto, $titulo, $fecha);
            if ($fila !== null) {
                $filas[] = [
                    'codprd'        => $meta['codprd'],
                    'producto'      => $meta['producto'],
                    // Inv Final (no Inv Lec): es el inventario de cierre del
                    // día ya con la merma aplicada, consistente con lo que
                    // encadena inv_inicial del día siguiente en este reporte.
                    'inv_fisico'    => $fila['inv_final'],
                    'ventas_reales' => $fila['ventas'],
                    'compras'       => $fila['compras_doc'],
                    // Cierre del día anterior según ControlGas — se usa para
                    // sembrar ese día si el sistema aún no tiene un corte
                    // físico previo del que encadenar (ver guardar_balance_praxedis).
                    'inv_inicial'   => $fila['inv_inicial'],
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
     * Ubica el bloque de una sección de producto (entre su título y el
     * siguiente título de sección, o fin de texto) y extrae los valores
     * numéricos de la fila que empieza con la fecha del reporte. Si esa
     * fila no existe (sección "null"), devuelve null.
     */
    private static function extraerFilaSeccion(string $texto, string $titulo, string $fecha): ?array
    {
        $tituloEsc = preg_quote($titulo, '/');
        if (!preg_match('/^' . $tituloEsc . '\s*$/m', $texto, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $inicio = $m[0][1] + strlen($m[0][0]);

        // Encuentra el siguiente título de sección dentro del texto restante
        $textoResto = substr($texto, $inicio);
        $patronSecciones = '/^(87 Octanos|91 Octanos|Diesel)\s*$/m';
        if (preg_match($patronSecciones, $textoResto, $mSig, PREG_OFFSET_CAPTURE)) {
            // Toma el bloque hasta la siguiente sección
            $fin = $inicio + $mSig[0][1];
            $bloque = substr($texto, $inicio, $fin - $inicio);
        } else {
            // No hay siguiente sección, toma todo lo que queda
            $bloque = $textoResto;
        }

        $fechaEsc = preg_quote($fecha, '/');
        if (!preg_match('/^\s*' . $fechaEsc . '\s+(.+)$/m', $bloque, $mFila)) {
            return null; // sección "null" (estación no vendió ese producto ese día)
        }

        // Extrae todos los números (con separador de miles/decimales, signo opcional)
        // de la fila y toma los primeros 7: InvInicial, ComprasLec, ComprasDoc,
        // Ventas, InvLec, InvDoc, InvFinal.
        preg_match_all('/-?[\d,]+\.\d+/', $mFila[1], $mNums);
        $nums = array_map(fn($n) => (float) str_replace(',', '', $n), $mNums[0]);
        if (count($nums) < 7) {
            return null;
        }

        return [
            'inv_inicial' => $nums[0],
            'compras_lec' => $nums[1],
            'compras_doc' => $nums[2],
            'ventas'      => $nums[3],
            'inv_lec'     => $nums[4],
            'inv_doc'     => $nums[5],
            'inv_final'   => $nums[6],
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
