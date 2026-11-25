# Notas de crédito y cargo ligadas a FacturasRecibidas — diseño

Fecha: 2026-11-25 · Pantalla: `/payment/credit_notes` · Permiso de alta: 66

## Por qué

Las notas de cargo (falsos fletes de MGC) se capturan a mano: folio, monto y
descripción tecleados, más un PDF que se guarda en
`_assets/uploads/credit_debit_notes/` y nadie lee. Consecuencias vistas en BD
(2026-11-24):

- 9 requisiciones pagadas sin empresa (`emp_cod NULL`) porque el pago solo traía
  notas de cargo y la empresa sale del filtro `#company` (`add_payment.html:1391`).
  Corregidas con `docs/sql/fix_falsos_fletes_mgc_sin_empresa_2026-11-24.sql`.
- Las filas de pago de notas de cargo no tienen UUID ni estación: nada impide
  pagar dos veces el mismo CFDI.
- Descripciones inconsistentes ("FALSO FLETE", "FASLO FLETE", "falso fdl").

Y los CFDI ya llegan solos: los falsos fletes y las notas de crédito de MGC están
en `TG.dbo.FacturasRecibidas` con UUID, PDF, receptor y `EstacionCodgas`.

## Objetivo

Una nota de crédito o cargo **siempre sale de un CFDI en FacturasRecibidas**. Ya
no se teclea. El archivo vive donde viven las facturas (FacturasRecibidas /
NAS), no en una carpeta aparte.

## Metas

1. **Ligar**: en `/payment/credit_notes`, el botón "Buscar en facturas
   recibidas" abre un modal con los CFDI candidatos que todavía no son nota.
   Se pueden seleccionar varios y se crea una nota por cada CFDI, ligada a él.
2. **Importar**: si el CFDI no está en FacturasRecibidas, se sube **XML, PDF o
   ambos (ninguno es obligatorio, pero al menos uno)**. Se inserta en
   FacturasRecibidas con sus archivos y queda ligada.
3. La nota guarda el Id de FacturasRecibidas, el UUID, la empresa (por RFC
   receptor) y la estación (`EstacionCodgas`).
4. Al usar una nota de cargo en Nuevo Pago, la fila de pago lleva UUID y
   `codgas`. Si el pago solo trae notas, la empresa sale de las notas.
5. En el catálogo, cada nota de cargo muestra su **estado de pago**:
   Disponible / En pago #id / Autorizada / Pagada (fecha y referencia).
6. **Ligar las notas que ya existen** con su CFDI real.

## No metas

- No se cambia cómo se aplican las notas de crédito a facturas
  (`credit_note_applications`) ni el cálculo de saldo.
- No se borra `invoice_credit_debit_notes_doc` ni los PDF viejos. Quedan como
  histórico de solo lectura.
- Seleccionar fletes directo como factura en Nuevo Pago (la "opción A"
  anterior) queda fuera.

## Anti-metas

- Una nota sin CFDI detrás después de este cambio, salvo las históricas que no
  tengan pareja.
- Dos notas activas con el mismo UUID.
- Que una nota de cargo vuelva a crear un pago sin empresa.

## Restricciones

- `FacturasRecibidas.UUID` es `NOT NULL` con índice único
  (`UX_FacturasRecibidas_UUID`). El resto de las columnas acepta NULL, así que
  se puede insertar con solo PDF.
- Las collation difieren: usar `COLLATE DATABASE_DEFAULT` en joins por UUID.
- `payment_request_invoices` tiene un índice filtrado: los scripts necesitan
  `SET QUOTED_IDENTIFIER ON`.
- `NotaCreditoPdfParser` lee la "cadena original" del PDF con posiciones fijas
  y **solo está validado para MGC** (RFC `MME141110IJ9`).
- `AttachmentsPath` (legacy / NAS) está en plena migración (plan ApiER + NAS,
  en pausa). Las rutas se guardan con `AttachmentsPath::rutaParaBd()`.
- Los PDF viejos están solo en el servidor de producción
  (`_assets/uploads/credit_debit_notes/`), no en local.

## Enfoques considerados

| Enfoque | Qué implica | Decisión |
|---|---|---|
| **A. Ligar la nota a FacturasRecibidas con nuevas columnas** | Se agregan `factura_recibida_id`, `uuid`, `emp_cod`, `codgas` y `origen` a `invoice_credit_debit_notes`. Archivos, empresa y estación se leen del CFDI. | **Elegido.** Los saldos y las aplicaciones siguen igual. |
| B. Quitar la tabla de notas y usar solo FacturasRecibidas | La nota sería una vista sobre los CFDI tipo E / fletes. | Descartado: `credit_note_applications`, saldos y filas de pago dependen de `invoice_credit_debit_notes.id`. |
| C. Nueva tabla puente nota ↔ factura | Relación N:N. | Descartado: la relación es 1:1. |

## Diseño

### BD (`docs/sql/notas_ligadas_fr_2026-11-25.sql`)

`TG.dbo.invoice_credit_debit_notes`:

- `factura_recibida_id INT NULL` → `FacturasRecibidas.Id`
- `uuid VARCHAR(50) NULL`
- `emp_cod INT NULL` (SG12.Empresas por `ReceptorRfc`)
- `codgas INT NULL` (`FacturasRecibidas.EstacionCodgas`)
- `origen VARCHAR(20) NULL`: `'ligada'` | `'importada'` | `'historica'`
- Índice único filtrado `uuid WHERE status = 1 AND uuid IS NOT NULL`

### Candidatos del modal "Buscar"

Son CFDI de FacturasRecibidas del proveedor elegido que **no** son ya nota activa
(por UUID) y **no** están en `payment_request_invoices` activos (por UUID).

| Clase | Regla | Tipo de nota | Descripción |
|---|---|---|---|
| Nota de crédito | `TipoDeComprobante = 'E'` | CREDIT | "NOTA DE CRÉDITO" + concepto |
| Falso flete | Tipo `I` cuyos conceptos son todos "Servicio asociado" (clave 80141629) | DEBIT | "FALSO FLETE" |
| Moratorios | Tipo `I` con concepto "Intereses Moratorios" | DEBIT | "INTERESES MORATORIOS" |

- El proveedor se resuelve por `EmisorRfc` → `SG12.Proveedores.rfc`.
- Filtros: proveedor, rango de fecha (por defecto, los últimos 90 días) y clase.
- Columnas: casilla, folio (serie + folio), fecha, clase, empresa, estación,
  total, PDF.
- Datos de referencia (2026-11-24): 82 fletes MGC desde junio, 69 ya como nota,
  13 pendientes; 5 moratorios.

Decisión mía, se puede cambiar: incluir moratorios como clase propia, porque
hoy se capturan a mano igual ("MORATORIOS TSA"). Las facturas de combustible
y de anticipo nunca son candidatas.

### Número de nota

`note_number` = `Serie + Folio` sin espacio; si la serie viene vacía, solo el
folio. Es **solo para mostrar**: las notas nuevas se identifican por UUID.
FacturasRecibidas no trae la serie en los CFDI que descarga el correo (MGC
llega con `Serie NULL`), así que una nota ligada de un flete se ve como
`034398` y no como el `SE0034398` que tecleaba Jesús. El consumo histórico
(`pri.folio = note_number`) sigue funcionando, porque la fila de pago copia el
número de su propia nota, y además se agrega el match por UUID.

### Importar (sin CFDI en FacturasRecibidas)

Modal "Importar CFDI": un archivo XML y/o un PDF.

1. **Con XML**: `FuelReceptionInvoiceModel::parseCfdiXml()`. Encabezado y
   conceptos completos.
2. **Solo PDF**: `NotaCreditoPdfParser::parse()`. Inserta UUID, serie, folio,
   fecha, subtotal, total, tipo, emisor y receptor; los conceptos quedan
   vacíos. Si el parser no logra leer el PDF (otro proveedor, otro formato),
   se rechaza con "Sube el XML de esta nota".
3. Si el UUID ya existe en FacturasRecibidas, no se inserta. Se completa el
   archivo que le falte (PDF o XML) y se liga.
4. Los archivos se guardan con `AttachmentsPath::procesadasDir()` /
   `procesadasXmlDir()` en la carpeta del proveedor (MGC → `mcg`). Para
   proveedores sin carpeta, una carpeta `notas` (decisión mía).
   `OrigenImportacion = 'aplicativo'`.
5. Validaciones: el RFC emisor debe ser un proveedor conocido, el UUID debe
   ser válido y no puede estar ya como nota activa.

### Alta manual y carga masiva

Se quitan el formulario de captura manual (`addCreditDebitNote` con campos
tecleados), `uploadNoteFile` y la carga masiva solo de PDF
(`preview_notas_credito` / `guardar_notas_credito`). La carga masiva se
reemplaza por la importación, que acepta varios archivos.

### Archivos en el catálogo

El botón PDF/XML de la nota abre el archivo de FacturasRecibidas (endpoint
existente de facturas recibidas). Si la nota es histórica sin CFDI, abre el PDF
viejo de `invoice_credit_debit_notes_doc`.

### Pago

- `PaymentRequestsModel::create_payment_with_invoices()` paso 2b: la fila
  `is_debit_note = 1` recibe `uuid` y `codgas` de la nota.
- Si el pago solo trae notas, `emp_cod` = `emp_cod` de las notas. Si las notas
  son de empresas distintas, se rechaza ("Las notas de cargo son de empresas
  distintas: crea un pago por empresa"). En `add_payment.html`, el bloque
  `else` deja de depender de `#company`.
- Se agrega la validación del paso 2 por UUID (`get_active_invoice_by_uuid`):
  una nota de cargo cuyo UUID ya está en otra orden activa se rechaza.

### Estado de pago (catálogo)

Para notas DEBIT, se usa la fila `payment_request_invoices` activa con
`is_debit_note = 1` y el mismo UUID (o, en las históricas sin UUID, el mismo
folio y proveedor). Así la columna queda:

- Sin fila → **Disponible**
- `pr.status = 0` → **En pago #id**
- `pr.status = 1` → **Autorizada #id**
- `pr.status = 2` o la fila pagada → **Pagada**, con fecha y referencia
  (de `payment_transactions` o del comentario)

Para notas CREDIT se mantiene la columna actual "Asignado a" y el saldo.

### Ligar las notas existentes (backfill)

`docs/sql/backfill_notas_fr_2026-11-25.sql`, con dry-run, respaldo y transacción:

| Grupo | Match | Notas |
|---|---|---|
| DEBIT | folio numérico + RFC emisor + monto (±0.01), único | 75 |
| CREDIT | UUID en `description` ("Carga masiva - UUID …") | 254 |
| CREDIT | folio + RFC + monto, único | 6 |

- Llena `factura_recibida_id`, `uuid`, `emp_cod` y `codgas`, con
  `origen = 'historica'`.
- Para las DEBIT ligadas, llena también `uuid` y `codgas` en sus filas de pago
  (las 24 de 2026-11-24 ya tienen UUID).
- Al final saca la lista de las que no tienen pareja (≈70): `SE00333575` (folio
  con un dígito de más), `4164616` de $15,000 (nunca usada), 57 notas de crédito
  MGC de enero y febrero cuyo CFDI no está en FacturasRecibidas, y 9 de
  proveedores 71/84/96 más algunas MGC capturadas a mano.

**Las 57 de enero y febrero**: tienen UUID y su PDF está en el servidor. Un
script PHP CLI (`tools/backfill_notas_importar_pdf.php`) las importa a
FacturasRecibidas con la ruta de solo PDF y las liga. Se corre en el servidor
de producción. Sin dry-run no escribe.

## Lo que queda al builder

- El formato exacto de `note_number` (padding) para que el match histórico siga
  funcionando.
- Reutilizar o extraer el parse e insert de `FuelReceptionInvoiceModel` en un
  servicio común, sin romper Mis Recepciones.
- Qué endpoint existente sirve PDF y XML de FacturasRecibidas desde el
  catálogo.
- Actualizar `docs/pagos_documentacion_tecnica.html` y
  `docs/pagos_flujo_procesos.html`.
