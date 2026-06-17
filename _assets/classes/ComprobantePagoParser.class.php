<?php

use Smalot\PdfParser\Parser;

/**
 * Extrae los datos de un comprobante de pago bancario (PDF de texto) y los
 * normaliza a una estructura común, independiente del banco.
 *
 * Soporta dos formatos:
 *   - Banorte:   "Reporte de Transferencia a Nacionales SPEI"
 *   - Santander: "Comprobante de Operación"
 *
 * Uso: ComprobantePagoParser::parse($_FILES['x']['tmp_name'], $nombreOriginal)
 */
class ComprobantePagoParser
{
    const BANCO_BANORTE   = 'Banorte';
    const BANCO_SANTANDER = 'Santander';
    const BANCO_DESCONOCIDO = 'Desconocido';

    /**
     * Estructura devuelta:
     * [
     *   'archivo', 'banco',
     *   'rfc_ordenante', 'nombre_ordenante',
     *   'rfc_beneficiario', 'nombre_beneficiario',
     *   'cuenta_cargo', 'cuenta_abono',
     *   'importe' (float), 'referencia', 'fecha',
     *   'raw_ok' (bool), 'error' (string|null)
     * ]
     */
    public static function parse(string $rutaPdf, string $nombreArchivo = ''): array
    {
        $base = [
            'archivo'             => $nombreArchivo,
            'banco'               => self::BANCO_DESCONOCIDO,
            'rfc_ordenante'       => '',
            'nombre_ordenante'    => '',
            'rfc_beneficiario'    => '',
            'nombre_beneficiario' => '',
            'cuenta_cargo'        => '',
            'cuenta_abono'        => '',
            'importe'             => 0.0,
            'referencia'          => '',
            'fecha'               => '',
            'raw_ok'              => false,
            'error'               => null,
        ];

        try {
            $parser = new Parser();
            $pdf    = $parser->parseFile($rutaPdf);
            $texto  = $pdf->getText();
        } catch (Exception $e) {
            $base['error'] = 'No se pudo leer el PDF: ' . $e->getMessage();
            return $base;
        }

        if (stripos($texto, 'Reporte de Transferencia') !== false
            || stripos($texto, 'BANCO MERCANTIL DEL NORTE') !== false) {
            return self::parseBanorte($texto, $base);
        }

        if (stripos($texto, 'Comprobante de Operación') !== false
            || stripos($texto, 'Comprobante de Operacion') !== false
            || stripos($texto, 'SuperLínea') !== false) {
            return self::parseSantander($texto, $base);
        }

        $base['error'] = 'Formato de comprobante no reconocido (ni Banorte ni Santander)';
        return $base;
    }

    /**
     * Formato Banorte: pares "Etiqueta<salto>Valor".
     */
    private static function parseBanorte(string $texto, array $r): array
    {
        $r['banco'] = self::BANCO_BANORTE;

        $r['nombre_ordenante']    = self::campo($texto, 'Nombre del Ordenante');
        $r['rfc_ordenante']       = self::normalizarRfc(self::campo($texto, 'RFC o CURP del Ordenante'));
        $r['nombre_beneficiario'] = self::campo($texto, 'Nombre del Beneficiario');
        $r['rfc_beneficiario']    = self::normalizarRfc(self::campo($texto, 'RFC Beneficiario'));
        $r['cuenta_cargo']        = self::campo($texto, 'Cuenta/ CLABE Ordenante');
        $r['cuenta_abono']        = self::campo($texto, 'Cuenta/CLABE/Celular');
        $r['importe']             = self::normalizarImporte(self::campo($texto, 'Importe a Transferir'));
        $r['referencia']          = self::campo($texto, 'Clave de Rastreo');
        $r['fecha']               = self::soloFecha(self::campo($texto, 'Fecha Aplicaci'));

        $r['raw_ok'] = ($r['rfc_ordenante'] !== '' && $r['importe'] > 0);
        if (!$r['raw_ok']) {
            $r['error'] = 'No se pudieron extraer los datos clave del comprobante Banorte';
        }
        return $r;
    }

    /**
     * Formato Santander: "Cuenta Cargo: <cuenta> - <nombre>", importe "$ 1,234.56 MXN".
     * El RFC del beneficiario suele venir vacío.
     */
    private static function parseSantander(string $texto, array $r): array
    {
        $r['banco'] = self::BANCO_SANTANDER;

        // "Contrato: DIAZ GAS SA DE CV 080122594733" -> nombre del ordenante (sin el número)
        $contrato = self::campo($texto, 'Contrato');
        $r['nombre_ordenante'] = trim(preg_replace('/\s+\d{6,}\s*$/', '', $contrato));

        $r['rfc_ordenante']    = self::normalizarRfc(self::campo($texto, 'RFC Ordenante'));
        $r['rfc_beneficiario'] = self::normalizarRfc(self::campo($texto, 'RFC Beneficiario'));

        // "Cuenta Cargo: 65504998214 - GASOMEX PRINCIPAL"
        [$r['cuenta_cargo']] = self::cuentaYNombre(self::campo($texto, 'Cuenta Cargo'));
        // "Cuenta Abono: 014180655047781122 - MGC MEXICO SA DE CV"
        [$r['cuenta_abono'], $r['nombre_beneficiario']] = self::cuentaYNombre(self::campo($texto, 'Cuenta Abono'));

        $r['importe']    = self::normalizarImporte(self::campo($texto, 'Importe'));
        $r['referencia'] = self::campo($texto, 'Movimiento');
        $r['fecha']      = self::soloFecha(self::campo($texto, 'Liquidaci'));

        $r['raw_ok'] = ($r['rfc_ordenante'] !== '' && $r['importe'] > 0);
        if (!$r['raw_ok']) {
            $r['error'] = 'No se pudieron extraer los datos clave del comprobante Santander';
        }
        return $r;
    }

    /**
     * Extrae el valor que sigue a una etiqueta. La etiqueta puede venir partida
     * en varias líneas en el texto del PDF (ej. "RFC\nOrdenante:"), por eso cada
     * espacio de la etiqueta se trata como "cualquier whitespace, incluido salto".
     * El valor es el texto que sigue a los dos puntos: si está en la misma línea
     * se toma esa, si no, la siguiente línea no vacía.
     */
    private static function campo(string $texto, string $etiqueta): string
    {
        // Escapar la etiqueta y permitir whitespace flexible donde haya espacios.
        $tokens = preg_split('/\s+/', trim($etiqueta));
        $tokens = array_map(fn($t) => preg_quote($t, '/'), $tokens);
        $etiquetaRegex = implode('\s+', $tokens);

        // Separador etiqueta↔valor: ":" (Santander) o TAB/espacios (Banorte).
        // El valor puede venir en la misma línea o en la siguiente línea no vacía.
        $patron = '/' . $etiquetaRegex . '[ \t]*:?[ \t]*(?:([^\r\n]+)|\R+[ \t]*([^\r\n]+))/u';
        if (preg_match($patron, $texto, $m)) {
            $valor = isset($m[1]) && trim($m[1]) !== '' ? $m[1] : ($m[2] ?? '');
            return trim($valor);
        }
        return '';
    }

    /**
     * Separa "65504998214 - GASOMEX PRINCIPAL" en [cuenta, nombre].
     */
    private static function cuentaYNombre(string $valor): array
    {
        $partes = explode(' - ', $valor, 2);
        $cuenta = trim($partes[0]);
        $nombre = isset($partes[1]) ? trim($partes[1]) : '';
        return [$cuenta, $nombre];
    }

    private static function normalizarRfc(string $rfc): string
    {
        $rfc = strtoupper(preg_replace('/\s+/', '', $rfc));
        // Descartar valores claramente no-RFC (ej. nombres que se colaron)
        return preg_match('/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/', $rfc) ? $rfc : '';
    }

    /**
     * Recorta cualquier residuo previo (ej. "ón" del acento partido) y deja
     * desde la fecha dd/mm/aaaa en adelante.
     */
    private static function soloFecha(string $valor): string
    {
        if (preg_match('/\d{2}\/\d{2}\/\d{4}.*/', $valor, $m)) {
            return trim($m[0]);
        }
        return trim($valor);
    }

    private static function normalizarImporte(string $valor): float
    {
        // "$ 1,111,592.86 MXN" / "$3,143,851.23" -> 1111592.86
        $limpio = preg_replace('/[^0-9.]/', '', str_replace(',', '', $valor));
        return $limpio === '' ? 0.0 : (float) $limpio;
    }
}
