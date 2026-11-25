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
     * MORATORIOS = "intereses moratorios".
     */
    public static function pdfCoincideConClase(string $texto, string $clase): bool
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto), 'UTF-8');
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        if ($clase === NotaCfdi::MORATORIOS) {
            return strpos($t, 'intereses moratorios') !== false;
        }
        if ($clase === NotaCfdi::FALSO_FLETE) {
            if (strpos($t, 'servicio asociado') === false && strpos($t, NotaCfdi::CLAVE_SERVICIO_ASOCIADO) === false) {
                return false;
            }
            return !preg_match('/\b(pemex|magna|premium|diesel)\b/', $t);
        }
        return false;
    }
}
