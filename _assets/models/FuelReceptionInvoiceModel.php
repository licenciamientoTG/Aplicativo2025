<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/_assets/classes/common/AttachmentsPath.php';

class FuelReceptionInvoiceModel extends Model {

    // Mapeo completo de atributos CFDI 3.3/4.0 a columnas de
    // TG.dbo.FacturasRecibidas -- ReceptorRegimenFiscal y
    // DomicilioFiscalReceptor solo existen en CFDI 4.0 (quedan NULL en
    // comprobantes 3.3, que no traen esos atributos).
    private const NS_CFDI = 'http://www.sat.gob.mx/cfd/4';
    private const NS_TFD = 'http://www.sat.gob.mx/TimbreFiscalDigital';

    /**
     * Parsea un archivo XML de CFDI y devuelve los datos listos para
     * insertar en FacturasRecibidas + FacturasRecibidasConceptos.
     * Lanza Exception si el archivo no es un CFDI válido o no tiene UUID.
     */
    public function parseCfdiXml(string $xmlPath): array {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($xmlPath);
        if ($xml === false) {
            $errores = libxml_get_errors();
            libxml_clear_errors();
            $detalle = $errores ? trim($errores[0]->message) : 'formato desconocido';
            throw new Exception("El XML no se pudo leer ($detalle)");
        }

        $namespaces = $xml->getNamespaces(true);
        $cfdiNs = $namespaces['cfdi'] ?? null;
        $tfdNs = $namespaces['tfd'] ?? null;
        if ($cfdiNs === null) {
            throw new Exception('El XML no es un CFDI válido (falta el namespace cfdi:)');
        }

        $comprobante = $xml->attributes();
        $emisor = $xml->children($cfdiNs)->Emisor->attributes();
        $receptor = $xml->children($cfdiNs)->Receptor->attributes();
        $impuestos = $xml->children($cfdiNs)->Impuestos->attributes();

        $timbre = null;
        $complemento = $xml->children($cfdiNs)->Complemento ?? null;
        if ($complemento !== null && $tfdNs !== null) {
            $timbre = $complemento->children($tfdNs)->TimbreFiscalDigital->attributes();
        }
        if ($timbre === null || (string)($timbre['UUID'] ?? '') === '') {
            throw new Exception('El XML no tiene Timbre Fiscal Digital (no está timbrado, o le falta el UUID)');
        }

        $factura = [
            'Folio' => (string)($comprobante['Folio'] ?? '') ?: null,
            'Serie' => (string)($comprobante['Serie'] ?? '') ?: null,
            'Fecha' => (string)($comprobante['Fecha'] ?? '') ?: null,
            'FormaPago' => (string)($comprobante['FormaPago'] ?? '') ?: null,
            'MetodoPago' => (string)($comprobante['MetodoPago'] ?? '') ?: null,
            'TipoCambio' => (string)($comprobante['TipoCambio'] ?? '') ?: null,
            'Moneda' => (string)($comprobante['Moneda'] ?? '') ?: null,
            'SubTotal' => (string)($comprobante['SubTotal'] ?? '0'),
            'Total' => (string)($comprobante['Total'] ?? '0'),
            'Exportacion' => (string)($comprobante['Exportacion'] ?? '') ?: null,
            'TipoDeComprobante' => (string)($comprobante['TipoDeComprobante'] ?? '') ?: null,
            'LugarExpedicion' => (string)($comprobante['LugarExpedicion'] ?? '') ?: null,
            'Certificado' => (string)($comprobante['Certificado'] ?? '') ?: null,
            'NoCertificado' => (string)($comprobante['NoCertificado'] ?? '') ?: null,
            'Sello' => (string)($comprobante['Sello'] ?? '') ?: null,
            'EmisorNombre' => (string)($emisor['Nombre'] ?? '') ?: null,
            'EmisorRfc' => (string)($emisor['Rfc'] ?? '') ?: null,
            'EmisorRegimenFiscal' => (string)($emisor['RegimenFiscal'] ?? '') ?: null,
            'ReceptorNombre' => (string)($receptor['Nombre'] ?? '') ?: null,
            'ReceptorRfc' => (string)($receptor['Rfc'] ?? '') ?: null,
            'ReceptorRegimenFiscal' => (string)($receptor['RegimenFiscalReceptor'] ?? '') ?: null,
            'DomicilioFiscalReceptor' => (string)($receptor['DomicilioFiscalReceptor'] ?? '') ?: null,
            'UsoCFDI' => (string)($receptor['UsoCFDI'] ?? '') ?: null,
            'FechaTimbrado' => (string)($timbre['FechaTimbrado'] ?? '') ?: null,
            'RfcProvCertif' => (string)($timbre['RfcProvCertif'] ?? '') ?: null,
            'UUID' => (string)$timbre['UUID'],
            'NoCertificadoSAT' => (string)($timbre['NoCertificadoSAT'] ?? '') ?: null,
            'TotalImpuestosTrasladados' => (string)($impuestos['TotalImpuestosTrasladados'] ?? '0'),
            'TotalImpuestosRetenidos' => (string)($impuestos['TotalImpuestosRetenidos'] ?? '0'),
        ];

        $conceptos = [];
        $nodosConceptos = $xml->children($cfdiNs)->Conceptos->children($cfdiNs)->Concepto ?? [];
        foreach ($nodosConceptos as $concepto) {
            $attrs = $concepto->attributes();
            $conceptoData = [
                'Cantidad' => (string)($attrs['Cantidad'] ?? '0'),
                'ClaveProdServ' => (string)($attrs['ClaveProdServ'] ?? '') ?: null,
                'ClaveUnidad' => (string)($attrs['ClaveUnidad'] ?? '') ?: null,
                'Descripcion' => (string)($attrs['Descripcion'] ?? '') ?: null,
                'ValorUnitario' => (string)($attrs['ValorUnitario'] ?? '0'),
                'Importe' => (string)($attrs['Importe'] ?? '0'),
                'NoIdentificacion' => (string)($attrs['NoIdentificacion'] ?? '') ?: null,
                'Unidad' => (string)($attrs['Unidad'] ?? '') ?: null,
                'ObjetoImp' => null,
                'Impuesto' => null,
                'TasaOCuota' => null,
                'TipoFactor' => null,
                'Base' => null,
                'ImporteImpuesto' => null,
            ];

            $traslado = $concepto->children($cfdiNs)->Impuestos->children($cfdiNs)->Traslados->children($cfdiNs)->Traslado ?? null;
            if ($traslado !== null) {
                $tAttrs = $traslado->attributes();
                $conceptoData['ObjetoImp'] = (string)($attrs['ObjetoImp'] ?? '') ?: null;
                $conceptoData['Impuesto'] = (string)($tAttrs['Impuesto'] ?? '') ?: null;
                $conceptoData['TasaOCuota'] = (string)($tAttrs['TasaOCuota'] ?? '') ?: null;
                $conceptoData['TipoFactor'] = (string)($tAttrs['TipoFactor'] ?? '') ?: null;
                $conceptoData['Base'] = (string)($tAttrs['Base'] ?? '') ?: null;
                $conceptoData['ImporteImpuesto'] = (string)($tAttrs['Importe'] ?? '') ?: null;
            }

            $conceptos[] = $conceptoData;
        }

        $factura['Destino'] = null;
        $factura['Remision'] = null;
        $factura['PresentacionTesoro'] = null;

        // Tesoro manda estos 3 datos (folio de comprobante de carga, permiso/
        // remisión y folio de presentación) únicamente dentro de su Addenda
        // propietaria -- cfdi:Comprobante no los trae. El pipeline de correos
        // (CorreoFactruras.py, fuera de este repo) ya los captura para las
        // facturas que llegan por ahí; este parser del modal de subida manual
        // no los leía en absoluto -- hallado 2026-09-09 al subir una factura
        // de Tesoro/Diaz Gas y notar Destino/Remision/PresentacionTesoro NULL
        // pese a que el PDF sí los mostraba.
        $addenda = $xml->children($cfdiNs)->Addenda ?? null;
        if ($addenda !== null) {
            // AddendaEmisor/TesoroAddenda/Comprobantes/ComprobanteTesoro no
            // tienen namespace propio (a diferencia de cfdi:Addenda, que sí
            // lo tiene) -- acceder con -> hereda el namespace cfdi: del nodo
            // padre y no encuentra nada; children() sin argumento navega en
            // el namespace vacío por defecto, que es donde realmente viven.
            $comprobanteTesoro = $addenda->children()->AddendaEmisor->children()->TesoroAddenda
                ->children()->Comprobantes->children()->ComprobanteTesoro ?? null;
            if ($comprobanteTesoro !== null) {
                $ct = $comprobanteTesoro->attributes();
                // Convención confirmada contra facturas reales ya guardadas por
                // el pipeline de correos (ej. FacturasRecibidas.Id=72219):
                // Remision = número de comprobante de carga (numérico, ej.
                // "451303984"); Destino = número de permiso HYP (texto, ej.
                // "H/19873/COM/2017") -- al revés de lo que sugieren los
                // nombres de columna a primera vista.
                $factura['Remision'] = (string)($ct['ComprobanteCarga'] ?? $ct['NumeroDocumento'] ?? '') ?: null;
                $factura['PresentacionTesoro'] = (string)($ct['Presentacion'] ?? '') ?: null;
            }
        }

        // Destino = número de permiso HYP (ej. "H/19873/COM/2017"), igual
        // convención que usa el pipeline de correos para Petrotal -- se toma
        // del primer concepto que traiga el complemento
        // cfdi:ComplementoConcepto > hidrocarburospetroliferos:HidroYPetro.
        $hypNs = $namespaces['hidrocarburospetroliferos'] ?? null;
        if ($factura['Destino'] === null && $hypNs !== null && !empty($nodosConceptos)) {
            foreach ($nodosConceptos as $concepto) {
                $complementoConcepto = $concepto->children($cfdiNs)->ComplementoConcepto ?? null;
                $hyp = $complementoConcepto !== null ? ($complementoConcepto->children($hypNs)->HidroYPetro ?? null) : null;
                if ($hyp !== null) {
                    $hypAttrs = $hyp->attributes();
                    $numeroPermiso = (string)($hypAttrs['NumeroPermiso'] ?? '');
                    if ($numeroPermiso !== '') {
                        $factura['Destino'] = $numeroPermiso;
                        break;
                    }
                }
            }
        }

        return ['factura' => $factura, 'conceptos' => $conceptos];
    }

    /**
     * RFC del emisor -> proveedor de TG.dbo.Proveedores. Dos pasos porque
     * getProviderByRfc() (InvoiceCreditDebitNotesModel) solo llega hasta
     * SG12.dbo.Proveedores.cod -- TG.dbo.Proveedores.id_control_gas es el
     * FK real que liga ambos catálogos (confirmado en ProveedoresModel.php).
     */
    public function resolverProveedorPorRfc(string $rfc): ?array {
        $query = "
            SELECT t1.id, t2.den AS nombre
            FROM TG.dbo.Proveedores t1
            JOIN SG12.dbo.Proveedores t2 ON t2.cod = t1.id_control_gas
            WHERE t2.rfc = ?
        ";
        $rows = $this->sql->select($query, [$rfc]);
        return $rows[0] ?? null;
    }

    public function buscarPorUuid(string $uuid): ?array {
        $query = "SELECT * FROM TG.dbo.FacturasRecibidas WHERE UUID = ?";
        $rows = $this->sql->select($query, [$uuid]);
        return $rows[0] ?? null;
    }

    /**
     * Mapeo fijo id de TG.dbo.Proveedores -> nombre de carpeta de
     * attachments (mismos 7 proveedores de combustible que
     * FuelReceptionScheduleModel::IDS_PROVEEDORES_COMBUSTIBLE, mismos
     * nombres de carpeta que payment.php::ALLOWED_PROVIDERS).
     */
    private const CARPETA_POR_SUPPLIER_ID = [
        138 => 'premiergas',
        123 => 'tesoro',
        139 => 'mcg',
        150 => 'enerey',
        122 => 'petrotal',
        163 => 'aemsa',
        151 => 'essafuel',
    ];

    public function carpetaDeProveedor(int $supplierId): ?string {
        return self::CARPETA_POR_SUPPLIER_ID[$supplierId] ?? null;
    }

    /**
     * INSERT a FacturasRecibidas + cada concepto a
     * FacturasRecibidasConceptos, en una sola transacción -- si algo falla
     * a medias, no debe quedar un encabezado de factura sin sus conceptos.
     * Devuelve el Id nuevo de FacturasRecibidas.
     */
    public function insertarFactura(array $factura, array $conceptos): int {
        $this->sql->beginTransaction();
        try {
            $query = "
                INSERT INTO TG.dbo.FacturasRecibidas
                    (Folio, Serie, Fecha, FormaPago, MetodoPago, TipoCambio, Moneda,
                     SubTotal, Total, Exportacion, TipoDeComprobante, LugarExpedicion,
                     Certificado, NoCertificado, Sello, EmisorNombre, EmisorRfc,
                     EmisorRegimenFiscal, ReceptorNombre, ReceptorRfc, ReceptorRegimenFiscal,
                     DomicilioFiscalReceptor, UsoCFDI, FechaTimbrado, RfcProvCertif, UUID,
                     NoCertificadoSAT, TotalImpuestosTrasladados, TotalImpuestosRetenidos,
                     Destino, Remision, PresentacionTesoro)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            $invoiceId = (int)$this->sql->insert($query, [
                $factura['Folio'], $factura['Serie'], $factura['Fecha'], $factura['FormaPago'],
                $factura['MetodoPago'], $factura['TipoCambio'], $factura['Moneda'],
                $factura['SubTotal'], $factura['Total'], $factura['Exportacion'],
                $factura['TipoDeComprobante'], $factura['LugarExpedicion'], $factura['Certificado'],
                $factura['NoCertificado'], $factura['Sello'], $factura['EmisorNombre'],
                $factura['EmisorRfc'], $factura['EmisorRegimenFiscal'], $factura['ReceptorNombre'],
                $factura['ReceptorRfc'], $factura['ReceptorRegimenFiscal'],
                $factura['DomicilioFiscalReceptor'], $factura['UsoCFDI'], $factura['FechaTimbrado'],
                $factura['RfcProvCertif'], $factura['UUID'], $factura['NoCertificadoSAT'],
                $factura['TotalImpuestosTrasladados'], $factura['TotalImpuestosRetenidos'],
                $factura['Destino'], $factura['Remision'], $factura['PresentacionTesoro'],
            ]);

            $queryConcepto = "
                INSERT INTO TG.dbo.FacturasRecibidasConceptos
                    (FacturaId, Cantidad, ClaveProdServ, ClaveUnidad, Descripcion, ValorUnitario,
                     Importe, NoIdentificacion, ObjetoImp, Impuesto, TasaOCuota, TipoFactor,
                     Base, Unidad, ImporteImpuesto)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            foreach ($conceptos as $c) {
                $this->sql->insert($queryConcepto, [
                    $invoiceId, $c['Cantidad'], $c['ClaveProdServ'], $c['ClaveUnidad'],
                    $c['Descripcion'], $c['ValorUnitario'], $c['Importe'], $c['NoIdentificacion'],
                    $c['ObjetoImp'], $c['Impuesto'], $c['TasaOCuota'], $c['TipoFactor'],
                    $c['Base'], $c['Unidad'], $c['ImporteImpuesto'],
                ]);
            }

            $this->sql->commit();
            return $invoiceId;
        } catch (Exception $e) {
            $this->sql->rollBack();
            throw $e;
        }
    }

    /**
     * Mueve PDF y XML (ya subidos a una ruta temporal de PHP) a la carpeta
     * compartida de attachments, con el mismo nombre base (UUID en
     * mayúsculas con guión bajo en vez de guión, igual convención que usa
     * el flujo automático de correos) y solo cambiando la extensión.
     * El PDF se guarda en procesadas/ y el XML en procesadasXml/ (carpetas
     * distintas, igual que el flujo automático).
     */
    public function guardarArchivos(string $proveedorCarpeta, string $uuid, string $tmpPdfPath, string $tmpXmlPath): array {
        $dirPdf = AttachmentsPath::procesadasDir($proveedorCarpeta);
        if (!is_dir($dirPdf)) {
            mkdir($dirPdf, 0755, true);
        }
        $dirXml = AttachmentsPath::procesadasXmlDir($proveedorCarpeta);
        if (!is_dir($dirXml)) {
            mkdir($dirXml, 0755, true);
        }

        $nombreBase = strtoupper(str_replace('-', '_', $uuid));
        $nombrePdf = $nombreBase . '.pdf';
        $nombreXml = $nombreBase . '.xml';
        $rutaPdf = $dirPdf . '\\' . $nombrePdf;
        $rutaXml = $dirXml . '\\' . $nombreXml;

        if (!move_uploaded_file($tmpPdfPath, $rutaPdf)) {
            throw new Exception('No se pudo guardar el PDF en la carpeta de facturas');
        }
        if (!move_uploaded_file($tmpXmlPath, $rutaXml)) {
            throw new Exception('No se pudo guardar el XML en la carpeta de facturas');
        }

        return [
            'rutaPdf' => $rutaPdf, 'nombrePdf' => $nombrePdf,
            'rutaXml' => $rutaXml, 'nombreXml' => $nombreXml,
        ];
    }

    public function actualizarArchivos(int $invoiceId, array $rutas): void {
        $query = "
            UPDATE TG.dbo.FacturasRecibidas
            SET RutaArchivo = ?, NombreArchivo = ?, RutaXml = ?, NombreXml = ?
            WHERE Id = ?
        ";
        $this->sql->update($query, [
            $rutas['rutaPdf'], $rutas['nombrePdf'], $rutas['rutaXml'], $rutas['nombreXml'], $invoiceId,
        ]);
    }

    public function vincular(int $scheduleId, int $invoiceId, int $userId): void {
        $query = "
            INSERT INTO TG.dbo.fuel_reception_invoices (schedule_id, invoice_id, created_by, created_at)
            VALUES (?, ?, ?, GETDATE())
        ";
        $this->sql->insert($query, [$scheduleId, $invoiceId, $userId]);
    }

    public function desvincular(int $scheduleId): void {
        // MySqlPdoHandler::update() exige que el texto de la query contenga
        // la palabra "update" (ver stristr en su implementación) -- con un
        // DELETE, ese chequeo falla silenciosamente y la fila nunca se
        // borra. Hallado durante la verificación de este mismo fix wave
        // (una fila de prueba quedó residual tras llamar a desvincular()).
        $query = "DELETE FROM TG.dbo.fuel_reception_invoices WHERE schedule_id = ?";
        $this->sql->delete($query, [$scheduleId]);
    }

    /**
     * Mapeo schedule_id -> invoice_id de TODOS los vínculos existentes,
     * sin filtrar por fecha (la tabla fuel_reception_invoices es pequeña,
     * un filtro por fecha requeriría JOIN con fuel_reception_schedule
     * innecesariamente para este caso de uso). Usado por
     * scheduling_day_data() para evitar N llamadas individuales
     * (medido: 496ms para 53 filas vs 17ms con este enfoque).
     */
    public function obtenerVinculosPorScheduleId(): array {
        $query = "SELECT schedule_id, invoice_id FROM TG.dbo.fuel_reception_invoices";
        $rows = $this->sql->select($query, []);
        $mapa = [];
        foreach ($rows as $row) {
            $mapa[(int)$row['schedule_id']] = (int)$row['invoice_id'];
        }
        return $mapa;
    }

    public function obtenerFacturaDeRecepcion(int $scheduleId): ?array {
        $query = "
            SELECT f.Id, f.Folio, f.Fecha, f.Total, f.EmisorNombre, f.EmisorRfc, f.UUID,
                   f.RutaArchivo, f.NombreArchivo, f.RutaXml, f.NombreXml
            FROM TG.dbo.fuel_reception_invoices fri
            JOIN TG.dbo.FacturasRecibidas f ON f.Id = fri.invoice_id
            WHERE fri.schedule_id = ?
        ";
        $rows = $this->sql->select($query, [$scheduleId]);
        return $rows[0] ?? null;
    }
}
