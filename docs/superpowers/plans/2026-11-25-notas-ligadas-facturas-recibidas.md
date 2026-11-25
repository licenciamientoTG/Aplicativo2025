# Notas ligadas a FacturasRecibidas — plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Las notas de crédito y de cargo en `/payment/credit_notes` siempre salen de un CFDI de `TG.dbo.FacturasRecibidas`: se ligan desde un modal "Buscar" o se importan (XML y/o PDF). Las notas de cargo muestran su estado de pago y las notas existentes quedan ligadas a su CFDI.

**Architecture:** Se agregan columnas de liga (`factura_recibida_id`, `uuid`, `emp_cod`, `codgas`, `origen`) a `invoice_credit_debit_notes`. La lógica pura (clasificar un CFDI, número de nota, carpeta, armar la fila de FacturasRecibidas desde un PDF, empresa de un pago con solo notas) va en clases sin BD con tests. Las consultas van en `InvoiceCreditDebitNotesModel`, y la importación reutiliza `FuelReceptionInvoiceModel`.

**Tech Stack:** PHP 8 (MVC propio), SQL Server vía PDO sqlsrv, Twig, jQuery + DataTables + SweetAlert, tests PHP sin framework (`tests/support.php` → `check()`).

**Spec:** `docs/superpowers/specs/2026-11-25-notas-ligadas-facturas-recibidas-design.md`

## Global Constraints

- El controlador `payment` no está migrado al router: las URLs son `/payment/<método>` (fallback legacy).
- Permiso para ligar, importar o borrar notas: `authorized(66)`.
- RFC de MGC: `MME141110IJ9`. Carpeta de archivos MGC: `mcg`. Otros proveedores: `notas`.
- Joins por UUID con FacturasRecibidas: `COLLATE DATABASE_DEFAULT` en ambos lados.
- Todo script SQL que escriba: `SET XACT_ABORT ON; SET QUOTED_IDENTIFIER ON; SET ANSI_NULLS ON;`, dry-run con validaciones antes de `BEGIN TRAN`, y respaldo `SELECT … INTO TG.dbo.bkp_<tema>_20261125_*`.
- Las escrituras en BD (DDL, backfill) las corre el usuario o se corren con su autorización explícita en el chat. Los SELECT de validación sí se corren directo con `sqlcmd -S 192.168.0.6 -U cguser … -C -W`.
- No levantar el servidor PHP. La prueba en navegador la hace el usuario.
- No editar archivos con PowerShell `Set-Content` (mojibake): usar Edit/Write.
- Commits solo cuando el usuario lo pida. Al final, listar los archivos tocados para el deploy manual.
- Descripciones fijas de nota: `FALSO FLETE`, `INTERESES MORATORIOS`, `NOTA DE CRÉDITO`.
- Clase "falso flete": CFDI tipo `I` cuyos conceptos son **todos** "Servicio asociado" (clave `80141629`). Clase "moratorios": tipo `I` con algún concepto "Intereses Moratorios". Clase "nota de crédito": tipo `E`. Cualquier otro (combustible, anticipo) no es candidato.

## Review Focus

1. **El mismo CFDI ligado dos veces** (dos usuarios a la vez, o doble clic): debe fallar la segunda con "Ya existe una nota con ese UUID". Lo cubren el índice único filtrado (Task 1) y `existsUuid` antes de insertar (Task 3).
2. **Pago con notas de cargo de dos empresas distintas** y sin facturas: se rechaza con un mensaje claro, en lugar de guardar una empresa al azar o NULL (Task 7, `NotasPago::empresaUnica`).
3. **PDF de otro proveedor sin XML**: el parser no lo lee y se responde "Sube el XML de esta nota", sin insertar una fila a medias en FacturasRecibidas (Task 5, test `filaDesdePdf` con `raw_ok=false`).
4. **CFDI que ya está en FacturasRecibidas pero sin PDF**, y se importa su PDF: no se inserta de nuevo (índice único de UUID); se completa `RutaArchivo` y se liga (Task 5).
5. **Notas históricas sin UUID** que ya se pagaron por folio: siguen saliendo como "Pagada" y siguen sin aparecer como disponibles en Nuevo Pago (Task 6 y Task 7: el match es por UUID **o** por folio + proveedor).

---

### Task 1: Columnas de liga en la BD

**Files:**
- Create: `docs/sql/notas_ligadas_fr_2026-11-25.sql`

**Interfaces:**
- Produces: en `TG.dbo.invoice_credit_debit_notes`: `factura_recibida_id INT NULL`, `uuid VARCHAR(50) NULL`, `emp_cod INT NULL`, `codgas INT NULL`, `origen VARCHAR(20) NULL` (`'ligada'|'importada'|'historica'`). Índice único filtrado `UX_icdn_uuid_activa ON (uuid) WHERE status = 1 AND uuid IS NOT NULL`. Índice `IX_icdn_factura_recibida (factura_recibida_id)`.

- [ ] **Step 1:** Escribir el script idempotente (`IF COL_LENGTH(...) IS NULL ALTER TABLE ... ADD ...`, `IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = ...)`), con los SET de Global Constraints.
- [ ] **Step 2:** Verificar que hoy no hay UUID duplicados que rompan el índice (no aplica todavía, todas las filas tienen NULL). Run: `SELECT COUNT(*) FROM TG.dbo.invoice_credit_debit_notes WHERE uuid IS NOT NULL` → Expected: error "Invalid column" antes de correr el script, o 0 después.
- [ ] **Step 3:** El usuario corre el script (o autoriza correrlo). Verificar: `SELECT COLUMN_NAME FROM TG.INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='invoice_credit_debit_notes'` → incluye las 5 columnas; `sys.indexes` incluye `UX_icdn_uuid_activa` con `filter_definition` no nulo.
- [ ] **Step 4: Commit** `docs/sql/notas_ligadas_fr_2026-11-25.sql`.

---

### Task 2: Lógica pura de notas desde CFDI

**Files:**
- Create: `_assets/classes/NotaCfdi.class.php`
- Test: `tests/NotaCfdiTest.php`

**Interfaces:**
- Produces (todos `public static`):
  - `NotaCfdi::clasificar(string $tipoComprobante, array $descripcionesConceptos, array $clavesProdServ): ?string` → `'NOTA_CREDITO' | 'FALSO_FLETE' | 'MORATORIOS' | null`
  - `NotaCfdi::noteType(string $clase): string` → `'CREDIT'` para `NOTA_CREDITO`, `'DEBIT'` para las otras
  - `NotaCfdi::descripcion(string $clase): string` → `'NOTA DE CRÉDITO' | 'FALSO FLETE' | 'INTERESES MORATORIOS'`
  - `NotaCfdi::numeroNota(?string $serie, string $folio): string` → `trim($serie) . trim($folio)` sin espacio; si la serie es vacía o NULL, solo el folio. Es solo para mostrar: las notas nuevas se identifican por UUID.
  - `NotaCfdi::carpetaPorRfc(string $rfcEmisor): string` → `'mcg'` para `MME141110IJ9`, `'notas'` para cualquier otro.

- [ ] **Step 1: Write the failing test** `tests/NotaCfdiTest.php` (`require __DIR__.'/support.php'` y la clase). Casos con `check()`:
  - `clasificar('E', ['Bonificación'], [''])` === `'NOTA_CREDITO'`
  - `clasificar('I', ['Servicio Asociado'], ['80141629'])` === `'FALSO_FLETE'`
  - `clasificar('I', ["Servicio\nasociado", 'Servicio asociado'], ['', '80141629'])` === `'FALSO_FLETE'` (hay conceptos duplicados con salto de línea y sin clave)
  - `clasificar('I', ['PEMEX MAGNA', 'Servicio asociado'], ['15101514', '80141629'])` === `null`
  - `clasificar('I', ['Intereses Moratorios 2705035811 04-Agosto- 2026'], [''])` === `'MORATORIOS'`
  - `clasificar('I', ['Anticipo saldo cliente'], [''])` === `null`
  - `clasificar('I', [], [])` === `null`
  - `clasificar('P', ['Pago'], [''])` === `null`
  - `numeroNota('SE', '034398')` === `'SE034398'`; `numeroNota(null, '034398')` === `'034398'`; `numeroNota(' MR ', ' 003109 ')` === `'MR003109'`
  - `carpetaPorRfc('MME141110IJ9')` === `'mcg'`; `carpetaPorRfc('XAXX010101000')` === `'notas'`
  - `noteType('FALSO_FLETE')` === `'DEBIT'`; `noteType('NOTA_CREDITO')` === `'CREDIT'`
- [ ] **Step 2:** Run `php tests/NotaCfdiTest.php` → Expected: FAIL (clase no existe).
- [ ] **Step 3:** Implementar `NotaCfdi`. Antes de comparar las descripciones: colapsar espacios y saltos de línea, pasar a minúsculas y quitar acentos. "Servicio asociado" se reconoce por texto **o** por clave `80141629`.
- [ ] **Step 4:** Run `php tests/NotaCfdiTest.php` → Expected: todos OK.
- [ ] **Step 5: Commit.**

---

### Task 3: Modal "Buscar en facturas recibidas" y ligar

**Files:**
- Modify: `_assets/models/InvoiceCreditDebitNotesModel.php` (agregar métodos; `addCreditDebitNote` acepta las columnas nuevas)
- Modify: `_assets/controllers/payment.php` (endpoints nuevos junto a `credit_notes()`, ~línea 5614)
- Create: `views/payment/modals/notas_buscar_fr.html` (vista parcial que se carga por `fetch`, patrón del proyecto)
- Modify: `views/payment/credit_notes.html` (botón "Buscar en facturas recibidas" en la barra, junto al de carga masiva en ~línea 99; JS del modal)

**Interfaces:**
- Consumes: `NotaCfdi::*` (Task 2); columnas de Task 1.
- Produces:
  - `InvoiceCreditDebitNotesModel::getCandidatosFR(int $providerId, string $desde, string $hasta): array`. Filas de FacturasRecibidas `{fr_id, uuid, serie, folio, fecha, tipo, total, receptor_nombre, receptor_rfc, emp_cod, emp_den, codgas, estacion, tiene_pdf, conceptos (string con '|'), claves (string con '|')}` cuyo `EmisorRfc` es el RFC del proveedor, con `Fecha` en el rango, sin nota activa con ese UUID y sin fila activa en `payment_request_invoices` con ese UUID. Los conceptos se agregan con `STRING_AGG`. La clase la calcula PHP con `NotaCfdi::clasificar` y se descartan los `null`.
  - `InvoiceCreditDebitNotesModel::existsUuid(string $uuid): bool` (nota activa con ese UUID).
  - `InvoiceCreditDebitNotesModel::crearDesdeFactura(int $frId, int $userId, string $origen = 'ligada', ?string $claseForzada = null): array` (`$claseForzada` solo la usa la Task 5, para un CFDI importado con solo PDF) → `['success'=>bool, 'note_id'=>?int, 'message'=>string]`. Relee el CFDI de la BD (no confía en el cliente), clasifica y resuelve proveedor (RFC emisor → `SG12.Proveedores.rfc`), empresa (`ReceptorRfc` → `SG12.Empresas.rfc`) y `codgas` (`EstacionCodgas`). Inserta con `note_number = NotaCfdi::numeroNota`, `amount = Total`, `note_date = Fecha`, `description = NotaCfdi::descripcion`, `reason_code = 'CFDI'`. Rechaza si la clase es `null` ("Este CFDI no es nota de crédito ni de cargo") o si `existsUuid`.
  - `GET /payment/notas_buscar_fr_modal` → HTML del modal (proveedores activos para el filtro; MGC seleccionado por defecto; fechas de los últimos 90 días).
  - `POST /payment/notas_candidatos_fr` (`provider_id`, `desde`, `hasta`) → `{success, candidatos:[…+clase, note_type, numero]}`
  - `POST /payment/notas_ligar_fr` (`factura_ids[]`) → `{success, creadas:int, resultados:[{fr_id, success, message}]}`. Es tolerante a fallos parciales, igual que `guardar_notas_credito`.

- [ ] **Step 1:** Escribir el SQL de `getCandidatosFR` y validarlo con sqlcmd para MGC (proveedor 72), desde `2026-06-01` hasta `2026-11-25`. Expected (datos de 2026-11-24): entre las filas cuyos conceptos son solo "Servicio asociado" deben salir los **13 fletes sin nota** (no los 69 ya ligados) y los 5 moratorios. Las facturas de combustible pueden salir de la consulta: `NotaCfdi::clasificar` las descarta en PHP.
- [ ] **Step 2:** Implementar los 3 métodos del modelo y los 3 endpoints, todos con `authorized(66)` (si no: `json_output(['success'=>false,'message'=>'Sin permiso'])`). Run `php -l` en ambos archivos → Expected: "No syntax errors".
- [ ] **Step 3:** Vista parcial y JS. Columnas: casilla, folio (`numero`), fecha, clase (badge), empresa, estación, total, botón PDF (`/payment/invoiceFile?FacturaId=<fr_id>`, deshabilitado si no `tiene_pdf`). Pie: "N seleccionadas · $total" y botón "Crear N notas". Al terminar: SweetAlert con el detalle por fila y recarga de la tabla del catálogo.
- [ ] **Step 4:** Verificación en BD tras la prueba del usuario en navegador: `SELECT TOP 5 id, note_number, uuid, emp_cod, codgas, origen FROM TG.dbo.invoice_credit_debit_notes ORDER BY id DESC` → las nuevas tienen `uuid`, `emp_cod` y `origen='ligada'`.
- [ ] **Step 5: Commit.**

---

### Task 4: Catálogo — archivo del CFDI y estado de pago

**Files:**
- Modify: `_assets/models/InvoiceCreditDebitNotesModel.php:20-53` (`getNotesByProvider`)
- Modify: `views/payment/credit_notes.html` (columnas de la DataTable, ~líneas 470-545)

**Interfaces:**
- Consumes: columnas de Task 1.
- Produces: `getNotesByProvider` agrega estos campos por fila: `factura_recibida_id`, `uuid`, `emp_den`, `tiene_pdf_fr` (bit), `tiene_xml_fr` (bit), `pago_id` (?int), `pago_status` (?int, `payment_requests.status`), `pago_fecha` (?datetime), `pago_ref` (?string) y `estado_pago` (`'DISPONIBLE'|'EN_PAGO'|'AUTORIZADA'|'PAGADA'|null`, null en notas CREDIT).

- [ ] **Step 1:** SQL con `OUTER APPLY (SELECT TOP 1 …)` sobre `payment_request_invoices pri JOIN payment_requests pr` con `pri.is_debit_note = 1`, `pri.is_deleted = 0`, `pr.is_deleted = 0` y match `(t1.uuid IS NOT NULL AND pri.uuid = t1.uuid) OR (pri.folio = t1.note_number AND pr.provider_cod = t1.provider_id)`. Estado: sin fila → DISPONIBLE; `pr.status=0` → EN_PAGO; `1` → AUTORIZADA; `2` o `pri.status=2` → PAGADA. Fecha y referencia: de `payment_transactions` (`payment_request_id = pr.id`, la más reciente con `status IN (1,2)`); si no hay, `pr.comment`. Validar con sqlcmd. Expected con datos de 2026-11-24: DEBIT → 69 PAGADA, 3 AUTORIZADA, 4 EN_PAGO, 1 DISPONIBLE.
- [ ] **Step 2:** En la tabla: columna "Pago" (badge con el estado y "#id" enlazado a `/payment/payment_detail/<id>`, tooltip con fecha y referencia). La columna de documentos: si hay `factura_recibida_id`, ícono PDF → `/payment/invoiceFile?FacturaId=`; si no, se mantiene el botón actual de `getNoteDocuments` para las históricas.
- [ ] **Step 3:** `php -l` → OK. El usuario verifica en el navegador: una nota pagada (p. ej. `SE0034398`) sale "Pagada #1545" con la referencia `8846APR22026…`.
- [ ] **Step 4: Commit.**

---

### Task 5: Importar CFDI (XML y/o PDF)

**Files:**
- Create: `_assets/classes/CfdiNotaImport.class.php`
- Modify: `_assets/models/FuelReceptionInvoiceModel.php:400-436` (agregar `guardarArchivo`, sin cambiar `guardarArchivos`)
- Modify: `_assets/controllers/payment.php` (endpoint nuevo)
- Modify: `views/payment/credit_notes.html` (modal "Importar CFDI", que reemplaza al de carga masiva)
- Test: `tests/CfdiNotaImportTest.php`

**Interfaces:**
- Consumes: `NotaCreditoPdfParser::parse()`, `FuelReceptionInvoiceModel::parseCfdiXml()`, `::insertarFactura()`, `::buscarPorUuid()`, `::actualizarArchivos()`, `NotaCfdi::carpetaPorRfc()`, `InvoiceCreditDebitNotesModel::crearDesdeFactura()` (Task 3).
- Produces:
  - `CfdiNotaImport::filaDesdePdf(array $parsed): ?array` (pura). Si `$parsed['raw_ok']` es false, devuelve `null`. Si no, devuelve el arreglo `$factura` con las llaves que espera `insertarFactura` (`Folio, Serie, Fecha, Total, SubTotal, TipoDeComprobante, EmisorRfc, EmisorNombre, ReceptorRfc, ReceptorNombre, UUID` con los valores del parser, `Moneda='MXN'` y el resto `null`).
  - `CfdiNotaImport::emparejarArchivos(array $files): array` (pura). Recibe `$_FILES['cfdi']` normalizado como `[['name','tmp_name','error'], …]` y agrupa por nombre base sin extensión, sin distinguir mayúsculas: `[['base'=>…, 'xml'=>?tmp, 'pdf'=>?tmp, 'pdf_name'=>?string], …]`. Si un grupo no trae ninguno de los dos, se ignora.
  - `FuelReceptionInvoiceModel::guardarArchivo(string $carpeta, string $uuid, string $tmpPath, string $ext): array` → `['ruta'=>…, 'nombre'=>…]` (`$ext` es `'pdf'` o `'xml'`; carpeta `procesadas` o `procesadasXml`).
  - `POST /payment/notas_importar_cfdi` (`cfdi[]` multiarchivo) → `{success, resultados:[{archivo, success, message, note_id?}]}`. Por cada grupo:
    1. Si trae XML, `parseCfdiXml`; si no, `NotaCreditoPdfParser::parse` y `filaDesdePdf` (si da `null`: "Sube el XML de esta nota").
    2. Si `buscarPorUuid` encuentra el CFDI, se usa ese Id y se completa el archivo que falte (`RutaArchivo` y/o `RutaXml`). Si no, `insertarFactura` (los conceptos quedan vacíos cuando solo viene PDF) y se guardan los archivos.
    3. Si ya hay nota activa con ese UUID: "Ya existe una nota con ese UUID".
    4. `crearDesdeFactura($id, $user, 'importada', $claseForzada)`. Si se importó con solo PDF y no hay conceptos: el tipo `E` es nota de crédito y no necesita clase forzada. Para el tipo `I` se usa la clase que el usuario eligió en el selector del modal ("Si no subes XML, ¿qué es?: Falso flete / Moratorios").

- [ ] **Step 1: Write the failing test** `tests/CfdiNotaImportTest.php`:
  - `filaDesdePdf(['raw_ok'=>false])` === `null`
  - `filaDesdePdf([... 'raw_ok'=>true, 'uuid'=>'C5BB5D69-B101-451B-81BB-489C5B5C8290', 'folio'=>'034398', 'serie'=>'SE', 'total'=>1027.91, 'tipo_comprobante'=>'I', 'rfc_emisor'=>'MME141110IJ9', 'rfc_receptor'=>'DGA930823KD3', 'fecha'=>'2026-08-20', …])['UUID']` === el mismo UUID; `['Sello']` === `null`; `['Total']` === `1027.91`
  - `emparejarArchivos` con `[a.xml, a.PDF, b.pdf, c.txt]` → 2 grupos: `a` con xml y pdf, `b` solo con pdf (`c.txt` se ignora)
- [ ] **Step 2:** Run `php tests/CfdiNotaImportTest.php` → FAIL.
- [ ] **Step 3:** Implementar `CfdiNotaImport`, `guardarArchivo`, el endpoint y el modal (dropzone; acepta `.xml` y `.pdf`; "Puedes subir el XML, el PDF o ambos con el mismo nombre").
- [ ] **Step 4:** Run `php tests/CfdiNotaImportTest.php` y `php tests/AttachmentsPathTest.php` → todo OK (Mis Recepciones no se rompe: `guardarArchivos` queda igual).
- [ ] **Step 5:** El usuario prueba en el navegador con (a) XML + PDF de un flete nuevo, (b) solo el PDF de una NC de MGC de enero, (c) solo el PDF de otro proveedor → debe salir "Sube el XML". Verificación: `SELECT Id, UUID, RutaArchivo, RutaXml, OrigenImportacion FROM TG.dbo.FacturasRecibidas WHERE UUID IN (…)`.
- [ ] **Step 6: Commit.**

---

### Task 6: Quitar la captura manual

**Files:**
- Modify: `_assets/controllers/payment.php` (`addCreditDebitNote` ~5142, `uploadNoteFile` ~5528, `preview_notas_credito` ~5261, `guardar_notas_credito` ~5347, `addNoteModal` ~1751)
- Modify: `views/payment/credit_notes.html` (quitar el botón y el modal de alta manual y el de carga masiva; quitar "subir archivo" por nota)
- Modify: `views/payment/add_payment.html` (si `addNoteModal` permite capturar notas desde Nuevo Pago, quitar esa opción y dejar un enlace a `/payment/credit_notes`)

**Interfaces:**
- Produces: los 4 endpoints de alta responden `json_output(['success'=>false,'message'=>'Las notas se crean desde un CFDI: usa "Buscar en facturas recibidas" o "Importar CFDI".'])`. Se dejan como stub un tiempo por si alguna pantalla vieja los llama. `getNoteDocuments` / `viewNoteDocument` / `view_note_doc` se quedan (lectura histórica).

- [ ] **Step 1:** `grep -rn "addCreditDebitNote\|uploadNoteFile\|preview_notas_credito\|guardar_notas_credito\|addNoteModal" views _assets/js` para listar quién los llama; quitar o reemplazar cada llamada.
- [ ] **Step 2:** Convertir los endpoints en stubs; `php -l` → OK.
- [ ] **Step 3:** Repetir el grep → solo quedan los stubs del controlador.
- [ ] **Step 4: Commit.**

---

### Task 7: Pago — la nota de cargo lleva UUID, estación y empresa

**Files:**
- Create: `_assets/classes/NotasPago.class.php`
- Modify: `_assets/models/PaymentRequestsModel.php:25-111` (`create_payment_with_invoices`, paso 2b) y `:1192` (el segundo INSERT de notas, revisar si es el de "agregar a pago existente")
- Modify: `_assets/models/InvoiceCreditDebitNotesModel.php:58-104` y `:204` (match por UUID o por folio en "ya consumida")
- Modify: `_assets/controllers/payment.php:1451-1479` (empresa)
- Modify: `views/payment/add_payment.html:1391-1396`
- Test: `tests/NotasPagoTest.php`

**Interfaces:**
- Consumes: columnas de Task 1.
- Produces:
  - `NotasPago::empresaUnica(array $notas): ?int` (pura; `$notas` = filas con `emp_cod`). Devuelve el único `emp_cod` no nulo. Lanza `InvalidArgumentException('Las notas de cargo son de empresas distintas: crea un pago por empresa')` si hay más de uno, y devuelve `null` si ninguna tiene empresa.
  - En `create_payment_with_invoices`, el paso 2b relee cada nota por `credit_note_id` (`getNoteById`) y no usa los montos ni números del cliente para uuid/codgas. Inserta la fila con `codgas = nota.codgas` y `uuid = nota.uuid`. Antes de insertar, si `uuid` tiene valor y `get_active_invoice_by_uuid($uuid)` devuelve fila, lanza "La nota {numero} ya está incluida en otra orden de pago".
  - Si `$documents` está vacío: `$empresa_cod = $empresa_cod ?: NotasPago::empresaUnica($notasReleidas)`. Si al final sigue `null`: error "Elige la empresa del pago" (no se crea el pago sin empresa).
  - En `getAvailableNotesByProvider` y `getAvailableBalance`, el `NOT EXISTS` / `EXISTS` de "ya cobrada" usa `((t1.uuid IS NOT NULL AND pri.uuid = t1.uuid) OR (pri.folio = t1.note_number AND pr.provider_cod = t1.provider_id))`.

- [ ] **Step 1: Write the failing test** `tests/NotasPagoTest.php`:
  - `empresaUnica([['emp_cod'=>1],['emp_cod'=>1]])` === `1`
  - `empresaUnica([['emp_cod'=>1],['emp_cod'=>null]])` === `1`
  - `empresaUnica([['emp_cod'=>null]])` === `null`
  - `empresaUnica([])` === `null`
  - `empresaUnica([['emp_cod'=>1],['emp_cod'=>21]])` lanza `InvalidArgumentException` con el mensaje exacto
- [ ] **Step 2:** Run `php tests/NotasPagoTest.php` → FAIL.
- [ ] **Step 3:** Implementar `NotasPago` y los cambios del modelo y el controlador. En `add_payment.html`, la rama `else` manda `empresa_cod` del filtro solo si el usuario lo eligió; el servidor decide.
- [ ] **Step 4:** Run `php tests/NotasPagoTest.php` → OK; `php -l` en los archivos tocados → OK.
- [ ] **Step 5:** El usuario crea en el navegador un pago solo con 2 notas de cargo ligadas en la Task 3, sin elegir empresa. Verificación: `SELECT r.emp_cod, p.uuid, p.codgas FROM TG.dbo.payment_requests r JOIN TG.dbo.payment_request_invoices p ON p.payment_request_id=r.id WHERE r.id = <nuevo>` → empresa, UUID y codgas con valor.
- [ ] **Step 6: Commit.**

---

### Task 8: Ligar las notas existentes (SQL)

**Files:**
- Create: `docs/sql/backfill_notas_fr_2026-11-25.sql`

**Interfaces:**
- Consumes: columnas de Task 1.
- Produces: notas con `factura_recibida_id`, `uuid`, `emp_cod`, `codgas` y `origen='historica'`; filas de pago DEBIT con `uuid` y `codgas`. Respaldos `TG.dbo.bkp_notas_fr_20261125_icdn` y `_pri`.

- [ ] **Step 1:** Tabla temporal `#m(note_id, fr_id, uuid, emp_cod, codgas, metodo)` con tres pasos de match, en este orden, cada uno solo sobre las notas que no emparejaron antes:
  1. CREDIT por UUID de `description` (`SUBSTRING(description, 21, 40)` cuando `description LIKE 'Carga masiva - UUID %'`).
  2. DEBIT y CREDIT por folio numérico (`TRY_CAST` quitando las letras del `note_number`) + `EmisorRfc` = RFC del proveedor + `ABS(Total - amount) < 0.01`, solo cuando el match es único.
  3. Se excluyen los UUID que ya tiene otra nota activa.
- [ ] **Step 2:** Validaciones antes de `BEGIN TRAN`: ninguna nota con más de un match y ningún UUID repetido en `#m`. Esperado con datos de 2026-11-24: ~254 por UUID, ~81 por folio (75 DEBIT + 6 CREDIT). Si los conteos se alejan más de 5 de esos números, abortar con `RAISERROR`.
- [ ] **Step 3:** En la transacción: respaldos, UPDATE de las notas, y UPDATE de `payment_request_invoices` (`is_debit_note=1`, `uuid IS NULL`, `folio = note_number`, mismo proveedor) con `uuid` y `codgas`. Las `payment_requests` con `emp_cod IS NULL` y solo filas de notas reciben `emp_cod` si todas sus notas comparten empresa.
- [ ] **Step 4:** Al final, SELECT de las notas sin pareja (id, proveedor, número, fecha, monto, descripción), para entregarlas al usuario.
- [ ] **Step 5:** Dry-run con todo dentro de `BEGIN TRAN … ROLLBACK` (bandera `@aplicar BIT = 0` al inicio del script). Mostrar los conteos al usuario; con su OK, correr con `@aplicar = 1`.
- [ ] **Step 6:** Verificación: `SELECT origen, note_type, COUNT(*) FROM TG.dbo.invoice_credit_debit_notes GROUP BY origen, note_type` y que el estado de pago de la Task 4 siga dando 69/3/4/1.
- [ ] **Step 7: Commit.**

---

### Task 9: Importar las 57 notas de crédito de enero y febrero desde su PDF guardado

**Files:**
- Create: `tools/backfill_notas_importar_pdf.php`

**Interfaces:**
- Consumes: `NotaCreditoPdfParser::parse`, `CfdiNotaImport::filaDesdePdf`, `FuelReceptionInvoiceModel::insertarFactura`, `::guardarArchivo`, `::actualizarArchivos`.
- Produces: CLI `php tools/backfill_notas_importar_pdf.php [--aplicar]`. Sin `--aplicar` solo imprime una línea por nota: `note_id | uuid | pdf | accion (importar/ya-en-FR/sin-pdf/no-legible/uuid-distinto)`.

- [ ] **Step 1:** Seleccionar las notas activas con `uuid IS NULL` y `description LIKE 'Carga masiva - UUID %'` con su primer documento en `invoice_credit_debit_notes_doc`. Por cada una: resolver el PDF con `__DIR__.'/../_assets/'.file_path`, parsearlo y comprobar que el UUID del PDF sea el de la descripción (si no coincide: `uuid-distinto`, no se toca).
- [ ] **Step 2:** Con `--aplicar`: copiar el PDF (sin moverlo) con `guardarArchivo('mcg', $uuid, $ruta, 'pdf')`, `insertarFactura($fila, [])` si no existe el UUID, y UPDATE de la nota (`factura_recibida_id`, `uuid`, `emp_cod` por RFC receptor, `origen='historica'`).
- [ ] **Step 3:** `php -l tools/backfill_notas_importar_pdf.php` → OK. El usuario lo corre **en el servidor de producción** (los PDF solo están ahí): primero sin `--aplicar`, revisar la salida (esperado: ~57 `importar`) y luego con `--aplicar`.
- [ ] **Step 4: Commit.**

---

### Task 10: Documentación y cierre

**Files:**
- Modify: `docs/pagos_documentacion_tecnica.html`, `docs/pagos_flujo_procesos.html`
- [ ] **Step 1:** Documentar las dos formas de alta (Buscar / Importar), las columnas nuevas, el estado de pago y que la captura manual se quitó.
- [ ] **Step 2:** Correr todos los tests: `php tests/NotaCfdiTest.php`, `php tests/CfdiNotaImportTest.php`, `php tests/NotasPagoTest.php`, `php tests/AttachmentsPathTest.php`, `php tools/routes.php lint` → OK.
- [ ] **Step 3:** Entregar al usuario la lista de archivos para el deploy manual, el orden de los SQL (Task 1 → deploy del código → Task 8 → Task 9 en el servidor) y la lista de notas sin pareja.
- [ ] **Step 4: Commit.**
