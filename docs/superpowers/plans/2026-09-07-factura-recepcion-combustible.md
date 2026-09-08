# Factura (PDF+XML) para Recepciones de Combustible — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir que un usuario de `/supply/scheduling` suba el PDF+XML (CFDI) de la factura de una recepción de combustible ya guardada, y que esa factura quede como una fila real en `TG.dbo.FacturasRecibidas` (con sus conceptos), vinculada a esa recepción — visible para el resto del sistema (reportes, conciliación) igual que cualquier otra factura.

**Architecture:** Un modelo nuevo (`FuelReceptionInvoiceModel`) concentra el parseo del CFDI XML (con `SimpleXMLElement`), la resolución de proveedor por RFC, el insert transaccional a `FacturasRecibidas`+`FacturasRecibidasConceptos`, el guardado de archivos en la carpeta compartida de attachments, y el CRUD de una tabla de vínculo nueva `fuel_reception_invoices` (N:1 factura→recepciones). Cuatro endpoints nuevos en el controlador `Supply` exponen ese modelo. En el frontend, un tercer ícono junto a Editar/Cancelar en cada fila de `scheduling.js` abre un modal aparte (mismo patrón "vista parcial Twig vía fetch" que ya usa el modal de captura) que muestra el formulario de subida o la vista de solo lectura según el estado.

**Tech Stack:** PHP 8 sin framework, Twig, jQuery, Bootstrap Material Design, SQL Server vía PDO (`MySqlPdoHandler`), `SimpleXMLElement` para parseo XML (ya disponible en PHP core, sin dependencia nueva).

**Spec:** `docs/superpowers/specs/2026-09-07-factura-recepcion-combustible-design.md`

## Global Constraints

- Permiso requerido en todos los endpoints nuevos: `authorized(95)` (mismo permiso que el resto de `/supply/scheduling`).
- Tamaño máximo por archivo: 10MB (mismo límite que `credit_debit_notes` en `payment.php:4800` y que `mis_recepciones.html`).
- `TG.dbo.FacturasRecibidas.UUID` es `varchar(50) NOT NULL` — cualquier INSERT debe garantizar un UUID no vacío, o la query falla a nivel BD.
- La resolución RFC→proveedor usa `InvoiceCreditDebitNotesModel::getProviderByRfc($rfc)` (ya existente, `_assets/models/InvoiceCreditDebitNotesModel.php:182`), que devuelve `['cod' => ..., 'den' => ..., 'rfc' => ...]` de `SG12.dbo.Proveedores` — NO la cadena de 3 pasos que el spec original describía (esa era específica de `PetrotalReconciliationModel`, con RFCs hardcodeados, no reutilizable). Para llegar al `id` de `TG.dbo.Proveedores` hace falta un segundo paso: `SELECT id FROM TG.dbo.Proveedores WHERE id_control_gas = ?` con el `cod` obtenido.
- Ningún XML de CFDI real existe en este repo para pruebas (confirmado: los únicos XML presentes son reportes de CRE, otro dominio). La Tarea 2 debe verificarse contra un XML de factura real que el usuario proporcione durante la implementación — el plan no puede incluir ese archivo de fixture porque no existe todavía.
- `RutaArchivo`/`NombreArchivo`/`RutaXml`/`NombreXml` en `FacturasRecibidas` son las 4 columnas de archivo; las dos últimas ya existen en el esquema (migradas previamente) pero ningún código PHP las escribe hoy — este módulo será el primero.
- Los 7 IDs de proveedores de combustible y sus nombres de carpeta de attachments ya están fijos y conocidos: Premier Gas=138, Tesoro=123, MGC=139, Enerey=150, Petrotal=122, AEMSA=163, Essa Fuel=151 (`FuelReceptionScheduleModel::IDS_PROVEEDORES_COMBUSTIBLE`). Nombres de carpeta reales usados hoy en `payment.php::ALLOWED_PROVIDERS`: `aemsa, enerey, essafuel, lobo, mcg, petrotal, premiergas, tesoro`.

---

## Task 1: Migración de BD — tabla `fuel_reception_invoices` y clase compartida `AttachmentsPath`

**Files:**
- Create: `docs/sql/fuel_reception_invoices.sql`
- Create: `_assets/classes/common/AttachmentsPath.php`
- Modify: `_assets/controllers/payment.php` (reemplazar la constante privada `BASE_ATTACHMENTS_PATH` por la clase compartida)

**Interfaces:**
- Produces: `AttachmentsPath::BASE` (string constante), `AttachmentsPath::procesadasDir(string $proveedorCarpeta): string` — devuelve `BASE . '\' . $proveedorCarpeta . '\procesadas'`. Usado por Task 3.
- Produces: tabla `TG.dbo.fuel_reception_invoices(id, schedule_id, invoice_id, created_by, created_at)` con `UNIQUE(schedule_id)`. Usada por Task 3.

- [ ] **Step 1: Escribir la migración SQL idempotente**

Archivo `docs/sql/fuel_reception_invoices.sql`:

```sql
USE [TG];
GO

IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'fuel_reception_invoices'
)
BEGIN
    CREATE TABLE [TG].[dbo].[fuel_reception_invoices] (
        id INT IDENTITY(1,1) PRIMARY KEY,
        schedule_id INT NOT NULL,
        invoice_id INT NOT NULL,
        created_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT GETDATE(),
        CONSTRAINT FK_fri_schedule FOREIGN KEY (schedule_id)
            REFERENCES [TG].[dbo].[fuel_reception_schedule](id),
        CONSTRAINT FK_fri_invoice FOREIGN KEY (invoice_id)
            REFERENCES [TG].[dbo].[FacturasRecibidas](Id),
        CONSTRAINT UQ_fri_schedule UNIQUE (schedule_id)
    );
    PRINT 'Tabla fuel_reception_invoices creada.';
END
ELSE
    PRINT 'Tabla fuel_reception_invoices ya existía, sin cambios.';
GO
```

- [ ] **Step 2: Ejecutar la migración contra la BD de desarrollo**

Run:
```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp"
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
\$db = MySqlPdoHandler::getInstance();
\$sql = file_get_contents('docs/sql/fuel_reception_invoices.sql');
foreach (array_filter(array_map('trim', explode('GO', \$sql))) as \$batch) {
    if (\$batch === '') continue;
    \$db->getPdo()->exec(\$batch);
}
echo 'OK' . PHP_EOL;
"
```
Expected: imprime `OK` sin errores. Si `getPdo()` no existe en `MySqlPdoHandler`, usar en su lugar `\$db->select(\$batch, [])` para cada batch (el handler ya acepta DDL vía `select()` en otros scripts de este repo — confirmar con `grep -n "function select" _assets/classes/common/MySqlPdoHandler.class.php` si hay dudas).

- [ ] **Step 3: Verificar que la tabla existe con las columnas correctas**

Run:
```bash
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
\$db = MySqlPdoHandler::getInstance();
\$rows = \$db->select(\"SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='fuel_reception_invoices' AND TABLE_SCHEMA='dbo' ORDER BY ORDINAL_POSITION\", []);
foreach (\$rows as \$r) echo \$r['COLUMN_NAME'] . ' | ' . \$r['DATA_TYPE'] . PHP_EOL;
"
```
Expected: 5 filas (`id`, `schedule_id`, `invoice_id`, `created_by`, `created_at`) con tipos `int, int, int, int, datetime`.

- [ ] **Step 4: Crear la clase compartida `AttachmentsPath`**

Archivo `_assets/classes/common/AttachmentsPath.php`:

```php
<?php
// Carpeta raíz donde el flujo automático de correos (CorreoFactruras.py,
// fuera de este repo) y el import manual de payment.php dejan los
// adjuntos ya procesados -- constante compartida para que cualquier
// módulo PHP que necesite escribir/leer ahí use la misma ruta, en vez de
// duplicarla (antes vivía privada dentro de Payment::BASE_ATTACHMENTS_PATH).
class AttachmentsPath {
    const BASE = 'C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas\attachments';

    public static function procesadasDir(string $proveedorCarpeta): string {
        return self::BASE . '\\' . $proveedorCarpeta . '\\procesadas';
    }
}
```

- [ ] **Step 5: Verificar sintaxis**

Run: `php -l _assets/classes/common/AttachmentsPath.php`
Expected: `No syntax errors detected`

- [ ] **Step 6: Actualizar `payment.php` para usar la clase compartida**

En `_assets/controllers/payment.php`, localizar la constante privada `BASE_ATTACHMENTS_PATH` (línea ~8) y toda referencia a ella dentro de la clase (usada en `save_imported_pdf()`, línea ~5601). Reemplazar:

```php
private const BASE_ATTACHMENTS_PATH = 'C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas\attachments';
```

por: eliminar esa línea por completo, y cambiar cada uso de `self::BASE_ATTACHMENTS_PATH` en el archivo por `AttachmentsPath::BASE`. Buscar todas las ocurrencias con:
```bash
grep -n "BASE_ATTACHMENTS_PATH" _assets/controllers/payment.php
```
y reemplazar cada una manualmente (no debe quedar ninguna referencia a `self::BASE_ATTACHMENTS_PATH` ni a la constante eliminada).

- [ ] **Step 7: Verificar sintaxis y comportamiento sin cambios**

Run: `php -l _assets/controllers/payment.php`
Expected: `No syntax errors detected`

Run:
```bash
grep -n "BASE_ATTACHMENTS_PATH" _assets/controllers/payment.php
```
Expected: cero resultados (todas las referencias ya apuntan a `AttachmentsPath::BASE`).

- [ ] **Step 8: Commit**

```bash
git add docs/sql/fuel_reception_invoices.sql _assets/classes/common/AttachmentsPath.php _assets/controllers/payment.php
git commit -m "Agregar tabla fuel_reception_invoices y compartir BASE_ATTACHMENTS_PATH

Prepara la infraestructura de BD y de rutas de archivos para que
/supply/scheduling pueda subir facturas CFDI de recepciones sin
duplicar la constante de carpeta de attachments que ya usa payment.php."
```

---

## Task 2: `FuelReceptionInvoiceModel` — parseo de CFDI y resolución de proveedor

**Files:**
- Create: `_assets/models/FuelReceptionInvoiceModel.php`

**Interfaces:**
- Consumes: `InvoiceCreditDebitNotesModel::getProviderByRfc(string $rfc): array|false` (ya existe, `_assets/models/InvoiceCreditDebitNotesModel.php:182`).
- Produces: `FuelReceptionInvoiceModel::parseCfdiXml(string $xmlPath): array` — devuelve `['factura' => array, 'conceptos' => array[]]` o lanza `Exception` con mensaje claro si el XML no es un CFDI válido.
- Produces: `FuelReceptionInvoiceModel::resolverProveedorPorRfc(string $rfc): ?array` — devuelve `['id' => int, 'nombre' => string]` de `TG.dbo.Proveedores`, o `null` si no hay match.
- Produces: `FuelReceptionInvoiceModel::buscarPorUuid(string $uuid): ?array` — fila completa de `FacturasRecibidas` o `null`.

- [ ] **Step 1: Crear el esqueleto del modelo con el parser CFDI**

Archivo `_assets/models/FuelReceptionInvoiceModel.php`:

```php
<?php
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
}
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l _assets/models/FuelReceptionInvoiceModel.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Probar el parser contra un XML de CFDI real**

**No existe ningún XML de factura real en este repo** (verificado: todos los `.xml` presentes son reportes de CRE, no CFDI de facturación). Pide al usuario un XML de factura real de cualquiera de los 7 proveedores de combustible (puede ser cualquier factura ya archivada en `C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas\attachments\<proveedor>\procesadas\*.xml`) y guárdalo temporalmente en el scratchpad de la sesión, por ejemplo `C:\ruta\a\prueba.xml`.

Run:
```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp"
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
require_once MODELS . 'FuelReceptionInvoiceModel.php';
\$m = new FuelReceptionInvoiceModel();
\$resultado = \$m->parseCfdiXml('C:\\\\ruta\\\\a\\\\prueba.xml');
echo json_encode(\$resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
"
```
Expected: JSON con `factura.UUID` no vacío, `factura.EmisorRfc`/`EmisorNombre` poblados, `factura.Total`/`SubTotal` numéricos coherentes con el PDF de esa misma factura, y `conceptos` con al menos un elemento. Si el CFDI es 3.3 (sin namespace `http://www.sat.gob.mx/cfd/4`), verificar que el parser igual funcione — el código usa `$xml->getNamespaces(true)` para detectar el namespace real del documento en vez de asumir la versión, así que debe soportar ambas versiones sin cambios. Si falla con 3.3, ajustar `resolverProveedorPorRfc`/`parseCfdiXml` para no depender de `self::NS_CFDI`/`self::NS_TFD` como constantes fijas (ya no se usan directamente en el código de arriba — son vestigiales, considerar eliminarlas si no se necesitan).

- [ ] **Step 4: Probar `resolverProveedorPorRfc` con un RFC real de proveedor de combustible**

Run (usando el RFC que salió del Step 3, o cualquier RFC conocido de Premier Gas/Tesoro/etc.):
```bash
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
require_once MODELS . 'FuelReceptionInvoiceModel.php';
\$m = new FuelReceptionInvoiceModel();
var_dump(\$m->resolverProveedorPorRfc('RFC_REAL_AQUI'));
"
```
Expected: array con `id` (uno de los 7 IDs conocidos: 138, 123, 139, 150, 122, 163, 151) y `nombre` no vacío. Si devuelve `null` para un RFC que sabes que sí es de un proveedor de combustible, verificar que `SG12.dbo.Proveedores.rfc` para ese proveedor esté poblado (columna puede venir vacía en catálogos viejos).

- [ ] **Step 5: Probar `buscarPorUuid` contra un UUID que ya exista en BD**

Run:
```bash
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
require_once MODELS . 'FuelReceptionInvoiceModel.php';
\$m = new FuelReceptionInvoiceModel();
\$rows = (new MySqlPdoHandler())::getInstance()->select('SELECT TOP 1 UUID FROM TG.dbo.FacturasRecibidas WHERE UUID IS NOT NULL', []);
\$uuid = \$rows[0]['UUID'];
echo 'probando con UUID: ' . \$uuid . PHP_EOL;
var_dump(\$m->buscarPorUuid(\$uuid) !== null);
"
```
Expected: `bool(true)`.

- [ ] **Step 6: Commit**

```bash
git add _assets/models/FuelReceptionInvoiceModel.php
git commit -m "Agregar FuelReceptionInvoiceModel: parseo de CFDI y resolución de proveedor por RFC"
```

---

## Task 3: `FuelReceptionInvoiceModel` — insert transaccional, vínculo y archivos

**Files:**
- Modify: `_assets/models/FuelReceptionInvoiceModel.php`

**Interfaces:**
- Consumes: `AttachmentsPath::procesadasDir(string $proveedorCarpeta): string` (Task 1).
- Consumes: `$this->sql->beginTransaction()`, `$this->sql->commit()`, `$this->sql->rollBack()`, `$this->sql->insert($query, $params)`, `$this->sql->update($query, $params)` (métodos ya existentes en el wrapper de BD, documentados en `CLAUDE.md`).
- Produces: `insertarFactura(array $factura, array $conceptos): int` — devuelve el nuevo `Id`.
- Produces: `guardarArchivos(string $proveedorCarpeta, string $uuid, string $tmpPdfPath, string $tmpXmlPath): array` — devuelve `['rutaPdf' => string, 'nombrePdf' => string, 'rutaXml' => string, 'nombreXml' => string]` (rutas absolutas ya movidas a su ubicación final).
- Produces: `actualizarArchivos(int $invoiceId, array $rutas): void`.
- Produces: `vincular(int $scheduleId, int $invoiceId, int $userId): void`.
- Produces: `desvincular(int $scheduleId): void`.
- Produces: `obtenerFacturaDeRecepcion(int $scheduleId): ?array` — JOIN de `fuel_reception_invoices` con `FacturasRecibidas`, o `null` si no hay vínculo.

- [ ] **Step 1: Agregar los métodos de inserción, archivos y vínculo**

Agregar al final de la clase `FuelReceptionInvoiceModel` (antes del `}` de cierre), en `_assets/models/FuelReceptionInvoiceModel.php`:

```php
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
                     NoCertificadoSAT, TotalImpuestosTrasladados, TotalImpuestosRetenidos)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
     * mayúsculas sin guiones, igual convención que usa el flujo automático
     * de correos) y solo cambiando la extensión.
     */
    public function guardarArchivos(string $proveedorCarpeta, string $uuid, string $tmpPdfPath, string $tmpXmlPath): array {
        $dir = AttachmentsPath::procesadasDir($proveedorCarpeta);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $nombreBase = strtoupper(str_replace('-', '', $uuid));
        $nombrePdf = $nombreBase . '.pdf';
        $nombreXml = $nombreBase . '.xml';
        $rutaPdf = $dir . '\\' . $nombrePdf;
        $rutaXml = $dir . '\\' . $nombreXml;

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
        $query = "DELETE FROM TG.dbo.fuel_reception_invoices WHERE schedule_id = ?";
        $this->sql->update($query, [$scheduleId]);
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
```

- [ ] **Step 2: Verificar sintaxis**

Run: `php -l _assets/models/FuelReceptionInvoiceModel.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Probar `insertarFactura` + `vincular` + `obtenerFacturaDeRecepcion` de punta a punta contra BD real**

Usar el resultado del parseo del Step 3 de la Task 2 (o volver a parsear el mismo XML de prueba), y una recepción real existente en `fuel_reception_schedule` (usar cualquier `id` que ya exista, consultar con `SELECT TOP 1 id FROM TG.dbo.fuel_reception_schedule` si hace falta uno).

Run:
```bash
cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp"
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
require_once MODELS . 'FuelReceptionInvoiceModel.php';
\$m = new FuelReceptionInvoiceModel();
\$parseado = \$m->parseCfdiXml('C:\\\\ruta\\\\a\\\\prueba.xml');
// Usar un UUID de prueba distinto al real si ya existe en BD, para no chocar con datos reales:
\$parseado['factura']['UUID'] = 'TEST-' . uniqid();
\$invoiceId = \$m->insertarFactura(\$parseado['factura'], \$parseado['conceptos']);
echo 'invoice_id: ' . \$invoiceId . PHP_EOL;
\$scheduleIdPrueba = 1; // reemplazar por un id real de fuel_reception_schedule
\$m->vincular(\$scheduleIdPrueba, \$invoiceId, 0);
var_dump(\$m->obtenerFacturaDeRecepcion(\$scheduleIdPrueba));
\$m->desvincular(\$scheduleIdPrueba);
var_dump(\$m->obtenerFacturaDeRecepcion(\$scheduleIdPrueba));
"
```
Expected: `insertarFactura` devuelve un `int` > 0; `obtenerFacturaDeRecepcion` antes de `desvincular` devuelve el array con los datos de la factura; después de `desvincular` devuelve `null`.

Limpiar el registro de prueba tras verificar (no dejar filas `TEST-*` en `FacturasRecibidas`):
```bash
php -r "
\$_SERVER['DOCUMENT_ROOT'] = getcwd();
\$_SERVER['REQUEST_URI'] = '/';
require '_assets/classes/header.class.php';
require_once MODELS . 'Model.php';
\$db = MySqlPdoHandler::getInstance();
\$db->update('DELETE FROM TG.dbo.FacturasRecibidasConceptos WHERE FacturaId IN (SELECT Id FROM TG.dbo.FacturasRecibidas WHERE UUID LIKE ?)', ['TEST-%']);
\$db->update('DELETE FROM TG.dbo.FacturasRecibidas WHERE UUID LIKE ?', ['TEST-%']);
echo 'limpio' . PHP_EOL;
"
```

- [ ] **Step 4: Commit**

```bash
git add _assets/models/FuelReceptionInvoiceModel.php
git commit -m "Agregar insert transaccional, guardado de archivos y vínculo en FuelReceptionInvoiceModel"
```

---

## Task 4: Endpoints en `Supply` — modal, subir, desvincular, servir archivo

**Files:**
- Modify: `_assets/controllers/supply.php` (agregar propiedad `$fuelReceptionInvoiceModel`, instanciarla en el constructor, agregar 4 métodos nuevos)
- Modify: `_assets/controllers/supply.php::scheduling_day_data()` (extender el SELECT para incluir si la recepción tiene factura vinculada)
- Create: `views/supply/modals/frmFacturaRecepcion.html` (formulario de subida)
- Create: `views/supply/modals/verFacturaRecepcion.html` (vista de solo lectura)

**Interfaces:**
- Consumes: `FuelReceptionInvoiceModel` completo (Tasks 2 y 3).
- Consumes: `FuelReceptionScheduleModel::get_one(int $id): ?array` (ya existe, para saber el `supplier_id` de la recepción al subir la factura).
- Produces (para Task 5): `POST /supply/scheduling_invoice_modal` (param `schedule_id`) → JSON `{success, html}`.
- Produces (para Task 5): `POST /supply/scheduling_upload_invoice` (params `schedule_id`, `pdf`, `xml` como `$_FILES`) → JSON `{success, invoice_id?, ya_existia?, advertencia_rfc?, message?}`.
- Produces (para Task 5): `POST /supply/scheduling_invoice_unlink` (param `schedule_id`) → JSON `{success}`.
- Produces (para Task 5): `GET /supply/scheduling_invoice_file` (params `invoice_id`, `tipo=pdf|xml`) → sirve el archivo binario.
- Produces (para Task 5): `scheduling_day_data()` ahora incluye `invoice_id` (nullable) en cada fila del JSON.

- [ ] **Step 1: Instanciar el modelo nuevo en el constructor de `Supply`**

En `_assets/controllers/supply.php`, junto a las demás propiedades de modelos (buscar `public FuelReceptionScheduleModel $fuelReceptionScheduleModel;`), agregar:

```php
    public FuelReceptionInvoiceModel $fuelReceptionInvoiceModel;
```

Y en el constructor, junto a `$this->fuelReceptionScheduleModel = new FuelReceptionScheduleModel();`, agregar:

```php
        $this->fuelReceptionInvoiceModel = new FuelReceptionInvoiceModel();
```

- [ ] **Step 2: Extender `scheduling_day_data()` para incluir el estado de factura**

Localizar `scheduling_day_data()` en `supply.php`. Su cuerpo actual llama a `$this->fuelReceptionScheduleModel->get_day($fecha)` y responde `json_output(['data' => $filas])`. Modificar para enriquecer cada fila con `invoice_id`:

```php
    public function scheduling_day_data()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['data' => [], 'error' => 'No autorizado']);
            return;
        }
        $fecha = $_REQUEST['fecha'] ?? date('Y-m-d', strtotime('+1 day'));
        $filas = $this->fuelReceptionScheduleModel->get_day($fecha);

        foreach ($filas as &$fila) {
            $factura = $this->fuelReceptionInvoiceModel->obtenerFacturaDeRecepcion((int)$fila['id']);
            $fila['invoice_id'] = $factura['Id'] ?? null;
        }
        unset($fila);

        json_output(['data' => $filas]);
    }
```

(Reemplaza el cuerpo completo del método existente — mismo nombre, misma firma, mismo permiso, misma fuente de `$fecha`.)

- [ ] **Step 3: Agregar el endpoint del modal**

Agregar en `supply.php`, cerca de `scheduling_modal()`:

```php
    public function scheduling_invoice_modal()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }

        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        if ($scheduleId <= 0) {
            json_output(['success' => false, 'message' => 'Falta el id de la recepción']);
            return;
        }

        $factura = $this->fuelReceptionInvoiceModel->obtenerFacturaDeRecepcion($scheduleId);

        if ($factura) {
            $html = $this->twig->render($this->route . 'modals/verFacturaRecepcion.html', [
                'scheduleId' => $scheduleId,
                'factura' => $factura,
            ]);
        } else {
            $html = $this->twig->render($this->route . 'modals/frmFacturaRecepcion.html', [
                'scheduleId' => $scheduleId,
            ]);
        }

        json_output(['success' => true, 'html' => $html]);
    }
```

- [ ] **Step 4: Agregar el endpoint de subida**

Agregar en `supply.php`:

```php
    public function scheduling_upload_invoice()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }

        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        if ($scheduleId <= 0) {
            json_output(['success' => false, 'message' => 'Falta el id de la recepción']);
            return;
        }

        $recepcion = $this->fuelReceptionScheduleModel->get_one($scheduleId);
        if (!$recepcion) {
            json_output(['success' => false, 'message' => 'La recepción no existe']);
            return;
        }

        if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            json_output(['success' => false, 'message' => 'Falta el archivo PDF']);
            return;
        }
        if (!isset($_FILES['xml']) || $_FILES['xml']['error'] !== UPLOAD_ERR_OK) {
            json_output(['success' => false, 'message' => 'Falta el archivo XML']);
            return;
        }

        $maxSize = 10 * 1024 * 1024;
        if ($_FILES['pdf']['size'] > $maxSize || $_FILES['xml']['size'] > $maxSize) {
            json_output(['success' => false, 'message' => 'Cada archivo debe pesar máximo 10MB']);
            return;
        }
        if (mime_content_type($_FILES['pdf']['tmp_name']) !== 'application/pdf') {
            json_output(['success' => false, 'message' => 'El primer archivo debe ser un PDF válido']);
            return;
        }
        if (pathinfo($_FILES['xml']['name'], PATHINFO_EXTENSION) !== 'xml') {
            json_output(['success' => false, 'message' => 'El segundo archivo debe ser un XML']);
            return;
        }

        try {
            $parseado = $this->fuelReceptionInvoiceModel->parseCfdiXml($_FILES['xml']['tmp_name']);
        } catch (Exception $e) {
            json_output(['success' => false, 'message' => $e->getMessage()]);
            return;
        }

        $advertenciaRfc = null;
        $proveedorPorRfc = $this->fuelReceptionInvoiceModel->resolverProveedorPorRfc($parseado['factura']['EmisorRfc'] ?? '');
        if (!$proveedorPorRfc || (int)$proveedorPorRfc['id'] !== (int)$recepcion['supplier_id']) {
            $advertenciaRfc = 'El RFC del emisor de la factura no coincide con el proveedor de esta recepción. Se guardó de todas formas.';
        }

        $userId = (int)($_SESSION['tg_user']['id'] ?? 0);
        $existente = $this->fuelReceptionInvoiceModel->buscarPorUuid($parseado['factura']['UUID']);

        if ($existente) {
            $invoiceId = (int)$existente['Id'];
            $yaExistia = true;
        } else {
            $carpeta = $this->fuelReceptionInvoiceModel->carpetaDeProveedor((int)$recepcion['supplier_id']);
            if (!$carpeta) {
                json_output(['success' => false, 'message' => 'No se reconoce el proveedor de esta recepción para archivar la factura']);
                return;
            }

            try {
                $invoiceId = $this->fuelReceptionInvoiceModel->insertarFactura($parseado['factura'], $parseado['conceptos']);
                $rutas = $this->fuelReceptionInvoiceModel->guardarArchivos(
                    $carpeta, $parseado['factura']['UUID'], $_FILES['pdf']['tmp_name'], $_FILES['xml']['tmp_name']
                );
                $this->fuelReceptionInvoiceModel->actualizarArchivos($invoiceId, $rutas);
            } catch (Exception $e) {
                json_output(['success' => false, 'message' => 'No se pudo guardar la factura: ' . $e->getMessage()]);
                return;
            }
            $yaExistia = false;
        }

        $this->fuelReceptionInvoiceModel->vincular($scheduleId, $invoiceId, $userId);

        json_output([
            'success' => true,
            'invoice_id' => $invoiceId,
            'ya_existia' => $yaExistia,
            'advertencia_rfc' => $advertenciaRfc,
        ]);
    }
```

- [ ] **Step 5: Agregar el endpoint de desvincular**

Agregar en `supply.php`:

```php
    public function scheduling_invoice_unlink()
    {
        header('Content-Type: application/json');
        if (!authorized(95)) {
            json_output(['success' => false, 'message' => 'No autorizado']);
            return;
        }
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);
        if ($scheduleId <= 0) {
            json_output(['success' => false, 'message' => 'Falta el id de la recepción']);
            return;
        }
        $this->fuelReceptionInvoiceModel->desvincular($scheduleId);
        json_output(['success' => true]);
    }
```

- [ ] **Step 6: Agregar el endpoint de servir archivo**

Agregar en `supply.php`:

```php
    public function scheduling_invoice_file()
    {
        if (!authorized(95)) {
            http_response_code(403);
            echo 'No autorizado';
            return;
        }

        $invoiceId = (int)($_GET['invoice_id'] ?? 0);
        $tipo = $_GET['tipo'] ?? '';
        if ($invoiceId <= 0 || !in_array($tipo, ['pdf', 'xml'], true)) {
            http_response_code(400);
            echo 'Parámetros inválidos';
            return;
        }

        $rows = $this->db->select(
            'SELECT RutaArchivo, RutaXml FROM TG.dbo.FacturasRecibidas WHERE Id = ?',
            [$invoiceId]
        );
        $factura = $rows[0] ?? null;
        $ruta = $tipo === 'pdf' ? ($factura['RutaArchivo'] ?? null) : ($factura['RutaXml'] ?? null);

        if (!$factura || !$ruta || !is_file($ruta)) {
            http_response_code(404);
            echo 'Archivo no encontrado';
            return;
        }

        $mime = $tipo === 'pdf' ? 'application/pdf' : 'text/xml';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($ruta) . '"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
    }
```

Nota: este método usa `$this->db` directamente en vez de un modelo — verificar en el constructor de `Supply` si ya existe una propiedad `$this->db` (patrón usado en otros controladores del repo vía `MySqlPdoHandler::getInstance()`); si no existe, agregar al inicio del método:
```php
$db = MySqlPdoHandler::getInstance();
$rows = $db->select(...);
```
en vez de `$this->db->select(...)`.

- [ ] **Step 7: Verificar sintaxis**

Run: `php -l _assets/controllers/supply.php`
Expected: `No syntax errors detected`

- [ ] **Step 8: Crear la vista del formulario de subida**

Archivo `views/supply/modals/frmFacturaRecepcion.html`:

```html
<input type="hidden" id="factura_schedule_id" value="{{ scheduleId }}">
<div class="modal-body">
    <p class="text-muted">Sube el PDF y el XML del CFDI de esta recepción. Se validará el XML y, si es válido, la factura quedará registrada en el sistema de pagos.</p>
    <div class="mb-3">
        <label class="col-form-label col-form-label-sm" for="factura_pdf">Archivo PDF:</label>
        <input type="file" class="form-control" id="factura_pdf" accept="application/pdf" required>
    </div>
    <div class="mb-3">
        <label class="col-form-label col-form-label-sm" for="factura_xml">Archivo XML:</label>
        <input type="file" class="form-control" id="factura_xml" accept=".xml,text/xml" required>
    </div>
    <div id="facturaMensajeError" class="alert alert-danger" style="display:none;"></div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-primary" id="btnSubirFactura">Subir y vincular</button>
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
</div>
```

- [ ] **Step 9: Crear la vista de solo lectura**

Archivo `views/supply/modals/verFacturaRecepcion.html`:

```html
<input type="hidden" id="factura_schedule_id" value="{{ scheduleId }}">
<div class="modal-body">
    <div class="row mb-2">
        <div class="col-6"><strong>Folio:</strong> {{ factura.Folio|default('—') }}</div>
        <div class="col-6"><strong>Fecha:</strong> {{ factura.Fecha|date('Y-m-d') }}</div>
    </div>
    <div class="row mb-2">
        <div class="col-12"><strong>Emisor:</strong> {{ factura.EmisorNombre }} ({{ factura.EmisorRfc }})</div>
    </div>
    <div class="row mb-2">
        <div class="col-6"><strong>Total:</strong> ${{ factura.Total|number_format(2) }}</div>
        <div class="col-6"><strong>UUID:</strong> <small>{{ factura.UUID }}</small></div>
    </div>
    <div class="d-flex gap-2 mt-3">
        <a href="/supply/scheduling_invoice_file?invoice_id={{ factura.Id }}&tipo=pdf" target="_blank" class="btn btn-outline-secondary btn-sm">Ver PDF</a>
        <a href="/supply/scheduling_invoice_file?invoice_id={{ factura.Id }}&tipo=xml" target="_blank" class="btn btn-outline-secondary btn-sm">Ver XML</a>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-outline-danger" id="btnReemplazarFactura">Reemplazar factura</button>
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
</div>
```

- [ ] **Step 10: Verificar que index.php ya rutea genéricamente a estos métodos**

Run: `grep -n "case 'supply'" -A 5 index.php`
Expected: la rama de routing de `supply` ya despacha por nombre de método sin lista explícita (patrón `/[controller]/[method]`, documentado en `CLAUDE.md`); si en cambio hay una whitelist explícita de métodos permitidos para `Supply`, agregar `scheduling_invoice_modal`, `scheduling_upload_invoice`, `scheduling_invoice_unlink`, `scheduling_invoice_file` a esa lista.

- [ ] **Step 11: Probar el endpoint de modal vía CLI (simulando sesión)**

Esto requiere sesión de navegador real para `authorized()` — verificar manualmente en navegador en la Task 5 en vez de vía CLI (los endpoints de `Supply` dependen de `$_SESSION['tg_user']`, no simulable de forma simple por script).

- [ ] **Step 12: Commit**

```bash
git add _assets/controllers/supply.php views/supply/modals/frmFacturaRecepcion.html views/supply/modals/verFacturaRecepcion.html
git commit -m "Agregar endpoints de subida/consulta de factura de recepción en Supply"
```

---

## Task 5: Frontend — ícono, modal y flujo de subida en `scheduling.js`

**Files:**
- Modify: `_assets/js/scheduling.js` (`botonesAccion()`, nuevo `abrirModalFactura()`, nuevos handlers)
- Modify: `views/supply/scheduling.html` (nuevo `<div id="modalFactura">`)

**Interfaces:**
- Consumes: `POST /supply/scheduling_invoice_modal`, `POST /supply/scheduling_upload_invoice`, `POST /supply/scheduling_invoice_unlink` (Task 4).
- Consumes: `fila.invoice_id` en cada fila de `scheduling_day_data()` (Task 4, Step 2).

- [ ] **Step 1: Agregar el modal nuevo a la vista**

En `views/supply/scheduling.html`, junto al `<div class="modal fade" id="modalProgramacion" ...>` existente, agregar un modal hermano:

```html
<div class="modal fade" id="modalFactura" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Factura de la recepción</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div id="modalFacturaContent"></div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Extender `botonesAccion()` con el tercer ícono**

En `_assets/js/scheduling.js`, localizar `botonesAccion(id)` (línea ~124-131). Su firma actual solo recibe `id`; necesita también `invoiceId` para decidir el color del ícono. Reemplazar:

```javascript
function botonesAccion(id, invoiceId) {
    const colorFactura = invoiceId ? 'btn-outline-success' : 'btn-outline-secondary';
    return `
        <div class="d-flex gap-1 justify-content-center">
            <button type="button" class="btn btn-outline-success btn-editar-recepcion btn-accion-icono" data-id="${id}" title="Editar"><i data-feather="edit-3"></i></button>
            <button type="button" class="btn ${colorFactura} btn-factura-recepcion btn-accion-icono" data-id="${id}" title="${invoiceId ? 'Ver factura' : 'Subir factura'}"><i data-feather="paperclip"></i></button>
            <button type="button" class="btn btn-outline-danger btn-cancelar-recepcion btn-accion-icono" data-id="${id}" title="Cancelar"><i data-feather="trash-2"></i></button>
        </div>
    `;
}
```

- [ ] **Step 3: Actualizar los dos llamadores de `botonesAccion()` para pasar `invoiceId`**

En `formatearFilaTerminal(fila, mostrarTransportista, mostrarReferenciaNotas)` y en `formatearFilaEstacion(fila)`, localizar la línea `${botonesAccion(fila.id)}` (aparece una vez en cada función) y cambiarla a:

```javascript
${botonesAccion(fila.id, fila.invoice_id)}
```

- [ ] **Step 4: Agregar `abrirModalFactura()` y sus handlers**

En `_assets/js/scheduling.js`, junto a la función `abrirModal()` existente (línea ~430), agregar:

```javascript
function abrirModalFactura(scheduleId) {
    $.post('/supply/scheduling_invoice_modal', { schedule_id: scheduleId })
        .done(function (resp) {
            if (!resp.success) {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo abrir el formulario de factura.</p></div>');
                return;
            }
            $('#modalFacturaContent').html(resp.html);
            const modal = new bootstrap.Modal(document.getElementById('modalFactura'));
            modal.show();
        })
        .fail(function () {
            alertify.myAlert('<div class="text-danger text-center"><p>No se pudo abrir el formulario de factura.</p></div>');
        });
}
```

Dentro del bloque `$(document).ready(function () { ... });`, junto a los handlers `.btn-editar-recepcion`/`.btn-cancelar-recepcion` existentes, agregar:

```javascript
    $(document).on('click', '.btn-factura-recepcion', function () {
        abrirModalFactura($(this).data('id'));
    });

    $(document).on('click', '#btnSubirFactura', function () {
        const boton = $(this);
        const scheduleId = $('#factura_schedule_id').val();
        const pdfFile = $('#factura_pdf')[0].files[0];
        const xmlFile = $('#factura_xml')[0].files[0];
        const errorBox = $('#facturaMensajeError');

        errorBox.hide().text('');

        if (!pdfFile || !xmlFile) {
            errorBox.text('Selecciona ambos archivos (PDF y XML).').show();
            return;
        }

        const datos = new FormData();
        datos.append('schedule_id', scheduleId);
        datos.append('pdf', pdfFile);
        datos.append('xml', xmlFile);

        boton.prop('disabled', true);

        $.ajax({
            url: '/supply/scheduling_upload_invoice',
            method: 'POST',
            data: datos,
            processData: false,
            contentType: false,
        })
            .done(function (resp) {
                if (!resp.success) {
                    errorBox.text(resp.message || 'No se pudo guardar la factura.').show();
                    return;
                }
                bootstrap.Modal.getInstance(document.getElementById('modalFactura')).hide();
                if (resp.advertencia_rfc) {
                    alertify.myAlert('<div class="text-warning text-center"><p>' + esc(resp.advertencia_rfc) + '</p></div>');
                }
                cargarDia($('#fecha_programacion').val());
            })
            .fail(function () {
                errorBox.text('No se pudo guardar la factura.').show();
            })
            .always(function () {
                boton.prop('disabled', false);
            });
    });

    $(document).on('click', '#btnReemplazarFactura', function () {
        const scheduleId = $('#factura_schedule_id').val();
        if (!confirm('¿Quitar la factura vinculada a esta recepción? La factura seguirá existiendo en el sistema, solo se quita el vínculo.')) return;
        $.post('/supply/scheduling_invoice_unlink', { schedule_id: scheduleId })
            .done(function (resp) {
                if (!resp.success) {
                    alertify.myAlert('<div class="text-danger text-center"><p>No se pudo quitar el vínculo.</p></div>');
                    return;
                }
                abrirModalFactura(scheduleId);
                cargarDia($('#fecha_programacion').val());
            })
            .fail(function () {
                alertify.myAlert('<div class="text-danger text-center"><p>No se pudo quitar el vínculo.</p></div>');
            });
    });
```

- [ ] **Step 5: Verificar sintaxis**

Run: `node -c _assets/js/scheduling.js`
Expected: sin salida (sin errores).

- [ ] **Step 6: Verificación manual en navegador — subida exitosa**

1. Abrir `http://localhost:8000/supply/scheduling` (o el puerto que uses con `php -S`).
2. Confirmar que cada fila de la tabla ahora muestra 3 iconos (Editar, Factura en gris, Cancelar).
3. Clic en el ícono de factura de una recepción sin factura vinculada → debe abrir el modal con el formulario de subida.
4. Subir un PDF+XML real de una factura de combustible (usar el mismo par de archivos usado en las pruebas de la Task 2/3, o uno nuevo).
5. Confirmar que el modal se cierra, la tabla se refresca, y el ícono de esa fila ahora aparece en verde.
6. Clic de nuevo en el mismo ícono (ahora verde) → debe abrir la vista de solo lectura con Folio/Emisor/Total/UUID correctos y los links "Ver PDF"/"Ver XML" funcionando (abren el archivo en pestaña nueva).

Expected: todo el flujo funciona sin errores en la consola del navegador.

- [ ] **Step 7: Verificación manual en navegador — UUID duplicado**

1. Repetir la subida del Step 6 con el MISMO par de archivos en OTRA recepción (fila distinta) de la misma tarea.
2. Confirmar que la respuesta es exitosa, que NO se crea una segunda fila en `FacturasRecibidas` (verificar con `SELECT COUNT(*) FROM TG.dbo.FacturasRecibidas WHERE UUID = '<uuid de la prueba>'` — debe ser 1), y que ambas recepciones ahora muestran el ícono verde apuntando a la misma factura.

- [ ] **Step 8: Verificación manual en navegador — reemplazar factura**

1. Abrir el modal de solo lectura de una recepción con factura vinculada.
2. Clic en "Reemplazar factura", confirmar el diálogo.
3. Confirmar que el modal se reabre mostrando el formulario de subida (no la vista de solo lectura), y que la fila en la tabla vuelve a mostrar el ícono en gris tras refrescar.
4. Confirmar en BD que la fila de `fuel_reception_invoices` para ese `schedule_id` ya no existe, pero que la fila en `FacturasRecibidas` sigue intacta (no se borró).

- [ ] **Step 9: Commit**

```bash
git add _assets/js/scheduling.js views/supply/scheduling.html
git commit -m "Agregar ícono, modal y flujo de subida de factura en la vista de scheduling"
```

---

## Self-Review (completado durante la escritura de este plan)

**Spec coverage:**
- Duplicados por UUID → Task 4 Step 4 (`buscarPorUuid` antes de insertar).
- RFC no bloqueante → Task 4 Step 4 (advertencia, no rechazo).
- Modal aparte solo para recepción ya guardada → Task 5 (ícono en la fila, no en el modal de captura).
- Estado visual por color → Task 5 Step 2.
- Conceptos completos → Task 3 Step 1 (`insertarFactura` inserta todos los conceptos).
- N:1 (tabla de vínculo) → Task 1.
- XML inválido rechaza todo → Task 2 Step 1 (`parseCfdiXml` lanza excepción antes de cualquier escritura) + Task 4 Step 4 (el catch responde error sin continuar).

**Correcciones aplicadas respecto al spec original** (halladas durante la investigación previa a este plan, ya incorporadas arriba):
- `resolverProveedorPorRfc` usa `SG12.dbo.Proveedores.rfc` directamente (join con `TG.dbo.Proveedores.id_control_gas`), no la cadena de `PetrotalReconciliationModel` (esa es RFCs hardcodeados, caso especial no reutilizable).
- Mapeo completo de columnas verificado contra el esquema real de BD (incluye `FacturasRecibidasConceptos.Unidad`, que el spec original no distinguía bien de `ClaveUnidad`).
- No existe ningún XML de CFDI real en el repo — el plan señala explícitamente en la Task 2 que la verificación requiere un archivo real aportado por el usuario durante la ejecución, no asume su existencia.

**Placeholder scan:** sin TBD/TODO; cada paso de código trae el código completo, no una descripción.

**Type consistency:** `scheduleId`/`invoiceId` como `int` en PHP y como valores de `data-id`/inputs en JS consistentemente; `botonesAccion(id, invoiceId)` con la misma firma en su definición y en ambos llamadores.
