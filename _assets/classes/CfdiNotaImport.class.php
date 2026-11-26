<?php

/**
 * Lógica pura (sin BD ni disco) para importar CFDI de notas desde XML y/o PDF.
 */
class CfdiNotaImport
{
    /**
     * Arma la fila de FacturasRecibidas a partir del PDF leído por NotaCreditoPdfParser.
     * Devuelve null si el parser no pudo leer el PDF (raw_ok = false).
     */
    public static function filaDesdePdf(array $parsed): ?array
    {
        if (empty($parsed['raw_ok'])) {
            return null;
        }
        $texto = fn(string $k) => (($parsed[$k] ?? '') === '') ? null : (string)$parsed[$k];

        $fila = array_fill_keys([
            'Folio', 'Serie', 'Fecha', 'FormaPago', 'MetodoPago', 'TipoCambio', 'Moneda',
            'SubTotal', 'Total', 'Exportacion', 'TipoDeComprobante', 'LugarExpedicion',
            'Certificado', 'NoCertificado', 'Sello', 'EmisorNombre', 'EmisorRfc',
            'EmisorRegimenFiscal', 'ReceptorNombre', 'ReceptorRfc', 'ReceptorRegimenFiscal',
            'DomicilioFiscalReceptor', 'UsoCFDI', 'FechaTimbrado', 'RfcProvCertif', 'UUID',
            'NoCertificadoSAT', 'TotalImpuestosTrasladados', 'TotalImpuestosRetenidos',
            'Destino', 'Remision', 'PresentacionTesoro',
        ], null);

        $fila['Folio'] = $texto('folio');
        $fila['Serie'] = $texto('serie');
        $fila['Fecha'] = $texto('fecha');
        $fila['Moneda'] = 'MXN';
        $fila['SubTotal'] = $parsed['subtotal'] ?? 0;
        $fila['Total'] = $parsed['total'] ?? 0;
        $fila['TipoDeComprobante'] = $texto('tipo_comprobante');
        $fila['EmisorRfc'] = $texto('rfc_emisor');
        $fila['EmisorNombre'] = $texto('nombre_emisor');
        $fila['ReceptorRfc'] = $texto('rfc_receptor');
        $fila['ReceptorNombre'] = $texto('nombre_receptor');
        $fila['UUID'] = $texto('uuid');
        return $fila;
    }

    /**
     * Agrupa los archivos subidos por nombre base (sin extensión, sin distinguir
     * mayúsculas). Solo cuentan .xml y .pdf subidos sin error.
     * @param array $files [['name','tmp_name','error'], ...]
     * @return array [['base','xml'=>?tmp,'pdf'=>?tmp,'pdf_name'=>?string], ...]
     */
    public static function emparejarArchivos(array $files): array
    {
        $grupos = [];
        foreach ($files as $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $nombre = (string)($f['name'] ?? '');
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            if ($ext !== 'xml' && $ext !== 'pdf') {
                continue;
            }
            $base = pathinfo($nombre, PATHINFO_FILENAME);
            $key = strtolower($base);
            if (!isset($grupos[$key])) {
                $grupos[$key] = ['base' => $base, 'xml' => null, 'pdf' => null, 'pdf_name' => null];
            }
            if ($ext === 'xml') {
                $grupos[$key]['xml'] = $f['tmp_name'];
            } else {
                $grupos[$key]['pdf'] = $f['tmp_name'];
                $grupos[$key]['pdf_name'] = $nombre;
            }
        }
        return array_values($grupos);
    }

    /**
     * Un PDF sin XML (tipo I) solo se acepta si su texto es coherente con la clase elegida:
     * FALSO_FLETE = "servicio asociado" o clave 80141629 y sin combustible;
     * MORATORIOS = "intereses moratorios" y sin combustible (la leyenda de pagaré de una
     * factura de combustible también menciona intereses moratorios).
     */
    public static function pdfCoincideConClase(string $texto, string $clase): bool
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto), 'UTF-8');
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        if ($clase === NotaCfdi::MORATORIOS) {
            return strpos($t, 'intereses moratorios') !== false
                && !preg_match('/\b(pemex|magna|premium|diesel)\b/', $t);
        }
        if ($clase === NotaCfdi::FALSO_FLETE) {
            if (strpos($t, 'servicio asociado') === false && strpos($t, NotaCfdi::CLAVE_SERVICIO_ASOCIADO) === false) {
                return false;
            }
            return !preg_match('/\b(pemex|magna|premium|diesel)\b/', $t);
        }
        return false;
    }

    /**
     * Guarda para el solo-PDF: si FacturasRecibidas ya tiene una fila con el UUID leído del PDF,
     * esa fila debe ser la misma nota (folio numérico, RFC emisor y total ±0.01); si no, el UUID
     * leído pertenece a otro CFDI y no se debe tocar nada.
     * @param array $fr   fila de FacturasRecibidas (Folio, EmisorRfc, Total)
     * @param array $fila fila de filaDesdePdf()
     */
    public static function facturaCoincideConPdf(array $fr, array $fila): bool
    {
        $a = trim((string)($fr['Folio'] ?? ''));
        $b = trim((string)($fila['Folio'] ?? ''));
        $da = preg_replace('/\D/', '', $a);
        $db = preg_replace('/\D/', '', $b);
        $folioOk = ($da !== '' && $db !== '')
            ? ltrim($da, '0') === ltrim($db, '0')
            : strcasecmp($a, $b) === 0;
        $rfcOk = strcasecmp(trim((string)($fr['EmisorRfc'] ?? '')), trim((string)($fila['EmisorRfc'] ?? ''))) === 0;
        $totalOk = abs((float)($fr['Total'] ?? 0) - (float)($fila['Total'] ?? 0)) <= 0.01;
        return $folioOk && $rfcOk && $totalOk;
    }

    /** Parte numérica del número de nota (desde el primer dígito; null si no es solo dígitos), como el SQL de backfill. */
    public static function folioNumericoNota(string $noteNumber): ?string
    {
        if (!preg_match('/\d.*$/', $noteNumber, $m) || !ctype_digit($m[0])) {
            return null;
        }
        $n = ltrim($m[0], '0');
        return $n === '' ? '0' : $n;
    }

    /** Folio del PDF vs número de la nota: igual como entero, o la nota termina en el folio (folio de 5+ dígitos). */
    public static function folioPdfCoincideConNota(string $folioPdf, string $noteNumber): bool
    {
        $nota = self::folioNumericoNota($noteNumber);
        $pdf = ltrim(preg_replace('/\D/', '', $folioPdf) ?? '', '0');
        if ($nota === null || $pdf === '') {
            return false;
        }
        if ($nota === $pdf) {
            return true;
        }
        return strlen($pdf) >= 5 && strlen($pdf) <= strlen($nota) && substr($nota, -strlen($pdf)) === $pdf;
    }

    /** UUID de "Carga masiva - UUID <uuid>" (es el de la factura relacionada, no el de la nota) o ''. */
    public static function uuidEnDescripcion(string $descripcion): string
    {
        return preg_match('/UUID\s+([0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12})/i', $descripcion, $m)
            ? strtoupper($m[1]) : '';
    }

    /**
     * Decide qué hacer con una nota histórica y su PDF ya leído (sin BD ni disco).
     * @param array      $nota  note_type, note_number, amount, description, rfc_proveedor
     * @param array      $pdf   salida de NotaCreditoPdfParser::parse
     * @param array|null $frExistente fila de FacturasRecibidas con el UUID del PDF (Folio, EmisorRfc, Total) o null
     * @param bool       $uuidEnOtraNota el UUID del PDF ya está en otra nota activa
     * @param int[]      $otrasConMismoUuid ids de otras notas de la corrida cuyo PDF trae el mismo UUID
     * @param int[]      $otrasSinLigar ids de otras notas activas sin ligar que coinciden con este PDF (ver notaCoincideConPdf)
     * @return array{accion:string,detalle:string,aviso:string}
     *   accion: no-legible | pdf-no-coincide | requiere-revision | ya-ligada | uuid-otro-cfdi | duplicada | ya-en-FR | importar
     */
    public static function accionBackfill(array $nota, array $pdf, ?array $frExistente, bool $uuidEnOtraNota,
                                          array $otrasConMismoUuid = [], array $otrasSinLigar = []): array
    {
        $r = fn(string $a, string $d = '', string $aviso = '') => ['accion' => $a, 'detalle' => $d, 'aviso' => $aviso];
        if (empty($pdf['raw_ok'])) {
            return $r('no-legible', (string)($pdf['error'] ?? ''));
        }
        $fallas = [];
        if (!self::folioPdfCoincideConNota((string)($pdf['folio'] ?? ''), (string)($nota['note_number'] ?? ''))) {
            $fallas[] = 'folio ' . ($pdf['folio'] ?? '') . ' vs ' . ($nota['note_number'] ?? '');
        }
        if (abs((float)($pdf['total'] ?? 0) - (float)($nota['amount'] ?? 0)) >= 0.01) {
            $fallas[] = 'total ' . ($pdf['total'] ?? 0) . ' vs ' . ($nota['amount'] ?? 0);
        }
        $rfcProv = strtoupper(trim((string)($nota['rfc_proveedor'] ?? '')));
        if ($rfcProv === '' || strtoupper((string)($pdf['rfc_emisor'] ?? '')) !== $rfcProv) {
            $fallas[] = 'rfc emisor ' . ($pdf['rfc_emisor'] ?? '') . ' vs ' . $rfcProv;
        }
        $tipoEsperado = (($nota['note_type'] ?? '') === 'CREDIT') ? 'E' : 'I';
        if (strtoupper((string)($pdf['tipo_comprobante'] ?? '')) !== $tipoEsperado) {
            $fallas[] = 'tipo ' . ($pdf['tipo_comprobante'] ?? '') . ' vs ' . $tipoEsperado;
        }
        $aviso = '';
        $uuidDesc = self::uuidEnDescripcion((string)($nota['description'] ?? ''));
        if ($uuidDesc !== '') {
            $rel = strtoupper((string)($pdf['uuid_relacionado'] ?? ''));
            if ($rel === '') {
                $aviso = 'sin uuid_relacionado en PDF';
            } elseif ($rel !== $uuidDesc) {
                $fallas[] = 'uuid relacionado ' . $rel . ' vs descripcion ' . $uuidDesc;
            }
        }
        if ($fallas) {
            return $r('pdf-no-coincide', implode('; ', $fallas), $aviso);
        }
        if ($uuidEnOtraNota) {
            return $r('ya-ligada', 'el UUID del PDF ya esta en otra nota activa', $aviso);
        }
        if ($frExistente !== null) {
            $fila = self::filaDesdePdf($pdf);
            if ($fila === null || !self::facturaCoincideConPdf($frExistente, $fila)) {
                return $r('uuid-otro-cfdi', 'el UUID existe en FacturasRecibidas con otro folio/rfc/total', $aviso);
            }
        }
        // Mismo CFDI capturado en varias notas (o copia sin PDF): no se liga ninguna
        $otras = array_values(array_unique(array_merge($otrasConMismoUuid, $otrasSinLigar)));
        if ($otras) {
            return $r('duplicada', 'mismo CFDI en otras notas: ' . implode(',', $otras), $aviso);
        }
        if (($nota['note_type'] ?? '') === 'DEBIT') {
            return $r('requiere-revision', 'nota de cargo: sus filas de pago las liga el SQL de backfill', $aviso);
        }
        return $frExistente !== null ? $r('ya-en-FR', 'solo ligar', $aviso) : $r('importar', '', $aviso);
    }

    /** ¿Otra nota sin ligar es el mismo CFDI que este PDF? Folio (o sufijo) + total ±0.01 + tipo de comprobante. */
    public static function notaCoincideConPdf(array $otra, array $pdf): bool
    {
        $tipo = (($otra['note_type'] ?? '') === 'CREDIT') ? 'E' : 'I';
        return self::folioPdfCoincideConNota((string)($pdf['folio'] ?? ''), (string)($otra['note_number'] ?? ''))
            && abs((float)($otra['amount'] ?? 0) - (float)($pdf['total'] ?? 0)) < 0.01
            && $tipo === strtoupper((string)($pdf['tipo_comprobante'] ?? ''));
    }

    /** codgas para la nota a partir de EstacionCodgas de la FR: entero o null. */
    public static function codgasDeFr(?array $fr): ?int
    {
        $v = $fr['EstacionCodgas'] ?? null;
        return ($v !== null && is_numeric($v)) ? (int)$v : null;
    }

    /** La ruta del documento debe ser uploads/credit_debit_notes/<archivo>.pdf (sin subcarpetas ni ..). */
    public static function rutaDocValida(string $ruta): bool
    {
        return (bool)preg_match('#^uploads/credit_debit_notes/[^/\\\\]+\.pdf$#i', $ruta) && strpos($ruta, '..') === false;
    }
}
