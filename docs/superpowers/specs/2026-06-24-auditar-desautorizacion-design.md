# Auditar la desautorización de facturas

## Contexto

Tesorería (permiso 68) puede "desautorizar" facturas autorizadas para regresarlas a la cola de autorización, vía `unauthorize_invoices` (controller `_assets/controllers/payment.php:2809` → modelo `_assets/models/PaymentRequestInvoicesModel.php:531`). El modelo, dentro de una transacción: pone `payment_authorized=0`, `authorized_amount=NULL`, `authorized_by=NULL`, `authorized_at=NULL` en cada factura desautorizable (autorizada y sin pagos), y regresa la requisición a PENDIENTE solo si ninguna factura queda autorizada y estaba en estado AUTORIZADO.

**Problema:** la desautorización **no deja ningún rastro**. Existe la tabla `PaymentRequestAuditLog` y el modelo `PaymentRequestAuditLogModel` con operaciones `ADD_INVOICE` y `REMOVE_INVOICE` (usadas al agregar/quitar facturas), pero `unauthorize_invoices` no registra nada. Además, al poner `authorized_by`/`authorized_at` en NULL se borra incluso el dato de quién autorizó originalmente, sin guardarlo en ningún lado. Confirmado con la requisición 1113: 24 facturas desautorizadas correctamente, sin registro alguno de quién ni cuándo.

## Decisión de diseño

Registrar cada desautorización en la tabla `PaymentRequestAuditLog` existente, reutilizando su estructura e infraestructura. **Sin cambios de esquema.** Se añade una operación `UNAUTHORIZE_INVOICE` cuyo `DatosAnteriores` guarda el snapshot de la factura ANTES de limpiarla — capturando `folio`, `authorized_amount`, `authorized_by`, `authorized_at` — de modo que el rastro del autorizador original tampoco se pierde. El registro ocurre dentro de la misma transacción que la desautorización (consistente con add/remove: si el log falla, rollback).

Alcance mínimo confirmado: solo registrar la desautorización. NO se modifica la lista de pagos ni la tabla `payment_request_authorizations` (las firmas por nivel siguen como están).

## Comportamiento

### Modelo de auditoría — `PaymentRequestAuditLogModel.php`
- Nueva constante `OP_UNAUTHORIZE_INVOICE = 'UNAUTHORIZE_INVOICE'`.
- Nuevo método público `log_unauthorize_invoice($payment_id, array $invoice_snapshot, $user_id, $user_name, $accounting_group_id): bool` que reutiliza el `insert_log` privado existente, guardando el snapshot en `DatosAnteriores` (igual patrón que `log_remove_invoice`).

### Modelo de desautorización — `PaymentRequestInvoicesModel::unauthorize_invoices`
- Cambia firma a `unauthorize_invoices(array $invoice_ids, $user_id, $user_name): array` (hoy recibe solo `$invoice_ids`).
- El `SELECT` inicial amplía los campos para el snapshot: además de `id, payment_request_id, folio, payment_authorized, paid_amount`, traer `authorized_amount, authorized_by, authorized_at` y el `accounting_group_id` de la requisición (vía join a `payment_requests` o select aparte).
- Por cada factura que se va a limpiar (las que pasan las validaciones), **antes** del UPDATE que pone NULL, registrar el log con su snapshot llamando a `PaymentRequestAuditLogModel::log_unauthorize_invoice`. El modelo de invoices instancia/usa el modelo de auditoría.
- El registro va dentro de la transacción existente; si el insert del log falla, lanzar excepción → rollback (igual criterio que add/remove invoice).
- Facturas omitidas (ya pagadas o no autorizadas) NO se registran, porque no se desautorizan.

### Controller — `payment.php:2809`
- Pasar `$_SESSION['tg_user']['Id']` y `$_SESSION['tg_user']['Nombre']` a la llamada del modelo (mismo patrón que `log_remove_invoice` en payment.php:3869-3873).

### Vista — `payment_detail.html`, tab Auditoría (~línea 576)
- Hoy el badge de movimiento es un `if ADD_INVOICE / else` binario, que mostraría `UNAUTHORIZE_INVOICE` incorrectamente como "Quitó factura". Cambiar a `if ADD_INVOICE / elseif REMOVE_INVOICE / else` (o `elseif UNAUTHORIZE_INVOICE`) para que la desautorización muestre su propio badge — ej. amarillo/warning con texto "Desautorizó factura".
- El controller (`payment.php:1498-1510`) ya mapea el audit log genéricamente (lee `Operacion`, folio, monto del JSON), así que la nueva operación se carga sin cambios ahí.

## Manejo de errores
- Todo dentro de la transacción de `unauthorize_invoices`; si el log falla, rollback y mensaje de error (la desautorización no se aplica a medias).
- Si una factura no es desautorizable (pagada o no autorizada), se omite y no genera log, igual que hoy.

## Fuera de alcance
- No se modifica la lista de pagos (`payment_list_table` / `get_requests_with_summary`).
- No se toca `payment_request_authorizations` (las firmas por nivel).
- No se añaden tablas ni columnas.
- No se cambia el comportamiento de regreso a PENDIENTE.

## Archivos afectados
- `_assets/models/PaymentRequestAuditLogModel.php` — constante + método `log_unauthorize_invoice`.
- `_assets/models/PaymentRequestInvoicesModel.php` — `unauthorize_invoices`: nueva firma, SELECT ampliado, llamada al log antes del UPDATE.
- `_assets/controllers/payment.php` — `unauthorize_invoices`: pasar user_id/user_name al modelo.
- `views/payment/payment_detail.html` — badge de la nueva operación en el tab Auditoría.

## Verificación
Sin framework de tests (CLAUDE.md). `php -l` en los archivos PHP. Prueba manual: como Tesorería, desautorizar una factura de una requisición, abrir su detalle → tab Auditoría → confirmar que aparece el movimiento "Desautorizó factura" con folio, monto y usuario; verificar en BD que se insertó la fila en `PaymentRequestAuditLog` con `Operacion='UNAUTHORIZE_INVOICE'` y el snapshot en `DatosAnteriores`.
