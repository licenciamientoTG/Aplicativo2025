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

    /**
     * Acepta los dos formatos del reporte:
     *  - Un día (Fecha == Fecha Hasta): el export diario de siempre.
     *  - Rango (Fecha < Fecha Hasta): una fila por día en cada sección.
     * En ambos casos devuelve 'dias' => [['fecha' => Y-m-d, 'filas' => [...]], ...]
     * ordenado por fecha ascendente.
     */
    public static function parse(string $rutaPdf, string $nombreArchivo = ''): array
    {
        $base = [
            'archivo'     => $nombreArchivo,
            'ok'          => false,
            'error'       => null,
            'fecha_desde' => '',
            'fecha_hasta' => '',
            'dias'        => [],
        ];

        $texto = self::extraerTexto($rutaPdf);
        if ($texto === null) {
            $base['error'] = 'No se pudo leer el PDF (pdftotext)';
            return $base;
        }
        // pdftotext marca el salto de página con \f pegado al inicio de la
        // línea siguiente ("\fDiesel"): sin normalizar, el título de una
        // sección que cae al inicio de página no se reconoce y sus filas se
        // atribuyen a la sección anterior.
        $texto = str_replace("\f", "\n", $texto);

        if (!preg_match('/Fecha\s+(\d{4}-\d{2}-\d{2})/', $texto, $mFecha)
            || !preg_match('/Fecha Hasta\s+(\d{4}-\d{2}-\d{2})/', $texto, $mFechaHasta)) {
            $base['error'] = 'No se encontraron las fechas del encabezado';
            return $base;
        }
        $desde = $mFecha[1];
        $hasta = $mFechaHasta[1];
        if ($desde > $hasta) {
            $base['error'] = "Rango de fechas inválido en el encabezado ({$desde} > {$hasta})";
            return $base;
        }
        // Algunos exports de ControlGas no imprimen la línea "Estación" en el
        // encabezado: si viene, debe ser PRAXEDIS; si no viene, se acepta
        // con advertencia (el preview la muestra para que el usuario confirme).
        $advertencias = [];
        if (preg_match('/Estación\s+(\S+)/u', $texto, $mEst)) {
            if (strtoupper($mEst[1]) !== 'PRAXEDIS') {
                $base['error'] = "El PDF es de la estación {$mEst[1]}, no de PRAXEDIS";
                return $base;
            }
        } else {
            $advertencias[] = 'El PDF no indica la estación; verifica que sea de Praxedis';
        }
        if (!preg_match('/Tipo\s+(\S+)/', $texto, $mTipo) || strtolower($mTipo[1]) !== 'diario') {
            $base['error'] = 'El PDF no es de tipo Diario';
            return $base;
        }

        $porFecha = []; // fecha => filas por producto
        foreach (self::SECCIONES as $titulo => $meta) {
            foreach (self::extraerFilasSeccion($texto, $titulo, $desde, $hasta) as $fecha => $fila) {
                $porFecha[$fecha][] = [
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

        if (empty($porFecha)) {
            $base['error'] = 'El PDF no trae datos numéricos en ninguna familia de producto';
            return $base;
        }
        ksort($porFecha);

        // En un rango, ControlGas imprime "Fecha Hasta" aunque el último día
        // aún no cierre (no trae fila): se avisa qué días del rango faltan.
        if ($desde !== $hasta) {
            $faltantes = [];
            for ($d = $desde; $d <= $hasta; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                if (!isset($porFecha[$d])) $faltantes[] = $d;
            }
            if ($faltantes) {
                $advertencias[] = 'Sin datos en el PDF para: ' . implode(', ', $faltantes) . ' (esas fechas no se tocan)';
            }
        }

        $base['ok']          = true;
        $base['fecha_desde'] = $desde;
        $base['fecha_hasta'] = $hasta;
        foreach ($porFecha as $fecha => $filas) {
            $base['dias'][] = ['fecha' => $fecha, 'filas' => $filas];
        }
        if ($advertencias) {
            $base['advertencia'] = implode(' · ', $advertencias);
        }
        return $base;
    }

    /**
     * Ubica el bloque de una sección de producto (entre su título y el
     * siguiente título de sección, o fin de texto) y extrae los valores
     * numéricos de cada fila que empieza con una fecha dentro de
     * [$desde, $hasta] (la fila TOTAL se ignora). Sección "null" (la
     * estación no vendió ese producto) => arreglo vacío.
     *
     * @return array<string, array> fecha => valores
     */
    private static function extraerFilasSeccion(string $texto, string $titulo, string $desde, string $hasta): array
    {
        $tituloEsc = preg_quote($titulo, '/');
        if (!preg_match('/^' . $tituloEsc . '\s*$/m', $texto, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $inicio = $m[0][1] + strlen($m[0][0]);

        // Toma el bloque hasta el siguiente título de sección (o fin de texto)
        $bloque = substr($texto, $inicio);
        if (preg_match('/^(87 Octanos|91 Octanos|Diesel)\s*$/m', $bloque, $mSig, PREG_OFFSET_CAPTURE)) {
            $bloque = substr($bloque, 0, $mSig[0][1]);
        }

        preg_match_all('/^\s*(\d{4}-\d{2}-\d{2})\s+(.+)$/m', $bloque, $mFilas, PREG_SET_ORDER);
        $out = [];
        foreach ($mFilas as $mFila) {
            $fecha = $mFila[1];
            if ($fecha < $desde || $fecha > $hasta || isset($out[$fecha])) {
                continue;
            }
            // Extrae todos los números (con separador de miles/decimales, signo
            // opcional) de la fila y toma los primeros 7: InvInicial, ComprasLec,
            // ComprasDoc, Ventas, InvLec, InvDoc, InvFinal.
            preg_match_all('/-?[\d,]+\.\d+/', $mFila[2], $mNums);
            $nums = array_map(fn($n) => (float) str_replace(',', '', $n), $mNums[0]);
            if (count($nums) < 7) {
                continue;
            }
            $out[$fecha] = [
                'inv_inicial' => $nums[0],
                'compras_lec' => $nums[1],
                'compras_doc' => $nums[2],
                'ventas'      => $nums[3],
                'inv_lec'     => $nums[4],
                'inv_doc'     => $nums[5],
                'inv_final'   => $nums[6],
            ];
        }
        return $out;
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
