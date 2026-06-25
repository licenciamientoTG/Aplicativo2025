# Anticipos a Proveedores — Flujo Completo Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Completar el flujo de anticipos a proveedores en AplicativoPhp: vista de detalle propia (no copiada de pagos normales), registro de pago con comprobante, y ligado manual a facturas para descontar saldo.

**Architecture:** PHP MVC (sin framework de tests). Se reutilizan modelos existentes (`PaymentBatchesModel`, `PaymentTransactionsModel`, `PaymentTransactionDocumentsModel`, `PaymentRequestsModel`) y se añade un método de controlador nuevo y aislado (`pay_anticipo()`) más un endpoint de consulta de facturas pendientes por proveedor. Frontend en Twig + jQuery siguiendo el patrón ya usado en `payment.js`/`anticipo_detail.html`.

**Tech Stack:** PHP 8 (PDO/sqlsrv), Twig 3, jQuery + Bootstrap, DataTables, alertify.js.

## Global Constraints

- No build step, no test framework — toda verificación es manual vía navegador o scripts PHP puntuales.
- Toda escritura multi-tabla va dentro de `$this->sql->beginTransaction()` / `commit()` / `rollback()` (patrón ya usado en `PaymentRequestsModel::create_anticipo()` y `create_payment_with_invoices()`).
- Permiso de Tesorería = `68` (constante `PaymentRequestAuthorizationsModel::PERM_TESORERIA`). Verificar con la función global `authorized(68)`.
- Estados de `payment_requests.status`: `0` = STATUS_PENDING, `1` = STATUS_AUTHORIZED, `2` = STATUS_PAID, `3` = STATUS_CANCELLED (constantes en `PaymentRequestsModel`).
- `payment_requests.tipo`: `1` = anticipo (sin facturas), ausente/`0`/NULL = pago normal con facturas.
- No modificar `registrar_lote_y_pago()`, `process_bulk_payment()`, `authorize_payment_execution()`, `execute_authorized_payments()` — son el pipeline de pagos normales en producción.
- Subida de archivos: extensiones permitidas `pdf, jpg, jpeg, png`, tamaño máximo 10 MB (`PaymentTransactionDocumentsModel::ALLOWED_EXT` / `MAX_SIZE`).
- Todos los textos de UI en español, mismo tono que el resto del módulo (ej. "Solo Tesorería puede...", alertify para confirmaciones).

---

## File Structure

| Archivo | Acción | Responsabilidad |
|---|---|---|
| `_assets/controllers/payment.php` | Modificar | Agregar `pay_anticipo()` y `get_invoices_pendientes_by_provider()` |
| `_assets/models/PaymentRequestInvoicesModel.php` | Modificar | Agregar `get_pending_by_provider($provider_cod)` |
| `views/payment/anticipo_detail.html` | Reescribir completo | Vista de detalle propia del anticipo (autorización 1 nivel, pago+comprobante, aplicaciones a facturas) |
| `views/payment/modals/aplicar_anticipo.html` | Reescribir completo | Modal funcional de ligar anticipo a facturas del proveedor |
| `_assets/js/payment.js` | Modificar | Quitar código muerto de `get_anticipos_disponibles`; agregar JS de `anticipo_detail.html` (autorizar, pagar, aplicar a facturas) |

No se crean tablas ni columnas nuevas. No se modifica el esquema de `payment_transactions` ni `payment_transaction_documents` (ambas ya soportan `invoice_id`/`transaction_id` opcionales según lo verificado en el diseño).

---

### Task 1: Query de facturas pendientes por proveedor

**Files:**
- Modify: `_assets/models/PaymentRequestInvoicesModel.php`
- Test: script manual en `C:\Users\alejandro.martinez\Desktop\pago a proveedores\test_get_pending_by_provider.php`

**Interfaces:**
- Produces: `PaymentRequestInvoicesModel::get_pending_by_provider(string $provider_cod): array|false` — cada fila: `id, payment_request_id, folio, invoice_number, amount, paid_amount, saldo, uuid, estacion_nombre`.

- [ ] **Step 1: Agregar el método al modelo**

En `_assets/models/PaymentRequestInvoicesModel.php`, agregar después de `get_invoices_by_request()` (línea 35):

```php
    /**
     * Facturas con saldo pendiente de un proveedor (para ligar anticipos).
     * @param string $provider_cod
     * @return array|false
     */
    public function get_pending_by_provider($provider_cod) : array|false {
        $query = "
            SELECT
                pri.id,
                pri.payment_request_id,
                pri.folio,
                pri.invoice_number,
                pri.amount,
                ISNULL(pri.paid_amount, 0) AS paid_amount,
                (pri.amount - ISNULL(pri.paid_amount, 0)) AS saldo,
                pri.uuid,
                g.abr AS estacion_nombre
            FROM [TG].[dbo].[payment_request_invoices] pri
            INNER JOIN [TG].[dbo].[payment_requests] pr ON pr.id = pri.payment_request_id
            LEFT JOIN [SG12].[dbo].Gasolineras g ON g.cod = pri.codgas
            WHERE pr.provider_cod = ?
              AND pri.is_deleted = 0
              AND pri.status IN (0, 1, 3)
              AND (pri.amount - ISNULL(pri.paid_amount, 0)) > 0
            ORDER BY pri.id DESC
        ";
        return ($this->sql->select($query, [$provider_cod])) ?: false;
    }
```

- [ ] **Step 2: Verificar manualmente con un script PHP puntual**

Crear `C:\Users\alejandro.martinez\Desktop\pago a proveedores\test_get_pending_by_provider.php`:

```php
<?php
chdir('C:/Users/alejandro.martinez/Desktop/codigo/AplicativoPhp');
require '_assets/classes/header.class.php';
require '_assets/classes/php_functions.php';

spl_autoload_register(function($class) {
    if (file_exists(MODELS . $class . '.php')) require MODELS . $class . '.php';
});

$model = new PaymentRequestInvoicesModel();
$provider_cod = $argv[1] ?? null;
if (!$provider_cod) {
    echo "Uso: php test_get_pending_by_provider.php <provider_cod>\n";
    exit(1);
}
$rows = $model->get_pending_by_provider($provider_cod);
echo $rows ? json_encode($rows, JSON_PRETTY_PRINT) : "Sin resultados\n";
```

Run: `php "C:\Users\alejandro.martinez\Desktop\pago a proveedores\test_get_pending_by_provider.php" <un_provider_cod_real>`
Expected: JSON con filas de facturas pendientes de ese proveedor, o "Sin resultados" si no tiene — sin errores de SQL.

- [ ] **Step 3: Commit**

```bash
git add "_assets/models/PaymentRequestInvoicesModel.php"
git commit -m "Agregar get_pending_by_provider para ligar anticipos a facturas"
```

---

### Task 2: Endpoint `get_invoices_pendientes_by_provider`

**Files:**
- Modify: `_assets/controllers/payment.php`

**Interfaces:**
- Consumes: `PaymentRequestInvoicesModel::get_pending_by_provider()` (Task 1).
- Produces: ruta `POST /payment/get_invoices_pendientes_by_provider`, recibe JSON `{provider_cod}`, responde JSON `{success, data: [{id, payment_request_id, folio, invoice_number, amount, paid_amount, saldo, uuid, estacion_nombre}]}`.

- [ ] **Step 1: Agregar el método al controlador**

En `_assets/controllers/payment.php`, agregar junto a `apply_anticipo_to_invoices()` (después de la línea 5687, antes de `generar_html_notificacion_pago`):

```php
    /**
     * Facturas pendientes de un proveedor, para el modal de ligar anticipo.
     */
    public function get_invoices_pendientes_by_provider()
    {
        header('Content-Type: application/json');
        try {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);
            $provider_cod = $data['provider_cod'] ?? null;

            if (!$provider_cod) {
                json_output(['success' => false, 'message' => 'Proveedor requerido']);
                return;
            }

            $facturas = $this->paymentRequestInvoicesModel->get_pending_by_provider($provider_cod);

            json_output([
                'success' => true,
                'data' => $facturas ?: []
            ]);
        } catch (Exception $e) {
            error_log('Error en get_invoices_pendientes_by_provider: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error del servidor']);
        }
    }
```

- [ ] **Step 2: Verificar manualmente con curl (servidor PHP local corriendo y sesión logueada)**

Run (con el navegador logueado, copiar la cookie `PHPSESSID` de devtools):
```bash
curl -s -X POST "http://localhost:8000/payment/get_invoices_pendientes_by_provider" \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=<pegar_aqui>" \
  -d '{"provider_cod":"<un_provider_cod_real>"}'
```
Expected: `{"success":true,"data":[...]}` con facturas de ese proveedor (mismo resultado que Task 1, vía HTTP).

- [ ] **Step 3: Commit**

```bash
git add "_assets/controllers/payment.php"
git commit -m "Agregar endpoint get_invoices_pendientes_by_provider"
```

---

### Task 3: Backend `pay_anticipo()` — registrar pago y comprobante del anticipo

**Files:**
- Modify: `_assets/controllers/payment.php`

**Interfaces:**
- Consumes: `PaymentBatchesModel::create(array $data): int|false`, `PaymentTransactionsModel::insert_transaction(...)`, `PaymentTransactionDocumentsModel::upload(?int $transaction_id, array $file, int $user_id, ?int $batch_id = null): array`, `PaymentRequestsModel::update_request_status($id, $status, $comment = null): bool`, `PaymentRequestsModel::get_request_by_id($id): array|false`, método privado existente `banco_por_emp_cod($emp_cod): string`.
- Produces: ruta `POST /payment/pay_anticipo` (multipart/form-data: `anticipo_id`, `fecha_pago`, `referencia`, `observaciones`, file `comprobante`), responde JSON `{success, message, batch_id?}`.

- [ ] **Step 1: Agregar el método al controlador**

Agregar en `_assets/controllers/payment.php`, justo después de `apply_anticipo_to_invoices()` (línea 5687) y antes del método de Task 2 (o después, el orden entre ambos no importa):

```php
    /**
     * Registra el pago de un anticipo ya autorizado: crea el lote, la
     * transacción (sin factura) y sube el comprobante. Marca el anticipo
     * como PAGADO.
     */
    public function pay_anticipo()
    {
        header('Content-Type: application/json');
        try {
            $anticipo_id = isset($_POST['anticipo_id']) ? intval($_POST['anticipo_id']) : 0;
            $fecha_pago = $_POST['fecha_pago'] ?? null;
            $referencia = $_POST['referencia'] ?? null;
            $observaciones = $_POST['observaciones'] ?? '';
            $user_id = $_SESSION['tg_user']['Id'] ?? null;

            if (!$anticipo_id || !$fecha_pago || !$referencia) {
                json_output(['success' => false, 'message' => 'Faltan datos obligatorios: fecha y referencia bancaria']);
                return;
            }
            if (!$user_id) {
                json_output(['success' => false, 'message' => 'Usuario no identificado']);
                return;
            }
            if (!authorized(68)) {
                json_output(['success' => false, 'message' => 'Solo Tesorería puede registrar el pago']);
                return;
            }

            $anticipo = $this->PaymentRequestsModel->get_request_by_id($anticipo_id);
            if (!$anticipo || intval($anticipo['tipo'] ?? 0) !== 1) {
                json_output(['success' => false, 'message' => 'Anticipo no encontrado']);
                return;
            }
            if (intval($anticipo['status']) !== PaymentRequestsModel::STATUS_AUTHORIZED) {
                json_output(['success' => false, 'message' => 'El anticipo debe estar autorizado por Tesorería antes de pagarse']);
                return;
            }

            $comprobante = (!empty($_FILES['comprobante']['name']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK)
                ? $_FILES['comprobante'] : null;
            if (!$comprobante) {
                json_output(['success' => false, 'message' => 'El comprobante de pago es obligatorio']);
                return;
            }

            $this->PaymentRequestsModel->sql->beginTransaction();

            $batch_id = $this->PaymentBatchesModel->create([
                'fecha_pago'    => $fecha_pago,
                'referencia'    => $referencia,
                'banco'         => $this->banco_por_emp_cod($anticipo['emp_cod'] ?? null),
                'emp_cod'       => $anticipo['emp_cod'] ?? null,
                'provider_cod'  => $anticipo['provider_cod'] ?? null,
                'monto_total'   => $anticipo['monto_total'],
                'observaciones' => $observaciones,
                'created_by'    => $user_id,
            ]);

            if (!$batch_id) {
                $this->PaymentRequestsModel->sql->rollback();
                json_output(['success' => false, 'message' => 'No se pudo crear el lote de pago']);
                return;
            }

            $transaction_id = $this->paymentTransactionsModel->insert_transaction(
                $anticipo_id,
                null,
                $anticipo['monto_total'],
                $fecha_pago,
                $user_id,
                'TRANSFERENCIA',
                $referencia,
                $observaciones,
                null,
                null,
                null,
                $batch_id
            );

            if (!$transaction_id) {
                $this->PaymentRequestsModel->sql->rollback();
                json_output(['success' => false, 'message' => 'Error al registrar la transacción del anticipo']);
                return;
            }

            $upload = $this->PaymentTransactionDocumentsModel->upload($transaction_id, $comprobante, $user_id, $batch_id);
            if (!$upload['success']) {
                $this->PaymentRequestsModel->sql->rollback();
                json_output(['success' => false, 'message' => 'Error al guardar el comprobante: ' . $upload['message']]);
                return;
            }

            $this->PaymentRequestsModel->update_request_status(
                $anticipo_id,
                PaymentRequestsModel::STATUS_PAID,
                "Anticipo pagado el " . date('d/m/Y', strtotime($fecha_pago)) . " - Ref: " . $referencia
            );

            $this->PaymentRequestsModel->sql->commit();

            json_output([
                'success' => true,
                'message' => 'Anticipo pagado y comprobante guardado correctamente',
                'batch_id' => $batch_id
            ]);
        } catch (Exception $e) {
            $this->PaymentRequestsModel->sql->rollback();
            error_log('Error en pay_anticipo: ' . $e->getMessage());
            json_output(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
        }
    }
```

**Nota de implementación:** `$this->PaymentRequestsModel->sql` es el wrapper PDO compartido (`MySqlPdoHandler::getInstance()`, singleton) — el mismo objeto `$this->sql` que usan internamente todos los modelos, así que `beginTransaction()`/`commit()`/`rollback()` cubren los inserts hechos por `PaymentBatchesModel`, `PaymentTransactionsModel` y `PaymentTransactionDocumentsModel` dentro de esta misma transacción. Confirmar este supuesto en el Step 2 antes de continuar.

- [ ] **Step 2: Confirmar que `sql` es un singleton compartido entre modelos**

Run:
```bash
grep -n "class Model" -A 15 "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp\_assets\models\Model.php"
```
Expected: ver que el constructor de `Model` asigna `$this->sql = MySqlPdoHandler::getInstance();` (o equivalente) confirmando que es un singleton — si no es así, ajustar el Step 1 para usar `MySqlPdoHandler::getInstance()` directamente en vez de `$this->PaymentRequestsModel->sql`.

- [ ] **Step 3: Probar manualmente con un anticipo real en estado AUTORIZADO**

1. Levantar el servidor: `php -S localhost:8000 router.php` (desde la raíz del repo).
2. En el navegador, loguear con un usuario con permiso 68, crear un anticipo de prueba (`/payment/payment_list`, tab Anticipos, botón crear), autorizarlo (`authorize_payment` ya existente — usar el botón actual de autorización en la UI vieja, o llamar el endpoint manualmente).
3. Run:
```bash
curl -s -X POST "http://localhost:8000/payment/pay_anticipo" \
  -H "Cookie: PHPSESSID=<pegar_aqui>" \
  -F "anticipo_id=<id_del_anticipo>" \
  -F "fecha_pago=2026-06-25" \
  -F "referencia=TEST-001" \
  -F "observaciones=Prueba manual" \
  -F "comprobante=@C:/Users/alejandro.martinez/Desktop/pago a proveedores/comprobante_test.pdf"
```
(Crear antes un PDF de prueba cualquiera en esa ruta.)

Expected: `{"success":true,"message":"Anticipo pagado y comprobante guardado correctamente","batch_id":<n>}`. Verificar en BD que `payment_requests.status = 2` para ese anticipo, que existe una fila en `payment_batches`, una en `payment_transactions` con `invoice_id IS NULL`, y una en `payment_transaction_documents` con `batch_id` correcto y el archivo físico en `_assets/uploads/payment_documents/2026/06/`.

- [ ] **Step 4: Commit**

```bash
git add "_assets/controllers/payment.php"
git commit -m "Agregar pay_anticipo para registrar pago y comprobante de anticipos"
```

---

### Task 4: Reescribir `anticipo_detail.html`

**Files:**
- Modify: `views/payment/anticipo_detail.html` (reemplazo completo del contenido)

**Interfaces:**
- Consumes: variables ya pasadas por el controlador `anticipo_detail()` (sin cambios en el controlador): `anticipo` (array con `id, request_date, usuario_nombre, status, comment, monto_total, provider_cod, emp_cod`), `aplicaciones` (array de filas con `folio, invoice_number, monto_aplicado, fecha_aplicacion, aplicado_por_nombre, proveedor_nombre, estacion_nombre`), `summary` (`monto_original, total_aplicado, saldo_disponible, total_aplicaciones`), `authorizations` (array), `authorization_status` (`tesoreria, next_level, completed` — del modelo de 1 nivel), `auth_info.tesoreria`.
- Produces: botones que llaman a JS de Task 6 (`openAuthModalAnticipo()`, `abrirModalPagarAnticipo()`, `abrirModalAplicarAFacturas()`), variable global JS `var anticipoId`.

**Nota:** la sección "Estado y autorización" usa `authorization_status.tesoreria` / `next_level` / `completed`, que hoy provienen de `getAuthorizationStatus($anticipo_id)` en `PaymentRequestsModel` (línea 768) — ese método YA devuelve también `abastos`, `contabilidad`, `admin_finanzas` por compatibilidad con el modelo viejo de 3 niveles, pero esta vista solo usa `tesoreria`, `next_level`, `completed`. No es necesario modificar ese método del modelo.

- [ ] **Step 1: Reemplazar el contenido completo de `views/payment/anticipo_detail.html`**

```html
{% extends "views/layouts/base.html" %}
{% block title %}Detalle de Anticipo #{{ anticipo.id }}{% endblock %}
{% block content %}

<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <span>Anticipo #{{ anticipo.id }}</span>
                    <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;font-weight:600;margin-left:.5rem;">
                        <i class="fas fa-hand-holding-usd"></i> Anticipo
                    </span>
                </h5>
                <a href="/payment/payment_list" class="btn btn-sm btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <p><strong>Fecha Solicitud:</strong><br>{{ anticipo.request_date|date('d/m/Y H:i') }}</p>
                    </div>
                    <div class="col-md-3">
                        <p><strong>Solicitado por:</strong><br>{{ anticipo.usuario_nombre ?? 'N/A' }}</p>
                    </div>
                    <div class="col-md-3">
                        <p><strong>Estado:</strong><br>
                            {% if anticipo.status == 0 %}
                            <span class="badge bg-warning text-dark">Pendiente de Autorización</span>
                            {% elseif anticipo.status == 1 %}
                            <span class="badge bg-info">Autorizado - Listo para Pagar</span>
                            {% elseif anticipo.status == 2 %}
                            <span class="badge bg-success">Pagado</span>
                            {% else %}
                            <span class="badge bg-danger">Cancelado</span>
                            {% endif %}
                        </p>
                    </div>
                    <div class="col-md-3">
                        <p><strong>Monto del Anticipo:</strong><br>
                            <span class="h5 text-primary">${{ anticipo.monto_total|number_format(2) }}</span>
                        </p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <p class="mb-0"><strong>Justificación:</strong><br>{{ anticipo.comment ?? 'Sin comentario' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Resumen Financiero -->
<div class="row mt-3">
    <div class="col-md-4">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <h6 class="mb-2">Monto Original</h6>
                <h3 class="mb-0">${{ summary.monto_original|number_format(2) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-info text-white">
            <div class="card-body">
                <h6 class="mb-2">Aplicado a Facturas</h6>
                <h3 class="mb-0">${{ summary.total_aplicado|number_format(2) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success text-white">
            <div class="card-body">
                <h6 class="mb-2">Saldo Disponible</h6>
                <h3 class="mb-0">${{ summary.saldo_disponible|number_format(2) }}</h3>
            </div>
        </div>
    </div>
</div>

<!-- Autorización (un solo nivel: Tesorería) -->
<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-shield-alt"></i> Autorización</h6>
            </div>
            <div class="card-body">
                {% if authorization_status.tesoreria %}
                <div class="alert alert-success mb-0">
                    <i class="fas fa-check-circle"></i>
                    <strong>Autorizado por Tesorería</strong>
                    {% if auth_info.tesoreria %}
                    — {{ auth_info.tesoreria.autorizador_nombre }} el {{ auth_info.tesoreria.authorization_date|date('d/m/Y H:i') }}
                    {% endif %}
                </div>
                {% else %}
                    {% if authorized(68) %}
                    <button class="btn btn-info" onclick="openAuthModalAnticipo()">
                        <i class="fas fa-check"></i> Autorizar como Tesorería
                    </button>
                    {% else %}
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-clock"></i> Esperando autorización de Tesorería
                    </div>
                    {% endif %}
                {% endif %}
            </div>
        </div>
    </div>
</div>

<!-- Pago y comprobante -->
<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-money-check-alt"></i> Pago del Anticipo</h6>
            </div>
            <div class="card-body">
                {% if anticipo.status == 2 %}
                <div class="alert alert-success mb-0">
                    <i class="fas fa-check-circle"></i> Este anticipo ya fue pagado. El comprobante está disponible en el historial de transacciones.
                </div>
                {% elseif anticipo.status == 1 and authorized(68) %}
                <button class="btn btn-primary" onclick="abrirModalPagarAnticipo()">
                    <i class="fas fa-dollar-sign"></i> Registrar Pago y Subir Comprobante
                </button>
                {% elseif anticipo.status == 1 %}
                <div class="alert alert-info mb-0">
                    <i class="fas fa-clock"></i> Autorizado. Esperando que Tesorería registre el pago.
                </div>
                {% else %}
                <div class="alert alert-secondary mb-0">
                    <i class="fas fa-lock"></i> Debe autorizarse antes de poder pagarse.
                </div>
                {% endif %}
            </div>
        </div>
    </div>
</div>

<!-- Aplicaciones a facturas -->
<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-file-invoice-dollar"></i> Aplicaciones a Facturas</h6>
                {% if anticipo.status == 2 and summary.saldo_disponible > 0 %}
                <button class="btn btn-sm btn-success" onclick="abrirModalAplicarAFacturas()">
                    <i class="fas fa-link"></i> Aplicar a Facturas
                </button>
                {% endif %}
            </div>
            <div class="card-body table-responsive">
                {% if aplicaciones and aplicaciones|length > 0 %}
                <table class="table table-sm table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Folio</th>
                            <th>Factura</th>
                            <th>Estación</th>
                            <th class="text-end">Monto Aplicado</th>
                            <th>Fecha</th>
                            <th>Aplicado por</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for app in aplicaciones %}
                        <tr>
                            <td>{{ app.folio }}</td>
                            <td>{{ app.invoice_number }}</td>
                            <td>{{ app.estacion_nombre }}</td>
                            <td class="text-end">${{ app.monto_aplicado|number_format(2) }}</td>
                            <td>{{ app.fecha_aplicacion|date('d/m/Y H:i') }}</td>
                            <td>{{ app.aplicado_por_nombre }}</td>
                        </tr>
                        {% endfor %}
                    </tbody>
                </table>
                {% else %}
                <p class="text-muted mb-0">Este anticipo aún no se ha aplicado a ninguna factura.</p>
                {% endif %}
            </div>
        </div>
    </div>
</div>

<!-- Historial de autorizaciones -->
{% if authorizations and authorizations|length > 0 %}
<div class="row mt-3 mb-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-history"></i> Historial de Autorizaciones</h6>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Departamento</th>
                            <th>Autorizador</th>
                            <th>Fecha y Hora</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for auth in authorizations %}
                        <tr>
                            <td>{{ auth.departamento }}</td>
                            <td><i class="fas fa-user"></i> {{ auth.autorizador_nombre }}</td>
                            <td><i class="fas fa-calendar"></i> {{ auth.authorization_date|date('d/m/Y H:i:s') }}</td>
                        </tr>
                        {% endfor %}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
{% endif %}

<!-- Modal: Autorizar -->
<div class="modal fade" id="authModalAnticipo" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-shield-alt"></i> Autorizar Anticipo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-center mb-0"><strong>¿Confirmas la autorización de este anticipo como Tesorería?</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="confirmarAutorizacionAnticipo()">
                    <i class="fas fa-check"></i> Confirmar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Pagar anticipo -->
<div class="modal fade" id="modalPagarAnticipo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-dollar-sign"></i> Registrar Pago del Anticipo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formPagarAnticipo">
                    <div class="mb-3">
                        <label class="form-label">Fecha de Pago <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="pago_fecha" value="{{ 'now'|date('Y-m-d') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Referencia Bancaria <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="pago_referencia" placeholder="Ej: Transferencia #12345" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Observaciones</label>
                        <textarea class="form-control" id="pago_observaciones" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Comprobante de Pago (PDF, JPG o PNG) <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="pago_comprobante" accept=".pdf,.jpg,.jpeg,.png" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="btnConfirmarPagoAnticipo" onclick="confirmarPagoAnticipo()">
                    <i class="fas fa-check"></i> Confirmar Pago
                </button>
            </div>
        </div>
    </div>
</div>

{% include 'views/payment/modals/aplicar_anticipo.html' %}

<input type="hidden" id="anticipoId" value="{{ anticipo.id }}">
<input type="hidden" id="anticipoProviderCod" value="{{ anticipo.provider_cod }}">
<input type="hidden" id="anticipoSaldoDisponible" value="{{ summary.saldo_disponible }}">
{% endblock %}

{% block myjs %}
<script src="{{ JS }}payment.js"></script>
{% endblock %}
```

- [ ] **Step 2: Verificación visual manual**

1. Levantar `php -S localhost:8000 router.php`.
2. Loguear y navegar a `/payment/anticipo_detail/<id_de_un_anticipo_existente>`.
3. Confirmar: no hay errores de Twig (variable indefinida), se ven las 5 secciones (cabecera, resumen financiero, autorización, pago, aplicaciones), y los botones aparecen/desaparecen según el `status` del anticipo de prueba.

Expected: página renderiza sin error 500 ni warnings de Twig sobre variables faltantes.

- [ ] **Step 3: Commit**

```bash
git add "views/payment/anticipo_detail.html"
git commit -m "Reescribir anticipo_detail.html como vista propia (ya no copia de payment_detail)"
```

---

### Task 5: Completar modal `aplicar_anticipo.html`

**Files:**
- Modify: `views/payment/modals/aplicar_anticipo.html` (reemplazo completo)

**Interfaces:**
- Consumes: endpoint de Task 2 (`/payment/get_invoices_pendientes_by_provider`), elementos `#anticipoId`, `#anticipoProviderCod`, `#anticipoSaldoDisponible` definidos en Task 4.
- Produces: modal `#modalAplicarAnticipo` con `<tbody id="tbodyFacturasAplicar">`, invocado por `abrirModalAplicarAFacturas()` (JS de Task 6).

- [ ] **Step 1: Reemplazar el contenido completo de `views/payment/modals/aplicar_anticipo.html`**

```html
{# views/payment/modals/aplicar_anticipo.html #}
<div class="modal fade" id="modalAplicarAnticipo" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-link"></i> Aplicar Anticipo a Facturas</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <strong>Saldo disponible del anticipo:</strong>
                    $<span id="saldoDisponibleModal">0.00</span>
                </div>

                <input type="search" id="buscarFacturaAplicar" class="form-control mb-2"
                       placeholder="Buscar por folio o número de factura...">

                <div class="table-responsive">
                    <table class="table table-sm table-hover" id="tablaFacturasPendientesAplicar">
                        <thead class="table-light">
                            <tr>
                                <th width="40">
                                    <input type="checkbox" id="selectAllFacturasAplicar">
                                </th>
                                <th>Folio</th>
                                <th>Factura</th>
                                <th>Estación</th>
                                <th class="text-end">Monto</th>
                                <th class="text-end">Saldo Pendiente</th>
                                <th width="150">Aplicar Monto</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyFacturasAplicar">
                            <tr id="filaSinFacturas">
                                <td colspan="7" class="text-center text-muted">Cargando facturas...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-warning mt-2 d-none" id="alertExcedeSaldo">
                    <i class="fas fa-exclamation-triangle"></i> El monto total a aplicar excede el saldo disponible del anticipo.
                </div>
            </div>
            <div class="modal-footer">
                <div class="me-auto">
                    <strong>Total a aplicar:</strong> $<span id="totalAplicarModal">0.00</span>
                </div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" id="btnConfirmarAplicarAnticipo"
                        onclick="confirmarAplicarAnticipo()" disabled>
                    <i class="fas fa-check"></i> Confirmar Aplicación
                </button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 2: Verificación visual manual**

1. Con el servidor corriendo, navegar a `/payment/anticipo_detail/<id_de_un_anticipo_pagado_con_saldo>`.
2. Click en "Aplicar a Facturas" (visible solo si `status == 2` y `saldo_disponible > 0` — si no hay un anticipo así de prueba, ajustar manualmente el status en BD para probar, y revertirlo después).
3. Confirmar que el modal abre (aunque la tabla seguirá en "Cargando facturas..." hasta completar Task 6).

Expected: modal `#modalAplicarAnticipo` se abre sin errores de consola JS por elementos no encontrados.

- [ ] **Step 3: Commit**

```bash
git add "views/payment/modals/aplicar_anticipo.html"
git commit -m "Completar modal aplicar_anticipo con tabla de facturas pendientes"
```

---

### Task 6: JS de `anticipo_detail.html` — autorizar, pagar, aplicar a facturas

**Files:**
- Modify: `_assets/js/payment.js`

**Interfaces:**
- Consumes: `/payment/authorize_payment` (ya existe, recibe `payment_id`, `permission`), `/payment/pay_anticipo` (Task 3), `/payment/get_invoices_pendientes_by_provider` (Task 2), `/payment/apply_anticipo_to_invoices` (ya existe, recibe `anticipo_id`, `aplicaciones` JSON-string).
- Produces: funciones globales `openAuthModalAnticipo()`, `confirmarAutorizacionAnticipo()`, `abrirModalPagarAnticipo()`, `confirmarPagoAnticipo()`, `abrirModalAplicarAFacturas()`, `confirmarAplicarAnticipo()` — referenciadas por `onclick` en las vistas de Tasks 4 y 5.

- [ ] **Step 1: Eliminar código muerto de `payment.js`**

Localizar y eliminar por completo las funciones `cargarAnticiposDisponibles`, `mostrarAnticiposDisponibles`, `calcularTotalConAnticipos` (líneas ~3075-3172 según la última lectura — verificar número exacto antes de borrar, puede haber corrido por ediciones previas):

Run primero para ubicar las líneas exactas:
```bash
grep -n "^function cargarAnticiposDisponibles\|^function mostrarAnticiposDisponibles\|^function calcularTotalConAnticipos" "C:\Users\alejandro.martinez\Desktop\codigo\AplicativoPhp\_assets\js\payment.js"
```

Borrar las 3 funciones completas (desde su `function nombre(...) {` hasta el `}` de cierre correspondiente). También eliminar cualquier llamada a `cargarAnticiposDisponibles(...)` que quede huérfana (buscar con `grep -n "cargarAnticiposDisponibles("`).

- [ ] **Step 2: Agregar las nuevas funciones al final de `payment.js`**

```javascript
// ============================================================
// ANTICIPO DETAIL: autorización, pago y aplicación a facturas
// ============================================================

function openAuthModalAnticipo() {
  $("#authModalAnticipo").modal("show");
}

function confirmarAutorizacionAnticipo() {
  const anticipoId = $("#anticipoId").val();

  fetch("/payment/authorize_payment", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ payment_id: anticipoId, permission: 68 }),
  })
    .then((r) => r.json())
    .then((data) => {
      $("#authModalAnticipo").modal("hide");
      if (data.success) {
        alertify.success(data.message || "Anticipo autorizado");
        setTimeout(() => location.reload(), 1000);
      } else {
        alertify.error(data.message || "Error al autorizar");
      }
    })
    .catch(() => {
      $("#authModalAnticipo").modal("hide");
      alertify.error("Error de conexión al autorizar");
    });
}

function abrirModalPagarAnticipo() {
  $("#formPagarAnticipo")[0].reset();
  $("#pago_fecha").val(new Date().toISOString().slice(0, 10));
  $("#modalPagarAnticipo").modal("show");
}

function confirmarPagoAnticipo() {
  const anticipoId = $("#anticipoId").val();
  const fecha = $("#pago_fecha").val();
  const referencia = $("#pago_referencia").val().trim();
  const observaciones = $("#pago_observaciones").val().trim();
  const fileInput = document.getElementById("pago_comprobante");

  if (!fecha || !referencia) {
    alertify.error("Fecha y referencia bancaria son obligatorias");
    return;
  }
  if (!fileInput.files.length) {
    alertify.error("Debe adjuntar el comprobante de pago");
    return;
  }

  const formData = new FormData();
  formData.append("anticipo_id", anticipoId);
  formData.append("fecha_pago", fecha);
  formData.append("referencia", referencia);
  formData.append("observaciones", observaciones);
  formData.append("comprobante", fileInput.files[0]);

  $("#btnConfirmarPagoAnticipo")
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Procesando...');

  fetch("/payment/pay_anticipo", { method: "POST", body: formData })
    .then((r) => r.json())
    .then((data) => {
      $("#modalPagarAnticipo").modal("hide");
      if (data.success) {
        alertify.success(data.message || "Anticipo pagado correctamente");
        setTimeout(() => location.reload(), 1200);
      } else {
        alertify.error(data.message || "Error al registrar el pago");
        $("#btnConfirmarPagoAnticipo")
          .prop("disabled", false)
          .html('<i class="fas fa-check"></i> Confirmar Pago');
      }
    })
    .catch(() => {
      alertify.error("Error de conexión al registrar el pago");
      $("#btnConfirmarPagoAnticipo")
        .prop("disabled", false)
        .html('<i class="fas fa-check"></i> Confirmar Pago');
    });
}

let facturasPendientesAplicar = [];

function abrirModalAplicarAFacturas() {
  const providerCod = $("#anticipoProviderCod").val();
  const saldo = parseFloat($("#anticipoSaldoDisponible").val()) || 0;

  $("#saldoDisponibleModal").text(saldo.toLocaleString("es-MX", { minimumFractionDigits: 2 }));
  $("#tbodyFacturasAplicar").html(
    '<tr><td colspan="7" class="text-center text-muted">Cargando facturas...</td></tr>',
  );
  $("#modalAplicarAnticipo").modal("show");

  fetch("/payment/get_invoices_pendientes_by_provider", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ provider_cod: providerCod }),
  })
    .then((r) => r.json())
    .then((data) => {
      facturasPendientesAplicar = data.success ? data.data : [];
      renderFacturasPendientesAplicar(facturasPendientesAplicar);
    })
    .catch(() => {
      $("#tbodyFacturasAplicar").html(
        '<tr><td colspan="7" class="text-center text-danger">Error al cargar facturas</td></tr>',
      );
    });
}

function renderFacturasPendientesAplicar(facturas) {
  const $tbody = $("#tbodyFacturasAplicar");
  $tbody.empty();

  if (!facturas.length) {
    $tbody.html(
      '<tr><td colspan="7" class="text-center text-muted">No hay facturas pendientes de este proveedor</td></tr>',
    );
    return;
  }

  facturas.forEach((f) => {
    const saldo = parseFloat(f.saldo) || 0;
    const row = `
      <tr data-invoice-id="${f.id}" data-payment-request-id="${f.payment_request_id}" data-saldo="${saldo}">
        <td><input type="checkbox" class="factura-aplicar-checkbox"></td>
        <td>${f.folio}</td>
        <td>${f.invoice_number}</td>
        <td>${f.estacion_nombre || "N/A"}</td>
        <td class="text-end">$${parseFloat(f.amount).toLocaleString("es-MX", { minimumFractionDigits: 2 })}</td>
        <td class="text-end">$${saldo.toLocaleString("es-MX", { minimumFractionDigits: 2 })}</td>
        <td>
          <input type="number" class="form-control form-control-sm monto-aplicar-input"
                 step="0.01" min="0.01" max="${saldo}" placeholder="0.00" disabled>
        </td>
      </tr>`;
    $tbody.append(row);
  });

  $(".factura-aplicar-checkbox").on("change", function () {
    const $input = $(this).closest("tr").find(".monto-aplicar-input");
    $input.prop("disabled", !this.checked);
    if (!this.checked) $input.val("");
    actualizarTotalAplicar();
  });
  $(".monto-aplicar-input").on("input", actualizarTotalAplicar);
}

$(document).on("input", "#buscarFacturaAplicar", function () {
  const term = $(this).val().toLowerCase();
  const filtradas = facturasPendientesAplicar.filter(
    (f) =>
      (f.folio || "").toLowerCase().includes(term) ||
      (f.invoice_number || "").toLowerCase().includes(term),
  );
  renderFacturasPendientesAplicar(filtradas);
});

$(document).on("change", "#selectAllFacturasAplicar", function () {
  $(".factura-aplicar-checkbox").prop("checked", this.checked).trigger("change");
});

function actualizarTotalAplicar() {
  const saldoDisponible = parseFloat($("#anticipoSaldoDisponible").val()) || 0;
  let total = 0;
  $(".factura-aplicar-checkbox:checked").each(function () {
    const monto = parseFloat($(this).closest("tr").find(".monto-aplicar-input").val()) || 0;
    total += monto;
  });

  $("#totalAplicarModal").text(total.toLocaleString("es-MX", { minimumFractionDigits: 2 }));

  const excede = total > saldoDisponible;
  $("#alertExcedeSaldo").toggleClass("d-none", !excede);
  $("#btnConfirmarAplicarAnticipo").prop("disabled", excede || total <= 0);
}

function confirmarAplicarAnticipo() {
  const anticipoId = $("#anticipoId").val();
  const aplicaciones = [];

  $(".factura-aplicar-checkbox:checked").each(function () {
    const $row = $(this).closest("tr");
    const monto = parseFloat($row.find(".monto-aplicar-input").val()) || 0;
    if (monto > 0) {
      aplicaciones.push({
        invoice_id: $row.data("invoice-id"),
        payment_request_id: $row.data("payment-request-id"),
        monto: monto,
      });
    }
  });

  if (!aplicaciones.length) {
    alertify.error("Seleccione al menos una factura y un monto a aplicar");
    return;
  }

  $("#btnConfirmarAplicarAnticipo")
    .prop("disabled", true)
    .html('<i class="fas fa-spinner fa-spin"></i> Aplicando...');

  fetch("/payment/apply_anticipo_to_invoices", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({
      anticipo_id: anticipoId,
      aplicaciones: JSON.stringify(aplicaciones),
    }),
  })
    .then((r) => r.json())
    .then((data) => {
      $("#modalAplicarAnticipo").modal("hide");
      if (data.success) {
        alertify.success(data.message || "Anticipo aplicado correctamente");
        setTimeout(() => location.reload(), 1200);
      } else {
        alertify.error(data.message || "Error al aplicar el anticipo");
        $("#btnConfirmarAplicarAnticipo")
          .prop("disabled", false)
          .html('<i class="fas fa-check"></i> Confirmar Aplicación');
      }
    })
    .catch(() => {
      alertify.error("Error de conexión al aplicar el anticipo");
      $("#btnConfirmarAplicarAnticipo")
        .prop("disabled", false)
        .html('<i class="fas fa-check"></i> Confirmar Aplicación');
    });
}
```

- [ ] **Step 3: Verificación funcional manual end-to-end**

1. Con el servidor local corriendo y logueado como usuario con permiso 68:
   - Crear un anticipo nuevo de prueba.
   - Abrir su detalle (`/payment/anticipo_detail/<id>`), click "Autorizar como Tesorería" → confirmar en el modal → verificar que la página recarga y muestra "Autorizado por Tesorería".
   - Click "Registrar Pago y Subir Comprobante" → llenar fecha/referencia/observaciones, adjuntar un PDF de prueba → confirmar → verificar que recarga y muestra "Pagado" con la sección de pago indicando que ya fue pagado.
   - Click "Aplicar a Facturas" → verificar que carga la tabla de facturas pendientes del mismo proveedor (usar un proveedor que tenga facturas pendientes reales de prueba) → marcar una factura, ingresar un monto menor o igual al saldo → confirmar → verificar que la tabla de "Aplicaciones a Facturas" en el detalle ahora muestra la fila nueva y el saldo disponible bajó.
   - Intentar aplicar un monto que exceda el saldo disponible → verificar que el botón "Confirmar Aplicación" se deshabilita y aparece la alerta de saldo excedido.

Expected: las 4 sub-pruebas pasan sin error 500, sin errores de consola JS, y los datos en BD (`payment_requests.status`, `payment_batches`, `payment_transactions`, `payment_transaction_documents`, `anticipo_invoice_applications`) reflejan correctamente cada paso.

- [ ] **Step 4: Commit**

```bash
git add "_assets/js/payment.js"
git commit -m "Implementar JS de autorizar/pagar/aplicar anticipo y limpiar codigo muerto"
```

---

## Self-Review Notes

- **Cobertura del diseño:** los 3 puntos rotos identificados en el diagnóstico (vista de detalle copiada, ausencia de pago+comprobante, modal de ligado sin terminar) quedan cubiertos por Tasks 3-6. El endpoint inexistente `/payment/get_anticipos_disponibles` y su JS muerto quedan removidos en Task 6 Step 1, conforme a "fuera de alcance" del diseño.
- **Sin placeholders:** todo paso de código trae el contenido completo a pegar; no hay "TODO" ni "similar a Task N".
- **Consistencia de tipos/nombres:** `pay_anticipo` (controlador) ↔ `/payment/pay_anticipo` (JS) — coincide. `get_invoices_pendientes_by_provider` (controlador) ↔ mismo nombre en URL JS — coincide. `get_pending_by_provider` (modelo, Task 1) ↔ llamado dentro del controlador de Task 2 vía `$this->paymentRequestInvoicesModel->get_pending_by_provider(...)` — coincide. IDs de elementos HTML (`#anticipoId`, `#anticipoProviderCod`, `#anticipoSaldoDisponible`, `#modalAplicarAnticipo`, `#tbodyFacturasAplicar`, etc.) definidos en Tasks 4-5 se usan exactamente igual en el JS de Task 6.
