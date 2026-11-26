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
    const POS_UUID_REL  = 16; // UUID de CfdiRelacionados (nota de crédito) o RFC emisor (tipo I)
    const POS_RFC_EMI   = 17;
    const POS_NOM_EMI   = 18;
    const POS_RFC_REC   = 20;
    const POS_NOM_REC   = 21;

    public static function parse(string $rutaPdf, string $nombreArchivo = ''): array
    {
        $texto = self::extraerTexto($rutaPdf);
        if ($texto === null) {
            $base = self::base($nombreArchivo);
            $base['error'] = 'No se pudo leer el PDF (pdftotext)';
            return $base;
        }
        $r = self::parseTexto($texto, $nombreArchivo);
        if ($r['uuid'] === '') {
            // Con -layout el folio fiscal impreso sale partido por otras columnas; en modo
            // -raw queda contiguo (los PDF de MGC no traen la cadena ||1.1|). Del pase -raw
            // se toma SOLO el uuid; el resto de los campos se queda con los de -layout.
            $raw = self::extraerTexto($rutaPdf, '-raw');
            if ($raw !== null) {
                $r2 = self::parseTexto($raw, $nombreArchivo);
                if ($r2['uuid'] !== '' && $r2['uuid'] !== $r['uuid_relacionado']) {
                    $r['uuid'] = $r2['uuid'];
                    $r = self::finalizar($r);
                }
            }
        }
        return $r;
    }

    /** Base vacía del resultado. */
    private static function base(string $nombreArchivo): array
    {
        return [
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
            'uuid_relacionado' => '',
            'rfc_emisor'       => '',
            'nombre_emisor'    => '',
            'rfc_receptor'     => '',
            'nombre_receptor'  => '',
            'raw_ok'           => false,
            'error'            => null,
        ];
    }

    /**
     * Lee los datos de una nota a partir del texto del PDF (pdftotext).
     * 'uuid' es el del TIMBRE de la propia nota (cadena original del complemento 1.1);
     * el campo 16 de la cadena del comprobante es el UUID del CFDI relacionado.
     */
    public static function parseTexto(string $texto, string $nombreArchivo = ''): array
    {
        $base = self::base($nombreArchivo);

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
        // Campo 16 de la cadena del comprobante: UUID del CFDI relacionado (no el de la nota).
        // Todo UUID dentro de la cadena del comprobante es ajeno a la nota (su timbre se genera
        // después), así que se excluye de cualquier candidato a UUID propio.
        $enCadena = self::uuidsEn(preg_replace('/\s+/', '', $m[0]));
        $rel = strtoupper($get(self::POS_UUID_REL));
        $base['uuid_relacionado'] = self::validarUuid($rel) ? $rel : '';
        if ($base['uuid_relacionado'] === '' && count($enCadena) === 1) {
            $base['uuid_relacionado'] = $enCadena[0];   // posiciones desplazadas por atributos opcionales
        }
        $ambiguo = $base['uuid_relacionado'] === '' && count($enCadena) > 1;
        $base['uuid']             = $ambiguo ? '' : self::uuidTimbre($plano, $enCadena);
        $base['rfc_emisor']       = strtoupper($get(self::POS_RFC_EMI));
        $base['nombre_emisor']    = $get(self::POS_NOM_EMI);
        $base['rfc_receptor']     = strtoupper($get(self::POS_RFC_REC));
        $base['nombre_receptor']  = $get(self::POS_NOM_REC);

        // Tipo de comprobante CFDI: E (Egreso) = nota de crédito.
        $base['note_type'] = ($base['tipo_comprobante'] === 'E') ? 'CREDIT' : 'DEBIT';

        if ($ambiguo) {
            $base['error'] = 'La cadena del comprobante trae varios UUID y no se pudo identificar el relacionado';
            return $base;
        }
        return self::finalizar($base);
    }

    /** raw_ok y mensaje de error a partir de los campos ya leídos. */
    private static function finalizar(array $base): array
    {
        $base['raw_ok'] = (
            $base['rfc_emisor'] !== '' &&
            $base['folio'] !== '' &&
            $base['total'] > 0 &&
            $base['uuid'] !== ''
        );
        $base['error'] = null;
        if ($base['uuid'] === '') {
            $base['error'] = 'No se encontró el UUID del timbre (folio fiscal)';
        } elseif (!$base['raw_ok']) {
            $base['error'] = 'No se pudieron extraer los datos clave de la nota (CFDI)';
        }
        return $base;
    }

    /** Texto plano del PDF (pdftotext) o null si no se pudo leer. */
    public static function texto(string $rutaPdf): ?string
    {
        return self::extraerTexto($rutaPdf);
    }

    /**
     * Ejecuta el pdftotext empaquetado por ruta absoluta. Devuelve el texto o null.
     */
    private static function extraerTexto(string $rutaPdf, string $modo = '-layout'): ?string
    {
        $bin = self::binarioPdftotext();
        if (!$bin || !is_file($rutaPdf)) {
            return null;
        }

        $cmd = '"' . $bin . '" ' . $modo . ' ' . escapeshellarg($rutaPdf) . ' -';
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

    /** Patrón estricto de UUID impreso: espacios solo tras el 4º guion, límites alfanuméricos, último grupo de 12. */
    private const UUID_IMPRESO = '(?<![0-9A-Za-z])[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-\s*[0-9A-Fa-f]{12}(?![0-9A-Za-z])';

    /** UUID (únicos, mayúsculas, sin espacios) que aparecen en un texto con el patrón estricto. */
    private static function uuidsEn(string $texto): array
    {
        $out = [];
        if (preg_match_all('/' . self::UUID_IMPRESO . '/', $texto, $mm)) {
            foreach ($mm[0] as $c) {
                $u = strtoupper(preg_replace('/\s+/', '', $c));
                if (self::validarUuid($u)) {
                    $out[$u] = true;   // el mismo UUID impreso varias veces (p. ej. QR) cuenta una vez
                }
            }
        }
        return array_keys($out);
    }

    /**
     * UUID del timbre de la nota, por orden de confianza:
     *  1. cadena del complemento ||1.1|UUID|FechaTimbrado|...|| (no la traen los PDF de MGC);
     *  2. etiqueta "Folio Fiscal" seguida inmediatamente de un UUID impreso (estricto);
     *  3. el ÚNICO UUID impreso en el documento que no esté en la cadena del comprobante.
     * Los UUID de $excluidos (los de la cadena ||4.0|, o sea CfdiRelacionados) nunca se aceptan.
     * Límites: si el UUID impreso queda partido por otras columnas (pdftotext -layout) no se
     * reconoce y se devuelve ''; si hay 2+ candidatos distintos (p. ej. una leyenda con otro
     * UUID) tampoco se adivina. '' = no se encontró.
     */
    private static function uuidTimbre(string $plano, array $excluidos = []): string
    {
        if (preg_match('/\|\|1\.1\|(.*?)\|\|/s', $plano, $m)) {
            $campos = explode('|', $m[1]);
            $uuid = strtoupper(preg_replace('/\s+/', '', $campos[0]));
            if (self::validarUuid($uuid) && !in_array($uuid, $excluidos, true)) {
                return $uuid;
            }
        }
        if (preg_match('/Folio\s+Fiscal\s*:?\s*(' . self::UUID_IMPRESO . ')/i', $plano, $m)) {
            $uuid = strtoupper(preg_replace('/\s+/', '', $m[1]));
            if (self::validarUuid($uuid) && !in_array($uuid, $excluidos, true)) {
                return $uuid;
            }
        }
        $candidatos = array_values(array_diff(self::uuidsEn($plano), $excluidos));
        return count($candidatos) === 1 ? $candidatos[0] : '';
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
