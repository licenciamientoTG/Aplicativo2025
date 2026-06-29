# Permitir quitar facturas no autorizadas de requisiciones agrupadas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir quitar (soft-delete) una factura individual de una requisición de pago ya agrupada contablemente (`accounting_group_id` no nulo), siempre que esa factura específica nunca haya sido autorizada por Tesorería (`payment_authorized = 0`).

**Architecture:** Un único endpoint cambia de comportamiento: `payment.php::remove_invoice_from_payment()`. Antes de evaluar el guard `assert_payment_not_grouped()`, se consulta el `payment_authorized` de la factura puntual. El guard de agrupación solo se aplica si la factura está autorizada. El guard de `STATUS_PAID` (pago ya ejecutado) no cambia.

**Tech Stack:** PHP 8, PDO con driver `sqlsrv`, SQL Server (`TG.dbo.payment_request_invoices`, `TG.dbo.payment_requests`). Sin framework de tests — verificación con `php -l` y prueba manual end-to-end.

## Global Constraints

- No se modifica el esquema de BD (no nuevas columnas ni tablas) — spec `docs/superpowers/specs/2026-06-29-remove-invoice-bypass-agrupacion-design.md`.
- El guard `status == STATUS_PAID` permanece sin cambios.
- El hardcode `user_id !== 6296` en `assert_payment_not_grouped()` permanece sin cambios.
- Solo se toca `remove_invoice_from_payment()` (controller) y se agrega un método de lectura en `PaymentRequestInvoicesModel.php`. `add_invoice_to_payment()` / `add_invoices_bulk_to_payment()` no se tocan.
- Mensajes de error existentes ("Pago no encontrado", "No se pueden modificar pagos ya ejecutados", mensaje de Contabilidad) deben mantenerse exactamente igual donde sigan aplicando.

---

## File Structure

- **Modify:** `_assets/models/PaymentRequestInvoicesModel.php` — agregar método público `get_invoice_by_id(int $invoice_id): array|false` que devuelve `id, payment_request_id, payment_authorized, folio` de una factura no eliminada.
- **Modify:** `_assets/controllers/payment.php` — en `remove_invoice_from_payment()` (líneas 3896-3972 actuales), insertar la consulta de la factura puntual y condicionar el guard de agrupación a `payment_authorized`.

No se crean archivos nuevos. No hay tests automatizados en el proyecto (confirmado en CLAUDE.md), así que la verificación de cada tarea es `php -l` + inspección manual de la lógica; la prueba funcional completa ocurre en la Tarea 3.

---

### Task 1: Agregar `get_invoice_by_id()` al modelo de facturas

**Files:**
- Modify: `_assets/models/PaymentRequestInvoicesModel.php` (agregar método nuevo; insertarlo junto a `remove_invoice_from_payment()`, antes de la línea `public function remove_invoice_from_payment($invoice_id, $deleted_by = null) : array {` que hoy está en la línea 1819)

**Interfaces:**
- Produces: `PaymentRequestInvoicesModel::get_invoice_by_id(int $invoice_id): array|false` — devuelve un array asociativo con claves `id`, `payment_request_id`, `payment_authorized`, `folio` si la factura existe y no está soft-deleted; `false` si no existe.

- [ ] **Step 1: Leer el contexto exacto de inserción**

Confirmar que la línea previa a `remove_invoice_from_payment` en el modelo es el cierre de la función anterior. Ejecutar:

```bash
grep -n "function remove_invoice_from_payment\|function add_invoice_to_payment" _assets/models/PaymentRequestInvoicesModel.php
```

Expected: la línea de `public function remove_invoice_from_payment($invoice_id, $deleted_by = null) : array {` debe aparecer (era la línea 1819 al momento de este plan; si el número difiere, usar el resultado real de este grep como referencia).

- [ ] **Step 2: Insertar el nuevo método**

Insertar inmediatamente antes de la firma `public function remove_invoice_from_payment($invoice_id, $deleted_by = null) : array {`:

```php
    /**
     * Trae una factura puntual (no eliminada) por su id, con su estado de
     * autorización. Usado para decidir si el guard de requisición agrupada
     * aplica a esta factura específica.
     */
    public function get_invoice_by_id($invoice_id): array|false
    {
        $query = "
            SELECT id, payment_request_id, payment_authorized, folio
            FROM [TG].[dbo].[payment_request_invoices]
            WHERE id = ? AND is_deleted = 0
        ";

        return ($rs = $this->sql->select($query, [$invoice_id])) ? $rs[0] : false;
    }

```

- [ ] **Step 3: Verificar sintaxis PHP**

```bash
php -l _assets/models/PaymentRequestInvoicesModel.php
```

Expected: `No syntax errors detected in _assets/models/PaymentRequestInvoicesModel.php`

- [ ] **Step 4: Commit**

```bash
git add _assets/models/PaymentRequestInvoicesModel.php
git commit -m "Agregar get_invoice_by_id() a PaymentRequestInvoicesModel"
```

---

### Task 2: Condicionar el guard de agrupación al estado de autorización de la factura

**Files:**
- Modify: `_assets/controllers/payment.php:3896-3972` (función `remove_invoice_from_payment()`)

**Interfaces:**
- Consumes: `PaymentRequestInvoicesModel::get_invoice_by_id(int $invoice_id): array|false` (Task 1).
- Consumes (sin cambios): `PaymentRequestsModel::get_request_by_id($id): array|false`, `Payment::assert_payment_not_grouped(array $payment, int $user_id): ?string`, `PaymentRequestInvoicesModel::remove_invoice_from_payment($invoice_id, $deleted_by = null): array`.

- [ ] **Step 1: Reemplazar el bloque de validación en el controller**

Reemplazar el método completo actual (líneas 3896-3972 de `_assets/controllers/payment.php`):

```php
    public function remove_invoice_from_payment()
    {
        header('Content-Type: application/json');

        try {
            $invoice_id = $_POST['invoice_id'] ?? 0;
            $payment_id = $_POST['payment_id'] ?? 0;

            if (!$invoice_id || !$payment_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Datos inválidos'
                ]);
                return;
            }

            // Verificar que el pago existe y no está pagado
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);

            if (!$payment) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Pago no encontrado'
                ]);
                return;
            }

            if ($payment['status'] == PaymentRequestsModel::STATUS_PAID) {
                echo json_encode([
                    'success' => false,
                    'message' => 'No se pueden modificar pagos ya ejecutados'
                ]);
                return;
            }

            $invoice = $this->paymentRequestInvoicesModel->get_invoice_by_id($invoice_id);

            if (!$invoice || (int)$invoice['payment_request_id'] !== (int)$payment_id) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Factura no encontrada'
                ]);
                return;
            }

            $user_id = (int)($_SESSION['tg_user']['Id'] ?? 0);

            // El guard de requisición agrupada solo aplica si Tesorería ya
            // autorizó esta factura puntual. Si nunca se autorizó, no hay
            // monto comprometido en el archivo contable que proteger.
            if ((int)($invoice['payment_authorized'] ?? 0) === 1) {
                $blockMessage = $this->assert_payment_not_grouped($payment, $user_id);
                if ($blockMessage !== null) {
                    echo json_encode([
                        'success' => false,
                        'message' => $blockMessage
                    ]);
                    return;
                }
            }

            // Quitar la factura (soft-delete)
            $result = $this->paymentRequestInvoicesModel->remove_invoice_from_payment($invoice_id, $user_id);

            if (!$result['success']) {
                echo json_encode($result);
                return;
            }

            $user_name = $_SESSION['tg_user']['Nombre'] ?? null;
            $this->PaymentRequestAuditLogModel->log_remove_invoice(
                $payment_id, $result['invoice_snapshot'] ?? [], $user_id, $user_name,
                $payment['accounting_group_id'] ?? null
            );

            // Recalcular total
            $this->PaymentRequestsModel->recalculate_payment_total($payment_id);

            // Reiniciar autorizaciones
            $this->PaymentRequestsModel->reset_authorizations($payment_id);

            echo json_encode([
                'success' => true,
                'message' => 'Factura eliminada correctamente. Las autorizaciones han sido reiniciadas.'
            ]);
        } catch (Exception $e) {
            error_log("Error en remove_invoice_from_payment: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }
```

- [ ] **Step 2: Verificar sintaxis PHP**

```bash
php -l _assets/controllers/payment.php
```

Expected: `No syntax errors detected in _assets/controllers/payment.php`

- [ ] **Step 3: Commit**

```bash
git add _assets/controllers/payment.php
git commit -m "Permitir quitar facturas no autorizadas de requisiciones ya agrupadas"
```

---

### Task 3: Verificación manual end-to-end

**Files:** ninguno (solo pruebas manuales contra la app corriendo).

**Interfaces:** ninguna nueva — valida el comportamiento conjunto de Task 1 + Task 2.

- [ ] **Step 1: Confirmar el estado actual del caso real (payment_request 1166)**

El usuario ya confirmó estos datos en la conversación de diseño:
- `payment_requests.id=1166`, `status=0` (PENDING), `accounting_group_id=1121`.
- `payment_request_invoices.id=2211`, `folio=1498`, `payment_request_id=1166`, `payment_authorized=0`, `is_deleted=0`.

No se requiere volver a consultar BD si estos valores no han cambiado desde la última verificación.

- [ ] **Step 2: Probar el caso que debe permitirse ahora**

En el navegador, como un usuario que NO sea `user_id=6296`, ir a `/payment/payment_detail/1166`, localizar la factura folio `1498` y usar la acción de quitar/eliminar factura.

Expected:
- Respuesta JSON con `"success": true` y mensaje "Factura eliminada correctamente. Las autorizaciones han sido reiniciadas."
- La factura folio 1498 desaparece de la lista de facturas activas de la requisición.
- El total mostrado en el encabezado de la requisición se recalcula sin el monto de esa factura.

- [ ] **Step 3: Verificar en BD el soft-delete y el audit log**

Confirmar (vía la herramienta de consulta que el usuario use normalmente, p.ej. SSMS):

```sql
SELECT id, folio, is_deleted, deleted_at, deleted_by
FROM TG.dbo.payment_request_invoices
WHERE id = 2211;

SELECT TOP 1 *
FROM TG.dbo.PaymentRequestAuditLog
WHERE PaymentRequestId = 1166 AND InvoiceId = 2211
ORDER BY Fecha DESC;
```

Expected: `is_deleted = 1`, `deleted_at` con la fecha/hora de la prueba, `deleted_by` con el id del usuario que probó. En `PaymentRequestAuditLog`: una fila nueva con `Operacion='REMOVE_INVOICE'` y `AccountingGroupId=1121`.

- [ ] **Step 4: Verificar el badge "Post-agrupación" en el tab Auditoría**

En la misma vista `/payment/payment_detail/1166`, abrir el tab "Auditoría" y confirmar que el movimiento de la factura 1498 aparece resaltado en rojo con el badge "Post-agrupación" (comportamiento ya existente, ver memoria `auditoria-movimientos-payment-requisiciones`).

- [ ] **Step 5: Probar el caso que debe seguir bloqueado (regresión)**

Elegir o crear una factura con `payment_authorized = 1` dentro de una requisición con `accounting_group_id` no nulo. Intentar quitarla desde la vista, como un usuario que NO sea `user_id=6296`.

Expected: respuesta JSON con `"success": false` y mensaje "Esta requisición ya fue incluida en un archivo de contabilidad y no puede modificarse. Contacte a Contabilidad." La factura permanece activa (`is_deleted = 0` sin cambios).

- [ ] **Step 6: Probar el guard de STATUS_PAID (regresión)**

Elegir una requisición con `status = STATUS_PAID` (2) e intentar quitar cualquiera de sus facturas.

Expected: respuesta JSON con `"success": false` y mensaje "No se pueden modificar pagos ya ejecutados", sin importar el valor de `payment_authorized` de la factura.

- [ ] **Step 7: Confirmar que no hay regresión en agregar facturas**

Sin cambios de código en `add_invoice_to_payment()` / `add_invoices_bulk_to_payment()`, pero como verificación rápida: en una requisición agrupada, agregar una factura nueva vía el flujo existente y confirmar que sigue funcionando igual que antes (sin bloqueo), ya que ese comportamiento no debía tocarse.

Expected: la factura se agrega correctamente, igual que antes de este cambio.
