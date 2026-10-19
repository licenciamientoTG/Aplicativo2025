# Pagos extraordinarios Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el Excel "FORMATO SOLICITUD EXTRA-MKT" por un flujo capturar → VoBo CxP → autorización Admón y Finanzas → pago de Tesorería.

**Architecture:** Controlador nuevo `ExtraPayments` con router explícito (`routes/extrapayments.php`). Las reglas de estatus viven en una clase pura `ExtraPaymentWorkflow` (sin BD, probada con `tests/support.php`); los modelos solo persisten. Vistas Twig; modales por vista parcial vía fetch (patrón del proyecto).

**Tech Stack:** PHP 8, Twig, SQL Server (PDO sqlsrv, BD `TG`), PhpSpreadsheet (export), `send_mail_with_fallback()` para correos.

**Spec:** `docs/superpowers/specs/2026-10-19-pagos-extraordinarios-design.md`

## Global Constraints

- Estatus (int, en este orden): `1` Pendiente VoBo CxP, `2` Pendiente Admón, `3` Autorizada, `4` Pagada, `8` Cancelada, `9` Rechazada.
- Permisos (verificados libres en BD; 103–105 son de Tableros; último en uso 105): `106` crear/ver propias, `107` VoBo CxP, `108` autoriza Admón, `109` paga Tesorería, `110` ver todas.
- Motivos fijos (catálogo `extra_payment_reasons`): Pocos días de crédito o proveedor no da crédito; Solicitud de los Sr. Díaz; Viaje esporádico; Cortes de servicios o créditos; No se programó con tiempo; Otros (exige texto).
- Pago múltiple = un solo registro de pago por solicitud; `fecha_pago_requerida` por renglón es informativa.
- Centro de costos: texto libre (VARCHAR 80). Empresas: `SG12.dbo.Empresas` (`cod`, `den`).
- Rechazo regresa a edición del solicitante con comentario; al reenviar se borran las aprobaciones previas de esa solicitud (se conservan en `extra_payment_audit_log`).
- URLs de rutas migradas solo con `url('extrapayments.x')` en Twig y `routeUrl()` en JS. Nunca editar archivos con PowerShell Get-Content/Set-Content. No levantar el servidor PHP.
- Modo pruebas de correo: destinatarios por `PaymentNotificationRecipientsModel::get_active_emails($evento)`, eventos `pago_extra_cxp`, `pago_extra_admon`, `pago_extra_tesoreria`; remitente `totalgasdesarrollo@gmail.com`.
- Adjuntos: PDF/JPG/PNG/XML, máx 10 MB, `_assets/uploads/pagos_extraordinarios/YYYY/MM/{id}.{ext}`, soft-delete (patrón `TarjetasCreditoDocumentosModel`).

## Review Focus

- Solicitud sin renglones o con monto ≤ 0 o no numérico: se rechaza al enviar.
- Motivo "Otros" sin texto: se rechaza.
- Un usuario con permiso 107 aprobando una solicitud en estatus 2 (o 108 en estatus 1): se rechaza por estatus/permiso.
- Aprobar dos veces la misma solicitud (doble clic / dos pestañas): la segunda no avanza el estatus.
- Solicitante viendo/editando solicitudes ajenas sin permiso 110: 403.
- Pago de una solicitud que no está Autorizada, o registrado dos veces: se rechaza.
- Descarga de adjunto con ruta fuera de la carpeta de uploads: 404.

---

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `docs/sql/pagos_extraordinarios_schema.sql` | Tablas, seed de motivos, permisos 106–110 |
| `_assets/classes/ExtraPaymentWorkflow.php` | Reglas puras de estatus/permiso/validación |
| `_assets/models/ExtraPaymentRequestsModel.php` | Solicitudes, renglones, aprobaciones, pago, auditoría |
| `_assets/models/ExtraPaymentDocumentsModel.php` | Adjuntos |
| `_assets/models/ExtraPaymentCatalogModel.php` | Motivos y empresas |
| `_assets/controllers/extrapayments.php` | Controlador `ExtraPayments` |
| `routes/extrapayments.php` | Rutas explícitas |
| `views/extrapayments/*.html` | `index`, `form`, `detail`, `inbox`, `report`, `print`, `modals/*` |
| `_assets/js/extrapayments.js` | Renglones dinámicos, acciones por fetch |
| `tests/ExtraPaymentWorkflowTest.php`, `tests/ExtraPaymentsRoutesTest.php` | Pruebas sin framework |

---

### Task 1: Esquema SQL y permisos

**Files:**
- Create: `docs/sql/pagos_extraordinarios_schema.sql`

**Interfaces:**
- Produces: tablas `TG.dbo.extra_payment_requests`, `extra_payment_lines`, `extra_payment_reasons`, `extra_payment_approvals`, `extra_payment_documents`, `extra_payment_payments`, `extra_payment_audit_log`; permisos 106–110 en `tg_permissions`.

- [ ] **Step 1:** Escribir el script (idempotente, `IF NOT EXISTS`, estilo `docs/sql/departamentos.sql`). Columnas:
  - `extra_payment_requests`: `id` IDENTITY PK, `folio` (= id; sin columna extra), `fecha` DATE, `user_id` INT, `departamento_id` INT NULL, `tipo` VARCHAR(10) CHECK `('individual','multiple')`, `reason_id` INT, `motivo_otros` VARCHAR(200) NULL, `total` DECIMAL(18,2), `status` INT, `created_at`, `updated_at`.
  - `extra_payment_lines`: `id`, `request_id`, `empresa_cod` INT, `centro_costos` VARCHAR(80), `proveedor` VARCHAR(200), `factura` VARCHAR(60) NULL, `fecha_factura` DATE NULL, `orden_compra` VARCHAR(60) NULL, `descripcion` VARCHAR(400), `monto` DECIMAL(18,2), `fecha_pago_requerida` DATE, `comentario_urgencia` VARCHAR(300) NULL.
  - `extra_payment_reasons`: `id`, `nombre`, `requiere_texto` BIT, `activo` BIT, `orden` INT; seed con los 6 motivos de Global Constraints.
  - `extra_payment_approvals`: `id`, `request_id`, `step` INT (1 CxP, 2 Admón), `permission_number` INT, `user_id`, `decision` VARCHAR(10) (`aprobada`/`rechazada`), `comentario` VARCHAR(400) NULL, `created_at`, `is_active` BIT DEFAULT 1 (se pone 0 al reenviar).
  - `extra_payment_documents`: igual que `tarjetas_credito_documentos` más `request_id` y `kind` VARCHAR(20) (`soporte`/`comprobante`).
  - `extra_payment_payments`: `id`, `request_id` UNIQUE, `bank_account_id` INT, `fecha_pago` DATE, `referencia` VARCHAR(80), `monto` DECIMAL(18,2), `user_id`, `created_at`.
  - `extra_payment_audit_log`: `id`, `request_id`, `user_id`, `action` VARCHAR(30), `detail` VARCHAR(500) NULL, `created_at`.
  - Índices por `(status)`, `(user_id)`, `(request_id)` en hijas.
  - Permisos con `SET IDENTITY_INSERT [TG].[dbo].[tg_permissions] ON` ids 106–110 solo si no existen (`action='read'`, `department='Tesorería'`, descripción máx 50 caracteres, p. ej. "Pagos extra: crear/ver propias", "Pagos extra: VoBo CxP", "Pagos extra: Autoriza Admón/Finanzas", "Pagos extra: Pago Tesorería", "Pagos extra: Ver todas").
  - Seed de `payment_notification_recipients` para los 3 eventos con `alejandro.martinez@totalgas.com` (modo pruebas), `IF NOT EXISTS` por evento.
- [ ] **Step 2:** Verificar libres los ids antes de correr: `sqlcmd` SELECT `SELECT id FROM TG.dbo.tg_permissions WHERE id BETWEEN 106 AND 110` → 0 filas. Si hay filas, ajustar los ids en script y en Global Constraints.
- [ ] **Step 3:** Pedir al usuario que corra el script en `TG` (DDL no se ejecuta desde aquí). Verificar con SELECT que existen las 7 tablas y 6 motivos.
- [ ] **Step 4:** Commit `docs/sql/pagos_extraordinarios_schema.sql`.

---

### Task 2: Reglas de flujo (`ExtraPaymentWorkflow`)

**Files:**
- Create: `_assets/classes/ExtraPaymentWorkflow.php`
- Test: `tests/ExtraPaymentWorkflowTest.php`

**Interfaces:**
- Produces (todos `public static`, sin BD):
  - consts `ST_PEND_CXP=1, ST_PEND_ADMON=2, ST_AUTORIZADA=3, ST_PAGADA=4, ST_CANCELADA=8, ST_RECHAZADA=9`; `PERM_CREAR=106, PERM_CXP=107, PERM_ADMON=108, PERM_TESORERIA=109, PERM_VER_TODAS=110`.
  - `next_status_on_approve(int $status): ?int` — 1→2, 2→3, otro→null.
  - `required_permission(int $status): ?int` — 1→107, 2→108, 3→109 (pagar), otro→null.
  - `can_edit(int $status): bool` — true solo en 9 (rechazada) o 1 sin aprobaciones lo decide el modelo; aquí: true para 9.
  - `can_cancel(int $status): bool` — true para 1, 2, 9.
  - `validate_request(array $header, array $lines): array` — devuelve lista de mensajes de error (vacía = válida). `$header`: `reason_id`, `motivo_otros`, `requiere_texto` (bool), `tipo`. `$lines`: cada una con `empresa_cod`, `centro_costos`, `proveedor`, `descripcion`, `monto`, `fecha_pago_requerida`.
  - `total(array $lines): float` — suma de `monto` redondeada a 2 decimales.

- [ ] **Step 1: Write the failing test** `tests/ExtraPaymentWorkflowTest.php` (`require __DIR__.'/support.php'` + la clase), con `check()` para:
  - `next_status_on_approve(1)===2`, `(2)===3`, `(3)===null`, `(4)===null`.
  - `required_permission(1)===107`, `(2)===108`, `(3)===109`, `(4)===null`.
  - `can_edit(9)===true`, `can_edit(1)===false`; `can_cancel(3)===false`, `can_cancel(2)===true`.
  - `validate_request` válida con 1 renglón completo → `[]`.
  - sin renglones → 1 error; monto `0`, `-5`, `'abc'` → error cada uno; `requiere_texto=true` con `motivo_otros=''` → error; `tipo='individual'` con 2 renglones → error; renglón sin `empresa_cod`, `proveedor` o `descripcion` → error.
  - `total([['monto'=>'10.10'],['monto'=>'0.20']])===10.3`.
- [ ] **Step 2:** `php tests/ExtraPaymentWorkflowTest.php` → FALLA (clase inexistente).
- [ ] **Step 3:** Implementar la clase en `_assets/classes/ExtraPaymentWorkflow.php` con las firmas de Interfaces.
- [ ] **Step 4:** `php tests/ExtraPaymentWorkflowTest.php` → "Todo bien".
- [ ] **Step 5:** Commit.

---

### Task 3: Modelos y catálogos

**Files:**
- Create: `_assets/models/ExtraPaymentRequestsModel.php`, `ExtraPaymentCatalogModel.php`

**Interfaces:**
- Consumes: `ExtraPaymentWorkflow` (Task 2); tablas (Task 1).
- Produces (`ExtraPaymentCatalogModel`): `reasons(): array` (activos, por `orden`), `empresas(): array` (`cod`,`den` de `[SG12].[dbo].[Empresas]` activas).
- Produces (`ExtraPaymentRequestsModel extends Model`):
  - `create(array $header, array $lines, int $userId): int|false` — transacción: inserta solicitud con `status=1`, `total` calculado con `ExtraPaymentWorkflow::total`, renglones, auditoría `CREATE`; devuelve id.
  - `update(int $id, array $header, array $lines, int $userId): bool` — solo si status 9; reemplaza renglones, pone `status=1`, `is_active=0` a aprobaciones previas, auditoría `RESUBMIT`.
  - `get(int $id): ?array` — encabezado + `lines` + `approvals` (activas con nombre de usuario) + `payment` + `reason_nombre` + `solicitante` + `departamento`.
  - `list_for_user(int $userId): array`, `list_all(array $filters): array` (`status`, `empresa_cod`, `reason_id`, `desde`, `hasta`), `list_by_status(int $status): array`.
  - `approve(int $id, int $userId, ?string $comentario): bool` y `reject(int $id, int $userId, string $comentario): bool` — transacción; el `UPDATE ... SET status=? WHERE id=? AND status=?` con el estatus esperado y `@@ROWCOUNT`/filas afectadas = 1 para que una segunda llamada concurrente no avance; inserta fila en `approvals` y auditoría. `reject` pone status 9 y exige comentario no vacío.
  - `cancel(int $id, int $userId): bool` — solo estatus 1, 2, 9; auditoría.
  - `register_payment(int $id, int $userId, int $bankAccountId, string $fecha, string $referencia): bool` — transacción; exige status 3; `UPDATE ... WHERE status=3`; inserta en `payments` con `monto = total`; status 4.
  - `audit(int $id, int $userId, string $action, ?string $detail): void`.

- [ ] **Step 1:** Implementar el catálogo y el modelo con las firmas de Interfaces; todas las consultas parametrizadas, escritura bajo `beginTransaction()/commit()/rollBack()`.
- [ ] **Step 2:** Prueba de humo contra BD con un script desechable en el scratchpad (no se commitea): crear una solicitud, `approve` ×2 verificando que la segunda devuelve `false`, `register_payment` antes de status 3 devuelve `false`. Pedir confirmación al usuario antes de escribir en la BD real; limpiar filas de prueba al terminar.
- [ ] **Step 3:** Commit.

---

### Task 4: Controlador, rutas y captura

**Files:**
- Create: `_assets/controllers/extrapayments.php`, `routes/extrapayments.php`, `views/extrapayments/index.html`, `form.html`, `detail.html`, `_assets/js/extrapayments.js`
- Test: `tests/ExtraPaymentsRoutesTest.php`

**Interfaces:**
- Consumes: modelos (Task 3), `ExtraPaymentWorkflow`.
- Produces rutas (grupo `pagos-extraordinarios/`, nombre → URL → verbos → método):
  - `extrapayments.index` → `` → GET → `index()`
  - `extrapayments.new` → `new/` → GET → `form()`
  - `extrapayments.edit` → `<int:id>/edit/` → GET → `form($id)`
  - `extrapayments.save` → `save/` → POST → `save()` (JSON; alta o reenvío si trae `id`)
  - `extrapayments.detail` → `<int:id>/` → GET → `detail($id)`
  - `extrapayments.cancel` → `<int:id>/cancel/` → POST → `cancel($id)`
  Declarar `new/` y `save/` antes que `<int:id>/`. Sin `alias` (módulo nuevo).
- Controlador: `class ExtraPayments`, constructor `__construct($twig)`; todas las acciones validan `authorized()` y devuelven JSON con `json_output()` o render Twig. Un usuario sin 110 solo ve/edita sus propias solicitudes (403 si no).

- [ ] **Step 1: Write the failing test** `tests/ExtraPaymentsRoutesTest.php` usando `check_routes()` de `tests/support.php` con las 6 rutas (misma estructura que `tests/RoutesFase1Test.php`; cargar el Router igual que ese archivo).
- [ ] **Step 2:** `php tests/ExtraPaymentsRoutesTest.php` → FALLA.
- [ ] **Step 3:** Crear `routes/extrapayments.php` con las 6 rutas y `permission: 106` donde aplique (detail también acepta 107–110: validarlo en el controlador, no en la ruta).
- [ ] **Step 4:** `php tests/ExtraPaymentsRoutesTest.php` → "Todo bien"; `php tools/routes.php lint` sin errores nuevos.
- [ ] **Step 5:** Implementar `index()` (Mis solicitudes: folio, fecha, tipo, total, estatus con badge, acciones), `form()` (encabezado: empresa por renglón, motivo con radio, "Otros" muestra input; renglones dinámicos con total calculado en JS; tipo individual oculta "agregar renglón"), `save()` (arma `$header/$lines`, `validate_request`, `create` o `update`), `detail()` (encabezado, renglones, línea de tiempo de firmas, historial de auditoría) y `cancel()`. Área = `DepartamentosModel::nombre_de_usuario($userId)`; nombre = `$_SESSION['tg_user']`.
- [ ] **Step 6:** Verificación manual en navegador (el usuario levanta el servidor): crear individual y múltiple, editar tras rechazo, intentar abrir una solicitud ajena → 403.
- [ ] **Step 7:** Commit.

---

### Task 5: Bandejas de aprobación, pago y correos

**Files:**
- Modify: `_assets/controllers/extrapayments.php`, `routes/extrapayments.php`, `_assets/js/extrapayments.js`, `tests/ExtraPaymentsRoutesTest.php`
- Create: `views/extrapayments/inbox.html`, `views/extrapayments/modals/decision.html`, `modals/pay.html`

**Interfaces:**
- Produces rutas: `extrapayments.inbox` `inbox/<str:stage>/` GET → `inbox($stage)` con `stage` ∈ `cxp|admon|tesoreria`; `extrapayments.decision_modal` `<int:id>/decision-modal/` GET; `extrapayments.approve` `<int:id>/approve/` POST; `extrapayments.reject` `<int:id>/reject/` POST; `extrapayments.pay_modal` `<int:id>/pay-modal/` GET; `extrapayments.pay` `<int:id>/pay/` POST. Declarar `inbox/...` antes que `<int:id>/`.
- Produces: `private function notify(int $id, string $evento, string $asunto): void` en el controlador (usa `PaymentNotificationRecipientsModel::get_active_emails` y `send_mail_with_fallback`; un fallo de correo se registra pero no revierte la transición).

- [ ] **Step 1:** Ampliar `tests/ExtraPaymentsRoutesTest.php` con las 6 rutas nuevas; ver FALLA; declararlas en `routes/extrapayments.php`; ver "Todo bien".
- [ ] **Step 2:** `inbox($stage)`: exige 107/108/109 según stage; lista `list_by_status` (1, 2 o 3) con botón que abre el modal por fetch (shell fijo + parcial, `.html(content)`; el método hace `echo` del render sin layout).
- [ ] **Step 3:** `approve`/`reject`: re-validan `ExtraPaymentWorkflow::required_permission(status actual)` contra `authorized()`; responden JSON; tras `approve` llaman `notify` (evento del siguiente paso: 1→2 `pago_extra_admon`, 2→3 `pago_extra_tesoreria`); tras `reject` notifican al solicitante (correo del usuario).
- [ ] **Step 4:** `pay`: exige 109 y status 3; cuenta bancaria desde `CuentasBancariasModel` activas; llama `register_payment`.
- [ ] **Step 5:** Verificación manual con tres usuarios de prueba (107, 108, 109): flujo completo, rechazo y reenvío, doble clic en Aprobar (segundo no avanza).
- [ ] **Step 6:** Commit.

---

### Task 6: Adjuntos

**Files:**
- Create: `_assets/models/ExtraPaymentDocumentsModel.php`
- Modify: controlador, rutas, `detail.html`, `form.html`

**Interfaces:**
- Produces (`ExtraPaymentDocumentsModel`): consts `UPLOAD_BASE='_assets/uploads/pagos_extraordinarios/'`, `MAX_SIZE=10*1024*1024`, `ALLOWED_EXT=['pdf','jpg','jpeg','png','xml']`; `get_by_request(int $requestId): array`, `get_by_id(int $id): ?array`, `upload(int $requestId, string $kind, array $file, int $userId): array` (`['success','id'?,'message']`), `soft_delete(int $id, int $userId): bool` — mismo patrón que `TarjetasCreditoDocumentosModel`.
- Produces rutas: `extrapayments.doc_upload` `<int:id>/documents/` POST; `extrapayments.doc_view` `documents/<int:doc_id>/` GET; `extrapayments.doc_delete` `documents/<int:doc_id>/delete/` POST. Declarar `documents/...` antes de `<int:id>/`.

- [ ] **Step 1:** Rutas en la prueba de rutas (FALLA → declarar → pasa).
- [ ] **Step 2:** Modelo y acciones; `doc_view` valida con `realpath` que el archivo esté bajo `UPLOAD_BASE` (404 si no) y que el usuario pueda ver la solicitud; subir permitido al solicitante en estatus 1/9 y a Tesorería (`kind='comprobante'`) en estatus 3/4.
- [ ] **Step 3:** UI de adjuntos en `form` y `detail`. Verificación manual: subir PDF y XML, ver, eliminar; archivo `.exe` rechazado.
- [ ] **Step 4:** Commit.

---

### Task 7: Impresión y reporte

**Files:**
- Modify: controlador, rutas, `tests/ExtraPaymentsRoutesTest.php`
- Create: `views/extrapayments/print.html`, `report.html`

**Interfaces:**
- Produces rutas: `extrapayments.print` `<int:id>/print/` GET; `extrapayments.report` `report/` GET (`permission: 110`); `extrapayments.report_export` `report/export/` GET. Declarar `report/...` antes de `<int:id>/`.

- [ ] **Step 1:** Rutas en la prueba (FALLA → pasa).
- [ ] **Step 2:** `print`: HTML imprimible (`@media print`) con el layout del Excel: fecha, razón social/empresa, área, solicitante, motivo marcado con X, tabla de renglones (o campos individuales), total, y tres bloques de firma: VoBo CxP, Autorización, Autorización Dir. de Admón y Finanzas, con nombre y fecha de la aprobación real cuando exista.
- [ ] **Step 3:** `report`: filtros `status`, `empresa_cod`, `reason_id`, `desde`, `hasta` sobre `list_all`; `report_export` genera `.xlsx` con PhpSpreadsheet (una fila por renglón: folio, fecha, empresa, centro, proveedor, factura, monto, estatus, fecha de pago).
- [ ] **Step 4:** Verificación manual: imprimir una solicitud pagada y exportar con filtros. Commit.

---

### Task 8: Sidebar, documentación y cierre

**Files:**
- Modify: `views/layouts/sidebar.html` (junto al bloque TESORERÍA, ~línea 815)
- Create: `docs/pagos_extraordinarios_documentacion.html`

- [ ] **Step 1:** Añadir bloque `{% if authorized(106) or authorized(107) or authorized(108) or authorized(109) or authorized(110) %}` con header "PAGOS EXTRAORDINARIOS" y enlaces con `url()`: Mis solicitudes (106), Nueva solicitud (106), VoBo CxP (107), Autorización Admón (108), Pagos pendientes (109), Reporte (110).
- [ ] **Step 2:** Documentación HTML única (flujo, estatus, permisos, tablas, rutas) en `docs/`.
- [ ] **Step 3:** `php tools/routes.php lint`, `php tests/ExtraPaymentWorkflowTest.php`, `php tests/ExtraPaymentsRoutesTest.php` limpios.
- [ ] **Step 4:** Listar al usuario los archivos tocados y qué cambió (deploy manual) y que los correos están en modo pruebas. Commit.
