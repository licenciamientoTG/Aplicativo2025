<?php

/**
 * Extrae los datos de una nota de crédito/cargo (CFDI 4.0 en PDF) leyendo la
 * "Cadena original del complemento de certificación del SAT", que es un string
 * delimitado por '|' con todos los datos fiscales exactos.
 *
 * Usa el binario pdftotext (Poppler) empaquetado en _assets/bin/poppler/, porque
 * smalot/pdfparser no decodifica la fuente embebida de estos CFDI.
 *
 * Uso: NotaCreditoPdfParser::parse($rutaPdf, $nombreArchivo)
 */
class NotaCreditoPdfParser
{
    /** Posiciones (1-based) dentro de la cadena original del SAT, CFDI 4.0. */
    const POS_VERSION   = 1;
    const POS_SERIE     = 2;
    const POS_FOLIO     = 3;
    const POS_FECHA     = 4;
    const POS_SUBTOTAL  = 7;
    const POS_TOTAL     = 10;
    const POS_TIPO      = 11; // E = Egreso (nota de crédito), I = Ingreso
    const POS_UUID      = 16;
    const POS_RFC_EMI   = 17;
    const POS_NOM_EMI   = 18;
    const POS_RFC_REC   = 20;
    const POS_NOM_REC   = 21;

    public static function parse(string $rutaPdf, string $nombreArchivo = ''): array
    {
        $base = [
            'archivo'          => $nombreArchivo,
            'serie'            => '',
            'folio'            => '',
            'note_number'      => '',
            'fecha'            => '',
            'subtotal'         => 0.0,
            'total'            => 0.0,
            'tipo_comprobante' => '',
            'note_type'        => '',
            'uuid'             => '',
            'rfc_emisor'       => '',
            'nombre_emisor'    => '',
            'rfc_receptor'     => '',
            'nombre_receptor'  => '',
            'raw_ok'           => false,
            'error'            => null,
        ];

        $texto = self::extraerTexto($rutaPdf);
        if ($texto === null) {
            $base['error'] = 'No se pudo leer el PDF (pdftotext)';
            return $base;
        }

        // Aislar la cadena original: desde "||4.0|" hasta el doble pipe de cierre.
        // pdftotext intercala espacios/saltos dentro de los valores, así que se
        // colapsan después de separar por '|'.
        $plano = preg_replace('/[\r\n]+/', ' ', $texto);
        if (!preg_match('/\|\|4\.0\|.*?\|\|/s', $plano, $m)) {
            // Cierre alterno: la cadena termina antes de "Versión CFDI"
            if (!preg_match('/\|\|4\.0\|.*/s', $plano, $m)) {
                $base['error'] = 'No se encontró la cadena original del SAT';
                return $base;
            }
        }

        // Quitar el "||" inicial y separar
        $cadena = ltrim($m[0], '|');
        $campos = explode('|', $cadena);
        // Limpiar espacios internos que mete pdftotext
        $campos = array_map(fn($c) => trim(preg_replace('/\s+/', '', $c)), $campos);

        $get = fn($pos) => $campos[$pos - 1] ?? '';

        $base['serie']            = $get(self::POS_SERIE);
        $base['folio']            = $get(self::POS_FOLIO);
        $base['note_number']      = trim($base['serie'] . ' ' . $base['folio']);
        $base['fecha']            = self::normalizarFecha($get(self::POS_FECHA));
        $base['subtotal']         = (float) $get(self::POS_SUBTOTAL);
        $base['total']            = (float) $get(self::POS_TOTAL);
        $base['tipo_comprobante'] = $get(self::POS_TIPO);
        $base['uuid']             = strtoupper($get(self::POS_UUID));
        $base['rfc_emisor']       = strtoupper($get(self::POS_RFC_EMI));
        $base['nombre_emisor']    = $get(self::POS_NOM_EMI);
        $base['rfc_receptor']     = strtoupper($get(self::POS_RFC_REC));
        $base['nombre_receptor']  = $get(self::POS_NOM_REC);

        // Tipo de comprobante CFDI: E (Egreso) = nota de crédito.
        $base['note_type'] = ($base['tipo_comprobante'] === 'E') ? 'CREDIT' : 'DEBIT';

        $base['raw_ok'] = (
            $base['rfc_emisor'] !== '' &&
            $base['folio'] !== '' &&
            $base['total'] > 0 &&
            self::validarUuid($base['uuid'])
        );
        if (!$base['raw_ok']) {
            $base['error'] = 'No se pudieron extraer los datos clave de la nota (CFDI)';
        }

        return $base;
    }

    /**
     * Ejecuta el pdftotext empaquetado por ruta absoluta. Devuelve el texto o null.
     */
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

    /**
     * Ruta al pdftotext.exe empaquetado; fallback al del PATH.
     */
    private static function binarioPdftotext(): ?string
    {
        $empaquetado = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
            . 'bin' . DIRECTORY_SEPARATOR . 'poppler' . DIRECTORY_SEPARATOR . 'pdftotext.exe';
        if (is_file($empaquetado)) {
            return realpath($empaquetado);
        }
        return 'pdftotext'; // fallback: buscar en PATH
    }

    /** "2026-05-15T08:54:20" -> "2026-05-15" */
    private static function normalizarFecha(string $f): string
    {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $f, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function validarUuid(string $uuid): bool
    {
        return (bool) preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/i', $uuid);
    }
}
