<?php
declare(strict_types=1);

/**
 * Pruebas de NotaCreditoPdfParser::parseTexto (UUID del timbre vs UUID relacionado):
 *   php tests/NotaCreditoPdfParserTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/NotaCreditoPdfParser.class.php';

$REL = 'AAAAAAAA-1111-2222-3333-BBBBBBBBBBBB';
$PROPIO = 'C5BB5D69-B101-451B-81BB-489C5B5C8290';

// Cadena del comprobante 4.0: campos 1..21 (16 = UUID relacionado en nota de crédito)
function cadenaComprobante(string $tipo, string $campo16): string
{
    $campos = [
        '4.0', 'NC', '001234', '2026-07-01T10:00:00', '99', '03', '1000.00', 'MXN', '0.00', '1160.00',
        $tipo, '01', '03100', 'PUE', '01', $campo16, 'MME141110IJ9', 'MGC MEXICO SA DE CV', '601',
        'DGA930823KD3', 'DIAZ GAS SA DE CV', '601',
    ];
    return '||' . implode('|', $campos) . '||';
}
function cadenaTimbre(string $uuid): string
{
    return '||1.1|' . $uuid . '|2026-07-01T10:00:00|SFE0807172W7|SELLOABC|00001000000500003416||';
}

// (a) NC con UUID relacionado y timbre propio
$t = "Cadena original del complemento\n" . cadenaComprobante('E', $REL) . "\nCadena original del complemento de certificación digital del SAT\n" . cadenaTimbre($PROPIO) . "\n";
$r = NotaCreditoPdfParser::parseTexto($t, 'nc.pdf');
check('a: uuid = timbre propio', $r['uuid'] === $PROPIO);
check('a: uuid_relacionado = campo 16', $r['uuid_relacionado'] === $REL);
check('a: raw_ok y tipo E', $r['raw_ok'] === true && $r['tipo_comprobante'] === 'E' && $r['rfc_emisor'] === 'MME141110IJ9');
check('a: folio y total', $r['folio'] === '001234' && $r['total'] === 1160.0);

// (b) valores partidos por espacios y saltos de línea
$rel2 = substr($REL, 0, 13) . "\n  " . substr($REL, 13);
$prop2 = substr($PROPIO, 0, 10) . " \n " . substr($PROPIO, 10, 12) . "\n" . substr($PROPIO, 22);
$t2 = str_replace($REL, $rel2, cadenaComprobante('E', $REL)) . "\n" . str_replace($PROPIO, $prop2, cadenaTimbre($PROPIO));
$r2 = NotaCreditoPdfParser::parseTexto($t2);
check('b: uuid con saltos', $r2['uuid'] === $PROPIO);
check('b: relacionado con saltos', $r2['uuid_relacionado'] === $REL);

// (c) sin cadena 1.1 ni Folio Fiscal
$r3 = NotaCreditoPdfParser::parseTexto(cadenaComprobante('E', $REL));
check('c: raw_ok false', $r3['raw_ok'] === false);
check('c: uuid vacío', $r3['uuid'] === '');
check('c: error de timbre', $r3['error'] === 'No se encontró el UUID del timbre (folio fiscal)');

// (c2) respaldo: etiqueta "Folio Fiscal"
$r3b = NotaCreditoPdfParser::parseTexto(cadenaComprobante('E', $REL) . "\nFolio Fiscal: " . $PROPIO . "\nFecha");
check('c2: Folio Fiscal como respaldo', $r3b['uuid'] === $PROPIO && $r3b['raw_ok'] === true);

// (d) tipo I sin CfdiRelacionados: el campo 16 es un RFC
$r4 = NotaCreditoPdfParser::parseTexto(cadenaComprobante('I', 'MME141110IJ9') . "\n" . cadenaTimbre($PROPIO));
check('d: uuid_relacionado vacío', $r4['uuid_relacionado'] === '');
check('d: uuid propio', $r4['uuid'] === $PROPIO && $r4['raw_ok'] === true);

// (e) PDF real de MGC: sin cadena ||1.1|; el folio fiscal impreso queda partido en dos líneas
$propioPartido = substr($PROPIO, 0, 24) . "
" . substr($PROPIO, 24);
$relPartido = substr($REL, 0, 23) . ' ' . substr($REL, 23);
$t5 = str_replace($REL, $relPartido, cadenaComprobante('E', $REL)) . "
Versión CFDI:4.0
Fecha Factura: 2026-11-10T11:25:57
" . $propioPartido . "
00001000000712122236
";
$r5 = NotaCreditoPdfParser::parseTexto($t5);
check('e: uuid impreso único distinto del relacionado', $r5['uuid'] === $PROPIO && $r5['uuid_relacionado'] === $REL && $r5['raw_ok'] === true);
$r5b = NotaCreditoPdfParser::parseTexto($t5 . "
11111111-2222-3333-4444-555555555555
");
check('e: dos UUID candidatos -> ambiguo, raw_ok false', $r5b['uuid'] === '' && $r5b['raw_ok'] === false);

// (f) con -layout el UUID impreso queda separado por otras columnas: no debe tomar el no. de certificado
$t6 = cadenaComprobante('E', $REL) . "
Folio Fiscal  Numero de Certificado del Emisor
  " . substr($PROPIO, 0, 24) . "   00001000000712122236   4500082596
  " . substr($PROPIO, 24) . "
";
$r6 = NotaCreditoPdfParser::parseTexto($t6);
check('f: layout partido no inventa UUID', $r6['uuid'] === '' && $r6['raw_ok'] === false);

finish();
