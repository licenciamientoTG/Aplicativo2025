# Permitir quitar facturas no autorizadas de requisiciones ya agrupadas

## Contexto

`remove_invoice_from_payment()` (`_assets/controllers/payment.php:3896`) usa `assert_payment_not_grouped()` (`payment.php:98`) para bloquear cualquier intento de quitar una factura de una requisición con `accounting_group_id` no nulo (ya incluida en un archivo enviado a Contabilidad), salvo para el usuario hardcodeado `6296`. Este guard se agregó tras un incidente real (ver memoria `auditoria-movimientos-payment-requisiciones`) donde se borró físicamente una factura de una requisición ya cerrada contablemente.

**Problema:** el guard mira el `accounting_group_id` de la requisición completa, no el estado de la factura puntual que se quiere quitar. Caso real: `payment_request_id=1166` (`accounting_group_id=1121`, `status=0` PENDING). Su factura folio 1498 (`id=2211`) tiene `payment_authorized=0` — Tesorería nunca la tocó — pero no se puede quitar porque la requisición ya está agrupada.

## Decisión de diseño

Antes de aplicar `assert_payment_not_grouped()`, consultar `payment_authorized` de la factura puntual que se va a quitar. Si es `0` (nunca autorizada por Tesorería), se omite el guard de agrupación y se permite el borrado — porque no hay ningún monto autorizado/pagado de esa factura que el archivo contable ya cerrado dependa de mantener. Si es `1`, se mantiene el bloqueo actual sin cambios.

El guard de `status == STATUS_PAID` (pago completo ya ejecutado) no se modifica — sigue aplicando igual, independientemente de este cambio.

## Comportamiento

### Controller — `payment.php::remove_invoice_from_payment()`

Orden de validaciones (sustituye el bloque actual de líneas ~3912-3939):

1. Obtener `$payment = $this->PaymentRequestsModel->get_request_by_id($payment_id)` (sin cambios).
2. Si no existe → error "Pago no encontrado" (sin cambios).
3. Si `$payment['status'] == STATUS_PAID` → error "No se pueden modificar pagos ya ejecutados" (sin cambios).
4. **Nuevo:** obtener la factura puntual antes de evaluar el guard de agrupación:
   ```php
   $invoice = $this->paymentRequestInvoicesModel->get_invoice_by_id($invoice_id);
   ```
   (Nuevo método simple en el modelo, o reutilizar uno existente si ya hay un `SELECT ... WHERE id = ?` equivalente — revisar antes de duplicar.)
5. Si `$invoice` no existe o no pertenece a `$payment_id` → error "Factura no encontrada" (nuevo, defensivo).
6. **Modificado:** solo aplicar `assert_payment_not_grouped()` si la factura está autorizada:
   ```php
   if ((int)($invoice['payment_authorized'] ?? 0) === 1) {
       $blockMessage = $this->assert_payment_not_grouped($payment, $user_id);
       if ($blockMessage !== null) {
           echo json_encode(['success' => false, 'message' => $blockMessage]);
           return;
       }
   }
   ```
   Si `payment_authorized == 0`, no se llama `assert_payment_not_grouped()` y el flujo continúa directo al soft-delete, sin importar el valor de `accounting_group_id`.
7. Resto del flujo sin cambios: `remove_invoice_from_payment()` del modelo (soft-delete), `log_remove_invoice` (audit log, ya captura `accounting_group_id` en el snapshot), `recalculate_payment_total()`, `reset_authorizations()`.

### Modelo — `PaymentRequestInvoicesModel.php`

- Si no existe ya un método que devuelva una factura individual con `payment_authorized` por `id`, agregar uno mínimo:
  ```php
  public function get_invoice_by_id($invoice_id): array|false
  {
      $query = "SELECT id, payment_request_id, payment_authorized, paid_amount, folio
                FROM [TG].[dbo].[payment_request_invoices]
                WHERE id = ? AND is_deleted = 0";
      return ($rs = $this->sql->select($query, [$invoice_id])) ? $rs[0] : false;
  }
  ```
- Revisar primero si algún método existente (p.ej. usado en `add_invoice_to_payment` o similar) ya cubre esto antes de crear uno nuevo redundante.

### Sin cambios

- `add_invoice_to_payment()` y `add_invoices_bulk_to_payment()` — siguen su lógica actual de agrupación (agregar facturas a una requisición agrupada ya está permitido por decisión previa).
- Esquema de BD — no se agregan columnas ni tablas.
- Tab Auditoría (`payment_detail.html`) — el badge "Post-agrupación" ya existe y se sigue mostrando para estos borrados (vía `AccountingGroupId` no nulo en el snapshot del log), sirviendo como señal visual para Contabilidad de que una factura no autorizada se quitó después de agrupar.
- El hardcode `user_id !== 6296` en `assert_payment_not_grouped()` — sigue existiendo para el caso en que sí se intente quitar una factura ya autorizada de una requisición agrupada.

## Manejo de errores

- Factura inexistente o de otra requisición → error explícito, no se ejecuta ningún cambio.
- Si la factura está autorizada (`payment_authorized == 1`) y la requisición está agrupada → mismo mensaje de bloqueo actual: "Esta requisición ya fue incluida en un archivo de contabilidad y no puede modificarse. Contacte a Contabilidad."
- El resto de manejo de errores (try/catch general, log de excepción) no cambia.

## Fuera de alcance

- No se modifica el guard de `STATUS_PAID`.
- No se agrega confirmación adicional en el UI para este caso (se evaluó y se descartó — el badge de auditoría ya cubre la trazabilidad).
- No se toca `unauthorize_invoices` ni otros endpoints de facturas.
- No se resuelve el caso 1166 manualmente en BD — se resuelve usando la app una vez aplicado este cambio.

## Archivos afectados

- `_assets/controllers/payment.php` — `remove_invoice_from_payment()`: reordenar validaciones, condicionar `assert_payment_not_grouped()` al estado de la factura puntual.
- `_assets/models/PaymentRequestInvoicesModel.php` — posible método nuevo `get_invoice_by_id()` (verificar si ya existe equivalente antes de agregarlo).

## Verificación

Sin framework de tests (CLAUDE.md). `php -l` en los archivos modificados. Prueba manual:
1. Como usuario distinto de `6296`, abrir `payment_detail/1166`, intentar quitar la factura folio 1498 (`payment_authorized=0`) → debe permitir el borrado y mostrar el mensaje de éxito habitual.
2. Verificar en BD: la fila de `payment_request_invoices` queda con `is_deleted=1`; el total de la requisición se recalculó sin esa factura; aparece un nuevo renglón en `PaymentRequestAuditLog` con `Operacion='REMOVE_INVOICE'` y `AccountingGroupId=1121`.
3. Abrir el tab Auditoría en la vista → confirmar que el movimiento aparece resaltado con el badge "Post-agrupación".
4. Repetir el intento con una factura que sí tenga `payment_authorized=1` en una requisición agrupada → debe seguir bloqueado con el mensaje de Contabilidad.
