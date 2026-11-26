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

// (g) posiciones desplazadas (campo 16 ya no es el UUID): el relacionado sale de la cadena y nunca es el uuid
$desplazada = str_replace('|PUE|', '|PUE|CONTADO|', cadenaComprobante('E', $REL));
$r7 = NotaCreditoPdfParser::parseTexto($desplazada . "\nFolio Fiscal " . substr($PROPIO, 0, 24) . "   00001000000712122236\n" . substr($PROPIO, 24) . "\n");
check('g: relacionado tomado de la cadena', $r7['uuid_relacionado'] === $REL);
check('g: uuid partido en layout -> vacío, nunca el relacionado', $r7['uuid'] === '' && $r7['raw_ok'] === false);
$r7b = NotaCreditoPdfParser::parseTexto($desplazada . "\nFecha Factura 2026-07-01\n" . $PROPIO . "\n");
check('g: uuid propio impreso contiguo -> propio', $r7b['uuid'] === $PROPIO && $r7b['uuid_relacionado'] === $REL);
$r7c = NotaCreditoPdfParser::parseTexto($desplazada);
check('g: solo el relacionado en el documento -> vacío', $r7c['uuid'] === '' && $r7c['raw_ok'] === false);

// (h) tipo I con un UUID ajeno en una leyenda: 2 candidatos -> no se adivina
$AJENO = '11111111-2222-3333-4444-555555555555';
$r8 = NotaCreditoPdfParser::parseTexto(cadenaComprobante('I', 'MME141110IJ9') . "\nSustituye al CFDI $AJENO\n" . $PROPIO . "\n");
check('h: foreign + propio -> vacío', $r8['uuid'] === '' && $r8['raw_ok'] === false);
$r8b = NotaCreditoPdfParser::parseTexto(cadenaComprobante('I', 'MME141110IJ9') . "\nSustituye al CFDI $AJENO\n");
check('h: solo ajeno impreso = único candidato (límite conocido)', $r8b['uuid'] === $AJENO);

// (i) etiqueta "Folio Fiscal" seguida del no. de certificado: no pega dígitos de la columna vecina
$r9 = NotaCreditoPdfParser::parseTexto(cadenaComprobante('E', $REL) . "\nFolio Fiscal " . substr($PROPIO, 0, 24) . "   00001000000712122236\n");
check('i: etiqueta + certificado no forma UUID', $r9['uuid'] === '');
$r9b = NotaCreditoPdfParser::parseTexto(cadenaComprobante('E', $REL) . "\nFolio Fiscal: " . substr($PROPIO, 0, 24) . "\n " . substr($PROPIO, 24) . "\n");
check('i: etiqueta + UUID partido en 2 líneas -> propio', $r9b['uuid'] === $PROPIO);

// (j) mismo UUID impreso varias veces (etiqueta + QR ?id=UUID&re=...) cuenta una vez
$r10 = NotaCreditoPdfParser::parseTexto(cadenaComprobante('E', $REL) . "\nQR https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?id=" . $PROPIO . "&re=MME141110IJ9&rr=DGA930823KD3\nFecha\n" . $PROPIO . "\n");
check('j: UUID repetido (QR) cuenta una vez', $r10['uuid'] === $PROPIO && $r10['raw_ok'] === true);

// (k) varios UUID en la cadena y campo 16 sin UUID -> se rechaza con error explícito
$dos = str_replace('|PUE|', '|PUE|' . $AJENO . '|', cadenaComprobante('E', 'X')) ;
$dos = str_replace('|X|', '|' . $REL . '|', $dos);
$r11 = NotaCreditoPdfParser::parseTexto($dos . "\n" . $PROPIO . "\n");
check('k: cadena con 2 UUID y campo 16 sin UUID -> rechazo', $r11['uuid'] === '' && $r11['raw_ok'] === false && strpos((string)$r11['error'], 'varios UUID') !== false);

finish();
