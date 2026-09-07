# Factura (PDF+XML) para recepciones de combustible — Diseño

**Fecha:** 2026-09-07
**Módulo afectado:** `/supply/scheduling` (Programación de Recepciones de combustible)
**Autor de la investigación previa:** ver hallazgos en la conversación que originó este spec

## Contexto y motivación

Los usuarios de Abastos capturan recepciones de combustible programadas en
`fuel_reception_schedule` (tabla en `TG`). Hoy no hay forma de vincular la
factura fiscal (CFDI, PDF+XML) de una recepción con el registro de
programación, ni de que esa factura entre al sistema de pagos a
proveedores si aún no llegó por el flujo automático de correos
(`CorreoFactruras.py`) ni fue importada manualmente en
`/payment/import_invoices`.

El usuario quiere que, desde `/supply/scheduling`, se pueda subir el PDF y
el XML de la factura de una recepción, y que esa factura cuente como
factura real del sistema de pagos — es decir, termine como una fila válida
en `TG.dbo.FacturasRecibidas`, indexada igual que cualquier otra factura
del sistema (visible en reportes de compras, conciliación, etc.), no como
un archivo aislado sin relación con el resto de la aplicación.

### Hallazgos clave de la investigación previa

- **`/payment/import_invoices` no es un molde reutilizable.** Solo maneja
  PDF (no XML) y delega TODO el parseo a una API externa
  (`http://192.168.0.109:82/api/importar_factura_pdf/`) que no está en
  este repo. El PHP nunca lee el contenido del PDF — solo lo reenvía y
  usa la respuesta JSON de la API.
- **No existe ningún parseo de CFDI XML en este repo.** Ningún
  controlador usa `SimpleXMLElement`/`DOMDocument` para leer un CFDI. Se
  escribe desde cero.
- **No existe ningún `INSERT` a `FacturasRecibidas` en el código PHP.**
  Todas las filas de esa tabla las crea hoy el proceso externo
  (correo/API). Este módulo será el primer código PHP que inserte ahí
  directamente.
- **Cadena RFC → proveedor, ya usada en producción**
  (`PetrotalReconciliationModel`):
  `SG12.dbo.Proveedores.rfc` → `.cod` = `TG.dbo.Proveedores.id_control_gas`
  → `TG.dbo.Proveedores.id`.
- **Convención de archivos, confirmada por `docs/sql/facturas_recibidas_xml.sql`**:
  misma carpeta, mismo nombre base, solo cambia la extensión:
  ```
  attachments\<proveedor>\procesadas\<UUID>.pdf  → FacturasRecibidas.RutaArchivo / .NombreArchivo
  attachments\<proveedor>\procesadas\<UUID>.xml  → FacturasRecibidas.RutaXml    / .NombreXml
  ```
  Las columnas `RutaXml`/`NombreXml` ya existen en BD (migradas
  previamente) y ya se leen en producción (`PetrotalReconciliationModel`),
  pero ningún código PHP las escribe hoy.
- **`BASE_ATTACHMENTS_PATH`** (`C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas\attachments`)
  está hardcodeada como constante **privada** dentro de
  `_assets/controllers/payment.php` — no reutilizable directamente desde
  otro controlador sin duplicarla o moverla a un lugar compartido.
- **`fuel_reception_schedule` no tiene ninguna columna que la ligue a una
  factura.** Se confirmó que una factura puede cubrir varias recepciones
  a la vez (una entrega grande facturada una sola vez, repartida en el
  Excel en varias filas de estación), así que la relación es N:1 — se
  necesita una tabla intermedia, no una columna simple.

## Decisiones de diseño (ya validadas con el usuario)

1. **Duplicados por UUID:** si el UUID del XML subido ya existe en
   `FacturasRecibidas`, no se inserta nada nuevo — se usa el `Id`
   existente para el vínculo, y se informa al usuario que la factura ya
   estaba en el sistema y solo se vinculó.
2. **RFC no coincide con el proveedor de la recepción:** solo se
   advierte (mensaje no bloqueante), no se rechaza la subida. Puede haber
   casos legítimos de facturación a través de un tercero.
3. **Momento de subida:** solo disponible para una recepción ya
   guardada (con `id`), nunca durante la captura de una recepción nueva.
4. **Punto de entrada:** un modal aparte (no el modal de captura/edición
   existente), abierto desde un ícono nuevo en cada fila de la tabla,
   junto a Editar/Cancelar.
5. **Estado visual:** el ícono cambia de color según si la recepción ya
   tiene factura vinculada o no, y el clic abre un modal distinto según
   el estado (formulario de subida vs. vista de solo lectura).
6. **Conceptos del CFDI:** se guardan todos (inserción completa en
   `FacturasRecibidasConceptos`), igual que hace el flujo automático de
   correos — mantiene consistencia con reportes que ya hacen JOIN a esa
   tabla (ej. `compras_facturas_table`).
7. **Cardinalidad recepción↔factura:** una factura puede cubrir varias
   recepciones (N:1) — requiere tabla intermedia.
8. **XML inválido:** si el parseo falla o no se encuentra el UUID del
   timbre fiscal, se rechaza el flujo completo (nada se guarda, ni PDF ni
   fila en BD), con mensaje claro de qué falló.

## Arquitectura

### Tabla nueva: `TG.dbo.fuel_reception_invoices`

Tabla de vínculo N:1 entre recepciones y facturas — evita modificar el
esquema de `fuel_reception_schedule` o de `FacturasRecibidas`, y permite
que una factura cubra varias recepciones sin duplicar sus datos.

Migración idempotente (mismo patrón que `docs/sql/facturas_recibidas_xml.sql`):

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

`UNIQUE (schedule_id)` porque cada recepción tiene como máximo UNA
factura vinculada (mínimo viable acordado); una misma `invoice_id` sí
puede repetirse en varias filas (varias recepciones, una factura).
"Reemplazar factura" borra físicamente la fila existente de esta tabla
para ese `schedule_id` (nunca borra la fila de `FacturasRecibidas`, que
puede seguir referenciada por otros procesos) y el flujo de subida vuelve
a insertar una nueva.

### Modelo nuevo: `FuelReceptionInvoiceModel.php`

Responsabilidad separada de `FuelReceptionScheduleModel` porque el
parseo fiscal (CFDI) es un dominio distinto al de programación de
recepciones — mantiene cada modelo enfocado en una sola cosa.

Métodos:
- `parseCfdiXml(string $xmlPath): array` — parsea el XML con
  `SimpleXMLElement`, registra namespaces `cfdi:` y `tfd:`, devuelve un
  array asociativo con todos los campos de `FacturasRecibidas` (ver tabla
  de mapeo abajo) + un array de conceptos. Lanza excepción con mensaje
  claro si falta el nodo `tfd:TimbreFiscalDigital` o el UUID.
- `resolverProveedorPorRfc(string $rfc): ?array` — ejecuta la cadena
  RFC → cod → id_control_gas → id, devuelve el proveedor de
  `TG.dbo.Proveedores` o `null` si no hay match.
- `buscarPorUuid(string $uuid): ?array` — `SELECT Id, ... FROM
  FacturasRecibidas WHERE UUID = ?`.
- `insertarFactura(array $datosFactura, array $conceptos): int` — INSERT
  a `FacturasRecibidas` + INSERT de cada concepto a
  `FacturasRecibidasConceptos`, dentro de una transacción
  (`beginTransaction`/`commit`/`rollBack`). Devuelve el nuevo `Id`.
  **Auditoría:** aunque no exista una columna dedicada en el esquema
  actual, se debe dejar constancia de que esta fila fue creada desde el
  módulo de recepciones (no desde el flujo de correos) — usar la columna
  libre `PresentacionTesoro` NO es apropiado (tiene otro propósito ya en
  uso); en su lugar, este dato queda implícito por la existencia de la
  fila en `fuel_reception_invoices` con `created_by` propio, suficiente
  para trazabilidad sin tocar el esquema de `FacturasRecibidas`.
- `vincular(int $scheduleId, int $invoiceId, int $userId): void` —
  INSERT a `fuel_reception_invoices`.
- `desvincular(int $scheduleId): void` — DELETE de `fuel_reception_invoices`
  para ese `scheduleId` (usado por "Reemplazar factura").
- `obtenerFacturaDeRecepcion(int $scheduleId): ?array` — JOIN de
  `fuel_reception_invoices` con `FacturasRecibidas` para traer los datos
  a mostrar en el modal de solo lectura.
- `guardarArchivos(string $proveedorCarpeta, string $uuid, string
  $tmpPdfPath, string $tmpXmlPath): array` — mueve ambos archivos a
  `attachments\<proveedorCarpeta>\procesadas\<UUID>.{pdf,xml}` (usando la
  constante compartida, ver más abajo), devuelve las rutas relativas para
  guardar en `RutaArchivo`/`RutaXml`.

### Mapeo XML CFDI → columnas de `FacturasRecibidas`

| Nodo/atributo CFDI | Columna |
|---|---|
| `cfdi:Comprobante/@Folio` | `Folio` |
| `cfdi:Comprobante/@Serie` | `Serie` |
| `cfdi:Comprobante/@Fecha` | `Fecha` |
| `cfdi:Comprobante/@FormaPago` | `FormaPago` |
| `cfdi:Comprobante/@MetodoPago` | `MetodoPago` |
| `cfdi:Comprobante/@TipoCambio` | `TipoCambio` |
| `cfdi:Comprobante/@Moneda` | `Moneda` |
| `cfdi:Comprobante/@SubTotal` | `SubTotal` |
| `cfdi:Comprobante/@Total` | `Total` |
| `cfdi:Comprobante/@Exportacion` | `Exportacion` |
| `cfdi:Comprobante/@TipoDeComprobante` | `TipoDeComprobante` |
| `cfdi:Comprobante/@LugarExpedicion` | `LugarExpedicion` |
| `cfdi:Comprobante/@Certificado` | `Certificado` |
| `cfdi:Comprobante/@NoCertificado` | `NoCertificado` |
| `cfdi:Comprobante/@Sello` | `Sello` |
| `cfdi:Emisor/@Nombre` | `EmisorNombre` |
| `cfdi:Emisor/@Rfc` | `EmisorRfc` |
| `cfdi:Emisor/@RegimenFiscal` | `EmisorRegimenFiscal` |
| `cfdi:Receptor/@Nombre` | `ReceptorNombre` |
| `cfdi:Receptor/@Rfc` | `ReceptorRfc` |
| `cfdi:Receptor/@RegimenFiscalReceptor` (CFDI 4.0; ausente en 3.3 → queda `NULL`) | `ReceptorRegimenFiscal` |
| `cfdi:Receptor/@DomicilioFiscalReceptor` (CFDI 4.0; ausente en 3.3 → queda `NULL`) | `DomicilioFiscalReceptor` |
| `cfdi:Receptor/@UsoCFDI` | `UsoCFDI` |
| `tfd:TimbreFiscalDigital/@FechaTimbrado` | `FechaTimbrado` |
| `tfd:TimbreFiscalDigital/@RfcProvCertif` | `RfcProvCertif` |
| `tfd:TimbreFiscalDigital/@UUID` | `UUID` |
| `tfd:TimbreFiscalDigital/@NoCertificadoSAT` | `NoCertificadoSAT` |
| `cfdi:Impuestos/@TotalImpuestosTrasladados` | `TotalImpuestosTrasladados` |
| `cfdi:Impuestos/@TotalImpuestosRetenidos` | `TotalImpuestosRetenidos` |

Columnas `Destino`, `Remision`, `PresentacionTesoro` quedan `NULL` (son
específicas del flujo de correos/conciliación Petrotal, no aplican aquí).
`RutaArchivo`/`NombreArchivo`/`RutaXml`/`NombreXml` se llenan tras guardar
los archivos en disco (paso posterior al INSERT del encabezado).

Cada `cfdi:Concepto` mapea a una fila de `FacturasRecibidasConceptos`:
`Cantidad`, `ClaveProdServ`, `ClaveUnidad`, `Descripcion`,
`ValorUnitario`, `Importe`, `NoIdentificacion`; sus nodos hijos
`cfdi:Impuestos/cfdi:Traslados/cfdi:Traslado` mapean a `ObjetoImp`,
`Impuesto`, `TasaOCuota`, `TipoFactor`, `Base`, `ImporteImpuesto`
(`Unidad` se toma del atributo `Unidad` del concepto, distinto de
`ClaveUnidad`).

### Refactor: `BASE_ATTACHMENTS_PATH` compartida

Se mueve la constante de `payment.php` (privada) a una clase de utilidad
nueva y pequeña, `_assets/classes/common/AttachmentsPath.php`:

```php
class AttachmentsPath {
    const BASE = 'C:\Software\TareasProgramadas\Facturas_proveedores\correoFacturas\attachments';

    public static function procesadasDir(string $proveedor): string {
        return self::BASE . '\\' . $proveedor . '\\procesadas';
    }
}
```

`payment.php` se actualiza para usar `AttachmentsPath::BASE` en vez de su
propia constante (elimina la duplicación sin cambiar su comportamiento
actual). `FuelReceptionInvoiceModel` usa `AttachmentsPath::procesadasDir()`
para guardar PDF/XML.

**Nota sobre `$proveedor` (carpeta):** `payment.php` usa nombres de
carpeta en minúsculas fijos (aemsa, enerey, essafuel, lobo, mcg,
petrotal, premiergas, tesoro) — una whitelist de 8 nombres
(`ALLOWED_PROVIDERS`). El nuevo flujo debe resolver el nombre de carpeta
correcto a partir del `TG.dbo.Proveedores.id` de la recepción (no del RFC
del XML, que solo se usa para la advertencia de no-coincidencia) — se
necesita un mapeo `id de TG.dbo.Proveedores → nombre de carpeta`. Dado
que los 7 proveedores de combustible
(`FuelReceptionScheduleModel::IDS_PROVEEDORES_COMBUSTIBLE`) ya tienen IDs
fijos y conocidos, este mapeo se declara como constante en
`FuelReceptionInvoiceModel` (similar al patrón ya usado para
`GRUPOS_PROVEEDOR_TERMINAL` en el JS).

## Backend: endpoints nuevos en `Supply`

Todos protegidos por el permiso 95 (mismo que el resto de
`/supply/scheduling`).

### `POST /supply/scheduling_invoice_modal`
Devuelve el HTML del modal según el estado de esa recepción:
- Sin factura vinculada → renderiza el formulario de subida.
- Con factura vinculada → renderiza la vista de solo lectura (vía
  `FuelReceptionInvoiceModel::obtenerFacturaDeRecepcion()`).

### `POST /supply/scheduling_upload_invoice`
Parámetros: `schedule_id`, `pdf` (archivo), `xml` (archivo).

1. Valida que ambos archivos llegaron, con extensión/tipo correctos
   (`.pdf`, `.xml`) y tamaño ≤ 10MB (mismo límite que `mis_recepciones.html`).
2. Guarda el XML subido a una ruta temporal y lo parsea
   (`parseCfdiXml`). Si falla → responde error, no continúa.
3. Resuelve el proveedor por RFC emisor (`resolverProveedorPorRfc`). Si
   no hay match o no coincide con `fuel_reception_schedule.supplier_id`
   de esa recepción → se agrega una advertencia a la respuesta, pero se
   continúa.
4. Busca el UUID (`buscarPorUuid`):
   - Existe → usa ese `Id` tal cual. Los archivos PDF/XML recién subidos
     por el usuario se descartan sin guardarse en disco ni tocar
     `RutaArchivo`/`RutaXml` — la factura ya tiene su propio archivo
     archivado por el flujo que la creó originalmente; este flujo solo
     vincula, nunca sobreescribe un archivo ya existente.
   - No existe → determina la carpeta de proveedor (por
     `supplier_id` de la recepción, no por el proveedor resuelto del RFC),
     inserta la factura + conceptos (`insertarFactura`, en transacción),
     guarda PDF/XML en disco (`guardarArchivos`), actualiza
     `RutaArchivo`/`NombreArchivo`/`RutaXml`/`NombreXml`.
5. Vincula (`vincular`) — si la recepción ya tenía otra factura vinculada
   (caso "Reemplazar"), el frontend ya habrá llamado a
   `scheduling_invoice_unlink` antes de este paso.
6. Responde JSON: `success`, `invoice_id`, `ya_existia` (bool),
   `advertencia_rfc` (string|null).

### `POST /supply/scheduling_invoice_unlink`
Parámetro: `schedule_id`. Llama a `desvincular()`. Usado por el flujo de
"Reemplazar factura" antes de volver a mostrar el formulario de subida.

### `GET /supply/scheduling_invoice_file`
Parámetros: `invoice_id`, `tipo` (`pdf`|`xml`). Sirve el archivo
correspondiente desde `attachments\...` con los headers apropiados
(`Content-Type`, `Content-Disposition: inline`), para los links "Ver
PDF"/"Ver XML" del modal de solo lectura. Valida que el `invoice_id`
tenga `RutaArchivo`/`RutaXml` no nulo antes de intentar leer el archivo.

## Frontend

### Icono nuevo en la fila (vista Terminal y vista Estación)

Junto a los botones Editar/Cancelar existentes
(`botonesAccion()` en `scheduling.js`): un tercer botón con ícono de
factura/clip (`data-feather="paperclip"` o similar).
- Sin factura (`fila.invoice_id` es `null` en la respuesta de
  `scheduling_day_data`, que se extiende para incluir este campo vía
  LEFT JOIN a `fuel_reception_invoices`): ícono gris/outline.
- Con factura: ícono verde/lleno.

Clic → `abrirModalFactura(scheduleId)` → `POST
/supply/scheduling_invoice_modal` → inyecta el HTML en un nuevo
`#modalFactura` (mismo patrón de "vista parcial Twig vía fetch" ya usado
para el modal de captura).

### Modal de subida (sin factura vinculada)

Dos inputs de archivo (PDF, XML) + botón "Subir y vincular". Al enviar,
`FormData` con ambos archivos + `schedule_id` →
`scheduling_upload_invoice`. Maneja la respuesta:
- Éxito sin advertencia → cierra modal, refresca la fila (ícono a verde).
- Éxito con `advertencia_rfc` → muestra `alertify.myAlert` con el texto
  de advertencia, luego cierra y refresca igual.
- Error → mensaje de error en el propio modal, no lo cierra (permite
  reintentar con otro archivo).

### Modal de solo lectura (con factura vinculada)

Folio, Emisor (nombre + RFC), Total, Fecha, UUID — de solo lectura. Dos
links "Ver PDF" / "Ver XML" que abren `scheduling_invoice_file` en pestaña
nueva. Botón "Reemplazar factura" → confirmación (`confirm()` o
`alertify`) → `scheduling_invoice_unlink` → re-render del modal en su
estado de formulario de subida (sin recargar la página).

## Manejo de errores y validaciones

- **XML mal formado / sin timbre fiscal / no es CFDI**: rechazo total —
  "El XML no es un CFDI válido o no se pudo leer" — nada se guarda.
- **Extensión/tipo de archivo incorrecto**: rechazo con mensaje
  específico de qué campo falló.
- **Tamaño máximo**: 10MB por archivo (mismo límite ya usado en
  `mis_recepciones.html` para remisiones).
- **RFC no coincide con el proveedor de la recepción**: advertencia no
  bloqueante.
- **Reemplazar factura**: borra solo la fila de vínculo
  (`fuel_reception_invoices`), nunca la factura en `FacturasRecibidas`.
- **Permisos**: reutiliza el permiso 95.
- **Concurrencia**: el `UNIQUE (schedule_id)` en `fuel_reception_invoices`
  evita que dos vínculos convivan para la misma recepción a nivel BD,
  como red de seguridad además de la validación en la capa de aplicación.

## Testing

Sin framework de tests en el proyecto. Verificación:
- `php -l` en cada archivo PHP nuevo/modificado.
- Prueba real del parser CFDI contra al menos un XML real de un
  proveedor de combustible (`php -r` instanciando
  `FuelReceptionInvoiceModel` y llamando `parseCfdiXml()` directamente),
  confirmando que los campos extraídos coinciden con lo esperado.
- Prueba real contra BD (ambiente de desarrollo) del flujo completo:
  insertar una factura nueva, vincular una existente por UUID duplicado,
  desvincular/reemplazar.
- Verificación manual en navegador: ambos estados del modal, ambas vistas
  (Terminal y Estación) muestran el ícono correcto.

## Fuera de alcance (explícitamente)

- No se modifica el flujo de `/payment/import_invoices` ni la API
  externa.
- No se agrega edición manual de los campos parseados del CFDI antes de
  guardar (se confía en el parseo automático; si algo sale mal, el
  usuario corrige subiendo el par de archivos correcto).
- No se implementa notificación ni disparo del flujo de pagos a partir
  de esta factura — solo queda disponible en `FacturasRecibidas` igual
  que cualquier otra, para que el resto del sistema (reportes, conciliación,
  `payment_requests`) la recoja con su lógica normal.
