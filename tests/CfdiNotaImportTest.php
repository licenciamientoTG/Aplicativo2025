<?php
declare(strict_types=1);

/**
 * Pruebas de CfdiNotaImport (lógica pura de importar CFDI de notas):
 *   php tests/CfdiNotaImportTest.php
 */
require __DIR__ . '/support.php';
require __DIR__ . '/../_assets/classes/CfdiNotaImport.class.php';

check('filaDesdePdf raw_ok=false -> null', CfdiNotaImport::filaDesdePdf(['raw_ok' => false]) === null);

$parsed = [
    'raw_ok' => true,
    'uuid' => 'C5BB5D69-B101-451B-81BB-489C5B5C8290',
    'folio' => '034398',
    'serie' => 'SE',
    'fecha' => '2026-08-20',
    'subtotal' => 886.13,
    'total' => 1027.91,
    'tipo_comprobante' => 'I',
    'rfc_emisor' => 'MME141110IJ9',
    'nombre_emisor' => 'MGC MEXICO',
    'rfc_receptor' => 'DGA930823KD3',
    'nombre_receptor' => 'DIAZ GAS',
];
$fila = CfdiNotaImport::filaDesdePdf($parsed);
check('filaDesdePdf UUID', $fila['UUID'] === 'C5BB5D69-B101-451B-81BB-489C5B5C8290');
check('filaDesdePdf Sello null', $fila['Sello'] === null);
check('filaDesdePdf Total', $fila['Total'] === 1027.91);
check('filaDesdePdf SubTotal', $fila['SubTotal'] === 886.13);
check('filaDesdePdf Moneda MXN', $fila['Moneda'] === 'MXN');
check('filaDesdePdf emisor/receptor', $fila['EmisorRfc'] === 'MME141110IJ9' && $fila['ReceptorRfc'] === 'DGA930823KD3'
    && $fila['EmisorNombre'] === 'MGC MEXICO' && $fila['ReceptorNombre'] === 'DIAZ GAS');
check('filaDesdePdf tipo/folio/serie/fecha', $fila['TipoDeComprobante'] === 'I' && $fila['Folio'] === '034398'
    && $fila['Serie'] === 'SE' && $fila['Fecha'] === '2026-08-20');
check('filaDesdePdf FechaTimbrado null', $fila['FechaTimbrado'] === null);
check('filaDesdePdf TotalImpuestos null', $fila['TotalImpuestosTrasladados'] === null && $fila['TotalImpuestosRetenidos'] === null);

$grupos = CfdiNotaImport::emparejarArchivos([
    ['name' => 'a.xml', 'tmp_name' => '/tmp/1', 'error' => 0],
    ['name' => 'a.PDF', 'tmp_name' => '/tmp/2', 'error' => 0],
    ['name' => 'b.pdf', 'tmp_name' => '/tmp/3', 'error' => 0],
    ['name' => 'c.txt', 'tmp_name' => '/tmp/4', 'error' => 0],
]);
check('emparejar -> 2 grupos', count($grupos) === 2);
check('grupo a con xml y pdf', $grupos[0]['base'] === 'a' && $grupos[0]['xml'] === '/tmp/1'
    && $grupos[0]['pdf'] === '/tmp/2' && $grupos[0]['pdf_name'] === 'a.PDF');
check('grupo b solo pdf', $grupos[1]['base'] === 'b' && $grupos[1]['xml'] === null && $grupos[1]['pdf'] === '/tmp/3');

$g2 = CfdiNotaImport::emparejarArchivos([
    ['name' => 'X.XML', 'tmp_name' => '/t/1', 'error' => 0],
    ['name' => 'x.pdf', 'tmp_name' => '/t/2', 'error' => 0],
    ['name' => 'y.xml', 'tmp_name' => '/t/3', 'error' => 4],
]);
check('sin distinguir mayúsculas y con error ignorado', count($g2) === 1 && $g2[0]['xml'] === '/t/1' && $g2[0]['pdf'] === '/t/2');

require __DIR__ . '/../_assets/classes/NotaCfdi.class.php';
check('pdf flete: servicio asociado', CfdiNotaImport::pdfCoincideConClase("Descripción
SERVICIO
Asociado   a flete", 'FALSO_FLETE'));
check('pdf flete: clave 80141629', CfdiNotaImport::pdfCoincideConClase('80141629 Cargo', 'FALSO_FLETE'));
check('pdf flete con combustible -> no', !CfdiNotaImport::pdfCoincideConClase('Servicio asociado PEMEX Magna 15101514', 'FALSO_FLETE'));
check('pdf flete con diesel -> no', !CfdiNotaImport::pdfCoincideConClase('80141629 diesel', 'FALSO_FLETE'));
check('pdf flete sin nada -> no', !CfdiNotaImport::pdfCoincideConClase('Gasolina regular 15101515', 'FALSO_FLETE'));
check('pdf moratorios ok', CfdiNotaImport::pdfCoincideConClase('Intereses  Moratorios 2705', 'MORATORIOS'));
check('pdf moratorios no', !CfdiNotaImport::pdfCoincideConClase('Servicio asociado', 'MORATORIOS'));
check('pdf clase inválida -> no', !CfdiNotaImport::pdfCoincideConClase('Servicio asociado', 'NOTA_CREDITO'));

check('pdf moratorios: factura de combustible con leyenda de pagare -> no', !CfdiNotaImport::pdfCoincideConClase('PEMEX MAGNA 15101514 ... causará intereses moratorios al tipo de 3% mensual', 'MORATORIOS'));
check('pdf moratorios real -> si', CfdiNotaImport::pdfCoincideConClase('Intereses Moratorios 2705035811 04-Agosto- 2026', 'MORATORIOS'));

finish();
