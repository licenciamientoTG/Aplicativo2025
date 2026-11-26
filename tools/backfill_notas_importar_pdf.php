<?php
/**
 * Backfill: importa a FacturasRecibidas las notas de crédito/cargo históricas de MGC (proveedor 72)
 * a partir de su PDF guardado (_assets/uploads/credit_debit_notes/) y las liga
 * (factura_recibida_id, uuid, emp_cod, codgas NULL, origen='historica').
 *
 * Los PDF solo existen en el servidor de producción: correrlo ahí.
 * Orden: (1) docs/sql/backfill_notas_fr_2026-11-25.sql con @aplicar = 1 ANTES del deploy,
 * (2) deploy del código, (3) este CLI en simulación, (4) este CLI con --aplicar.
 *
 *   php tools/backfill_notas_importar_pdf.php              (simulación: no escribe nada)
 *   php tools/backfill_notas_importar_pdf.php --aplicar    (copia PDF, inserta FR y liga la nota)
 *
 * Selección: notas activas con factura_recibida_id y uuid NULL y al menos un documento en
 * invoice_credit_debit_notes_doc. Las de otro proveedor o sin documento solo se listan.
 * El UUID de la descripción ("Carga masiva - UUID ...") es el de la factura RELACIONADA; el de la
 * nota es el del timbre que lee NotaCreditoPdfParser. Decisión por nota: CfdiNotaImport::accionBackfill.
 *
 * Acciones: importar, ya-en-FR, ya-en-FR-total (FR con Total 0: corrige el Total desde el PDF y liga), ya-ligada, uuid-otro-cfdi, duplicada (mismo CFDI en 2+ notas: no se liga ninguna),
 *           requiere-revision (notas de cargo), pdf-no-coincide, no-legible, pdf-no-encontrado, ruta-invalida,
 *           sin-pdf, otro-proveedor. Con --aplicar exige el respaldo del SQL de backfill (Task 8).
 * El PDF se COPIA (no se mueve); la nota y la FR nueva se escriben en una sola transacción por nota.
 */
if (php_sapi_name() !== 'cli') {
    exit('Solo por línea de comandos.');
}

const BACKFILL_PROVEEDOR = 72;   // MGC: el parser solo está validado para sus PDF

$_SERVER['DOCUMENT_ROOT'] = __DIR__ . '/..';
$_SERVER['REQUEST_URI'] = '/';
chdir($_SERVER['DOCUMENT_ROOT']);
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/header.class.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/php_functions.php';
require $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/autoload.php';

$aplicar = in_array('--aplicar', $argv, true);
$frModel = new FuelReceptionInvoiceModel();   // conecta la BD (singleton)
$sql = $frModel->sql;

/** Copia (sin mover) el PDF a la carpeta de FR del proveedor. Mismas rutas/nombres que guardarArchivo. */
function copiarPdfFr(string $carpeta, string $uuid, string $origen): array
{
    $dir = AttachmentsPath::procesadasDir($carpeta);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $nombre = AttachmentsPath::nombreDesdeUuid($uuid, 'pdf');
    $destino = $dir . '\\' . $nombre;
    $parcial = $destino . '.tmp';
    // guardarSubido() usa move_uploaded_file (solo válido en una subida HTTP); en CLI se copia igual: .tmp + rename
    if (!@copy($origen, $parcial)) {
        @unlink($parcial);
        throw new Exception('No se pudo copiar el archivo a ' . $dir);
    }
    clearstatcache(true, $parcial);
    if (@filesize($parcial) !== @filesize($origen)) {
        @unlink($parcial);
        throw new Exception('La copia quedó incompleta en ' . $dir);
    }
    if (!@rename($parcial, $destino)) {
        @unlink($parcial);
        throw new Exception('No se pudo dejar el archivo en ' . $dir);
    }
    return ['ruta' => AttachmentsPath::rutaParaBd($destino), 'nombre' => $nombre];
}

$rfcProv = $sql->select("SELECT TOP 1 rfc FROM SG12.dbo.Proveedores WHERE cod = ?", [BACKFILL_PROVEEDOR]);
$rfcProv = strtoupper(trim((string)($rfcProv[0]['rfc'] ?? '')));
if ($rfcProv === '') {
    exit("No se encontró el RFC del proveedor " . BACKFILL_PROVEEDOR . "\n");
}

$notas = $sql->select("
    SELECT n.id, n.provider_id, n.note_type, n.note_number, n.amount, n.description,
           (SELECT TOP 1 d.file_path FROM TG.dbo.invoice_credit_debit_notes_doc d
             WHERE d.credit_note_id = n.id ORDER BY d.id) AS file_path
    FROM TG.dbo.invoice_credit_debit_notes n
    WHERE n.status = 1 AND n.factura_recibida_id IS NULL AND n.uuid IS NULL
      AND EXISTS (SELECT 1 FROM TG.dbo.invoice_credit_debit_notes_doc d WHERE d.credit_note_id = n.id)
    ORDER BY n.id", []);

$respaldo = $sql->select("SELECT OBJECT_ID('TG.dbo.bkp_notas_fr_20261125_icdn') AS oid", []);
$hayRespaldo = !empty($respaldo[0]['oid']);
if (!$hayRespaldo) {
    $aviso = 'Corre primero docs/sql/backfill_notas_fr_2026-11-25.sql con @aplicar = 1';
    if ($aplicar) {
        exit("ABORTADO: $aviso\n");
    }
    echo "ADVERTENCIA: $aviso (sin eso --aplicar se negara a escribir)\n";
}

echo ($aplicar ? "MODO APLICAR\n" : "SIMULACION (sin --aplicar: no se escribe nada)\n");
echo "note_id | note_number | amount | uuid | uuid_origen | pdf | accion | detalle\n";

// Todas las notas activas sin ligar del proveedor (con o sin PDF): una copia sin PDF también cuenta como duplicada
$sinLigar = $sql->select("
    SELECT id, note_type, note_number, amount FROM TG.dbo.invoice_credit_debit_notes
    WHERE status = 1 AND provider_id = ? AND factura_recibida_id IS NULL AND uuid IS NULL", [BACKFILL_PROVEEDOR]);

// Pasada 1: resolver ruta, parsear y juntar los UUID del PDF
$info = [];       // id => ['accion','detalle','parsed','abs']
$porUuid = [];    // UUID => [ids]
foreach ($notas as $n) {
    $id = (int)$n['id'];
    $pdfRuta = (string)($n['file_path'] ?? '');
    $i = ['accion' => '', 'detalle' => '', 'parsed' => null, 'abs' => ''];
    if ((int)$n['provider_id'] !== BACKFILL_PROVEEDOR) {
        $i['accion'] = 'otro-proveedor';
        $i['detalle'] = 'provider_id ' . $n['provider_id'];
    } elseif ($pdfRuta === '') {
        $i['accion'] = 'sin-pdf';
    } elseif (!CfdiNotaImport::rutaDocValida($pdfRuta)) {
        $i['accion'] = 'ruta-invalida';
    } else {
        $i['abs'] = __DIR__ . '/../_assets/' . $pdfRuta;
        if (!is_file($i['abs'])) {
            $i['accion'] = 'pdf-no-encontrado';
        } else {
            $i['parsed'] = NotaCreditoPdfParser::parse($i['abs'], basename($i['abs']));
            if (!empty($i['parsed']['raw_ok'])) {
                $porUuid[strtoupper((string)$i['parsed']['uuid'])][] = $id;
            }
        }
    }
    $info[$id] = $i;
}

// Pasada 2: decidir y aplicar
$cuenta = [];
foreach ($notas as $n) {
    $id = (int)$n['id'];
    $i = $info[$id];
    $parsed = $i['parsed'];
    $accion = $i['accion'];
    $detalle = $i['detalle'];
    $uuid = $parsed ? (string)($parsed['uuid'] ?? '') : '';
    $origen = $parsed ? (string)($parsed['uuid_origen'] ?? '') : '';
    $pdfRuta = (string)($n['file_path'] ?? '');

    if ($parsed !== null) {
        $existente = $uuid !== '' ? $frModel->buscarPorUuid($uuid) : null;
        $otra = false;
        if ($uuid !== '') {
            $q = $sql->select("
                SELECT TOP 1 id FROM TG.dbo.invoice_credit_debit_notes
                WHERE status = 1 AND id <> ? AND (uuid = ? " . ($existente ? "OR factura_recibida_id = ?" : "") . ")",
                $existente ? [$id, $uuid, (int)$existente['Id']] : [$id, $uuid]);
            $otra = !empty($q);
        }
        $mismoUuid = $uuid !== '' ? array_values(array_diff($porUuid[strtoupper($uuid)] ?? [], [$id])) : [];
        $otrasSinLigar = [];
        if (!empty($parsed['raw_ok'])) {
            foreach ($sinLigar as $o) {
                if ((int)$o['id'] !== $id && CfdiNotaImport::notaCoincideConPdf($o, $parsed)) {
                    $otrasSinLigar[] = (int)$o['id'];
                }
            }
        }
        $d = CfdiNotaImport::accionBackfill($n + ['rfc_proveedor' => $rfcProv], $parsed, $existente, $otra, $mismoUuid, $otrasSinLigar);
        $accion = $d['accion'];
        $detalle = trim($d['detalle'] . ($d['aviso'] !== '' ? ' [AVISO ' . $d['aviso'] . ']' : ''));

        if ($aplicar && in_array($accion, ['importar', 'ya-en-FR', 'ya-en-FR-total'], true)) {
            $res = aplicarNota($n, $parsed, $existente, $i['abs'], $accion, $frModel, $sql);
            $detalle = trim($detalle . ' ' . $res);
            if (str_starts_with($res, 'ERROR')) {
                $accion .= '-FALLO';
            }
        }
    }
    $cuenta[$accion] = ($cuenta[$accion] ?? 0) + 1;
    echo implode(' | ', [$id, $n['note_number'], $n['amount'], $uuid, $origen, $pdfRuta, $accion, $detalle]) . "\n";
}

echo "\nResumen" . ($aplicar ? '' : ' (simulación)') . ":\n";
ksort($cuenta);
foreach ($cuenta as $a => $c) {
    echo "  $a: $c\n";
}
echo '  total: ' . count($notas) . "\n";

/**
 * Copia el PDF y, en una sola transacción, inserta la FR (si no existe) y liga la nota.
 * Devuelve un texto para la columna detalle.
 */
function aplicarNota(array $n, array $parsed, ?array $existente, string $pdfAbs, string $accion, FuelReceptionInvoiceModel $frModel, $sql): string
{
    $uuid = (string)$parsed['uuid'];
    $fila = CfdiNotaImport::filaDesdePdf($parsed);
    $carpeta = NotaCfdi::carpetaPorRfc((string)$fila['EmisorRfc']);   // 'mcg'
    $archivo = null;
    try {
        if ($accion === 'importar' || empty($existente['RutaArchivo'])) {
            $archivo = copiarPdfFr($carpeta, $uuid, $pdfAbs);
        }
    } catch (Throwable $e) {
        return 'ERROR copiando PDF: ' . $e->getMessage();
    }

    $emp = $sql->select("SELECT MIN(cod) AS cod FROM SG12.dbo.Empresas WHERE rfc = ?", [$parsed['rfc_receptor']]);
    $empCod = isset($emp[0]['cod']) && $emp[0]['cod'] !== null ? (int)$emp[0]['cod'] : null;

    $sql->beginTransaction();
    try {
        if ($existente) {
            $frId = (int)$existente['Id'];
            if ($accion === 'ya-en-FR-total') {
                // FR importada antes solo con PDF y Total 0: el Total sale del PDF (mismo UUID, folio, RFC y SubTotal)
                $affT = $sql->updateSafe(
                    "UPDATE TG.dbo.FacturasRecibidas SET Total = ? WHERE Id = ? AND ISNULL(Total, 0) = 0",
                    [(float)$fila['Total'], $frId]);
                if ($affT !== 1) {
                    throw new Exception('El UPDATE del Total de la FR afectó ' . var_export($affT, true) . ' filas');
                }
            }
        } else {
            // Mismo INSERT que FuelReceptionInvoiceModel::insertarFactura (sin conceptos), pero sin su
            // transacción propia (no se puede anidar) y con OrigenImportacion = 'backfill'.
            $frId = (int)$sql->insert("
                INSERT INTO TG.dbo.FacturasRecibidas
                    (Folio, Serie, Fecha, FormaPago, MetodoPago, TipoCambio, Moneda,
                     SubTotal, Total, Exportacion, TipoDeComprobante, LugarExpedicion,
                     Certificado, NoCertificado, Sello, EmisorNombre, EmisorRfc,
                     EmisorRegimenFiscal, ReceptorNombre, ReceptorRfc, ReceptorRegimenFiscal,
                     DomicilioFiscalReceptor, UsoCFDI, FechaTimbrado, RfcProvCertif, UUID,
                     NoCertificadoSAT, TotalImpuestosTrasladados, TotalImpuestosRetenidos,
                     Destino, Remision, PresentacionTesoro, OrigenImportacion)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'backfill')",
                [
                    $fila['Folio'], $fila['Serie'], $fila['Fecha'], $fila['FormaPago'],
                    $fila['MetodoPago'], $fila['TipoCambio'], $fila['Moneda'],
                    $fila['SubTotal'], $fila['Total'], $fila['Exportacion'],
                    $fila['TipoDeComprobante'], $fila['LugarExpedicion'], $fila['Certificado'],
                    $fila['NoCertificado'], $fila['Sello'], $fila['EmisorNombre'],
                    $fila['EmisorRfc'], $fila['EmisorRegimenFiscal'], $fila['ReceptorNombre'],
                    $fila['ReceptorRfc'], $fila['ReceptorRegimenFiscal'],
                    $fila['DomicilioFiscalReceptor'], $fila['UsoCFDI'], $fila['FechaTimbrado'],
                    $fila['RfcProvCertif'], $fila['UUID'], $fila['NoCertificadoSAT'],
                    $fila['TotalImpuestosTrasladados'], $fila['TotalImpuestosRetenidos'],
                    $fila['Destino'], $fila['Remision'], $fila['PresentacionTesoro'],
                ]);
            if ($frId <= 0) {
                throw new Exception('No se obtuvo el Id de la FR insertada');
            }
        }
        if ($archivo !== null
            && $sql->updateSafe("UPDATE TG.dbo.FacturasRecibidas SET RutaArchivo = ?, NombreArchivo = ? WHERE Id = ?",
                [$archivo['ruta'], $archivo['nombre'], $frId]) === false) {
            throw new Exception('No se pudo guardar la ruta del PDF en la FR');
        }
        $aff = $sql->updateSafe("
            UPDATE TG.dbo.invoice_credit_debit_notes
            SET factura_recibida_id = ?, uuid = ?, emp_cod = ?, codgas = ?, origen = 'historica'
            WHERE id = ? AND factura_recibida_id IS NULL AND uuid IS NULL",
            [$frId, $uuid, $empCod, CfdiNotaImport::codgasDeFr($existente), (int)$n['id']]);
        if ($aff !== 1) {
            throw new Exception('El UPDATE de la nota afectó ' . var_export($aff, true) . ' filas');
        }
        $sql->commit();
    } catch (Throwable $e) {
        $sql->rollBack();
        return 'ERROR BD (revertido): ' . $e->getMessage()
            . ($archivo ? ' | PDF ya copiado en ' . $archivo['ruta'] : '');
    }
    return 'FR ' . $frId . ' emp_cod ' . ($empCod ?? 'NULL') . ($archivo ? ' pdf ' . $archivo['ruta'] : '');
}
