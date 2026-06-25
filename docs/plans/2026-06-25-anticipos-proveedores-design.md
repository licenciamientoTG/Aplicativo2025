# Diseño: Flujo completo de Anticipos a Proveedores

Fecha: 2026-06-25

## Contexto / diagnóstico

El flujo de "anticipo" (pago sin factura, `payment_requests.tipo = 1`) en `_assets/controllers/payment.php` está incompleto en 3 puntos:

1. **`anticipo_detail()` renderiza `anticipo_detail.html`, que es una copia pegada de `payment_detail.html`** (la primera línea del archivo dice literalmente `{# payment_detail.html #}`). Espera variables (`invoices`, `summary.total_invoices`, `transactions`) que el controlador no pasa para un anticipo. También muestra un flujo de autorización de 3 niveles (Abastos/Admin-Finanzas/Tesorería) que **ya no es el modelo vigente**: `PaymentRequestAuthorizationsModel::AUTHORIZATION_SEQUENCE` solo tiene un nivel (Tesorería, permiso 68). El flujo real es: Abastos crea la requisición, Tesorería autoriza y ejecuta el pago.

2. **No existe forma de marcar un anticipo como pagado ni subirle comprobante.** El pipeline de ejecución/comprobante (`authorize_payment_execution`, `execute_authorized_payments`, `process_bulk_payment`, `registrar_lote_y_pago`) opera exclusivamente sobre `payment_request_invoices` (facturas). Un anticipo no tiene facturas, así que nunca llega a `STATUS_PAID` con comprobante por ese camino.

3. **El modal de ligar anticipo a facturas (`aplicar_anticipo.html`) es un esqueleto sin terminar** (sin `<tbody>`, sin JS). La función `aplicarAnticipo()` en `payment.js` ni lo abre — solo redirige a `anticipo_detail`. El backend para esto **sí existe y funciona**: `PaymentRequestsModel::register_anticipo_applications()` + `apply_anticipo_to_invoices()` insertan correctamente en `TG.dbo.anticipo_invoice_applications` y validan saldo.

   Adicionalmente, hay código JS muerto (`cargarAnticiposDisponibles`, `mostrarAnticiposDisponibles`, `calcularTotalConAnticipos`, líneas ~3056-3172 de `payment.js`) que intenta descontar anticipos al crear un pago nuevo, pero llama a un endpoint `/payment/get_anticipos_disponibles` que no existe en el controlador. Ese código queda fuera de alcance de este diseño (no se implementa ni se conecta).

## Flujo objetivo

```
Abastos crea anticipo (sin facturas) — ya funciona, sin cambios
        ↓
Tesorería autoriza (permiso 68) — mismo mecanismo que pagos normales, ya funciona
        ↓
Tesorería ejecuta el pago: crea payment_batch + payment_transaction (invoice_id=NULL) + sube comprobante — NUEVO
        ↓
Anticipo queda STATUS_PAID con comprobante adjunto
        ↓
(en cualquier momento posterior, mientras haya saldo) Abastos/Tesorería liga el anticipo a 1+ facturas
del mismo proveedor, aplicando montos parciales — backend ya existe, falta UI
```

## Decisiones de diseño

- **Comprobante de anticipo**: se reutiliza el patrón de lotes existente (`PaymentBatchesModel` + `PaymentTransactionDocumentsModel`) en vez de agregar columnas nuevas a `payment_requests`. Se inserta una `payment_transaction` con `invoice_id = NULL` y `payment_request_id = anticipo_id` ligada al lote — el esquema actual ya lo permite sin cambios (ni `payment_transactions.invoice_id` ni `payment_transaction_documents.transaction_id` tienen restricción que lo impida; `insert_transaction()` ya acepta `invoice_id = null`).
- **Backend de pago del anticipo**: método nuevo y aislado (`pay_anticipo()`), NO se modifica `registrar_lote_y_pago()`, para no arriesgar el flujo de pagos normales con facturas que ya está en producción.
- **Ligado a facturas**: manual, parcial, solo facturas del mismo proveedor del anticipo. Un anticipo puede cubrir varias facturas y puede dejar saldo sin aplicar. No hay aplicación automática al crear un pago nuevo (eso queda fuera de alcance).
- **Sin cambios de esquema de BD.**
- **Sin notificación por correo** al aplicar anticipo a facturas (sí existe ya para la creación del anticipo, eso no cambia).

## Cambios a implementar

### Backend

1. **`pay_anticipo()`** (nuevo método en `_assets/controllers/payment.php`)
   - Input: `multipart/form-data` con `anticipo_id`, `fecha_pago`, `referencia`, `observaciones`, `comprobante` (archivo).
   - Valida: `authorized(68)`; anticipo existe, `tipo == 1`, `status == PaymentRequestsModel::STATUS_AUTHORIZED`.
   - Lógica (transacción SQL):
     1. `PaymentBatchesModel::create()` — `monto_total = anticipo.monto_total`, `provider_cod`, `emp_cod`, `fecha_pago`, `referencia`, `observaciones`, `banco` (mismo cálculo `banco_por_emp_cod()` ya existente).
     2. `PaymentTransactionsModel::insert_transaction()` — `payment_request_id = anticipo_id`, `invoice_id = null`, `payment_amount = monto_total`, `batch_id`.
     3. `PaymentTransactionDocumentsModel::upload()` — comprobante ligado a esa transacción y al batch.
     4. `PaymentRequestsModel::update_request_status($anticipo_id, STATUS_PAID, ...)`.
   - Output JSON: `success`, `message`, `batch_id`.

2. **`get_invoices_pendientes_by_provider($provider_cod)`** (nuevo método en controlador + query en `PaymentRequestInvoicesModel`)
   - Devuelve facturas con saldo pendiente (`status != PAID`, `is_deleted = 0`) de requisiciones (`payment_requests`) de ese `provider_cod`, con folio, monto, saldo, UUID.

3. Sin cambios en `apply_anticipo_to_invoices()`, `register_anticipo_applications()`, `authorize_payment()`, `create_anticipo()`.

### Frontend

4. **Reescribir `views/payment/anticipo_detail.html`** desde cero (eliminar el copy-paste de `payment_detail.html`):
   - Cabecera: ID, proveedor, empresa, monto, fecha solicitud, usuario, comentario, fecha de pago deseada.
   - Bloque de autorización (un solo nivel Tesorería): botón "Autorizar como Tesorería" si aplica, o info de quién/cuándo autorizó.
   - Bloque de pago (visible si `status == AUTHORIZED`): botón que abre modal "Registrar pago del anticipo" (fecha, referencia, observaciones, input file comprobante).
   - Bloque de comprobante (visible si `status == PAID`): ver/descargar el archivo adjunto.
   - Resumen financiero: monto original, total aplicado, saldo disponible (usa `summary` ya devuelto por el controlador).
   - Tabla de aplicaciones a facturas (usa `aplicaciones` ya devuelto por el controlador) + botón "Aplicar a facturas" si `status == PAID` y `saldo_disponible > 0`.
   - Historial de autorizaciones (tabla simple, ya existe el dato).
   - Se elimina todo bloque de "facturas incluidas"/selección de facturas para pago.

5. **Nuevo modal "Registrar pago del anticipo"** — formulario simple (fecha, referencia, observaciones, file comprobante) que llama a `pay_anticipo()`.

6. **Completar `views/payment/modals/aplicar_anticipo.html` + JS nuevo**:
   - Carga facturas pendientes del proveedor vía `get_invoices_pendientes_by_provider`.
   - Tabla con checkbox + folio + monto + saldo + input "monto a aplicar" (`max = min(saldo_factura, saldo_restante_anticipo)`).
   - Buscador por folio/UUID (el input ya existe en el HTML).
   - Validación en vivo: suma de montos aplicados no excede saldo disponible — deshabilita "Confirmar" si se excede.
   - Al confirmar, llama a `apply_anticipo_to_invoices()` (sin cambios).
   - Tras éxito, refresca la sección de aplicaciones y el resumen de saldo sin recargar toda la página.

7. **Limpieza en `payment.js`**: eliminar código muerto `cargarAnticiposDisponibles`, `mostrarAnticiposDisponibles`, `calcularTotalConAnticipos` y la llamada a `/payment/get_anticipos_disponibles` (endpoint inexistente, fuera de alcance).

## Fuera de alcance

- Aplicación automática de anticipos al crear un pago nuevo con facturas (lo que el JS muerto intentaba hacer).
- Notificación por correo al aplicar anticipo a facturas.
- Cambios de esquema de base de datos.
