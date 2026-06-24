# Auditar la desautorización de facturas - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Registrar cada desautorización de factura en la tabla `PaymentRequestAuditLog` (operación `UNAUTHORIZE_INVOICE`) con el snapshot de la factura antes de limpiarla, y mostrarla en el tab Auditoría del detalle de pago.

**Architecture:** Se añade un método de log al modelo de auditoría existente, se amplía `unauthorize_invoices` para capturar el snapshot y registrar el log dentro de su transacción (cambiando su firma para recibir user_id/user_name), el controller pasa esos datos, y la vista del tab Auditoría distingue la nueva operación con su propio badge. Sin cambios de esquema.

**Tech Stack:** PHP MVC (clases Model), PDO/sqlsrv, Twig. Sin framework de tests — verificación con `php -l` y prueba manual.

## Global Constraints

- Sin cambios de esquema — reutilizar la tabla `PaymentRequestAuditLog` tal cual.
- Reutilizar el `insert_log` privado existente en `PaymentRequestAuditLogModel`; no duplicar el INSERT.
- El registro del log va DENTRO de la transacción de `unauthorize_invoices`; si el log falla, lanzar excepción → rollback.
- El snapshot debe capturarse ANTES de poner `authorized_amount/authorized_by/authorized_at` en NULL (para no perder el autorizador original).
- Facturas omitidas (pagadas o no autorizadas) NO generan log.
- No tocar la lista de pagos, ni `payment_request_authorizations`, ni el comportamiento de regreso a PENDIENTE.
- Patrón de usuario: `$_SESSION['tg_user']['Id']` y `$_SESSION['tg_user']['Nombre']` (igual que log_remove_invoice en payment.php:3869).
- Solo Tesorería (68) ejecuta unauthorize_invoices — ya validado en el controller, no se toca.

---

### Task 1: Método `log_unauthorize_invoice` en el modelo de auditoría

**Files:**
- Modify: `_assets/models/PaymentRequestAuditLogModel.php`

**Interfaces:**
- Consumes: el método privado existente `insert_log($payment_id, $invoice_id, $operacion, $user_id, $user_name, $datos_anteriores, $datos_nuevos, $accounting_group_id): bool`.
- Produces: constante `PaymentRequestAuditLogModel::OP_UNAUTHORIZE_INVOICE = 'UNAUTHORIZE_INVOICE'` y método `log_unauthorize_invoice($payment_id, array $invoice_snapshot, $user_id, $user_name, $accounting_group_id): bool`. Lo consume Task 2.

- [ ] **Step 1: Agregar la constante**

En `_assets/models/PaymentRequestAuditLogModel.php`, localizar las constantes existentes (líneas 5-6):

```php
    const OP_ADD_INVOICE    = 'ADD_INVOICE';
    const OP_REMOVE_INVOICE = 'REMOVE_INVOICE';
```

Reemplazarlas por:

```php
    const OP_ADD_INVOICE         = 'ADD_INVOICE';
    const OP_REMOVE_INVOICE      = 'REMOVE_INVOICE';
    const OP_UNAUTHORIZE_INVOICE = 'UNAUTHORIZE_INVOICE';
```

- [ ] **Step 2: Agregar el método `log_unauthorize_invoice`**

Insertar este método inmediatamente después del cierre de `log_add_invoice` (después de su `}` en la línea 49, antes de `private function insert_log`):

```php
    /**
     * Registra la desautorización de una factura (Tesorería la regresa a la cola).
     * Guarda el snapshot de la factura ANTES de limpiar sus campos de autorización,
     * de modo que quede el rastro del autorizador original (authorized_by/authorized_at).
     * @param int         $payment_id
     * @param array       $invoice_snapshot  Fila de payment_request_invoices antes de poner NULL
     * @param int|null    $user_id           Usuario que desautoriza
     * @param string|null $user_name
     * @param int|null    $accounting_group_id
     */
    public function log_unauthorize_invoice($payment_id, array $invoice_snapshot, $user_id, $user_name, $accounting_group_id): bool {
        return $this->insert_log(
            $payment_id,
            $invoice_snapshot['id'] ?? null,
            self::OP_UNAUTHORIZE_INVOICE,
            $user_id,
            $user_name,
            json_encode($invoice_snapshot, JSON_UNESCAPED_UNICODE),
            null,
            $accounting_group_id
        );
    }
```

- [ ] **Step 3: Verificar sintaxis**

Run: `cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp" && php -l _assets/models/PaymentRequestAuditLogModel.php`
Expected: `No syntax errors detected in _assets/models/PaymentRequestAuditLogModel.php`

- [ ] **Step 4: Commit**

```bash
git add _assets/models/PaymentRequestAuditLogModel.php
git commit -m "Agregar log_unauthorize_invoice al modelo de auditoria de pagos"
```

---

### Task 2: Registrar el log en `unauthorize_invoices` (modelo)

**Files:**
- Modify: `_assets/models/PaymentRequestInvoicesModel.php:531-629`

**Interfaces:**
- Consumes: `PaymentRequestAuditLogModel::log_unauthorize_invoice($payment_id, array $invoice_snapshot, $user_id, $user_name, $accounting_group_id): bool` (Task 1).
- Produces: nueva firma `unauthorize_invoices(array $invoice_ids, $user_id = null, $user_name = null): array`. La consume Task 3.

- [ ] **Step 1: Leer el método actual para confirmar contexto**

Abrir `_assets/models/PaymentRequestInvoicesModel.php` y confirmar que `unauthorize_invoices` (línea 531) tiene la firma `public function unauthorize_invoices(array $invoice_ids) : array {` y que el SELECT inicial (líneas 544-549) trae `id, payment_request_id, folio, payment_authorized, ISNULL(paid_amount,0) AS paid_amount`. Si difiere, adaptar los pasos siguientes al código real.

- [ ] **Step 2: Cambiar la firma para recibir usuario**

Reemplazar:

```php
    public function unauthorize_invoices(array $invoice_ids) : array {
```

por:

```php
    public function unauthorize_invoices(array $invoice_ids, $user_id = null, $user_name = null) : array {
```

- [ ] **Step 3: Ampliar el SELECT para capturar el snapshot completo**

Reemplazar el SELECT inicial (el bloque dentro de `$rows = $this->sql->select("..."`):

```php
            $rows = $this->sql->select("
                SELECT id, payment_request_id, folio, payment_authorized,
                       ISNULL(paid_amount, 0) AS paid_amount
                FROM [TG].[dbo].[payment_request_invoices]
                WHERE id IN ($placeholders) AND is_deleted = 0
            ", $invoice_ids);
```

por:

```php
            $rows = $this->sql->select("
                SELECT pri.id, pri.payment_request_id, pri.folio, pri.payment_authorized,
                       pri.authorized_amount, pri.authorized_by, pri.authorized_at,
                       ISNULL(pri.paid_amount, 0) AS paid_amount,
                       pr.accounting_group_id
                FROM [TG].[dbo].[payment_request_invoices] pri
                LEFT JOIN [TG].[dbo].[payment_requests] pr ON pr.id = pri.payment_request_id
                WHERE pri.id IN ($placeholders) AND pri.is_deleted = 0
            ", $invoice_ids);
```

- [ ] **Step 4: Registrar el log de cada factura desautorizable antes del UPDATE**

Localizar el bloque que decide qué facturas limpiar (líneas 559-570). Actualmente:

```php
            foreach ($rows as $r) {
                if ((int)$r['payment_authorized'] !== 1) {
                    $errores[] = "Factura {$r['folio']}: no está autorizada";
                    continue;
                }
                if ((float)$r['paid_amount'] > 0) {
                    $errores[] = "Factura {$r['folio']}: tiene pagos registrados y no puede desautorizarse";
                    continue;
                }
                $a_limpiar[] = (int)$r['id'];
                $payment_req_ids[(int)$r['payment_request_id']] = true;
            }
```

Reemplazarlo por (añade el registro del log con el snapshot, dentro de la transacción; si falla, lanza excepción → rollback):

```php
            $auditLog = new PaymentRequestAuditLogModel();

            foreach ($rows as $r) {
                if ((int)$r['payment_authorized'] !== 1) {
                    $errores[] = "Factura {$r['folio']}: no está autorizada";
                    continue;
                }
                if ((float)$r['paid_amount'] > 0) {
                    $errores[] = "Factura {$r['folio']}: tiene pagos registrados y no puede desautorizarse";
                    continue;
                }

                // Registrar auditoría con el snapshot ANTES de limpiar la autorización.
                $snapshot = [
                    'id'                => (int)$r['id'],
                    'folio'             => $r['folio'],
                    'authorized_amount' => $r['authorized_amount'],
                    'authorized_by'     => $r['authorized_by'],
                    'authorized_at'     => $r['authorized_at'],
                ];
                $logged = $auditLog->log_unauthorize_invoice(
                    (int)$r['payment_request_id'],
                    $snapshot,
                    $user_id,
                    $user_name,
                    $r['accounting_group_id'] ?? null
                );
                if (!$logged) {
                    throw new Exception("No se pudo registrar la auditoría de la factura {$r['folio']}");
                }

                $a_limpiar[] = (int)$r['id'];
                $payment_req_ids[(int)$r['payment_request_id']] = true;
            }
```

- [ ] **Step 5: Verificar sintaxis**

Run: `cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp" && php -l _assets/models/PaymentRequestInvoicesModel.php`
Expected: `No syntax errors detected in _assets/models/PaymentRequestInvoicesModel.php`

- [ ] **Step 6: Confirmar que PaymentRequestAuditLogModel es cargable desde este modelo**

Run: `cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp" && grep -rn "class PaymentRequestAuditLogModel\|require.*PaymentRequestAuditLogModel\|spl_autoload\|autoload" _assets/models/PaymentRequestAuditLogModel.php index.php _assets/classes/header.class.php | head`
Expected: confirmar cómo se cargan los modelos (autoload o require). Si los modelos se autocargan (como `new PaymentRequestsModel()` ya se usa dentro de este mismo archivo — buscar `new PaymentRequestsModel` en PaymentRequestInvoicesModel.php para confirmar el patrón), entonces `new PaymentRequestAuditLogModel()` funciona igual. Si NO hay autoload y se requiere include explícito, añadir el `require_once` correspondiente siguiendo el patrón del archivo. Documentar en el reporte cuál fue el caso.

- [ ] **Step 7: Commit**

```bash
git add _assets/models/PaymentRequestInvoicesModel.php
git commit -m "Registrar auditoria UNAUTHORIZE_INVOICE al desautorizar facturas"
```

---

### Task 3: Pasar usuario desde el controller

**Files:**
- Modify: `_assets/controllers/payment.php:2809-2836` (método `unauthorize_invoices`)

**Interfaces:**
- Consumes: `PaymentRequestInvoicesModel::unauthorize_invoices(array $invoice_ids, $user_id, $user_name): array` (Task 2).
- Produces: nada (endpoint final).

- [ ] **Step 1: Leer el método actual**

Confirmar que el controller `unauthorize_invoices` (payment.php:2809) llama hoy `$this->paymentRequestInvoicesModel->unauthorize_invoices($invoice_ids);` (línea 2830) sin pasar usuario.

- [ ] **Step 2: Pasar user_id y user_name**

Reemplazar:

```php
            $result = $this->paymentRequestInvoicesModel->unauthorize_invoices($invoice_ids);
            json_output($result);
```

por:

```php
            $user_id = $_SESSION['tg_user']['Id'] ?? null;
            $user_name = $_SESSION['tg_user']['Nombre'] ?? null;
            $result = $this->paymentRequestInvoicesModel->unauthorize_invoices($invoice_ids, $user_id, $user_name);
            json_output($result);
```

- [ ] **Step 3: Verificar sintaxis**

Run: `cd "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp" && php -l _assets/controllers/payment.php`
Expected: `No syntax errors detected in _assets/controllers/payment.php`

- [ ] **Step 4: Commit**

```bash
git add _assets/controllers/payment.php
git commit -m "Pasar usuario a unauthorize_invoices para auditoria"
```

---

### Task 4: Badge de la nueva operación en el tab Auditoría

**Files:**
- Modify: `views/payment/payment_detail.html:576-580`

**Interfaces:**
- Consumes: el `audit_log` ya mapeado por el controller (payment.php:1498-1510), donde `log.operacion` puede ahora ser `'UNAUTHORIZE_INVOICE'`.
- Produces: nada (vista final).

- [ ] **Step 1: Leer el bloque actual del badge**

Confirmar que en `views/payment/payment_detail.html` (líneas 576-580) el badge de movimiento es:

```twig
									{% if log.operacion == 'ADD_INVOICE' %}
										<span class="badge bg-success"><i class="fas fa-plus"></i> Agregó factura</span>
									{% else %}
										<span class="badge bg-danger"><i class="fas fa-minus"></i> Quitó factura</span>
									{% endif %}
```

- [ ] **Step 2: Distinguir las tres operaciones**

Reemplazar ese bloque por:

```twig
									{% if log.operacion == 'ADD_INVOICE' %}
										<span class="badge bg-success"><i class="fas fa-plus"></i> Agregó factura</span>
									{% elseif log.operacion == 'UNAUTHORIZE_INVOICE' %}
										<span class="badge bg-warning text-dark"><i class="fas fa-unlock"></i> Desautorizó factura</span>
									{% else %}
										<span class="badge bg-danger"><i class="fas fa-minus"></i> Quitó factura</span>
									{% endif %}
```

- [ ] **Step 3: Verificación manual en navegador**

1. Iniciar servidor: `php -S localhost:8000 router.php` (o usar IIS local).
2. Login como Tesorería (permiso 68).
3. Tomar una requisición con facturas autorizadas y sin pagos; desautorizar al menos una factura (desde el modal de Desglose, botón Desautorizar).
4. Abrir el detalle de esa requisición (`/payment/payment_detail/<id>`) → tab **Auditoría**.
5. Confirmar que aparece una fila con badge amarillo **"Desautorizó factura"**, con el folio, el monto que estaba autorizado y el nombre del usuario.
6. Verificar en BD: `SELECT TOP 5 Operacion, InvoiceId, UsuarioNombre, DatosAnteriores, Fecha FROM TG.dbo.PaymentRequestAuditLog WHERE Operacion = 'UNAUTHORIZE_INVOICE' ORDER BY Fecha DESC;` → debe existir la fila con el snapshot JSON (folio, authorized_amount, authorized_by, authorized_at) en `DatosAnteriores`.

- [ ] **Step 4: Commit**

```bash
git add views/payment/payment_detail.html
git commit -m "Mostrar movimiento Desautorizo factura en el tab Auditoria"
```
