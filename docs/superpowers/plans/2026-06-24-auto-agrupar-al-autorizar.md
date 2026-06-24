# Auto-agrupar requisición al autorizar (Tesorería) - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cuando Tesorería autoriza facturas de una requisición que aún no tiene `accounting_group_id` (es decir, Abastos no la agrupó/envió por correo), el sistema la agrupa automáticamente en ese momento, reutilizando el grupo contable del día para esa empresa si existe, y avisa a Tesorería que esto ocurrió.

**Architecture:** Se añade una función nueva en `PaymentAccountingGroupsModel` que agrupa una sola requisición puntual (sin el filtro de "todos los PDFs completos" que usa el flujo masivo de correo), reutilizando `get_next_accounting_id()` y `create_group()` ya existentes. `authorize_payment_execution()` en el controller la invoca cuando detecta `accounting_group_id IS NULL`, antes de autorizar las facturas. El frontend muestra un aviso si la respuesta indica que se agrupó automáticamente.

**Tech Stack:** PHP (MVC propio, sin framework), PDO/sqlsrv, jQuery + alertify.js. No hay test framework en el proyecto — la verificación de cada tarea es manual (revisar SQL generado, probar el endpoint).

## Global Constraints

- No crear tablas ni columnas nuevas — reutilizar `payment_accounting_groups` y `payment_requests.accounting_group_id` tal cual existen.
- No duplicar la lógica de generación de `accounting_id` ni de inserción de grupo — reutilizar `get_next_accounting_id()` y `create_group()` de `PaymentAccountingGroupsModel.php`.
- No modificar `auto_group_by_date()`, `get_ungrouped_by_date()`, ni `send_to_payments()` — el flujo de correo de Abastos no cambia.
- Solo Tesorería (permiso 68) ejecuta `authorize_payment_execution()` — eso ya está validado en el código existente (payment.php:1816) y no se toca.
- El aviso al frontend es informativo, no bloqueante: la autorización ya ocurrió cuando se muestra.

---

### Task 1: Agregar `auto_group_single_request()` al modelo de grupos contables

**Files:**
- Modify: `_assets/models/PaymentAccountingGroupsModel.php` (agregar método después de `auto_group_by_date()`, línea 372)

**Interfaces:**
- Consumes: `$this->get_next_accounting_id(): string` (línea 8), `$this->create_group(string $accounting_id, ?string $provider_cod, string $emp_cod, string $razon_social, int $user_id, array $request_ids): array` (línea 25)
- Produces: `auto_group_single_request(int $request_id, string $emp_cod, int $user_id): array` — retorna `['success' => bool, 'accounting_id' => string|null, 'group_id' => int|null, 'message' => string]`. Usado por Task 2.

Esta función busca si ya existe un grupo contable creado **hoy** para la empresa `$emp_cod`. Si existe, asigna la requisición a ese grupo (reutilizando el mismo `accounting_id` del día, igual que hace `auto_group_by_date()` al agrupar por empresa). Si no existe, crea uno nuevo con `get_next_accounting_id()` + `create_group()`.

- [ ] **Step 1: Leer el código existente para confirmar las firmas exactas**

Abrir `_assets/models/PaymentAccountingGroupsModel.php` y confirmar que `get_next_accounting_id()` (línea 8) y `create_group()` (línea 25) tienen exactamente estas firmas:

```php
public function get_next_accounting_id(): string
public function create_group(string $accounting_id, ?string $provider_cod, string $emp_cod, string $razon_social, int $user_id, array $request_ids): array
```

Si difieren, ajustar los pasos siguientes a la firma real antes de continuar.

- [ ] **Step 2: Escribir el método `auto_group_single_request()`**

Insertar este método inmediatamente después del cierre de `auto_group_by_date()` (después de la línea 372, antes de `get_payments_by_group_date()`):

```php
    /**
     * Agrupa una sola requisición puntual (no agrupada todavía) al momento en que
     * Tesorería la autoriza sin que Abastos la haya enviado primero. Reutiliza el
     * grupo del día para la misma empresa si ya existe (creado por auto_group_by_date()
     * u otra llamada a este método), o crea uno nuevo si no.
     */
    public function auto_group_single_request(int $request_id, string $emp_cod, int $user_id): array
    {
        // 1. Buscar grupo ya creado hoy para esta empresa
        $existing = $this->sql->select(
            "SELECT TOP 1 id, accounting_id
             FROM [TG].[dbo].[payment_accounting_groups]
             WHERE emp_cod = ? AND CAST(created_at AS DATE) = CAST(GETDATE() AS DATE)
             ORDER BY id DESC",
            [$emp_cod]
        );

        if (!empty($existing)) {
            $group_id      = $existing[0]['id'];
            $accounting_id = $existing[0]['accounting_id'];

            $updated = $this->sql->update(
                "UPDATE [TG].[dbo].[payment_requests]
                 SET accounting_group_id = ?
                 WHERE id = ? AND accounting_group_id IS NULL",
                [$group_id, $request_id]
            );

            if ($updated === false) {
                return ['success' => false, 'accounting_id' => null, 'group_id' => null, 'message' => "No se pudo asignar la requisición #$request_id al grupo existente"];
            }

            return ['success' => true, 'accounting_id' => $accounting_id, 'group_id' => $group_id, 'message' => "Requisición agregada al grupo contable $accounting_id"];
        }

        // 2. No hay grupo del día para esta empresa: crear uno nuevo
        $emp_name = $this->sql->select(
            "SELECT den FROM [SG12].[dbo].[Empresas] WHERE cod = ?",
            [$emp_cod]
        );
        $razon_social = $emp_name[0]['den'] ?? 'Sin empresa';

        $next_id = $this->get_next_accounting_id();
        $result  = $this->create_group($next_id, null, $emp_cod, $razon_social, $user_id, [$request_id]);

        if (!$result['success']) {
            return ['success' => false, 'accounting_id' => null, 'group_id' => null, 'message' => $result['message']];
        }

        return ['success' => true, 'accounting_id' => $result['accounting_id'], 'group_id' => $result['group_id'], 'message' => "Requisición agrupada en nuevo archivo contable {$result['accounting_id']}"];
    }
```

- [ ] **Step 3: Verificación manual de sintaxis**

Run: `php -l _assets/models/PaymentAccountingGroupsModel.php`
Expected: `No syntax errors detected in _assets/models/PaymentAccountingGroupsModel.php`

- [ ] **Step 4: Commit**

```bash
git add _assets/models/PaymentAccountingGroupsModel.php
git commit -m "Agregar auto_group_single_request para agrupar una requisicion puntual al autorizar"
```

---

### Task 2: Invocar auto-agrupación en `authorize_payment_execution()` y exponer el resultado

**Files:**
- Modify: `_assets/controllers/payment.php:1807-1837`

**Interfaces:**
- Consumes: `PaymentAccountingGroupsModel::auto_group_single_request(int $request_id, string $emp_cod, int $user_id): array` (Task 1), `$this->PaymentAccountingGroupsModel` ya instanciado en el controller (payment.php:54)
- Produces: la respuesta JSON de `/payment/authorize_payment_execution` incluye ahora `auto_grouped: bool` y `accounting_id: string|null`. Usado por Task 3.

- [ ] **Step 1: Leer el bloque actual para confirmar contexto exacto**

Confirmar que en `_assets/controllers/payment.php` el bloque entre la obtención de `$payment` (línea 1808) y la llamada a `authorize_invoices_for_payment()` (línea 1833) coincide con:

```php
            // Verificar que el pago exista y esté AUTORIZADO (status = 1)
            $payment = $this->PaymentRequestsModel->get_request_by_id($payment_id);

            if (!$payment) {
                json_output(['success' => false, 'message' => 'Solicitud de pago no encontrada']);
                return;
            }

            // Verificar que el usuario tenga permiso de Tesorería (68)
            if (!authorized(68)) {
                json_output(['success' => false, 'message' => 'Solo Tesorería puede autorizar facturas para ejecución de pago']);
                return;
            }

            // Aprobación implícita: si el pago sigue Pendiente, se registra la
            // autorización de Tesorería y se sube el status en este mismo paso
            $approval = $this->ensure_tesoreria_approval($payment_id, $payment['status'], $user_id);
            if (!$approval['success']) {
                json_output(['success' => false, 'message' => $approval['message']]);
                return;
            }

            // ========================================
            // PROCESAR AUTORIZACIONES USANDO EL MODELO
            // ========================================

            $result = $this->paymentRequestInvoicesModel->authorize_invoices_for_payment(
                $payment_id,
                $facturas,
                $user_id
            );
```

Si el contenido real difiere (por ejemplo si otro cambio ya tocó estas líneas), localizar el bloque equivalente por los mismos comentarios y ajustar los pasos siguientes a las líneas reales.

- [ ] **Step 2: Insertar la auto-agrupación entre la aprobación de Tesorería y el procesamiento de facturas**

Reemplazar:

```php
            // Aprobación implícita: si el pago sigue Pendiente, se registra la
            // autorización de Tesorería y se sube el status en este mismo paso
            $approval = $this->ensure_tesoreria_approval($payment_id, $payment['status'], $user_id);
            if (!$approval['success']) {
                json_output(['success' => false, 'message' => $approval['message']]);
                return;
            }

            // ========================================
            // PROCESAR AUTORIZACIONES USANDO EL MODELO
            // ========================================

            $result = $this->paymentRequestInvoicesModel->authorize_invoices_for_payment(
                $payment_id,
                $facturas,
                $user_id
            );
```

con:

```php
            // Aprobación implícita: si el pago sigue Pendiente, se registra la
            // autorización de Tesorería y se sube el status en este mismo paso
            $approval = $this->ensure_tesoreria_approval($payment_id, $payment['status'], $user_id);
            if (!$approval['success']) {
                json_output(['success' => false, 'message' => $approval['message']]);
                return;
            }

            // Si Tesorería autoriza una requisición que Abastos aún no agrupó/envió
            // por correo, se agrupa en este momento para no perder trazabilidad contable.
            $auto_grouped = false;
            $accounting_id = null;
            if (empty($payment['accounting_group_id'])) {
                $group_result = $this->PaymentAccountingGroupsModel->auto_group_single_request(
                    (int)$payment_id,
                    $payment['emp_cod'],
                    $user_id
                );
                if ($group_result['success']) {
                    $auto_grouped = true;
                    $accounting_id = $group_result['accounting_id'];
                }
            }

            // ========================================
            // PROCESAR AUTORIZACIONES USANDO EL MODELO
            // ========================================

            $result = $this->paymentRequestInvoicesModel->authorize_invoices_for_payment(
                $payment_id,
                $facturas,
                $user_id
            );
```

Nota: si `auto_group_single_request()` falla, no se bloquea la autorización — solo no se marca `auto_grouped`. La prioridad es no impedir el trabajo de Tesorería.

- [ ] **Step 3: Incluir `auto_grouped` y `accounting_id` en la respuesta de éxito**

Reemplazar:

```php
                json_output([
                    'success' => true,
                    'message' => $mensaje,
                    'facturas_autorizadas' => $result['facturas_autorizadas'],
                    'total_autorizado' => number_format($result['total_autorizado'], 2, '.', ''),
                    'errores' => $result['errores'] ?? []
                ]);
```

con:

```php
                json_output([
                    'success' => true,
                    'message' => $mensaje,
                    'facturas_autorizadas' => $result['facturas_autorizadas'],
                    'total_autorizado' => number_format($result['total_autorizado'], 2, '.', ''),
                    'errores' => $result['errores'] ?? [],
                    'auto_grouped' => $auto_grouped,
                    'accounting_id' => $accounting_id
                ]);
```

- [ ] **Step 4: Verificación manual de sintaxis**

Run: `php -l _assets/controllers/payment.php`
Expected: `No syntax errors detected in _assets/controllers/payment.php`

- [ ] **Step 5: Commit**

```bash
git add _assets/controllers/payment.php
git commit -m "Auto-agrupar requisicion sin grupo al autorizarla en authorize_payment_execution"
```

---

### Task 3: Aviso en frontend cuando la autorización auto-agrupó la requisición

**Files:**
- Modify: `_assets/js/payment.js:3285-3299`

**Interfaces:**
- Consumes: respuesta JSON de `/payment/authorize_payment_execution` con campos `auto_grouped: bool`, `accounting_id: string|null` (Task 2)
- Produces: ninguno (último eslabón visible al usuario)

- [ ] **Step 1: Leer el callback actual para confirmar contexto exacto**

Confirmar en `_assets/js/payment.js` que el callback `success` de la llamada a `/payment/authorize_payment_execution` (alrededor de la línea 3285) coincide con:

```javascript
    success: function (response) {
      if (response.success) {
        alertify.success("✓ " + response.message);
        $("#modalAutorizarPago").modal("hide");

        setTimeout(() => {
          location.reload();
        }, 1500);
      } else {
        alertify.error(response.message);
        $("#btnConfirmarAutorizacion")
          .prop("disabled", false)
          .html('<i class="fas fa-check-circle"></i> Autorizar Pago');
      }
    },
```

Si difiere, localizar el bloque equivalente (mismo `url: "/payment/authorize_payment_execution"`) y ajustar el paso siguiente a las líneas reales.

- [ ] **Step 2: Mostrar aviso adicional cuando `auto_grouped` sea true**

Reemplazar:

```javascript
    success: function (response) {
      if (response.success) {
        alertify.success("✓ " + response.message);
        $("#modalAutorizarPago").modal("hide");

        setTimeout(() => {
          location.reload();
        }, 1500);
      } else {
```

con:

```javascript
    success: function (response) {
      if (response.success) {
        alertify.success("✓ " + response.message);

        if (response.auto_grouped) {
          alertify.message(
            "Esta requisición no había sido enviada por Abastos — se agrupó automáticamente en el archivo contable " +
              response.accounting_id +
              " al autorizarla."
          );
        }

        $("#modalAutorizarPago").modal("hide");

        setTimeout(() => {
          location.reload();
        }, 1500);
      } else {
```

- [ ] **Step 3: Verificación manual en navegador**

1. Iniciar servidor local: `php -S localhost:8000 router.php`
2. Loguearse con un usuario con permiso 68 (Tesorería).
3. Localizar (o crear vía datos de prueba) una requisición con `status = 0`, `tipo = 0`, al menos una factura, y `accounting_group_id IS NULL` (sin pasar por "Mandar pagos").
4. Abrir el modal de autorización de esa requisición y autorizar al menos una factura.
5. Confirmar en la respuesta visual: aparece el toast verde de éxito y, justo después, el mensaje informativo de auto-agrupación con el `accounting_id` generado.
6. Verificar en BD (`SELECT accounting_group_id FROM payment_requests WHERE id = <id>`) que ahora tiene un `accounting_group_id` no nulo, y que existe la fila correspondiente en `payment_accounting_groups`.
7. Repetir el proceso con otra requisición de la **misma empresa** el mismo día y confirmar que reutiliza el mismo `accounting_id` (no crea un grupo nuevo).

- [ ] **Step 4: Commit**

```bash
git add _assets/js/payment.js
git commit -m "Avisar a Tesoreria cuando autorizar agrupa automaticamente una requisicion"
```
